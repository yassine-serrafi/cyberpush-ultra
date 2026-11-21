<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Accès centralisé aux tables SQL du plugin.
 * Aucune table n'est codée en dur ailleurs : tous les modules
 * doivent passer par CU_DB::table( 'xxx' ).
 */
class CU_DB {

	/** @var array<string,bool> Résultats mémorisés pour la requête courante, par nom court. */
	private static $table_exists_cache = array();

	/** Date calculée dans le fuseau WordPress, cohérente avec current_time('mysql'). */
	public static function date_from_now( $format = 'Y-m-d H:i:s', $offset_seconds = 0 ) {
		return wp_date( $format, time() + (int) $offset_seconds, wp_timezone() );
	}

	/**
	 * Retourne le nom complet (avec préfixe WordPress) d'une table du plugin.
	 *
	 * @param string $name Nom court, ex: 'sessions', 'events', 'firewall_logs'.
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'cu_' . $name;
	}

	/**
	 * Liste des noms courts de toutes les tables gérées par le plugin.
	 */
	public static function table_names() {
		return array(
			'sessions',
			'events',
			'content_daily',
			'firewall_logs',
			'firewall_blocks',
			'firewall_whitelist',
			'firewall_watch',
			'firewall_audit',
			'seo_pages',
			'seo_issues',
			'seo_meta',
			'seo_links',
			'seo_history',
			'logs',
			'contacts',
			'fim',
			'spam_log',
			'logins',
		);
	}

	/**
	 * Renvoie true si toutes les tables du plugin existent réellement en base.
	 * Utilisé pour afficher "Migration SQL requise" sans jamais casser le site public.
	 */
	public static function tables_exist( $refresh = false ) {
		return self::tables_exist_for( self::table_names(), $refresh );
	}

	/**
	 * Renvoie true si toutes les tables demandees existent.
	 *
	 * Les noms courts sont valides contre le schema gere par le plugin. Les
	 * tables non encore connues du cache sont controlees ensemble afin de ne
	 * produire qu'une seule requete SQL, meme pour le schema complet.
	 *
	 * @param array $short_names Noms courts issus de self::table_names().
	 * @param bool  $refresh     Force un nouveau controle des tables demandees.
	 * @return bool
	 */
	public static function tables_exist_for( array $short_names, $refresh = false ) {
		$known_names = array_fill_keys( self::table_names(), true );
		$requested   = array();

		foreach ( $short_names as $short_name ) {
			if ( ! is_string( $short_name ) || ! preg_match( '/\A[a-z0-9_]+\z/D', $short_name ) || ! isset( $known_names[ $short_name ] ) ) {
				return false;
			}
			$requested[ $short_name ] = true;
		}

		$requested = array_keys( $requested );
		if ( empty( $requested ) ) {
			return true;
		}

		// Chemin rapide hors administration : le pare-feu appelle ce contrôle à
		// CHAQUE visite publique, et une requête information_schema est coûteuse
		// sur MySQL 5.7 / MariaDB mutualisés. L'option de version (autoload, donc
		// déjà en mémoire) n'est écrite qu'après une création de tables réussie et
		// supprimée avec elles à la désinstallation : c'est une preuve suffisante.
		// L'administration garde le contrôle réel (avis « Migration SQL requise »),
		// y compris les appels REST de l'interface d'administration : is_admin()
		// y vaut false, et le chemin rapide faisait annoncer « Tables installées »,
		// « Analytics OK » ou un effacement réussi alors qu'une table manquait.
		// (Rien n'est mémorisé ici : un contrôle réel plus tard dans la même
		// requête — route REST d'administration — ne doit pas hériter de ce « oui ».)
		if ( ! $refresh && ! is_admin() && ! self::is_admin_rest_request() && defined( 'CU_OPTION_DB_VERSION' ) && get_option( CU_OPTION_DB_VERSION ) === CU_DB_VERSION ) {
			return true;
		}

		$to_check = array();
		foreach ( $requested as $short_name ) {
			if ( $refresh || ! array_key_exists( $short_name, self::$table_exists_cache ) ) {
				$to_check[] = $short_name;
			}
		}

		if ( ! empty( $to_check ) ) {
			global $wpdb;

			$full_names    = array();
			$full_to_short = array();
			foreach ( $to_check as $short_name ) {
				$full_name                                   = self::table( $short_name );
				$full_names[]                                = $full_name;
				$full_to_short[ $full_name ]                 = $short_name;
				self::$table_exists_cache[ $short_name ] = false;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $full_names ), '%s' ) );
			$sql          = $wpdb->prepare(
				"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
				$full_names
			);
			$found_tables = $wpdb->get_col( $sql );

			if ( is_array( $found_tables ) ) {
				foreach ( $found_tables as $found_table ) {
					if ( isset( $full_to_short[ $found_table ] ) ) {
						self::$table_exists_cache[ $full_to_short[ $found_table ] ] = true;
					}
				}
			}
		}

		foreach ( $requested as $short_name ) {
			if ( empty( self::$table_exists_cache[ $short_name ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Requête REST d'un administrateur (interface du plugin). REST_REQUEST n'est
	 * défini qu'au routage (après `init`) : le pare-feu public garde le chemin
	 * rapide, et la collecte anonyme (/collect) n'est jamais concernée.
	 */
	private static function is_admin_rest_request() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || ! function_exists( 'current_user_can' ) || ! did_action( 'init' ) ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}

	/** True si la colonne existe (contrôle réel, sans cache). */
	public static function column_exists( $short_name, $column ) {
		global $wpdb;
		if ( ! self::tables_exist_for( array( $short_name ), true ) ) {
			return false;
		}
		$suppress = $wpdb->suppress_errors( true );
		$found    = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . self::table( $short_name ) . ' LIKE %s', $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->suppress_errors( $suppress );
		return ! empty( $found );
	}

	/**
	 * Verrou inter-processus court (tâches planifiées, migration, agrégation).
	 *
	 * INSERT IGNORE sur l'index unique option_name : une seule requête l'obtient,
	 * contrairement à add_option() (lecture puis INSERT … ON DUPLICATE KEY UPDATE,
	 * que deux requêtes simultanées « réussissent » toutes les deux). Un verrou
	 * plus ancien que $ttl (requête interrompue) est repris par
	 * compare-and-swap sur sa valeur exacte.
	 *
	 * @param string $option Nom complet de l'option servant de verrou.
	 * @param int    $ttl    Durée au-delà de laquelle le verrou est jugé orphelin (s).
	 * @return string|false Jeton propriétaire, ou false si le verrou est tenu.
	 */
	public static function lock_acquire( $option, $ttl ) {
		global $wpdb;
		$now      = time();
		$token    = $now . '|' . wp_generate_uuid4();
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $token ) );
		$current  = null;
		if ( 1 !== (int) $inserted ) {
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		}
		$wpdb->suppress_errors( $suppress );
		wp_cache_delete( $option, 'options' );
		if ( 1 === (int) $inserted ) {
			return $token;
		}
		if ( false === $inserted ) {
			return false;
		}
		if ( null === $current ) {
			// Libéré entre les deux requêtes : une seule nouvelle tentative.
			$retry = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $token ) );
			return 1 === (int) $retry ? $token : false;
		}
		$parts   = explode( '|', (string) $current, 2 );
		$created = (int) $parts[0];
		if ( $created > 0 && $created <= $now && ( $now - $created ) < max( 1, (int) $ttl ) ) {
			return false;
		}
		$replaced = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $option, (string) $current ) );
		wp_cache_delete( $option, 'options' );
		return 1 === (int) $replaced ? $token : false;
	}

	/** Libère un verrou obtenu par lock_acquire() (seulement s'il est encore le nôtre). */
	public static function lock_release( $option, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, (string) $token ) );
		wp_cache_delete( $option, 'options' );
	}

	/**
	 * Compteur à fenêtre fixe ATOMIQUE (limitation de débit, plafonds).
	 *
	 * Avec un cache objet persistant : wp_cache_add + wp_cache_incr (atomiques).
	 * Sans cache objet : une seule requête INSERT … ON DUPLICATE KEY UPDATE sur
	 * wp_options (index unique option_name), atomique côté MySQL/MariaDB, au lieu
	 * d'un transient lu puis réécrit (incréments perdus sous requêtes simultanées).
	 * Les fenêtres sont alignées sur l'horloge (fin = multiple de la durée) ; la
	 * fin de fenêtre fait partie du nom de la ligne, si bien qu'une nouvelle
	 * fenêtre repart toujours de zéro. Lignes non chargées automatiquement,
	 * préfixe « cu_ctr_ » (supprimées à la désinstallation avec les autres
	 * options cu_*), la fenêtre précédente est retirée dès qu'une nouvelle
	 * commence et counter_purge_expired() balaie les restes.
	 * En cas d'erreur SQL, le compteur échoue en mode ouvert (valeur 1) : il ne
	 * doit jamais bloquer un visiteur à cause d'un incident de base.
	 *
	 * @param string $key    Identifiant logique (haché, jamais stocké en clair).
	 * @param int    $window Durée de la fenêtre en secondes.
	 * @param int    $by     Incrément (≥ 1).
	 * @return array{count:int, expires_at:int}
	 */
	public static function counter_increment( $key, $window, $by = 1 ) {
		$window = max( 1, (int) $window );
		$by     = max( 1, (int) $by );
		$end    = self::counter_window_end( $window );
		$hash   = md5( (string) $key );

		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$ckey = $hash . '_' . $end;
			wp_cache_add( $ckey, 0, 'cu_ctr', $window + 5 );
			$count = wp_cache_incr( $ckey, $by, 'cu_ctr' );
			if ( false === $count ) {
				// Entrée évincée entre l'ajout et l'incrément.
				wp_cache_set( $ckey, $by, 'cu_ctr', $window + 5 );
				$count = $by;
			}
			return array( 'count' => max( 1, (int) $count ), 'expires_at' => $end );
		}

		global $wpdb;
		$name     = self::counter_option_name( $hash, $end );
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no') ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + %d)",
				$name,
				$by,
				$by
			)
		);
		$affected = (int) $wpdb->rows_affected;
		$last_id  = (int) $wpdb->insert_id;
		if ( false !== $result && 1 === $affected ) {
			// Nouvelle fenêtre : la ligne de la fenêtre précédente n'a plus d'usage.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::counter_option_name( $hash, $end - $window ) ) );
		}
		$wpdb->suppress_errors( $suppress );

		if ( false === $result ) {
			return array( 'count' => 1, 'expires_at' => $end );
		}
		$count = ( 1 === $affected ) ? $by : $last_id;
		return array( 'count' => max( 1, $count ), 'expires_at' => $end );
	}

	/** Valeur courante d'un compteur (0 si la fenêtre est neuve), sans l'incrémenter. */
	public static function counter_value( $key, $window ) {
		$window = max( 1, (int) $window );
		$end    = self::counter_window_end( $window );
		$hash   = md5( (string) $key );
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return max( 0, (int) wp_cache_get( $hash . '_' . $end, 'cu_ctr' ) );
		}
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::counter_option_name( $hash, $end ) ) );
		return max( 0, (int) $value );
	}

	/** Remet un compteur à zéro (fenêtre courante). */
	public static function counter_reset( $key, $window ) {
		$window = max( 1, (int) $window );
		$end    = self::counter_window_end( $window );
		$hash   = md5( (string) $key );
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			wp_cache_delete( $hash . '_' . $end, 'cu_ctr' );
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::counter_option_name( $hash, $end ) ) );
	}

	/** Supprime les lignes de compteurs dont la fenêtre est terminée (tâche de nettoyage). */
	public static function counter_purge_expired( $limit = 5000 ) {
		global $wpdb;
		// « cu_ctr_ » (7) + md5 (32) + « _ » : l'horodatage de fin commence au 41e caractère.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING(option_name, 41) AS UNSIGNED) < %d LIMIT %d",
				$wpdb->esc_like( 'cu_ctr_' ) . '%',
				time(),
				max( 1, (int) $limit )
			)
		);
	}

	private static function counter_window_end( $window ) {
		$now = time();
		return (int) ( $now - ( $now % $window ) + $window );
	}

	private static function counter_option_name( $hash, $end ) {
		return 'cu_ctr_' . $hash . '_' . (int) $end;
	}

	/** Vérifie les colonnes/index indispensables aux rapports Analytics 1.8.7, à l'historique SEO 1.8.8, aux contacts 1.8.9 et à la mesure anonyme 1.9.1. */
	public static function analytics_schema_ready() {
		if ( ! self::tables_exist_for( array( 'sessions', 'events', 'content_daily', 'seo_history', 'contacts' ), true ) ) {
			return false;
		}

		global $wpdb;
		$checks = array(
			array( "SHOW COLUMNS FROM " . self::table( 'events' ) . " LIKE %s", 'duration' ),
			array( "SHOW COLUMNS FROM " . self::table( 'sessions' ) . " LIKE %s", 'geo_token' ),
			array( "SHOW COLUMNS FROM " . self::table( 'sessions' ) . " LIKE %s", 'geo_queued_at' ),
			array( "SHOW COLUMNS FROM " . self::table( 'sessions' ) . " LIKE %s", 'live_ip' ),
			array( "SHOW COLUMNS FROM " . self::table( 'sessions' ) . " LIKE %s", 'anon' ),
			array( "SHOW COLUMNS FROM " . self::table( 'sessions' ) . " LIKE %s", 'language' ),
			array( "SHOW COLUMNS FROM " . self::table( 'content_daily' ) . " LIKE %s", 'measured_sessions' ),
			array( "SHOW COLUMNS FROM " . self::table( 'seo_history' ) . " LIKE %s", 'score_global' ),
			array( "SHOW INDEX FROM " . self::table( 'sessions' ) . " WHERE Key_name = %s", 'first_seen' ),
			array( "SHOW INDEX FROM " . self::table( 'sessions' ) . " WHERE Key_name = %s", 'geo_pending' ),
			array( "SHOW INDEX FROM " . self::table( 'events' ) . " WHERE Key_name = %s", 'event_created_session' ),
			array( "SHOW INDEX FROM " . self::table( 'events' ) . " WHERE Key_name = %s", 'event_session_order' ),
			array( "SHOW INDEX FROM " . self::table( 'contacts' ) . " WHERE Key_name = %s", 'channel_created' ),
		);
		foreach ( $checks as $check ) {
			$wpdb->last_error = '';
			$found = $wpdb->get_var( $wpdb->prepare( $check[0], $check[1] ) );
			if ( ! $found || $wpdb->last_error ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Schéma SQL complet (dbDelta). Centralisé ici pour que l'activation
	 * et les migrations restent cohérentes entre tous les modules.
	 */
	public static function schema_sql() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sessions           = self::table( 'sessions' );
		$events             = self::table( 'events' );
		$content_daily      = self::table( 'content_daily' );
		$firewall_logs      = self::table( 'firewall_logs' );
		$firewall_blocks    = self::table( 'firewall_blocks' );
		$firewall_whitelist = self::table( 'firewall_whitelist' );
		$firewall_watch     = self::table( 'firewall_watch' );
		$firewall_audit     = self::table( 'firewall_audit' );
		$seo_pages          = self::table( 'seo_pages' );
		$seo_issues         = self::table( 'seo_issues' );
		$seo_meta           = self::table( 'seo_meta' );
		$seo_links          = self::table( 'seo_links' );
		$seo_history        = self::table( 'seo_history' );
		$logs               = self::table( 'logs' );
		$contacts           = self::table( 'contacts' );
		$fim                = self::table( 'fim' );
		$spam_log           = self::table( 'spam_log' );
		$logins             = self::table( 'logins' );

		// Types entiers écrits avec leur largeur canonique (bigint(20) unsigned…),
		// telle que MariaDB et MySQL 5.7 la renvoient : sinon dbDelta() juge chaque
		// colonne différente et relance 71 ALTER TABLE à chaque contrôle d'un
		// schéma pourtant à jour. MySQL 8.0.17+ ignore la largeur (dbDelta aussi).
		$sql = "
CREATE TABLE {$sessions} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	session_uuid VARCHAR(36) NOT NULL,
	first_seen DATETIME NOT NULL,
	last_seen DATETIME NOT NULL,
	ip_hash VARCHAR(64) NOT NULL,
	country_code VARCHAR(2) NULL,
	device VARCHAR(20) NULL,
	browser VARCHAR(30) NULL,
	os VARCHAR(30) NULL,
	is_new TINYINT(1) NOT NULL DEFAULT 1,
	is_bot TINYINT(1) NOT NULL DEFAULT 0,
	is_reliable TINYINT(1) NOT NULL DEFAULT 1,
	is_excluded TINYINT(1) NOT NULL DEFAULT 0,
	referrer_source VARCHAR(50) NULL,
	referrer_url VARCHAR(500) NULL,
	landing_url VARCHAR(500) NULL,
	exit_url VARCHAR(500) NULL,
	pageviews INT(10) UNSIGNED NOT NULL DEFAULT 0,
	duration INT(10) UNSIGNED NOT NULL DEFAULT 0,
	user_role VARCHAR(50) NULL,
	referrer_host VARCHAR(255) NULL,
	is_datacenter TINYINT(1) NOT NULL DEFAULT 0,
	is_proxy TINYINT(1) NOT NULL DEFAULT 0,
	asn VARCHAR(60) NULL,
	enriched TINYINT(1) NOT NULL DEFAULT 0,
	geo_token VARCHAR(512) NULL,
	geo_queued_at DATETIME NULL,
	live_ip VARCHAR(255) NULL,
	anon TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
	language VARCHAR(16) NULL,
	screen VARCHAR(12) NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY session_uuid (session_uuid),
	KEY first_seen (first_seen),
	KEY last_seen (last_seen),
	KEY is_reliable (is_reliable),
	KEY country_code (country_code),
	KEY referrer_host (referrer_host),
	KEY ip_hash (ip_hash),
	KEY enriched (enriched),
	KEY geo_pending (enriched, geo_queued_at)
) {$charset_collate};

CREATE TABLE {$events} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	session_id BIGINT(20) UNSIGNED NOT NULL,
	event_type VARCHAR(30) NOT NULL,
	object_id BIGINT(20) UNSIGNED NULL,
	object_type VARCHAR(30) NULL,
	url VARCHAR(500) NULL,
	scroll_percent TINYINT(3) UNSIGNED NULL,
	duration INT(10) UNSIGNED NULL,
	meta LONGTEXT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	KEY session_id (session_id),
	KEY event_type (event_type),
	KEY event_created_session (event_type, created_at, session_id),
	KEY event_session_order (event_type, session_id, id),
	KEY object_lookup (object_type, object_id),
	KEY created_at (created_at)
) {$charset_collate};

CREATE TABLE {$content_daily} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	stat_date DATE NOT NULL,
	object_id BIGINT(20) UNSIGNED NOT NULL,
	object_type VARCHAR(30) NOT NULL,
	sessions INT(10) UNSIGNED NOT NULL DEFAULT 0,
	pageviews INT(10) UNSIGNED NOT NULL DEFAULT 0,
	entries INT(10) UNSIGNED NOT NULL DEFAULT 0,
	exits INT(10) UNSIGNED NOT NULL DEFAULT 0,
	bounces INT(10) UNSIGNED NOT NULL DEFAULT 0,
	total_duration INT(10) UNSIGNED NOT NULL DEFAULT 0,
	cta_clicks INT(10) UNSIGNED NOT NULL DEFAULT 0,
	conversions INT(10) UNSIGNED NOT NULL DEFAULT 0,
	avg_scroll TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
	measured_sessions INT(10) UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY day_object (stat_date, object_id, object_type),
	KEY object_lookup (object_type, object_id)
) {$charset_collate};

CREATE TABLE {$firewall_logs} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	created_at DATETIME NOT NULL,
	ip VARCHAR(45) NOT NULL,
	country_code VARCHAR(2) NULL,
	url VARCHAR(500) NULL,
	method VARCHAR(10) NULL,
	response_code SMALLINT(5) UNSIGNED NULL,
	user_agent VARCHAR(255) NULL,
	referer VARCHAR(255) NULL,
	rule VARCHAR(50) NULL,
	decision VARCHAR(20) NOT NULL DEFAULT 'allowed',
	severity VARCHAR(10) NULL,
	PRIMARY KEY  (id),
	KEY ip (ip),
	KEY created_at (created_at),
	KEY rule (rule),
	KEY decision (decision),
	KEY decision_created (decision, created_at),
	KEY rule_created (rule, created_at)
) {$charset_collate};

CREATE TABLE {$firewall_blocks} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	ip_or_cidr VARCHAR(64) NOT NULL,
	type VARCHAR(20) NOT NULL DEFAULT 'auto',
	reason VARCHAR(255) NULL,
	comment TEXT NULL,
	country_code VARCHAR(2) NULL,
	asn VARCHAR(100) NULL,
	hits INT(10) UNSIGNED NOT NULL DEFAULT 0,
	first_seen DATETIME NOT NULL,
	last_seen DATETIME NOT NULL,
	expires_at DATETIME NULL,
	created_by BIGINT(20) UNSIGNED NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY ip_or_cidr (ip_or_cidr),
	KEY expires_at (expires_at)
) {$charset_collate};

CREATE TABLE {$firewall_whitelist} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	ip_or_cidr VARCHAR(64) NOT NULL,
	label VARCHAR(255) NULL,
	created_at DATETIME NOT NULL,
	expires_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY ip_or_cidr (ip_or_cidr),
	KEY expires_at (expires_at)
) {$charset_collate};

CREATE TABLE {$firewall_watch} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	ip VARCHAR(45) NOT NULL,
	country_code VARCHAR(2) NULL,
	org VARCHAR(150) NULL,
	asn VARCHAR(60) NULL,
	is_datacenter TINYINT(1) NOT NULL DEFAULT 0,
	is_proxy TINYINT(1) NOT NULL DEFAULT 0,
	enriched TINYINT(1) NOT NULL DEFAULT 0,
	hits INT(10) UNSIGNED NOT NULL DEFAULT 0,
	score SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
	signals VARCHAR(255) NULL,
	ua_sample VARCHAR(255) NULL,
	last_url VARCHAR(255) NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'observed',
	first_seen DATETIME NOT NULL,
	last_seen DATETIME NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY ip (ip),
	KEY score (score),
	KEY status (status),
	KEY last_seen (last_seen),
	KEY enriched (enriched)
) {$charset_collate};

CREATE TABLE {$firewall_audit} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	created_at DATETIME NOT NULL,
	severity VARCHAR(10) NOT NULL DEFAULT 'medium',
	category VARCHAR(20) NOT NULL DEFAULT 'other',
	event_key VARCHAR(60) NOT NULL,
	actor_id BIGINT(20) UNSIGNED NULL,
	actor_login VARCHAR(100) NULL,
	ip VARCHAR(45) NULL,
	country_code VARCHAR(2) NULL,
	object_type VARCHAR(40) NULL,
	object_name VARCHAR(255) NULL,
	message VARCHAR(255) NOT NULL,
	context LONGTEXT NULL,
	PRIMARY KEY  (id),
	KEY created_at (created_at),
	KEY severity (severity),
	KEY category (category),
	KEY event_key (event_key)
) {$charset_collate};

CREATE TABLE {$seo_pages} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	post_id BIGINT(20) UNSIGNED NULL,
	url VARCHAR(500) NOT NULL,
	url_hash CHAR(32) NOT NULL,
	object_type VARCHAR(30) NOT NULL DEFAULT 'url',
	title VARCHAR(255) NULL,
	meta_desc VARCHAR(320) NULL,
	http_status SMALLINT(5) UNSIGNED NULL,
	indexable TINYINT(1) NOT NULL DEFAULT 1,
	depth TINYINT(3) UNSIGNED NULL,
	word_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
	score TINYINT(3) UNSIGNED NULL,
	score_content TINYINT(3) UNSIGNED NULL,
	score_meta TINYINT(3) UNSIGNED NULL,
	score_technical TINYINT(3) UNSIGNED NULL,
	perf_score TINYINT(3) UNSIGNED NULL,
	ai_ready TINYINT(3) UNSIGNED NULL,
	issues_error INT(10) UNSIGNED NOT NULL DEFAULT 0,
	issues_warning INT(10) UNSIGNED NOT NULL DEFAULT 0,
	issues_notice INT(10) UNSIGNED NOT NULL DEFAULT 0,
	index_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
	index_checked DATETIME NULL,
	snapshot LONGTEXT NULL,
	crawl_state VARCHAR(20) NOT NULL DEFAULT 'pending',
	last_crawled DATETIME NULL,
	last_audited DATETIME NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY url_hash (url_hash),
	KEY post_id (post_id),
	KEY score (score),
	KEY crawl_state (crawl_state),
	KEY last_crawled (last_crawled)
) {$charset_collate};

CREATE TABLE {$seo_issues} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	page_id BIGINT(20) UNSIGNED NOT NULL,
	category VARCHAR(20) NOT NULL,
	code VARCHAR(50) NOT NULL,
	severity VARCHAR(10) NOT NULL DEFAULT 'notice',
	message VARCHAR(255) NOT NULL,
	detail VARCHAR(500) NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	KEY page_id (page_id),
	KEY severity (severity),
	KEY category (category),
	KEY code (code)
) {$charset_collate};

CREATE TABLE {$seo_meta} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	post_id BIGINT(20) UNSIGNED NOT NULL,
	target_keyword VARCHAR(191) NULL,
	notes TEXT NULL,
	ai_meta_title VARCHAR(255) NULL,
	ai_meta_description VARCHAR(320) NULL,
	updated_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY post_id (post_id)
) {$charset_collate};

CREATE TABLE {$seo_links} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	source_page_id BIGINT(20) UNSIGNED NOT NULL,
	url VARCHAR(500) NOT NULL,
	url_hash CHAR(32) NOT NULL,
	anchor VARCHAR(255) NULL,
	type VARCHAR(10) NOT NULL DEFAULT 'internal',
	is_nofollow TINYINT(1) NOT NULL DEFAULT 0,
	http_status SMALLINT(5) UNSIGNED NULL,
	checked_at DATETIME NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	KEY source_page_id (source_page_id),
	KEY url_hash (url_hash),
	KEY type (type),
	KEY http_status (http_status)
) {$charset_collate};

CREATE TABLE {$seo_history} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	snap_date DATE NOT NULL,
	score_avg TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
	score_global TINYINT(3) UNSIGNED NULL DEFAULT NULL,
	pages_total INT(10) UNSIGNED NOT NULL DEFAULT 0,
	indexable_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
	issues_error INT(10) UNSIGNED NOT NULL DEFAULT 0,
	issues_warning INT(10) UNSIGNED NOT NULL DEFAULT 0,
	issues_notice INT(10) UNSIGNED NOT NULL DEFAULT 0,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY snap_date (snap_date)
) {$charset_collate};

CREATE TABLE {$logs} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	created_at DATETIME NOT NULL,
	module VARCHAR(20) NOT NULL,
	level VARCHAR(10) NOT NULL DEFAULT 'info',
	message VARCHAR(500) NOT NULL,
	context LONGTEXT NULL,
	PRIMARY KEY  (id),
	KEY module (module),
	KEY created_at (created_at),
	KEY level (level)
) {$charset_collate};

CREATE TABLE {$contacts} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	session_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	channel VARCHAR(12) NOT NULL,
	action VARCHAR(12) NOT NULL,
	target VARCHAR(40) NULL,
	kind VARCHAR(12) NULL,
	widget VARCHAR(30) NULL,
	placement VARCHAR(10) NULL,
	label VARCHAR(120) NULL,
	object_id BIGINT(20) UNSIGNED NULL,
	object_type VARCHAR(30) NULL,
	url VARCHAR(500) NULL,
	page_seconds INT(10) UNSIGNED NULL,
	fill_seconds INT(10) UNSIGNED NULL,
	scroll_pct TINYINT(3) UNSIGNED NULL,
	fields VARCHAR(255) NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	KEY channel_created (channel, created_at),
	KEY channel_target (channel, target),
	KEY session_id (session_id),
	KEY created_at (created_at)
) {$charset_collate};

CREATE TABLE {$fim} (
	path_hash CHAR(32) NOT NULL,
	path VARCHAR(1024) NOT NULL,
	unit VARCHAR(100) NOT NULL DEFAULT '',
	baseline_md5 CHAR(32) NULL,
	scan_md5 CHAR(32) NULL,
	scan_state TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
	updated_at DATETIME NOT NULL,
	PRIMARY KEY  (path_hash),
	KEY scan_state (scan_state),
	KEY unit (unit)
) {$charset_collate};

CREATE TABLE {$spam_log} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	created_at DATETIME NOT NULL,
	form_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	decision VARCHAR(12) NOT NULL,
	reason VARCHAR(30) NOT NULL DEFAULT '',
	agent VARCHAR(30) NOT NULL DEFAULT '',
	email_domain VARCHAR(190) NULL,
	source_key CHAR(12) NULL,
	PRIMARY KEY  (id),
	KEY created_at (created_at),
	KEY form_created (form_id, created_at),
	KEY decision_created (decision, created_at)
) {$charset_collate};

CREATE TABLE {$logins} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	created_at DATETIME NOT NULL,
	user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	user_login VARCHAR(60) NOT NULL DEFAULT '',
	role VARCHAR(60) NULL,
	event VARCHAR(40) NOT NULL,
	severity VARCHAR(10) NOT NULL DEFAULT 'info',
	ip VARCHAR(45) NULL,
	country_code VARCHAR(2) NULL,
	browser VARCHAR(60) NULL,
	ip_status VARCHAR(10) NULL,
	country_status VARCHAR(10) NULL,
	failures_account SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
	failures_ip SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
	actor_id BIGINT(20) UNSIGNED NULL,
	actor_login VARCHAR(60) NULL,
	message VARCHAR(255) NULL,
	audit_id BIGINT(20) UNSIGNED NULL,
	PRIMARY KEY  (id),
	KEY created_at (created_at),
	KEY user_created (user_id, created_at),
	KEY event_created (event, created_at),
	KEY severity_created (severity, created_at)
) {$charset_collate};
";

		return $sql;
	}
}

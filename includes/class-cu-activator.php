<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CU_Activator {

	public static function activate() {
		$previous     = (string) get_option( CU_OPTION_DB_VERSION, '' );
		$had_measured = CU_DB::column_exists( 'content_daily', 'measured_sessions' );
		$tables_ready = self::install_tables();
		if ( $tables_ready ) {
			// Réactivation après une mise à jour par fichiers : la migration de la
			// mesure anonyme ne doit pas être sautée.
			if ( '' !== $previous && version_compare( $previous, '1.9.1', '<' ) ) {
				self::migrate_analytics_1_9_1( $had_measured );
			}
			update_option( CU_OPTION_DB_VERSION, CU_DB_VERSION );
		}
		// Protection des connexions : les administrateurs présents à l'activation forment
		// l'état de référence (jamais signalés comme « apparus »).
		if ( class_exists( 'CU_Logins' ) ) {
			CU_Logins::save_baseline();
		}

		if ( false === get_option( CU_OPTION_INSTALLED_AT ) ) {
			update_option( CU_OPTION_INSTALLED_AT, current_time( 'mysql' ) );
		}

		if ( false === get_option( CU_OPTION_SETTINGS ) ) {
			// add_option : n'écrase jamais des réglages créés entre-temps.
			add_option( CU_OPTION_SETTINGS, CU_Settings::get_defaults() );
		}
		self::cleanup_legacy_firewall_signatures();
		// Réactivation après une mise à jour par fichiers : la migration du pays par
		// base locale ne doit pas être sautée. Installation neuve : la base est
		// téléchargée en arrière-plan dès l'activation.
		if ( '' !== $previous && version_compare( $previous, '1.9.3', '<' ) ) {
			self::migrate_geo_1_9_3();
		} elseif ( CU_Geo::local_provider_active() && '' === CU_Geo::local_db_path() ) {
			CU_Geo::schedule_local_db_update();
		}
		// Anti-spam : début du délai de grâce (les pages déjà en cache n'ont pas
		// encore les champs de protection ; elles sont purgées plus bas).
		if ( CU_Spam::honeypot_active() ) {
			CU_Spam::mark_since( true );
		}

		// Une seule IP de récupération automatique : remplacer l'ancienne IP
		// dynamique au lieu d'accumuler des adresses potentiellement réattribuées.
		if ( $tables_ready ) {
			self::refresh_activation_whitelist();
		}

		CU_Cron::instance()->schedule_events();

		flush_rewrite_rules();

		// Les pages mises en cache avant l'activation (ou pendant une réinstallation)
		// ne contiennent pas le tracker : elles ne compteraient aucune visite.
		try {
			CU_Tracker::purge_page_caches();
		} catch ( Throwable $e ) {
			// Une extension de cache défaillante ne doit jamais bloquer l'activation.
			unset( $e );
		}
	}

	public static function install_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( CU_DB::schema_sql() );
		// Un contrôle antérieur dans la même requête a pu mémoriser « absent ».
		return CU_DB::tables_exist( true ) && CU_DB::analytics_schema_ready();
	}

	/**
	 * Vérifie et applique les migrations SQL nécessaires si la version
	 * installée diffère de la version attendue par le plugin.
	 * N'affecte jamais le rendu du site public : en cas d'échec, la page
	 * d'administration affiche simplement "Migration SQL requise".
	 */
	public static function maybe_upgrade() {
		// La migration s'exécute dès la première requête suivant une mise à jour
		// (mise à jour automatique, FTP…), sans attendre la visite d'un
		// administrateur : jusque-là, les tables manquantes désactiveraient le
		// pare-feu et la collecte. Un verrou empêche deux requêtes simultanées de
		// lancer dbDelta en parallèle.
		$lock = CU_DB::lock_acquire( 'cu_upgrade_lock', 5 * MINUTE_IN_SECONDS );
		if ( false === $lock ) {
			return;
		}
		try {
			$installed_version = (string) get_option( CU_OPTION_DB_VERSION, '0' );
			// Colonne présente AVANT dbDelta : la migration 1.9.1 a déjà eu lieu
			// (version effacée, rétrogradation puis remise à niveau…).
			$had_measured = CU_DB::column_exists( 'content_daily', 'measured_sessions' );
			if ( ! self::install_tables() ) {
				return;
			}
			self::cleanup_legacy_seo();
			// Ce nettoyage ne concerne que les exclusions Analytics historiques. Les
			// pays de confiance du Firewall sont toujours conservés : une migration ne
			// peut pas distinguer un ancien exemple d'un choix confirmé par l'admin.
			if ( version_compare( $installed_version, '1.8.3', '<' ) && ! self::cleanup_legacy_analytics_defaults() ) {
				return;
			}
			self::cleanup_legacy_firewall_signatures();
			if ( version_compare( $installed_version, '1.9.0', '<' ) ) {
				self::migrate_settings_1_9_0();
				// Référence d'intégrité : options de plusieurs Mo → table cu_fim.
				if ( class_exists( 'CU_Audit' ) && method_exists( 'CU_Audit', 'migrate_legacy_storage' ) ) {
					CU_Audit::instance()->migrate_legacy_storage();
				}
			}
			if ( version_compare( $installed_version, '1.9.1', '<' ) ) {
				self::migrate_analytics_1_9_1( $had_measured );
			}
			if ( version_compare( $installed_version, '1.9.2', '<' ) ) {
				self::migrate_spam_1_9_2();
			}
			if ( version_compare( $installed_version, '1.9.3', '<' ) ) {
				self::migrate_geo_1_9_3();
			}
			// L'IP de récupération est celle de l'administrateur : jamais celle d'un
			// visiteur anonyme qui déclencherait la migration.
			if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				self::refresh_activation_whitelist();
			}
			// (Re)planifie les tâches si un hook manque après la migration.
			CU_Cron::instance()->schedule_events();
			update_option( CU_OPTION_DB_VERSION, CU_DB_VERSION );
		} finally {
			CU_DB::lock_release( 'cu_upgrade_lock', $lock );
		}
	}

	/**
	 * 1.9.3 : le pays des visiteurs vient par défaut d'une base locale (DB-IP
	 * Lite, téléchargée par le site : l'IP est lue en mémoire, jamais transmise ni
	 * enregistrée). Un site resté sur « Aucun fournisseur » — l'ancien réglage par
	 * défaut, qui ne donnait un pays que derrière Cloudflare — passe à la base
	 * locale ; un fournisseur déjà choisi (WooCommerce, MaxMind, ipwho.is) est
	 * conservé. Aucune table ni colonne modifiée. Exécutée une seule fois : revenir
	 * ensuite à « Aucun » dans les réglages reste possible.
	 */
	private static function migrate_geo_1_9_3() {
		$analytics = CU_Settings::module( 'analytics' );
		if ( isset( $analytics['geo_provider'] ) && 'none' === $analytics['geo_provider'] ) {
			CU_Settings::update_module( 'analytics', array( 'geo_lookup_enabled' => true, 'geo_provider' => 'local' ), false );
		}
		if ( CU_Geo::local_provider_active() && '' === CU_Geo::local_db_path() ) {
			CU_Geo::schedule_local_db_update();
		}
	}

	/**
	 * 1.9.0 : retire les réglages sans effet, transfère le compteur de jetons IA
	 * dans sa propre option (incrément atomique) et migre le mode Firewall
	 * « personnalisé » (qui se comportait exactement comme « normal »).
	 */
	private static function migrate_settings_1_9_0() {
		CU_Settings::acquire_write_lock();
		try {
			self::migrate_settings_1_9_0_locked();
		} finally {
			CU_Settings::release_write_lock();
		}
		// IP des listes Firewall stockées avant la normalisation IPv6.
		if ( method_exists( 'CU_Firewall', 'migrate_normalize_ips' ) && CU_DB::tables_exist_for( array( 'firewall_blocks', 'firewall_whitelist' ) ) ) {
			CU_Firewall::migrate_normalize_ips();
		}
	}

	private static function migrate_settings_1_9_0_locked() {
		$stored = get_option( CU_OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			return;
		}
		if ( isset( $stored['ai']['tokens_used_total'] ) && false === get_option( 'cu_ai_tokens_used', false ) ) {
			add_option( 'cu_ai_tokens_used', max( 0, (int) $stored['ai']['tokens_used_total'] ), '', false );
		}
		if ( isset( $stored['firewall']['mode'] ) && 'personnalise' === $stored['firewall']['mode'] ) {
			$stored['firewall']['mode'] = 'normal';
		}
		// Ancienne interface : « api » et « free » désignaient le même service.
		if ( isset( $stored['analytics']['geo_provider'] ) && 'api' === $stored['analytics']['geo_provider'] ) {
			$stored['analytics']['geo_provider'] = 'free';
		}
		foreach ( CU_Settings::obsolete_keys() as $module => $keys ) {
			if ( true === $keys ) {
				unset( $stored[ $module ] );
			} elseif ( isset( $stored[ $module ] ) && is_array( $stored[ $module ] ) ) {
				foreach ( $keys as $key ) {
					unset( $stored[ $module ][ $key ] );
				}
			}
		}
		update_option( CU_OPTION_SETTINGS, $stored );
		// Les rapports en cache ont été calculés avec les anciennes règles de comptage.
		update_option( 'cu_analytics_cache_epoch', wp_generate_uuid4(), false );
	}

	/**
	 * 1.9.1 : mesure anonyme. dbDelta ajoute sessions.anon (0 = visite suivie en
	 * continu : exact pour toutes les lignes existantes) et
	 * content_daily.measured_sessions, à 0 par défaut. Avant 1.9.1, toutes les
	 * visites étaient suivies en continu : le dénominateur des taux par contenu
	 * (rebond…) des journées déjà agrégées est donc leur nombre de visites. Sans
	 * cela, l'historique afficherait « n/d » au lieu de son taux réel.
	 *
	 * Uniquement quand la colonne vient d'être créée : si elle existait déjà
	 * (migration rejouée, version effacée, rétrogradation puis remise à niveau),
	 * une ligne à 0 peut être une journée de visites anonymes seules, qu'il ne
	 * faut jamais réécrire.
	 *
	 * @param bool $column_existed La colonne measured_sessions existait avant dbDelta.
	 */
	private static function migrate_analytics_1_9_1( $column_existed = false ) {
		global $wpdb;
		if ( ! $column_existed && CU_DB::tables_exist_for( array( 'content_daily' ) ) ) {
			$wpdb->query( 'UPDATE ' . CU_DB::table( 'content_daily' ) . ' SET measured_sessions = sessions WHERE measured_sessions = 0 AND sessions > 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		// Les rapports en cache ignorent la distinction visites suivies / anonymes.
		update_option( 'cu_analytics_cache_epoch', wp_generate_uuid4(), false );
	}

	/**
	 * 1.9.2 : module Spam. dbDelta crée cu_spam_log. Le délai de grâce démarre
	 * maintenant, et les caches de page sont purgés une fois pour que le HTML
	 * servi contienne les champs de protection des formulaires.
	 */
	private static function migrate_spam_1_9_2() {
		if ( ! CU_Spam::honeypot_active() ) {
			return;
		}
		CU_Spam::mark_since( true );
		try {
			CU_Tracker::purge_page_caches();
		} catch ( Throwable $e ) {
			unset( $e ); // une extension de cache défaillante ne bloque pas la migration
		}
	}

	/** Remplace l'ancienne whitelist d'activation sans toucher aux entrées manuelles. */
	private static function refresh_activation_whitelist() {
		if ( ! CU_DB::tables_exist_for( array( 'firewall_whitelist' ) ) ) {
			return;
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_whitelist' );
		// Marqueur persistant, volontairement non traduit : un changement de langue
		// ne doit jamais rendre l'ancienne IP automatique impossible à retrouver.
		$label        = 'CyberPush — IP de récupération automatique';
		$legacy_label = 'Administrateur (activation du plugin)';
		$old_labels   = array_unique( array( $label, $legacy_label, __( $legacy_label, 'cyberpush-ultra' ) ) );
		foreach ( $old_labels as $old_label ) {
			$deleted = $wpdb->delete( $table, array( 'label' => $old_label ) );
			if ( false === $deleted ) {
				CU_Logger::log( 'firewall', 'error', __( 'Impossible de rafraîchir l’IP de récupération automatique.', 'cyberpush-ultra' ), array(
					'db_error' => sanitize_text_field( (string) $wpdb->last_error ),
				) );
				return;
			}
		}

		$ip = CU_Firewall::get_client_ip();
		// Une IP privée (relais interne, test local) n'identifie pas
		// l'administrateur : la mettre en liste blanche exempterait tout le trafic
		// passant par ce relais.
		if ( $ip && CU_Geo::is_public_ip( $ip ) ) {
			// Une entrée manuelle existante pour la même IP reste intacte. La clé
			// UNIQUE(ip_or_cidr) interdit de toute façon les doublons.
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE ip_or_cidr = %s", $ip ) );
			if ( $wpdb->last_error || $existing ) {
				return;
			}
			// Exemption temporaire (7 jours) : une IP dynamique, de VPN ou de
			// Wi-Fi public est réattribuée à d'autres personnes.
			$inserted = $wpdb->insert(
				$table,
				array(
					'ip_or_cidr' => $ip,
					'label'      => $label,
					'created_at' => current_time( 'mysql' ),
					'expires_at' => CU_DB::date_from_now( 'Y-m-d H:i:s', 7 * DAY_IN_SECONDS ),
				)
			);
			if ( false === $inserted ) {
				CU_Logger::log( 'firewall', 'error', __( 'Impossible d’ajouter l’IP de récupération automatique.', 'cyberpush-ultra' ), array(
					'ip'       => $ip,
					'db_error' => sanitize_text_field( (string) $wpdb->last_error ),
				) );
			}
		}
		// La liste blanche est mise en cache par le pare-feu.
		if ( method_exists( 'CU_Firewall', 'flush_list_cache' ) ) {
			CU_Firewall::flush_list_cache();
		}
	}

	/**
	 * Nettoyage des vestiges de l'ANCIENNE partie SEO (tables seo_urls/seo_queue
	 * et son option de cache), retirée avant la reconstruction du module SEO.
	 * Idempotent (IF EXISTS) — sans effet une fois le nettoyage effectué.
	 * NB : on ne touche PAS au hook cron cu_cron_seo_scan_cycle, désormais
	 * réutilisé par le nouveau module SEO.
	 */
	private static function cleanup_legacy_seo() {
		global $wpdb;
		foreach ( array( 'seo_urls', 'seo_queue' ) as $short ) {
			$table = $wpdb->prefix . 'cu_' . $short;
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( 'cu_seo_previous_summary' );
	}

	/**
	 * Nettoie uniquement les listes d'exemple exactes livrées par les premières
	 * versions. Toute liste différente est considérée comme personnalisée.
	 */
	private static function cleanup_legacy_analytics_defaults() {
		CU_Settings::acquire_write_lock();
		try {
			return self::cleanup_legacy_analytics_defaults_locked();
		} finally {
			CU_Settings::release_write_lock();
		}
	}

	private static function cleanup_legacy_analytics_defaults_locked() {
		$stored = get_option( CU_OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			return true;
		}

		$changed = false;
		if ( isset( $stored['analytics']['excluded_countries'] ) && is_array( $stored['analytics']['excluded_countries'] ) ) {
			$countries = array();
			foreach ( $stored['analytics']['excluded_countries'] as $raw_country ) {
				if ( is_scalar( $raw_country ) ) {
					$countries[] = strtoupper( trim( (string) $raw_country ) );
				}
			}
			$countries = array_values( array_unique( $countries ) );
			sort( $countries );
			if ( array( 'MA', 'US' ) === $countries ) {
				$stored['analytics']['excluded_countries'] = array();
				$changed = true;
			}
		}

		return ! $changed || update_option( CU_OPTION_SETTINGS, $stored );
	}

	/**
	 * Remplace uniquement les anciennes listes Firewall strictement intactes et
	 * retire la signature ambiguë "x8" lorsqu'elle est présente seule.
	 * Les bibliothèques HTTP génériques ne prouvent pas une attaque et causaient
	 * des faux positifs sur les webhooks/API ; les personnalisations sont gardées.
	 * La transformation est idempotente : un second passage ne modifie rien.
	 */
	private static function cleanup_legacy_firewall_signatures() {
		CU_Settings::acquire_write_lock();
		try {
			self::cleanup_legacy_firewall_signatures_locked();
		} finally {
			CU_Settings::release_write_lock();
		}
	}

	private static function cleanup_legacy_firewall_signatures_locked() {
		$stored = get_option( CU_OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) || empty( $stored['firewall'] ) || ! is_array( $stored['firewall'] ) ) {
			return;
		}

		$legacy_scanners = array(
			'sqlmap', 'nikto', 'nuclei', 'wpscan', 'masscan', 'nmap', 'zgrab', 'zmap',
			'dirbuster', 'gobuster', 'feroxbuster', 'ffuf', 'wfuzz', 'dirb',
			'acunetix', 'netsparker', 'nessus', 'openvas', 'arachni', 'skipfish', 'w3af',
			'whatweb', 'joomscan', 'droopescan', 'commix', 'xsser', 'fimap', 'hydra', 'medusa',
			'python-requests', 'go-http-client', 'curl/',
			'httpx', 'katana', 'dalfox', 'x8', 'nuclei', 'httrack', 'zgrab2', 'puppeteer-scanner',
		);
		$legacy_bad_bots = array(
			'MJ12bot', 'DotBot', 'BLEXBot', 'Bytespider', 'DataForSeoBot',
			'ZoominfoBot', 'serpstatbot', 'Barkrowler', 'MegaIndex', 'SEOkicks',
			'SiteSnagger', 'HTTrack', 'WebZIP', 'WebCopier', 'Teleport', 'libwww-perl',
			'Java/', 'zgrab', 'Nimbostratus', 'magpie-crawler', 'semrushbot-ba',
		);
		$defaults = CU_Settings::get_defaults();
		$changed  = false;

		if ( isset( $stored['firewall']['scanner_signatures'] ) && $legacy_scanners === $stored['firewall']['scanner_signatures'] ) {
			$stored['firewall']['scanner_signatures'] = $defaults['firewall']['scanner_signatures'];
			$changed = true;
		}
		if ( isset( $stored['firewall']['scanner_signatures'] ) && is_array( $stored['firewall']['scanner_signatures'] ) ) {
			$scanner_signatures = array();
			foreach ( $stored['firewall']['scanner_signatures'] as $signature ) {
				if ( is_string( $signature ) && 0 === strcasecmp( trim( $signature ), 'x8' ) ) {
					$changed = true;
					continue;
				}
				$scanner_signatures[] = $signature;
			}
			$stored['firewall']['scanner_signatures'] = $scanner_signatures;
		}
		if ( isset( $stored['firewall']['bad_bot_signatures'] ) && $legacy_bad_bots === $stored['firewall']['bad_bot_signatures'] ) {
			$stored['firewall']['bad_bot_signatures'] = $defaults['firewall']['bad_bot_signatures'];
			$changed = true;
		}

		if ( $changed ) {
			update_option( CU_OPTION_SETTINGS, $stored );
		}
	}
}

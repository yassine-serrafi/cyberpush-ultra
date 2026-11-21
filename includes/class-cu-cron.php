<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Planification des tâches d'arrière-plan (WP-Cron).
 * Les modules s'accrochent à ces hooks pour rester légers et ne jamais
 * ralentir le chargement du site public.
 */
class CU_Cron {

	private static $instance = null;
	private const ROLLUP_LOCK_OPTION   = 'cu_rollup_daily_lock';
	private const ROLLUP_LOCK_TTL      = 15 * MINUTE_IN_SECONDS;
	/** Première journée encore à (re)calculer par l'agrégation (Y-m-d, heure du site). */
	private const ROLLUP_CURSOR_OPTION = 'cu_rollup_cursor';
	/** Journées au plus par passage : un rattrapage après un long arrêt est étalé. */
	private const ROLLUP_MAX_DAYS      = 7;
	/** Lignes par INSERT multi-valeurs. */
	private const ROLLUP_INSERT_CHUNK  = 200;
	/** Lignes supprimées par DELETE … LIMIT lors du nettoyage. */
	private const CLEANUP_BATCH        = 5000;
	/** Budget de temps du nettoyage quotidien (s). */
	private const CLEANUP_BUDGET       = 25;

	/** Le nettoyage s'est arrêté sur son budget : un passage de reprise est programmé. */
	private $cleanup_unfinished = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );

		// « cu_cron_rollup_daily » s'exécute toutes les HEURES (nom historique
		// conservé pour la compatibilité des tâches déjà planifiées).
		add_action( 'cu_cron_rollup_daily', array( $this, 'cron_rollup_daily' ) );
		// Plusieurs wp-cron.php simultanés (trafic, cron système, appel manuel)
		// peuvent dépiler la même tâche : un verrou court par tâche évite la
		// double exécution (le SEO et l'audit d'intégrité ont leur propre verrou).
		add_action( 'cu_cron_analytics_enrich', array( $this, 'cron_enrich' ) );
		// Géolocalisation ponctuelle d'un nouveau visiteur (pays visible dans le temps réel).
		add_action( 'cu_cron_analytics_enrich_now', array( $this, 'cron_enrich' ) );
		add_action( 'cu_cron_seo_scan_cycle', array( 'CU_SEO', 'run_scan_cycle' ) );
		add_action( 'cu_cron_firewall_maintenance', array( $this, 'cron_firewall_maintenance' ) );
		add_action( 'cu_cron_cleanup', array( $this, 'cron_cleanup' ) );
		// Base locale IP → pays : téléchargement en arrière-plan (activation, mise à jour).
		add_action( CU_Geo::LOCAL_DB_HOOK, array( 'CU_Geo', 'update_local_db' ) );
		// Option « cron » vidée (extension de nettoyage, restauration partielle) :
		// les tâches ne seraient replanifiées qu'à la prochaine activation.
		add_action( 'admin_init', array( $this, 'maybe_reschedule' ) );
	}

	/** Replanifie les tâches récurrentes manquantes (lecture seule si rien ne manque). */
	public function maybe_reschedule() {
		foreach ( array( 'cu_cron_rollup_daily', 'cu_cron_analytics_enrich', 'cu_cron_seo_scan_cycle', 'cu_cron_firewall_maintenance', 'cu_cron_cleanup', 'cu_cron_audit_scan', 'cu_cron_vuln_sync' ) as $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				$this->schedule_events();
				return;
			}
		}
	}

	/** Agrégation horaire (verrou propre dans run_rollup_daily) + tâches récurrentes en double. */
	public function cron_rollup_daily() {
		$this->dedupe_recurring_events();
		$this->run_rollup_daily();
	}

	public function cron_enrich() {
		$this->run_exclusive( 'enrich', 10 * MINUTE_IN_SECONDS, array( 'CU_Analytics', 'run_enrichment_queue' ) );
	}

	public function cron_firewall_maintenance() {
		$this->run_exclusive( 'firewall_maintenance', 10 * MINUTE_IN_SECONDS, array( 'CU_Firewall', 'run_maintenance' ) );
		// Compteurs de débit expirés (pare-feu, collecte, IA, spam) : balayés toutes
		// les 5 minutes pour que wp_options ne garde pas une ligne par IP et par fenêtre.
		CU_DB::counter_purge_expired();
	}

	public function cron_cleanup() {
		$this->run_cleanup();
	}

	/**
	 * Exécute une tâche sous verrou court (option unique, INSERT IGNORE). Une
	 * seconde exécution simultanée est simplement ignorée.
	 *
	 * @return mixed|false Valeur de la tâche, false si elle tourne déjà.
	 */
	private function run_exclusive( $name, $ttl, $callback ) {
		if ( ! is_callable( $callback ) ) {
			return false;
		}
		$option = 'cu_lock_cron_' . $name;
		$token  = CU_DB::lock_acquire( $option, $ttl );
		if ( false === $token ) {
			return false;
		}
		try {
			return call_user_func( $callback );
		} finally {
			CU_DB::lock_release( $option, $token );
		}
	}

	/**
	 * Supprime les occurrences RÉCURRENTES en double d'une tâche du plugin
	 * (course connue de WP-Cron quand plusieurs wp-cron.php replanifient la même
	 * tâche en parallèle). Les reprises ponctuelles ne sont pas touchées ; la
	 * plus proche occurrence est conservée.
	 */
	private function dedupe_recurring_events() {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return;
		}
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}
		ksort( $crons );
		$seen = array();
		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( 0 !== strpos( (string) $hook, 'cu_cron_' ) || ! is_array( $events ) ) {
					continue;
				}
				foreach ( $events as $event ) {
					if ( empty( $event['schedule'] ) ) {
						continue;
					}
					$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
					$sig  = $hook . '|' . md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					if ( isset( $seen[ $sig ] ) ) {
						wp_unschedule_event( (int) $timestamp, $hook, $args );
					} else {
						$seen[ $sig ] = true;
					}
				}
			}
		}
	}

	public function add_schedules( $schedules ) {
		if ( ! isset( $schedules['cu_five_minutes'] ) ) {
			$schedules['cu_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Toutes les 5 minutes (CyberPush Ultra)', 'cyberpush-ultra' ),
			);
		}
		return $schedules;
	}

	public function schedule_events() {
		// Pendant l'activation d'une extension, `plugins_loaded` a souvent déjà
		// été exécuté : init() n'a donc pas encore pu enregistrer cette fréquence.
		// L'ajout est idempotent et garantit que le cron Firewall est réellement
		// créé dès la toute première activation.
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );

		if ( ! wp_next_scheduled( 'cu_cron_rollup_daily' ) ) {
			wp_schedule_event( time(), 'hourly', 'cu_cron_rollup_daily' );
		}
		if ( ! wp_next_scheduled( 'cu_cron_analytics_enrich' ) ) {
			wp_schedule_event( time() + 60, 'cu_five_minutes', 'cu_cron_analytics_enrich' );
		}
		if ( ! wp_next_scheduled( 'cu_cron_seo_scan_cycle' ) ) {
			wp_schedule_event( time() + 120, 'hourly', 'cu_cron_seo_scan_cycle' );
		}
		if ( ! wp_next_scheduled( 'cu_cron_firewall_maintenance' ) ) {
			wp_schedule_event( time(), 'cu_five_minutes', 'cu_cron_firewall_maintenance' );
		}
		if ( ! wp_next_scheduled( 'cu_cron_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'cu_cron_cleanup' );
		}
		if ( ! wp_next_scheduled( 'cu_cron_audit_scan' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'cu_cron_audit_scan' );
		}
		// Vulnérabilités & versions : une vérification par jour (par lots).
		if ( class_exists( 'CU_Vulns' ) ) {
			CU_Vulns::schedule( 600 );
		}
	}

	public function unschedule_events() {
		// wp_clear_scheduled_hook supprime toutes les occurrences, pas seulement
		// la prochaine. C'est important pour les reprises ponctuelles de l'audit.
		foreach ( array( 'cu_cron_rollup_daily', 'cu_cron_analytics_enrich', 'cu_cron_analytics_enrich_now', 'cu_cron_seo_scan_cycle', 'cu_cron_firewall_maintenance', 'cu_cron_cleanup', 'cu_cron_audit_scan', 'cu_cron_audit_scan_continue', 'cu_cron_indexnow_flush', 'cu_fw_rdns_verify', 'cu_cron_vuln_sync', 'cu_cron_vuln_continue' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Acquiert un verrou inter-processus pour l'agrégation Analytics.
	 *
	 * INSERT IGNORE (CU_DB::lock_acquire) : add_option() vérifie puis écrit
	 * (INSERT … ON DUPLICATE KEY UPDATE), ce que deux workers simultanés
	 * « réussissaient » tous les deux. Un verrou orphelin est repris par
	 * compare-et-échange de sa valeur exacte.
	 *
	 * @return string|false Jeton propriétaire, ou false si un rollup est actif.
	 */
	private function acquire_rollup_lock() {
		return CU_DB::lock_acquire( self::ROLLUP_LOCK_OPTION, self::ROLLUP_LOCK_TTL );
	}

	/** Libère uniquement le verrou encore détenu par ce worker. */
	private function release_rollup_lock( $token ) {
		CU_DB::lock_release( self::ROLLUP_LOCK_OPTION, $token );
	}

	/**
	 * Agrège les événements bruts (cu_events + cu_sessions) en lignes
	 * journalières par contenu (cu_content_daily), pour que les tableaux de
	 * performance restent rapides même sur de gros sites. Lancé toutes les heures.
	 *
	 * Incrémental : chaque passage recalcule la veille et le jour en cours (les
	 * sessions sont reclassées après coup par l'enrichissement réseau) et, si le
	 * cron s'est arrêté, rattrape les journées manquantes depuis le curseur, au
	 * plus ROLLUP_MAX_DAYS par passage. Chaque requête est bornée à une journée.
	 *
	 * @return bool True après validation complète, false si le traitement est ignoré ou échoue.
	 */
	public function run_rollup_daily() {
		if ( ! CU_DB::tables_exist_for( array( 'sessions', 'events', 'content_daily' ) ) ) {
			return false;
		}
		$lock_token = $this->acquire_rollup_lock();
		if ( false === $lock_token ) {
			return false;
		}

		try {
			return $this->run_rollup_daily_locked();
		} finally {
			$this->release_rollup_lock( $lock_token );
		}
	}

	/** Exécute le calcul une fois le verrou inter-processus acquis. */
	private function run_rollup_daily_locked() {
		$today     = CU_DB::date_from_now( 'Y-m-d' );
		$yesterday = CU_DB::date_from_now( 'Y-m-d', -DAY_IN_SECONDS );
		// Plancher de rattrapage = durée de conservation réelle : celle du
		// nettoyage automatique s'il est actif, sinon le plafond de 25 mois.
		$days_kept = CU_Settings::get( 'performance', 'auto_cleanup_enabled', true )
			? max( 1, min( 760, (int) CU_Settings::get( 'performance', 'auto_cleanup_days', 90 ) ) )
			: 760;
		$floor     = CU_DB::date_from_now( 'Y-m-d', -$days_kept * DAY_IN_SECONDS );
		$cursor    = (string) get_option( self::ROLLUP_CURSOR_OPTION, '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $cursor ) ) {
			// Première exécution : même fenêtre que les versions précédentes.
			$cursor = CU_DB::date_from_now( 'Y-m-d', -2 * DAY_IN_SECONDS );
		}
		// Dates Y-m-d : l'ordre lexicographique est l'ordre chronologique.
		$cursor = max( $cursor, $floor );
		$cursor = min( $cursor, $yesterday );

		$max_exec = (int) ini_get( 'max_execution_time' );
		$deadline = microtime( true ) + ( $max_exec > 0 ? max( 10, min( 120, (int) floor( $max_exec / 2 ) ) ) : 120 );
		$day      = $cursor;
		$done     = 0;
		while ( $day <= $today && $done < self::ROLLUP_MAX_DAYS ) {
			if ( $done > 0 && microtime( true ) > $deadline ) {
				break;
			}
			if ( ! $this->rollup_day( $day ) ) {
				update_option( self::ROLLUP_CURSOR_OPTION, $day, false ); // reprise au jour en échec
				return false;
			}
			$done++;
			$day = gmdate( 'Y-m-d', (int) strtotime( $day . ' +1 day' ) );
		}
		$caught_up = $day > $today;
		update_option( self::ROLLUP_CURSOR_OPTION, $caught_up ? $yesterday : min( $day, $yesterday ), false );
		if ( ! $caught_up ) {
			// Rattrapage en cours : un nouveau passage dans une minute plutôt qu'une heure.
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'cu_cron_rollup_daily' );
		}

		CU_Logger::log( 'system', 'debug', sprintf( /* translators: %d: nombre de journées */ __( 'Agrégation des statistiques exécutée (%d journée(s) recalculée(s)).', 'cyberpush-ultra' ), $done ) );
		return true;
	}

	/** Lecture SQL de l'agrégation : false en cas d'erreur (jamais un faux tableau vide). */
	private function rollup_read( $sql ) {
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return $rows;
	}

	/**
	 * Recalcule une journée de cu_content_daily. Lectures bornées à la journée
	 * (± 1 jour pour retrouver la première / dernière page vue d'une session à
	 * cheval sur minuit), écriture dans une transaction : la journée est
	 * remplacée entièrement ou pas du tout.
	 */
	private function rollup_day( $day ) {
		global $wpdb;
		$events   = CU_DB::table( 'events' );
		$sessions = CU_DB::table( 'sessions' );
		$daily    = CU_DB::table( 'content_daily' );
		$start    = $day . ' 00:00:00';
		$end      = $day . ' 23:59:59';
		$before   = gmdate( 'Y-m-d', (int) strtotime( $day . ' -1 day' ) ) . ' 00:00:00';
		$after    = gmdate( 'Y-m-d', (int) strtotime( $day . ' +1 day' ) ) . ' 23:59:59';

		// « Sans faux résultat » : les stats de contenu ne comptent QUE le trafic
		// humain fiable (ni bot, ni datacenter, ni proxy, ni exclu).
		$rel = ' AND s.is_reliable = 1 AND s.is_bot = 0 AND s.is_datacenter = 0 AND s.is_proxy = 0 AND s.is_excluded = 0 ';
		// Niveau de mesure (schéma 1.9.1) : 0 = visite suivie en continu, 1 = page
		// anonyme d'entrée, 2 = page anonyme interne. Avant la migration : tout est suivi.
		$anon_ready = CU_Analytics::anon_ready();
		$a          = $anon_ready ? 's.anon' : '0';

		// Agrégat principal par contenu. La jointure sur wp_posts écarte tout
		// identifiant qui ne désigne pas un contenu existant (beacons forgés ou
		// antérieurs à la validation) : la table ne peut pas grossir au-delà du
		// nombre de contenus réellement consultés. « sessions » = visites suivies
		// ayant vu le contenu + pages vues anonymes du contenu (chacune compte une
		// fois : sans identifiant, deux pages anonymes ne peuvent pas être reliées).
		// Rebond = page vue d'une visite SUIVIE à une seule page (même définition
		// que le résumé), rapporté aux seules visites suivies (measured_sessions).
		$base = $this->rollup_read( $wpdb->prepare(
			"SELECT e.object_id, e.object_type, MAX(p.post_type) AS post_type,
				COUNT(DISTINCT CASE WHEN e.event_type = 'pageview' THEN e.session_id END) AS sessions,
				COUNT(DISTINCT CASE WHEN e.event_type = 'pageview' AND {$a} = 0 THEN e.session_id END) AS measured_sessions,
				SUM(e.event_type = 'pageview') AS pageviews,
				COUNT(DISTINCT CASE WHEN e.event_type = 'pageview' AND {$a} = 0 AND s.pageviews <= 1 THEN e.session_id END) AS bounces,
				SUM(e.event_type = 'cta_click') AS cta_clicks,
				SUM(e.event_type = 'conversion') AS conversions,
				COALESCE(SUM(CASE WHEN e.event_type = 'exit' THEN e.duration END), 0) AS total_duration
			FROM {$events} e
			INNER JOIN {$sessions} s ON s.id = e.session_id
			INNER JOIN {$wpdb->posts} p ON p.ID = e.object_id
			WHERE e.created_at BETWEEN %s AND %s AND e.object_id IS NOT NULL
				AND e.object_type IS NOT NULL AND e.object_type <> '' {$rel}
			GROUP BY e.object_id, e.object_type",
			$start,
			$end
		) );
		if ( false === $base ) {
			return $this->rollup_failed( __( 'Échec SQL pendant la lecture des données Analytics à agréger.', 'cyberpush-ultra' ) );
		}

		$key = static function ( $row ) {
			return (int) $row['object_id'] . '|' . $row['object_type'];
		};
		$map = array();
		foreach ( $base as $row ) {
			// Type cohérent avec le contenu réel (comparé en PHP : aucune comparaison
			// entre colonnes de collations différentes).
			if ( (string) $row['post_type'] !== (string) $row['object_type'] ) {
				continue;
			}
			$map[ $key( $row ) ] = array(
				'object_id'      => (int) $row['object_id'],
				'object_type'    => substr( (string) $row['object_type'], 0, 30 ),
				'sessions'       => (int) $row['sessions'],
				'measured_sessions' => (int) $row['measured_sessions'],
				'pageviews'      => (int) $row['pageviews'],
				'entries'        => 0,
				'exits'          => 0,
				'bounces'        => (int) $row['bounces'],
				'total_duration' => max( 0, (int) $row['total_duration'] ),
				'cta_clicks'     => (int) $row['cta_clicks'],
				'conversions'    => (int) $row['conversions'],
				'avg_scroll'     => 0,
			);
		}

		if ( $map ) {
			// Profondeur : maximum atteint par session ayant réellement vu le contenu ;
			// une session sans défilement compte 0.
			$scrolls = $this->rollup_read( $wpdb->prepare(
				"SELECT pv.object_id, pv.object_type, AVG(COALESCE(sc.max_scroll, 0)) AS avg_scroll
				FROM (
					SELECT p.object_id, p.object_type, p.session_id
					FROM {$events} p
					INNER JOIN {$sessions} s ON s.id = p.session_id
					WHERE p.event_type = 'pageview' AND p.created_at BETWEEN %s AND %s
						AND p.object_id IS NOT NULL AND p.object_type IS NOT NULL AND p.object_type <> '' {$rel}
					GROUP BY p.object_id, p.object_type, p.session_id
				) pv
				LEFT JOIN (
					SELECT se.object_id, se.object_type, se.session_id, MAX(se.scroll_percent) AS max_scroll
					FROM {$events} se
					WHERE se.event_type = 'scroll' AND se.created_at BETWEEN %s AND %s
						AND se.object_id IS NOT NULL AND se.object_type IS NOT NULL AND se.object_type <> ''
					GROUP BY se.object_id, se.object_type, se.session_id
				) sc ON sc.object_id = pv.object_id AND sc.object_type = pv.object_type AND sc.session_id = pv.session_id
				GROUP BY pv.object_id, pv.object_type",
				$start,
				$end,
				$start,
				$end
			) );
			// Entrées : première page vue de la session (recherchée depuis la veille).
			// Une page anonyme interne (anon = 2) n'est jamais une entrée.
			$entries = $this->rollup_read( $wpdb->prepare(
				"SELECT e.object_id, e.object_type, COUNT(*) AS c
				FROM {$events} e
				INNER JOIN (
					SELECT session_id, MIN(id) AS pick FROM {$events}
					WHERE event_type = 'pageview' AND created_at BETWEEN %s AND %s
					GROUP BY session_id
				) f ON f.pick = e.id
				INNER JOIN {$sessions} s ON s.id = e.session_id
				WHERE e.created_at BETWEEN %s AND %s AND e.object_id IS NOT NULL {$rel} AND {$a} < 2
				GROUP BY e.object_id, e.object_type",
				$before,
				$end,
				$start,
				$end
			) );
			// Sorties : dernière page vue de la session (recherchée jusqu'au lendemain).
			// Visites suivies seulement : après une page anonyme, la suite est inconnue.
			$exits = $this->rollup_read( $wpdb->prepare(
				"SELECT e.object_id, e.object_type, COUNT(*) AS c
				FROM {$events} e
				INNER JOIN (
					SELECT session_id, MAX(id) AS pick FROM {$events}
					WHERE event_type = 'pageview' AND created_at BETWEEN %s AND %s
					GROUP BY session_id
				) f ON f.pick = e.id
				INNER JOIN {$sessions} s ON s.id = e.session_id
				WHERE e.created_at BETWEEN %s AND %s AND e.object_id IS NOT NULL {$rel} AND {$a} = 0
				GROUP BY e.object_id, e.object_type",
				$start,
				$after,
				$start,
				$end
			) );
			if ( false === $scrolls || false === $entries || false === $exits ) {
				return $this->rollup_failed( __( 'Échec SQL pendant la lecture des données Analytics à agréger.', 'cyberpush-ultra' ) );
			}
			foreach ( $scrolls as $row ) {
				if ( isset( $map[ $key( $row ) ] ) ) {
					$map[ $key( $row ) ]['avg_scroll'] = max( 0, min( 100, (int) round( (float) $row['avg_scroll'] ) ) );
				}
			}
			foreach ( array( 'entries' => $entries, 'exits' => $exits ) as $column => $rows ) {
				foreach ( $rows as $row ) {
					if ( isset( $map[ $key( $row ) ] ) ) {
						$map[ $key( $row ) ][ $column ] = (int) $row['c'];
					}
				}
			}
		}

		// Remplacement atomique de la journée : une ligne devenue vide (sessions
		// reclassées après coup) disparaît, les autres sont écrites par lots.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->rollup_failed( __( 'Impossible de démarrer la transaction des agrégats Analytics.', 'cyberpush-ultra' ) );
		}
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE stat_date = %s", $day ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->rollup_failed( __( 'Impossible de préparer le recalcul des agrégats Analytics.', 'cyberpush-ultra' ) );
		}
		$columns = array( 'object_id', 'object_type', 'sessions', 'pageviews', 'entries', 'exits', 'bounces', 'total_duration', 'cta_clicks', 'conversions', 'avg_scroll' );
		if ( $anon_ready ) {
			$columns[] = 'measured_sessions';
		}
		$update  = implode( ', ', array_map( static function ( $column ) {
			return "{$column} = VALUES({$column})";
		}, array_slice( $columns, 2 ) ) );
		// (%s : date, %d : identifiant, %s : type, puis un %d par colonne numérique.)
		$group_format = '(%s, %d, %s' . str_repeat( ', %d', count( $columns ) - 2 ) . ')';
		foreach ( array_chunk( array_values( $map ), self::ROLLUP_INSERT_CHUNK ) as $chunk ) {
			$groups = array();
			$values = array();
			foreach ( $chunk as $row ) {
				$groups[] = $group_format;
				$values[] = $day;
				foreach ( $columns as $column ) {
					$values[] = $row[ $column ];
				}
			}
			$sql = "INSERT INTO {$daily} (stat_date, " . implode( ', ', $columns ) . ') VALUES ' . implode( ', ', $groups ) . " ON DUPLICATE KEY UPDATE {$update}";
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $values ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->rollup_failed( __( 'Un ou plusieurs agrégats Analytics n’ont pas pu être enregistrés.', 'cyberpush-ultra' ) );
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->rollup_failed( __( 'Impossible de valider les agrégats Analytics.', 'cyberpush-ultra' ) );
		}
		return true;
	}

	private function rollup_failed( $message ) {
		global $wpdb;
		CU_Logger::log( 'system', 'error', $message, array( 'db_error' => CU_Text::substr( sanitize_text_field( (string) $wpdb->last_error ), 0, 300 ) ) );
		return false;
	}

	/**
	 * Nettoyage quotidien : rétention par table (chaque table est traitée si elle
	 * existe, indépendamment des autres), suppressions par lots DELETE … LIMIT
	 * avec un budget de temps, puis tâches de confidentialité de l'Analytics. Le
	 * Journal de sécurité garde sa propre purge (CU_Audit::purge_old).
	 *
	 * @return bool|null true : terminé sans erreur SQL ; false : erreur SQL ;
	 *                   null : un autre nettoyage est déjà en cours.
	 */
	public function run_cleanup() {
		$lock = CU_DB::lock_acquire( 'cu_lock_cron_cleanup', 30 * MINUTE_IN_SECONDS );
		if ( false === $lock ) {
			return null;
		}
		try {
			$this->dedupe_recurring_events();
			// Compteurs de débit dont la fenêtre est terminée (CU_DB::counter_increment).
			CU_DB::counter_purge_expired();
			return $this->run_cleanup_locked();
		} finally {
			CU_DB::lock_release( 'cu_lock_cron_cleanup', $lock );
		}
	}

	private function run_cleanup_locked() {
		$enabled  = (bool) CU_Settings::get( 'performance', 'auto_cleanup_enabled', true );
		$days     = max( 1, min( 760, (int) CU_Settings::get( 'performance', 'auto_cleanup_days', 90 ) ) );
		$exempt   = class_exists( 'CU_Analytics' ) && 'exempt' === CU_Analytics::privacy_mode();
		$deadline = microtime( true ) + self::CLEANUP_BUDGET;
		$ok       = true;
		$this->cleanup_unfinished = false;

		// Mode exempté : statistiques conservées 25 mois au plus (760 jours), même
		// si le nettoyage automatique a été désactivé.
		if ( $enabled || $exempt ) {
			$a_cutoff = CU_DB::date_from_now( 'Y-m-d H:i:s', -( $enabled ? $days : 760 ) * DAY_IN_SECONDS );
			$jobs     = array(
				array( 'events', 'created_at < %s', $a_cutoff ),
				// Les sessions suivent la même rétention que les événements.
				array( 'sessions', 'last_seen < %s', $a_cutoff ),
				array( 'content_daily', 'stat_date < %s', substr( $a_cutoff, 0, 10 ) ),
				array( 'contacts', 'created_at < %s', $a_cutoff ),
			);
			if ( $enabled ) {
				$cutoff = CU_DB::date_from_now( 'Y-m-d H:i:s', -$days * DAY_IN_SECONDS );
				$jobs[] = array( 'firewall_logs', 'created_at < %s', $cutoff );
				$jobs[] = array( 'firewall_blocks', 'expires_at IS NOT NULL AND expires_at < %s', current_time( 'mysql' ) );
				$jobs[] = array( 'logs', 'created_at < %s', $cutoff );
			}
			foreach ( $jobs as $job ) {
				$ok = $this->purge_table( $job[0], $job[1], $job[2], $deadline ) && $ok;
			}
			if ( $enabled && class_exists( 'CU_Error_Logger' ) ) {
				CU_Error_Logger::prune( $days );
			}
		}

		// Journal anti-spam : sa propre durée de conservation (module Spam),
		// appliquée même si le nettoyage automatique général est désactivé.
		$spam_days = max( 1, min( 365, (int) CU_Settings::get( 'spam', 'log_retention_days', 30 ) ) );
		$ok        = $this->purge_table( 'spam_log', 'created_at < %s', CU_DB::date_from_now( 'Y-m-d H:i:s', -$spam_days * DAY_IN_SECONDS ), $deadline ) && $ok;

		// Confidentialité : sel du jour, empreintes et IP résiduelles, minimisation
		// des données antérieures à 1.2.0 (false = budget épuisé avant la fin).
		if ( class_exists( 'CU_Analytics' ) && ! CU_Analytics::daily_maintenance( $deadline ) ) {
			$this->cleanup_unfinished = true;
		}
		// Liste publique des domaines jetables : au plus une fois par semaine
		// (module Spam actif, vérification et mise à jour automatique activées).
		if ( class_exists( 'CU_Spam' ) ) {
			CU_Spam::maybe_refresh_disposable_list();
		}
		// Base locale IP → pays : édition du mois (ou base manquante).
		CU_Geo::maybe_refresh_local_db();
		if ( $this->cleanup_unfinished ) {
			// Budget épuisé : reprise dans 10 minutes (sans attendre le lendemain).
			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, 'cu_cron_cleanup' );
		}

		if ( ! $ok ) {
			CU_Logger::log( 'system', 'error', __( 'Le nettoyage automatique a rencontré une erreur SQL.', 'cyberpush-ultra' ) );
			return false;
		}
		CU_Logger::log( 'system', 'info', __( 'Nettoyage automatique des données expirées effectué', 'cyberpush-ultra' ) );
		return true;
	}

	/**
	 * Supprime par lots les lignes expirées d'une table (index sur la colonne de
	 * date), sans jamais verrouiller la table entière ni dépasser le budget.
	 *
	 * @return bool false en cas d'erreur SQL.
	 */
	private function purge_table( $short, $where, $value, $deadline ) {
		if ( ! CU_DB::tables_exist_for( array( $short ) ) ) {
			return true;
		}
		global $wpdb;
		$table = CU_DB::table( $short );
		do {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$where} LIMIT %d", $value, self::CLEANUP_BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $deleted ) {
				return false;
			}
		} while ( $deleted >= self::CLEANUP_BATCH && microtime( true ) < $deadline );
		if ( $deleted >= self::CLEANUP_BATCH ) {
			$this->cleanup_unfinished = true;
		}
		return true;
	}
}

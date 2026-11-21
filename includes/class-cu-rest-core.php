<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Routes REST "transverses" utilisées par les pages Réglages et Logs, ainsi
 * que par les outils système (migrations SQL, informations de diagnostic).
 * Les modules Analytics/Firewall/SEO enregistrent leurs propres routes dans
 * leurs classes respectives.
 */
class CU_REST_Core {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @var array<string,int> Nombre d'erreurs SQL connues avant chaque route du plugin. */
	private $sql_errors_before = array();

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'guard_missing_tables' ), 10, 3 );
		// Avant la journalisation (10) : une table absente n'est pas une panne du code.
		add_filter( 'rest_request_after_callbacks', array( $this, 'convert_missing_table_errors' ), 5, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'log_rest_failure' ), 10, 3 );
	}

	/** Route relative au namespace du plugin (« /analytics/summary »), ou null. */
	private function plugin_route( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return null;
		}
		$route  = (string) $request->get_route();
		$prefix = '/' . trim( CU_REST_NAMESPACE, '/' );
		if ( 0 !== strpos( $route, $prefix . '/' ) ) {
			return null;
		}
		return substr( $route, strlen( $prefix ) );
	}

	/**
	 * Tables indispensables aux routes d'administration qui les lisent
	 * directement. La collecte publique (/collect) n'est jamais concernée : elle
	 * échoue silencieusement, sans contrôle coûteux.
	 */
	private function route_tables( $route ) {
		$map = array(
			'/analytics/'      => array( 'sessions', 'events' ),
			'/firewall/audit/' => array( 'firewall_audit', 'fim' ),
			'/seo/'            => array( 'seo_pages', 'seo_issues' ),
			'/spam/log'        => array( 'spam_log' ),
			'/logs'            => array( 'logs' ),
		);
		foreach ( $map as $prefix => $tables ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return $tables;
			}
		}
		return array();
	}

	/** Erreur contrôlée « table manquante » (409, comme les autres contrôles du plugin). */
	private function migration_required_error( array $tables = array() ) {
		$message = __( 'Migration SQL requise : une table ou une colonne du plugin est absente. Ouvrez Réglages > Base de données et lancez l’installation / la réparation des tables.', 'cyberpush-ultra' );
		$names   = array();
		foreach ( $tables as $short ) {
			$names[] = CU_DB::table( $short );
		}
		return new WP_Error( 'cu_migration_required', $message, array( 'status' => 409, 'tables' => $names ) );
	}

	/**
	 * Garde centrale : une route d'administration dont une table indispensable
	 * manque répond 409 « Migration SQL requise » au lieu d'une erreur SQL 500.
	 */
	public function guard_missing_tables( $response, $handler, $request ) {
		unset( $handler );
		$route = $this->plugin_route( $request );
		if ( null === $route || is_wp_error( $response ) ) {
			return $response;
		}
		global $EZSQL_ERROR;
		$this->sql_errors_before[ spl_object_hash( $request ) ] = is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;
		$tables = $this->route_tables( $route );
		if ( $tables && current_user_can( 'manage_options' ) && ! CU_DB::tables_exist_for( $tables ) ) {
			return $this->migration_required_error( $tables );
		}
		return $response;
	}

	/**
	 * Filet de sécurité : une route du plugin qui échoue (5xx) à cause d'une
	 * table ou d'une colonne absente (schéma incomplet) renvoie 409 « Migration
	 * SQL requise » plutôt qu'une panne générique.
	 */
	public function convert_missing_table_errors( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		$key = spl_object_hash( $request );
		if ( ! isset( $this->sql_errors_before[ $key ] ) ) {
			return $response;
		}
		$before = (int) $this->sql_errors_before[ $key ];
		unset( $this->sql_errors_before[ $key ] );

		$status = 200;
		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		} elseif ( is_object( $response ) && is_callable( array( $response, 'get_status' ) ) ) {
			$status = (int) $response->get_status();
		}
		if ( $status < 500 ) {
			return $response;
		}
		global $EZSQL_ERROR;
		$new = is_array( $EZSQL_ERROR ) ? array_slice( $EZSQL_ERROR, $before ) : array();
		foreach ( $new as $error ) {
			$text = is_array( $error ) && isset( $error['error_str'] ) ? (string) $error['error_str'] : '';
			if ( preg_match( "/doesn't exist|Unknown column|Unknown table|no such table/i", $text ) ) {
				return $this->migration_required_error();
			}
		}
		return $response;
	}

	/**
	 * Paramètre de module des routes /settings/{module} : lu dans l'URL
	 * (un ?module[]=… en requête ne doit pas le remplacer par un tableau).
	 */
	private function module_param( WP_REST_Request $request ) {
		$url    = $request->get_url_params();
		$module = isset( $url['module'] ) ? $url['module'] : $request->get_param( 'module' );
		return is_string( $module ) ? sanitize_key( $module ) : '';
	}

	/** Journalise automatiquement les pannes serveur des routes du plugin. */
	public function log_rest_failure( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		$route  = (string) $request->get_route();
		$prefix = '/' . trim( CU_REST_NAMESPACE, '/' ) . '/';
		if ( 0 !== strpos( $route, $prefix ) ) {
			return $response;
		}

		$status  = 200;
		$code    = 'rest_error';
		$message = '';
		if ( is_wp_error( $response ) ) {
			$code    = (string) $response->get_error_code();
			$message = (string) $response->get_error_message();
			$data    = $response->get_error_data();
			$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		} elseif ( is_object( $response ) && is_callable( array( $response, 'get_status' ) ) ) {
			$status = (int) $response->get_status();
			if ( $status >= 500 && is_callable( array( $response, 'get_data' ) ) ) {
				$data = $response->get_data();
				if ( is_array( $data ) ) {
					$code    = isset( $data['code'] ) ? sanitize_key( $data['code'] ) : $code;
					$message = isset( $data['message'] ) ? (string) $data['message'] : '';
				}
			}
		}

		// Les erreurs de validation 4xx sont visibles par l'administrateur mais ne
		// sont pas des pannes du plugin et ne doivent pas polluer le journal.
		if ( $status < 500 ) {
			return $response;
		}
		$message = '' !== $message ? $message : __( 'Erreur interne sans message.', 'cyberpush-ultra' );
		CU_Logger::log(
			'system',
			'error',
			sprintf( __( 'Erreur REST %1$d sur %2$s : %3$s', 'cyberpush-ultra' ), $status, $route, $message ),
			array(
				'route'  => $route,
				'method' => $request->get_method(),
				'code'   => $code,
				'status' => $status,
			)
		);
		return $response;
	}

	public function permission_admin() {
		return current_user_can( 'manage_options' );
	}

	public function register_routes() {
		$ns = CU_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_all_settings' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/settings/(?P<module>[a-z_]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_module_settings' ),
					'permission_callback' => array( $this, 'permission_admin' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_module_settings' ),
					'permission_callback' => array( $this, 'permission_admin' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/settings/(?P<module>[a-z_]+)/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_module_settings' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/ai/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_ai_connection' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/system/info',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_system_info' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/system/install-tables',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'install_tables' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/system/erase-module-data',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'erase_module_data' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/settings-export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_settings' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/settings-import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_settings' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/system/maintenance',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_maintenance' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/system/self-test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'self_test' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/system/error-log',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_error_log' ),
					'permission_callback' => array( $this, 'permission_admin' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'clear_error_log' ),
					'permission_callback' => array( $this, 'permission_admin' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/logs/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_logs_summary' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/logs/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purge_logs' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/logs/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_logs' ),
				'permission_callback' => array( $this, 'permission_admin' ),
			)
		);
	}

	// -------------------------------------------------------------------
	// Réglages
	// -------------------------------------------------------------------

	/** Les secrets (clés API) ne quittent jamais le serveur en clair. */
	public function get_all_settings() {
		return rest_ensure_response( CU_Settings::redact( CU_Settings::all() ) );
	}

	public function get_module_settings( WP_REST_Request $request ) {
		$module = $this->module_param( $request );
		$all    = CU_Settings::redact( CU_Settings::all() );
		if ( ! array_key_exists( $module, $all ) ) {
			return new WP_Error( 'cu_unknown_module', __( 'Module de réglages inconnu.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $all[ $module ] );
	}

	public function update_module_settings( WP_REST_Request $request ) {
		$module = $this->module_param( $request );
		$all    = CU_Settings::all();
		if ( ! array_key_exists( $module, $all ) ) {
			return new WP_Error( 'cu_unknown_module', __( 'Module de réglages inconnu.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		$values = $request->get_json_params();
		if ( ! is_array( $values ) ) {
			return new WP_Error( 'cu_invalid_body', __( 'Corps de requête invalide.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$values  = $this->restrict_network_settings( $module, $this->sanitize_settings_values( $values, $module ) );
		$updated = CU_Settings::update_module( $module, $values );
		return rest_ensure_response( $this->redact_module( $module, $updated ) );
	}

	public function reset_module_settings( WP_REST_Request $request ) {
		$module = $this->module_param( $request );
		$all    = CU_Settings::get_defaults();
		if ( ! array_key_exists( $module, $all ) ) {
			return new WP_Error( 'cu_unknown_module', __( 'Module de réglages inconnu.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->redact_module( $module, CU_Settings::reset_module( $module ) ) );
	}

	private function redact_module( $module, $values ) {
		$redacted = CU_Settings::redact( array( $module => (array) $values ) );
		return $redacted[ $module ];
	}

	/**
	 * En multisite, un administrateur de sous-site ne doit pas pouvoir diriger
	 * les requêtes du serveur vers le réseau interne (URL Ollama/personnalisée) :
	 * ces champs sont réservés au super-administrateur.
	 */
	private function restrict_network_settings( $module, $values ) {
		if ( 'ai' === $module && is_multisite() && ! is_super_admin() && isset( $values['api_keys'] ) && is_array( $values['api_keys'] ) ) {
			unset( $values['api_keys']['ollama_url'], $values['api_keys']['custom_url'] );
		}
		return $values;
	}

	/**
	 * Nettoyage récursif des valeurs entrantes selon leur nature : secrets
	 * (aucune altération hormis espaces/contrôles), URL (esc_url_raw), texte
	 * (sans HTML). Booléens, nombres et structures sont ensuite contraints par
	 * CU_Settings (types et bornes du schéma).
	 */
	private function sanitize_settings_values( $values, $path ) {
		$secrets = CU_Settings::secret_paths();
		foreach ( $values as $key => $value ) {
			$key_path = $path . '.' . $key;
			if ( is_string( $value ) ) {
				if ( in_array( $key_path, $secrets, true ) ) {
					$values[ $key ] = CU_Settings::is_masked( $value ) ? $value : (string) preg_replace( '/[\x00-\x20\x7F]+/', '', $value );
				} elseif ( preg_match( '/(^|_)url$/', (string) $key ) ) {
					$values[ $key ] = esc_url_raw( trim( $value ), array( 'http', 'https' ) );
				} else {
					$values[ $key ] = sanitize_text_field( $value );
				}
			} elseif ( is_array( $value ) ) {
				$values[ $key ] = $this->sanitize_settings_values( $value, $key_path );
			}
		}
		return $values;
	}

	/** Retire d'un module importé les secrets vides ou masqués (conserve l'existant). */
	private function drop_empty_secrets( $module, $values ) {
		foreach ( CU_Settings::secret_paths() as $secret ) {
			$parts = explode( '.', $secret );
			if ( array_shift( $parts ) !== $module ) {
				continue;
			}
			if ( 1 === count( $parts ) ) {
				if ( array_key_exists( $parts[0], $values ) && ( '' === $values[ $parts[0] ] || CU_Settings::is_masked( $values[ $parts[0] ] ) ) ) {
					unset( $values[ $parts[0] ] );
				}
			} elseif ( isset( $values[ $parts[0] ] ) && is_array( $values[ $parts[0] ] ) && array_key_exists( $parts[1], $values[ $parts[0] ] )
				&& ( '' === $values[ $parts[0] ][ $parts[1] ] || CU_Settings::is_masked( $values[ $parts[0] ][ $parts[1] ] ) ) ) {
				unset( $values[ $parts[0] ][ $parts[1] ] );
			}
		}
		return $values;
	}

	public function test_ai_connection() {
		return rest_ensure_response( CU_AI::test_connection() );
	}

	// -------------------------------------------------------------------
	// Système / migrations
	// -------------------------------------------------------------------

	public function get_system_info() {
		global $wpdb;

		// Contrôle réel (jamais le chemin rapide fondé sur l'option de version) :
		// ce bloc sert justement à diagnostiquer une table manquante.
		$tables_exist = CU_DB::tables_exist( true ) && CU_DB::analytics_schema_ready();
		$counts       = array();
		$missing      = array();
		foreach ( CU_DB::table_names() as $short ) {
			if ( CU_DB::tables_exist_for( array( $short ) ) ) {
				$counts[ $short ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CU_DB::table( $short ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			} else {
				$missing[] = CU_DB::table( $short );
			}
		}

		return rest_ensure_response(
			array(
				'plugin_version'   => CU_VERSION,
				'db_version'       => get_option( CU_OPTION_DB_VERSION ),
				'installed_at'     => get_option( CU_OPTION_INSTALLED_AT ),
				'tables_exist'     => $tables_exist,
				'missing_tables'   => $missing,
				'table_counts'     => $counts,
				'wp_version'       => get_bloginfo( 'version' ),
				'php_version'      => phpversion(),
				'woocommerce_active' => class_exists( 'WooCommerce' ),
				'yoast_active'     => defined( 'WPSEO_VERSION' ),
				'rankmath_active'  => class_exists( 'RankMath' ),
				'site_url'         => site_url(),
				'multisite'        => is_multisite(),
				'cron_next_events' => array(
					'rollup'    => wp_next_scheduled( 'cu_cron_rollup_daily' ),
					'seo_scan'  => wp_next_scheduled( 'cu_cron_seo_scan_cycle' ),
					'firewall'  => wp_next_scheduled( 'cu_cron_firewall_maintenance' ),
					'cleanup'   => wp_next_scheduled( 'cu_cron_cleanup' ),
				),
			)
		);
	}

	public function install_tables() {
		$success = CU_Activator::install_tables();
		if ( ! $success ) {
			return new WP_Error( 'cu_install_failed', __( 'Impossible d’installer toutes les tables SQL. Consultez le journal d’erreurs de l’hébergement.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		update_option( CU_OPTION_DB_VERSION, CU_DB_VERSION );
		CU_Logger::log( 'system', 'info', __( 'Tables SQL installées/mises à jour manuellement depuis les Réglages', 'cyberpush-ultra' ) );
		return rest_ensure_response(
			array(
				'success'      => true,
				'tables_exist' => true,
			)
		);
	}

	/**
	 * Vide les données d'un module (Analytics, Firewall, SEO, Logs) sans
	 * désinstaller le plugin ni toucher aux réglages.
	 */
	public function erase_module_data( WP_REST_Request $request ) {
		$module = sanitize_key( $request->get_param( 'module' ) );
		global $wpdb;

		$map = array(
			'analytics' => array( 'sessions', 'events', 'content_daily', 'contacts' ),
			'firewall'  => array( 'firewall_logs', 'firewall_blocks', 'firewall_whitelist', 'firewall_watch', 'firewall_audit' ),
			'seo'       => array( 'seo_pages', 'seo_issues', 'seo_meta', 'seo_links', 'seo_history' ),
			'logs'      => array( 'logs' ),
		);

		if ( ! isset( $map[ $module ] ) ) {
			return new WP_Error( 'cu_unknown_module', __( 'Module inconnu.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}

		// Contrôle réel des tables DU module : jamais de « succès » si l'une
		// d'elles manque (TRUNCATE échouerait ou ne viderait qu'une partie).
		if ( ! CU_DB::tables_exist_for( $map[ $module ], true ) ) {
			return $this->migration_required_error( $map[ $module ] );
		}

		foreach ( $map[ $module ] as $short ) {
			if ( false === $wpdb->query( 'TRUNCATE TABLE ' . CU_DB::table( $short ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new WP_Error( 'cu_erase_failed', __( 'La réinitialisation SQL a échoué.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
			}
		}

		// Les rapports en cache décriraient encore les données effacées.
		if ( 'firewall' === $module && method_exists( 'CU_Firewall', 'flush_list_cache' ) ) {
			CU_Firewall::flush_list_cache();
		}
		if ( 'analytics' === $module ) {
			$this->flush_plugin_cache();
			if ( method_exists( 'CU_Analytics', 'purge_transients' ) ) {
				CU_Analytics::purge_transients();
			}
		}

		CU_Logger::log( 'system', 'warning', sprintf( __( 'Données du module "%s" réinitialisées manuellement', 'cyberpush-ultra' ), $module ), array(
			'user' => get_current_user_id(),
		) );

		return rest_ensure_response( array( 'success' => true ) );
	}

	// -------------------------------------------------------------------
	// Logs (page "Logs" — chronologie unifiée)
	// -------------------------------------------------------------------

	private function logs_where( WP_REST_Request $request ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		$module = $request->get_param( 'module' );
		if ( $module && 'all' !== $module ) {
			$where[]  = 'module = %s';
			$params[] = sanitize_key( $module );
		}

		$level = $request->get_param( 'level' );
		if ( $level && 'all' !== $level ) {
			$where[]  = 'level = %s';
			$params[] = sanitize_key( $level );
		}

		$search = $request->get_param( 'search' );
		$search = is_scalar( $search ) ? sanitize_text_field( (string) $search ) : '';
		if ( '' !== $search ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$range = (int) $request->get_param( 'hours' );
		if ( $range > 0 ) {
			$where[]  = 'created_at >= %s';
			$params[] = CU_DB::date_from_now( 'Y-m-d H:i:s', -$range * HOUR_IN_SECONDS );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	public function get_logs( WP_REST_Request $request ) {
		if ( ! CU_DB::tables_exist_for( array( 'logs' ) ) ) {
			return new WP_Error( 'cu_migration_required', __( 'Migration SQL requise.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}

		global $wpdb;
		$table = CU_DB::table( 'logs' );
		$wpdb->last_error = '';

		list( $where_sql, $params ) = $this->logs_where( $request );

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = 30;
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = '' !== $wpdb->last_error;

		$rows_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = $read_failed || '' !== $wpdb->last_error;
		if ( $read_failed || ! is_array( $rows ) ) {
			return new WP_Error( 'cu_logs_read_failed', __( 'Impossible de lire le journal SQL.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		foreach ( $rows as &$row ) {
			$row['context'] = $row['context'] ? json_decode( $row['context'], true ) : null;
		}
		unset( $row );

		return rest_ensure_response(
			array(
				'items'    => $rows,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'pages'    => (int) ceil( $total / $per_page ),
			)
		);
	}

	public function get_logs_summary() {
		if ( ! CU_DB::tables_exist_for( array( 'logs' ) ) ) {
			return new WP_Error( 'cu_migration_required', __( 'Migration SQL requise.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'logs' );
		$wpdb->last_error = '';

		$by_module  = $wpdb->get_results( "SELECT module, COUNT(*) AS total FROM {$table} GROUP BY module", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = '' !== $wpdb->last_error;
		$by_level     = $wpdb->get_results( "SELECT level, COUNT(*) AS total FROM {$table} GROUP BY level", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed  = $read_failed || '' !== $wpdb->last_error;
		$last_24h     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", CU_DB::date_from_now( 'Y-m-d H:i:s', -DAY_IN_SECONDS ) ) );
		$read_failed  = $read_failed || '' !== $wpdb->last_error;
		$total         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed  = $read_failed || '' !== $wpdb->last_error;
		if ( $read_failed || ! is_array( $by_module ) || ! is_array( $by_level ) ) {
			return new WP_Error( 'cu_logs_summary_failed', __( 'Impossible de calculer le résumé des journaux.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'total'     => $total,
				'last_24h'  => $last_24h,
				'by_module' => $by_module,
				'by_level'  => $by_level,
			)
		);
	}

	public function purge_logs( WP_REST_Request $request ) {
		$mode = $request->get_param( 'mode' );
		if ( 'all' === $mode ) {
			$result = CU_Logger::purge_all();
		} else {
			$result = CU_Logger::purge_keep_last_24h();
		}
		if ( false === $result ) {
			return new WP_Error( 'cu_logs_purge_failed', __( 'Impossible de purger les journaux.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function export_logs( WP_REST_Request $request ) {
		if ( ! CU_DB::tables_exist_for( array( 'logs' ) ) ) {
			return new WP_Error( 'cu_migration_required', __( 'Migration SQL requise.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'logs' );
		$wpdb->last_error = '';
		list( $where_sql, $params ) = $this->logs_where( $request );
		$sql  = "SELECT created_at, module, level, message FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 5000";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'cu_logs_export_failed', __( 'Impossible d’exporter les journaux.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=cyberpush-ultra-logs.csv' );
		$out = fopen( 'php://output', 'w' );
		// BOM UTF-8 + séparateur « ; » : format attendu par Excel en français (cohérent avec l'export Analytics).
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Date', 'Module', 'Niveau', 'Message' ), ';', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( $this, 'csv_safe_cell' ), array_values( $row ) ), ';', '"', '' );
		}
		fclose( $out );
		exit;
	}

	/** Neutralise les formules lors de l'ouverture d'un export dans Excel/Calc. */
	private function csv_safe_cell( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^[\x00-\x20]*[=+\-@]/u', $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	// -------------------------------------------------------------------
	// Sauvegarde / import des réglages (Réglages > Sauvegarde)
	// -------------------------------------------------------------------

	/**
	 * Renvoie l'intégralité des réglages du plugin sous forme d'objet JSON,
	 * prêt à être sauvegardé. Le front-end déclenche le téléchargement.
	 */
	public function export_settings() {
		// Les clés API ne sont jamais exportées : un fichier de sauvegarde se
		// partage facilement. L'import conserve les clés déjà enregistrées.
		return rest_ensure_response(
			array(
				'plugin'           => 'cyberpush-ultra',
				'version'          => CU_VERSION,
				'exported_at'      => current_time( 'mysql' ),
				'secrets_excluded' => true,
				'settings'         => CU_Settings::redact( CU_Settings::all(), true ),
			)
		);
	}

	/**
	 * Importe des réglages précédemment exportés. Seuls les modules connus
	 * sont pris en compte ; chaque module est fusionné avec ses valeurs
	 * actuelles pour éviter toute clé manquante.
	 */
	public function import_settings( WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$incoming = isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : $body;

		if ( ! is_array( $incoming ) ) {
			return new WP_Error( 'cu_invalid_body', __( 'Fichier de réglages invalide.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}

		$defaults = CU_Settings::get_defaults();
		$applied  = array();

		foreach ( $incoming as $module => $values ) {
			if ( is_string( $module ) && array_key_exists( $module, $defaults ) && is_array( $values ) ) {
				$values = $this->drop_empty_secrets( $module, $this->sanitize_settings_values( $values, $module ) );
				CU_Settings::update_module( $module, $this->restrict_network_settings( $module, $values ), false );
				$applied[] = $module;
			}
		}

		if ( empty( $applied ) ) {
			return new WP_Error( 'cu_nothing_imported', __( 'Aucun module de réglages valide trouvé dans le fichier.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}

		CU_Logger::log( 'system', 'info', sprintf( __( 'Réglages importés : %s', 'cyberpush-ultra' ), implode( ', ', $applied ) ) );

		return rest_ensure_response( array( 'success' => true, 'modules' => $applied ) );
	}

	// -------------------------------------------------------------------
	// Maintenance (Réglages > Nettoyage)
	// -------------------------------------------------------------------

	/**
	 * Actions de maintenance immédiates : vider le cache, recalculer les
	 * statistiques ou supprimer les données expirées.
	 */
	public function run_maintenance( WP_REST_Request $request ) {
		$action = sanitize_key( $request->get_param( 'action' ) );

		switch ( $action ) {
			case 'flush_cache':
				$count = $this->flush_plugin_cache();
				CU_Logger::log( 'system', 'info', __( 'Cache du plugin vidé manuellement', 'cyberpush-ultra' ) );
				return rest_ensure_response( array( 'success' => true, 'message' => sprintf( __( '%d entrée(s) de cache supprimée(s).', 'cyberpush-ultra' ), $count ) ) );

			case 'recalculate':
				if ( ! CU_DB::tables_exist_for( array( 'sessions', 'events', 'content_daily' ), true ) ) {
					return $this->migration_required_error( array( 'sessions', 'events', 'content_daily' ) );
				}
				// false = un autre recalcul détient le verrou, ou une erreur SQL a été
				// journalisée : ne jamais annoncer un succès qui n'a pas eu lieu.
				if ( ! CU_Cron::instance()->run_rollup_daily() ) {
					return new WP_Error( 'cu_recalculate_busy', __( 'Recalcul non effectué : un calcul est déjà en cours ou une erreur SQL est survenue (voir Logs). Réessayez dans quelques minutes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
				}
				return rest_ensure_response( array( 'success' => true, 'message' => __( 'Statistiques recalculées.', 'cyberpush-ultra' ) ) );

			case 'cleanup_expired':
				if ( ! CU_DB::tables_exist( true ) ) {
					return $this->migration_required_error();
				}
				$result = CU_Cron::instance()->run_cleanup();
				if ( null === $result ) {
					return new WP_Error( 'cu_cleanup_busy', __( 'Un nettoyage est déjà en cours. Réessayez dans quelques minutes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
				}
				// Même périmètre que la tâche planifiée : le Journal de sécurité suit
				// sa propre durée de conservation (Réglages > Journal de sécurité).
				if ( class_exists( 'CU_Audit' ) && method_exists( 'CU_Audit', 'instance' ) ) {
					CU_Audit::instance()->purge_old();
				}
				if ( false === $result ) {
					return new WP_Error( 'cu_cleanup_failed', __( 'Le nettoyage a rencontré une erreur SQL (voir Logs). Certaines données expirées n’ont peut-être pas été supprimées.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'success' => true, 'message' => __( 'Données expirées supprimées.', 'cyberpush-ultra' ) ) );

			case 'geo_update':
				// Base locale IP → pays : téléchargement immédiat (utile si WP-Cron ne
				// s'exécute pas sur le site). Jamais un succès annoncé à tort.
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				$geo = CU_Geo::update_local_db();
				if ( empty( $geo['ok'] ) ) {
					return new WP_Error( 'cu_geo_update_failed', sprintf( /* translators: %s: cause */ __( 'Base de pays non installée : %s', 'cyberpush-ultra' ), $geo['message'] ), array( 'status' => 502 ) );
				}
				return rest_ensure_response( array( 'success' => true, 'message' => $geo['message'] ) );

			default:
				return new WP_Error( 'cu_unknown_action', __( 'Action de maintenance inconnue.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Supprime toutes les entrées de cache (transients) créées par le plugin.
	 *
	 * @return int Nombre de transients supprimés.
	 */
	private function flush_plugin_cache() {
		global $wpdb;
		// Uniquement les caches recalculables. Les autres transients du plugin
		// portent des DONNÉES (passages des crawlers IA sur 40 jours) ou un ÉTAT
		// de sécurité (récidives, verrous de connexion, compteurs anti-flood) :
		// les effacer ici ferait perdre des statistiques ou lever des bannissements.
		$prefixes = array( 'cu_analytics_', 'cu_geo_', 'cu_enrich_', 'cu_bot_prefixes_', 'cu_goodbot_', 'cu_rollup_recent' );
		$count    = 0;
		foreach ( $prefixes as $prefix ) {
			$like    = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
			$timeout = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
			$found   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout ) );
			if ( false !== $deleted ) {
				$count += $found;
			}
		}
		// Avec un cache objet persistant (Redis, Memcached), les transients ne sont
		// pas en base : changer l'époque invalide tout de même les rapports Analytics.
		update_option( 'cu_analytics_cache_epoch', wp_generate_uuid4(), false );
		return $count;
	}

	// -------------------------------------------------------------------
	// Auto-tests (Réglages > Développeur)
	// -------------------------------------------------------------------

	/**
	 * Vérifie rapidement la santé d'un sous-système et renvoie un état lisible.
	 */
	public function self_test( WP_REST_Request $request ) {
		$target = sanitize_key( $request->get_param( 'target' ) );
		global $wpdb;

		switch ( $target ) {
			case 'cron':
				$next = wp_next_scheduled( 'cu_cron_cleanup' );
				return rest_ensure_response( array(
					'success' => (bool) $next,
					'message' => $next
						? sprintf( __( 'WP-Cron opérationnel. Prochain nettoyage : %s', 'cyberpush-ultra' ), wp_date( 'Y-m-d H:i', $next, wp_timezone() ) )
						: __( 'Aucune tâche planifiée détectée — réactivez le plugin.', 'cyberpush-ultra' ),
				) );

			case 'firewall':
				$fw_tables = CU_DB::tables_exist_for( array( 'firewall_logs', 'firewall_blocks', 'firewall_whitelist', 'firewall_watch', 'firewall_audit' ), true );
				$enabled   = CU_Settings::get( 'firewall', 'enabled', true ) && ! ( defined( 'CU_FIREWALL_DISABLED' ) && CU_FIREWALL_DISABLED );
				$ip_note  = ' ' . sprintf(
					/* translators: 1: IP, 2: source */
					__( 'Votre IP vue par le pare-feu : %1$s (source : %2$s).', 'cyberpush-ultra' ),
					CU_Geo::client_ip(),
					(string) CU_Settings::get( 'firewall', 'ip_source', 'auto' )
				);
				$disabled = defined( 'CU_FIREWALL_DISABLED' ) && CU_FIREWALL_DISABLED
					? __( 'Firewall désactivé par la constante CU_FIREWALL_DISABLED (wp-config.php).', 'cyberpush-ultra' )
					: __( 'Firewall désactivé dans les réglages.', 'cyberpush-ultra' );
				return rest_ensure_response( array(
					'success' => $fw_tables && $enabled,
					'message' => ( ! $fw_tables
						? __( 'Tables SQL manquantes.', 'cyberpush-ultra' )
						: ( $enabled ? __( 'Firewall actif et opérationnel.', 'cyberpush-ultra' ) : $disabled ) ) . $ip_note,
				) );

			case 'analytics':
				// Contrôle réel des tables ET des colonnes de la collecte : jamais
				// « Analytics OK » si l'une manque.
				if ( ! CU_DB::analytics_schema_ready() ) {
					return rest_ensure_response( array( 'success' => false, 'message' => __( 'Tables SQL manquantes ou incomplètes : lancez l’installation / la réparation des tables (Réglages > Base de données).', 'cyberpush-ultra' ) ) );
				}
				$wpdb->last_error = '';
				$sessions         = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CU_DB::table( 'sessions' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( null === $sessions || '' !== $wpdb->last_error ) {
					return rest_ensure_response( array( 'success' => false, 'message' => __( 'Lecture SQL des visites impossible (voir Logs).', 'cyberpush-ultra' ) ) );
				}
				return rest_ensure_response( array(
					'success' => true,
					'message' => sprintf( __( 'Analytics OK. %d session(s) collectée(s).', 'cyberpush-ultra' ), (int) $sessions ),
				) );

			case 'seo':
				if ( ! CU_DB::tables_exist_for( array( 'seo_pages', 'seo_issues', 'seo_meta', 'seo_links', 'seo_history' ), true ) ) {
					return rest_ensure_response( array( 'success' => false, 'message' => __( 'Tables SQL manquantes.', 'cyberpush-ultra' ) ) );
				}
				$enabled = CU_Settings::get( 'seo', 'enabled', true );
				$crawled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . CU_DB::table( 'seo_pages' ) . " WHERE crawl_state = 'done'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return rest_ensure_response( array(
					'success' => $enabled,
					'message' => $enabled
						? sprintf( __( 'SEO actif. %d page(s) analysée(s).', 'cyberpush-ultra' ), $crawled )
						: __( 'Module SEO désactivé dans les réglages.', 'cyberpush-ultra' ),
				) );

			case 'ai':
				return rest_ensure_response( CU_AI::test_connection() );

			default:
				return new WP_Error( 'cu_unknown_target', __( 'Cible de test inconnue.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
	}

	// -------------------------------------------------------------------
	// Journal d'erreurs du plugin (fichier de secours) — Réglages > Développeur
	// -------------------------------------------------------------------

	public function get_error_log() {
		$lines = class_exists( 'CU_Error_Logger' ) ? CU_Error_Logger::tail( 300 ) : array();
		return rest_ensure_response( array(
			'lines' => $lines,
			'count' => count( $lines ),
		) );
	}

	public function clear_error_log() {
		$deleted = class_exists( 'CU_Error_Logger' ) ? CU_Error_Logger::clear() : 0;
		CU_Logger::log( 'system', 'info', __( 'Journal d\'erreurs du plugin vidé', 'cyberpush-ultra' ) );
		return rest_ensure_response( array( 'success' => true, 'deleted_files' => $deleted ) );
	}
}

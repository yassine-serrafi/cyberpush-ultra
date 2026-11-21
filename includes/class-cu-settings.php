<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Accès unique aux réglages du plugin (option WordPress `cu_settings`).
 * Toute la configuration passe par cette classe afin que chaque module
 * (Analytics, Firewall, SEO, IA...) puisse être activé, désactivé ou
 * personnalisé indépendamment, sans jamais modifier le code.
 *
 * Règle : chaque clé déclarée ici est réellement lue par le code. Une option
 * sans effet n'a pas sa place dans les valeurs par défaut (voir
 * obsolete_keys() pour celles retirées lors des migrations).
 */
class CU_Settings {

	/** Préfixe des valeurs masquées renvoyées à l'interface pour les secrets. */
	const SECRET_MASK = '••••';

	/**
	 * Bornes de taille : `cu_settings` est lue à chaque requête. Un champ de
	 * 1 Mo ou des listes de dizaines de milliers d'entrées alourdissaient chaque
	 * page (et faisaient passer l'option hors du chargement automatique).
	 */
	const MAX_STRING_LENGTH    = 4096;
	const MAX_LIST_ITEMS       = 1000;
	const MAX_LIST_ITEM_LENGTH = 255;

	/** @var array|null Valeurs par défaut construites une seule fois par requête. */
	private static $defaults_memo = null;

	/** @var array|null Réglages fusionnés mis en cache pour la requête courante. */
	private static $all_memo = null;

	/** @var mixed Valeur brute de l'option ayant produit $all_memo. */
	private static $all_memo_source = null;

	/**
	 * Valeurs par défaut, organisées par module.
	 */
	public static function get_defaults() {
		if ( null === self::$defaults_memo ) {
			self::$defaults_memo = self::build_defaults();
		}
		return self::$defaults_memo;
	}

	private static function build_defaults() {
		return array(
			'dashboard' => array(
				'show_animations' => true,
				'rows_per_table'  => 20,
			),
			'analytics' => array(
				'enabled'                 => true,
				// Confidentialité : 'exempt' = mesure d'audience minimisée utilisable
				// sans consentement (pas d'IP affichée ni transmise, URL sans
				// paramètres, opposition possible, conservation ≤ 25 mois) ;
				// 'consent' = aucune collecte avant consentement (WP Consent API ou
				// API JavaScript) ; 'standard' = aucune restriction (hors UE, ou base
				// légale gérée par le site).
				'privacy_mode'            => 'exempt',
				// Mesure anonyme de secours (refus, absence de réponse au bandeau, GPC) :
				// chaque page vue est comptée sans cookie, sans stockage dans le
				// navigateur, sans identifiant persistant ; de l'IP, seul le pays est déduit en mémoire.
				// Désactivée : ces visiteurs ne sont pas mesurés du tout.
				'anonymous_fallback'      => true,
				'cache_duration_minutes'  => 10,
				'exclude_bots'            => true,
				'filter_datacenter'       => true, // effectif uniquement avec un fournisseur IP externe
				'exclude_admins'          => true,
				'excluded_roles'          => array( 'administrator' ),
				'excluded_ips'            => array(),
				'excluded_countries'      => array(),
				'realtime_refresh_seconds' => 20,
				// IP des visiteurs du temps réel : opt-in, conservée chiffrée et
				// effacée 1 h après la dernière activité. Jamais en mode exempté.
				'realtime_show_ip'         => false,
				'session_duration_minutes' => 30,
				'track_cta'               => true,
				// Clics sortants : liens externes, téléchargements, appels (tel:), e-mails (mailto:).
				'track_outbound'          => true,
				'track_scroll'            => true,
				'track_forms'             => true, // formulaires marqués data-cu-conversion
				'track_woocommerce'       => true,
				'track_whatsapp'          => true,
				'track_cf7'               => true,
				// Un clic WhatsApp est une intention, pas un lead confirmé : compté
				// comme conversion « déclarée » seulement si l'administrateur le choisit.
				'whatsapp_conversion'     => false,
				'cf7_conversion'          => true,
				// Pays des visiteurs : base locale par défaut (DB-IP Lite téléchargée par le
				// site ; l'IP est lue en mémoire, jamais transmise ni enregistrée).
				'geo_lookup_enabled'      => true,
				'geo_provider'            => 'local', // local (base DB-IP) | none | woocommerce | maxmind | free (ipwho.is) | api (alias historique de free)
			),
			'firewall'  => array(
				'enabled'                  => true,
				'mode'                     => 'normal', // normal | strict | maintenance
				// Résolution de l'IP visiteur (voir CU_Geo::client_ip()).
				'ip_source'                => 'auto', // auto | remote_addr | x_forwarded_for | x_real_ip | cf_connecting_ip
				'trusted_proxies'          => array(), // IP/CIDR de proxys PUBLICS (CDN, WAF) autorisés à transmettre l'IP
				'auto_block_duration'      => '1h',
				// Bannissements automatiques : jamais permanents (plafond 7 jours).
				// Seul un administrateur peut bannir définitivement.
				'escalation_steps'         => array( '30m', '1h', '2h', '4h', '8h', '24h', '7d' ),
				// Un pays entier ne doit jamais contourner le bannissement par défaut.
				'trusted_countries'        => array(),
				'flood_404_enabled'        => true,
				'flood_404_max'            => 20,
				'flood_404_window_minutes' => 5,
				'probe_detection_enabled'  => true,
				// Chemins d'attaque réellement sondés sur WordPress. Chaque entrée est
				// comparée à un segment/fichier du chemin canonique : on n'inclut que
				// des motifs rarement présents dans une URL légitime.
				'sensitive_paths'          => array(
					'/.env', '/.git', '/.svn', '/.hg', '/.aws', '/.ssh', '/.htpasswd',
					'/wp-config.', '/config.php.', '/.htaccess.', '/configuration.php',
					'/.DS_Store', '/.vscode', '/.idea', '/composer.lock',
					'/secrets.json', '/secrets.yml', '/credentials.json', '/settings.py',
					'/dump.sql', '/backup.sql', '/database.sql', '/db.sql', '/mysql.sql', '/site.sql',
					'/backup.zip', '/backup.tar.gz', '/backup.tar', '/backup.rar', '/backup.gz',
					'/phpMyAdmin', '/adminer.php', '/dbadmin', '/myadmin', '/mysqladmin', '/pma/', '/sqlitemanager',
					'/shell.php', '/cmd.php', '/c99.php', '/r57.php', '/wso.php', '/alfa.php',
					'/eval-stdin.php', '/vendor/phpunit', '/phpinfo.php', '/muhstik.php',
					'/wp-content/debug.log', '/actuator/',
				),
				'scanner_detection_enabled' => true,
				'scanner_signatures'       => array(
					'sqlmap', 'nikto', 'nuclei', 'wpscan', 'masscan', 'nmap', 'zgrab', 'zmap',
					'dirbuster', 'gobuster', 'feroxbuster', 'ffuf', 'wfuzz', 'dirb',
					'acunetix', 'netsparker', 'nessus', 'openvas', 'arachni', 'skipfish', 'w3af',
					'whatweb', 'joomscan', 'droopescan', 'commix', 'xsser', 'fimap', 'hydra', 'medusa',
					'katana', 'dalfox', 'httrack', 'zgrab2', 'puppeteer-scanner',
				),
				'login_protection_enabled' => true,
				'login_max_attempts'       => 5,
				'login_lock_minutes'       => 15,
				'xmlrpc_protection'        => 'limited', // disabled | limited | off
				'restapi_protection_enabled' => true,
				'restapi_blocked_routes'   => array(),
				'upload_protection_enabled' => true,
				'upload_blocked_extensions' => array( 'php', 'phtml', 'php5', 'exe', 'sh' ),
				'rate_limit_enabled'       => true,
				'rate_limit_max_requests'  => 120,
				'rate_limit_window_seconds' => 60,
				'blocked_http_methods'     => array( 'TRACE', 'TRACK' ),
				'blocked_url_keywords'     => array(),
				'waf_enabled'              => true,
				'waf_scan_post'            => true,  // inspecte aussi le corps POST des requêtes anonymes
				'block_user_enumeration'   => true,  // bloque ?author=N et /wp-json/wp/v2/users
				'enum_escalation_enabled'  => true,
				'enum_escalation_max'      => 5,
				'enum_escalation_window_minutes' => 10,
				'enum_escalation_duration' => '24h', // 24h | 7d | 30j | permanent
				'rest_hardening_enabled'   => true,
				// Bad-bots connus (scrapers / harvesters agressifs). Les robots d'IA
				// sont volontairement EXCLUS par défaut (visibilité GEO).
				'bad_bot_blocking_enabled' => true,
				'bad_bot_signatures'       => array(
					'MJ12bot', 'DotBot', 'BLEXBot', 'Bytespider', 'DataForSeoBot',
					'ZoominfoBot', 'serpstatbot', 'Barkrowler', 'MegaIndex', 'SEOkicks',
					'SiteSnagger', 'HTTrack', 'WebZIP', 'WebCopier', 'Teleport', 'libwww-perl',
					'zgrab', 'Nimbostratus', 'magpie-crawler', 'semrushbot-ba',
				),
				'js_challenge_enabled'         => false,
				'js_challenge_scope'           => 'suspicious', // suspicious | all
				'js_challenge_ttl_hours'       => 24,
				'radar_enabled'            => true,
				'radar_autoblock_enabled'  => false,
				'radar_autoblock_min_score' => 90,
				'radar_autoblock_min_hits'  => 3,
				'radar_autoblock_mode'     => 'ip',   // ip | cidr (plage /24)
				'radar_autoblock_duration' => '7d',   // 24h | 7d | 30j | permanent
				'screenshot_protection_enabled' => false,
				'hotlink_protection_enabled' => false,
				'hotlink_allowed_domains'    => array(),
				'hotlink_extensions'         => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'mp4', 'webm', 'mp3', 'pdf' ),
				'hotlink_action'             => 'forbidden', // forbidden | redirect
				'hotlink_redirect_url'       => '',
			),
			// SEO — auditeur. Mode AUDIT UNIQUEMENT : aucune balise émise sur le front.
			'seo'       => array(
				'enabled'                    => true,
				'crawl_scope'                => 'tracked', // tracked | all_public
				'crawl_batch_size'           => 8,
				'recrawl_days'               => 7,
				'crawl_home'                 => true,
				'crawl_terms'                => false,
				'crawl_taxonomies'           => array( 'category' => true, 'post_tag' => true ),
				'request_timeout'            => 15,
				'user_agent'                 => 'CyberPushBot/1.0 (+SEO Audit)',
				'title_min'                  => 30,
				'title_max'                  => 60,
				'meta_desc_min'              => 120,
				'meta_desc_max'              => 160,
				'content_min_words'          => 300,
				'keyword_density_max'        => 3.0,
				'max_crawl_depth'            => 3,
				'psi_enabled'                => false,
				'psi_api_key'                => '',
				'psi_strategy'               => 'mobile',  // mobile | desktop
				'serper_api_key'             => '',
				'index_auto'                 => false,
				'index_auto_per_cycle'       => 10,
				'check_broken_links'         => true,
				'excluded_url_patterns'      => array( '/wp-admin', '/wp-json', '/feed', '?', '/cart', '/checkout', '/mon-compte', '/my-account' ),
			),
			'audit'     => array(
				'enabled'        => true,
				'email_alerts'   => true,
				'alert_email'    => '',
				'retention_days' => 90,
				// Vulnérabilités & versions : consultation de WPVulnerability / php.net
				// (slug + version des composants, aucune donnée de visiteur) et e-mail
				// pour les nouvelles failles importantes.
				'vuln_online'    => true,
				'vuln_email'     => true,
				// Protection des connexions (surveillance, jamais de blocage).
				'login_watch'            => true,
				'login_watch_privileged' => false,
				'login_email_new_ip'     => false,
				'login_retention_days'   => 30,
			),
			'indexnow'  => array(
				// Opt-in : évite les doubles soumissions avec un autre plugin SEO.
				'enabled'     => false,
				'auto_submit' => true,
				'endpoint'    => 'api.indexnow.org',
				'key'         => '',
			),
			// Anti-spam des formulaires (Contact Form 7). Aucune analyse du contenu.
			'spam'      => array(
				'enabled'                   => true,
				'honeypot'                  => true,
				'email_check'               => true,  // domaine de l'adresse : réservé, Null MX, sans messagerie (DNS)
				'disposable_check'          => true,
				'disposable_action'         => 'block', // block | suspect
				'disposable_auto_update'    => true,
				'rate_limit_enabled'        => true,
				'rate_limit_max'            => 5,
				'rate_limit_window_minutes' => 10,
				'excluded_forms'            => array(),
				'allowed_domains'           => array(), // domaines ou adresses complètes
				'blocked_domains'           => array(),
				'log_retention_days'        => 30,
			),
			'ai'        => array(
				'provider'                 => 'openai', // openai | anthropic | google | mistral | ollama | custom
				'model'                    => 'gpt-4o-mini',
				'language'                 => 'fr',
				'tone'                     => 'professionnel',
				'api_keys'                 => array(
					'openai'    => '',
					'anthropic' => '',
					'google'    => '',
					'mistral'   => '',
					'ollama_url' => 'http://localhost:11434',
					'custom_url' => '',
					'custom_key' => '',
				),
			),
			'wordpress' => array(
				'tracked_post_types' => array( 'post', 'page' ),
				'excluded_post_types' => array(),
			),
			'performance' => array(
				'auto_cleanup_enabled' => true,
				'auto_cleanup_days'    => 90,
			),
			'security'  => array(
				'log_setting_changes'      => true,
				'erase_data_on_uninstall'  => false,
			),
			'developer' => array(
				'debug_mode'          => false,
			),
		);
	}

	/**
	 * Clés retirées lors du nettoyage 1.2.0 (aucun code ne les lisait). Utilisé
	 * par la migration pour purger l'option stockée. Un module entier est
	 * représenté par la valeur true.
	 */
	public static function obsolete_keys() {
		return array(
			'general'       => true,
			'notifications' => true,
			'woocommerce'   => true,
			'dashboard'     => array( 'show_charts', 'date_format', 'timezone', 'currency' ),
			'performance'   => array( 'memory_cache_enabled', 'disk_cache_enabled', 'cache_duration_minutes', 'compression_enabled', 'sql_optimization', 'auto_indexing', 'cron_frequency', 'max_concurrent_jobs' ),
			'security'      => array( 'admin_only_access', 'allowed_roles', 'confirm_critical_actions', 'protect_accidental_deletion' ),
			'developer'     => array( 'show_sql_queries', 'show_execution_time' ),
			'ai'            => array( 'response_length', 'auto_seo_optimization', 'auto_generate_meta', 'auto_generate_faq', 'auto_generate_internal_links', 'auto_generate_titles', 'auto_generate_product_descriptions', 'tokens_used_total' ),
			'firewall'      => array( 'blacklist_ips', 'js_challenge_pass_known_bots' ),
			// Données plus collectées (minimisation) : interrupteurs devenus sans effet.
			'analytics'     => array( 'track_downloads', 'track_external_links', 'track_internal_search' ),
		);
	}

	/**
	 * Chemins (module.clé[.sous-clé]) des secrets : jamais renvoyés en clair à
	 * l'interface, jamais exportés, conservés si l'interface renvoie un masque.
	 */
	public static function secret_paths() {
		return array(
			'ai.api_keys.openai',
			'ai.api_keys.anthropic',
			'ai.api_keys.google',
			'ai.api_keys.mistral',
			'ai.api_keys.custom_key',
			'seo.psi_api_key',
			'seo.serper_api_key',
			// La clé IndexNow est publique par conception, mais liée au domaine qui
			// l'a générée : un import ne doit pas la recopier sur un autre site.
			'indexnow.key',
		);
	}

	/** Valeur masquée d'un secret (vide si aucun secret enregistré). */
	public static function mask_secret( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		return self::SECRET_MASK . ( strlen( $value ) > 8 ? substr( $value, -4 ) : '' );
	}

	/** True si la valeur reçue est un masque renvoyé tel quel par l'interface. */
	public static function is_masked( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::SECRET_MASK );
	}

	/**
	 * Copie des réglages dont les secrets sont masqués (ou vidés si $strip).
	 *
	 * @param array $settings Réglages complets (CU_Settings::all()).
	 * @param bool  $strip    true : secrets remplacés par '' (export).
	 */
	public static function redact( array $settings, $strip = false ) {
		foreach ( self::secret_paths() as $path ) {
			$parts = explode( '.', $path );
			$ref   = &$settings;
			$found = true;
			foreach ( $parts as $i => $part ) {
				if ( ! is_array( $ref ) || ! array_key_exists( $part, $ref ) ) {
					$found = false;
					break;
				}
				if ( $i < count( $parts ) - 1 ) {
					$ref = &$ref[ $part ];
				}
			}
			if ( $found ) {
				$last         = end( $parts );
				$ref[ $last ] = $strip ? '' : self::mask_secret( $ref[ $last ] );
			}
			unset( $ref );
		}
		return $settings;
	}

	/**
	 * Fusionne récursivement les réglages enregistrés avec les valeurs par
	 * défaut, afin qu'une nouvelle option ajoutée dans une mise à jour du
	 * plugin ait toujours une valeur valide. Seuls les modules et clés déclarés
	 * sont renvoyés.
	 */
	public static function all() {
		$raw = get_option( CU_OPTION_SETTINGS, array() );
		// Lu des dizaines de fois par requête (pare-feu, tracker…) : on ne refait
		// la fusion/normalisation que si la valeur brute de l'option a changé.
		if ( null !== self::$all_memo && self::$all_memo_source === $raw ) {
			return self::$all_memo;
		}
		$stored   = is_array( $raw ) ? $raw : array();
		$defaults = self::get_defaults();
		$merged   = array();
		foreach ( $defaults as $module => $module_defaults ) {
			$values            = isset( $stored[ $module ] ) && is_array( $stored[ $module ] ) ? $stored[ $module ] : array();
			$merged[ $module ] = self::constrain_module(
				$module,
				self::merge_recursive( $module_defaults, self::normalize_types( $module_defaults, $values, false ) )
			);
		}
		self::$all_memo        = $merged;
		self::$all_memo_source = $raw;
		return $merged;
	}

	public static function module( $module ) {
		$all = self::all();
		return isset( $all[ $module ] ) ? $all[ $module ] : array();
	}

	public static function get( $module, $key, $default = null ) {
		$mod = self::module( $module );
		return array_key_exists( $key, $mod ) ? $mod[ $key ] : $default;
	}

	/**
	 * Met à jour un module (fusionné avec les valeurs existantes). Relit
	 * l'option en base juste avant l'écriture : une requête longue ne doit
	 * jamais réécrire une version périmée et effacer un réglage enregistré
	 * entre-temps par un autre onglet ou administrateur.
	 */
	public static function update_module( $module, $values, $log_change = true ) {
		$defaults = self::get_defaults();
		if ( ! isset( $defaults[ $module ] ) ) {
			return array();
		}
		// Lire-modifier-écrire sérialisé : deux enregistrements simultanés (deux
		// onglets, deux modules, un import) ne doivent plus s'écraser.
		self::acquire_write_lock();
		try {
			$all    = self::all();
			$values = self::normalize_types( $defaults[ $module ], is_array( $values ) ? $values : array(), false );
			$values = self::keep_masked_secrets( $module, $values, $all[ $module ] );

			$all[ $module ] = self::constrain_module( $module, self::merge_recursive( $all[ $module ], $values ) );
			update_option( CU_OPTION_SETTINGS, $all );
			self::$all_memo = null;
		} finally {
			self::release_write_lock();
		}

		if ( $log_change && self::get( 'security', 'log_setting_changes', true ) ) {
			CU_Logger::log( 'system', 'info', sprintf( __( 'Réglages "%s" mis à jour', 'cyberpush-ultra' ), $module ) );
		}

		return $all[ $module ];
	}

	/**
	 * Réinitialise un module unique à ses valeurs par défaut. Les secrets
	 * (clés API) sont conservés : une remise à zéro des préférences ne doit pas
	 * obliger à ressaisir chaque clé.
	 */
	public static function reset_module( $module ) {
		$defaults = self::get_defaults();
		if ( ! isset( $defaults[ $module ] ) ) {
			return array();
		}
		self::acquire_write_lock();
		try {
			$all     = self::all();
			$current = $all[ $module ];
			$fresh   = $defaults[ $module ];
			foreach ( self::secret_paths() as $path ) {
				$parts = explode( '.', $path );
				if ( array_shift( $parts ) !== $module ) {
					continue;
				}
				if ( 1 === count( $parts ) && isset( $current[ $parts[0] ] ) ) {
					$fresh[ $parts[0] ] = $current[ $parts[0] ];
				} elseif ( 2 === count( $parts ) && isset( $current[ $parts[0] ][ $parts[1] ] ) ) {
					$fresh[ $parts[0] ][ $parts[1] ] = $current[ $parts[0] ][ $parts[1] ];
				}
			}
			$all[ $module ] = $fresh;
			update_option( CU_OPTION_SETTINGS, $all );
			self::$all_memo = null;
		} finally {
			self::release_write_lock();
		}
		return $all[ $module ];
	}

	/** @var int Profondeur d'imbrication du verrou d'écriture (hooks update_option qui réenregistrent). */
	private static $lock_depth = 0;

	/** @var bool Verrou SQL réellement obtenu par cette requête. */
	private static $lock_held = false;

	/**
	 * Verrou d'écriture des réglages (verrou nommé MySQL/MariaDB, délai court).
	 * Relit ensuite l'option en base. Réentrant : un hook déclenché par
	 * update_option() peut lui-même enregistrer un module. Si le serveur SQL
	 * refuse GET_LOCK (certains clusters), l'écriture se fait sans verrou, comme
	 * auparavant. Public pour les migrations qui réécrivent l'option brute.
	 */
	public static function acquire_write_lock() {
		if ( self::$lock_depth++ > 0 ) {
			self::refresh_from_db();
			return;
		}
		global $wpdb;
		$suppress        = $wpdb->suppress_errors( true );
		$got             = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), 5 ) );
		self::$lock_held = ( '1' === (string) $got );
		$wpdb->suppress_errors( $suppress );
		self::refresh_from_db();
	}

	/** Libère le verrou d'écriture (au dernier niveau d'imbrication). */
	public static function release_write_lock() {
		if ( self::$lock_depth > 0 ) {
			self::$lock_depth--;
		}
		if ( self::$lock_depth > 0 || ! self::$lock_held ) {
			return;
		}
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		$wpdb->suppress_errors( $suppress );
		self::$lock_held = false;
	}

	/** Nom du verrou : propre à la base et à la table d'options (multisite : un par site). */
	private static function lock_name() {
		global $wpdb;
		return 'cu_settings_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->options );
	}

	/**
	 * Vide le cache objet de l'option avant une écriture, pour lire la valeur
	 * réellement en base (et non celle chargée au début de la requête).
	 */
	private static function refresh_from_db() {
		wp_cache_delete( CU_OPTION_SETTINGS, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		self::$all_memo = null;
	}

	/** Un secret renvoyé masqué (ou non modifié) par l'interface est conservé. */
	private static function keep_masked_secrets( $module, $values, $current ) {
		foreach ( self::secret_paths() as $path ) {
			$parts = explode( '.', $path );
			if ( array_shift( $parts ) !== $module ) {
				continue;
			}
			if ( 1 === count( $parts ) ) {
				if ( isset( $values[ $parts[0] ] ) && self::is_masked( $values[ $parts[0] ] ) ) {
					$values[ $parts[0] ] = isset( $current[ $parts[0] ] ) ? $current[ $parts[0] ] : '';
				}
			} elseif ( isset( $values[ $parts[0] ][ $parts[1] ] ) && self::is_masked( $values[ $parts[0] ][ $parts[1] ] ) ) {
				$values[ $parts[0] ][ $parts[1] ] = isset( $current[ $parts[0] ][ $parts[1] ] ) ? $current[ $parts[0] ][ $parts[1] ] : '';
			}
		}
		return $values;
	}

	/**
	 * Fusion récursive. Une carte (tableau associatif, ex. api_keys) est toujours
	 * fusionnée clé par clé, même si la valeur reçue est vide : un import ou un
	 * envoi `{ "api_keys": {} }` ne doit jamais effacer les clés enregistrées.
	 * Une liste (chemins, IP…) est remplacée en bloc.
	 */
	private static function merge_recursive( $defaults, $overrides ) {
		foreach ( $overrides as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && ( self::is_assoc( $defaults[ $key ] ) || self::is_assoc( $value ) ) ) {
				$defaults[ $key ] = self::merge_recursive( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}
		return $defaults;
	}

	/**
	 * Aligne les valeurs persistées/importées sur les types du schéma par défaut.
	 * Une option corrompue ne doit jamais transformer un tableau attendu en chaîne
	 * et provoquer ensuite une erreur fatale sous PHP 8.
	 *
	 * @param bool $keep_unknown Conserver les clés absentes du schéma. Faux au
	 *                           niveau d'un module (clés obsolètes ignorées), vrai
	 *                           pour les cartes extensibles (taxonomies ajoutées
	 *                           par des tiers).
	 */
	private static function normalize_types( $schema, $values, $keep_unknown = true ) {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$out = array();
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				if ( $keep_unknown && ( is_scalar( $value ) || null === $value ) ) {
					$out[ $key ] = $value;
				}
				continue;
			}

			$expected = $schema[ $key ];
			if ( is_array( $expected ) ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				if ( self::is_assoc( $expected ) ) {
					$out[ $key ] = self::normalize_types( $expected, $value, true );
				} else {
					// Listes bornées : l'option est chargée à chaque requête et le
					// pare-feu parcourt certaines listes à chaque visite.
					$out[ $key ] = array_slice( array_values( array_filter( $value, static function ( $item ) {
						return is_scalar( $item ) && strlen( (string) $item ) <= self::MAX_LIST_ITEM_LENGTH;
					} ) ), 0, self::MAX_LIST_ITEMS );
				}
			} elseif ( is_bool( $expected ) ) {
				if ( is_bool( $value ) ) {
					$out[ $key ] = $value;
				} elseif ( in_array( $value, array( 1, '1', 'true', 'on', 'yes' ), true ) ) {
					$out[ $key ] = true;
				} elseif ( in_array( $value, array( 0, '0', 'false', 'off', 'no', '' ), true ) ) {
					$out[ $key ] = false;
				}
			} elseif ( is_int( $expected ) ) {
				if ( is_numeric( $value ) ) {
					$out[ $key ] = (int) $value;
				}
			} elseif ( is_float( $expected ) ) {
				if ( is_numeric( $value ) ) {
					$out[ $key ] = (float) $value;
				}
			} elseif ( is_string( $expected ) && is_scalar( $value ) ) {
				$value       = (string) $value;
				$out[ $key ] = strlen( $value ) > self::MAX_STRING_LENGTH ? CU_Text::substr( $value, 0, self::MAX_STRING_LENGTH ) : $value;
			}
		}

		return $out;
	}

	/** Borne les réglages qui pilotent des délais, volumes ou scores sensibles. */
	private static function constrain_module( $module, $values ) {
		$defaults = self::get_defaults();
		$ranges = array(
			'dashboard' => array(
				'rows_per_table' => array( 5, 100 ),
			),
			'analytics' => array(
				'cache_duration_minutes'   => array( 0, 1440 ),
				'realtime_refresh_seconds' => array( 5, 300 ),
				'session_duration_minutes' => array( 1, 1440 ),
			),
			'firewall' => array(
				'flood_404_max'                  => array( 1, 10000 ),
				'flood_404_window_minutes'       => array( 1, 1440 ),
				'login_max_attempts'             => array( 1, 100 ),
				'login_lock_minutes'             => array( 1, 1440 ),
				'rate_limit_max_requests'        => array( 10, 100000 ),
				'rate_limit_window_seconds'      => array( 1, 3600 ),
				'enum_escalation_max'            => array( 1, 100 ),
				'enum_escalation_window_minutes' => array( 1, 1440 ),
				'js_challenge_ttl_hours'         => array( 1, 168 ),
				'radar_autoblock_min_score'      => array( 0, 100 ),
				'radar_autoblock_min_hits'       => array( 2, 1000 ),
			),
			'seo' => array(
				'crawl_batch_size'       => array( 1, 25 ),
				'recrawl_days'           => array( 1, 3650 ),
				'request_timeout'        => array( 5, 30 ),
				'title_min'              => array( 1, 300 ),
				'title_max'              => array( 1, 300 ),
				'meta_desc_min'          => array( 1, 1000 ),
				'meta_desc_max'          => array( 1, 1000 ),
				'content_min_words'      => array( 1, 1000000 ),
				'keyword_density_max'    => array( 0, 100 ),
				'max_crawl_depth'        => array( 1, 100 ),
				'index_auto_per_cycle'   => array( 1, 50 ),
			),
			// Données personnelles : conservation bornée (journal de sécurité :
			// 2 ans max ; statistiques : 25 mois max, plafond CNIL de la mesure
			// d'audience).
			'audit' => array(
				'retention_days' => array( 1, 730 ),
				'login_retention_days' => array( 7, 365 ),
			),
			'performance' => array(
				'auto_cleanup_days'      => array( 1, 760 ),
			),
			'spam' => array(
				'rate_limit_max'            => array( 2, 100 ),
				'rate_limit_window_minutes' => array( 1, 1440 ),
				'log_retention_days'        => array( 1, 365 ),
			),
		);

		if ( isset( $ranges[ $module ] ) ) {
			foreach ( $ranges[ $module ] as $key => $bounds ) {
				if ( isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ) {
					$number = is_float( $values[ $key ] ) ? (float) $values[ $key ] : (int) $values[ $key ];
					$values[ $key ] = max( $bounds[0], min( $bounds[1], $number ) );
				}
			}
		}

		$enums = array(
			'analytics' => array(
				'privacy_mode' => array( 'exempt', 'consent', 'standard' ),
				'geo_provider' => array( 'local', 'none', 'free', 'woocommerce', 'maxmind', 'api' ),
			),
			'firewall' => array(
				'mode'                     => array( 'normal', 'strict', 'maintenance' ),
				'ip_source'                => array( 'auto', 'remote_addr', 'x_forwarded_for', 'x_real_ip', 'cf_connecting_ip' ),
				'auto_block_duration'      => array( '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '24h', '7d', '30j', 'permanent' ),
				'xmlrpc_protection'        => array( 'disabled', 'limited', 'off' ),
				'enum_escalation_duration' => array( '24h', '7d', '30j', 'permanent' ),
				'js_challenge_scope'       => array( 'suspicious', 'all' ),
				'radar_autoblock_mode'     => array( 'ip', 'cidr' ),
				'radar_autoblock_duration' => array( '24h', '7d', '30j', 'permanent' ),
				'hotlink_action'           => array( 'forbidden', 'redirect' ),
			),
			'seo' => array(
				'crawl_scope'  => array( 'tracked', 'all_public' ),
				'psi_strategy' => array( 'mobile', 'desktop' ),
			),
			'indexnow' => array(
				// Doit rester aligné sur CU_IndexNow::endpoints() (registre indexnow.org).
				'endpoint' => array( 'api.indexnow.org', 'indexnow.amazonbot.amazon', 'www.bing.com', 'yandex.com', 'searchadvisor.naver.com', 'search.seznam.cz', 'indexnow.yep.com', 'internetarchive.indexnow.org' ),
			),
			'ai' => array(
				'provider' => array( 'openai', 'anthropic', 'google', 'mistral', 'ollama', 'custom' ),
				'tone'     => array( 'professionnel', 'amical', 'direct' ),
			),
			'spam' => array(
				'disposable_action' => array( 'block', 'suspect' ),
			),
		);
		if ( isset( $enums[ $module ] ) ) {
			foreach ( $enums[ $module ] as $key => $allowed ) {
				if ( ! isset( $values[ $key ] ) || ! in_array( $values[ $key ], $allowed, true ) ) {
					$values[ $key ] = $defaults[ $module ][ $key ];
				}
			}
		}

		if ( 'firewall' === $module ) {
			// Paliers d'escalade : durées connues uniquement, jamais « permanent ».
			$steps = array();
			foreach ( (array) $values['escalation_steps'] as $step ) {
				if ( in_array( $step, array( '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '24h', '7d' ), true ) ) {
					$steps[] = $step;
				}
			}
			$values['escalation_steps'] = $steps ? $steps : $defaults['firewall']['escalation_steps'];
			$values['trusted_proxies'] = self::clean_ip_list( $values['trusted_proxies'], true );
			$values['hotlink_redirect_url'] = self::clean_http_url( $values['hotlink_redirect_url'] );
		}
		if ( 'analytics' === $module ) {
			$values['anonymous_fallback'] = isset( $values['anonymous_fallback'] ) ? (bool) $values['anonymous_fallback'] : (bool) $defaults['analytics']['anonymous_fallback'];
			$values['excluded_ips'] = self::clean_ip_list( $values['excluded_ips'], true );
			$countries = array();
			foreach ( (array) $values['excluded_countries'] as $country ) {
				$code = class_exists( 'CU_Geo' ) ? CU_Geo::normalize_country( $country ) : strtoupper( (string) $country );
				if ( '' !== $code ) {
					$countries[ $code ] = true;
				}
			}
			$values['excluded_countries'] = array_keys( $countries );
		}
		if ( 'ai' === $module && isset( $values['api_keys'] ) && is_array( $values['api_keys'] ) ) {
			foreach ( array( 'ollama_url', 'custom_url' ) as $url_key ) {
				if ( isset( $values['api_keys'][ $url_key ] ) ) {
					$values['api_keys'][ $url_key ] = self::clean_http_url( $values['api_keys'][ $url_key ], true );
				}
			}
		}

		if ( 'spam' === $module ) {
			$forms = array();
			foreach ( (array) $values['excluded_forms'] as $form_id ) {
				$form_id = is_numeric( $form_id ) ? (int) $form_id : 0;
				if ( $form_id > 0 && count( $forms ) < 1000 ) {
					$forms[ $form_id ] = true;
				}
			}
			$values['excluded_forms'] = array_keys( $forms );
			foreach ( array( 'allowed_domains', 'blocked_domains' ) as $list_key ) {
				$values[ $list_key ] = class_exists( 'CU_Spam' ) ? CU_Spam::clean_domain_list( $values[ $list_key ] ) : array_slice( array_values( array_filter( (array) $values[ $list_key ], 'is_string' ) ), 0, 500 );
			}
		}

		// Destinataire des alertes : une adresse invalide est vidée (repli sur
		// l'e-mail d'administration) plutôt que de faire échouer chaque alerte.
		if ( 'audit' === $module && isset( $values['alert_email'] ) ) {
			$email                 = sanitize_email( (string) $values['alert_email'] );
			$values['alert_email'] = ( '' !== $email && is_email( $email ) ) ? $email : '';
		}

		// Des minima supérieurs aux maxima rendent chaque page fautive.
		if ( 'seo' === $module ) {
			if ( $values['title_min'] > $values['title_max'] ) {
				$values['title_min'] = $values['title_max'];
			}
			if ( $values['meta_desc_min'] > $values['meta_desc_max'] ) {
				$values['meta_desc_min'] = $values['meta_desc_max'];
			}
		}

		return $values;
	}

	/** Liste d'IP (ou CIDR) normalisées, dédoublonnées ; entrées invalides retirées. */
	private static function clean_ip_list( $list, $allow_cidr ) {
		$out = array();
		foreach ( (array) $list as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}
			$entry = class_exists( 'CU_Geo' )
				? ( $allow_cidr ? CU_Geo::normalize_ip_or_cidr( $entry ) : CU_Geo::normalize_ip( $entry ) )
				: trim( (string) $entry );
			if ( '' !== $entry ) {
				$out[ $entry ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * URL http(s) assainie, sans identifiants. $base_only : réduite à
	 * schéma://hôte[:port][/chemin] (ni requête, ni fragment) pour les URL
	 * d'API auxquelles le plugin ajoute lui-même un chemin.
	 */
	private static function clean_http_url( $url, $base_only = false ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( ! $base_only ) {
			return esc_url_raw( $url, array( 'http', 'https' ) );
		}
		$clean = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] )
			. ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' )
			. ( ! empty( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '' );
		return esc_url_raw( $clean, array( 'http', 'https' ) );
	}

	private static function is_assoc( $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CU_Firewall — Pare-feu applicatif intégré à WordPress.
 *
 * Points d'accroche réels (voir init()) :
 *  - `init` priorité 0 : contrôle XML-RPC (avant le dispatch IXR) ;
 *  - `init` priorité 1 : inspection principale de chaque requête ;
 *  - `template_redirect` 0/1 : défi JavaScript (opt-in) puis flood 404 ;
 *  - `authenticate` 30 / `wp_login_failed` : protection de la connexion ;
 *  - `rest_authentication_errors` 110 : routes REST bloquées (visiteurs anonymes).
 *
 * Gradation volontaire des réponses (« précis et peu intrusif ») :
 *  1. refus d'UNE requête (403) — première détection d'un contenu suspect ;
 *  2. limite temporaire (429 / verrou de connexion court) — débit excessif ;
 *  3. bannissement — seulement après des preuves répétées, durée plafonnée.
 *
 * Récupération : `define( 'CU_FIREWALL_DISABLED', true );` dans wp-config.php
 * désactive entièrement le pare-feu (aucun blocage, aucune écriture).
 */
class CU_Firewall {

	private static $instance = null;

	/** @var bool Tables SQL disponibles ? (vérifié une fois par requête) */
	private static $tables_ok = null;

	/** @var bool Un crawler vérifié a déjà été compté pendant cette requête. */
	private static $good_bot_recorded = false;

	/** @var bool Budget de reverse-DNS épuisé pendant cette requête (résultat non concluant). */
	private static $dns_budget_exhausted = false;

	/** @var bool Le dernier reverse-DNS n'a renvoyé aucun nom d'hôte. */
	private static $rdns_no_host = false;

	/** @var bool Vérification DNS reportée en arrière-plan pendant cette requête (résultat non concluant). */
	private static $rdns_deferred = false;

	/** @var array<string,bool> Mémo « crawler vérifié » par IP pour la requête courante. */
	private static $good_bot_memo = array();

	/** @var array<string,array> Entrées exactes (whitelist/blocages) lues pour la requête courante. */
	private static $exact_memo = array();

	/** @var array|null Listes CIDR (whitelist + blocages) en cache pour la requête courante. */
	private static $cidr_lists = null;

	/** @var array|null Contexte de la requête courante (AJAX, connexion, REST…). */
	private static $ctx = null;

	/** @var array|null État de l'utilisateur courant tel que le pare-feu doit le considérer. */
	private static $user_state = null;

	/** Analyse WAF : taille d'un segment, recouvrement et budget global d'octets par requête. */
	const WAF_CHUNK     = 4096;
	const WAF_OVERLAP   = 256;
	const WAF_BUDGET    = 262144;
	const WAF_MAX_ITEMS = 2048;

	/** Clés de cache (transients autochargés, sans expiration : lecture gratuite). */
	const LIST_VERSION_KEY = 'cu_fw_list_ver';
	const LIST_CACHE_KEY   = 'cu_fw_cidr_lists';
	const SERVER_IPS_KEY   = 'cu_fw_server_ips';
	/** Relais publics vus sur les requêtes d'un administrateur connecté (jamais bannis). */
	const ADMIN_RELAYS_KEY = 'cu_fw_admin_relays';

	/** File des robots à vérifier par reverse-DNS en arrière-plan (option non chargée automatiquement) et tâche associée. */
	const RDNS_QUEUE_KEY  = 'cu_fw_rdns_queue';
	const RDNS_QUEUE_HOOK = 'cu_fw_rdns_verify';
	const RDNS_QUEUE_MAX  = 50;

	// -----------------------------------------------------------------------
	// Singleton
	// -----------------------------------------------------------------------

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// -----------------------------------------------------------------------
	// Résolution IP client
	// -----------------------------------------------------------------------

	/**
	 * Retourne l'IP réelle du visiteur via le résolveur sécurisé partagé
	 * (anti-usurpation : CF-Connecting-IP uniquement si la requête vient de
	 * Cloudflare, X-Forwarded-For uniquement derrière un reverse-proxy local).
	 * Voir CU_Geo::client_ip(). On renvoie '' (et non '0.0.0.0') pour conserver
	 * la sémantique du pare-feu : « pas d'IP fiable » → on n'agit pas.
	 *
	 * @return string IP validée ou chaîne vide.
	 */
	public static function get_client_ip() {
		$ip = CU_Geo::client_ip();
		return ( '0.0.0.0' === $ip ) ? '' : $ip;
	}

	// -----------------------------------------------------------------------
	// Initialisation
	// -----------------------------------------------------------------------

	public function init() {
		// Enregistrement des routes REST admin (toujours disponibles, même
		// lorsque le pare-feu est coupé : l'administrateur doit pouvoir le gérer).
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Interrupteur de secours (wp-config.php) : aucun filtrage, aucun blocage.
		if ( self::kill_switch() ) {
			return;
		}

		// Vérification DNS des robots déclarés, hors du parcours des visiteurs (WP-Cron).
		add_action( self::RDNS_QUEUE_HOOK, array( $this, 'process_rdns_queue' ) );

		// Inspection de la requête au plus tôt (hook 'init', priorité 1).
		add_action( 'init', array( $this, 'inspect_request' ), 1 );

		// Défi JavaScript (« Bot Fight Mode » maison). Sur template_redirect
		// (priorité 0, avant le flood 404) : uniquement le front-end, jamais
		// REST/admin/AJAX/cron. Ne fait rien tant qu'il n'est pas activé.
		add_action( 'template_redirect', array( $this, 'maybe_js_challenge' ), 0 );

		// Flood 404.
		add_action( 'template_redirect', array( $this, 'check_flood_404' ), 1 );

		// Protection de la connexion. Le filtre passe APRÈS la vérification des
		// identifiants (priorité 30 > 20) : pendant un verrou ou un blocage, toute
		// authentification est refusée, administrateurs compris (seule la
		// whitelist ou la constante CU_FIREWALL_DISABLED lèvent ce refus).
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 2 );
		add_filter( 'authenticate', array( $this, 'maybe_block_login' ), 30, 3 );

		// Protection XML-RPC.
		add_action( 'init', array( $this, 'check_xmlrpc_request' ), 0 );
		add_filter( 'xmlrpc_enabled', array( $this, 'filter_xmlrpc' ) );
		add_filter( 'xmlrpc_methods', array( $this, 'filter_xmlrpc_methods' ) );

		// Protection REST API : après les contrôles d'authentification du cœur
		// (cookie + nonce à 100, mots de passe d'application à 90), pour savoir
		// réellement si le visiteur est anonyme.
		add_filter( 'rest_authentication_errors', array( $this, 'filter_rest_api' ), 110 );

		// Anti-énumération d'utilisateurs via l'API REST (/wp/v2/users).
		add_filter( 'rest_endpoints', array( $this, 'restrict_rest_user_endpoints' ) );
		// … et WordPress n'annonce plus ces routes refusées (lien « alternate »
		// des archives d'auteur) : les robots n'y perdent plus de requêtes en 403.
		add_filter( 'rest_queried_resource_route', array( $this, 'hide_user_rest_route' ) );

		// Protection Upload.
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'filter_upload' ) );
	}

	/** Constante de secours `CU_FIREWALL_DISABLED` (wp-config.php). */
	public static function kill_switch() {
		return defined( 'CU_FIREWALL_DISABLED' ) && CU_FIREWALL_DISABLED;
	}

	/** Pare-feu actif : ni constante de secours, ni interrupteur maître coupé. */
	private static function firewall_active() {
		return ! self::kill_switch() && (bool) CU_Settings::get( 'firewall', 'enabled', true );
	}

	/**
	 * Masque les routes REST listant les utilisateurs aux visiteurs non
	 * connectés (technique de reconnaissance classique : /wp-json/wp/v2/users).
	 * Un site « headless » peut les rouvrir : add_filter( 'cu_firewall_allow_rest_users', '__return_true' ).
	 */
	public function restrict_rest_user_endpoints( $endpoints ) {
		if ( ! self::firewall_active() || ! CU_Settings::get( 'firewall', 'block_user_enumeration', true ) || is_user_logged_in() ) {
			return $endpoints;
		}
		if ( self::rest_users_allowed() ) {
			return $endpoints;
		}
		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * Archive d'auteur : WordPress (5.5+) ajoute dans le <head> et l'en-tête
	 * Link un lien vers /wp-json/wp/v2/users/N. Quand cette route est refusée
	 * aux anonymes, le lien n'est plus émis (les robots le suivaient en 403).
	 */
	public function hide_user_rest_route( $route ) {
		if ( ! is_string( $route ) || 0 !== strpos( $route, '/wp/v2/users' ) ) {
			return $route;
		}
		if ( ! self::firewall_active() || ! CU_Settings::get( 'firewall', 'block_user_enumeration', true ) || is_user_logged_in() || self::rest_users_allowed() ) {
			return $route;
		}
		return '';
	}

	// -----------------------------------------------------------------------
	// Inspection principale de chaque requête
	// -----------------------------------------------------------------------

	public function inspect_request() {
		if ( ! self::firewall_active() ) {
			return;
		}
		$ip = self::get_client_ip();
		if ( '' === $ip ) {
			return;
		}
		$ctx = self::request_context();

		// 0. Exemptions absolues, évaluées AVANT toute requête SQL : crawl SEO
		//    signé, cron / WP-CLI, administrateurs connectés (pour l'API REST,
		//    seulement avec un nonce `wp_rest` valide, comme WordPress lui-même).
		if ( self::is_request_exempt() ) {
			return;
		}
		// robots.txt est toujours servi : Google suspend l'exploration de TOUT le
		// site pendant 12 h s'il reçoit un 429 ou un 5xx sur ce fichier. Il ne
		// contient rien de sensible et WordPress n'en lit aucun paramètre.
		if ( '/robots.txt' === strtolower( self::request_path() ) && in_array( self::request_method(), array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		// Sécurité : ne jamais bloquer si les tables n'existent pas.
		if ( ! self::tables_available() ) {
			return;
		}

		// 1. Whitelist (entrées non expirées) → toujours laisser passer.
		if ( self::is_whitelisted( $ip ) ) {
			return;
		}

		$user = self::user_state();

		// 2. Blocklist, appliquée partout (connexion, admin-ajax, admin-post et
		//    wp-admin compris). Seul un bannissement AUTOMATIQUE épargne un crawler
		//    de moteur vérifié ; un blocage MANUEL de l'administrateur s'applique à
		//    tous. Les verrous de connexion (type « login ») ne visent que
		//    l'authentification : le reste du site reste accessible.
		$block = self::find_block( $ip );
		if ( $block && 'login' !== $block['type'] ) {
			if ( 'manual' !== $block['type'] && self::is_good_bot_request( $ip ) ) {
				self::log_event( $ip, 'allowed', 'goodbot_spared', 'low' );
			} else {
				self::track_block_hit( $block['ip_or_cidr'] );
				self::render_blocked_page( $ip );
			}
		}

		// 3. Vraies pages de wp-admin : WordPress gère lui-même l'authentification
		//    (redirection vers la page de connexion). Rien d'autre à filtrer.
		if ( $ctx['admin_page'] ) {
			return;
		}

		// 4. Page de connexion (wp-login.php ou URL masquée) : jamais d'analyse WAF
		//    (un mot de passe est du texte libre), mais une limite modeste des POST
		//    (connexion, mot de passe oublié, inscription), sans bannissement.
		if ( $ctx['auth'] ) {
			self::enforce_login_post_limit( $ip );
			return;
		}

		$mode = CU_Settings::get( 'firewall', 'mode', 'normal' );

		// 5. admin-ajax.php / admin-post.php.
		if ( $ctx['ajax'] ) {
			if ( $user['logged_in'] ) {
				// Connectés : exemptés du WAF ; seul le débit reste borné pour les
				// comptes sans droit d'édition (abonnés, clients).
				if ( ! $user['editor'] ) {
					self::enforce_rate_limit( $ip, $mode );
				}
				return;
			}
			self::inspect_anonymous_ajax( $ip, $mode );
			return;
		}

		// 6. Mode maintenance : 503 + Retry-After (jamais 403), crawlers compris.
		if ( 'maintenance' === $mode && ! self::is_maintenance_exempt() ) {
			self::log_event( $ip, 'blocked', 'maintenance_mode', 'low', 503 );
			self::render_blocked_page( $ip, 503 );
		}

		// 7. Crawlers de recherche authentifiés (plage officielle ou FCrDNS ; le
		//    User-Agent seul ne suffit jamais) : exemptés des règles automatiques
		//    (débit, WAF, sondes, bad-bots). robots.txt, sitemaps et ressources
		//    servies par WordPress leur restent donc toujours accessibles.
		if ( self::is_good_bot_request( $ip ) ) {
			return;
		}

		// 8. Limitation de débit AVANT tout refus : un client qui enchaîne des
		//    requêtes refusées (WAF, sondes…) est lui aussi ralenti, puis banni.
		if ( ! $user['editor'] && ! self::is_analytics_beacon_request() ) {
			self::enforce_rate_limit( $ip, $mode );
		}

		$ua = self::current_ua();

		if ( 'strict' === $mode && '' === trim( $ua ) && self::block_ip( $ip, __( 'User-Agent vide (mode strict)', 'cyberpush-ultra' ), 'strict_mode', 'medium' ) ) {
			self::render_blocked_page( $ip );
		}

		// 9. Méthodes HTTP bloquées (TRACE/TRACK : jamais légitimes).
		$method = self::request_method();
		if ( in_array( $method, self::blocked_methods(), true ) && self::block_ip( $ip, 'Méthode HTTP interdite : ' . $method, 'method_block', 'medium' ) ) {
			self::render_blocked_page( $ip );
		}

		// 10. Mots-clés d'URL, comparés au chemin et aux paramètres DÉCODÉS
		//     (`%2D` ou un double encodage ne contournent plus la règle).
		$keyword = self::matched_url_keyword();
		if ( '' !== $keyword ) {
			self::refuse_malicious( $ip, 'url_keyword', 'medium', 'Mot-clé URL bloqué : ' . $keyword, $user );
		}

		// 11. Anti-hotlink (repli PHP — la protection réelle des fichiers statiques
		//     est en .htaccess). Refus sans bannissement : le référent est falsifiable.
		if ( CU_Settings::get( 'firewall', 'hotlink_protection_enabled', false ) && self::is_hotlink_request() ) {
			self::log_event( $ip, 'blocked', 'hotlink', 'low' );
			$redir = trim( (string) CU_Settings::get( 'firewall', 'hotlink_redirect_url', '' ) );
			if ( 'redirect' === CU_Settings::get( 'firewall', 'hotlink_action', 'forbidden' ) && '' !== $redir && ! self::hotlink_redirect_would_loop( $redir ) ) {
				wp_redirect( esc_url_raw( $redir ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
			status_header( 403 );
			nocache_headers();
			exit;
		}

		// 12. Sondes de chemins sensibles (/.env, /.git…) : la requête est refusée ;
		//     le bannissement n'intervient qu'après plusieurs sondes DISTINCTES
		//     (une image tierce pointant vers /.env ne fait bannir personne).
		if ( CU_Settings::get( 'firewall', 'probe_detection_enabled', true ) ) {
			$uri_path = self::request_path();
			foreach ( (array) CU_Settings::get( 'firewall', 'sensitive_paths', array() ) as $path ) {
				if ( self::sensitive_path_matches( $uri_path, $path ) ) {
					self::refuse_malicious( $ip, 'probe_detection', 'high', 'Sonde détectée : ' . ( is_scalar( $path ) ? (string) $path : '' ), $user );
				}
			}
		}

		// 13. Scanners d'attaque qui se déclarent par leur User-Agent → bannissement.
		self::check_scanner_ua( $ip, $ua );

		// 14. Bad-bots (scrapers) : refus 403, sans bannissement (UA constant).
		self::check_bad_bot_ua( $ip, $ua );

		// 15. WAF par signatures (SQLi / XSS / traversée / RCE).
		if ( CU_Settings::get( 'firewall', 'waf_enabled', true ) ) {
			$hit = self::detect_malicious_payload( 'front' );
			if ( $hit ) {
				self::handle_waf_hit( $ip, $hit, $user );
			}
		}

		// 16. Anti-énumération d'utilisateurs (?author=N), visiteurs anonymes.
		if ( CU_Settings::get( 'firewall', 'block_user_enumeration', true ) && ! $user['logged_in'] ) {
			self::check_author_enumeration( $ip );
		}

		// 17. Durcissement REST 2026 (anonymes ; nonce REST vérifié).
		if ( CU_Settings::get( 'firewall', 'rest_hardening_enabled', true ) && ! $user['logged_in'] ) {
			self::check_rest_hardening( $ip, $user );
		}

		// 18. Échantillon des requêtes AUTORISÉES (1/20) : uniquement en mode
		//     débogage (Réglages ▸ Développeur), URL enregistrée sans paramètres.
		if ( CU_Settings::get( 'developer', 'debug_mode', false ) && 0 === wp_rand( 0, 19 ) ) {
			self::log_request( $ip, 'allowed', null, null );
		}

		// 19. Radar — observateur passif : jamais bloquant.
		if ( CU_Settings::get( 'firewall', 'radar_enabled', true ) ) {
			self::radar_observe( $ip );
		}
	}

	/**
	 * Visiteur NON connecté sur admin-ajax.php / admin-post.php : blocklist (déjà
	 * appliquée), débit, scanners déclarés, puis WAF en mode « refus seul »
	 * (403 sans jamais bannir à partir du contenu d'un formulaire AJAX).
	 * Filtre `cu_firewall_exempt_ajax_actions` : actions exclues du WAF.
	 */
	private static function inspect_anonymous_ajax( $ip, $mode ) {
		if ( ! self::is_analytics_beacon_request() && ! self::is_good_bot_request( $ip ) ) {
			self::enforce_rate_limit( $ip, $mode );
		}
		$method = self::request_method();
		if ( in_array( $method, self::blocked_methods(), true ) && self::block_ip( $ip, 'Méthode HTTP interdite : ' . $method, 'method_block', 'medium' ) ) {
			self::render_blocked_page( $ip );
		}
		$ua = self::current_ua();
		self::check_scanner_ua( $ip, $ua );
		self::check_bad_bot_ua( $ip, $ua );

		if ( ! CU_Settings::get( 'firewall', 'waf_enabled', true ) || self::is_exempt_ajax_action() ) {
			return;
		}
		$hit = self::detect_malicious_payload( 'ajax' );
		if ( ! $hit ) {
			return;
		}
		if ( ! empty( $hit['error'] ) ) {
			self::deny_waf_error( $ip, $hit );
		}
		self::log_event(
			$ip,
			'blocked',
			'waf_' . $hit['rule'],
			'high',
			403,
			sprintf( __( 'Requête AJAX refusée — payload suspect (%1$s) : %2$s', 'cyberpush-ultra' ), $hit['type'], $hit['sample'] )
		);
		self::render_blocked_page( $ip );
	}

	/** Action admin-ajax/admin-post exclue du WAF par un filtre de compatibilité. */
	private static function is_exempt_ajax_action() {
		$action = '';
		if ( isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = (string) wp_unslash( $_REQUEST['action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === $action ) {
			return false;
		}
		$exempt = array_filter( array_map( 'strval', (array) apply_filters( 'cu_firewall_exempt_ajax_actions', array() ) ) );
		return in_array( $action, $exempt, true );
	}

	/**
	 * Traite une détection WAF. Corps de requête : refus seul (texte libre écrit
	 * par un humain). Paramètres d'URL : refus, puis bannissement uniquement après
	 * plusieurs requêtes malveillantes distinctes (voir refuse_malicious()).
	 */
	private static function handle_waf_hit( $ip, array $hit, array $user ) {
		if ( ! empty( $hit['error'] ) ) {
			self::deny_waf_error( $ip, $hit );
		}
		$rule = 'waf_' . $hit['rule'];
		$msg  = sprintf( __( 'Payload malveillant (%1$s) : %2$s', 'cyberpush-ultra' ), $hit['type'], $hit['sample'] );
		if ( 'body' === $hit['source'] ) {
			self::log_event( $ip, 'blocked', $rule, 'high', 403, sprintf( __( 'Requête refusée — payload suspect dans un formulaire (%1$s) : %2$s', 'cyberpush-ultra' ), $hit['type'], $hit['sample'] ) );
			self::render_blocked_page( $ip );
		}
		self::refuse_malicious( $ip, $rule, 'critical', $msg, $user );
	}

	/**
	 * Erreur PCRE (limite de pile JIT, backtracking…) : on refuse la requête par
	 * précaution, sans bannir, et on le signale une seule fois par période.
	 */
	private static function deny_waf_error( $ip, array $hit ) {
		self::log_event( $ip, 'blocked', 'waf_error', 'medium' );
		if ( ! get_transient( 'cu_fw_waf_error_notice' ) ) {
			set_transient( 'cu_fw_waf_error_notice', 1, HOUR_IN_SECONDS );
			CU_Logger::log( 'firewall', 'error', __( 'Analyse WAF interrompue par une erreur PCRE : requête refusée par précaution (sans bannissement).', 'cyberpush-ultra' ), array(
				'ip'    => $ip,
				'rule'  => isset( $hit['rule'] ) ? (string) $hit['rule'] : '',
				'error' => (string) $hit['error'],
			) );
		}
		self::render_blocked_page( $ip );
	}

	/**
	 * Refuse une requête malveillante (403). Le bannissement n'est prononcé qu'à
	 * partir de N requêtes malveillantes DISTINCTES de la même IP (préfixe /64 en
	 * IPv6) sur une fenêtre courte. Ne comptent jamais : les éditeurs connectés,
	 * les adresses non publiques ou du serveur, et les requêtes qu'un navigateur
	 * émet pour le compte d'un AUTRE site (Sec-Fetch-Site cross-site, référent
	 * externe) — sans quoi une page tierce pourrait faire bannir ses lecteurs.
	 */
	private static function refuse_malicious( $ip, $rule, $severity, $reason, array $user ) {
		$can_count = empty( $user['editor'] ) && ! self::is_infra_ip( $ip ) && ! self::is_cross_site_request();
		if ( $can_count ) {
			$distinct = self::record_malicious_hit( $ip, $rule );
			if ( $distinct >= self::waf_ban_threshold() ) {
				$banned = self::block_ip(
					$ip,
					sprintf(
						/* translators: 1: raison, 2: nombre de requêtes */
						__( '%1$s — %2$d requêtes malveillantes distinctes', 'cyberpush-ultra' ),
						$reason,
						$distinct
					),
					$rule,
					$severity,
					true
				);
				if ( ! $banned ) {
					self::log_event( $ip, 'blocked', $rule, $severity, 403 );
				}
				self::render_blocked_page( $ip );
			}
		}
		self::log_event( $ip, 'blocked', $rule, $severity, 403, $reason );
		self::render_blocked_page( $ip );
	}

	/** Seuil de requêtes malveillantes distinctes avant bannissement (filtrable, ≥ 2). */
	private static function waf_ban_threshold() {
		return max( 2, (int) apply_filters( 'cu_firewall_waf_ban_threshold', 3 ) );
	}

	/**
	 * Mémorise une requête malveillante et renvoie le nombre de requêtes
	 * DISTINCTES (méthode + URI + règle + taille du corps) sur la fenêtre.
	 * Rejouer la même URL (image tierce rechargée) ne compte qu'une fois.
	 */
	private static function record_malicious_hit( $ip, $rule ) {
		$window = max( 60, (int) apply_filters( 'cu_firewall_waf_ban_window', 10 * MINUTE_IN_SECONDS ) );
		$key    = self::counter_key( 'wafhit', $ip );
		$now    = time();
		$state  = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['exp'] ) || (int) $state['exp'] <= $now || ! isset( $state['h'] ) || ! is_array( $state['h'] ) ) {
			$state = array( 'exp' => $now + $window, 'h' => array() );
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (string) (int) $_SERVER['CONTENT_LENGTH'] : '0';
		$sig    = substr( md5( self::request_method() . '|' . substr( $uri, 0, 2048 ) . '|' . $rule . '|' . $length ), 0, 12 );
		if ( ! isset( $state['h'][ $sig ] ) && count( $state['h'] ) < 32 ) {
			$state['h'][ $sig ] = 1;
		}
		set_transient( $key, $state, max( 1, (int) $state['exp'] - $now ) );
		return count( $state['h'] );
	}

	/**
	 * Requête émise par un navigateur pour le compte d'un AUTRE site (image,
	 * script, iframe ou navigation provoquée par une page tierce). Les en-têtes
	 * Fetch Metadata ne sont pas modifiables par une page web ; à défaut
	 * (navigateur ancien), un référent externe est retenu.
	 */
	private static function is_cross_site_request() {
		$site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) && is_string( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? strtolower( trim( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		if ( 'cross-site' === $site || 'same-site' === $site ) {
			return true;
		}
		if ( '' !== $site ) {
			return false; // same-origin ou none (saisie directe, favori).
		}
		$referer = isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		if ( '' === $referer ) {
			return false;
		}
		$ref_host  = strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return '' !== $ref_host && $ref_host !== $home_host;
	}

	/**
	 * Limitation de débit : au-delà du seuil, LIMITE TEMPORAIRE (429 +
	 * Retry-After jusqu'à la fin de la fenêtre), jamais de bannissement. C'est le
	 * signal que Google recommande pour ralentir un robot (un 403 ou un 404 ferait
	 * retirer les pages de l'index) ; un bannissement n'économiserait rien ici,
	 * WordPress étant déjà chargé. Une fois le seuil dépassé, le compteur n'est
	 * plus réécrit : une rafale ne provoque aucune écriture supplémentaire.
	 * Jamais pour une adresse non publique / du serveur (proxy mal déclaré,
	 * préchargeur de cache).
	 */
	private static function enforce_rate_limit( $ip, $mode ) {
		if ( ! CU_Settings::get( 'firewall', 'rate_limit_enabled', true ) || self::is_infra_ip( $ip ) ) {
			return;
		}
		$max = max( 1, (int) CU_Settings::get( 'firewall', 'rate_limit_max_requests', 120 ) );
		if ( 'strict' === $mode ) {
			$max = max( 1, (int) floor( $max / 2 ) );
		}
		$window = self::counter_window( 'rl' );
		$key    = self::counter_key( 'rl', $ip );
		$now    = time();
		if ( self::fixed_window_counter_value( $key, $window ) > $max ) {
			self::log_event( $ip, 'blocked', 'rate_limit', 'low', 429 );
			// Fenêtres alignées sur l'horloge : attente jusqu'à la fin de la fenêtre en cours.
			self::render_blocked_page( $ip, 429, max( 1, $window - ( $now % $window ) ) );
		}
		$state = self::increment_fixed_window_counter( $key, $window );
		if ( (int) $state['count'] <= $max ) {
			return;
		}
		self::log_event( $ip, 'blocked', 'rate_limit', 'low', 429 );
		self::render_blocked_page( $ip, 429, max( 1, (int) $state['expires_at'] - time() ) );
	}

	/**
	 * Limite modeste des POST sur la page de connexion (connexion, mot de passe
	 * oublié, inscription), par IP (préfixe /64 en IPv6) : 429 temporaire, jamais
	 * de bannissement. Filtre `cu_firewall_login_post_limit` (défaut 30 / 5 min).
	 */
	private static function enforce_login_post_limit( $ip ) {
		if ( 'POST' !== self::request_method() || ! CU_Settings::get( 'firewall', 'login_protection_enabled', true ) || self::is_infra_ip( $ip ) ) {
			return;
		}
		$max   = max( 5, (int) apply_filters( 'cu_firewall_login_post_limit', 30 ) );
		$state = self::increment_fixed_window_counter( self::counter_key( 'lpost', $ip ), self::counter_window( 'lpost' ) );
		if ( (int) $state['count'] > $max ) {
			self::log_event( $ip, 'blocked', 'login_rate_limit', 'medium', 429 );
			self::render_blocked_page( $ip, 429, max( 1, (int) $state['expires_at'] - time() ) );
		}
	}

	/** Scanners de vulnérabilités qui se déclarent (sqlmap, wpscan…) : bannissement. */
	private static function check_scanner_ua( $ip, $ua ) {
		if ( '' === $ua || ! CU_Settings::get( 'firewall', 'scanner_detection_enabled', true ) ) {
			return;
		}
		foreach ( (array) CU_Settings::get( 'firewall', 'scanner_signatures', array() ) as $sig ) {
			if ( self::scanner_signature_matches( $ua, $sig ) ) {
				if ( self::block_ip( $ip, 'Scanner détecté : ' . $sig, 'scanner_detection', 'high' ) ) {
					self::render_blocked_page( $ip );
				}
				return;
			}
		}
	}

	/** Bad-bots (aspirateurs de contenu) : refus 403 sans bannissement. */
	private static function check_bad_bot_ua( $ip, $ua ) {
		if ( '' === $ua || ! CU_Settings::get( 'firewall', 'bad_bot_blocking_enabled', true ) ) {
			return;
		}
		foreach ( (array) CU_Settings::get( 'firewall', 'bad_bot_signatures', array() ) as $sig ) {
			if ( is_scalar( $sig ) && '' !== trim( (string) $sig ) && false !== stripos( $ua, trim( (string) $sig ) ) ) {
				self::log_event( $ip, 'blocked', 'bad_bot', 'medium', 403, 'Bot malveillant : ' . $sig );
				self::render_blocked_page( $ip );
			}
		}
	}

	/**
	 * ?author=N : avec les permaliens simples, c'est l'URL NATIVE d'une archive
	 * d'auteur (autorisée). Avec de jolis permaliens, toute valeur commençant
	 * par un chiffre (y compris « 1x », que WordPress lit comme 1) est refusée.
	 */
	private static function check_author_enumeration( $ip ) {
		if ( ! isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}
		$raw    = wp_unslash( $_GET['author'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$values = is_array( $raw ) ? array_slice( array_values( $raw ), 0, 20 ) : array( $raw );
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && preg_match( '/^\s*\+?\d/', (string) $value ) ) {
				self::block_enumeration( $ip, 'user_enumeration' );
			}
		}
	}

	/** Menaces REST activement exploitées (visiteurs anonymes). */
	private static function check_rest_hardening( $ip, array $user ) {
		$route = self::current_rest_route();

		// wp2shell (CVE-2026-63030) : RCE non authentifiée via l'API batch.
		if ( '' !== $route && 0 === strpos( $route, '/batch/v' ) ) {
			self::refuse_malicious( $ip, 'rest_batch', 'critical', __( 'Accès anonyme à l\'API batch (wp2shell / CVE-2026-63030)', 'cyberpush-ultra' ), $user );
		}

		// CVE-2026-60137 : injection SQL via author__not_in / author__in non numériques.
		foreach ( array( 'author__not_in', 'author__in' ) as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$raw  = wp_unslash( $_GET[ $param ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$flat = is_array( $raw ) ? implode( ',', array_filter( array_slice( $raw, 0, 200 ), 'is_scalar' ) ) : ( is_scalar( $raw ) ? (string) $raw : '' );
				if ( '' !== trim( $flat ) && ! preg_match( '/^[0-9,\s]{1,4096}$/', $flat ) ) {
					self::refuse_malicious( $ip, 'rest_sqli_author', 'critical', sprintf( __( 'Injection SQL via %s (CVE-2026-60137)', 'cyberpush-ultra' ), $param ), $user );
				}
			}
		}

		// Énumération d'utilisateurs via l'API REST (refusée ; bannissement après récidive).
		if ( '' !== $route && CU_Settings::get( 'firewall', 'block_user_enumeration', true ) && ! self::rest_users_allowed()
			&& ( '/wp/v2/users' === $route || 0 === strpos( $route, '/wp/v2/users/' ) ) ) {
			self::block_enumeration( $ip, 'rest_user_enum' );
		}
	}

	/** Site « headless » : la liste des auteurs via REST peut être rouverte par filtre. */
	private static function rest_users_allowed() {
		return (bool) apply_filters( 'cu_firewall_allow_rest_users', false );
	}

	/** Mot-clé d'URL configuré, recherché dans le chemin et la requête décodés. */
	private static function matched_url_keyword() {
		$keywords = (array) CU_Settings::get( 'firewall', 'blocked_url_keywords', array() );
		if ( empty( $keywords ) ) {
			return '';
		}
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$hay   = strtolower( self::request_path() . ( '' !== $query ? '?' . self::decode_waf_source( $query ) : '' ) );
		foreach ( $keywords as $kw ) {
			if ( ! is_scalar( $kw ) ) {
				continue;
			}
			$needle = strtolower( trim( rawurldecode( (string) $kw ) ) );
			if ( '' !== $needle && false !== strpos( $hay, $needle ) ) {
				return (string) $kw;
			}
		}
		return '';
	}

	// -----------------------------------------------------------------------
	// Flood 404
	// -----------------------------------------------------------------------

	public function check_flood_404() {
		if ( ! self::firewall_active() || ! CU_Settings::get( 'firewall', 'flood_404_enabled', true ) ) {
			return;
		}
		if ( ! is_404() ) {
			return;
		}
		// Mêmes exemptions que inspect_request() — surtout le crawl SEO signé :
		// la vérification des liens internes cassés enchaîne des 404 depuis l'IP
		// du serveur. Les éditeurs connectés ne sont jamais concernés.
		if ( self::is_request_exempt() ) {
			return;
		}
		$user = self::user_state();
		if ( $user['editor'] ) {
			return;
		}
		// Ressource statique manquante (image, CSS, police…) : avec les permaliens,
		// WordPress la traite en 404. Un thème qui référence 3 images absentes ferait
		// sinon bannir un lecteur au bout de 7 pages.
		if ( preg_match( '/\.(?:jpe?g|png|gif|webp|avif|svg|ico|bmp|css|js|mjs|map|woff2?|ttf|otf|eot|mp4|webm|mp3|ogg|wav|pdf)$/i', self::request_path() ) ) {
			return;
		}

		$ip = self::get_client_ip();
		if ( '' === $ip ) {
			return;
		}
		// Préchargeur de cache / proxy (IP du serveur ou non publique) et liens
		// morts suivis depuis un autre site : jamais comptés.
		if ( self::is_infra_ip( $ip ) || self::is_cross_site_request() ) {
			return;
		}
		if ( ! self::tables_available() ) {
			return;
		}
		if ( self::is_whitelisted( $ip ) || self::is_good_bot_request( $ip ) ) {
			return;
		}

		$max    = max( 1, (int) CU_Settings::get( 'firewall', 'flood_404_max', 20 ) );
		$key    = self::counter_key( '404', $ip );
		$state  = self::increment_fixed_window_counter( $key, self::counter_window( '404' ) );
		$count  = (int) $state['count'];

		// Une 404 isolée est normale (ancien lien, faute de frappe, ressource de
		// thème). Ne signaler l'IP qu'à l'approche réelle du seuil.
		$suspicious_at = min( $max, max( 3, (int) ceil( $max / 2 ) ) );
		if ( $count >= $suspicious_at ) {
			self::bump_soft_flag( $ip );
		}

		// Rafale de 404 : réponse 429 temporaire pour les pages INTROUVABLES
		// seulement, jusqu'à la fin de la fenêtre, sans bannissement. Les pages
		// existantes restent servies : un robot qui suit d'anciens liens (refonte,
		// migration) ralentit sans rien perdre, un scanner n'apprend rien. Les
		// vraies sondes d'attaque relèvent des règles « sondes » et WAF.
		if ( $count > $max ) {
			self::log_event( $ip, 'blocked', 'flood_404', 'medium', 429 );
			self::render_blocked_page( $ip, 429, max( 1, (int) $state['expires_at'] - time() ) );
		}
	}

	// -----------------------------------------------------------------------
	// Protection de la connexion (wp-login.php, XML-RPC, WooCommerce…)
	// -----------------------------------------------------------------------

	/**
	 * Compte chaque échec d'identifiants, Y COMPRIS les tentatives refusées
	 * pendant un verrou (`cu_login_blocked`) : un attaquant qui insiste voit son
	 * verrou prolongé. Le verrou ne concerne que l'authentification (le site
	 * reste consultable), commence court (réglage « verrouillage », 15 min par
	 * défaut) et double à chaque récidive, plafonné à 24 h.
	 */
	public function on_login_failed( $username, $error = null ) {
		if ( ! self::firewall_active() || ! CU_Settings::get( 'firewall', 'login_protection_enabled', true ) ) {
			return;
		}
		// Une extension 2FA/SSO peut utiliser WP_Error pour demander un code après
		// un mot de passe déjà accepté : ses propres codes ne sont jamais comptés.
		if ( $error instanceof WP_Error && ! self::is_login_credential_error( $error ) && ! in_array( 'cu_login_blocked', $error->get_error_codes(), true ) ) {
			return;
		}
		if ( ! self::tables_available() ) {
			return;
		}

		$ip = self::get_client_ip();
		if ( '' === $ip || self::is_whitelisted( $ip ) ) {
			return;
		}
		// Adresse du proxy / du serveur : verrouiller bloquerait la connexion de
		// TOUS les utilisateurs. On le signale au lieu de verrouiller.
		if ( self::is_infra_ip( $ip ) ) {
			self::warn_infra_ip_once( $ip );
			return;
		}

		$max   = max( 1, (int) CU_Settings::get( 'firewall', 'login_max_attempts', 5 ) );
		$lock  = max( 1, (int) CU_Settings::get( 'firewall', 'login_lock_minutes', 15 ) ) * MINUTE_IN_SECONDS;
		$state = self::increment_fixed_window_counter( self::counter_key( 'login', $ip ), self::counter_window( 'login' ) );
		$count = (int) $state['count'];

		// Tentative échouée → IP suspecte (défi JS en portée ciblée).
		self::bump_soft_flag( $ip );

		// Le KPI « Tentatives login » compte chaque authentification échouée.
		self::log_request( $ip, 'observed', 'login_protection', 'medium' );

		// Verrou au seuil, puis prolongation à chaque nouvelle série d'échecs.
		if ( $count >= $max && 0 === ( $count - $max ) % $max ) {
			self::lock_login( $ip, $count, $lock );
		}

		if ( self::should_log( $ip, 'login_failed_detail' ) ) {
			CU_Logger::log( 'firewall', 'warning', 'Tentative de connexion échouée', array(
				'ip'       => $ip,
				// Un identifiant inexistant est souvent un mot de passe tapé dans le
				// mauvais champ : il n'est jamais journalisé en clair.
				'username' => username_exists( (string) $username ) ? sanitize_user( (string) $username ) : '[identifiant inconnu]',
				'count'    => $count,
			) );
		}
	}

	/** Pose (ou prolonge) un verrou de connexion, visible dans « Blocages actifs ». */
	private static function lock_login( $ip, $count, $base_seconds ) {
		$strike_key = self::counter_key( 'lstrike', $ip );
		$strikes    = max( 0, (int) get_transient( $strike_key ) );
		$cap        = max( (int) $base_seconds, DAY_IN_SECONDS );
		$seconds    = (int) min( $cap, $base_seconds * pow( 2, min( $strikes, 16 ) ) );
		set_transient( $strike_key, $strikes + 1, DAY_IN_SECONDS );

		$reason = sprintf( 'Trop de tentatives de connexion (%1$d) — connexion suspendue %2$d min', $count, (int) ceil( $seconds / 60 ) );
		$ok     = self::persist_block( $ip, 'login', $reason, CU_DB::date_from_now( 'Y-m-d H:i:s', $seconds ), array(
			'never_shorten' => true,
			'country'       => CU_Geo::country_for_ip( $ip ),
		) );
		self::log_event( $ip, 'blocked', 'login_protection', 'high', 403, $reason, true );
		if ( ! $ok ) {
			self::log_db_failure_once( 'login_lock_write' );
		}
	}

	/** Connexion suspendue : compteur d'échecs au seuil ou blocage actif (tout type). */
	private static function is_login_locked( $ip ) {
		$max = max( 1, (int) CU_Settings::get( 'firewall', 'login_max_attempts', 5 ) );
		if ( ! self::is_infra_ip( $ip ) && self::fixed_window_counter_value( self::counter_key( 'login', $ip ), self::counter_window( 'login' ) ) >= $max ) {
			return true;
		}
		return null !== self::find_block( $ip, true );
	}

	/**
	 * Pendant un verrou ou un blocage, TOUTE authentification est refusée,
	 * administrateurs compris (des identifiants valides ne doivent pas permettre
	 * de contourner un verrou obtenu par force brute). Récupération : whitelist,
	 * expiration du verrou (courte par défaut), ou constante CU_FIREWALL_DISABLED.
	 */
	public function maybe_block_login( $user, $username, $password ) {
		if ( ! self::firewall_active() || ! CU_Settings::get( 'firewall', 'login_protection_enabled', true ) ) {
			return $user;
		}
		// Ne jamais remplacer l'erreur d'une extension 2FA, SSO ou passwordless.
		if ( $user instanceof WP_Error && ! self::is_login_credential_error( $user ) ) {
			return $user;
		}
		if ( ! $user instanceof WP_User && '' === trim( (string) $username ) ) {
			return $user;
		}
		if ( ! self::tables_available() ) {
			return $user;
		}
		$ip = self::get_client_ip();
		if ( '' === $ip || self::is_whitelisted( $ip ) ) {
			return $user;
		}
		if ( self::is_login_locked( $ip ) ) {
			return new WP_Error( 'cu_login_blocked', __( 'Trop de tentatives de connexion depuis votre adresse. Pour votre sécurité, la connexion est temporairement suspendue : réessayez dans quelques minutes.', 'cyberpush-ultra' ) );
		}
		return $user;
	}

	/**
	 * Reconnaît uniquement les échecs d'identifiants produits par WordPress.
	 * Les codes propres aux extensions (2FA, SSO, CAPTCHA…) restent intacts.
	 */
	private static function is_login_credential_error( $error ) {
		if ( ! $error instanceof WP_Error ) {
			return false;
		}
		$credential_codes = array(
			'invalid_username',
			'invalid_email',
			'incorrect_password',
			'empty_username',
			'empty_password',
			'authentication_failed',
		);
		return (bool) array_intersect( $credential_codes, $error->get_error_codes() );
	}

	// -----------------------------------------------------------------------
	// Protection XML-RPC
	// -----------------------------------------------------------------------

	public function filter_xmlrpc( $enabled ) {
		if ( ! self::firewall_active() ) {
			return $enabled;
		}
		$mode = CU_Settings::get( 'firewall', 'xmlrpc_protection', 'limited' );
		if ( 'disabled' === $mode ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * `xmlrpc_enabled` ne coupe que les méthodes authentifiées dans WordPress.
	 * Vider la table des méthodes garantit que le mode « désactivé » affiché par
	 * l'interface couvre aussi pingbacks et méthodes publiques.
	 */
	public function filter_xmlrpc_methods( $methods ) {
		if ( self::firewall_active() && 'disabled' === CU_Settings::get( 'firewall', 'xmlrpc_protection', 'limited' ) ) {
			return array();
		}
		return $methods;
	}

	/**
	 * Contrôle la requête XML-RPC avant son dispatch (init:0, AVANT l'inspection
	 * générale). La blocklist est vérifiée en premier : une règle XML-RPC ne doit
	 * jamais réécrire un bannissement existant plus long (ni un ban permanent).
	 */
	public function check_xmlrpc_request() {
		if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST || ! self::firewall_active() ) {
			return;
		}
		$mode        = CU_Settings::get( 'firewall', 'xmlrpc_protection', 'limited' );
		$ip          = self::get_client_ip();
		$tables      = '' !== $ip && self::tables_available();
		$whitelisted = $tables && self::is_whitelisted( $ip );

		if ( $tables && ! $whitelisted && ! self::is_request_exempt() ) {
			$block = self::find_block( $ip );
			if ( $block && 'login' !== $block['type'] ) {
				self::track_block_hit( $block['ip_or_cidr'] );
				self::render_blocked_page( $ip );
			}
		}

		if ( 'disabled' === $mode ) {
			if ( $tables ) {
				self::log_event( $ip, 'blocked', 'xmlrpc_protection', 'medium' );
			}
			self::render_blocked_page( $ip );
		}
		if ( 'limited' !== $mode || ! $tables || $whitelisted ) {
			return;
		}

		// Lecture bornée (1 Mio) : le plafond d'appels internes doit voir tout le
		// multicall, pas seulement ses premiers 64 Kio.
		$payload = file_get_contents( 'php://input', false, null, 0, MB_IN_BYTES );
		if ( ! is_string( $payload ) || ! preg_match( '/<methodName>\s{0,64}system\.multicall\s{0,64}<\/methodName>/i', $payload ) ) {
			return;
		}

		// Borner aussi le nombre d'opérations contenues dans UN multicall.
		$inner_calls = preg_match_all( '/<name>\s{0,64}methodName\s{0,64}<\/name>/i', $payload, $unused );
		if ( false !== $inner_calls && $inner_calls > 25 && self::block_ip( $ip, 'XML-RPC multicall surdimensionné (' . $inner_calls . ' appels)', 'xmlrpc_protection', 'high' ) ) {
			self::render_blocked_page( $ip );
		}

		$state = self::increment_fixed_window_counter( self::counter_key( 'xmlrpc', $ip ), self::counter_window( 'xmlrpc' ) );
		if ( (int) $state['count'] > 5 && self::block_ip( $ip, 'XML-RPC multicall suspect (' . (int) $state['count'] . '/5min)', 'xmlrpc_protection', 'high' ) ) {
			self::render_blocked_page( $ip );
		}
	}

	// -----------------------------------------------------------------------
	// Protection REST API (routes bloquées pour les visiteurs anonymes)
	// -----------------------------------------------------------------------

	/**
	 * Accroché APRÈS les contrôles du cœur (priorité 110) : à ce stade, un cookie
	 * sans nonce valide a déjà été ramené à « anonyme » par WordPress, et une
	 * authentification par mot de passe d'application est établie. La route
	 * comparée est celle que WordPress va réellement servir (décodée, y compris
	 * via `?rest_route=`), ce qui ferme les contournements par encodage.
	 */
	public function filter_rest_api( $errors ) {
		if ( is_wp_error( $errors ) ) {
			return $errors;
		}
		if ( ! self::firewall_active() || ! CU_Settings::get( 'firewall', 'restapi_protection_enabled', true ) ) {
			return $errors;
		}
		$blocked_routes = (array) CU_Settings::get( 'firewall', 'restapi_blocked_routes', array() );
		if ( empty( $blocked_routes ) || is_user_logged_in() ) {
			return $errors;
		}
		if ( ! self::tables_available() ) {
			return $errors;
		}
		$ip = self::get_client_ip();
		if ( '' === $ip || self::is_whitelisted( $ip ) || self::is_good_bot_request( $ip ) ) {
			return $errors;
		}

		$route = self::dispatched_rest_route();
		foreach ( $blocked_routes as $blocked ) {
			$blocked = self::normalize_rest_route_setting( $blocked );
			if ( '' !== $blocked && ( $route === $blocked || 0 === strpos( $route, $blocked . '/' ) ) ) {
				self::log_event( $ip, 'blocked', 'restapi_protection', 'medium', 403, 'Route REST API bloquée : ' . $route );
				return new WP_Error( 'cu_rest_blocked', __( 'Route REST API bloquée.', 'cyberpush-ultra' ), array( 'status' => 403 ) );
			}
		}

		return $errors;
	}

	/** Route REST effectivement dispatchée par WordPress, normalisée (minuscules). */
	private static function dispatched_rest_route() {
		$route = '';
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) && is_scalar( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		}
		if ( '' === $route ) {
			return self::current_rest_route();
		}
		return strtolower( untrailingslashit( self::normalize_path( $route ) ) );
	}

	/** Entrée de réglage « /wp-json/wp/v2/users » ou « wp/v2/users » → « /wp/v2/users ». */
	private static function normalize_rest_route_setting( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$route  = strtolower( untrailingslashit( self::normalize_path( trim( (string) $value ) ) ) );
		$prefix = '/' . strtolower( trim( function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json', '/' ) );
		if ( $route === $prefix ) {
			return '';
		}
		if ( 0 === strpos( $route, $prefix . '/' ) ) {
			$route = substr( $route, strlen( $prefix ) );
		}
		return '/' === $route ? '' : $route;
	}

	// -----------------------------------------------------------------------
	// Protection Upload
	// -----------------------------------------------------------------------

	public function filter_upload( $file ) {
		if ( ! self::firewall_active() || ! CU_Settings::get( 'firewall', 'upload_protection_enabled', true ) ) {
			return $file;
		}

		$blocked_ext = CU_Settings::get( 'firewall', 'upload_blocked_extensions', array( 'php', 'phtml', 'php5', 'exe', 'sh' ) );
		$filename    = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$ext         = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		$blocked_ext_clean = array();
		foreach ( (array) $blocked_ext as $blocked_item ) {
			if ( is_scalar( $blocked_item ) ) {
				$blocked_ext_clean[] = strtolower( trim( (string) $blocked_item ) );
			}
		}
		if ( in_array( $ext, $blocked_ext_clean, true ) ) {
			$file['error'] = sprintf(
				/* translators: %s: file extension */
				__( 'Extension de fichier non autorisée : .%s', 'cyberpush-ultra' ),
				esc_html( $ext )
			);
			$ip = self::get_client_ip();
			if ( $ip && self::tables_available() ) {
				self::log_request( $ip, 'blocked', 'upload_protection', 'high' );
				CU_Logger::log( 'firewall', 'warning', 'Upload bloqué (extension)', array( 'ip' => $ip, 'file' => $filename ) );
			}
			return $file;
		}

		// Vérification MIME réel vs extension déclarée.
		if ( ! empty( $file['tmp_name'] ) && function_exists( 'finfo_open' ) ) {
			$finfo    = finfo_open( FILEINFO_MIME_TYPE );
			$mimetype = $finfo ? finfo_file( $finfo, $file['tmp_name'] ) : '';
			// Libère la ressource des anciennes versions de PHP ou l'objet des
			// versions récentes sans appeler finfo_close(), déprécié depuis PHP 8.5.
			unset( $finfo );

			// Liste de MIME types toujours suspects quelle que soit l'extension.
			$dangerous_mimes = array(
				'application/x-php',
				'application/x-httpd-php',
				'text/x-php',
				'application/x-sh',
				'application/x-msdos-program',
				'application/x-msdownload',
			);
			if ( in_array( strtolower( $mimetype ), $dangerous_mimes, true ) ) {
				$file['error'] = __( 'Type de fichier dangereux détecté.', 'cyberpush-ultra' );
				$ip = self::get_client_ip();
				if ( $ip && self::tables_available() ) {
					self::log_request( $ip, 'blocked', 'upload_protection', 'critical' );
					CU_Logger::log( 'firewall', 'warning', 'Upload bloqué (MIME dangereux)', array( 'ip' => $ip, 'mime' => $mimetype ) );
				}
			}
		}

		return $file;
	}

	// -----------------------------------------------------------------------
	// Page 403
	// -----------------------------------------------------------------------

	/**
	 * Affiche une page de refus stylée (sans dépendance aux assets admin) et exit.
	 * Le message n'expose jamais la règle déclenchée ni aucun détail interne.
	 *
	 * @param string $ip          IP à afficher (facultatif).
	 * @param int    $status      403 (refus, défaut), 429 (limite temporaire) ou
	 *                            503 (maintenance). 503 + Retry-After est CRUCIAL
	 *                            pour le SEO : Google ne déréférence pas sur un 503
	 *                            temporaire, contrairement à un 403 prolongé.
	 * @param int    $retry_after Secondes avant un nouvel essai (429).
	 */
	public static function render_blocked_page( $ip = '', $status = 403, $retry_after = 0 ) {
		$status = in_array( (int) $status, array( 403, 429, 503 ), true ) ? (int) $status : 403;
		// Point d'extension (et d'observation pour les tests) avant la réponse finale.
		do_action( 'cu_firewall_request_refused', $status, (string) $ip );
		$is_maintenance = ( 503 === $status );
		$is_limited     = ( 429 === $status );
		if ( ! headers_sent() ) {
			status_header( $status );
			nocache_headers();
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
			if ( $is_maintenance ) {
				header( 'Retry-After: 3600' ); // réessayez dans 1 h (crawlers inclus).
			} elseif ( $is_limited ) {
				header( 'Retry-After: ' . max( 1, min( DAY_IN_SECONDS, (int) $retry_after ) ) );
			}
			// Empêche toute indexation de la page de blocage/maintenance.
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Content-Type: text/html; charset=UTF-8' );
		}
		if ( $is_maintenance ) {
			$args = array(
				'variant' => 'maintenance',
				'title'   => __( 'Maintenance en cours', 'cyberpush-ultra' ),
				'heading' => __( 'Maintenance en cours', 'cyberpush-ultra' ),
				'message' => __( 'Le site est momentanément en maintenance. Merci de réessayer dans quelques instants.', 'cyberpush-ultra' ),
			);
		} elseif ( $is_limited ) {
			$args = array(
				'variant' => 'maintenance',
				'title'   => __( 'Trop de requêtes', 'cyberpush-ultra' ),
				'heading' => __( 'Merci de patienter un instant', 'cyberpush-ultra' ),
				'message' => __( 'Trop de requêtes ont été envoyées depuis votre connexion en peu de temps. Patientez quelques minutes, puis réessayez.', 'cyberpush-ultra' ),
			);
		} else {
			$args = array(
				'variant' => 'blocked',
				'title'   => __( 'Accès bloqué', 'cyberpush-ultra' ),
				'heading' => __( 'Accès bloqué', 'cyberpush-ultra' ),
				'message' => __( 'Ce site a refusé cette requête par mesure de sécurité. Réessayez plus tard. Si le problème persiste, contactez le responsable du site en lui indiquant les informations ci-dessous : elles lui permettent de retrouver l’événement dans son journal de sécurité.', 'cyberpush-ultra' ),
				'details' => true,
				'ip'      => $ip,
			);
		}
		echo self::public_page_html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans public_page_html().
		exit;
	}

	/**
	 * Page publique du pare-feu (blocage, maintenance, défi navigateur), thème
	 * clair « Lumière » autonome : styles en ligne, aucune ressource externe,
	 * nom, icône et langue du site. Toutes les valeurs dynamiques sont échappées.
	 *
	 * @param array $args variant (blocked|maintenance|challenge|js), title, heading,
	 *                    message, details (bool), ip, extra (HTML de confiance), script.
	 * @return string
	 */
	private static function public_page_html( array $args ) {
		$args = array_merge(
			array( 'variant' => 'blocked', 'title' => '', 'heading' => '', 'message' => '', 'details' => false, 'ip' => '', 'extra' => '', 'script' => '' ),
			$args
		);

		$lang      = get_bloginfo( 'language' );
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$site_icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 64 ) : '';
		$initial   = '' !== $site_name ? CU_Text::substr( $site_name, 0, 1 ) : 'W';

		$icons = array(
			'blocked'     => array( '#fff1f0', '#c41d1d', '<path d="M12 3l8 3v6c0 4.97-3.5 8.5-8 10-4.5-1.5-8-5.03-8-10V6l8-3z"/><path d="m4.9 4.9 14.2 14.2"/>' ),
			'maintenance' => array( '#fff6df', '#8a5a00', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>' ),
			'js'          => array( '#e6f8fb', '#0a6f7d', '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7.5v.5"/>' ),
		);
		if ( 'challenge' === $args['variant'] ) {
			$visual = '<div class="sp" role="progressbar" aria-label="' . esc_attr__( 'Vérification en cours', 'cyberpush-ultra' ) . '"></div>';
		} else {
			$icon   = isset( $icons[ $args['variant'] ] ) ? $icons[ $args['variant'] ] : $icons['blocked'];
			$visual = '<div class="ico" style="background:' . $icon[0] . ';color:' . $icon[1] . '"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon[2] . '</svg></div>';
		}

		$details = '';
		if ( $args['details'] ) {
			$details = '<dl class="info"><div><dt>' . esc_html__( 'Date', 'cyberpush-ultra' ) . '</dt><dd>' . esc_html( wp_date( get_option( 'date_format', 'd/m/Y' ) . ' ' . get_option( 'time_format', 'H:i' ) ) ) . '</dd></div>';
			if ( '' !== (string) $args['ip'] ) {
				$details .= '<div><dt>' . esc_html__( 'Votre adresse IP', 'cyberpush-ultra' ) . '</dt><dd>' . esc_html( $args['ip'] ) . '</dd></div>';
			}
			$details .= '</dl>';
		}

		$brand = $site_icon
			? '<img src="' . esc_url( $site_icon ) . '" alt="" width="28" height="28">'
			: '<i aria-hidden="true">' . esc_html( $initial ) . '</i>';

		return '<!DOCTYPE html><html lang="' . esc_attr( $lang ? $lang : 'fr' ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . esc_html( $args['title'] . ( '' !== $site_name ? ' · ' . $site_name : '' ) ) . '</title><style>'
			. '*{box-sizing:border-box;margin:0;padding:0}html{color-scheme:light}'
			. 'body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#f3f6fd;color:#141a33;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;-webkit-font-smoothing:antialiased;overflow-x:hidden}'
			. 'body::before{content:"";position:fixed;left:-10%;right:-10%;top:-20%;height:75vh;pointer-events:none;background:radial-gradient(40% 50% at 20% 40%,rgba(56,88,233,.18),rgba(56,88,233,0) 70%),radial-gradient(36% 46% at 55% 30%,rgba(15,181,201,.15),rgba(15,181,201,0) 70%),radial-gradient(34% 44% at 85% 45%,rgba(52,211,153,.14),rgba(52,211,153,0) 70%)}'
			. '.card{position:relative;width:100%;max-width:470px;background:#fff;border:1px solid #e2e7f5;border-radius:24px;padding:34px 32px 24px;box-shadow:0 1px 2px rgba(20,26,51,.04),0 30px 70px -30px rgba(35,55,200,.35)}'
			. '.site{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:600;color:#4a5272;margin-bottom:28px;min-width:0}'
			. '.site span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
			. '.site img,.site i{width:28px;height:28px;border-radius:8px;flex:none}'
			. '.site i{display:grid;place-items:center;font-style:normal;font-weight:700;color:#fff;text-transform:uppercase;background:linear-gradient(135deg,#3858e9,#2337c8)}'
			. '.ico{width:56px;height:56px;border-radius:16px;display:grid;place-items:center;margin-bottom:18px}'
			. '.sp{width:56px;height:56px;margin-bottom:18px;border-radius:50%;border:4px solid #e3e9fb;border-top-color:#3858e9;border-right-color:#0fb5c9;animation:s .9s linear infinite}@keyframes s{to{transform:rotate(360deg)}}'
			. 'h1{font-size:23px;line-height:1.25;font-weight:650;letter-spacing:-.02em;margin-bottom:10px}'
			. 'p{color:#4a5272}'
			. '.info{margin-top:22px;border-radius:14px;background:#f6f8fe;border:1px solid #e9edf8;padding:14px 16px;display:grid;gap:10px}'
			. '.info div{display:flex;justify-content:space-between;align-items:baseline;gap:12px;font-size:13.5px}'
			. '.info dt{color:#5f6788}.info dd{font-weight:600;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;word-break:break-all;text-align:right}'
			. '.ns{display:block;margin-top:16px;padding:12px 14px;border-radius:12px;background:#fff6df;color:#8a5a00;font-size:14px}'
			. '.foot{margin-top:24px;padding-top:16px;border-top:1px solid #edf0f8;font-size:12.5px;color:#5f6788;display:flex;align-items:center;gap:8px}'
			. '@media (max-width:480px){.card{padding:28px 22px 20px}h1{font-size:21px}}'
			. '@media (prefers-reduced-motion:reduce){.sp{animation-duration:2.4s}}'
			. '</style></head><body><main class="card">'
			. '<div class="site">' . $brand . '<span>' . esc_html( '' !== $site_name ? $site_name : wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</span></div>'
			. $visual
			. '<h1>' . esc_html( $args['heading'] ) . '</h1>'
			. '<p>' . esc_html( $args['message'] ) . '</p>'
			. $details
			. $args['extra']
			. '<div class="foot"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6l7-3z"/></svg>' . esc_html__( 'Protégé par CyberPush Ultra', 'cyberpush-ultra' ) . '</div>'
			. '</main>' . $args['script'] . '</body></html>';
	}

	// -----------------------------------------------------------------------
	// Maintenance (cron)
	// -----------------------------------------------------------------------

	/**
	 * Nettoie les blocages expirés.
	 * Appelé par cu_cron_firewall_maintenance (CU_Cron).
	 */
	public static function run_maintenance() {
		if ( ! self::tables_available() ) {
			return;
		}
		global $wpdb;
		$table  = CU_DB::table( 'firewall_blocks' );
		$result = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s",
			current_time( 'mysql' )
		) );
		if ( $result > 0 ) {
			self::flush_list_cache();
			CU_Logger::log( 'firewall', 'info', sprintf(
				/* translators: %d: nombre de blocages supprimés */
				__( 'Maintenance pare-feu : %d blocage(s) expiré(s) supprimé(s)', 'cyberpush-ultra' ),
				$result
			) );
		} elseif ( false === $result ) {
			self::log_db_failure_once( 'maintenance_blocks' );
		}

		// Whitelist : les exemptions temporaires (ex. « IP de récupération », 7 j)
		// expirées sont retirées. Elles sont déjà ignorées dès leur échéance.
		$wl_table = CU_DB::table( 'firewall_whitelist' );
		$wl_purge = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wl_table} WHERE expires_at IS NOT NULL AND expires_at < %s",
			current_time( 'mysql' )
		) );
		if ( $wl_purge > 0 ) {
			self::flush_list_cache();
		} elseif ( false === $wl_purge ) {
			self::log_db_failure_once( 'maintenance_whitelist' );
		}

		// Adresses du serveur lui-même (jamais bannies) : résolution DNS faite ici,
		// en tâche de fond, jamais pendant une requête de visiteur.
		self::refresh_server_ips();

		// Radar : purge des observations obsolètes (> 30 jours) pour garder le filet léger.
		$watch = CU_DB::table( 'firewall_watch' );
		$watch_purge = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$watch} WHERE last_seen < %s",
			CU_DB::date_from_now( 'Y-m-d H:i:s', -30 * DAY_IN_SECONDS )
		) );
		if ( false === $watch_purge ) {
			self::log_db_failure_once( 'maintenance_radar' );
		}
		// Les anciennes versions pouvaient marquer un timeout d'enrichissement
		// comme terminé. Remettre uniquement ces lignes manifestement vides en file.
		$watch_retry = $wpdb->query(
			"UPDATE {$watch} SET enriched = 0 WHERE enriched = 1 AND COALESCE(org, '') = '' AND COALESCE(asn, '') = ''"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $watch_retry ) {
			self::log_db_failure_once( 'maintenance_radar_retry' );
		}

		// Radar : auto-blocage intelligent des suspects à haut risque (opt-in).
		self::radar_autoblock_sweep();
	}

	/**
	 * Promeut automatiquement les suspects du Radar (datacenter/proxy + score
	 * élevé) vers les blocages. DÉSACTIVÉ par défaut : le Radar reste un filet
	 * passif tant que l'admin n'active pas la règle. Tourne dans le cron (aucune
	 * latence sur les requêtes) et n'utilise que des données déjà ENRICHIES.
	 * Garde-fous : whitelist épargnée, crawlers vérifiés jamais touchés (ils ne
	 * sont pas dans le filet), IP déjà bloquées ignorées.
	 */
	private static function radar_autoblock_sweep() {
		if ( ! self::tables_available() ) {
			return;
		}
		if ( ! self::firewall_active() ) {
			return;
		}
		if ( ! CU_Settings::get( 'firewall', 'radar_autoblock_enabled', false ) ) {
			return;
		}
		global $wpdb;
		$watch     = CU_DB::table( 'firewall_watch' );
		$min_score = (int) CU_Settings::get( 'firewall', 'radar_autoblock_min_score', 90 );
		$min_hits  = max( 2, (int) CU_Settings::get( 'firewall', 'radar_autoblock_min_hits', 3 ) );
		$mode      = ( 'cidr' === CU_Settings::get( 'firewall', 'radar_autoblock_mode', 'ip' ) ) ? 'cidr' : 'ip';
		$duration  = self::radar_duration( CU_Settings::get( 'firewall', 'radar_autoblock_duration', '7d' ) );

		// 1) Enrichir un petit lot en attente (autonome, même sans ouvrir la page).
		//    Uniquement si l'administrateur a autorisé un fournisseur externe :
		//    sinon aucune IP de visiteur ne quitte le serveur.
		if ( CU_Geo::remote_lookups_allowed() ) {
			$pending = $wpdb->get_results( "SELECT id, ip FROM {$watch} WHERE enriched = 0 AND status = 'observed' ORDER BY score DESC, last_seen DESC LIMIT 8", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $wpdb->last_error || ! is_array( $pending ) ) {
				self::log_db_failure_once( 'radar_pending_read' );
				return;
			}
			foreach ( (array) $pending as $row ) {
				$e = CU_Geo::enrich( $row['ip'] );
				if ( empty( $e['source'] ) ) {
					continue; // panne temporaire : conserver enriched=0 pour un futur essai.
				}
				if ( false === self::radar_apply_enrichment( (int) $row['id'], $e ) ) {
					self::log_db_failure_once( 'radar_enrichment_write' );
				}
			}
		}

		// 2) Candidats : enrichis, datacenter OU proxy, score suffisant, non ignorés.
		// Exiger plusieurs observations empêche qu'une 404 isolée depuis un VPN
		// ou un datacenter soit transformée en bannissement automatique. Les vraies
		// limites (flood 404, rate-limit, WAF) restent appliquées en temps réel par
		// leurs modules respectifs et ne dépendent pas de ce garde-fou Radar.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ip, country_code, org, score, hits, ua_sample FROM {$watch}
			 WHERE status = 'observed' AND enriched = 1 AND score >= %d AND hits >= %d
			   AND ( is_datacenter = 1 OR is_proxy = 1 )
			 ORDER BY score DESC LIMIT 100",
			$min_score,
			$min_hits
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			self::log_db_failure_once( 'radar_candidates_read' );
			return;
		}

		$blocked = 0;
		foreach ( (array) $rows as $row ) {
			$ip = $row['ip'];
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			// Filet de sécurité : un crawler de moteur VÉRIFIÉ n'est jamais auto-bloqué.
			$ua_row = isset( $row['ua_sample'] ) ? (string) $row['ua_sample'] : '';
			if ( '' !== $ua_row && self::looks_like_good_bot_ua( $ua_row ) && self::is_verified_good_bot( $ip, $ua_row ) ) {
				$wpdb->update( $watch, array( 'status' => 'ignored' ), array( 'ip' => $ip ) );
				continue;
			}
			$row_country = isset( $row['country_code'] ) ? strtoupper( (string) $row['country_code'] ) : '';
			if ( in_array( $row_country, self::trusted_country_codes(), true ) || self::is_trusted_country_ip( $ip ) ) {
				$wpdb->update( $watch, array( 'status' => 'ignored' ), array( 'ip' => $ip ) );
				continue;
			}
			if ( self::is_whitelisted( $ip ) || self::is_blocked( $ip, false ) ) {
				// Déjà couvert / de confiance → on le sort juste du filet.
				$wpdb->update( $watch, array( 'status' => 'blocked' ), array( 'ip' => $ip ) );
				continue;
			}
			$target = ( 'cidr' === $mode && false === strpos( $ip, ':' ) ) ? self::ipv4_to_cidr24( $ip ) : $ip;
			$reason = sprintf(
				/* translators: 1: score, 2: observations, 3: organisation */
				__( 'Radar — auto-bloqué (score %1$d, %2$d observations, %3$s)', 'cyberpush-ultra' ),
				(int) $row['score'],
				(int) $row['hits'],
				$row['org'] ? $row['org'] : __( 'datacenter/proxy', 'cyberpush-ultra' )
			);
			if ( self::radar_insert_block( $target, $reason, $duration, 'auto' ) ) {
				$wpdb->update( $watch, array( 'status' => 'blocked' ), array( 'ip' => $ip ) );
				$blocked++;
			}
		}

		if ( $blocked > 0 ) {
			CU_Logger::log( 'firewall', 'warning', sprintf(
				/* translators: %d: nombre d'IP auto-bloquées */
				__( 'Radar : %d suspect(s) auto-bloqué(s) (haut risque)', 'cyberpush-ultra' ),
				$blocked
			) );
		}
	}

	// -----------------------------------------------------------------------
	// Routes REST admin
	// -----------------------------------------------------------------------

	public function register_routes() {
		$ns = CU_REST_NAMESPACE;

		// Statut général.
		register_rest_route( $ns, '/firewall/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_status' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Toggle actif/inactif.
		register_rest_route( $ns, '/firewall/toggle', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_toggle' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Tableau de bord.
		register_rest_route( $ns, '/firewall/dashboard', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_dashboard' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Graphique temporel.
		register_rest_route( $ns, '/firewall/attacks-timeseries', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_attacks_timeseries' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Détail d'une journée du graphique (infobulle, chargé au survol).
		register_rest_route( $ns, '/firewall/attacks-day', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_attacks_day' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Blocages : liste, création, suppression, permanence, whitelist.
		register_rest_route( $ns, '/firewall/blocks', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_blocks_list' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_blocks_create' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
		) );
		register_rest_route( $ns, '/firewall/blocks/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'rest_blocks_delete' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );
		register_rest_route( $ns, '/firewall/blocks/(?P<id>\d+)/permanent', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_blocks_make_permanent' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );
		register_rest_route( $ns, '/firewall/blocks/(?P<id>\d+)/whitelist', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_blocks_to_whitelist' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Whitelist.
		register_rest_route( $ns, '/firewall/whitelist', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_whitelist_list' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_whitelist_create' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
		) );
		register_rest_route( $ns, '/firewall/whitelist/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'rest_whitelist_delete' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Pays de confiance.
		register_rest_route( $ns, '/firewall/trusted-countries', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_trusted_countries_get' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_trusted_countries_update' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
		) );

		// Top attaquants.
		register_rest_route( $ns, '/firewall/top-attackers', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_top_attackers' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Logs.
		register_rest_route( $ns, '/firewall/logs', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_logs_list' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );
		register_rest_route( $ns, '/firewall/logs/purge', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_logs_purge' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Statistiques globales.
		register_rest_route( $ns, '/firewall/global-stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_global_stats' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );

		// Radar — surveillance passive (le « filet »).
		register_rest_route( $ns, '/firewall/radar/list', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_radar_list' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );
		register_rest_route( $ns, '/firewall/radar/block-bulk', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_radar_block_bulk' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );
		register_rest_route( $ns, '/firewall/radar/ignore', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_radar_ignore' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );
		register_rest_route( $ns, '/firewall/radar/purge', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_radar_purge' ),
			'permission_callback' => array( $this, 'rest_permission' ),
		) );
	}

	// -----------------------------------------------------------------------
	// Permission callback commun
	// -----------------------------------------------------------------------

	public function rest_permission() {
		return current_user_can( 'manage_options' );
	}

	// -----------------------------------------------------------------------
	// Callbacks REST
	// -----------------------------------------------------------------------

	public function rest_status( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$logs_table   = CU_DB::table( 'firewall_logs' );
		$blocks_table = CU_DB::table( 'firewall_blocks' );
		$wl_table     = CU_DB::table( 'firewall_whitelist' );

		$fw_settings = CU_Settings::module( 'firewall' );

		// Dernière attaque.
		$last_attack = $wpdb->get_row(
			"SELECT ip, country_code, rule, created_at FROM {$logs_table} WHERE decision = 'blocked' ORDER BY created_at DESC LIMIT 1",
			ARRAY_A
		);

		$last_attack_data = null;
		if ( $last_attack ) {
			// created_at est en heure locale WordPress (current_time('mysql')) :
			// le convertir en UTC avant de le comparer à time().
			$attack_ts = strtotime( get_gmt_from_date( $last_attack['created_at'] ) . ' UTC' );
			$elapsed   = $attack_ts ? max( 0, time() - $attack_ts ) : 0;
			$last_attack_data = array(
				'ip'      => $last_attack['ip'],
				'country' => $last_attack['country_code'],
				'type'    => $last_attack['rule'],
				'date'    => $last_attack['created_at'],
				'elapsed' => $elapsed,
			);
		}

		// Modules et leurs compteurs.
		$modules_info = $this->get_modules_status( $fw_settings );

		return rest_ensure_response( array(
			'enabled'          => (bool) $fw_settings['enabled'],
			'mode'             => $fw_settings['mode'],
			'last_attack'      => $last_attack_data,
			'modules'          => $modules_info,
			'trusted_countries' => $fw_settings['trusted_countries'],
			'whitelist_count'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wl_table} WHERE expires_at IS NULL OR expires_at > %s", current_time( 'mysql' ) ) ),
			'kill_switch'      => self::kill_switch(),
		) );
	}

	/** Période demandée par l'interface (« 24h », « 168 »…) bornée à 1 h – 90 jours. */
	private static function bounded_hours( $raw, $default = 24, $max = 2160 ) {
		$hours = is_scalar( $raw ) ? (int) $raw : 0;
		if ( $hours <= 0 ) {
			$hours = $default;
		}
		return max( 1, min( (int) $max, $hours ) );
	}

	/** Numéro de page REST borné (évite un OFFSET gigantesque). */
	private static function bounded_page( $raw ) {
		return max( 1, min( 10000, is_scalar( $raw ) ? (int) $raw : 1 ) );
	}

	public function rest_toggle( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		// L'interface envoie l'état VOULU : une simple inversion appliquerait
		// l'inverse de l'intention si l'écran était périmé (autre onglet, double clic).
		$wanted  = $request->get_param( 'enabled' );
		$current = (bool) CU_Settings::get( 'firewall', 'enabled', true );
		$new_val = null === $wanted ? ! $current : rest_sanitize_boolean( $wanted );
		if ( $new_val === $current ) {
			return rest_ensure_response( array( 'enabled' => $new_val ) );
		}
		CU_Settings::update_module( 'firewall', array( 'enabled' => $new_val ) );
		CU_Logger::log( 'firewall', 'info', $new_val
			? __( 'Pare-feu activé', 'cyberpush-ultra' )
			: __( 'Pare-feu désactivé', 'cyberpush-ultra' )
		);
		return rest_ensure_response( array( 'enabled' => $new_val ) );
	}

	public function rest_dashboard( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$hours        = self::bounded_hours( $request->get_param( 'range' ) );
		$since        = CU_DB::date_from_now( 'Y-m-d H:i:s', -$hours * HOUR_IN_SECONDS );
		$since_prev   = CU_DB::date_from_now( 'Y-m-d H:i:s', -2 * $hours * HOUR_IN_SECONDS );
		$logs_table   = CU_DB::table( 'firewall_logs' );
		$blocks_table = CU_DB::table( 'firewall_blocks' );

		$attacks_now  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE decision = 'blocked' AND created_at >= %s", $since ) );
		$attacks_prev = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE decision = 'blocked' AND created_at >= %s AND created_at < %s", $since_prev, $since ) );

		$active_blocks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$blocks_table} WHERE expires_at IS NULL OR expires_at > %s", current_time( 'mysql' ) ) );
		$permanent     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$blocks_table} WHERE expires_at IS NULL" );

		$by_rule = $wpdb->get_results( $wpdb->prepare(
			"SELECT rule, COUNT(*) as cnt FROM {$logs_table} WHERE decision = 'blocked' AND created_at >= %s GROUP BY rule",
			$since
		), ARRAY_A );
		$rule_map = array();
		foreach ( (array) $by_rule as $row ) {
			$rule_map[ $row['rule'] ] = (int) $row['cnt'];
		}

		$top_countries = $wpdb->get_results( $wpdb->prepare(
			"SELECT country_code, COUNT(*) as cnt FROM {$logs_table} WHERE decision = 'blocked' AND created_at >= %s AND country_code IS NOT NULL GROUP BY country_code ORDER BY cnt DESC LIMIT 10",
			$since
		), ARRAY_A );

		$unique_ips = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT ip) FROM {$logs_table} WHERE decision = 'blocked' AND created_at >= %s",
			$since
		) );

		// Crawlers de moteurs VÉRIFIÉS observés. L'écriture est limitée à une
		// ligne par IP et par heure afin de ne pas remplir le journal pendant un crawl.
		$goodbot_verified = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$logs_table} WHERE rule = 'goodbot_verified' AND decision = 'observed' AND created_at >= %s",
			$since
		) );

		// Sous-ensemble des crawlers qui auraient effectivement rencontré un blocage.
		$goodbot_spared = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$logs_table} WHERE rule = 'goodbot_spared' AND created_at >= %s",
			$since
		) );

		$login_attempts = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$logs_table} WHERE rule = 'login_protection' AND decision = 'observed' AND created_at >= %s",
			$since
		) );

		return rest_ensure_response( array(
			'active_blocks'          => $active_blocks,
			'permanent_blocks'       => $permanent,
			'attacks_24h'            => $attacks_now,
			'attacks_prev'           => $attacks_prev,
			'flood_404'              => $rule_map['flood_404'] ?? 0,
			'probe_detection'        => $rule_map['probe_detection'] ?? 0,
			'login_protection'       => $login_attempts,
			'xmlrpc_protection'      => $rule_map['xmlrpc_protection'] ?? 0,
			'scanner_detection'      => $rule_map['scanner_detection'] ?? 0,
			'bad_bot'                => $rule_map['bad_bot'] ?? 0,
			'rate_limit'             => $rule_map['rate_limit'] ?? 0,
			'upload_protection'      => $rule_map['upload_protection'] ?? 0,
			'top_countries'          => $top_countries,
			'unique_attacker_ips'    => $unique_ips,
			'goodbot_verified'       => $goodbot_verified,
			'goodbot_spared'         => $goodbot_spared,
		) );
	}

	/**
	 * Famille d'une règle pour le graphique : les règles WAF numérotées
	 * (waf_sqli_0, waf_sqli_3…) sont regroupées par type d'attaque.
	 */
	private static function rule_family( $rule ) {
		$rule = sanitize_key( (string) $rule );
		if ( '' === $rule ) {
			return 'other';
		}
		if ( 0 === strpos( $rule, 'waf_' ) ) {
			$parts = explode( '_', $rule );
			return 'waf_' . ( isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : 'other' );
		}
		return $rule;
	}

	/**
	 * Série « Évolution des attaques » : par jour, le total bloqué, les IP uniques
	 * et la répartition par famille de règle ; plus un résumé de la période et sa
	 * comparaison avec la période précédente. Quatre requêtes agrégées.
	 */
	public function rest_attacks_timeseries( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$days       = max( 1, min( 90, (int) ( $request->get_param( 'days' ) ?: 14 ) ) );
		$logs_table = CU_DB::table( 'firewall_logs' );
		$start      = CU_DB::date_from_now( 'Y-m-d', -( $days - 1 ) * DAY_IN_SECONDS ) . ' 00:00:00';
		$prev_start = CU_DB::date_from_now( 'Y-m-d', -( 2 * $days - 1 ) * DAY_IN_SECONDS ) . ' 00:00:00';

		/** @var array<int, array{date: string, total: int, unique_ips: int, rules: array<string, int>}> $series */
		$series = array();
		$index  = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date           = CU_DB::date_from_now( 'Y-m-d', -$i * DAY_IN_SECONDS );
			$index[ $date ] = count( $series );
			$series[]       = array( 'date' => $date, 'total' => 0, 'unique_ips' => 0, 'rules' => array() );
		}

		$by_rule = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS day, rule, COUNT(*) AS cnt FROM {$logs_table}
			 WHERE decision = 'blocked' AND created_at >= %s
			 GROUP BY day, rule",
			$start
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rule_totals = array();
		foreach ( (array) $by_rule as $row ) {
			if ( ! isset( $index[ $row['day'] ] ) ) {
				continue;
			}
			$pos    = $index[ $row['day'] ];
			$family = self::rule_family( $row['rule'] );
			$cnt    = (int) $row['cnt'];
			$series[ $pos ]['total']           += $cnt;
			$series[ $pos ]['rules'][ $family ] = ( isset( $series[ $pos ]['rules'][ $family ] ) ? $series[ $pos ]['rules'][ $family ] : 0 ) + $cnt;
			$rule_totals[ $family ]             = ( isset( $rule_totals[ $family ] ) ? $rule_totals[ $family ] : 0 ) + $cnt;
		}

		$uniques = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS day, COUNT(DISTINCT ip) AS ips FROM {$logs_table}
			 WHERE decision = 'blocked' AND created_at >= %s
			 GROUP BY day",
			$start
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $uniques as $row ) {
			if ( isset( $index[ $row['day'] ] ) ) {
				$series[ $index[ $row['day'] ] ]['unique_ips'] = (int) $row['ips'];
			}
		}

		$period_ips = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT ip) FROM {$logs_table} WHERE decision = 'blocked' AND created_at >= %s",
			$start
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$logs_table} WHERE decision = 'blocked' AND created_at >= %s AND created_at < %s",
			$prev_start,
			$start
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total = 0;
		$peak  = null;
		foreach ( $series as $day ) {
			$total += $day['total'];
			if ( $day['total'] > 0 && ( null === $peak || $day['total'] > $peak['total'] ) ) {
				$peak = array( 'date' => $day['date'], 'total' => $day['total'] );
			}
		}
		arsort( $rule_totals );

		return rest_ensure_response( array(
			'days'    => $series,
			'rules'   => $rule_totals, // familles, de la plus fréquente à la plus rare
			'summary' => array(
				'total'      => $total,
				'prev_total' => $prev_total,
				'avg'        => $days ? round( $total / $days, 1 ) : 0,
				'unique_ips' => $period_ips,
				'peak'       => $peak,
				'days'       => $days,
			),
		) );
	}

	/**
	 * Détail d'une journée pour l'infobulle du graphique : heure par heure, IP
	 * les plus actives (pays, règle principale, encore bloquées ?), pays, pages
	 * visées, gravité, première et dernière attaque. Requêtes bornées à la journée.
	 */
	public function rest_attacks_day( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		$date = $request->get_param( 'date' );
		$date = is_string( $date ) ? $date : '';
		$parts = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ? array( (int) $m[1], (int) $m[2], (int) $m[3] ) : null;
		$today = CU_DB::date_from_now( 'Y-m-d' );
		$oldest = CU_DB::date_from_now( 'Y-m-d', -90 * DAY_IN_SECONDS );
		if ( ! $parts || ! checkdate( $parts[1], $parts[2], $parts[0] ) || $date > $today || $date < $oldest ) {
			return new WP_Error( 'cu_invalid_date', __( 'Date invalide.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$logs   = CU_DB::table( 'firewall_logs' );
		$blocks = CU_DB::table( 'firewall_blocks' );
		$from   = $date . ' 00:00:00';
		$to     = gmdate( 'Y-m-d', (int) strtotime( $date . ' 00:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00';
		$where  = "decision = 'blocked' AND created_at >= %s AND created_at < %s";

		$hours = array_fill( 0, 24, 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT HOUR(created_at) AS h, COUNT(*) AS n FROM {$logs} WHERE {$where} GROUP BY h", $from, $to ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$h = (int) $row['h'];
			if ( $h >= 0 && $h < 24 ) {
				$hours[ $h ] = (int) $row['n'];
			}
		}

		$top_ips = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT ip, MAX(country_code) AS country, COUNT(*) AS n, MAX(created_at) AS last_at FROM {$logs} WHERE {$where} GROUP BY ip ORDER BY n DESC LIMIT 5",
			$from,
			$to
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ips = array_column( $top_ips, 'ip' );
		$main_rule = array();
		$blocked   = array();
		if ( $ips ) {
			$in = implode( ',', array_fill( 0, count( $ips ), '%s' ) );
			$rule_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ip, rule, COUNT(*) AS n FROM {$logs} WHERE {$where} AND ip IN ({$in}) GROUP BY ip, rule",
				array_merge( array( $from, $to ), $ips )
			), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$best = array();
			foreach ( (array) $rule_rows as $row ) {
				if ( ! isset( $best[ $row['ip'] ] ) || (int) $row['n'] > $best[ $row['ip'] ] ) {
					$best[ $row['ip'] ]      = (int) $row['n'];
					$main_rule[ $row['ip'] ] = self::rule_family( $row['rule'] );
				}
			}
			$active = $wpdb->get_col( $wpdb->prepare(
				"SELECT ip_or_cidr FROM {$blocks} WHERE ip_or_cidr IN ({$in}) AND ( expires_at IS NULL OR expires_at > %s )",
				array_merge( $ips, array( current_time( 'mysql' ) ) )
			) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$blocked = array_fill_keys( (array) $active, true );
		}
		$top = array();
		foreach ( $top_ips as $row ) {
			$top[] = array(
				'ip'      => $row['ip'],
				'country' => $row['country'] ? strtoupper( (string) $row['country'] ) : '',
				'count'   => (int) $row['n'],
				'last_at' => $row['last_at'],
				'rule'    => isset( $main_rule[ $row['ip'] ] ) ? $main_rule[ $row['ip'] ] : 'other',
				'blocked' => isset( $blocked[ $row['ip'] ] ),
			);
		}

		$countries = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT country_code AS cc, COUNT(*) AS n FROM {$logs} WHERE {$where} AND country_code IS NOT NULL AND country_code <> '' GROUP BY cc ORDER BY n DESC LIMIT 5", $from, $to ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$countries[] = array( 'code' => strtoupper( (string) $row['cc'] ), 'count' => (int) $row['n'] );
		}

		// Pages visées : chemin sans la requête (les charges d'attaque restent hors de l'affichage).
		$paths = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT url, COUNT(*) AS n FROM {$logs} WHERE {$where} AND url IS NOT NULL AND url <> '' GROUP BY url ORDER BY n DESC LIMIT 25", $from, $to ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$path = (string) wp_parse_url( (string) $row['url'], PHP_URL_PATH );
			$path = '' === $path ? '/' : CU_Text::substr( $path, 0, 90 );
			$paths[ $path ] = ( isset( $paths[ $path ] ) ? $paths[ $path ] : 0 ) + (int) $row['n'];
		}
		arsort( $paths );
		$targets = array();
		foreach ( array_slice( $paths, 0, 3, true ) as $path => $n ) {
			$targets[] = array( 'path' => (string) $path, 'count' => (int) $n );
		}

		$severity = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT severity, COUNT(*) AS n FROM {$logs} WHERE {$where} GROUP BY severity", $from, $to ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$key = sanitize_key( (string) $row['severity'] );
			$severity[ '' === $key ? 'unknown' : $key ] = (int) $row['n'];
		}
		$span = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM {$logs} WHERE {$where}", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return rest_ensure_response( array(
			'date'      => $date,
			'hours'     => $hours,
			'top_ips'   => $top,
			'countries' => $countries,
			'targets'   => $targets,
			'severity'  => $severity,
			'first_at'  => isset( $span['first_at'] ) ? $span['first_at'] : null,
			'last_at'   => isset( $span['last_at'] ) ? $span['last_at'] : null,
		) );
	}

	public function rest_blocks_list( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table   = CU_DB::table( 'firewall_blocks' );
		$search  = sanitize_text_field( $request->get_param( 'search' ) ?: '' );
		$page    = self::bounded_page( $request->get_param( 'page' ) );
		$per     = 20;
		$offset  = ( $page - 1 ) * $per;

		$where = "WHERE (expires_at IS NULL OR expires_at > %s)";
		$args  = array( current_time( 'mysql' ) );

		if ( $search ) {
			$where  .= " AND ip_or_cidr LIKE %s";
			$args[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $args ) );
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY last_seen DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, $offset ) ) );
		// phpcs:enable

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return rest_ensure_response( array(
			'items' => $rows,
			'total' => $total,
			'pages' => ceil( $total / $per ),
			'page'  => $page,
		) );
	}

	public function rest_blocks_create( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		$raw      = $request->get_param( 'ip' );
		$ip       = self::canonical_ip_or_cidr( is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '' );
		$reason   = sanitize_text_field( $request->get_param( 'reason' ) ?: 'Manuel' );
		$comment  = sanitize_textarea_field( $request->get_param( 'comment' ) ?: '' );
		$duration = self::manual_duration( $request->get_param( 'duration' ), '24h' );
		$confirm  = rest_sanitize_boolean( $request->get_param( 'confirm_broad' ) );

		if ( '' === $ip ) {
			return new WP_Error( 'cu_invalid_ip', __( 'Adresse IP invalide.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$guard = self::manual_block_guard( $ip, $confirm );
		if ( is_wp_error( $guard ) ) {
			return self::guard_response( $guard );
		}

		global $wpdb;
		$wl_table    = CU_DB::table( 'firewall_whitelist' );
		$whitelisted = false === strpos( $ip, '/' )
			? self::is_whitelisted( $ip )
			: (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wl_table} WHERE ip_or_cidr = %s", $ip ) );
		if ( $whitelisted ) {
			return new WP_Error( 'cu_whitelist_conflict', __( 'Cette IP est dans la whitelist. Retirez-la d\'abord de la whitelist.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		$country_ip = false !== strpos( $ip, '/' ) ? strtok( $ip, '/' ) : $ip;
		$ok         = self::persist_block( $ip, 'manual', $reason, self::duration_to_expires( $duration ), array(
			'explicit'   => true,
			'comment'    => $comment,
			'country'    => CU_Geo::country_for_ip( $country_ip ),
			'created_by' => get_current_user_id(),
		) );
		if ( ! $ok ) {
			return new WP_Error( 'cu_db_write_failed', __( 'Impossible d\'enregistrer le blocage.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		CU_Logger::log( 'firewall', 'warning', __( 'IP bannie manuellement', 'cyberpush-ultra' ), array( 'ip' => $ip, 'reason' => $reason, 'duration' => $duration ) );

		return rest_ensure_response( array( 'success' => true, 'ip' => $ip ) );
	}

	public function rest_blocks_delete( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_blocks' );
		$id    = (int) $request->get_param( 'id' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT ip_or_cidr FROM {$table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new WP_Error( 'cu_not_found', __( 'Blocage introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		$result = $wpdb->delete( $table, array( 'id' => $id ) );
		if ( false === $result ) {
			return new WP_Error( 'cu_db_write_failed', __( 'Impossible de supprimer ce blocage.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		self::flush_list_cache();
		// Un déblocage manuel remet aussi à zéro les compteurs de l'adresse : sans
		// cela, la personne serait reverrouillée dès la tentative suivante.
		self::reset_ip_counters( $row->ip_or_cidr );
		CU_Logger::log( 'firewall', 'info', __( 'IP débloquée', 'cyberpush-ultra' ), array( 'ip' => $row->ip_or_cidr ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function rest_blocks_make_permanent( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_blocks' );
		$id    = (int) $request->get_param( 'id' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT ip_or_cidr FROM {$table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new WP_Error( 'cu_not_found', __( 'Blocage introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		$result = $wpdb->update( $table, array( 'expires_at' => null, 'type' => 'manual' ), array( 'id' => $id ) );
		if ( false === $result ) {
			return new WP_Error( 'cu_db_write_failed', __( 'Impossible de rendre ce blocage permanent.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		self::flush_list_cache();
		CU_Logger::log( 'firewall', 'warning', __( 'IP bannie de façon permanente', 'cyberpush-ultra' ), array( 'ip' => $row->ip_or_cidr ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function rest_blocks_to_whitelist( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$blocks_table = CU_DB::table( 'firewall_blocks' );
		$wl_table     = CU_DB::table( 'firewall_whitelist' );
		$id           = (int) $request->get_param( 'id' );
		$row          = $wpdb->get_row( $wpdb->prepare( "SELECT ip_or_cidr FROM {$blocks_table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new WP_Error( 'cu_not_found', __( 'Blocage introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		// Ajouter d'abord à la whitelist. Le blocage n'est supprimé qu'après
		// confirmation de l'écriture afin de ne perdre aucune protection sur erreur SQL.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wl_table} WHERE ip_or_cidr = %s", $row->ip_or_cidr ) );
		if ( ! $existing ) {
			$result = $wpdb->insert( $wl_table, array(
				'ip_or_cidr' => $row->ip_or_cidr,
				'label'      => __( 'Ajouté depuis les blocages', 'cyberpush-ultra' ),
				'created_at' => current_time( 'mysql' ),
				'expires_at' => null,
			) );
			if ( false === $result ) {
				return new WP_Error( 'cu_db_write_failed', __( 'Impossible d\'ajouter cette IP à la whitelist.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
			}
		} else {
			// Une entrée temporaire existante devient permanente (choix explicite).
			$wpdb->update( $wl_table, array( 'expires_at' => null ), array( 'id' => (int) $existing ) );
		}
		self::flush_list_cache();
		$deleted = $wpdb->delete( $blocks_table, array( 'id' => $id ) );
		if ( false === $deleted ) {
			return new WP_Error( 'cu_db_write_failed', __( 'IP ajoutée à la whitelist, mais impossible de nettoyer son ancien blocage.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		self::flush_list_cache();
		self::reset_ip_counters( $row->ip_or_cidr );
		CU_Logger::log( 'firewall', 'info', __( 'IP déplacée vers la whitelist', 'cyberpush-ultra' ), array( 'ip' => $row->ip_or_cidr ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function rest_whitelist_list( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_whitelist' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 1000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = is_array( $rows ) ? $rows : array();
		$now   = current_time( 'mysql' );
		foreach ( $rows as &$row ) {
			$row['expires_at'] = isset( $row['expires_at'] ) ? $row['expires_at'] : null;
			$row['expired']    = null !== $row['expires_at'] && $row['expires_at'] <= $now;
		}
		unset( $row );
		return rest_ensure_response( array( 'items' => $rows ) );
	}

	public function rest_whitelist_create( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		$raw      = $request->get_param( 'ip' );
		$ip       = self::canonical_ip_or_cidr( is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '' );
		$label    = sanitize_text_field( $request->get_param( 'label' ) ?: '' );
		$duration = self::manual_duration( $request->get_param( 'duration' ), 'permanent' );
		$confirm  = rest_sanitize_boolean( $request->get_param( 'confirm_broad' ) );

		if ( '' === $ip ) {
			return new WP_Error( 'cu_invalid_ip', __( 'Adresse IP invalide.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$guard = self::whitelist_guard( $ip, $confirm );
		if ( is_wp_error( $guard ) ) {
			return self::guard_response( $guard );
		}

		global $wpdb;
		$table    = CU_DB::table( 'firewall_whitelist' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE ip_or_cidr = %s", $ip ) );
		if ( $existing ) {
			return new WP_Error( 'cu_already_whitelisted', __( 'IP déjà dans la whitelist.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		$result = $wpdb->insert( $table, array(
			'ip_or_cidr' => $ip,
			'label'      => $label,
			'created_at' => current_time( 'mysql' ),
			'expires_at' => self::duration_to_expires( $duration ),
		) );
		if ( false === $result ) {
			return new WP_Error( 'cu_db_write_failed', __( 'Impossible d\'ajouter cette IP à la whitelist.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		$whitelist_id = (int) $wpdb->insert_id;
		self::flush_list_cache();
		// Une entrée exacte ne doit pas rester simultanément bloquée et en
		// whitelist : la whitelist gagne déjà à l'exécution, on nettoie donc le
		// blocage devenu trompeur dans l'interface.
		$cleanup = $wpdb->delete( CU_DB::table( 'firewall_blocks' ), array( 'ip_or_cidr' => $ip ) );
		if ( false === $cleanup ) {
			return new WP_Error( 'cu_db_write_failed', __( 'IP ajoutée à la whitelist, mais impossible de nettoyer son ancien blocage.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		self::flush_list_cache();
		self::reset_ip_counters( $ip );
		CU_Logger::log( 'firewall', 'info', __( 'IP ajoutée à la whitelist', 'cyberpush-ultra' ), array( 'ip' => $ip, 'duration' => $duration ) );
		return rest_ensure_response( array( 'success' => true, 'id' => $whitelist_id ) );
	}

	public function rest_whitelist_delete( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_whitelist' );
		$id    = (int) $request->get_param( 'id' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT ip_or_cidr FROM {$table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new WP_Error( 'cu_not_found', __( 'Entrée introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		$result = $wpdb->delete( $table, array( 'id' => $id ) );
		if ( false === $result ) {
			return new WP_Error( 'cu_db_write_failed', __( 'Impossible de retirer cette IP de la whitelist.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		self::flush_list_cache();
		CU_Logger::log( 'firewall', 'info', __( 'IP retirée de la whitelist', 'cyberpush-ultra' ), array( 'ip' => $row->ip_or_cidr ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Réponse d'un garde-fou : une demande de CONFIRMATION (plage large) est
	 * renvoyée en 200 `{ needs_confirm: true, message }` pour que l'interface
	 * puisse la proposer ; tout autre refus reste une erreur HTTP.
	 */
	private static function guard_response( WP_Error $guard ) {
		$data = $guard->get_error_data();
		if ( is_array( $data ) && ! empty( $data['needs_confirm'] ) ) {
			return rest_ensure_response( array(
				'success'       => false,
				'needs_confirm' => true,
				'code'          => $guard->get_error_code(),
				'message'       => $guard->get_error_message(),
			) );
		}
		return $guard;
	}

	/** Durée choisie par l'administrateur, validée contre la liste connue. */
	private static function manual_duration( $raw, $default ) {
		$allowed = array( '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '24h', '7d', '30j', 'permanent' );
		$value   = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/** Durée Radar (liste du réglage) ; une valeur vide ou inconnue n'est JAMAIS « permanent ». */
	private static function radar_duration( $raw ) {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( in_array( $value, array( '24h', '7d', '30j', 'permanent' ), true ) ) {
			return $value;
		}
		$setting = (string) CU_Settings::get( 'firewall', 'radar_autoblock_duration', '7d' );
		return in_array( $setting, array( '24h', '7d', '30j', 'permanent' ), true ) ? $setting : '7d';
	}

	/**
	 * Garde-fous d'un blocage manuel : jamais tout Internet, jamais une adresse
	 * privée/réservée, jamais l'administrateur courant, son relais (proxy, CDN)
	 * ni le serveur lui-même ; une plage plus large que /16 (IPv4) ou /32 (IPv6)
	 * exige une confirmation explicite (`confirm_broad`).
	 *
	 * @return true|WP_Error
	 */
	private static function manual_block_guard( $target, $confirm_broad ) {
		$is_cidr = false !== strpos( $target, '/' );
		$net     = $is_cidr ? strtok( $target, '/' ) : $target;
		$bits    = $is_cidr ? (int) substr( $target, strpos( $target, '/' ) + 1 ) : null;
		$is_v6   = false !== strpos( $net, ':' );

		if ( $is_cidr && 0 === $bits ) {
			return new WP_Error( 'cu_cidr_all', __( 'Impossible de bloquer tout Internet (/0).', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		if ( ! CU_Geo::is_public_ip( $net ) ) {
			return new WP_Error( 'cu_private_ip', __( 'Adresse privée, réservée ou locale : la bloquer pourrait couper votre proxy, votre réseau ou le serveur lui-même.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		foreach ( self::protected_addresses() as $addr => $label ) {
			if ( CU_Geo::ip_in_cidr( $addr, $target ) ) {
				return new WP_Error(
					'cu_self_block',
					sprintf(
						/* translators: 1: IP address, 2: description */
						__( 'Refusé : cette entrée couvre %1$s (%2$s). Vous risqueriez de vous bloquer vous-même ou de rendre le site inaccessible.', 'cyberpush-ultra' ),
						$addr,
						$label
					),
					array( 'status' => 409 )
				);
			}
		}
		if ( $is_cidr && ( ( ! $is_v6 && $bits < 16 ) || ( $is_v6 && $bits < 32 ) ) && ! $confirm_broad ) {
			return new WP_Error(
				'cu_broad_cidr',
				sprintf(
					/* translators: %d: CIDR prefix length */
					__( 'Plage très large (/%d) : elle peut contenir des millions de visiteurs légitimes. Confirmez explicitement pour continuer.', 'cyberpush-ultra' ),
					$bits
				),
				array( 'status' => 409, 'needs_confirm' => true )
			);
		}
		return true;
	}

	/**
	 * Garde-fous d'une entrée de whitelist : jamais tout Internet ; une plage
	 * très large ou une adresse privée (qui exempterait TOUT le trafic passant
	 * par un proxy interne) exigent une confirmation explicite.
	 *
	 * @return true|WP_Error
	 */
	private static function whitelist_guard( $target, $confirm_broad ) {
		$is_cidr = false !== strpos( $target, '/' );
		$net     = $is_cidr ? strtok( $target, '/' ) : $target;
		$bits    = $is_cidr ? (int) substr( $target, strpos( $target, '/' ) + 1 ) : null;
		$is_v6   = false !== strpos( $net, ':' );
		if ( $is_cidr && 0 === $bits ) {
			return new WP_Error( 'cu_cidr_all', __( 'Impossible d\'autoriser tout Internet (/0) : cela désactiverait le pare-feu.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		if ( $confirm_broad ) {
			return true;
		}
		if ( $is_cidr && ( ( ! $is_v6 && $bits < 16 ) || ( $is_v6 && $bits < 32 ) ) ) {
			return new WP_Error( 'cu_broad_cidr', sprintf( __( 'Plage très large (/%d) : tous ses visiteurs échapperaient au pare-feu. Confirmez explicitement pour continuer.', 'cyberpush-ultra' ), $bits ), array( 'status' => 409, 'needs_confirm' => true ) );
		}
		if ( ! CU_Geo::is_public_ip( $net ) ) {
			return new WP_Error( 'cu_broad_cidr', __( 'Adresse privée ou locale : si le site est derrière un proxy interne, cette entrée exempterait TOUT le trafic. Confirmez explicitement pour continuer.', 'cyberpush-ultra' ), array( 'status' => 409, 'needs_confirm' => true ) );
		}
		return true;
	}

	/** Adresses qu'un blocage manuel ne doit jamais couvrir (libellé lisible). */
	private static function protected_addresses() {
		$out  = array();
		$self = self::get_client_ip();
		if ( '' !== $self ) {
			$out[ $self ] = __( 'votre adresse IP actuelle', 'cyberpush-ultra' );
		}
		$peer = CU_Geo::normalize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		if ( '' !== $peer && ! isset( $out[ $peer ] ) ) {
			$out[ $peer ] = __( 'le relais (proxy/CDN) par lequel arrivent les requêtes', 'cyberpush-ultra' );
		}
		foreach ( self::server_ips() as $server_ip ) {
			if ( ! isset( $out[ $server_ip ] ) ) {
				$out[ $server_ip ] = __( 'une adresse du serveur', 'cyberpush-ultra' );
			}
		}
		return $out;
	}

	public function rest_trusted_countries_get( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( array(
			'trusted_countries' => CU_Settings::get( 'firewall', 'trusted_countries', array() ),
		) );
	}

	public function rest_trusted_countries_update( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		$countries = $request->get_param( 'trusted_countries' );
		if ( ! is_array( $countries ) ) {
			$countries = array();
		}
		$clean_countries = array();
		foreach ( $countries as $country ) {
			if ( is_scalar( $country ) ) {
				$clean_countries[] = strtoupper( sanitize_text_field( $country ) );
			}
		}
		$countries = $clean_countries;
		$countries = array_filter( $countries, function( $c ) { return preg_match( '/^[A-Z]{2}$/', $c ); } );
		$countries = array_values( array_unique( $countries ) );

		CU_Settings::update_module( 'firewall', array( 'trusted_countries' => $countries ) );
		return rest_ensure_response( array( 'success' => true, 'trusted_countries' => $countries ) );
	}

	public function rest_top_attackers( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$hours      = self::bounded_hours( $request->get_param( 'range' ) );
		$since      = CU_DB::date_from_now( 'Y-m-d H:i:s', -$hours * HOUR_IN_SECONDS );
		$logs_table = CU_DB::table( 'firewall_logs' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ip, country_code, COUNT(*) as attacks, MAX(created_at) as last_seen, GROUP_CONCAT(DISTINCT rule ORDER BY rule SEPARATOR ',') as rules
			 FROM {$logs_table}
			 WHERE decision = 'blocked' AND created_at >= %s
			 GROUP BY ip, country_code
			 ORDER BY attacks DESC
			 LIMIT 20",
			$since
		), ARRAY_A );

		return rest_ensure_response( array( 'items' => $rows ) );
	}

	public function rest_logs_list( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table    = CU_DB::table( 'firewall_logs' );
		$search   = sanitize_text_field( $request->get_param( 'search' ) ?: '' );
		$ip       = sanitize_text_field( $request->get_param( 'ip' ) ?: '' );
		$country  = sanitize_text_field( $request->get_param( 'country' ) ?: '' );
		$type     = sanitize_text_field( $request->get_param( 'type' ) ?: '' );
		$severity = sanitize_text_field( $request->get_param( 'severity' ) ?: '' );
		$status   = sanitize_text_field( $request->get_param( 'status' ) ?: '' );
		$page     = self::bounded_page( $request->get_param( 'page' ) );
		$per      = 50;
		$offset   = ( $page - 1 ) * $per;
		$hours    = self::bounded_hours( $request->get_param( 'range' ) );
		$since    = CU_DB::date_from_now( 'Y-m-d H:i:s', -$hours * HOUR_IN_SECONDS );

		$conditions = array( 'created_at >= %s' );
		$args       = array( $since );

		if ( $ip ) {
			$conditions[] = 'ip LIKE %s';
			$args[]       = '%' . $wpdb->esc_like( $ip ) . '%';
		}
		if ( $country ) {
			$conditions[] = 'country_code = %s';
			$args[]       = strtoupper( $country );
		}
		if ( 'waf' === $type ) {
			// Les règles WAF sont dynamiques (waf_sqli_0, waf_xss_2…) : filtre par préfixe.
			$conditions[] = 'rule LIKE %s';
			$args[]       = $wpdb->esc_like( 'waf_' ) . '%';
		} elseif ( $type ) {
			$conditions[] = 'rule = %s';
			$args[]       = $type;
		}
		if ( $severity ) {
			$conditions[] = 'severity = %s';
			$args[]       = $severity;
		}
		if ( $status ) {
			$conditions[] = 'decision = %s';
			$args[]       = $status;
		}
		if ( $search ) {
			$conditions[] = '(ip LIKE %s OR url LIKE %s OR user_agent LIKE %s)';
			$like         = '%' . $wpdb->esc_like( $search ) . '%';
			$args[]       = $like;
			$args[]       = $like;
			$args[]       = $like;
		}

		$where = 'WHERE ' . implode( ' AND ', $conditions );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $args ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, $offset ) ) ),
			ARRAY_A
		);
		// phpcs:enable

		return rest_ensure_response( array(
			'items' => $rows,
			'total' => $total,
			'pages' => ceil( $total / $per ),
			'page'  => $page,
		) );
	}

	public function rest_logs_purge( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table  = CU_DB::table( 'firewall_logs' );
		$since  = CU_DB::date_from_now( 'Y-m-d H:i:s', -DAY_IN_SECONDS );
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $since ) );
		if ( false === $result ) {
			self::log_db_failure_once( 'logs_purge' );
			return new WP_Error( 'cu_db_write_failed', __( 'Impossible de purger le journal du pare-feu.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		CU_Logger::log( 'firewall', 'info', sprintf(
			/* translators: %d: rows deleted */
			__( 'Journal pare-feu purgé (%d entrées supprimées)', 'cyberpush-ultra' ),
			(int) $result
		) );
		return rest_ensure_response( array( 'success' => true, 'deleted' => (int) $result ) );
	}

	public function rest_global_stats( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$logs_table   = CU_DB::table( 'firewall_logs' );
		$blocks_table = CU_DB::table( 'firewall_blocks' );
		$wl_table     = CU_DB::table( 'firewall_whitelist' );

		$total_blocked   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table} WHERE decision = 'blocked'" );
		// Les requêtes autorisées ne sont échantillonnées (1/20, sans paramètres
		// d'URL) qu'en mode débogage : hors de ce mode, aucun taux n'est estimable.
		$sampling        = (bool) CU_Settings::get( 'developer', 'debug_mode', false );
		$total_allowed   = $sampling ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table} WHERE decision = 'allowed' AND rule IS NULL" ) : 0;
		$estimated_allowed = $total_allowed * 20;
		$total_requests  = $total_blocked + $estimated_allowed;
		$block_rate      = ( $sampling && $total_allowed > 0 && $total_requests > 0 ) ? round( ( $total_blocked / $total_requests ) * 100, 2 ) : null;
		$blocked_ips     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$blocks_table} WHERE expires_at IS NULL OR expires_at > %s", current_time( 'mysql' ) ) );
		$whitelist_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wl_table} WHERE expires_at IS NULL OR expires_at > %s", current_time( 'mysql' ) ) );

		$top_countries = $wpdb->get_results(
			"SELECT country_code, COUNT(*) as cnt FROM {$logs_table} WHERE decision = 'blocked' AND country_code IS NOT NULL GROUP BY country_code ORDER BY cnt DESC LIMIT 5",
			ARRAY_A
		);

		$top_types = $wpdb->get_results(
			"SELECT rule, COUNT(*) as cnt FROM {$logs_table} WHERE decision = 'blocked' AND rule IS NOT NULL GROUP BY rule ORDER BY cnt DESC LIMIT 5",
			ARRAY_A
		);

		// Temps moyen entre 2 attaques (secondes).
		$time_between = null;
		$intervals = $wpdb->get_col(
			"SELECT UNIX_TIMESTAMP(created_at) FROM {$logs_table} WHERE decision = 'blocked' ORDER BY created_at DESC LIMIT 500"
		);
		if ( count( $intervals ) > 1 ) {
			$diffs = array();
			for ( $i = 1; $i < count( $intervals ); $i++ ) {
				$diffs[] = abs( $intervals[ $i ] - $intervals[ $i - 1 ] );
			}
			$time_between = round( array_sum( $diffs ) / count( $diffs ) );
		}

		// Date de la première et dernière attaque.
		$first_attack = $wpdb->get_var( "SELECT MIN(created_at) FROM {$logs_table} WHERE decision = 'blocked'" );
		$last_attack  = $wpdb->get_var( "SELECT MAX(created_at) FROM {$logs_table} WHERE decision = 'blocked'" );

		return rest_ensure_response( array(
			'total_blocked'       => $total_blocked,
			'total_requests'      => $total_requests,
			'total_allowed'       => $estimated_allowed,
			'sampled_allowed'     => $total_allowed,
			'sampling'            => $sampling,
			'block_rate'          => $block_rate,
			'blocked_ips'         => $blocked_ips,
			'whitelist_count'     => $whitelist_count,
			'top_countries'       => $top_countries,
			'top_types'           => $top_types,
			'avg_time_between_s'  => $time_between,
			'first_attack'        => $first_attack,
			'last_attack'         => $last_attack,
		) );
	}

	// -----------------------------------------------------------------------
	// Radar — callbacks REST
	// -----------------------------------------------------------------------

	/**
	 * Liste les suspects observés. Enrichit au passage un petit lot d'entrées
	 * pas encore résolues (org/datacenter/proxy) — throttlé pour ménager l'API.
	 */
	public function rest_radar_list( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_watch' );

		// 1) Enrichissement réseau (organisation, datacenter, proxy) : uniquement
		//    si l'administrateur a choisi un fournisseur externe (Réglages ▸
		//    Analytics). Au plus 3 IP par chargement de page ; le reste est traité
		//    en tâche de fond par la maintenance (toutes les 5 minutes).
		$enrichment = CU_Geo::remote_lookups_allowed();
		if ( $enrichment ) {
			$to_enrich = $wpdb->get_results( "SELECT id, ip FROM {$table} WHERE enriched = 0 ORDER BY score DESC, last_seen DESC LIMIT 3", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $wpdb->last_error || ! is_array( $to_enrich ) ) {
				self::log_db_failure_once( 'radar_list_enrichment_read' );
				return new WP_Error( 'cu_db_read_failed', __( 'Impossible de lire les données du Radar.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
			}
			foreach ( (array) $to_enrich as $row ) {
				$e = CU_Geo::enrich( $row['ip'] );
				if ( empty( $e['source'] ) ) {
					continue; // ne pas transformer un timeout en faux résultat définitif.
				}
				if ( false === self::radar_apply_enrichment( (int) $row['id'], $e ) ) {
					self::log_db_failure_once( 'radar_enrichment_write' );
				}
			}
		}

		// 2) Filtres (bornés : le filet est purgé au-delà de 30 jours).
		$hours    = self::bounded_hours( $request->get_param( 'hours' ), 168, 720 );
		$since    = CU_DB::date_from_now( 'Y-m-d H:i:s', -$hours * HOUR_IN_SECONDS );
		$filter   = sanitize_key( $request->get_param( 'filter' ) ?: '' );
		$minscore = max( 0, min( 100, (int) ( $request->get_param( 'min_score' ) ?: 0 ) ) );
		$page     = self::bounded_page( $request->get_param( 'page' ) );
		$per      = 25;
		$offset   = ( $page - 1 ) * $per;

		$where  = array( 'last_seen >= %s', "status != 'ignored'" );
		$args   = array( $since );
		if ( 'datacenter' === $filter ) {
			$where[] = 'is_datacenter = 1';
		} elseif ( 'proxy' === $filter ) {
			$where[] = 'is_proxy = 1';
		} elseif ( 'bot' === $filter ) {
			// Ce fragment passe ensuite dans $wpdb->prepare() : les pourcentages
			// littéraux doivent être doublés pour ne pas être lus comme placeholders.
			$where[] = "(signals LIKE '%%bot%%' OR signals LIKE '%%empty_ua%%')";
		}
		if ( $minscore > 0 ) {
			$where[] = 'score >= %d';
			$args[]  = $minscore;
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY score DESC, last_seen DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, $offset ) ) ),
			ARRAY_A
		);
		// phpcs:enable
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			self::log_db_failure_once( 'radar_list_read' );
			return new WP_Error( 'cu_db_read_failed', __( 'Impossible de lire les données du Radar.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		// 3) Marque les IP déjà bloquées ou en whitelist, y compris lorsqu'une
		// plage CIDR les couvre. La consultation ne doit jamais augmenter les hits.
		foreach ( $rows as &$r ) {
			$r['is_blocked']     = self::is_blocked( $r['ip'], false );
			$r['is_whitelisted'] = self::is_whitelisted( $r['ip'] );
			$r['signals']        = $r['signals'] ? explode( ',', $r['signals'] ) : array();
		}
		unset( $r );

		// 4) Compteurs synthétiques.
		$counts = array(
			'total'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE last_seen >= %s AND status != 'ignored'", $since ) ),
			'datacenter' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE last_seen >= %s AND status != 'ignored' AND is_datacenter = 1", $since ) ),
			'proxy'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE last_seen >= %s AND status != 'ignored' AND is_proxy = 1", $since ) ),
			'pending'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE last_seen >= %s AND status != 'ignored' AND enriched = 0", $since ) ),
		);
		if ( $wpdb->last_error ) {
			self::log_db_failure_once( 'radar_counts_read' );
			return new WP_Error( 'cu_db_read_failed', __( 'Impossible de calculer les compteurs du Radar.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'items'  => $rows,
			'total'  => $total,
			'pages'  => (int) ceil( $total / $per ),
			'page'   => $page,
			'counts' => $counts,
			'enrichment' => array(
				'enabled' => $enrichment,
			),
			'autoblock' => array(
				'enabled'   => (bool) CU_Settings::get( 'firewall', 'radar_autoblock_enabled', false ),
				'min_score' => (int) CU_Settings::get( 'firewall', 'radar_autoblock_min_score', 90 ),
				'min_hits'  => max( 2, (int) CU_Settings::get( 'firewall', 'radar_autoblock_min_hits', 3 ) ),
				'mode'      => (string) CU_Settings::get( 'firewall', 'radar_autoblock_mode', 'ip' ),
				'duration'  => (string) CU_Settings::get( 'firewall', 'radar_autoblock_duration', '7d' ),
			),
		) );
	}

	/**
	 * Bloque en masse une sélection d'IP issues du Radar. Par défaut vise l'IP
	 * seule ; mode 'cidr' bloque le /24 (IPv4) pour couvrir un datacenter.
	 */
	public function rest_radar_block_bulk( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		$body     = $request->get_json_params();
		$ips      = ( is_array( $body ) && isset( $body['ips'] ) && is_array( $body['ips'] ) ) ? array_slice( $body['ips'], 0, 500 ) : array();
		// Durée absente, vide ou inconnue → durée Radar configurée (jamais un ban
		// permanent par accident).
		$duration = self::radar_duration( isset( $body['duration'] ) ? $body['duration'] : '' );
		$mode     = ( isset( $body['mode'] ) && 'cidr' === $body['mode'] ) ? 'cidr' : 'ip';

		if ( empty( $ips ) ) {
			return new WP_Error( 'cu_radar_empty', __( 'Aucune IP sélectionnée.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$watch   = CU_DB::table( 'firewall_watch' );
		$blocked = 0;
		$skipped = 0;

		foreach ( $ips as $raw ) {
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$original = sanitize_text_field( (string) $raw );
			$ip       = CU_Geo::normalize_ip( $original );
			if ( '' === $ip ) {
				continue;
			}
			if ( self::is_whitelisted( $ip ) || self::is_blocked( $ip, false ) ) {
				continue;
			}
			$target = ( 'cidr' === $mode && false === strpos( $ip, ':' ) ) ? self::ipv4_to_cidr24( $ip ) : $ip;
			if ( is_wp_error( self::manual_block_guard( $target, false ) ) ) {
				$skipped++;
				continue;
			}
			if ( self::radar_insert_block( $target, __( 'Radar — blocage manuel', 'cyberpush-ultra' ), $duration ) ) {
				// Retire l'IP du filet uniquement après confirmation du blocage.
				$wpdb->delete( $watch, array( 'ip' => $original ) );
				$blocked++;
			}
		}

		CU_Logger::log( 'firewall', 'warning', sprintf( __( 'Radar : %d IP bloquée(s) manuellement', 'cyberpush-ultra' ), $blocked ), array( 'duration' => $duration, 'skipped' => $skipped ) );
		return rest_ensure_response( array( 'success' => true, 'blocked' => $blocked, 'skipped' => $skipped ) );
	}

	public function rest_radar_ignore( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		$body = $request->get_json_params();
		$ips  = ( is_array( $body ) && isset( $body['ips'] ) && is_array( $body['ips'] ) ) ? $body['ips'] : array();
		if ( empty( $ips ) ) {
			return new WP_Error( 'cu_radar_empty', __( 'Aucune IP sélectionnée.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$table   = CU_DB::table( 'firewall_watch' );
		$ignored = 0;
		$failed  = 0;
		foreach ( $ips as $raw ) {
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$ip = sanitize_text_field( $raw );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$result = $wpdb->update( $table, array( 'status' => 'ignored' ), array( 'ip' => $ip ) );
				if ( false === $result ) {
					$failed++;
				} elseif ( $result > 0 ) {
					$ignored++;
				}
			}
		}
		if ( $failed > 0 ) {
			return new WP_Error( 'cu_db_write_failed', __( 'Impossible d’ignorer une ou plusieurs IP du Radar.', 'cyberpush-ultra' ), array( 'status' => 500, 'ignored' => $ignored ) );
		}
		return rest_ensure_response( array( 'success' => true, 'ignored' => $ignored ) );
	}

	public function rest_radar_purge( $request ) {
		if ( ! self::tables_available() ) {
			return new WP_Error( 'cu_no_tables', __( 'Tables SQL manquantes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_watch' );
		$scope = sanitize_key( $request->get_param( 'scope' ) ?: 'ignored' );
		if ( 'all' === $scope ) {
			$deleted = $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$deleted = $wpdb->query( "DELETE FROM {$table} WHERE status = 'ignored'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( false === $deleted ) {
			return new WP_Error( 'cu_db_write_failed', __( 'Impossible de purger les observations du Radar.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'deleted' => (int) $deleted ) );
	}

	/**
	 * Enregistre l'enrichissement réseau d'une observation Radar. Le bonus de
	 * score n'est accordé que pour un drapeau NOUVELLEMENT acquis : une ligne
	 * remise en file par la maintenance (org/ASN vides) ne doit pas cumuler
	 * +40/+35 à chaque cycle jusqu'au seuil d'auto-blocage.
	 * MySQL évalue les affectations dans l'ordre : `score` est calculé avant
	 * que is_datacenter / is_proxy ne soient mis à jour.
	 *
	 * @return int|false Lignes modifiées, ou false en cas d'erreur SQL.
	 */
	private static function radar_apply_enrichment( $id, array $e ) {
		global $wpdb;
		$table = CU_DB::table( 'firewall_watch' );
		$dc    = ! empty( $e['is_datacenter'] ) ? 1 : 0;
		$proxy = ! empty( $e['is_proxy'] ) ? 1 : 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET
				score = LEAST(100, score + IF(%d = 1 AND is_datacenter = 0, 40, 0) + IF(%d = 1 AND is_proxy = 0, 35, 0)),
				country_code = COALESCE(NULLIF(%s, ''), country_code), org = %s, asn = %s,
				is_datacenter = %d, is_proxy = %d, enriched = 1
			 WHERE id = %d",
			$dc,
			$proxy,
			(string) $e['country'],
			(string) $e['org'],
			(string) $e['asn'],
			$dc,
			$proxy,
			(int) $id
		) );
	}

	/** Convertit une IPv4 en notation /24 (ex: 203.0.113.45 → 203.0.113.0/24). */
	private static function ipv4_to_cidr24( $ip ) {
		$parts = explode( '.', $ip );
		if ( 4 !== count( $parts ) ) {
			return $ip;
		}
		return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
	}

	/**
	 * Insère (ou met à jour) un blocage issu du Radar. La durée est celle choisie
	 * explicitement par l'administrateur (bouton ou réglage d'auto-blocage).
	 * Automatique : un blocage existant n'est jamais raccourci. Une adresse
	 * protégée (serveur, relais, privée) n'est jamais visée.
	 */
	private static function radar_insert_block( $target, $reason, $duration, $type = 'manual' ) {
		$target = self::canonical_ip_or_cidr( $target );
		if ( '' === $target || is_wp_error( self::manual_block_guard( $target, false ) ) ) {
			return false;
		}
		$country = ( false === strpos( $target, '/' ) ) ? CU_Geo::country_for_ip( $target ) : '';
		return self::persist_block( $target, 'auto' === $type ? 'auto' : 'manual', $reason, self::duration_to_expires( $duration ), array(
			'explicit'      => 'auto' !== $type,
			'never_shorten' => 'auto' === $type,
			'country'       => $country,
			'created_by'    => get_current_user_id(),
			'hits'          => 0,
		) );
	}

	// -----------------------------------------------------------------------
	// Méthodes internes utilitaires
	// -----------------------------------------------------------------------

	/**
	 * Vérifie si les tables sont disponibles (résultat mis en cache pour la requête).
	 */
	private static function tables_available() {
		if ( null === self::$tables_ok ) {
			// Même preuve que CU_DB hors administration : l'option de version
			// (autochargée) n'est écrite qu'après une création de tables réussie.
			// Évite une requête information_schema à CHAQUE appel admin-ajax
			// anonyme (is_admin() y est vrai, ce qui désactive le chemin rapide de CU_DB).
			if ( defined( 'CU_OPTION_DB_VERSION' ) && defined( 'CU_DB_VERSION' ) && get_option( CU_OPTION_DB_VERSION ) === CU_DB_VERSION ) {
				self::$tables_ok = true;
			} else {
				self::$tables_ok = method_exists( 'CU_DB', 'tables_exist_for' )
					? CU_DB::tables_exist_for( array( 'firewall_logs', 'firewall_blocks', 'firewall_whitelist', 'firewall_watch' ) )
					: CU_DB::tables_exist();
			}
		}
		return self::$tables_ok;
	}

	/** Chemin URL canonique, sans query string, borné et décodé au plus deux fois. */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = wp_parse_url( (string) $uri, PHP_URL_PATH );
		return self::normalize_path( false === $path || null === $path ? '/' : $path );
	}

	/** Normalise un chemin sans laisser les slashs, dot-segments ou doubles encodages contourner les règles. */
	private static function normalize_path( $path ) {
		$path = substr( (string) $path, 0, 8192 );
		for ( $i = 0; $i < 2; $i++ ) {
			$decoded = rawurldecode( $path );
			if ( $decoded === $path ) {
				break;
			}
			$path = $decoded;
		}
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#/+#', '/', '/' . ltrim( $path, '/' ) );
		$parts = array();
		foreach ( explode( '/', (string) $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}
		return '/' . implode( '/', $parts );
	}

	/** robots.txt et sitemaps réels (jamais une simple occurrence dans la query string). */
	private static function is_seo_control_path( $path ) {
		$base = strtolower( basename( (string) $path ) );
		if ( 'robots.txt' === $base ) {
			return true;
		}
		return false !== strpos( $base, 'sitemap' ) && (bool) preg_match( '/\.xml(?:\.gz)?$/i', $base );
	}

	/**
	 * Authentifie le crawl loopback du module SEO. Le User-Agent seul n'est
	 * jamais une preuve : le jeton HMAC expire en cinq minutes et utilise les
	 * sels secrets WordPress.
	 */
	private static function is_internal_seo_audit_request() {
		// Jeton HMAC à usage unique, lié au chemin, valable 2 minutes (voir
		// CU_SEO_Renderer::audit_headers_for()). Le User-Agent seul n'est jamais une preuve.
		return class_exists( 'CU_SEO_Renderer' ) && CU_SEO_Renderer::is_valid_audit_request();
	}

	/** Requête POST vers la route publique de collecte Analytics du plugin. */
	private static function is_analytics_beacon_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return false;
		}
		// Voie de secours du tracker quand l'API REST est fermée aux visiteurs.
		if ( isset( $_GET['action'] ) && 'cu_collect' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return self::str_ends_with_ci( untrailingslashit( self::request_path() ), '/admin-ajax.php' );
		}
		$route = '/' . CU_REST_NAMESPACE . '/collect';
		// Permaliens simples : /?rest_route=/cyberpush-ultra/v1/collect
		if ( isset( $_GET['rest_route'] ) && is_scalar( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return strtolower( self::normalize_path( wp_unslash( $_GET['rest_route'] ) ) ) === $route; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( rest_get_url_prefix(), '/' ) : 'wp-json';
		return self::str_ends_with_ci( untrailingslashit( self::request_path() ), '/' . $prefix . $route );
	}

	/**
	 * Clé de compteur : préfixe + identifiant HMAC du « seau » de l'IP (IPv4 :
	 * l'adresse ; IPv6 : son /64). Changer d'adresse dans un même /64 ne remet
	 * donc pas les compteurs à zéro et ne crée pas de nouvelles lignes. Les
	 * bannissements, eux, restent par adresse exacte.
	 */
	private static function counter_key( $prefix, $ip ) {
		return 'cu_' . $prefix . '_' . CU_Geo::ip_key( CU_Geo::rate_bucket( $ip ) );
	}

	/**
	 * Module Spam : incrémente un compteur à fenêtre fixe pour le seau de l'IP
	 * (même mécanisme que le débit du pare-feu) et renvoie sa valeur. Lecture
	 * seule pour le pare-feu : aucun blocage, aucun bannissement.
	 */
	public static function bump_ip_counter( $prefix, $ip, $window ) {
		$state = self::increment_fixed_window_counter( self::counter_key( sanitize_key( (string) $prefix ), $ip ), $window );
		return (int) $state['count'];
	}

	/** Module Spam : l'IP figure-t-elle dans la liste blanche du pare-feu ? */
	public static function ip_is_whitelisted( $ip ) {
		return self::is_whitelisted( $ip );
	}

	/** Cache objet persistant (Redis, Memcached…) disponible. */
	private static function has_object_cache() {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}

	/**
	 * Durée de fenêtre de chaque compteur du pare-feu. Lecture, incrément et
	 * remise à zéro d'un même compteur utilisent toujours la même fenêtre.
	 */
	private static function counter_window( $prefix ) {
		switch ( $prefix ) {
			case 'rl':
				return max( 1, (int) CU_Settings::get( 'firewall', 'rate_limit_window_seconds', 60 ) );
			case '404':
				return max( 1, (int) CU_Settings::get( 'firewall', 'flood_404_window_minutes', 5 ) ) * MINUTE_IN_SECONDS;
			case 'login':
				return max( max( 1, (int) CU_Settings::get( 'firewall', 'login_lock_minutes', 15 ) ) * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS );
			case 'enum':
				return self::enumeration_window();
			case 'lpost':
			case 'xmlrpc':
				return 5 * MINUTE_IN_SECONDS;
		}
		return MINUTE_IN_SECONDS;
	}

	/**
	 * Incrémente un compteur à fenêtre FIXE, de façon ATOMIQUE (QA-MAIN-004) :
	 * CU_DB::counter_increment() utilise wp_cache_incr avec un cache objet
	 * persistant, sinon une seule requête INSERT … ON DUPLICATE KEY UPDATE. Les
	 * requêtes simultanées ne perdent plus d'incréments (l'ancien transient lu
	 * puis réécrit laissait passer jusqu'à ~25 % de requêtes en trop). Fenêtres
	 * alignées sur l'horloge : la fin de fenêtre n'est jamais repoussée.
	 *
	 * @return array{count:int, expires_at:int}
	 */
	private static function increment_fixed_window_counter( $key, $window ) {
		return CU_DB::counter_increment( $key, max( 1, (int) $window ) );
	}

	/** Valeur courante d'un compteur fixe (fenêtre en cours). */
	private static function fixed_window_counter_value( $key, $window ) {
		return CU_DB::counter_value( $key, max( 1, (int) $window ) );
	}

	/** Remet un compteur à zéro (et l'ancien transient éventuel des versions ≤ 1.3.0). */
	private static function reset_counter( $key, $window ) {
		CU_DB::counter_reset( $key, max( 1, (int) $window ) );
		if ( self::has_object_cache() ) {
			wp_cache_delete( $key, 'cu_fw' );
			wp_cache_delete( $key . '_t', 'cu_fw' );
		}
		delete_transient( $key );
	}

	/** Après un déblocage / une mise en whitelist : compteurs de l'adresse remis à zéro. */
	private static function reset_ip_counters( $ip_or_cidr ) {
		$ip = CU_Geo::normalize_ip( (string) $ip_or_cidr );
		if ( '' === $ip ) {
			return;
		}
		foreach ( array( 'login', 'lpost', 'rl', '404', 'enum', 'xmlrpc' ) as $prefix ) {
			self::reset_counter( self::counter_key( $prefix, $ip ), self::counter_window( $prefix ) );
		}
		// Compteurs restés en transients (ensembles de signatures, niveaux, drapeaux).
		foreach ( array( 'lstrike', 'wafhit', 'soft' ) as $prefix ) {
			delete_transient( self::counter_key( $prefix, $ip ) );
		}
		delete_transient( 'cu_fw_strikes_v3_' . CU_Geo::ip_key( $ip ) );
	}

	/**
	 * Limite d'écriture du journal : au plus une ligne par (IP, règle) et par
	 * minute pour les refus répétitifs. Atomique avec un cache objet persistant.
	 */
	private static function should_log( $ip, $rule ) {
		$key = 'cu_fwlog_' . CU_Geo::ip_key( (string) $ip . '|' . (string) $rule );
		if ( self::has_object_cache() ) {
			return (bool) wp_cache_add( $key, 1, 'cu_fw', MINUTE_IN_SECONDS );
		}
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Une signature de scanner doit correspondre à un NOM D'OUTIL entier dans le
	 * User-Agent, jamais à un fragment de mot (QA-MAIN-006) : « Nmap Scripting
	 * Engine », « sqlmap/1.7 », « (Nikto/2.5) », « DirBuster-1.0 » correspondent ;
	 * « Nmapper », « Hydrabad-Kids-App », « Katana-Phone » ou `x86_64` (pour `x8`)
	 * non. Avant le nom : début ou caractère non alphanumérique ; après : fin,
	 * caractère non alphanumérique autre que « - », ou « - » suivi d'un numéro de
	 * version. L'outil ffuf s'annonce « Fuzz Faster U Fool » : alias intégré.
	 */
	private static function scanner_signature_matches( $ua, $signature ) {
		if ( ! is_scalar( $signature ) ) {
			return false;
		}
		$signature = trim( (string) $signature );
		$ua        = (string) $ua;
		if ( '' === $signature || '' === $ua ) {
			return false;
		}
		$names = array( $signature );
		if ( 'ffuf' === strtolower( $signature ) ) {
			$names[] = 'Fuzz Faster U Fool';
		}
		foreach ( $names as $name ) {
			if ( false === stripos( $ua, $name ) ) {
				continue; // préfiltre linéaire : aucune expression régulière pour un UA ordinaire
			}
			if ( preg_match( '/(?<![a-z0-9])' . preg_quote( $name, '/' ) . '(?![a-z0-9]|-[a-z])/i', $ua ) ) {
				return true;
			}
		}
		return false;
	}

	/** Correspondance de sonde bornée à un segment/fichier, jamais au milieu d'un slug. */
	private static function sensitive_path_matches( $request_path, $signature ) {
		if ( ! is_scalar( $signature ) ) {
			return false;
		}
		$signature = self::normalize_path( trim( (string) $signature ) );
		if ( '/' === $signature ) {
			return false;
		}
		$offset = 0;
		while ( false !== ( $pos = stripos( $request_path, $signature, $offset ) ) ) {
			$end   = $pos + strlen( $signature );
			$after = ( $end < strlen( $request_path ) ) ? $request_path[ $end ] : '';
			if ( '' === $after || '/' === $after || '.' === $after || '/' === substr( $signature, -1 ) ) {
				return true;
			}
			if ( '.' === substr( $signature, -1 ) ) {
				$tail = substr( $request_path, $end );
				if ( preg_match( '/^(?:php\d*|bak|old|orig|save|swp|tmp|txt|zip|gz|tar|rar)(?:[.\/]|$)/i', $tail ) ) {
					return true;
				}
			}
			$offset = $pos + 1;
		}
		return false;
	}

	// -----------------------------------------------------------------------
	// Anti-hotlink (vol de bande passante des médias)
	// -----------------------------------------------------------------------

	/**
	 * Fragments de référents TOUJOURS autorisés : le domaine du site, les grands
	 * moteurs (aperçus/images) et réseaux sociaux, plus les domaines de l'utilisateur.
	 */
	private static function hotlink_allowed_fragments() {
		$own   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$frags = array(
			$own,
			'google.', 'bing.', 'yandex.', 'duckduckgo.', 'yahoo.', 'baidu.', 'ecosia.',
			'apple.', 'naver.', 'msn.', 'microsoft.', 'qwant.', 'brave.', 'startpage.',
			'facebook.', 'fbcdn.', 'instagram.', 'twitter.', 'x.com', 't.co', 'linkedin.',
			'pinterest.', 'reddit.', 'youtube.', 'googleusercontent.', 'whatsapp.', 'telegram.',
		);
		foreach ( (array) CU_Settings::get( 'firewall', 'hotlink_allowed_domains', array() ) as $d ) {
			$d = strtolower( trim( (string) $d ) );
			if ( '' !== $d ) {
				$custom_host = (string) wp_parse_url( false === strpos( $d, '://' ) ? 'https://' . $d : $d, PHP_URL_HOST );
				if ( '' !== $custom_host ) {
					$frags[] = strtolower( rtrim( $custom_host, '.' ) );
				}
			}
		}
		return array_values( array_unique( array_filter( $frags ) ) );
	}

	/** Compare un hôte réel à une entrée autorisée, sans correspondance en query string. */
	private static function hotlink_host_matches( $host, $allowed ) {
		$host    = strtolower( rtrim( (string) $host, '.' ) );
		$allowed = strtolower( trim( (string) $allowed ) );
		if ( '' === $host || '' === $allowed ) {
			return false;
		}

		// Les entrées internes finissant par un point représentent une marque avec
		// TLD local (google.fr, google.co.uk), jamais une simple sous-chaîne.
		if ( '.' === substr( $allowed, -1 ) ) {
			$brand = preg_quote( rtrim( $allowed, '.' ), '/' );
			return (bool) preg_match( '/(^|\.)' . $brand . '\.[a-z]{2,3}(\.[a-z]{2})?$/i', $host );
		}

		$allowed = rtrim( $allowed, '.' );
		return $host === $allowed || self::str_ends_with_ci( $host, '.' . $allowed );
	}

	/**
	 * Repli PHP : la requête cible-t-elle un média avec un référent externe non
	 * autorisé ? (Ne couvre que les médias qui atteignent PHP — la protection
	 * réelle des fichiers statiques est en .htaccess.)
	 */
	private static function is_hotlink_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = strtolower( (string) strtok( (string) $uri, '?' ) );

		$hit = false;
		foreach ( (array) CU_Settings::get( 'firewall', 'hotlink_extensions', array() ) as $ext ) {
			$ext = ltrim( strtolower( trim( (string) $ext ) ), '.' );
			if ( '' !== $ext && preg_match( '/\.' . preg_quote( $ext, '/' ) . '$/', $path ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return false;
		}

		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		if ( '' === $referer ) {
			return false; // accès direct (référent vide) toujours autorisé
		}
		$referer_host = (string) wp_parse_url( $referer, PHP_URL_HOST );
		foreach ( self::hotlink_allowed_fragments() as $frag ) {
			if ( self::hotlink_host_matches( $referer_host, $frag ) ) {
				return false; // référent autorisé
			}
		}
		return true; // référent externe non autorisé → hotlink
	}

	/** Un placeholder local protégé par la même règle provoquerait une boucle 302. */
	private static function hotlink_redirect_would_loop( $url ) {
		$target_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' !== $target_host && $target_host !== $home_host ) {
			return false;
		}
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		foreach ( (array) CU_Settings::get( 'firewall', 'hotlink_extensions', array() ) as $ext ) {
			$ext = ltrim( strtolower( trim( (string) $ext ) ), '.' );
			if ( '' !== $ext && preg_match( '/\.' . preg_quote( $ext, '/' ) . '$/', $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Génère le bloc de règles .htaccess (Apache) — LA méthode qui protège
	 * réellement les fichiers statiques, servis sans passer par PHP.
	 * À coller dans le .htaccess à la racine du site.
	 */
	public static function hotlink_htaccess_rules() {
		$exts = array();
		foreach ( (array) CU_Settings::get( 'firewall', 'hotlink_extensions', array() ) as $ext ) {
			$ext = ltrim( strtolower( trim( (string) $ext ) ), '.' );
			if ( '' !== $ext ) {
				$exts[] = preg_quote( $ext, '/' );
			}
		}
		if ( empty( $exts ) ) {
			$exts = array( 'jpe?g', 'png', 'gif', 'webp' );
		}
		$ext_re = implode( '|', $exts );

		$conds = '';
		foreach ( self::hotlink_allowed_fragments() as $frag ) {
			$brand_entry = '.' === substr( $frag, -1 );
			$domain      = preg_quote( rtrim( $frag, '.' ), '/' );
			$host_re     = $brand_entry
				? $domain . '\\.[A-Za-z]{2,3}(\\.[A-Za-z]{2})?'
				: $domain;
			// Ancrage sur le véritable hôte du référent : ni le chemin ni la query
			// string ne peuvent imiter un domaine autorisé.
			$conds .= 'RewriteCond %{HTTP_REFERER} !^https?://([^/]+\\.)?' . $host_re . '(:[0-9]+)?(/|$)' . " [NC]\n";
		}

		$action = CU_Settings::get( 'firewall', 'hotlink_action', 'forbidden' );
		$redir  = trim( (string) CU_Settings::get( 'firewall', 'hotlink_redirect_url', '' ) );
		if ( 'redirect' === $action && '' !== $redir ) {
			// Image de remplacement hébergée sur le site : le référent externe reste
			// identique après la redirection, la règle la viserait donc à nouveau
			// (boucle infinie). On l'exclut explicitement.
			if ( self::hotlink_redirect_would_loop( $redir ) ) {
				$redir_path = (string) wp_parse_url( $redir, PHP_URL_PATH );
				$conds     .= 'RewriteCond %{REQUEST_URI} !^' . preg_quote( $redir_path, '/' ) . "$ [NC]\n";
			}
			$rule = esc_url_raw( $redir ) . ' [R=302,NC,L]';
		} else {
			$rule = '- [F,NC,L]'; // 403 Forbidden
		}

		return "# BEGIN CyberPush Hotlink Protection\n"
			. "<IfModule mod_rewrite.c>\n"
			. "RewriteEngine On\n"
			. "RewriteCond %{HTTP_REFERER} !^$\n"
			. $conds
			. "RewriteRule \\.({$ext_re})$ {$rule}\n"
			. "</IfModule>\n"
			. "# END CyberPush Hotlink Protection";
	}

	// -----------------------------------------------------------------------
	// Contexte de la requête et état de l'utilisateur
	// -----------------------------------------------------------------------

	/**
	 * Requêtes jamais filtrées (anti-verrouillage) : crawl SEO signé du plugin,
	 * cron / WP-CLI et administrateurs connectés. Pour l'API REST, un cookie ne
	 * suffit pas : WordPress traite comme anonyme une requête sans nonce
	 * `wp_rest` valide, le pare-feu fait donc de même (voir user_state()).
	 * wp-admin, admin-ajax.php et la page de connexion ne sont PLUS exemptés en
	 * bloc : la blocklist s'y applique, avec des règles adaptées à chaque surface.
	 */
	private static function is_request_exempt() {
		if ( self::is_internal_seo_audit_request() ) {
			return true;
		}
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		$user = self::user_state();
		return $user['admin'];
	}

	/**
	 * Nature de la requête courante (mémorisée) :
	 *  - ajax       : admin-ajax.php ou admin-post.php ;
	 *  - admin_page : vraie page de wp-admin (WordPress gère l'authentification) ;
	 *  - auth       : page de connexion (wp-login.php ou URL masquée) ;
	 *  - rest       : route de l'API REST (/wp-json/… ou ?rest_route=) ;
	 *  - xmlrpc     : xmlrpc.php.
	 */
	private static function request_context() {
		if ( null !== self::$ctx ) {
			return self::$ctx;
		}
		$is_ajax    = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		$in_admin   = function_exists( 'is_admin' ) && is_admin();
		$admin_post = $in_admin && ! $is_ajax && self::str_ends_with_ci( untrailingslashit( self::request_path() ), '/admin-post.php' );
		$ajax       = $is_ajax || $admin_post;
		self::$ctx  = array(
			'ajax'       => $ajax,
			'admin_page' => $in_admin && ! $ajax,
			'auth'       => ! $in_admin && ! $ajax && self::is_auth_request(),
			'rest'       => '' !== self::current_rest_route(),
			'xmlrpc'     => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
		);
		return self::$ctx;
	}

	/**
	 * Utilisateur tel que WordPress le traitera : pour une requête REST, un
	 * cookie de session n'est pris en compte qu'avec un nonce `wp_rest` valide
	 * (en-tête X-WP-Nonce ou paramètre _wpnonce). Sinon un simple cookie
	 * d'abonné désactiverait le WAF du corps et le durcissement REST alors que
	 * WordPress traite ensuite la requête comme anonyme.
	 *
	 * @return array{logged_in:bool, admin:bool, editor:bool}
	 */
	private static function user_state() {
		if ( null !== self::$user_state ) {
			return self::$user_state;
		}
		$state = array( 'logged_in' => false, 'admin' => false, 'editor' => false );
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$ctx   = self::request_context();
			$valid = $ctx['rest'] ? self::has_valid_rest_nonce() : true;
			if ( $valid ) {
				$state['logged_in'] = true;
				$state['admin']     = current_user_can( 'manage_options' );
				$state['editor']    = $state['admin'] || current_user_can( 'edit_posts' );
			}
		}
		self::$user_state = $state;
		return $state;
	}

	/** Nonce REST valide pour l'utilisateur du cookie (même contrôle que le cœur). */
	private static function has_valid_rest_nonce() {
		$nonce = '';
		if ( isset( $_REQUEST['_wpnonce'] ) && is_string( $_REQUEST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$nonce = (string) wp_unslash( $_REQUEST['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) && is_string( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = (string) wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] );
		}
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Session administrateur par cookie (même sans nonce REST). Sert uniquement à
	 * ne JAMAIS bannir l'IP d'un administrateur : le sens prudent.
	 */
	private static function cookie_admin() {
		return function_exists( 'is_user_logged_in' ) && is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/** Route REST demandée (minuscules, décodée), ou '' si ce n'est pas une requête REST. */
	private static function current_rest_route() {
		foreach ( array( $_GET, $_POST ) as $source ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( isset( $source['rest_route'] ) && is_scalar( $source['rest_route'] ) ) {
				$route = strtolower( untrailingslashit( self::normalize_path( wp_unslash( (string) $source['rest_route'] ) ) ) );
				return '' === $route ? '/' : $route;
			}
		}
		$prefix = '/' . strtolower( trim( function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json', '/' ) );
		$path   = strtolower( self::request_path() );
		if ( $path === $prefix ) {
			return '/';
		}
		$pos = strpos( $path . '/', $prefix . '/' );
		if ( false === $pos ) {
			return '';
		}
		$route = untrailingslashit( substr( $path, $pos + strlen( $prefix ) ) );
		return '' === $route ? '/' : $route;
	}

	/** User-Agent brut (borné) de la requête courante. */
	private static function current_ua() {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) || ! is_string( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		return trim( substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 512 ) );
	}

	/** Méthode HTTP (lettres uniquement, majuscules). */
	private static function request_method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		return strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $method ), 0, 16 ) );
	}

	/** Méthodes HTTP refusées (réglage). */
	private static function blocked_methods() {
		$out = array();
		foreach ( (array) CU_Settings::get( 'firewall', 'blocked_http_methods', array( 'TRACE', 'TRACK' ) ) as $method ) {
			if ( is_scalar( $method ) && '' !== trim( (string) $method ) ) {
				$out[] = strtoupper( trim( (string) $method ) );
			}
		}
		return $out;
	}

	/**
	 * Détecte le flux d'authentification, y compris lorsque l'URL de connexion
	 * est masquée par une extension. `wp_login_url()` applique le filtre
	 * WordPress utilisé par ces extensions ; on compare donc son chemin à la
	 * requête courante plutôt que de ne reconnaître que wp-login.php.
	 *
	 * Le filtre permet de couvrir un flux 2FA particulièrement exotique sans
	 * modifier le plugin : add_filter( 'cu_firewall_is_auth_request', ... ).
	 */
	private static function is_auth_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = untrailingslashit( self::request_path() );

		// Toujours prendre en charge l'URL WordPress native, même si une
		// extension a remplacé wp_login_url().
		$native_login_path = untrailingslashit( self::normalize_path( (string) wp_parse_url( site_url( 'wp-login.php', 'login' ), PHP_URL_PATH ) ) );
		if ( strtolower( $path ) === strtolower( $native_login_path ) ) {
			return true;
		}

		foreach ( array( wp_login_url(), wp_lostpassword_url() ) as $auth_url ) {
			$auth_path = wp_parse_url( $auth_url, PHP_URL_PATH );
			$auth_path = untrailingslashit( self::normalize_path( (string) $auth_path ) );
			if ( '/' !== $auth_path && $path === $auth_path ) {
				return true;
			}
		}

		return (bool) apply_filters( 'cu_firewall_is_auth_request', false, $path, $uri );
	}

	/**
	 * En mode maintenance, requêtes qui restent autorisées : administrateurs,
	 * page de connexion, wp-admin (WordPress y gère l'authentification), cron /
	 * WP-CLI, robots.txt et sitemaps. Aucun verrouillage de l'administrateur.
	 */
	private static function is_maintenance_exempt() {
		$user = self::user_state();
		if ( $user['admin'] ) {
			return true;
		}
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		if ( is_admin() ) {
			return true;
		}
		$path      = self::request_path();
		$cron_path = self::normalize_path( (string) wp_parse_url( site_url( 'wp-cron.php' ), PHP_URL_PATH ) );
		if ( self::is_auth_request() || strtolower( $path ) === strtolower( $cron_path ) ) {
			return true;
		}
		// Les fichiers de pilotage des moteurs doivent rester disponibles : un 5xx
		// sur robots.txt peut suspendre temporairement toute exploration du site.
		if ( self::is_seo_control_path( $path ) ) {
			return true;
		}
		return false;
	}

	// -----------------------------------------------------------------------
	// Adresses jamais bannies (non publiques, serveur, relais)
	// -----------------------------------------------------------------------

	/**
	 * Adresse d'infrastructure : privée/réservée/loopback, adresse du serveur
	 * lui-même, proxy de confiance déclaré ou relais Cloudflare. La bannir (ou
	 * la limiter) bloquerait tout le site si l'IP visiteur est mal résolue.
	 */
	private static function is_infra_ip( $ip ) {
		static $memo = array();
		$ip = CU_Geo::normalize_ip( $ip );
		if ( isset( $memo[ $ip ] ) ) {
			return $memo[ $ip ];
		}
		$infra = '' === $ip || ! CU_Geo::is_public_ip( $ip ) || in_array( $ip, self::server_ips(), true );
		if ( ! $infra ) {
			foreach ( CU_Geo::trusted_proxies() as $cidr ) {
				if ( CU_Geo::ip_in_cidr( $ip, $cidr ) ) {
					$infra = true;
					break;
				}
			}
		}
		// Prise en charge automatique si CU_Geo expose un jour ce test publiquement.
		if ( ! $infra && is_callable( array( 'CU_Geo', 'is_cloudflare_ip' ) ) ) {
			$infra = (bool) call_user_func( array( 'CU_Geo', 'is_cloudflare_ip' ), $ip );
		}
		// Relais de l'hébergeur pas encore approuvé : tous les visiteurs portent son
		// adresse ; le bannir couperait le site entier.
		if ( ! $infra ) {
			$infra = isset( self::admin_relays()[ $ip ] );
		}
		if ( count( $memo ) > 50 ) {
			$memo = array();
		}
		$memo[ $ip ] = $infra;
		return $infra;
	}

	/**
	 * Relais publics (proxy / CDN de l'hébergeur) par lesquels arrivent les
	 * requêtes d'un administrateur connecté, sans être encore approuvés.
	 *
	 * @return array<string,int> Adresse => dernière observation (timestamp).
	 */
	private static function admin_relays() {
		static $memo = null;
		if ( null === $memo ) {
			$stored = get_option( self::ADMIN_RELAYS_KEY, array() );
			$memo   = is_array( $stored ) ? $stored : array();
		}
		return $memo;
	}

	/**
	 * Échecs de connexion de l'adresse dans la fenêtre en cours (lecture seule,
	 * pour la section « Protection des connexions » ; aucun effet sur les verrous).
	 */
	public static function login_failures_for_ip( $ip ) {
		$ip = CU_Geo::normalize_ip( $ip );
		return '' === $ip ? 0 : (int) self::fixed_window_counter_value( self::counter_key( 'login', $ip ), self::counter_window( 'login' ) );
	}

	/** L'adresse est-elle un relais vu sur une requête d'administrateur (non approuvé) ? */
	public static function is_admin_relay( $ip ) {
		$ip = CU_Geo::normalize_ip( $ip );
		return '' !== $ip && isset( self::admin_relays()[ $ip ] );
	}

	/**
	 * Mémorise le relais observé sur une requête d'administrateur authentifiée
	 * (voir CU_Geo::untrusted_proxy_hint()). Seul un administrateur connecté peut
	 * l'inscrire : un visiteur qui forge des en-têtes ne s'en sert jamais. Il
	 * n'est pas « de confiance » (ses en-têtes restent ignorés) : il n'est
	 * simplement jamais banni ni limité. 10 relais au plus, oubliés après 60 jours.
	 */
	public static function remember_admin_relay( $ip ) {
		$ip = CU_Geo::normalize_ip( $ip );
		if ( '' === $ip || ! CU_Geo::is_public_ip( $ip ) ) {
			return;
		}
		$relays = self::admin_relays();
		if ( isset( $relays[ $ip ] ) && time() - (int) $relays[ $ip ] < DAY_IN_SECONDS ) {
			return;
		}
		$relays[ $ip ] = time();
		$relays        = array_filter( $relays, function ( $seen ) {
			return time() - (int) $seen < 60 * DAY_IN_SECONDS;
		} );
		arsort( $relays );
		update_option( self::ADMIN_RELAYS_KEY, array_slice( $relays, 0, 10, true ), true );
	}

	/**
	 * Adresses du serveur : SERVER_ADDR / LOCAL_ADDR, plus la résolution du nom
	 * d'hôte du site mise en cache par la maintenance (jamais de DNS ici).
	 */
	private static function server_ips() {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$ips = array();
		foreach ( array( 'SERVER_ADDR', 'LOCAL_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				$ips[] = (string) $_SERVER[ $key ];
			}
		}
		$cached = get_transient( self::SERVER_IPS_KEY );
		if ( is_array( $cached ) && isset( $cached['ips'] ) && is_array( $cached['ips'] ) ) {
			$ips = array_merge( $ips, $cached['ips'] );
		}
		$out = array();
		foreach ( (array) apply_filters( 'cu_firewall_server_ips', $ips ) as $candidate ) {
			$normalized = CU_Geo::normalize_ip( is_scalar( $candidate ) ? (string) $candidate : '' );
			if ( '' !== $normalized ) {
				$out[ $normalized ] = true;
			}
		}
		$memo = array_keys( $out );
		return $memo;
	}

	/** Résout (en tâche de fond) le nom d'hôte du site ; cache 12 h, autochargé. */
	public static function refresh_server_ips( $force = false ) {
		$cached = get_transient( self::SERVER_IPS_KEY );
		if ( ! $force && is_array( $cached ) && isset( $cached['t'] ) && ( time() - (int) $cached['t'] ) < 12 * HOUR_IN_SECONDS ) {
			return;
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$ips  = array();
		if ( '' !== $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( function_exists( 'gethostbynamel' ) ) {
				foreach ( (array) @gethostbynamel( $host ) as $resolved ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$ips[] = CU_Geo::normalize_ip( $resolved );
				}
			}
			if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
				foreach ( (array) @dns_get_record( $host, DNS_AAAA ) as $record ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( isset( $record['ipv6'] ) ) {
						$ips[] = CU_Geo::normalize_ip( $record['ipv6'] );
					}
				}
			}
		} elseif ( '' !== $host ) {
			$ips[] = CU_Geo::normalize_ip( $host );
		}
		$ips = array_values( array_unique( array_filter( $ips ) ) );
		set_transient( self::SERVER_IPS_KEY, array( 't' => time(), 'ips' => array_slice( $ips, 0, 16 ) ) );
	}

	/** Signale (au plus toutes les 6 h) une détection visant une adresse protégée. */
	private static function warn_infra_ip_once( $ip ) {
		if ( get_transient( 'cu_fw_infra_notice' ) ) {
			return;
		}
		set_transient( 'cu_fw_infra_notice', 1, 6 * HOUR_IN_SECONDS );
		CU_Logger::log( 'firewall', 'warning', __( 'Une détection concerne une adresse non publique, un relais de confiance ou le serveur lui-même : aucun verrou ni bannissement n’est appliqué. Si tous les visiteurs apparaissent avec cette adresse, réglez la « Source de l’IP visiteur » (Réglages ▸ Firewall).', 'cyberpush-ultra' ), array( 'ip' => $ip ) );
	}

	// -----------------------------------------------------------------------
	// Whitelist et blocages : recherches indexées + cache des plages CIDR
	// -----------------------------------------------------------------------

	/**
	 * Invalide le cache des listes (à appeler après TOUTE écriture dans la
	 * whitelist ou dans un blocage CIDR). La version est un transient sans
	 * expiration (autochargé) : la vérifier ne coûte aucune requête SQL.
	 */
	public static function flush_list_cache() {
		set_transient( self::LIST_VERSION_KEY, uniqid( '', true ) );
		self::$cidr_lists = null;
		self::$exact_memo = array();
	}

	/**
	 * Plages CIDR actives (whitelist et blocages) et, si elle est raisonnable
	 * (≤ 500 entrées), la whitelist complète. Reconstruites au plus toutes les
	 * 10 minutes ou dès que la version change.
	 *
	 * @return array{v:string, t:int, wl_all:bool, wl:array, bl:array}
	 */
	private static function cidr_lists() {
		if ( null !== self::$cidr_lists ) {
			return self::$cidr_lists;
		}
		$version = (string) get_transient( self::LIST_VERSION_KEY );
		$cached  = get_transient( self::LIST_CACHE_KEY );
		$now_ts  = time();
		if ( is_array( $cached ) && isset( $cached['v'], $cached['t'], $cached['wl'], $cached['bl'] )
			&& (string) $cached['v'] === $version && (int) $cached['t'] <= $now_ts && ( $now_ts - (int) $cached['t'] ) < 10 * MINUTE_IN_SECONDS ) {
			self::$cidr_lists = $cached;
			return $cached;
		}

		global $wpdb;
		$now     = current_time( 'mysql' );
		$wl_t    = CU_DB::table( 'firewall_whitelist' );
		$bl_t    = CU_DB::table( 'firewall_blocks' );
		$empty   = array( 'v' => '', 't' => 0, 'wl_all' => false, 'wl' => array(), 'bl' => array() );
		$wl_rows = $wpdb->get_results( $wpdb->prepare( "SELECT ip_or_cidr, expires_at FROM {$wl_t} WHERE expires_at IS NULL OR expires_at > %s ORDER BY id ASC LIMIT 501", $now ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->last_error || ! is_array( $wl_rows ) ) {
			self::log_db_failure_once( 'whitelist_read' );
			self::$cidr_lists = $empty;
			return $empty;
		}
		$wl_all = count( $wl_rows ) <= 500;
		if ( ! $wl_all ) {
			$wl_rows = $wpdb->get_results( $wpdb->prepare( "SELECT ip_or_cidr, expires_at FROM {$wl_t} WHERE ip_or_cidr LIKE %s AND ( expires_at IS NULL OR expires_at > %s )", '%/%', $now ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$bl_rows = $wpdb->get_results( $wpdb->prepare( "SELECT ip_or_cidr, expires_at, type FROM {$bl_t} WHERE ip_or_cidr LIKE %s AND ( expires_at IS NULL OR expires_at > %s )", '%/%', $now ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->last_error || ! is_array( $wl_rows ) || ! is_array( $bl_rows ) ) {
			self::log_db_failure_once( 'blocked_list_read' );
			self::$cidr_lists = $empty;
			return $empty;
		}

		$data = array( 'v' => $version, 't' => $now_ts, 'wl_all' => $wl_all, 'wl' => array(), 'bl' => array() );
		foreach ( $wl_rows as $row ) {
			$data['wl'][] = array( (string) $row['ip_or_cidr'], null === $row['expires_at'] ? null : (string) $row['expires_at'] );
		}
		foreach ( $bl_rows as $row ) {
			$data['bl'][] = array( (string) $row['ip_or_cidr'], null === $row['expires_at'] ? null : (string) $row['expires_at'], (string) $row['type'] );
		}
		set_transient( self::LIST_CACHE_KEY, $data );
		self::$cidr_lists = $data;
		return $data;
	}

	/** Correspondance d'une IP normalisée avec une entrée canonique (IP ou CIDR). */
	private static function entry_matches( $ip, $entry ) {
		$entry = (string) $entry;
		if ( false === strpos( $entry, '/' ) ) {
			return $ip === $entry || $ip === CU_Geo::normalize_ip( $entry );
		}
		return CU_Geo::ip_in_cidr( $ip, $entry );
	}

	/**
	 * Vérifie si une IP est dans la whitelist (entrées non expirées, exactes ou CIDR).
	 */
	private static function is_whitelisted( $ip ) {
		static $memo = array();
		$ip = CU_Geo::normalize_ip( $ip );
		if ( '' === $ip ) {
			return false;
		}
		$lists   = self::cidr_lists();
		$memo_id = $ip . '|' . $lists['v'] . '|' . $lists['t'];
		if ( isset( $memo[ $memo_id ] ) ) {
			return $memo[ $memo_id ];
		}
		$now    = current_time( 'mysql' );
		$result = false;
		foreach ( $lists['wl'] as $entry ) {
			if ( ( null === $entry[1] || $entry[1] > $now ) && self::entry_matches( $ip, $entry[0] ) ) {
				$result = true;
				break;
			}
		}
		if ( ! $result && empty( $lists['wl_all'] ) ) {
			global $wpdb;
			$table  = CU_DB::table( 'firewall_whitelist' );
			$result = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE ip_or_cidr = %s AND ( expires_at IS NULL OR expires_at > %s ) LIMIT 1", $ip, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( count( $memo ) > 50 ) {
			$memo = array();
		}
		$memo[ $memo_id ] = $result;
		return $result;
	}

	/** Ligne de blocage EXACTE d'une IP (requête indexée sur la clé unique), mémorisée. */
	private static function exact_block_row( $ip ) {
		if ( array_key_exists( $ip, self::$exact_memo ) ) {
			return self::$exact_memo[ $ip ];
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_blocks' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT ip_or_cidr, expires_at, type FROM {$table} WHERE ip_or_cidr = %s LIMIT 1", $ip ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->last_error ) {
			self::log_db_failure_once( 'blocked_exact_read' );
			$row = null;
		}
		if ( count( self::$exact_memo ) > 50 ) {
			self::$exact_memo = array();
		}
		self::$exact_memo[ $ip ] = is_array( $row ) ? $row : null;
		return self::$exact_memo[ $ip ];
	}

	/**
	 * Blocage actif couvrant une IP (exact puis CIDR), ou null.
	 *
	 * @param bool $include_login Inclure les verrous de connexion (type « login »).
	 * @return array{ip_or_cidr:string, expires_at:?string, type:string}|null
	 */
	private static function find_block( $ip, $include_login = false ) {
		$ip = CU_Geo::normalize_ip( $ip );
		if ( '' === $ip ) {
			return null;
		}
		$now = current_time( 'mysql' );
		$row = self::exact_block_row( $ip );
		if ( $row && ( null === $row['expires_at'] || $row['expires_at'] > $now ) && ( $include_login || 'login' !== $row['type'] ) ) {
			return $row;
		}
		$lists = self::cidr_lists();
		foreach ( $lists['bl'] as $entry ) {
			if ( ( null === $entry[1] || $entry[1] > $now ) && ( $include_login || 'login' !== $entry[2] ) && self::entry_matches( $ip, $entry[0] ) ) {
				return array( 'ip_or_cidr' => $entry[0], 'expires_at' => $entry[1], 'type' => $entry[2] );
			}
		}
		return null;
	}

	/**
	 * Vérifie si une IP est activement bloquée (hors verrous de connexion).
	 * $track_hit = true : compte une requête réellement refusée.
	 */
	private static function is_blocked( $ip, $track_hit = true ) {
		$block = self::find_block( $ip );
		if ( $block && $track_hit ) {
			self::track_block_hit( $block['ip_or_cidr'] );
		}
		return null !== $block;
	}

	/** Une requête refusée par un blocage existant : compteur de hits + dernière vue. */
	private static function track_block_hit( $ip_or_cidr ) {
		global $wpdb;
		$table   = CU_DB::table( 'firewall_blocks' );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET hits = hits + 1, last_seen = %s WHERE ip_or_cidr = %s",
			current_time( 'mysql' ),
			$ip_or_cidr
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $updated ) {
			self::log_db_failure_once( 'blocked_hit_update' );
		}
	}

	/**
	 * Crée ou met à jour un blocage en UNE requête atomique (clé unique).
	 *
	 * Options :
	 *  - explicit      : choix de l'administrateur (type, durée, raison appliqués tels quels) ;
	 *  - never_shorten : chemin automatique — un blocage permanent reste permanent,
	 *                    sinon la date de fin la plus lointaine est conservée ; un
	 *                    blocage manuel actif garde son type et sa raison, et un
	 *                    verrou de connexion ne rétrograde jamais un bannissement ;
	 *  - comment, country, created_by, hits.
	 *
	 * @param string      $target     IP ou CIDR canonique.
	 * @param string      $type       auto | manual | login
	 * @param string|null $expires_at Date MySQL locale, ou null (permanent).
	 */
	private static function persist_block( $target, $type, $reason, $expires_at, array $opts = array() ) {
		global $wpdb;
		$opts = array_merge(
			array( 'explicit' => false, 'never_shorten' => true, 'comment' => null, 'country' => '', 'created_by' => 0, 'hits' => 1 ),
			$opts
		);
		$table   = CU_DB::table( 'firewall_blocks' );
		$now     = current_time( 'mysql' );
		$type    = in_array( $type, array( 'auto', 'manual', 'login' ), true ) ? $type : 'auto';
		$country = CU_Geo::normalize_country( (string) $opts['country'] );

		$cols = array( '%s', '%s', '%s' );
		$args = array( (string) $target, $type, CU_Text::substr( (string) $reason, 0, 255 ) );
		if ( null === $opts['comment'] ) {
			$cols[] = 'NULL';
		} else {
			$cols[] = '%s';
			$args[] = (string) $opts['comment'];
		}
		if ( '' === $country ) {
			$cols[] = 'NULL';
		} else {
			$cols[] = '%s';
			$args[] = $country;
		}
		$cols[] = '%d';
		$args[] = max( 0, (int) $opts['hits'] );
		$cols[] = '%s';
		$args[] = $now;
		$cols[] = '%s';
		$args[] = $now;
		if ( null === $expires_at ) {
			$cols[] = 'NULL';
		} else {
			$cols[] = '%s';
			$args[] = (string) $expires_at;
		}
		if ( (int) $opts['created_by'] > 0 ) {
			$cols[] = '%d';
			$args[] = (int) $opts['created_by'];
		} else {
			$cols[] = 'NULL';
		}

		if ( $opts['explicit'] ) {
			$update = 'last_seen = VALUES(last_seen), reason = VALUES(reason), comment = VALUES(comment), type = VALUES(type), expires_at = VALUES(expires_at), created_by = VALUES(created_by)';
		} else {
			// MySQL évalue ces affectations de gauche à droite : `reason` et `type`
			// lisent encore l'ancienne date de fin, modifiée en dernier.
			$keep   = "( expires_at IS NULL OR expires_at > %s ) AND ( type = 'manual' OR ( type = 'auto' AND VALUES(type) = 'login' ) )";
			$update = 'hits = hits + VALUES(hits), last_seen = VALUES(last_seen), '
				. "reason = IF( {$keep}, reason, VALUES(reason) ), "
				. "type = IF( {$keep}, type, VALUES(type) ), "
				. ( null === $expires_at ? 'expires_at = NULL' : 'expires_at = IF( expires_at IS NULL, NULL, GREATEST( expires_at, VALUES(expires_at) ) )' );
			$args[] = $now;
			$args[] = $now;
		}

		$sql = "INSERT INTO {$table} (ip_or_cidr, type, reason, comment, country_code, hits, first_seen, last_seen, expires_at, created_by) VALUES ("
			. implode( ', ', $cols ) . ") ON DUPLICATE KEY UPDATE {$update}";
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		unset( self::$exact_memo[ (string) $target ] );
		if ( false !== strpos( (string) $target, '/' ) ) {
			self::flush_list_cache();
		}
		return false !== $result;
	}

	/**
	 * Forme canonique d'une IP ou d'un CIDR saisi : IPv6 compressée en minuscules,
	 * IPv4-mappée ramenée à l'IPv4, bits d'hôte d'un CIDR mis à zéro (un /32 ou
	 * un /128 redevient une IP simple). '' si invalide.
	 */
	public static function canonical_ip_or_cidr( $value ) {
		$norm = CU_Geo::normalize_ip_or_cidr( (string) $value );
		if ( '' === $norm || false === strpos( $norm, '/' ) ) {
			return $norm;
		}
		list( $ip, $bits ) = explode( '/', $norm, 2 );
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $bin ) {
			return '';
		}
		$bits = (int) $bits;
		$len  = strlen( $bin );
		$out  = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$keep = max( 0, min( 8, $bits - $i * 8 ) );
			$mask = $keep >= 8 ? 0xff : ( ( 0xff << ( 8 - $keep ) ) & 0xff );
			$out .= chr( ord( $bin[ $i ] ) & $mask );
		}
		$network = CU_Geo::normalize_ip( (string) @inet_ntop( $out ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( '' === $network ) {
			return '';
		}
		return $bits === $len * 8 ? $network : $network . '/' . $bits;
	}

	/** Valide une IP exacte ou un CIDR IPv4/IPv6 avec un masque cohérent. */
	private static function is_valid_ip_or_cidr( $value ) {
		return is_scalar( $value ) && '' !== CU_Geo::normalize_ip_or_cidr( (string) $value );
	}

	/** Vérifie si $ip correspond à $entry (IP exacte ou CIDR), formes équivalentes comprises. */
	private static function ip_matches( $ip, $entry ) {
		$ip = CU_Geo::normalize_ip( $ip );
		return '' !== $ip && self::entry_matches( $ip, $entry );
	}

	/**
	 * Réécrit les entrées existantes des blocages et de la whitelist sous forme
	 * canonique (IPv6 compressée, IPv4-mappée, CIDR réseau). Un doublon créé par
	 * la normalisation est fusionné sans perte : la durée la plus longue
	 * (permanent prioritaire), la somme des hits et le type « manuel » sont
	 * conservés. À appeler par la migration 1.9.0 ; idempotent.
	 *
	 * @return array{updated:int, merged:int, invalid:int}
	 */
	public static function migrate_normalize_ips() {
		$report = array( 'updated' => 0, 'merged' => 0, 'invalid' => 0 );
		if ( ! CU_DB::tables_exist_for( array( 'firewall_blocks', 'firewall_whitelist' ) ) ) {
			return $report;
		}
		global $wpdb;
		foreach ( array( 'firewall_blocks', 'firewall_whitelist' ) as $short ) {
			$table   = CU_DB::table( $short );
			$last_id = 0;
			for ( $batch = 0; $batch < 1000; $batch++ ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$table} WHERE id > %d AND ( ip_or_cidr LIKE %s OR ip_or_cidr LIKE %s OR ip_or_cidr LIKE %s OR ip_or_cidr <> TRIM(ip_or_cidr) ) ORDER BY id ASC LIMIT 500",
					$last_id,
					'%:%',
					'%/%',
					'% %'
				), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $wpdb->last_error || ! is_array( $rows ) ) {
					self::log_db_failure_once( 'migrate_normalize_read' );
					break;
				}
				foreach ( $rows as $row ) {
					$last_id = (int) $row['id'];
					$canon   = self::canonical_ip_or_cidr( $row['ip_or_cidr'] );
					if ( '' === $canon ) {
						$report['invalid']++;
						continue;
					}
					if ( $canon === $row['ip_or_cidr'] ) {
						continue;
					}
					$dupe = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ip_or_cidr = %s", $canon ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( is_array( $dupe ) ) {
						$keep_exp = ( null === $row['expires_at'] || null === $dupe['expires_at'] ) ? null : max( (string) $row['expires_at'], (string) $dupe['expires_at'] );
						$update   = array( 'expires_at' => $keep_exp );
						if ( 'firewall_blocks' === $short ) {
							$update['hits'] = (int) $row['hits'] + (int) $dupe['hits'];
							if ( 'manual' === $row['type'] || 'manual' === $dupe['type'] ) {
								$update['type'] = 'manual';
							}
						}
						$merged  = $wpdb->update( $table, $update, array( 'id' => (int) $dupe['id'] ) );
						$deleted = false !== $merged ? $wpdb->delete( $table, array( 'id' => (int) $row['id'] ) ) : false;
						if ( false === $deleted ) {
							self::log_db_failure_once( 'migrate_normalize_merge' );
							continue;
						}
						$report['merged']++;
					} else {
						if ( false === $wpdb->update( $table, array( 'ip_or_cidr' => $canon ), array( 'id' => (int) $row['id'] ) ) ) {
							self::log_db_failure_once( 'migrate_normalize_update' );
							continue;
						}
						$report['updated']++;
					}
				}
				if ( count( $rows ) < 500 ) {
					break;
				}
			}
		}
		self::flush_list_cache();
		if ( $report['updated'] || $report['merged'] || $report['invalid'] ) {
			CU_Logger::log( 'firewall', 'info', __( 'Pare-feu : adresses IP des blocages et de la whitelist normalisées.', 'cyberpush-ultra' ), $report );
		}
		return $report;
	}

	// -----------------------------------------------------------------------
	// WAF par signatures
	// -----------------------------------------------------------------------

	/**
	 * Signatures du WAF. Chaque règle :
	 *  - type : sqli | xss | traversal | rce ;
	 *  - hc   : haute confiance — seule appliquée au texte libre (corps de
	 *           formulaire, `?s=` et autres paramètres de recherche/message) et à
	 *           admin-ajax anonyme ;
	 *  - sql  : appliquée à la vue SQL normalisée (commentaires retirés, espaces fusionnés) ;
	 *  - need : préfiltres (stripos) — tous requis ; une entrée tableau = l'une de ses valeurs ;
	 *  - re   : expression à quantificateurs BORNÉS (aucun retour arrière
	 *           exponentiel ; testé sur 64 Kio adverses, PCRE JIT actif et inactif).
	 *
	 * @return array<string, array{type:string, hc:bool, sql:bool, need:array, re:string}>
	 */
	private static function waf_rules() {
		static $rules = null;
		if ( null !== $rules ) {
			return $rules;
		}
		// Argument plausible d'une commande shell : option, chemin, variable, URL, IPv4.
		$arg    = '(?:-|\/|~|\.{1,2}\/|\$|[a-z]{2,10}:\/\/|\d{1,3}(?:\.\d{1,3}){3}\b)';
		$events = 'error|load|click|dblclick|mouse(?:over|out|down|up|move|enter|leave)|focus(?:in|out)?|blur|submit|toggle|animation(?:start|end|iteration)|transition(?:end|start|run)|page(?:show|hide)|begin|input|change|key(?:down|up|press)|pointer(?:over|enter|down|up|move)|wheel|scroll|resize|hashchange|message|popstate|unload|beforeunload|show|start|finish|copy|cut|paste|drag(?:start|end|over|enter|leave)?|drop|play|pause|ended|canplay(?:through)?|readystatechange|search|select|auxclick|contextmenu|beforescriptexecute|afterscriptexecute';
		$union  = 'union(?:\s+(?:all|distinct))?(?:\s+|\s*\(\s*)select\b';

		$rules = array(
			// --- Injections SQL (vue normalisée : /**/, #…\n et espaces neutralisés) ---
			// UNION SELECT dans un paramètre NON textuel (id, cat, orderby…) : jamais
			// légitime. Les champs de recherche/message n'utilisent que sqli_1.
			'sqli_0' => array( 'type' => 'sqli', 'hc' => false, 'sql' => true, 'need' => array( 'union', 'select' ),
				're' => '/\b' . $union . '/i' ),
			'sqli_1' => array( 'type' => 'sqli', 'hc' => true, 'sql' => true, 'need' => array( 'union', 'select', 'from' ),
				're' => '/(?:[\'"`)]|\b\d{1,20})\s?' . $union . '[^;]{0,200}?\bfrom\b/i' ),
			// SELECT … FROM <table sensible> : ancré sur FROM (linéaire) ; la présence
			// de SELECT dans le même segment est exigée par le préfiltre.
			'sqli_2' => array( 'type' => 'sqli', 'hc' => true, 'sql' => true, 'need' => array( 'select', 'from' ),
				're' => '/\bfrom\s(?:[`"]?[a-z0-9_$]{1,64}[`"]?\s?\.\s?)?[`"]?(?:information_schema|pg_catalog|sqlite_master|[a-z0-9]{1,24}_(?:users|usermeta|options))\b|\bfrom\s[`"]?mysql[`"]?\s?\.\s?[`"]?user\b/i' ),
			'sqli_3' => array( 'type' => 'sqli', 'hc' => true, 'sql' => true, 'need' => array( '=', array( ' or ', ' and ', '\'or', '\'and', ')or', ')and', '"or', '"and' ) ),
				're' => '/(?:[\'"\)]|=\s?-?\d{1,20})\s?\b(?:or|and)\b\s\d{1,20}\s?=\s?\d{1,20}\s?(?:--|#|;|$)/i' ),
			'sqli_4' => array( 'type' => 'sqli', 'hc' => true, 'sql' => true, 'need' => array( '\'' ),
				're' => '/\'\s?(?:or|and)\s\'?(\d{1,20})\'?\s?=\s?\'?\1(?!\d)|\'\s?or\s\'([a-z0-9]{1,10})\'\s?=\s?\'\2\'/i' ),
			'sqli_5' => array( 'type' => 'sqli', 'hc' => true, 'sql' => true, 'need' => array( array( 'sleep', 'benchmark', 'waitfor' ) ),
				're' => '/\b(?:sleep|pg_sleep)\s?\(\s?\d|\bbenchmark\s?\(\s?\d|\bwaitfor\sdelay\s\'/i' ),
			'sqli_6' => array( 'type' => 'sqli', 'hc' => true, 'sql' => true, 'need' => array( array( 'load_file', 'outfile', 'dumpfile' ) ),
				're' => '/\bload_file\s?\(|\binto\s(?:out|dump)file\b/i' ),
			'sqli_7' => array( 'type' => 'sqli', 'hc' => true, 'sql' => true, 'need' => array( array( 'updatexml', 'extractvalue' ) ),
				're' => '/\b(?:updatexml|extractvalue)\s?\(/i' ),
			// Injection booléenne/aveugle : ' AND SUBSTRING(user(),1,1)='r
			'sqli_8' => array( 'type' => 'sqli', 'hc' => false, 'sql' => true, 'need' => array( '(' ),
				're' => '/[\'"\)]\s?(?:and|or|&&|\|\|)\s(?:not\s)?(?:ascii|ord|substring|substr|mid|length|char|hex|if|ifnull|elt|make_set|database|user|current_user|version|count|exists|extractvalue|updatexml)\s?\(/i' ),
			// Requêtes empilées : ; DROP TABLE … / ; DELETE FROM …
			'sqli_9' => array( 'type' => 'sqli', 'hc' => false, 'sql' => true, 'need' => array( ';' ),
				're' => '/;\s?(?:drop|truncate|alter)\s(?:table|database)\b|;\s?(?:delete\sfrom|insert\sinto)\b|;\s?update\s[a-z0-9_`]{1,64}\sset\b/i' ),

			// --- XSS ---
			'xss_0' => array( 'type' => 'xss', 'hc' => true, 'sql' => false, 'need' => array( '<script' ),
				're' => '/<script(?:[\s\/>]|$)/i' ),
			// javascript: suivi d'un appel, d'une propriété ou d'une affectation (tabulations tolérées).
			'xss_1' => array( 'type' => 'xss', 'hc' => true, 'sql' => false, 'need' => array( ':' ),
				're' => '/\bj[\t\r\n]{0,4}a[\t\r\n]{0,4}v[\t\r\n]{0,4}a[\t\r\n]{0,4}s[\t\r\n]{0,4}c[\t\r\n]{0,4}r[\t\r\n]{0,4}i[\t\r\n]{0,4}p[\t\r\n]{0,4}t\s{0,8}:\s{0,8}(?:[a-z_$][\w$]{0,30}\s{0,4}[.(\[=`]|[(\[`\'"])/i' ),
			// Gestionnaire d'événement DANS une balise : <body onpageshow=…>, <svg/onload=…>.
			'xss_2' => array( 'type' => 'xss', 'hc' => true, 'sql' => false, 'need' => array( '<', 'on' ),
				're' => '/<[a-z][^<>]{0,250}?[\s\/"\'`]on[a-z]{3,25}\s{0,8}=/i' ),
			// Sortie d'attribut : " onfocusin="… ou ' autofocus onfocus='…
			'xss_3' => array( 'type' => 'xss', 'hc' => true, 'sql' => false, 'need' => array( 'on', '=' ),
				're' => '/["\'`](?:[^"\'`<>=]{0,60}?[\s\/])?on(?:' . $events . ')\s{0,8}=/i' ),
			'xss_4' => array( 'type' => 'xss', 'hc' => false, 'sql' => false, 'need' => array( '<' ),
				're' => '/<(?:iframe|frame|frameset|embed|object|applet|base)(?:[\s\/>]|$)/i' ),
			'xss_5' => array( 'type' => 'xss', 'hc' => false, 'sql' => false, 'need' => array( array( 'document', 'fromcharcode', 'alert', 'prompt', 'confirm' ) ),
				're' => '/\bdocument\s{0,4}\.\s{0,4}(?:cookie\b|domain\b|write(?:ln)?\s{0,4}\()|\bstring\s{0,4}\.\s{0,4}fromcharcode\s{0,4}\(|\b(?:alert|prompt|confirm)\s{0,4}\(\s{0,4}(?:[\d\'"`]|document\b|window\b)/i' ),
			'xss_6' => array( 'type' => 'xss', 'hc' => false, 'sql' => false, 'need' => array( 'data' ),
				're' => '/\bdata\s{0,4}:\s{0,4}(?:text\/html|image\/svg\+xml|application\/(?:x-)?javascript)\s{0,4}[;,]/i' ),

			// --- Traversée de répertoire / LFI / XXE ---
			'traversal_0' => array( 'type' => 'traversal', 'hc' => true, 'sql' => false, 'need' => array( '..' ),
				're' => '/(?:\.\.[\/\\\\]){2}/' ),
			'traversal_1' => array( 'type' => 'traversal', 'hc' => false, 'sql' => false, 'need' => array( 'etc' ),
				're' => '/\/etc\/(?:passwd|shadow|hosts|group|issue)(?=$|[\/?&#.\s])/i' ),
			'traversal_2' => array( 'type' => 'traversal', 'hc' => false, 'sql' => false, 'need' => array( 'proc' ),
				're' => '/\/proc\/self\/(?:environ|cmdline|maps|status|fd\/)/i' ),
			'traversal_3' => array( 'type' => 'traversal', 'hc' => false, 'sql' => false, 'need' => array( '://' ),
				're' => '/\b(?:php|file|data|expect|zip|phar|glob|zlib|compress\.zlib|compress\.bzip2)\s{0,4}:\/\//i' ),
			'traversal_4' => array( 'type' => 'traversal', 'hc' => false, 'sql' => false, 'need' => array( '.ini' ),
				're' => '/\b(?:boot|win|system)\.ini(?=$|[\/?&#.\s])/i' ),
			'traversal_5' => array( 'type' => 'traversal', 'hc' => true, 'sql' => false, 'need' => array( 'wp-config' ),
				're' => '/(?:^|[\/\\\\=])wp-config\.php(?=$|[\/?&#.\s~])/i' ),
			'traversal_6' => array( 'type' => 'traversal', 'hc' => true, 'sql' => false, 'need' => array( '<!', array( 'entity', 'system' ) ),
				're' => '/<!entity\b|<!doctype[^>]{0,64}?\bsystem\b/i' ),

			// --- Exécution de commandes / de code ---
			// Une vraie commande exige un argument (option, chemin, URL, IP) :
			// « name|id » ou « red|sh » dans un paramètre normal ne déclenchent rien.
			'rce_0' => array( 'type' => 'rce', 'hc' => false, 'sql' => false, 'need' => array( ';' ),
				're' => '/;\s{0,5}(?:whoami\b|uname\s{1,5}-[a-z]|id\s{0,5}(?:;|$)|(?:cat|ls|wget|curl|bash|sh|nc|ncat|ping|chmod|rm|kill|nslookup|perl|python[23]?)\s{1,5}' . $arg . ')/i' ),
			'rce_1' => array( 'type' => 'rce', 'hc' => false, 'sql' => false, 'need' => array( '|' ),
				're' => '/\|\|?\s{0,5}(?:whoami\b|uname\s{1,5}-[a-z]|(?:cat|ls|id|wget|curl|bash|sh|nc|ncat|ping|nslookup|perl|python[23]?)\s{1,5}' . $arg . ')/i' ),
			'rce_2' => array( 'type' => 'rce', 'hc' => false, 'sql' => false, 'need' => array( array( '`', '$(' ) ),
				're' => '/`\s{0,5}(?:cat|ls|id|whoami|uname|wget|curl|bash|sh|nc|ncat|ping|chmod|rm|sleep|nslookup|perl|python\d?)\b[^`]{0,200}`|\$\(\s{0,5}(?:cat|ls|id|whoami|uname|wget|curl|bash|sh|nc|ncat|ping|chmod|rm|sleep|nslookup|perl|python\d?|echo)\b[^)]{0,200}\)/i' ),
			'rce_3' => array( 'type' => 'rce', 'hc' => false, 'sql' => false, 'need' => array( '(' ),
				're' => '/\b(?:passthru|shell_exec|popen|proc_open|pcntl_exec)\s{0,4}\(/i' ),
			'rce_4' => array( 'type' => 'rce', 'hc' => false, 'sql' => false, 'need' => array( '(' ),
				're' => '/\b(?:system|exec|eval|assert|create_function)\s{0,4}\(\s{0,4}(?:[\'"`$]|[a-z_][\w\\\\]{0,40}\s{0,4}\()/i' ),
			'rce_5' => array( 'type' => 'rce', 'hc' => false, 'sql' => false, 'need' => array( '(' ),
				're' => '/\b(?:base64_decode|gzinflate|str_rot13|gzuncompress|gzdecode)\s{0,4}\(/i' ),
			'rce_6' => array( 'type' => 'rce', 'hc' => false, 'sql' => false, 'need' => array( '<?' ),
				're' => '/<\?(?:php\b|=)/i' ),
			// JNDI / Log4Shell (y compris ${${lower:j}ndi:…}).
			'rce_7' => array( 'type' => 'rce', 'hc' => true, 'sql' => false, 'need' => array( array( 'jndi', '${' ) ),
				're' => '/\$\{\s{0,4}jndi\s{0,4}:|\bjndi\s{0,4}:\s{0,4}(?:ldaps?|rmi|dns|iiop|nis|corba|https?)\b|\$\{\s{0,4}\$\{\s{0,4}(?:lower|upper|env|sys|date|::-)/i' ),
		);
		return $rules;
	}

	/**
	 * WAF par signatures : inspecte les paramètres d'URL (clés comprises), le
	 * chemin (traversée uniquement) et, pour les visiteurs anonymes, le corps
	 * (formulaire, JSON au sens de WordPress, PUT/PATCH/DELETE). Les valeurs
	 * longues sont analysées par segments bornés qui se recouvrent (début et fin
	 * d'abord), dans un budget global d'octets par requête.
	 *
	 * @param string $mode front | ajax (ajax : signatures de haute confiance uniquement).
	 * @return array{type:string, rule:string, sample:string, source:string, error?:string}|false
	 */
	private static function detect_malicious_payload( $mode = 'front' ) {
		$items = array();
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::waf_collect( wp_unslash( $_GET ), 'query', $items ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// Chemin brut conservé séparément : `normalize_path()` résout les
		// dot-segments, ce qui effacerait la preuve d'une traversée ../ encodée.
		// Seules les signatures de traversée s'y appliquent (un slug éditorial
		// contenant des mots SQL/JS ne doit jamais être refusé).
		$raw_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$raw_path = wp_parse_url( $raw_uri, PHP_URL_PATH );
		$path_src = ( is_string( $raw_path ) && '' !== $raw_path && '/' !== $raw_path ) ? self::decode_waf_source( substr( $raw_path, 0, 8192 ) ) : '';

		// Le beacon Analytics transporte du texte libre (erreurs JS du thème…)
		// entièrement assaini côté serveur : l'analyser bannirait des visiteurs.
		$user = self::user_state();
		if ( CU_Settings::get( 'firewall', 'waf_scan_post', true ) && ! $user['logged_in'] && ! self::is_analytics_beacon_request() ) {
			self::waf_body_items( $items );
			if ( 'front' === $mode ) {
				$items = self::without_cf7_text_fields( $items );
			}
		}

		$texts = array();
		foreach ( array_slice( $items, 0, 200 ) as $item ) {
			$texts[] = substr( (string) $item['k'], 0, 1024 ) . '=' . (string) $item['v'];
		}
		if ( apply_filters( 'cu_waf_skip', false, $texts ) ) {
			return false;
		}

		if ( '' !== $path_src && 'ajax' !== $mode ) {
			$hit = self::waf_scan_string( $path_src, 'path', 'traversal' );
			if ( $hit ) {
				return $hit;
			}
		}
		return self::waf_scan_items( $items, 'ajax' === $mode );
	}

	/**
	 * Aplati itérativement (sans récursion) des données de requête en éléments
	 * { k: chemin de clé, v: valeur scalaire, src }. Bornes : profondeur 32,
	 * WAF_MAX_ITEMS valeurs, 4 × WAF_MAX_ITEMS nœuds visités.
	 */
	private static function waf_collect( $data, $src, array &$items ) {
		$stack = array( array( '', $data, 0 ) );
		$nodes = 0;
		$limit = 4 * self::WAF_MAX_ITEMS;
		while ( ! empty( $stack ) && count( $items ) < self::WAF_MAX_ITEMS && $nodes < $limit ) {
			list( $prefix, $value, $depth ) = array_pop( $stack );
			if ( is_array( $value ) ) {
				if ( $depth >= 32 ) {
					continue;
				}
				foreach ( $value as $k => $v ) {
					if ( ++$nodes >= $limit ) {
						break;
					}
					$key     = '' === $prefix ? (string) $k : $prefix . '[' . $k . ']';
					$stack[] = array( substr( $key, 0, 1024 ), $v, $depth + 1 );
				}
				continue;
			}
			if ( is_scalar( $value ) ) {
				$items[] = array( 'k' => $prefix, 'v' => is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value, 'src' => $src );
			}
		}
	}

	/**
	 * Corps de requête : $_POST, JSON (application/json ou suffixe +json, comme
	 * WordPress) quelle que soit la méthode, et formulaire urlencodé envoyé en
	 * PUT/PATCH/DELETE (lu par l'API REST mais pas par PHP). Lecture bornée de
	 * php://input (le flux reste relisible par WordPress).
	 */
	private static function waf_body_items( array &$items ) {
		$method = self::request_method();
		if ( 'POST' === $method && ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::waf_collect( wp_unslash( $_POST ), 'body', $items ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return;
		}
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) && is_string( $_SERVER['CONTENT_TYPE'] ) ? strtolower( trim( $_SERVER['CONTENT_TYPE'] ) ) : '';
		$is_json      = '' !== $content_type && self::is_json_content_type( $content_type );
		$is_form      = 0 === strpos( $content_type, 'application/x-www-form-urlencoded' );
		if ( ! $is_json && ! ( $is_form && 'POST' !== $method ) ) {
			return;
		}
		$limit = self::waf_budget();
		$raw   = file_get_contents( 'php://input', false, null, 0, $limit + 1 );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return;
		}
		$truncated = strlen( $raw ) > $limit;
		$raw       = substr( $raw, 0, $limit );
		if ( $is_json ) {
			$decoded = $truncated ? null : json_decode( $raw, true, 32 );
			if ( is_array( $decoded ) ) {
				self::waf_collect( $decoded, 'body', $items );
			} else {
				// JSON invalide, trop profond ou tronqué : analyse du texte brut.
				$items[] = array( 'k' => '', 'v' => $raw, 'src' => 'body' );
			}
			return;
		}
		$parsed = array();
		parse_str( $raw, $parsed );
		if ( ! empty( $parsed ) ) {
			self::waf_collect( $parsed, 'body', $items );
		}
	}

	/**
	 * Contact Form 7 (QA-MAIN-002) : le texte qu'un visiteur écrit dans un champ
	 * de saisie libre d'un formulaire RECONNU n'est jamais une raison de refus —
	 * un client qui cite « <script src=…> » ou « SELECT * FROM wp_users » pour
	 * décrire son problème doit pouvoir envoyer son message. Seuls les champs
	 * DÉCLARÉS dans le modèle du formulaire, d'un type de saisie libre (texte,
	 * e-mail, URL, téléphone, zone de texte, nombre, date), sortent de l'analyse
	 * du corps ; les paramètres techniques (`_wpcf7*`, jetons, champs non
	 * déclarés), l'URL, la query string et toutes les autres règles (débit,
	 * sondes, scanners, liste noire…) restent appliqués.
	 *
	 * @param array $items Éléments collectés par waf_collect().
	 * @return array
	 */
	private static function without_cf7_text_fields( array $items ) {
		$fields = self::cf7_submission_text_fields();
		if ( empty( $fields ) ) {
			return $items;
		}
		$kept = array();
		foreach ( $items as $item ) {
			$base = strtolower( (string) preg_replace( '/\[.{0,1024}$/s', '', (string) $item['k'] ) );
			if ( 'body' === $item['src'] && isset( $fields[ $base ] ) ) {
				continue;
			}
			$kept[] = $item;
		}
		return $kept;
	}

	/**
	 * Envoi d'un formulaire Contact Form 7 publié : POST vers sa route REST
	 * `contact-form-7/v1/contact-forms/<id>/feedback` (envoi JavaScript) ou POST
	 * d'une page portant `_wpcf7=<id>` (envoi sans JavaScript). Le `_wpcf7` du
	 * corps, s'il existe, doit désigner le même formulaire.
	 *
	 * @return array<string,bool> Noms des champs de saisie libre déclarés (minuscules), vide sinon.
	 */
	private static function cf7_submission_text_fields() {
		if ( 'POST' !== self::request_method() || ! defined( 'WPCF7_VERSION' ) ) {
			return array();
		}
		$posted = null;
		if ( isset( $_POST['_wpcf7'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted = is_scalar( $_POST['_wpcf7'] ) ? trim( (string) wp_unslash( $_POST['_wpcf7'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! ctype_digit( $posted ) ) {
				return array();
			}
		}
		$route = self::current_rest_route();
		if ( '' !== $route ) {
			if ( ! preg_match( '#^/contact-form-7/v1/contact-forms/(\d{1,20})/feedback$#', $route, $m ) ) {
				return array();
			}
			$form_id = (int) $m[1];
			if ( null !== $posted && (int) $posted !== $form_id ) {
				return array();
			}
		} else {
			if ( null === $posted ) {
				return array();
			}
			$form_id = (int) $posted;
		}
		$form = $form_id > 0 ? get_post( $form_id ) : null;
		if ( ! $form || 'wpcf7_contact_form' !== $form->post_type || 'publish' !== $form->post_status ) {
			return array();
		}
		$template = (string) get_post_meta( $form_id, '_form', true );
		if ( '' === $template || ! preg_match_all( '/\[\s*(text|email|url|tel|textarea|number|date)\*?\s+([a-zA-Z][0-9a-zA-Z:._-]{0,100})/', $template, $tags ) ) {
			return array();
		}
		$fields = array();
		foreach ( $tags[2] as $name ) {
			$name = strtolower( $name );
			$fields[ $name ] = true;
			// PHP remplace « . » (et l'espace) par « _ » dans les noms de champs reçus.
			$fields[ str_replace( '.', '_', $name ) ] = true;
		}
		return $fields;
	}

	/** Type de contenu JSON au sens de WordPress (application/json, application/x+json…). */
	private static function is_json_content_type( $content_type ) {
		if ( function_exists( 'wp_is_json_media_type' ) ) {
			return wp_is_json_media_type( $content_type );
		}
		return (bool) preg_match( '/(^|\s|,)application\/([\w!#\$&\-\^\.\+]{1,64}\+)?json(\+oembed)?($|\s|;|,)/i', (string) $content_type );
	}

	/** Budget global d'octets analysés par requête (filtrable, 32 Kio – 1 Mio). */
	private static function waf_budget() {
		return max( 32768, min( 1048576, (int) apply_filters( 'cu_firewall_waf_byte_budget', self::WAF_BUDGET ) ) );
	}

	/**
	 * Paramètres de texte libre (recherche, message, commentaire…) : seules les
	 * signatures de haute confiance s'y appliquent. Filtre `cu_firewall_freetext_params`.
	 */
	private static function is_freetext_key( $key ) {
		static $list = null;
		if ( null === $list ) {
			$list = array_map(
				'strtolower',
				array_filter( array_map( 'strval', (array) apply_filters( 'cu_firewall_freetext_params', array(
					's', 'q', 'search', 'query', 'keyword', 'keywords', 'term', 'terms', 'text', 'message', 'msg',
					'comment', 'content', 'subject', 'body', 'description', 'title', 'name', 'author_name',
					'post_title', 'excerpt', 'note', 'notes', 'feedback', 'review',
				) ) ) )
			);
		}
		$base = strtolower( (string) preg_replace( '/\[.{0,1024}$/s', '', (string) $key ) );
		if ( in_array( $base, $list, true ) ) {
			return true;
		}
		foreach ( array( 'search', 'keyword', 'message', 'comment' ) as $fragment ) {
			if ( false !== strpos( $base, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Analyse les éléments par segments de WAF_CHUNK octets (+ recouvrement) :
	 * 1er passage = valeurs courtes, début et fin des valeurs longues ; 2e
	 * passage = milieu des valeurs longues, tant que le budget le permet.
	 */
	private static function waf_scan_items( array $items, $hc_only ) {
		$budget = self::waf_budget();
		$seg    = self::WAF_CHUNK + self::WAF_OVERLAP;
		$first  = array();
		$second = array();
		$max_2  = (int) ( $budget / self::WAF_CHUNK ) + 1;
		foreach ( $items as $item ) {
			// Un mot de passe (connexion WooCommerce, inscription, changement de mot
			// de passe) peut légitimement contenir n'importe quoi : jamais analysé.
			if ( 'body' === $item['src'] && preg_match( '/pass|pwd/i', substr( (string) $item['k'], 0, 1024 ) ) ) {
				continue;
			}
			$set = ( $hc_only || 'body' === $item['src'] || self::is_freetext_key( $item['k'] ) ) ? 'hc' : 'full';
			$h   = substr( (string) $item['k'], 0, 1024 ) . '=' . (string) $item['v'];
			$len = strlen( $h );
			if ( $len <= $seg ) {
				$first[] = array( $h, 0, $set, $item['src'] );
				continue;
			}
			$first[] = array( $h, 0, $set, $item['src'] );
			$first[] = array( $h, $len - $seg, $set, $item['src'] );
			for ( $offset = self::WAF_CHUNK; $offset < $len - $seg && count( $second ) < $max_2; $offset += self::WAF_CHUNK ) {
				$second[] = array( $h, $offset, $set, $item['src'] );
			}
		}
		$used = 0;
		foreach ( array( $first, $second ) as $pass ) {
			foreach ( $pass as $segment ) {
				if ( $used >= $budget ) {
					return false;
				}
				$text  = substr( $segment[0], $segment[1], $seg );
				$used += strlen( $text );
				$hit   = self::waf_scan_string( self::decode_waf_source( $text ), $segment[3], $segment[2] );
				if ( $hit ) {
					return $hit;
				}
			}
		}
		return false;
	}

	/**
	 * Applique les signatures d'un ensemble (full | hc | traversal) à un texte.
	 * Une erreur PCRE n'est JAMAIS traitée comme « pas de correspondance ».
	 */
	private static function waf_scan_string( $text, $source, $set ) {
		$text = str_replace( "\0", '', (string) $text );
		if ( '' === $text ) {
			return false;
		}
		$sql = null;
		foreach ( self::waf_rules() as $id => $rule ) {
			if ( 'traversal' === $set && 'traversal' !== $rule['type'] ) {
				continue;
			}
			if ( 'hc' === $set && empty( $rule['hc'] ) ) {
				continue;
			}
			if ( $rule['sql'] ) {
				if ( null === $sql ) {
					$sql = self::sql_normalize( $text );
				}
				$haystack = $sql;
			} else {
				$haystack = $text;
			}
			if ( ! self::waf_needles_present( $haystack, $rule['need'] ) ) {
				continue;
			}
			$matched = preg_match( $rule['re'], $haystack, $m );
			if ( false === $matched ) {
				return array(
					'type'   => strtoupper( $rule['type'] ),
					'rule'   => $id,
					'sample' => '',
					'source' => $source,
					'error'  => function_exists( 'preg_last_error_msg' ) ? preg_last_error_msg() : (string) preg_last_error(),
				);
			}
			if ( 1 === $matched ) {
				return array(
					'type'   => strtoupper( $rule['type'] ),
					'rule'   => $id,
					'sample' => self::waf_sample( $m[0] ),
					'source' => $source,
				);
			}
		}
		return false;
	}

	/** Préfiltres : chaque entrée doit être présente ; une entrée tableau = au moins l'une de ses valeurs. */
	private static function waf_needles_present( $haystack, array $needles ) {
		foreach ( $needles as $group ) {
			$found = false;
			foreach ( (array) $group as $needle ) {
				if ( '' !== $needle && false !== stripos( $haystack, (string) $needle ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Vue « SQL » d'un texte : commentaires bloc retirés (le contenu des
	 * commentaires exécutables /*!50000 … *\/ est conservé, comme MySQL),
	 * commentaires de fin de ligne suivis d'un saut de ligne retirés, espaces
	 * fusionnés. Linéaire (strpos), sans expression régulière à risque.
	 */
	private static function sql_normalize( $text ) {
		$text = (string) $text;
		if ( false !== strpos( $text, '/*' ) ) {
			$out   = '';
			$pos   = 0;
			$len   = strlen( $text );
			$guard = 0;
			while ( $pos < $len && $guard++ < 20000 ) {
				$start = strpos( $text, '/*', $pos );
				if ( false === $start ) {
					$out .= substr( $text, $pos );
					$pos  = $len;
					break;
				}
				$out .= substr( $text, $pos, $start - $pos ) . ' ';
				if ( isset( $text[ $start + 2 ] ) && '!' === $text[ $start + 2 ] ) {
					$p = $start + 3;
					while ( $p < $len && $p < $start + 9 && ctype_digit( $text[ $p ] ) ) {
						$p++;
					}
					$pos = $p;
					continue;
				}
				$end = strpos( $text, '*/', $start + 2 );
				if ( false === $end ) {
					$pos = $len; // commentaire non fermé : ignoré jusqu'à la fin, comme MySQL.
					break;
				}
				$pos = $end + 2;
			}
			if ( $pos < $len ) {
				$out .= substr( $text, $pos );
			}
			$text = str_replace( '*/', ' ', $out );
		}
		if ( false !== strpos( $text, "\n" ) && ( false !== strpos( $text, '#' ) || false !== strpos( $text, '--' ) ) ) {
			$stripped = preg_replace( '/(?:#|--)[^\n]{0,256}\n/', ' ', $text );
			if ( is_string( $stripped ) ) {
				$text = $stripped;
			}
		}
		$collapsed = preg_replace( '/[\s\x0b]+/', ' ', $text );
		return is_string( $collapsed ) ? $collapsed : $text;
	}

	/** Extrait lisible et borné de la correspondance (journal). */
	private static function waf_sample( $match ) {
		$sample = preg_replace( '/[\x00-\x1F\x7F\s]+/', ' ', (string) $match );
		return CU_Text::substr( is_string( $sample ) ? trim( $sample ) : '', 0, 60 );
	}

	/** Décode les formulaires (`+` compris) et un niveau d'encodage imbriqué, avec une borne stricte. */
	private static function decode_waf_source( $value ) {
		$value = substr( (string) $value, 0, 65536 );
		for ( $i = 0; $i < 2; $i++ ) {
			$decoded = urldecode( $value );
			if ( $decoded === $value ) {
				break;
			}
			$value = $decoded;
		}
		return $value;
	}

	// -----------------------------------------------------------------------
	// Défi JavaScript (« Bot Fight Mode » maison)
	// -----------------------------------------------------------------------

	/**
	 * Sert un défi JavaScript au front-end : un visiteur anonyme reçoit une page
	 * qui doit EXÉCUTER du JS pour poser un cookie signé, puis recharge. Sans JS
	 * (la plupart des bots bas de gamme) → jamais le contenu réel. Ne s'exécute
	 * que sur template_redirect (front-end pur), jamais REST/admin/AJAX/cron.
	 */
	public function maybe_js_challenge() {
		if ( ! self::firewall_active() ) {
			return;
		}
		if ( ! CU_Settings::get( 'firewall', 'js_challenge_enabled', false ) ) {
			return;
		}
		if ( self::is_challenge_exempt() ) {
			return;
		}

		$ip = self::get_client_ip();
		if ( '' === $ip || ! self::tables_available() || self::is_whitelisted( $ip ) ) {
			return;
		}

		$ua = self::current_ua();

		// Déjà validé → laisser passer (et nettoyer le paramètre anti-boucle de l'URL).
		if ( self::has_valid_challenge_cookie( $ip, $ua ) ) {
			if ( isset( $_GET['__cujsc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_safe_redirect( self::challenge_target_url( null ) );
				exit;
			}
			return;
		}

		// Crawlers légitimes vérifiés (y compris les fetchers déclenchés par
		// l'éditeur du site) : un crawler ne peut pas exécuter ce défi.
		if ( '' !== self::good_bot_family( $ua ) && self::is_good_bot_request( $ip, false ) ) {
			return;
		}
		// Aperçus de liens (réseaux sociaux, messageries) et sondes de
		// disponibilité : jamais de 503 de défi (ils restent soumis au WAF et
		// à la limitation de débit). Le User-Agent ne leur accorde rien d'autre.
		if ( self::is_preview_or_monitor_ua( $ua ) ) {
			return;
		}

		// Portée : par défaut, uniquement les visiteurs suspects.
		$scope = CU_Settings::get( 'firewall', 'js_challenge_scope', 'suspicious' );
		if ( 'all' !== $scope && ! self::is_suspicious_visitor( $ip, $ua ) ) {
			return;
		}

		$attempt = ( isset( $_GET['__cujsc'] ) && is_scalar( $_GET['__cujsc'] ) ) ? max( 0, min( 9, (int) $_GET['__cujsc'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Anti-boucle : après 2 essais sans cookie valide (JS/cookies désactivés),
		// message final au lieu de recharger à l'infini.
		if ( $attempt >= 2 ) {
			self::render_challenge_final_page();
		}

		$ttl   = max( 1, (int) CU_Settings::get( 'firewall', 'js_challenge_ttl_hours', 24 ) ) * HOUR_IN_SECONDS;
		$token = self::issue_challenge_token( $ip, $ua, $ttl );
		self::render_challenge_page( self::challenge_target_url( $attempt + 1 ), $token, $ttl );
	}

	/**
	 * URL de retour du défi, TOUJOURS relative à l'origine du site : chemin brut
	 * de la requête (barres obliques et antislashs initiaux fusionnés, caractères
	 * de contrôle retirés), paramètres conservés, `__cujsc` remplacé. Empêche
	 * `https://site//evil.com/` de devenir une redirection vers evil.com.
	 *
	 * @param int|null $attempt Compteur anti-boucle, ou null pour le retirer.
	 */
	private static function challenge_target_url( $attempt ) {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$uri   = substr( $uri, 0, 4096 );
		$qpos  = strpos( $uri, '?' );
		$path  = false === $qpos ? $uri : substr( $uri, 0, $qpos );
		$query = false === $qpos ? '' : substr( $uri, $qpos + 1 );
		$hash  = strpos( $query, '#' );
		if ( false !== $hash ) {
			$query = substr( $query, 0, $hash );
		}
		$path = (string) preg_replace( '/[\x00-\x20\x7f]+/', '', str_replace( '\\', '/', $path ) );
		$path = '/' . ltrim( (string) preg_replace( '#/{2,}#', '/', $path ), '/' );

		// « ? » toujours présent : sans lui, add_query_arg() prendrait un chemin
		// contenant « = » pour une chaîne de requête.
		$target = (string) remove_query_arg( '__cujsc', $path . '?' . preg_replace( '/[\x00-\x20\x7f]+/', '', $query ) );
		if ( false === strpos( $target, '?' ) ) {
			$target .= '?';
		}
		if ( null !== $attempt ) {
			$target = add_query_arg( '__cujsc', (int) $attempt, $target );
		}
		$target = rtrim( (string) $target, '?' );
		// Dernier garde-fou : un chemin relatif d'origine, jamais « //hôte » ni « /\hôte ».
		if ( '' === $target || '/' !== $target[0] || ( isset( $target[1] ) && ( '/' === $target[1] || '\\' === $target[1] ) ) ) {
			$target = null === $attempt ? '/' : '/?__cujsc=' . (int) $attempt;
		}
		return $target;
	}

	/** Aperçus de liens et sondes de disponibilité (UA déclaré : exemption du défi seulement). */
	private static function is_preview_or_monitor_ua( $ua ) {
		if ( '' === $ua ) {
			return false;
		}
		$tokens = (array) apply_filters( 'cu_firewall_challenge_exempt_ua', array(
			// Aperçus de liens.
			'facebookexternalhit', 'facebot', 'linkedinbot', 'slackbot', 'slack-imgproxy', 'twitterbot',
			'whatsapp', 'discordbot', 'telegrambot', 'skypeuripreview', 'pinterestbot', 'redditbot',
			'embedly', 'iframely', 'mastodon', 'bluesky', 'cardyb', 'vkshare', 'snapchat',
			// Sondes de disponibilité.
			'uptimerobot', 'pingdom', 'statuscake', 'site24x7', 'betteruptime', 'better uptime',
			'uptime-kuma', 'uptimekuma', 'hetrixtools', 'freshping', 'updown.io', 'jetmon', 'nodeping',
			'checkly', 'newrelicpinger', 'datadog', 'ohdear', 'oh dear',
		) );
		foreach ( $tokens as $token ) {
			if ( is_scalar( $token ) && '' !== (string) $token && false !== stripos( $ua, (string) $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Requêtes jamais soumises au défi JS : connectés, admin, API REST, AJAX,
	 * cron, XML-RPC, robots.txt, sitemaps, flux, favicon, et tout ce qui n'est
	 * pas un GET de page HTML.
	 */
	private static function is_challenge_exempt() {
		if ( self::is_internal_seo_audit_request() ) {
			return true;
		}
		if ( is_user_logged_in() || is_admin() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		if ( self::is_auth_request() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return true;
		}
		if ( 'GET' !== self::request_method() ) {
			return true; // HEAD/POST/… : pas de rendu HTML exploitable pour le défi.
		}
		if ( ( function_exists( 'is_robots' ) && is_robots() ) || ( function_exists( 'is_favicon' ) && is_favicon() ) || ( function_exists( 'is_feed' ) && is_feed() ) || ( function_exists( 'is_trackback' ) && is_trackback() ) ) {
			return true;
		}
		$path = strtolower( self::request_path() );
		if ( self::is_seo_control_path( $path ) || '' !== self::current_rest_route() ) {
			return true;
		}
		return false;
	}

	/**
	 * Un visiteur est « suspect » (portée ciblée du défi) si : User-Agent vide,
	 * IP déjà signalée (soft-flag : 404 en rafale, échecs de connexion), débit
	 * proche du seuil de rate-limit, ou UA non-navigateur non reconnu comme bot légitime.
	 */
	private static function is_suspicious_visitor( $ip, $ua ) {
		if ( '' === trim( $ua ) ) {
			return true;
		}
		if ( self::is_soft_flagged( $ip ) ) {
			return true;
		}
		$max = (int) CU_Settings::get( 'firewall', 'rate_limit_max_requests', 120 );
		if ( $max > 0 ) {
			$cnt = self::fixed_window_counter_value( self::counter_key( 'rl', $ip ), self::counter_window( 'rl' ) );
			if ( $cnt >= max( 1, (int) floor( $max / 2 ) ) ) {
				return true;
			}
		}
		if ( false === stripos( $ua, 'mozilla' ) && ! self::looks_like_good_bot_ua( $ua ) ) {
			return true;
		}
		return false;
	}

	/** UA correspondant (par nom) à un crawler de moteur connu — filtre préalable. */
	private static function looks_like_good_bot_ua( $ua ) {
		return '' !== self::good_bot_family( $ua );
	}

	/**
	 * Associe le User-Agent à son moteur. La famille sert aussi à lier le UA
	 * au suffixe DNS attendu : un faux Googlebot venant d'un vrai serveur Yandex
	 * ne doit pas être accepté par accident.
	 */
	private static function good_bot_family( $ua ) {
		$families = array(
			'google_special' => array(
				'adsbot-google', 'mediapartners-google', 'apis-google', 'google-safety',
			),
			'google_common' => array(
				'googlebot', 'storebot-google', 'google-inspectiontool', 'googleother',
				'google-cloudvertexbot',
			),
			// Fetchers déclenchés par l'éditeur du site lui-même (validation Search
			// Console, flux, Publisher Center) : un défi JS ferait échouer la
			// validation de propriété du site.
			'google_fetcher' => array( 'google-site-verification', 'feedfetcher-google', 'googleproducer' ),
			'bing'   => array( 'bingbot', 'msnbot', 'adidxbot', 'microsoftpreview', 'bingpreview', 'bingvideopreview' ),
			'yahoo'  => array( 'slurp' ),
			'duckduckgo' => array( 'duckduckbot' ),
			'baidu'  => array( 'baiduspider', 'baiduimagespider', 'baidumobaider' ),
			'yandex' => array( 'yandex', 'yadirectfetcher' ),
			'apple'  => array( 'applebot' ),
			'naver'  => array( 'yeti/', 'ads-naver', 'blueno' ),
			// Robots de recherche IA : seuls ceux qui servent directement la
			// visibilité/recherche sont exemptés (pas les crawlers d'entraînement).
			'openai_search'     => array( 'oai-searchbot' ),
			'perplexity_search' => array( 'perplexitybot' ),
			'anthropic_search'  => array( 'claude-searchbot' ),
		);

		foreach ( $families as $family => $tokens ) {
			foreach ( $tokens as $token ) {
				if ( false !== stripos( $ua, $token ) ) {
					return $family;
				}
			}
		}
		return '';
	}

	/**
	 * Raccourci : la requête COURANTE provient-elle d'un crawler de moteur
	 * VÉRIFIÉ (FCrDNS) ? Lit le User-Agent de la requête. Utilisé partout où l'on
	 * doit garantir qu'un moteur ne reçoit JAMAIS un 403 (protection SEO absolue).
	 */
	private static function is_good_bot_request( $ip, $full = true ) {
		$ua     = self::current_ua();
		$family = self::good_bot_family( $ua );
		if ( '' === $family ) {
			return false;
		}
		// Les « fetchers déclenchés par l'utilisateur » de Google passent par des
		// plages (App Engine, Apps Script) utilisables par n'importe qui : ils ne
		// reçoivent jamais l'exemption complète d'un crawler (seulement le défi JS).
		if ( $full && in_array( $family, self::limited_bot_families(), true ) ) {
			return false;
		}
		$memo_key = $family . '|' . $ip;
		if ( ! array_key_exists( $memo_key, self::$good_bot_memo ) ) {
			self::$good_bot_memo[ $memo_key ] = self::is_verified_good_bot( $ip, $ua );
			if ( self::$good_bot_memo[ $memo_key ] ) {
				self::record_verified_good_bot( $ip, $ua );
			}
		}
		return self::$good_bot_memo[ $memo_key ];
	}

	/** Familles vérifiables mais sans exemption complète (plages partagées). */
	private static function limited_bot_families() {
		return array( 'google_fetcher' );
	}

	/**
	 * Compte un crawler authentifié sans journaliser chacune de ses ressources.
	 * Une seule ligne est conservée par famille/IP/heure, et une seule tentative
	 * est faite pendant la requête courante même si plusieurs règles demandent
	 * la vérification du robot.
	 */
	private static function record_verified_good_bot( $ip, $ua ) {
		if ( self::$good_bot_recorded || ! self::tables_available() ) {
			return;
		}
		self::$good_bot_recorded = true;
		$family = self::good_bot_family( $ua );
		$key    = 'cu_goodbot_seen_' . CU_Geo::ip_key( $family . '|' . $ip );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );
		self::log_request( $ip, 'observed', 'goodbot_verified', 'low' );
	}

	/**
	 * Confirme qu'une requête vient VRAIMENT d'un crawler de moteur (et n'usurpe
	 * pas le User-Agent) via les plages officielles publiées ou, en repli pour
	 * certains moteurs, un reverse-DNS + forward-confirm strict. Cache 1 jour.
	 */
	private static function is_verified_good_bot( $ip, $ua ) {
		$family = self::good_bot_family( $ua );
		if ( '' === $family || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$cache_key = 'cu_goodbot_' . CU_Geo::ip_key( $family . '|' . $ip );
		$cached    = get_transient( $cache_key );
		if ( 'yes' === $cached ) {
			return true;
		}
		if ( 'no' === $cached ) {
			return false;
		}
		$list_families = array(
			'google_common', 'google_special', 'google_fetcher', 'bing', 'duckduckgo', 'apple',
			'openai_search', 'perplexity_search', 'anthropic_search',
		);
		$verified            = false;
		$list_status         = null;
		self::$rdns_no_host  = false;
		self::$rdns_deferred = false;
		if ( in_array( $family, $list_families, true ) ) {
			$list_status = self::official_bot_ip_status( $ip, $family );
			if ( true === $list_status ) {
				$verified = true;
			} elseif ( false === $list_status && 'bing' !== $family ) {
				// Liste Google/Apple disponible et IP absente : la liste fait foi.
				// Ne PAS tenter un reverse-DNS, sinon un botnet se présentant comme
				// « Googlebot » depuis des milliers d'IP déclencherait autant de
				// résolutions DNS.
				$verified = false;
			} elseif ( in_array( $family, array( 'google_common', 'google_special', 'google_fetcher', 'bing', 'apple' ), true ) ) {
				// Liste indisponible OU IP absente de la liste : FCrDNS catégoriel.
				// Les deux méthodes sont officielles et une liste peut être en retard
				// (bingbot.json n'a pas été régénéré depuis janvier 2024) : un vrai
				// Bingbot venant d'un nouveau sous-réseau ne doit pas être rejeté.
				// Le forward-confirm empêche toute usurpation par simple PTR.
				$verified = self::reverse_dns_check( $ip, $family );
			}
		} else {
			// Familles sans liste officielle (Yandex, Baidu, Yahoo, Naver) : FCrDNS seul.
			$verified = self::reverse_dns_check( $ip, $family );
		}
		// Mise en cache : un robot confirmé (1 jour) ; un refus seulement s'il a
		// coûté une résolution DNS (15 min). Une IP absente d'une liste officielle
		// n'est pas mémorisée : la liste est déjà en cache, et un faux « Googlebot »
		// changeant d'IP ne provoque ainsi aucune écriture en base. Résultat non
		// concluant (vérification reportée en arrière-plan, budget DNS épuisé, DNS
		// muet) : rien n'est mis en cache, le visiteur est simplement traité comme
		// un visiteur ordinaire.
		$inconclusive  = ! $verified && ( self::$rdns_deferred || self::$dns_budget_exhausted || ( null === $list_status && self::$rdns_no_host ) );
		$list_negative = ! $verified && false === $list_status && 'bing' !== $family;
		if ( $verified ) {
			set_transient( $cache_key, 'yes', DAY_IN_SECONDS );
		} elseif ( ! $inconclusive && ! $list_negative ) {
			set_transient( $cache_key, 'no', 15 * MINUTE_IN_SECONDS );
		}
		return $verified;
	}

	/**
	 * Reverse-DNS (FCrDNS) JAMAIS pendant la visite (QA-MAIN-003) :
	 * gethostbyaddr() n'a pas de délai réglable et bloque PHP plusieurs secondes
	 * quand l'adresse n'a pas de PTR (≈ 10 s mesurées par faux « YandexBot »).
	 * Pendant une visite, l'adresse est placée dans une petite file bornée et
	 * vérifiée en arrière-plan par WP-Cron ; d'ici là, le visiteur est traité
	 * comme un visiteur ordinaire (aucun privilège accordé sur la seule foi du
	 * User-Agent, aucun blocage). Les tâches planifiées (Radar, file elle-même)
	 * vérifient directement. Le verdict est ensuite lu dans le cache habituel.
	 */
	private static function reverse_dns_check( $ip, $family ) {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return self::reverse_dns_confirms_bot( $ip, $family );
		}
		self::$rdns_deferred = true;
		self::queue_rdns_check( $ip, $family );
		return false;
	}

	/** Ajoute une adresse à la file de vérification (au plus RDNS_QUEUE_MAX, une écriture par nouvelle adresse). */
	private static function queue_rdns_check( $ip, $family ) {
		if ( ! function_exists( 'gethostbyaddr' ) ) {
			return;
		}
		$queue = get_option( self::RDNS_QUEUE_KEY, array() );
		$queue = is_array( $queue ) ? $queue : array();
		$id    = $family . '|' . $ip;
		if ( ! isset( $queue[ $id ] ) && count( $queue ) < self::RDNS_QUEUE_MAX ) {
			$queue[ $id ] = time();
			update_option( self::RDNS_QUEUE_KEY, $queue, false );
		}
		if ( ! empty( $queue ) && ! wp_next_scheduled( self::RDNS_QUEUE_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::RDNS_QUEUE_HOOK );
		}
	}

	/**
	 * Tâche WP-Cron : vérifie les robots en file, dans la limite du budget DNS
	 * par minute (filtre `cu_goodbot_dns_per_minute`), met le verdict en cache
	 * (confirmé : 1 jour ; non confirmé : 15 min) et se replanifie s'il reste des
	 * adresses. Une entrée de plus d'une heure est abandonnée.
	 */
	public function process_rdns_queue() {
		$queue = get_option( self::RDNS_QUEUE_KEY, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		if ( ! self::firewall_active() ) {
			delete_option( self::RDNS_QUEUE_KEY );
			return;
		}
		$done    = array();
		$now     = time();
		$lookups = 0;
		foreach ( $queue as $id => $queued_at ) {
			if ( $lookups >= 3 ) {
				break; // passage court : une résolution sans PTR peut durer plusieurs secondes
			}
			$parts = explode( '|', (string) $id, 2 );
			if ( 2 !== count( $parts ) || (int) $queued_at < $now - HOUR_IN_SECONDS || ! filter_var( $parts[1], FILTER_VALIDATE_IP ) ) {
				$done[] = $id;
				continue;
			}
			$cache_key = 'cu_goodbot_' . CU_Geo::ip_key( $parts[0] . '|' . $parts[1] );
			if ( false !== get_transient( $cache_key ) ) {
				$done[] = $id;
				continue;
			}
			self::$dns_budget_exhausted = false;
			self::$rdns_no_host         = false;
			$lookups++;
			$verified                   = self::reverse_dns_confirms_bot( $parts[1], $parts[0] );
			if ( self::$dns_budget_exhausted ) {
				break; // budget de la minute épuisé : la suite au prochain passage
			}
			set_transient( $cache_key, $verified ? 'yes' : 'no', $verified ? DAY_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
			$done[] = $id;
		}
		// Relecture avant écriture : des visites ont pu ajouter des adresses entre-temps.
		wp_cache_delete( self::RDNS_QUEUE_KEY, 'options' );
		$fresh = get_option( self::RDNS_QUEUE_KEY, array() );
		$fresh = is_array( $fresh ) ? $fresh : array();
		foreach ( $done as $id ) {
			unset( $fresh[ $id ] );
		}
		if ( empty( $fresh ) ) {
			delete_option( self::RDNS_QUEUE_KEY );
			return;
		}
		update_option( self::RDNS_QUEUE_KEY, $fresh, false );
		if ( ! wp_next_scheduled( self::RDNS_QUEUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RDNS_QUEUE_HOOK );
		}
	}

	private static function reverse_dns_confirms_bot( $ip, $family ) {
		if ( ! function_exists( 'gethostbyaddr' ) ) {
			return false;
		}
		// Plafond GLOBAL de vérifications DNS par minute : gethostbyaddr() bloque le
		// processus PHP (tâches planifiées uniquement, voir reverse_dns_check()).
		// Au-delà, le résultat n'est pas mis en cache comme négatif : l'adresse
		// sera vérifiée au passage suivant.
		$max = max( 1, (int) apply_filters( 'cu_goodbot_dns_per_minute', 20 ) );
		$key = 'cu_goodbot_dns_' . gmdate( 'YmdHi' );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			self::$dns_budget_exhausted = true;
			return false;
		}
		set_transient( $key, $n + 1, 2 * MINUTE_IN_SECONDS );
		$host = @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $host || $host === $ip ) {
			self::$rdns_no_host = true;
			return false;
		}
		$host     = rtrim( strtolower( $host ), '.' );
		$suffixes = array(
			// Ne jamais accepter le suffixe générique googleusercontent.com : une
			// VM GCP ordinaire (*.bc.googleusercontent.com) pourrait usurper le UA.
			'google_common'  => array( '.googlebot.com' ),
			'google_special' => array( '.google.com' ),
			// google-proxy-*.google.com uniquement : *.gae.googleusercontent.com
			// n'est pas assez spécifique pour servir de preuve.
			'google_fetcher' => array( '.google.com' ),
			'bing'   => array( '.search.msn.com' ),
			'yahoo'  => array( '.crawl.yahoo.net' ),
			'yandex' => array( '.yandex.com', '.yandex.ru', '.yandex.net' ),
			'baidu'  => array( '.baidu.com', '.baidu.jp' ),
			'apple'  => array( '.applebot.apple.com' ),
			'naver'  => array( '.naver.com' ),
		);
		$allowed  = isset( $suffixes[ $family ] ) ? $suffixes[ $family ] : array();
		$suffix_ok = false;
		foreach ( $allowed as $suffix ) {
			if ( self::str_ends_with_ci( $host, $suffix ) ) {
				$suffix_ok = true;
				break;
			}
		}
		if ( ! $suffix_ok ) {
			return false;
		}
		// Forward-confirm : le nom validé doit se résoudre vers l'IP d'origine.
		// En IPv4, examiner toutes les adresses A (pas seulement la première).
		if ( false === strpos( $ip, ':' ) ) {
			$forwards = function_exists( 'gethostbynamel' ) ? @gethostbynamel( $host ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_array( $forwards ) && function_exists( 'gethostbyname' ) ) {
				$forward  = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$forwards = ( $forward && $forward !== $host ) ? array( $forward ) : array();
			}
			foreach ( (array) $forwards as $forward ) {
				if ( self::same_ip( $ip, $forward ) ) {
					return true;
				}
			}
			return false;
		}

		// IPv6 : un suffixe seul n'est jamais une preuve. Exiger une adresse AAAA
		// qui correspond exactement à l'adresse source, comme en IPv4.
		if ( ! function_exists( 'dns_get_record' ) || ! defined( 'DNS_AAAA' ) ) {
			return false;
		}
		$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( (array) $records as $record ) {
			if ( isset( $record['ipv6'] ) && self::same_ip( $ip, $record['ipv6'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Compare deux IP, y compris deux écritures textuelles équivalentes en IPv6. */
	private static function same_ip( $left, $right ) {
		if ( ! filter_var( $left, FILTER_VALIDATE_IP ) || ! filter_var( $right, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( function_exists( 'inet_pton' ) ) {
			$left_bin  = @inet_pton( $left );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$right_bin = @inet_pton( $right ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false !== $left_bin && false !== $right_bin && hash_equals( $left_bin, $right_bin );
		}
		return $left === $right;
	}

	/**
	 * Compare une IP aux listes JSON officielles. Retourne true/false lorsque la
	 * liste est disponible, null en cas d'indisponibilité sans cache exploitable.
	 * Le dernier jeu valide est conservé sept jours et n'est jamais remplacé par
	 * une erreur HTTP/JSON. Un verrou court évite les rafales de rafraîchissement.
	 *
	 * @return bool|null
	 */
	private static function official_bot_ip_status( $ip, $family ) {
		$sources = array(
			'google_common'     => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
			'google_special'    => 'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
			'google_fetcher'    => array(
				'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers.json',
				'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json',
			),
			'bing'            => 'https://www.bing.com/toolbox/bingbot.json',
			'duckduckgo'        => 'https://duckduckgo.com/duckduckbot.json',
			'apple'             => 'https://search.developer.apple.com/applebot.json',
			'openai_search'     => 'https://openai.com/searchbot.json',
			'perplexity_search' => 'https://www.perplexity.com/perplexitybot.json',
			'anthropic_search'  => 'https://claude.com/crawling/bots.json',
		);
		$sources = (array) apply_filters( 'cu_official_bot_ip_sources', $sources );
		if ( empty( $sources[ $family ] ) || ! function_exists( 'wp_remote_get' ) ) {
			return null;
		}

		$family_key = sanitize_key( $family );
		$fresh_key = 'cu_bot_prefixes_v2_' . $family_key;
		$stale_key = 'cu_bot_prefixes_stale_v2_' . $family_key;
		$lock_key  = 'cu_bot_prefixes_lock_v2_' . $family_key;
		$state     = get_transient( $fresh_key );
		if ( is_array( $state ) && array_key_exists( 'available', $state ) ) {
			return ! empty( $state['available'] )
				? self::ip_matches_prefixes( $ip, isset( $state['prefixes'] ) ? $state['prefixes'] : array() )
				: null;
		}

		$stale = get_transient( $stale_key );
		if ( get_transient( $lock_key ) ) {
			return ( is_array( $stale ) && ! empty( $stale['prefixes'] ) )
				? self::ip_matches_prefixes( $ip, $stale['prefixes'] )
				: null;
		}
		set_transient( $lock_key, 1, 30 );

		// Une famille peut avoir plusieurs fichiers officiels. Ils doivent TOUS
		// répondre : une liste partielle rejetterait à tort des IP légitimes.
		$urls     = array_filter( array_map( 'strval', (array) $sources[ $family ] ) );
		$prefixes = array();
		$complete = ! empty( $urls );
		foreach ( $urls as $url ) {
			$found    = false;
			$response = wp_remote_get(
				esc_url_raw( $url ),
				array(
					'timeout'             => 3,
					'redirection'         => 2,
					'sslverify'           => true,
					'limit_response_size' => 512 * KB_IN_BYTES,
					'user-agent'          => 'CyberPush Ultra/' . ( defined( 'CU_VERSION' ) ? CU_VERSION : '1.0' ),
					'headers'             => array( 'Accept' => 'application/json' ),
				)
			);
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( isset( $data['prefixes'] ) && is_array( $data['prefixes'] ) && count( $data['prefixes'] ) <= 5000 ) {
					foreach ( $data['prefixes'] as $row ) {
						$prefix = '';
						if ( is_string( $row ) ) {
							$prefix = $row;
						} elseif ( is_array( $row ) ) {
							$prefix = isset( $row['ipv4Prefix'] ) ? $row['ipv4Prefix'] : ( isset( $row['ipv6Prefix'] ) ? $row['ipv6Prefix'] : '' );
						}
						$prefix = is_string( $prefix ) ? trim( $prefix ) : '';
						if ( '' !== $prefix && self::is_valid_ip_or_cidr( $prefix ) ) {
							$prefixes[] = $prefix;
							$found      = true;
						}
					}
				}
			}
			if ( ! $found ) {
				$complete = false;
				break;
			}
		}
		if ( ! $complete ) {
			$prefixes = array();
		}
		delete_transient( $lock_key );

		$prefixes = array_values( array_unique( $prefixes ) );
		if ( ! empty( $prefixes ) ) {
			$state = array( 'available' => true, 'prefixes' => $prefixes );
			set_transient( $fresh_key, $state, DAY_IN_SECONDS );
			set_transient( $stale_key, $state, 7 * DAY_IN_SECONDS );
			return self::ip_matches_prefixes( $ip, $prefixes );
		}

		// Repli sur la dernière liste valide, avec nouvel essai dans 15 minutes.
		$log_key = 'cu_bot_source_warning_v2_' . $family_key;
		if ( ! get_transient( $log_key ) ) {
			set_transient( $log_key, 1, 6 * HOUR_IN_SECONDS );
			CU_Logger::log( 'firewall', 'warning', __( 'Liste officielle d’un robot de recherche momentanément indisponible.', 'cyberpush-ultra' ), array(
				'family' => $family,
				'source' => implode( ', ', array_map( 'esc_url_raw', $urls ) ),
				'stale_cache_used' => is_array( $stale ) && ! empty( $stale['prefixes'] ),
			) );
		}
		if ( is_array( $stale ) && ! empty( $stale['prefixes'] ) ) {
			set_transient( $fresh_key, $stale, 15 * MINUTE_IN_SECONDS );
			return self::ip_matches_prefixes( $ip, $stale['prefixes'] );
		}
		set_transient( $fresh_key, array( 'available' => false, 'prefixes' => array() ), 15 * MINUTE_IN_SECONDS );
		return null;
	}

	/** Compare une IP à un petit jeu validé d'IP/CIDR. */
	private static function ip_matches_prefixes( $ip, $prefixes ) {
		foreach ( (array) $prefixes as $prefix ) {
			if ( self::ip_matches( $ip, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	private static function str_ends_with_ci( $haystack, $needle ) {
		$len = strlen( $needle );
		if ( 0 === $len ) {
			return true;
		}
		return 0 === substr_compare( $haystack, $needle, -$len, $len, true );
	}

	/** Secret HMAC du défi, dérivé des sels WordPress (jamais exposé). */
	private static function challenge_secret() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|cu-js-challenge' );
	}

	/** Fabrique un jeton signé (expiry.signature) lié à l'IP et au User-Agent. */
	private static function issue_challenge_token( $ip, $ua, $ttl_seconds ) {
		$expiry = time() + (int) $ttl_seconds;
		$sig    = substr( hash_hmac( 'sha256', $expiry . '|' . $ip . '|' . md5( $ua ), self::challenge_secret() ), 0, 32 );
		return $expiry . '.' . $sig;
	}

	/** Valide un jeton du cookie cu_jsc : non expiré, signature correcte, bon IP/UA. */
	private static function validate_challenge_token( $token, $ip, $ua ) {
		if ( ! is_string( $token ) || false === strpos( $token, '.' ) ) {
			return false;
		}
		list( $expiry, $sig ) = explode( '.', $token, 2 );
		if ( ! ctype_digit( (string) $expiry ) || (int) $expiry < time() ) {
			return false;
		}
		if ( strlen( $sig ) !== 32 || ! ctype_xdigit( $sig ) ) {
			return false;
		}
		$expected = substr( hash_hmac( 'sha256', $expiry . '|' . $ip . '|' . md5( $ua ), self::challenge_secret() ), 0, 32 );
		return hash_equals( $expected, $sig );
	}

	private static function has_valid_challenge_cookie( $ip, $ua ) {
		$token = ( isset( $_COOKIE['cu_jsc'] ) && is_scalar( $_COOKIE['cu_jsc'] ) ) ? (string) wp_unslash( $_COOKIE['cu_jsc'] ) : '';
		return self::validate_challenge_token( $token, $ip, $ua );
	}

	/** Incrémente le compteur « soft-flag » d'une IP (comportement suspect). */
	private static function bump_soft_flag( $ip, $ttl = 1800 ) {
		if ( '' === $ip ) {
			return;
		}
		$k = self::counter_key( 'soft', $ip );
		set_transient( $k, (int) get_transient( $k ) + 1, $ttl );
	}

	private static function is_soft_flagged( $ip ) {
		return (int) get_transient( self::counter_key( 'soft', $ip ) ) > 0;
	}

	/**
	 * Affiche la page-écran du défi : elle exécute du JS pour poser le cookie
	 * signé puis navigue vers l'URL suivante (compteur anti-boucle). Toutes les
	 * valeurs serveur sont encodées en JSON → aucune injection possible.
	 */
	private static function render_challenge_page( $next_url, $token, $ttl_seconds ) {
		if ( ! headers_sent() ) {
			status_header( 503 );
			nocache_headers();
			header( 'Retry-After: 5' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Content-Type: text/html; charset=UTF-8' );
		}
		$token_b64 = wp_json_encode( base64_encode( $token ) );
		$next_js   = wp_json_encode( $next_url );
		$secure_js = is_ssl() ? ';Secure' : '';
		echo self::public_page_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans public_page_html() ; valeurs du script encodées en JSON.
			array(
				'variant' => 'challenge',
				'title'   => __( 'Vérification du navigateur…', 'cyberpush-ultra' ),
				'heading' => __( 'Vérification de votre navigateur', 'cyberpush-ultra' ),
				'message' => __( 'Nous vérifions que vous n\'êtes pas un robot. Cette page se chargera automatiquement dans un instant.', 'cyberpush-ultra' ),
				'extra'   => '<noscript><span class="ns">' . esc_html__( 'Veuillez activer JavaScript et les cookies pour accéder à ce site.', 'cyberpush-ultra' ) . '</span></noscript>',
				'script'  => '<script>(function(){try{var t=atob(' . $token_b64 . ');document.cookie="cu_jsc="+t+";path=/;max-age=' . (int) $ttl_seconds . ';SameSite=Lax' . $secure_js . '";location.href=' . $next_js . ';}catch(e){}})();</script>',
			)
		);
		exit;
	}

	/**
	 * Page finale (après échec répété du défi : JS ou cookies désactivés). Pas de
	 * rechargement → stoppe toute boucle. Les bots sans JS restent ici, bloqués.
	 */
	private static function render_challenge_final_page() {
		if ( ! headers_sent() ) {
			status_header( 503 );
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Content-Type: text/html; charset=UTF-8' );
		}
		echo self::public_page_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans public_page_html().
			array(
				'variant' => 'js',
				'title'   => __( 'JavaScript requis', 'cyberpush-ultra' ),
				'heading' => __( 'JavaScript et cookies requis', 'cyberpush-ultra' ),
				'message' => __( 'Pour accéder à ce site, activez JavaScript et les cookies dans votre navigateur, puis rechargez la page.', 'cyberpush-ultra' ),
			)
		);
		exit;
	}

	// -----------------------------------------------------------------------
	// Radar — observateur passif (le « filet »)
	// -----------------------------------------------------------------------

	/**
	 * Journalise un visiteur AUTORISÉ mais suspect dans la table de surveillance
	 * (firewall_watch), sans jamais le bloquer. Seuls des signaux LOCAUX (peu
	 * coûteux) sont évalués ici ; l'enrichissement réseau (datacenter/proxy/org)
	 * est fait plus tard, à la demande, quand l'admin ouvre le Radar. Écriture
	 * throttlée (30 s/IP) pour rester légère même sous rafale.
	 */
	private static function radar_observe( $ip ) {
		if ( ! self::tables_available() ) {
			return;
		}
		// IP privée/réservée (loopback, LAN, requêtes internes) ou du serveur → pas un visiteur externe.
		if ( self::is_infra_ip( $ip ) ) {
			return;
		}

		$ua = self::current_ua();

		// Trafic interne WordPress (loopback, pingback, health-check) : jamais un suspect.
		if ( '' !== $ua && false !== stripos( $ua, 'wordpress' ) ) {
			return;
		}
		$signals = array();
		$score   = 0;

		if ( '' === trim( $ua ) ) {
			$signals[] = 'empty_ua';
			$score    += 25;
		} elseif ( self::matches_bad_bot( $ua ) ) {
			$signals[] = 'bot';
			$score    += 25;
		} elseif ( false === stripos( $ua, 'mozilla' ) && ! self::looks_like_good_bot_ua( $ua ) ) {
			$signals[] = 'nonbrowser';
			$score    += 15;
		}

		if ( self::is_soft_flagged( $ip ) ) {
			$signals[] = 'flooder';
			$score    += 20;
		}

		$max = (int) CU_Settings::get( 'firewall', 'rate_limit_max_requests', 120 );
		if ( $max > 0 ) {
			$cnt = self::fixed_window_counter_value( self::counter_key( 'rl', $ip ), self::counter_window( 'rl' ) );
			if ( $cnt >= max( 1, (int) floor( $max / 2 ) ) ) {
				$signals[] = 'rate';
				$score    += 15;
			}
		}

		// Rien de suspect → on ne remplit pas le filet (on ne surveille pas les humains normaux).
		if ( empty( $signals ) ) {
			return;
		}

		// Écriture limitée à une fois toutes les 30 s par IP (lu seulement pour
		// les visiteurs déjà suspects : aucun coût pour un visiteur ordinaire).
		$throttle = 'cu_watch_' . CU_Geo::ip_key( $ip );
		if ( get_transient( $throttle ) ) {
			return;
		}

		// Un crawler de moteur VÉRIFIÉ (FCrDNS) n'est jamais un suspect, même
		// depuis un datacenter (Googlebot/Bingbot vivent dans des datacenters).
		// Le filtre UA préalable évite tout DNS sur les requêtes non concernées.
		if ( self::looks_like_good_bot_ua( $ua ) && self::is_verified_good_bot( $ip, $ua ) ) {
			return;
		}

		set_transient( $throttle, 1, 30 );

		// Requête publique autorisée : jamais d'appel HTTP de géolocalisation ici.
		// Le pays est complété plus tard par l'enrichissement Radar (cron/admin).
		// URL : chemin seul (les paramètres peuvent contenir des données personnelles).
		$country = CU_Geo::country_for_ip( $ip, false );
		$url     = self::clean_log_text( self::uri_without_query(), 255 );
		self::radar_upsert( $ip, $country, self::clean_log_text( $ua, 255 ), $url, $signals, min( 100, $score ) );
	}

	/** Un User-Agent figure-t-il dans la liste des bots malveillants configurée ? */
	private static function matches_bad_bot( $ua ) {
		foreach ( (array) CU_Settings::get( 'firewall', 'bad_bot_signatures', array() ) as $sig ) {
			if ( ! empty( $sig ) && false !== stripos( $ua, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Insère ou met à jour l'entrée de surveillance d'une IP (upsert atomique).
	 * Le statut « ignored » (posé manuellement par l'admin) reste collant : de
	 * nouveaux hits ne le repassent pas en « observed ».
	 */
	private static function radar_upsert( $ip, $country, $ua, $url, $signals, $score ) {
		global $wpdb;
		$table = CU_DB::table( 'firewall_watch' );
		$now   = current_time( 'mysql' );
		$sig   = implode( ',', array_unique( $signals ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (ip, country_code, ua_sample, last_url, signals, score, hits, status, first_seen, last_seen)
			 VALUES (%s, %s, %s, %s, %s, %d, 1, 'observed', %s, %s)
			 ON DUPLICATE KEY UPDATE
			   hits = hits + 1,
			   last_seen = VALUES(last_seen),
			   signals = VALUES(signals),
			   ua_sample = VALUES(ua_sample),
			   last_url = VALUES(last_url),
			   score = GREATEST(score, VALUES(score)),
			   country_code = COALESCE(NULLIF(VALUES(country_code), ''), country_code),
			   status = IF(status = 'ignored', 'ignored', 'observed')",
			$ip,
			$country ? $country : '',
			CU_Text::substr( $ua, 0, 255 ),
			$url,
			$sig,
			(int) $score,
			$now,
			$now
		) );
		if ( false === $result ) {
			self::log_db_failure_once( 'radar_upsert' );
		}
	}

	/**
	 * Refuse une requête d'énumération d'utilisateurs. Le premier accès est
	 * simplement renvoyé en 403 (prudence : un ?author=N isolé n'est pas banni,
	 * pour éviter tout faux positif). Mais un énumérateur RÉCIDIVISTE (plusieurs
	 * tentatives sur une courte fenêtre — auteur ET/OU API REST confondus) est
	 * banni. Les requêtes provoquées par une page tierce ne comptent jamais.
	 */
	private static function block_enumeration( $ip, $rule ) {
		// Un crawler de moteur vérifié n'est jamais 403 (même sur ?author=N) : on
		// le laisse poursuivre (WordPress redirigera vers l'archive auteur légitime).
		if ( self::is_good_bot_request( $ip ) ) {
			return;
		}
		$can_count = ! self::is_infra_ip( $ip ) && ! self::is_cross_site_request();
		// Si l'escalade a banni l'IP, block_ip a déjà journalisé → on ne relogue pas.
		if ( ! $can_count || ! self::escalate_enumeration( $ip, $rule ) ) {
			self::log_event( $ip, 'blocked', $rule, 'medium' );
		}
		self::render_blocked_page( $ip );
	}

	/**
	 * Compte les tentatives d'énumération d'une IP sur une fenêtre fixe et
	 * la bannit au-delà du seuil. Le compteur est partagé entre l'énumération par
	 * ?author=N et par l'API REST (mêmes intentions).
	 *
	 * @return bool True si un bannissement a été tenté (block_ip appelé, donc déjà
	 *              journalisé) ; false si on est encore sous le seuil.
	 */
	/** Fenêtre du compteur d'énumération (réglage en minutes). */
	private static function enumeration_window() {
		return max( 1, (int) CU_Settings::get( 'firewall', 'enum_escalation_window_minutes', 10 ) ) * MINUTE_IN_SECONDS;
	}

	private static function escalate_enumeration( $ip, $rule ) {
		if ( ! CU_Settings::get( 'firewall', 'enum_escalation_enabled', true ) ) {
			return false;
		}
		$max = (int) CU_Settings::get( 'firewall', 'enum_escalation_max', 5 );
		if ( $max < 2 ) {
			$max = 2; // jamais bannir dès le 1er accès.
		}
		$state  = self::increment_fixed_window_counter( self::counter_key( 'enum', $ip ), self::enumeration_window() );
		$count  = (int) $state['count'];

		if ( $count >= $max ) {
			$duration = (string) CU_Settings::get( 'firewall', 'enum_escalation_duration', '24h' );
			self::block_ip(
				$ip,
				sprintf(
					/* translators: %d: nombre de tentatives d'énumération */
					__( 'Énumération d\'utilisateurs répétée (%d tentatives)', 'cyberpush-ultra' ),
					$count
				),
				'enum_escalation',
				'high',
				false,
				$duration
			);
			return true;
		}
		return false;
	}

	/** Une IP provient-elle d'un pays configuré pour réduire les faux positifs ? */
	private static function is_trusted_country_ip( $ip ) {
		$trusted = self::trusted_country_codes();
		if ( empty( $trusted ) ) {
			return false;
		}
		$country = CU_Geo::country_for_ip( $ip );
		return $country && in_array( strtoupper( $country ), $trusted, true );
	}

	/** Codes pays nettoyés, y compris face à une ancienne option corrompue. */
	private static function trusted_country_codes() {
		$codes = array();
		foreach ( (array) CU_Settings::get( 'firewall', 'trusted_countries', array() ) as $raw ) {
			if ( is_scalar( $raw ) ) {
				$code = strtoupper( trim( (string) $raw ) );
				if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
					$codes[] = $code;
				}
			}
		}
		return array_values( array_unique( $codes ) );
	}

	/**
	 * Un pays de confiance adoucit les protections comportementales, mais n'est
	 * jamais une identité. Les preuves techniques fortes restent bloquantes.
	 */
	private static function trusted_country_can_bypass( $rule, $severity ) {
		if ( 'critical' === $severity || 0 === strpos( (string) $rule, 'waf_' ) ) {
			return false;
		}
		$hard_rules = array(
			'probe_detection', 'scanner_detection', 'rest_batch', 'rest_sqli_author',
			'method_block', 'url_keyword', 'xmlrpc_protection',
		);
		return ! in_array( (string) $rule, $hard_rules, true );
	}

	/**
	 * Une escalade suit les infractions distinctes, jamais les pages refusées
	 * pendant un ban. Plafonds : 24 h pour les règles comportementales (une IP
	 * partagée — CGNAT, Wi-Fi d'école — peut les déclencher sans attaque) et
	 * 7 jours pour les autres (filtre `cu_firewall_max_auto_ban`). Un
	 * bannissement automatique n'est jamais permanent : seul l'administrateur
	 * peut le décider.
	 */
	private static function next_escalation_duration( $ip, $rule = '' ) {
		$defaults = array( '30m', '1h', '2h', '4h', '8h', '24h', '7d' );
		$steps    = array();
		foreach ( (array) CU_Settings::get( 'firewall', 'escalation_steps', $defaults ) as $step ) {
			if ( is_scalar( $step ) && '' !== trim( (string) $step ) ) {
				$steps[] = trim( (string) $step );
			}
		}
		if ( empty( $steps ) ) {
			$steps = $defaults;
		}
		$key   = 'cu_fw_strikes_v3_' . CU_Geo::ip_key( $ip );
		$level = max( 0, (int) get_transient( $key ) );
		$index = min( $level, count( $steps ) - 1 );
		set_transient( $key, min( $level + 1, count( $steps ) ), 30 * DAY_IN_SECONDS );
		return self::cap_auto_duration( (string) $steps[ $index ], $rule );
	}

	/** Plafonne une durée de bannissement AUTOMATIQUE (voir next_escalation_duration()). */
	private static function cap_auto_duration( $duration, $rule ) {
		$behavioral = in_array( (string) $rule, array( 'rate_limit', 'flood_404' ), true );
		$cap        = $behavioral ? '24h' : (string) apply_filters( 'cu_firewall_max_auto_ban', '7d', (string) $rule );
		return self::duration_seconds( $duration ) > self::duration_seconds( $cap ) ? $cap : $duration;
	}

	/** Durée en secondes (« permanent » = PHP_INT_MAX ; inconnue = 1 h). */
	private static function duration_seconds( $duration ) {
		if ( is_int( $duration ) || ( is_string( $duration ) && ctype_digit( $duration ) ) ) {
			return max( 1, (int) $duration );
		}
		if ( 'permanent' === $duration ) {
			return PHP_INT_MAX;
		}
		$map = self::duration_map();
		return isset( $map[ $duration ] ) ? $map[ $duration ] : HOUR_IN_SECONDS;
	}

	/** Durées acceptées. */
	private static function duration_map() {
		return array(
			'15m' => 15 * MINUTE_IN_SECONDS,
			'30m' => 30 * MINUTE_IN_SECONDS,
			'1h'  => HOUR_IN_SECONDS,
			'2h'  => 2 * HOUR_IN_SECONDS,
			'4h'  => 4 * HOUR_IN_SECONDS,
			'6h'  => 6 * HOUR_IN_SECONDS,
			'8h'  => 8 * HOUR_IN_SECONDS,
			'12h' => 12 * HOUR_IN_SECONDS,
			'24h' => DAY_IN_SECONDS,
			'7d'  => 7 * DAY_IN_SECONDS,
			'30j' => 30 * DAY_IN_SECONDS,
		);
	}

	/**
	 * Bannit automatiquement une IP (adresse exacte, jamais tout un /64).
	 *
	 * @param string          $ip
	 * @param string          $reason
	 * @param string          $rule      Nom du module (ex: flood_404).
	 * @param string          $severity  low | medium | high | critical
	 * @param bool            $escalate  Si true, utilise les escalation_steps (plafonnées).
	 * @param string|int|null $duration  Durée forcée, ou secondes entières.
	 * @return bool True si la requête courante doit être refusée (bannissement
	 *              enregistré, ou adresse protégée : refus sans bannissement) ;
	 *              false si elle doit passer (administrateur, crawler vérifié,
	 *              pays de confiance : détection journalisée seulement).
	 */
	private static function block_ip( $ip, $reason, $rule, $severity = 'medium', $escalate = false, $duration = null ) {
		if ( ! self::tables_available() ) {
			return false;
		}

		// Ne JAMAIS bannir un administrateur (session cookie, même sans nonce REST).
		if ( self::cookie_admin() ) {
			return false;
		}

		// Ne JAMAIS bannir automatiquement un crawler de moteur VÉRIFIÉ. La preuve
		// combine le User-Agent avec une plage IP officielle ou un FCrDNS strict ;
		// le User-Agent seul ne suffit jamais.
		if ( self::is_good_bot_request( $ip ) ) {
			self::log_event( $ip, 'allowed', 'goodbot_spared', 'low' );
			return false;
		}

		// Pays de confiance : les règles comportementales sont journalisées sans
		// bannissement (une limite temporaire 429 peut toujours s'appliquer).
		$country    = CU_Geo::country_for_ip( $ip );
		$is_trusted = $country && in_array( strtoupper( $country ), self::trusted_country_codes(), true );
		if ( $is_trusted && self::trusted_country_can_bypass( $rule, $severity ) ) {
			self::log_event(
				$ip,
				'allowed',
				'trusted_country_spared',
				'low',
				null,
				/* translators: 1: IP, 2: rule name */
				sprintf( __( 'Détection épargnée [%1$s] — pays de confiance, règle : %2$s', 'cyberpush-ultra' ), $ip, $rule ),
				false,
				array( 'reason' => $reason, 'country' => $country )
			);
			return false;
		}

		// Adresse non publique, relais de confiance ou serveur lui-même : la
		// bannir rendrait le site inaccessible à tous. Refus de CETTE requête seulement.
		if ( self::is_infra_ip( $ip ) ) {
			self::warn_infra_ip_once( $ip );
			self::log_event( $ip, 'blocked', $rule, $severity, 403 );
			return true;
		}

		if ( null === $duration ) {
			$duration = $escalate
				? self::next_escalation_duration( $ip, $rule )
				: CU_Settings::get( 'firewall', 'auto_block_duration', '1h' );
		}

		// Un bannissement est un événement rare et important : jamais limité.
		self::log_event(
			$ip,
			'blocked',
			$rule,
			$severity,
			403,
			/* translators: 1: IP, 2: rule name */
			sprintf( __( 'IP bloquée [%1$s] — règle : %2$s', 'cyberpush-ultra' ), $ip, $rule ),
			true,
			array( 'reason' => $reason, 'country' => $country, 'duration' => (string) $duration )
		);

		// Un blocage existant n'est jamais raccourci (un ban permanent le reste).
		$ok = self::persist_block( $ip, 'auto', $reason, self::duration_to_expires( $duration ), array(
			'never_shorten' => true,
			'country'       => $country,
		) );
		if ( ! $ok ) {
			// La requête dangereuse courante reste refusée (fail-closed), mais rendre
			// visible l'échec de persistance pour le diagnostic administrateur.
			CU_Logger::log( 'firewall', 'error', __( 'Échec SQL pendant la persistance d’un blocage automatique.', 'cyberpush-ultra' ), array(
				'ip'     => $ip,
				'rule'   => $rule,
				'reason' => $reason,
			) );
		}
		return true;
	}

	/**
	 * Journalise un événement du pare-feu, limité par défaut à une ligne par
	 * (IP, règle) et par minute : un client qui répète une requête refusée ne
	 * peut pas inonder la base. $force = true pour les événements rares
	 * (bannissement, verrou).
	 */
	private static function log_event( $ip, $decision, $rule, $severity, $code = null, $message = '', $force = false, array $context = array() ) {
		if ( ! $force && ! self::should_log( $ip, $rule ) ) {
			return;
		}
		self::log_request( $ip, $decision, $rule, $severity, $code );
		if ( '' !== (string) $message ) {
			CU_Logger::log( 'firewall', 'blocked' === $decision ? 'warning' : 'info', $message, array_merge( array( 'ip' => $ip, 'rule' => $rule ), $context ) );
		}
	}

	/**
	 * Insère une ligne dans cu_firewall_logs. Les textes (URL, User-Agent,
	 * référent) sont stockés BRUTS (tronqués, UTF-8 valide, sans caractères de
	 * contrôle) : l'échappement se fait à l'affichage. Pour une requête non
	 * bloquée, l'URL et le référent sont enregistrés sans leurs paramètres.
	 */
	private static function log_request( $ip, $decision, $rule = null, $severity = null, $code = null ) {
		global $wpdb;
		if ( ! self::tables_available() ) {
			return;
		}

		// Les requêtes autorisées/observées, la maintenance, les limites de débit
		// et le hotlink ne doivent jamais attendre une API externe : seules les
		// vraies détections justifient une résolution distante.
		$allow_remote = 'blocked' === $decision && ! in_array( $rule, array( 'maintenance_mode', 'hotlink', 'rate_limit', 'login_rate_limit' ), true );
		$country      = CU_Geo::country_for_ip( $ip, $allow_remote );
		$blocked      = 'blocked' === $decision;
		$raw_uri      = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$raw_referer  = isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		if ( ! $blocked ) {
			$raw_uri     = self::strip_query( $raw_uri );
			$raw_referer = self::strip_query( $raw_referer );
		}
		$ua = self::current_ua();
		if ( null === $code && $blocked ) {
			$code = 'maintenance_mode' === $rule ? 503 : 403;
		}

		$inserted = $wpdb->insert( CU_DB::table( 'firewall_logs' ), array(
			'created_at'    => current_time( 'mysql' ),
			'ip'            => $ip,
			'country_code'  => $country ?: null,
			'url'           => '' !== $raw_uri ? self::clean_log_text( $raw_uri, 500 ) : null,
			'method'        => self::request_method(),
			'response_code' => null !== $code ? (int) $code : null,
			'user_agent'    => '' !== $ua ? self::clean_log_text( $ua, 255 ) : null,
			'referer'       => '' !== $raw_referer ? self::clean_log_text( $raw_referer, 255 ) : null,
			'rule'          => $rule,
			'decision'      => $decision,
			'severity'      => $severity,
		) );
		if ( false === $inserted ) {
			self::log_db_failure_once( 'request_log' );
		}
	}

	/** Texte brut sûr à stocker : UTF-8 valide, sans caractères de contrôle, tronqué. */
	private static function clean_log_text( $text, $max ) {
		$text = (string) $text;
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$text = wp_check_invalid_utf8( $text, true );
		}
		$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $text );
		return CU_Text::substr( trim( (string) $text ), 0, (int) $max );
	}

	/** URL sans sa requête ni son fragment. */
	private static function strip_query( $url ) {
		$url = (string) $url;
		$cut = strcspn( $url, '?#' );
		return substr( $url, 0, $cut );
	}

	/** Chemin de la requête courante, sans paramètres. */
	private static function uri_without_query() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return self::strip_query( $uri );
	}

	/** Journalise une panne SQL au plus une fois par opération et par tranche de 5 minutes. */
	private static function log_db_failure_once( $operation ) {
		global $wpdb;
		$operation = sanitize_key( $operation );
		$key       = 'cu_fw_db_' . md5( $operation );
		$db_error  = CU_Text::substr( sanitize_text_field( (string) $wpdb->last_error ), 0, 500 );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );
		CU_Logger::log( 'firewall', 'error', __( 'Échec SQL dans le pare-feu.', 'cyberpush-ultra' ), array(
			'operation' => $operation,
			'db_error'  => $db_error,
		) );
	}

	/**
	 * Convertit une durée en datetime MySQL (ou null pour permanent).
	 * Un entier positif représente un nombre de secondes. Seule la valeur
	 * explicite « permanent » produit un blocage sans fin : une durée vide ou
	 * inconnue vaut 1 h (jamais un bannissement permanent par accident).
	 */
	private static function duration_to_expires( $duration ) {
		if ( is_int( $duration ) || ( is_string( $duration ) && ctype_digit( $duration ) ) ) {
			$seconds = max( 1, min( 30 * DAY_IN_SECONDS, (int) $duration ) );
			return CU_DB::date_from_now( 'Y-m-d H:i:s', $seconds );
		}
		if ( 'permanent' === $duration ) {
			return null;
		}
		$map     = self::duration_map();
		$seconds = ( is_string( $duration ) && isset( $map[ $duration ] ) ) ? $map[ $duration ] : HOUR_IN_SECONDS;
		return CU_DB::date_from_now( 'Y-m-d H:i:s', $seconds );
	}

	/**
	 * Retourne un tableau descriptif de chaque module et son état.
	 */
	private function get_modules_status( $fw ) {
		global $wpdb;
		$logs_table = CU_DB::table( 'firewall_logs' );
		$since_24h  = CU_DB::date_from_now( 'Y-m-d H:i:s', -DAY_IN_SECONDS );

		$modules = array(
			array( 'key' => 'flood_404',         'setting' => 'flood_404_enabled',         'rule' => 'flood_404' ),
			array( 'key' => 'probe_detection',    'setting' => 'probe_detection_enabled',    'rule' => 'probe_detection' ),
			array( 'key' => 'scanner_detection',  'setting' => 'scanner_detection_enabled',  'rule' => 'scanner_detection' ),
			array( 'key' => 'bad_bot',            'setting' => 'bad_bot_blocking_enabled',   'rule' => 'bad_bot' ),
			array( 'key' => 'login_protection',   'setting' => 'login_protection_enabled',   'rule' => 'login_protection' ),
			array( 'key' => 'xmlrpc_protection',  'setting' => 'xmlrpc_protection',          'rule' => 'xmlrpc_protection' ),
			array( 'key' => 'restapi_protection', 'setting' => 'restapi_protection_enabled', 'rule' => 'restapi_protection' ),
			array( 'key' => 'upload_protection',  'setting' => 'upload_protection_enabled',  'rule' => 'upload_protection' ),
			array( 'key' => 'rate_limit',         'setting' => 'rate_limit_enabled',         'rule' => 'rate_limit' ),
		);

		$result = array();
		foreach ( $modules as $m ) {
			if ( 'xmlrpc_protection' === $m['key'] ) {
				$xmlrpc_mode = isset( $fw[ $m['setting'] ] ) ? (string) $fw[ $m['setting'] ] : 'off';
				// `disabled` est la protection maximale, pas un module inactif.
				$enabled = 'off' !== $xmlrpc_mode;
			} else {
				$enabled = isset( $fw[ $m['setting'] ] ) ? (bool) $fw[ $m['setting'] ] : false;
			}
			$count   = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$logs_table} WHERE rule = %s AND decision = 'blocked' AND created_at >= %s",
				$m['rule'],
				$since_24h
			) );
			$row = array(
				'key'       => $m['key'],
				'enabled'   => $enabled,
				'hits_24h'  => $count,
			);
			if ( 'xmlrpc_protection' === $m['key'] ) {
				$row['mode'] = $xmlrpc_mode; // disabled | limited | off
			}
			$result[] = $row;
		}
		return $result;
	}
}

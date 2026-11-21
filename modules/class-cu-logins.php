<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Protection des connexions : surveillance des accès PRIVILÉGIÉS (administrateurs,
 * et sur option les autres comptes à privilèges élevés). Le module observe,
 * enregistre et alerte ; il ne bloque, ne déconnecte, ne suspend jamais rien
 * (la prévention reste au pare-feu : limitation des tentatives, verrous).
 *
 * Réutilise :
 *  - la résolution d'adresse IP du pare-feu (CU_Geo::client_ip, proxys de confiance) ;
 *  - la base de pays locale DB-IP (aucun appel réseau) ;
 *  - le compteur d'échecs par IP du pare-feu (lecture seule) ;
 *  - le Journal de sécurité pour les changements de comptes (création, promotion,
 *    mots de passe, e-mail…) et ses alertes e-mail : ces événements sont recopiés
 *    ici pour être consultés par compte, sans second envoi.
 *
 * Écritures : une ligne par connexion réussie (wp_login), par accès à
 * l'administration avec une session existante depuis une AUTRE connexion réseau,
 * et par changement de compte privilégié. Aucune écriture par page consultée,
 * aucune donnée sur les visiteurs publics. Jamais de mot de passe, de cookie, de
 * jeton de session (seule une empreinte courte du jeton sert à reconnaître la
 * session). Conservation : 30 jours par défaut.
 *
 * Limite : une extension d'authentification qui n'appelle pas wp_login (certains
 * SSO) n'est vue qu'au premier accès à l'administration (« session existante »).
 */
class CU_Logins {

	const META_IPS       = 'cu_known_ips';       // empreintes HMAC des IP déjà vues (jamais l'IP en clair)
	const META_COUNTRIES = 'cu_known_countries';
	const META_SESSIONS  = 'cu_session_seen';    // empreinte du jeton de session => empreinte IP
	const META_SEEN      = 'cu_logins_seen';     // dernier événement lu (avis du tableau de bord)
	const OPT_BASELINE   = 'cu_logins_baseline'; // administrateurs connus (état initial)

	/** Événements du Journal de sécurité recopiés, par famille. */
	const ADMIN_NEW   = array( 'admin_created', 'privileged_user_created', 'role_elevated', 'privileges_granted', 'super_admin_granted', 'role_caps_added', 'role_created_privileged', 'admin_appeared' );
	const ADMIN_LOST  = array( 'role_demoted', 'privileges_removed', 'admin_deleted', 'admin_disappeared' );
	const CREDENTIALS = array( 'password_changed', 'password_reset', 'user_email_changed', 'app_password_created', 'app_password_deleted' );

	private static $instance = null;
	private $roles_touched   = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public static function enabled() {
		return (bool) CU_Settings::get( 'audit', 'login_watch', true );
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'wp_login', array( $this, 'on_login' ), 1, 2 );
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 5, 2 );
		add_action( 'set_logged_in_cookie', array( $this, 'on_logged_in_cookie' ), 10, 6 );
		add_action( 'admin_init', array( $this, 'on_admin_access' ), 5 );
		add_action( 'cu_audit_recorded', array( $this, 'mirror_audit' ), 10, 2 );
		foreach ( array( 'set_user_role', 'add_user_role', 'remove_user_role', 'user_register', 'deleted_user', 'granted_super_admin', 'revoked_super_admin' ) as $hook ) {
			add_action( $hook, array( $this, 'roles_touched' ) );
		}
		add_action( 'cu_cron_firewall_maintenance', array( __CLASS__, 'reconcile_admins' ) );
		add_action( 'cu_cron_cleanup', array( __CLASS__, 'purge' ) );
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
			add_action( 'admin_post_cu_logins_dismiss', array( __CLASS__, 'handle_dismiss' ) );
		}
	}

	private static function table_ready() {
		return CU_DB::tables_exist_for( array( 'logins' ) );
	}

	// -------------------------------------------------------------------------
	// Comptes surveillés
	// -------------------------------------------------------------------------

	/** Administrateur (ou super-administrateur), ou, sur option, compte à privilèges élevés. */
	public static function monitored( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) || ( is_multisite() && is_super_admin( $user->ID ) ) ) {
			return true;
		}
		if ( ! CU_Settings::get( 'audit', 'login_watch_privileged', false ) ) {
			return false;
		}
		foreach ( array_merge( CU_Audit::PRIVILEGED_CAPS, array( 'edit_others_posts', 'manage_woocommerce' ) ) as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	private static function role_of( WP_User $user ) {
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return 'super-admin';
		}
		$roles = array_values( (array) $user->roles );
		return $roles ? sanitize_key( (string) $roles[0] ) : '';
	}

	private static function browser_label() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua || ! class_exists( 'CU_Analytics' ) ) {
			return '';
		}
		$p = CU_Analytics::parse_ua( $ua );
		return CU_Text::substr( trim( ( $p['browser'] ?? '' ) . ( ! empty( $p['os'] ) ? ' · ' . $p['os'] : '' ), ' ·' ), 0, 60 );
	}

	/** Adresse fiable (proxys de confiance du pare-feu), '' si inconnue. */
	private static function current_ip() {
		$ip = CU_Geo::client_ip();
		return '0.0.0.0' === $ip ? '' : $ip;
	}

	// -------------------------------------------------------------------------
	// IP et pays déjà observés pour un compte
	// -------------------------------------------------------------------------

	/**
	 * Statut de l'adresse et du pays pour le compte : first (premier accès observé
	 * depuis l'activation du module), known, new ; puis mémorisation.
	 *
	 * @return array{0:string,1:string} statut IP, statut pays
	 */
	private static function observe( $user_id, $ip, $country ) {
		$ips   = get_user_meta( $user_id, self::META_IPS, true );
		$ips   = is_array( $ips ) ? $ips : array();
		$ccs   = get_user_meta( $user_id, self::META_COUNTRIES, true );
		$ccs   = is_array( $ccs ) ? $ccs : array();
		$first = ! $ips;
		$now   = time();

		$ip_status = '';
		if ( '' !== $ip ) {
			$key       = substr( CU_Geo::ip_key( CU_Geo::rate_bucket( $ip ) ), 0, 20 );
			$ip_status = $first ? 'first' : ( isset( $ips[ $key ] ) ? 'known' : 'new' );
			$ips[ $key ] = $now;
			// 50 adresses au plus, oubliées après un an sans usage.
			$ips = array_filter( $ips, function ( $ts ) use ( $now ) {
				return $now - (int) $ts < YEAR_IN_SECONDS;
			} );
			arsort( $ips );
			update_user_meta( $user_id, self::META_IPS, array_slice( $ips, 0, 50, true ) );
		}
		$cc_status = 'unknown';
		if ( '' !== $country ) {
			$cc_status       = ! $ccs ? 'first' : ( isset( $ccs[ $country ] ) ? 'known' : 'new' );
			$ccs[ $country ] = $now;
			arsort( $ccs );
			update_user_meta( $user_id, self::META_COUNTRIES, array_slice( $ccs, 0, 30, true ) );
		}
		return array( $ip_status, $cc_status );
	}

	private static function insert( array $row ) {
		if ( ! self::table_ready() ) {
			return 0;
		}
		global $wpdb;
		$row = array_merge(
			array(
				'created_at'       => current_time( 'mysql' ),
				'user_id'          => 0,
				'user_login'       => '',
				'role'             => null,
				'event'            => 'login',
				'severity'         => 'info',
				'ip'               => null,
				'country_code'     => null,
				'browser'          => null,
				'ip_status'        => null,
				'country_status'   => null,
				'failures_account' => 0,
				'failures_ip'      => 0,
				'actor_id'         => null,
				'actor_login'      => null,
				'message'          => null,
				'audit_id'         => null,
			),
			$row
		);
		$row['user_login'] = CU_Text::substr( (string) $row['user_login'], 0, 60 );
		$row['message']    = null === $row['message'] ? null : CU_Text::substr( (string) $row['message'], 0, 255 );
		$suppress          = $wpdb->suppress_errors( true );
		$ok                = $wpdb->insert( CU_DB::table( 'logins' ), array_filter( $row, function ( $v ) {
			return null !== $v;
		} ) );
		$wpdb->suppress_errors( $suppress );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	// -------------------------------------------------------------------------
	// Connexions
	// -------------------------------------------------------------------------

	/** Clé du compteur d'échecs d'un compte (fenêtre d'une heure). */
	private static function failure_key( $user_id ) {
		return 'cu_lfa_' . (int) $user_id;
	}

	/**
	 * Échec de connexion sur un compte EXISTANT et surveillé : simple compteur de
	 * contexte (la limitation et les verrous restent au pare-feu).
	 */
	public function on_login_failed( $username, $error = null ) {
		try {
			if ( $error instanceof WP_Error && ! array_intersect( $error->get_error_codes(), array( 'incorrect_password', 'cu_login_blocked', 'invalid_email', 'invalid_username' ) ) ) {
				return; // code 2FA, extension SSO… : pas un échec d'identifiants
			}
			$username = (string) $username;
			$user     = is_email( $username ) ? get_user_by( 'email', $username ) : get_user_by( 'login', $username );
			if ( $user && self::monitored( $user ) ) {
				CU_DB::counter_increment( self::failure_key( $user->ID ), HOUR_IN_SECONDS );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Connexion réussie : UN événement. Toute erreur est absorbée, la connexion
	 * n'est jamais empêchée par la journalisation.
	 *
	 * @param string  $login
	 * @param WP_User $user
	 */
	public function on_login( $login, $user = null ) {
		try {
			if ( ! $user instanceof WP_User || ! self::monitored( $user ) ) {
				return;
			}
			$ip      = self::current_ip();
			$country = '' !== $ip ? (string) CU_Geo::country_for_ip( $ip, false ) : '';
			// Double soumission du formulaire : une seule ligne.
			$dedupe = 'cu_lg_' . md5( $user->ID . '|' . $ip );
			if ( get_transient( $dedupe ) ) {
				return;
			}
			set_transient( $dedupe, 1, MINUTE_IN_SECONDS );

			$fail_account = (int) CU_DB::counter_value( self::failure_key( $user->ID ), HOUR_IN_SECONDS );
			$fail_ip      = '' !== $ip && class_exists( 'CU_Firewall' ) ? (int) CU_Firewall::login_failures_for_ip( $ip ) : 0;
			list( $ip_status, $cc_status ) = self::observe( $user->ID, $ip, $country );

			$severity = 'info';
			$parts    = array( __( 'Connexion réussie.', 'cyberpush-ultra' ) );
			if ( 'new' === $ip_status ) {
				$severity = 'notice';
				$parts[]  = __( 'Nouvelle adresse IP détectée pour ce compte.', 'cyberpush-ultra' );
			} elseif ( 'first' === $ip_status ) {
				$parts[] = __( 'Premier accès observé depuis l’activation de la surveillance.', 'cyberpush-ultra' );
			}
			if ( 'new' === $cc_status ) {
				$severity = 'notice';
				/* translators: %s: code pays */
				$parts[] = sprintf( __( 'Première connexion observée depuis ce pays (%s, indicatif).', 'cyberpush-ultra' ), $country );
			}
			if ( $fail_account >= 3 || $fail_ip >= 3 ) {
				$severity = 'warning';
				$detail   = array();
				if ( $fail_account >= 3 ) {
					/* translators: %d: nombre */
					$detail[] = sprintf( _n( '%d échec récent sur ce compte', '%d échecs récents sur ce compte', $fail_account, 'cyberpush-ultra' ), $fail_account );
				}
				if ( $fail_ip >= 3 ) {
					/* translators: %d: nombre */
					$detail[] = sprintf( _n( '%d échec récent depuis cette adresse', '%d échecs récents depuis cette adresse', $fail_ip, 'cyberpush-ultra' ), $fail_ip );
				}
				$parts[] = __( 'Connexion réussie après plusieurs tentatives échouées récentes', 'cyberpush-ultra' ) . ' (' . implode( ', ', $detail ) . ').';
			}
			// Élément probant : beaucoup d'échecs SUR CE COMPTE puis une réussite depuis une adresse jamais vue.
			$alert = $fail_account >= 5 && 'new' === $ip_status;
			if ( $alert ) {
				$severity = 'alert';
			}
			$id = self::insert( array(
				'user_id'          => (int) $user->ID,
				'user_login'       => $user->user_login,
				'role'             => self::role_of( $user ),
				'event'            => 'login',
				'severity'         => $severity,
				'ip'               => '' !== $ip ? $ip : null,
				'country_code'     => '' !== $country ? $country : null,
				'browser'          => self::browser_label(),
				'ip_status'        => $ip_status ?: null,
				'country_status'   => $cc_status,
				'failures_account' => min( 65535, $fail_account ),
				'failures_ip'      => min( 65535, $fail_ip ),
				'actor_id'         => (int) $user->ID,
				'actor_login'      => $user->user_login,
				'message'          => implode( ' ', $parts ),
			) );
			if ( $fail_account ) {
				CU_DB::counter_reset( self::failure_key( $user->ID ), HOUR_IN_SECONDS );
			}
			if ( $alert ) {
				self::email( 'login_after_failures', $user, $ip, $country, __( 'Connexion réussie à un compte administrateur depuis une adresse jamais utilisée par ce compte, juste après plusieurs échecs de mot de passe sur ce même compte. Ce peut être l’administrateur qui avait oublié son mot de passe : vérifiez auprès de lui.', 'cyberpush-ultra' ), $id, $fail_account );
			} elseif ( ( 'new' === $ip_status || 'new' === $cc_status ) && CU_Settings::get( 'audit', 'login_email_new_ip', false ) ) {
				self::email( 'login_new_ip', $user, $ip, $country, 'new' === $cc_status ? __( 'Connexion depuis un pays jamais observé pour ce compte (indicatif : VPN, mobile ou proxy possibles).', 'cyberpush-ultra' ) : __( 'Connexion depuis une adresse IP jamais observée pour ce compte (réseau mobile, VPN ou nouveau lieu possibles).', 'cyberpush-ultra' ), $id, 0 );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/** Empreinte courte d'un jeton de session (le jeton lui-même n'est jamais conservé). */
	private static function token_hash( $token ) {
		return substr( hash_hmac( 'sha256', (string) $token, wp_salt( 'nonce' ) . '|cu-session' ), 0, 20 );
	}

	/** Nouvelle session ouverte : son réseau d'origine est mémorisé. */
	public function on_logged_in_cookie( $cookie, $expire, $expiration, $user_id, $scheme = '', $token = '' ) {
		try {
			$user = get_userdata( (int) $user_id );
			if ( '' === (string) $token || ! $user || ! self::monitored( $user ) ) {
				return;
			}
			$ip = self::current_ip();
			self::remember_session( (int) $user_id, self::token_hash( $token ), '' !== $ip ? substr( CU_Geo::ip_key( CU_Geo::rate_bucket( $ip ) ), 0, 20 ) : '' );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	private static function remember_session( $user_id, $hash, $ip_key ) {
		$seen          = get_user_meta( $user_id, self::META_SESSIONS, true );
		$seen          = is_array( $seen ) ? $seen : array();
		$seen[ $hash ] = array( $ip_key, time() );
		uasort( $seen, function ( $a, $b ) {
			return (int) $b[1] - (int) $a[1];
		} );
		update_user_meta( $user_id, self::META_SESSIONS, array_slice( $seen, 0, 10, true ) );
	}

	/**
	 * Accès à l'administration avec une session DÉJÀ ouverte : un événement
	 * seulement si le réseau diffère de celui de cette session (ou, pour une
	 * session antérieure au module, si l'adresse est inconnue du compte). Aucune
	 * écriture quand rien ne change ; jamais pour AJAX, REST ni cron. Ce n'est
	 * jamais présenté comme une nouvelle connexion.
	 */
	public function on_admin_access() {
		try {
			if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET' ) ) {
				return;
			}
			$user = wp_get_current_user();
			if ( ! $user || ! $user->exists() || ! self::monitored( $user ) ) {
				return;
			}
			$token = function_exists( 'wp_get_session_token' ) ? wp_get_session_token() : '';
			if ( '' === (string) $token ) {
				return;
			}
			$ip = self::current_ip();
			if ( '' === $ip ) {
				return;
			}
			$hash   = self::token_hash( $token );
			$ip_key = substr( CU_Geo::ip_key( CU_Geo::rate_bucket( $ip ) ), 0, 20 );
			$seen   = get_user_meta( $user->ID, self::META_SESSIONS, true );
			$seen   = is_array( $seen ) ? $seen : array();
			if ( isset( $seen[ $hash ] ) && $seen[ $hash ][0] === $ip_key ) {
				return; // même session, même réseau : rien à écrire
			}
			$known_ips = get_user_meta( $user->ID, self::META_IPS, true );
			$was_known = is_array( $known_ips ) && isset( $known_ips[ $ip_key ] );
			self::remember_session( $user->ID, $hash, $ip_key );
			if ( ! isset( $seen[ $hash ] ) && $was_known ) {
				// Session antérieure au module, réseau déjà connu : mémorisée sans bruit.
				return;
			}
			$country                       = (string) CU_Geo::country_for_ip( $ip, false );
			list( $ip_status, $cc_status ) = self::observe( $user->ID, $ip, $country );
			$message = isset( $seen[ $hash ] )
				? __( 'Accès à l’administration avec une session déjà ouverte, depuis une autre connexion réseau (ce n’est pas une nouvelle authentification).', 'cyberpush-ultra' )
				: __( 'Premier accès observé à l’administration avec une session déjà ouverte (ce n’est pas une nouvelle authentification).', 'cyberpush-ultra' );
			if ( 'new' === $ip_status ) {
				$message .= ' ' . __( 'Nouvelle adresse IP pour ce compte.', 'cyberpush-ultra' );
			}
			self::insert( array(
				'user_id'        => (int) $user->ID,
				'user_login'     => $user->user_login,
				'role'           => self::role_of( $user ),
				'event'          => 'session',
				'severity'       => ( 'new' === $ip_status || 'new' === $cc_status ) ? 'notice' : 'info',
				'ip'             => $ip,
				'country_code'   => '' !== $country ? $country : null,
				'browser'        => self::browser_label(),
				'ip_status'      => $ip_status ?: null,
				'country_status' => $cc_status,
				'actor_id'       => (int) $user->ID,
				'actor_login'    => $user->user_login,
				'message'        => $message,
			) );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	// -------------------------------------------------------------------------
	// Changements de comptes (depuis le Journal de sécurité)
	// -------------------------------------------------------------------------

	/** Famille d'un événement pour les filtres de l'interface. */
	public static function group_of( $event, $ip_status = '', $cc_status = '' ) {
		if ( in_array( $event, self::ADMIN_NEW, true ) ) {
			return 'admin_new';
		}
		if ( in_array( $event, self::ADMIN_LOST, true ) ) {
			return 'admin_lost';
		}
		if ( in_array( $event, self::CREDENTIALS, true ) ) {
			return 'credentials';
		}
		if ( 'new' === $ip_status || 'new' === $cc_status ) {
			return 'new_ip';
		}
		return 'session' === $event ? 'session' : 'login';
	}

	/**
	 * Recopie un changement de compte privilégié enregistré par le Journal de
	 * sécurité (qui envoie déjà l'alerte e-mail des événements critiques).
	 */
	public function mirror_audit( $audit_id, $data ) {
		try {
			if ( ! is_array( $data ) || 'users' !== ( $data['category'] ?? '' ) ) {
				return;
			}
			$key = (string) ( $data['event_key'] ?? '' );
			if ( ! in_array( $key, array_merge( self::ADMIN_NEW, self::ADMIN_LOST, self::CREDENTIALS ), true ) ) {
				return;
			}
			$login   = (string) ( $data['object_name'] ?? '' );
			// Événement sur un RÔLE (capacités ajoutées à « editor »…) : jamais rattaché à un compte homonyme.
			$is_role = in_array( $key, array( 'role_caps_added', 'role_created_privileged' ), true );
			$user    = '' !== $login && '#' !== $login[0] && ! $is_role ? get_user_by( 'login', $login ) : false;
			if ( $is_role ) {
				/* translators: %s: nom du rôle */
				$login = sprintf( __( 'rôle « %s »', 'cyberpush-ultra' ), $login );
			}
			// Identifiants d'un compte non surveillé (simple abonné) : hors périmètre.
			if ( in_array( $key, self::CREDENTIALS, true ) && ( ! $user || ! self::monitored( $user ) ) ) {
				return;
			}
			// Changement de rôle tracé par WordPress : l'état de référence suit (pas de
			// fausse alerte « apparu sans passer par WordPress », pas de double e-mail).
			if ( in_array( $key, array_merge( self::ADMIN_NEW, self::ADMIN_LOST ), true ) && ! in_array( $key, array( 'admin_appeared', 'admin_disappeared' ), true ) ) {
				$this->roles_touched();
			}
			$map   = array( 'critical' => 'alert', 'high' => 'warning', 'medium' => 'notice', 'low' => 'info' );
			$actor = isset( $data['actor'] ) && is_array( $data['actor'] ) ? $data['actor'] : array( 'id' => 0, 'login' => '' );
			self::insert( array(
				'user_id'      => $user ? (int) $user->ID : 0,
				'user_login'   => $login,
				'role'         => $user ? self::role_of( $user ) : null,
				'event'        => $key,
				'severity'     => $map[ $data['severity'] ?? 'medium' ] ?? 'notice',
				'ip'           => ! empty( $data['ip'] ) ? (string) $data['ip'] : null,
				'country_code' => ! empty( $data['country'] ) ? (string) $data['country'] : null,
				'actor_id'     => ! empty( $actor['id'] ) ? (int) $actor['id'] : null,
				'actor_login'  => ! empty( $actor['login'] ) ? (string) $actor['login'] : null,
				'message'      => (string) ( $data['message'] ?? '' ),
				'audit_id'     => (int) $audit_id,
			) );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	// -------------------------------------------------------------------------
	// État initial des administrateurs et rapprochement
	// -------------------------------------------------------------------------

	/** @return array<int,string> ID => identifiant des administrateurs actuels. */
	private static function current_admins() {
		$out = array();
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login' ), 'number' => 500 ) ) as $u ) {
			$out[ (int) $u->ID ] = (string) $u->user_login;
		}
		if ( is_multisite() ) {
			foreach ( (array) get_super_admins() as $login ) {
				$u = get_user_by( 'login', $login );
				if ( $u ) {
					$out[ (int) $u->ID ] = (string) $u->user_login;
				}
			}
		}
		return $out;
	}

	/** Un rôle a changé par WordPress (journalisé par le Journal de sécurité) : l'état initial suivra. */
	public function roles_touched() {
		if ( ! $this->roles_touched ) {
			$this->roles_touched = true;
			add_action( 'shutdown', array( __CLASS__, 'save_baseline' ) );
		}
	}

	public static function save_baseline() {
		update_option( self::OPT_BASELINE, array( 'at' => time(), 'checked' => time(), 'admins' => self::current_admins() ), false );
	}

	/**
	 * Toutes les 15 minutes au plus (tâche de maintenance du pare-feu) : un
	 * administrateur apparu ou disparu SANS passer par WordPress (écriture directe
	 * en base, outil externe) est signalé. Première exécution : état initial,
	 * les administrateurs existants ne sont jamais présentés comme nouveaux.
	 */
	public static function reconcile_admins() {
		try {
			$base = get_option( self::OPT_BASELINE, array() );
			if ( ! is_array( $base ) || ! isset( $base['admins'] ) || ! is_array( $base['admins'] ) ) {
				self::save_baseline();
				return;
			}
			if ( time() - (int) ( $base['checked'] ?? 0 ) < 15 * MINUTE_IN_SECONDS ) {
				return;
			}
			$now   = self::current_admins();
			$added = array_diff_key( $now, $base['admins'] );
			$gone  = array_diff_key( $base['admins'], $now );
			if ( ( $added || $gone ) && class_exists( 'CU_Audit' ) ) {
				foreach ( array_slice( $added, 0, 10, true ) as $login ) {
					/* translators: %s: identifiant */
					CU_Audit::instance()->record( 'critical', 'users', 'admin_appeared', sprintf( __( 'Administrateur apparu sans passer par WordPress (création ou modification directe de la base ?) : %s', 'cyberpush-ultra' ), $login ), 'user', $login );
				}
				foreach ( array_slice( $gone, 0, 10, true ) as $login ) {
					/* translators: %s: identifiant */
					CU_Audit::instance()->record( 'high', 'users', 'admin_disappeared', sprintf( __( 'Administrateur disparu sans passer par WordPress : %s', 'cyberpush-ultra' ), $login ), 'user', $login );
				}
			}
			update_option( self::OPT_BASELINE, array( 'at' => (int) ( $base['at'] ?? time() ), 'checked' => time(), 'admins' => $now ), false );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	public static function retention_days() {
		return max( 7, min( 365, (int) CU_Settings::get( 'audit', 'login_retention_days', 30 ) ) );
	}

	public static function purge() {
		if ( ! self::table_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CU_DB::table( 'logins' ) . ' WHERE created_at < %s', CU_DB::date_from_now( 'Y-m-d H:i:s', -self::retention_days() * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// -------------------------------------------------------------------------
	// Alertes
	// -------------------------------------------------------------------------

	/** E-mail sobre (même destinataire et plafonds que les alertes du journal), une fois par compte et adresse par jour. */
	private static function email( $type, WP_User $user, $ip, $country, $why, $event_id, $failures ) {
		if ( ! CU_Settings::get( 'audit', 'email_alerts', true ) || ! method_exists( 'CU_Audit', 'send_security_alert' ) ) {
			return;
		}
		$once = 'cu_lgm_' . md5( $type . '|' . $user->ID . '|' . $ip );
		if ( get_transient( $once ) ) {
			return;
		}
		set_transient( $once, 1, DAY_IN_SECONDS );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array(
			$why,
			'',
			/* translators: %s: date */
			sprintf( __( 'Date : %s', 'cyberpush-ultra' ), current_time( 'mysql' ) ),
			/* translators: 1: identifiant, 2: rôle */
			sprintf( __( 'Compte : %1$s (%2$s)', 'cyberpush-ultra' ), $user->user_login, self::role_of( $user ) ),
			/* translators: 1: adresse IP, 2: pays */
			sprintf( __( 'Adresse IP : %1$s — pays estimé : %2$s', 'cyberpush-ultra' ), '' !== $ip ? $ip : '—', '' !== $country ? $country : __( 'inconnu', 'cyberpush-ultra' ) ),
		);
		if ( $failures ) {
			/* translators: %d: nombre */
			$lines[] = sprintf( __( 'Échecs de mot de passe récents sur ce compte : %d', 'cyberpush-ultra' ), $failures );
		}
		$lines[] = '';
		$lines[] = __( 'Aucune action automatique n’a été prise. Si ce n’était pas vous : changez le mot de passe de ce compte et déconnectez ses sessions (Profil › Se déconnecter partout).', 'cyberpush-ultra' );
		$lines[] = admin_url( 'admin.php?page=cyberpush-ultra-firewall#cu-logins-section' );
		$subject = 'login_after_failures' === $type
			/* translators: %s: nom du site */
			? sprintf( __( '[%s] Connexion inhabituelle à un compte administrateur', 'cyberpush-ultra' ), $site )
			/* translators: %s: nom du site */
			: sprintf( __( '[%s] Connexion administrateur depuis une nouvelle adresse', 'cyberpush-ultra' ), $site );
		CU_Audit::instance()->send_security_alert( $subject, $lines, $type );
	}

	/** Dernières connexions depuis une nouvelle IP / un nouveau pays, ou inhabituelles, non encore lues. */
	private static function unread( $user_id ) {
		if ( ! self::table_ready() ) {
			return array( 0, 0 );
		}
		global $wpdb;
		$seen = (int) get_user_meta( $user_id, self::META_SEEN, true );
		$row  = $wpdb->get_row( $wpdb->prepare(
			'SELECT COUNT(*) AS n, MAX(id) AS last FROM ' . CU_DB::table( 'logins' ) . " WHERE id > %d AND created_at >= %s AND event IN ('login','session') AND ( ip_status = 'new' OR country_status = 'new' OR severity IN ('warning','alert') )", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$seen,
			CU_DB::date_from_now( 'Y-m-d H:i:s', -7 * DAY_IN_SECONDS )
		), ARRAY_A );
		return array( (int) ( $row['n'] ?? 0 ), (int) ( $row['last'] ?? 0 ) );
	}

	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! ( 'dashboard' === $screen->id || false !== strpos( $screen->id, 'cyberpush-ultra' ) ) || false !== strpos( $screen->id, 'cyberpush-ultra-firewall' ) || false !== strpos( $screen->id, 'cyberpush-ultra-dashboard' ) ) {
			return;
		}
		list( $count ) = self::unread( get_current_user_id() );
		if ( ! $count ) {
			return;
		}
		$url     = admin_url( 'admin.php?page=cyberpush-ultra-firewall#cu-logins-section' );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=cu_logins_dismiss' ), 'cu_logins_dismiss' );
		echo '<div class="notice notice-info"><p><strong>CyberPush Ultra — </strong>'
			/* translators: %d: nombre */
			. esc_html( sprintf( _n( '%d connexion à un compte administrateur depuis une nouvelle adresse IP, un nouveau pays ou après des échecs (7 derniers jours).', '%d connexions à des comptes administrateurs depuis une nouvelle adresse IP, un nouveau pays ou après des échecs (7 derniers jours).', $count, 'cyberpush-ultra' ), $count ) )
			. ' ' . esc_html__( 'Ce n’est pas forcément une attaque (réseau mobile, VPN, déplacement).', 'cyberpush-ultra' )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Voir', 'cyberpush-ultra' ) . '</a> · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Marquer comme lu', 'cyberpush-ultra' ) . '</a></p></div>';
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'cyberpush-ultra' ), 403 );
		}
		check_admin_referer( 'cu_logins_dismiss' );
		list( , $last ) = self::unread( get_current_user_id() );
		if ( $last ) {
			update_user_meta( get_current_user_id(), self::META_SEEN, $last );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	// -------------------------------------------------------------------------
	// REST (administrateurs uniquement)
	// -------------------------------------------------------------------------

	public function register_routes() {
		$perm = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route( CU_REST_NAMESPACE, '/firewall/logins', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_list' ), 'permission_callback' => $perm ) );
		register_rest_route( CU_REST_NAMESPACE, '/firewall/logins/account', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_account' ), 'permission_callback' => $perm ) );
		register_rest_route( CU_REST_NAMESPACE, '/firewall/logins/settings', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_settings' ), 'permission_callback' => $perm ) );
	}

	private static function settings_payload() {
		return array(
			'watch'      => self::enabled(),
			'privileged' => (bool) CU_Settings::get( 'audit', 'login_watch_privileged', false ),
			'email_ip'   => (bool) CU_Settings::get( 'audit', 'login_email_new_ip', false ),
			'email'      => (bool) CU_Settings::get( 'audit', 'email_alerts', true ),
			'retention'  => self::retention_days(),
		);
	}

	private static function format_row( array $r ) {
		$actor = (string) ( $r['actor_login'] ?? '' );
		if ( '' === $actor || 'système' === $actor ) {
			$actor = __( 'Origine non déterminée', 'cyberpush-ultra' );
		}
		return array(
			'id'               => (int) $r['id'],
			'date'             => (string) $r['created_at'],
			'user_id'          => (int) $r['user_id'],
			'user_login'       => (string) $r['user_login'],
			'role'             => (string) ( $r['role'] ?? '' ),
			'event'            => (string) $r['event'],
			'group'            => self::group_of( (string) $r['event'], (string) $r['ip_status'], (string) $r['country_status'] ),
			'severity'         => (string) $r['severity'],
			'ip'               => (string) ( $r['ip'] ?? '' ),
			'country'          => (string) ( $r['country_code'] ?? '' ),
			'browser'          => (string) ( $r['browser'] ?? '' ),
			'ip_status'        => (string) ( $r['ip_status'] ?? '' ),
			'country_status'   => (string) ( $r['country_status'] ?? '' ),
			'failures_account' => (int) $r['failures_account'],
			'failures_ip'      => (int) $r['failures_ip'],
			'actor'            => $actor,
			'message'          => (string) ( $r['message'] ?? '' ),
		);
	}

	public function rest_list( WP_REST_Request $request ) {
		nocache_headers();
		$out = array( 'ready' => self::table_ready(), 'settings' => self::settings_payload(), 'items' => array(), 'total' => 0, 'pages' => 1, 'page' => 1, 'kpis' => array(), 'accounts' => array() );
		if ( ! $out['ready'] ) {
			return rest_ensure_response( $out );
		}
		global $wpdb;
		$t    = CU_DB::table( 'logins' );
		$days = (int) $request->get_param( 'days' );
		$days = in_array( $days, array( 1, 7, 30, 90, 365 ), true ) ? $days : 7;
		$from = CU_DB::date_from_now( 'Y-m-d H:i:s', -$days * DAY_IN_SECONDS );

		// Indicateurs de la période.
		$k = $wpdb->get_row( $wpdb->prepare(
			"SELECT SUM( event = 'login' ) AS logins,
				SUM( event IN ('login','session') AND ( ip_status = 'new' OR country_status = 'new' ) ) AS new_ips,
				SUM( event IN ('" . implode( "','", self::ADMIN_NEW ) . "') ) AS new_admins,
				SUM( severity IN ('warning','alert') OR event IN ('" . implode( "','", array_merge( self::ADMIN_LOST, self::CREDENTIALS ) ) . "') ) AS sens
			FROM {$t} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$from
		), ARRAY_A );
		$last = $wpdb->get_row( "SELECT created_at, user_login, event FROM {$t} WHERE event IN ('login','session') ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['kpis'] = array(
			'logins'     => (int) ( $k['logins'] ?? 0 ),
			'new_ips'    => (int) ( $k['new_ips'] ?? 0 ),
			'new_admins' => (int) ( $k['new_admins'] ?? 0 ),
			'sensitive'  => (int) ( $k['sens'] ?? 0 ),
			'last'       => $last ? array( 'date' => (string) $last['created_at'], 'login' => (string) $last['user_login'], 'event' => (string) $last['event'] ) : null,
		);

		// Filtres.
		$where  = array( 'created_at >= %s' );
		$params = array( $from );
		$user   = (int) $request->get_param( 'user' );
		if ( $user > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $user;
		}
		$group = sanitize_key( (string) $request->get_param( 'group' ) );
		$lists = array( 'admin_new' => self::ADMIN_NEW, 'admin_lost' => self::ADMIN_LOST, 'credentials' => self::CREDENTIALS );
		if ( isset( $lists[ $group ] ) ) {
			$where[] = "event IN ('" . implode( "','", $lists[ $group ] ) . "')";
		} elseif ( 'new_ip' === $group ) {
			$where[] = "event IN ('login','session') AND ( ip_status = 'new' OR country_status = 'new' )";
		} elseif ( 'login' === $group || 'session' === $group ) {
			$where[]  = 'event = %s';
			$params[] = $group;
		}
		$severity = sanitize_key( (string) $request->get_param( 'severity' ) );
		if ( in_array( $severity, array( 'info', 'notice', 'warning', 'alert' ), true ) ) {
			$where[]  = 'severity = %s';
			$params[] = $severity;
		}
		$page  = max( 1, min( 10000, (int) $request->get_param( 'page' ) ) );
		$per   = 25;
		$sql_w = implode( ' AND ', $where );
		$out['total'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$sql_w}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows         = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql_w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out['items'] = array_map( array( __CLASS__, 'format_row' ), $rows );
		$out['page']  = $page;
		$out['pages'] = max( 1, (int) ceil( $out['total'] / $per ) );

		// Comptes : surveillés aujourd'hui + ceux présents dans le journal.
		$accounts = array();
		foreach ( get_users( array( 'role__in' => array( 'administrator' ), 'fields' => array( 'ID', 'user_login' ), 'number' => 200 ) ) as $u ) {
			$accounts[ (int) $u->ID ] = (string) $u->user_login;
		}
		foreach ( (array) $wpdb->get_results( "SELECT DISTINCT user_id, user_login FROM {$t} WHERE user_id > 0 ORDER BY user_login LIMIT 200", ARRAY_A ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$accounts[ (int) $u['user_id'] ] = (string) $u['user_login'];
		}
		asort( $accounts, SORT_NATURAL | SORT_FLAG_CASE );
		foreach ( $accounts as $id => $login ) {
			$out['accounts'][] = array( 'id' => $id, 'login' => $login );
		}
		// La section a été consultée : l'avis du tableau de bord est considéré comme lu
		// (pas pour le centre de contrôle, qui n'affiche qu'un résumé : mark=0).
		$max = '0' === (string) $request->get_param( 'mark' ) ? 0 : (int) $wpdb->get_var( "SELECT MAX(id) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $max ) {
			update_user_meta( get_current_user_id(), self::META_SEEN, $max );
		}
		return rest_ensure_response( $out );
	}

	/** Historique d'un compte : adresses récemment observées et derniers événements. */
	public function rest_account( WP_REST_Request $request ) {
		nocache_headers();
		$id   = (int) $request->get_param( 'id' );
		$user = $id > 0 ? get_userdata( $id ) : false;
		if ( ! self::table_ready() || $id <= 0 ) {
			return new WP_Error( 'cu_logins_account', __( 'Compte introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$t   = CU_DB::table( 'logins' );
		$ips = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT ip, MAX(country_code) AS country, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen, COUNT(*) AS n, SUM(event = 'login') AS logins
			FROM {$t} WHERE user_id = %d AND ip IS NOT NULL AND event IN ('login','session') GROUP BY ip ORDER BY last_seen DESC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id
		), ARRAY_A );
		$events = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT 30", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return rest_ensure_response( array(
			'user'      => $user ? array( 'id' => $id, 'login' => $user->user_login, 'role' => self::role_of( $user ), 'monitored' => self::monitored( $user ) ) : array( 'id' => $id, 'login' => '#' . $id, 'role' => '', 'monitored' => false ),
			'ips'       => array_map( function ( $r ) {
				return array( 'ip' => (string) $r['ip'], 'country' => (string) $r['country'], 'first' => (string) $r['first_seen'], 'last' => (string) $r['last_seen'], 'count' => (int) $r['n'], 'logins' => (int) $r['logins'] );
			}, $ips ),
			'countries' => $user ? array_keys( (array) get_user_meta( $id, self::META_COUNTRIES, true ) ) : array(),
			'events'    => array_map( array( __CLASS__, 'format_row' ), $events ),
			'retention' => self::retention_days(),
		) );
	}

	public function rest_settings( WP_REST_Request $request ) {
		$values = array();
		foreach ( array( 'watch' => 'login_watch', 'privileged' => 'login_watch_privileged', 'email_ip' => 'login_email_new_ip' ) as $param => $key ) {
			$value = $request->get_param( $param );
			if ( null !== $value ) {
				$values[ $key ] = (bool) rest_sanitize_boolean( $value );
			}
		}
		$retention = $request->get_param( 'retention' );
		if ( null !== $retention && is_numeric( $retention ) ) {
			$values['login_retention_days'] = max( 7, min( 365, (int) $retention ) );
		}
		if ( $values ) {
			CU_Settings::update_module( 'audit', $values );
		}
		// Surveillance (ré)activée : les administrateurs actuels forment l'état de référence.
		if ( ! empty( $values['login_watch'] ) ) {
			self::save_baseline();
		}
		return rest_ensure_response( array( 'settings' => self::settings_payload() ) );
	}
}

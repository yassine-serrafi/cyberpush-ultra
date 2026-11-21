<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module Spam — protection légère des formulaires, SANS jamais lire le contenu
 * des messages (aucun mot-clé, lien, langue, répétition ni score de contenu).
 *
 * Contrôles, du moins coûteux au plus coûteux :
 *  1. Pot de miel : un champ invisible (nom propre au site, préfixé « _ » : jamais
 *     transmis dans les e-mails) et un horodatage signé. Aucun JavaScript ; une
 *     page servie par un cache ne fait qu'allonger le délai mesuré.
 *  2. Domaine de l'adresse e-mail : listes de l'administrateur, domaines réservés,
 *     grands fournisseurs (sans DNS), adresses jetables (liste publique CC0), puis
 *     DNS (MX, Null MX, MX implicite A/AAAA). Une erreur DNS ne bloque jamais.
 *  3. Envois répétés : compteur à fenêtre fixe (atomique) du pare-feu, par
 *     formulaire et par IP (/64 en IPv6), sans bannissement ; message clair de
 *     limite temporaire pour le visiteur.
 *
 * Les décisions ne dépendent d'aucune extension de formulaires : seul l'adaptateur
 * (CU_Spam_CF7) connaît Contact Form 7. Journal minimal : une ligne par envoi
 * examiné, sans message, adresse complète, IP ni valeur de champ.
 */
class CU_Spam {

	const TOKEN_FIELD    = '_cu_t';
	const OPT_SINCE      = 'cu_spam_since';
	const OPT_LAST_ERROR = 'cu_spam_last_error';
	const OPT_LIST       = 'cu_spam_disposable_list';
	const OPT_LIST_META  = 'cu_spam_disposable_meta';
	const LIST_URL       = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';
	/** Jours pendant lesquels un envoi sans nos champs n'est que « suspect » (pages encore en cache). */
	const GRACE_DAYS     = 7;
	const PER_PAGE       = 25;
	/** Conservation en cache d'un résultat DNS, selon le résultat. */
	const DNS_TTL        = array( 'ok' => 604800, 'null_mx' => 86400, 'no_mail_domain' => 86400, 'unknown' => 600 );
	const DOMAIN_RE      = '/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';
	/** Grands fournisseurs : acceptés sans DNS et jamais considérés comme jetables. */
	const MAJOR_EXACT    = array( 'gmail.com', 'googlemail.com', 'msn.com', 'ymail.com', 'icloud.com', 'me.com', 'mac.com', 'proton.me', 'protonmail.com', 'protonmail.ch', 'pm.me', 'web.de', 'orange.fr', 'wanadoo.fr', 'free.fr', 'sfr.fr', 'neuf.fr', 'laposte.net', 'bbox.fr', 'aol.com', 'aol.fr', 'mail.ru', 'zoho.com', 'tutanota.com', 'tutanota.de', 'tuta.io', 'tuta.com', 'fastmail.com', 'fastmail.fm', 'skynet.be', 'bluewin.ch' );
	/** Familles « nom.tld » (outlook.fr, yahoo.co.uk, gmx.de…), suffixe national ou com/net uniquement. */
	const MAJOR_FAMILIES = array( 'outlook', 'hotmail', 'live', 'yahoo', 'gmx', 'yandex' );

	private static $instance = null;
	/** @var array|null Verdict du pot de miel pour la soumission en cours (calculé une fois). */
	private $verdict = null;
	/** @var array{blocked:string, suspect:string} Décision CyberPush pour la soumission en cours. */
	private $state = array( 'blocked' => '', 'suspect' => '' );
	/** @var array<string,bool>|null Domaines jetables (chargés une fois par requête). */
	private static $disposable = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'update_option_' . CU_OPTION_SETTINGS, array( $this, 'on_settings_updated' ), 10, 2 );
		if ( self::enabled() ) {
			CU_Spam_CF7::instance()->init();
			CU_Spam_WP::instance()->init();
		}
	}

	// -------------------------------------------------------------------------
	// Réglages
	// -------------------------------------------------------------------------

	public static function opt( $key ) {
		return CU_Settings::get( 'spam', $key, null );
	}

	public static function enabled() {
		return (bool) self::opt( 'enabled' );
	}

	public static function honeypot_active() {
		return self::enabled() && (bool) self::opt( 'honeypot' );
	}

	public static function email_checks_active() {
		return self::enabled() && ( self::opt( 'email_check' ) || self::opt( 'disposable_check' ) );
	}

	/** Formulaire examiné : module actif et formulaire non exclu. */
	public static function form_protected( $form_id ) {
		return self::enabled() && ! in_array( (int) $form_id, array_map( 'intval', (array) self::opt( 'excluded_forms' ) ), true );
	}

	/**
	 * Début de la protection : un envoi sans nos champs n'est que « suspect »
	 * pendant GRACE_DAYS (pages encore en cache sans les champs).
	 *
	 * @param bool $reset true : la protection vient d'être (ré)activée.
	 */
	public static function mark_since( $reset = false ) {
		if ( $reset ) {
			update_option( self::OPT_SINCE, time(), true );
		} elseif ( false === get_option( self::OPT_SINCE, false ) ) {
			add_option( self::OPT_SINCE, time(), '', true );
		}
	}

	public static function in_grace() {
		$since = (int) get_option( self::OPT_SINCE, 0 );
		if ( $since <= 0 ) {
			self::mark_since();
			return true;
		}
		return time() - $since < self::GRACE_DAYS * DAY_IN_SECONDS;
	}

	/** Pot de miel (ré)activé : nouveau délai de grâce et purge des caches de page. */
	public function on_settings_updated( $old, $new ) {
		if ( ! self::honeypot_on_in( $old ) && self::honeypot_on_in( $new ) ) {
			self::mark_since( true );
			try {
				CU_Tracker::purge_page_caches();
			} catch ( Throwable $e ) {
				unset( $e ); // une extension de cache défaillante ne bloque pas l'enregistrement
			}
		}
	}

	/** Pot de miel actif d'après une valeur brute de l'option (clés absentes : valeurs par défaut). */
	private static function honeypot_on_in( $raw ) {
		$spam = ( is_array( $raw ) && isset( $raw['spam'] ) && is_array( $raw['spam'] ) ? $raw['spam'] : array() ) + CU_Settings::get_defaults()['spam'];
		return ! empty( $spam['enabled'] ) && ! empty( $spam['honeypot'] );
	}

	// -------------------------------------------------------------------------
	// Pot de miel (aucun JavaScript, compatible cache)
	// -------------------------------------------------------------------------

	/** Nom du champ piège : stable pour ce site, imprévisible ailleurs, jamais envoyé par CF7. */
	public static function hp_field() {
		return '_cu_' . substr( hash_hmac( 'sha256', 'cu-spam-hp', wp_salt( 'nonce' ) ), 0, 8 );
	}

	public static function make_token( $ts = null ) {
		$ts = null === $ts ? time() : (int) $ts;
		return $ts . '.' . self::token_sig( $ts );
	}

	private static function token_sig( $ts ) {
		return substr( hash_hmac( 'sha256', 'cu-spam-t|' . (int) $ts, wp_salt( 'nonce' ) ), 0, 16 );
	}

	/** Horodatage d'un jeton correctement signé, sinon null (jeton absent ou falsifié). */
	public static function token_time( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^(\d{9,11})\.([0-9a-f]{16})$/D', $token, $m ) ) {
			return null;
		}
		return hash_equals( self::token_sig( (int) $m[1] ), $m[2] ) ? (int) $m[1] : null;
	}

	/**
	 * Champ piège masqué SANS décalage hors écran (QA-MAIN-001) : un
	 * `left:-10000px` agrandit la zone défilable des pages de droite à gauche
	 * (arabe, hébreu…) d'environ 10 000 px. Boîte de 1 px découpée (clip /
	 * clip-path), à sa place dans le flux : invisible quel que soit le sens
	 * d'écriture, hors tabulation, ignorée des technologies d'assistance.
	 */
	public static function honeypot_html() {
		return '<span class="cu-hp" aria-hidden="true" style="position:absolute!important;width:1px!important;height:1px!important;margin:-1px!important;padding:0!important;border:0!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;clip-path:inset(50%)!important;white-space:nowrap!important;opacity:0!important;pointer-events:none!important;">'
			. '<label>' . esc_html__( 'Laissez ce champ vide', 'cyberpush-ultra' ) . ' '
			. '<input type="text" name="' . esc_attr( self::hp_field() ) . '" value="" size="1" tabindex="-1" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other">'
			. '</label></span>';
	}

	/**
	 * Verdict du pot de miel, calculé une fois par soumission.
	 *
	 * @param array $post Données envoyées (sans antislashs).
	 * @return array{level:string, reason:string} level : ok | suspect | spam.
	 */
	public function verdict( array $post ) {
		if ( null !== $this->verdict ) {
			return $this->verdict;
		}
		$hp     = self::hp_field();
		$has_hp = array_key_exists( $hp, $post );
		$filled = $has_hp && ( ! is_scalar( $post[ $hp ] ) || '' !== trim( (string) $post[ $hp ] ) );
		$ts     = isset( $post[ self::TOKEN_FIELD ] ) && is_scalar( $post[ self::TOKEN_FIELD ] ) ? self::token_time( (string) $post[ self::TOKEN_FIELD ] ) : null;
		$level  = 'ok';
		$reason = '';
		if ( $filled ) {
			$level  = 'spam';
			$reason = 'honeypot';
		} elseif ( ! $has_hp && null === $ts ) {
			// Envoi direct, sans le formulaire rendu par WordPress.
			$level  = self::in_grace() ? 'suspect' : 'spam';
			$reason = 'missing_fields';
		} elseif ( null !== $ts ) {
			$elapsed = time() - $ts;
			$min     = (int) apply_filters( 'cu_spam_min_seconds', 3 );
			if ( $elapsed >= 0 && $elapsed < $min ) {
				$level  = 'suspect'; // jamais bloquant seul : saisie automatique + formulaire court
				$reason = 'too_fast';
			}
		}
		$this->verdict = array( 'level' => $level, 'reason' => $reason );
		return $this->verdict;
	}

	// -------------------------------------------------------------------------
	// Décisions (appelées par l'adaptateur de formulaires)
	// -------------------------------------------------------------------------

	/** Note la décision CyberPush de la soumission en cours (première raison retenue). */
	public function note( $kind, $reason ) {
		if ( isset( $this->state[ $kind ] ) && '' === $this->state[ $kind ] ) {
			$this->state[ $kind ] = (string) $reason;
		}
	}

	public function reset() {
		$this->verdict = null;
		$this->state   = array( 'blocked' => '', 'suspect' => '' );
	}

	/** Raison du blocage CyberPush de la soumission en cours ('' si aucun). */
	public function blocked_reason() {
		return $this->state['blocked'];
	}

	/**
	 * Étape « spam » d'une soumission valide : pot de miel puis envois répétés.
	 *
	 * @param array $post    Données envoyées (sans antislashs).
	 * @param int   $form_id Formulaire concerné (compteur d'envois par formulaire).
	 * @return string Raison du blocage, '' si l'envoi passe.
	 */
	public function spam_reason( array $post, $form_id = 0 ) {
		if ( self::honeypot_active() ) {
			$verdict = $this->verdict( $post );
			if ( 'spam' === $verdict['level'] ) {
				return $verdict['reason'];
			}
			if ( 'suspect' === $verdict['level'] ) {
				$this->note( 'suspect', $verdict['reason'] );
			}
		}
		return self::rate_limited( $form_id ) ? 'rate_limit' : '';
	}

	/**
	 * Trop d'envois du MÊME formulaire depuis la même source (IP, /64 en IPv6)
	 * dans la fenêtre réglée (QA-MAIN-005). Compter par formulaire évite qu'une
	 * connexion partagée (bureau, hôtel, réseau mobile) épuise la limite de tous
	 * les formulaires du site à la fois. Compteur atomique du pare-feu, jamais de
	 * bannissement ; le visiteur voit un message de limite temporaire (voir
	 * rate_limit_message()), pas l'erreur générique.
	 */
	private static function rate_limited( $form_id = 0 ) {
		if ( ! self::opt( 'rate_limit_enabled' ) || is_user_logged_in() ) {
			return false;
		}
		$ip = self::visitor_ip();
		if ( ! CU_Geo::is_public_ip( $ip ) || CU_Firewall::is_admin_relay( $ip ) ) {
			return false; // adresse partagée par tous les visiteurs : un compteur commun bloquerait tout le monde
		}
		if ( CU_DB::tables_exist_for( array( 'firewall_whitelist' ) ) && CU_Firewall::ip_is_whitelisted( $ip ) ) {
			return false;
		}
		$count = CU_Firewall::bump_ip_counter( 'spam_f' . absint( $form_id ), $ip, MINUTE_IN_SECONDS * max( 1, (int) self::opt( 'rate_limit_window_minutes' ) ) );
		return $count > (int) self::opt( 'rate_limit_max' );
	}

	/** Message affiché au visiteur quand la limite d'envois est atteinte (rien d'autre n'est révélé). */
	public static function rate_limit_message() {
		return __( 'Vous avez envoyé ce formulaire plusieurs fois en peu de temps. Par sécurité, les envois sont temporairement limités : merci de réessayer dans quelques minutes.', 'cyberpush-ultra' );
	}

	/**
	 * Vérifie une adresse saisie et note la décision.
	 *
	 * @return string Message de refus à afficher au visiteur, '' si l'adresse est acceptée.
	 */
	public function email_decision( $email ) {
		$status = self::check_email( $email );
		if ( in_array( $status, array( 'reserved', 'null_mx', 'no_mail_domain', 'blocklisted' ), true ) ) {
			$this->note( 'blocked', $status );
			return __( 'Cette adresse e-mail ne semble pas pouvoir recevoir de réponse. Vérifiez-la ou utilisez-en une autre.', 'cyberpush-ultra' );
		}
		if ( 'disposable' === $status ) {
			if ( 'block' === self::opt( 'disposable_action' ) ) {
				$this->note( 'blocked', 'disposable' );
				return __( 'Merci d’utiliser une adresse e-mail permanente pour que nous puissions vous répondre.', 'cyberpush-ultra' );
			}
			$this->note( 'suspect', 'disposable' );
		}
		return '';
	}

	/**
	 * Fin de soumission : une ligne de journal selon l'issue réelle, puis remise à zéro.
	 *
	 * @param string $status      mail_sent | mail_failed | spam | validation_failed | acceptance_missing | aborted.
	 * @param string $other_agent Mécanisme ayant classé l'envoi en spam (si ce n'est pas nous).
	 * @param string $email       Adresse saisie (seul son domaine est conservé, et seulement si utile).
	 */
	public function finish( $form_id, $status, $other_agent, $email ) {
		$decision = '';
		$reason   = '';
		$agent    = '';
		if ( '' !== $this->state['blocked'] && in_array( $status, array( 'spam', 'validation_failed' ), true ) ) {
			$decision = 'blocked';
			$reason   = $this->state['blocked'];
			$agent    = 'cyberpush';
		} elseif ( 'spam' === $status ) {
			$decision = 'other';
			$reason   = 'external';
			$agent    = '' !== (string) $other_agent ? (string) $other_agent : 'unknown';
		} elseif ( in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) ) {
			$reason   = $this->state['suspect'];
			$decision = '' !== $reason ? 'suspect' : 'allowed';
			$agent    = '' !== $reason ? 'cyberpush' : '';
		}
		if ( '' !== $decision ) {
			$this->log_row( $form_id, $decision, $reason, $agent, $email );
		}
		$this->reset();
	}

	private function log_row( $form_id, $decision, $reason, $agent, $email ) {
		if ( ! CU_DB::tables_exist_for( array( 'spam_log' ) ) ) {
			return;
		}
		global $wpdb;
		$detail   = 'allowed' !== $decision;
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			CU_DB::table( 'spam_log' ),
			array(
				'created_at'   => current_time( 'mysql' ),
				'form_id'      => absint( $form_id ),
				'decision'     => $decision,
				'reason'       => substr( sanitize_key( $reason ), 0, 30 ),
				'agent'        => substr( sanitize_key( $agent ), 0, 30 ),
				'email_domain' => $detail ? self::email_domain( $email ) : null,
				'source_key'   => $detail ? self::source_key() : null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppress );
		if ( false === $inserted ) {
			self::record_error( 'db', (string) $wpdb->last_error );
		}
	}

	/** Domaine de l'adresse (jamais l'adresse complète), ou null. */
	private static function email_domain( $email ) {
		$email  = (string) $email;
		$at     = strrpos( $email, '@' );
		$domain = false === $at ? '' : self::normalize_domain( substr( $email, $at + 1 ) );
		return '' !== $domain ? substr( $domain, 0, 190 ) : null;
	}

	/**
	 * Adresse du visiteur pour les compteurs du module. Derrière un relais de
	 * l'hébergeur pas encore approuvé (vu par un administrateur), l'adresse
	 * transmise par Cloudflare / le relais remplace celle du relais, partagée par
	 * tous. Jamais utilisée pour bloquer une adresse.
	 */
	private static function visitor_ip() {
		$ip = CU_Geo::client_ip();
		return CU_Firewall::is_admin_relay( $ip ) ? CU_Geo::audience_ip() : $ip;
	}

	/** Empreinte courte et non réversible de la source (IP, /64 en IPv6). */
	private static function source_key() {
		$ip = self::visitor_ip();
		return '' !== CU_Geo::normalize_ip( $ip ) && '0.0.0.0' !== $ip ? substr( CU_Geo::ip_key( CU_Geo::rate_bucket( $ip ) ), 0, 12 ) : null;
	}

	/** Dernière erreur technique (affichée dans le diagnostic), écrite au plus toutes les 10 min. */
	private static function record_error( $type, $message ) {
		$message = CU_Text::substr( sanitize_text_field( $message ), 0, 300 );
		$last    = get_option( self::OPT_LAST_ERROR, array() );
		if ( is_array( $last ) && isset( $last['message'], $last['ts'] ) && $last['message'] === $message && time() - (int) $last['ts'] < 600 ) {
			return;
		}
		update_option( self::OPT_LAST_ERROR, array( 'type' => $type, 'message' => $message, 'at' => current_time( 'mysql' ), 'ts' => time() ), false );
	}

	// -------------------------------------------------------------------------
	// Adresse e-mail : domaine uniquement
	// -------------------------------------------------------------------------

	/**
	 * @return string ok | unknown | allowlisted | blocklisted | reserved | null_mx | no_mail_domain | disposable
	 */
	public static function check_email( $email, $use_cache = true ) {
		$email = CU_Text::lower( trim( (string) $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return 'unknown';
		}
		$domain = self::normalize_domain( substr( $email, $at + 1 ) );
		if ( '' === $domain ) {
			return 'unknown'; // syntaxe laissée à CF7 ; domaine international sans l'extension intl
		}
		$address = substr( $email, 0, $at ) . '@' . $domain;
		if ( self::in_list( 'allowed_domains', $domain, $address ) ) {
			return 'allowlisted';
		}
		if ( self::in_list( 'blocked_domains', $domain, $address ) ) {
			return 'blocklisted';
		}
		$dns = (bool) self::opt( 'email_check' );
		if ( $dns && self::is_reserved( $domain ) ) {
			return 'reserved';
		}
		if ( self::is_major_provider( $domain ) ) {
			return 'ok';
		}
		if ( self::opt( 'disposable_check' ) && self::domain_in_set( $domain, self::disposable_set() ) ) {
			return 'disposable';
		}
		return $dns ? self::dns_status( $domain, $use_cache ) : 'ok';
	}

	/** Domaine en minuscules, sans point final, converti en ASCII (IDN) ; '' si invalide. */
	public static function normalize_domain( $domain ) {
		$domain = rtrim( CU_Text::lower( trim( (string) $domain ) ), '.' );
		if ( '' !== $domain && preg_match( '/[^\x20-\x7e]/', $domain ) ) {
			$ascii = function_exists( 'idn_to_ascii' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) && defined( 'IDNA_NONTRANSITIONAL_TO_ASCII' )
				? idn_to_ascii( $domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 )
				: false;
			$domain = is_string( $ascii ) ? strtolower( $ascii ) : '';
		}
		return ( '' !== $domain && preg_match( self::DOMAIN_RE, $domain ) ) ? $domain : '';
	}

	/** Liste de domaines ou d'adresses saisie par l'administrateur, nettoyée (≤ 500). */
	public static function clean_domain_list( $list ) {
		$out = array();
		foreach ( (array) $list as $entry ) {
			if ( ! is_scalar( $entry ) || count( $out ) >= 500 ) {
				continue;
			}
			$entry = ltrim( CU_Text::lower( trim( (string) $entry ) ), '@' );
			$at    = strrpos( $entry, '@' );
			if ( false === $at ) {
				$entry = self::normalize_domain( $entry );
			} else {
				$local  = substr( $entry, 0, $at );
				$domain = self::normalize_domain( substr( $entry, $at + 1 ) );
				$entry  = ( '' !== $domain && '' !== $local && strlen( $local ) <= 64 && ! preg_match( '/[\s,;<>"]/', $local ) ) ? $local . '@' . $domain : '';
			}
			if ( '' !== $entry ) {
				$out[ $entry ] = true;
			}
		}
		return array_keys( $out );
	}

	private static function in_list( $key, $domain, $address ) {
		$list = (array) self::opt( $key );
		if ( ! $list ) {
			return false;
		}
		$set = array_flip( $list );
		return isset( $set[ $address ] ) || self::domain_in_set( $domain, $set );
	}

	/** Le domaine ou l'un de ses domaines parents (au moins deux niveaux) figure dans l'ensemble. */
	private static function domain_in_set( $domain, array $set ) {
		$labels = explode( '.', $domain );
		for ( $i = 0, $n = count( $labels ); $i < $n - 1; $i++ ) {
			if ( isset( $set[ implode( '.', array_slice( $labels, $i ) ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Noms réservés (RFC 2606 / 6761) : ne peuvent recevoir aucun e-mail. */
	private static function is_reserved( $domain ) {
		$tld = substr( (string) strrchr( '.' . $domain, '.' ), 1 );
		return in_array( $tld, array( 'example', 'test', 'invalid', 'localhost', 'local' ), true )
			|| self::domain_in_set( $domain, array_flip( array( 'example.com', 'example.net', 'example.org' ) ) );
	}

	private static function is_major_provider( $domain ) {
		if ( in_array( $domain, self::MAJOR_EXACT, true ) ) {
			return true;
		}
		$dot = strpos( $domain, '.' );
		return false !== $dot && in_array( substr( $domain, 0, $dot ), self::MAJOR_FAMILIES, true )
			&& 1 === preg_match( '/^(?:com|net|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$/D', substr( $domain, $dot + 1 ) );
	}

	/** Domaines jetables : liste rafraîchie (option) ou instantané livré avec le plugin. */
	private static function disposable_set() {
		if ( null === self::$disposable ) {
			$raw = get_option( self::OPT_LIST, '' );
			if ( ! is_string( $raw ) || strlen( $raw ) < 1000 ) {
				$raw = is_readable( self::bundled_file() ) ? (string) file_get_contents( self::bundled_file() ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			$set = array();
			foreach ( explode( "\n", $raw ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line && '#' !== $line[0] ) {
					$set[ $line ] = true;
				}
			}
			self::$disposable = $set;
		}
		return self::$disposable;
	}

	private static function bundled_file() {
		return CU_PLUGIN_DIR . 'includes/data/disposable-domains.txt';
	}

	// -------------------------------------------------------------------------
	// DNS (domaine uniquement, jamais la boîte aux lettres)
	// -------------------------------------------------------------------------

	private static function dns_status( $domain, $use_cache ) {
		$key = 'cu_spam_dns_' . substr( md5( $domain ), 0, 20 );
		if ( $use_cache ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && isset( self::DNS_TTL[ $cached ] ) ) {
				return $cached;
			}
		}
		// Résolveur en panne (ou lent) récemment, ou budget du site épuisé : on laisse passer.
		if ( '0' === (string) get_transient( 'cu_spam_dns_health' ) || ! self::take_dns_budget() ) {
			return 'unknown';
		}
		$start  = microtime( true );
		$status = self::resolve_domain( $domain );
		if ( 'unknown' === $status && microtime( true ) - $start > 3 ) {
			// Échec lent (délai dépassé) : plus de DNS pendant 10 min, aucun visiteur ne réattend.
			set_transient( 'cu_spam_dns_health', '0', 10 * MINUTE_IN_SECONDS );
		}
		set_transient( $key, $status, self::DNS_TTL[ $status ] );
		return $status;
	}

	/** MX, puis MX implicite (A, AAAA, RFC 5321) ; « aucun enregistrement » seulement si le résolveur répond. */
	private static function resolve_domain( $domain ) {
		$fqdn = $domain . '.'; // nom complet : aucun suffixe de recherche local ne peut s'ajouter
		$mx   = self::dns_records( $fqdn, 'MX' );
		if ( false === $mx ) {
			return 'unknown';
		}
		if ( $mx ) {
			$targets = array();
			foreach ( $mx as $record ) {
				$targets[ rtrim( strtolower( (string) ( isset( $record['target'] ) ? $record['target'] : '' ) ), '.' ) ] = true;
			}
			return array( '' ) === array_keys( $targets ) ? 'null_mx' : 'ok'; // RFC 7505 : « MX 0 . » seul
		}
		foreach ( array( 'A', 'AAAA' ) as $type ) {
			$records = self::dns_records( $fqdn, $type );
			if ( false === $records ) {
				return 'unknown';
			}
			if ( $records ) {
				return 'ok';
			}
		}
		return self::resolver_healthy() ? 'no_mail_domain' : 'unknown';
	}

	/**
	 * Unique point d'accès au DNS. Le filtre `cu_spam_dns_records` peut fournir
	 * une réponse (tableau d'enregistrements, ou false pour une panne).
	 *
	 * @return array|false Enregistrements du type demandé, false en cas d'erreur.
	 */
	private static function dns_records( $host, $type ) {
		$pre = apply_filters( 'cu_spam_dns_records', null, $host, $type );
		if ( null !== $pre ) {
			return is_array( $pre ) ? $pre : false;
		}
		$types = array( 'MX' => 'DNS_MX', 'A' => 'DNS_A', 'AAAA' => 'DNS_AAAA' );
		if ( ! function_exists( 'dns_get_record' ) || ! isset( $types[ $type ] ) || ! defined( $types[ $type ] ) ) {
			return false;
		}
		$failed = false;
		// Avertissement (« DNS Query failed », « temporary server error ») = panne, pas absence.
		set_error_handler( static function () use ( &$failed ) { return $failed = true; } ); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
		try {
			$records = dns_get_record( $host, constant( $types[ $type ] ) );
		} catch ( Throwable $e ) {
			$records = false;
		} finally {
			restore_error_handler();
		}
		if ( $failed || ! is_array( $records ) ) {
			return false;
		}
		$out = array();
		foreach ( $records as $record ) {
			if ( is_array( $record ) && ( ! isset( $record['type'] ) || $type === $record['type'] ) ) {
				$out[] = $record;
			}
		}
		return $out;
	}

	/** Le résolveur répond-il ? (MX de gmail.com, résultat gardé 10 min) */
	private static function resolver_healthy() {
		$cached = get_transient( 'cu_spam_dns_health' );
		if ( false !== $cached ) {
			return '1' === (string) $cached;
		}
		$mx = self::dns_records( 'gmail.com.', 'MX' );
		$ok = is_array( $mx ) && count( $mx ) > 0;
		set_transient( 'cu_spam_dns_health', $ok ? '1' : '0', 10 * MINUTE_IN_SECONDS );
		return $ok;
	}

	/** Budget DNS du site (30 résolutions / minute par défaut, filtre `cu_spam_dns_budget`). */
	private static function take_dns_budget() {
		$limit  = (int) apply_filters( 'cu_spam_dns_budget', 30 );
		$minute = (int) floor( time() / 60 );
		$state  = get_transient( 'cu_spam_dns_budget' );
		$count  = ( is_array( $state ) && isset( $state['m'], $state['n'] ) && (int) $state['m'] === $minute ) ? (int) $state['n'] : 0;
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( 'cu_spam_dns_budget', array( 'm' => $minute, 'n' => $count + 1 ), 2 * MINUTE_IN_SECONDS );
		return true;
	}

	// -------------------------------------------------------------------------
	// Liste des domaines jetables (rafraîchie chaque semaine par le cron quotidien)
	// -------------------------------------------------------------------------

	/**
	 * Liste en service : téléchargée (option) ou instantané livré (date et nombre
	 * lus dans son en-tête), avec le dernier essai de mise à jour.
	 *
	 * @return array{source:string, count:int, updated:string, updated_ts:int, checked_at:int, last_error:string}
	 */
	public static function list_meta() {
		$meta = get_option( self::OPT_LIST_META, array() );
		$meta = ( is_array( $meta ) ? $meta : array() ) + array( 'count' => 0, 'updated_at' => 0, 'checked_at' => 0, 'last_error' => '' );
		$out  = array( 'source' => 'remote', 'count' => (int) $meta['count'], 'updated_ts' => (int) $meta['updated_at'], 'checked_at' => (int) $meta['checked_at'], 'last_error' => (string) $meta['last_error'] );
		if ( ! $out['count'] || ! $out['updated_ts'] ) {
			$head = is_readable( self::bundled_file() ) ? (string) file_get_contents( self::bundled_file(), false, null, 0, 400 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$m    = preg_match( '/(\d{4}-\d{2}-\d{2}) : (\d+)/', $head, $found ) ? $found : array( '', '', 0 );
			$out  = array( 'source' => 'bundled', 'count' => (int) $m[2], 'updated_ts' => $m[1] ? (int) strtotime( $m[1] . ' 00:00:00 UTC' ) : 0 ) + $out;
		}
		$out['updated'] = $out['updated_ts'] ? gmdate( 'Y-m-d', $out['updated_ts'] ) : '';
		return $out;
	}

	/**
	 * Télécharge la liste publique au plus une fois par semaine (nouvel essai le
	 * lendemain en cas d'échec). L'ancienne liste est conservée en cas d'erreur.
	 *
	 * @return bool true si une nouvelle liste a été enregistrée.
	 */
	public static function maybe_refresh_disposable_list() {
		if ( ! self::enabled() || ! self::opt( 'disposable_check' ) || ! self::opt( 'disposable_auto_update' ) ) {
			return false;
		}
		$meta = self::list_meta();
		$now  = time();
		if ( $now - $meta['updated_ts'] < WEEK_IN_SECONDS || $now - $meta['checked_at'] < DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			return false;
		}
		$stored = get_option( self::OPT_LIST_META, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$error  = '';
		$response = wp_safe_remote_get( self::LIST_URL, array( 'timeout' => 10, 'redirection' => 2, 'limit_response_size' => 2 * MB_IN_BYTES, 'user-agent' => 'CyberPush-Ultra/' . CU_VERSION ) );
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$error = 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );
		} else {
			$body    = (string) wp_remote_retrieve_body( $response );
			$domains = strlen( $body ) >= 2 * MB_IN_BYTES ? array() : self::parse_list( $body );
			if ( count( $domains ) < 1000 || count( $domains ) > 100000 ) {
				/* translators: %d: nombre de domaines reçus */
				$error = sprintf( __( 'liste rejetée (%d domaines valides)', 'cyberpush-ultra' ), count( $domains ) );
			} else {
				update_option( self::OPT_LIST, implode( "\n", $domains ), false );
				$stored = array( 'count' => count( $domains ), 'updated_at' => $now );
				self::$disposable = null;
			}
		}
		$stored['checked_at'] = $now;
		$stored['last_error'] = $error;
		update_option( self::OPT_LIST_META, $stored, false );
		if ( '' !== $error ) {
			self::record_error( 'list', __( 'Mise à jour de la liste des adresses jetables : ', 'cyberpush-ultra' ) . $error );
		}
		return '' === $error;
	}

	/** Lignes « domaine » valides, sans commentaires ni grands fournisseurs. */
	public static function parse_list( $raw ) {
		$out = array();
		foreach ( preg_split( '/\R/', (string) $raw ) as $line ) {
			$line = strtolower( trim( (string) preg_replace( '/#.*$/', '', $line ) ) );
			if ( '' !== $line && preg_match( self::DOMAIN_RE, $line ) && ! self::is_major_provider( $line ) ) {
				$out[ $line ] = true;
			}
		}
		return array_keys( $out );
	}

	// -------------------------------------------------------------------------
	// Administration : routes REST (manage_options, nonce wp_rest)
	// -------------------------------------------------------------------------

	public function admin_permission() {
		return current_user_can( 'manage_options' );
	}

	public function register_routes() {
		$ns = CU_REST_NAMESPACE;
		register_rest_route( $ns, '/spam/status', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_status' ), 'permission_callback' => array( $this, 'admin_permission' ) ) );
		register_rest_route( $ns, '/spam/log', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_log' ), 'permission_callback' => array( $this, 'admin_permission' ) ) );
		register_rest_route( $ns, '/spam/test-email', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_test_email' ), 'permission_callback' => array( $this, 'admin_permission' ) ) );
	}

	/** Tout l'état de la page en un appel (les libellés vivent dans cu-spam.js, comme pour les autres pages). */
	public function rest_status( WP_REST_Request $request ) {
		nocache_headers();
		$days = $request->get_param( 'days' );
		$days = ( is_scalar( $days ) && 30 === (int) $days ) ? 30 : 7;
		return rest_ensure_response( array( 'settings' => CU_Settings::module( 'spam' ), 'overview' => $this->overview( $days ), 'forms' => $this->forms_report(), 'diagnostic' => $this->diagnostic() ) );
	}

	/** Vue générale : uniquement des chiffres réellement journalisés. */
	private function overview( $days ) {
		$out = array( 'tables' => CU_DB::tables_exist_for( array( 'spam_log' ) ), 'totals' => array( 'examined' => 0, 'allowed' => 0, 'blocked' => 0, 'suspect' => 0, 'other' => 0 ), 'reasons' => array(), 'series' => array(), 'recent' => array() );
		if ( ! $out['tables'] ) {
			return $out;
		}
		global $wpdb;
		$t    = CU_DB::table( 'spam_log' );
		$from = CU_DB::date_from_now( 'Y-m-d', -( $days - 1 ) * DAY_IN_SECONDS ) . ' 00:00:00';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT decision, COUNT(*) AS c FROM {$t} WHERE created_at >= %s GROUP BY decision", $from ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( isset( $out['totals'][ $row['decision'] ] ) ) {
				$out['totals'][ $row['decision'] ] = (int) $row['c'];
				$out['totals']['examined']        += (int) $row['c'];
			}
		}
		$out['reasons'] = (array) $wpdb->get_results( $wpdb->prepare( "SELECT decision, reason, agent, COUNT(*) AS c FROM {$t} WHERE created_at >= %s AND decision <> 'allowed' GROUP BY decision, reason, agent ORDER BY c DESC LIMIT 20", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$daily          = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, decision, COUNT(*) AS c FROM {$t} WHERE created_at >= %s GROUP BY DATE(created_at), decision", $from ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$daily[ $row['d'] ][ $row['decision'] ] = (int) $row['c'];
		}
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d               = CU_DB::date_from_now( 'Y-m-d', -$i * DAY_IN_SECONDS );
			$out['series'][] = array( 'date' => $d ) + ( isset( $daily[ $d ] ) ? $daily[ $d ] : array() );
		}
		$out['recent'] = (array) $wpdb->get_results( "SELECT id, created_at, form_id, decision, reason, agent, email_domain FROM {$t} ORDER BY id DESC LIMIT 10", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $out;
	}

	private function forms_report() {
		$counts = array();
		if ( CU_DB::tables_exist_for( array( 'spam_log' ) ) ) {
			global $wpdb;
			$t = CU_DB::table( 'spam_log' );
			foreach ( (array) $wpdb->get_results( "SELECT form_id, COUNT(*) AS observed, SUM(decision = 'blocked') AS blocked, SUM(decision = 'suspect') AS suspect, SUM(decision = 'other') AS other FROM {$t} GROUP BY form_id", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$counts[ (int) $row['form_id'] ] = $row;
			}
		}
		$forms    = array();
		$excluded = array_map( 'intval', (array) self::opt( 'excluded_forms' ) );
		foreach ( array_merge( CU_Spam_WP::instance()->forms(), CU_Spam_CF7::instance()->forms() ) as $form ) {
			$row = $form + array( 'protected' => self::form_protected( $form['id'] ), 'excluded' => in_array( $form['id'], $excluded, true ) );
			foreach ( array( 'observed', 'blocked', 'suspect', 'other' ) as $key ) {
				$row[ $key ] = isset( $counts[ $form['id'] ][ $key ] ) ? (int) $counts[ $form['id'] ][ $key ] : 0;
			}
			$forms[] = $row;
		}
		return $forms;
	}

	private function diagnostic() {
		$since = (int) get_option( self::OPT_SINCE, 0 );
		$error = get_option( self::OPT_LAST_ERROR, array() );
		return array(
			'cf7'            => array( 'active' => CU_Spam_CF7::is_available(), 'version' => CU_Spam_CF7::version() ),
			'native'         => array( 'comments' => (bool) get_option( 'default_comment_status' ) && 'open' === get_option( 'default_comment_status' ), 'register' => (bool) get_option( 'users_can_register' ) ),
			'honeypot'       => self::honeypot_active(),
			'since'          => $since ? wp_date( 'Y-m-d H:i', $since ) : '',
			'grace_left'     => $since ? max( 0, (int) ceil( ( $since + self::GRACE_DAYS * DAY_IN_SECONDS - time() ) / DAY_IN_SECONDS ) ) : 0,
			'dns'            => function_exists( 'dns_get_record' ),
			'idn'            => function_exists( 'idn_to_ascii' ),
			'list'           => self::list_meta(),
			'integrations'   => CU_Spam_CF7::instance()->integrations(),
			'flamingo_url'   => admin_url( 'admin.php?page=flamingo_inbound&post_status=spam' ),
			'page_cache'     => self::page_cache_name(),
			'other_antispam' => CU_Spam_CF7::instance()->other_antispam_plugins(),
			'last_error'     => is_array( $error ) && ! empty( $error['message'] ) ? array( 'at' => (string) $error['at'], 'message' => (string) $error['message'] ) : null,
		);
	}

	/** Cache de page détecté (les pages en cache gardent l'ancien HTML : d'où le délai de grâce). */
	private static function page_cache_name() {
		$known = array( 'WP Rocket' => function_exists( 'rocket_clean_domain' ), 'W3 Total Cache' => function_exists( 'w3tc_flush_all' ), 'WP Super Cache' => function_exists( 'wp_cache_clear_cache' ), 'LiteSpeed Cache' => defined( 'LSCWP_V' ), 'WP Fastest Cache' => function_exists( 'wpfc_clear_all_cache' ), 'SiteGround Optimizer' => function_exists( 'sg_cachepress_purge_cache' ), 'Cache Enabler' => class_exists( 'Cache_Enabler' ), 'Breeze' => defined( 'BREEZE_VERSION' ), 'Hummingbird' => defined( 'WPHB_VERSION' ) );
		foreach ( $known as $name => $found ) {
			if ( $found ) {
				return $name;
			}
		}
		return ( defined( 'WP_CACHE' ) && WP_CACHE ) ? 'WP_CACHE' : '';
	}

	/** Journal paginé, filtres décision / formulaire (requêtes préparées). */
	public function rest_log( WP_REST_Request $request ) {
		if ( ! CU_DB::tables_exist_for( array( 'spam_log' ) ) ) {
			return new WP_Error( 'cu_migration_required', __( 'Migration SQL requise.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$t        = CU_DB::table( 'spam_log' );
		$where    = array( '1=1' );
		$params   = array();
		// Paramètres non scalaires, négatifs ou démesurés : valeurs par défaut (PM-040,
		// jamais d'erreur SQL ni d'avertissement PHP).
		$decision = $request->get_param( 'decision' );
		$decision = is_scalar( $decision ) ? sanitize_key( (string) $decision ) : '';
		if ( in_array( $decision, array( 'allowed', 'suspect', 'blocked', 'other' ), true ) ) {
			$where[]  = 'decision = %s';
			$params[] = $decision;
		}
		$form = $request->get_param( 'form' );
		$form = ( is_scalar( $form ) && ctype_digit( (string) $form ) && strlen( (string) $form ) <= 18 ) ? (int) $form : 0;
		if ( $form ) {
			$where[]  = 'form_id = %d';
			$params[] = $form;
		}
		$page             = $request->get_param( 'page' );
		$page             = ( is_scalar( $page ) && ctype_digit( (string) $page ) && strlen( (string) $page ) <= 6 ) ? max( 1, (int) $page ) : 1;
		$where            = implode( ' AND ', $where );
		$wpdb->last_error = '';
		$count_sql        = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		$total            = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, form_id, decision, reason, agent, email_domain, source_key FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'cu_spam_log_failed', __( 'Impossible de lire le journal anti-spam.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'items' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil( $total / self::PER_PAGE ) ) );
	}

	/** Outil « Tester une adresse » : verdict local selon les réglages ; seule une requête DNS peut partir. */
	public function rest_test_email( WP_REST_Request $request ) {
		$email = $request->get_param( 'email' );
		$email = is_scalar( $email ) ? trim( sanitize_text_field( (string) $email ) ) : '';
		if ( '' === $email || false === strpos( $email, '@' ) || strlen( $email ) > 254 ) {
			return new WP_Error( 'cu_spam_bad_email', __( 'Saisissez une adresse e-mail complète.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$status = self::check_email( $email, false );
		$effect = 'accepted';
		if ( in_array( $status, array( 'reserved', 'null_mx', 'no_mail_domain', 'blocklisted' ), true ) || ( 'disposable' === $status && 'block' === self::opt( 'disposable_action' ) ) ) {
			$effect = 'blocked';
		} elseif ( 'disposable' === $status ) {
			$effect = 'suspect';
		}
		return rest_ensure_response( array( 'status' => $status, 'effect' => self::email_checks_active() ? $effect : 'inactive' ) );
	}

	/** Motif générique inscrit dans le journal de CF7 / Flamingo (aucun détail technique). */
	public static function log_reason( $reason ) {
		return 'rate_limit' === $reason
			? __( 'Envois répétés depuis la même source (CyberPush Ultra).', 'cyberpush-ultra' )
			: __( 'Envoi automatisé probable (CyberPush Ultra).', 'cyberpush-ultra' );
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module Journal de sécurité (Audit) — CyberPush Ultra
 * -----------------------------------------------------------------------------
 * Enregistre les CHANGEMENTS à haut risque sur le site — ce que le firewall
 * (trafic) et la page Logs (erreurs) ne couvrent pas. Chaque événement retient
 * QUI, QUOI, QUAND, depuis quelle IP et quel pays (pays : source locale ou
 * cache uniquement, jamais d'appel réseau pendant la requête).
 *
 * Deux moteurs :
 *   1. Événements — des hooks WordPress captent les actions sensibles
 *      (extension activée, privilèges accordés, URL du site changée, édition
 *      de fichier ACCEPTÉE par l'éditeur intégré…) et les journalisent.
 *   2. Intégrité des fichiers (FIM) — un scan planifié (WP-Cron), par lots
 *      bornés en temps et reprenables, empreinte (MD5) les fichiers
 *      exécutables : racine du site (premier niveau), cœur, tout wp-content
 *      (hors caches bruyants), extensions et thèmes, must-use, médias. La
 *      référence et l'état par fichier vivent dans la table cu_fim ; l'option
 *      d'état ne garde que la pile de dossiers et des compteurs (quelques Ko).
 *      Les fichiers changés par une mise à jour WordPress sont vérifiés
 *      (empreintes wordpress.org pour les extensions, fenêtre de dates sinon)
 *      avant d'être intégrés à la référence.
 *
 * Alerte e-mail (optionnelle) sur événement CRITIQUE, plafonnée par type
 * d'événement ; les actions d'effacement (vider le journal, réinitialiser la
 * référence) alertent toujours, hors plafond.
 */
class CU_Audit {

	private static $instance = null;

	/** Vrai pendant un scan d'intégrité : coupe les emails par fichier (1 récap à la fin). */
	private $in_scan = false;

	/** Ancienne référence (≤ 1.1.7) : map chemin => md5 dans une option. Migrée vers cu_fim puis supprimée. */
	const LEGACY_BASELINE_OPTION = 'cu_audit_baseline';
	/** Ancien état de scan (≤ 1.1.7, liste complète des chemins + nouvelle référence). Supprimé. */
	const LEGACY_STATE_OPTION    = 'cu_audit_scan_state';
	/** État compact d'un scan en cours : phase, pile de dossiers, compteurs. */
	const STATE_OPTION           = 'cu_audit_fim_state';
	/** Verrou inter-processus d'un lot de scan (cron, bouton, réinitialisation). */
	const LOCK_OPTION            = 'cu_audit_scan_lock';
	const LOCK_TTL               = 300;
	/** Réinitialisation demandée : le prochain scan pose une nouvelle référence. */
	const REBASELINE_OPTION      = 'cu_audit_rebaseline';
	/** Trace minimale des effacements (survit au vidage du journal). */
	const FORENSICS_OPTION       = 'cu_audit_forensics';
	/** Ancien destinataire des alertes, prévenu lui aussi pendant 7 jours. */
	const PREV_RECIPIENT_OPTION  = 'cu_audit_prev_recipient';
	/** Résumé du dernier scan terminé. */
	const LAST_SCAN_OPTION       = 'cu_audit_last_scan';
	/** Résultat de la comparaison du cœur aux empreintes officielles wordpress.org. */
	const CORE_CHECK_OPTION      = 'cu_audit_core_check';
	/** Au-delà, on journalise UN événement de synthèse plutôt qu'un par fichier. */
	const CORE_EVENT_CAP         = 50;
	/** Fichiers racine du cœur (comparés aux empreintes officielles ; wp-config.php non). */
	const ROOT_FILES             = array(
		'wp-config.php', '.htaccess', 'index.php', 'wp-load.php', 'wp-settings.php',
		'wp-blog-header.php', 'wp-cron.php', 'xmlrpc.php', 'wp-login.php', 'wp-mail.php',
		'wp-links-opml.php', 'wp-trackback.php', 'wp-comments-post.php', 'wp-activate.php', 'wp-signup.php',
	);
	/** Plafond de fichiers empreintés par scan (filtre cu_audit_max_files). */
	const MAX_FILES              = 40000;
	/** Plafond d'entrées parcourues par scan (filtre cu_audit_max_visits). */
	const MAX_VISITS             = 1500000;
	/** Fichiers comparés à la base par requête SQL groupée. */
	const BATCH_SIZE             = 200;
	/**
	 * Version du périmètre surveillé. 2 : médias, must-use, drop-ins, toutes les
	 * extensions et tous les thèmes. 3 : racine entière (premier niveau), tout
	 * wp-content hors caches, dossiers cachés. Un périmètre élargi est d'abord
	 * inventorié sans alerte individuelle (sauf exécutable dans les médias).
	 */
	const SCOPE_VERSION          = 3;
	const SCOPE_OPTION           = 'cu_audit_scope';
	/** Unités (extension, thème, cœur, traductions) que WordPress vient d'installer, mettre à jour ou supprimer. */
	const TRUSTED_OPTION         = 'cu_audit_trusted';
	/** Extensions que PHP peut exécuter ou inclure (webshells déguisés compris). */
	const EXEC_EXTENSIONS        = array( 'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'inc' );
	/** Fichiers de configuration capables de rendre un fichier exécutable. */
	const CONFIG_FILES           = array( '.htaccess', '.user.ini', 'php.ini' );
	/** Dossiers de wp-content ignorés (caches régénérés en permanence ; filtre cu_audit_excluded_content_dirs). */
	const EXCLUDED_CONTENT_DIRS  = array( 'cache', 'et-cache', 'litespeed', 'wflogs', 'upgrade-temp-backup' );
	/** Parcours du dossier des médias borné (très grosses médiathèques). */
	const UPLOADS_VISIT_CAP      = 200000;
	/** Au-delà, les changements d'une même extension ou d'un même thème sont regroupés. */
	const GROUP_THRESHOLD        = 5;
	/** Événements critiques individuels par scan ; le surplus est résumé en un seul. */
	const CRITICAL_EVENT_CAP     = 25;
	/** Changements chargés pour composer les événements de fin de scan (le surplus est compté). */
	const PENDING_CAP            = 5000;
	/** Fenêtre de dates acceptée autour d'une mise à jour sans empreintes officielles. */
	const TRUST_MTIME_BEFORE     = 1800;
	const TRUST_MTIME_AFTER      = 300;
	/** Appels aux empreintes d'extensions wordpress.org par scan. */
	const CHECKSUM_CALLS_PER_SCAN = 30;
	/** Alertes e-mail par heure : par type d'événement, puis tous types confondus. */
	const MAIL_CAP_PER_TYPE      = 5;
	const MAIL_CAP_GLOBAL        = 30;
	/** Plafond de lignes des événements moyens/faibles (critiques/élevés : rétention par âge seule). */
	const RETENTION_ROW_CAP      = 20000;
	/** Capacités qui donnent la main sur le site (une seule suffit pour qualifier un rôle de « privilégié »). */
	const PRIVILEGED_CAPS        = array(
		'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users',
		'activate_plugins', 'install_plugins', 'edit_plugins', 'update_plugins', 'delete_plugins',
		'install_themes', 'edit_themes', 'switch_themes', 'edit_files', 'update_core', 'unfiltered_upload',
		'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_options', 'manage_network_plugins',
	);

	/** États d'une ligne cu_fim pendant un scan. */
	const FIM_UNSEEN  = 0; // pas (encore) vu par le scan en cours
	const FIM_OK      = 1; // vu : identique, reconnu, inventorié ou inerte → référence mise à jour
	const FIM_CHANGED = 2; // vu : ajouté/modifié, à signaler
	const FIM_REMOVED = 3; // disparu, à signaler
	const FIM_GONE    = 4; // disparu sans alerte (média retiré, suppression par WordPress)

	/** @var array|null Zones (préfixe => zone), calculées une fois par requête. */
	private $zones = null;
	/** @var array<int,array> Capacités d'un utilisateur juste avant leur mise à jour. */
	private $caps_before = array();
	/** Comptes en cours de suppression : identifiant et statut administrateur. */
	private $deleting = array();
	/** @var array<string,string>|null Version installée de chaque extension (par dossier). */
	private $plugin_versions = null;
	/** @var array<string,array> Empreintes wordpress.org déjà résolues dans la requête. */
	private $checksum_memo = array();
	/** @var array|null Unités reconnues (mise à jour WordPress), lues une fois par lot. */
	private $trusted_memo = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// RGPD : export et anonymisation, même journal désactivé (des données peuvent exister).
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );

		if ( ! $this->enabled() ) {
			return;
		}

		$this->hook_events();

		// Scan d'intégrité planifié + reprise si un scan dépasse le budget temps.
		add_action( 'cu_cron_audit_scan', array( $this, 'cron_scan' ) );
		add_action( 'cu_cron_audit_scan_continue', array( $this, 'cron_scan' ) );

		// Purge/rétention greffée sur le nettoyage quotidien existant.
		add_action( 'cu_cron_cleanup', array( $this, 'purge_old' ) );
	}

	private function enabled() {
		return (bool) CU_Settings::get( 'audit', 'enabled', true );
	}

	private function retention_days() {
		$d = (int) CU_Settings::get( 'audit', 'retention_days', 90 );
		return $d > 0 ? $d : 90;
	}

	// =========================================================================
	// Journalisation
	// =========================================================================

	/**
	 * Enregistre un événement d'audit.
	 *
	 * @param string $severity 'critical' | 'high' | 'medium' | 'low'
	 * @param string $category 'plugins'|'themes'|'users'|'settings'|'files'|'core'|'other'
	 * @param string $event_key Identifiant machine (ex: 'plugin_activated').
	 * @param string $message  Phrase lisible.
	 * @param string $object_type Type d'objet concerné (plugin, user, file…).
	 * @param string $object_name Nom/chemin de l'objet.
	 * @param array  $context  Détails additionnels (avant/après, hash…).
	 * @param array  $alert    Options d'alerte : 'to' (destinataires en plus, ex.
	 *                         l'ANCIENNE adresse admin), 'force' (envoyer même si
	 *                         les alertes e-mail sont coupées) et 'urgent' (ni
	 *                         anti-répétition ni plafond horaire : effacements).
	 * @return int Identifiant de la ligne créée (0 en cas d'échec).
	 */
	public function record( $severity, $category, $event_key, $message, $object_type = '', $object_name = '', $context = array(), $alert = array() ) {
		if ( ! CU_DB::tables_exist_for( array( 'firewall_audit' ) ) ) {
			return 0;
		}
		global $wpdb;

		$ip      = CU_Geo::client_ip();
		// Jamais d'appel réseau ici : une requête admin-ajax « nopriv » (inscription,
		// commande…) ne doit pas attendre une API tierce. Source locale/CDN/cache seulement.
		$country = $ip ? CU_Geo::country_for_ip( $ip, false ) : '';
		$actor   = $this->current_actor();

		$inserted = $wpdb->insert(
			CU_DB::table( 'firewall_audit' ),
			array(
				'created_at'   => current_time( 'mysql' ),
				'severity'     => in_array( $severity, array( 'critical', 'high', 'medium', 'low' ), true ) ? $severity : 'medium',
				'category'     => $category ? $category : 'other',
				'event_key'    => substr( $event_key, 0, 60 ),
				'actor_id'     => $actor['id'] ? $actor['id'] : null,
				'actor_login'  => $actor['login'],
				'ip'           => $ip ? $ip : null,
				'country_code' => $country ? $country : null,
				'object_type'  => $object_type ? substr( $object_type, 0, 40 ) : null,
				'object_name'  => $object_name ? $this->trim255( $object_name ) : null,
				'message'      => $this->trim255( $message ),
				'context'      => ! empty( $context ) ? wp_json_encode( $context ) : null,
			)
		);
		if ( false === $inserted ) {
			CU_Logger::log( 'firewall', 'error', __( 'Impossible d’enregistrer un événement dans le journal de sécurité.', 'cyberpush-ultra' ), array( 'db_error' => $this->trim255( $wpdb->last_error ) ) );
		}
		$id = false === $inserted ? 0 : (int) $wpdb->insert_id;
		// Protection des connexions : les événements de comptes y sont aussi présentés.
		if ( $id ) {
			do_action( 'cu_audit_recorded', $id, array( 'severity' => $severity, 'category' => $category, 'event_key' => $event_key, 'message' => $message, 'object_name' => $object_name, 'actor' => $actor, 'ip' => $ip, 'country' => $country ) );
		}

		if ( 'critical' === $severity ) {
			$this->maybe_alert( $event_key, $message, $object_name, $ip, $country, $alert );
		}
		// Trace aussi dans le journal système (page Logs) pour convergence.
		CU_Logger::log( 'firewall', ( 'critical' === $severity ? 'warning' : 'info' ), $message, array( 'audit' => $event_key ) );
		return $id;
	}

	/** Identité de l'auteur de l'action (utilisateur connecté, WP-CLI ou système). */
	private function current_actor() {
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			return array( 'id' => $uid, 'login' => $user ? $user->user_login : ( '#' . $uid ) );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return array( 'id' => 0, 'login' => 'WP-CLI' );
		}
		if ( wp_doing_cron() ) {
			return array( 'id' => 0, 'login' => 'cron' );
		}
		return array( 'id' => 0, 'login' => 'système' );
	}

	private function trim255( $str ) {
		$str = (string) $str;
		return function_exists( 'mb_substr' ) ? mb_substr( $str, 0, 255 ) : substr( $str, 0, 255 );
	}

	// =========================================================================
	// Alerte email (événements critiques)
	// =========================================================================

	private function maybe_alert( $event_key, $message, $object, $ip, $country, $alert = array() ) {
		$extra_to = isset( $alert['to'] ) ? (array) $alert['to'] : array();
		$force    = ! empty( $alert['force'] );
		$urgent   = ! empty( $alert['urgent'] );
		if ( ! $force && ! CU_Settings::get( 'audit', 'email_alerts', true ) ) {
			return;
		}
		// Pendant un scan d'intégrité : pas d'email par fichier (un récapitulatif
		// unique est envoyé à la fin du scan) — évite la tempête d'emails après
		// une mise à jour du cœur qui touche des centaines de fichiers.
		if ( $this->in_scan && ! $urgent ) {
			return;
		}
		// Anti-spam : une alerte identique (même événement + même objet) au
		// maximum toutes les 15 minutes — sauf effacement (chaque geste compte).
		if ( ! $urgent ) {
			$throttle = 'cu_audit_alert_' . md5( $event_key . '|' . $object );
			if ( get_transient( $throttle ) ) {
				return;
			}
			set_transient( $throttle, 1, 15 * MINUTE_IN_SECONDS );
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( __( '[%s] Alerte sécurité critique', 'cyberpush-ultra' ), $site );
		$actor   = $this->current_actor();
		$lines   = array(
			__( 'CyberPush Ultra a détecté un changement CRITIQUE sur votre site.', 'cyberpush-ultra' ),
			'',
			sprintf( __( 'Événement : %s', 'cyberpush-ultra' ), $message ),
			sprintf( __( 'Date : %s', 'cyberpush-ultra' ), current_time( 'mysql' ) ),
			sprintf( __( 'Utilisateur : %s', 'cyberpush-ultra' ), $actor['login'] ),
			sprintf( __( 'IP : %1$s (%2$s)', 'cyberpush-ultra' ), $ip ? $ip : '—', $country ? $country : '?' ),
			'',
			__( 'Si ce changement n\'est pas de votre fait, vérifiez immédiatement votre site.', 'cyberpush-ultra' ),
			admin_url( 'admin.php?page=cyberpush-ultra-firewall' ),
		);
		$this->send_alert( $subject, $lines, $extra_to, false, $event_key, $urgent );
	}

	/**
	 * Destinataires des alertes : l'adresse dédiée des Réglages si elle est
	 * valide, sinon l'adresse d'administration du site.
	 *
	 * @return string[]
	 */
	private function alert_recipients() {
		$custom = trim( (string) CU_Settings::get( 'audit', 'alert_email', '' ) );
		if ( '' !== $custom && is_email( $custom ) ) {
			return array( $custom );
		}
		$admin = (string) get_option( 'admin_email' );
		return is_email( $admin ) ? array( $admin ) : array();
	}

	/** Ancien destinataire des alertes s'il a changé il y a moins de 7 jours. */
	private function previous_recipient() {
		$prev = get_option( self::PREV_RECIPIENT_OPTION, array() );
		if ( is_array( $prev ) && ! empty( $prev['email'] ) && isset( $prev['until'] ) && (int) $prev['until'] > time() && is_email( $prev['email'] ) ) {
			return array( (string) $prev['email'] );
		}
		return array();
	}

	private function remember_previous_recipient( $email ) {
		$email = trim( (string) $email );
		if ( '' !== $email && is_email( $email ) ) {
			update_option( self::PREV_RECIPIENT_OPTION, array( 'email' => $email, 'until' => time() + 7 * DAY_IN_SECONDS ), false );
		}
	}

	/** Options d'alerte des gestes d'effacement : toujours envoyée, hors plafond, ancien destinataire en copie. */
	private function forensic_alert() {
		return array( 'force' => true, 'urgent' => true, 'to' => $this->previous_recipient() );
	}

	/**
	 * Point d'envoi UNIQUE de toutes les alertes e-mail du plugin.
	 *  - Plafond PAR TYPE d'événement (5/h) puis global (30/h) : une rafale d'un
	 *    même type (CSRF, 50 admins créés…) ne peut plus affamer les autres
	 *    alertes, ni épuiser le quota d'envoi de l'hébergeur. Les événements
	 *    restent journalisés.
	 *  - Texte brut forcé : lisible même si une extension impose le HTML.
	 *  - Un échec SMTP est journalisé (page Logs) au lieu d'être perdu en silence.
	 *
	 * @param string   $subject
	 * @param string[] $lines
	 * @param string[] $extra_to   Destinataires supplémentaires (ex. ancienne adresse admin).
	 * @param bool     $is_test    Envoi de test : hors plafond, erreur renvoyée à l'appelant.
	 * @param string   $type       Type d'alerte (clé d'événement) pour le plafond par type.
	 * @param bool     $bypass_cap Effacement du journal/de la référence : jamais plafonné.
	 * @return true|WP_Error
	 */
	/**
	 * Alerte de sécurité d'un autre module (Vulnérabilités & versions) : même
	 * destinataire et mêmes plafonds horaires que les alertes du journal.
	 *
	 * @return true|WP_Error
	 */
	public function send_security_alert( $subject, array $lines, $type ) {
		return $this->send_alert( (string) $subject, $lines, array(), false, 'vuln_' . sanitize_key( (string) $type ) );
	}

	private function send_alert( $subject, $lines, $extra_to = array(), $is_test = false, $type = 'generic', $bypass_cap = false ) {
		$to = array();
		foreach ( array_merge( $this->alert_recipients(), (array) $extra_to ) as $addr ) {
			$addr = sanitize_email( (string) $addr );
			if ( $addr && is_email( $addr ) && ! in_array( strtolower( $addr ), array_map( 'strtolower', $to ), true ) ) {
				$to[] = $addr;
			}
		}
		if ( empty( $to ) ) {
			return new WP_Error( 'cu_alert_no_recipient', __( 'Aucune adresse e-mail valide pour les alertes (Réglages > Alertes e-mail, ou Réglages > Général de WordPress).', 'cyberpush-ultra' ) );
		}

		if ( ! $is_test && ! $bypass_cap ) {
			$hour        = gmdate( 'YmdH' );
			$type_hash   = substr( md5( (string) $type ), 0, 10 );
			$type_key    = 'cu_audit_mail_' . $type_hash . '_' . $hour;
			$global_key  = 'cu_audit_mail_' . $hour;
			$type_sent   = (int) get_transient( $type_key );
			$global_sent = (int) get_transient( $global_key );
			if ( $type_sent >= self::MAIL_CAP_PER_TYPE || $global_sent >= self::MAIL_CAP_GLOBAL ) {
				$noted = 'cu_audit_mailcap_' . $type_hash . '_' . $hour;
				if ( ! get_transient( $noted ) ) {
					set_transient( $noted, 1, HOUR_IN_SECONDS );
					CU_Logger::log( 'firewall', 'warning', sprintf(
						/* translators: %s: type d'alerte */
						__( 'Plafond horaire d’alertes e-mail atteint (%s) : les événements suivants restent visibles dans le Journal de sécurité mais ne sont plus envoyés par e-mail cette heure-ci.', 'cyberpush-ultra' ),
						$type_sent >= self::MAIL_CAP_PER_TYPE ? sanitize_key( (string) $type ) : __( 'tous types', 'cyberpush-ultra' )
					) );
				}
				return new WP_Error( 'cu_alert_capped', __( 'Plafond horaire d’alertes e-mail atteint.', 'cyberpush-ultra' ) );
			}
			set_transient( $type_key, $type_sent + 1, HOUR_IN_SECONDS );
			set_transient( $global_key, $global_sent + 1, HOUR_IN_SECONDS );
		}

		// wp_mail() ne renvoie qu'un booléen : capter le détail de l'erreur PHPMailer.
		$mail_error = '';
		$catch      = function ( $error ) use ( &$mail_error ) {
			if ( $error instanceof WP_Error ) {
				$mail_error = $error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = wp_mail( $to, $subject, implode( "\n", $lines ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
		remove_action( 'wp_mail_failed', $catch );

		if ( $ok ) {
			return true;
		}
		$detail = '' !== $mail_error ? $mail_error : __( 'wp_mail() a renvoyé un échec sans détail (fonction mail() du serveur désactivée ?).', 'cyberpush-ultra' );
		if ( ! $is_test ) {
			CU_Logger::log( 'firewall', 'error', sprintf( __( 'Échec d’envoi d’une alerte e-mail : %s', 'cyberpush-ultra' ), $this->trim255( $detail ) ), array( 'subject' => $this->trim255( $subject ) ) );
		}
		return new WP_Error( 'cu_alert_send_failed', $detail );
	}

	// =========================================================================
	// Événements (hooks WordPress)
	// =========================================================================

	private function hook_events() {
		global $wpdb;
		$self = $this;

		// --- Extensions -----------------------------------------------------
		add_action( 'activated_plugin', function ( $plugin ) use ( $self ) {
			$self->record( 'critical', 'plugins', 'plugin_activated', sprintf( __( 'Extension activée : %s', 'cyberpush-ultra' ), $self->plugin_label( $plugin ) ), 'plugin', $plugin );
		}, 10, 1 );
		add_action( 'deactivated_plugin', function ( $plugin ) use ( $self ) {
			$self->record( 'high', 'plugins', 'plugin_deactivated', sprintf( __( 'Extension désactivée : %s', 'cyberpush-ultra' ), $self->plugin_label( $plugin ) ), 'plugin', $plugin );
		}, 10, 1 );
		add_action( 'deleted_plugin', function ( $plugin, $deleted ) use ( $self ) {
			if ( $deleted ) {
				$self->record( 'high', 'plugins', 'plugin_deleted', sprintf( __( 'Extension supprimée : %s', 'cyberpush-ultra' ), $plugin ), 'plugin', $plugin );
				// Suppression faite par WordPress : le scan ne la signalera pas comme une disparition suspecte.
				$self->trust_units( array( 'plugins:' . $self->plugin_slug( $plugin ) ) );
			}
		}, 10, 2 );
		// Liste des extensions actives modifiée sans activate_plugin()/deactivate_plugins()
		// (code malveillant, requête directe…) : le seul moyen de la voir.
		add_action( 'update_option_active_plugins', array( $this, 'on_active_plugins_updated' ), 10, 2 );

		// --- Thèmes ---------------------------------------------------------
		add_action( 'switch_theme', function ( $new_name ) use ( $self ) {
			$self->record( 'critical', 'themes', 'theme_switched', sprintf( __( 'Thème actif changé : %s', 'cyberpush-ultra' ), $new_name ), 'theme', $new_name );
		}, 10, 1 );
		add_action( 'deleted_theme', function ( $stylesheet, $deleted ) use ( $self ) {
			if ( $deleted ) {
				$self->record( 'high', 'themes', 'theme_deleted', sprintf( __( 'Thème supprimé : %s', 'cyberpush-ultra' ), $stylesheet ), 'theme', $stylesheet );
				$self->trust_units( array( 'themes:' . sanitize_file_name( (string) $stylesheet ) ) );
			}
		}, 10, 2 );

		// --- Installations / mises à jour (extensions, thèmes, cœur, traductions)
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
		add_action( '_core_updated_successfully', function ( $wp_version ) use ( $self ) {
			$self->record( 'medium', 'core', 'core_updated', sprintf( __( 'WordPress mis à jour vers la version %s', 'cyberpush-ultra' ), $wp_version ), 'core', 'wordpress ' . $wp_version );
		}, 10, 1 );

		// --- Utilisateurs ---------------------------------------------------
		add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
		// Privilèges : TOUTE écriture de la méta « capabilities » (set_role, add_role,
		// add_cap, update_user_meta direct…) passe par ces hooks — une source unique,
		// donc pas de doublon entre set_user_role et add_user_role.
		add_action( 'update_user_meta', array( $this, 'before_caps_meta_update' ), 10, 4 );
		add_action( 'updated_user_meta', array( $this, 'on_caps_meta_updated' ), 10, 4 );
		add_action( 'added_user_meta', array( $this, 'on_caps_meta_added' ), 10, 4 );
		add_action( 'granted_super_admin', array( $this, 'on_granted_super_admin' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( 'delete_user', array( $this, 'before_delete_user' ), 10, 1 );
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ), 10, 1 );
		add_action( 'after_password_reset', array( $this, 'on_password_reset' ), 10, 1 );
		add_action( 'wp_create_application_password', array( $this, 'on_app_password_created' ), 10, 2 );
		add_action( 'wp_delete_application_password', array( $this, 'on_app_password_deleted' ), 10, 2 );
		// Capacités sensibles ajoutées à un rôle (option {préfixe}user_roles).
		add_action( 'update_option_' . $wpdb->get_blog_prefix() . 'user_roles', array( $this, 'on_roles_option_updated' ), 10, 2 );

		// --- Réglages critiques (options) -----------------------------------
		foreach ( array( 'siteurl', 'home', 'users_can_register', 'default_role', 'admin_email' ) as $opt ) {
			add_action( "update_option_{$opt}", array( $this, 'on_critical_option' ), 10, 3 );
		}
		// Les alertes elles-mêmes : les couper ou les rediriger est un geste
		// d'attaquant classique pour agir ensuite sans témoin.
		add_action( 'update_option_' . CU_OPTION_SETTINGS, array( $this, 'on_alert_settings_changed' ), 10, 2 );

		// --- Édition de fichier via l'éditeur intégré ------------------------
		// On ne journalise que si le fichier a RÉELLEMENT changé en fin de requête :
		// WordPress a donc validé nonce et droits. Une requête forgée (CSRF) ne
		// produit plus de faux événement ni ne consomme le quota d'alertes.
		add_action( 'wp_ajax_edit-theme-plugin-file', array( $this, 'detect_file_editor_ajax' ), 0 );
		add_action( 'admin_init', array( $this, 'detect_file_editor_save' ) );
	}

	/** Libellé lisible d'une extension à partir de son chemin. */
	public function plugin_label( $plugin_file ) {
		$plugin_file = (string) $plugin_file;
		if ( function_exists( 'get_plugin_data' ) ) {
			$path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( is_readable( $path ) ) {
				$data = get_plugin_data( $path, false, false );
				if ( ! empty( $data['Name'] ) ) {
					return $data['Name'];
				}
			}
		}
		return $plugin_file;
	}

	public function on_upgrader_complete( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) ) {
			return;
		}
		$type = $hook_extra['type']; // plugin | theme | core | translation
		if ( 'translation' === $type ) {
			// Paquets de langue (dont les .l10n.php, exécutables) : reconnus par le
			// scan, sous réserve que leurs dates correspondent à la mise à jour.
			$this->trust_units( array( 'content:languages' ) );
			return;
		}
		if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			return;
		}
		// wp-content/upgrade est le dossier de travail de WordPress : ses fichiers
		// d'extraction (y compris ceux laissés par une installation échouée) datent
		// de cette opération et ne sont pas une intrusion. Un fichier déposé plus
		// tard (hors fenêtre de dates) reste signalé.
		$this->trust_units( array( 'content:upgrade' ) );

		$action = isset( $hook_extra['action'] ) ? $hook_extra['action'] : 'update'; // install | update
		$result = is_object( $upgrader ) && isset( $upgrader->result ) ? $upgrader->result : null;
		if ( empty( $hook_extra['bulk'] ) && ( is_wp_error( $result ) || ( 'install' === $action && empty( $result ) ) ) ) {
			// Installation ou mise à jour refusée / échouée (dossier existant, PHP
			// requis…) : rien n'a changé, aucun événement « installé(s) / mis à jour ».
			return;
		}
		// « Remplacer la version installée » (envoi d'un ZIP d'une extension ou d'un
		// thème déjà présent) : WordPress le traite comme une installation qui
		// écrase la destination, mais c'est une mise à jour.
		if ( 'install' === $action && is_array( $result ) && ! empty( $result['clear_destination'] ) ) {
			$action = 'update';
		}
		$is_install = ( 'install' === $action );
		$sev        = $is_install ? 'critical' : 'high';

		$names = array();
		if ( 'plugin' === $type ) {
			if ( ! empty( $hook_extra['plugins'] ) ) {
				$names = (array) $hook_extra['plugins'];
			} elseif ( ! empty( $hook_extra['plugin'] ) ) {
				$names = array( $hook_extra['plugin'] );
			}
		} elseif ( 'theme' === $type ) {
			if ( ! empty( $hook_extra['themes'] ) ) {
				$names = (array) $hook_extra['themes'];
			} elseif ( ! empty( $hook_extra['theme'] ) ) {
				$names = array( $hook_extra['theme'] );
			}
		} elseif ( 'core' === $type ) {
			$names = array( 'wordpress' );
		}
		// Une installation depuis un zip ne renseigne pas le nom : le dossier créé le donne.
		// (Aussi pour un remplacement par ZIP, désormais classé « mise à jour ».)
		if ( ! $names && is_array( $result ) && ! empty( $result['destination_name'] ) ) {
			$names = array( (string) $result['destination_name'] );
		}
		$label = $names ? implode( ', ', array_map( 'sanitize_text_field', $names ) ) : $type;

		// Fichiers changés par WordPress lui-même : vérifiés puis reconnus par le
		// prochain scan d'intégrité (empreintes wordpress.org ou fenêtre de dates).
		$units = array();
		foreach ( $names as $name ) {
			if ( 'plugin' === $type ) {
				$units[] = 'plugins:' . $this->plugin_slug( (string) $name );
			} elseif ( 'theme' === $type ) {
				$units[] = 'themes:' . sanitize_file_name( (string) $name );
			}
		}
		if ( 'core' === $type ) {
			$units[] = 'core';
		}
		$this->trust_units( $units );

		$verb = $is_install ? __( 'installé(s)', 'cyberpush-ultra' ) : __( 'mis à jour', 'cyberpush-ultra' );
		$what = 'plugin' === $type ? __( 'Extension', 'cyberpush-ultra' ) : ( 'theme' === $type ? __( 'Thème', 'cyberpush-ultra' ) : __( 'Cœur WordPress', 'cyberpush-ultra' ) );
		$this->record( $sev, ( 'core' === $type ? 'core' : ( $type . 's' ) ), $type . '_' . $action, sprintf( '%1$s %2$s : %3$s', $what, $verb, $label ), $type, $label );
	}

	public function on_user_register( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->record( 'medium', 'users', 'user_registered', sprintf( __( 'Nouvel utilisateur créé : %s', 'cyberpush-ultra' ), '#' . (int) $user_id ), 'user', '#' . (int) $user_id );
			return;
		}
		$grants = $this->privileged_grants( (array) $user->caps );
		if ( in_array( 'administrator', $grants, true ) || user_can( $user, 'manage_options' ) ) {
			$this->record( 'critical', 'users', 'admin_created', sprintf( __( 'Nouvel ADMINISTRATEUR créé : %s', 'cyberpush-ultra' ), $user->user_login ), 'user', $user->user_login );
		} elseif ( $grants ) {
			/* translators: 1: identifiant, 2: rôles ou capacités */
			$this->record( 'critical', 'users', 'privileged_user_created', sprintf( __( 'Nouveau compte à privilèges élevés créé : %1$s (%2$s)', 'cyberpush-ultra' ), $user->user_login, implode( ', ', $grants ) ), 'user', $user->user_login );
		} else {
			$this->record( 'medium', 'users', 'user_registered', sprintf( __( 'Nouvel utilisateur créé : %s', 'cyberpush-ultra' ), $user->user_login ), 'user', $user->user_login );
		}
	}

	/** Clé de méta des capacités pour le site courant (multisite : wp_2_capabilities…). */
	private function caps_meta_key() {
		global $wpdb;
		return $wpdb->get_blog_prefix() . 'capabilities';
	}

	/** Mémorise les capacités AVANT leur mise à jour (le hook « updated » ne donne que la nouvelle valeur). */
	public function before_caps_meta_update( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( $meta_key !== $this->caps_meta_key() ) {
			return;
		}
		$old = get_user_meta( (int) $user_id, $meta_key, true );
		$this->caps_before[ (int) $user_id ] = is_array( $old ) ? $old : array();
	}

	public function on_caps_meta_updated( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( $meta_key !== $this->caps_meta_key() ) {
			return;
		}
		$user_id = (int) $user_id;
		$old     = isset( $this->caps_before[ $user_id ] ) ? $this->caps_before[ $user_id ] : array();
		unset( $this->caps_before[ $user_id ] );
		$this->on_caps_changed( $user_id, $old, $meta_value, false );
	}

	public function on_caps_meta_added( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( $meta_key !== $this->caps_meta_key() ) {
			return;
		}
		$this->on_caps_changed( (int) $user_id, array(), $meta_value, true );
	}

	/**
	 * Escalade de privilèges : rôle privilégié ou capacité sensible apparus dans
	 * la méta « capabilities », quel que soit le chemin (set_role, add_role,
	 * add_cap, écriture directe de la méta).
	 */
	private function on_caps_changed( $user_id, $old, $new, $added ) {
		$new    = maybe_unserialize( $new );
		$now    = $this->privileged_grants( is_array( $new ) ? $new : array() );
		$before = $this->privileged_grants( is_array( $old ) ? $old : array() );
		$gained = array_values( array_diff( $now, $before ) );
		// Retrait du rôle administrateur (ou de tout privilège élevé) : tracé, sans e-mail.
		$lost = array_values( array_diff( $before, $now ) );
		if ( $lost && ! $added ) {
			$lost_user  = get_userdata( $user_id );
			$lost_login = $lost_user ? $lost_user->user_login : ( '#' . (int) $user_id );
			if ( in_array( 'administrator', $lost, true ) ) {
				/* translators: %s: identifiant */
				$this->record( 'high', 'users', 'role_demoted', sprintf( __( 'Rôle ADMINISTRATEUR retiré : %s', 'cyberpush-ultra' ), $lost_login ), 'user', $lost_login, array( 'before' => implode( ', ', $lost ) ) );
			} else {
				/* translators: 1: identifiant, 2: rôles ou capacités retirés */
				$this->record( 'medium', 'users', 'privileges_removed', sprintf( __( 'Privilèges élevés retirés à %1$s : %2$s', 'cyberpush-ultra' ), $lost_login, implode( ', ', $lost ) ), 'user', $lost_login, array( 'before' => implode( ', ', $lost ) ) );
			}
		}
		if ( ! $gained ) {
			return;
		}
		$user = get_userdata( $user_id );
		// Création de compte : wp_insert_user() pose la méta juste avant user_register,
		// qui journalise déjà le nouveau compte (on_user_register).
		if ( $added && $user && ! empty( $user->user_registered ) && strtotime( $user->user_registered . ' UTC' ) >= time() - 300 ) {
			return;
		}
		$login = $user ? $user->user_login : ( '#' . (int) $user_id );
		if ( in_array( 'administrator', $gained, true ) ) {
			$this->record( 'critical', 'users', 'role_elevated', sprintf( __( 'Utilisateur promu ADMINISTRATEUR : %s', 'cyberpush-ultra' ), $login ), 'user', $login, array( 'after' => implode( ', ', $gained ) ) );
			return;
		}
		/* translators: 1: identifiant, 2: rôles ou capacités ajoutés */
		$this->record( 'critical', 'users', 'privileges_granted', sprintf( __( 'Privilèges élevés accordés à %1$s : %2$s', 'cyberpush-ultra' ), $login, implode( ', ', $gained ) ), 'user', $login, array( 'after' => implode( ', ', $gained ) ) );
	}

	/**
	 * Rôles/capacités « privilégiés » d'une méta capabilities : administrateur,
	 * tout rôle qui porte une capacité sensible, ou une capacité sensible
	 * accordée directement.
	 *
	 * @return string[]
	 */
	private function privileged_grants( array $caps ) {
		$out = array();
		foreach ( $caps as $name => $granted ) {
			if ( ! $granted || ! is_string( $name ) || '' === $name ) {
				continue;
			}
			if ( 'administrator' === $name ) {
				$out[] = 'administrator';
				continue;
			}
			$role = function_exists( 'get_role' ) ? get_role( $name ) : null;
			if ( $role ) {
				if ( $this->caps_are_privileged( (array) $role->capabilities ) ) {
					$out[] = $name;
				}
			} elseif ( in_array( $name, self::PRIVILEGED_CAPS, true ) ) {
				$out[] = $name;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function caps_are_privileged( array $caps ) {
		foreach ( self::PRIVILEGED_CAPS as $cap ) {
			if ( ! empty( $caps[ $cap ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Capacités sensibles ajoutées à un rôle existant, ou rôle privilégié créé. */
	public function on_roles_option_updated( $old, $new ) {
		if ( ! is_array( $new ) ) {
			return;
		}
		$old    = is_array( $old ) ? $old : array();
		$events = 0;
		foreach ( $new as $role => $data ) {
			if ( 'administrator' === $role || ! is_array( $data ) ) {
				continue; // l'administrateur possède déjà toutes les capacités sensibles
			}
			$new_caps = isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ? $data['capabilities'] : array();
			$old_caps = isset( $old[ $role ]['capabilities'] ) && is_array( $old[ $role ]['capabilities'] ) ? $old[ $role ]['capabilities'] : array();
			$gained   = array();
			foreach ( self::PRIVILEGED_CAPS as $cap ) {
				if ( ! empty( $new_caps[ $cap ] ) && empty( $old_caps[ $cap ] ) ) {
					$gained[] = $cap;
				}
			}
			if ( ! $gained ) {
				continue;
			}
			$role = sanitize_key( (string) $role );
			if ( isset( $old[ $role ] ) ) {
				/* translators: 1: rôle, 2: capacités */
				$this->record( 'critical', 'users', 'role_caps_added', sprintf( __( 'Capacités sensibles ajoutées au rôle « %1$s » : %2$s', 'cyberpush-ultra' ), $role, implode( ', ', $gained ) ), 'role', $role, array( 'after' => implode( ', ', $gained ) ) );
			} else {
				/* translators: 1: rôle, 2: capacités */
				$this->record( 'critical', 'users', 'role_created_privileged', sprintf( __( 'Rôle privilégié créé : « %1$s » (%2$s)', 'cyberpush-ultra' ), $role, implode( ', ', $gained ) ), 'role', $role, array( 'after' => implode( ', ', $gained ) ) );
			}
			if ( ++$events >= 5 ) {
				break; // une réécriture complète des rôles ne doit pas inonder le journal
			}
		}
	}

	public function on_granted_super_admin( $user_id ) {
		$user  = get_userdata( (int) $user_id );
		$login = $user ? $user->user_login : ( '#' . (int) $user_id );
		$this->record( 'critical', 'users', 'super_admin_granted', sprintf( __( 'Droits de SUPER-ADMINISTRATEUR du réseau accordés à %s', 'cyberpush-ultra' ), $login ), 'user', $login );
	}

	/**
	 * active_plugins modifiée hors activate_plugin()/deactivate_plugins() (aucun
	 * hook « activate_{extension} » dans la requête) : chargement d'une extension
	 * à l'insu de WordPress. Les réactivations silencieuses du cœur pendant une
	 * mise à jour sont ignorées ; un administrateur connecté reste tracé, en moins grave.
	 */
	public function on_active_plugins_updated( $old, $new ) {
		$old = is_array( $old ) ? array_map( 'strval', $old ) : array();
		$new = is_array( $new ) ? array_map( 'strval', $new ) : array();
		if ( doing_filter( 'upgrader_pre_install' ) || doing_filter( 'upgrader_post_install' ) || doing_action( 'upgrader_process_complete' ) ) {
			return;
		}
		$capable = function_exists( 'current_user_can' ) && current_user_can( 'activate_plugins' );
		foreach ( array_slice( array_values( array_diff( $new, $old ) ), 0, 10 ) as $plugin ) {
			if ( did_action( 'activate_' . $plugin ) || doing_action( 'activate_plugin' ) ) {
				continue; // activation standard : « Extension activée » journalisé par activated_plugin
			}
			$this->record( $capable ? 'medium' : 'critical', 'plugins', 'plugin_activated_direct', sprintf( __( 'Extension activée sans le processus standard de WordPress : %s', 'cyberpush-ultra' ), $this->plugin_label( $plugin ) ), 'plugin', $plugin );
		}
		foreach ( array_slice( array_values( array_diff( $old, $new ) ), 0, 10 ) as $plugin ) {
			if ( did_action( 'deactivate_' . $plugin ) || doing_action( 'deactivate_plugin' ) ) {
				continue;
			}
			$this->record( $capable ? 'low' : 'high', 'plugins', 'plugin_deactivated_direct', sprintf( __( 'Extension désactivée sans le processus standard de WordPress : %s', 'cyberpush-ultra' ), $plugin ), 'plugin', $plugin );
		}
	}

	public function on_profile_update( $user_id, $old_user_data ) {
		$new = get_userdata( $user_id );
		if ( ! $new || ! is_object( $old_user_data ) ) {
			return;
		}
		if ( isset( $old_user_data->user_email ) && $old_user_data->user_email !== $new->user_email ) {
			// Adresse d'un administrateur : elle sert à réinitialiser son mot de passe (alerte).
			$this->record( user_can( $new, 'manage_options' ) ? 'critical' : 'high', 'users', 'user_email_changed', sprintf( __( 'Email modifié pour %1$s (%2$s → %3$s)', 'cyberpush-ultra' ), $new->user_login, $old_user_data->user_email, $new->user_email ), 'user', $new->user_login );
		}
		if ( isset( $old_user_data->user_pass ) && $old_user_data->user_pass !== $new->user_pass ) {
			$sev = user_can( $new, 'manage_options' ) ? 'critical' : 'medium';
			$this->record( $sev, 'users', 'password_changed', sprintf( __( 'Mot de passe modifié pour %s', 'cyberpush-ultra' ), $new->user_login ), 'user', $new->user_login );
		}
	}

	/** Identité du compte AVANT sa suppression (deleted_user ne donne plus que l'ID). */
	public function before_delete_user( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( $user ) {
			$this->deleting[ (int) $user_id ] = array( 'login' => $user->user_login, 'admin' => user_can( $user, 'manage_options' ) );
		}
	}

	public function on_deleted_user( $user_id ) {
		$known = isset( $this->deleting[ (int) $user_id ] ) ? $this->deleting[ (int) $user_id ] : null;
		unset( $this->deleting[ (int) $user_id ] );
		if ( $known && $known['admin'] ) {
			/* translators: %s: identifiant */
			$this->record( 'high', 'users', 'admin_deleted', sprintf( __( 'ADMINISTRATEUR supprimé : %s', 'cyberpush-ultra' ), $known['login'] ), 'user', $known['login'] );
			return;
		}
		if ( $known ) {
			/* translators: 1: identifiant, 2: ID */
			$this->record( 'high', 'users', 'user_deleted', sprintf( __( 'Utilisateur supprimé : %1$s (ID %2$d)', 'cyberpush-ultra' ), $known['login'], (int) $user_id ), 'user', $known['login'] );
			return;
		}
		$this->record( 'high', 'users', 'user_deleted', sprintf( __( 'Utilisateur supprimé (ID %d)', 'cyberpush-ultra' ), (int) $user_id ), 'user', '#' . (int) $user_id );
	}

	/** Mot de passe réinitialisé par le lien « Mot de passe oublié » (hors profile_update). */
	public function on_password_reset( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$admin = user_can( $user, 'manage_options' );
		/* translators: %s: identifiant */
		$this->record( $admin ? 'critical' : 'medium', 'users', 'password_reset', sprintf( __( 'Mot de passe réinitialisé (lien « mot de passe oublié ») pour %s', 'cyberpush-ultra' ), $user->user_login ), 'user', $user->user_login );
	}

	/**
	 * Mot de passe d'application créé / supprimé : seul son NOM est gardé, jamais
	 * le mot de passe (ni son empreinte).
	 */
	public function on_app_password_created( $user_id, $item = array() ) {
		$user  = get_userdata( (int) $user_id );
		$login = $user ? $user->user_login : ( '#' . (int) $user_id );
		$name  = is_array( $item ) && isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
		$admin = $user && user_can( $user, 'manage_options' );
		/* translators: 1: identifiant, 2: nom du mot de passe d'application */
		$this->record( $admin ? 'critical' : 'medium', 'users', 'app_password_created', sprintf( __( 'Mot de passe d’application créé pour %1$s : « %2$s »', 'cyberpush-ultra' ), $login, $name ), 'user', $login );
	}

	public function on_app_password_deleted( $user_id, $item = array() ) {
		$user  = get_userdata( (int) $user_id );
		$login = $user ? $user->user_login : ( '#' . (int) $user_id );
		$name  = is_array( $item ) && isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
		/* translators: 1: identifiant, 2: nom du mot de passe d'application */
		$this->record( 'medium', 'users', 'app_password_deleted', sprintf( __( 'Mot de passe d’application supprimé pour %1$s : « %2$s »', 'cyberpush-ultra' ), $login, $name ), 'user', $login );
	}

	/**
	 * Alerte l'ANCIEN destinataire quand les alertes sont coupées, redirigées
	 * vers une autre adresse, ou que le journal de sécurité est désactivé.
	 * Envoi forcé et hors plafond : à ce stade, le nouveau réglage dit déjà
	 * « pas d'e-mail ».
	 */
	public function on_alert_settings_changed( $old, $new ) {
		$read = static function ( $all, $key, $default ) {
			return ( is_array( $all ) && isset( $all['audit'] ) && is_array( $all['audit'] ) && array_key_exists( $key, $all['audit'] ) ) ? $all['audit'][ $key ] : $default;
		};
		$old_on      = (bool) $read( $old, 'email_alerts', true );
		$new_on      = (bool) $read( $new, 'email_alerts', true );
		$old_enabled = (bool) $read( $old, 'enabled', true );
		$new_enabled = (bool) $read( $new, 'enabled', true );
		$old_to      = trim( (string) $read( $old, 'alert_email', '' ) );
		$new_to      = trim( (string) $read( $new, 'alert_email', '' ) );
		$admin       = (string) get_option( 'admin_email' );
		$old_dest    = ( '' !== $old_to && is_email( $old_to ) ) ? $old_to : $admin;
		$new_dest    = ( '' !== $new_to && is_email( $new_to ) ) ? $new_to : $admin;

		if ( 0 !== strcasecmp( $old_dest, $new_dest ) ) {
			// Les gestes d'effacement des 7 prochains jours préviendront aussi l'ancienne adresse.
			$this->remember_previous_recipient( $old_dest );
		}

		$message = '';
		if ( $old_enabled && ! $new_enabled ) {
			$message = __( 'Journal de sécurité DÉSACTIVÉ (plus aucun événement ni alerte ne sera enregistré)', 'cyberpush-ultra' );
		} elseif ( $old_on && ! $new_on ) {
			$message = __( 'Alertes e-mail de sécurité DÉSACTIVÉES', 'cyberpush-ultra' );
		} elseif ( $old_on && $new_on && 0 !== strcasecmp( $old_dest, $new_dest ) ) {
			/* translators: 1: ancienne adresse, 2: nouvelle adresse. */
			$message = sprintf( __( 'Adresse des alertes de sécurité changée : %1$s → %2$s', 'cyberpush-ultra' ), $old_dest, $new_dest );
		}
		if ( '' === $message || ! $old_on ) {
			return; // rien de sensible, ou alertes déjà coupées avant ce changement.
		}
		$this->record( 'critical', 'settings', 'alert_settings_changed', $message, 'option', 'cu_settings.audit', array( 'before' => $old_dest, 'after' => $new_dest ), array( 'to' => array( $old_dest ), 'force' => true, 'urgent' => true ) );
	}

	public function on_critical_option( $old_value, $new_value, $option ) {
		if ( $old_value === $new_value ) {
			return;
		}
		switch ( $option ) {
			case 'siteurl':
			case 'home':
				$this->record( 'critical', 'settings', 'site_url_changed', sprintf( __( 'Adresse du site (%1$s) changée : %2$s → %3$s', 'cyberpush-ultra' ), $option, $old_value, $new_value ), 'option', $option, array( 'before' => $old_value, 'after' => $new_value ) );
				break;
			case 'users_can_register':
				if ( $new_value ) {
					$this->record( 'high', 'settings', 'registration_opened', __( 'Les inscriptions publiques ont été OUVERTES', 'cyberpush-ultra' ), 'option', $option );
				} else {
					$this->record( 'medium', 'settings', 'registration_closed', __( 'Les inscriptions publiques ont été fermées', 'cyberpush-ultra' ), 'option', $option );
				}
				break;
			case 'default_role':
				$sev = ( 'administrator' === $new_value || 'editor' === $new_value ) ? 'critical' : 'high';
				$this->record( $sev, 'settings', 'default_role_changed', sprintf( __( 'Rôle par défaut des nouveaux comptes : %1$s → %2$s', 'cyberpush-ultra' ), $old_value, $new_value ), 'option', $option, array( 'before' => $old_value, 'after' => $new_value ) );
				break;
			case 'admin_email':
				// Premier geste d'une prise de contrôle : détourner l'adresse qui reçoit
				// les alertes et les réinitialisations de mot de passe. CRITIQUE, et
				// l'alerte part AUSSI vers l'ANCIENNE adresse — la nouvelle est déjà
				// enregistrée à ce stade et appartient peut-être à l'attaquant.
				$custom = trim( (string) CU_Settings::get( 'audit', 'alert_email', '' ) );
				if ( '' === $custom || ! is_email( $custom ) ) {
					$this->remember_previous_recipient( (string) $old_value );
				}
				$this->record( 'critical', 'settings', 'admin_email_changed', sprintf( __( 'Email d\'administration changé : %1$s → %2$s', 'cyberpush-ultra' ), $old_value, $new_value ), 'option', $option, array( 'before' => $old_value, 'after' => $new_value ), array( 'to' => array( $old_value ) ) );
				break;
		}
	}

	/** Sauvegarde historique (POST theme-editor.php / plugin-editor.php). */
	public function detect_file_editor_save() {
		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		$page = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		if ( 'theme-editor.php' !== $page && 'plugin-editor.php' !== $page ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- lecture seule ; l'événement n'est écrit que si WordPress a enregistré le fichier.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( 'update' !== $action ) {
			return;
		}
		$file  = isset( $_POST['file'] ) && is_scalar( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$theme = '';
		if ( 'theme-editor.php' === $page ) {
			$theme = isset( $_REQUEST['theme'] ) && is_scalar( $_REQUEST['theme'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['theme'] ) ) : get_stylesheet();
		}
		// phpcs:enable
		$this->watch_editor_target( $theme, '', $file );
	}

	/** Sauvegarde AJAX de l'éditeur de thème/extension (WordPress 4.9+). */
	public function detect_file_editor_ajax() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce vérifié par le cœur ; on ne trace qu'une écriture effective.
		$theme  = isset( $_POST['theme'] ) && is_scalar( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		$plugin = isset( $_POST['plugin'] ) && is_scalar( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$file   = isset( $_POST['file'] ) && is_scalar( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		// phpcs:enable
		$this->watch_editor_target( $theme, $plugin, $file );
	}

	/**
	 * Empreinte le fichier visé AVANT que WordPress ne traite la demande, puis le
	 * compare en fin de requête : l'événement n'existe que si l'écriture a eu
	 * lieu (nonce, droits et contrôle de boucle locale acceptés par le cœur).
	 */
	private function watch_editor_target( $theme, $plugin, $file ) {
		$is_theme = '' !== (string) $theme;
		if ( '' === (string) $file && ! $is_theme ) {
			$file = (string) $plugin;
		}
		if ( '' === (string) $file || ! current_user_can( $is_theme ? 'edit_themes' : 'edit_plugins' ) || 0 !== validate_file( $file ) ) {
			return;
		}
		if ( $is_theme ) {
			$t = wp_get_theme( $theme );
			if ( ! $t->exists() ) {
				return;
			}
			$path = trailingslashit( wp_normalize_path( $t->get_stylesheet_directory() ) ) . $file;
		} else {
			$path = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) . $file;
		}
		$before = is_file( $path ) ? (string) @md5_file( $path ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$self   = $this;
		$kind   = $is_theme ? __( 'thème', 'cyberpush-ultra' ) : __( 'extension', 'cyberpush-ultra' );
		$object = $is_theme ? $theme . '/' . $file : $file;
		add_action( 'shutdown', function () use ( $self, $path, $before, $kind, $object ) {
			clearstatcache( true, $path );
			$after = is_file( $path ) ? (string) @md5_file( $path ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $after === $before ) {
				return; // refusée (nonce, droits) ou annulée par WordPress (erreur fatale détectée)
			}
			/* translators: 1: thème|extension, 2: fichier */
			$self->record( 'critical', 'files', 'file_editor_edit', sprintf( __( 'Fichier de %1$s modifié via l\'éditeur intégré : %2$s', 'cyberpush-ultra' ), $kind, $object ), 'file', $object, array( 'before' => $before ? $before : '—', 'after' => $after ? $after : '—' ) );
		}, 0 );
	}

	// =========================================================================
	// Intégrité des fichiers (FIM) — chemins et zones
	// =========================================================================

	/** Racine WordPress normalisée (avec slash final). */
	private function abspath() {
		return trailingslashit( wp_normalize_path( ABSPATH ) );
	}

	private function content_dir() {
		return trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
	}

	private function plugins_dir() {
		return trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
	}

	private function mu_dir() {
		return trailingslashit( wp_normalize_path( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : $this->content_dir() . 'mu-plugins' ) );
	}

	private function themes_dir() {
		return trailingslashit( wp_normalize_path( get_theme_root() ) );
	}

	private function uploads_dir() {
		$up = wp_get_upload_dir();
		return ! empty( $up['basedir'] ) ? trailingslashit( wp_normalize_path( $up['basedir'] ) ) : '';
	}

	/** Préfixe → zone ; le plus long l'emporte (voir classify()). */
	private function zones() {
		if ( null !== $this->zones ) {
			return $this->zones;
		}
		$abs   = $this->abspath();
		$zones = array(
			array( $abs . 'wp-admin/', 'core' ),
			array( $abs . 'wp-includes/', 'core' ),
			array( $this->plugins_dir(), 'plugins' ),
			array( $this->themes_dir(), 'themes' ),
			array( $this->mu_dir(), 'mu' ),
			array( $this->content_dir(), 'content' ),
			array( $abs, 'root' ),
		);
		$uploads = $this->uploads_dir();
		if ( '' !== $uploads ) {
			$zones[] = array( $uploads, 'uploads' );
		}
		usort( $zones, function ( $a, $b ) { return strlen( $b[0] ) - strlen( $a[0] ); } );
		$this->zones = $zones;
		return $zones;
	}

	/** Fichier surveillé : code exécutable par PHP ou configuration qui peut le rendre exécutable. */
	private function is_watched_name( $name ) {
		$name = strtolower( (string) $name );
		if ( in_array( $name, self::CONFIG_FILES, true ) ) {
			return true;
		}
		return in_array( pathinfo( $name, PATHINFO_EXTENSION ), self::EXEC_EXTENSIONS, true );
	}

	/** Chemin relatif à ABSPATH pour un stockage compact et lisible. */
	private function rel_path( $abs_file ) {
		$abs      = $this->abspath();
		$abs_file = wp_normalize_path( $abs_file );
		if ( 0 === strpos( $abs_file, $abs ) ) {
			return substr( $abs_file, strlen( $abs ) );
		}
		return $abs_file; // wp-config une couche au-dessus, wp-content déporté, etc.
	}

	/** Chemin absolu d'une entrée de la référence (inverse de rel_path()). */
	private function abs_of( $rel ) {
		return preg_match( '#^(?:/|[a-zA-Z]:/)#', $rel ) ? $rel : $this->abspath() . $rel;
	}

	/**
	 * Zone et unité d'un fichier. L'unité regroupe les changements : une
	 * extension ou un thème (par dossier), un sous-dossier de wp-content, sinon
	 * la zone elle-même.
	 *
	 * @return array{0: string, 1: string} array( zone, unité )
	 */
	private function classify( $abs ) {
		foreach ( $this->zones() as $z ) {
			if ( 0 !== strpos( $abs, $z[0] ) ) {
				continue;
			}
			$rest = substr( $abs, strlen( $z[0] ) );
			if ( 'plugins' === $z[1] || 'themes' === $z[1] ) {
				$parts = explode( '/', $rest, 2 );
				return array( $z[1], substr( $z[1] . ':' . $parts[0], 0, 100 ) );
			}
			if ( 'content' === $z[1] ) {
				if ( false === strpos( $rest, '/' ) ) {
					return array( 'dropins', 'dropins' ); // advanced-cache.php, object-cache.php, db.php…
				}
				$parts = explode( '/', $rest, 2 );
				return array( 'content', substr( 'content:' . $parts[0], 0, 100 ) );
			}
			return array( $z[1], $z[1] );
		}
		return array( 'root', 'root' ); // wp-config.php au-dessus de la racine
	}

	/** Zone d'une unité enregistrée (« plugins:akismet » → plugins). */
	private function zone_of_unit( $unit ) {
		$parts = explode( ':', (string) $unit, 2 );
		return $parts[0];
	}

	/** Identifiant d'une extension : son dossier, ou son fichier unique. */
	public function plugin_slug( $plugin_file ) {
		$plugin_file = wp_normalize_path( (string) $plugin_file );
		return '.' === dirname( $plugin_file ) ? basename( $plugin_file ) : strtok( $plugin_file, '/' );
	}

	/**
	 * Marque des unités comme modifiées par WordPress (installation, mise à jour,
	 * suppression) : le prochain scan vérifie leurs fichiers puis met leur
	 * référence à jour.
	 *
	 * @param string[] $units Ex. 'plugins:woocommerce', 'themes:astra', 'core', 'content:languages'.
	 */
	public function trust_units( array $units ) {
		if ( ! $units ) {
			return;
		}
		$trusted = get_option( self::TRUSTED_OPTION, array() );
		$trusted = is_array( $trusted ) ? $trusted : array();
		foreach ( $units as $unit ) {
			$trusted[ (string) $unit ] = time();
		}
		update_option( self::TRUSTED_OPTION, $trusted, false );
		$this->trusted_memo = null;
	}

	/** Une installation/mise à jour WordPress a-t-elle eu lieu dans l'heure ? */
	private function recent_wp_update() {
		$latest = $this->trusted() ? max( array_map( 'intval', $this->trusted() ) ) : 0;
		return $latest >= time() - HOUR_IN_SECONDS;
	}

	private function trusted() {
		if ( null === $this->trusted_memo ) {
			$t                  = get_option( self::TRUSTED_OPTION, array() );
			$this->trusted_memo = is_array( $t ) ? $t : array();
		}
		return $this->trusted_memo;
	}

	/**
	 * Fichier exécutable trouvé dans les médias : « inert » s'il ne contient aucun
	 * code actif — index.php vide (« Silence is golden »), garde `exit` suivie de
	 * commentaires (journaux protégés), .htaccess qui interdit l'accès — sinon
	 * « danger ». Analyse lexicale (token_get_all), sans jamais exécuter le fichier.
	 */
	private function inspect_upload_file( $abs ) {
		$code = (string) @file_get_contents( $abs, false, null, 0, 262144 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$name = strtolower( basename( $abs ) );
		if ( in_array( $name, self::CONFIG_FILES, true ) ) {
			// Directive qui fait exécuter du PHP (ou en injecte) : AddType .jpg, auto_prepend_file…
			return preg_match( '/\b(?:AddType|AddHandler|SetHandler|ForceType|php_value|php_flag|php_admin_value|php_admin_flag)\b|auto_(?:prepend|append)_file|x-httpd-php|^\s*engine\s*=/im', $code ) ? 'danger' : 'inert';
		}
		return $this->php_is_inert( $code ) ? 'inert' : 'danger';
	}

	/** Le code PHP ne fait rien d'autre que s'arrêter (ou ne contient que des commentaires). */
	private function php_is_inert( $code ) {
		if ( '' === trim( $code ) ) {
			return true;
		}
		if ( ! function_exists( 'token_get_all' ) ) {
			// Repli sans l'extension tokenizer : seuls les cas évidents sont reconnus.
			return (bool) preg_match( '/^\s*<\?php\s*(?:(?:\/\/[^\n]*|#[^\n]*|\/\*.*?\*\/)\s*)*(?:(?:defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*(?:\|\||or)\s*)?(?:exit|die)\b[^;\n]*;\s*)?(?:(?:\/\/[^\n]*|#[^\n]*)\s*)*$/is', $code );
		}
		$ignored = array( T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML );
		$parts   = array();
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], $ignored, true ) ) {
					continue;
				}
				$parts[] = strtolower( $token[1] );
			} else {
				$parts[] = $token;
			}
		}
		if ( ! $parts ) {
			return true; // uniquement des commentaires ou du texte hors balises PHP
		}
		$code_line = implode( ' ', $parts );
		$stop      = "(?:exit|die)(?: \\( (?:'[^']*' |\"[^\"]*\" |\\d+ )?\\))? ;";
		$guard     = "defined \\( (?:'abspath'|\"abspath\") \\)";
		return (bool) preg_match( "/^(?:{$guard} (?:\\|\\||or) )?{$stop}$/", $code_line )
			|| (bool) preg_match( "/^if \\( ! {$guard} \\) (?:{$stop}|\\{ {$stop} \\})$/", $code_line );
	}

	// =========================================================================
	// FIM — verrou, état, migration
	// =========================================================================

	/**
	 * Verrou inter-processus d'un lot (cron, bouton, réinitialisation), stocké
	 * comme une option. L'acquisition est un INSERT IGNORE sur option_name
	 * (unique) : atomique, contrairement à add_option() qui vérifie puis écrit
	 * avec ON DUPLICATE KEY UPDATE. Un verrou orphelin (processus tué) est
	 * repris par compare-et-échange après LOCK_TTL. Lecture directe en base :
	 * aucun cache d'options ne peut masquer l'état réel.
	 *
	 * @return string|false Jeton propriétaire, ou false si un lot est en cours.
	 */
	private function acquire_scan_lock() {
		global $wpdb;
		$now   = time();
		$token = $now . '|' . wp_generate_uuid4();
		$won   = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $token ) );
		if ( 1 === $won ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			return $token;
		}
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
		if ( null === $current ) {
			return false; // libéré entre-temps : le prochain essai l'obtiendra
		}
		$current = (string) $current;
		$parts   = explode( '|', $current, 2 );
		$created = (int) $parts[0];
		if ( $created > 0 && $created <= $now && ( $now - $created ) < self::LOCK_TTL ) {
			return false;
		}
		$replaced = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$token,
			self::LOCK_OPTION,
			$current
		) );
		if ( 1 !== $replaced ) {
			return false;
		}
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return $token;
	}

	private function release_scan_lock( $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, (string) $token ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/** État du scan en cours (petit tableau) ou array() si aucun. */
	private function scan_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return ( is_array( $state ) && isset( $state['v'], $state['phase'] ) && 3 === (int) $state['v'] ) ? $state : array();
	}

	/** Remet à zéro les colonnes de travail de cu_fim (scan abandonné ou nouveau scan). */
	private function reset_scan_columns() {
		global $wpdb;
		$table = CU_DB::table( 'fim' );
		$a     = $wpdb->query( "DELETE FROM {$table} WHERE baseline_md5 IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$b     = $wpdb->query( "UPDATE {$table} SET scan_md5 = NULL, scan_state = 0 WHERE scan_state <> 0 OR scan_md5 IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return false !== $a && false !== $b;
	}

	/** Une référence existe-t-elle (table, ou ancienne option pas encore migrée) ? */
	private function baseline_ready() {
		global $wpdb;
		$table = CU_DB::table( 'fim' );
		if ( $wpdb->get_var( "SELECT 1 FROM {$table} WHERE baseline_md5 IS NOT NULL LIMIT 1" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return true;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::LEGACY_BASELINE_OPTION ) );
	}

	/**
	 * Migration 1.1.7 → 1.2.0 : la référence quitte l'option cu_audit_baseline
	 * (plusieurs Mo, réécrite à chaque lot) pour la table cu_fim. Idempotente ;
	 * l'ancienne option n'est supprimée qu'après une insertion complète.
	 */
	public function migrate_legacy_storage() {
		global $wpdb;
		if ( ! CU_DB::tables_exist_for( array( 'fim' ) ) ) {
			return false;
		}
		$has_legacy = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::LEGACY_BASELINE_OPTION ) );
		$has_state  = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::LEGACY_STATE_OPTION ) );
		if ( $has_legacy ) {
			$table  = CU_DB::table( 'fim' );
			$legacy = get_option( self::LEGACY_BASELINE_OPTION, array() );
			$exists = $wpdb->get_var( "SELECT 1 FROM {$table} WHERE baseline_md5 IS NOT NULL LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $legacy ) && $legacy && ! $exists ) {
				$rows = array();
				foreach ( $legacy as $rel => $md5 ) {
					if ( ! is_string( $md5 ) || ! preg_match( '/^[a-f0-9]{32}$/', $md5 ) ) {
						continue;
					}
					$rel      = (string) $rel;
					$cls      = $this->classify( $this->abs_of( $rel ) );
					$rows[]   = array( md5( $rel ), $rel, $cls[1], $md5 );
					if ( count( $rows ) >= 300 ) {
						if ( ! $this->insert_baseline_rows( $rows ) ) {
							return false; // réessayé au prochain lot ; l'ancienne option reste intacte
						}
						$rows = array();
					}
				}
				if ( $rows && ! $this->insert_baseline_rows( $rows ) ) {
					return false;
				}
			}
			unset( $legacy );
			delete_option( self::LEGACY_BASELINE_OPTION );
		}
		if ( $has_state ) {
			delete_option( self::LEGACY_STATE_OPTION );
		}
		if ( $has_legacy || $has_state ) {
			CU_Logger::log( 'firewall', 'info', __( 'Référence d’intégrité migrée vers la table dédiée (anciennes options supprimées).', 'cyberpush-ultra' ) );
		}
		return true;
	}

	/** @param array<int,array{0:string,1:string,2:string,3:string}> $rows path_hash, path, unit, md5 */
	private function insert_baseline_rows( array $rows ) {
		global $wpdb;
		$table  = CU_DB::table( 'fim' );
		$now    = current_time( 'mysql' );
		$values = array();
		$params = array();
		foreach ( $rows as $r ) {
			$values[] = '(%s,%s,%s,%s,0,%s)';
			array_push( $params, $r[0], $r[1], $r[2], $r[3], $now );
		}
		$sql = "INSERT INTO {$table} (path_hash, path, unit, baseline_md5, scan_state, updated_at) VALUES " . implode( ',', $values )
			. ' ON DUPLICATE KEY UPDATE baseline_md5 = VALUES(baseline_md5), unit = VALUES(unit), path = VALUES(path)';
		return false !== $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// =========================================================================
	// FIM — plan de parcours
	// =========================================================================

	/** Plafonds filtrables. */
	private function max_files() {
		return max( 1000, (int) apply_filters( 'cu_audit_max_files', self::MAX_FILES ) );
	}

	private function max_visits() {
		return max( 10000, (int) apply_filters( 'cu_audit_max_visits', self::MAX_VISITS ) );
	}

	/**
	 * Racines du parcours, de la plus sensible à la moins sensible (le plafond
	 * de fichiers ne peut donc exclure qu'une extension inactive en fin de liste) :
	 * racine du site (premier niveau), wp-config parent, must-use, wp-content
	 * (drop-ins, languages, upgrade, dossiers tiers), médias, cœur, thèmes et
	 * extensions actifs, puis tous les autres — dossiers cachés compris.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}> chemin, mode (f = fichiers seuls, r = récursif, file), étiquette
	 */
	private function build_roots() {
		$abs     = $this->abspath();
		$content = $this->content_dir();
		$plugins = $this->plugins_dir();
		$themes  = $this->themes_dir();
		$mu      = $this->mu_dir();
		$uploads = $this->uploads_dir();

		$roots   = array();
		$roots[] = array( $abs, 'f', '' );
		// wp-config.php peut être un cran au-dessus de ABSPATH.
		$parent_cfg = wp_normalize_path( dirname( untrailingslashit( $abs ) ) ) . '/wp-config.php';
		if ( is_file( $parent_cfg ) ) {
			$roots[] = array( $parent_cfg, 'file', '' );
		}
		if ( is_dir( $mu ) ) {
			$roots[] = array( $mu, 'r', '' );
		}
		if ( is_dir( $content ) ) {
			$roots[] = array( $content, 'r', '' );
		}
		if ( '' !== $uploads && is_dir( $uploads ) ) {
			$roots[] = array( $uploads, 'r', 'uploads' );
		}
		$roots[] = array( $abs . 'wp-admin/', 'r', '' );
		$roots[] = array( $abs . 'wp-includes/', 'r', '' );

		// Thèmes : actif (enfant + parent) d'abord.
		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$roots[] = array( trailingslashit( wp_normalize_path( get_stylesheet_directory() ) ), 'r', '' );
			$roots[] = array( trailingslashit( wp_normalize_path( get_template_directory() ) ), 'r', '' );
		}
		// Extensions actives (y compris réseau) d'abord.
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $active as $plugin ) {
			$plugin = wp_normalize_path( (string) $plugin );
			// Extension en fichier unique : couverte par la racine du dossier ci-dessous.
			if ( '' === $plugin || 0 !== validate_file( $plugin ) || '.' === dirname( $plugin ) ) {
				continue;
			}
			$roots[] = array( $plugins . strtok( $plugin, '/' ) . '/', 'r', '' );
		}
		// Fichiers isolés à la racine des dossiers extensions et thèmes.
		$roots[] = array( $plugins, 'f', '' );
		$roots[] = array( $themes, 'f', '' );
		// Tous les autres thèmes et extensions (inactifs, déposés par FTP, cachés).
		foreach ( array( $themes, $plugins ) as $parent ) {
			foreach ( (array) @scandir( $parent ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_string( $name ) && '.' !== $name && '..' !== $name && is_dir( $parent . $name ) && ! is_link( $parent . $name ) ) {
					$roots[] = array( $parent . $name . '/', 'r', '' );
				}
			}
		}

		$seen = array();
		$out  = array();
		foreach ( $roots as $root ) {
			$key = $root[0] . '|' . $root[1];
			if ( isset( $seen[ $key ] ) || ( 'file' !== $root[1] && ! is_dir( $root[0] ) ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $root;
		}
		return $out;
	}

	/**
	 * Dossiers jamais descendus pendant un parcours récursif : les autres racines
	 * (évite d'empreinter deux fois un fichier) et les caches exclus de wp-content.
	 *
	 * @return array<string, int>
	 */
	private function build_skip_set( array $roots ) {
		$skip = array(
			$this->plugins_dir() => 1,
			$this->themes_dir()  => 1,
		);
		foreach ( $roots as $root ) {
			if ( 'r' === $root[1] ) {
				$skip[ $root[0] ] = 1;
			}
		}
		$content  = $this->content_dir();
		$excluded = (array) apply_filters( 'cu_audit_excluded_content_dirs', self::EXCLUDED_CONTENT_DIRS );
		foreach ( $excluded as $dir ) {
			$dir = trim( wp_normalize_path( (string) $dir ), '/' );
			if ( '' !== $dir && 0 === validate_file( $dir ) ) {
				$skip[ $content . $dir . '/' ] = 1;
			}
		}
		return $skip;
	}

	/** Nouveau scan : colonnes de travail remises à zéro, pile de racines, contexte. */
	private function new_scan_state() {
		global $wpdb;
		$table = CU_DB::table( 'fim' );
		if ( ! $this->reset_scan_columns() ) {
			return array();
		}
		$establishing = ! $wpdb->get_var( "SELECT 1 FROM {$table} WHERE baseline_md5 IS NOT NULL LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$covered      = array();
		if ( ! $establishing ) {
			foreach ( (array) $wpdb->get_col( "SELECT DISTINCT unit FROM {$table} WHERE baseline_md5 IS NOT NULL" ) as $unit ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$covered[ (string) $unit ] = 1;
			}
		}
		$scope_from = (int) get_option( self::SCOPE_OPTION, 1 );
		if ( ! $establishing && $scope_from < 3 ) {
			// La racine n'était couverte que pour 15 fichiers du cœur : ses autres
			// fichiers PHP, déjà présents, sont inventoriés une fois (pas d'alerte en rafale).
			unset( $covered['root'] );
		}
		$roots = $this->build_roots();
		$stack = array();
		for ( $i = count( $roots ) - 1; $i >= 0; $i-- ) {
			$stack[] = array( $roots[ $i ][0], $roots[ $i ][1], 0, $roots[ $i ][2] );
		}
		$last = get_option( self::LAST_SCAN_OPTION, array() );
		// Extensions mises à jour par WordPress : empreintes wordpress.org récupérées
		// AVANT le parcours (phase « prep », bornée en temps), jamais pendant un lot.
		$prep = array();
		foreach ( array_keys( $this->trusted() ) as $unit ) {
			if ( 0 === strpos( (string) $unit, 'plugins:' ) ) {
				$prep[] = (string) substr( (string) $unit, 8 );
			}
		}
		return array(
			'v'               => 3,
			'seq'             => 0,
			'phase'           => $prep ? 'prep' : 'walk',
			'prep'            => $prep,
			'sums_error'      => array(),
			'started_at'      => current_time( 'mysql' ),
			'started_ts'      => time(),
			'touched_ts'      => time(),
			'stack'           => $stack,
			'skip'            => $this->build_skip_set( $roots ),
			'files'           => 0,
			'visited'         => 0,
			'uploads_visits'  => 0,
			'changes'         => 0,
			'estimate'        => is_array( $last ) && isset( $last['files'] ) ? (int) $last['files'] : 0,
			'establishing'    => $establishing,
			'first_cover'     => $establishing || $scope_from < self::SCOPE_VERSION,
			'covered'         => $covered,
			'rebaseline'      => ! $establishing && (bool) get_option( self::REBASELINE_OPTION, false ),
			'absorbed'        => array(),
			'inventory'       => array(),
			'verify'          => array(),
			'reset_diff'      => array( 'n' => 0, 'files' => array() ),
			'remote_calls'    => 0,
			'remote_errors'   => 0,
			'truncated'       => false,
			'uploads_partial' => false,
		);
	}

	// =========================================================================
	// FIM — exécution par lots
	// =========================================================================

	/** Échéance d'un lot : budget demandé, borné par max_execution_time. */
	private function deadline( $budget ) {
		$now      = microtime( true );
		$deadline = $now + max( 0.5, (float) $budget );
		$met      = (int) ini_get( 'max_execution_time' );
		if ( $met > 0 && isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			$deadline = min( $deadline, (float) $_SERVER['REQUEST_TIME_FLOAT'] + $met * 0.6 );
		}
		return max( $deadline, $now + 0.5 ); // toujours un minimum de progression
	}

	/**
	 * Traite un lot du scan d'intégrité (reprenable). Un seul lot à la fois sur
	 * tout le site (verrou) : cron et bouton ne se marchent plus dessus.
	 *
	 * @param bool  $start  Demande un nouveau scan (ignoré si un scan avance déjà).
	 * @param float $budget Secondes de travail pour ce lot.
	 * @return array{done:bool, processed:int, visited:int, total:int, changes:int, phase:string, busy?:bool, error?:string}
	 */
	public function run_scan_chunk( $start = false, $budget = 4.0 ) {
		if ( ! CU_DB::tables_exist_for( array( 'fim', 'firewall_audit' ) ) ) {
			return array( 'done' => true, 'processed' => 0, 'visited' => 0, 'total' => 0, 'changes' => 0, 'phase' => '', 'error' => 'tables_missing' );
		}
		$token = $this->acquire_scan_lock();
		if ( false === $token ) {
			$res         = $this->progress( $this->scan_state(), false );
			$res['busy'] = true;
			return $res;
		}
		$this->in_scan = true;
		try {
			return $this->run_scan_locked( $start, $budget );
		} finally {
			$this->in_scan = false;
			$this->release_scan_lock( $token );
		}
	}

	private function progress( array $state, $done ) {
		return array(
			'done'      => (bool) $done,
			'processed' => isset( $state['files'] ) ? (int) $state['files'] : 0,
			'visited'   => isset( $state['visited'] ) ? (int) $state['visited'] : 0,
			'total'     => isset( $state['estimate'] ) ? (int) $state['estimate'] : 0,
			'changes'   => isset( $state['changes'] ) ? (int) $state['changes'] : 0,
			'phase'     => isset( $state['phase'] ) ? (string) $state['phase'] : '',
			'pending'   => isset( $state['prep'] ) ? count( (array) $state['prep'] ) : 0,
			'checked'   => isset( $state['checked'] ) ? (int) $state['checked'] : 0,
		);
	}

	private function run_scan_locked( $start, $budget ) {
		$this->migrate_legacy_storage();
		$this->trusted_memo = null;

		$state = $this->scan_state();
		$age   = $state ? time() - (int) $state['touched_ts'] : 0;
		// Nouveau scan si aucun n'est en cours, si le bouton relance un scan
		// abandonné (> 15 min sans progression) ou si l'état date de plus de 6 h.
		if ( ! $state || ( $start && $age > 15 * MINUTE_IN_SECONDS ) || $age > 6 * HOUR_IN_SECONDS ) {
			$state = $this->new_scan_state();
			if ( ! $state ) {
				return $this->abort_scan( array(), 'db' );
			}
		}
		$deadline = $this->deadline( $budget );
		$error    = '';

		if ( 'prep' === $state['phase'] ) {
			while ( $state['prep'] && microtime( true ) < $deadline ) {
				$this->prefetch_plugin_checksums( $state, (string) array_shift( $state['prep'] ) );
			}
			if ( ! $state['prep'] ) {
				$state['phase'] = 'walk';
			}
		}
		if ( 'walk' === $state['phase'] ) {
			$batch  = array();
			$walked = $this->walk( $state, $deadline, $batch );
			if ( ! $this->flush_batch( $state, $batch ) ) {
				$walked = 'error';
			}
			if ( 'error' === $walked ) {
				$error = 'db';
			} elseif ( 'done' === $walked ) {
				$state['phase'] = 'removed';
			}
		}
		if ( '' === $error && 'removed' === $state['phase'] && microtime( true ) < $deadline ) {
			$r = $this->process_removals( $state, $deadline );
			if ( null === $r ) {
				$error = 'db';
			} elseif ( true === $r ) {
				$state['phase'] = 'finish';
			}
		}
		if ( '' !== $error ) {
			return $this->abort_scan( $state, $error );
		}
		// La fin (empreintes officielles du cœur : appel réseau) démarre toujours
		// avec du temps devant elle, au besoin au lot suivant.
		if ( 'finish' === $state['phase'] && microtime( true ) < $deadline ) {
			return $this->finish_scan( $state );
		}

		$state['seq']        = (int) $state['seq'] + 1; // garantit une écriture effective
		$state['touched_ts'] = time();
		if ( ! update_option( self::STATE_OPTION, $state, false ) ) {
			return $this->abort_scan( $state, 'state_save_failed' );
		}
		return $this->progress( $state, false );
	}

	/** Arrêt propre sur erreur SQL : la référence existante reste intacte. */
	private function abort_scan( array $state, $error ) {
		global $wpdb;
		$db_error = $this->trim255( $wpdb->last_error );
		delete_option( self::STATE_OPTION );
		$this->reset_scan_columns();
		CU_Logger::log( 'firewall', 'error', __( 'Scan d’intégrité interrompu : erreur d’accès à la base (la référence existante est conservée).', 'cyberpush-ultra' ), array(
			'error'    => $error,
			'files'    => isset( $state['files'] ) ? (int) $state['files'] : 0,
			'db_error' => $db_error,
		) );
		$res          = $this->progress( $state, true );
		$res['error'] = $error;
		return $res;
	}

	/**
	 * Parcours en profondeur reprenable : pile de dossiers [chemin, mode, position,
	 * étiquette] dans l'état. Un dossier interrompu (budget) reprend à sa position.
	 *
	 * @return string 'done' | 'more' | 'error'
	 */
	private function walk( array &$state, $deadline, array &$batch ) {
		$skip        = $state['skip'];
		$max_files   = $this->max_files();
		$max_visits  = $this->max_visits();
		$uploads_cap = max( 1000, (int) apply_filters( 'cu_audit_uploads_visit_cap', self::UPLOADS_VISIT_CAP ) );

		while ( ! empty( $state['stack'] ) ) {
			if ( $state['files'] >= $max_files || $state['visited'] >= $max_visits ) {
				$state['truncated'] = true;
				$state['stack']     = array();
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				return 'more';
			}
			$top                              = count( $state['stack'] ) - 1;
			list( $path, $mode, $offset, $tag ) = $state['stack'][ $top ];

			if ( 'file' === $mode ) {
				array_pop( $state['stack'] );
				$state['visited']++;
				if ( is_file( $path ) && $this->is_watched_name( basename( $path ) ) ) {
					$this->take_file( $state, $batch, $path );
				}
				continue;
			}

			$handle = @opendir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $handle ) {
				array_pop( $state['stack'] ); // dossier illisible (droits) → ignoré
				continue;
			}
			$subdirs  = array();
			$pos      = 0;
			$status   = 'finished';
			$n        = 0;
			while ( false !== ( $name = readdir( $handle ) ) ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( $pos++ < $offset ) {
					continue;
				}
				$state['visited']++;
				if ( 'uploads' === $tag && ++$state['uploads_visits'] > $uploads_cap ) {
					$state['uploads_partial'] = true;
					$status                   = 'abort_uploads';
					break;
				}
				$full = $path . $name;
				if ( is_dir( $full ) ) {
					if ( 'r' === $mode && ! is_link( $full ) && ! isset( $skip[ $full . '/' ] ) ) {
						$subdirs[] = array( $full . '/', 'r', 0, $tag );
					}
				} elseif ( $this->is_watched_name( $name ) && is_file( $full ) ) {
					$this->take_file( $state, $batch, $full );
					if ( count( $batch ) >= self::BATCH_SIZE && ! $this->flush_batch( $state, $batch ) ) {
						closedir( $handle );
						return 'error';
					}
				}
				if ( 0 === ( ++$n % 25 ) && ( microtime( true ) >= $deadline || $state['files'] >= $max_files || $state['visited'] >= $max_visits ) ) {
					$status = 'paused';
					break;
				}
			}
			closedir( $handle );

			if ( 'abort_uploads' === $status ) {
				// Médiathèque trop grande : on abandonne le reste des médias uniquement.
				$state['stack'] = array_values( array_filter( $state['stack'], static function ( $entry ) {
					return 'uploads' !== $entry[3];
				} ) );
				continue;
			}
			if ( 'finished' === $status ) {
				array_pop( $state['stack'] );
			} else {
				$state['stack'][ $top ][2] = $pos;
			}
			for ( $i = count( $subdirs ) - 1; $i >= 0; $i-- ) {
				$state['stack'][] = $subdirs[ $i ];
			}
		}
		return empty( $state['stack'] ) ? 'done' : 'more';
	}

	/** Empreinte un fichier et le met en attente de comparaison groupée. */
	private function take_file( array &$state, array &$batch, $abs ) {
		$hash = @md5_file( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $hash ) {
			return;
		}
		$state['files']++;
		$rel                 = $this->rel_path( $abs );
		$batch[ md5( $rel ) ] = array( $rel, $abs, $hash );
	}

	/**
	 * Compare un groupe de fichiers à la référence (1 SELECT), décide de leur
	 * état, puis les écrit (1 INSERT … ON DUPLICATE KEY UPDATE).
	 */
	private function flush_batch( array &$state, array &$batch ) {
		if ( ! $batch ) {
			return true;
		}
		global $wpdb;
		$table  = CU_DB::table( 'fim' );
		$hashes = array_map( 'strval', array_keys( $batch ) );
		$in     = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( $wpdb->prepare( "SELECT path_hash, baseline_md5 FROM {$table} WHERE path_hash IN ({$in})", $hashes ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			$batch = array();
			return false; // sans la référence, tout paraîtrait « ajouté » : on s'arrête
		}
		$base = array();
		foreach ( $rows as $r ) {
			$base[ $r['path_hash'] ] = $r['baseline_md5'];
		}

		$now    = current_time( 'mysql' );
		$values = array();
		$params = array();
		foreach ( $batch as $path_hash => $item ) {
			list( $rel, $abs, $hash ) = $item;
			// Colonne VARCHAR(1024) : en mode SQL strict, un seul chemin trop long
			// ferait échouer tout le lot (et bloquerait chaque scan suivant).
			if ( CU_Text::length( $rel ) > 1024 ) {
				CU_Logger::log( 'system', 'warning', __( 'Intégrité : chemin de fichier trop long ignoré (plus de 1 024 caractères).', 'cyberpush-ultra' ), array( 'path' => CU_Text::substr( $rel, 0, 200 ) . '…' ) );
				continue;
			}
			list( $zone, $unit )      = $this->classify( wp_normalize_path( $abs ) );
			$unit                     = CU_Text::substr( (string) $unit, 0, 100 );
			$old                      = isset( $base[ $path_hash ] ) ? $base[ $path_hash ] : null;
			$st                       = ( null !== $old && $old === $hash ) ? self::FIM_OK : $this->judge_change( $state, $rel, $abs, $hash, null === $old ? 'added' : 'modified', $zone, $unit );
			$values[]                 = '(%s,%s,%s,%s,%d,%s)';
			array_push( $params, (string) $path_hash, $rel, $unit, $hash, $st, $now );
		}
		$batch = array();
		if ( ! $values ) {
			return true;
		}
		$sql   = "INSERT INTO {$table} (path_hash, path, unit, scan_md5, scan_state, updated_at) VALUES " . implode( ',', $values )
			. ' ON DUPLICATE KEY UPDATE path = VALUES(path), unit = VALUES(unit), scan_md5 = VALUES(scan_md5), scan_state = VALUES(scan_state), updated_at = VALUES(updated_at)';
		return false !== $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Décide du sort d'un fichier ajouté ou modifié :
	 *  1. médias : seul un exécutable actif compte (signalé même au premier scan) ;
	 *  2. mise à jour faite par WordPress : vérifiée (wordpress.org / dates) ;
	 *  3. premier scan ou zone jamais surveillée : inventaire sans alerte ;
	 *  4. réinitialisation demandée : nouvelle référence, différence listée ;
	 *  5. sinon : changement à signaler.
	 *
	 * @return int État FIM_OK ou FIM_CHANGED.
	 */
	private function judge_change( array &$state, $rel, $abs, $hash, $kind, $zone, $unit ) {
		if ( 'uploads' === $zone ) {
			if ( 'inert' === $this->inspect_upload_file( $abs ) ) {
				return self::FIM_OK;
			}
			$state['changes']++;
			return self::FIM_CHANGED;
		}
		$trusted   = $this->trusted();
		$trust_key = $this->trust_key_for( $zone, $unit, $rel, $trusted );
		if ( '' !== $trust_key ) {
			$verdict = $this->verify_trusted_file( $state, $trust_key, $zone, $unit, $abs, $hash, (int) $trusted[ $trust_key ], $rel );
			if ( 'absorb' === $verdict ) {
				$state['absorbed'][ $trust_key ] = ( isset( $state['absorbed'][ $trust_key ] ) ? (int) $state['absorbed'][ $trust_key ] : 0 ) + 1;
				return self::FIM_OK;
			}
			if ( 'reported' === $verdict ) {
				return self::FIM_OK; // signalé par un événement dédié (empreinte officielle, non vérifié…)
			}
		}
		if ( 'added' === $kind && ( $state['establishing'] || ( $state['first_cover'] && ! isset( $state['covered'][ $unit ] ) ) ) ) {
			if ( count( $state['inventory'] ) < 50 && $this->is_inventory_candidate( $zone, $unit, $rel ) && 'danger' === $this->inspect_upload_file( $abs ) ) {
				$state['inventory'][] = $rel;
			}
			return self::FIM_OK;
		}
		if ( ! empty( $state['rebaseline'] ) ) {
			$this->note_reset_diff( $state, $kind, $rel );
			return self::FIM_OK;
		}
		$state['changes']++;
		return self::FIM_CHANGED;
	}

	/** Unité de confiance applicable (mise à jour WordPress récente), ou ''. */
	private function trust_key_for( $zone, $unit, $rel, array $trusted ) {
		if ( isset( $trusted[ $unit ] ) && in_array( $zone, array( 'plugins', 'themes', 'content' ), true ) ) {
			return $unit;
		}
		if ( isset( $trusted['core'] ) ) {
			if ( 'core' === $zone ) {
				return 'core';
			}
			// wp-config.php, .htaccess et les fichiers non-cœur de la racine ne sont jamais couverts par une mise à jour.
			$base = basename( $rel );
			if ( 'root' === $zone && ( in_array( $base, self::ROOT_FILES, true ) || 'wp-config-sample.php' === $base ) && ! in_array( $base, array( 'wp-config.php', '.htaccess' ), true ) ) {
				return 'core';
			}
		}
		return '';
	}

	/**
	 * Fichier d'une unité mise à jour par WordPress : intégré à la référence
	 * seulement s'il est prouvé conforme.
	 *  - extension wordpress.org : MD5 comparé aux empreintes officielles de la
	 *    version installée ; différent ou absent du paquet → événement dédié ;
	 *    API injoignable → « non vérifié » (jamais silencieux) ;
	 *  - thème, extension hors wordpress.org, traductions : date de modification
	 *    comprise dans la fenêtre de la mise à jour ;
	 *  - cœur : intégré (comparé ensuite aux empreintes officielles du cœur).
	 *
	 * @return string 'absorb' | 'reported' | 'change'
	 */
	private function verify_trusted_file( array &$state, $trust_key, $zone, $unit, $abs, $hash, $trusted_ts, $rel ) {
		if ( 'core' === $trust_key ) {
			return 'absorb';
		}
		if ( 'plugins' === $zone ) {
			$sums = $this->plugin_checksums( $state, $unit );
			if ( 'ok' === $sums['status'] ) {
				$inner = substr( wp_normalize_path( $abs ), strlen( $this->plugins_dir() . substr( $unit, 8 ) . '/' ) );
				if ( isset( $sums['files'][ $inner ] ) ) {
					if ( in_array( $hash, (array) $sums['files'][ $inner ], true ) ) {
						return 'absorb';
					}
					$this->note_verify( $state, $unit, 'mismatch', $rel, $sums['version'] );
					return 'reported';
				}
				$this->note_verify( $state, $unit, 'unknown', $rel, $sums['version'] );
				return 'reported';
			}
			if ( 'error' === $sums['status'] ) {
				$this->note_verify( $state, $unit, 'unverified', $rel, $sums['version'] );
				return 'reported';
			}
			// 'none' : extension absente de wordpress.org → fenêtre de dates.
		}
		$mtime = @filemtime( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $mtime && $mtime >= $trusted_ts - self::TRUST_MTIME_BEFORE && $mtime <= $trusted_ts + self::TRUST_MTIME_AFTER ) {
			return 'absorb';
		}
		return 'change';
	}

	private function note_verify( array &$state, $unit, $kind, $rel, $version ) {
		if ( ! isset( $state['verify'][ $unit ] ) ) {
			$state['verify'][ $unit ] = array( 'v' => (string) $version, 'mismatch' => 0, 'unknown' => 0, 'unverified' => 0, 'files' => array() );
		}
		$state['verify'][ $unit ][ $kind ]++;
		if ( count( $state['verify'][ $unit ]['files'] ) < 20 ) {
			$state['verify'][ $unit ]['files'][] = $kind . ' ' . $rel;
		}
		$state['changes']++;
	}

	private function note_reset_diff( array &$state, $kind, $rel ) {
		$state['reset_diff']['n']++;
		if ( count( $state['reset_diff']['files'] ) < 30 ) {
			$state['reset_diff']['files'][] = $kind . ' ' . $rel;
		}
	}

	/** Fichier listé dans l'inventaire d'une zone nouvellement surveillée. */
	private function is_inventory_candidate( $zone, $unit, $rel ) {
		if ( 'mu' === $zone || 'dropins' === $zone ) {
			return true;
		}
		if ( 'root' === $zone ) {
			$base = strtolower( basename( $rel ) );
			return 'wp-config-sample.php' !== $base && ! in_array( $base, array_map( 'strtolower', self::ROOT_FILES ), true );
		}
		if ( 'content' === $zone ) {
			return 'content:languages' !== $unit;
		}
		if ( 'plugins' === $zone || 'themes' === $zone ) {
			$slug = (string) substr( $unit, strlen( $zone ) + 1 );
			return '' !== $slug && '.' === $slug[0]; // dossier caché
		}
		return false;
	}

	/** Version installée d'une extension (dossier), lue dans son en-tête. */
	private function plugin_version( $slug ) {
		if ( null === $this->plugin_versions ) {
			$this->plugin_versions = array();
			if ( ! function_exists( 'get_plugins' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( function_exists( 'get_plugins' ) ) {
				foreach ( (array) get_plugins() as $file => $data ) {
					$parts = explode( '/', wp_normalize_path( (string) $file ), 2 );
					if ( 2 === count( $parts ) && ! empty( $data['Version'] ) && ! isset( $this->plugin_versions[ $parts[0] ] ) ) {
						$this->plugin_versions[ $parts[0] ] = (string) $data['Version'];
					}
				}
			}
		}
		return isset( $this->plugin_versions[ $slug ] ) ? $this->plugin_versions[ $slug ] : '';
	}

	/** Slug wordpress.org plausible (dossier) et version installée valide, sinon ''. */
	private function checksum_key( $slug ) {
		// Extension en fichier unique ou dossier caché : aucun paquet wordpress.org identifiable.
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/i', $slug ) || preg_match( '/\.php$/i', $slug ) ) {
			return '';
		}
		$version = $this->plugin_version( $slug );
		if ( ! preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,40}$/', $version ) ) {
			return '';
		}
		return 'cu_audit_psums_' . md5( $slug . '|' . $version );
	}

	/**
	 * Phase « prep » : récupère les empreintes officielles d'une extension
	 * wordpress.org mise à jour (downloads.wordpress.org/plugin-checksums) pour
	 * sa version installée. Cache 7 jours par slug/version (404 : 1 jour).
	 * Appels bornés par scan ; trois échecs réseau suspendent les appels ; un
	 * échec marque l'extension « non vérifiée » (jamais absorbée en silence).
	 */
	private function prefetch_plugin_checksums( array &$state, $slug ) {
		$tkey = $this->checksum_key( $slug );
		if ( '' === $tkey || is_array( get_transient( $tkey ) ) ) {
			return;
		}
		$max = max( 0, (int) apply_filters( 'cu_audit_checksum_calls_per_scan', self::CHECKSUM_CALLS_PER_SCAN ) );
		if ( (int) $state['remote_calls'] >= $max || (int) $state['remote_errors'] >= 3 ) {
			$state['sums_error'][ $slug ] = 1;
			return;
		}
		$state['remote_calls']++;
		$resp = wp_remote_get(
			'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $this->plugin_version( $slug ) ) . '.json',
			array( 'timeout' => 8, 'redirection' => 2, 'limit_response_size' => 4 * MB_IN_BYTES )
		);
		$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
		if ( 404 === $code ) {
			set_transient( $tkey, array( 'status' => 'none', 'files' => array() ), DAY_IN_SECONDS );
			return;
		}
		$body = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $resp ), true ) : null;
		if ( ! is_array( $body ) || ! isset( $body['files'] ) || ! is_array( $body['files'] ) ) {
			$state['remote_errors']++;
			$state['sums_error'][ $slug ] = 1;
			return;
		}
		$files = array();
		foreach ( $body['files'] as $path => $info ) {
			if ( ! is_string( $path ) || ! is_array( $info ) || ! isset( $info['md5'] ) || ! $this->is_watched_name( basename( $path ) ) ) {
				continue;
			}
			$md5s = array_values( array_filter( (array) $info['md5'], static function ( $m ) {
				return is_string( $m ) && (bool) preg_match( '/^[a-f0-9]{32}$/', $m );
			} ) );
			if ( $md5s ) {
				$files[ ltrim( wp_normalize_path( $path ), '/' ) ] = 1 === count( $md5s ) ? $md5s[0] : $md5s;
			}
		}
		set_transient( $tkey, array( 'status' => 'ok', 'files' => $files ), WEEK_IN_SECONDS );
	}

	/**
	 * Empreintes officielles d'une extension pendant le parcours (cache seul,
	 * aucun appel réseau dans un lot). Extension mise à jour après le début du
	 * scan (non préparée) : 'none' → contrôle par la fenêtre de dates.
	 *
	 * @return array{status:string, files:array, version:string} status ok | none | error
	 */
	private function plugin_checksums( array &$state, $unit ) {
		$slug = (string) substr( $unit, 8 );
		if ( isset( $this->checksum_memo[ $slug ] ) ) {
			return $this->checksum_memo[ $slug ];
		}
		$res  = array( 'status' => 'none', 'files' => array(), 'version' => $this->plugin_version( $slug ) );
		$tkey = $this->checksum_key( $slug );
		if ( '' !== $tkey ) {
			$cached = get_transient( $tkey );
			if ( is_array( $cached ) && isset( $cached['status'], $cached['files'] ) ) {
				$res['status'] = (string) $cached['status'];
				$res['files']  = (array) $cached['files'];
			} elseif ( isset( $state['sums_error'][ $slug ] ) ) {
				$res['status'] = 'error';
			}
		}
		$this->checksum_memo[ $slug ] = $res;
		return $res;
	}

	/**
	 * Fichiers de la référence absents du parcours : encore présents (plafond,
	 * dossier exclu) → empreinte conservée ; sinon disparition à signaler, sauf
	 * médias et suppressions faites par WordPress.
	 *
	 * @return bool|null true terminé, false budget épuisé, null erreur SQL.
	 */
	private function process_removals( array &$state, $deadline ) {
		global $wpdb;
		$table = CU_DB::table( 'fim' );
		while ( microtime( true ) < $deadline ) {
			$wpdb->last_error = '';
			$rows             = $wpdb->get_results( "SELECT path_hash, path FROM {$table} WHERE scan_state = 0 AND baseline_md5 IS NOT NULL LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				return null;
			}
			if ( ! $rows ) {
				return true;
			}
			$state['checked'] = ( isset( $state['checked'] ) ? (int) $state['checked'] : 0 ) + count( $rows );
			$keep    = array();
			$by_code = array( self::FIM_REMOVED => array(), self::FIM_GONE => array() );
			foreach ( $rows as $r ) {
				$abs = $this->abs_of( (string) $r['path'] );
				if ( is_file( $abs ) ) {
					$keep[] = $r['path_hash'];
					continue;
				}
				list( $zone, $unit ) = $this->classify( $abs );
				$trust_key           = $this->trust_key_for( $zone, $unit, (string) $r['path'], $this->trusted() );
				if ( 'uploads' === $zone ) {
					$by_code[ self::FIM_GONE ][] = $r['path_hash']; // un exécutable retiré des médias n'est pas une menace
				} elseif ( '' !== $trust_key ) {
					$state['absorbed'][ $trust_key ] = ( isset( $state['absorbed'][ $trust_key ] ) ? (int) $state['absorbed'][ $trust_key ] : 0 ) + 1;
					$by_code[ self::FIM_GONE ][]     = $r['path_hash'];
				} elseif ( ! empty( $state['rebaseline'] ) ) {
					$this->note_reset_diff( $state, 'removed', (string) $r['path'] );
					$by_code[ self::FIM_GONE ][] = $r['path_hash'];
				} else {
					$state['changes']++;
					$by_code[ self::FIM_REMOVED ][] = $r['path_hash'];
				}
			}
			if ( $keep ) {
				$in = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET scan_md5 = baseline_md5, scan_state = 1 WHERE path_hash IN ({$in})", $keep ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					return null;
				}
			}
			foreach ( $by_code as $code => $list ) {
				if ( ! $list ) {
					continue;
				}
				$in     = implode( ',', array_fill( 0, count( $list ), '%s' ) );
				$params = array_merge( array( (int) $code ), $list );
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET scan_state = %d WHERE path_hash IN ({$in})", $params ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					return null;
				}
			}
		}
		return false;
	}

	/** Fin de scan : comparaison officielle du cœur, événements, validation de la nouvelle référence. */
	private function finish_scan( array $state ) {
		global $wpdb;
		$table = CU_DB::table( 'fim' );

		// Référence EXTERNE : contrairement à la base (qui se compare à elle-même),
		// les empreintes wordpress.org détectent aussi une infection présente AVANT
		// l'installation du plugin, et ne peuvent pas être « réinitialisées ».
		// Réutilise les MD5 déjà calculés : aucune relecture.
		$core_hashes = array();
		$rows        = $wpdb->get_results( $wpdb->prepare( "SELECT path, scan_md5 FROM {$table} WHERE scan_state IN (1,2) AND scan_md5 IS NOT NULL AND unit IN (%s,%s)", 'core', 'root' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $r ) {
			$core_hashes[ (string) $r['path'] ] = (string) $r['scan_md5'];
		}
		unset( $rows );
		$core_new = $this->verify_core_against_official( $core_hashes );
		unset( $core_hashes );

		$findings = $this->record_findings( $state );

		// Validation : disparus supprimés, empreintes du scan → référence.
		$deleted   = $wpdb->query( "DELETE FROM {$table} WHERE scan_state IN (3,4)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$committed = false !== $deleted ? $wpdb->query( "UPDATE {$table} SET baseline_md5 = scan_md5, scan_md5 = NULL, scan_state = 0 WHERE scan_state IN (1,2)" ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $committed ) {
			CU_Logger::log( 'firewall', 'error', __( 'La nouvelle référence d’intégrité n’a pas pu être enregistrée.', 'cyberpush-ultra' ), array(
				'files'    => (int) $state['files'],
				'db_error' => $this->trim255( $wpdb->last_error ),
			) );
		} else {
			update_option( self::SCOPE_OPTION, self::SCOPE_VERSION, false );
			// Les mises à jour antérieures au début du scan sont maintenant dans la référence.
			$still = array();
			foreach ( $this->trusted() as $unit => $ts ) {
				if ( (int) $ts >= (int) $state['started_ts'] ) {
					$still[ $unit ] = $ts;
				}
			}
			update_option( self::TRUSTED_OPTION, $still, false );
			$this->trusted_memo = null;
			delete_option( self::REBASELINE_OPTION );
		}
		update_option( self::LAST_SCAN_OPTION, array(
			'finished_at' => current_time( 'mysql' ),
			'files'       => (int) $state['files'],
			'visited'     => (int) $state['visited'],
			'changes'     => (int) $state['changes'],
			'duration'    => max( 0, time() - (int) $state['started_ts'] ),
			'established' => ! empty( $state['establishing'] ) ? 1 : 0,
			'truncated'   => ! empty( $state['truncated'] ) ? 1 : 0,
		), false );
		delete_option( self::STATE_OPTION );

		if ( ! empty( $state['establishing'] ) ) {
			$this->record( 'low', 'files', 'baseline_established', sprintf( __( 'Référence d\'intégrité établie (%d fichiers surveillés)', 'cyberpush-ultra' ), (int) $state['files'] ), 'files', '', array() );
		}
		$this->in_scan = false;
		// Un seul email récapitulatif, détaillé, s'il y a quelque chose à vérifier.
		if ( $findings ) {
			$this->send_scan_summary( $findings, (int) $state['files'] );
		}
		if ( ! empty( $core_new ) ) {
			$this->send_core_alert( $core_new );
		}
		$res = $this->progress( $state, true );
		$res['phase'] = 'done';
		return $res;
	}

	/**
	 * Transforme les changements du scan en événements du journal, regroupés :
	 * une extension ou un thème modifié en masse donne UN événement, une
	 * extension déposée hors WordPress aussi. Les zones critiques (médias,
	 * must-use, drop-ins, racine, cœur, wp-content) sont détaillées fichier par
	 * fichier dans la limite d'un budget.
	 *
	 * @return array<int, array{0: string, 1: string}> Constats pour l'e-mail : array( gravité, message ).
	 */
	private function record_findings( array $state ) {
		global $wpdb;
		$table    = CU_DB::table( 'fim' );
		$findings = array();
		$add      = function ( $severity, $key, $message, $object = '', $context = array() ) use ( &$findings ) {
			$this->record( $severity, 'files', $key, $message, 'file', $object, $context );
			if ( 'critical' === $severity || 'high' === $severity ) {
				$findings[] = array( $severity, $message );
			}
		};

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE scan_state IN (2,3)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $total ? $wpdb->get_results( $wpdb->prepare( "SELECT path, unit, baseline_md5, scan_state FROM {$table} WHERE scan_state IN (2,3) ORDER BY path LIMIT %d", self::PENDING_CAP ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$by_unit = array();
		$loaded  = array();
		foreach ( (array) $rows as $r ) {
			$kind               = 3 === (int) $r['scan_state'] ? 'removed' : ( null === $r['baseline_md5'] ? 'added' : 'modified' );
			$unit               = (string) $r['unit'];
			$by_unit[ $unit ][] = array( $kind, (string) $r['path'], $this->zone_of_unit( $unit ), $unit );
			$loaded[ $unit ]    = ( isset( $loaded[ $unit ] ) ? $loaded[ $unit ] : 0 ) + 1;
		}
		unset( $rows );
		$overflow = array();
		if ( $total > array_sum( $loaded ) ) {
			foreach ( (array) $wpdb->get_results( "SELECT unit, COUNT(*) AS n FROM {$table} WHERE scan_state IN (2,3) GROUP BY unit", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$extra = (int) $r['n'] - ( isset( $loaded[ $r['unit'] ] ) ? $loaded[ $r['unit'] ] : 0 );
				if ( $extra > 0 ) {
					$overflow[ (string) $r['unit'] ] = $extra;
					if ( ! isset( $by_unit[ $r['unit'] ] ) ) {
						$by_unit[ (string) $r['unit'] ] = array();
					}
				}
			}
		}

		$verbs = array(
			'added'    => __( 'ajouté', 'cyberpush-ultra' ),
			'modified' => __( 'modifié', 'cyberpush-ultra' ),
			'removed'  => __( 'supprimé', 'cyberpush-ultra' ),
		);
		$feminine    = array( 'added' => __( 'ajoutée', 'cyberpush-ultra' ), 'modified' => __( 'modifiée', 'cyberpush-ultra' ), 'removed' => __( 'supprimée', 'cyberpush-ultra' ) );
		$keys        = array( 'added' => 'file_added', 'modified' => 'file_modified', 'removed' => 'file_removed' );
		$crit_budget = self::CRITICAL_EVENT_CAP;
		$crit_extra  = 0;

		foreach ( $by_unit as $unit => $changes ) {
			$unit   = (string) $unit;
			$extra  = isset( $overflow[ $unit ] ) ? (int) $overflow[ $unit ] : 0;
			$zone   = $this->zone_of_unit( $unit );
			$count  = count( $changes ) + $extra;
			$tally  = array( 'added' => 0, 'modified' => 0, 'removed' => 0 );
			foreach ( $changes as $change ) {
				$tally[ $change[0] ]++;
			}
			$sample = array_slice( array_map( function ( $change ) { return $change[0] . ' ' . $change[1]; }, $changes ), 0, 20 );

			if ( 'plugins' === $zone || 'themes' === $zone ) {
				$slug      = substr( $unit, strlen( $zone ) + 1 );
				$is_plugin = 'plugins' === $zone;
				$what      = $is_plugin ? __( 'Extension', 'cyberpush-ultra' ) : __( 'Thème', 'cyberpush-ultra' );
				$fresh     = ! isset( $state['covered'][ $unit ] ) && $changes && $tally['added'] === count( $changes );
				$gone      = $changes && $tally['removed'] === count( $changes ) && ! is_dir( ( $is_plugin ? $this->plugins_dir() : $this->themes_dir() ) . $slug );
				if ( $fresh ) {
					$message = $is_plugin
						/* translators: 1: dossier, 2: nombre de fichiers */
						? sprintf( __( 'Extension déposée hors de WordPress (FTP, gestionnaire de fichiers ou intrusion) : %1$s (%2$d fichiers)', 'cyberpush-ultra' ), $slug, $count )
						/* translators: 1: dossier, 2: nombre de fichiers */
						: sprintf( __( 'Thème déposé hors de WordPress (FTP, gestionnaire de fichiers ou intrusion) : %1$s (%2$d fichiers)', 'cyberpush-ultra' ), $slug, $count );
					$add( 'critical', $is_plugin ? 'plugin_dropped' : 'theme_dropped', $message, $slug, array( 'files' => $sample ) );
				} elseif ( $gone ) {
					$message = $is_plugin
						/* translators: 1: dossier, 2: nombre de fichiers */
						? sprintf( __( 'Extension supprimée hors de WordPress : %1$s (%2$d fichiers)', 'cyberpush-ultra' ), $slug, $count )
						/* translators: 1: dossier, 2: nombre de fichiers */
						: sprintf( __( 'Thème supprimé hors de WordPress : %1$s (%2$d fichiers)', 'cyberpush-ultra' ), $slug, $count );
					$add( 'high', $is_plugin ? 'plugin_removed_outside' : 'theme_removed_outside', $message, $slug, array( 'files' => $sample ) );
				} elseif ( $count > self::GROUP_THRESHOLD ) {
					$message = $is_plugin
						/* translators: 1: dossier, 2: modifiés, 3: ajoutés, 4: supprimés */
						? sprintf( __( 'Extension %1$s modifiée hors mise à jour WordPress : %2$d fichier(s) modifié(s), %3$d ajouté(s), %4$d supprimé(s)', 'cyberpush-ultra' ), $slug, $tally['modified'], $tally['added'] + $extra, $tally['removed'] )
						/* translators: 1: dossier, 2: modifiés, 3: ajoutés, 4: supprimés */
						: sprintf( __( 'Thème %1$s modifié hors mise à jour WordPress : %2$d fichier(s) modifié(s), %3$d ajouté(s), %4$d supprimé(s)', 'cyberpush-ultra' ), $slug, $tally['modified'], $tally['added'] + $extra, $tally['removed'] );
					$add( 'high', $is_plugin ? 'plugin_files_changed' : 'theme_files_changed', $message, $slug, array( 'files' => $sample ) );
				} else {
					foreach ( $changes as $change ) {
						/* translators: 1: Extension|Thème, 2: dossier, 3: ajouté|modifié|supprimé, 4: fichier */
						$add( 'high', $keys[ $change[0] ], sprintf( __( '%1$s %2$s : fichier %3$s — %4$s', 'cyberpush-ultra' ), $what, $slug, $verbs[ $change[0] ], $change[1] ), $change[1] );
					}
				}
				continue;
			}

			if ( 'content' === $zone ) {
				// Sous-dossier de wp-content hors extensions/thèmes/médias : un PHP qui
				// y apparaît est suspect. Traductions : élevé. Dossier upgrade : vide hors
				// mise à jour → critique, sauf si WordPress vient d'en faire une (fichiers
				// temporaires d'extraction).
				$dir   = substr( $unit, 8 );
				$noisy = 'languages' === $dir || ( 'upgrade' === $dir && $this->recent_wp_update() );
				if ( $count > self::GROUP_THRESHOLD ) {
					$sev = $noisy ? 'high' : ( ( $tally['added'] + $tally['modified'] + $extra ) > 0 ? 'critical' : 'medium' );
					/* translators: 1: dossier, 2: modifiés, 3: ajoutés, 4: supprimés */
					$add( $sev, 'content_files_changed', sprintf( __( 'Dossier wp-content/%1$s changé hors mise à jour WordPress : %2$d fichier(s) PHP modifié(s), %3$d ajouté(s), %4$d supprimé(s)', 'cyberpush-ultra' ), $dir, $tally['modified'], $tally['added'] + $extra, $tally['removed'] ), 'wp-content/' . $dir, array( 'files' => $sample ) );
				} else {
					foreach ( $changes as $change ) {
						$sev = $noisy ? 'high' : ( 'removed' === $change[0] ? 'medium' : 'critical' );
						/* translators: 1: ajouté|modifié|supprimé, 2: dossier, 3: fichier */
						$add( $sev, $keys[ $change[0] ], sprintf( __( 'Fichier exécutable %1$s dans wp-content/%2$s : %3$s', 'cyberpush-ultra' ), $verbs[ $change[0] ], $dir, $change[1] ), $change[1] );
					}
				}
				continue;
			}

			if ( 'core' === $zone && $count > self::GROUP_THRESHOLD ) {
				/* translators: 1: modifiés, 2: ajoutés, 3: supprimés */
				$add( 'critical', 'core_files_changed', sprintf( __( 'Cœur de WordPress modifié hors mise à jour : %1$d fichier(s) modifié(s), %2$d ajouté(s), %3$d supprimé(s)', 'cyberpush-ultra' ), $tally['modified'], $tally['added'] + $extra, $tally['removed'] ), 'wordpress', array( 'files' => $sample ) );
				continue;
			}

			// Zones critiques : un événement par fichier, dans la limite du budget.
			foreach ( $changes as $change ) {
				if ( $crit_budget <= 0 ) {
					$crit_extra++;
					continue;
				}
				$crit_budget--;
				list( $kind, $rel ) = $change;
				switch ( $zone ) {
					case 'uploads':
						if ( in_array( strtolower( basename( $rel ) ), self::CONFIG_FILES, true ) ) {
							$key = 'upload_exec_config';
							/* translators: 1: ajoutée|modifiée, 2: fichier */
							$msg = sprintf( __( 'Configuration piégée dans les médias (%1$s) : %2$s — elle peut rendre des fichiers exécutables par PHP', 'cyberpush-ultra' ), $feminine[ $kind ], $rel );
						} else {
							$key = 'upload_exec_file';
							/* translators: 1: ajouté|modifié, 2: fichier */
							$msg = sprintf( __( 'Fichier PHP exécutable dans les médias (%1$s) : %2$s — un dossier de médias ne devrait jamais en contenir', 'cyberpush-ultra' ), $verbs[ $kind ], $rel );
						}
						break;
					case 'mu':
						$key = 'mu_plugin_' . $kind;
						/* translators: 1: ajoutée|modifiée|supprimée, 2: fichier */
						$msg = sprintf( __( 'Extension « must-use » %1$s (chargée automatiquement, sans activation) : %2$s', 'cyberpush-ultra' ), $feminine[ $kind ], $rel );
						break;
					case 'dropins':
						$key = 'dropin_' . $kind;
						/* translators: 1: ajouté|modifié|supprimé, 2: fichier */
						$msg = sprintf( __( 'Drop-in de wp-content %1$s (chargé automatiquement) : %2$s', 'cyberpush-ultra' ), $verbs[ $kind ], $rel );
						break;
					case 'core':
						$key = $keys[ $kind ];
						/* translators: 1: ajouté|modifié|supprimé, 2: fichier */
						$msg = sprintf( __( 'Fichier du cœur de WordPress %1$s hors mise à jour : %2$s', 'cyberpush-ultra' ), $verbs[ $kind ], $rel );
						break;
					default:
						$key = $keys[ $kind ];
						/* translators: 1: ajouté|modifié|supprimé, 2: fichier */
						$msg = sprintf( __( 'Fichier racine %1$s : %2$s', 'cyberpush-ultra' ), $verbs[ $kind ], $rel );
				}
				$add( 'critical', $key, $msg, $rel );
			}
			$crit_extra += $extra;
		}
		if ( $crit_extra > 0 ) {
			/* translators: %d: nombre de fichiers */
			$add( 'critical', 'files_changed_more', sprintf( __( '… et %d autre(s) fichier(s) sensible(s) changé(s) : consultez le Journal de sécurité et vérifiez le serveur.', 'cyberpush-ultra' ), $crit_extra ) );
		}

		// Mises à jour WordPress dont les fichiers ne sont PAS prouvés conformes.
		foreach ( $state['verify'] as $unit => $v ) {
			$slug    = substr( (string) $unit, 8 );
			$context = array( 'version' => (string) $v['v'], 'files' => (array) $v['files'] );
			if ( $v['mismatch'] > 0 ) {
				/* translators: 1: extension, 2: version, 3: nombre */
				$add( 'critical', 'plugin_checksum_mismatch', sprintf( __( 'Extension %1$s %2$s mise à jour : %3$d fichier(s) DIFFÉRENT(S) de la version officielle wordpress.org', 'cyberpush-ultra' ), $slug, $v['v'], $v['mismatch'] ), $slug, $context );
			}
			if ( $v['unknown'] > 0 ) {
				/* translators: 1: extension, 2: version, 3: nombre */
				$add( 'high', 'plugin_unknown_files', sprintf( __( 'Extension %1$s %2$s mise à jour : %3$d fichier(s) absent(s) du paquet officiel wordpress.org', 'cyberpush-ultra' ), $slug, $v['v'], $v['unknown'] ), $slug, $context );
			}
			if ( $v['unverified'] > 0 ) {
				/* translators: 1: extension, 2: nombre */
				$add( 'high', 'plugin_update_unverified', sprintf( __( 'Mise à jour de l’extension %1$s NON VÉRIFIÉE (empreintes wordpress.org indisponibles) : %2$d fichier(s) à contrôler', 'cyberpush-ultra' ), $slug, $v['unverified'] ), $slug, $context );
			}
		}

		// Réinitialisation : la nouvelle référence garde la trace de ce qu'elle a absorbé.
		if ( ! empty( $state['rebaseline'] ) ) {
			$n = (int) $state['reset_diff']['n'];
			if ( $n > 0 ) {
				/* translators: %d: nombre de fichiers */
				$add( 'high', 'baseline_rebuilt', sprintf( __( 'Nouvelle référence d’intégrité posée après réinitialisation : %d fichier(s) différaient de l’ancienne référence', 'cyberpush-ultra' ), $n ), '', array( 'files' => $state['reset_diff']['files'] ) );
			} else {
				$this->record( 'low', 'files', 'baseline_rebuilt', __( 'Nouvelle référence d’intégrité posée après réinitialisation : aucune différence avec l’ancienne', 'cyberpush-ultra' ), 'files', '' );
			}
		}

		// Transparence, sans alerte : mises à jour reconnues, inventaire, limites.
		foreach ( $state['absorbed'] as $unit => $n ) {
			$unit  = (string) $unit;
			$label = 'core' === $unit ? __( 'cœur de WordPress', 'cyberpush-ultra' ) : str_replace( array( 'plugins:', 'themes:', 'content:' ), array( __( 'extension ', 'cyberpush-ultra' ), __( 'thème ', 'cyberpush-ultra' ), 'wp-content/' ), $unit );
			/* translators: 1: extension, thème ou cœur, 2: nombre de fichiers */
			$this->record( 'low', 'files', 'baseline_refreshed', sprintf( __( 'Référence mise à jour après une opération de WordPress (%1$s, %2$d fichier(s) vérifié(s))', 'cyberpush-ultra' ), $label, $n ), 'files', $unit );
		}
		if ( $state['inventory'] ) {
			// Au premier scan : simple information. Après un élargissement du
			// périmètre : fichiers déjà présents mais jamais vus → à vérifier (e-mail).
			$sev = ! empty( $state['establishing'] ) ? 'medium' : 'high';
			/* translators: 1: nombre, 2: liste de fichiers */
			$msg = sprintf( __( 'Fichiers exécutables désormais surveillés (%1$d) : %2$s — vérifiez que vous les reconnaissez', 'cyberpush-ultra' ), count( $state['inventory'] ), implode( ', ', array_slice( $state['inventory'], 0, 8 ) ) );
			$this->record( $sev, 'files', 'autoload_inventory', $msg, 'files', '', array( 'files' => $state['inventory'] ) );
			if ( 'high' === $sev ) {
				$findings[] = array( 'high', $msg );
			}
		}
		if ( empty( $state['establishing'] ) && ! empty( $state['first_cover'] ) ) {
			$this->record( 'low', 'files', 'scope_extended', __( 'Surveillance étendue : racine du site, tout wp-content (hors caches), dossiers cachés des extensions et thèmes', 'cyberpush-ultra' ), 'files', '' );
		}
		if ( ! empty( $state['truncated'] ) ) {
			/* translators: %d: plafond de fichiers */
			$this->record( 'medium', 'files', 'scan_truncated', sprintf( __( 'Surveillance limitée aux %d premiers fichiers : les extensions inactives en fin de liste ne sont pas couvertes', 'cyberpush-ultra' ), $this->max_files() ), 'files', '' );
		}
		if ( ! empty( $state['uploads_partial'] ) ) {
			/* translators: %d: nombre d'entrées */
			$this->record( 'medium', 'files', 'uploads_partial', sprintf( __( 'Dossier des médias trop volumineux : seules les %d premières entrées ont été examinées', 'cyberpush-ultra' ), self::UPLOADS_VISIT_CAP ), 'files', '' );
		}
		return $findings;
	}

	// =========================================================================
	// Cœur WordPress vs empreintes officielles (api.wordpress.org)
	// =========================================================================

	/**
	 * Empreintes MD5 officielles des fichiers PHP du cœur pour la version et la
	 * langue installées. Un paquet localisé (fr_FR…) a ses propres empreintes ;
	 * si elles manquent, repli sur en_US en ignorant version.php, qui contient
	 * légitimement la langue du paquet. Mis en cache 12 h (15 min en cas d'échec).
	 *
	 * @return array{version:string, locale:string, fallback:bool, sums:array<string,string>}
	 */
	private function official_core_checksums() {
		global $wp_local_package;
		$version   = (string) get_bloginfo( 'version' );
		$requested = ! empty( $wp_local_package ) ? (string) $wp_local_package : 'en_US';
		$key       = 'cu_audit_core_sums_' . md5( $version . '|' . $requested );
		$cached    = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['sums'] ) ) {
			return $cached;
		}
		$result = array( 'version' => $version, 'locale' => $requested, 'fallback' => false, 'sums' => array() );
		foreach ( array_unique( array( $requested, 'en_US' ) ) as $locale ) {
			$sums = $this->fetch_core_checksums( $version, $locale );
			if ( $sums ) {
				$result['sums']     = $sums;
				$result['locale']   = $locale;
				$result['fallback'] = ( $locale !== $requested );
				break;
			}
		}
		set_transient( $key, $result, $result['sums'] ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Interroge api.wordpress.org/core/checksums. Seuls les fichiers PHP hors
	 * wp-content sont gardés (périmètre du scan, transient plus léger).
	 *
	 * @return array<string,string> chemin relatif => md5, vide si indisponible.
	 */
	private function fetch_core_checksums( $version, $locale ) {
		$url  = add_query_arg( array( 'version' => $version, 'locale' => $locale ), 'https://api.wordpress.org/core/checksums/1.0/' );
		$resp = wp_remote_get( $url, array( 'timeout' => 15, 'limit_response_size' => 2 * MB_IN_BYTES ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['checksums'] ) || ! is_array( $body['checksums'] ) ) {
			return array(); // version inconnue (développement, bêta) → « checksums: false »
		}
		$out = array();
		foreach ( $body['checksums'] as $file => $md5 ) {
			if ( is_string( $file ) && is_string( $md5 ) && preg_match( '/^[a-f0-9]{32}$/', $md5 )
				&& '.php' === substr( $file, -4 ) && 0 !== strpos( $file, 'wp-content/' ) ) {
				$out[ $file ] = $md5;
			}
		}
		return $out;
	}

	/**
	 * Compare les MD5 du scan aux empreintes officielles, enregistre le résultat
	 * et journalise uniquement les NOUVELLES anomalies (une anomalie persistante
	 * reste affichée, sans réalerter chaque jour).
	 *
	 * @param array<string,string> $hashes chemin relatif => md5 (scan en cours).
	 * @return array<int,array{file:string,type:string}> nouvelles anomalies critiques.
	 */
	private function verify_core_against_official( array $hashes ) {
		$official = $this->official_core_checksums();
		$prev     = get_option( self::CORE_CHECK_OPTION, array() );
		$now      = current_time( 'mysql' );

		if ( empty( $official['sums'] ) ) {
			update_option( self::CORE_CHECK_OPTION, array(
				'status'      => 'unavailable',
				'version'     => $official['version'],
				'finished_at' => $now,
				'checked'     => 0,
				// Conservées pour ne pas réalerter sur des anomalies déjà signalées.
				'issues'      => isset( $prev['issues'] ) && is_array( $prev['issues'] ) ? $prev['issues'] : array(),
				'issues_total' => isset( $prev['issues_total'] ) ? (int) $prev['issues_total'] : 0,
			), false );
			return array();
		}

		$sums       = $official['sums'];
		$root_scope = array_flip( self::ROOT_FILES );
		$abs        = $this->abspath();
		$issues     = array();
		$checked    = 0;
		foreach ( $sums as $rel => $md5 ) {
			if ( 0 !== strpos( $rel, 'wp-admin/' ) && 0 !== strpos( $rel, 'wp-includes/' ) && ! isset( $root_scope[ $rel ] ) ) {
				continue; // hors périmètre de la comparaison (ex. wp-config-sample.php, traduit)
			}
			if ( $official['fallback'] && 'wp-includes/version.php' === $rel ) {
				continue;
			}
			if ( isset( $hashes[ $rel ] ) ) {
				$checked++;
				if ( $hashes[ $rel ] !== $md5 ) {
					$issues[] = array( 'file' => $rel, 'type' => 'modified' );
				}
			} elseif ( ! file_exists( $abs . $rel ) ) {
				$issues[] = array( 'file' => $rel, 'type' => 'missing' );
			}
			// Présent mais non empreinté (illisible) : ni conforme, ni anomalie.
		}
		foreach ( array_keys( $hashes ) as $rel ) {
			$rel = (string) $rel;
			if ( ( 0 === strpos( $rel, 'wp-admin/' ) || 0 === strpos( $rel, 'wp-includes/' ) )
				&& '.php' === substr( $rel, -4 ) && ! isset( $sums[ $rel ] ) ) {
				$issues[] = array( 'file' => $rel, 'type' => 'unknown' );
			}
		}

		// Nouvelles anomalies seulement (clé type|fichier).
		$known = array();
		foreach ( isset( $prev['issues'] ) && is_array( $prev['issues'] ) ? $prev['issues'] : array() as $p ) {
			if ( isset( $p['type'], $p['file'] ) ) {
				$known[ $p['type'] . '|' . $p['file'] ] = true;
			}
		}
		$new = array();
		foreach ( $issues as $i ) {
			if ( ! isset( $known[ $i['type'] . '|' . $i['file'] ] ) ) {
				$new[] = $i;
			}
		}

		$labels = array(
			'modified' => __( 'Fichier du cœur MODIFIÉ (différent de wordpress.org) : %s', 'cyberpush-ultra' ),
			'unknown'  => __( 'Fichier PHP INCONNU dans le cœur (absent du paquet officiel) : %s', 'cyberpush-ultra' ),
			'missing'  => __( 'Fichier du cœur absent : %s', 'cyberpush-ultra' ),
		);
		$context = array( 'version' => $official['version'], 'locale' => $official['locale'] );
		if ( count( $new ) > self::CORE_EVENT_CAP ) {
			// Mise à jour interrompue… ou infection de masse : une synthèse critique.
			$this->record( 'critical', 'core', 'core_checksums_mass', sprintf(
				/* translators: %d: nombre de fichiers. */
				__( '%d fichiers du cœur diffèrent de wordpress.org (mise à jour incomplète ou infection de masse) — réinstallez WordPress', 'cyberpush-ultra' ),
				count( $new )
			), 'core', 'wordpress ' . $official['version'], $context );
		} else {
			foreach ( $new as $i ) {
				$this->record( 'missing' === $i['type'] ? 'medium' : 'critical', 'core', 'core_file_' . $i['type'], sprintf( $labels[ $i['type'] ], $i['file'] ), 'file', $i['file'], $context );
			}
		}
		if ( empty( $issues ) && ! empty( $prev['issues'] ) ) {
			$this->record( 'low', 'core', 'core_checksums_clean', sprintf( __( 'Cœur WordPress %s de nouveau conforme aux empreintes officielles', 'cyberpush-ultra' ), $official['version'] ), 'core', 'wordpress ' . $official['version'], $context );
		}

		update_option( self::CORE_CHECK_OPTION, array(
			'status'       => $issues ? 'issues' : 'ok',
			'version'      => $official['version'],
			'locale'       => $official['locale'],
			'fallback'     => $official['fallback'],
			'finished_at'  => $now,
			'checked'      => $checked,
			'issues'       => array_slice( $issues, 0, 200 ),
			'issues_total' => count( $issues ),
		), false );

		return array_values( array_filter( $new, static function ( $i ) {
			return 'missing' !== $i['type'];
		} ) );
	}

	/** Un e-mail par scan listant les nouvelles anomalies critiques du cœur. */
	private function send_core_alert( array $new ) {
		if ( ! CU_Settings::get( 'audit', 'email_alerts', true ) ) {
			return;
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( __( '[%s] Fichiers du cœur WordPress modifiés', 'cyberpush-ultra' ), $site );
		$lines   = array(
			sprintf( __( 'La comparaison avec les empreintes officielles de wordpress.org a détecté %d nouvelle(s) anomalie(s) :', 'cyberpush-ultra' ), count( $new ) ),
			'',
		);
		foreach ( array_slice( $new, 0, 10 ) as $i ) {
			$lines[] = ( 'unknown' === $i['type'] ? __( '  [inconnu] ', 'cyberpush-ultra' ) : __( '  [modifié] ', 'cyberpush-ultra' ) ) . $i['file'];
		}
		if ( count( $new ) > 10 ) {
			$lines[] = sprintf( __( '  … et %d autre(s).', 'cyberpush-ultra' ), count( $new ) - 10 );
		}
		$lines = array_merge( $lines, array(
			'',
			__( 'Les fichiers du cœur ne doivent jamais être modifiés : c\'est un signe fréquent d\'intrusion.', 'cyberpush-ultra' ),
			__( 'Remède : Tableau de bord > Mises à jour > « Réinstaller la version » remplace les fichiers du cœur par les originaux. Supprimez ensuite les fichiers « inconnus » après vérification.', 'cyberpush-ultra' ),
			admin_url( 'admin.php?page=cyberpush-ultra-firewall' ),
		) );
		$this->send_alert( $subject, $lines, array(), false, 'core_checksums' );
	}

	/**
	 * Récapitulatif e-mail du scan : chaque constat (critique d'abord), au lieu
	 * d'un simple nombre. Les mises à jour faites depuis WordPress et prouvées
	 * conformes n'y figurent pas.
	 *
	 * @param array<int, array{0: string, 1: string}> $findings array( gravité, message ).
	 * @param int $total Fichiers surveillés.
	 */
	private function send_scan_summary( array $findings, $total ) {
		if ( ! CU_Settings::get( 'audit', 'email_alerts', true ) ) {
			return;
		}
		usort( $findings, function ( $a, $b ) { return ( 'critical' === $a[0] ? 0 : 1 ) - ( 'critical' === $b[0] ? 0 : 1 ); } );
		$critical = count( array_filter( $findings, function ( $f ) { return 'critical' === $f[0]; } ) );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject  = $critical
			/* translators: 1: site, 2: nombre */
			? sprintf( __( '[%1$s] ALERTE intégrité : %2$d constat(s) critique(s)', 'cyberpush-ultra' ), $site, $critical )
			/* translators: 1: site, 2: nombre */
			: sprintf( __( '[%1$s] Intégrité des fichiers : %2$d changement(s) à vérifier', 'cyberpush-ultra' ), $site, count( $findings ) );
		/* translators: %d: fichiers surveillés */
		$lines = array( sprintf( __( 'Le scan d\'intégrité (%d fichiers surveillés) a relevé :', 'cyberpush-ultra' ), $total ), '' );
		foreach ( array_slice( $findings, 0, 30 ) as $finding ) {
			$lines[] = ( 'critical' === $finding[0] ? __( '[CRITIQUE] ', 'cyberpush-ultra' ) : __( '[À VÉRIFIER] ', 'cyberpush-ultra' ) ) . $finding[1];
		}
		if ( count( $findings ) > 30 ) {
			/* translators: %d: nombre */
			$lines[] = sprintf( __( '… et %d autre(s) dans le Journal de sécurité.', 'cyberpush-ultra' ), count( $findings ) - 30 );
		}
		$lines[] = '';
		$lines[] = __( 'Les installations et mises à jour faites depuis WordPress sont vérifiées puis reconnues automatiquement : ce qui figure ici s\'est produit en dehors (FTP, gestionnaire de fichiers, autre programme ou intrusion) ou n\'a pas pu être prouvé conforme.', 'cyberpush-ultra' );
		$lines[] = __( 'Si vous n\'êtes pas à l\'origine de ces changements, vérifiez immédiatement ces fichiers et changez vos mots de passe.', 'cyberpush-ultra' );
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=cyberpush-ultra-firewall' );
		$this->send_alert( $subject, $lines, array(), false, 'scan_summary' );
	}

	/**
	 * Scan planifié : un lot borné en temps, puis replanification si le scan
	 * n'est pas terminé (gros sites) ou si un autre lot tient le verrou.
	 */
	public function cron_scan() {
		if ( ! $this->enabled() ) {
			return;
		}
		$res = $this->run_scan_chunk( false, (float) apply_filters( 'cu_audit_cron_budget', 20 ) );
		if ( empty( $res['done'] ) && ! wp_next_scheduled( 'cu_cron_audit_scan_continue' ) ) {
			wp_schedule_single_event( time() + ( ! empty( $res['busy'] ) ? 180 : 60 ), 'cu_cron_audit_scan_continue' );
		}
	}

	// =========================================================================
	// Rétention
	// =========================================================================

	public function purge_old() {
		if ( ! CU_DB::tables_exist_for( array( 'firewall_audit' ) ) ) {
			return;
		}
		global $wpdb;
		$table  = CU_DB::table( 'firewall_audit' );
		$cutoff = CU_DB::date_from_now( 'Y-m-d H:i:s', -$this->retention_days() * DAY_IN_SECONDS );
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			CU_Logger::log( 'firewall', 'error', __( 'Impossible de purger les anciens événements de sécurité.', 'cyberpush-ultra' ) );
			return;
		}

		// Plafond de lignes (garde-fou contre une rafale d'inscriptions…) appliqué
		// aux seuls événements moyens/faibles : un flot de bruit ne peut plus
		// pousser hors du journal les preuves critiques/élevées, qui ne suivent
		// que la rétention par âge ci-dessus.
		$max   = max( 1000, (int) apply_filters( 'cu_audit_row_cap', self::RETENTION_ROW_CAP ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE severity IN ('medium','low')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $total > $max ) {
			$cut_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE severity IN ('medium','low') ORDER BY id DESC LIMIT 1 OFFSET %d", $max ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $cut_id > 0 ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE severity IN ('medium','low') AND id <= %d", $cut_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	// =========================================================================
	// RGPD : export et anonymisation (Outils > Exporter / Effacer les données)
	// =========================================================================

	public function register_privacy_exporter( $exporters ) {
		$exporters['cyberpush-ultra-audit'] = array(
			'exporter_friendly_name' => __( 'Journal de sécurité (CyberPush Ultra)', 'cyberpush-ultra' ),
			'callback'               => array( $this, 'privacy_export' ),
		);
		return $exporters;
	}

	public function register_privacy_eraser( $erasers ) {
		$erasers['cyberpush-ultra-audit'] = array(
			'eraser_friendly_name' => __( 'Journal de sécurité (CyberPush Ultra)', 'cyberpush-ultra' ),
			'callback'             => array( $this, 'privacy_erase' ),
		);
		return $erasers;
	}

	/** Événements dont la personne est l'AUTEUR (identifiant ou login), 100 par page. */
	public function privacy_export( $email, $page = 1 ) {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user || ! CU_DB::tables_exist_for( array( 'firewall_audit' ) ) ) {
			return array( 'data' => array(), 'done' => true );
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_audit' );
		$per   = 100;
		$page  = max( 1, (int) $page );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, message, ip, country_code FROM {$table} WHERE actor_id = %d OR actor_login = %s ORDER BY id ASC LIMIT %d OFFSET %d", (int) $user->ID, $user->user_login, $per, ( $page - 1 ) * $per ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$data  = array();
		foreach ( (array) $rows as $r ) {
			$data[] = array(
				'group_id'          => 'cyberpush-ultra-audit',
				'group_label'       => __( 'Journal de sécurité', 'cyberpush-ultra' ),
				'group_description' => __( 'Actions sensibles effectuées avec ce compte (conservées pour la sécurité du site).', 'cyberpush-ultra' ),
				'item_id'           => 'cu-audit-' . (int) $r['id'],
				'data'              => array(
					array( 'name' => __( 'Date', 'cyberpush-ultra' ), 'value' => (string) $r['created_at'] ),
					array( 'name' => __( 'Événement', 'cyberpush-ultra' ), 'value' => (string) $r['message'] ),
					array( 'name' => __( 'Adresse IP', 'cyberpush-ultra' ), 'value' => (string) $r['ip'] ),
					array( 'name' => __( 'Pays', 'cyberpush-ultra' ), 'value' => (string) $r['country_code'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => count( (array) $rows ) < $per );
	}

	/**
	 * Effacement = ANONYMISATION, pas suppression : le journal de sécurité est
	 * une preuve en cas d'intrusion (intérêt légitime, RGPD art. 6.1.f et
	 * considérant 49 ; un attaquant pourrait sinon faire effacer ses traces via
	 * une demande d'effacement). Login et ID retirés, IP tronquée
	 * (wp_privacy_anonymize_ip), adresse e-mail masquée dans les messages.
	 */
	public function privacy_erase( $email, $page = 1 ) {
		$out  = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user || ! CU_DB::tables_exist_for( array( 'firewall_audit' ) ) ) {
			return $out;
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_audit' );
		$batch = 200;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, ip FROM {$table} WHERE actor_id = %d OR actor_login = %s LIMIT %d", (int) $user->ID, $user->user_login, $batch ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$done  = 0;
		foreach ( (array) $rows as $r ) {
			$ip = '' !== (string) $r['ip'] ? wp_privacy_anonymize_ip( (string) $r['ip'] ) : null;
			if ( false !== $wpdb->update( $table, array( 'actor_id' => null, 'actor_login' => '[anonymisé]', 'ip' => $ip ), array( 'id' => (int) $r['id'] ) ) ) {
				$done++;
			}
		}
		$last = count( (array) $rows ) < $batch;
		if ( $last && is_email( $user->user_email ) ) {
			$like = '%' . $wpdb->esc_like( $user->user_email ) . '%';
			$mask = '[e-mail anonymisé]';
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET message = REPLACE(message, %s, %s), object_name = REPLACE(object_name, %s, %s), context = REPLACE(context, %s, %s) WHERE message LIKE %s OR object_name LIKE %s OR context LIKE %s", $user->user_email, $mask, $user->user_email, $mask, $user->user_email, $mask, $like, $like, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $done > 0 ) {
			$out['items_removed']  = true;
			$out['items_retained'] = true;
			$out['messages'][]     = __( 'Journal de sécurité : les événements liés à ce compte sont conservés sous forme anonymisée (identifiant retiré, adresse IP tronquée), car ils servent de preuve en cas d’intrusion.', 'cyberpush-ultra' );
		}
		$out['done'] = $last;
		return $out;
	}

	// =========================================================================
	// Routes REST
	// =========================================================================

	public function register_routes() {
		$ns = CU_REST_NAMESPACE;

		register_rest_route( $ns, '/firewall/audit/list', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list' ),
			'permission_callback' => array( $this, 'permission_admin' ),
		) );
		register_rest_route( $ns, '/firewall/audit/scan', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_scan' ),
			'permission_callback' => array( $this, 'permission_admin' ),
		) );
		register_rest_route( $ns, '/firewall/audit/baseline-reset', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_baseline_reset' ),
			'permission_callback' => array( $this, 'permission_admin' ),
		) );
		register_rest_route( $ns, '/firewall/audit/purge', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_purge' ),
			'permission_callback' => array( $this, 'permission_admin' ),
		) );
		register_rest_route( $ns, '/firewall/audit/test-email', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_test_email' ),
			'permission_callback' => array( $this, 'permission_admin' ),
		) );
	}

	/**
	 * Envoie un e-mail de test par le même chemin que les vraies alertes. Beaucoup
	 * d'hébergements ont un mail() bloqué ou un SMTP mal configuré : sans test,
	 * l'administrateur découvrirait la panne le jour d'une vraie intrusion.
	 */
	public function rest_test_email() {
		$throttle = 'cu_audit_test_mail';
		if ( get_transient( $throttle ) ) {
			return new WP_Error( 'cu_test_mail_wait', __( 'Un e-mail de test vient d’être envoyé. Patientez 30 secondes avant d’en demander un autre.', 'cyberpush-ultra' ), array( 'status' => 429 ) );
		}
		set_transient( $throttle, 1, 30 );

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( __( '[%s] Test des alertes de sécurité', 'cyberpush-ultra' ), $site );
		$lines   = array(
			__( 'Ceci est un e-mail de test envoyé depuis CyberPush Ultra.', 'cyberpush-ultra' ),
			'',
			__( 'Si vous le lisez, les alertes de sécurité critiques vous parviendront à cette adresse.', 'cyberpush-ultra' ),
			__( 'Pensez à vérifier le dossier « Spam » et à marquer cet expéditeur comme fiable.', 'cyberpush-ultra' ),
			'',
			sprintf( __( 'Date : %s', 'cyberpush-ultra' ), current_time( 'mysql' ) ),
			admin_url( 'admin.php?page=cyberpush-ultra-settings#alerts' ),
		);
		$result = $this->send_alert( $subject, $lines, array(), true );
		if ( is_wp_error( $result ) ) {
			// 502 : la panne vient du serveur de messagerie, pas de la requête ;
			// le journal REST la consigne aussi dans la page Logs.
			return new WP_Error( 'cu_test_mail_failed', sprintf( __( 'Échec de l’envoi : %s', 'cyberpush-ultra' ), $result->get_error_message() ), array( 'status' => 'cu_alert_no_recipient' === $result->get_error_code() ? 400 : 502 ) );
		}
		return rest_ensure_response( array(
			'success'    => true,
			'recipients' => $this->alert_recipients(),
			'message'    => sprintf( __( 'E-mail de test accepté par le serveur pour : %s. Vérifiez la boîte de réception (et le dossier Spam).', 'cyberpush-ultra' ), implode( ', ', $this->alert_recipients() ) ),
		) );
	}

	public function permission_admin() {
		return current_user_can( 'manage_options' );
	}

	public function rest_list( WP_REST_Request $request ) {
		if ( ! CU_DB::tables_exist() ) {
			return new WP_Error( 'cu_migration_required', __( 'Migration SQL requise.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_audit' );

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 5, (int) $request->get_param( 'per_page' ) ?: 25 ) );
		$severity = sanitize_key( (string) $request->get_param( 'severity' ) );
		$category = sanitize_key( (string) $request->get_param( 'category' ) );
		$search   = is_scalar( $request->get_param( 'search' ) ) ? trim( (string) $request->get_param( 'search' ) ) : '';

		$where  = array( '1=1' );
		$params = array();
		if ( in_array( $severity, array( 'critical', 'high', 'medium', 'low' ), true ) ) {
			$where[]  = 'severity = %s';
			$params[] = $severity;
		}
		if ( $category ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}
		if ( '' !== $search ) {
			$where[]  = '( message LIKE %s OR object_name LIKE %s OR actor_login LIKE %s OR ip LIKE %s )';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );

		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $params
			? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) // phpcs:ignore WordPress.DB.PreparedSQL
			: "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = '' !== $wpdb->last_error;

		$offset   = ( $page - 1 ) * $per_page;
		$rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$read_failed = $read_failed || '' !== $wpdb->last_error;
		if ( $read_failed || ! is_array( $rows ) ) {
			return new WP_Error( 'cu_audit_read_failed', __( 'Impossible de lire le journal de sécurité.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		foreach ( $rows as &$r ) {
			if ( ! empty( $r['context'] ) ) {
				$r['context'] = json_decode( $r['context'], true );
			}
		}
		unset( $r );

		// KPI (indépendants des filtres).
		$crit_24h = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE severity='critical' AND created_at >= %s", CU_DB::date_from_now( 'Y-m-d H:i:s', -DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = '' !== $wpdb->last_error;
		$crit_7d  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE severity='critical' AND created_at >= %s", CU_DB::date_from_now( 'Y-m-d H:i:s', -7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = $read_failed || '' !== $wpdb->last_error;
		$total_all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = $read_failed || '' !== $wpdb->last_error;
		if ( $read_failed ) {
			return new WP_Error( 'cu_audit_kpi_failed', __( 'Impossible de calculer les indicateurs du journal de sécurité.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		// Données légères uniquement : état compact du scan, résumés en options.
		$state     = $this->scan_state();
		$stalled   = $state && ( time() - (int) $state['touched_ts'] ) > 30 * MINUTE_IN_SECONDS;
		$last_scan = get_option( self::LAST_SCAN_OPTION, array() );

		return rest_ensure_response( array(
			'items'         => $rows,
			'total'         => $total,
			'page'          => $page,
			'pages'         => (int) ceil( $total / $per_page ),
			'kpi'           => array( 'critical_24h' => $crit_24h, 'critical_7d' => $crit_7d, 'total' => $total_all ),
			'scanning'      => $state && ! $stalled,
			'scan_stalled'  => (bool) $stalled,
			'scan_progress' => $state ? array(
				'processed'  => (int) $state['files'],
				'visited'    => (int) $state['visited'],
				'total'      => (int) $state['estimate'],
				'phase'      => (string) $state['phase'],
				'started_at' => (string) $state['started_at'],
			) : null,
			'last_scan'     => $last_scan ? $last_scan : null,
			'core_check'    => $this->core_check_summary(),
			'baseline_ready' => $this->baseline_ready(),
			'rebaseline_pending' => (bool) get_option( self::REBASELINE_OPTION, false ),
			'forensics'     => $this->forensics_summary(),
			'enabled'       => $this->enabled(),
		) );
	}

	/** Résumé affichable de la dernière comparaison officielle (null si jamais faite). */
	private function core_check_summary() {
		$c = get_option( self::CORE_CHECK_OPTION, array() );
		if ( empty( $c ) || ! is_array( $c ) || empty( $c['status'] ) ) {
			return null;
		}
		return array(
			'status'       => (string) $c['status'],
			'version'      => isset( $c['version'] ) ? (string) $c['version'] : '',
			'finished_at'  => isset( $c['finished_at'] ) ? (string) $c['finished_at'] : '',
			'checked'      => isset( $c['checked'] ) ? (int) $c['checked'] : 0,
			'issues_total' => isset( $c['issues_total'] ) ? (int) $c['issues_total'] : 0,
			'issues'       => isset( $c['issues'] ) && is_array( $c['issues'] ) ? array_slice( $c['issues'], 0, 30 ) : array(),
		);
	}

	/** Dernier vidage du journal / dernière réinitialisation de la référence. */
	private function forensics_summary() {
		$f   = get_option( self::FORENSICS_OPTION, array() );
		$out = array();
		foreach ( array( 'purge', 'reset' ) as $what ) {
			if ( is_array( $f ) && isset( $f[ $what ] ) && is_array( $f[ $what ] ) ) {
				$e            = $f[ $what ];
				$out[ $what ] = array(
					'at'   => isset( $e['at'] ) ? sanitize_text_field( (string) $e['at'] ) : '',
					'by'   => isset( $e['by'] ) ? sanitize_text_field( (string) $e['by'] ) : '',
					'ip'   => isset( $e['ip'] ) ? sanitize_text_field( (string) $e['ip'] ) : '',
					'rows' => isset( $e['rows'] ) ? (int) $e['rows'] : 0,
				);
			}
		}
		return $out ? $out : null;
	}

	private function remember_forensic( $what, $rows = 0 ) {
		$f            = get_option( self::FORENSICS_OPTION, array() );
		$f            = is_array( $f ) ? $f : array();
		$actor        = $this->current_actor();
		$f[ $what ]   = array(
			'at'   => current_time( 'mysql' ),
			'by'   => (string) $actor['login'],
			'ip'   => (string) CU_Geo::client_ip(),
			'rows' => (int) $rows,
		);
		update_option( self::FORENSICS_OPTION, $f, false );
	}

	public function rest_scan( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return new WP_Error( 'cu_audit_disabled', __( 'Le journal de sécurité est désactivé.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$start = (bool) $request->get_param( 'start' );
		$res   = $this->run_scan_chunk( $start, (float) apply_filters( 'cu_audit_rest_budget', 4 ) );
		$res['success'] = true;
		return rest_ensure_response( $res );
	}

	/**
	 * Réinitialisation de la référence : tracée et signalée AVANT d'agir (e-mail
	 * forcé, hors plafond). La référence n'est pas effacée : le prochain scan
	 * accepte l'état actuel mais liste ce qui différait de l'ancienne.
	 */
	public function rest_baseline_reset() {
		if ( ! CU_DB::tables_exist_for( array( 'fim', 'firewall_audit' ) ) ) {
			return new WP_Error( 'cu_migration_required', __( 'Migration SQL requise.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		$token = $this->acquire_scan_lock();
		if ( false === $token ) {
			return new WP_Error( 'cu_audit_busy', __( 'Un lot de scan est en cours : réessayez dans quelques secondes.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		try {
			$this->record( 'critical', 'files', 'baseline_reset', __( 'Référence d’intégrité RÉINITIALISÉE : le prochain scan acceptera l’état actuel des fichiers (les différences seront listées, sans alerte individuelle)', 'cyberpush-ultra' ), 'files', '', array(), $this->forensic_alert() );
			$this->remember_forensic( 'reset' );
			delete_option( self::STATE_OPTION );
			$this->reset_scan_columns();
			update_option( self::REBASELINE_OPTION, time(), false );
		} finally {
			$this->release_scan_lock( $token );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Vidage du journal : l'événement de vidage est écrit (et l'e-mail envoyé)
	 * AVANT la suppression, puis tout ce qui le précède est effacé — il reste
	 * donc la première ligne du journal. Une trace survit aussi en option.
	 */
	public function rest_purge() {
		if ( ! CU_DB::tables_exist_for( array( 'firewall_audit' ) ) ) {
			return new WP_Error( 'cu_migration_required', __( 'Migration SQL requise.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$table = CU_DB::table( 'firewall_audit' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		/* translators: %d: nombre d'événements */
		$id = $this->record( 'critical', 'settings', 'audit_log_purged', sprintf( __( 'Journal de sécurité VIDÉ (%d événement(s) supprimé(s))', 'cyberpush-ultra' ), $count ), 'table', 'firewall_audit', array(), $this->forensic_alert() );
		if ( $id <= 0 ) {
			return new WP_Error( 'cu_audit_purge_failed', __( 'Vidage annulé : l’opération n’a pas pu être tracée dans le journal.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		$this->remember_forensic( 'purge', $count );
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'cu_audit_purge_failed', __( 'Impossible de purger le journal de sécurité.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}
}

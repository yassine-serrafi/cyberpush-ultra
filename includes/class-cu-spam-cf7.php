<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adaptateur Contact Form 7 du module Spam : le SEUL fichier qui connaît CF7.
 *
 * Contrat d'un adaptateur (à reproduire pour une autre extension de formulaires) :
 *  - is_available() / version() : extension présente, et sa version ;
 *  - forms()                    : formulaires connus (id, titre, champ e-mail, lien) ;
 *  - integrations()             : protections tierces détectées (diagnostic) ;
 *  - init()                     : branche les crochets de l'extension, qui délèguent
 *    toutes les décisions à CU_Spam : honeypot_html(), make_token(), verdict(),
 *    spam_reason(), email_decision(), note(), finish(), reset().
 *
 * Crochets publics de CF7 (inchangés jusqu'à CF7 6.2.x) :
 *  - wpcf7_form_elements / wpcf7_form_hidden_fields : champ piège + horodatage signé
 *    (noms préfixés « _ » : CF7 ne les met jamais dans les e-mails ni dans Flamingo) ;
 *  - wpcf7_validate_email / email* (20) : domaine de l'adresse, message corrigeable ;
 *  - wpcf7_spam (8) : avant reCAPTCHA / Turnstile (9) et Akismet, un robot pris au
 *    piège ne coûte aucun appel d'API ; motif inscrit via add_spam_log() ;
 *  - wpcf7_display_message (20) : message de limite temporaire (envois répétés) ;
 *  - wpcf7_submit (99) : une ligne de journal par envoi examiné, après l'e-mail.
 * Toute erreur est absorbée : un incident du module ne bloque jamais un envoi.
 */
class CU_Spam_CF7 {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public static function is_available() {
		return defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7_ContactForm' );
	}

	public static function version() {
		return defined( 'WPCF7_VERSION' ) ? (string) WPCF7_VERSION : '';
	}

	public function init() {
		add_filter( 'wpcf7_form_elements', array( $this, 'add_honeypot' ), 20 );
		add_filter( 'wpcf7_form_hidden_fields', array( $this, 'add_token' ), 20 );
		add_filter( 'wpcf7_validate_email', array( $this, 'validate_email' ), 20, 2 );
		add_filter( 'wpcf7_validate_email*', array( $this, 'validate_email' ), 20, 2 );
		add_filter( 'wpcf7_spam', array( $this, 'filter_spam' ), 8, 2 );
		add_filter( 'wpcf7_display_message', array( $this, 'display_message' ), 20, 2 );
		add_action( 'wpcf7_submit', array( $this, 'on_submit' ), 99, 2 );
	}

	/**
	 * Envoi refusé par la limite d'envois répétés : le visiteur lit un message
	 * explicite de limite temporaire au lieu de l'erreur générique de CF7. Les
	 * autres refus (pot de miel…) gardent le message standard : rien n'est révélé.
	 *
	 * @param string $message Message prévu par CF7.
	 * @param string $status  Statut CF7 (spam, mail_sent…).
	 */
	public function display_message( $message, $status = '' ) {
		try {
			if ( 'spam' === $status && 'rate_limit' === CU_Spam::instance()->blocked_reason() ) {
				return CU_Spam::rate_limit_message();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $message;
	}

	// -------------------------------------------------------------------------
	// Rendu du formulaire
	// -------------------------------------------------------------------------

	public function add_honeypot( $html ) {
		try {
			if ( CU_Spam::honeypot_active() && CU_Spam::form_protected( $this->current_form_id() ) ) {
				CU_Spam::mark_since();
				return (string) $html . CU_Spam::honeypot_html();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $html;
	}

	public function add_token( $fields ) {
		try {
			if ( CU_Spam::honeypot_active() && CU_Spam::form_protected( $this->current_form_id() ) ) {
				$fields                          = (array) $fields;
				$fields[ CU_Spam::TOKEN_FIELD ] = CU_Spam::make_token();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $fields;
	}

	// -------------------------------------------------------------------------
	// Envoi
	// -------------------------------------------------------------------------

	/**
	 * @param WPCF7_Validation $result
	 * @param WPCF7_FormTag    $tag
	 */
	public function validate_email( $result, $tag ) {
		try {
			$name = is_object( $tag ) && isset( $tag->name ) ? (string) $tag->name : '';
			if ( '' === $name || ! is_object( $result ) || ! method_exists( $result, 'invalidate' ) ) {
				return $result;
			}
			if ( ( method_exists( $result, 'is_valid' ) && ! $result->is_valid( $name ) ) || ! CU_Spam::email_checks_active() || ! CU_Spam::form_protected( $this->submission_form_id() ) ) {
				return $result;
			}
			$post = $this->posted();
			if ( CU_Spam::honeypot_active() ) {
				$verdict = CU_Spam::instance()->verdict( $post );
				if ( 'spam' === $verdict['level'] ) {
					return $result; // robot évident : aucune requête DNS, l'étape spam tranchera
				}
			}
			$email = isset( $post[ $name ] ) && is_scalar( $post[ $name ] ) ? trim( (string) $post[ $name ] ) : '';
			if ( '' !== $email ) {
				$message = CU_Spam::instance()->email_decision( $email );
				if ( '' !== $message ) {
					$result->invalidate( $tag, $message );
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $result;
	}

	/**
	 * @param bool                  $spam       true si un mécanisme précédent a déjà tranché.
	 * @param WPCF7_Submission|null $submission
	 */
	public function filter_spam( $spam, $submission = null ) {
		if ( $spam ) {
			return $spam; // un autre mécanisme a déjà classé l'envoi : rien à ajouter
		}
		try {
			$form_id = $this->submission_form_id( $submission );
			if ( ! CU_Spam::form_protected( $form_id ) ) {
				return $spam;
			}
			$reason = CU_Spam::instance()->spam_reason( $this->posted(), $form_id );
			if ( '' === $reason ) {
				return $spam;
			}
			CU_Spam::instance()->note( 'blocked', $reason );
			if ( is_object( $submission ) && method_exists( $submission, 'add_spam_log' ) ) {
				$submission->add_spam_log( array( 'agent' => 'cyberpush', 'reason' => CU_Spam::log_reason( $reason ) ) );
			}
			return true;
		} catch ( Throwable $e ) {
			unset( $e );
			return $spam;
		}
	}

	/**
	 * @param WPCF7_ContactForm $contact_form
	 * @param array             $result       Résultat CF7 (« status »…).
	 */
	public function on_submit( $contact_form, $result = array() ) {
		try {
			$form_id = is_object( $contact_form ) && method_exists( $contact_form, 'id' ) ? (int) $contact_form->id() : 0;
			$status  = is_array( $result ) && isset( $result['status'] ) ? (string) $result['status'] : '';
			if ( CU_Spam::form_protected( $form_id ) ) {
				CU_Spam::instance()->finish( $form_id, $status, 'spam' === $status ? $this->other_agent() : '', $this->first_email( $contact_form ) );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		CU_Spam::instance()->reset();
	}

	/** Données envoyées (CF7 lit $_POST, que l'envoi passe par l'API REST ou sans JavaScript). */
	private function posted() {
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return is_array( $post ) ? $post : array();
	}

	private function current_form_id() {
		if ( class_exists( 'WPCF7_ContactForm' ) && method_exists( 'WPCF7_ContactForm', 'get_current' ) ) {
			$form = WPCF7_ContactForm::get_current();
			if ( is_object( $form ) && method_exists( $form, 'id' ) ) {
				return (int) $form->id();
			}
		}
		return 0;
	}

	private function submission_form_id( $submission = null ) {
		if ( ! is_object( $submission ) && class_exists( 'WPCF7_Submission' ) ) {
			$submission = WPCF7_Submission::get_instance();
		}
		if ( is_object( $submission ) && method_exists( $submission, 'get_contact_form' ) ) {
			$form = $submission->get_contact_form();
			if ( is_object( $form ) && method_exists( $form, 'id' ) ) {
				return (int) $form->id();
			}
		}
		$current = $this->current_form_id();
		return $current ? $current : ( isset( $_POST['_wpcf7'] ) && is_scalar( $_POST['_wpcf7'] ) ? absint( $_POST['_wpcf7'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/** Premier mécanisme (autre que CyberPush) inscrit dans le journal de spam de CF7. */
	private function other_agent() {
		$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		if ( is_object( $submission ) && method_exists( $submission, 'get_spam_log' ) ) {
			foreach ( (array) $submission->get_spam_log() as $entry ) {
				$agent = is_array( $entry ) && isset( $entry['agent'] ) ? substr( sanitize_key( (string) $entry['agent'] ), 0, 30 ) : '';
				if ( '' !== $agent && 'cyberpush' !== $agent ) {
					return $agent;
				}
			}
		}
		return '';
	}

	/** Première adresse saisie dans un champ e-mail du formulaire (seul son domaine sera gardé). */
	private function first_email( $contact_form ) {
		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return '';
		}
		$post = $this->posted();
		foreach ( (array) $contact_form->scan_form_tags( array( 'basetype' => 'email' ) ) as $tag ) {
			$name = is_object( $tag ) && isset( $tag->name ) ? (string) $tag->name : '';
			if ( '' !== $name && isset( $post[ $name ] ) && is_scalar( $post[ $name ] ) && '' !== trim( (string) $post[ $name ] ) ) {
				return trim( (string) $post[ $name ] );
			}
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Administration
	// -------------------------------------------------------------------------

	/** @return array<int, array{id:int, title:string, has_email:bool, edit_url:string}> */
	public function forms() {
		if ( ! post_type_exists( 'wpcf7_contact_form' ) ) {
			return array();
		}
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => true ) ) as $post ) {
			$has_email = (bool) preg_match( '/\[\s*email\*?\s/', (string) get_post_meta( $post->ID, '_form', true ) );
			if ( class_exists( 'WPCF7_ContactForm' ) && method_exists( 'WPCF7_ContactForm', 'get_instance' ) ) {
				$form = WPCF7_ContactForm::get_instance( $post );
				if ( is_object( $form ) && method_exists( $form, 'scan_form_tags' ) ) {
					$has_email = (bool) $form->scan_form_tags( array( 'basetype' => 'email' ) );
				}
			}
			$out[] = array(
				'id'        => (int) $post->ID,
				'title'     => (string) get_the_title( $post ),
				'has_email' => $has_email,
				'edit_url'  => admin_url( 'admin.php?page=wpcf7&post=' . (int) $post->ID . '&action=edit' ),
			);
		}
		return $out;
	}

	/** @return array<string, bool|null> true actif, false installé mais inactif, null non détecté. */
	public function integrations() {
		$akismet = null;
		if ( class_exists( 'Akismet' ) && is_callable( array( 'Akismet', 'get_api_key' ) ) ) {
			$akismet = '' !== (string) call_user_func( array( 'Akismet', 'get_api_key' ) );
		}
		return array(
			'recaptcha' => self::service_active( 'recaptcha', 'WPCF7_RECAPTCHA' ),
			'turnstile' => self::service_active( 'turnstile', 'WPCF7_Turnstile' ),
			'akismet'   => $akismet,
			'flamingo'  => defined( 'FLAMINGO_VERSION' ) || class_exists( 'Flamingo_Inbound_Message' ) ? true : null,
		);
	}

	/** Service d'intégration CF7 (reCAPTCHA, Turnstile ≥ 6.1) : registre CF7, puis classe connue. */
	private static function service_active( $name, $class ) {
		try {
			if ( class_exists( 'WPCF7_Integration' ) && method_exists( 'WPCF7_Integration', 'get_instance' ) ) {
				$integration = WPCF7_Integration::get_instance();
				$service     = is_object( $integration ) && method_exists( $integration, 'get_service' ) ? $integration->get_service( $name ) : null;
				if ( is_object( $service ) && method_exists( $service, 'is_active' ) ) {
					return (bool) $service->is_active();
				}
			}
			if ( class_exists( $class ) && method_exists( $class, 'get_instance' ) ) {
				$service = call_user_func( array( $class, 'get_instance' ) );
				if ( is_object( $service ) && method_exists( $service, 'is_active' ) ) {
					return (bool) $service->is_active();
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return null;
	}

	/** Autres extensions anti-spam pour CF7 actives (doublon possible, pas une erreur). */
	public function other_antispam_plugins() {
		$known  = array(
			'contact-form-7-honeypot' => 'Honeypot for Contact Form 7',
			'honeypot'                => 'WP Armour – Honeypot Anti Spam',
			'cf7-antispam'            => 'AntiSpam for Contact Form 7',
			'zero-spam'               => 'Zero Spam for WordPress',
			'cleantalk-spam-protect'  => 'CleanTalk Anti-Spam',
		);
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$found = array();
		foreach ( $active as $file ) {
			$dir = strtok( (string) $file, '/' );
			if ( is_string( $dir ) && isset( $known[ $dir ] ) ) {
				$found[ $known[ $dir ] ] = true;
			}
		}
		return array_keys( $found );
	}
}

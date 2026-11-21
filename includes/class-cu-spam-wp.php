<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adaptateur « formulaires natifs de WordPress » du module Spam : commentaires
 * (y compris les avis WooCommerce, qui sont des commentaires), inscription et
 * mot de passe oublié de wp-login.php. Même contrat que CU_Spam_CF7 : toutes
 * les décisions restent dans CU_Spam (pot de miel, jeton signé, envois répétés,
 * domaine de l'adresse e-mail) ; le contenu des messages n'est jamais analysé.
 *
 * Garde-fous (ne jamais bloquer un vrai visiteur) :
 *  - utilisateurs connectés jamais examinés ;
 *  - « champs de protection absents » ne bloque que si CE formulaire a déjà été
 *    affiché avec nos champs sur ce site (sinon : simple « suspect ») — un thème
 *    ou une extension qui remplace le formulaire sans ses crochets ne bloque
 *    donc personne ;
 *  - commentaire sans les champs : rangé dans « Indésirables » (récupérable),
 *    jamais supprimé ; seul un champ piège rempli refuse le commentaire.
 *  - toute erreur est absorbée : un incident du module ne bloque jamais un envoi.
 *
 * Chaque formulaire a un identifiant réservé (hors des identifiants d'articles)
 * dans le journal anti-spam et la liste des exclusions.
 */
class CU_Spam_WP {

	const FORM_COMMENTS = 9100000001;
	const FORM_REGISTER = 9100000002;
	const FORM_LOSTPASS = 9100000003;

	/** Dernier affichage de chaque formulaire avec nos champs (option autochargée). */
	const OPT_RENDERED = 'cu_spam_wp_rendered';

	private static $instance = null;

	/** Soumission de commentaire en cours depuis le formulaire public. */
	private $comment_ctx = false;
	/** Commentaire en cours déjà examiné. */
	private $comment_checked = false;
	/** Décision déjà journalisée pour la soumission en cours. */
	private $logged = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/** Identifiants des formulaires gérés par cet adaptateur. */
	public static function form_ids() {
		return array( self::FORM_COMMENTS, self::FORM_REGISTER, self::FORM_LOSTPASS );
	}

	public function init() {
		// Commentaires.
		add_action( 'comment_form', array( $this, 'render_comment_fields' ), 20 );
		add_action( 'pre_comment_on_post', array( $this, 'start_comment' ), 1 );
		add_filter( 'pre_comment_approved', array( $this, 'filter_comment' ), 20, 2 );
		add_action( 'comment_post', array( $this, 'after_comment' ), 99, 2 );
		// Inscription (wp-login.php?action=register).
		add_action( 'register_form', array( $this, 'render_register_fields' ), 20 );
		add_filter( 'registration_errors', array( $this, 'filter_registration' ), 20, 3 );
		add_action( 'register_new_user', array( $this, 'after_registration' ), 99 );
		// Mot de passe oublié (wp-login.php?action=lostpassword).
		add_action( 'lostpassword_form', array( $this, 'render_lostpassword_fields' ), 20 );
		add_action( 'lostpassword_post', array( $this, 'filter_lostpassword' ), 20, 2 );
	}

	// -------------------------------------------------------------------------
	// Rendu : champ piège + horodatage signé
	// -------------------------------------------------------------------------

	private function fields_html( $form_id ) {
		if ( is_user_logged_in() || ! CU_Spam::honeypot_active() || ! CU_Spam::form_protected( $form_id ) ) {
			return '';
		}
		CU_Spam::mark_since();
		self::mark_rendered( $form_id );
		return CU_Spam::honeypot_html()
			. '<input type="hidden" name="' . esc_attr( CU_Spam::TOKEN_FIELD ) . '" value="' . esc_attr( CU_Spam::make_token() ) . '">';
	}

	public function render_comment_fields() {
		$this->echo_fields( self::FORM_COMMENTS );
	}

	public function render_register_fields() {
		$this->echo_fields( self::FORM_REGISTER );
	}

	public function render_lostpassword_fields() {
		$this->echo_fields( self::FORM_LOSTPASS );
	}

	private function echo_fields( $form_id ) {
		try {
			echo $this->fields_html( $form_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML construit et échappé ci-dessus.
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/** Premier et dernier affichage du formulaire avec nos champs : array( premier, dernier ). */
	private static function rendered_times( $form_id ) {
		$seen = get_option( self::OPT_RENDERED, array() );
		$key  = (string) (int) $form_id;
		if ( ! is_array( $seen ) || ! isset( $seen[ $key ] ) ) {
			return array( 0, 0 );
		}
		$v = $seen[ $key ];
		return is_array( $v ) ? array( (int) ( $v[0] ?? 0 ), (int) ( $v[1] ?? 0 ) ) : array( (int) $v, (int) $v );
	}

	/** Mémorise (au plus deux fois par jour) que le formulaire s'affiche avec nos champs. */
	private static function mark_rendered( $form_id ) {
		list( $first, $last ) = self::rendered_times( $form_id );
		if ( $last && time() - $last < 12 * HOUR_IN_SECONDS ) {
			return;
		}
		$seen                               = get_option( self::OPT_RENDERED, array() );
		$seen                               = is_array( $seen ) ? $seen : array();
		$seen[ (string) (int) $form_id ] = array( $first ? $first : time(), time() );
		update_option( self::OPT_RENDERED, $seen, true );
	}

	/**
	 * Le formulaire porte-t-il nos champs depuis assez longtemps pour qu'un envoi
	 * sans eux soit un robot ? Délai de grâce PROPRE à ce formulaire (pages encore
	 * en cache après l'installation ou la mise à jour), et vu récemment.
	 */
	private static function rendered_recently( $form_id ) {
		list( $first, $last ) = self::rendered_times( $form_id );
		return $first && time() - $first >= CU_Spam::GRACE_DAYS * DAY_IN_SECONDS && time() - $last < 30 * DAY_IN_SECONDS;
	}

	// -------------------------------------------------------------------------
	// Décision commune
	// -------------------------------------------------------------------------

	/**
	 * Pot de miel, jeton et envois répétés. « Champs absents » sur un formulaire
	 * jamais vu avec nos champs : seulement suspect.
	 *
	 * @return string Raison du blocage, '' si l'envoi passe.
	 */
	private function spam_reason( $form_id ) {
		$spam   = CU_Spam::instance();
		$reason = $spam->spam_reason( $this->posted(), $form_id );
		// Inscription et mot de passe oublié : un formulaire personnalisé (thème,
		// extension) peut poster sans nos champs — jamais bloqué pour cela, seulement
		// « suspect ». Commentaires : rangés dans « Indésirables » après le délai de grâce.
		if ( 'missing_fields' === $reason && ( self::FORM_COMMENTS !== (int) $form_id || ! self::rendered_recently( $form_id ) ) ) {
			$spam->note( 'suspect', 'missing_fields' );
			return '';
		}
		return $reason;
	}

	/** Message affiché au visiteur (rien n'est révélé, sauf la limite temporaire). */
	private static function refusal_message( $reason ) {
		return 'rate_limit' === $reason
			? CU_Spam::rate_limit_message()
			: __( 'Votre envoi n’a pas pu être enregistré. Merci de réessayer depuis le formulaire du site.', 'cyberpush-ultra' );
	}

	private function finish( $form_id, $status, $other_agent, $email ) {
		if ( $this->logged ) {
			return;
		}
		$this->logged = true;
		CU_Spam::instance()->finish( $form_id, $status, $other_agent, $email );
	}

	// -------------------------------------------------------------------------
	// Commentaires
	// -------------------------------------------------------------------------

	/** Début d'une soumission par le formulaire public (wp-comments-post.php). */
	public function start_comment() {
		$this->comment_ctx     = ! is_user_logged_in() && CU_Spam::form_protected( self::FORM_COMMENTS );
		$this->comment_checked = false;
		$this->logged          = false;
		CU_Spam::instance()->reset();
	}

	/**
	 * @param int|string|WP_Error $approved    Statut prévu (1, 0, 'spam', 'trash' ou erreur).
	 * @param array               $commentdata Données du commentaire.
	 * @return int|string|WP_Error
	 */
	public function filter_comment( $approved, $commentdata = array() ) {
		// WordPress revalide le commentaire après ses filtres (wp_check_comment_data) :
		// une seule décision par soumission, sinon chaque envoi compterait double.
		if ( ! $this->comment_ctx || $this->comment_checked || is_wp_error( $approved ) ) {
			return $approved;
		}
		$this->comment_checked = true;
		try {
			$type = is_array( $commentdata ) && isset( $commentdata['comment_type'] ) ? (string) $commentdata['comment_type'] : '';
			if ( ! in_array( $type, array( '', 'comment', 'review' ), true ) ) {
				$this->comment_ctx = false; // ping, rétrolien, note : pas un formulaire
				return $approved;
			}
			if ( 'spam' === $approved ) {
				return $approved; // déjà classé par Akismet ou la liste de modération : journalisé après coup
			}
			$email  = is_array( $commentdata ) && isset( $commentdata['comment_author_email'] ) ? trim( (string) $commentdata['comment_author_email'] ) : '';
			$spam   = CU_Spam::instance();
			$reason = $this->spam_reason( self::FORM_COMMENTS );
			if ( '' !== $reason ) {
				$spam->note( 'blocked', $reason );
				if ( 'missing_fields' === $reason ) {
					return 'spam'; // rangé dans « Indésirables » : récupérable, jamais perdu
				}
				$this->finish( self::FORM_COMMENTS, 'spam', '', $email );
				return new WP_Error( 'cu_spam_comment', self::refusal_message( $reason ), 'rate_limit' === $reason ? 429 : 403 );
			}
			if ( '' !== $email && CU_Spam::email_checks_active() ) {
				$message = $spam->email_decision( $email );
				if ( '' !== $message ) {
					$this->finish( self::FORM_COMMENTS, 'validation_failed', '', $email );
					return new WP_Error( 'cu_spam_comment_email', $message, 400 );
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $approved;
	}

	/**
	 * Commentaire enregistré : journal selon son statut final.
	 *
	 * @param int        $comment_id
	 * @param int|string $approved   1, 0, 'spam' ou 'trash'.
	 */
	public function after_comment( $comment_id, $approved = 0 ) {
		if ( ! $this->comment_ctx ) {
			return;
		}
		try {
			$comment = get_comment( $comment_id );
			$email   = $comment ? (string) $comment->comment_author_email : '';
			if ( 'spam' === $approved || 'trash' === $approved ) {
				$other = '' === CU_Spam::instance()->blocked_reason() ? self::comment_agent( $comment_id, $approved ) : '';
				$this->finish( self::FORM_COMMENTS, 'spam', $other, $email );
			} else {
				$this->finish( self::FORM_COMMENTS, 'mail_sent', '', $email );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		$this->comment_ctx = false;
		CU_Spam::instance()->reset();
	}

	/** Mécanisme qui a classé le commentaire (Akismet, sinon la modération de WordPress). */
	private static function comment_agent( $comment_id, $approved ) {
		if ( 'spam' === $approved && ( get_comment_meta( $comment_id, 'akismet_result', true ) || ( class_exists( 'Akismet' ) && is_callable( array( 'Akismet', 'get_api_key' ) ) && '' !== (string) call_user_func( array( 'Akismet', 'get_api_key' ) ) ) ) ) {
			return 'akismet';
		}
		return 'wordpress';
	}

	// -------------------------------------------------------------------------
	// Inscription
	// -------------------------------------------------------------------------

	/** La requête vient-elle de wp-login.php pour l'action donnée ? */
	private static function on_login_page( array $actions ) {
		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] && in_array( $action, $actions, true ) && 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' );
	}

	/**
	 * @param WP_Error $errors
	 * @param string   $login
	 * @param string   $email
	 * @return WP_Error
	 */
	public function filter_registration( $errors, $login = '', $email = '' ) {
		if ( ! is_wp_error( $errors ) || is_user_logged_in() || ! self::on_login_page( array( 'register' ) ) || ! CU_Spam::form_protected( self::FORM_REGISTER ) ) {
			return $errors;
		}
		try {
			$this->logged = false;
			$spam         = CU_Spam::instance();
			$spam->reset();
			$reason = $this->spam_reason( self::FORM_REGISTER );
			if ( '' !== $reason ) {
				$spam->note( 'blocked', $reason );
				$errors->add( 'cu_spam', self::refusal_message( $reason ) );
				$this->finish( self::FORM_REGISTER, 'spam', '', (string) $email );
				return $errors;
			}
			if ( '' !== (string) $email && ! $errors->get_error_message( 'invalid_email' ) && CU_Spam::email_checks_active() ) {
				$message = $spam->email_decision( (string) $email );
				if ( '' !== $message ) {
					$errors->add( 'cu_spam_email', $message );
					$this->finish( self::FORM_REGISTER, 'validation_failed', '', (string) $email );
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $errors;
	}

	/** Compte créé : envoi accepté (ou suspect) journalisé. */
	public function after_registration( $user_id ) {
		if ( ! self::on_login_page( array( 'register' ) ) || ! CU_Spam::form_protected( self::FORM_REGISTER ) ) {
			return;
		}
		try {
			$user = get_userdata( $user_id );
			$this->finish( self::FORM_REGISTER, 'mail_sent', '', $user ? (string) $user->user_email : '' );
		} catch ( Throwable $e ) {
			unset( $e );
		}
		CU_Spam::instance()->reset();
	}

	// -------------------------------------------------------------------------
	// Mot de passe oublié
	// -------------------------------------------------------------------------

	/**
	 * Demande de réinitialisation (protège contre l'envoi massif d'e-mails).
	 *
	 * @param WP_Error      $errors
	 * @param WP_User|false $user_data
	 */
	public function filter_lostpassword( $errors, $user_data = false ) {
		if ( ! is_wp_error( $errors ) || is_user_logged_in() || ! self::on_login_page( array( 'lostpassword', 'retrievepassword' ) ) || ! CU_Spam::form_protected( self::FORM_LOSTPASS ) ) {
			return;
		}
		try {
			$this->logged = false;
			$spam         = CU_Spam::instance();
			$spam->reset();
			$email  = $user_data instanceof WP_User ? (string) $user_data->user_email : '';
			$reason = $this->spam_reason( self::FORM_LOSTPASS );
			if ( '' !== $reason ) {
				$spam->note( 'blocked', $reason );
				$errors->add( 'cu_spam', self::refusal_message( $reason ) );
				$this->finish( self::FORM_LOSTPASS, 'spam', '', $email );
				return;
			}
			// Demande acceptée par CyberPush (WordPress peut encore la refuser : compte inconnu).
			$this->finish( self::FORM_LOSTPASS, 'mail_sent', '', $email );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	// -------------------------------------------------------------------------
	// Outils
	// -------------------------------------------------------------------------

	private function posted() {
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return is_array( $post ) ? $post : array();
	}

	// -------------------------------------------------------------------------
	// Administration
	// -------------------------------------------------------------------------

	/** @return array<int, array{id:int, title:string, has_email:bool, edit_url:string, native:bool, note:string}> */
	public function forms() {
		$register_open = (bool) get_option( 'users_can_register' ) || ( is_multisite() && in_array( get_site_option( 'registration' ), array( 'user', 'all' ), true ) );
		return array(
			array(
				'id'        => self::FORM_COMMENTS,
				'title'     => __( 'Commentaires et avis (WordPress)', 'cyberpush-ultra' ),
				'has_email' => true,
				'edit_url'  => admin_url( 'options-discussion.php' ),
				'native'    => true,
				'note'      => self::rendered_recently( self::FORM_COMMENTS ) ? '' : __( 'Pas encore vu affiché avec la protection : un envoi sans les champs reste « suspect ».', 'cyberpush-ultra' ),
			),
			array(
				'id'        => self::FORM_REGISTER,
				'title'     => __( 'Inscription (wp-login.php)', 'cyberpush-ultra' ),
				'has_email' => true,
				'edit_url'  => admin_url( 'options-general.php' ),
				'native'    => true,
				'note'      => $register_open ? '' : __( 'Inscriptions fermées sur ce site.', 'cyberpush-ultra' ),
			),
			array(
				'id'        => self::FORM_LOSTPASS,
				'title'     => __( 'Mot de passe oublié (wp-login.php)', 'cyberpush-ultra' ),
				'has_email' => false,
				'edit_url'  => '',
				'native'    => true,
				'note'      => '',
			),
		);
	}
}

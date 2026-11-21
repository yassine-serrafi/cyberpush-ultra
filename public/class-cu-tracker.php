<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Partie publique : injecte le script de suivi côté visiteur (pages vues,
 * défilement, clics CTA, conversions déclarées, contacts WhatsApp / Contact
 * Form 7, erreurs JS), le point de collecte de secours admin-ajax et le
 * shortcode d'opposition [cyberpush_optout]. Toute la logique de collecte et
 * d'agrégation vit dans CU_Analytics.
 */
class CU_Tracker {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		// Opposition du visiteur : disponible même module coupé (le texte l'indique).
		add_shortcode( 'cyberpush_optout', array( $this, 'optout_shortcode' ) );
		if ( ! CU_Settings::get( 'analytics', 'enabled', true ) ) {
			return;
		}
		// Compatibilité WP Consent API : le tracker lit la catégorie « statistics ».
		add_filter( 'wp_consent_api_registered_' . CU_PLUGIN_BASENAME, '__return_true' );
		// WP Consent API enregistre/enfile son script tres tard. Executer ensuite
		// permet de declarer une dependance reelle uniquement quand il est present.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_beacon' ), PHP_INT_MAX - 50 );
		add_action( 'rest_api_init', array( 'CU_Analytics', 'register_public_routes' ) );
		// Collecte de secours quand l'API REST est fermée aux visiteurs (extension
		// de sécurité, hébergeur) : le tracker bascule seul sur admin-ajax.php.
		add_action( 'wp_ajax_nopriv_cu_collect', array( $this, 'ajax_collect' ) );
		add_action( 'wp_ajax_cu_collect', array( $this, 'ajax_collect' ) );
		// Les optimiseurs qui retardent le JavaScript jusqu'au premier geste du
		// visiteur (ou qui le fusionnent) empêchent de compter une visite sans
		// interaction : le tracker et sa configuration sont marqués comme exclus.
		add_filter( 'wp_script_attributes', array( $this, 'tracker_script_attributes' ) );
		add_filter( 'wp_inline_script_attributes', array( $this, 'tracker_script_attributes' ) );
		add_filter( 'perfmatters_delay_js_exclusions', array( $this, 'optimizer_exclusions' ) );
		// Journalisation serveur des crawlers IA (ils n'exécutent pas le JS → invisibles
		// du beacon). Priorité haute, early return si l'UA n'est pas un crawler IA.
		add_action( 'template_redirect', array( 'CU_Analytics', 'log_ai_crawler' ), 1 );
		// Contact Form 7 : chaque courriel réellement parti est confirmé côté serveur,
		// référence indépendante du navigateur (consentement, bloqueurs, JS coupé).
		if ( CU_Settings::get( 'analytics', 'track_cf7', true ) ) {
			add_action( 'wpcf7_mail_sent', array( 'CU_Analytics', 'record_cf7_server_sent' ) );
		}
	}

	public function enqueue_beacon() {
		if ( is_admin() || $this->is_current_user_excluded() ) {
			return;
		}

		$tracker_file    = CU_PLUGIN_DIR . 'public/js/cu-tracker.js';
		$tracker_version = @filemtime( $tracker_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mode            = CU_Analytics::privacy_mode();
		$consent_script  = wp_script_is( 'wp-consent-api', 'registered' );
		$consent_api     = $consent_script || function_exists( 'wp_has_consent' );
		$dependencies    = $consent_script ? array( 'wp-consent-api' ) : array();
		wp_enqueue_script( 'cyberpush-tracker', CU_PLUGIN_URL . 'public/js/cu-tracker.js', $dependencies, $tracker_version ? (string) $tracker_version : CU_VERSION, true );
		wp_localize_script(
			'cyberpush-tracker',
			'cuTrackerConfig',
			array(
				'restUrl'      => esc_url_raw( rest_url( CU_REST_NAMESPACE . '/collect' ) ),
				'ajaxUrl'      => esc_url_raw( add_query_arg( 'action', 'cu_collect', admin_url( 'admin-ajax.php' ) ) ),
				// exempt : mesure minimisée sans consentement ; consent : suivi continu
				// après l'accord (mesure anonyme sinon, si activée) ; standard : sans
				// restriction (responsabilité du site).
				'mode'         => $mode,
				// Signal de présence (s) tant que l'onglet est visible et que le visiteur
				// a agi depuis moins de 30 min : sans lui, un lecteur immobile sortirait
				// du « temps réel » (fenêtre de 5 min) en cours de lecture.
				'heartbeat'    => max( 0, min( 600, (int) apply_filters( 'cu_tracker_heartbeat_seconds', 120 ) ) ),
				'consentApi'   => (bool) $consent_api,
				// Modes exempté et consentement : cookies de bandeaux reconnus (Complianz,
				// CookieYes, Cookie Notice…), filtrables via `cu_analytics_consent_cookies`.
				'consentCookies' => 'standard' !== $mode ? CU_Analytics::consent_cookies() : array(),
				// Gestionnaire de consentement détecté côté serveur : sa présence suffit à
				// ne pas suivre en continu un visiteur qui n'a pas encore répondu.
				'cmp'          => '' !== CU_Analytics::consent_manager_detected(),
				// Mesure anonyme de secours (refus, absence de réponse, GPC) : sans
				// cookie, sans stockage, sans identifiant. Coupée : rien n'est mesuré.
				'anonFallback' => (bool) CU_Settings::get( 'analytics', 'anonymous_fallback', true ),
				// Hôtes du site : une arrivée depuis l'un d'eux n'est pas une entrée.
				'hosts'        => CU_Analytics::allowed_hosts(),
				// Signal Global Privacy Control respecté (hors mode standard, filtrable).
				'gpc'          => (bool) apply_filters( 'cu_analytics_honor_gpc', 'standard' !== $mode, $mode ),
				'allowParams'  => CU_Analytics::allowed_query_params(),
				'maxBatch'     => CU_Analytics::MAX_BATCH,
				'objectId'     => is_singular() ? get_queried_object_id() : 0,
				'objectType'   => is_singular() ? get_post_type() : ( is_search() ? 'search' : ( is_404() ? '404' : 'archive' ) ),
				'trackScroll'  => (bool) CU_Settings::get( 'analytics', 'track_scroll', true ),
				'trackCta'     => (bool) CU_Settings::get( 'analytics', 'track_cta', true ),
				// Formulaires : uniquement les conversions déclarées (data-cu-conversion).
				'trackForms'   => (bool) CU_Settings::get( 'analytics', 'track_forms', true ),
				'trackWoo'      => (bool) CU_Settings::get( 'analytics', 'track_woocommerce', true ),
				'trackWhatsapp' => (bool) CU_Settings::get( 'analytics', 'track_whatsapp', true ),
				'trackCf7'      => (bool) CU_Settings::get( 'analytics', 'track_cf7', true ),
				'trackOutbound' => (bool) CU_Settings::get( 'analytics', 'track_outbound', true ),
				'sessionMinutes' => max( 1, min( 1440, (int) CU_Settings::get( 'analytics', 'session_duration_minutes', 30 ) ) ),
			)
		);
	}

	/**
	 * [cyberpush_optout] : case à cocher accessible qui enregistre (ou retire)
	 * l'opposition du visiteur à la mesure d'audience dans son navigateur
	 * (localStorage cu_optout=1). Le tracker n'envoie alors plus rien et efface
	 * ses identifiants. À placer dans la politique de confidentialité.
	 *
	 * @return string
	 */
	public function optout_shortcode() {
		if ( ! CU_Settings::get( 'analytics', 'enabled', true ) ) {
			return '<p class="cu-optout">' . esc_html__( 'La mesure d’audience de ce site est désactivée : aucune donnée de navigation n’est collectée.', 'cyberpush-ultra' ) . '</p>';
		}
		$mode    = CU_Analytics::privacy_mode();
		$gpc     = (bool) apply_filters( 'cu_analytics_honor_gpc', 'standard' !== $mode, $mode );
		$msg_gpc = CU_Settings::get( 'analytics', 'anonymous_fallback', true )
			? __( 'Votre navigateur envoie le signal Global Privacy Control : aucun identifiant n’est utilisé ni stocké ; seules des pages vues anonymes sont comptées (sans cookie, sans stockage, sans adresse IP).', 'cyberpush-ultra' )
			: __( 'Votre navigateur envoie le signal Global Privacy Control : aucune mesure d’audience n’est effectuée.', 'cyberpush-ultra' );
		$this->enqueue_optout_script();
		static $instance = 0;
		$instance++;
		$id = 'cu-optout-' . $instance;
		return '<div class="cu-optout" data-cu-optout data-gpc="' . ( $gpc ? '1' : '0' ) . '"'
			. ' data-msg-on="' . esc_attr__( 'Vous ne vous êtes pas opposé(e) à la mesure d’audience sur ce navigateur.', 'cyberpush-ultra' ) . '"'
			. ' data-msg-off="' . esc_attr__( 'Opposition enregistrée : plus aucune donnée de navigation n’est envoyée depuis ce navigateur.', 'cyberpush-ultra' ) . '"'
			. ' data-msg-gpc="' . esc_attr( $msg_gpc ) . '"'
			. ' data-msg-storage="' . esc_attr__( 'Votre choix ne peut pas être enregistré : le stockage local de ce navigateur est désactivé.', 'cyberpush-ultra' ) . '">'
			. '<p><input type="checkbox" id="' . esc_attr( $id ) . '" class="cu-optout-input" checked disabled> '
			. '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Autoriser la mesure d’audience de ce site sur ce navigateur', 'cyberpush-ultra' ) . '</label></p>'
			. '<p class="cu-optout-status" role="status" aria-live="polite"></p>'
			. '<noscript><p>' . esc_html__( 'JavaScript est désactivé : aucune mesure d’audience n’est effectuée sur ce navigateur.', 'cyberpush-ultra' ) . '</p></noscript>'
			. '</div>';
	}

	/**
	 * Script du bouton d'opposition, autonome : il fonctionne même là où le
	 * tracker n'est pas chargé (administrateur exclu, page sans suivi).
	 */
	private function enqueue_optout_script() {
		if ( wp_script_is( 'cyberpush-optout', 'enqueued' ) ) {
			return;
		}
		wp_register_script( 'cyberpush-optout', false, array(), CU_VERSION, true );
		wp_add_inline_script(
			'cyberpush-optout',
			'(function(){"use strict";var K="cu_optout",KEYS=["cu_session_id","cu_session_id_ts","cu_landing_url","cu_visitor_seen","cu_visitor_seen_ts"];'
			. 'function read(){try{return window.localStorage.getItem(K)==="1";}catch(e){return null;}}'
			. 'function write(off){try{if(off){window.localStorage.setItem(K,"1");KEYS.forEach(function(k){window.localStorage.removeItem(k);});}else{window.localStorage.removeItem(K);}}catch(e){return false;}'
			. 'try{window.dispatchEvent(new Event("cu_optout_change"));}catch(e){}return true;}'
			. 'function init(){Array.prototype.forEach.call(document.querySelectorAll("[data-cu-optout]"),function(box){'
			. 'var input=box.querySelector(".cu-optout-input"),status=box.querySelector(".cu-optout-status");if(!input||!status)return;'
			. 'var gpc=box.getAttribute("data-gpc")==="1"&&navigator.globalPrivacyControl===true;'
			. 'function render(){var off=read();if(null===off){input.disabled=true;status.textContent=box.getAttribute("data-msg-storage");return;}'
			. 'input.disabled=gpc;input.checked=!off&&!gpc;status.textContent=box.getAttribute(gpc?"data-msg-gpc":(off?"data-msg-off":"data-msg-on"));}'
			. 'input.addEventListener("change",function(){write(!input.checked);render();});render();});}'
			. 'if("loading"===document.readyState){document.addEventListener("DOMContentLoaded",init);}else{init();}})();'
		);
		wp_enqueue_script( 'cyberpush-optout' );
	}

	/**
	 * Point de collecte admin-ajax : même traitement que la route REST /collect
	 * (contrôles d'origine, limite par IP, filtres anti-bot), corps JSON borné.
	 */
	public function ajax_collect() {
		$body    = (string) file_get_contents( 'php://input', false, null, 0, 32769 );
		$request = new WP_REST_Request( 'POST', '/' . CU_REST_NAMESPACE . '/collect' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		$response = rest_ensure_response( CU_Analytics::handle_collect( $request ) );
		wp_send_json( $response->get_data(), $response->get_status() );
	}

	/**
	 * Attributs reconnus par les optimiseurs pour ne pas retarder ni fusionner un
	 * script : WP Rocket (nowprocket), Cloudflare Rocket Loader (data-cfasync),
	 * LiteSpeed Cache (data-no-optimize, data-no-defer), Jetpack Boost.
	 * Appliqué au script du tracker et à sa configuration (wp_localize_script).
	 *
	 * @param array $attributes Attributs de la balise <script>.
	 * @return array
	 */
	public function tracker_script_attributes( $attributes ) {
		$id = isset( $attributes['id'] ) ? (string) $attributes['id'] : '';
		if ( 'cyberpush-tracker-js' !== $id && 'cyberpush-tracker-js-extra' !== $id ) {
			return $attributes;
		}
		$attributes['nowprocket']         = true;
		$attributes['data-cfasync']       = 'false';
		$attributes['data-no-optimize']   = '1';
		$attributes['data-no-defer']      = '1';
		$attributes['data-jetpack-boost'] = 'ignore';
		return $attributes;
	}

	/** Perfmatters : exclusions du « Delay JavaScript » par fragment d'URL ou de code. */
	public function optimizer_exclusions( $exclusions ) {
		$exclusions   = (array) $exclusions;
		$exclusions[] = 'cu-tracker.js';
		$exclusions[] = 'cuTrackerConfig';
		return $exclusions;
	}

	/**
	 * Vide le cache des pages des extensions de cache courantes : une page mise en
	 * cache avant l'activation ne contient pas le tracker, et ne compterait donc
	 * aucune visite jusqu'à son expiration. Chaque appel est sans effet si
	 * l'extension correspondante est absente.
	 */
	public static function purge_page_caches() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
		}
		do_action( 'litespeed_purge_all' );
		do_action( 'cache_enabler_clear_complete_cache' );
		do_action( 'breeze_clear_all_cache' );
		do_action( 'wphb_clear_page_cache' );
		do_action( 'rt_nginx_helper_purge_all' );
	}

	/**
	 * Exclut automatiquement les rôles configurés (ex: administrateur) du
	 * suivi Analytics, conformément aux réglages du module.
	 */
	public function is_current_user_excluded() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( CU_Settings::get( 'analytics', 'exclude_admins', true ) && current_user_can( 'manage_options' ) ) {
			return true;
		}
		$excluded_roles = (array) CU_Settings::get( 'analytics', 'excluded_roles', array() );
		$user           = wp_get_current_user();
		return (bool) array_intersect( $excluded_roles, (array) $user->roles );
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CU_Protection — Protection anti-capture (DISSUASION uniquement).
 * -----------------------------------------------------------------------------
 * ⚠️ Important : il est IMPOSSIBLE d'empêcher réellement une capture d'écran
 * depuis une page web. La capture est une fonction du système d'exploitation
 * (Impr écran, Outil Capture, Cmd+Shift+4, photo depuis un autre téléphone…) sur
 * laquelle aucun site n'a la main. Ce module applique seulement un ensemble de
 * mesures de DISSUASION qui arrêtent l'utilisateur occasionnel — pas un
 * utilisateur déterminé (désactiver JavaScript contourne tout).
 *
 * Niveau « équilibré » (aucune sous-option) :
 *   - voile noir quand la page perd le focus / change d'onglet ;
 *   - Impr écran → efface le presse-papier + petit avertissement ;
 *   - blocage de l'impression (Ctrl+P / Ctrl+S) + page blanche à l'impression ;
 *   - clic droit et glisser d'images désactivés.
 *
 * Front-end uniquement, JAMAIS pour les contributeurs connectés (éditeurs,
 * auteurs, administrateurs : ils travaillent normalement), ni sur les pages
 * « Mon compte » / commandes de WooCommerce (factures et reçus à imprimer).
 * Filtre `cu_protection_active` pour l'écarter d'autres pages.
 */
class CU_Protection {

	private static $instance = null;

	/** @var bool|null Décision mémorisée pour la requête courante. */
	private $active = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'wp_head', array( $this, 'print_css' ), 99 );
		add_action( 'wp_footer', array( $this, 'print_js' ), 99 );
	}

	/**
	 * Actif seulement si : réglage ON, côté public (pas wp-admin), visiteur sans
	 * droit d'édition (on n'entrave jamais le travail d'un éditeur sur le front)
	 * et hors des pages WooCommerce où l'impression est un besoin légitime.
	 */
	private function is_active() {
		if ( null === $this->active ) {
			$this->active = (bool) apply_filters( 'cu_protection_active', $this->compute_active() );
		}
		return $this->active;
	}

	private function compute_active() {
		if ( is_admin() ) {
			return false;
		}
		if ( ! CU_Settings::get( 'firewall', 'screenshot_protection_enabled', false ) ) {
			return false;
		}
		if ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) ) {
			return false;
		}
		// Factures, reçus, détail de commande : le client doit pouvoir imprimer.
		if ( $this->is_woocommerce_account_or_order_page() ) {
			return false;
		}
		// JAMAIS pour les robots des moteurs / réseaux sociaux : la protection
		// est de la dissuasion pour un HUMAIN, inutile pour un crawler — et on
		// écarte ainsi tout risque de gêner l'indexation ou l'aperçu de lien
		// (aucune différence de contenu servie aux bots = zéro cloaking).
		if ( $this->is_bot_request() ) {
			return false;
		}
		return true;
	}

	/** Pages « Mon compte » (et ses points de terminaison), commande reçue, paiement de commande. */
	private function is_woocommerce_account_or_order_page() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return true;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return true;
		}
		return false;
	}

	/** Détection légère de crawler par User-Agent (dissuasion → pas besoin de FCrDNS). */
	private function is_bot_request() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' === $ua ) {
			return false;
		}
		$tokens = array(
			'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandex', 'applebot',
			'google-inspectiontool', 'googleother', 'microsoftpreview', 'bingvideopreview',
			'yeti/', 'ads-naver', 'blueno',
			'petalbot', 'facebookexternalhit', 'facebot', 'twitterbot', 'linkedinbot', 'embedly',
			'pinterest', 'whatsapp', 'telegrambot', 'discordbot', 'redditbot', 'bot', 'crawler', 'spider',
		);
		foreach ( $tokens as $t ) {
			if ( false !== strpos( $ua, $t ) ) {
				return true;
			}
		}
		return false;
	}

	public function print_css() {
		if ( ! $this->is_active() ) {
			return;
		}
		// Page blanche à l'impression / export PDF + drag d'images désactivé + voile.
		echo '<style id="cu-nosc-css">'
			. '@media print{html body{display:none !important}}'
			. 'img{-webkit-user-drag:none;-khtml-user-drag:none;-moz-user-drag:none;-o-user-drag:none;user-drag:none}'
			. '#cu-nosc-veil{position:fixed;inset:0;z-index:2147483647;background:#f3f6fd;'
			. 'display:flex;align-items:center;justify-content:center;color:#4a5272;'
			. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
			. 'font-size:15px;letter-spacing:.3px;opacity:0;visibility:hidden;transition:opacity .12s}'
			. '#cu-nosc-veil.on{opacity:1;visibility:visible}'
			. '#cu-nosc-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);'
			. 'z-index:2147483647;background:#fff;color:#141a33;border:1px solid #e2e7f5;padding:10px 18px;'
			. 'border-radius:12px;font:600 13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
			. 'box-shadow:0 18px 40px -16px rgba(35,55,200,.35);opacity:0;transition:opacity .2s;pointer-events:none}'
			. '#cu-nosc-toast.on{opacity:1}'
			. '</style>';
	}

	public function print_js() {
		if ( ! $this->is_active() ) {
			return;
		}
		// wp_json_encode produit un littéral JS complet (guillemets inclus), sûr
		// quelle que soit la traduction (apostrophes/guillemets échappés correctement).
		// JSON_HEX_TAG : une traduction contenant « </script> » ne peut pas
		// refermer prématurément le bloc <script> inline.
		$veil_text = wp_json_encode( __( 'Contenu masqué', 'cyberpush-ultra' ), JSON_HEX_TAG | JSON_HEX_AMP );
		$toast_txt = wp_json_encode( __( 'Capture désactivée sur ce site.', 'cyberpush-ultra' ), JSON_HEX_TAG | JSON_HEX_AMP );
		?>
<script id="cu-nosc-js">
(function(){
	"use strict";
	var veil, toast, toastTimer;

	function el( id, cls ) {
		var e = document.getElementById( id );
		if ( ! e ) {
			e = document.createElement( "div" );
			e.id = id;
			if ( cls ) e.className = cls;
			document.body.appendChild( e );
		}
		return e;
	}

	function ensure() {
		if ( ! veil ) { veil = el( "cu-nosc-veil" ); veil.textContent = <?php echo $veil_text; // phpcs:ignore ?>; }
		if ( ! toast ) { toast = el( "cu-nosc-toast" ); }
	}

	function showVeil() { ensure(); veil.classList.add( "on" ); }
	function hideVeil() { if ( veil ) veil.classList.remove( "on" ); }

	function flashToast() {
		ensure();
		toast.textContent = <?php echo $toast_txt; // phpcs:ignore ?>;
		toast.classList.add( "on" );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () { toast.classList.remove( "on" ); }, 2200 );
	}

	// 1. Voile noir dès que la page perd le focus / change d'onglet — beaucoup
	//    d'outils de capture volent le focus, l'image ne montre alors que le voile.
	//    Exception : un clic dans une iframe DE LA PAGE (paiement Stripe/WooCommerce,
	//    vidéo YouTube, reCAPTCHA…) retire aussi le focus à la fenêtre. Sans ce
	//    contrôle, le voile masquerait le formulaire de carte pendant la saisie.
	//    activeElement n'est à jour qu'après l'évènement, d'où le report.
	window.addEventListener( "blur", function () {
		setTimeout( function () {
			var a = document.activeElement;
			if ( a && "IFRAME" === a.tagName ) return;
			showVeil();
		}, 0 );
	} );
	window.addEventListener( "focus", hideVeil );
	document.addEventListener( "visibilitychange", function () {
		if ( document.hidden ) showVeil(); else hideVeil();
	} );

	// 2. Impr écran → on écrase le presse-papier (le collage sera vide) + avertissement.
	function clearClipboard() {
		try {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( " " ).catch( function () {} );
			}
		} catch ( e ) {}
	}
	document.addEventListener( "keyup", function ( e ) {
		if ( e.key === "PrintScreen" || e.keyCode === 44 ) {
			clearClipboard();
			flashToast();
		}
	} );

	// 3. Blocage impression / sauvegarde (Ctrl/Cmd + P ou S).
	document.addEventListener( "keydown", function ( e ) {
		var k = ( e.key || "" ).toLowerCase();
		if ( ( e.ctrlKey || e.metaKey ) && ( k === "p" || k === "s" ) ) {
			e.preventDefault();
			e.stopPropagation();
			flashToast();
			return false;
		}
	} );
	window.addEventListener( "beforeprint", showVeil );
	window.addEventListener( "afterprint", hideVeil );

	// 4. Clic droit (« Enregistrer l'image sous… ») et glisser d'images désactivés.
	//    Les champs de saisie gardent leur menu (coller une adresse, un code promo…).
	document.addEventListener( "contextmenu", function ( e ) {
		var t = e.target;
		if ( t && t.closest && t.closest( "input, textarea, select, [contenteditable]" ) ) return;
		e.preventDefault();
		return false;
	} );
	document.addEventListener( "dragstart", function ( e ) {
		if ( e.target && e.target.tagName === "IMG" ) { e.preventDefault(); return false; }
	} );
})();
</script>
		<?php
	}
}

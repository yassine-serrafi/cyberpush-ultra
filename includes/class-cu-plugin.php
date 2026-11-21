<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Classe principale — orchestre le chargement des modules.
 *
 * Volontairement HORS du fichier principal : PHP déclare dès la compilation
 * les classes écrites au niveau supérieur d'un fichier. Placée dans
 * cyberpush-ultra.php, elle « existerait » avant la garde anti-conflit, qui
 * prendrait alors le plugin pour son propre doublon (et, avec OPcache, une
 * vraie seconde copie provoquerait une erreur fatale avant la garde).
 */
final class CU_Plugin {

	/** @var CU_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function run() {
		// Le pare-feu pose ses hooks ici ; il inspecte la requête sur `init`
		// (priorité 0 pour XML-RPC, 1 pour l'inspection générale), avant le
		// rendu du thème. Le code exécuté plus tôt par d'autres extensions et
		// les pages servies par un cache de page ne passent pas par lui.
		CU_Firewall::instance()->init();
		// Anti-spam des formulaires (crochets Contact Form 7 uniquement, aucun coût ailleurs).
		CU_Spam::instance()->init();
		CU_Audit::instance()->init();
		CU_Vulns::instance()->init();
		CU_Logins::instance()->init();
		CU_Analytics::instance()->init();
		CU_SEO::instance()->init();
		CU_IndexNow::instance()->init();
		CU_Protection::instance()->init();
		CU_Tracker::instance()->init();
		CU_Cron::instance()->init();
		CU_REST_Core::instance()->init();

		if ( is_admin() ) {
			CU_Admin::instance()->init();
		}

		// Depuis WordPress 6.7, les traductions doivent être chargées sur `init` ou
		// plus tard (sinon notice « _load_textdomain_just_in_time »). La migration
		// SQL utilise __() : elle s'exécute donc aussi sur `init`, en priorité 0,
		// avant l'inspection du pare-feu (init, priorité 1).
		add_action( 'init', array( $this, 'on_init' ), 0 );
		add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );
	}

	/**
	 * Texte proposé dans Réglages > Confidentialité > Guide de la politique de
	 * confidentialité : décrit factuellement ce que le plugin collecte selon les
	 * réglages actifs (l'administrateur reste responsable de sa politique).
	 */
	public function register_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$mode = CU_Settings::get( 'analytics', 'privacy_mode', 'exempt' );
		$p    = array();
		$p[]  = __( 'Mesure d’audience : ce site utilise CyberPush Ultra, hébergé sur notre propre serveur, pour produire des statistiques de fréquentation (pages vues, durée, défilement, clics sur les boutons, formulaires et liens de contact, type d’appareil, pays). Aucune donnée de mesure n’est vendue ni partagée à des fins publicitaires.', 'cyberpush-ultra' );
		$p[] = __( 'Données mesurées : pages consultées (sans leurs paramètres, hormis les paramètres de campagne utm_*), nom du site de provenance, type d’appareil, navigateur et système (le User-Agent complet n’est pas conservé), pays, durée de visite et profondeur de lecture, nombre de clics sur les boutons, clics vers WhatsApp (numéro du site uniquement), étapes des formulaires Contact Form 7 (jamais leur contenu) et erreurs techniques de la page.', 'cyberpush-ultra' );
		if ( 'exempt' === $mode ) {
			$p[] = __( 'La mesure est configurée pour être exemptée de consentement : elle sert uniquement à produire des statistiques de fréquentation pour notre propre usage. Votre adresse IP n’est jamais enregistrée ni transmise à un tiers ; elle sert seulement, de façon transitoire et non réversible, à limiter les abus. Le pays est déterminé sur notre propre serveur (base installée localement, ou information de notre hébergeur ou CDN) : votre adresse n’est pas conservée après ce calcul. Un identifiant aléatoire est stocké dans votre navigateur (stockage local, 13 mois au plus, non prolongé) et un identifiant de visite expire après 30 minutes d’inactivité. Les données sont conservées 25 mois au plus. Vous pouvez vous opposer à la mesure à tout moment grâce au module d’opposition présent sur la page de politique de confidentialité ; le signal Global Privacy Control de votre navigateur est également respecté.', 'cyberpush-ultra' );
		} elseif ( 'consent' === $mode ) {
			$p[] = __( 'Le suivi de visite (identifiant stocké dans votre navigateur) n’est activé qu’après votre consentement à la catégorie « statistiques ». Vous pouvez retirer votre consentement à tout moment depuis le gestionnaire de consentement du site : les identifiants stockés dans votre navigateur sont alors effacés.', 'cyberpush-ultra' );
		}
		if ( CU_Settings::get( 'analytics', 'anonymous_fallback', true ) ) {
			$p[] = __( 'Mesure anonyme : si vous refusez la mesure, ne répondez pas au bandeau de consentement, ou si votre navigateur envoie le signal Global Privacy Control, chaque page que vous consultez est comptée isolément, sans cookie, sans rien stocker dans votre navigateur, sans identifiant permettant de relier vos pages entre elles et sans enregistrer ni transmettre votre adresse IP (seul le pays en est déduit, sur notre propre serveur). Seuls sont enregistrés la page vue, le site de provenance pour une page d’arrivée, le type d’appareil et de navigateur et les interactions sur cette page. Le module d’opposition arrête toute mesure, y compris celle-ci.', 'cyberpush-ultra' );
		}
		if ( 'exempt' !== $mode && CU_Geo::remote_lookups_allowed() ) {
			$p[] = __( 'Géolocalisation : pour déterminer le pays et détecter les réseaux d’hébergement ou VPN, l’adresse IP est transmise au service ipwho.is et conservée chiffrée 24 heures au plus pendant ce traitement.', 'cyberpush-ultra' );
		}
		if ( 'standard' === $mode && CU_Settings::get( 'analytics', 'realtime_show_ip', false ) ) {
			$p[] = __( 'Visiteurs en ligne : l’adresse IP des visiteurs actifs est conservée chiffrée et affichée aux administrateurs, puis effacée une heure après la dernière activité.', 'cyberpush-ultra' );
		}
		$p[] = __( 'Sécurité : pour protéger le site contre les attaques, l’adresse IP, l’adresse de la page demandée, le navigateur (User-Agent) et la page de provenance des requêtes bloquées ou suspectes sont journalisés, ainsi que les tentatives de connexion échouées et les modifications sensibles réalisées par les administrateurs. Ces journaux sont conservés pour la durée configurée (90 jours par défaut) et ne sont utilisés qu’à des fins de sécurité.', 'cyberpush-ultra' );
		if ( CU_Settings::get( 'spam', 'enabled', true ) ) {
			$p[] = sprintf(
				/* translators: %d: durée de conservation du journal anti-spam, en jours. */
				__( 'Anti-spam des formulaires : à l’envoi d’un formulaire de contact, des contrôles techniques sont effectués localement (champ invisible, délai depuis l’affichage du formulaire, nombre d’envois récents depuis la même adresse IP, utilisée de façon transitoire et non réversible). Le domaine de l’adresse e-mail saisie (jamais l’adresse complète) peut être vérifié par une requête DNS et comparé à une liste publique de services d’adresses jetables. Le contenu des messages n’est jamais analysé. Un journal minimal est conservé %d jours : date, formulaire, décision et motif ; pour les envois bloqués ou suspects, le domaine de l’adresse et une empreinte non réversible de l’IP. Ni le message, ni l’adresse complète, ni l’IP n’y figurent.', 'cyberpush-ultra' ),
				(int) CU_Settings::get( 'spam', 'log_retention_days', 30 )
			);
		}
		$html = '';
		foreach ( $p as $paragraph ) {
			$html .= '<p class="privacy-policy-tutorial">' . esc_html( $paragraph ) . '</p>';
		}
		wp_add_privacy_policy_content( 'CyberPush Ultra', wp_kses_post( $html ) );
	}

	public function on_init() {
		load_plugin_textdomain( CU_TEXT_DOMAIN, false, dirname( CU_PLUGIN_BASENAME ) . '/languages' );

		// Vérifie que les tables SQL sont à jour (utile après une mise à jour du plugin).
		if ( get_option( CU_OPTION_DB_VERSION ) !== CU_DB_VERSION ) {
			CU_Activator::maybe_upgrade();
		}
	}
}

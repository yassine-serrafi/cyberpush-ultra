<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enregistre le menu d'administration (7 pages) et charge les
 * ressources (CSS/JS) uniquement sur les écrans du plugin.
 */
class CU_Admin {

	private static $instance = null;
	const CAPABILITY = 'manage_options';

	/**
	 * Identifiants d'écran réellement renvoyés par WordPress. Le préfixe des
	 * sous-pages dérive du titre de menu (traduit, ou renommé par une extension
	 * d'édition de menu) : un nom codé en dur ne chargerait alors aucun asset.
	 *
	 * @var string[]
	 */
	private $page_hooks = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_migration_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_proxy_notice' ) );
		add_action( 'admin_post_cu_trust_proxy', array( $this, 'handle_trust_proxy' ) );
		add_action( 'admin_init', array( $this, 'remember_admin_relay' ) );
	}

	/**
	 * Relais public non approuvé vu sur la requête d'un administrateur : le
	 * pare-feu ne le bannira jamais (il porte l'adresse de tous les visiteurs).
	 */
	public function remember_admin_relay() {
		if ( ! current_user_can( self::CAPABILITY ) || ! class_exists( 'CU_Firewall' ) ) {
			return;
		}
		$hint = CU_Geo::untrusted_proxy_hint();
		// Seulement un relais d'hébergement (adresse du serveur, chaîne Cloudflare) :
		// jamais un proxy d'entreprise ou une sortie VPN partagée.
		if ( $hint && 'untrusted' === $hint['type'] && CU_Geo::looks_like_host_relay() ) {
			CU_Firewall::remember_admin_relay( $hint['proxy'] );
		}
	}

	/**
	 * « Approuver ce relais » : ajoute aux proxys de confiance l'adresse du relais
	 * par lequel arrive CETTE requête d'administrateur (relue côté serveur, jamais
	 * prise dans le formulaire). Les visiteurs, leur pays et les blocages du
	 * pare-feu retrouvent alors leur véritable adresse.
	 */
	public function handle_trust_proxy() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'cyberpush-ultra' ), 403 );
		}
		check_admin_referer( 'cu_trust_proxy' );
		$hint     = CU_Geo::untrusted_proxy_hint();
		$back     = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=cyberpush-ultra' );
		$result   = 'none';
		if ( $hint && 'untrusted' === $hint['type'] && CU_Geo::is_public_ip( $hint['proxy'] ) ) {
			$list   = (array) CU_Settings::get( 'firewall', 'trusted_proxies', array() );
			$list[] = $hint['proxy'];
			CU_Settings::update_module( 'firewall', array( 'trusted_proxies' => array_values( array_unique( array_map( 'strval', $list ) ) ) ) );
			CU_Logger::log( 'system', 'info', sprintf( /* translators: %s: adresse IP du relais */ __( 'Relais approuvé comme proxy de confiance : %s', 'cyberpush-ultra' ), $hint['proxy'] ) );
			$result = 'ok';
		}
		wp_safe_redirect( add_query_arg( 'cu_proxy', $result, remove_query_arg( 'cu_proxy', $back ) ) );
		exit;
	}

	/**
	 * Classe « cu-admin-page » sur le <body> des écrans du plugin (même repère
	 * que enqueue_assets) : la feuille de style peint alors tout le canevas de
	 * l'administration avec le fond du plugin, sous la page et le pied WordPress.
	 *
	 * @param string $classes Classes du <body>, séparées par des espaces.
	 * @return string
	 */
	public function body_class( $classes ) {
		global $hook_suffix;
		if ( is_string( $hook_suffix ) && in_array( $hook_suffix, $this->page_hooks, true ) ) {
			$classes .= ' cu-admin-page';
		}
		return $classes;
	}

	/**
	 * Avertit lorsque le site semble derrière un proxy/CDN public non approuvé :
	 * sans réglage, le pare-feu voit tous les visiteurs avec l'IP du proxy.
	 * Désactivable via add_filter( 'cu_show_proxy_notice', '__return_false' ).
	 */
	public function maybe_show_proxy_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || ! CU_Settings::get( 'firewall', 'enabled', true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'cyberpush-ultra' ) || ! apply_filters( 'cu_show_proxy_notice', true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple retour d'affichage.
		$done = isset( $_GET['cu_proxy'] ) ? sanitize_key( wp_unslash( $_GET['cu_proxy'] ) ) : '';
		if ( 'ok' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Relais approuvé : les visiteurs, leur pays et le pare-feu utilisent maintenant leur véritable adresse IP.', 'cyberpush-ultra' ) . '</p></div>';
		}
		$hint = CU_Geo::untrusted_proxy_hint();
		if ( ! $hint ) {
			return;
		}
		$settings_url = admin_url( 'admin.php?page=cyberpush-ultra-settings#firewall' );
		$trust_form   = '';
		if ( 'untrusted' === $hint['type'] ) {
			$trust_form = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 10px">'
				. '<input type="hidden" name="action" value="cu_trust_proxy">'
				. wp_nonce_field( 'cu_trust_proxy', '_wpnonce', true, false )
				. '<button type="submit" class="button button-primary">'
				/* translators: %s: adresse IP du relais */
				. esc_html( sprintf( __( 'Approuver ce relais (%s)', 'cyberpush-ultra' ), $hint['proxy'] ) )
				. '</button> <span class="description">'
				. esc_html__( 'À faire uniquement si ce relais appartient à votre hébergeur ou à votre CDN.', 'cyberpush-ultra' )
				. '</span></form>';
		}
		if ( 'ambiguous' === $hint['type'] ) {
			$message = sprintf(
				/* translators: 1: IP du relais, 2: IP transmise par X-Real-IP. */
				__( 'Votre relais interne (%1$s) transmet X-Real-IP (%2$s) et un X-Forwarded-For différent. Si votre hébergeur ne remplit que X-Real-IP, sélectionnez cette source dans Réglages > Firewall > Adresse IP des visiteurs ; sinon, conservez le mode automatique.', 'cyberpush-ultra' ),
				$hint['proxy'],
				$hint['client']
			);
		} else {
			$message = sprintf(
				/* translators: 1: IP du proxy, 2: en-tête HTTP, 3: IP réelle du visiteur. */
				__( 'Vos requêtes arrivent depuis %1$s, qui transmet l\'IP réelle %3$s dans l\'en-tête %2$s. Tant que ce proxy n\'est pas approuvé, le pare-feu voit TOUS les visiteurs avec l\'IP %1$s (il refuse cependant de la bannir). Ajoutez les plages IP de votre CDN ou proxy (Sucuri, QUIC.cloud, BunnyCDN, Fastly, répartiteur de charge…) dans Réglages > Firewall > Proxys de confiance.', 'cyberpush-ultra' ),
				$hint['proxy'],
				$hint['header'],
				$hint['client']
			);
		}
		echo '<div class="notice notice-warning"><p><strong>CyberPush Ultra — '
			. esc_html__( 'configuration proxy / CDN à vérifier', 'cyberpush-ultra' ) . '</strong></p><p>'
			. esc_html( $message )
			. ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Ouvrir les réglages', 'cyberpush-ultra' ) . '</a></p>'
			. $trust_form . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- formulaire construit ci-dessus, chaque valeur échappée.
	}

	public function register_menu() {
		$this->page_hooks[] = add_menu_page(
			__( 'CyberPush Ultra', 'cyberpush-ultra' ),
			__( 'CyberPush Ultra', 'cyberpush-ultra' ),
			self::CAPABILITY,
			'cyberpush-ultra-dashboard',
			array( $this, 'render_dashboard_page' ),
			CU_Icons::menu_icon_data_uri(),
			26
		);

		// Centre de contrôle en tête ; Analytics garde son adresse (page=cyberpush-ultra).
		$this->page_hooks[] = add_submenu_page( 'cyberpush-ultra-dashboard', __( 'Dashboard — CyberPush Ultra', 'cyberpush-ultra' ), __( 'Dashboard', 'cyberpush-ultra' ), self::CAPABILITY, 'cyberpush-ultra-dashboard', array( $this, 'render_dashboard_page' ) );
		$this->page_hooks[] = add_submenu_page( 'cyberpush-ultra-dashboard', __( 'Analytics — CyberPush Ultra', 'cyberpush-ultra' ), __( 'Analytics', 'cyberpush-ultra' ), self::CAPABILITY, 'cyberpush-ultra', array( $this, 'render_analytics_page' ) );
		$this->page_hooks[] = add_submenu_page( 'cyberpush-ultra-dashboard', __( 'Firewall — CyberPush Ultra', 'cyberpush-ultra' ), __( 'Firewall', 'cyberpush-ultra' ), self::CAPABILITY, 'cyberpush-ultra-firewall', array( $this, 'render_firewall_page' ) );
		$this->page_hooks[] = add_submenu_page( 'cyberpush-ultra-dashboard', __( 'Spam — CyberPush Ultra', 'cyberpush-ultra' ), __( 'Spam', 'cyberpush-ultra' ), self::CAPABILITY, 'cyberpush-ultra-spam', array( $this, 'render_spam_page' ) );
		$this->page_hooks[] = add_submenu_page( 'cyberpush-ultra-dashboard', __( 'SEO — CyberPush Ultra', 'cyberpush-ultra' ), __( 'SEO', 'cyberpush-ultra' ), self::CAPABILITY, 'cyberpush-ultra-seo', array( $this, 'render_seo_page' ) );
		$this->page_hooks[] = add_submenu_page( 'cyberpush-ultra-dashboard', __( 'Index Now — CyberPush Ultra', 'cyberpush-ultra' ), __( 'Index Now', 'cyberpush-ultra' ), self::CAPABILITY, 'cyberpush-ultra-indexnow', array( $this, 'render_indexnow_page' ) );
		$this->page_hooks[] = add_submenu_page( 'cyberpush-ultra-dashboard', __( 'Réglages — CyberPush Ultra', 'cyberpush-ultra' ), __( 'Réglages', 'cyberpush-ultra' ), self::CAPABILITY, 'cyberpush-ultra-settings', array( $this, 'render_settings_page' ) );
		$this->page_hooks[] = add_submenu_page( 'cyberpush-ultra-dashboard', __( 'Logs — CyberPush Ultra', 'cyberpush-ultra' ), __( 'Logs', 'cyberpush-ultra' ), self::CAPABILITY, 'cyberpush-ultra-logs', array( $this, 'render_logs_page' ) );
		$this->page_hooks   = array_values( array_filter( $this->page_hooks ) );
	}

	private function current_screen_slug() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'cyberpush-admin', CU_PLUGIN_URL . 'assets/css/admin.css', array(), $this->asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'cyberpush-admin', CU_PLUGIN_URL . 'assets/js/cu-admin.js', array(), $this->asset_version( 'assets/js/cu-admin.js' ), true );

		wp_localize_script(
			'cyberpush-admin',
			'cuAdminConfig',
			array(
				'restUrl'     => esc_url_raw( rest_url( CU_REST_NAMESPACE ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'currentPage' => $this->current_screen_slug(),
				'showAnimations' => (bool) CU_Settings::get( 'dashboard', 'show_animations', true ),
				// Décalage du fuseau du site (secondes) : les dates SQL sont en heure
				// du site, le navigateur peut être dans un autre fuseau.
				'gmtOffset'   => (int) wp_timezone()->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) ),
				'i18n'        => array(
					'loading'    => __( 'Chargement…', 'cyberpush-ultra' ),
					'error'      => __( "Une erreur est survenue.", 'cyberpush-ultra' ),
					'saved'      => __( 'Enregistré.', 'cyberpush-ultra' ),
					'confirm'    => __( 'Confirmer cette action ?', 'cyberpush-ultra' ),
					'noData'     => __( 'Aucune donnée pour cette période.', 'cyberpush-ultra' ),
					'sessionExpired' => __( 'Session expirée : rechargez la page (F5) pour continuer.', 'cyberpush-ultra' ),
				),
			)
		);

		foreach ( array(
			'cyberpush-ultra-dashboard' => 'cu-dashboard',
			'cyberpush-ultra'          => 'cu-analytics',
			'cyberpush-ultra-firewall' => 'cu-firewall',
			'cyberpush-ultra-spam'     => 'cu-spam',
			'cyberpush-ultra-seo'      => 'cu-seo',
			'cyberpush-ultra-indexnow' => 'cu-indexnow',
			'cyberpush-ultra-settings' => 'cu-settings',
			'cyberpush-ultra-logs'     => 'cu-logs',
		) as $page_slug => $handle ) {
			if ( $this->current_screen_slug() === $page_slug || ( 'cyberpush-ultra' === $page_slug && '' === $this->current_screen_slug() ) ) {
				// La page Analytics « Observatory » dépend du moteur de visualisation cu-viz.
				// Handles préfixés « cyberpush- » : un handle générique (« cu-admin ») déjà
				// enregistré par une autre extension ferait ignorer le nôtre par WordPress.
				$deps = ( 'cu-analytics' === $handle ) ? array( 'cyberpush-admin', 'cyberpush-viz' ) : array( 'cyberpush-admin' );
				// Centre de contrôle : fond de carte du monde pour les pays.
				if ( 'cu-dashboard' === $handle ) {
					wp_register_script( 'cyberpush-world-map', CU_PLUGIN_URL . 'assets/js/cu-world-map.js', array(), $this->asset_version( 'assets/js/cu-world-map.js' ), true );
					$deps[] = 'cyberpush-world-map';
				}
				// Le graphique « Évolution des attaques » est un composant séparé.
				if ( 'cu-firewall' === $handle ) {
					wp_register_script( 'cyberpush-attack-chart', CU_PLUGIN_URL . 'assets/js/cu-attack-chart.js', array( 'cyberpush-admin' ), $this->asset_version( 'assets/js/cu-attack-chart.js' ), true );
					$deps[] = 'cyberpush-attack-chart';
				}
				// Même principe pour la courbe détaillée de la carte « En bref ».
				if ( 'cu-analytics' === $handle ) {
					wp_register_script( 'cyberpush-brief-chart', CU_PLUGIN_URL . 'assets/js/cu-brief-chart.js', array( 'cyberpush-admin' ), $this->asset_version( 'assets/js/cu-brief-chart.js' ), true );
					$deps[] = 'cyberpush-brief-chart';
					// Section « Contacts & leads » (WhatsApp + Contact Form 7).
					// Fond de carte du monde (données de jsVectorMap, MIT) pour la section Pays.
					wp_register_script( 'cyberpush-world-map', CU_PLUGIN_URL . 'assets/js/cu-world-map.js', array(), $this->asset_version( 'assets/js/cu-world-map.js' ), true );
					$deps[] = 'cyberpush-world-map';
					wp_register_script( 'cyberpush-contacts', CU_PLUGIN_URL . 'assets/js/cu-contacts.js', array( 'cyberpush-admin' ), $this->asset_version( 'assets/js/cu-contacts.js' ), true );
					$deps[] = 'cyberpush-contacts';
					wp_enqueue_style( 'cyberpush-contacts', CU_PLUGIN_URL . 'assets/css/cu-contacts.css', array( 'cyberpush-admin' ), $this->asset_version( 'assets/css/cu-contacts.css' ) );
				}
				// Audit SEO : jauge, orbes et courbe de tendance + leur feuille de style.
				if ( 'cu-seo' === $handle ) {
					wp_register_script( 'cyberpush-seo-viz', CU_PLUGIN_URL . 'assets/js/cu-seo-viz.js', array( 'cyberpush-admin' ), $this->asset_version( 'assets/js/cu-seo-viz.js' ), true );
					$deps[] = 'cyberpush-seo-viz';
					wp_enqueue_style( 'cyberpush-seo', CU_PLUGIN_URL . 'assets/css/cu-seo.css', array( 'cyberpush-admin' ), $this->asset_version( 'assets/css/cu-seo.css' ) );
				}
				wp_enqueue_script( 'cyberpush-' . substr( $handle, 3 ), CU_PLUGIN_URL . "assets/js/{$handle}.js", $deps, $this->asset_version( "assets/js/{$handle}.js" ), true );
			}
		}

		// Analytics « Observatory » : feuille de style dédiée + moteur de visualisation
		// (canvas rétina, animations, tooltips). Chargés uniquement sur cette page.
		if ( 'cyberpush-ultra' === $this->current_screen_slug() || '' === $this->current_screen_slug() ) {
			wp_enqueue_style( 'cyberpush-analytics', CU_PLUGIN_URL . 'assets/css/cu-analytics.css', array( 'cyberpush-admin' ), $this->asset_version( 'assets/css/cu-analytics.css' ) );
			wp_enqueue_script( 'cyberpush-viz', CU_PLUGIN_URL . 'assets/js/cu-viz.js', array(), $this->asset_version( 'assets/js/cu-viz.js' ), true );
		}

		// Le Radar et le Journal de sécurité sont des sections de la page Firewall :
		// scripts dédiés chargés en plus.
		if ( 'cyberpush-ultra-firewall' === $this->current_screen_slug() ) {
			wp_enqueue_script( 'cyberpush-radar', CU_PLUGIN_URL . 'assets/js/cu-radar.js', array( 'cyberpush-admin', 'cyberpush-firewall' ), $this->asset_version( 'assets/js/cu-radar.js' ), true );
			wp_enqueue_script( 'cyberpush-audit', CU_PLUGIN_URL . 'assets/js/cu-audit.js', array( 'cyberpush-admin', 'cyberpush-firewall' ), $this->asset_version( 'assets/js/cu-audit.js' ), true );
			// Section « Vulnérabilités & versions ».
			wp_enqueue_script( 'cyberpush-vulns', CU_PLUGIN_URL . 'assets/js/cu-vulns.js', array( 'cyberpush-admin' ), $this->asset_version( 'assets/js/cu-vulns.js' ), true );
			// Section « Protection des connexions ».
			wp_enqueue_script( 'cyberpush-logins', CU_PLUGIN_URL . 'assets/js/cu-logins.js', array( 'cyberpush-admin' ), $this->asset_version( 'assets/js/cu-logins.js' ), true );
		}
	}

	/**
	 * Version d'un asset basée sur sa date de modification (cache-busting
	 * automatique : dès qu'on édite un JS/CSS, le navigateur recharge la nouvelle
	 * version au lieu de servir l'ancienne depuis son cache). Repli sur
	 * CU_VERSION si le fichier est introuvable.
	 */
	private function asset_version( $relative_path ) {
		$file = CU_PLUGIN_DIR . $relative_path;
		$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $mtime ? (string) $mtime : CU_VERSION;
	}

	public function maybe_show_migration_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'cyberpush-ultra' ) ) {
			return;
		}
		// Pages du plugin seulement : tables ET colonnes indispensables (une colonne
		// manquante cassait les rapports sans aucun avertissement).
		if ( CU_DB::tables_exist() && CU_DB::analytics_schema_ready() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Migration SQL requise — rendez-vous dans Réglages > Base de données pour installer les tables du plugin.', 'cyberpush-ultra' ) . '</p></div>';
	}

	/**
	 * Rend une page du plugin : la barre supérieure (header), la vue demandée,
	 * puis le footer qui referme le conteneur .cu-wrap. Le contenu de la vue est
	 * ainsi imbriqué dans .cu-wrap et hérite bien des variables CSS du thème.
	 *
	 * @param string $active Onglet actif (analytics|firewall|seo|settings|logs).
	 * @param string $view   Nom du fichier de vue (sans extension) dans admin/views/.
	 */
	private function render_page( $active, $view ) {
		include CU_PLUGIN_DIR . 'admin/partials/header.php';
		include CU_PLUGIN_DIR . 'admin/views/' . $view . '.php';
		include CU_PLUGIN_DIR . 'admin/partials/footer.php';
	}

	public function render_dashboard_page() {
		$this->render_page( 'dashboard', 'dashboard' );
	}

	public function render_analytics_page() {
		$this->render_page( 'analytics', 'analytics' );
	}

	public function render_firewall_page() {
		$this->render_page( 'firewall', 'firewall' );
	}

	public function render_spam_page() {
		$this->render_page( 'spam', 'spam' );
	}

	public function render_seo_page() {
		$this->render_page( 'seo', 'seo' );
	}

	public function render_indexnow_page() {
		$this->render_page( 'indexnow', 'indexnow' );
	}

	public function render_settings_page() {
		$this->render_page( 'settings', 'settings' );
	}

	public function render_logs_page() {
		$this->render_page( 'logs', 'logs' );
	}
}

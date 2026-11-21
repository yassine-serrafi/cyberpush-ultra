<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$s = CU_Settings::all();

/**
 * Champ secret (clé API) : la valeur enregistrée n'est jamais renvoyée au
 * navigateur. Champ vide = conserver la clé actuelle ; « Effacer » la supprime.
 */
$cu_secret_field = static function ( $data_key, $stored, $placeholder = '' ) {
	$stored = (string) $stored;
	$hint   = '' !== $stored
		/* translators: %s: masked key suffix */
		? sprintf( __( 'Clé enregistrée (%s) — laisser vide pour la conserver', 'cyberpush-ultra' ), CU_Settings::mask_secret( $stored ) )
		: $placeholder;
	echo '<div class="cu-secret-field" style="display:flex;gap:8px;align-items:center;">';
	echo '<input type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore class="cu-input" data-secret="1" data-key="' . esc_attr( $data_key ) . '" value="" placeholder="' . esc_attr( $hint ) . '">';
	if ( '' !== $stored ) {
		echo '<button type="button" class="cu-btn" data-secret-clear="' . esc_attr( $data_key ) . '">' . esc_html__( 'Effacer', 'cyberpush-ultra' ) . '</button>';
	}
	echo '</div>';
};
$cu_ip_hint     = CU_Geo::untrusted_proxy_hint();
$cu_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
$cu_fwd_headers = array();
foreach ( array( 'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For', 'HTTP_X_REAL_IP' => 'X-Real-IP', 'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP' ) as $cu_h => $cu_label ) {
	if ( ! empty( $_SERVER[ $cu_h ] ) ) {
		$cu_fwd_headers[] = $cu_label . ' : ' . sanitize_text_field( wp_unslash( $_SERVER[ $cu_h ] ) );
	}
}
?>
<div class="cu-page" id="cu-settings-page">
	<div class="cu-page-title">
		<div>
			<h1><?php echo CU_Icons::get( 'gear' ); ?> <?php esc_html_e( 'Réglages', 'cyberpush-ultra' ); ?></h1>
			<p><?php esc_html_e( 'Un seul endroit pour configurer Analytics, Firewall, SEO, IA et toute l’intégration WordPress/WooCommerce.', 'cyberpush-ultra' ); ?></p>
		</div>
		<div class="cu-toolbar">
			<?php $cu_db_ok = CU_DB::tables_exist() && CU_DB::analytics_schema_ready(); ?>
			<span class="cu-pill <?php echo $cu_db_ok ? 'cu-pill-success' : 'cu-pill-danger'; ?>">
				<?php echo $cu_db_ok ? esc_html__( 'Base de données OK', 'cyberpush-ultra' ) : esc_html__( 'Migration SQL requise', 'cyberpush-ultra' ); ?>
			</span>
		</div>
	</div>

	<div class="cu-settings-tabs" id="cu-settings-tabs">
		<button class="cu-settings-tab is-active" data-tab="dashboard"><?php esc_html_e( 'Tableau de bord', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="analytics"><?php esc_html_e( 'Analytics', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="firewall"><?php esc_html_e( 'Firewall', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="seo"><?php esc_html_e( 'SEO', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="ai"><?php esc_html_e( 'Intelligence Artificielle', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="wordpress"><?php esc_html_e( 'WordPress', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="performance"><?php esc_html_e( 'Performance', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="system"><?php esc_html_e( 'Base de données', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="security"><?php esc_html_e( 'Sécurité', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="alerts"><?php esc_html_e( 'Alertes e-mail', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="backup"><?php esc_html_e( 'Sauvegarde & Maintenance', 'cyberpush-ultra' ); ?></button>
		<button class="cu-settings-tab" data-tab="developer"><?php esc_html_e( 'Développeur', 'cyberpush-ultra' ); ?></button>
	</div>

	<!-- Tableau de bord -->
	<div class="cu-settings-panel is-active" data-panel="dashboard">
		<div class="cu-card">
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Animations d’interface', 'cyberpush-ultra' ); ?></strong></div>
				<label class="cu-switch"><input type="checkbox" data-key="show_animations" <?php checked( $s['dashboard']['show_animations'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Lignes par tableau', 'cyberpush-ultra' ); ?></label>
				<input type="number" class="cu-input" data-key="rows_per_table" value="<?php echo esc_attr( $s['dashboard']['rows_per_table'] ); ?>" min="5" max="100">
			</div>
			<button class="cu-btn cu-btn-primary" data-save="dashboard"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- Analytics -->
	<div class="cu-settings-panel" data-panel="analytics">
		<div class="cu-card">
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Module Analytics actif', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Désactiver arrête totalement le suivi et l’affichage du tableau de bord.', 'cyberpush-ultra' ); ?></span></div>
				<label class="cu-switch"><input type="checkbox" data-key="enabled" <?php checked( $s['analytics']['enabled'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-section-title"><?php esc_html_e( 'Confidentialité et consentement', 'cyberpush-ultra' ); ?></div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Mode de collecte', 'cyberpush-ultra' ); ?></label>
				<select class="cu-select" data-key="privacy_mode">
					<option value="exempt" <?php selected( $s['analytics']['privacy_mode'], 'exempt' ); ?>><?php esc_html_e( 'Mesure d’audience minimisée, sans consentement (recommandé en France / UE)', 'cyberpush-ultra' ); ?></option>
					<option value="consent" <?php selected( $s['analytics']['privacy_mode'], 'consent' ); ?>><?php esc_html_e( 'Suivi continu après consentement (mesure anonyme sans accord, si activée)', 'cyberpush-ultra' ); ?></option>
					<option value="standard" <?php selected( $s['analytics']['privacy_mode'], 'standard' ); ?>><?php esc_html_e( 'Standard, sans restriction (hors UE ou base légale gérée par le site)', 'cyberpush-ultra' ); ?></option>
				</select>
				<span class="cu-field-hint"><?php esc_html_e( 'Minimisée : aucune IP affichée ni transmise à un tiers, URL sans paramètres (sauf utm_*), référent réduit au domaine, termes de recherche non conservés, opposition possible (shortcode [cyberpush_optout]), conservation limitée à 25 mois. Consentement : le suivi continu (identifiant de visite) ne démarre qu’après l’accord du visiteur (WP Consent API, Complianz, CookieYes, Cookie Notice, Google Consent Mode, ou window.cuAnalytics.consent(true)). Mentionnez la mesure d’audience dans votre politique de confidentialité.', 'cyberpush-ultra' ); ?></span>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Mesure anonyme en cas de refus ou d’absence de réponse', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Visiteur qui refuse, bandeau sans réponse, ou signal GPC : chaque page vue est comptée sans cookie, sans stockage dans le navigateur, sans identifiant persistant ; l’adresse IP n’est ni enregistrée ni transmise (seul le pays en est déduit, en mémoire). Les visites, pages vues, sources, pays et appareils restent complets ; rebond, durée, pages par visite, parcours et fidélité ne sont calculés que sur les visites suivies en continu, avec leur part affichée. Désactivée : ces visiteurs ne sont pas mesurés du tout. L’opposition ([cyberpush_optout]) arrête toujours toute mesure.', 'cyberpush-ultra' ); ?></span></div>
				<label class="cu-switch"><input type="checkbox" data-key="anonymous_fallback" <?php checked( ! empty( $s['analytics']['anonymous_fallback'] ) ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field">
					<label><?php esc_html_e( 'Durée du cache des rapports (minutes)', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="cache_duration_minutes" value="<?php echo esc_attr( $s['analytics']['cache_duration_minutes'] ); ?>" min="0">
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Durée de session (minutes)', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="session_duration_minutes" value="<?php echo esc_attr( $s['analytics']['session_duration_minutes'] ); ?>" min="1">
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Rafraîchissement temps réel (secondes)', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="realtime_refresh_seconds" value="<?php echo esc_attr( $s['analytics']['realtime_refresh_seconds'] ); ?>" min="5">
				</div>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Exclure les robots', 'cyberpush-ultra' ); ?></strong></div>
				<label class="cu-switch"><input type="checkbox" data-key="exclude_bots" <?php checked( $s['analytics']['exclude_bots'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Exclure les administrateurs', 'cyberpush-ultra' ); ?></strong></div>
				<label class="cu-switch"><input type="checkbox" data-key="exclude_admins" <?php checked( $s['analytics']['exclude_admins'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Afficher l’adresse IP en temps réel', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Mode « Standard » uniquement (ignoré en mode minimisé). L’IP des visiteurs en ligne est conservée chiffrée, puis effacée 1 h après leur dernière activité ; les rapports restent pseudonymisés. Donnée personnelle : à mentionner dans votre politique de confidentialité.', 'cyberpush-ultra' ); ?></span></div>
				<label class="cu-switch"><input type="checkbox" data-key="realtime_show_ip" <?php checked( $s['analytics']['realtime_show_ip'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Pays exclus (codes ISO2 séparés par des virgules)', 'cyberpush-ultra' ); ?></label>
				<input type="text" class="cu-input" data-key="excluded_countries" data-type="csv" value="<?php echo esc_attr( implode( ', ', $s['analytics']['excluded_countries'] ) ); ?>" placeholder="MA, US">
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Adresses IP exclues (IP ou plage CIDR, une par ligne)', 'cyberpush-ultra' ); ?></label>
				<textarea class="cu-textarea" data-key="excluded_ips" data-type="lines" rows="3"><?php echo esc_textarea( implode( "\n", $s['analytics']['excluded_ips'] ) ); ?></textarea>
			</div>
			<div class="cu-section-title"><?php esc_html_e( 'Suivi des interactions', 'cyberpush-ultra' ); ?></div>
			<?php
			$toggles = array(
				'track_cta'             => __( 'Clics sur les boutons / CTA (nombre de clics, sans libellé ni lien)', 'cyberpush-ultra' ),
				'track_outbound'        => __( 'Clics sortants : liens vers d’autres sites, téléchargements, appels (tel:) et e-mails (mailto:) — lien du site sans paramètres, domaine seulement pour un e-mail', 'cyberpush-ultra' ),
				'track_scroll'          => __( 'Profondeur de lecture (scroll)', 'cyberpush-ultra' ),
				'track_forms'           => __( 'Conversions des formulaires marqués data-cu-conversion', 'cyberpush-ultra' ),
				'track_woocommerce'     => __( 'Événements WooCommerce', 'cyberpush-ultra' ),
			);
			foreach ( $toggles as $key => $label ) :
				?>
				<div class="cu-field-row">
					<div class="cu-field-row-text"><strong><?php echo esc_html( $label ); ?></strong></div>
					<label class="cu-switch"><input type="checkbox" data-key="<?php echo esc_attr( $key ); ?>" <?php checked( $s['analytics'][ $key ] ); ?>><span class="slider"></span></label>
				</div>
			<?php endforeach; ?>
			<div class="cu-section-title"><?php esc_html_e( 'Contacts & leads', 'cyberpush-ultra' ); ?></div>
			<?php
			$contact_toggles = array(
				'track_whatsapp'      => array( __( 'Clics vers WhatsApp', 'cyberpush-ultra' ), __( 'Liens wa.me / api.whatsapp.com, boutons Joinchat, Click to Chat, NinjaTeam, QuadLayers, Chaty… Seul le numéro de destination (le vôtre) est enregistré, jamais le message.', 'cyberpush-ultra' ) ),
				'track_cf7'           => array( __( 'Formulaires Contact Form 7', 'cyberpush-ultra' ), __( 'Formulaire vu, commencé, envoyé, refusé (champ invalide, spam, échec d’envoi) ou abandonné, et envois confirmés par le serveur. Aucun contenu saisi n’est lu.', 'cyberpush-ultra' ) ),
				'whatsapp_conversion' => array( __( 'Compter un clic WhatsApp comme conversion déclarée', 'cyberpush-ultra' ), __( 'Un clic est une intention de contact, pas un message reçu : il est compté parmi les conversions « déclarées » (vues par le navigateur), jamais parmi les conversions confirmées.', 'cyberpush-ultra' ) ),
				'cf7_conversion'      => array( __( 'Compter un message Contact Form 7 envoyé comme conversion', 'cyberpush-ultra' ), __( 'Envois réussis vus par le navigateur (conversion déclarée). Les envois confirmés par le serveur (e-mail parti) sont comptés à part, sans double comptage.', 'cyberpush-ultra' ) ),
			);
			foreach ( $contact_toggles as $key => $texts ) :
				?>
				<div class="cu-field-row">
					<div class="cu-field-row-text"><strong><?php echo esc_html( $texts[0] ); ?></strong><span><?php echo esc_html( $texts[1] ); ?></span></div>
					<label class="cu-switch"><input type="checkbox" data-key="<?php echo esc_attr( $key ); ?>" <?php checked( $s['analytics'][ $key ] ); ?>><span class="slider"></span></label>
				</div>
			<?php endforeach; ?>
			<div class="cu-section-title"><?php esc_html_e( 'Géolocalisation', 'cyberpush-ultra' ); ?></div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Détecter le pays des visiteurs', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Avec ou sans consentement : l’adresse IP est comparée en mémoire à une base installée sur votre serveur, puis oubliée — seul le code pays est enregistré. Désactivée : seul le pays transmis par votre CDN (Cloudflare) ou le serveur (mod_geoip) est utilisé.', 'cyberpush-ultra' ); ?></span></div>
				<label class="cu-switch"><input type="checkbox" data-key="geo_lookup_enabled" <?php checked( $s['analytics']['geo_lookup_enabled'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Fournisseur de géolocalisation', 'cyberpush-ultra' ); ?></label>
				<select class="cu-select" data-key="geo_provider">
					<option value="local" <?php selected( $s['analytics']['geo_provider'], 'local' ); ?>><?php esc_html_e( 'Base locale DB-IP (recommandé : aucune IP transmise, mise à jour mensuelle)', 'cyberpush-ultra' ); ?></option>
					<option value="none" <?php selected( $s['analytics']['geo_provider'], 'none' ); ?>><?php esc_html_e( 'Aucun (pays du CDN uniquement)', 'cyberpush-ultra' ); ?></option>
					<option value="woocommerce" <?php selected( $s['analytics']['geo_provider'], 'woocommerce' ); ?>><?php esc_html_e( 'WooCommerce (base locale, sans API)', 'cyberpush-ultra' ); ?></option>
					<option value="maxmind" <?php selected( $s['analytics']['geo_provider'], 'maxmind' ); ?>><?php esc_html_e( 'MaxMind GeoLite2 (fichier local)', 'cyberpush-ultra' ); ?></option>
					<option value="free" <?php selected( in_array( $s['analytics']['geo_provider'], array( 'free', 'api' ), true ) ); ?>><?php esc_html_e( 'ipwho.is — service externe HTTPS (transmet l’IP à un tiers)', 'cyberpush-ultra' ); ?></option>
				</select>
				<span class="cu-field-hint"><?php esc_html_e( 'Seul « ipwho.is » envoie des adresses IP à un tiers (géolocalisation, et détection datacenter/VPN pour Analytics et le Radar du pare-feu). En mode de collecte minimisé et pour les pages anonymes, Analytics ne l’utilise jamais : le pays vient alors de la base locale.', 'cyberpush-ultra' ); ?></span>
				<?php
				$cu_geo_db = CU_Geo::local_db_status();
				if ( $cu_geo_db['available'] && $cu_geo_db['custom'] ) {
					$cu_geo_text = __( 'Base locale : fichier fourni par le site (filtre cu_geo_local_database_path).', 'cyberpush-ultra' );
				} elseif ( $cu_geo_db['available'] ) {
					/* translators: 1: édition AAAA-MM, 2: date */
					$cu_geo_text = sprintf( __( 'Base locale installée : édition %1$s, téléchargée le %2$s.', 'cyberpush-ultra' ), '' !== $cu_geo_db['edition'] ? $cu_geo_db['edition'] : '—', $cu_geo_db['updated_at'] ? wp_date( get_option( 'date_format' ), $cu_geo_db['updated_at'] ) : '—' );
				} elseif ( '' !== $cu_geo_db['error'] ) {
					/* translators: %s: cause de l'échec */
					$cu_geo_text = sprintf( __( 'Base locale absente — dernier téléchargement en échec : %s En attendant, le pays est estimé d’après le fuseau horaire du navigateur.', 'cyberpush-ultra' ), $cu_geo_db['error'] );
				} else {
					$cu_geo_text = __( 'Base locale pas encore téléchargée : le téléchargement se fait seul en arrière-plan (environ 4 Mo). En attendant, le pays est estimé d’après le fuseau horaire du navigateur.', 'cyberpush-ultra' );
				}
				?>
				<span class="cu-field-hint"><?php echo esc_html( $cu_geo_text ); ?> <button type="button" class="cu-btn" data-maintenance="geo_update"><?php esc_html_e( 'Télécharger / mettre à jour maintenant', 'cyberpush-ultra' ); ?></button></span>
				<span class="cu-field-hint"><?php echo wp_kses( __( 'Données de pays : <a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">IP Geolocation by DB-IP</a> (licence CC BY 4.0).', 'cyberpush-ultra' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></span>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Exclure datacenters et proxy/VPN du trafic fiable', 'cyberpush-ultra' ); ?></strong><span>
					<?php
					if ( CU_Geo::remote_lookups_allowed() ) {
						esc_html_e( 'Actif : les nouvelles IP sont analysées en différé via ipwho.is.', 'cyberpush-ultra' );
					} else {
						esc_html_e( 'Inactif tant que la géolocalisation n’utilise pas ipwho.is : sans ce service, le plugin ne peut pas reconnaître un datacenter ou un VPN.', 'cyberpush-ultra' );
					}
					?>
				</span></div>
				<label class="cu-switch"><input type="checkbox" data-key="filter_datacenter" <?php checked( $s['analytics']['filter_datacenter'] ); ?>><span class="slider"></span></label>
			</div>
			<button class="cu-btn cu-btn-primary" data-save="analytics"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>
			<button class="cu-btn cu-btn-danger" data-erase="analytics"><?php echo CU_Icons::get( 'trash' ); ?> <?php esc_html_e( 'Réinitialiser les données Analytics', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- Firewall -->
	<div class="cu-settings-panel" data-panel="firewall">
		<div class="cu-card">
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Firewall actif', 'cyberpush-ultra' ); ?></strong></div>
				<label class="cu-switch"><input type="checkbox" data-key="enabled" <?php checked( $s['firewall']['enabled'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Mode de protection', 'cyberpush-ultra' ); ?></label>
				<select class="cu-select" data-key="mode">
					<option value="normal" <?php selected( $s['firewall']['mode'], 'normal' ); ?>><?php esc_html_e( 'Normal', 'cyberpush-ultra' ); ?></option>
					<option value="strict" <?php selected( $s['firewall']['mode'], 'strict' ); ?>><?php esc_html_e( 'Strict', 'cyberpush-ultra' ); ?></option>
					<option value="maintenance" <?php selected( $s['firewall']['mode'], 'maintenance' ); ?>><?php esc_html_e( 'Maintenance (bloque tout sauf admin)', 'cyberpush-ultra' ); ?></option>
				</select>
				<span class="cu-field-hint"><?php esc_html_e( 'Récupération : si vous êtes bloqué hors de votre site, ajoutez define( \'CU_FIREWALL_DISABLED\', true ); dans wp-config.php, connectez-vous, corrigez la liste de blocage, puis retirez la ligne.', 'cyberpush-ultra' ); ?></span>
			</div>

			<div class="cu-section-title"><?php esc_html_e( 'Adresse IP des visiteurs (proxy / CDN)', 'cyberpush-ultra' ); ?></div>
			<p class="cu-field-row-text"><span>
				<?php
				printf(
					/* translators: 1: IP resolved for the admin, 2: REMOTE_ADDR, 3: forwarding headers */
					esc_html__( 'Votre IP vue par le pare-feu : %1$s (connexion directe : %2$s ; en-têtes reçus : %3$s).', 'cyberpush-ultra' ),
					'<strong>' . esc_html( CU_Geo::client_ip() ) . '</strong>',
					esc_html( $cu_remote_addr ),
					esc_html( $cu_fwd_headers ? implode( ' | ', $cu_fwd_headers ) : __( 'aucun', 'cyberpush-ultra' ) )
				);
				?>
			</span></p>
			<?php if ( $cu_ip_hint ) : ?>
				<p class="cu-field-row-text"><span style="color:var(--cu-warning);">
					<?php
					if ( 'ambiguous' === $cu_ip_hint['type'] ) {
						/* translators: %s: IP from X-Real-IP */
						printf( esc_html__( 'Votre relais transmet X-Real-IP (%s) mais aussi un X-Forwarded-For différent. Si votre hébergeur ne remplit que X-Real-IP, choisissez cette source ci-dessous.', 'cyberpush-ultra' ), esc_html( $cu_ip_hint['client'] ) );
					} else {
						/* translators: 1: proxy IP, 2: header, 3: client IP */
						printf( esc_html__( 'Vos requêtes semblent traverser un proxy public (%1$s) qui transmet votre IP réelle (%3$s) dans %2$s. Ajoutez ses plages IP dans « Proxys de confiance », sinon tous les visiteurs partagent l’IP du proxy.', 'cyberpush-ultra' ), esc_html( $cu_ip_hint['proxy'] ), esc_html( $cu_ip_hint['header'] ), esc_html( $cu_ip_hint['client'] ) );
					}
					?>
				</span></p>
			<?php endif; ?>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field">
					<label><?php esc_html_e( 'Source de l’IP visiteur', 'cyberpush-ultra' ); ?></label>
					<select class="cu-select" data-key="ip_source">
						<option value="auto" <?php selected( $s['firewall']['ip_source'], 'auto' ); ?>><?php esc_html_e( 'Automatique (recommandé)', 'cyberpush-ultra' ); ?></option>
						<option value="remote_addr" <?php selected( $s['firewall']['ip_source'], 'remote_addr' ); ?>><?php esc_html_e( 'Connexion directe (REMOTE_ADDR, aucun proxy)', 'cyberpush-ultra' ); ?></option>
						<option value="x_forwarded_for" <?php selected( $s['firewall']['ip_source'], 'x_forwarded_for' ); ?>>X-Forwarded-For</option>
						<option value="x_real_ip" <?php selected( $s['firewall']['ip_source'], 'x_real_ip' ); ?>>X-Real-IP</option>
						<option value="cf_connecting_ip" <?php selected( $s['firewall']['ip_source'], 'cf_connecting_ip' ); ?>>CF-Connecting-IP (Cloudflare)</option>
					</select>
					<span class="cu-field-hint"><?php esc_html_e( 'Un en-tête n’est jamais cru s’il ne vient pas d’un relais de confiance (Cloudflare, relais interne ou proxy listé ci-contre). Automatique : Cloudflare vérifié, puis X-Forwarded-For lu de droite à gauche.', 'cyberpush-ultra' ); ?></span>
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Proxys de confiance (IP ou CIDR publics, un par ligne)', 'cyberpush-ultra' ); ?></label>
					<textarea class="cu-textarea" data-key="trusted_proxies" data-type="lines" rows="3" placeholder="203.0.113.0/24"><?php echo esc_textarea( implode( "\n", (array) $s['firewall']['trusted_proxies'] ) ); ?></textarea>
					<span class="cu-field-hint"><?php esc_html_e( 'Plages publiées par votre CDN ou WAF (Sucuri, BunnyCDN, Fastly, QUIC.cloud, répartiteur public). Inutile pour Cloudflare et les relais internes.', 'cyberpush-ultra' ); ?></span>
				</div>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Pays de confiance (codes ISO2)', 'cyberpush-ultra' ); ?></label>
				<input type="text" class="cu-input" data-key="trusted_countries" data-type="csv" value="<?php echo esc_attr( implode( ', ', $s['firewall']['trusted_countries'] ) ); ?>">
				<span class="cu-field-hint"><?php esc_html_e( 'Réduit les faux positifs comportementaux (flood, débit). Les sondes, scanners et attaques WAF critiques restent bloqués ; utilisez la whitelist pour une IP personnelle.', 'cyberpush-ultra' ); ?></span>
			</div>
			<div class="cu-section-title"><?php esc_html_e( 'Détection', 'cyberpush-ultra' ); ?></div>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Flood 404', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Au-delà du seuil, les pages introuvables répondent 429 (temporaire) jusqu’à la fin de la fenêtre ; les pages existantes restent servies. Jamais de bannissement : les robots des moteurs suivent souvent d’anciens liens.', 'cyberpush-ultra' ); ?></span></div><label class="cu-switch"><input type="checkbox" data-key="flood_404_enabled" <?php checked( $s['firewall']['flood_404_enabled'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Seuil 404 / fenêtre (minutes)', 'cyberpush-ultra' ); ?></label>
					<div style="display:flex;gap:8px;">
						<input type="number" class="cu-input" data-key="flood_404_max" value="<?php echo esc_attr( $s['firewall']['flood_404_max'] ); ?>" min="1">
						<input type="number" class="cu-input" data-key="flood_404_window_minutes" value="<?php echo esc_attr( $s['firewall']['flood_404_window_minutes'] ); ?>" min="1">
					</div>
				</div>
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Détection de sondes (chemins sensibles)', 'cyberpush-ultra' ); ?></strong></div><label class="cu-switch"><input type="checkbox" data-key="probe_detection_enabled" <?php checked( $s['firewall']['probe_detection_enabled'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Détection de scanners connus', 'cyberpush-ultra' ); ?></strong></div><label class="cu-switch"><input type="checkbox" data-key="scanner_detection_enabled" <?php checked( $s['firewall']['scanner_detection_enabled'] ); ?>><span class="slider"></span></label></div>
					<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Blocage des bots malveillants (User-Agents)', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Scrapers et harvesters agressifs : refus 403, sans bannissement. Les robots d’IA en sont exclus par défaut (visibilité GEO).', 'cyberpush-ultra' ); ?></span></div><label class="cu-switch"><input type="checkbox" data-key="bad_bot_blocking_enabled" <?php checked( $s['firewall']['bad_bot_blocking_enabled'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'WAF — détection de payloads (SQLi / XSS / traversée / RCE)', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( '1re détection : requête refusée (403), sans bannissement. Bannissement temporaire seulement après 3 requêtes malveillantes distinctes en 10 min (jamais pour une requête provoquée par un autre site). Champs de recherche, de message et corps : signatures à haute confiance uniquement.', 'cyberpush-ultra' ); ?></span></div><label class="cu-switch"><input type="checkbox" data-key="waf_enabled" <?php checked( $s['firewall']['waf_enabled'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'WAF — inspecter aussi le corps des requêtes (anonymes)', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Formulaires, JSON, PUT/PATCH/DELETE, y compris admin-ajax.php ; refus sans bannissement ; les mots de passe ne sont jamais analysés.', 'cyberpush-ultra' ); ?></span></div><label class="cu-switch"><input type="checkbox" data-key="waf_scan_post" <?php checked( $s['firewall']['waf_scan_post'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Bloquer l’énumération d’utilisateurs (?author=N, API REST)', 'cyberpush-ultra' ); ?></strong></div><label class="cu-switch"><input type="checkbox" data-key="block_user_enumeration" <?php checked( $s['firewall']['block_user_enumeration'] ); ?>><span class="slider"></span></label></div>
					<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Durcissement REST 2026 (wp2shell, SQLi author, API batch)', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Bloque les menaces 2026 activement exploitées : RCE anonyme via l’API batch (CVE-2026-63030), injection SQL author__not_in (CVE-2026-60137). Recommandé.', 'cyberpush-ultra' ); ?></span></div><label class="cu-switch"><input type="checkbox" data-key="rest_hardening_enabled" <?php checked( $s['firewall']['rest_hardening_enabled'] ); ?>><span class="slider"></span></label></div>
					<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Radar — surveillance passive des suspects', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Observe les visiteurs suspects (datacenter/proxy/bot) sans les bloquer. Le filet est en bas de la page Firewall.', 'cyberpush-ultra' ); ?></span></div><label class="cu-switch"><input type="checkbox" data-key="radar_enabled" <?php checked( $s['firewall']['radar_enabled'] ); ?>><span class="slider"></span></label></div>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Chemins sensibles surveillés', 'cyberpush-ultra' ); ?></label>
				<textarea class="cu-textarea" data-key="sensitive_paths" data-type="lines" rows="7"><?php echo esc_textarea( implode( "\n", $s['firewall']['sensitive_paths'] ) ); ?></textarea>
					<span style="font-size:11px;color:var(--cu-text-dim);"><?php esc_html_e( 'Motif recherché dans le chemin URL normalisé (jamais dans la query string), avec limite de segment pour réduire les faux positifs.', 'cyberpush-ultra' ); ?></span>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Signatures de scanners (User-Agents, un par ligne)', 'cyberpush-ultra' ); ?></label>
				<textarea class="cu-textarea" data-key="scanner_signatures" data-type="lines" rows="5"><?php echo esc_textarea( implode( "\n", $s['firewall']['scanner_signatures'] ) ); ?></textarea>
				<span style="font-size:11px;color:var(--cu-text-dim);"><?php esc_html_e( 'Outils d’attaque connus (sqlmap, nuclei, ffuf…). Correspondance par nom d’outil entier, insensible à la casse.', 'cyberpush-ultra' ); ?></span>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Bots malveillants bloqués (un User-Agent par ligne)', 'cyberpush-ultra' ); ?></label>
				<textarea class="cu-textarea" data-key="bad_bot_signatures" data-type="lines" rows="5"><?php echo esc_textarea( implode( "\n", $s['firewall']['bad_bot_signatures'] ) ); ?></textarea>
				<span style="font-size:11px;color:var(--cu-text-dim);"><?php esc_html_e( 'Correspondance partielle, insensible à la casse. Pour bloquer les IA, ajoutez GPTBot, ClaudeBot, CCBot, PerplexityBot (déconseillé si vous visez la visibilité GEO).', 'cyberpush-ultra' ); ?></span>
			</div>

			<div class="cu-section-title"><?php esc_html_e( 'Défi JavaScript (anti-bots — style « Bot Fight Mode »)', 'cyberpush-ultra' ); ?></div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Activer le défi JavaScript', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Oblige les visiteurs à exécuter du JS avant d’afficher la page. Les bots sans JS sont bloqués ; les crawlers vérifiés (Google, Bing…) ne sont jamais défiés.', 'cyberpush-ultra' ); ?></span></div>
				<label class="cu-switch"><input type="checkbox" data-key="js_challenge_enabled" <?php checked( $s['firewall']['js_challenge_enabled'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field">
					<label><?php esc_html_e( 'Portée du défi', 'cyberpush-ultra' ); ?></label>
					<select class="cu-select" data-key="js_challenge_scope">
						<option value="suspicious" <?php selected( $s['firewall']['js_challenge_scope'], 'suspicious' ); ?>><?php esc_html_e( 'Visiteurs suspects uniquement (recommandé)', 'cyberpush-ultra' ); ?></option>
						<option value="all" <?php selected( $s['firewall']['js_challenge_scope'], 'all' ); ?>><?php esc_html_e( 'Tous les visiteurs anonymes', 'cyberpush-ultra' ); ?></option>
					</select>
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Validité du laissez-passer (heures)', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="js_challenge_ttl_hours" value="<?php echo esc_attr( $s['firewall']['js_challenge_ttl_hours'] ); ?>" min="1" max="168">
				</div>
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Crawlers de recherche vérifiés toujours autorisés', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Protection SEO permanente par DNS aller-retour ou liste IP officielle (Google, Bing, Apple, Yandex, Baidu, Naver, DuckDuckGo…).', 'cyberpush-ultra' ); ?></span></div><span class="cu-pill cu-pill-success" title="<?php esc_attr_e( 'Exemption de sécurité non désactivable : un robot de moteur vérifié n\'est jamais bloqué.', 'cyberpush-ultra' ); ?>"><?php esc_html_e( 'Toujours actif', 'cyberpush-ultra' ); ?></span></div>
			</div>

			<div class="cu-section-title"><?php esc_html_e( 'Connexion / API / Uploads', 'cyberpush-ultra' ); ?></div>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Protection de connexion', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Après N échecs, seule la connexion est suspendue depuis cette IP (administrateurs compris, sauf IP en liste blanche), durée doublée à chaque récidive (24 h max). wp-login.php : 30 envois / 5 min max (mot de passe oublié et inscription compris).', 'cyberpush-ultra' ); ?></span></div><label class="cu-switch"><input type="checkbox" data-key="login_protection_enabled" <?php checked( $s['firewall']['login_protection_enabled'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Tentatives max / verrouillage (min)', 'cyberpush-ultra' ); ?></label>
					<div style="display:flex;gap:8px;">
						<input type="number" class="cu-input" data-key="login_max_attempts" value="<?php echo esc_attr( $s['firewall']['login_max_attempts'] ); ?>" min="1">
						<input type="number" class="cu-input" data-key="login_lock_minutes" value="<?php echo esc_attr( $s['firewall']['login_lock_minutes'] ); ?>" min="1">
					</div>
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'XML-RPC', 'cyberpush-ultra' ); ?></label>
					<select class="cu-select" data-key="xmlrpc_protection">
						<option value="off" <?php selected( $s['firewall']['xmlrpc_protection'], 'off' ); ?>><?php esc_html_e( 'Aucune restriction', 'cyberpush-ultra' ); ?></option>
						<option value="limited" <?php selected( $s['firewall']['xmlrpc_protection'], 'limited' ); ?>><?php esc_html_e( 'Limité', 'cyberpush-ultra' ); ?></option>
						<option value="disabled" <?php selected( $s['firewall']['xmlrpc_protection'], 'disabled' ); ?>><?php esc_html_e( 'Désactivé', 'cyberpush-ultra' ); ?></option>
					</select>
				</div>
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Protection REST API', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Bloque pour les visiteurs non connectés les routes listées ci-dessous.', 'cyberpush-ultra' ); ?></span></div><label class="cu-switch"><input type="checkbox" data-key="restapi_protection_enabled" <?php checked( $s['firewall']['restapi_protection_enabled'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Routes REST interdites aux anonymes (une par ligne, ex. /wp/v2/comments ; préfixe /wp-json accepté)', 'cyberpush-ultra' ); ?></label>
					<textarea class="cu-textarea" data-key="restapi_blocked_routes" data-type="lines" rows="3" placeholder="/wp/v2/comments"><?php echo esc_textarea( implode( "\n", (array) $s['firewall']['restapi_blocked_routes'] ) ); ?></textarea>
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Mots-clés d’URL bloqués (un par ligne)', 'cyberpush-ultra' ); ?></label>
					<textarea class="cu-textarea" data-key="blocked_url_keywords" data-type="lines" rows="3"><?php echo esc_textarea( implode( "\n", (array) $s['firewall']['blocked_url_keywords'] ) ); ?></textarea>
					<span class="cu-field-hint"><?php esc_html_e( 'Comparés au chemin et aux paramètres décodés de l’URL. Refus (403) ; bannissement temporaire seulement en cas de récidive.', 'cyberpush-ultra' ); ?></span>
				</div>
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Protection des uploads', 'cyberpush-ultra' ); ?></strong></div><label class="cu-switch"><input type="checkbox" data-key="upload_protection_enabled" <?php checked( $s['firewall']['upload_protection_enabled'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Extensions bloquées', 'cyberpush-ultra' ); ?></label>
					<input type="text" class="cu-input" data-key="upload_blocked_extensions" data-type="csv" value="<?php echo esc_attr( implode( ', ', $s['firewall']['upload_blocked_extensions'] ) ); ?>">
				</div>
			</div>
			<div class="cu-section-title"><?php esc_html_e( 'Limitation de débit', 'cyberpush-ultra' ); ?></div>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Rate limiting actif', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Au-delà du seuil : réponse 429 temporaire jusqu’à la fin de la fenêtre, jamais de bannissement (signal recommandé par Google pour ralentir un robot). Compté par IP (par préfixe /64 en IPv6). Éditeurs connectés et robots vérifiés jamais limités.', 'cyberpush-ultra' ); ?></span></div><label class="cu-switch"><input type="checkbox" data-key="rate_limit_enabled" <?php checked( $s['firewall']['rate_limit_enabled'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Requêtes max / fenêtre (secondes)', 'cyberpush-ultra' ); ?></label>
					<div style="display:flex;gap:8px;">
						<input type="number" class="cu-input" data-key="rate_limit_max_requests" value="<?php echo esc_attr( $s['firewall']['rate_limit_max_requests'] ); ?>" min="1">
						<input type="number" class="cu-input" data-key="rate_limit_window_seconds" value="<?php echo esc_attr( $s['firewall']['rate_limit_window_seconds'] ); ?>" min="1">
					</div>
				</div>
			</div>
			<div class="cu-section-title"><?php esc_html_e( 'Protection du contenu', 'cyberpush-ultra' ); ?></div>
			<div class="cu-field-row">
				<div class="cu-field-row-text">
					<strong><?php esc_html_e( 'Protection anti-capture (dissuasion)', 'cyberpush-ultra' ); ?></strong>
					<span><?php esc_html_e( 'Dissuasion seulement : aucune page web ne peut vraiment empêcher une capture (fonction du système). Voile à la perte de focus, effacement du presse-papier sur Impr écran, blocage impression, clic droit et glisser d’images désactivés. Ne s’applique JAMAIS aux administrateurs. Peut gêner les vrais visiteurs.', 'cyberpush-ultra' ); ?></span>
				</div>
				<label class="cu-switch"><input type="checkbox" data-key="screenshot_protection_enabled" <?php checked( $s['firewall']['screenshot_protection_enabled'] ); ?>><span class="slider"></span></label>
			</div>

			<div class="cu-section-title"><?php esc_html_e( 'Protection anti-hotlink', 'cyberpush-ultra' ); ?></div>
			<div class="cu-field-row">
				<div class="cu-field-row-text">
					<strong><?php esc_html_e( 'Activer la protection anti-hotlink', 'cyberpush-ultra' ); ?></strong>
					<span><?php esc_html_e( 'Empêche d\'autres sites d\'afficher vos images/médias (vol de bande passante). L\'interrupteur seul n\'agit que sur les requêtes qui passent par WordPress : vos fichiers statiques (images, PDF…) servis directement par le serveur ne sont protégés qu\'après avoir copié les règles .htaccess ci-dessous sur votre serveur. Le référent vide (accès direct) est toujours autorisé.', 'cyberpush-ultra' ); ?></span>
				</div>
				<label class="cu-switch"><input type="checkbox" data-key="hotlink_protection_enabled" <?php checked( $s['firewall']['hotlink_protection_enabled'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Domaines autorisés supplémentaires (un par ligne)', 'cyberpush-ultra' ); ?></label>
				<textarea class="cu-textarea" data-key="hotlink_allowed_domains" data-type="lines" rows="3" placeholder="mon-cdn.com&#10;partenaire.fr"><?php echo esc_textarea( implode( "\n", (array) $s['firewall']['hotlink_allowed_domains'] ) ); ?></textarea>
				<span style="font-size:11px;color:var(--cu-text-dim);"><?php esc_html_e( 'Votre domaine, Google, Bing, réseaux sociaux… sont déjà autorisés d\'office.', 'cyberpush-ultra' ); ?></span>
			</div>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field">
					<label><?php esc_html_e( 'Extensions protégées (séparées par des virgules)', 'cyberpush-ultra' ); ?></label>
					<input type="text" class="cu-input" data-key="hotlink_extensions" data-type="csv" value="<?php echo esc_attr( implode( ', ', (array) $s['firewall']['hotlink_extensions'] ) ); ?>">
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Action', 'cyberpush-ultra' ); ?></label>
					<select class="cu-select" data-key="hotlink_action">
						<option value="forbidden" <?php selected( $s['firewall']['hotlink_action'], 'forbidden' ); ?>><?php esc_html_e( 'Bloquer (403)', 'cyberpush-ultra' ); ?></option>
						<option value="redirect" <?php selected( $s['firewall']['hotlink_action'], 'redirect' ); ?>><?php esc_html_e( 'Rediriger vers une image', 'cyberpush-ultra' ); ?></option>
					</select>
				</div>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Image de remplacement (si « Rediriger »)', 'cyberpush-ultra' ); ?></label>
				<input type="url" class="cu-input" data-key="hotlink_redirect_url" value="<?php echo esc_attr( $s['firewall']['hotlink_redirect_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-content/uploads/interdit.png' ) ); ?>">
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Règles .htaccess (la vraie protection sur Apache/cPanel)', 'cyberpush-ultra' ); ?></label>
				<textarea class="cu-textarea" rows="10" readonly onclick="this.select()" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;"><?php echo esc_textarea( CU_Firewall::hotlink_htaccess_rules() ); ?></textarea>
				<span style="font-size:12px;color:var(--cu-warning);line-height:1.5;display:block;margin-top:6px;">
					<?php echo CU_Icons::get( 'alert', 'cu-icon cu-icon-inline' ); // phpcs:ignore ?> <?php esc_html_e( 'Important : Apache sert vos fichiers statiques SANS passer par PHP. Pour une protection réelle, copiez ces règles au DÉBUT de votre fichier .htaccess (racine du site, via cPanel ▸ Gestionnaire de fichiers). Enregistrez d\'abord vos réglages ci-dessus pour régénérer les règles. Le repli PHP ci-dessus ne couvre que les médias servis par WordPress.', 'cyberpush-ultra' ); ?>
				</span>
			</div>

			<button class="cu-btn cu-btn-primary" data-save="firewall"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>
			<button class="cu-btn cu-btn-danger" data-erase="firewall"><?php echo CU_Icons::get( 'trash' ); ?> <?php esc_html_e( 'Réinitialiser les données Firewall', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- SEO -->
	<div class="cu-settings-panel" data-panel="seo">
		<div class="cu-card">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'search' ); ?> <?php esc_html_e( 'Auditeur SEO', 'cyberpush-ultra' ); ?></div>
			<p style="color:var(--cu-text-dim);font-size:13px;line-height:1.6;margin:8px 0 14px;">
				<?php esc_html_e( 'Mode AUDIT UNIQUEMENT : le module analyse le rendu FINAL de chaque page (Elementor, Divi, shortcodes inclus) et la note, mais n\'émet aucune balise sur le site — aucun conflit si vous utilisez déjà Yoast ou Rank Math.', 'cyberpush-ultra' ); ?>
			</p>
			<div class="cu-field-row">
				<div class="cu-field-row-text">
					<strong><?php esc_html_e( 'Module SEO actif', 'cyberpush-ultra' ); ?></strong>
					<span><?php esc_html_e( 'Active le crawl, l\'audit et le suivi de score.', 'cyberpush-ultra' ); ?></span>
				</div>
				<label class="cu-switch"><input type="checkbox" data-key="enabled" <?php checked( $s['seo']['enabled'] ); ?>><span class="slider"></span></label>
			</div>

			<div class="cu-section-title"><?php esc_html_e( 'Périmètre du crawl', 'cyberpush-ultra' ); ?></div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Contenus analysés', 'cyberpush-ultra' ); ?></label>
				<select class="cu-select" data-key="crawl_scope">
					<option value="tracked" <?php selected( $s['seo']['crawl_scope'], 'tracked' ); ?>><?php esc_html_e( 'Types suivis (articles, pages)', 'cyberpush-ultra' ); ?></option>
					<option value="all_public" <?php selected( $s['seo']['crawl_scope'], 'all_public' ); ?>><?php esc_html_e( 'Tous les types publics', 'cyberpush-ultra' ); ?></option>
				</select>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Inclure la page d\'accueil', 'cyberpush-ultra' ); ?></strong></div>
				<label class="cu-switch"><input type="checkbox" data-key="crawl_home" <?php checked( $s['seo']['crawl_home'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Inclure les archives de taxonomies', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Catégories, étiquettes… Choisissez précisément lesquelles ci-dessous.', 'cyberpush-ultra' ); ?></span></div>
				<label class="cu-switch"><input type="checkbox" data-key="crawl_terms" <?php checked( $s['seo']['crawl_terms'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Taxonomies à inclure (quand l\'option ci-dessus est active)', 'cyberpush-ultra' ); ?></label>
				<div class="cu-seo-tax-grid">
					<?php
					$cu_public_taxes = get_taxonomies( array( 'public' => true ), 'objects' );
					$cu_tax_map = ( isset( $s['seo']['crawl_taxonomies'] ) && is_array( $s['seo']['crawl_taxonomies'] ) ) ? $s['seo']['crawl_taxonomies'] : array();
					foreach ( $cu_public_taxes as $cu_tax ) {
						if ( 'post_format' === $cu_tax->name ) {
							continue;
						}
						$cu_tax_on = ! empty( $cu_tax_map[ $cu_tax->name ] );
						?>
						<label class="cu-seo-tax-item">
							<input type="checkbox" data-key="crawl_taxonomies.<?php echo esc_attr( $cu_tax->name ); ?>" <?php checked( $cu_tax_on ); ?>>
							<span><?php echo esc_html( $cu_tax->labels->name ); ?> <em><?php echo esc_html( $cu_tax->name ); ?></em></span>
						</label>
						<?php
					}
					?>
				</div>
				<span class="cu-field-hint"><?php esc_html_e( 'Décochez par exemple « Étiquettes » (post_tag) ou « Étiquettes de produit » (product_tag) pour ne pas les analyser ni vérifier leur indexation.', 'cyberpush-ultra' ); ?></span>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text">
					<strong><?php esc_html_e( 'Vérifier les liens cassés (404)', 'cyberpush-ultra' ); ?></strong>
					<span><?php esc_html_e( 'Teste les liens sortants détectés lors de l\'audit.', 'cyberpush-ultra' ); ?></span>
				</div>
				<label class="cu-switch"><input type="checkbox" data-key="check_broken_links" <?php checked( $s['seo']['check_broken_links'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-grid cu-grid-3">
				<div class="cu-field">
					<label><?php esc_html_e( 'Pages par lot', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="crawl_batch_size" value="<?php echo esc_attr( $s['seo']['crawl_batch_size'] ); ?>" min="1" max="25">
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Ré-analyse après (jours)', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="recrawl_days" value="<?php echo esc_attr( $s['seo']['recrawl_days'] ); ?>" min="1">
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Timeout par page (s)', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="request_timeout" value="<?php echo esc_attr( $s['seo']['request_timeout'] ); ?>" min="5" max="30">
				</div>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'User-Agent du robot d\'audit', 'cyberpush-ultra' ); ?></label>
				<input type="text" class="cu-input" data-key="user_agent" value="<?php echo esc_attr( $s['seo']['user_agent'] ); ?>">
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'URL exclues du crawl (une par ligne, sous-chaîne)', 'cyberpush-ultra' ); ?></label>
				<textarea class="cu-textarea" data-key="excluded_url_patterns" data-type="lines" rows="4"><?php echo esc_textarea( implode( "\n", (array) $s['seo']['excluded_url_patterns'] ) ); ?></textarea>
			</div>

			<div class="cu-section-title"><?php esc_html_e( 'Seuils d\'audit', 'cyberpush-ultra' ); ?></div>
			<div class="cu-grid cu-grid-3">
				<div class="cu-field">
					<label><?php esc_html_e( 'Titre — min / max', 'cyberpush-ultra' ); ?></label>
					<div style="display:flex;gap:8px;">
						<input type="number" class="cu-input" data-key="title_min" value="<?php echo esc_attr( $s['seo']['title_min'] ); ?>" min="1">
						<input type="number" class="cu-input" data-key="title_max" value="<?php echo esc_attr( $s['seo']['title_max'] ); ?>" min="1">
					</div>
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Meta desc — min / max', 'cyberpush-ultra' ); ?></label>
					<div style="display:flex;gap:8px;">
						<input type="number" class="cu-input" data-key="meta_desc_min" value="<?php echo esc_attr( $s['seo']['meta_desc_min'] ); ?>" min="1">
						<input type="number" class="cu-input" data-key="meta_desc_max" value="<?php echo esc_attr( $s['seo']['meta_desc_max'] ); ?>" min="1">
					</div>
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Contenu mince (< mots)', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="content_min_words" value="<?php echo esc_attr( $s['seo']['content_min_words'] ); ?>" min="1">
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Densité mot-clé max (%)', 'cyberpush-ultra' ); ?></label>
					<input type="number" step="0.1" class="cu-input" data-key="keyword_density_max" value="<?php echo esc_attr( $s['seo']['keyword_density_max'] ); ?>" min="0">
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Profondeur de clic max', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="max_crawl_depth" value="<?php echo esc_attr( $s['seo']['max_crawl_depth'] ); ?>" min="1">
				</div>
			</div>

			<div class="cu-section-title"><?php esc_html_e( 'Core Web Vitals — PageSpeed Insights', 'cyberpush-ultra' ); ?></div>
			<div class="cu-field-row">
				<div class="cu-field-row-text">
					<strong><?php esc_html_e( 'Activer la mesure de vitesse', 'cyberpush-ultra' ); ?></strong>
					<span><?php esc_html_e( 'Utilise l\'API Google PageSpeed Insights (clé gratuite, envoyée en en-tête) : LCP, INP, CLS + note Lighthouse. Une mesure de moins de 7 jours est réutilisée si la page n\'a pas changé ; une panne ou un quota atteint affiche « mesure indisponible » sans effet sur le score.', 'cyberpush-ultra' ); ?></span>
				</div>
				<label class="cu-switch"><input type="checkbox" data-key="psi_enabled" <?php checked( $s['seo']['psi_enabled'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field">
					<label><?php esc_html_e( 'Clé API PageSpeed Insights', 'cyberpush-ultra' ); ?></label>
					<?php $cu_secret_field( 'psi_api_key', $s['seo']['psi_api_key'], 'AIza...' ); ?>
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Stratégie', 'cyberpush-ultra' ); ?></label>
					<select class="cu-select" data-key="psi_strategy">
						<option value="mobile" <?php selected( $s['seo']['psi_strategy'], 'mobile' ); ?>><?php esc_html_e( 'Mobile', 'cyberpush-ultra' ); ?></option>
						<option value="desktop" <?php selected( $s['seo']['psi_strategy'], 'desktop' ); ?>><?php esc_html_e( 'Ordinateur', 'cyberpush-ultra' ); ?></option>
					</select>
				</div>
			</div>

			<div class="cu-section-title"><?php esc_html_e( 'Présence dans Google — Serper.dev', 'cyberpush-ultra' ); ?></div>
			<div class="cu-field-row">
				<div class="cu-field-row-text">
					<strong><?php esc_html_e( 'Vérifier la présence de vos pages dans les résultats Google', 'cyberpush-ultra' ); ?></strong>
					<span><?php esc_html_e( 'Recherche Google « site: » via l\'API Serper.dev, puis vérification que l\'adresse EXACTE de chaque page figure dans les résultats. Indicatif (ce n\'est pas la Search Console) et hors score. Fonctionne sur hébergement mutualisé.', 'cyberpush-ultra' ); ?></span>
				</div>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Clé API Serper.dev', 'cyberpush-ultra' ); ?></label>
				<?php $cu_secret_field( 'serper_api_key', $s['seo']['serper_api_key'], __( 'Votre clé Serper.dev', 'cyberpush-ultra' ) ); ?>
				<span class="cu-field-hint"><?php esc_html_e( 'Clé gratuite (2 500 requêtes) sur serper.dev. Une requête = une URL vérifiée. La vérification se lance depuis la section « Présence dans Google » en bas de la page SEO. Une page n\'est revérifiée qu\'après 30 jours (observée), 7 jours (non trouvée) ou 6 heures (erreur).', 'cyberpush-ultra' ); ?></span>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text">
					<strong><?php esc_html_e( 'Auto-vérification (en arrière-plan)', 'cyberpush-ultra' ); ?></strong>
					<span><?php esc_html_e( 'Vérifie automatiquement l\'indexation par petits lots via la tâche planifiée. Désactivé par défaut car cela consomme votre quota Serper. Mêmes délais que la vérification manuelle ; suspendue automatiquement si la clé est refusée ou le quota épuisé.', 'cyberpush-ultra' ); ?></span>
				</div>
				<label class="cu-switch"><input type="checkbox" data-key="index_auto" <?php checked( $s['seo']['index_auto'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'URL vérifiées par passage automatique', 'cyberpush-ultra' ); ?></label>
				<input type="number" min="1" max="50" class="cu-input" data-key="index_auto_per_cycle" value="<?php echo esc_attr( $s['seo']['index_auto_per_cycle'] ); ?>">
				<span class="cu-field-hint"><?php esc_html_e( 'Nombre maximal d\'URL contrôlées à chaque exécution de la tâche planifiée (horaire). Plus le nombre est bas, moins le quota est consommé.', 'cyberpush-ultra' ); ?></span>
			</div>

			<button class="cu-btn cu-btn-primary" data-save="seo"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>
			<button class="cu-btn cu-btn-danger" data-erase="seo"><?php echo CU_Icons::get( 'trash' ); ?> <?php esc_html_e( 'Réinitialiser les données SEO', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- IA -->
	<div class="cu-settings-panel" data-panel="ai">
		<div class="cu-card">
			<div class="cu-field">
				<label><?php esc_html_e( 'Fournisseur IA', 'cyberpush-ultra' ); ?></label>
				<select class="cu-select" data-key="provider">
					<option value="openai" <?php selected( $s['ai']['provider'], 'openai' ); ?>>OpenAI</option>
					<option value="anthropic" <?php selected( $s['ai']['provider'], 'anthropic' ); ?>>Anthropic</option>
					<option value="google" <?php selected( $s['ai']['provider'], 'google' ); ?>>Google Gemini</option>
					<option value="mistral" <?php selected( $s['ai']['provider'], 'mistral' ); ?>>Mistral</option>
					<option value="ollama" <?php selected( $s['ai']['provider'], 'ollama' ); ?>>Ollama (local)</option>
					<option value="custom" <?php selected( $s['ai']['provider'], 'custom' ); ?>><?php esc_html_e( 'Personnalisé', 'cyberpush-ultra' ); ?></option>
				</select>
			</div>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field">
					<label><?php esc_html_e( 'Modèle', 'cyberpush-ultra' ); ?></label>
					<input type="text" class="cu-input" data-key="model" value="<?php echo esc_attr( $s['ai']['model'] ); ?>" placeholder="gpt-4o-mini">
				</div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Ton des réponses', 'cyberpush-ultra' ); ?></label>
					<select class="cu-select" data-key="tone">
						<option value="professionnel" <?php selected( $s['ai']['tone'], 'professionnel' ); ?>><?php esc_html_e( 'Professionnel', 'cyberpush-ultra' ); ?></option>
						<option value="amical" <?php selected( $s['ai']['tone'], 'amical' ); ?>><?php esc_html_e( 'Amical', 'cyberpush-ultra' ); ?></option>
						<option value="direct" <?php selected( $s['ai']['tone'], 'direct' ); ?>><?php esc_html_e( 'Direct', 'cyberpush-ultra' ); ?></option>
					</select>
				</div>
			</div>
			<div class="cu-section-title"><?php esc_html_e( 'Clés API', 'cyberpush-ultra' ); ?></div>
			<p class="cu-field-row-text"><span><?php esc_html_e( 'Les clés enregistrées ne sont jamais réaffichées ni exportées. Laissez un champ vide pour conserver la clé actuelle.', 'cyberpush-ultra' ); ?></span></p>
			<div class="cu-field"><label>OpenAI</label><?php $cu_secret_field( 'api_keys.openai', $s['ai']['api_keys']['openai'], 'sk-...' ); ?></div>
			<div class="cu-field"><label>Anthropic</label><?php $cu_secret_field( 'api_keys.anthropic', $s['ai']['api_keys']['anthropic'], 'sk-ant-...' ); ?></div>
			<div class="cu-field"><label>Google</label><?php $cu_secret_field( 'api_keys.google', $s['ai']['api_keys']['google'] ); ?></div>
			<div class="cu-field"><label>Mistral</label><?php $cu_secret_field( 'api_keys.mistral', $s['ai']['api_keys']['mistral'] ); ?></div>
			<?php $cu_network_locked = is_multisite() && ! is_super_admin(); ?>
			<div class="cu-field"><label>URL Ollama</label><input type="url" class="cu-input" data-key="api_keys.ollama_url" value="<?php echo esc_attr( $s['ai']['api_keys']['ollama_url'] ); ?>" <?php disabled( $cu_network_locked ); ?>></div>
			<div class="cu-field"><label><?php esc_html_e( 'URL personnalisée (compatible OpenAI, adresse publique)', 'cyberpush-ultra' ); ?></label><input type="url" class="cu-input" data-key="api_keys.custom_url" value="<?php echo esc_attr( $s['ai']['api_keys']['custom_url'] ); ?>" <?php disabled( $cu_network_locked ); ?>></div>
			<?php if ( $cu_network_locked ) : ?>
				<p class="cu-field-row-text"><span><?php esc_html_e( 'Multisite : seuls les super-administrateurs peuvent modifier les URL de serveur IA.', 'cyberpush-ultra' ); ?></span></p>
			<?php endif; ?>
			<div class="cu-field"><label><?php esc_html_e( 'Clé personnalisée', 'cyberpush-ultra' ); ?></label><?php $cu_secret_field( 'api_keys.custom_key', $s['ai']['api_keys']['custom_key'] ); ?></div>
			<p class="cu-field-row-text"><span><?php printf( esc_html__( 'Jetons consommés au total : %s', 'cyberpush-ultra' ), esc_html( number_format_i18n( CU_AI::tokens_used() ) ) ); ?></span></p>
			<div class="cu-toolbar">
				<button class="cu-btn cu-btn-primary" data-save="ai"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn" id="cu-ai-test"><?php echo CU_Icons::get( 'sparkles' ); ?> <?php esc_html_e( 'Tester la connexion', 'cyberpush-ultra' ); ?></button>
			</div>
		</div>
	</div>

	<!-- WordPress -->
	<div class="cu-settings-panel" data-panel="wordpress">
		<div class="cu-card">
			<div class="cu-field">
				<label><?php esc_html_e( 'Types de contenu suivis (slugs séparés par des virgules)', 'cyberpush-ultra' ); ?></label>
				<input type="text" class="cu-input" data-key="tracked_post_types" data-type="csv" value="<?php echo esc_attr( implode( ', ', $s['wordpress']['tracked_post_types'] ) ); ?>">
			</div>
			<div class="cu-field">
				<label><?php esc_html_e( 'Types de contenu exclus', 'cyberpush-ultra' ); ?></label>
				<input type="text" class="cu-input" data-key="excluded_post_types" data-type="csv" value="<?php echo esc_attr( implode( ', ', $s['wordpress']['excluded_post_types'] ) ); ?>">
			</div>
			<button class="cu-btn cu-btn-primary" data-save="wordpress"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- Performance -->
	<div class="cu-settings-panel" data-panel="performance">
		<div class="cu-card">
			<div class="cu-section-title"><?php esc_html_e( 'Conservation des données', 'cyberpush-ultra' ); ?></div>
			<div class="cu-grid cu-grid-2">
				<div class="cu-field-row"><div class="cu-field-row-text"><strong><?php esc_html_e( 'Nettoyage automatique', 'cyberpush-ultra' ); ?></strong></div><label class="cu-switch"><input type="checkbox" data-key="auto_cleanup_enabled" <?php checked( $s['performance']['auto_cleanup_enabled'] ); ?>><span class="slider"></span></label></div>
				<div class="cu-field">
					<label><?php esc_html_e( 'Conserver les données (jours)', 'cyberpush-ultra' ); ?></label>
					<input type="number" class="cu-input" data-key="auto_cleanup_days" value="<?php echo esc_attr( $s['performance']['auto_cleanup_days'] ); ?>" min="1" max="760">
					<span class="cu-field-hint"><?php esc_html_e( 'Statistiques, journaux du pare-feu et journal d’activité. Maximum 760 jours (25 mois, plafond recommandé par la CNIL pour la mesure d’audience).', 'cyberpush-ultra' ); ?></span>
				</div>
			</div>
			<button class="cu-btn cu-btn-primary" data-save="performance"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- Base de données / migrations -->
	<div class="cu-settings-panel" data-panel="system">
		<div class="cu-card">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'database' ); ?> <?php esc_html_e( 'État de la base de données', 'cyberpush-ultra' ); ?></div>
			<div id="cu-system-info" class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></div>
			<div class="cu-toolbar" style="margin-top:16px;">
				<button class="cu-btn cu-btn-primary" id="cu-install-tables"><?php echo CU_Icons::get( 'refresh' ); ?> <?php esc_html_e( 'Installer / mettre à jour les tables SQL', 'cyberpush-ultra' ); ?></button>
			</div>
		</div>
	</div>

	<!-- Sécurité -->
	<div class="cu-settings-panel" data-panel="security">
		<div class="cu-card">
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Journaliser les changements de réglages', 'cyberpush-ultra' ); ?></strong></div>
				<label class="cu-switch"><input type="checkbox" data-key="log_setting_changes" <?php checked( $s['security']['log_setting_changes'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Supprimer les données à la désinstallation', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Si activé, toutes les données du plugin seront définitivement supprimées lorsque vous le désinstallerez.', 'cyberpush-ultra' ); ?></span></div>
				<label class="cu-switch"><input type="checkbox" data-key="erase_data_on_uninstall" <?php checked( ! empty( $s['security']['erase_data_on_uninstall'] ) ); ?>><span class="slider"></span></label>
			</div>
			<button class="cu-btn cu-btn-primary" data-save="security"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- Alertes e-mail (module Journal de sécurité) -->
	<div class="cu-settings-panel" data-panel="alerts">
		<div class="cu-card">
			<div class="cu-section-title"><?php esc_html_e( 'Alertes e-mail de sécurité', 'cyberpush-ultra' ); ?></div>
			<p style="color:var(--cu-text-dim);font-size:13px;line-height:1.6;margin:8px 0 14px;">
				<?php esc_html_e( 'Un e-mail est envoyé pour chaque événement CRITIQUE : extension installée ou activée, nouvel administrateur, changement de l’adresse du site ou de l’e-mail d’administration, fichier modifié via l’éditeur, fichiers du cœur modifiés. Au plus 5 e-mails par heure et par type d’événement (30 au total) ; tout reste visible dans Firewall > Journal de sécurité. Vider le journal ou réinitialiser la référence d’intégrité envoie toujours une alerte, y compris à l’ancienne adresse pendant 7 jours. Couper ou rediriger ces alertes prévient toujours l’ancien destinataire.', 'cyberpush-ultra' ); ?>
			</p>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Journal de sécurité actif', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Enregistre les changements à haut risque et le scan d’intégrité des fichiers.', 'cyberpush-ultra' ); ?></span></div>
				<label class="cu-switch"><input type="checkbox" data-key="enabled" <?php checked( $s['audit']['enabled'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Envoyer les alertes critiques par e-mail', 'cyberpush-ultra' ); ?></strong></div>
				<label class="cu-switch"><input type="checkbox" data-key="email_alerts" <?php checked( $s['audit']['email_alerts'] ); ?>><span class="slider"></span></label>
			</div>
			<div class="cu-field">
				<label for="cu-alert-email"><?php esc_html_e( 'Adresse de réception', 'cyberpush-ultra' ); ?></label>
				<input type="email" class="cu-input" id="cu-alert-email" data-key="alert_email" value="<?php echo esc_attr( $s['audit']['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				<span class="cu-field-hint"><?php esc_html_e( 'Laissez vide pour utiliser l’e-mail d’administration du site. Conseil : une adresse externe au site (ex. Gmail), qui reste joignable même si le site est compromis.', 'cyberpush-ultra' ); ?></span>
			</div>
			<div class="cu-field">
				<label for="cu-audit-retention"><?php esc_html_e( 'Conservation du journal (jours)', 'cyberpush-ultra' ); ?></label>
				<input type="number" class="cu-input" id="cu-audit-retention" data-key="retention_days" min="1" max="730" value="<?php echo esc_attr( $s['audit']['retention_days'] ); ?>">
				<span class="cu-field-hint"><?php esc_html_e( 'Maximum 730 jours.', 'cyberpush-ultra' ); ?></span>
			</div>
			<button class="cu-btn cu-btn-primary" data-save="audit"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>
			<button class="cu-btn" id="cu-alert-test"><?php esc_html_e( 'Envoyer un e-mail de test', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- Sauvegarde & Maintenance -->
	<div class="cu-settings-panel" data-panel="backup">
		<div class="cu-card">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'download' ); ?> <?php esc_html_e( 'Sauvegarde des réglages', 'cyberpush-ultra' ); ?></div>
			<p class="cu-field-row-text"><span><?php esc_html_e( 'Exportez tous les réglages du plugin dans un fichier JSON, puis réimportez-les sur ce site ou un autre. Les clés API ne sont jamais incluses : l’import conserve celles déjà enregistrées.', 'cyberpush-ultra' ); ?></span></p>
			<div class="cu-toolbar">
				<button class="cu-btn cu-btn-primary" id="cu-settings-export"><?php echo CU_Icons::get( 'download' ); ?> <?php esc_html_e( 'Exporter les réglages', 'cyberpush-ultra' ); ?></button>
				<label class="cu-btn" for="cu-settings-import-file" style="cursor:pointer;"><?php echo CU_Icons::get( 'upload' ); ?> <?php esc_html_e( 'Importer un fichier…', 'cyberpush-ultra' ); ?></label>
				<input type="file" id="cu-settings-import-file" accept="application/json,.json" style="display:none;">
			</div>
		</div>

		<div class="cu-card" style="margin-top:16px;">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'refresh' ); ?> <?php esc_html_e( 'Nettoyage & maintenance', 'cyberpush-ultra' ); ?></div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Vider le cache', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Force le recalcul immédiat des rapports et des données réseau en cache (géolocalisation, robots vérifiés). Les statistiques et l’état de sécurité sont conservés.', 'cyberpush-ultra' ); ?></span></div>
				<button class="cu-btn" data-maintenance="flush_cache"><?php esc_html_e( 'Vider', 'cyberpush-ultra' ); ?></button>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Recalculer les statistiques', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Relance l’agrégation journalière des contenus.', 'cyberpush-ultra' ); ?></span></div>
				<button class="cu-btn" data-maintenance="recalculate"><?php esc_html_e( 'Recalculer', 'cyberpush-ultra' ); ?></button>
			</div>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Supprimer les données expirées', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Supprime les événements et journaux plus anciens que la durée de conservation.', 'cyberpush-ultra' ); ?></span></div>
				<button class="cu-btn cu-btn-danger" data-maintenance="cleanup_expired"><?php esc_html_e( 'Nettoyer', 'cyberpush-ultra' ); ?></button>
			</div>
		</div>
	</div>

	<!-- Développeur -->
	<div class="cu-settings-panel" data-panel="developer">
		<div class="cu-card">
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Mode debug', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Active la journalisation détaillée (niveau debug) et l’échantillon 1/20 des requêtes autorisées par le pare-feu (URL sans paramètres).', 'cyberpush-ultra' ); ?></span></div>
				<label class="cu-switch"><input type="checkbox" data-key="debug_mode" <?php checked( $s['developer']['debug_mode'] ); ?>><span class="slider"></span></label>
			</div>
			<button class="cu-btn cu-btn-primary" data-save="developer"><?php echo CU_Icons::get( 'save' ); ?> <?php esc_html_e( 'Enregistrer', 'cyberpush-ultra' ); ?></button>

			<div class="cu-section-title" style="margin-top:20px;"><?php echo CU_Icons::get( 'sparkles' ); ?> <?php esc_html_e( 'Auto-tests', 'cyberpush-ultra' ); ?></div>
			<p class="cu-field-row-text"><span><?php esc_html_e( 'Vérifiez rapidement l’état de chaque sous-système du plugin.', 'cyberpush-ultra' ); ?></span></p>
			<div class="cu-toolbar">
				<button class="cu-btn" data-selftest="cron"><?php esc_html_e( 'Tester le Cron', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn" data-selftest="firewall"><?php esc_html_e( 'Tester le Firewall', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn" data-selftest="seo"><?php esc_html_e( 'Tester le SEO', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn" data-selftest="analytics"><?php esc_html_e( 'Tester Analytics', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn" data-selftest="ai"><?php esc_html_e( 'Tester l’IA', 'cyberpush-ultra' ); ?></button>
			</div>

			<div class="cu-section-title" style="margin-top:20px;"><?php echo CU_Icons::get( 'bug' ); ?> <?php esc_html_e( 'Journal d’erreurs du plugin', 'cyberpush-ultra' ); ?></div>
			<p class="cu-field-row-text"><span><?php esc_html_e( 'Les erreurs fatales et exceptions non interceptées provenant du plugin sont capturées ici et dans la page Logs (une même erreur au plus une fois par 10 min, 1 Mo par jour, 30 jours). Les simples avertissements PHP restent dans le journal d’erreurs de votre hébergement. Fichier de secours utilisable même si la base est en cause.', 'cyberpush-ultra' ); ?></span></p>
			<div class="cu-toolbar" style="margin-bottom:12px;">
				<button class="cu-btn cu-btn-primary" id="cu-errlog-refresh"><?php echo CU_Icons::get( 'refresh' ); ?> <?php esc_html_e( 'Charger les erreurs', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn cu-btn-danger" id="cu-errlog-clear"><?php echo CU_Icons::get( 'trash' ); ?> <?php esc_html_e( 'Vider le journal d’erreurs', 'cyberpush-ultra' ); ?></button>
			</div>
			<pre id="cu-errlog-output" style="max-height:320px;overflow:auto;background:var(--cu-bg-soft);border:1px solid var(--cu-panel-border);border-radius:8px;padding:12px;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word;color:var(--cu-text-dim);margin:0;"><?php esc_html_e( 'Cliquez sur « Charger les erreurs ».', 'cyberpush-ultra' ); ?></pre>
		</div>
	</div>
</div>

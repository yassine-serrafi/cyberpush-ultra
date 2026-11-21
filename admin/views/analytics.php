<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$excluded_countries = implode( ', ', (array) CU_Settings::get( 'analytics', 'excluded_countries', array() ) );
$cache_min          = max( 0, min( 1440, (int) CU_Settings::get( 'analytics', 'cache_duration_minutes', 10 ) ) );
$realtime_refresh   = max( 5, min( 300, (int) CU_Settings::get( 'analytics', 'realtime_refresh_seconds', 20 ) ) );
$woo_active         = class_exists( 'WooCommerce' );
$privacy_mode       = CU_Analytics::privacy_mode();
$consent_manager    = 'consent' === $privacy_mode ? CU_Analytics::consent_manager_detected() : '';
$dc_filter          = CU_Analytics::datacenter_filter_active();
$show_ip            = CU_Analytics::live_ip_allowed();
$reliable_help      = $dc_filter
	? __( 'Trafic humain probable : robots déclarés, navigateurs automatisés, requêtes sans en-têtes de navigateur, datacenters et proxy/VPN (détectés par le fournisseur IP externe) sont exclus.', 'cyberpush-ultra' )
	: __( 'Trafic humain probable : robots déclarés, navigateurs automatisés et requêtes sans en-têtes de navigateur sont exclus. Le filtrage datacenter / VPN est inactif (aucun fournisseur IP externe, ou mode exempté) : ce trafic peut rester compté.', 'cyberpush-ultra' );
?>
<div class="cu-page" id="cu-analytics-page"
	data-realtime-refresh="<?php echo esc_attr( $realtime_refresh ); ?>"
	data-cache-min="<?php echo esc_attr( $cache_min ); ?>"
	data-privacy-mode="<?php echo esc_attr( $privacy_mode ); ?>"
	data-dc-filter="<?php echo $dc_filter ? '1' : '0'; ?>"
	data-show-ip="<?php echo $show_ip ? '1' : '0'; ?>">

	<!-- ============================================================
	     BARRE DE CONTRÔLE
	     ============================================================ -->
	<div class="cu-page-title">
		<div>
			<h1><?php echo CU_Icons::get( 'chart' ); // phpcs:ignore ?> <?php esc_html_e( 'Analytics Pro', 'cyberpush-ultra' ); ?></h1>
			<p><?php
				if ( $excluded_countries ) {
					printf(
						/* translators: %1$s: excluded countries, %2$d: cache minutes */
						esc_html__( 'Exclusions actives : %1$s — Cache : %2$d min', 'cyberpush-ultra' ),
						esc_html( $excluded_countries ),
						absint( $cache_min )
					);
				}
			?></p>
		</div>
		<div class="cu-toolbar" id="cu-analytics-toolbar">
			<label class="cu-field" style="margin:0;flex-direction:row;align-items:center;gap:8px;">
				<span style="font-size:12px;font-weight:700;color:var(--cu-text-dim)"><?php esc_html_e( 'Période', 'cyberpush-ultra' ); ?></span>
				<select id="cu-range" class="cu-select">
					<option value="7"><?php esc_html_e( '7 jours', 'cyberpush-ultra' ); ?></option>
					<option value="14"><?php esc_html_e( '14 jours', 'cyberpush-ultra' ); ?></option>
					<option value="30" selected><?php esc_html_e( '30 jours', 'cyberpush-ultra' ); ?></option>
					<option value="60"><?php esc_html_e( '60 jours', 'cyberpush-ultra' ); ?></option>
					<option value="90"><?php esc_html_e( '90 jours', 'cyberpush-ultra' ); ?></option>
				</select>
			</label>
			<label class="cu-field" style="margin:0;flex-direction:row;align-items:center;gap:8px;">
				<span style="font-size:12px;font-weight:700;color:var(--cu-text-dim)"><?php esc_html_e( 'Segment', 'cyberpush-ultra' ); ?></span>
				<select id="cu-segment" class="cu-select">
					<option value="reliable"><?php esc_html_e( 'Trafic fiable', 'cyberpush-ultra' ); ?></option>
					<option value="suspect"><?php esc_html_e( 'Suspect / bots', 'cyberpush-ultra' ); ?></option>
					<option value="all"><?php esc_html_e( 'Tout', 'cyberpush-ultra' ); ?></option>
				</select>
			</label>
			<span class="description" title="<?php echo esc_attr( __( 'Les rapports Contenu, SEO et WooCommerce utilisent toujours les agrégats du trafic fiable.', 'cyberpush-ultra' ) . ' ' . $reliable_help ); ?>">
				<?php esc_html_e( 'Contenu / SEO / Woo : trafic fiable', 'cyberpush-ultra' ); ?>
			</span>
			<button id="cu-btn-refresh" class="cu-btn">
				<?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?>
				<?php esc_html_e( 'Actualiser', 'cyberpush-ultra' ); ?>
			</button>
			<button id="cu-btn-export-csv" class="cu-btn">
				<?php echo CU_Icons::get( 'download' ); // phpcs:ignore ?>
				<?php esc_html_e( 'Export CSV', 'cyberpush-ultra' ); ?>
			</button>
		</div>
	</div>

	<!-- ============================================================
	     CONFIDENTIALITÉ : mode actif, dit honnêtement
	     ============================================================ -->
	<?php
	if ( 'consent' === $privacy_mode && '' === $consent_manager ) {
		$privacy_class = 'cu-alert-strip is-danger cu-privacy-strip';
		$privacy_icon  = 'alert';
		$privacy_title = CU_Settings::get( 'analytics', 'anonymous_fallback', true )
			? __( 'Mode consentement : aucun gestionnaire compatible détecté côté serveur, les visites sans accord sont mesurées de façon anonyme (page par page).', 'cyberpush-ultra' )
			: __( 'Mode consentement : aucun gestionnaire compatible détecté côté serveur, seules les visites ayant accepté sont comptées.', 'cyberpush-ultra' );
		$privacy_text  = __( 'Installez un bandeau compatible (Complianz, CookieYes, Cookie Notice, ou tout bandeau relié à WP Consent API), ou faites appeler window.cuAnalytics.consent(true) par votre bandeau après l’accord « Statistiques ». Sinon, passez en mode exempté dans Réglages > Analytics.', 'cyberpush-ultra' );
	} elseif ( 'consent' === $privacy_mode ) {
		$privacy_class = 'cu-alert-strip cu-privacy-strip';
		$privacy_icon  = 'shield-check';
		/* translators: %s: nom du gestionnaire de consentement */
		$privacy_title = sprintf( __( 'Mode consentement (gestionnaire détecté : %s)', 'cyberpush-ultra' ), $consent_manager );
		$privacy_text  = CU_Settings::get( 'analytics', 'anonymous_fallback', true )
			? __( 'Visites suivies en continu après l’accord « Statistiques » ; sans accord, chaque page est comptée de façon anonyme (sans cookie, stockage ni identifiant). Rebond, durée, parcours et fidélité ne portent que sur les visites suivies ; leur part est indiquée près des indicateurs.', 'cyberpush-ultra' )
			: __( 'Rien n’est collecté ni stocké dans le navigateur avant l’accord « Statistiques » ; un retrait efface les identifiants. Les chiffres ne couvrent donc que les visiteurs qui ont accepté.', 'cyberpush-ultra' );
	} elseif ( 'standard' === $privacy_mode ) {
		$privacy_class = 'cu-alert-strip is-warning cu-privacy-strip';
		$privacy_icon  = 'alert';
		$privacy_title = __( 'Mode standard : aucune restriction automatique.', 'cyberpush-ultra' );
		$privacy_text  = $show_ip
			? __( 'La conformité RGPD (base légale, information, consentement éventuel) relève de votre responsabilité. Les adresses IP des visiteurs en ligne sont affichées (conservées chiffrées, effacées 1 h après leur dernière activité).', 'cyberpush-ultra' )
			: __( 'La conformité RGPD (base légale, information, consentement éventuel) relève de votre responsabilité.', 'cyberpush-ultra' );
	} else {
		$privacy_class = 'cu-alert-strip cu-privacy-strip';
		$privacy_icon  = 'shield-check';
		$privacy_title = __( 'Mode exempté (mesure d’audience sans consentement)', 'cyberpush-ultra' );
		$privacy_text  = __( 'Aucune IP affichée ni conservée, aucune géolocalisation externe, URL sans paramètres (hors utm_*), référent réduit au domaine, termes de recherche non stockés. Le signal Global Privacy Control est respecté ; ajoutez le shortcode [cyberpush_optout] à votre politique de confidentialité pour permettre l’opposition.', 'cyberpush-ultra' );
	}
	?>
	<div class="<?php echo esc_attr( $privacy_class ); ?>" id="cu-privacy-mode" role="note">
		<span class="cu-alert-strip-ico"><?php echo CU_Icons::get( $privacy_icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<div class="cu-alert-strip-text"><strong><?php echo esc_html( $privacy_title ); ?></strong> <?php echo esc_html( $privacy_text ); ?></div>
	</div>

	<!-- ============================================================
	     EN BREF (carte d'ouverture — jour / semaine / mois / année)
	     ============================================================ -->
	<div class="cu-brief" id="cu-brief">
		<div class="cu-brief-head">
			<span class="cu-brief-eyebrow"><?php echo CU_Icons::get( 'bolt' ); // phpcs:ignore ?> <?php esc_html_e( 'En bref', 'cyberpush-ultra' ); ?></span>
			<div class="cu-brief-periods" id="cu-brief-periods" role="tablist" aria-label="<?php esc_attr_e( 'Période de la carte En bref', 'cyberpush-ultra' ); ?>">
				<button type="button" class="cu-brief-pill" data-period="day" role="tab" aria-selected="false" aria-controls="cu-brief-body" tabindex="-1"><?php esc_html_e( 'Jour', 'cyberpush-ultra' ); ?></button>
				<button type="button" class="cu-brief-pill is-active" data-period="week" role="tab" aria-selected="true" aria-controls="cu-brief-body" tabindex="0"><?php esc_html_e( 'Semaine', 'cyberpush-ultra' ); ?></button>
				<button type="button" class="cu-brief-pill" data-period="month" role="tab" aria-selected="false" aria-controls="cu-brief-body" tabindex="-1"><?php esc_html_e( 'Mois', 'cyberpush-ultra' ); ?></button>
				<button type="button" class="cu-brief-pill" data-period="year" role="tab" aria-selected="false" aria-controls="cu-brief-body" tabindex="-1"><?php esc_html_e( 'Année', 'cyberpush-ultra' ); ?></button>
			</div>
		</div>
		<div class="cu-brief-body" id="cu-brief-body" role="tabpanel" aria-live="polite">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     TEMPS RÉEL
	     ============================================================ -->
	<div class="cu-section" id="cu-section-realtime">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'pulse' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Temps réel — 5 dernières minutes', 'cyberpush-ultra' ); ?>
			<span id="cu-realtime-count" class="cu-pill cu-pill-success" style="margin-left:8px;">0</span>
			<span id="cu-realtime-suspect" style="margin-left:8px;font-size:11px;color:var(--cu-text-dim);"></span>
		</div>
		<div class="cu-card" style="padding:14px 18px;">
			<div id="cu-realtime-list" class="cu-live">
				<div class="cu-loading"><div class="cu-spinner"></div> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></div>
			</div>
			<div class="cu-rt-foot">
				<span id="cu-realtime-last" class="cu-rt-last"></span>
				<button type="button" class="cu-btn cu-btn-sm" id="cu-tracking-check">
					<?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Vérifier le suivi', 'cyberpush-ultra' ); ?>
				</button>
			</div>
			<ul id="cu-tracking-report" class="cu-check-list" aria-live="polite" hidden></ul>
		</div>
	</div>

	<!-- ============================================================
	     KPI PRINCIPAUX
	     ============================================================ -->
	<div class="cu-section" id="cu-section-kpi">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'bolt' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Indicateurs clés', 'cyberpush-ultra' ); ?>
			<span id="cu-traffic-quality" style="font-weight:400;font-size:12px;margin-left:10px;" title="<?php echo esc_attr( $reliable_help ); ?>"></span>
		</div>
		<?php // 8 indicateurs → 4 colonnes = 2 lignes complètes (6 colonnes laissaient 2 orphelins). ?>
		<div class="cu-grid cu-grid-4" id="cu-kpi-grid">
			<?php
			$kpis = array(
				array( 'id' => 'sessions',     'label' => __( 'Visites', 'cyberpush-ultra' ),          'icon' => 'pulse',        'help' => __( 'Visites suivies en continu + arrivées anonymes (visiteurs sans consentement, comptés à leur arrivée sur le site, sans identifiant).', 'cyberpush-ultra' ) . ' ' . $reliable_help ),
				array( 'id' => 'pageviews',    'label' => __( 'Pages vues', 'cyberpush-ultra' ),        'icon' => 'eye',          'help' => __( 'Toutes les pages affichées, visites suivies et pages anonymes comprises.', 'cyberpush-ultra' ) ),
				array( 'id' => 'pages_session','label' => __( 'Pages / visite', 'cyberpush-ultra' ),    'icon' => 'file-text',    'help' => __( 'Calculé sur les visites suivies en continu uniquement (une visite anonyme ne peut pas être suivie de page en page).', 'cyberpush-ultra' ) ),
				array( 'id' => 'avg_duration', 'label' => __( 'Durée moy.', 'cyberpush-ultra' ),        'icon' => 'clock',        'help' => __( 'Temps moyen par visite, calculé sur les visites suivies en continu uniquement.', 'cyberpush-ultra' ) ),
				array( 'id' => 'bounce_rate',  'label' => __( 'Taux de rebond', 'cyberpush-ultra' ),    'icon' => 'trending-up',  'help' => __( 'Part des visites à une seule page, calculée sur les visites suivies en continu uniquement. Plus c\'est BAS, mieux c\'est.', 'cyberpush-ultra' ) ),
				array( 'id' => 'cta_clicks',   'label' => __( 'Clics CTA', 'cyberpush-ultra' ),         'icon' => 'bolt',         'help' => __( 'Clics sur vos boutons d\'action (boutons, .btn, ajouter au panier…), pages anonymes comprises.', 'cyberpush-ultra' ) ),
				array( 'id' => 'ctr',          'label' => __( 'CTR CTA', 'cyberpush-ultra' ),           'icon' => 'link',         'help' => __( 'Visites avec au moins un clic CTA ÷ visites, calculé sur les visites suivies en continu uniquement.', 'cyberpush-ultra' ) ),
				array( 'id' => 'conversions',  'label' => __( 'Conversions déclarées', 'cyberpush-ultra' ), 'icon' => 'sparkles', 'help' => __( 'Objectifs signalés par le navigateur (non vérifiés) : formulaires marqués data-cu-conversion, envois Contact Form 7 vus par le navigateur et, si activé, clics WhatsApp (simple intention de contact). En dessous : les conversions CONFIRMÉES par le serveur (messages Contact Form 7 réellement partis, commandes WooCommerce payées), toutes visites confondues — les deux chiffres ne s’additionnent pas.', 'cyberpush-ultra' ) ),
			);
			foreach ( $kpis as $kpi ) :
			?>
			<div class="cu-card cu-kpi" id="cu-kpi-<?php echo esc_attr( $kpi['id'] ); ?>">
				<div class="cu-kpi-label">
					<?php echo CU_Icons::get( $kpi['icon'] ); // phpcs:ignore ?>
					<?php echo esc_html( $kpi['label'] ); ?>
					<span class="cu-help" tabindex="0" role="img" aria-label="<?php echo esc_attr( $kpi['help'] ); ?>" title="<?php echo esc_attr( $kpi['help'] ); ?>">?</span>
				</div>
				<div class="cu-kpi-value">—</div>
				<div class="cu-kpi-delta-wrap"></div>
				<div class="cu-kpi-spark"></div>
				<?php if ( 'conversions' === $kpi['id'] ) : ?>
				<div class="cu-kpi-note" id="cu-kpi-conversions-confirmed" aria-live="polite"></div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- ============================================================
	     CONTACTS & LEADS (WhatsApp + Contact Form 7) — cu-contacts.js
	     ============================================================ -->
	<div class="cu-section" id="cu-section-contacts">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'message' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php esc_html_e( 'Contacts & leads', 'cyberpush-ultra' ); ?>
			<span class="cu-section-hint"><?php esc_html_e( 'qui vous écrit sur WhatsApp ou via vos formulaires, d’où, quand et après quoi', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-ct" id="cu-contacts" aria-busy="true">
			<div class="cu-ct-skeleton" aria-hidden="true">
				<div class="cu-skel cu-skel-num"></div>
				<div class="cu-skel cu-skel-line" style="width:64%"></div>
				<div class="cu-skel cu-ct-skel-chart"></div>
			</div>
		</div>
	</div>

	<!-- ============================================================
	     SANTÉ DU TRAFIC (ring gauges animées)
	     ============================================================ -->
	<div class="cu-section" id="cu-section-health">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Santé du trafic', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card cu-gauges">
			<div class="cu-gauge-item">
				<span class="cu-gauge-title"><?php esc_html_e( 'Humain réel', 'cyberpush-ultra' ); ?></span>
				<div class="cu-ring"><canvas id="cu-gauge-human" aria-hidden="true"></canvas>
					<div class="cu-ring-center"><span class="cu-ring-val" id="cu-gauge-human-val">—</span><span class="cu-ring-cap"><?php esc_html_e( 'humain', 'cyberpush-ultra' ); ?></span></div>
				</div>
				<span class="cu-gauge-sub" id="cu-gauge-human-sub"></span>
			</div>
			<div class="cu-gauge-item">
				<span class="cu-gauge-title"><?php esc_html_e( 'Taux de rebond', 'cyberpush-ultra' ); ?></span>
				<div class="cu-ring"><canvas id="cu-gauge-bounce" aria-hidden="true"></canvas>
					<div class="cu-ring-center"><span class="cu-ring-val" id="cu-gauge-bounce-val">—</span><span class="cu-ring-cap"><?php esc_html_e( 'rebond', 'cyberpush-ultra' ); ?></span></div>
				</div>
				<span class="cu-gauge-sub"><?php esc_html_e( 'plus bas = mieux', 'cyberpush-ultra' ); ?></span>
			</div>
			<div class="cu-gauge-item">
				<span class="cu-gauge-title"><?php esc_html_e( 'Engagement CTA', 'cyberpush-ultra' ); ?></span>
				<div class="cu-ring"><canvas id="cu-gauge-ctr" aria-hidden="true"></canvas>
					<div class="cu-ring-center"><span class="cu-ring-val" id="cu-gauge-ctr-val">—</span><span class="cu-ring-cap"><?php esc_html_e( 'CTR', 'cyberpush-ultra' ); ?></span></div>
				</div>
				<span class="cu-gauge-sub"><?php esc_html_e( 'visites avec clic / visites suivies', 'cyberpush-ultra' ); ?></span>
			</div>
		</div>
	</div>

	<!-- ============================================================
	     RÉPARTITION DU TRAFIC PAR TYPE DE CONTENU
	     ============================================================ -->
	<div class="cu-section" id="cu-section-breakdown">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'chart' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Trafic par type de contenu', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" id="cu-breakdown-list">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     GRAPHIQUE D'ÉVOLUTION
	     ============================================================ -->
	<div class="cu-section" id="cu-section-timeseries">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'trending-up' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Évolution dans le temps', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card">
			<canvas id="cu-chart-timeseries" role="img" aria-label="<?php esc_attr_e( 'Graphique de l’évolution des visites sur la période choisie (totaux ci-dessous).', 'cyberpush-ultra' ); ?>" style="width:100%;height:260px;display:block;"></canvas>
		</div>
		<div style="display:flex;gap:20px;margin-top:10px;flex-wrap:wrap;" id="cu-timeseries-legend"></div>
	</div>

	<!-- ============================================================
	     HEATMAP JOUR × HEURE
	     ============================================================ -->
	<div class="cu-section" id="cu-section-heatmap">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'clock' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Arrivées des visiteurs par heure et jour', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card cu-scroll-region" style="overflow-x:auto;">
			<div id="cu-heatmap-container" style="min-width:700px;"></div>
		</div>
	</div>

	<!-- ============================================================
	     TABLEAU DE PERFORMANCE DU CONTENU
	     ============================================================ -->
	<div class="cu-section" id="cu-section-content">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'file-text' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Performance du contenu', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" style="padding:14px;">
			<div class="cu-toolbar" style="margin-bottom:14px;">
				<input type="text" id="cu-content-search" class="cu-input" placeholder="<?php esc_attr_e( 'Rechercher un titre…', 'cyberpush-ultra' ); ?>" style="flex:1;max-width:300px;">
				<select id="cu-content-type" class="cu-select" aria-label="<?php esc_attr_e( 'Type de contenu', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Tous les types', 'cyberpush-ultra' ); ?></option>
					<?php
					$post_types = get_post_types( array( 'public' => true ), 'objects' );
					foreach ( $post_types as $pt ) {
						echo '<option value="' . esc_attr( $pt->name ) . '">' . esc_html( $pt->labels->name ) . '</option>';
					}
					?>
				</select>
				<select id="cu-content-sort" class="cu-select" aria-label="<?php esc_attr_e( 'Trier les contenus par', 'cyberpush-ultra' ); ?>">
					<option value="sessions"><?php esc_html_e( 'Visites', 'cyberpush-ultra' ); ?></option>
					<option value="pageviews"><?php esc_html_e( 'Pages vues', 'cyberpush-ultra' ); ?></option>
					<option value="bounces"><?php esc_html_e( 'Rebonds', 'cyberpush-ultra' ); ?></option>
					<option value="cta_clicks"><?php esc_html_e( 'CTA', 'cyberpush-ultra' ); ?></option>
					<option value="conversions"><?php esc_html_e( 'Conversions', 'cyberpush-ultra' ); ?></option>
					<option value="entries"><?php esc_html_e( 'Entrées', 'cyberpush-ultra' ); ?></option>
					<option value="exits"><?php esc_html_e( 'Sorties', 'cyberpush-ultra' ); ?></option>
				</select>
			</div>
			<div class="cu-table-wrap" id="cu-content-table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Tableau des performances des contenus', 'cyberpush-ultra' ); ?>">
				<div class="cu-loading"><div class="cu-spinner"></div></div>
			</div>
			<div class="cu-pagination" id="cu-content-pagination"></div>
		</div>
	</div>

	<!-- ============================================================
	     PAGES D'ENTRÉE / SORTIE
	     ============================================================ -->
	<div class="cu-section" id="cu-section-entry-exit">
		<div class="cu-grid cu-grid-2">
			<div id="cu-section-entry">
				<div class="cu-section-title">
					<?php echo CU_Icons::get( 'link' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Pages d\'entrée', 'cyberpush-ultra' ); ?>
				</div>
				<div class="cu-card" id="cu-entry-list">
					<div class="cu-loading"><div class="cu-spinner"></div></div>
				</div>
			</div>
			<div id="cu-section-exit">
				<div class="cu-section-title">
					<?php echo CU_Icons::get( 'flag' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Pages de sortie', 'cyberpush-ultra' ); ?>
				</div>
				<div class="cu-card" id="cu-exit-list">
					<div class="cu-loading"><div class="cu-spinner"></div></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================================================
	     NOUVEAUX VS FIDÈLES
	     ============================================================ -->
	<div class="cu-section" id="cu-section-loyalty">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'bell' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Fidélité des visiteurs', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" id="cu-loyalty-content">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     SCROLL DEPTH
	     ============================================================ -->
	<div class="cu-section" id="cu-section-scroll">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'eye' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Profondeur de lecture (scroll)', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" id="cu-scroll-content">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     RÉPARTITION TECHNIQUE (3 DONUTS)
	     ============================================================ -->
	<div class="cu-section" id="cu-section-tech">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'gear' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Répartition technique', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-grid cu-grid-3">
			<?php
			$tech_sections = array(
				array( 'key' => 'devices',  'label' => __( 'Appareils', 'cyberpush-ultra' ) ),
				array( 'key' => 'browsers', 'label' => __( 'Navigateurs', 'cyberpush-ultra' ) ),
				array( 'key' => 'os',       'label' => __( 'Systèmes', 'cyberpush-ultra' ) ),
			);
			foreach ( $tech_sections as $ts ) :
			?>
			<div class="cu-card" style="display:flex;flex-direction:column;align-items:center;gap:12px;">
				<div style="font-size:12px;font-weight:700;color:var(--cu-text-dim);text-transform:uppercase;letter-spacing:.3px;"><?php echo esc_html( $ts['label'] ); ?></div>
				<canvas id="cu-donut-<?php echo esc_attr( $ts['key'] ); ?>" width="160" height="160" style="display:block;" role="img" aria-label="<?php /* translators: %s: Appareils, Navigateurs ou Systèmes */ echo esc_attr( sprintf( __( 'Répartition des visites : %s (détail dans la légende).', 'cyberpush-ultra' ), $ts['label'] ) ); ?>" aria-describedby="cu-donut-legend-<?php echo esc_attr( $ts['key'] ); ?>"></canvas>
				<div id="cu-donut-legend-<?php echo esc_attr( $ts['key'] ); ?>" style="width:100%;font-size:12px;"></div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- ============================================================
	     PAYS
	     ============================================================ -->
	<div class="cu-section" id="cu-section-countries">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'globe' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Pays', 'cyberpush-ultra' ); ?>
			<span class="cu-section-hint"><?php esc_html_e( 'd’où viennent vos visiteurs et lesquels vous contactent', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-card cu-geo" id="cu-countries-list">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     CLICS SORTANTS (liens externes, téléchargements, appels, e-mails)
	     ============================================================ -->
	<div class="cu-section" id="cu-section-outbound">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'link' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Clics sortants', 'cyberpush-ultra' ); ?>
			<span class="cu-section-hint"><?php esc_html_e( 'liens vers d’autres sites, téléchargements, appels et e-mails (WhatsApp : voir Contacts & leads)', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-card cu-ob" id="cu-outbound">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     LANGUES ET ÉCRANS
	     ============================================================ -->
	<div class="cu-section" id="cu-section-audience">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'globe' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Langues et écrans', 'cyberpush-ultra' ); ?>
			<span class="cu-section-hint"><?php esc_html_e( 'langue réglée dans le navigateur, taille de l’écran', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-grid cu-grid-2">
			<div class="cu-card">
				<div class="cu-tech-title"><?php esc_html_e( 'Langues', 'cyberpush-ultra' ); ?></div>
				<div id="cu-audience-languages"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
			</div>
			<div class="cu-card">
				<div class="cu-tech-title"><?php esc_html_e( 'Écrans', 'cyberpush-ultra' ); ?></div>
				<div id="cu-audience-screens"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
			</div>
		</div>
	</div>

	<!-- ============================================================
	     SOURCES DE TRAFIC
	     ============================================================ -->
	<div class="cu-section" id="cu-section-sources">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'link' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Sources de trafic', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" id="cu-sources-list">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     TOP DOMAINES RÉFÉRENTS (vrais domaines externes)
	     ============================================================ -->
	<div class="cu-section" id="cu-section-referrers">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'link' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Top domaines référents', 'cyberpush-ultra' ); ?>
			<span class="cu-section-hint" style="font-weight:400;color:var(--cu-text-dim);font-size:12px;margin-left:8px;"><?php esc_html_e( 'les sites externes qui vous envoient du trafic', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-card" id="cu-referrers-list">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     TRAFIC IA (ChatGPT, Perplexity, Claude…) — tendance 2026
	     ============================================================ -->
	<div class="cu-section" id="cu-section-ai">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'sparkles' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Trafic IA', 'cyberpush-ultra' ); ?>
			<span class="cu-section-hint" style="font-weight:400;color:var(--cu-text-dim);font-size:12px;margin-left:8px;"><?php esc_html_e( 'visiteurs venus de ChatGPT, Perplexity, Claude… + passages de robots IA déclarés par leur User-Agent', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-card" id="cu-ai-traffic">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     PAYS LES PLUS RÉCURRENTS (fidélité géographique)
	     ============================================================ -->
	<div class="cu-section" id="cu-section-recountries">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'globe' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Pays les plus récurrents', 'cyberpush-ultra' ); ?>
			<span class="cu-section-hint" style="font-weight:400;color:var(--cu-text-dim);font-size:12px;margin-left:8px;"><?php esc_html_e( 'part de visiteurs qui reviennent, par pays', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-card" id="cu-recountries-list">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     TOP PARCOURS DE NAVIGATION
	     ============================================================ -->
	<div class="cu-section" id="cu-section-paths">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'link' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Top parcours de navigation', 'cyberpush-ultra' ); ?>
			<span class="cu-section-hint" style="font-weight:400;color:var(--cu-text-dim);font-size:12px;margin-left:8px;"><?php esc_html_e( 'les chemins réels des visiteurs (page → page → page)', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-card" id="cu-paths-list">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     ERREURS JAVASCRIPT CÔTÉ VISITEUR
	     ============================================================ -->
	<div class="cu-section" id="cu-section-jserrors">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'gear' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Erreurs JavaScript', 'cyberpush-ultra' ); ?>
			<span class="cu-section-hint" style="font-weight:400;color:var(--cu-text-dim);font-size:12px;margin-left:8px;"><?php esc_html_e( 'les erreurs réellement rencontrées par vos visiteurs', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-card" id="cu-jserrors-list">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<!-- ============================================================
	     CATÉGORIES / TAGS / AUTEURS
	     ============================================================ -->
	<div class="cu-section" id="cu-section-taxonomies">
		<div class="cu-grid cu-grid-3">
			<div>
				<div class="cu-section-title">
					<?php echo CU_Icons::get( 'list' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Catégories', 'cyberpush-ultra' ); ?>
				</div>
				<div class="cu-card" id="cu-categories-list">
					<div class="cu-loading"><div class="cu-spinner"></div></div>
				</div>
			</div>
			<div>
				<div class="cu-section-title">
					<?php echo CU_Icons::get( 'search' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Tags', 'cyberpush-ultra' ); ?>
				</div>
				<div class="cu-card" id="cu-tags-list">
					<div class="cu-loading"><div class="cu-spinner"></div></div>
				</div>
			</div>
			<div>
				<div class="cu-section-title">
					<?php echo CU_Icons::get( 'key' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Auteurs', 'cyberpush-ultra' ); ?>
				</div>
				<div class="cu-card" id="cu-authors-list">
					<div class="cu-loading"><div class="cu-spinner"></div></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================================================
	     WOOCOMMERCE (conditionnel)
	     ============================================================ -->
	<?php if ( $woo_active ) : ?>
	<div class="cu-section" id="cu-section-woo">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'cart' ); // phpcs:ignore ?>
			<?php esc_html_e( 'WooCommerce', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" id="cu-woo-content">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>
	<?php endif; ?>

	<!-- ============================================================
	     INSIGHTS AUTOMATIQUES
	     ============================================================ -->
	<div class="cu-section" id="cu-section-insights">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'sparkles' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Insights automatiques', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-grid cu-grid-3" id="cu-insights-grid">
			<div class="cu-loading"><div class="cu-spinner"></div></div>
		</div>
	</div>

	<?php if ( CU_Geo::lookup_configured() && '' !== CU_Geo::local_db_path() ) : ?>
		<p class="cu-section-hint" style="color:var(--cu-text-dim);font-size:12px;margin-top:16px;">
			<?php echo wp_kses( __( 'Pays des visiteurs : <a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">IP Geolocation by DB-IP</a> — base installée sur votre serveur, l’adresse IP n’est ni enregistrée ni transmise.', 'cyberpush-ultra' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?>
		</p>
	<?php endif; ?>

</div><!-- .cu-page -->

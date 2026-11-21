<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Centre de contrôle : vue d'ensemble de tous les modules, alimentée uniquement
 * par les routes REST existantes (aucun calcul ni moteur propre à cette page).
 */
$cu_links = array(
	'analytics' => admin_url( 'admin.php?page=cyberpush-ultra' ),
	'firewall'  => admin_url( 'admin.php?page=cyberpush-ultra-firewall' ),
	'spam'      => admin_url( 'admin.php?page=cyberpush-ultra-spam' ),
	'seo'       => admin_url( 'admin.php?page=cyberpush-ultra-seo' ),
	'indexnow'  => admin_url( 'admin.php?page=cyberpush-ultra-indexnow' ),
	'logs'      => admin_url( 'admin.php?page=cyberpush-ultra-logs' ),
	'settings'  => admin_url( 'admin.php?page=cyberpush-ultra-settings' ),
);
?>
<div class="cu-page cu-db" id="cu-dashboard-page" data-links="<?php echo esc_attr( wp_json_encode( $cu_links ) ); ?>">

	<div class="cu-page-title">
		<div>
			<h1><?php echo CU_Icons::get( 'radar' ); // phpcs:ignore ?> <?php esc_html_e( 'Centre de contrôle', 'cyberpush-ultra' ); ?></h1>
			<p><?php esc_html_e( 'Audience, contacts, sécurité, vulnérabilités, connexions, anti-spam et SEO de votre site, en un coup d’œil. Chaque carte mène au détail.', 'cyberpush-ultra' ); ?></p>
		</div>
		<div class="cu-toolbar">
			<div class="cu-db-period" role="group" aria-label="<?php esc_attr_e( 'Période', 'cyberpush-ultra' ); ?>">
				<button type="button" data-db-range="7" aria-pressed="true"><?php esc_html_e( '7 jours', 'cyberpush-ultra' ); ?></button>
				<button type="button" data-db-range="30" aria-pressed="false"><?php esc_html_e( '30 jours', 'cyberpush-ultra' ); ?></button>
			</div>
			<span class="cu-db-updated" id="cu-db-updated"></span>
			<button class="cu-btn cu-btn-sm" id="cu-db-refresh"><?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?> <?php esc_html_e( 'Actualiser', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- Points d'attention : ce qui demande une action, tous modules confondus -->
	<div class="cu-card cu-db-attention" id="cu-db-attention" aria-live="polite">
		<div class="cu-loading"><div class="cu-spinner"></div></div>
	</div>

	<!-- État des modules -->
	<div class="cu-db-status" id="cu-db-status">
		<?php
		$cu_tiles = array(
			'firewall' => array( 'shield-check', __( 'Pare-feu', 'cyberpush-ultra' ), $cu_links['firewall'] ),
			'vulns'    => array( 'bug', __( 'Vulnérabilités', 'cyberpush-ultra' ), $cu_links['firewall'] . '#cu-vulns-section' ),
			'logins'   => array( 'key', __( 'Connexions', 'cyberpush-ultra' ), $cu_links['firewall'] . '#cu-logins-section' ),
			'fim'      => array( 'file-text', __( 'Intégrité des fichiers', 'cyberpush-ultra' ), $cu_links['firewall'] . '#cu-audit-section' ),
			'spam'     => array( 'message', __( 'Anti-spam', 'cyberpush-ultra' ), $cu_links['spam'] ),
			'seo'      => array( 'search', __( 'SEO', 'cyberpush-ultra' ), $cu_links['seo'] ),
			'indexnow' => array( 'rocket', __( 'IndexNow', 'cyberpush-ultra' ), $cu_links['indexnow'] ),
		);
		foreach ( $cu_tiles as $cu_key => $cu_tile ) :
			?>
			<a class="cu-card cu-db-tile is-loading" id="cu-db-tile-<?php echo esc_attr( $cu_key ); ?>" href="<?php echo esc_url( $cu_tile[2] ); ?>">
				<span class="cu-db-tile-head"><span class="cu-db-tile-ico"><?php echo CU_Icons::get( $cu_tile[0] ); // phpcs:ignore ?></span><span class="cu-db-tile-name"><?php echo esc_html( $cu_tile[1] ); ?></span><span class="cu-db-dot" aria-hidden="true"></span></span>
				<span class="cu-db-tile-value">—</span>
				<span class="cu-db-tile-sub">&nbsp;</span>
			</a>
		<?php endforeach; ?>
	</div>

	<!-- Audience -->
	<div class="cu-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'chart' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Audience', 'cyberpush-ultra' ); ?>
			<a class="cu-db-more" href="<?php echo esc_url( $cu_links['analytics'] ); ?>"><?php esc_html_e( 'Ouvrir Analytics', 'cyberpush-ultra' ); ?> →</a>
		</div>
		<div class="cu-db-grid cu-db-grid-audience">
			<div class="cu-card cu-db-chart-card">
				<div class="cu-db-card-head">
					<div><div class="cu-db-card-title" id="cu-db-traffic-title"><?php esc_html_e( 'Visites par jour', 'cyberpush-ultra' ); ?></div><div class="cu-db-card-sub" id="cu-db-traffic-sub"></div></div>
					<div class="cu-db-legend" id="cu-db-traffic-legend"></div>
				</div>
				<div class="cu-db-chart" id="cu-db-traffic"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
			</div>
			<div class="cu-db-kpis" id="cu-db-kpis">
				<?php
				foreach ( array(
					'visits'    => __( 'Visites', 'cyberpush-ultra' ),
					'pageviews' => __( 'Pages vues', 'cyberpush-ultra' ),
					'online'    => __( 'En ligne maintenant', 'cyberpush-ultra' ),
					'duration'  => __( 'Durée moyenne', 'cyberpush-ultra' ),
					'bounce'    => __( 'Taux de rebond', 'cyberpush-ultra' ),
					'human'     => __( 'Trafic humain', 'cyberpush-ultra' ),
				) as $cu_k => $cu_label ) :
					?>
					<div class="cu-card cu-db-kpi" id="cu-db-kpi-<?php echo esc_attr( $cu_k ); ?>"><span class="cu-db-kpi-label"><?php echo esc_html( $cu_label ); ?></span><span class="cu-db-kpi-value">—</span><span class="cu-db-kpi-sub">&nbsp;</span></div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<!-- Contacts, pays, clics sortants -->
	<div class="cu-db-grid cu-db-grid-3">
		<div class="cu-section">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'message' ); // phpcs:ignore ?> <?php esc_html_e( 'Contacts & leads', 'cyberpush-ultra' ); ?><a class="cu-db-more" href="<?php echo esc_url( $cu_links['analytics'] . '#cu-section-contacts' ); ?>"><?php esc_html_e( 'Détail', 'cyberpush-ultra' ); ?> →</a></div>
			<div class="cu-card cu-db-card" id="cu-db-contacts"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
		</div>
		<div class="cu-section">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'globe' ); // phpcs:ignore ?> <?php esc_html_e( 'Pays des visiteurs', 'cyberpush-ultra' ); ?><a class="cu-db-more" href="<?php echo esc_url( $cu_links['analytics'] . '#cu-section-countries' ); ?>"><?php esc_html_e( 'Carte complète', 'cyberpush-ultra' ); ?> →</a></div>
			<div class="cu-card cu-db-card" id="cu-db-countries"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
		</div>
		<div class="cu-section">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'link' ); // phpcs:ignore ?> <?php esc_html_e( 'Sources et clics sortants', 'cyberpush-ultra' ); ?><a class="cu-db-more" href="<?php echo esc_url( $cu_links['analytics'] . '#cu-section-outbound' ); ?>"><?php esc_html_e( 'Détail', 'cyberpush-ultra' ); ?> →</a></div>
			<div class="cu-card cu-db-card" id="cu-db-outbound"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
		</div>
	</div>

	<!-- Sécurité -->
	<div class="cu-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'shield' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Sécurité', 'cyberpush-ultra' ); ?>
			<a class="cu-db-more" href="<?php echo esc_url( $cu_links['firewall'] ); ?>"><?php esc_html_e( 'Ouvrir le Firewall', 'cyberpush-ultra' ); ?> →</a>
		</div>
		<div class="cu-db-grid cu-db-grid-security">
			<div class="cu-card cu-db-chart-card">
				<div class="cu-db-card-head">
					<div><div class="cu-db-card-title"><?php esc_html_e( 'Attaques bloquées — 14 jours', 'cyberpush-ultra' ); ?></div><div class="cu-db-card-sub" id="cu-db-attacks-sub"></div></div>
				</div>
				<div class="cu-db-chart cu-db-chart-sm" id="cu-db-attacks"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
				<div class="cu-db-mini-kpis" id="cu-db-fw-kpis"></div>
			</div>
			<div class="cu-card cu-db-card">
				<div class="cu-db-card-head"><div class="cu-db-card-title"><?php esc_html_e( 'Connexions aux comptes administrateurs', 'cyberpush-ultra' ); ?></div><a class="cu-db-more" href="<?php echo esc_url( $cu_links['firewall'] . '#cu-logins-section' ); ?>"><?php esc_html_e( 'Journal', 'cyberpush-ultra' ); ?> →</a></div>
				<div id="cu-db-logins"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
			</div>
			<div class="cu-card cu-db-card">
				<div class="cu-db-card-head"><div class="cu-db-card-title"><?php esc_html_e( 'Événements de sécurité récents', 'cyberpush-ultra' ); ?></div><a class="cu-db-more" href="<?php echo esc_url( $cu_links['firewall'] . '#cu-audit-section' ); ?>"><?php esc_html_e( 'Journal', 'cyberpush-ultra' ); ?> →</a></div>
				<div id="cu-db-audit"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
			</div>
		</div>
	</div>

	<!-- Vulnérabilités, SEO, système -->
	<div class="cu-db-grid cu-db-grid-3">
		<div class="cu-section">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'bug' ); // phpcs:ignore ?> <?php esc_html_e( 'Vulnérabilités & versions', 'cyberpush-ultra' ); ?><a class="cu-db-more" href="<?php echo esc_url( $cu_links['firewall'] . '#cu-vulns-section' ); ?>"><?php esc_html_e( 'Détail', 'cyberpush-ultra' ); ?> →</a></div>
			<div class="cu-card cu-db-card" id="cu-db-vulns"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
		</div>
		<div class="cu-section">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'search' ); // phpcs:ignore ?> <?php esc_html_e( 'Santé SEO', 'cyberpush-ultra' ); ?><a class="cu-db-more" href="<?php echo esc_url( $cu_links['seo'] ); ?>"><?php esc_html_e( 'Ouvrir SEO', 'cyberpush-ultra' ); ?> →</a></div>
			<div class="cu-card cu-db-card" id="cu-db-seo"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
		</div>
		<div class="cu-section">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'gear' ); // phpcs:ignore ?> <?php esc_html_e( 'Anti-spam et système', 'cyberpush-ultra' ); ?><a class="cu-db-more" href="<?php echo esc_url( $cu_links['logs'] ); ?>"><?php esc_html_e( 'Logs', 'cyberpush-ultra' ); ?> →</a></div>
			<div class="cu-card cu-db-card" id="cu-db-system"><div class="cu-loading"><div class="cu-spinner"></div></div></div>
		</div>
	</div>

</div>

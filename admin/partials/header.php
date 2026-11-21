<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** @var string $active */
$tabs = array(
	'dashboard' => array( 'label' => __( 'Dashboard', 'cyberpush-ultra' ), 'icon' => 'radar', 'url' => admin_url( 'admin.php?page=cyberpush-ultra-dashboard' ) ),
	'analytics' => array( 'label' => __( 'Analytics', 'cyberpush-ultra' ), 'icon' => 'chart', 'url' => admin_url( 'admin.php?page=cyberpush-ultra' ) ),
	'firewall'  => array( 'label' => __( 'Firewall', 'cyberpush-ultra' ), 'icon' => 'shield', 'url' => admin_url( 'admin.php?page=cyberpush-ultra-firewall' ) ),
	'spam'      => array( 'label' => __( 'Spam', 'cyberpush-ultra' ), 'icon' => 'message', 'url' => admin_url( 'admin.php?page=cyberpush-ultra-spam' ) ),
	'seo'       => array( 'label' => __( 'SEO', 'cyberpush-ultra' ), 'icon' => 'search', 'url' => admin_url( 'admin.php?page=cyberpush-ultra-seo' ) ),
	'indexnow'  => array( 'label' => __( 'Index Now', 'cyberpush-ultra' ), 'icon' => 'rocket', 'url' => admin_url( 'admin.php?page=cyberpush-ultra-indexnow' ) ),
	'settings'  => array( 'label' => __( 'Réglages', 'cyberpush-ultra' ), 'icon' => 'gear', 'url' => admin_url( 'admin.php?page=cyberpush-ultra-settings' ) ),
	'logs'      => array( 'label' => __( 'Logs', 'cyberpush-ultra' ), 'icon' => 'list', 'url' => admin_url( 'admin.php?page=cyberpush-ultra-logs' ) ),
);
?>
<div class="cu-wrap" data-cu-page="<?php echo esc_attr( $active ); ?>">
	<div class="cu-topbar">
		<div class="cu-brand">
			<span class="cu-brand-icon"><?php echo CU_Icons::get( 'cyber-logo', 'cu-icon cu-brand-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<div class="cu-brand-text">
				<strong>CyberPush</strong><span>Ultra</span>
			</div>
		</div>
		<nav class="cu-tabs">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<a href="<?php echo esc_url( $tab['url'] ); ?>" class="cu-tab <?php echo $active === $key ? 'is-active' : ''; ?>">
					<?php echo CU_Icons::get( $tab['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo esc_html( $tab['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
	</div>
	<?php // .cu-wrap reste ouvert : le contenu de la vue vit à l'intérieur pour hériter des variables CSS du thème. Il est fermé par admin/partials/footer.php. ?>

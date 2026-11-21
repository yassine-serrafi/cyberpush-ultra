<?php
/**
 * Plugin Name:       CyberPush Ultra
 * Plugin URI:        https://cyberpush.io
 * Description:       Analytics, pare-feu et SEO intégrés à WordPress, avec services externes optionnels clairement configurables.
 * Version:           1.3.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            CyberPush
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cyberpush-ultra
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Accès direct interdit.
}

// ---------------------------------------------------------------------
// Garde anti-conflit : une seconde copie du plugin (ancienne version encore
// active) ou une autre extension déclarant déjà nos noms provoquerait une
// erreur fatale (« Cannot declare class »). On se retire proprement à la place.
// IMPORTANT : ce fichier ne doit déclarer AUCUNE classe. PHP déclare dès la
// compilation les classes écrites au niveau supérieur d'un fichier : elles
// existeraient avant cette garde, qui croirait alors à un doublon.
// ---------------------------------------------------------------------
$cu_name_conflicts = array();
if ( defined( 'CU_VERSION' ) ) {
	$cu_name_conflicts[] = 'CU_VERSION';
}
foreach ( array( 'CU_Plugin', 'CU_Settings', 'CU_DB', 'CU_Text', 'CU_Logger', 'CU_Error_Logger', 'CU_Icons', 'CU_Geo', 'CU_Protection', 'CU_AI', 'CU_Cron', 'CU_Activator', 'CU_Deactivator', 'CU_REST_Core', 'CU_SEO_Renderer', 'CU_SEO_Readability', 'CU_SEO_Audit', 'CU_SEO_Knowledge', 'CU_SEO_Recommender', 'CU_Analytics', 'CU_Firewall', 'CU_Spam', 'CU_Spam_CF7', 'CU_Audit', 'CU_SEO', 'CU_IndexNow', 'CU_Tracker', 'CU_Admin' ) as $cu_class_name ) {
	if ( class_exists( $cu_class_name, false ) ) {
		$cu_name_conflicts[] = $cu_class_name;
	}
}
if ( $cu_name_conflicts ) {
	add_action( 'admin_notices', function () use ( $cu_name_conflicts ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>CyberPush Ultra</strong> — '
			. esc_html( sprintf(
				/* translators: %s: noms déjà déclarés. */
				__( 'non chargé : une autre extension (ou une seconde copie de CyberPush Ultra) déclare déjà %s. Désactivez le doublon pour éviter une erreur fatale.', 'cyberpush-ultra' ),
				implode( ', ', array_slice( $cu_name_conflicts, 0, 5 ) )
			) )
			. '</p></div>';
	} );
	unset( $cu_class_name );
	return;
}
unset( $cu_name_conflicts, $cu_class_name );

// ---------------------------------------------------------------------
// Constantes
// ---------------------------------------------------------------------
define( 'CU_VERSION', '1.3.4' );
define( 'CU_DB_VERSION', '1.9.4' );
define( 'CU_PLUGIN_FILE', __FILE__ );
define( 'CU_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CU_TEXT_DOMAIN', 'cyberpush-ultra' );
define( 'CU_REST_NAMESPACE', 'cyberpush-ultra/v1' );
define( 'CU_OPTION_SETTINGS', 'cu_settings' );
define( 'CU_OPTION_DB_VERSION', 'cu_db_version' );
define( 'CU_OPTION_INSTALLED_AT', 'cu_installed_at' );

// ---------------------------------------------------------------------
// Includes — cœur partagé (lu par tous les modules)
// ---------------------------------------------------------------------
// Capture des erreurs du plugin activée AU PLUS TÔT (avant le reste des
// includes) afin d'intercepter aussi d'éventuelles erreurs de chargement.
require_once CU_PLUGIN_DIR . 'includes/class-cu-error-logger.php';
CU_Error_Logger::instance()->init();

require_once CU_PLUGIN_DIR . 'includes/class-cu-db.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-text.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-settings.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-logger.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-icons.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-geo.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-protection.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-ai.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-seo-renderer.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-seo-readability.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-seo-audit.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-seo-knowledge.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-seo-recommender.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-cron.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-activator.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-deactivator.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-rest-core.php';

// ---------------------------------------------------------------------
// Modules métier
// ---------------------------------------------------------------------
require_once CU_PLUGIN_DIR . 'modules/class-cu-analytics.php';
require_once CU_PLUGIN_DIR . 'modules/class-cu-firewall.php';
// Anti-spam des formulaires : décisions (module) + seul adaptateur de formulaires (CF7).
require_once CU_PLUGIN_DIR . 'modules/class-cu-spam.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-spam-cf7.php';
require_once CU_PLUGIN_DIR . 'includes/class-cu-spam-wp.php';
require_once CU_PLUGIN_DIR . 'modules/class-cu-audit.php';
require_once CU_PLUGIN_DIR . 'modules/class-cu-vulns.php';
require_once CU_PLUGIN_DIR . 'modules/class-cu-logins.php';
require_once CU_PLUGIN_DIR . 'modules/class-cu-seo.php';
require_once CU_PLUGIN_DIR . 'modules/class-cu-indexnow.php';

// ---------------------------------------------------------------------
// Partie publique (suivi front-end + pare-feu, toujours chargée)
// ---------------------------------------------------------------------
require_once CU_PLUGIN_DIR . 'public/class-cu-tracker.php';

// ---------------------------------------------------------------------
// Administration (uniquement dans wp-admin)
// ---------------------------------------------------------------------
if ( is_admin() ) {
	require_once CU_PLUGIN_DIR . 'admin/class-cu-admin.php';
}

// ---------------------------------------------------------------------
// Activation / désactivation
// ---------------------------------------------------------------------
register_activation_hook( CU_PLUGIN_FILE, array( 'CU_Activator', 'activate' ) );
register_deactivation_hook( CU_PLUGIN_FILE, array( 'CU_Deactivator', 'deactivate' ) );

// ---------------------------------------------------------------------
// Classe principale (fichier séparé : voir includes/class-cu-plugin.php)
// ---------------------------------------------------------------------
require_once CU_PLUGIN_DIR . 'includes/class-cu-plugin.php';

// Priorité 5 : on veut être chargés avant la majorité des extensions tierces.
add_action(
	'plugins_loaded',
	function () {
		CU_Plugin::instance()->run();
	},
	5
);

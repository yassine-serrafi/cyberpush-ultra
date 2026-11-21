<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CU_Deactivator {

	/**
	 * La désactivation ne supprime jamais les données (Analytics, Firewall,
	 * SEO). Seules les tâches planifiées sont arrêtées. La suppression
	 * définitive des données se fait uniquement via uninstall.php si
	 * l'utilisateur active l'option correspondante dans Réglages > Sécurité.
	 */
	public static function deactivate() {
		CU_Cron::instance()->unschedule_events();
		wp_clear_scheduled_hook( CU_Geo::LOCAL_DB_HOOK );
		// Le fichier de preuve IndexNow à la racine n'a plus de raison d'exister
		// quand le plugin est inactif (il est recréé à la réactivation).
		if ( method_exists( 'CU_IndexNow', 'on_deactivate' ) ) {
			CU_IndexNow::on_deactivate();
		}
		flush_rewrite_rules();
	}
}

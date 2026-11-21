<?php
/**
 * Exécuté uniquement lors de la SUPPRESSION du plugin (pas à la désactivation).
 * Les données ne sont supprimées que si l'option "Suppression des données à
 * la désinstallation" a été explicitement activée dans Réglages > Sécurité.
 * Sur un multisite, chaque site applique son propre réglage.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Supprime les données CyberPush du site courant si son réglage l'autorise. */
function cu_uninstall_current_site() {
	global $wpdb;

	$settings = get_option( 'cu_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();
	$erase    = isset( $settings['security'] ) && is_array( $settings['security'] ) && array_key_exists( 'erase_data_on_uninstall', $settings['security'] )
		? $settings['security']['erase_data_on_uninstall']
		: false;
	// Effacement uniquement sur un « oui » explicite : une chaîne « false »,
	// « no », « 0 » ou « off » (option modifiée à la main, import ancien) est
	// une chaîne non vide, donc « vraie » pour PHP, mais signifie bien NON.
	$erase = ( true === $erase || 1 === $erase || ( is_string( $erase ) && in_array( strtolower( trim( $erase ) ), array( '1', 'true', 'yes', 'on' ), true ) ) );

	// Filet de sécurité : la désactivation retire déjà ces tâches, mais une
	// désactivation interrompue ne doit laisser aucun événement orphelin, même
	// lorsque les données sont conservées (le code qui les traite disparaît).
	foreach ( array( 'cu_cron_rollup_daily', 'cu_cron_analytics_enrich', 'cu_cron_analytics_enrich_now', 'cu_cron_seo_scan_cycle', 'cu_cron_firewall_maintenance', 'cu_cron_cleanup', 'cu_cron_audit_scan', 'cu_cron_audit_scan_continue', 'cu_cron_indexnow_flush', 'cu_fw_rdns_verify', 'cu_cron_geo_update', 'cu_cron_vuln_sync', 'cu_cron_vuln_continue' ) as $cu_hook ) {
		wp_clear_scheduled_hook( $cu_hook );
	}

	// Base locale IP → pays : simple copie d'une base publique téléchargée (8 Mo),
	// pas une donnée du site. Retirée dans tous les cas, pour ne pas laisser un
	// fichier inutile ; elle est retéléchargée si le plugin est réinstallé.
	$cu_uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
	if ( ! empty( $cu_uploads['basedir'] ) ) {
		foreach ( (array) glob( rtrim( $cu_uploads['basedir'], '/\\' ) . '/cyberpush-ultra/geo-country*' ) as $cu_geo_file ) {
			@unlink( $cu_geo_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	delete_option( 'cu_geo_local_db' );

	if ( ! $erase ) {
		return;
	}

	// Le fichier de preuve IndexNow est créé par le plugin à la racine du
	// site. Ne supprimer qu'une clé au format généré par le plugin et seulement
	// si le contenu correspond, afin de ne jamais toucher à un fichier utilisateur.
	$indexnow_key = isset( $settings['indexnow']['key'] ) ? (string) $settings['indexnow']['key'] : '';
	if ( preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $indexnow_key ) ) {
		$key_file = trailingslashit( ABSPATH ) . $indexnow_key . '.txt';
		if ( is_file( $key_file ) && trim( (string) @file_get_contents( $key_file ) ) === $indexnow_key ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			@unlink( $key_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	$tables = array(
		'cu_sessions',
		'cu_events',
		'cu_content_daily',
		'cu_firewall_logs',
		'cu_firewall_blocks',
		'cu_firewall_whitelist',
		'cu_firewall_watch',
		'cu_firewall_audit',
		'cu_seo_pages',
		'cu_seo_issues',
		'cu_seo_meta',
		'cu_seo_links',
		'cu_seo_history',
		'cu_seo_urls',
		'cu_seo_queue',
		'cu_logs',
		'cu_contacts',
		'cu_fim',
		'cu_spam_log',
		'cu_logins',
	);

	foreach ( $tables as $short ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $short ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	foreach ( array(
		'cu_settings',
		'cu_db_version',
		'cu_installed_at',
		'cu_seo_previous_summary',
		'cu_seo_site_audit',
		'cu_seo_ai_summary',
		'cu_indexnow_history',
		'cu_indexnow_stats',
		'cu_indexnow_verified',
		'cu_audit_baseline',
		'cu_audit_scan_state',
		'cu_audit_last_scan',
		'cu_audit_core_check',
		'cu_ip_salt',
		'cu_analytics_cache_epoch',
		'cu_rollup_daily_lock',
		'cu_audit_scope',
		'cu_audit_trusted',
		'cu_ai_tokens_used',
		'cu_upgrade_lock',
		'cu_ip_salt_daily',
		'cu_rollup_cursor',
		'cu_analytics_minimised',
		'cu_seo_run_lock',
		'cu_seo_discovery',
		'cu_seo_serper_state',
		'cu_audit_fim_state',
		'cu_audit_scan_lock',
		'cu_audit_rebaseline',
		'cu_audit_forensics',
		'cu_audit_prev_recipient',
		'cu_indexnow_key_host',
		'cu_indexnow_sent',
		'cu_indexnow_queue',
		'cu_spam_since',
		'cu_spam_last_error',
		'cu_spam_disposable_list',
		'cu_spam_disposable_meta',
		'cu_fw_rdns_queue',
		'cu_fw_admin_relays',
		'cu_vuln_state',
		'cu_logins_baseline',
		'cu_vuln_notified',
		'cu_vuln_server_sw',
		'cu_spam_wp_rendered',
		'cu_geo_local_db',
	) as $option ) {
		delete_option( $option );
	}
	// Avis « Vulnérabilités » masqué par chaque administrateur.
	delete_metadata( 'user', 0, 'cu_vuln_dismissed', '', true );
	// Protection des connexions : adresses connues (empreintes), sessions vues, avis lus.
	foreach ( array( 'cu_known_ips', 'cu_known_countries', 'cu_session_seen', 'cu_logins_seen' ) as $cu_meta ) {
		delete_metadata( 'user', 0, $cu_meta, '', true );
	}
	// Module Spam : toute autre option « cu_spam_* » (les transients sont traités ci-dessous).
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'cu_spam_' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	// Compteurs de débit (cu_ctr_*) et verrous courts (cu_lock_*) : état technique éphémère.
	foreach ( array( 'cu_ctr_', 'cu_lock_' ) as $cu_prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $cu_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// Supprimer les transients du plugin, y compris ceux dont la clé est dynamique
	// (cache Analytics, géolocalisation, limites de débit et verrous cron).
	$transient_like = $wpdb->esc_like( '_transient_cu_' ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_cu_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $transient_like, $timeout_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// Le journal de secours n'est pas en base. Lorsqu'une suppression complète
	// est demandée, supprimer aussi ses fichiers (dossier dédié uniquement).
	$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
	if ( ! empty( $uploads['basedir'] ) ) {
		$error_dir = rtrim( $uploads['basedir'], '/\\' ) . '/cyberpush-ultra';
		foreach ( (array) glob( $error_dir . '/error-*.*' ) as $error_file ) {
			@unlink( $error_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		// Base locale IP → pays (et téléchargement interrompu éventuel).
		foreach ( (array) glob( $error_dir . '/geo-country*' ) as $geo_file ) {
			@unlink( $geo_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $protection_file ) {
			$path = $error_dir . '/' . $protection_file;
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $error_dir ); // Supprime uniquement le dossier dédié s'il est vide.
	}
}

if ( is_multisite() ) {
	// Chaque site possède ses propres tables (préfixe), options et tâches cron.
	$cu_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $cu_site_ids as $cu_site_id ) {
		switch_to_blog( (int) $cu_site_id );
		cu_uninstall_current_site();
		restore_current_blog();
	}
} else {
	cu_uninstall_current_site();
}

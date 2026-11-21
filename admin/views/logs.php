<?php
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="cu-page" id="cu-logs-page">
	<div class="cu-page-title">
		<div>
			<h1><?php echo CU_Icons::get( 'list' ); ?> <?php esc_html_e( 'Logs', 'cyberpush-ultra' ); ?></h1>
			<p><?php esc_html_e( 'Chronologie unifiée de toute l’activité : Analytics, Firewall, SEO, Intelligence Artificielle, WooCommerce et système.', 'cyberpush-ultra' ); ?></p>
		</div>
		<div class="cu-toolbar">
			<button class="cu-btn" id="cu-logs-export"><?php echo CU_Icons::get( 'download' ); ?> <?php esc_html_e( 'Exporter (CSV)', 'cyberpush-ultra' ); ?></button>
			<button class="cu-btn cu-btn-danger" id="cu-logs-purge"><?php echo CU_Icons::get( 'trash' ); ?> <?php esc_html_e( 'Purger (conserver 24h)', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<div class="cu-grid cu-grid-4" id="cu-logs-summary">
		<div class="cu-card cu-kpi">
			<span class="cu-kpi-label"><?php esc_html_e( 'Total des événements', 'cyberpush-ultra' ); ?></span>
			<span class="cu-kpi-value" data-field="total">–</span>
		</div>
		<div class="cu-card cu-kpi">
			<span class="cu-kpi-label"><?php esc_html_e( 'Dernières 24h', 'cyberpush-ultra' ); ?></span>
			<span class="cu-kpi-value" data-field="last_24h">–</span>
		</div>
		<div class="cu-card cu-kpi">
			<span class="cu-kpi-label"><?php esc_html_e( 'Avertissements / erreurs', 'cyberpush-ultra' ); ?></span>
			<span class="cu-kpi-value" data-field="warnings">–</span>
		</div>
		<div class="cu-card cu-kpi">
			<span class="cu-kpi-label"><?php esc_html_e( 'Module le plus actif', 'cyberpush-ultra' ); ?></span>
			<span class="cu-kpi-value" data-field="top_module">–</span>
		</div>
	</div>

	<div class="cu-section">
		<div class="cu-card">
			<div class="cu-toolbar" style="margin-bottom:16px;">
				<select class="cu-select" id="cu-logs-filter-module" aria-label="<?php esc_attr_e( 'Filtrer par module', 'cyberpush-ultra' ); ?>">
					<option value="all"><?php esc_html_e( 'Tous les modules', 'cyberpush-ultra' ); ?></option>
					<option value="analytics"><?php esc_html_e( 'Analytics', 'cyberpush-ultra' ); ?></option>
					<option value="firewall"><?php esc_html_e( 'Firewall', 'cyberpush-ultra' ); ?></option>
					<option value="seo">SEO</option>
					<option value="ai"><?php esc_html_e( 'Intelligence Artificielle', 'cyberpush-ultra' ); ?></option>
					<option value="woocommerce">WooCommerce</option>
					<option value="system"><?php esc_html_e( 'Système', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select" id="cu-logs-filter-level" aria-label="<?php esc_attr_e( 'Filtrer par niveau', 'cyberpush-ultra' ); ?>">
					<option value="all"><?php esc_html_e( 'Tous les niveaux', 'cyberpush-ultra' ); ?></option>
					<option value="debug"><?php esc_html_e( 'Débogage', 'cyberpush-ultra' ); ?></option>
					<option value="info"><?php esc_html_e( 'Info', 'cyberpush-ultra' ); ?></option>
					<option value="warning"><?php esc_html_e( 'Avertissement', 'cyberpush-ultra' ); ?></option>
					<option value="error"><?php esc_html_e( 'Erreur', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select" id="cu-logs-filter-range" aria-label="<?php esc_attr_e( 'Période', 'cyberpush-ultra' ); ?>">
					<option value="0"><?php esc_html_e( 'Toute la période', 'cyberpush-ultra' ); ?></option>
					<option value="1"><?php esc_html_e( 'Dernière heure', 'cyberpush-ultra' ); ?></option>
					<option value="24" selected><?php esc_html_e( 'Dernières 24h', 'cyberpush-ultra' ); ?></option>
					<option value="168"><?php esc_html_e( '7 derniers jours', 'cyberpush-ultra' ); ?></option>
				</select>
				<input type="search" class="cu-input" id="cu-logs-search" placeholder="<?php esc_attr_e( 'Rechercher dans les messages…', 'cyberpush-ultra' ); ?>" style="flex:1;min-width:200px;">
			</div>
			<div class="cu-table-wrap">
				<table class="cu-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Module', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Niveau', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Message', 'cyberpush-ultra' ); ?></th>
						</tr>
					</thead>
					<tbody id="cu-logs-tbody">
						<tr><td colspan="4" class="cu-empty"><span class="cu-spinner"></span></td></tr>
					</tbody>
				</table>
			</div>
			<div class="cu-pagination" id="cu-logs-pagination"></div>
		</div>
	</div>

	<div class="cu-section">
		<div class="cu-card">
			<div class="cu-section-title">
				<?php echo CU_Icons::get( 'bug' ); ?>
				<?php esc_html_e( 'Erreurs fatales PHP — fichier de secours', 'cyberpush-ultra' ); ?>
				<span class="cu-badge cu-badge-gray" id="cu-logs-fallback-count">0</span>
			</div>
			<p class="cu-field-row-text">
				<span><?php esc_html_e( 'Erreurs fatales provoquées par CyberPush Ultra (exceptions non capturées comprises) et échecs d’écriture de la chronologie. Ce journal reste disponible même lorsqu’une panne SQL empêche l’enregistrement dans la chronologie principale. Une même erreur est notée au plus toutes les 10 minutes, 1 Mo par jour au maximum, conservé 30 jours. Les avertissements non fatals restent dans le journal PHP du serveur.', 'cyberpush-ultra' ); ?></span>
			</p>
			<p class="cu-field-row-text">
				<span><?php esc_html_e( 'Chronologie : un même message répété dans la minute est regroupé sur une seule ligne (badge « ×N »).', 'cyberpush-ultra' ); ?></span>
			</p>
			<div class="cu-toolbar" style="margin-bottom:12px;">
				<button class="cu-btn" id="cu-logs-fallback-refresh"><?php echo CU_Icons::get( 'refresh' ); ?> <?php esc_html_e( 'Actualiser', 'cyberpush-ultra' ); ?></button>
			</div>
			<pre id="cu-logs-fallback" style="max-height:320px;overflow:auto;background:var(--cu-bg-soft);border:1px solid var(--cu-panel-border);border-radius:8px;padding:12px;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word;color:var(--cu-text-dim);margin:0;"><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></pre>
		</div>
	</div>
</div>

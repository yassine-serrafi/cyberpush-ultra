<?php
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="cu-page" id="cu-firewall-page">

	<!-- Bandeau d'état -->
	<div class="cu-page-title">
		<div>
			<h1><?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?> <?php esc_html_e( 'Firewall Pro', 'cyberpush-ultra' ); ?></h1>
			<p><?php esc_html_e( 'Pare-feu applicatif — analyse chaque requête avant le rendu WordPress.', 'cyberpush-ultra' ); ?></p>
		</div>
		<div class="cu-toolbar">
			<span id="cu-fw-status-badge" class="cu-pill cu-pill-ghost"><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></span>
			<label class="cu-switch" title="<?php esc_attr_e( 'Activer / Désactiver le pare-feu', 'cyberpush-ultra' ); ?>">
				<input type="checkbox" id="cu-fw-toggle" disabled aria-label="<?php esc_attr_e( 'Activer le pare-feu', 'cyberpush-ultra' ); ?>">
				<span class="slider"></span>
			</label>
			<button class="cu-btn cu-btn-sm" id="cu-fw-refresh">
				<?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?>
				<?php esc_html_e( 'Actualiser', 'cyberpush-ultra' ); ?>
			</button>
		</div>
	</div>

	<?php if ( class_exists( 'CU_Firewall' ) && CU_Firewall::kill_switch() ) : ?>
	<div class="cu-alert-strip is-danger" role="status">
		<span class="cu-alert-strip-ico"><?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?></span>
		<div class="cu-alert-strip-text">
			<strong><?php esc_html_e( 'Pare-feu désactivé par la constante de secours.', 'cyberpush-ultra' ); ?></strong>
			<?php esc_html_e( 'wp-config.php contient CU_FIREWALL_DISABLED : aucune requête n’est filtrée. Retirez cette ligne dès que vous avez retrouvé l’accès.', 'cyberpush-ultra' ); ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- Dernière attaque -->
	<div id="cu-fw-last-attack" class="cu-alert-strip is-danger" style="display:none;">
		<span class="cu-alert-strip-ico"><?php echo CU_Icons::get( 'ban' ); // phpcs:ignore ?></span>
		<div class="cu-alert-strip-text">
			<strong><?php esc_html_e( 'Dernière attaque :', 'cyberpush-ultra' ); ?></strong>
			<span id="cu-fw-last-attack-detail"></span>
		</div>
	</div>

	<!-- KPI Grid -->
	<div class="cu-grid cu-fw-kpi-grid" id="cu-fw-kpi-grid" style="margin-bottom:24px;">
		<div class="cu-card cu-kpi">
			<div class="cu-kpi-label"><?php esc_html_e( 'Blocages actifs', 'cyberpush-ultra' ); ?></div>
			<div class="cu-kpi-value" id="cu-kpi-active-blocks">—</div>
		</div>
		<div class="cu-card cu-kpi">
			<div class="cu-kpi-label"><?php esc_html_e( 'Bans permanents', 'cyberpush-ultra' ); ?></div>
			<div class="cu-kpi-value" id="cu-kpi-permanent">—</div>
		</div>
		<div class="cu-card cu-kpi">
			<div class="cu-kpi-label"><?php esc_html_e( 'Attaques 24h', 'cyberpush-ultra' ); ?></div>
			<div class="cu-kpi-value" id="cu-kpi-attacks-24h">—</div>
			<div id="cu-kpi-attacks-delta"></div>
		</div>
		<div class="cu-card cu-kpi">
			<div class="cu-kpi-label"><?php esc_html_e( 'IP attaquantes uniques', 'cyberpush-ultra' ); ?></div>
			<div class="cu-kpi-value" id="cu-kpi-unique-ips">—</div>
		</div>
		<div class="cu-card cu-kpi">
			<div class="cu-kpi-label"><?php esc_html_e( 'Scans 404', 'cyberpush-ultra' ); ?></div>
			<div class="cu-kpi-value" id="cu-kpi-flood404">—</div>
		</div>
		<div class="cu-card cu-kpi">
			<div class="cu-kpi-label"><?php esc_html_e( 'Tentatives login', 'cyberpush-ultra' ); ?></div>
			<div class="cu-kpi-value" id="cu-kpi-login">—</div>
		</div>
		<div class="cu-card cu-kpi" title="<?php esc_attr_e( 'Crawlers de recherche authentifiés observés durant les dernières 24 heures. Une seule observation par IP et par heure est comptée.', 'cyberpush-ultra' ); ?>">
			<div class="cu-kpi-label"><?php esc_html_e( 'Crawlers vérifiés (24h)', 'cyberpush-ultra' ); ?></div>
			<div class="cu-kpi-value" id="cu-kpi-goodbot" style="color:var(--cu-success);">—</div>
		</div>
	</div>

	<!-- Graphique évolution attaques 14 jours -->
	<div class="cu-section" id="cu-section-attacks">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'pulse' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Évolution des attaques — 14 jours', 'cyberpush-ultra' ); ?>
			<span class="cu-live-badge" title="<?php esc_attr_e( 'Actualisé automatiquement chaque minute', 'cyberpush-ultra' ); ?>"><span class="cu-live-badge-dot" aria-hidden="true"></span><?php esc_html_e( 'En direct', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-card cu-ac-card">
			<div id="cu-fw-attacks"></div>
		</div>
	</div>

	<!-- Blocages actifs -->
	<div class="cu-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'ban' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Blocages actifs', 'cyberpush-ultra' ); ?>
			<span class="cu-pill" style="margin-left:auto;" id="cu-blocks-count-badge"></span>
		</div>
		<div class="cu-card" style="padding:0;">
			<div class="cu-toolbar" style="padding:14px 16px;border-bottom:1px solid var(--cu-panel-border);">
				<input type="text" class="cu-input" id="cu-blocks-search" placeholder="<?php esc_attr_e( 'Rechercher une IP…', 'cyberpush-ultra' ); ?>" style="width:220px;">
				<button class="cu-btn cu-btn-sm cu-btn-primary" id="cu-ban-open">
					<?php echo CU_Icons::get( 'plus-circle' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Bannir une IP', 'cyberpush-ultra' ); ?>
				</button>
			</div>
			<div class="cu-table-wrap">
				<table class="cu-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'IP / CIDR', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Pays', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Type', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Raison', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Hits', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Vu le', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Expire', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'cyberpush-ultra' ); ?></th>
						</tr>
					</thead>
					<tbody id="cu-blocks-tbody">
						<tr><td colspan="8" class="cu-loading"><span class="cu-spinner"></span><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
					</tbody>
				</table>
			</div>
			<div class="cu-pagination" id="cu-blocks-pagination" style="padding:12px 16px;"></div>
		</div>
	</div>

	<!-- Formulaire bannissement manuel (modal) -->
	<div id="cu-ban-modal" style="display:none;">
		<h2><?php esc_html_e( 'Bannir une IP manuellement', 'cyberpush-ultra' ); ?></h2>
		<div class="cu-field">
			<label for="cu-ban-ip"><?php esc_html_e( 'Adresse IP ou CIDR', 'cyberpush-ultra' ); ?></label>
			<input type="text" class="cu-input" id="cu-ban-ip" placeholder="203.0.113.7 ou 203.0.113.0/24" style="width:100%;">
			<p style="margin:6px 0 0;font-size:12px;color:var(--cu-text-dim);"><?php esc_html_e( 'Refusé automatiquement : votre propre adresse, celle du serveur ou de son relais (proxy/CDN), les adresses privées et « tout Internet ». Une plage plus large que /16 (IPv4) ou /32 (IPv6) demande une confirmation.', 'cyberpush-ultra' ); ?></p>
		</div>
		<div class="cu-field">
			<label><?php esc_html_e( 'Raison', 'cyberpush-ultra' ); ?></label>
			<input type="text" class="cu-input" id="cu-ban-reason" placeholder="<?php esc_attr_e( 'Ex: spam, scraping…', 'cyberpush-ultra' ); ?>" style="width:100%;">
		</div>
		<div class="cu-field">
			<label><?php esc_html_e( 'Durée', 'cyberpush-ultra' ); ?></label>
			<select class="cu-select" id="cu-ban-duration" style="width:100%;">
				<option value="15m"><?php esc_html_e( '15 minutes', 'cyberpush-ultra' ); ?></option>
				<option value="30m"><?php esc_html_e( '30 minutes', 'cyberpush-ultra' ); ?></option>
				<option value="1h"><?php esc_html_e( '1 heure', 'cyberpush-ultra' ); ?></option>
				<option value="6h"><?php esc_html_e( '6 heures', 'cyberpush-ultra' ); ?></option>
				<option value="12h"><?php esc_html_e( '12 heures', 'cyberpush-ultra' ); ?></option>
				<option value="24h" selected><?php esc_html_e( '24 heures', 'cyberpush-ultra' ); ?></option>
				<option value="7d"><?php esc_html_e( '7 jours', 'cyberpush-ultra' ); ?></option>
				<option value="30j"><?php esc_html_e( '30 jours', 'cyberpush-ultra' ); ?></option>
				<option value="permanent"><?php esc_html_e( 'Permanent', 'cyberpush-ultra' ); ?></option>
			</select>
		</div>
		<div class="cu-field">
			<label><?php esc_html_e( 'Commentaire (optionnel)', 'cyberpush-ultra' ); ?></label>
			<textarea class="cu-textarea" id="cu-ban-comment" rows="2" style="width:100%;"></textarea>
		</div>
		<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
			<button class="cu-btn" id="cu-ban-cancel"><?php esc_html_e( 'Annuler', 'cyberpush-ultra' ); ?></button>
			<button class="cu-btn cu-btn-primary" id="cu-ban-submit">
				<?php echo CU_Icons::get( 'ban' ); // phpcs:ignore ?>
				<?php esc_html_e( 'Bannir', 'cyberpush-ultra' ); ?>
			</button>
		</div>
	</div>

	<!-- Whitelist -->
	<div class="cu-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Whitelist IP', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" style="padding:0;">
			<div class="cu-toolbar" style="padding:14px 16px;border-bottom:1px solid var(--cu-panel-border);flex-wrap:wrap;gap:8px;">
				<input type="text" class="cu-input" id="cu-wl-ip" placeholder="<?php esc_attr_e( 'IP ou CIDR à autoriser', 'cyberpush-ultra' ); ?>" aria-label="<?php esc_attr_e( 'IP ou CIDR à autoriser', 'cyberpush-ultra' ); ?>" style="width:200px;">
				<input type="text" class="cu-input" id="cu-wl-label" placeholder="<?php esc_attr_e( 'Libellé (optionnel)', 'cyberpush-ultra' ); ?>" aria-label="<?php esc_attr_e( 'Libellé', 'cyberpush-ultra' ); ?>" style="width:200px;">
				<select class="cu-select" id="cu-wl-duration" aria-label="<?php esc_attr_e( 'Durée de l’exemption', 'cyberpush-ultra' ); ?>">
					<option value="permanent" selected><?php esc_html_e( 'Permanente', 'cyberpush-ultra' ); ?></option>
					<option value="24h"><?php esc_html_e( '24 heures', 'cyberpush-ultra' ); ?></option>
					<option value="7d"><?php esc_html_e( '7 jours', 'cyberpush-ultra' ); ?></option>
					<option value="30j"><?php esc_html_e( '30 jours', 'cyberpush-ultra' ); ?></option>
				</select>
				<button class="cu-btn cu-btn-sm cu-btn-primary" id="cu-wl-add">
					<?php echo CU_Icons::get( 'plus-circle' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Autoriser', 'cyberpush-ultra' ); ?>
				</button>
			</div>
			<p style="margin:0;padding:10px 16px 0;font-size:12px;color:var(--cu-text-dim);"><?php esc_html_e( 'Une adresse de la whitelist n’est jamais bloquée ni verrouillée. Préférez une durée limitée pour une IP dynamique, de VPN ou de Wi-Fi public : elle sera réattribuée à d’autres personnes. Les entrées expirées sont ignorées puis supprimées automatiquement.', 'cyberpush-ultra' ); ?></p>
			<div class="cu-table-wrap">
				<table class="cu-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'IP / CIDR', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Libellé', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Ajouté le', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Expire', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'cyberpush-ultra' ); ?></th>
						</tr>
					</thead>
					<tbody id="cu-wl-tbody">
						<tr><td colspan="5" class="cu-loading"><span class="cu-spinner"></span><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- Récupération d'accès (anti-verrouillage) -->
	<div class="cu-section" id="cu-fw-recovery">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'shield' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Comment le pare-feu répond, et comment retrouver l’accès', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card">
			<ul style="margin:0 0 12px 18px;padding:0;list-style:disc;color:var(--cu-text-dim);font-size:13px;line-height:1.6;">
				<li><?php esc_html_e( 'Requête suspecte isolée : refusée (403) sans bannissement. Un bannissement n’intervient qu’après plusieurs requêtes malveillantes distinctes de la même adresse en quelques minutes ; sa durée augmente en cas de récidive, plafonnée à 7 jours (24 h pour un simple excès de débit). Seul un administrateur peut bannir définitivement.', 'cyberpush-ultra' ); ?></li>
				<li><?php esc_html_e( 'Excès de débit : limite temporaire (429, « réessayez dans quelques instants »), puis bannissement seulement si le client insiste.', 'cyberpush-ultra' ); ?></li>
				<li><?php esc_html_e( 'Échecs de connexion répétés : seule la connexion est suspendue (le site reste consultable), d’abord pour la durée réglée, doublée à chaque récidive (24 h au plus). Pendant ce verrou, aucun compte ne peut se connecter depuis cette adresse, administrateurs compris.', 'cyberpush-ultra' ); ?></li>
				<li><?php esc_html_e( 'Jamais bloqués automatiquement : administrateurs connectés, adresses privées, serveur lui-même et ses relais déclarés, robots des moteurs de recherche vérifiés (un blocage manuel s’applique toutefois à eux).', 'cyberpush-ultra' ); ?></li>
			</ul>
			<p style="margin:0 0 8px;font-size:13px;"><strong><?php esc_html_e( 'Vous êtes bloqué hors de votre site ?', 'cyberpush-ultra' ); ?></strong> <?php esc_html_e( 'Attendez la fin du verrou, ou connectez-vous depuis une autre connexion (4G) pour débloquer votre adresse. En dernier recours, ajoutez cette ligne dans wp-config.php (par FTP ou le gestionnaire de fichiers de l’hébergeur), avant « C’est tout, ne touchez pas à ce qui suit » :', 'cyberpush-ultra' ); ?></p>
			<code style="display:block;padding:10px 12px;border-radius:8px;background:var(--cu-panel-2, rgba(0,0,0,.04));font-size:12.5px;user-select:all;">define( 'CU_FIREWALL_DISABLED', true );</code>
			<p style="margin:8px 0 0;font-size:12px;color:var(--cu-text-dim);"><?php esc_html_e( 'Le pare-feu est alors entièrement désactivé (aucun filtrage, aucun verrou). Débloquez votre adresse ou ajoutez-la à la whitelist, puis retirez la ligne.', 'cyberpush-ultra' ); ?></p>
		</div>
	</div>

	<!-- Pays de confiance -->
	<div class="cu-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'flag' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Pays de confiance', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card">
			<p style="color:var(--cu-text-dim);font-size:13px;margin-bottom:14px;">
				<?php esc_html_e( 'Les signaux comportementaux depuis ces pays sont journalisés sans blocage automatique. Les preuves fortes (WAF critique, sonde, scanner) restent bloquées ; un blocage manuel reste toujours possible.', 'cyberpush-ultra' ); ?>
			</p>
			<div class="cu-toolbar" style="margin-bottom:14px;">
				<input type="text" class="cu-input" id="cu-tc-add-code" placeholder="<?php esc_attr_e( 'Code pays ISO2 (ex: FR)', 'cyberpush-ultra' ); ?>" maxlength="2" style="width:210px;text-transform:uppercase;">
				<button class="cu-btn cu-btn-sm cu-btn-primary" id="cu-tc-add">
					<?php echo CU_Icons::get( 'plus-circle' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Ajouter', 'cyberpush-ultra' ); ?>
				</button>
			</div>
			<div id="cu-tc-list" style="display:flex;flex-wrap:wrap;gap:8px;min-height:32px;"></div>
		</div>
	</div>

	<!-- Top Attaquants -->
	<div class="cu-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'trending-up' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Top attaquants (24h)', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" style="padding:0;">
			<div class="cu-table-wrap">
				<table class="cu-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'IP', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Pays', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Attaques', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Types', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Dernière vue', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'cyberpush-ultra' ); ?></th>
						</tr>
					</thead>
					<tbody id="cu-top-attackers-tbody">
						<tr><td colspan="6" class="cu-loading"><span class="cu-spinner"></span><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- Modules de protection (config rapide) -->
	<div class="cu-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'gear' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Configuration rapide des modules', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" style="padding:0;">
			<div id="cu-modules-list">
				<div class="cu-loading"><span class="cu-spinner"></span><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></div>
			</div>
		</div>
	</div>

	<!-- Journal de sécurité -->
	<div class="cu-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'list' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Journal de sécurité', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card" style="padding:0;">
			<div class="cu-toolbar" style="padding:12px 16px;border-bottom:1px solid var(--cu-panel-border);flex-wrap:wrap;gap:8px;">
				<input type="text" class="cu-input cu-input-sm" id="cu-logs-search" placeholder="<?php esc_attr_e( 'Recherche…', 'cyberpush-ultra' ); ?>" style="width:160px;">
				<input type="text" class="cu-input cu-input-sm" id="cu-logs-ip" placeholder="<?php esc_attr_e( 'IP…', 'cyberpush-ultra' ); ?>" style="width:130px;">
				<select class="cu-select" id="cu-logs-status" style="font-size:12px;" aria-label="<?php esc_attr_e( 'Filtrer par décision', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Toutes décisions', 'cyberpush-ultra' ); ?></option>
					<option value="blocked"><?php esc_html_e( 'Bloqué', 'cyberpush-ultra' ); ?></option>
					<option value="allowed"><?php esc_html_e( 'Autorisé', 'cyberpush-ultra' ); ?></option>
					<option value="observed"><?php esc_html_e( 'Détecté', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select" id="cu-logs-type" style="font-size:12px;" aria-label="<?php esc_attr_e( 'Filtrer par type de règle', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Tous types', 'cyberpush-ultra' ); ?></option>
					<option value="waf"><?php esc_html_e( 'WAF (SQLi, XSS, RCE…)', 'cyberpush-ultra' ); ?></option>
					<option value="bad_bot"><?php esc_html_e( 'Bot malveillant', 'cyberpush-ultra' ); ?></option>
					<option value="user_enumeration"><?php esc_html_e( 'Énumération', 'cyberpush-ultra' ); ?></option>
					<option value="flood_404"><?php esc_html_e( 'Flood 404', 'cyberpush-ultra' ); ?></option>
					<option value="probe_detection"><?php esc_html_e( 'Sonde', 'cyberpush-ultra' ); ?></option>
					<option value="scanner_detection"><?php esc_html_e( 'Scanner', 'cyberpush-ultra' ); ?></option>
					<option value="login_protection"><?php esc_html_e( 'Login', 'cyberpush-ultra' ); ?></option>
					<option value="login_rate_limit"><?php esc_html_e( 'Limite de connexion (429)', 'cyberpush-ultra' ); ?></option>
					<option value="goodbot_verified"><?php esc_html_e( 'Crawler vérifié', 'cyberpush-ultra' ); ?></option>
					<option value="xmlrpc_protection"><?php esc_html_e( 'XML-RPC', 'cyberpush-ultra' ); ?></option>
					<option value="rate_limit"><?php esc_html_e( 'Rate limit', 'cyberpush-ultra' ); ?></option>
					<option value="upload_protection"><?php esc_html_e( 'Upload', 'cyberpush-ultra' ); ?></option>
					<option value="restapi_protection"><?php esc_html_e( 'REST API', 'cyberpush-ultra' ); ?></option>
					<option value="hotlink"><?php esc_html_e( 'Hotlink', 'cyberpush-ultra' ); ?></option>
					<option value="maintenance_mode"><?php esc_html_e( 'Maintenance', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select" id="cu-logs-range" style="font-size:12px;" aria-label="<?php esc_attr_e( 'Période du journal', 'cyberpush-ultra' ); ?>">
					<option value="24"><?php esc_html_e( '24 heures', 'cyberpush-ultra' ); ?></option>
					<option value="48"><?php esc_html_e( '48 heures', 'cyberpush-ultra' ); ?></option>
					<option value="168"><?php esc_html_e( '7 jours', 'cyberpush-ultra' ); ?></option>
					<option value="720"><?php esc_html_e( '30 jours', 'cyberpush-ultra' ); ?></option>
				</select>
				<button type="button" class="cu-btn cu-btn-sm" id="cu-logs-search-btn" aria-label="<?php esc_attr_e( 'Rechercher dans le journal', 'cyberpush-ultra' ); ?>">
					<?php echo CU_Icons::get( 'search' ); // phpcs:ignore ?>
				</button>
				<button class="cu-btn cu-btn-sm cu-btn-danger" id="cu-logs-purge" style="margin-left:auto;">
					<?php echo CU_Icons::get( 'trash' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Purger (garde 24h)', 'cyberpush-ultra' ); ?>
				</button>
			</div>
			<div class="cu-table-wrap">
				<table class="cu-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'IP', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Pays', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Méthode', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'URL', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Règle', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Décision', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Sévérité', 'cyberpush-ultra' ); ?></th>
						</tr>
					</thead>
					<tbody id="cu-logs-tbody">
						<tr><td colspan="8" class="cu-loading"><span class="cu-spinner"></span><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
					</tbody>
				</table>
			</div>
			<div class="cu-pagination" id="cu-logs-pagination" style="padding:12px 16px;"></div>
		</div>
	</div>

	<!-- Statistiques globales -->
	<div class="cu-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'database' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Statistiques globales', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-grid cu-grid-4" id="cu-global-stats-grid">
			<div class="cu-card cu-kpi">
				<div class="cu-kpi-label"><?php esc_html_e( 'Total attaques', 'cyberpush-ultra' ); ?></div>
				<div class="cu-kpi-value" id="cu-gs-total-blocked">—</div>
			</div>
			<div class="cu-card cu-kpi">
				<div class="cu-kpi-label"><?php esc_html_e( 'IP bloquées (total)', 'cyberpush-ultra' ); ?></div>
				<div class="cu-kpi-value" id="cu-gs-blocked-ips">—</div>
			</div>
			<div class="cu-card cu-kpi">
				<div class="cu-kpi-label" title="<?php esc_attr_e( 'Estimé à partir d’un échantillon des requêtes autorisées (1 sur 20), collecté uniquement en mode débogage (Réglages ▸ Développeur), URL sans paramètres.', 'cyberpush-ultra' ); ?>"><?php esc_html_e( 'Taux de blocage estimé', 'cyberpush-ultra' ); ?></div>
				<div class="cu-kpi-value" id="cu-gs-block-rate">—</div>
				<div id="cu-gs-block-rate-note" style="font-size:11px;color:var(--cu-text-dim);"></div>
			</div>
			<div class="cu-card cu-kpi">
				<div class="cu-kpi-label"><?php esc_html_e( 'Temps moy. entre attaques', 'cyberpush-ultra' ); ?></div>
				<div class="cu-kpi-value" id="cu-gs-avg-time">—</div>
			</div>
		</div>
		<div class="cu-grid cu-grid-2" style="margin-top:16px;">
			<div class="cu-card">
				<div class="cu-section-title" style="margin-bottom:10px;">
					<?php echo CU_Icons::get( 'globe' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Top pays attaquants', 'cyberpush-ultra' ); ?>
				</div>
				<div id="cu-gs-top-countries"></div>
			</div>
			<div class="cu-card">
				<div class="cu-section-title" style="margin-bottom:10px;">
					<?php echo CU_Icons::get( 'shield' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Top types d\'attaque', 'cyberpush-ultra' ); ?>
				</div>
				<div id="cu-gs-top-types"></div>
			</div>
		</div>
	</div>

	<!-- Radar — surveillance passive -->
	<div class="cu-section" id="cu-radar-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'radar' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Radar — surveillance passive', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card">
			<p style="margin:0 0 14px;color:var(--cu-text-dim);font-size:13px;">
				<?php esc_html_e( 'Le filet : les visiteurs suspects (datacenters, proxies, bots, comportements anormaux) sont observés sans être bloqués. Sélectionnez, copiez la liste, puis bloquez quand vous voulez — rien n\'est bloqué automatiquement, sauf si vous activez l\'auto-blocage ci-dessous.', 'cyberpush-ultra' ); ?>
			</p>
			<?php $cu_radar_enrich = class_exists( 'CU_Geo' ) && CU_Geo::remote_lookups_allowed(); ?>
			<div id="cu-radar-enrich-note" class="cu-alert-strip <?php echo $cu_radar_enrich ? 'is-info' : 'is-warning'; ?>" style="margin-bottom:14px;" data-enabled="<?php echo $cu_radar_enrich ? '1' : '0'; ?>">
				<div class="cu-alert-strip-text" style="font-size:13px;">
					<?php if ( $cu_radar_enrich ) : ?>
						<?php esc_html_e( 'Enrichissement réseau actif : les adresses IP des suspects sont transmises au service de géolocalisation externe choisi (ipwho.is) pour identifier datacenters et proxies — au plus 3 à l’ouverture de cette page, le reste en tâche de fond.', 'cyberpush-ultra' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Enrichissement réseau désactivé : aucune adresse IP n’est envoyée à un service tiers. Les colonnes Organisation, Datacenter et Proxy restent vides et l’auto-blocage (qui exige ces informations) ne bloque rien. Pour l’activer, choisissez un fournisseur de géolocalisation externe dans Réglages ▸ Analytics.', 'cyberpush-ultra' ); ?>
					<?php endif; ?>
				</div>
			</div>

			<!-- Auto-blocage intelligent (opt-in) -->
			<div class="cu-radar-autoblock" id="cu-radar-autoblock">
				<div class="cu-radar-ab-head">
					<label class="cu-in-switch" style="margin:0;">
						<input type="checkbox" id="cu-radar-ab-enabled">
						<span class="cu-in-slider"></span>
						<span class="cu-in-switch-label"><strong><?php esc_html_e( 'Auto-blocage intelligent', 'cyberpush-ultra' ); ?></strong></span>
					</label>
					<span class="cu-radar-ab-hint"><?php esc_html_e( 'Bloque tout seul les suspects enrichis à haut risque (datacenter/proxy). Crawlers vérifiés et liste blanche épargnés.', 'cyberpush-ultra' ); ?></span>
				</div>
				<div class="cu-radar-ab-opts" id="cu-radar-ab-opts">
					<label><?php esc_html_e( 'Seuil de score', 'cyberpush-ultra' ); ?>
						<select class="cu-select cu-select-sm" id="cu-radar-ab-score">
							<option value="80"><?php esc_html_e( '≥ 80 (large)', 'cyberpush-ultra' ); ?></option>
							<option value="85"><?php esc_html_e( '≥ 85', 'cyberpush-ultra' ); ?></option>
							<option value="90" selected><?php esc_html_e( '≥ 90 (recommandé)', 'cyberpush-ultra' ); ?></option>
							<option value="95"><?php esc_html_e( '≥ 95 (prudent)', 'cyberpush-ultra' ); ?></option>
						</select>
					</label>
					<label><?php esc_html_e( 'Observations minimum', 'cyberpush-ultra' ); ?>
						<select class="cu-select cu-select-sm" id="cu-radar-ab-hits">
							<option value="2">2</option>
							<option value="3" selected><?php esc_html_e( '3 (recommandé)', 'cyberpush-ultra' ); ?></option>
							<option value="5">5</option>
							<option value="10">10</option>
						</select>
					</label>
					<label><?php esc_html_e( 'Cible', 'cyberpush-ultra' ); ?>
						<select class="cu-select cu-select-sm" id="cu-radar-ab-mode">
							<option value="ip"><?php esc_html_e( 'IP seule', 'cyberpush-ultra' ); ?></option>
							<option value="cidr"><?php esc_html_e( 'Plage /24', 'cyberpush-ultra' ); ?></option>
						</select>
					</label>
					<label><?php esc_html_e( 'Durée', 'cyberpush-ultra' ); ?>
						<select class="cu-select cu-select-sm" id="cu-radar-ab-duration">
							<option value="24h"><?php esc_html_e( '24 heures', 'cyberpush-ultra' ); ?></option>
							<option value="7d" selected><?php esc_html_e( '7 jours', 'cyberpush-ultra' ); ?></option>
							<option value="30j"><?php esc_html_e( '30 jours', 'cyberpush-ultra' ); ?></option>
							<option value="permanent"><?php esc_html_e( 'Permanent', 'cyberpush-ultra' ); ?></option>
						</select>
					</label>
				</div>
			</div>

			<!-- Compteurs -->
			<div class="cu-grid cu-grid-4" style="margin-bottom:16px;">
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Suspects observés', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-radar-c-total">—</div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Datacenters', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-radar-c-datacenter">—</div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Proxies / VPN', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-radar-c-proxy">—</div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'À enrichir', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-radar-c-pending">—</div></div>
			</div>

			<!-- Filtres -->
			<div class="cu-toolbar" style="margin-bottom:12px;">
				<select class="cu-select" id="cu-radar-window" aria-label="<?php esc_attr_e( 'Période d\'observation', 'cyberpush-ultra' ); ?>">
					<option value="24"><?php esc_html_e( 'Dernières 24 h', 'cyberpush-ultra' ); ?></option>
					<option value="168" selected><?php esc_html_e( '7 derniers jours', 'cyberpush-ultra' ); ?></option>
					<option value="720"><?php esc_html_e( '30 derniers jours', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select" id="cu-radar-filter" aria-label="<?php esc_attr_e( 'Filtrer les suspects', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Tous les suspects', 'cyberpush-ultra' ); ?></option>
					<option value="datacenter"><?php esc_html_e( 'Datacenters uniquement', 'cyberpush-ultra' ); ?></option>
					<option value="proxy"><?php esc_html_e( 'Proxies / VPN', 'cyberpush-ultra' ); ?></option>
					<option value="bot"><?php esc_html_e( 'Bots / UA suspect', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select" id="cu-radar-minscore" aria-label="<?php esc_attr_e( 'Score minimum', 'cyberpush-ultra' ); ?>">
					<option value="0"><?php esc_html_e( 'Score : tous', 'cyberpush-ultra' ); ?></option>
					<option value="40"><?php esc_html_e( 'Score ≥ 40', 'cyberpush-ultra' ); ?></option>
					<option value="60"><?php esc_html_e( 'Score ≥ 60', 'cyberpush-ultra' ); ?></option>
					<option value="80"><?php esc_html_e( 'Score ≥ 80', 'cyberpush-ultra' ); ?></option>
				</select>
				<button class="cu-btn cu-btn-sm" id="cu-radar-refresh"><?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?> <?php esc_html_e( 'Rafraîchir', 'cyberpush-ultra' ); ?></button>
			</div>

			<!-- Barre d'actions de masse -->
			<div class="cu-toolbar" style="margin-bottom:12px;gap:8px;flex-wrap:wrap;">
				<label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--cu-text-dim);"><input type="checkbox" id="cu-radar-check-all"> <?php esc_html_e( 'Tout sélectionner', 'cyberpush-ultra' ); ?></label>
				<button class="cu-btn cu-btn-sm" id="cu-radar-select-dc"><?php esc_html_e( 'Sélectionner les datacenters', 'cyberpush-ultra' ); ?></button>
				<span style="flex:1;"></span>
				<select class="cu-select cu-select-sm" id="cu-radar-block-mode" title="<?php esc_attr_e( 'Cible du blocage', 'cyberpush-ultra' ); ?>" aria-label="<?php esc_attr_e( 'Cible du blocage', 'cyberpush-ultra' ); ?>">
					<option value="ip"><?php esc_html_e( 'IP seule', 'cyberpush-ultra' ); ?></option>
					<option value="cidr"><?php esc_html_e( 'Plage /24', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select cu-select-sm" id="cu-radar-block-duration" aria-label="<?php esc_attr_e( 'Durée du blocage', 'cyberpush-ultra' ); ?>">
					<option value="24h"><?php esc_html_e( '24 heures', 'cyberpush-ultra' ); ?></option>
					<option value="7d" selected><?php esc_html_e( '7 jours', 'cyberpush-ultra' ); ?></option>
					<option value="30j"><?php esc_html_e( '30 jours', 'cyberpush-ultra' ); ?></option>
					<option value="permanent"><?php esc_html_e( 'Permanent', 'cyberpush-ultra' ); ?></option>
				</select>
				<button class="cu-btn cu-btn-sm" id="cu-radar-copy"><?php echo CU_Icons::get( 'copy' ); // phpcs:ignore ?> <?php esc_html_e( 'Copier la liste', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn cu-btn-sm" id="cu-radar-ignore"><?php echo CU_Icons::get( 'eye' ); // phpcs:ignore ?> <?php esc_html_e( 'Ignorer', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn cu-btn-sm cu-btn-danger" id="cu-radar-block"><?php echo CU_Icons::get( 'ban' ); // phpcs:ignore ?> <?php esc_html_e( 'Bloquer la sélection', 'cyberpush-ultra' ); ?></button>
			</div>

			<div class="cu-table-wrap">
				<table class="cu-table" id="cu-radar-table">
					<thead>
						<tr>
							<th style="width:32px;"><span class="screen-reader-text"><?php esc_html_e( 'Sélection', 'cyberpush-ultra' ); ?></span></th>
							<th><?php esc_html_e( 'IP', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Pays', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Organisation / ASN', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Signaux', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Hits', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Score', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Dernier vu', 'cyberpush-ultra' ); ?></th>
						</tr>
					</thead>
					<tbody id="cu-radar-tbody">
						<tr><td colspan="8" class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
					</tbody>
				</table>
			</div>
			<div class="cu-pagination" id="cu-radar-pagination" style="padding:12px 16px;"></div>
		</div>
	</div>

	<!-- ==================== VULNÉRABILITÉS & VERSIONS ==================== -->
	<div class="cu-section" id="cu-vulns-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Vulnérabilités & versions', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card">
			<p class="cu-vu-intro">
				<?php esc_html_e( 'Versions réellement installées (PHP, WordPress, extensions et thèmes actifs ou non, base de données, serveur web, bibliothèques) comparées chaque jour à la base publique WPVulnerability, avec le calendrier de maintenance officiel de PHP. Le module informe : il ne met rien à jour lui-même.', 'cyberpush-ultra' ); ?>
			</p>

			<div class="cu-toolbar cu-vu-toolbar">
				<button class="cu-btn cu-btn-sm cu-btn-primary" id="cu-vu-sync"><?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?> <?php esc_html_e( 'Vérifier maintenant', 'cyberpush-ultra' ); ?></button>
				<span id="cu-vu-progress" class="cu-vu-progress" role="status" aria-live="polite"></span>
				<span style="flex:1;"></span>
				<label class="cu-vu-switch"><span><?php esc_html_e( 'Consultation en ligne', 'cyberpush-ultra' ); ?></span>
					<span class="cu-switch"><input type="checkbox" id="cu-vu-online" aria-label="<?php esc_attr_e( 'Consulter la base de vulnérabilités en ligne', 'cyberpush-ultra' ); ?>"><span class="slider"></span></span></label>
				<label class="cu-vu-switch"><span><?php esc_html_e( 'E-mail si nouvelle faille importante', 'cyberpush-ultra' ); ?></span>
					<span class="cu-switch"><input type="checkbox" id="cu-vu-email" aria-label="<?php esc_attr_e( 'Recevoir un e-mail pour les nouvelles vulnérabilités importantes', 'cyberpush-ultra' ); ?>"><span class="slider"></span></span></label>
			</div>

			<div class="cu-vu-banner" id="cu-vu-banner" role="status" hidden></div>

			<div class="cu-vu-kpis">
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Composants analysés', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-vu-kpi-total">—</div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Alertes de sécurité', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-vu-kpi-alerts">—</div><div class="cu-vu-kpi-sub" id="cu-vu-kpi-alerts-sub"></div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Fin de support', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-vu-kpi-eol">—</div><div class="cu-vu-kpi-sub" id="cu-vu-kpi-eol-sub"></div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Dernière synchronisation', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value cu-vu-kpi-text" id="cu-vu-kpi-sync">—</div><div class="cu-vu-kpi-sub" id="cu-vu-kpi-next"></div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Source', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value cu-vu-kpi-text" id="cu-vu-kpi-source">—</div><div class="cu-vu-kpi-sub">WPVulnerability · php.net</div></div>
			</div>

			<div class="cu-toolbar" style="margin:16px 0 12px;gap:8px;flex-wrap:wrap;">
				<select class="cu-select cu-select-sm" id="cu-vu-filter" aria-label="<?php esc_attr_e( 'Filtrer les composants', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Tous les composants', 'cyberpush-ultra' ); ?></option>
					<option value="alerts"><?php esc_html_e( 'Avec une vulnérabilité connue', 'cyberpush-ultra' ); ?></option>
					<option value="review"><?php esc_html_e( 'À vérifier', 'cyberpush-ultra' ); ?></option>
					<option value="eol"><?php esc_html_e( 'Fin de support / non maintenus', 'cyberpush-ultra' ); ?></option>
					<option value="plugin"><?php esc_html_e( 'Extensions', 'cyberpush-ultra' ); ?></option>
					<option value="theme"><?php esc_html_e( 'Thèmes', 'cyberpush-ultra' ); ?></option>
					<option value="server"><?php esc_html_e( 'Serveur (PHP, base de données…)', 'cyberpush-ultra' ); ?></option>
				</select>
				<input type="search" class="cu-input cu-input-sm" id="cu-vu-search" placeholder="<?php esc_attr_e( 'Rechercher un composant…', 'cyberpush-ultra' ); ?>" style="min-width:240px;">
			</div>

			<div class="cu-table-wrap">
				<table class="cu-table cu-vu-table" id="cu-vu-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Composant', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Version installée', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><?php esc_html_e( 'État', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Vulnérabilités', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Détail', 'cyberpush-ultra' ); ?></span></th>
						</tr>
					</thead>
					<tbody id="cu-vu-tbody">
						<tr><td colspan="5" class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<p class="cu-vu-foot">
				<?php
				echo wp_kses(
					__( 'Sources : <a href="https://www.wpvulnerability.com/" target="_blank" rel="noopener noreferrer">WPVulnerability</a> (base publique et gratuite, sans clé) et <a href="https://www.php.net/supported-versions.php" target="_blank" rel="noopener noreferrer">php.net</a> pour la maintenance de PHP. Seuls le nom technique et la version de chaque composant sont envoyés à wpvulnerability.net — ni l’adresse du site, ni aucune donnée de visiteur. « Aucune vulnérabilité connue » ne garantit pas l’absence de faille ; pour PHP, la base de données et le serveur web, l’hébergeur peut avoir appliqué des correctifs sans changer le numéro de version.', 'cyberpush-ultra' ),
					array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
				);
				?>
			</p>
		</div>
	</div>

	<!-- ==================== PROTECTION DES CONNEXIONS ==================== -->
	<div class="cu-section" id="cu-logins-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'key' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Protection des connexions', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card">
			<p class="cu-vu-intro">
				<?php esc_html_e( 'Qui se connecte aux comptes administrateurs, depuis quelle adresse IP (nouvelle ou déjà connue pour ce compte), et chaque création, promotion ou retrait d’administrateur. Cette section surveille et alerte uniquement : elle ne bloque, ne déconnecte et ne modifie jamais aucun compte (la limitation des tentatives reste au pare-feu).', 'cyberpush-ultra' ); ?>
			</p>

			<div class="cu-toolbar cu-vu-toolbar">
				<label class="cu-vu-switch"><span><?php esc_html_e( 'Surveillance active', 'cyberpush-ultra' ); ?></span>
					<span class="cu-switch"><input type="checkbox" id="cu-lg-watch"><span class="slider"></span></span></label>
				<label class="cu-vu-switch"><span><?php esc_html_e( 'Inclure les autres comptes à privilèges', 'cyberpush-ultra' ); ?></span>
					<span class="cu-switch"><input type="checkbox" id="cu-lg-privileged"><span class="slider"></span></span></label>
				<label class="cu-vu-switch"><span><?php esc_html_e( 'E-mail pour une nouvelle IP ou un nouveau pays', 'cyberpush-ultra' ); ?></span>
					<span class="cu-switch"><input type="checkbox" id="cu-lg-email-ip"><span class="slider"></span></span></label>
				<span style="flex:1;"></span>
				<label class="cu-vu-switch"><span><?php esc_html_e( 'Conservation', 'cyberpush-ultra' ); ?></span>
					<select class="cu-select cu-select-sm" id="cu-lg-retention">
						<option value="30"><?php esc_html_e( '30 jours', 'cyberpush-ultra' ); ?></option>
						<option value="60"><?php esc_html_e( '60 jours', 'cyberpush-ultra' ); ?></option>
						<option value="90"><?php esc_html_e( '90 jours', 'cyberpush-ultra' ); ?></option>
						<option value="180"><?php esc_html_e( '180 jours', 'cyberpush-ultra' ); ?></option>
					</select></label>
			</div>

			<div class="cu-vu-banner is-info" id="cu-lg-banner" role="status" hidden></div>

			<div class="cu-vu-kpis">
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Connexions réussies', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-lg-kpi-logins">—</div><div class="cu-vu-kpi-sub" id="cu-lg-kpi-period"></div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Nouvelles IP / pays', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-lg-kpi-newip">—</div><div class="cu-vu-kpi-sub"><?php esc_html_e( 'pas forcément une attaque', 'cyberpush-ultra' ); ?></div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Nouveaux administrateurs', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-lg-kpi-admins">—</div><div class="cu-vu-kpi-sub"><?php esc_html_e( 'créations, promotions, privilèges', 'cyberpush-ultra' ); ?></div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Événements sensibles', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-lg-kpi-sensitive">—</div><div class="cu-vu-kpi-sub"><?php esc_html_e( 'identifiants, retraits, échecs', 'cyberpush-ultra' ); ?></div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Dernière activité admin', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value cu-vu-kpi-text" id="cu-lg-kpi-last">—</div><div class="cu-vu-kpi-sub" id="cu-lg-kpi-last-sub"></div></div>
			</div>

			<div class="cu-toolbar" style="margin:16px 0 12px;gap:8px;flex-wrap:wrap;">
				<select class="cu-select cu-select-sm" id="cu-lg-days" aria-label="<?php esc_attr_e( 'Période', 'cyberpush-ultra' ); ?>">
					<option value="1"><?php esc_html_e( '24 dernières heures', 'cyberpush-ultra' ); ?></option>
					<option value="7" selected><?php esc_html_e( '7 derniers jours', 'cyberpush-ultra' ); ?></option>
					<option value="30"><?php esc_html_e( '30 derniers jours', 'cyberpush-ultra' ); ?></option>
					<option value="90"><?php esc_html_e( '90 derniers jours', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select cu-select-sm" id="cu-lg-user" aria-label="<?php esc_attr_e( 'Compte', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Tous les comptes', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select cu-select-sm" id="cu-lg-group" aria-label="<?php esc_attr_e( 'Type d’événement', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Tous les événements', 'cyberpush-ultra' ); ?></option>
					<option value="login"><?php esc_html_e( 'Connexions réussies', 'cyberpush-ultra' ); ?></option>
					<option value="session"><?php esc_html_e( 'Accès avec une session déjà ouverte', 'cyberpush-ultra' ); ?></option>
					<option value="new_ip"><?php esc_html_e( 'Nouvelle IP ou nouveau pays', 'cyberpush-ultra' ); ?></option>
					<option value="admin_new"><?php esc_html_e( 'Nouveaux administrateurs et privilèges', 'cyberpush-ultra' ); ?></option>
					<option value="admin_lost"><?php esc_html_e( 'Retraits et suppressions', 'cyberpush-ultra' ); ?></option>
					<option value="credentials"><?php esc_html_e( 'Mots de passe et e-mails', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select cu-select-sm" id="cu-lg-severity" aria-label="<?php esc_attr_e( 'Gravité', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Toutes gravités', 'cyberpush-ultra' ); ?></option>
					<option value="alert"><?php esc_html_e( 'Alerte', 'cyberpush-ultra' ); ?></option>
					<option value="warning"><?php esc_html_e( 'Attention', 'cyberpush-ultra' ); ?></option>
					<option value="notice"><?php esc_html_e( 'À vérifier', 'cyberpush-ultra' ); ?></option>
					<option value="info"><?php esc_html_e( 'Information', 'cyberpush-ultra' ); ?></option>
				</select>
				<button class="cu-btn cu-btn-sm" id="cu-lg-refresh"><?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?> <?php esc_html_e( 'Rafraîchir', 'cyberpush-ultra' ); ?></button>
			</div>

			<div class="cu-lg-account" id="cu-lg-account" hidden></div>

			<div class="cu-table-wrap">
				<table class="cu-table cu-vu-table" id="cu-lg-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Date', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Compte', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Événement', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><?php esc_html_e( 'IP', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Pays', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Statut', 'cyberpush-ultra' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Détail', 'cyberpush-ultra' ); ?></span></th>
						</tr>
					</thead>
					<tbody id="cu-lg-tbody">
						<tr><td colspan="7" class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
					</tbody>
				</table>
			</div>
			<div class="cu-pagination" id="cu-lg-pagination" style="padding:12px 16px;"></div>

			<p class="cu-vu-foot" id="cu-lg-foot">
				<?php esc_html_e( 'Adresse IP résolue par le pare-feu (proxys de confiance) ; pays estimé par la base locale DB-IP, sans service externe — un VPN, un réseau mobile ou un proxy peuvent le fausser. Données réservées aux administrateurs, jamais exposées publiquement ; aucun mot de passe, cookie ni jeton de session n’est conservé. Une extension de connexion qui n’utilise pas le processus standard de WordPress (certains SSO) n’apparaît qu’au premier accès à l’administration. Ce journal aide au diagnostic : il ne constitue pas une preuve infalsifiable si le serveur ou la base sont compromis.', 'cyberpush-ultra' ); ?>
			</p>
		</div>
	</div>

	<!-- ==================== JOURNAL DE SÉCURITÉ (AUDIT) ==================== -->
	<div class="cu-section" id="cu-audit-section">
		<div class="cu-section-title">
			<?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?>
			<?php esc_html_e( 'Journal de sécurité — changements à haut risque', 'cyberpush-ultra' ); ?>
		</div>
		<div class="cu-card">
			<p style="margin:0 0 14px;color:var(--cu-text-dim);font-size:13px;">
				<?php esc_html_e( 'Chaque changement sensible sur le site (extension activée, administrateur créé, URL du site modifiée, fichier du cœur altéré…) est enregistré avec l\'utilisateur, l\'IP, le pays et l\'heure. Chaque jour, la surveillance d\'intégrité contrôle le cœur, la racine du site et tout wp-content hors caches (extensions et thèmes actifs ou non, dossiers cachés compris, must-use, drop-ins, traductions, médias) : tout fichier ajouté ou modifié hors des mises à jour WordPress est signalé par e-mail, en priorité un fichier PHP à la racine ou caché dans les médias. Les mises à jour d\'extensions wordpress.org sont vérifiées avec les empreintes officielles.', 'cyberpush-ultra' ); ?>
			</p>

			<!-- Compteurs -->
			<div class="cu-grid cu-grid-4" style="margin-bottom:16px;">
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Critiques (24 h)', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-audit-kpi-crit24">—</div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Critiques (7 j)', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-audit-kpi-crit7">—</div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Événements enregistrés', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-audit-kpi-total">—</div></div>
				<div class="cu-card cu-kpi"><div class="cu-kpi-label"><?php esc_html_e( 'Intégrité fichiers', 'cyberpush-ultra' ); ?></div><div class="cu-kpi-value" id="cu-audit-kpi-fim" style="font-size:14px;">—</div></div>
			</div>

			<!-- Cœur WordPress comparé aux empreintes officielles wordpress.org -->
			<div class="cu-audit-core" id="cu-audit-core" aria-live="polite"></div>

			<!-- Actions intégrité -->
			<div class="cu-toolbar" style="margin-bottom:12px;gap:8px;flex-wrap:wrap;align-items:center;">
				<button class="cu-btn cu-btn-sm cu-btn-primary" id="cu-audit-scan"><?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?> <?php esc_html_e( 'Scanner l\'intégrité maintenant', 'cyberpush-ultra' ); ?></button>
				<span id="cu-audit-scan-progress" style="font-size:12px;color:var(--cu-text-dim);"></span>
				<span style="flex:1;"></span>
				<button class="cu-btn cu-btn-sm" id="cu-audit-baseline" title="<?php esc_attr_e( 'Accepter l\'état actuel comme nouvelle référence (action tracée dans le journal, alerte e-mail envoyée).', 'cyberpush-ultra' ); ?>"><?php esc_html_e( 'Réinitialiser la référence', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn cu-btn-sm cu-btn-danger" id="cu-audit-purge"><?php esc_html_e( 'Vider le journal', 'cyberpush-ultra' ); ?></button>
			</div>

			<!-- Filtres -->
			<div class="cu-toolbar" style="margin-bottom:12px;gap:8px;flex-wrap:wrap;">
				<select class="cu-select cu-select-sm" id="cu-audit-sev" aria-label="<?php esc_attr_e( 'Filtrer par gravité', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Toutes sévérités', 'cyberpush-ultra' ); ?></option>
					<option value="critical"><?php esc_html_e( 'Critique', 'cyberpush-ultra' ); ?></option>
					<option value="high"><?php esc_html_e( 'Élevé', 'cyberpush-ultra' ); ?></option>
					<option value="medium"><?php esc_html_e( 'Moyen', 'cyberpush-ultra' ); ?></option>
					<option value="low"><?php esc_html_e( 'Faible', 'cyberpush-ultra' ); ?></option>
				</select>
				<select class="cu-select cu-select-sm" id="cu-audit-cat" aria-label="<?php esc_attr_e( 'Filtrer par catégorie', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Toutes catégories', 'cyberpush-ultra' ); ?></option>
					<option value="files"><?php esc_html_e( 'Fichiers', 'cyberpush-ultra' ); ?></option>
					<option value="plugins"><?php esc_html_e( 'Extensions', 'cyberpush-ultra' ); ?></option>
					<option value="themes"><?php esc_html_e( 'Thèmes', 'cyberpush-ultra' ); ?></option>
					<option value="users"><?php esc_html_e( 'Utilisateurs', 'cyberpush-ultra' ); ?></option>
					<option value="settings"><?php esc_html_e( 'Réglages', 'cyberpush-ultra' ); ?></option>
					<option value="core"><?php esc_html_e( 'Cœur WordPress', 'cyberpush-ultra' ); ?></option>
				</select>
				<input type="search" class="cu-input cu-input-sm" id="cu-audit-search" placeholder="<?php esc_attr_e( 'Rechercher (message, IP, utilisateur…)', 'cyberpush-ultra' ); ?>" style="min-width:280px;">
				<button class="cu-btn cu-btn-sm" id="cu-audit-refresh"><?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?> <?php esc_html_e( 'Rafraîchir', 'cyberpush-ultra' ); ?></button>
			</div>

			<div class="cu-table-wrap">
				<table class="cu-table" id="cu-audit-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Sévérité', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Événement', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'Utilisateur', 'cyberpush-ultra' ); ?></th>
							<th><?php esc_html_e( 'IP / Pays', 'cyberpush-ultra' ); ?></th>
						</tr>
					</thead>
					<tbody id="cu-audit-tbody">
						<tr><td colspan="5" class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
					</tbody>
				</table>
			</div>
			<div class="cu-pagination" id="cu-audit-pagination" style="padding:12px 16px;"></div>
		</div>
	</div>

</div><!-- .cu-page -->

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// État RÉEL des sources externes (le tableau « Sources & fiabilité » ne doit
// jamais annoncer « Actif » pour un service non configuré, ni l'inverse).
$cu_psi_has_key  = '' !== trim( (string) CU_Settings::get( 'seo', 'psi_api_key', '' ) );
$cu_psi_ready    = (bool) CU_Settings::get( 'seo', 'psi_enabled', false ) && $cu_psi_has_key;
$cu_serper_ready = '' !== trim( (string) CU_Settings::get( 'seo', 'serper_api_key', '' ) );
$cu_ai_ready     = CU_AI::is_configured();
$cu_state_badge  = function ( $ready, $missing_label ) {
	return $ready
		? '<span class="cu-badge cu-badge-green">' . esc_html__( 'Actif', 'cyberpush-ultra' ) . '</span>'
		: '<span class="cu-badge cu-badge-yellow">' . esc_html( $missing_label ) . '</span>';
};
?>
<div class="cu-page cu-seo">

	<div class="cu-page-title">
		<div>
			<h1><?php echo CU_Icons::get( 'search' ); // phpcs:ignore ?> <?php esc_html_e( 'Audit SEO', 'cyberpush-ultra' ); ?></h1>
			<p><?php esc_html_e( 'Chaque page est analysée sur son HTML FINAL servi aux visiteurs — Elementor, Divi, shortcodes inclus. Le contenu ajouté uniquement par JavaScript n’est pas exécuté (signalé quand c’est le cas).', 'cyberpush-ultra' ); ?></p>
		</div>
		<div class="cu-seo-toolbar">
			<button class="cu-btn cu-btn-primary" id="cu-seo-scan"><?php echo CU_Icons::get( 'radar' ); // phpcs:ignore ?> <?php esc_html_e( 'Analyser tout le site', 'cyberpush-ultra' ); ?></button>
			<button class="cu-btn" id="cu-seo-export"><?php echo CU_Icons::get( 'download' ); // phpcs:ignore ?> <?php esc_html_e( 'Exporter (CSV)', 'cyberpush-ultra' ); ?></button>
			<button class="cu-btn cu-btn-danger" id="cu-seo-purge" title="<?php esc_attr_e( 'Vider l\'audit', 'cyberpush-ultra' ); ?>" aria-label="<?php esc_attr_e( 'Vider l\'audit', 'cyberpush-ultra' ); ?>"><?php echo CU_Icons::get( 'trash' ); // phpcs:ignore ?></button>
		</div>
	</div>

	<!-- Barre de progression du crawl -->
	<div class="cu-card cu-seo-progress" id="cu-seo-progress" hidden>
		<div class="cu-seo-progress-head">
			<span class="cu-spinner"></span>
			<strong id="cu-seo-progress-label"><?php esc_html_e( 'Analyse en cours…', 'cyberpush-ultra' ); ?></strong>
			<span class="cu-seo-progress-count" id="cu-seo-progress-count"></span>
		</div>
		<div class="cu-bar-track"><div class="cu-bar-fill" id="cu-seo-progress-fill" style="width:0%"></div></div>
	</div>

	<!-- ============================ SANTÉ SEO DU SITE (HÉROS) ============================ -->
	<div class="cu-card cu-seo-hero" id="cu-seo-hero">
		<div class="cu-seo-hero-dial">
			<div class="cu-seo-gauge" id="cu-seo-gauge" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'Score global : chargement…', 'cyberpush-ultra' ); ?>"></div>
			<span class="cu-seo-verdict-pill" id="cu-seo-verdict"></span>
		</div>
		<div class="cu-seo-hero-main">
			<div class="cu-seo-hero-head">
				<span class="cu-seo-eyebrow"><?php echo CU_Icons::get( 'pulse' ); // phpcs:ignore ?> <?php esc_html_e( 'Santé SEO du site', 'cyberpush-ultra' ); ?></span>
				<h2 class="cu-seo-hero-title" id="cu-seo-hero-title"><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></h2>
				<div class="cu-seo-hero-meta" id="cu-seo-hero-meta"></div>
			</div>
			<div class="cu-seo-hero-stats">
				<div class="cu-seo-hs">
					<div class="cu-seo-hs-head"><span><?php esc_html_e( 'Répartition des pages analysées', 'cyberpush-ultra' ); ?></span><b id="cu-seo-dist-total"></b></div>
					<div class="cu-seo-dist3d" id="cu-seo-dist"></div>
					<div class="cu-seo-dist-key" id="cu-seo-dist-key"></div>
				</div>
				<div class="cu-seo-hs">
					<div class="cu-seo-hs-head"><span><?php esc_html_e( 'Anomalies détectées', 'cyberpush-ultra' ); ?></span><span class="cu-seo-timeline" id="cu-seo-timeline"></span></div>
					<div class="cu-seo-anoms" id="cu-seo-anoms"></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================ RÉSUMÉ IA ============================ -->
	<div class="cu-card cu-seo-summary" id="cu-seo-summary">
		<div class="cu-seo-summary-head">
			<div class="cu-section-title" style="margin:0;"><?php echo CU_Icons::get( 'sparkles' ); // phpcs:ignore ?> <?php esc_html_e( 'État de votre site', 'cyberpush-ultra' ); ?> <span class="cu-seo-src-tag" style="margin-left:6px;"><span class="cu-dot cu-dot-yellow" aria-hidden="true"></span> <?php esc_html_e( 'Analyse IA', 'cyberpush-ultra' ); ?></span></div>
			<button class="cu-btn cu-btn-sm" id="cu-seo-summary-gen"><?php echo CU_Icons::get( 'sparkles' ); // phpcs:ignore ?> <span><?php esc_html_e( 'Générer le résumé', 'cyberpush-ultra' ); ?></span></button>
		</div>
		<div id="cu-seo-summary-body">
			<p class="cu-seo-scorehint" style="text-align:left;margin:8px 0 0;"><?php esc_html_e( 'L\'IA lit vos données réelles et vous explique la situation + un plan d\'action pour la semaine. Elle n\'invente aucun chiffre.', 'cyberpush-ultra' ); ?></p>
			<?php if ( ! $cu_ai_ready ) : ?>
				<p class="cu-seo-scorehint cu-seo-ai-off" style="text-align:left;margin:6px 0 0;"><?php esc_html_e( 'Aucun fournisseur IA n\'est configuré (Réglages → IA) : le résumé n\'est pas disponible. L\'audit SEO, lui, fonctionne sans IA.', 'cyberpush-ultra' ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<!-- ============================ MISSION DE LA SEMAINE ============================ -->
	<div class="cu-card cu-seo-mission" id="cu-seo-mission" hidden>
		<div class="cu-section-title"><?php echo CU_Icons::get( 'flag' ); // phpcs:ignore ?> <?php esc_html_e( 'Votre mission SEO cette semaine', 'cyberpush-ultra' ); ?></div>
		<div id="cu-seo-mission-body"></div>
	</div>

	<!-- ============================ CENTRE DE SANTÉ SEO ============================ -->
	<div class="cu-card cu-seo-health">
		<div class="cu-seo-card-head">
			<div class="cu-section-title" style="margin:0;"><?php echo CU_Icons::get( 'pulse' ); // phpcs:ignore ?> <?php esc_html_e( 'Centre de santé SEO', 'cyberpush-ultra' ); ?></div>
			<span class="cu-seo-card-hint"><?php esc_html_e( 'Survolez un indicateur : ce qu’il mesure, sa source et les points en jeu.', 'cyberpush-ultra' ); ?></span>
		</div>
		<div class="cu-seo-health-grid" id="cu-seo-health">
			<div class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></div>
		</div>
		<div class="cu-seo-breakdown" id="cu-seo-breakdown"></div>
	</div>

	<div class="cu-seo-duo">
		<!-- Tendance -->
		<div class="cu-card cu-seo-trend-card">
			<div class="cu-seo-card-head">
				<div class="cu-section-title" style="margin:0;"><?php echo CU_Icons::get( 'trending-up' ); // phpcs:ignore ?> <?php esc_html_e( 'Tendance du score', 'cyberpush-ultra' ); ?></div>
				<div class="cu-seo-trend-tools">
					<span class="cu-seo-trend-meta" id="cu-seo-trend-meta"></span>
					<div class="cu-brief-periods" id="cu-seo-trend-range" role="group" aria-label="<?php esc_attr_e( 'Période de la courbe', 'cyberpush-ultra' ); ?>" hidden>
						<button type="button" class="cu-brief-pill is-active" data-days="30" aria-pressed="true"><?php esc_html_e( '30 j', 'cyberpush-ultra' ); ?></button>
						<button type="button" class="cu-brief-pill" data-days="90" aria-pressed="false"><?php esc_html_e( '90 j', 'cyberpush-ultra' ); ?></button>
					</div>
				</div>
			</div>
			<div class="cu-seo-trend" id="cu-seo-trend"></div>
			<p class="cu-seo-trend-empty" id="cu-seo-trend-empty" hidden><?php esc_html_e( 'La courbe se construit au fil des analyses : un relevé par jour (score, erreurs, alertes).', 'cyberpush-ultra' ); ?></p>
		</div>

		<!-- Top anomalies -->
		<div class="cu-card cu-seo-top-card" id="cu-seo-top-card">
			<div class="cu-seo-card-head">
				<div class="cu-section-title" style="margin:0;"><?php echo CU_Icons::get( 'flag' ); // phpcs:ignore ?> <?php esc_html_e( 'Problèmes les plus fréquents', 'cyberpush-ultra' ); ?></div>
			</div>
			<p class="cu-seo-card-sub"><?php esc_html_e( 'Part des pages analysées touchées. Cliquez une ligne pour voir les URL concernées.', 'cyberpush-ultra' ); ?></p>
			<div id="cu-seo-top-issues" class="cu-seo-top">
				<div class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></div>
			</div>
		</div>
	</div>

	<!-- ============================ PLAN D'ACTION ============================ -->
	<div class="cu-card cu-seo-plan" style="margin-top:16px;">
		<div class="cu-section-title"><?php echo CU_Icons::get( 'sparkles' ); // phpcs:ignore ?> <?php esc_html_e( 'Plan d\'action prioritaire', 'cyberpush-ultra' ); ?></div>
		<p class="cu-seo-scorehint" style="text-align:left;margin:0 0 12px;"><?php esc_html_e( 'Ce qu\'il faut corriger en premier, trié par priorité. La priorité découle d\'un impact qualitatif (selon le type de problème), de l\'effort et du nombre de pages concernées — la règle est affichée sous « Pourquoi cette priorité ? ». Aucun gain de trafic n\'est chiffré : il dépend de données que l\'audit ne mesure pas.', 'cyberpush-ultra' ); ?></p>
		<div id="cu-seo-reco">
			<div class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></div>
		</div>
	</div>

	<!-- ============================ AUDIT TECHNIQUE ============================ -->
	<div class="cu-card cu-seo-siteaudit" style="margin-top:16px;">
		<div class="cu-seo-tablehead">
			<div class="cu-section-title" style="margin:0;"><?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?> <?php esc_html_e( 'Audit technique du site', 'cyberpush-ultra' ); ?></div>
			<div class="cu-seo-filters">
				<span class="cu-seo-scorehint" id="cu-seo-sa-time"></span>
				<button class="cu-btn cu-btn-primary cu-btn-sm" id="cu-seo-sa-run"><?php echo CU_Icons::get( 'radar' ); // phpcs:ignore ?> <?php esc_html_e( 'Lancer l\'audit technique', 'cyberpush-ultra' ); ?></button>
			</div>
		</div>
		<p class="cu-seo-scorehint" style="text-align:left;margin:0 0 12px;"><?php esc_html_e( 'Analyses transverses (tout le site) : pages orphelines, liens cassés, doublons de titres/descriptions, profondeur de clic, sitemap.xml et robots.txt.', 'cyberpush-ultra' ); ?></p>
		<div id="cu-seo-sa-body">
			<div class="cu-empty"><?php esc_html_e( 'Aucun audit technique encore. Cliquez « Lancer l\'audit technique ».', 'cyberpush-ultra' ); ?></div>
		</div>
	</div>

	<!-- ============================ PAGES ============================ -->
	<div class="cu-card" style="margin-top:16px;">
		<div class="cu-seo-tablehead">
			<div class="cu-section-title" style="margin:0;"><?php echo CU_Icons::get( 'file-text' ); // phpcs:ignore ?> <?php esc_html_e( 'Pages analysées', 'cyberpush-ultra' ); ?></div>
			<div class="cu-seo-filters">
				<select class="cu-select" id="cu-seo-filter" aria-label="<?php esc_attr_e( 'Filtrer les pages', 'cyberpush-ultra' ); ?>">
					<option value=""><?php esc_html_e( 'Toutes', 'cyberpush-ultra' ); ?></option>
					<option value="errors"><?php esc_html_e( 'Avec erreurs', 'cyberpush-ultra' ); ?></option>
					<option value="warnings"><?php esc_html_e( 'Avec alertes', 'cyberpush-ultra' ); ?></option>
					<option value="poor"><?php esc_html_e( 'Score faible', 'cyberpush-ultra' ); ?></option>
					<option value="medium"><?php esc_html_e( 'Score moyen (50–79)', 'cyberpush-ultra' ); ?></option>
					<option value="good"><?php esc_html_e( 'Bon score', 'cyberpush-ultra' ); ?></option>
					<option value="noindex"><?php esc_html_e( 'Non indexables', 'cyberpush-ultra' ); ?></option>
					<option value="pending"><?php esc_html_e( 'En attente', 'cyberpush-ultra' ); ?></option>
					<option value="failed"><?php esc_html_e( 'En erreur (HTTP / injoignables)', 'cyberpush-ultra' ); ?></option>
					<option value="skipped"><?php esc_html_e( 'Non analysables', 'cyberpush-ultra' ); ?></option>
				</select>
				<input type="search" class="cu-input" id="cu-seo-search" placeholder="<?php esc_attr_e( 'Rechercher une URL…', 'cyberpush-ultra' ); ?>">
			</div>
		</div>
		<div class="cu-table-wrap">
			<table class="cu-table cu-seo-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Score', 'cyberpush-ultra' ); ?></th>
						<th title="<?php esc_attr_e( 'Visites réelles sur 30 jours (source : CyberPush Analytics)', 'cyberpush-ultra' ); ?>"><?php esc_html_e( 'Trafic 30j', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Erreurs', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Alertes', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Mots', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Index', 'cyberpush-ultra' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'cyberpush-ultra' ); ?></span></th>
					</tr>
				</thead>
				<tbody id="cu-seo-tbody">
					<tr><td colspan="8" class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<div class="cu-seo-pager" id="cu-seo-pager"></div>
	</div>

	<!-- ============================ INDEXATION GOOGLE (SERPER.DEV) ============================ -->
	<div class="cu-card cu-seo-index" id="cu-seo-index" style="margin-top:16px;">
		<div class="cu-seo-index-head">
			<div class="cu-seo-index-intro">
				<div class="cu-section-title"><?php echo CU_Icons::get( 'search' ); // phpcs:ignore ?> <?php esc_html_e( 'Présence dans Google (observée via Serper)', 'cyberpush-ultra' ); ?></div>
				<p class="cu-seo-index-sub"><?php esc_html_e( 'Pour chaque URL, une recherche Google « site: » est faite via l\'API Serper.dev, puis l\'adresse EXACTE de la page est cherchée parmi les résultats renvoyés. « Observée » = la page apparaissait dans ces résultats à la date indiquée ; « non trouvée » = elle n\'y figurait pas, ce qui ne prouve pas qu\'elle est désindexée. Ce n\'est pas Google Search Console. Information indicative : elle n\'entre dans aucun score.', 'cyberpush-ultra' ); ?></p>
			</div>
			<div class="cu-seo-index-actions">
				<button class="cu-btn cu-btn-primary" id="cu-seo-index-check" title="<?php esc_attr_e( 'Vérifie seulement les pages jamais vérifiées ou dont le résultat a expiré (observée : 30 j, non trouvée : 7 j, erreur : 6 h) — 1 requête Serper par page', 'cyberpush-ultra' ); ?>"><?php echo CU_Icons::get( 'search' ); // phpcs:ignore ?> <?php esc_html_e( 'Vérifier les pages en attente', 'cyberpush-ultra' ); ?></button>
				<button class="cu-btn" id="cu-seo-index-recheck" title="<?php esc_attr_e( 'Revérifie toutes les pages, même récemment vérifiées (1 requête Serper par page)', 'cyberpush-ultra' ); ?>"><?php esc_html_e( 'Tout revérifier', 'cyberpush-ultra' ); ?></button>
			</div>
		</div>

		<div class="cu-seo-index-banner" id="cu-seo-index-banner" hidden></div>
		<div class="cu-seo-index-chips" id="cu-seo-index-chips"></div>

		<div class="cu-seo-index-toolbar">
			<label class="cu-seo-index-filter-label" for="cu-seo-index-filter"><?php esc_html_e( 'Filtrer :', 'cyberpush-ultra' ); ?></label>
			<select class="cu-select" id="cu-seo-index-filter">
				<option value=""><?php esc_html_e( 'Toutes les pages', 'cyberpush-ultra' ); ?></option>
				<option value="not_found"><?php esc_html_e( 'Non trouvées dans les résultats', 'cyberpush-ultra' ); ?></option>
				<option value="found"><?php esc_html_e( 'Observées dans Google', 'cyberpush-ultra' ); ?></option>
				<option value="error"><?php esc_html_e( 'Vérification impossible', 'cyberpush-ultra' ); ?></option>
				<option value="due"><?php esc_html_e( 'À vérifier (jamais ou expiré)', 'cyberpush-ultra' ); ?></option>
				<option value="unchecked"><?php esc_html_e( 'Jamais vérifiées', 'cyberpush-ultra' ); ?></option>
				<option value="legacy"><?php esc_html_e( 'Ancien résultat (à revérifier)', 'cyberpush-ultra' ); ?></option>
			</select>
		</div>

		<div class="cu-seo-index-progress" id="cu-seo-index-progress" hidden>
			<div class="cu-seo-index-bar"><span id="cu-seo-index-bar-fill"></span></div>
			<div class="cu-seo-index-progress-label" id="cu-seo-index-progress-label"></div>
		</div>

		<div class="cu-table-wrap">
			<table class="cu-table cu-seo-index-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Résultat (Serper)', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Vérifié le', 'cyberpush-ultra' ); ?></th>
					</tr>
				</thead>
				<tbody id="cu-seo-index-tbody">
					<tr><td colspan="3" class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<div class="cu-seo-pager" id="cu-seo-index-pager"></div>
	</div>

	<!-- ============================ SOURCES & FIABILITÉ ============================ -->
	<div class="cu-card cu-seo-sources" style="margin-top:16px;">
		<div class="cu-section-title"><?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?> <?php esc_html_e( 'Sources & fiabilité des données', 'cyberpush-ultra' ); ?></div>
		<p class="cu-seo-scorehint" style="text-align:left;margin:0 0 12px;"><?php esc_html_e( 'Chaque donnée porte sa source. L\'IA interprète ces données — elle n\'invente jamais un chiffre.', 'cyberpush-ultra' ); ?></p>
		<div class="cu-table-wrap">
			<table class="cu-table">
				<thead><tr><th><?php esc_html_e( 'Source', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Fiabilité', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Sert à', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'État', 'cyberpush-ultra' ); ?></th></tr></thead>
				<tbody>
					<tr><td><strong>WordPress</strong> <?php esc_html_e( '(audit local)', 'cyberpush-ultra' ); ?></td><td>★★★★★</td><td><?php esc_html_e( 'Technique, contenu, maillage, balises, liens', 'cyberpush-ultra' ); ?></td><td><span class="cu-badge cu-badge-green"><?php esc_html_e( 'Actif', 'cyberpush-ultra' ); ?></span></td></tr>
					<tr><td><strong>CyberPush Analytics</strong></td><td>★★★★★</td><td><?php esc_html_e( 'Trafic réel du site (visites, pages vues)', 'cyberpush-ultra' ); ?></td><td><span class="cu-badge cu-badge-green"><?php esc_html_e( 'Actif', 'cyberpush-ultra' ); ?></span></td></tr>
					<tr><td><strong>PageSpeed Insights / CrUX</strong></td><td>★★★★★</td><td><?php esc_html_e( 'Performance, Core Web Vitals', 'cyberpush-ultra' ); ?></td><td><?php echo $cu_state_badge( $cu_psi_ready, $cu_psi_has_key ? __( 'Désactivé', 'cyberpush-ultra' ) : __( 'Clé requise', 'cyberpush-ultra' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><td><strong>Serper.dev</strong></td><td>★★★☆☆</td><td><?php esc_html_e( 'Présence observée dans les résultats Google (requête « site: », URL exacte) — indicatif, hors score, pas Search Console', 'cyberpush-ultra' ); ?></td><td><?php echo $cu_state_badge( $cu_serper_ready, __( 'Clé requise', 'cyberpush-ultra' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><td><strong>Google Search Console</strong></td><td>★★★★★</td><td><?php esc_html_e( 'Positions, clics, CTR, impressions (vérité Google)', 'cyberpush-ultra' ); ?></td><td><span class="cu-badge cu-badge-gray"><?php esc_html_e( 'À venir', 'cyberpush-ultra' ); ?></span></td></tr>
					<tr><td><strong>DataForSEO</strong></td><td>★★★★☆</td><td><?php esc_html_e( 'Mots-clés, concurrents, backlinks', 'cyberpush-ultra' ); ?></td><td><span class="cu-badge cu-badge-gray"><?php esc_html_e( 'À venir', 'cyberpush-ultra' ); ?></span></td></tr>
					<tr><td><strong><?php esc_html_e( 'IA (OpenAI / Gemini…)', 'cyberpush-ultra' ); ?></strong></td><td>★★★☆☆</td><td><?php esc_html_e( 'Interprétation & recommandations (jamais de chiffres inventés)', 'cyberpush-ultra' ); ?></td><td><?php echo $cu_state_badge( $cu_ai_ready, __( 'Clé requise', 'cyberpush-ultra' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
				</tbody>
			</table>
		</div>
		<div class="cu-seo-charter">
			<div class="cu-seo-charter-title"><?php esc_html_e( 'Notre charte de fiabilité', 'cyberpush-ultra' ); ?></div>
			<ul>
				<li><span class="cu-seo-ok"><?php echo CU_Icons::get( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Les données techniques proviennent directement de votre site WordPress.', 'cyberpush-ultra' ); ?></li>
				<li><span class="cu-seo-ok"><?php echo CU_Icons::get( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Les métriques de trafic proviennent de notre propre base Analytics (visites réelles).', 'cyberpush-ultra' ); ?></li>
				<li><span class="cu-seo-ok"><?php echo CU_Icons::get( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Les positions et clics proviendront de Google Search Console une fois connecté.', 'cyberpush-ultra' ); ?></li>
				<li><span class="cu-seo-ok"><?php echo CU_Icons::get( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Les données concurrentielles proviendront de fournisseurs spécialisés (DataForSEO).', 'cyberpush-ultra' ); ?></li>
				<li><span class="cu-seo-ok"><?php echo CU_Icons::get( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'L\'IA n\'invente pas de données : elle interprète uniquement les informations disponibles.', 'cyberpush-ultra' ); ?></li>
				<li><span class="cu-seo-ok"><?php echo CU_Icons::get( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Si une donnée n\'est pas disponible, le plugin l\'indique clairement au lieu de l\'estimer.', 'cyberpush-ultra' ); ?></li>
				<li><span class="cu-seo-ok"><?php echo CU_Icons::get( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Une panne ou un refus d\'un service externe (PageSpeed, Serper, IA) est affiché comme « mesure indisponible », jamais comme un problème SEO, et ne change aucun score.', 'cyberpush-ultra' ); ?></li>
				<li><span class="cu-seo-ok"><?php echo CU_Icons::get( 'check' ); // phpcs:ignore ?></span> <?php esc_html_e( 'Aucun gain de trafic n\'est chiffré : sans données Search Console, ce serait une invention.', 'cyberpush-ultra' ); ?></li>
			</ul>
		</div>
	</div>

</div><!-- .cu-page -->

<?php
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="cu-page cu-indexnow">

	<!-- ============================ HERO ============================ -->
	<div class="cu-in-hero">
		<div class="cu-in-hero-glow" aria-hidden="true"></div>
		<div class="cu-in-hero-body">
			<div class="cu-in-hero-badge">
				<?php echo CU_Icons::get( 'rocket', 'cu-icon' ); // phpcs:ignore ?>
				<span><?php esc_html_e( 'Indexation instantanée · protocole ouvert', 'cyberpush-ultra' ); ?></span>
			</div>
			<h1><?php esc_html_e( 'Index Now', 'cyberpush-ultra' ); ?></h1>
			<p class="cu-in-hero-lead">
				<?php esc_html_e( 'Ne demandez plus aux moteurs de vous découvrir : notifiez-les. Une seule clé pousse vos URL vers tous les participants IndexNow : Bing, Amazonbot, Yandex, Naver, Seznam, Yep et Internet Archive. Cela peut aussi améliorer indirectement leur découverte par des services IA utilisant ces index, sans garantir leur citation. Aucun service payant ni CAPTCHA n’est requis.', 'cyberpush-ultra' ); ?>
			</p>
			<div class="cu-in-hero-actions">
				<button class="cu-btn cu-btn-primary" id="cu-in-submit-all">
					<?php echo CU_Icons::get( 'send' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Pousser toutes mes URL', 'cyberpush-ultra' ); ?>
				</button>
				<button class="cu-btn" id="cu-in-verify">
					<?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Vérifier la propriété', 'cyberpush-ultra' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- ==================== COUVERTURE MOTEURS ===================== -->
	<div class="cu-card cu-in-engines">
		<div class="cu-in-engines-col">
			<div class="cu-in-col-head">
				<?php echo CU_Icons::get( 'search', 'cu-icon' ); // phpcs:ignore ?>
				<div>
					<strong><?php esc_html_e( 'SEO — moteurs de recherche', 'cyberpush-ultra' ); ?></strong>
					<span><?php esc_html_e( 'Notification directe, crawl prioritaire', 'cyberpush-ultra' ); ?></span>
				</div>
			</div>
			<div class="cu-in-chips">
				<span class="cu-in-chip is-on">Bing <em><?php esc_html_e( 'principal', 'cyberpush-ultra' ); ?></em></span>
				<span class="cu-in-chip is-on">Amazonbot</span>
				<span class="cu-in-chip is-on">Yandex</span>
				<span class="cu-in-chip is-on">Naver</span>
				<span class="cu-in-chip is-on">Seznam</span>
				<span class="cu-in-chip is-on">Yep</span>
				<span class="cu-in-chip is-on">Internet Archive</span>
				<span class="cu-in-chip is-off" title="<?php esc_attr_e( 'Google ne participe pas au protocole IndexNow — il garde sa propre découverte. Utilisez l\'onglet SEO pour le suivi Google.', 'cyberpush-ultra' ); ?>">Google <em><?php esc_html_e( 'non participant', 'cyberpush-ultra' ); ?></em></span>
			</div>
		</div>
		<div class="cu-in-engines-sep" aria-hidden="true"></div>
		<div class="cu-in-engines-col">
			<div class="cu-in-col-head">
				<?php echo CU_Icons::get( 'sparkles', 'cu-icon' ); // phpcs:ignore ?>
				<div>
					<strong><?php esc_html_e( 'GEO — moteurs génératifs (IA)', 'cyberpush-ultra' ); ?></strong>
					<span><?php esc_html_e( 'Bénéfice indirect possible, sans garantie de citation', 'cyberpush-ultra' ); ?></span>
				</div>
			</div>
			<div class="cu-in-chips">
				<span class="cu-in-chip is-ai">Microsoft Copilot</span>
				<span class="cu-in-chip is-ai">ChatGPT Search <em><?php esc_html_e( 'peut utiliser Bing', 'cyberpush-ultra' ); ?></em></span>
				<span class="cu-in-chip is-ai">DuckDuckGo</span>
			</div>
		</div>
	</div>

	<!-- ============ ENVOIS BLOQUÉS (préproduction, noindex global…) ============ -->
	<div class="cu-in-note" id="cu-in-blocked" role="status" hidden>
		<?php echo CU_Icons::get( 'alert', 'cu-icon' ); // phpcs:ignore ?>
		<span><strong><?php esc_html_e( 'Aucun envoi ne partira :', 'cyberpush-ultra' ); ?></strong> <span id="cu-in-blocked-text"></span></span>
	</div>

	<!-- ====================== CARTES D'ÉTAT ======================= -->
	<div class="cu-grid cu-grid-4 cu-in-status" style="margin-top:16px;">

		<div class="cu-card cu-in-stat">
			<span class="cu-kpi-label"><?php echo CU_Icons::get( 'radar' ); // phpcs:ignore ?> <?php esc_html_e( 'Statut', 'cyberpush-ultra' ); ?></span>
			<div class="cu-in-stat-main">
				<span class="cu-in-dot" id="cu-in-dot"></span>
				<span class="cu-in-stat-value" id="cu-in-state"><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></span>
			</div>
			<label class="cu-in-switch">
				<input type="checkbox" id="cu-in-enabled">
				<span class="cu-in-slider"></span>
				<span class="cu-in-switch-label"><?php esc_html_e( 'Module actif', 'cyberpush-ultra' ); ?></span>
			</label>
		</div>

		<div class="cu-card cu-in-stat">
			<span class="cu-kpi-label"><?php echo CU_Icons::get( 'shield-check' ); // phpcs:ignore ?> <?php esc_html_e( 'Propriété', 'cyberpush-ultra' ); ?></span>
			<div class="cu-in-stat-main">
				<span class="cu-in-verify-badge cu-badge cu-badge-gray" id="cu-in-verify-badge"><?php esc_html_e( 'Non vérifiée', 'cyberpush-ultra' ); ?></span>
			</div>
			<a href="#" target="_blank" rel="noopener" class="cu-in-keyfile-link" id="cu-in-keyfile-link" hidden>
				<?php echo CU_Icons::get( 'file-text' ); // phpcs:ignore ?>
				<span><?php esc_html_e( 'Voir le fichier-clé', 'cyberpush-ultra' ); ?></span>
			</a>
		</div>

		<div class="cu-card cu-in-stat">
			<span class="cu-kpi-label"><?php echo CU_Icons::get( 'send' ); // phpcs:ignore ?> <?php esc_html_e( 'Aujourd’hui', 'cyberpush-ultra' ); ?></span>
			<span class="cu-kpi-value" id="cu-in-day-count">0</span>
			<span class="cu-in-stat-hint"><?php esc_html_e( 'URL acceptées par IndexNow aujourd’hui', 'cyberpush-ultra' ); ?></span>
		</div>

		<div class="cu-card cu-in-stat">
			<span class="cu-kpi-label"><?php echo CU_Icons::get( 'trending-up' ); // phpcs:ignore ?> <?php esc_html_e( 'Total poussé', 'cyberpush-ultra' ); ?></span>
			<span class="cu-kpi-value" id="cu-in-total">—</span>
			<span class="cu-in-stat-hint" id="cu-in-last"><?php esc_html_e( 'Aucun envoi encore', 'cyberpush-ultra' ); ?></span>
		</div>
	</div>

	<div class="cu-grid cu-grid-2" style="gap:16px;margin-top:16px;align-items:start;">

		<!-- ==================== SOUMISSION MANUELLE ==================== -->
		<div class="cu-card cu-in-panel">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'send' ); // phpcs:ignore ?> <?php esc_html_e( 'Pousser des URL', 'cyberpush-ultra' ); ?></div>
			<p class="cu-in-muted"><?php esc_html_e( 'Une URL (ou un chemin comme /mon-article/) par ligne. Idéal après une refonte, une mise à jour importante ou pour un contenu prioritaire. L’envoi manuel est toujours effectué, même si l’URL a été poussée récemment.', 'cyberpush-ultra' ); ?></p>
			<textarea class="cu-textarea" id="cu-in-urls" rows="6" placeholder="<?php echo esc_attr( home_url( '/exemple-article/' ) ); ?>&#10;<?php echo esc_attr( home_url( '/une-page/' ) ); ?>" style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;"></textarea>
			<div class="cu-in-form-row">
				<button class="cu-btn cu-btn-primary" id="cu-in-submit">
					<?php echo CU_Icons::get( 'rocket' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Soumettre maintenant', 'cyberpush-ultra' ); ?>
				</button>
				<span class="cu-in-muted" id="cu-in-submit-result"></span>
			</div>
		</div>

		<!-- ====================== CONFIGURATION ======================= -->
		<div class="cu-card cu-in-panel">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'gear' ); // phpcs:ignore ?> <?php esc_html_e( 'Configuration', 'cyberpush-ultra' ); ?></div>

			<label class="cu-in-switch cu-in-switch-block">
				<input type="checkbox" id="cu-in-auto">
				<span class="cu-in-slider"></span>
				<span>
					<b><?php esc_html_e( 'Soumission automatique', 'cyberpush-ultra' ); ?></b>
					<em><?php esc_html_e( 'Chaque publication, mise à jour ou suppression est poussée en arrière-plan (WP-Cron, en général sous quelques minutes), sans ralentir l’éditeur. Les contenus en noindex (extension SEO, ou noindex déjà relevé par l’audit SEO), protégés par mot de passe ou masqués sont ignorés. Moteur momentanément indisponible : l’envoi est retenté automatiquement (5 essais au plus, en quelques heures).', 'cyberpush-ultra' ); ?></em>
				</span>
			</label>

			<div class="cu-field" style="margin-top:14px;">
				<label for="cu-in-endpoint"><?php esc_html_e( 'Moteur de destination', 'cyberpush-ultra' ); ?></label>
				<select class="cu-select" id="cu-in-endpoint" style="width:100%;"></select>
				<span class="cu-in-muted"><?php esc_html_e( 'IndexNow.org relaie automatiquement vers tous les moteurs participants — laissez ce choix par défaut.', 'cyberpush-ultra' ); ?></span>
			</div>

			<div class="cu-field" style="margin-top:14px;">
				<label><?php esc_html_e( 'Clé IndexNow', 'cyberpush-ultra' ); ?></label>
				<div class="cu-in-key-row">
					<code class="cu-in-key" id="cu-in-key">••••••••</code>
					<button class="cu-btn cu-btn-sm" id="cu-in-copy-key" title="<?php esc_attr_e( 'Copier l\'URL du fichier-clé', 'cyberpush-ultra' ); ?>"><?php echo CU_Icons::get( 'copy' ); // phpcs:ignore ?></button>
					<button class="cu-btn cu-btn-sm cu-btn-danger" id="cu-in-regen" title="<?php esc_attr_e( 'Régénérer une nouvelle clé', 'cyberpush-ultra' ); ?>"><?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?></button>
				</div>
				<span class="cu-in-muted" id="cu-in-key-hint"><?php esc_html_e( 'Générée à l’activation du module, liée au domaine du site et servie à la racine uniquement tant qu’IndexNow est actif. Une copie de préproduction (autre domaine) n’envoie rien.', 'cyberpush-ultra' ); ?></span>
			</div>
		</div>
	</div>

	<!-- ===================== INTELLIGENCE SEO/GEO ===================== -->
	<div class="cu-card cu-in-brief" style="margin-top:16px;">
		<div class="cu-section-title"><?php echo CU_Icons::get( 'sparkles' ); // phpcs:ignore ?> <?php esc_html_e( 'La stratégie : pourquoi c\'est puissant', 'cyberpush-ultra' ); ?></div>
		<div class="cu-in-brief-grid">
			<div class="cu-in-brief-item">
				<span class="cu-in-brief-num">1</span>
				<div>
					<b><?php esc_html_e( 'Découverte → Notification', 'cyberpush-ultra' ); ?></b>
					<p><?php esc_html_e( 'Un crawl classique peut prendre des jours à repasser sur une page. IndexNow inverse la logique : dès qu\'une URL change, VOUS prévenez le moteur. Le passage du robot peut alors avoir lieu en quelques minutes ou heures au lieu de plusieurs jours (l\'indexation reste décidée par chaque moteur).', 'cyberpush-ultra' ); ?></p>
				</div>
			</div>
			<div class="cu-in-brief-item">
				<span class="cu-in-brief-num">2</span>
				<div>
					<b><?php esc_html_e( 'Effet réseau : 1 envoi = tous les moteurs', 'cyberpush-ultra' ); ?></b>
					<p><?php esc_html_e( 'Les moteurs participants se partagent les URL reçues. Soumettre à IndexNow.org, c\'est notifier Bing, Amazonbot, Yandex, Naver, Seznam, Yep et Internet Archive d\'un seul appel — sans gérer plusieurs API.', 'cyberpush-ultra' ); ?></p>
				</div>
			</div>
			<div class="cu-in-brief-item">
				<span class="cu-in-brief-num">3</span>
				<div>
					<b><?php esc_html_e( 'GEO : entrer dans les réponses des IA', 'cyberpush-ultra' ); ?></b>
					<p><?php esc_html_e( 'Copilot s’appuie sur la recherche Bing et ChatGPT Search peut utiliser Bing parmi d’autres fournisseurs. Une meilleure présence dans les index participants peut donc aider indirectement la visibilité générative, sans garantir une citation.', 'cyberpush-ultra' ); ?></p>
				</div>
			</div>
			<div class="cu-in-brief-item">
				<span class="cu-in-brief-num">4</span>
				<div>
					<b><?php esc_html_e( 'Discipline = résultats', 'cyberpush-ultra' ); ?></b>
					<p><?php esc_html_e( 'Ne soumettez que du contenu ajouté, modifié ou supprimé. Les envois automatiques et globaux ignorent une URL déjà poussée depuis moins de 24 h (anti-doublon intégré) ; un changement d\'adresse ou une suppression est toujours signalé, même dans les 24 h. Rien ne part si le site décourage l\'indexation (Réglages › Lecture), hors environnement de production, ou si l\'adresse du site n\'est pas publique (localhost, IP privée, .test, .local…). Un flux propre et pertinent est mieux traité qu\'un envoi massif répété.', 'cyberpush-ultra' ); ?></p>
				</div>
			</div>
		</div>
		<div class="cu-in-note">
			<?php echo CU_Icons::get( 'flag' ); // phpcs:ignore ?>
			<span><?php esc_html_e( 'Google reste hors protocole : IndexNow ne le remplace pas pour Google. Ces deux approches sont complémentaires — l\'onglet SEO gère le suivi d\'indexation Google, cet onglet accélère tout le reste.', 'cyberpush-ultra' ); ?></span>
		</div>
	</div>

	<!-- ========================= HISTORIQUE ========================= -->
	<div class="cu-card cu-in-panel" style="margin-top:16px;">
		<div class="cu-section-title" style="justify-content:space-between;display:flex;">
			<span style="display:inline-flex;align-items:center;gap:8px;"><?php echo CU_Icons::get( 'clock' ); // phpcs:ignore ?> <?php esc_html_e( 'Historique des envois', 'cyberpush-ultra' ); ?></span>
			<button class="cu-btn cu-btn-sm" id="cu-in-refresh-history"><?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore ?> <?php esc_html_e( 'Actualiser', 'cyberpush-ultra' ); ?></button>
		</div>
		<div class="cu-table-wrap">
			<table class="cu-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Origine', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'URL', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Moteur', 'cyberpush-ultra' ); ?></th>
						<th><?php esc_html_e( 'Résultat', 'cyberpush-ultra' ); ?></th>
					</tr>
				</thead>
				<tbody id="cu-in-history-body">
					<tr><td colspan="5" class="cu-loading"><span class="cu-spinner"></span> <?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></td></tr>
				</tbody>
			</table>
		</div>
	</div>

</div><!-- .cu-page -->

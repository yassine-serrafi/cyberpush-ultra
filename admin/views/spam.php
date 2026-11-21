<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$s = CU_Settings::module( 'spam' );

/** Ligne « interrupteur » des réglages (data-key lu par cu-spam.js). */
$cu_sp_switch = static function ( $key, $title, $hint ) use ( $s ) {
	echo '<div class="cu-field-row"><div class="cu-field-row-text"><strong>' . esc_html( $title ) . '</strong><span>' . esc_html( $hint ) . '</span></div>'
		. '<label class="cu-switch"><input type="checkbox" data-key="' . esc_attr( $key ) . '"' . checked( ! empty( $s[ $key ] ), true, false ) . '><span class="slider"></span></label></div>';
};
?>
<div class="cu-page cu-spam" id="cu-spam-page">
	<div class="cu-page-title">
		<div>
			<h1><?php echo CU_Icons::get( 'message' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Spam', 'cyberpush-ultra' ); ?></h1>
			<p><?php esc_html_e( 'Protection légère des formulaires WordPress (commentaires et avis, inscription, mot de passe oublié) et Contact Form 7 : champ piège invisible, vérification du domaine de l’adresse e-mail et limite d’envois répétés. Le contenu des messages n’est jamais analysé. Aucune protection n’arrête tous les robots.', 'cyberpush-ultra' ); ?></p>
		</div>
		<div class="cu-toolbar">
			<span class="cu-pill cu-pill-ghost" id="cu-sp-state"><?php esc_html_e( 'Chargement…', 'cyberpush-ultra' ); ?></span>
			<select class="cu-select" id="cu-sp-days" aria-label="<?php esc_attr_e( 'Période', 'cyberpush-ultra' ); ?>">
				<option value="7"><?php esc_html_e( '7 derniers jours', 'cyberpush-ultra' ); ?></option>
				<option value="30"><?php esc_html_e( '30 derniers jours', 'cyberpush-ultra' ); ?></option>
			</select>
			<button class="cu-btn" id="cu-sp-refresh"><?php echo CU_Icons::get( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Actualiser', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<div class="cu-alert-strip is-danger" id="cu-sp-notice" hidden>
		<span class="cu-alert-strip-ico"><?php echo CU_Icons::get( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<span class="cu-alert-strip-text" id="cu-sp-notice-text"></span>
	</div>

	<!-- A. Vue générale -->
	<div class="cu-sp-kpis">
		<?php
		foreach ( array(
			'examined' => __( 'Envois examinés', 'cyberpush-ultra' ),
			'allowed'  => __( 'Acceptés', 'cyberpush-ultra' ),
			'blocked'  => __( 'Bloqués par CyberPush', 'cyberpush-ultra' ),
			'suspect'  => __( 'Suspects (transmis)', 'cyberpush-ultra' ),
			'other'    => __( 'Rejetés par un autre mécanisme', 'cyberpush-ultra' ),
		) as $cu_key => $cu_label ) :
			?>
			<div class="cu-card cu-kpi">
				<span class="cu-kpi-label"><?php echo esc_html( $cu_label ); ?></span>
				<span class="cu-kpi-value" data-total="<?php echo esc_attr( $cu_key ); ?>">–</span>
			</div>
		<?php endforeach; ?>
	</div>
	<p class="cu-field-hint" id="cu-sp-empty" hidden><?php esc_html_e( 'Aucun envoi examiné pour l’instant. Les chiffres proviennent uniquement du journal anti-spam : ils apparaîtront dès le premier envoi d’un formulaire protégé.', 'cyberpush-ultra' ); ?></p>

	<div class="cu-grid cu-grid-2 cu-section" style="align-items:start;">
		<div class="cu-card">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'chart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Évolution par jour', 'cyberpush-ultra' ); ?></div>
			<canvas class="cu-sp-chart" id="cu-sp-chart" role="img" aria-label="<?php esc_attr_e( 'Envois examinés par jour', 'cyberpush-ultra' ); ?>"></canvas>
			<div class="cu-sp-legend" id="cu-sp-legend"></div>
		</div>
		<div class="cu-card">
			<div class="cu-section-title"><?php echo CU_Icons::get( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Motifs', 'cyberpush-ultra' ); ?></div>
			<ul class="cu-sp-reasons" id="cu-sp-reasons"></ul>
		</div>
	</div>

	<div class="cu-card cu-section">
		<div class="cu-section-title"><?php echo CU_Icons::get( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Derniers événements', 'cyberpush-ultra' ); ?></div>
		<div class="cu-table-wrap"><table class="cu-table">
			<thead><tr><th><?php esc_html_e( 'Date', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Formulaire', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Décision', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Motif', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Domaine', 'cyberpush-ultra' ); ?></th></tr></thead>
			<tbody id="cu-sp-recent"><tr><td colspan="5" class="cu-empty"><span class="cu-spinner"></span></td></tr></tbody>
		</table></div>
	</div>

	<!-- B. Formulaires -->
	<div class="cu-card cu-section">
		<div class="cu-section-title"><?php echo CU_Icons::get( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Formulaires protégés', 'cyberpush-ultra' ); ?></div>
		<p class="cu-field-hint"><?php esc_html_e( 'Un formulaire exclu n’est ni protégé ni journalisé. Les compteurs couvrent la durée de conservation du journal.', 'cyberpush-ultra' ); ?></p>
		<div class="cu-table-wrap"><table class="cu-table">
			<thead><tr><th><?php esc_html_e( 'Formulaire', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'ID', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Protection', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Observés', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Bloqués', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Remarques', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Exclure', 'cyberpush-ultra' ); ?></th></tr></thead>
			<tbody id="cu-sp-forms"><tr><td colspan="7" class="cu-empty"><span class="cu-spinner"></span></td></tr></tbody>
		</table></div>
	</div>

	<!-- C. Réglages -->
	<div class="cu-card cu-section" id="cu-sp-settings">
		<div class="cu-section-title"><?php echo CU_Icons::get( 'gear' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Réglages', 'cyberpush-ultra' ); ?></div>
		<?php
		$cu_sp_switch( 'enabled', __( 'Module Spam actif', 'cyberpush-ultra' ), __( 'Désactivé : aucun contrôle, aucun champ ajouté, aucun journal.', 'cyberpush-ultra' ) );
		$cu_sp_switch( 'honeypot', __( 'Champ piège invisible', 'cyberpush-ultra' ), __( 'Un champ caché que seuls les robots remplissent, plus un horodatage signé. Sans JavaScript et compatible avec le cache de page. Un envoi « trop rapide » n’est jamais bloqué, seulement marqué suspect.', 'cyberpush-ultra' ) );
		$cu_sp_switch( 'email_check', __( 'Vérifier le domaine de l’adresse e-mail', 'cyberpush-ultra' ), __( 'Refuse, avec un message corrigeable, un domaine réservé ou qui ne peut recevoir aucun e-mail (DNS). Si le DNS ne répond pas, l’envoi est accepté. La boîte elle-même n’est jamais contactée.', 'cyberpush-ultra' ) );
		$cu_sp_switch( 'disposable_check', __( 'Repérer les adresses jetables', 'cyberpush-ultra' ), __( 'Liste publique et vérifiée de domaines d’adresses temporaires (aucun grand fournisseur).', 'cyberpush-ultra' ) );
		?>
		<div class="cu-field-row">
			<div class="cu-field-row-text"><strong><?php esc_html_e( 'Adresse jetable détectée', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'Refuser (le visiteur peut saisir une autre adresse) ou laisser passer en marquant l’envoi comme suspect.', 'cyberpush-ultra' ); ?></span></div>
			<select class="cu-select" data-key="disposable_action">
				<option value="block" <?php selected( $s['disposable_action'], 'block' ); ?>><?php esc_html_e( 'Refuser', 'cyberpush-ultra' ); ?></option>
				<option value="suspect" <?php selected( $s['disposable_action'], 'suspect' ); ?>><?php esc_html_e( 'Marquer suspect', 'cyberpush-ultra' ); ?></option>
			</select>
		</div>
		<?php $cu_sp_switch( 'rate_limit_enabled', __( 'Limiter les envois répétés', 'cyberpush-ultra' ), __( 'Au-delà du seuil (5 envois en 10 minutes par défaut), les envois suivants du même formulaire depuis la même adresse IP sont refusés jusqu’à la fin de la fenêtre, avec un message invitant le visiteur à réessayer quelques minutes plus tard. Compté formulaire par formulaire (une connexion partagée n’épuise pas la limite de tout le site). Aucun bannissement. Utilisateurs connectés, IP privées et liste blanche du pare-feu exemptés.', 'cyberpush-ultra' ) ); ?>
		<details class="cu-sp-adv">
			<summary><?php esc_html_e( 'Réglages avancés', 'cyberpush-ultra' ); ?></summary>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Seuil des envois répétés', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'De 2 à 100 envois, sur 1 à 1 440 minutes.', 'cyberpush-ultra' ); ?></span></div>
				<div class="cu-sp-inline">
					<input type="number" class="cu-input cu-input-sm" data-key="rate_limit_max" min="2" max="100" value="<?php echo esc_attr( (string) $s['rate_limit_max'] ); ?>" aria-label="<?php esc_attr_e( 'Nombre d’envois', 'cyberpush-ultra' ); ?>">
					<span><?php esc_html_e( 'envois par', 'cyberpush-ultra' ); ?></span>
					<input type="number" class="cu-input cu-input-sm" data-key="rate_limit_window_minutes" min="1" max="1440" value="<?php echo esc_attr( (string) $s['rate_limit_window_minutes'] ); ?>" aria-label="<?php esc_attr_e( 'Fenêtre en minutes', 'cyberpush-ultra' ); ?>">
					<span><?php esc_html_e( 'minutes', 'cyberpush-ultra' ); ?></span>
				</div>
			</div>
			<div class="cu-grid cu-grid-2" style="margin-top:14px;">
				<div class="cu-field">
					<label for="cu-sp-allowed"><?php esc_html_e( 'Toujours accepter (domaines ou adresses)', 'cyberpush-ultra' ); ?></label>
					<textarea class="cu-textarea" id="cu-sp-allowed" data-key="allowed_domains" data-type="lines" rows="5" placeholder="exemple-partenaire.fr"><?php echo esc_textarea( implode( "\n", (array) $s['allowed_domains'] ) ); ?></textarea>
					<span class="cu-field-hint"><?php esc_html_e( 'Un par ligne. Un domaine couvre aussi ses sous-domaines. Prioritaire sur tous les contrôles d’adresse.', 'cyberpush-ultra' ); ?></span>
				</div>
				<div class="cu-field">
					<label for="cu-sp-blocked"><?php esc_html_e( 'Toujours refuser (domaines ou adresses)', 'cyberpush-ultra' ); ?></label>
					<textarea class="cu-textarea" id="cu-sp-blocked" data-key="blocked_domains" data-type="lines" rows="5"><?php echo esc_textarea( implode( "\n", (array) $s['blocked_domains'] ) ); ?></textarea>
					<span class="cu-field-hint"><?php esc_html_e( 'Un par ligne (500 au plus). Le visiteur voit le même message qu’une adresse invalide.', 'cyberpush-ultra' ); ?></span>
				</div>
			</div>
			<?php $cu_sp_switch( 'disposable_auto_update', __( 'Mettre à jour la liste des adresses jetables', 'cyberpush-ultra' ), __( 'Une fois par semaine depuis le dépôt public (CC0). En cas d’échec, la liste actuelle est conservée.', 'cyberpush-ultra' ) ); ?>
			<div class="cu-field-row">
				<div class="cu-field-row-text"><strong><?php esc_html_e( 'Conservation du journal (jours)', 'cyberpush-ultra' ); ?></strong><span><?php esc_html_e( 'De 1 à 365 jours.', 'cyberpush-ultra' ); ?></span></div>
				<input type="number" class="cu-input cu-input-sm" style="width:90px;" data-key="log_retention_days" min="1" max="365" value="<?php echo esc_attr( (string) $s['log_retention_days'] ); ?>">
			</div>
		</details>
		<div class="cu-toolbar" style="margin-top:16px;">
			<button class="cu-btn cu-btn-primary" id="cu-sp-save"><?php echo CU_Icons::get( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Enregistrer les réglages', 'cyberpush-ultra' ); ?></button>
		</div>
	</div>

	<!-- D. Journal -->
	<div class="cu-card cu-section">
		<div class="cu-section-title"><?php echo CU_Icons::get( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Journal', 'cyberpush-ultra' ); ?></div>
		<div class="cu-toolbar" style="margin-bottom:14px;">
			<select class="cu-select" id="cu-sp-filter-decision" aria-label="<?php esc_attr_e( 'Filtrer par décision', 'cyberpush-ultra' ); ?>">
				<option value=""><?php esc_html_e( 'Toutes les décisions', 'cyberpush-ultra' ); ?></option>
			</select>
			<select class="cu-select" id="cu-sp-filter-form" aria-label="<?php esc_attr_e( 'Filtrer par formulaire', 'cyberpush-ultra' ); ?>">
				<option value=""><?php esc_html_e( 'Tous les formulaires', 'cyberpush-ultra' ); ?></option>
			</select>
			<a class="cu-btn" id="cu-sp-flamingo" href="#" hidden><?php echo CU_Icons::get( 'folder' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Dossier Spam de Flamingo', 'cyberpush-ultra' ); ?></a>
		</div>
		<div class="cu-table-wrap"><table class="cu-table">
			<thead><tr><th><?php esc_html_e( 'Date', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Formulaire', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Décision', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Motif', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Protection', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Domaine', 'cyberpush-ultra' ); ?></th><th><?php esc_html_e( 'Source', 'cyberpush-ultra' ); ?></th></tr></thead>
			<tbody id="cu-sp-log"><tr><td colspan="7" class="cu-empty"><span class="cu-spinner"></span></td></tr></tbody>
		</table></div>
		<div class="cu-pagination" id="cu-sp-pagination"></div>
		<p class="cu-field-hint"><?php esc_html_e( '« Source » est une empreinte courte et non réversible de l’adresse IP : elle permet de repérer des envois répétés sans conserver l’IP. Aucun message ni adresse complète n’est enregistré.', 'cyberpush-ultra' ); ?></p>
	</div>

	<!-- E. Diagnostic -->
	<div class="cu-card cu-section">
		<div class="cu-section-title"><?php echo CU_Icons::get( 'wrench' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Diagnostic', 'cyberpush-ultra' ); ?></div>
		<ul class="cu-check-list" id="cu-sp-diag"></ul>
		<div class="cu-section-title" style="margin-top:26px;"><?php echo CU_Icons::get( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Tester une adresse e-mail', 'cyberpush-ultra' ); ?></div>
		<p class="cu-field-hint"><?php esc_html_e( 'Applique les réglages actuels à une adresse. Aucun service externe n’est contacté : seul le domaine peut faire l’objet d’une requête DNS. Un domaine valide ne prouve pas que la boîte existe.', 'cyberpush-ultra' ); ?></p>
		<div class="cu-sp-test">
			<input type="email" class="cu-input" id="cu-sp-test-email" placeholder="prenom@exemple-entreprise.fr" aria-label="<?php esc_attr_e( 'Adresse à tester', 'cyberpush-ultra' ); ?>">
			<button class="cu-btn" id="cu-sp-test"><?php echo CU_Icons::get( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Tester', 'cyberpush-ultra' ); ?></button>
		</div>
		<p class="cu-field-row-text" id="cu-sp-test-result" role="status"></p>
	</div>
</div>

/* CyberPush Ultra — page « Spam » (anti-spam des formulaires WordPress et Contact Form 7).
 * Dépend de window.cuAdmin (apiFetch, toast, lineChart, formatNumber, cssVar).
 * Le serveur ne renvoie que des codes ; les libellés sont ici. Tout texte dynamique est échappé.
 */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	if ( ! cu ) return;
	var page = document.getElementById( "cu-spam-page" );
	if ( ! page ) return;

	var LABELS = {
		decisions: { allowed: "Accepté", suspect: "Suspect (transmis)", blocked: "Bloqué par CyberPush", other: "Rejeté par un autre mécanisme" },
		reasons: {
			honeypot: "Champ piège rempli", missing_fields: "Champs de protection absents (envoi direct)", too_fast: "Envoyé très vite après l’affichage",
			rate_limit: "Envois répétés depuis la même source", disposable: "Adresse e-mail jetable", reserved: "Domaine réservé (exemple, test…)",
			null_mx: "Domaine qui refuse tout e-mail (Null MX)", no_mail_domain: "Domaine sans messagerie (faute de frappe ?)", blocklisted: "Refusé par votre liste",
			external: "Décision d’un autre mécanisme",
		},
		agents: { cyberpush: "CyberPush", recaptcha: "reCAPTCHA", turnstile: "Turnstile", akismet: "Akismet", wpcf7: "Contact Form 7 (contrôles intégrés)", wordpress: "Modération WordPress", unknown: "Non précisé" },
		emails: {
			ok: "Le domaine déclare pouvoir recevoir des e-mails. Cela ne prouve pas que la boîte existe.",
			unknown: "Vérification impossible pour l’instant (DNS indisponible ou lent, limite atteinte, domaine non vérifiable).",
			allowlisted: "Autorisé par votre liste.",
			blocklisted: "Refusé par votre liste.",
			reserved: "Domaine réservé (exemple, test…) : il ne peut recevoir aucune réponse.",
			null_mx: "Le domaine déclare explicitement ne recevoir aucun e-mail (Null MX).",
			no_mail_domain: "Le domaine n’a ni serveur de messagerie ni adresse : probablement une faute de frappe.",
			disposable: "Domaine d’adresses jetables connu.",
		},
	};
	var state = { days: 7, labels: LABELS, settings: {}, formTitles: {}, logPage: 1, decisionsFilled: false };
	var BADGE = { allowed: "cu-badge-green", suspect: "cu-badge-yellow", blocked: "cu-badge-red", other: "cu-badge-purple" };

	function $( id ) { return document.getElementById( id ); }

	function esc( str ) {
		return String( str == null ? "" : str ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}

	// « 2026-11-17 14:22:11 » (heure du site) → « 17/11/2026 14:22 ».
	function formatDate( mysql ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec( String( mysql || "" ) );
		if ( ! m ) return String( mysql || "" );
		return m[ 3 ] + "/" + m[ 2 ] + "/" + m[ 1 ] + ( m[ 4 ] ? " " + m[ 4 ] + ":" + m[ 5 ] : "" );
	}

	function label( group, key ) {
		var map = state.labels[ group ] || {};
		return map[ key ] || key || "—";
	}

	// Titre connu (liste des formulaires), sinon numéro (formulaire supprimé).
	function formTitle( row ) {
		var title = state.formTitles[ String( row.form_id ) ];
		if ( title ) return title;
		return Number( row.form_id ) > 0 ? "Formulaire n° " + row.form_id : "—";
	}

	function decisionBadge( decision ) {
		return '<span class="cu-badge ' + ( BADGE[ decision ] || "cu-badge-gray" ) + '">' + esc( label( "decisions", decision ) ) + "</span>";
	}

	// Motif lisible : décision d'un autre mécanisme → nom de ce mécanisme.
	function reasonText( row ) {
		if ( "other" === row.decision ) return label( "reasons", "external" ) + " (" + label( "agents", row.agent || "unknown" ) + ")";
		return row.reason ? label( "reasons", row.reason ) : "—";
	}

	function emptyRow( cols, text ) {
		return '<tr><td colspan="' + cols + '" class="cu-empty">' + esc( text ) + "</td></tr>";
	}

	// ---------------------------------------------------------------------
	// Chargement
	// ---------------------------------------------------------------------
	function load() {
		cu.apiFetch( "spam/status?days=" + state.days )
			.then( function ( data ) {
				state.settings = data.settings || {};
				state.formTitles = {};
				( data.forms || [] ).forEach( function ( f ) { state.formTitles[ String( f.id ) ] = f.title; } );
				renderState( data );
				renderOverview( data.overview || {} );
				renderForms( data.forms || [], data.diagnostic || {} );
				renderDiagnostic( data.diagnostic || {} );
				fillFilters( data.forms || [] );
				loadLog();
			} )
			.catch( function ( e ) {
				cu.toast( e.message || "Erreur de chargement.", "error" );
			} );
	}

	function renderState( data ) {
		var s = data.settings || {};
		var d = data.diagnostic || {};
		var pill = $( "cu-sp-state" );
		var notice = "";
		if ( ! s.enabled ) {
			notice = "Module désactivé : aucun formulaire n’est protégé ni journalisé.";
		} else if ( data.overview && false === data.overview.tables ) {
			notice = "Table du journal absente : ouvrez Réglages › Base de données pour installer les tables. La protection fonctionne, sans journal.";
		}
		pill.className = "cu-pill " + ( s.enabled ? "cu-pill-success" : "cu-pill-ghost" );
		pill.textContent = s.enabled ? "Protection active" : "Désactivé";
		$( "cu-sp-notice-text" ).textContent = notice;
		$( "cu-sp-notice" ).hidden = ! notice;
	}

	// ---------------------------------------------------------------------
	// A. Vue générale
	// ---------------------------------------------------------------------
	function renderOverview( o ) {
		var totals = o.totals || {};
		Array.prototype.forEach.call( page.querySelectorAll( "[data-total]" ), function ( el ) {
			el.textContent = cu.formatNumber( totals[ el.getAttribute( "data-total" ) ] || 0 );
		} );
		$( "cu-sp-empty" ).hidden = Number( totals.examined ) > 0;

		var keys = [ "allowed", "blocked", "suspect", "other" ];
		var colors = {
			allowed: cu.cssVar( "--cu-success", "#138a4a" ),
			blocked: cu.cssVar( "--cu-danger", "#c92a2a" ),
			suspect: cu.cssVar( "--cu-warning", "#b26200" ),
			other: cu.cssVar( "--cu-accent", "#3858e9" ),
		};
		var series = o.series || [];
		cu.lineChart( $( "cu-sp-chart" ), series.map( function ( p ) { return formatDate( p.date ).slice( 0, 5 ); } ), keys.map( function ( k ) {
			return { label: label( "decisions", k ), color: colors[ k ], data: series.map( function ( p ) { return Number( p[ k ] ) || 0; } ) };
		} ) );
		$( "cu-sp-legend" ).innerHTML = keys.map( function ( k ) {
			return '<span><i class="cu-dot" style="background:' + esc( colors[ k ] ) + '"></i>' + esc( label( "decisions", k ) ) + "</span>";
		} ).join( "" );

		var reasons = o.reasons || [];
		var max = reasons.reduce( function ( m, r ) { return Math.max( m, Number( r.c ) || 0 ); }, 1 );
		$( "cu-sp-reasons" ).innerHTML = reasons.length ? reasons.map( function ( r ) {
			var n = Number( r.c ) || 0;
			return "<li><span>" + decisionBadge( r.decision ) + " " + esc( reasonText( r ) ) + "</span><strong>" + cu.formatNumber( n ) + "</strong>" +
				'<div class="cu-bar-track"><div class="cu-bar-fill" style="width:' + Math.max( 2, Math.round( n / max * 100 ) ) + '%"></div></div></li>';
		} ).join( "" ) : '<li class="cu-field-hint">Aucun envoi bloqué ou suspect sur la période.</li>';

		var recent = o.recent || [];
		$( "cu-sp-recent" ).innerHTML = recent.length ? recent.map( function ( row ) {
			return "<tr><td style=\"white-space:nowrap;\">" + esc( formatDate( row.created_at ) ) + "</td><td>" + esc( formTitle( row ) ) + "</td><td>" + decisionBadge( row.decision ) +
				"</td><td>" + esc( reasonText( row ) ) + "</td><td>" + esc( row.email_domain || "—" ) + "</td></tr>";
		} ).join( "" ) : emptyRow( 5, "Aucun événement enregistré." );
	}

	// ---------------------------------------------------------------------
	// B. Formulaires (exclusion immédiate)
	// ---------------------------------------------------------------------
	function renderForms( forms, diag ) {
		var body = $( "cu-sp-forms" );
		if ( ! forms.length ) {
			body.innerHTML = emptyRow( 7, "Aucun formulaire à protéger." );
			return;
		}
		body.innerHTML = forms.map( function ( f ) {
			var badge = f.protected ? '<span class="cu-badge cu-badge-green">Protégé</span>'
				: '<span class="cu-badge cu-badge-gray">' + ( f.excluded ? "Exclu" : "Module désactivé" ) + "</span>";
			var notes = [];
			if ( f.note ) notes.push( f.note );
			if ( ! f.has_email ) notes.push( "Aucun champ e-mail : vérification du domaine sans objet." );
			if ( Number( f.suspect ) > 0 ) notes.push( cu.formatNumber( f.suspect ) + " suspect(s) transmis." );
			if ( Number( f.other ) > 0 ) notes.push( cu.formatNumber( f.other ) + " rejeté(s) par un autre mécanisme." );
			return "<tr><td>" + ( f.edit_url ? '<a href="' + esc( f.edit_url ) + '">' + esc( f.title || "—" ) + "</a>" : esc( f.title || "—" ) ) + "</td>" +
				"<td>" + ( f.native ? "WordPress" : esc( f.id ) ) + "</td><td>" + badge + "</td><td>" + cu.formatNumber( f.observed ) + "</td><td>" + cu.formatNumber( f.blocked ) + "</td>" +
				'<td class="cu-field-hint">' + esc( notes.join( " " ) || "—" ) + "</td>" +
				'<td><label class="cu-switch"><input type="checkbox" data-exclude="' + esc( f.id ) + '"' + ( f.excluded ? " checked" : "" ) +
				' aria-label="' + esc( "Exclure " + ( f.title || f.id ) ) + '"><span class="slider"></span></label></td></tr>';
		} ).join( "" );
	}

	function toggleExclude( input ) {
		var id = Number( input.getAttribute( "data-exclude" ) );
		var list = ( state.settings.excluded_forms || [] ).map( Number ).filter( function ( v ) { return v !== id; } );
		if ( input.checked ) list.push( id );
		input.disabled = true;
		cu.apiFetch( "settings/spam", { method: "POST", body: { excluded_forms: list } } )
			.then( function ( res ) {
				state.settings = res || state.settings;
				cu.toast( input.checked ? "Formulaire exclu de la protection." : "Formulaire protégé.", "success" );
				load();
			} )
			.catch( function ( e ) {
				input.checked = ! input.checked;
				cu.toast( e.message || "Échec de l'enregistrement.", "error" );
			} )
			.finally( function () { input.disabled = false; } );
	}

	// ---------------------------------------------------------------------
	// C. Réglages (un seul enregistrement, route générique settings/spam)
	// ---------------------------------------------------------------------
	function settingsFields() {
		return Array.prototype.slice.call( $( "cu-sp-settings" ).querySelectorAll( "[data-key]" ) );
	}

	function collectSettings() {
		var values = {};
		settingsFields().forEach( function ( field ) {
			var key = field.getAttribute( "data-key" );
			if ( "checkbox" === field.type ) {
				values[ key ] = field.checked;
			} else if ( "number" === field.type ) {
				if ( "" !== field.value.trim() ) values[ key ] = Number( field.value );
			} else if ( "lines" === field.getAttribute( "data-type" ) ) {
				values[ key ] = field.value.split( /[\r\n,;]+/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
			} else {
				values[ key ] = field.value;
			}
		} );
		return values;
	}

	function applySettings( s ) {
		settingsFields().forEach( function ( field ) {
			var key = field.getAttribute( "data-key" );
			if ( ! ( key in s ) ) return;
			if ( "checkbox" === field.type ) {
				field.checked = !! s[ key ];
			} else if ( "lines" === field.getAttribute( "data-type" ) ) {
				field.value = ( s[ key ] || [] ).join( "\n" );
			} else {
				field.value = s[ key ];
			}
		} );
	}

	function saveSettings() {
		var btn = $( "cu-sp-save" );
		btn.disabled = true;
		cu.apiFetch( "settings/spam", { method: "POST", body: collectSettings() } )
			.then( function ( res ) {
				state.settings = res || state.settings;
				applySettings( state.settings ); // listes nettoyées et valeurs bornées par le serveur
				cu.toast( "Réglages enregistrés.", "success" );
				load();
			} )
			.catch( function ( e ) {
				cu.toast( e.message || "Échec de l'enregistrement.", "error" );
			} )
			.finally( function () { btn.disabled = false; } );
	}

	// ---------------------------------------------------------------------
	// D. Journal (25 lignes par page)
	// ---------------------------------------------------------------------
	function fillFilters( forms ) {
		var dec = $( "cu-sp-filter-decision" );
		if ( ! state.decisionsFilled ) {
			Object.keys( state.labels.decisions || {} ).forEach( function ( k ) {
				var opt = document.createElement( "option" );
				opt.value = k;
				opt.textContent = label( "decisions", k );
				dec.appendChild( opt );
			} );
			state.decisionsFilled = true;
		}
		var sel = $( "cu-sp-filter-form" );
		var current = sel.value;
		sel.length = 1;
		forms.forEach( function ( f ) {
			var opt = document.createElement( "option" );
			opt.value = String( f.id );
			opt.textContent = f.native ? ( f.title || "—" ) : ( f.title || "—" ) + " (" + f.id + ")";
			sel.appendChild( opt );
		} );
		sel.value = current;
	}

	function loadLog() {
		var body = $( "cu-sp-log" );
		var query = "spam/log?page=" + state.logPage + "&decision=" + encodeURIComponent( $( "cu-sp-filter-decision" ).value ) + "&form=" + encodeURIComponent( $( "cu-sp-filter-form" ).value );
		cu.apiFetch( query )
			.then( function ( data ) {
				var items = data.items || [];
				body.innerHTML = items.length ? items.map( function ( row ) {
					return "<tr><td style=\"white-space:nowrap;\">" + esc( formatDate( row.created_at ) ) + "</td><td>" + esc( formTitle( row ) ) + "</td><td>" + decisionBadge( row.decision ) +
						"</td><td>" + esc( reasonText( row ) ) + "</td><td>" + esc( row.agent ? label( "agents", row.agent ) : "—" ) + "</td><td>" + esc( row.email_domain || "—" ) +
						"</td><td>" + ( row.source_key ? "<code>" + esc( row.source_key ) + "</code>" : "—" ) + "</td></tr>";
				} ).join( "" ) : emptyRow( 7, "Aucune entrée pour ces filtres." );
				renderPagination( data );
			} )
			.catch( function ( e ) {
				body.innerHTML = emptyRow( 7, e.message || "Erreur de chargement." );
				$( "cu-sp-pagination" ).innerHTML = "";
			} );
	}

	function renderPagination( data ) {
		var el = $( "cu-sp-pagination" );
		var pages = Number( data.pages ) || 1;
		var current = Number( data.page ) || 1;
		if ( pages <= 1 ) {
			el.innerHTML = "";
			return;
		}
		el.innerHTML = '<button class="cu-btn cu-btn-sm" data-page="' + ( current - 1 ) + '"' + ( current <= 1 ? " disabled" : "" ) + ' aria-label="Page précédente">&larr;</button>' +
			"<span>" + current + " / " + pages + "</span>" +
			'<button class="cu-btn cu-btn-sm" data-page="' + ( current + 1 ) + '"' + ( current >= pages ? " disabled" : "" ) + ' aria-label="Page suivante">&rarr;</button>';
	}

	// ---------------------------------------------------------------------
	// E. Diagnostic
	// ---------------------------------------------------------------------
	function check( level, title, detail ) {
		var badge = { ok: [ "cu-badge-green", "OK" ], info: [ "cu-badge-purple", "Info" ], warn: [ "cu-badge-yellow", "Attention" ], off: [ "cu-badge-gray", "Inactif" ] }[ level ];
		return '<li class="cu-check"><span class="cu-badge ' + badge[ 0 ] + '">' + badge[ 1 ] + '</span><span class="cu-check-title">' + esc( title ) + "</span>" +
			( detail ? '<span class="cu-check-detail">' + esc( detail ) + "</span>" : "" ) + "</li>";
	}

	function serviceState( v ) {
		return true === v ? "actif" : ( false === v ? "installé, inactif" : "non détecté" );
	}

	function renderDiagnostic( d ) {
		var items = [];
		var cf7 = d.cf7 || {};
		var native = d.native || {};
		items.push( check( "ok", "Formulaires WordPress protégés : commentaires et avis, inscription, mot de passe oublié",
			"Visiteurs non connectés uniquement. Commentaires " + ( native.comments ? "ouverts" : "fermés par défaut" ) + ", inscriptions " + ( native.register ? "ouvertes" : "fermées" ) + ". Un commentaire sans les champs de protection est rangé dans « Indésirables » (récupérable)." ) );
		items.push( cf7.active ? check( "ok", "Contact Form 7 actif" + ( cf7.version ? " (version " + cf7.version + ")" : "" ) )
			: check( "info", "Contact Form 7 non détecté", "Ses formulaires seront protégés automatiquement dès son activation." ) );
		if ( d.honeypot ) {
			var grace = Number( d.grace_left ) > 0
				? "Délai de grâce : encore " + d.grace_left + " jour(s). Un envoi sans les champs de protection (page en cache, envoi direct) est seulement marqué suspect."
				: ( d.since ? "Délai de grâce terminé : un envoi direct sans les champs de protection est classé spam." : "Démarrera au premier affichage d’un formulaire protégé." );
			items.push( check( "ok", "Champ piège actif" + ( d.since ? " depuis le " + formatDate( d.since ) : "" ), grace ) );
		} else {
			items.push( check( "off", "Champ piège désactivé" ) );
		}
		items.push( d.dns ? check( "ok", "Requêtes DNS disponibles" )
			: check( "warn", "Requêtes DNS indisponibles (dns_get_record)", "La vérification du domaine renvoie « inconnu » et laisse toujours passer." ) );
		items.push( d.idn ? check( "ok", "Domaines internationaux pris en charge (extension intl)" )
			: check( "info", "Extension intl absente", "Les domaines avec accents ne sont pas vérifiés (acceptés)." ) );
		var list = d.list || {};
		items.push( check( list.last_error ? "warn" : "ok", "Liste des adresses jetables : " + cu.formatNumber( list.count || 0 ) + " domaines",
			( "remote" === list.source ? "Mise à jour du " : "Instantané livré avec l’extension, du " ) + formatDate( list.updated ) +
			". Source : disposable-email-domains (CC0)." + ( list.last_error ? " Dernier échec de mise à jour : " + list.last_error + "." : "" ) ) );
		var i = d.integrations || {};
		items.push( check( "info", "Autres protections",
			"reCAPTCHA : " + serviceState( i.recaptcha ) + " · Turnstile : " + serviceState( i.turnstile ) + " · Akismet : " + serviceState( i.akismet ) +
			" · Flamingo : " + serviceState( i.flamingo ) + ". CyberPush passe avant reCAPTCHA et Turnstile : un robot pris au piège ne déclenche aucun appel à leur service." ) );
		items.push( d.page_cache ? check( "info", "Cache de page détecté : " + d.page_cache, "Les pages mises en cache avant l’activation n’ont pas les champs de protection : c’est la raison du délai de grâce de 7 jours." )
			: check( "ok", "Aucun cache de page détecté" ) );
		if ( d.other_antispam && d.other_antispam.length ) {
			items.push( check( "info", "Autre anti-spam actif : " + d.other_antispam.join( ", " ), "Doublon possible avec le champ piège ; ce n’est pas une erreur." ) );
		}
		items.push( d.last_error ? check( "warn", "Dernière erreur technique (" + formatDate( d.last_error.at ) + ")", d.last_error.message )
			: check( "ok", "Aucune erreur technique enregistrée" ) );
		$( "cu-sp-diag" ).innerHTML = items.join( "" );

		var flamingo = $( "cu-sp-flamingo" );
		flamingo.hidden = true !== i.flamingo;
		flamingo.href = d.flamingo_url || "#";
	}

	function testEmail() {
		var email = $( "cu-sp-test-email" ).value.trim();
		var out = $( "cu-sp-test-result" );
		var btn = $( "cu-sp-test" );
		if ( ! email ) {
			cu.toast( "Saisissez une adresse.", "error" );
			return;
		}
		btn.disabled = true;
		out.textContent = "Vérification…";
		cu.apiFetch( "spam/test-email", { method: "POST", body: { email: email } } )
			.then( function ( res ) {
				var effect = { accepted: "Envoi accepté.", blocked: "Envoi refusé (message corrigeable affiché au visiteur).", suspect: "Envoi accepté, marqué suspect.", inactive: "Vérifications d’adresse désactivées : envoi accepté." }[ res.effect ] || "";
				out.innerHTML = "<strong>" + esc( label( "emails", res.status ) ) + "</strong><span>" + esc( effect ) + "</span>";
			} )
			.catch( function ( e ) {
				out.textContent = e.message || "Échec du test.";
			} )
			.finally( function () { btn.disabled = false; } );
	}

	// ---------------------------------------------------------------------
	// Init
	// ---------------------------------------------------------------------
	function init() {
		$( "cu-sp-days" ).addEventListener( "change", function () {
			state.days = "30" === this.value ? 30 : 7;
			load();
		} );
		$( "cu-sp-refresh" ).addEventListener( "click", load );
		$( "cu-sp-save" ).addEventListener( "click", saveSettings );
		$( "cu-sp-test" ).addEventListener( "click", testEmail );
		$( "cu-sp-test-email" ).addEventListener( "keydown", function ( e ) {
			if ( "Enter" === e.key ) testEmail();
		} );
		[ "cu-sp-filter-decision", "cu-sp-filter-form" ].forEach( function ( id ) {
			$( id ).addEventListener( "change", function () {
				state.logPage = 1;
				loadLog();
			} );
		} );
		$( "cu-sp-pagination" ).addEventListener( "click", function ( e ) {
			var btn = e.target.closest( "button[data-page]" );
			if ( ! btn || btn.disabled ) return;
			state.logPage = Math.max( 1, Number( btn.getAttribute( "data-page" ) ) || 1 );
			loadLog();
		} );
		$( "cu-sp-forms" ).addEventListener( "change", function ( e ) {
			if ( e.target && e.target.hasAttribute( "data-exclude" ) ) toggleExclude( e.target );
		} );
		load();
	}

	// Le script peut être exécuté après DOMContentLoaded (chargement différé).
	if ( "loading" === document.readyState ) {
		document.addEventListener( "DOMContentLoaded", init );
	} else {
		init();
	}
} )();

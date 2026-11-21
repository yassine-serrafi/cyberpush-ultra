/* CyberPush Ultra — Firewall › « Protection des connexions ».
 * Données réelles du journal (route REST réservée aux administrateurs) ; tout
 * texte est échappé avant affichage. Dépend de window.cuAdmin. */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	var root = document.getElementById( "cu-logins-section" );
	if ( ! cu || ! root ) return;

	var state = { page: 1, open: {}, data: null };
	var EVENTS = {
		login: "Connexion réussie",
		session: "Accès admin (session déjà ouverte)",
		admin_created: "Administrateur créé",
		privileged_user_created: "Compte à privilèges créé",
		role_elevated: "Promu administrateur",
		privileges_granted: "Privilèges élevés accordés",
		super_admin_granted: "Super-administrateur accordé",
		role_caps_added: "Rôle renforcé",
		role_created_privileged: "Rôle privilégié créé",
		admin_appeared: "Administrateur apparu hors WordPress",
		role_demoted: "Rôle administrateur retiré",
		privileges_removed: "Privilèges retirés",
		admin_deleted: "Administrateur supprimé",
		admin_disappeared: "Administrateur disparu hors WordPress",
		password_changed: "Mot de passe modifié",
		password_reset: "Mot de passe réinitialisé",
		user_email_changed: "Adresse e-mail modifiée",
		app_password_created: "Mot de passe d’application créé",
		app_password_deleted: "Mot de passe d’application supprimé",
	};
	var SEV = {
		alert: [ "cu-badge-red", "Alerte" ],
		warning: [ "cu-badge-yellow", "Attention" ],
		notice: [ "cu-badge-yellow", "À vérifier" ],
		info: [ "cu-badge-gray", "Information" ],
	};
	var regionNames = null;
	try { regionNames = new Intl.DisplayNames( [ "fr" ], { type: "region" } ); } catch ( e ) { regionNames = null; }

	function $( id ) { return document.getElementById( id ); }
	function esc( s ) {
		return String( null == s ? "" : s ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}
	function frDate( mysql ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec( String( mysql || "" ) );
		return m ? m[ 3 ] + "/" + m[ 2 ] + "/" + m[ 1 ] + ( m[ 4 ] ? " " + m[ 4 ] + ":" + m[ 5 ] : "" ) : "—";
	}
	function country( code ) {
		if ( ! /^[A-Z]{2}$/.test( String( code || "" ) ) ) return "—";
		try { return regionNames ? regionNames.of( code ) + " (" + code + ")" : code; } catch ( e ) { return code; }
	}
	function isAccess( r ) { return "login" === r.event || "session" === r.event; }

	function statusHtml( r ) {
		var out = [];
		if ( isAccess( r ) ) {
			if ( "alert" === r.severity ) out.push( '<span class="cu-badge cu-badge-red">Alerte</span>' );
			if ( "new" === r.ip_status ) out.push( '<span class="cu-badge cu-badge-yellow">Nouvelle IP · à vérifier</span>' );
			else if ( "known" === r.ip_status ) out.push( '<span class="cu-badge cu-badge-green">IP connue</span>' );
			else if ( "first" === r.ip_status ) out.push( '<span class="cu-badge cu-badge-gray">Premier accès observé</span>' );
			if ( "new" === r.country_status ) out.push( '<span class="cu-badge cu-badge-yellow">Nouveau pays</span>' );
			if ( r.failures_account >= 3 || r.failures_ip >= 3 ) out.push( '<span class="cu-badge cu-badge-yellow">Après échecs</span>' );
			return out.join( " " ) || '<span class="cu-badge cu-badge-gray">—</span>';
		}
		var s = SEV[ r.severity ] || SEV.info;
		return '<span class="cu-badge ' + s[ 0 ] + '">' + s[ 1 ] + "</span>";
	}

	function detailHtml( r ) {
		var lines = [];
		if ( r.message ) lines.push( '<p class="cu-vu-life">' + esc( r.message ) + "</p>" );
		var meta = [];
		if ( r.role ) meta.push( "<span><b>Rôle :</b> " + esc( r.role ) + "</span>" );
		if ( r.browser ) meta.push( "<span><b>Navigateur :</b> " + esc( r.browser ) + "</span>" );
		if ( isAccess( r ) ) {
			meta.push( "<span><b>Échecs récents :</b> " + esc( r.failures_account ) + " sur ce compte · " + esc( r.failures_ip ) + " depuis cette adresse</span>" );
		} else {
			meta.push( "<span><b>Origine :</b> " + esc( r.actor ) + "</span>" );
		}
		if ( meta.length ) lines.push( '<div class="cu-vu-meta">' + meta.join( "" ) + "</div>" );
		if ( "session" === r.event ) lines.push( '<p class="cu-vu-note">Accès observé avec une session déjà ouverte : ce n’est pas une nouvelle saisie du mot de passe.</p>' );
		if ( "new" === r.ip_status || "new" === r.country_status ) lines.push( '<p class="cu-vu-note">Une nouvelle adresse ou un nouveau pays n’est pas une preuve d’intrusion (réseau mobile, VPN, déplacement). Si ce n’était pas ce compte, changez son mot de passe et déconnectez ses sessions.</p>' );
		if ( r.user_id ) lines.push( '<p><button type="button" class="cu-btn cu-btn-sm" data-lg-account="' + esc( r.user_id ) + '">Historique de ce compte</button></p>' );
		return '<div class="cu-vu-detail">' + lines.join( "" ) + "</div>";
	}

	function renderTable( d ) {
		var body = $( "cu-lg-tbody" );
		var items = d.items || [];
		if ( ! d.ready ) {
			body.innerHTML = '<tr><td colspan="7" class="cu-empty">Table du journal absente : ouvrez Réglages › Base de données pour l’installer.</td></tr>';
			return;
		}
		if ( ! items.length ) {
			body.innerHTML = '<tr><td colspan="7" class="cu-empty">Aucun événement pour ces filtres. Les connexions des administrateurs apparaîtront ici à partir de maintenant.</td></tr>';
			return;
		}
		body.innerHTML = items.map( function ( r ) {
			var open = !! state.open[ r.id ];
			var html = '<tr class="cu-vu-row' + ( open ? " is-open" : "" ) + '">' +
				'<td style="white-space:nowrap;">' + esc( frDate( r.date ) ) + "</td>" +
				"<td><strong>" + esc( r.user_login || "—" ) + "</strong>" + ( r.role ? '<div class="cu-vu-muted">' + esc( r.role ) + "</div>" : "" ) + "</td>" +
				"<td>" + esc( EVENTS[ r.event ] || r.event ) + ( ! isAccess( r ) && r.actor ? '<div class="cu-vu-muted">par ' + esc( r.actor ) + "</div>" : "" ) + "</td>" +
				'<td class="cu-vu-version">' + esc( r.ip || "—" ) + "</td>" +
				"<td>" + esc( country( r.country ) ) + "</td>" +
				"<td>" + statusHtml( r ) + "</td>" +
				'<td style="text-align:right;"><button type="button" class="cu-btn cu-btn-sm" data-lg-toggle="' + esc( r.id ) + '" aria-expanded="' + open + '">' + ( open ? "Masquer" : "Détails" ) + "</button></td></tr>";
			if ( open ) html += '<tr class="cu-vu-detail-row"><td colspan="7">' + detailHtml( r ) + "</td></tr>";
			return html;
		} ).join( "" );
	}

	function renderPagination( d ) {
		var el = $( "cu-lg-pagination" );
		var pages = Number( d.pages ) || 1;
		var cur = Number( d.page ) || 1;
		if ( pages <= 1 ) { el.innerHTML = ""; return; }
		el.innerHTML = '<button class="cu-btn cu-btn-sm" data-lg-page="' + ( cur - 1 ) + '"' + ( cur <= 1 ? " disabled" : "" ) + ' aria-label="Page précédente">&larr;</button> <span>' + cur + " / " + pages + "</span> " +
			'<button class="cu-btn cu-btn-sm" data-lg-page="' + ( cur + 1 ) + '"' + ( cur >= pages ? " disabled" : "" ) + ' aria-label="Page suivante">&rarr;</button>';
	}

	function renderKpis( d ) {
		var k = d.kpis || {};
		var days = $( "cu-lg-days" ).value;
		$( "cu-lg-kpi-logins" ).textContent = cu.formatNumber( k.logins || 0 );
		$( "cu-lg-kpi-period" ).textContent = "1" === days ? "24 dernières heures" : days + " derniers jours";
		$( "cu-lg-kpi-newip" ).textContent = cu.formatNumber( k.new_ips || 0 );
		$( "cu-lg-kpi-newip" ).style.color = k.new_ips ? "var(--cu-warning)" : "";
		$( "cu-lg-kpi-admins" ).textContent = cu.formatNumber( k.new_admins || 0 );
		$( "cu-lg-kpi-admins" ).style.color = k.new_admins ? "var(--cu-danger)" : "";
		$( "cu-lg-kpi-sensitive" ).textContent = cu.formatNumber( k.sensitive || 0 );
		$( "cu-lg-kpi-sensitive" ).style.color = k.sensitive ? "var(--cu-warning)" : "";
		$( "cu-lg-kpi-last" ).textContent = k.last ? frDate( k.last.date ) : "Aucune";
		$( "cu-lg-kpi-last-sub" ).textContent = k.last ? ( k.last.login + " · " + ( EVENTS[ k.last.event ] || k.last.event ).toLowerCase() ) : "aucune connexion enregistrée";
	}

	function renderSettings( s ) {
		$( "cu-lg-watch" ).checked = !! s.watch;
		$( "cu-lg-privileged" ).checked = !! s.privileged;
		$( "cu-lg-email-ip" ).checked = !! s.email_ip;
		$( "cu-lg-privileged" ).disabled = ! s.watch;
		$( "cu-lg-email-ip" ).disabled = ! s.watch;
		var ret = $( "cu-lg-retention" );
		if ( ! Array.prototype.some.call( ret.options, function ( o ) { return Number( o.value ) === Number( s.retention ); } ) ) {
			var opt = document.createElement( "option" );
			opt.value = s.retention;
			opt.textContent = s.retention + " jours";
			ret.appendChild( opt );
		}
		ret.value = String( s.retention );
		var banner = $( "cu-lg-banner" );
		var msg = "";
		if ( ! s.watch ) msg = "Surveillance désactivée : aucune nouvelle connexion n’est enregistrée. Les créations et promotions d’administrateurs restent tracées par le Journal de sécurité.";
		else if ( ! s.email ) msg = "Les alertes e-mail de sécurité sont coupées (Réglages › Alertes e-mail) : les alertes restent visibles ici.";
		banner.textContent = msg;
		banner.hidden = ! msg;
	}

	function fillAccounts( list ) {
		var sel = $( "cu-lg-user" );
		var cur = sel.value;
		sel.length = 1;
		( list || [] ).forEach( function ( a ) {
			var o = document.createElement( "option" );
			o.value = String( a.id );
			o.textContent = a.login;
			sel.appendChild( o );
		} );
		sel.value = cur;
	}

	function query() {
		return "firewall/logins?days=" + encodeURIComponent( $( "cu-lg-days" ).value ) +
			"&user=" + encodeURIComponent( $( "cu-lg-user" ).value ) +
			"&group=" + encodeURIComponent( $( "cu-lg-group" ).value ) +
			"&severity=" + encodeURIComponent( $( "cu-lg-severity" ).value ) +
			"&page=" + state.page;
	}

	function load() {
		cu.apiFetch( query() ).then( function ( d ) {
			state.data = d;
			renderSettings( d.settings || {} );
			renderKpis( d );
			fillAccounts( d.accounts );
			renderTable( d );
			renderPagination( d );
		} ).catch( function ( e ) {
			$( "cu-lg-tbody" ).innerHTML = '<tr><td colspan="7" class="cu-empty">' + esc( e.message || "Chargement impossible." ) + "</td></tr>";
		} );
	}

	// Historique d'un compte : adresses observées et derniers événements.
	function loadAccount( id ) {
		var panel = $( "cu-lg-account" );
		if ( ! id ) { panel.hidden = true; panel.innerHTML = ""; return; }
		panel.hidden = false;
		panel.innerHTML = '<div class="cu-loading"><span class="cu-spinner"></span></div>';
		cu.apiFetch( "firewall/logins/account?id=" + encodeURIComponent( id ) ).then( function ( d ) {
			var u = d.user || {};
			var ips = ( d.ips || [] ).map( function ( r ) {
				return "<tr><td class=\"cu-vu-version\">" + esc( r.ip ) + "</td><td>" + esc( country( r.country ) ) + "</td><td>" + esc( frDate( r.first ) ) + "</td><td>" + esc( frDate( r.last ) ) + "</td><td>" + esc( r.logins ) + "</td></tr>";
			} ).join( "" );
			var events = ( d.events || [] ).slice( 0, 10 ).map( function ( r ) {
				return "<li><b>" + esc( frDate( r.date ) ) + "</b> — " + esc( EVENTS[ r.event ] || r.event ) + ( r.ip ? " · " + esc( r.ip ) : "" ) + " " + statusHtml( r ) + "</li>";
			} ).join( "" );
			panel.innerHTML = '<div class="cu-lg-account-head"><strong>Historique de ' + esc( u.login ) + "</strong>" + ( u.role ? ' <span class="cu-badge cu-badge-purple">' + esc( u.role ) + "</span>" : "" ) +
				( u.monitored ? "" : ' <span class="cu-badge cu-badge-gray">non surveillé actuellement</span>' ) +
				' <span class="cu-vu-muted">(' + esc( d.retention ) + " jours conservés)</span></div>" +
				'<div class="cu-lg-account-body"><div><div class="cu-vu-subtitle">Adresses IP récemment observées</div>' +
				( ips ? '<table class="cu-table"><thead><tr><th>IP</th><th>Pays</th><th>Première fois</th><th>Dernière fois</th><th>Connexions</th></tr></thead><tbody>' + ips + "</tbody></table>" : '<p class="cu-vu-muted">Aucune adresse enregistrée sur la période conservée.</p>' ) +
				( ( d.countries || [] ).length ? '<p class="cu-vu-muted">Pays déjà observés pour ce compte : ' + esc( d.countries.map( country ).join( ", " ) ) + "</p>" : "" ) +
				'</div><div><div class="cu-vu-subtitle">Derniers événements</div>' + ( events ? '<ul class="cu-lg-events">' + events + "</ul>" : '<p class="cu-vu-muted">Aucun événement.</p>' ) + "</div></div>";
		} ).catch( function ( e ) {
			panel.innerHTML = '<p class="cu-vu-note">' + esc( e.message || "Historique indisponible." ) + "</p>";
		} );
	}

	function saveSettings( body ) {
		cu.apiFetch( "firewall/logins/settings", { method: "POST", body: body } ).then( function ( d ) {
			renderSettings( d.settings || {} );
			cu.toast( "Réglage enregistré.", "success" );
		} ).catch( function ( e ) {
			cu.toast( e.message || "Échec de l’enregistrement.", "error" );
			load();
		} );
	}

	[ "cu-lg-days", "cu-lg-group", "cu-lg-severity" ].forEach( function ( id ) {
		$( id ).addEventListener( "change", function () { state.page = 1; load(); } );
	} );
	$( "cu-lg-user" ).addEventListener( "change", function () { state.page = 1; load(); loadAccount( $( "cu-lg-user" ).value ); } );
	$( "cu-lg-refresh" ).addEventListener( "click", load );
	$( "cu-lg-watch" ).addEventListener( "change", function ( e ) { saveSettings( { watch: e.target.checked ? 1 : 0 } ); } );
	$( "cu-lg-privileged" ).addEventListener( "change", function ( e ) { saveSettings( { privileged: e.target.checked ? 1 : 0 } ); } );
	$( "cu-lg-email-ip" ).addEventListener( "change", function ( e ) { saveSettings( { email_ip: e.target.checked ? 1 : 0 } ); } );
	$( "cu-lg-retention" ).addEventListener( "change", function ( e ) { saveSettings( { retention: Number( e.target.value ) } ); } );
	$( "cu-lg-pagination" ).addEventListener( "click", function ( e ) {
		var b = e.target.closest ? e.target.closest( "[data-lg-page]" ) : null;
		if ( ! b || b.disabled ) return;
		state.page = Math.max( 1, Number( b.getAttribute( "data-lg-page" ) ) || 1 );
		load();
	} );
	$( "cu-lg-tbody" ).addEventListener( "click", function ( e ) {
		var t = e.target.closest ? e.target.closest( "[data-lg-toggle]" ) : null;
		if ( t ) {
			var id = t.getAttribute( "data-lg-toggle" );
			state.open[ id ] = ! state.open[ id ];
			if ( state.data ) renderTable( state.data );
			return;
		}
		var a = e.target.closest ? e.target.closest( "[data-lg-account]" ) : null;
		if ( a ) {
			$( "cu-lg-user" ).value = a.getAttribute( "data-lg-account" );
			state.page = 1;
			load();
			loadAccount( $( "cu-lg-user" ).value );
			$( "cu-lg-account" ).scrollIntoView( { behavior: "smooth", block: "start" } );
		}
	} );

	load();
} )();

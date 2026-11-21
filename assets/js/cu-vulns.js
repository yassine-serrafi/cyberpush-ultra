/* CyberPush Ultra — Firewall › « Vulnérabilités & versions ».
 * Dépend de window.cuAdmin (apiFetch, toast, formatNumber). Tout contenu venu
 * de la base externe est échappé avant affichage ; les liens ne sont gardés que
 * s'ils sont en http(s). */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	var root = document.getElementById( "cu-vulns-section" );
	if ( ! cu || ! root ) return;

	var state = { data: null, open: {}, busy: false };
	var SEV = {
		critical: { label: "Critique", cls: "cu-badge-red" },
		high: { label: "Élevée", cls: "cu-badge-red" },
		medium: { label: "Moyenne", cls: "cu-badge-yellow" },
		low: { label: "Faible", cls: "cu-badge-gray" },
		unknown: { label: "Gravité non publiée", cls: "cu-badge-gray" },
	};
	var STATUS = {
		vulnerable: { label: "Vulnérabilité connue", cls: "cu-badge-red" },
		server: { label: "Concerné d’après la version", cls: "cu-badge-yellow" },
		review: { label: "À vérifier", cls: "cu-badge-yellow" },
		eol: { label: "Fin de support", cls: "cu-badge-yellow" },
		ok: { label: "Aucune vulnérabilité connue", cls: "cu-badge-green" },
		unlisted: { label: "Non répertorié", cls: "cu-badge-gray" },
		pending: { label: "En attente de vérification", cls: "cu-badge-gray" },
		unknown: { label: "État inconnu", cls: "cu-badge-gray" },
		offline: { label: "Consultation désactivée", cls: "cu-badge-gray" },
	};
	var TYPE = { php: "Langage", core: "WordPress", mariadb: "Base de données", mysql: "Base de données", apache: "Serveur web", nginx: "Serveur web", curl: "Bibliothèque", imagemagick: "Bibliothèque", plugin: "Extension", theme: "Thème" };
	var SERVER_TYPES = [ "php", "mariadb", "mysql", "apache", "nginx", "curl", "imagemagick" ];

	function $( id ) { return document.getElementById( id ); }
	function esc( s ) {
		return String( null == s ? "" : s ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}
	function safeUrl( u ) { return /^https?:\/\//i.test( String( u || "" ) ) ? String( u ) : ""; }
	function frDate( iso ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec( String( iso || "" ) );
		return m ? m[ 3 ] + "/" + m[ 2 ] + "/" + m[ 1 ] + ( m[ 4 ] ? " " + m[ 4 ] + ":" + m[ 5 ] : "" ) : "";
	}
	function isServer( row ) { return SERVER_TYPES.indexOf( row.type ) !== -1; }

	// ---------------------------------------------------------------------
	// Cycle de maintenance (PHP, base de données, serveur web…)
	// ---------------------------------------------------------------------
	function lifecycleText( row ) {
		var l = row.lifecycle;
		if ( ! l || ! l.state ) return "";
		var end = l.end ? frDate( l.end ) : "";
		if ( "eol" === l.state ) return ( l.branch || "" ) + " : fin de vie" + ( end ? " depuis le " + end : "" ) + " — plus aucun correctif de sécurité.";
		if ( l.near ) return ( l.branch || "" ) + " : fin de support proche" + ( end ? " (" + end + ")" : "" ) + ".";
		if ( "security" === l.state ) return ( l.branch || "" ) + " : correctifs de sécurité uniquement" + ( end ? " jusqu’au " + end : "" ) + ".";
		if ( "maintained" === l.state ) return ( l.branch || "" ) + " : maintenue" + ( end ? " (sécurité jusqu’au " + end + ")" : "" ) + ".";
		return "";
	}

	function lifecycleBadge( row ) {
		var l = row.lifecycle;
		if ( ! l || ! l.state ) return "";
		if ( "eol" === l.state ) return '<span class="cu-badge cu-badge-red">Fin de vie</span>';
		if ( l.near ) return '<span class="cu-badge cu-badge-yellow">Fin de support proche</span>';
		if ( "security" === l.state ) return '<span class="cu-badge cu-badge-purple">Sécurité seulement</span>';
		if ( "maintained" === l.state ) return '<span class="cu-badge cu-badge-green">Maintenue</span>';
		return "";
	}

	function rowNeedsEol( row ) {
		if ( row.lifecycle && ( "eol" === row.lifecycle.state || row.lifecycle.near ) ) return true;
		return ( row.notes || [] ).some( function ( n ) { return "closed" === n.code || "stale" === n.code; } );
	}

	// ---------------------------------------------------------------------
	// Vue générale
	// ---------------------------------------------------------------------
	function renderSummary( d ) {
		var rows = d.rows || [];
		// Alertes = WordPress, extensions, thèmes (corrigeables par une mise à jour).
		// Côté serveur : compté à part, à confirmer auprès de l'hébergeur.
		var alerts = 0, top = 0, eol = 0, comps = 0, serverVulns = 0;
		rows.forEach( function ( r ) {
			if ( r.total && isServer( r ) ) {
				serverVulns += Number( r.total ) || 0;
			} else if ( r.total ) {
				alerts += Number( r.total ) || 0;
				comps++;
				top += ( Number( ( r.counts || {} ).critical ) || 0 ) + ( Number( ( r.counts || {} ).high ) || 0 );
			}
			if ( rowNeedsEol( r ) ) eol++;
		} );
		$( "cu-vu-kpi-total" ).textContent = cu.formatNumber( rows.length );
		$( "cu-vu-kpi-alerts" ).textContent = cu.formatNumber( alerts );
		$( "cu-vu-kpi-alerts" ).style.color = alerts ? "var(--cu-danger)" : "var(--cu-success)";
		$( "cu-vu-kpi-alerts-sub" ).textContent = ( alerts ? comps + " composant" + ( comps > 1 ? "s" : "" ) + " · " + top + " élevée(s) ou critique(s)" : "WordPress, extensions, thèmes : rien à corriger" ) +
			( serverVulns ? " · + " + cu.formatNumber( serverVulns ) + " côté serveur, à confirmer auprès de l’hébergeur" : "" );
		$( "cu-vu-kpi-eol" ).textContent = cu.formatNumber( eol );
		$( "cu-vu-kpi-eol" ).style.color = eol ? "var(--cu-warning)" : "";
		$( "cu-vu-kpi-eol-sub" ).textContent = eol ? "fin de vie, proche ou non maintenu" : "tout est maintenu";
		$( "cu-vu-kpi-sync" ).textContent = d.synced_at ? frDate( d.synced_at ) : "Jamais";
		$( "cu-vu-kpi-next" ).textContent = d.online ? ( d.next_run ? "prochaine : " + frDate( d.next_run ) : "" ) : "consultation désactivée";
		var source = { ok: "Connectée", degraded: "Partielle", partial: "En cours…", error: "Injoignable", disabled: "Désactivée" }[ d.status ] || ( d.online ? "Pas encore consultée" : "Désactivée" );
		var src = $( "cu-vu-kpi-source" );
		src.textContent = source;
		src.style.color = "ok" === d.status ? "var(--cu-success)" : ( "error" === d.status ? "var(--cu-danger)" : "" );

		$( "cu-vu-online" ).checked = !! d.online;
		$( "cu-vu-email" ).checked = !! d.email;
		$( "cu-vu-email" ).disabled = ! d.online;

		// Données anciennes ou incomplètes : toujours dit clairement, jamais une fausse alerte.
		var banner = $( "cu-vu-banner" );
		var msg = "";
		var cls = "is-info";
		if ( ! d.online ) {
			msg = "Consultation en ligne désactivée : l’inventaire reste à jour, mais aucune base de vulnérabilités n’est interrogée." + ( d.synced_at ? " Les résultats affichés datent du " + frDate( d.synced_at ) + "." : "" );
		} else if ( "error" === d.status ) {
			cls = "is-warning";
			msg = "La source de vulnérabilités n’a pas répondu lors du dernier essai (" + frDate( d.attempt_at ) + ")" + ( d.error ? " : " + d.error : "" ) + ". Les derniers résultats valides sont conservés" + ( d.synced_at ? " (" + frDate( d.synced_at ) + ")" : "" ) + " et peuvent être anciens. Nouvel essai automatique plus tard.";
		} else if ( "degraded" === d.status ) {
			cls = "is-warning";
			msg = "Certains composants n’ont pas pu être vérifiés au dernier passage (" + ( d.errors || [] ).slice( 0, 2 ).join( " ; " ) + "). Leur dernier résultat connu est conservé.";
		} else if ( d.pending ) {
			msg = "Vérification en cours : " + d.pending + " composant(s) restant(s), la suite se fait en arrière-plan.";
		} else if ( ! d.synced_at ) {
			msg = "Première vérification pas encore effectuée : cliquez sur « Vérifier maintenant » ou attendez la tâche quotidienne.";
		}
		banner.className = "cu-vu-banner " + cls;
		banner.textContent = msg;
		banner.hidden = ! msg;
	}

	// ---------------------------------------------------------------------
	// Inventaire
	// ---------------------------------------------------------------------
	function sevChips( row ) {
		var c = row.counts || {};
		var out = [];
		[ "critical", "high", "medium", "low", "unknown" ].forEach( function ( k ) {
			if ( Number( c[ k ] ) > 0 ) out.push( '<span class="cu-badge ' + SEV[ k ].cls + ' cu-vu-chip" title="' + esc( SEV[ k ].label ) + '">' + esc( c[ k ] ) + " " + esc( SEV[ k ].label.toLowerCase() ) + "</span>" );
		} );
		return out.join( " " );
	}

	function filterRows( rows ) {
		var f = $( "cu-vu-filter" ).value;
		var q = String( $( "cu-vu-search" ).value || "" ).toLowerCase().trim();
		return rows.filter( function ( r ) {
			if ( q && ( r.name + " " + r.slug ).toLowerCase().indexOf( q ) === -1 ) return false;
			if ( "alerts" === f ) return Number( r.total ) > 0;
			if ( "review" === f ) return Number( r.review_n ) > 0 || "review" === r.status;
			if ( "eol" === f ) return rowNeedsEol( r );
			if ( "plugin" === f || "theme" === f ) return r.type === f;
			if ( "server" === f ) return isServer( r );
			return true;
		} );
	}

	function renderTable() {
		var d = state.data;
		var body = $( "cu-vu-tbody" );
		if ( ! d ) return;
		var rows = filterRows( d.rows || [] );
		if ( ! rows.length ) {
			body.innerHTML = '<tr><td colspan="5" class="cu-empty">Aucun composant pour ce filtre.</td></tr>';
			return;
		}
		body.innerHTML = rows.map( function ( r ) {
			var st = STATUS[ r.status ] || STATUS.unknown;
			var sub = [ TYPE[ r.type ] || r.type ];
			if ( "plugin" === r.type || "theme" === r.type ) sub.push( r.active ? "active" : "inactive" );
			if ( r.raw && r.raw !== r.version ) sub.push( r.raw );
			var vulnCell;
			if ( Number( r.total ) > 0 ) {
				vulnCell = "<strong>" + esc( r.total ) + "</strong> " + sevChips( r ) + ( r.fix ? '<div class="cu-vu-fix">Corrigé en ' + esc( r.fix ) + "</div>" : ( null === r.fix ? '<div class="cu-vu-fix is-none">Correctif non publié pour au moins une faille</div>' : "" ) );
			} else if ( Number( r.review_n ) > 0 ) {
				vulnCell = '<span class="cu-vu-muted">' + esc( r.review_n ) + " à vérifier</span>";
			} else if ( r.checked ) {
				vulnCell = '<span class="cu-vu-muted">0</span>';
			} else {
				vulnCell = '<span class="cu-vu-muted">—</span>';
			}
			if ( Number( r.total ) > 0 && Number( r.review_n ) > 0 ) vulnCell += '<div class="cu-vu-muted">+ ' + esc( r.review_n ) + " à vérifier</div>";
			var stateCell = '<span class="cu-badge ' + st.cls + '">' + esc( st.label ) + "</span>" + ( lifecycleBadge( r ) && "eol" !== r.status ? " " + lifecycleBadge( r ) : "" );
			if ( r.stale ) stateCell += '<div class="cu-vu-muted">résultat du ' + esc( frDate( r.checked ? new Date( r.checked * 1000 ).toISOString() : "" ) ) + " (source indisponible)</div>";
			var hasDetail = Number( r.total ) > 0 || Number( r.review_n ) > 0 || ( r.notes || [] ).length || lifecycleText( r ) || r.error;
			var open = !! state.open[ r.key ];
			var html = '<tr class="cu-vu-row' + ( open ? " is-open" : "" ) + '" data-key="' + esc( r.key ) + '">' +
				'<td><strong>' + esc( r.name ) + '</strong><div class="cu-vu-muted">' + esc( sub.join( " · " ) ) + "</div></td>" +
				'<td class="cu-vu-version">' + esc( r.version || "—" ) + "</td>" +
				"<td>" + stateCell + "</td>" +
				"<td>" + vulnCell + "</td>" +
				'<td style="text-align:right;">' + ( hasDetail ? '<button type="button" class="cu-btn cu-btn-sm" data-vu-toggle="' + esc( r.key ) + '" aria-expanded="' + open + '">' + ( open ? "Masquer" : "Détails" ) + "</button>" : "" ) + "</td></tr>";
			if ( open ) html += '<tr class="cu-vu-detail-row"><td colspan="5">' + detailHtml( r ) + "</td></tr>";
			return html;
		} ).join( "" );
	}

	// ---------------------------------------------------------------------
	// Détail d'un composant et de ses failles
	// ---------------------------------------------------------------------
	function adviceFor( row, v ) {
		if ( isServer( row ) ) {
			return v && v.fixed
				? "Demandez à votre hébergeur si la version " + v.fixed + " (ou un correctif rétroporté) est appliquée ; sinon, passez à une version maintenue."
				: "Vérifiez auprès de votre hébergeur : un correctif peut exister sans changement du numéro de version.";
		}
		if ( v && v.unfixed ) return "Aucun correctif publié : désactivez ou remplacez ce composant si la fonction concernée est exposée, et surveillez une prochaine mise à jour.";
		if ( v && v.fixed ) return "Mettez à jour vers la version " + v.fixed + " ou plus récente (Tableau de bord › Mises à jour).";
		return "Mettez à jour vers la dernière version, puis relancez la vérification.";
	}

	function vulnHtml( row, v, review ) {
		var sev = SEV[ v.severity ] || SEV.unknown;
		var refs = ( v.refs || [] ).map( function ( ref ) {
			var url = safeUrl( ref.link );
			var label = esc( ref.id || ref.source ) + ( ref.source && ref.id && ref.source !== "CVE" ? ' <span class="cu-vu-muted">(' + esc( ref.source ) + ")</span>" : "" );
			return url ? '<a href="' + esc( url ) + '" target="_blank" rel="noopener noreferrer">' + label + "</a>" : label;
		} ).join( " · " );
		var meta = [];
		if ( v.range ) meta.push( "<span><b>Versions concernées :</b> " + esc( v.range ) + "</span>" );
		meta.push( "<span><b>Corrigée en :</b> " + ( v.fixed ? esc( v.fixed ) : ( v.unfixed ? "aucun correctif publié" : "non précisé" ) ) + "</span>" );
		if ( v.published ) meta.push( "<span><b>Publiée :</b> " + esc( frDate( v.published ) ) + ( v.updated && v.updated !== v.published ? " · mise à jour " + esc( frDate( v.updated ) ) : "" ) + "</span>" );
		return '<li class="cu-vu-vuln">' +
			'<div class="cu-vu-vuln-head">' + ( review ? '<span class="cu-badge cu-badge-yellow">À vérifier</span>' : '<span class="cu-badge ' + sev.cls + '">' + esc( sev.label ) + ( null != v.score ? " · CVSS " + esc( v.score ) : "" ) + "</span>" ) +
			( v.exploited || v.kev ? ' <span class="cu-badge cu-badge-red">Exploitée activement</span>' : "" ) +
			' <strong>' + esc( v.title ) + "</strong></div>" +
			( v.desc ? '<p class="cu-vu-desc">' + esc( v.desc ) + "</p>" : "" ) +
			'<div class="cu-vu-meta">' + meta.join( "" ) + "</div>" +
			( refs ? '<div class="cu-vu-refs"><b>Référence(s) :</b> ' + refs + "</div>" : "" ) +
			'<div class="cu-vu-advice"><b>Action conseillée :</b> ' + esc( review ? "vérifiez la version concernée dans l’avis original avant toute conclusion." : adviceFor( row, v ) ) + "</div>" +
			"</li>";
	}

	function detailHtml( r ) {
		var parts = [];
		var life = lifecycleText( r );
		if ( life ) parts.push( '<p class="cu-vu-life">' + esc( life ) + ( r.lifecycle && r.lifecycle.source ? ' <span class="cu-vu-muted">Source : ' + esc( r.lifecycle.source ) + "</span>" : "" ) + "</p>" );
		( r.notes || [] ).forEach( function ( n ) { parts.push( '<p class="cu-vu-note">' + esc( n.text ) + "</p>" ); } );
		if ( r.error ) parts.push( '<p class="cu-vu-note">Dernier essai : ' + esc( r.error ) + "</p>" );
		if ( isServer( r ) && Number( r.total ) > 0 ) {
			parts.push( '<p class="cu-vu-note">Failles concernées d’après le numéro de version' + ( r.distro ? " d’une version empaquetée par la distribution ou l’hébergeur" : "" ) + " : des correctifs peuvent avoir été rétroportés sans changer ce numéro. À confirmer auprès de l’hébergeur.</p>" );
		}
		if ( ( r.vulns || [] ).length ) {
			parts.push( '<ul class="cu-vu-list">' + r.vulns.map( function ( v ) { return vulnHtml( r, v, false ); } ).join( "" ) + "</ul>" );
			if ( Number( r.total ) > r.vulns.length ) parts.push( '<p class="cu-vu-muted">' + esc( Number( r.total ) - r.vulns.length ) + " autre(s) faille(s) de gravité moindre non détaillée(s).</p>" );
		}
		if ( ( r.review || [] ).length ) {
			parts.push( '<p class="cu-vu-subtitle">À vérifier — plage de versions imprécise, faille sans correctif au-delà de la dernière version examinée, ou correspondance incertaine :</p>' );
			parts.push( '<ul class="cu-vu-list">' + r.review.map( function ( v ) { return vulnHtml( r, v, true ); } ).join( "" ) + "</ul>" );
		}
		if ( "unlisted" === r.status ) parts.push( '<p class="cu-vu-note">Absent de la base publique (extension ou thème hors WordPress.org, ou développement sur mesure) : aucune information disponible, ce qui n’est pas une garantie.</p>' );
		if ( "ok" === r.status && ! ( r.review || [] ).length ) parts.push( '<p class="cu-vu-note">Aucune vulnérabilité connue pour cette version dans la base consultée. Ce n’est pas une garantie absolue de sécurité.</p>' );
		var link = safeUrl( r.link );
		if ( link ) parts.push( '<p class="cu-vu-muted"><a href="' + esc( link ) + '" target="_blank" rel="noopener noreferrer">Page officielle</a></p>' );
		return '<div class="cu-vu-detail">' + parts.join( "" ) + "</div>";
	}

	// ---------------------------------------------------------------------
	// Chargement, vérification, réglages
	// ---------------------------------------------------------------------
	function render( d ) {
		state.data = d;
		renderSummary( d );
		renderTable();
	}

	function load() {
		cu.apiFetch( "firewall/vulns" ).then( render ).catch( function ( e ) {
			$( "cu-vu-tbody" ).innerHTML = '<tr><td colspan="5" class="cu-empty">' + esc( e.message || "Impossible de charger l’inventaire." ) + "</td></tr>";
		} );
	}

	function sync( isContinue, round ) {
		var btn = $( "cu-vu-sync" );
		var progress = $( "cu-vu-progress" );
		state.busy = true;
		btn.disabled = true;
		progress.textContent = isContinue ? "Vérification en cours… (passe " + ( round + 1 ) + ")" : "Vérification en cours…";
		cu.apiFetch( "firewall/vulns/sync", { method: "POST", body: { "continue": isContinue ? 1 : 0 } } )
			.then( function ( d ) {
				render( d );
				if ( d.pending && round < 12 ) {
					progress.textContent = "Encore " + d.pending + " composant(s)…";
					setTimeout( function () { sync( true, round + 1 ); }, 800 );
					return;
				}
				state.busy = false;
				btn.disabled = false;
				progress.textContent = "";
				if ( "error" === d.status ) cu.toast( "La source n’a pas répondu : derniers résultats conservés.", "error" );
				else cu.toast( d.pending ? "Vérification poursuivie en arrière-plan." : "Vérification terminée.", "success" );
			} )
			.catch( function ( e ) {
				state.busy = false;
				btn.disabled = false;
				progress.textContent = "";
				cu.toast( e.message || "Vérification impossible.", "error" );
			} );
	}

	function saveSetting( name, value ) {
		var body = {};
		body[ name ] = value ? 1 : 0;
		return cu.apiFetch( "firewall/vulns/settings", { method: "POST", body: body } ).then( function ( d ) {
			render( d );
			cu.toast( "Réglage enregistré.", "success" );
		} ).catch( function ( e ) {
			cu.toast( e.message || "Échec de l’enregistrement.", "error" );
			load();
		} );
	}

	$( "cu-vu-sync" ).addEventListener( "click", function () { if ( ! state.busy ) sync( false, 0 ); } );
	$( "cu-vu-online" ).addEventListener( "change", function ( e ) { saveSetting( "online", e.target.checked ); } );
	$( "cu-vu-email" ).addEventListener( "change", function ( e ) { saveSetting( "email", e.target.checked ); } );
	$( "cu-vu-filter" ).addEventListener( "change", renderTable );
	$( "cu-vu-search" ).addEventListener( "input", cu.debounce ? cu.debounce( renderTable, 150 ) : renderTable );
	$( "cu-vu-tbody" ).addEventListener( "click", function ( e ) {
		var btn = e.target.closest ? e.target.closest( "[data-vu-toggle]" ) : null;
		if ( ! btn ) return;
		var key = btn.getAttribute( "data-vu-toggle" );
		state.open[ key ] = ! state.open[ key ];
		renderTable();
	} );

	load();
} )();

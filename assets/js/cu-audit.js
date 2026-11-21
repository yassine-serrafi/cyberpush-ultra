/* CyberPush Ultra — Journal de sécurité (section de la page Firewall).
 * Enregistre et affiche les changements à haut risque (extensions, admins,
 * réglages, intégrité des fichiers) avec utilisateur, IP, pays et heure.
 * Dépend de window.cuAdmin (apiFetch, toast, confirmAction, formatNumber).
 *
 * Rafraîchissement : la liste n'est rechargée automatiquement (15 s) que
 * pendant un scan d'intégrité lancé ailleurs (cron, autre onglet) ; sinon
 * uniquement à la demande (bouton, filtres, retour sur l'onglet).
 */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	if ( ! cu ) return;

	var state = { page: 1, sev: "", cat: "", search: "", scanning: false, manualScan: false, lastTopId: 0, firstLoad: true };
	var searchTimer = null;
	var liveTimer = null;
	var LIVE_MS = 15000; // suivi d'un scan en cours
	var BUSY_RETRY_MS = 5000;

	function $( id ) { return document.getElementById( id ); }

	function esc( str ) {
		return String( str == null ? "" : str ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}

	function fmt( n ) {
		return cu.formatNumber ? cu.formatNumber( Number( n ) || 0 ) : String( Number( n ) || 0 );
	}

	var sevLabels = { critical: "Critique", high: "Élevé", medium: "Moyen", low: "Faible" };

	function sevBadge( sev ) {
		var s = sevLabels[ sev ] ? sev : "medium";
		return '<span class="cu-sev is-' + s + '">' + esc( sevLabels[ s ] ) + "</span>";
	}

	// Code pays ISO-2 → emoji drapeau (indicateurs régionaux).
	function flag( cc ) {
		if ( ! cc || cc.length !== 2 ) return "";
		var up = cc.toUpperCase();
		if ( ! /^[A-Z]{2}$/.test( up ) ) return "";
		return String.fromCodePoint( 0x1F1E6 + up.charCodeAt( 0 ) - 65, 0x1F1E6 + up.charCodeAt( 1 ) - 65 );
	}

	function relTime( mysqlDate ) {
		if ( ! mysqlDate ) return "";
		var t = cu.siteTime( mysqlDate );
		if ( isNaN( t ) ) return mysqlDate;
		var diff = Math.max( 0, Math.floor( ( Date.now() - t ) / 1000 ) );
		if ( diff < 90 ) return "à l'instant";
		if ( diff < 3600 ) return "il y a " + Math.floor( diff / 60 ) + " min";
		if ( diff < 86400 ) return "il y a " + Math.floor( diff / 3600 ) + " h";
		return "il y a " + Math.floor( diff / 86400 ) + " j";
	}

	var phaseLabels = { prep: "vérification des mises à jour auprès de wordpress.org", walk: "examen des fichiers", removed: "recherche des fichiers disparus", finish: "finalisation", done: "terminé" };

	function progressText( p ) {
		if ( ! p ) return "Scan en cours…";
		var txt = "Scan en cours (" + ( phaseLabels[ p.phase ] || "en cours" ) + ") — " + fmt( p.processed ) + " fichier(s) empreinté(s)";
		if ( Number( p.total ) > 0 && "walk" === p.phase ) {
			txt += " sur ≈ " + fmt( p.total );
		}
		return txt + ".";
	}

	// ---------------------------------------------------------------------
	function load() {
		var params = "?page=" + state.page +
			"&severity=" + encodeURIComponent( state.sev ) +
			"&category=" + encodeURIComponent( state.cat ) +
			"&search=" + encodeURIComponent( state.search );
		// Filtres et recherche peuvent s'enchaîner : seule la dernière demande s'affiche.
		var seq = ++loadSeq;
		cu.apiFetch( "firewall/audit/list" + params )
			.then( function ( d ) { if ( seq === loadSeq ) render( d ); } )
			.catch( function ( e ) {
				if ( seq !== loadSeq ) return;
				$( "cu-audit-tbody" ).innerHTML = '<tr><td colspan="5" class="cu-empty">' + esc( e.message || "Erreur de chargement." ) + "</td></tr>";
			} );
	}
	var loadSeq = 0;

	// Cœur WordPress comparé aux empreintes officielles wordpress.org. Les noms de
	// fichiers viennent du disque : un attaquant peut en choisir un contenant du
	// HTML → toujours échappés.
	function renderCoreCheck( c ) {
		var box = $( "cu-audit-core" );
		if ( ! box ) return;
		var title = "<strong>Cœur WordPress vs empreintes officielles wordpress.org</strong> — ";
		if ( ! c ) {
			box.className = "cu-audit-core";
			box.innerHTML = title + "pas encore vérifié. Lancez « Scanner l'intégrité maintenant ».";
			return;
		}
		var when = c.finished_at ? " · " + esc( relTime( c.finished_at ) ) : "";
		if ( "ok" === c.status ) {
			box.className = "cu-audit-core is-ok";
			box.innerHTML = title + "✓ conforme (WordPress " + esc( c.version ) + " · " + ( Number( c.checked ) || 0 ) + " fichiers PHP vérifiés" + when + ").";
			return;
		}
		if ( "unavailable" === c.status ) {
			box.className = "cu-audit-core is-muted";
			box.innerHTML = title + "vérification impossible (wordpress.org injoignable, ou version " + esc( c.version ) + " sans empreintes publiées). Nouvel essai au prochain scan" + when + ".";
			return;
		}
		var labels = { modified: "modifié", unknown: "inconnu", missing: "absent" };
		var total = Number( c.issues_total ) || 0;
		var issues = Array.isArray( c.issues ) ? c.issues : [];
		var list = issues.map( function ( i ) {
			return '<li><span class="cu-badge ' + ( "missing" === i.type ? "cu-badge-yellow" : "cu-badge-red" ) + '">' + esc( labels[ i.type ] || i.type ) + "</span> <code>" + esc( i.file ) + "</code></li>";
		} ).join( "" );
		var more = total > issues.length ? "<li>… et " + ( total - issues.length ) + " autre(s) (voir le journal ci-dessous).</li>" : "";
		box.className = "cu-audit-core is-bad";
		box.innerHTML = title + cu.icon( "alert" ) + " " + total + " anomalie(s) (WordPress " + esc( c.version ) + when + ")." +
			"<ul>" + list + more + "</ul>" +
			"<p>Remède : <strong>Tableau de bord › Mises à jour › « Réinstaller la version »</strong> remplace les fichiers du cœur par les originaux. Supprimez ensuite les fichiers « inconnus » après vérification. « Réinitialiser la référence » ne masque pas ces anomalies.</p>";
	}

	// Trace des effacements (survit au vidage du journal) + réinitialisation en attente.
	function renderForensics( d ) {
		var core = $( "cu-audit-core" );
		if ( ! core ) return;
		var box = $( "cu-audit-forensics" );
		if ( ! box ) {
			box = document.createElement( "p" );
			box.id = "cu-audit-forensics";
			box.style.cssText = "margin:-8px 0 14px;font-size:12px;color:var(--cu-text-dim);";
			core.parentNode.insertBefore( box, core.nextSibling );
		}
		var parts = [];
		var f = d.forensics || {};
		function who( e ) {
			return esc( e.by || "?" ) + ( e.ip ? " (" + esc( e.ip ) + ")" : "" );
		}
		if ( f.purge && f.purge.at ) {
			parts.push( "Dernier vidage du journal : " + esc( f.purge.at ) + " par " + who( f.purge ) + " — " + fmt( f.purge.rows ) + " événement(s) supprimé(s)" );
		}
		if ( f.reset && f.reset.at ) {
			parts.push( "Dernière réinitialisation de la référence : " + esc( f.reset.at ) + " par " + who( f.reset ) );
		}
		if ( d.rebaseline_pending ) {
			parts.push( "<strong>Réinitialisation en attente</strong> : le prochain scan posera la nouvelle référence et listera les différences." );
		}
		box.innerHTML = parts.join( "<br>" );
		box.hidden = ! parts.length;
	}

	function render( d ) {
		var kpi = d.kpi || {};
		$( "cu-audit-kpi-crit24" ).textContent = fmt( kpi.critical_24h );
		$( "cu-audit-kpi-crit7" ).textContent = fmt( kpi.critical_7d );
		$( "cu-audit-kpi-total" ).textContent = fmt( kpi.total );

		// État de la surveillance d'intégrité.
		var fim = $( "cu-audit-kpi-fim" );
		if ( ! d.baseline_ready ) {
			fim.innerHTML = '<span style="color:var(--cu-text-faint);">Non initialisée</span>';
			fim.title = "";
		} else if ( d.last_scan ) {
			var ch = Number( d.last_scan.changes ) || 0;
			fim.innerHTML = ch > 0
				? '<span style="color:var(--cu-danger);">' + ch + " changement(s)</span>"
				: '<span style="color:var(--cu-success);">✓ intègre</span>';
			fim.title = "Dernier scan : " + relTime( d.last_scan.finished_at ) + " · " + ( Number( d.last_scan.files ) || 0 ) + " fichiers";
		} else {
			fim.innerHTML = '<span style="color:var(--cu-text-dim);">Référence posée</span>';
			fim.title = "";
		}

		renderCoreCheck( d.core_check || null );
		renderForensics( d );

		// Progression d'un scan en cours (cron, autre onglet ou ce bouton).
		state.scanning = !! d.scanning;
		var prog = $( "cu-audit-scan-progress" );
		if ( ! state.manualScan ) {
			if ( d.scanning ) {
				prog.textContent = progressText( d.scan_progress );
			} else if ( d.scan_stalled ) {
				prog.textContent = "Scan interrompu (aucune progression depuis 30 min) — il reprendra au prochain passage du cron, ou relancez-le.";
			} else {
				prog.textContent = "";
			}
		}
		syncLive();

		renderRows( d.items || [] );
		renderPager( d );
		state.firstLoad = false;
	}

	function renderRows( items ) {
		var body = $( "cu-audit-tbody" );
		if ( ! items.length ) {
			body.innerHTML = '<tr><td colspan="5" class="cu-empty">Aucun événement enregistré pour ces critères.</td></tr>';
			return;
		}
		var maxId = state.lastTopId;
		body.innerHTML = items.map( function ( it, idx ) {
			var id = Number( it.id ) || 0;
			if ( id > maxId ) maxId = id;
			// Sur une mise à jour automatique (pas le 1er chargement, page 1), on
			// surligne brièvement les événements réellement nouveaux.
			var isNew = ! state.firstLoad && id > state.lastTopId && state.page === 1;
			var ipCell = it.ip ? ( esc( it.ip ) + ( it.country_code ? ' <span title="' + esc( it.country_code ) + '">' + flag( it.country_code ) + "</span>" : "" ) ) : "—";
			var detail = detailHtml( it );
			var caret = detail ? '<span class="cu-audit-caret">▸</span> ' : "";
			var cls = "cu-audit-row" + ( detail ? " cu-audit-clickable" : "" ) + ( isNew ? " cu-audit-new" : "" );
			var rowCls = detail ? ' class="' + cls + '" data-target="cu-audit-d-' + idx + '"' : ' class="' + cls + '"';
			var main = "<tr" + rowCls + ">" +
				'<td style="white-space:nowrap;" title="' + esc( it.created_at ) + '">' + esc( relTime( it.created_at ) ) + "</td>" +
				"<td>" + sevBadge( it.severity ) + "</td>" +
				'<td class="cu-audit-msg">' + caret + esc( it.message ) + "</td>" +
				"<td>" + esc( it.actor_login || "—" ) + "</td>" +
				'<td style="white-space:nowrap;">' + ipCell + "</td>" +
				"</tr>";
			if ( detail ) {
				main += '<tr class="cu-audit-detail-row" id="cu-audit-d-' + idx + '" hidden><td colspan="5">' + detail + "</td></tr>";
			}
			return main;
		} ).join( "" );

		state.lastTopId = maxId;

		body.querySelectorAll( ".cu-audit-clickable" ).forEach( function ( row ) {
			row.addEventListener( "click", function () {
				var d = document.getElementById( row.dataset.target );
				if ( d ) {
					d.hidden = ! d.hidden;
					row.classList.toggle( "is-open", ! d.hidden );
				}
			} );
		} );
	}

	// ---------------------------------------------------------------------
	// Suivi automatique UNIQUEMENT pendant un scan lancé ailleurs (cron, autre
	// onglet). Pause si : onglet masqué, page d'historique, détail ouvert,
	// recherche en cours de frappe, ou scan manuel (qui rafraîchit lui-même).
	// ---------------------------------------------------------------------
	function shouldPause() {
		if ( document.hidden ) return true;
		if ( state.page !== 1 ) return true;
		if ( state.manualScan ) return true;
		var sb = $( "cu-audit-search" );
		if ( sb && document.activeElement === sb ) return true;
		var body = $( "cu-audit-tbody" );
		if ( body && body.querySelector( ".cu-audit-detail-row:not([hidden])" ) ) return true;
		return false;
	}

	function tick() {
		if ( ! shouldPause() ) {
			load();
		}
		setLiveBadge( shouldPause() );
	}

	function syncLive() {
		var want = state.scanning && ! state.manualScan;
		if ( want && ! liveTimer ) {
			liveTimer = setInterval( tick, LIVE_MS );
		} else if ( ! want && liveTimer ) {
			clearInterval( liveTimer );
			liveTimer = null;
		}
		var b = $( "cu-audit-live" );
		if ( b ) b.hidden = ! want;
		setLiveBadge( shouldPause() );
	}

	function setLiveBadge( paused ) {
		var b = $( "cu-audit-live" );
		if ( ! b ) return;
		b.classList.toggle( "is-paused", !! paused );
		b.title = paused ? "En pause (lecture en cours)" : "Scan en cours : mise à jour automatique toutes les 15 s";
	}

	// Insère l'indicateur « en direct » dans la barre de filtres (visible pendant un scan).
	function injectLiveBadge() {
		var refresh = $( "cu-audit-refresh" );
		if ( ! refresh || $( "cu-audit-live" ) ) return;
		var b = document.createElement( "span" );
		b.id = "cu-audit-live";
		b.className = "cu-audit-live";
		b.hidden = true;
		b.innerHTML = '<span class="cu-audit-live-dot"></span> scan en cours';
		refresh.parentNode.insertBefore( b, refresh );
	}

	// Détail dépliable : contexte (avant/après, hash, fichiers) + catégorie.
	// Les chemins viennent du disque : toujours échappés.
	function detailHtml( it ) {
		var rows = [];
		if ( it.object_name ) rows.push( [ "Objet", it.object_name ] );
		if ( it.category ) rows.push( [ "Catégorie", it.category ] );
		var ctx = it.context;
		if ( ctx && typeof ctx === "object" ) {
			if ( ctx.before != null ) rows.push( [ "Avant", ctx.before ] );
			if ( ctx.after != null ) rows.push( [ "Après", ctx.after ] );
			if ( ctx.hash != null ) rows.push( [ "Empreinte", ctx.hash ] );
			if ( ctx.version != null ) rows.push( [ "Version", ctx.version ] );
		}
		var files = ctx && Array.isArray( ctx.files ) ? ctx.files : [];
		if ( ! rows.length && ! files.length ) return "";
		var html = '<div class="cu-audit-detail">' + rows.map( function ( r ) {
			return '<div class="cu-audit-kv"><span class="cu-audit-k">' + esc( r[ 0 ] ) + '</span><span class="cu-audit-v">' + esc( r[ 1 ] ) + "</span></div>";
		} ).join( "" );
		if ( files.length ) {
			html += '<div class="cu-audit-kv"><span class="cu-audit-k">Fichiers</span><span class="cu-audit-v"><code style="white-space:pre-wrap;word-break:break-all;">' +
				files.slice( 0, 50 ).map( esc ).join( "\n" ) + "</code></span></div>";
		}
		return html + "</div>";
	}

	function renderPager( d ) {
		var pager = $( "cu-audit-pagination" );
		var pages = Number( d.pages ) || 0;
		var page = Number( d.page ) || 1;
		if ( pages <= 1 ) { pager.innerHTML = ""; return; }
		pager.innerHTML =
			'<button class="cu-btn cu-btn-sm" ' + ( page <= 1 ? "disabled" : "" ) + ' id="cu-audit-prev">‹</button>' +
			'<span class="cu-seo-pageinfo" style="margin:0 10px;">' + page + " / " + pages + " · " + esc( fmt( d.total ) ) + " événements</span>" +
			'<button class="cu-btn cu-btn-sm" ' + ( page >= pages ? "disabled" : "" ) + ' id="cu-audit-next">›</button>';
		var prev = $( "cu-audit-prev" ), next = $( "cu-audit-next" );
		if ( prev ) prev.addEventListener( "click", function () { if ( state.page > 1 ) { state.page--; load(); } } );
		if ( next ) next.addEventListener( "click", function () { if ( state.page < pages ) { state.page++; load(); } } );
	}

	// ---------------------------------------------------------------------
	// Scan d'intégrité : boucle de lots bornés en temps (≈ 4 s chacun).
	// ---------------------------------------------------------------------
	function stopManual( message, type ) {
		state.manualScan = false;
		$( "cu-audit-scan" ).disabled = false;
		$( "cu-audit-scan-progress" ).textContent = "";
		if ( message ) cu.toast( message, type || "error" );
		syncLive();
	}

	function runScan( first, ctx ) {
		ctx = ctx || { guard: 0, same: 0, busy: 0, last: "" };
		state.manualScan = true;
		syncLive();
		var btn = $( "cu-audit-scan" );
		btn.disabled = true;
		var prog = $( "cu-audit-scan-progress" );
		if ( first ) prog.textContent = "Scan en cours…";
		cu.apiFetch( "firewall/audit/scan", { method: "POST", body: { start: first ? 1 : 0 } } )
			.then( function ( res ) {
				if ( res.error ) {
					stopManual( "Scan interrompu : erreur d'accès à la base de données. Consultez la page Logs." );
					return;
				}
				if ( res.busy ) {
					// Un autre lot (cron, autre onglet) tient le verrou : on patiente.
					if ( ++ctx.busy > 60 ) {
						stopManual( "Un autre scan occupe le serveur depuis plusieurs minutes. Réessayez plus tard.", "info" );
						return;
					}
					prog.textContent = "Un autre lot de scan est en cours… nouvelle tentative dans quelques secondes.";
					setTimeout( function () { runScan( false, ctx ); }, BUSY_RETRY_MS );
					return;
				}
				ctx.busy = 0;
				if ( res.done ) {
					stopManual( "" );
					prog.textContent = "Scan terminé — " + fmt( res.changes ) + " changement(s) sur " + fmt( res.processed ) + " fichiers.";
					cu.toast( "Scan d'intégrité terminé.", "success" );
					load();
					return;
				}
				prog.textContent = progressText( { phase: res.phase, processed: res.processed, total: res.total } ) +
					( res.changes ? " · " + fmt( res.changes ) + " changement(s)" : "" );
				// Garde-fou : progression figée (même phase, mêmes compteurs) ou lots anormaux.
				var mark = [ res.phase, res.processed, res.visited, res.pending, res.checked ].join( "|" );
				ctx.same = mark === ctx.last ? ctx.same + 1 : 0;
				ctx.last = mark;
				if ( ctx.same >= 5 || ++ctx.guard > 2000 ) {
					stopManual( "Scan interrompu : aucune progression. Consultez la page Logs." );
					return;
				}
				runScan( false, ctx );
			} )
			.catch( function ( e ) {
				stopManual( e.message || "Échec du scan." );
			} );
	}

	// ---------------------------------------------------------------------
	function bind() {
		$( "cu-audit-refresh" ).addEventListener( "click", function () { state.page = 1; load(); } );
		$( "cu-audit-sev" ).addEventListener( "change", function () { state.sev = this.value; state.page = 1; load(); } );
		$( "cu-audit-cat" ).addEventListener( "change", function () { state.cat = this.value; state.page = 1; load(); } );
		$( "cu-audit-search" ).addEventListener( "input", function () {
			var v = this.value;
			clearTimeout( searchTimer );
			searchTimer = setTimeout( function () { state.search = v.trim(); state.page = 1; load(); }, 350 );
		} );

		$( "cu-audit-scan" ).addEventListener( "click", function () { runScan( true ); } );

		$( "cu-audit-baseline" ).addEventListener( "click", function () {
			if ( ! cu.confirmAction( "Réinitialiser la référence d'intégrité ?\n\nLe prochain scan acceptera l'état ACTUEL des fichiers comme nouvelle référence ; les fichiers qui différaient seront listés dans un seul événement, sans alerte individuelle. À faire après une modification légitime.\n\nCette action est tracée et signalée par e-mail." ) ) return;
			cu.apiFetch( "firewall/audit/baseline-reset", { method: "POST" } )
				.then( function () { cu.toast( "Référence réinitialisée. Lancez un scan pour poser la nouvelle référence.", "success" ); load(); } )
				.catch( function ( e ) { cu.toast( e.message || "Échec.", "error" ); } );
		} );

		$( "cu-audit-purge" ).addEventListener( "click", function () {
			if ( ! cu.confirmAction( "Vider tout le journal de sécurité ? Cette action est irréversible.\n\nLe vidage lui-même reste inscrit dans le journal et une alerte e-mail est envoyée." ) ) return;
			cu.apiFetch( "firewall/audit/purge", { method: "POST" } )
				.then( function () { cu.toast( "Journal vidé.", "success" ); state.page = 1; load(); } )
				.catch( function ( e ) { cu.toast( e.message || "Échec.", "error" ); } );
		} );
	}

	document.addEventListener( "DOMContentLoaded", function () {
		if ( ! $( "cu-audit-section" ) ) return;
		bind();
		injectLiveBadge();
		load();
		// Retour sur l'onglet : une seule mise à jour (pas de minuterie permanente).
		document.addEventListener( "visibilitychange", function () {
			if ( ! document.hidden && ! shouldPause() ) {
				load();
			}
			setLiveBadge( shouldPause() );
		} );
	} );
} )();

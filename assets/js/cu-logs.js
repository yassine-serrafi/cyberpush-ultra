/* CyberPush Ultra — page Logs (chronologie unifiée) */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	if ( ! cu ) return;

	var page = document.getElementById( "cu-logs-page" );
	if ( ! page ) return;

	var state = { module: "all", level: "all", hours: 24, search: "", page: 1 };

	var levelBadge = {
		debug: "cu-badge-gray",
		info: "cu-badge-purple",
		warning: "cu-badge-yellow",
		error: "cu-badge-red",
	};

	var levelLabel = {
		debug: "Débogage",
		info: "Info",
		warning: "Avertissement",
		error: "Erreur",
	};

	// Contexte technique (erreur SQL, opération, IP…) : indispensable au diagnostic.
	function contextHtml( context ) {
		if ( ! context || "object" !== typeof context || ! Object.keys( context ).length ) return "";
		return '<details class="cu-log-context"><summary>Détails</summary><pre>' +
			escapeHtml( JSON.stringify( context, null, 2 ) ) + "</pre></details>";
	}

	// Message répété dans la minute : une seule ligne « ×N » (regroupement serveur).
	function repeatBadge( context ) {
		var n = context && "object" === typeof context ? Number( context.repeat ) || 0 : 0;
		if ( n < 2 ) return "";
		var last = context.last_at ? " — dernière occurrence : " + String( context.last_at ) : "";
		return ' <span class="cu-badge cu-badge-gray" title="' + escapeHtml( "Répété " + n + " fois" + last ) + '">×' + escapeHtml( String( n ) ) + "</span>";
	}

	var moduleLabel = {
		analytics: "Analytics",
		firewall: "Firewall",
		seo: "SEO",
		ai: "IA",
		woocommerce: "WooCommerce",
		system: "Système",
	};

	function query() {
		var params = new URLSearchParams();
		params.set( "module", state.module );
		params.set( "level", state.level );
		params.set( "hours", state.hours );
		params.set( "page", state.page );
		if ( state.search ) params.set( "search", state.search );
		return params.toString();
	}

	function renderRows( items ) {
		var tbody = document.getElementById( "cu-logs-tbody" );
		if ( ! items.length ) {
			tbody.innerHTML = '<tr><td colspan="4" class="cu-empty">' + escapeHtml( cu.config.i18n.noData ) + "</td></tr>";
			return;
		}
		tbody.innerHTML = items
			.map( function ( row ) {
				var badgeClass = levelBadge[ row.level ] || "cu-badge-gray";
				var date = new Date( String( row.created_at || "" ).replace( " ", "T" ) );
				return (
					"<tr>" +
					"<td>" + escapeHtml( isNaN( date.getTime() ) ? String( row.created_at || "" ) : date.toLocaleString( "fr-FR" ) ) + "</td>" +
					'<td><span class="cu-badge cu-badge-purple">' + escapeHtml( moduleLabel[ row.module ] || row.module ) + "</span></td>" +
					'<td><span class="cu-badge ' + badgeClass + '">' + escapeHtml( levelLabel[ row.level ] || row.level ) + "</span></td>" +
					"<td>" + escapeHtml( row.message ) + repeatBadge( row.context ) + contextHtml( row.context ) + "</td>" +
					"</tr>"
				);
			} )
			.join( "" );
	}

	function escapeHtml( str ) {
		var div = document.createElement( "div" );
		div.textContent = str || "";
		return div.innerHTML;
	}

	function renderPagination( data ) {
		var el = document.getElementById( "cu-logs-pagination" );
		if ( data.pages <= 1 ) {
			el.innerHTML = "";
			return;
		}
		el.innerHTML =
			'<button class="cu-btn cu-btn-sm" id="cu-logs-prev" ' + ( data.page <= 1 ? "disabled" : "" ) + ">&larr;</button>" +
			"<span>" + ( Number( data.page ) || 1 ) + " / " + ( Number( data.pages ) || 1 ) + "</span>" +
			'<button class="cu-btn cu-btn-sm" id="cu-logs-next" ' + ( data.page >= data.pages ? "disabled" : "" ) + ">&rarr;</button>";

		var prev = document.getElementById( "cu-logs-prev" );
		var next = document.getElementById( "cu-logs-next" );
		if ( prev ) prev.addEventListener( "click", function () { state.page--; load(); } );
		if ( next ) next.addEventListener( "click", function () { state.page++; load(); } );
	}

	function load() {
		var tbody = document.getElementById( "cu-logs-tbody" );
		tbody.innerHTML = '<tr><td colspan="4" class="cu-empty"><span class="cu-spinner"></span></td></tr>';
		cu.apiFetch( "logs?" + query() )
			.then( function ( data ) {
				renderRows( data.items );
				renderPagination( data );
			} )
			.catch( function ( err ) {
				tbody.innerHTML = '<tr><td colspan="4" class="cu-empty">' + escapeHtml( err.message ) + "</td></tr>";
			} );
	}

	function loadSummary() {
		cu.apiFetch( "logs/summary" )
			.then( function ( data ) {
				var box = document.getElementById( "cu-logs-summary" );
				box.querySelector( '[data-field="total"]' ).textContent = cu.formatNumber( data.total );
				box.querySelector( '[data-field="last_24h"]' ).textContent = cu.formatNumber( data.last_24h );
				var warnings = ( data.by_level || [] )
					.filter( function ( l ) { return "warning" === l.level || "error" === l.level; } )
					.reduce( function ( sum, l ) { return sum + Number( l.total ); }, 0 );
				box.querySelector( '[data-field="warnings"]' ).textContent = cu.formatNumber( warnings );
				var top = ( data.by_module || [] ).sort( function ( a, b ) { return b.total - a.total; } )[ 0 ];
				box.querySelector( '[data-field="top_module"]' ).textContent = top ? ( moduleLabel[ top.module ] || top.module ) : "–";
			} )
			.catch( function () {} );
	}

	function loadFallback() {
		var output = document.getElementById( "cu-logs-fallback" );
		var count = document.getElementById( "cu-logs-fallback-count" );
		if ( ! output || ! count ) return;
		output.textContent = cu.config.i18n.loading || "Chargement…";
		cu.apiFetch( "system/error-log" )
			.then( function ( data ) {
				var lines = Array.isArray( data.lines ) ? data.lines : [];
				count.textContent = String( lines.length );
				// Rouge uniquement s'il y a réellement des erreurs ; vert sinon.
				count.className = "cu-badge " + ( lines.length ? "cu-badge-red" : "cu-badge-green" );
				output.textContent = lines.length ? lines.join( "\n" ) : "Aucune erreur PHP enregistrée.";
			} )
			.catch( function ( err ) {
				count.textContent = "!";
				count.className = "cu-badge cu-badge-red";
				output.textContent = err.message;
			} );
	}

	document.getElementById( "cu-logs-filter-module" ).addEventListener( "change", function ( e ) {
		state.module = e.target.value;
		state.page = 1;
		load();
	} );
	document.getElementById( "cu-logs-filter-level" ).addEventListener( "change", function ( e ) {
		state.level = e.target.value;
		state.page = 1;
		load();
	} );
	document.getElementById( "cu-logs-filter-range" ).addEventListener( "change", function ( e ) {
		state.hours = Number( e.target.value );
		state.page = 1;
		load();
	} );
	document.getElementById( "cu-logs-search" ).addEventListener(
		"input",
		cu.debounce( function ( e ) {
			state.search = e.target.value;
			state.page = 1;
			load();
		}, 350 )
	);

	document.getElementById( "cu-logs-purge" ).addEventListener( "click", function () {
		if ( ! cu.confirmAction( "Supprimer les entrées du journal de plus de 24 h (et les anciens fichiers d'erreurs) ?" ) ) return;
		cu.apiFetch( "logs/purge", { method: "POST", body: { mode: "keep24h" } } )
			.then( function () {
				cu.toast( cu.config.i18n.saved, "success" );
				load();
				loadSummary();
				loadFallback();
			} )
			.catch( function ( err ) {
				cu.toast( err.message, "error" );
			} );
	} );

	var fallbackRefresh = document.getElementById( "cu-logs-fallback-refresh" );
	if ( fallbackRefresh ) fallbackRefresh.addEventListener( "click", loadFallback );

	// Export CSV : le jeton REST part dans l'en-tête X-WP-Nonce (jamais dans
	// l'URL, qui finirait dans l'historique, les journaux serveur et le Referer).
	var exportBtn = document.getElementById( "cu-logs-export" );
	exportBtn.addEventListener( "click", function () {
		if ( exportBtn.disabled ) return;
		exportBtn.disabled = true;
		fetch( cu.restUrl( "logs/export?" + query() ), {
			headers: { "X-WP-Nonce": cu.config.nonce },
			credentials: "same-origin",
		} )
			.then( function ( r ) {
				var type = r.headers.get( "content-type" ) || "";
				if ( ! r.ok || type.indexOf( "text/csv" ) === -1 ) {
					return r.text().then( function ( raw ) {
						var msg = "Export impossible (HTTP " + r.status + ").";
						try {
							var data = JSON.parse( raw );
							if ( data && data.message ) msg = data.message;
						} catch ( e ) { /* réponse non JSON */ }
						throw new Error( msg );
					} );
				}
				return r.blob();
			} )
			.then( function ( blob ) {
				var a = document.createElement( "a" );
				var objectUrl = URL.createObjectURL( blob );
				a.href = objectUrl;
				a.download = "cyberpush-ultra-logs.csv";
				document.body.appendChild( a );
				a.click();
				a.remove();
				setTimeout( function () { URL.revokeObjectURL( objectUrl ); }, 1000 );
			} )
			.catch( function ( err ) { cu.toast( err.message || "Export impossible.", "error" ); } )
			.then( function () { exportBtn.disabled = false; } );
	} );

	load();
	loadSummary();
	loadFallback();
} )();

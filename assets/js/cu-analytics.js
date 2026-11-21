/* CyberPush Ultra — Analytics page
 * Vanilla JS IIFE. Depends on window.cuAdmin (cu-admin.js).
 */
( function () {
	"use strict";

	var api   = window.cuAdmin;
	var page  = document.getElementById( "cu-analytics-page" );
	if ( ! page || ! api ) return;

	var parsedCacheMin = parseInt( page.dataset.cacheMin, 10 );
	var cfg = {
		realtimeRefresh: parseInt( page.dataset.realtimeRefresh, 10 ) || 20,
		cacheMin: isNaN( parsedCacheMin ) ? 10 : parsedCacheMin,
		privacyMode: page.dataset.privacyMode || "exempt",
		// Filtrage datacenter / VPN réellement actif (fournisseur IP externe, hors mode exempté).
		dcFilter: "1" === page.dataset.dcFilter,
	};

	// Évolution : sans période précédente, « nouveau » plutôt qu'un faux « +100 % ».
	function deltaHtml( delta, lowerIsBetter ) {
		if ( null === delta ) return '<span class="cu-kpi-delta is-new" title="Aucune donnée sur la période précédente">nouveau</span>';
		return api.deltaBadge( delta, lowerIsBetter );
	}

	// Ce que le segment « fiable » écarte vraiment (jamais un filtre inactif).
	function filteredLabel() {
		return cfg.dcFilter ? "robots / datacenter / proxy" : "robots / navigateurs automatisés";
	}

	// State
	var state = {
		range:   "30",
		segment: "reliable",
		contentPage: 1,
		contentSearch: "",
		contentType: "",
		contentSort: "sessions",
		realtimeTimer: null,
		realtimeRequest: null,
	};

	// Toutes les sections partagent un ordonnanceur borné. Une page Analytics
	// complète déclenche beaucoup de rapports : les lancer sans limite ouvre un
	// grand nombre de workers PHP en parallèle et rend les réponses anciennes
	// susceptibles d'écraser le filtre courant.
	var REQUEST_MAX_CONCURRENCY = 4;
	var REQUEST_TIMEOUT_MS = 30000;
	var requestQueue = [];
	var requestActive = 0;
	var requestVersions = Object.create( null );
	var requestJobs = Object.create( null );

	function silentRequestError() {
		var err = new Error( "Requête remplacée." );
		err.cuSilent = true;
		return err;
	}

	function isSilentRequestError( err ) {
		return !! ( err && ( err.cuSilent || "AbortError" === err.name ) );
	}

	function cancelRequest( key ) {
		requestVersions[ key ] = ( requestVersions[ key ] || 0 ) + 1;
		var job = requestJobs[ key ];
		if ( ! job ) return;
		job.superseded = true;
		if ( job.controller ) job.controller.abort();
		if ( ! job.started && ! job.settled ) {
			job.settled = true;
			job.reject( silentRequestError() );
		}
		if ( requestJobs[ key ] === job ) delete requestJobs[ key ];
	}

	function drainRequestQueue() {
		while ( requestActive < REQUEST_MAX_CONCURRENCY && requestQueue.length ) {
			var job = requestQueue.shift();
			if ( job.settled || job.superseded || requestVersions[ job.key ] !== job.version ) {
				if ( ! job.settled ) {
					job.settled = true;
					job.reject( silentRequestError() );
				}
				continue;
			}

			job.started = true;
			requestActive++;
			if ( "function" === typeof window.AbortController ) {
				job.controller = new window.AbortController();
			}

			( function ( runningJob ) {
				var timedOut = false;
				var timeout;
				var options = runningJob.controller ? { signal: runningJob.controller.signal } : {};
				var fetchPromise;
				var timeoutPromise = new Promise( function ( resolve, reject ) {
					timeout = setTimeout( function () {
						timedOut = true;
						if ( runningJob.controller ) runningJob.controller.abort();
						reject( new Error( "Le serveur Analytics n'a pas répondu dans les 30 secondes." ) );
					}, REQUEST_TIMEOUT_MS );
				} );

				try {
					fetchPromise = api.apiFetch( runningJob.path, options );
				} catch ( err ) {
					fetchPromise = Promise.reject( err );
				}

				Promise.race( [ Promise.resolve( fetchPromise ), timeoutPromise ] ).then( function ( data ) {
					if ( runningJob.superseded || requestVersions[ runningJob.key ] !== runningJob.version ) {
						throw silentRequestError();
					}
					return data;
				} ).then( function ( data ) {
					finish( true, data );
				}, function ( err ) {
					if ( timedOut && ! runningJob.superseded ) {
						err = new Error( "Le serveur Analytics n'a pas répondu dans les 30 secondes." );
					} else if ( runningJob.superseded || requestVersions[ runningJob.key ] !== runningJob.version ) {
						err = silentRequestError();
					}
					finish( false, err );
				} );

				function finish( ok, value ) {
					clearTimeout( timeout );
					requestActive = Math.max( 0, requestActive - 1 );
					if ( requestJobs[ runningJob.key ] === runningJob ) delete requestJobs[ runningJob.key ];
					if ( ! runningJob.settled ) {
						runningJob.settled = true;
						if ( ok ) runningJob.resolve( value );
						else runningJob.reject( value );
					}
					drainRequestQueue();
				}
			} )( job );
		}
	}

	function analyticsFetch( key, path ) {
		cancelRequest( key );
		var version = requestVersions[ key ];
		return new Promise( function ( resolve, reject ) {
			var job = {
				key: key,
				path: path,
				version: version,
				resolve: resolve,
				reject: reject,
				controller: null,
				started: false,
				settled: false,
				superseded: false,
			};
			requestJobs[ key ] = job;
			requestQueue.push( job );
			drainRequestQueue();
		} );
	}

	function errorText( err ) {
		return ( err && err.message ) ? err.message : "Impossible de charger les données Analytics.";
	}

	function renderError( id, err ) {
		if ( isSilentRequestError( err ) ) return;
		var el = document.getElementById( id );
		if ( el ) el.innerHTML = '<div class="cu-empty cu-error">' + esc( errorText( err ) ) + '</div>';
	}

	function clearCanvas( id ) {
		var canvas = document.getElementById( id );
		if ( ! canvas || ! canvas.getContext ) return;
		var ctx = canvas.getContext( "2d" );
		if ( ctx ) ctx.clearRect( 0, 0, canvas.width, canvas.height );
	}

	function renderKpiError( err ) {
		if ( isSilentRequestError( err ) ) return;
		page.querySelectorAll( "#cu-kpi-grid .cu-kpi" ).forEach( function ( card ) {
			var value = card.querySelector( ".cu-kpi-value" );
			var delta = card.querySelector( ".cu-kpi-delta-wrap" );
			if ( value ) {
				value.textContent = "Erreur";
				value.title = errorText( err );
			}
			if ( delta ) delta.textContent = "";
		} );
		var quality = document.getElementById( "cu-traffic-quality" );
		if ( quality ) {
			quality.textContent = errorText( err );
			quality.style.color = "var(--cu-danger)";
		}
		[ "human", "bounce", "ctr" ].forEach( function ( key ) {
			setText( "cu-gauge-" + key + "-val", "—" );
			clearCanvas( "cu-gauge-" + key );
		} );
	}

	// -------------------------------------------------------------------------
	// Control elements
	// -------------------------------------------------------------------------
	var elRange   = document.getElementById( "cu-range" );
	var elSegment = document.getElementById( "cu-segment" );
	var btnRefresh = document.getElementById( "cu-btn-refresh" );
	var btnExportCsv = document.getElementById( "cu-btn-export-csv" );
	var elSearch  = document.getElementById( "cu-content-search" );
	var elType    = document.getElementById( "cu-content-type" );
	var elSort    = document.getElementById( "cu-content-sort" );

	if ( elRange )   elRange.addEventListener( "change", onFilterChange );
	if ( elSegment ) elSegment.addEventListener( "change", onFilterChange );
	if ( btnRefresh ) btnRefresh.addEventListener( "click", function () { loadAll( true ); } );
	if ( btnExportCsv ) btnExportCsv.addEventListener( "click", exportCsv );

	if ( elSearch ) elSearch.addEventListener( "input", api.debounce( function () {
		state.contentSearch = elSearch.value;
		state.contentPage = 1;
		loadContentTable();
	}, 400 ) );
	if ( elType ) elType.addEventListener( "change", function () {
		state.contentType = elType.value;
		state.contentPage = 1;
		loadContentTable();
	} );
	if ( elSort ) elSort.addEventListener( "change", function () {
		state.contentSort = elSort.value;
		state.contentPage = 1;
		loadContentTable();
	} );

	function onFilterChange() {
		state.range   = elRange   ? elRange.value   : state.range;
		state.segment = elSegment ? elSegment.value : state.segment;
		state.contentPage = 1;
		loadAll();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------
	function qp() {
		var query = "range=" + encodeURIComponent( state.range ) + "&segment=" + encodeURIComponent( state.segment );
		if ( state.refreshToken ) query += "&refresh=" + encodeURIComponent( state.refreshToken );
		return query;
	}

	function loading( id ) {
		var el = document.getElementById( id );
		if ( ! el ) return;
		// Skeleton shimmer (Observatory) plutôt qu'un spinner → chargement premium.
		el.innerHTML =
			'<div class="cu-skel-wrap">' +
				'<div class="cu-skel cu-skel-line" style="width:72%"></div>' +
				'<div class="cu-skel cu-skel-line" style="width:92%"></div>' +
				'<div class="cu-skel cu-skel-line" style="width:58%"></div>' +
			'</div>';
	}

	function empty( id, msg ) {
		var el = document.getElementById( id );
		if ( el ) el.innerHTML = '<div class="cu-empty">' + ( msg || "Aucune donnée." ) + '</div>';
	}

	var DEVICE_NAMES = { mobile: "Mobile", desktop: "Ordinateur", tablet: "Tablette", bot: "Robot" };

	// Palette catégorielle : jetons --cu-viz-1 … --cu-viz-10 du design system.
	function donutColor( i ) {
		var colors = api.vizColors();
		return colors[ i % colors.length ];
	}

	function donutLegend( containerId, data ) {
		var el = document.getElementById( containerId );
		if ( ! el || ! data ) return;
		var total = data.reduce( function ( s, d ) { return s + d.value; }, 0 ) || 1;
		el.innerHTML = data.map( function ( d, i ) {
			var pct = Math.round( d.value / total * 100 );
			return '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">' +
				'<span style="width:10px;height:10px;border-radius:50%;background:' + donutColor( i ) + ';flex-shrink:0;display:inline-block;"></span>' +
				'<span style="flex:1;color:var(--cu-text-dim)">' + esc( d.label ) + '</span>' +
				'<strong>' + fmtPct( pct ) + '</strong>' +
				'</div>';
		} ).join( "" );
	}

	function barList( rows, keyLabel, keyValue, keyTotal ) {
		if ( ! rows || ! rows.length ) return '<div class="cu-empty">Aucune donnée.</div>';
		var total = keyTotal ? rows.reduce( function ( s, r ) { return s + r[ keyValue ]; }, 0 ) || 1 : null;
		return rows.map( function ( r ) {
			var rawPct = r.percent !== undefined ? Number( r.percent ) : ( total ? Math.round( Number( r[ keyValue ] || 0 ) / total * 100 ) : 0 );
			var pct = Math.max( 0, Math.min( 100, isFinite( rawPct ) ? rawPct : 0 ) );
			return '<div style="margin-bottom:12px;">' +
				'<div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:12px;">' +
				'<span style="color:var(--cu-text)">' + esc( r[ keyLabel ] ) + '</span>' +
				'<strong>' + api.formatNumber( r[ keyValue ] ) + ' <span style="color:var(--cu-text-dim)">(' + fmtPct( pct ) + ')</span></strong>' +
				'</div>' +
				'<div class="cu-bar-track"><div class="cu-bar-fill" style="width:' + pct + '%;"></div></div>' +
				'</div>';
		} ).join( "" );
	}

	function esc( s ) {
		if ( s == null ) return "";
		return String( s )
			.replace( /&/g, "&amp;" )
			.replace( /</g, "&lt;" )
			.replace( />/g, "&gt;" )
			.replace( /"/g, "&quot;" );
	}

	function setText( id, txt ) { var el = document.getElementById( id ); if ( el ) el.textContent = txt; }

	// Pourcentage sûr pour un attribut style (jamais une chaîne du serveur telle quelle).
	function clampPct( value ) {
		var n = Number( value );
		return isFinite( n ) ? Math.max( 0, Math.min( 100, n ) ) : 0;
	}

	// Nom de pays lisible (« France » plutôt que « FR »), avec repli sur le code.
	var regionNames = null;
	try {
		if ( window.Intl && "function" === typeof window.Intl.DisplayNames ) {
			// Interface en français : noms de pays en français, quelle que soit la langue de l’administration.
			regionNames = new window.Intl.DisplayNames( [ "fr" ], { type: "region" } );
		}
	} catch ( e ) { regionNames = null; }
	function countryName( code ) {
		code = String( code || "" ).toUpperCase();
		if ( ! /^[A-Z]{2}$/.test( code ) ) return "Inconnu";
		try {
			var name = regionNames ? regionNames.of( code ) : "";
			return name && name !== code ? name + " (" + code + ")" : code;
		} catch ( e ) {
			return code;
		}
	}

	// Chemin lisible d'une URL du site (sans protocole ni domaine).
	function urlPath( url ) {
		var path = String( url || "" ).replace( /^https?:\/\/[^/]+/i, "" );
		return path || "/";
	}

	// Ring gauge animée (via cu-viz) sur un canvas identifié par son id.
	function gauge( canvasId, pct, color ) {
		var c = document.getElementById( canvasId );
		if ( c && window.cuViz ) window.cuViz.ringGauge( c, pct, { color: color } );
	}

	// -------------------------------------------------------------------------
	// Load all sections
	// -------------------------------------------------------------------------
	var lazyReports = [
		{ id: "cu-section-entry-exit", load: loadEntryExit },
		{ id: "cu-section-loyalty", load: loadLoyalty },
		{ id: "cu-section-scroll", load: loadScrollDepth },
		{ id: "cu-section-tech", load: loadTech },
		{ id: "cu-section-countries", load: loadCountries },
		{ id: "cu-section-outbound", load: loadOutbound },
		{ id: "cu-section-audience", load: loadAudience },
		{ id: "cu-section-sources", load: loadSources },
		{ id: "cu-section-referrers", load: loadReferrers },
		{ id: "cu-section-ai", load: loadAiTraffic },
		{ id: "cu-section-recountries", load: loadRecurringCountries },
		{ id: "cu-section-paths", load: loadPaths },
		{ id: "cu-section-jserrors", load: loadJsErrors },
		{ id: "cu-section-taxonomies", load: function () { loadCategories(); loadTags(); loadAuthors(); } },
		{ id: "cu-section-woo", load: loadWoo },
		{ id: "cu-section-insights", load: loadInsights },
	];

	function initLazyReports() {
		if ( "function" !== typeof window.IntersectionObserver ) {
			lazyReports.forEach( function ( report ) {
				report.loaded = true;
				report.load();
			} );
			return;
		}
		var observer = new window.IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) return;
				var report = lazyReports.find( function ( candidate ) { return candidate.id === entry.target.id; } );
				if ( ! report || report.loaded ) return;
				report.loaded = true;
				observer.unobserve( entry.target );
				report.load();
			} );
		}, { rootMargin: "400px 0px" } );
		lazyReports.forEach( function ( report ) {
			var section = document.getElementById( report.id );
			if ( section ) observer.observe( section );
		} );
	}

	function refreshLoadedLazyReports() {
		lazyReports.forEach( function ( report ) {
			if ( report.loaded ) report.load();
		} );
	}

	function loadAll( clearCache ) {
		if ( clearCache ) {
			state.refreshToken = Date.now();
		}
		loadKpi();
		loadContacts();
		loadBreakdown();
		loadTimeseries();
		loadHeatmap();
		loadContentTable();
		refreshLoadedLazyReports();
		if ( clearCache ) {
			loadBrief( briefPeriod, state.refreshToken );
			// Tous les appels ci-dessus ont déjà construit leur URL de façon synchrone.
			state.refreshToken = null;
		}
	}

	// -------------------------------------------------------------------------
	// Real-time
	// -------------------------------------------------------------------------
	function loadRealtime() {
		if ( document.hidden || state.realtimeRequest ) return;
		var realtimeRequest = analyticsFetch( "realtime", "analytics/realtime" );
		state.realtimeRequest = realtimeRequest;
		realtimeRequest
			.then( function ( data ) {
				var online  = ( data.online != null ) ? data.online : ( data.total || 0 );
				var suspect = data.suspect || 0;
				var countEl = document.getElementById( "cu-realtime-count" );
				if ( countEl ) {
					countEl.textContent = online + ( online > 1 ? " humains en ligne" : " humain en ligne" );
					countEl.title = suspect + " visiteur(s) suspect(s) filtré(s) (" + filteredLabel() + ")";
				}
				var suspEl = document.getElementById( "cu-realtime-suspect" );
				if ( suspEl ) {
					var anonPages = data.anonymous_pages || 0;
					suspEl.textContent = ( suspect > 0 ? ( "+" + suspect + " filtré" + ( suspect > 1 ? "s" : "" ) + " (" + filteredLabel() + ")" ) : "" ) +
						( anonPages > 0 ? ( suspect > 0 ? " · " : "" ) + anonPages + " page(s) anonyme(s) ouverte(s) — des pages, pas des visiteurs" : "" );
				}
				var lastEl = document.getElementById( "cu-realtime-last" );
				if ( lastEl ) lastEl.textContent = lastHitText( data.last_hit_seconds );

				var list = document.getElementById( "cu-realtime-list" );
				if ( ! list ) return;
				if ( ! data.visitors || ! data.visitors.length ) {
					list.innerHTML = '<span class="cu-live-empty">Aucun visiteur humain actif.</span>';
					return;
				}
				list.innerHTML = liveTable( data );
			} )
			.catch( function ( err ) {
				if ( isSilentRequestError( err ) ) return;
				renderError( "cu-realtime-list", err );
				var countEl = document.getElementById( "cu-realtime-count" );
				if ( countEl ) {
					countEl.textContent = "Temps réel indisponible";
					countEl.title = errorText( err );
				}
			} )
			.then( function () {
				if ( state.realtimeRequest === realtimeRequest ) state.realtimeRequest = null;
			} );
	}

	// Tableau des visiteurs en ligne : heure exacte (heure du site), pays, IP,
	// page en cours, appareil et source. Sur mobile, chaque ligne devient une fiche.
	var SOURCE_NAMES = { direct: "Accès direct", google: "Moteur de recherche", social: "Réseau social", referral: "Site référent", ia: "Assistant IA", email: "E-mail", campaign: "Campagne", unknown: "Inconnue (page anonyme)" };
	var LIVE_MAX = 20;
	function clockTime( mysqlDate ) {
		var m = /(\d{2}:\d{2}:\d{2})/.exec( String( mysqlDate || "" ) );
		return m ? m[ 1 ] : "—";
	}
	// Un seul bloc par cellule : la fiche mobile (grille libellé | valeur) reste alignée.
	function liveCell( label, html ) {
		return '<td data-label="' + esc( label ) + '"><div class="cu-live-cell">' + html + "</div></td>";
	}
	function liveSub( text ) {
		return text ? '<span class="cu-live-sub">' + esc( text ) + "</span>" : "";
	}
	function liveCountry( v, geoEnabled ) {
		var code = String( v.country_code || "" ).toUpperCase();
		if ( /^[A-Z]{2}$/.test( code ) ) {
			var name = countryName( code ).replace( " (" + code + ")", "" );
			return '<span class="cu-live-cc">' + esc( code ) + "</span>" + ( name !== code ? esc( name ) : "" );
		}
		if ( v.geo_pending ) return '<span class="cu-live-dim">Localisation…</span>';
		var hint = geoEnabled ? "Pays non déterminé pour cette adresse." : ( "exempt" === cfg.privacyMode ? "Mode exempté : seul le pays fourni par le CDN (Cloudflare…) ou une base locale est utilisé." : "Activez la géolocalisation (Réglages > Analytics) pour afficher le pays." );
		return '<span class="cu-live-dim" title="' + esc( hint ) + '">Inconnu</span>';
	}
	function liveIp( v ) {
		if ( ! v.ip ) return '<span class="cu-live-dim" title="Adresse non conservée pour cette visite.">—</span>';
		return '<code class="cu-live-ip">' + esc( v.ip ) + "</code>";
	}
	// La colonne « Adresse IP » n'existe qu'en mode standard avec l'affichage activé :
	// dans les autres modes, aucune IP n'est conservée.
	function liveTable( data ) {
		var showIp = !! data.show_ip;
		var rows = data.visitors.slice( 0, LIVE_MAX ).map( function ( v ) {
			var path = urlPath( v.url );
			var device = DEVICE_NAMES[ String( v.device || "" ).toLowerCase() ] || v.device || "—";
			var tech = [ v.browser, v.os ].filter( Boolean ).join( " · " );
			var pages = v.anon ? "visiteur anonyme (cette page seulement)" : ( v.pageviews > 1 ? v.pageviews + " pages vues" : "1 page vue" );
			return "<tr>" +
				liveCell( "Heure", '<span class="cu-live-time">' + esc( clockTime( v.last_seen ) ) + "</span>" + liveSub( "arrivé à " + clockTime( v.first_seen ) ) ) +
				liveCell( "Pays", liveCountry( v, data.geo_enabled ) ) +
				( showIp ? liveCell( "Adresse IP", liveIp( v ) ) : "" ) +
				liveCell( "Page en cours", '<span class="cu-live-path" title="' + esc( v.url || path ) + '">' + esc( path ) + "</span>" + liveSub( pages ) ) +
				liveCell( "Appareil", esc( device ) + liveSub( tech ) ) +
				liveCell( "Source", esc( SOURCE_NAMES[ v.referrer_source ] || v.referrer_source || "—" ) + liveSub( v.referrer_host ) ) +
				"</tr>";
		} ).join( "" );
		var more = data.visitors.length > LIVE_MAX ? '<p class="cu-live-more">… et ' + ( data.visitors.length - LIVE_MAX ) + " autre(s) visiteur(s) en ligne.</p>" : "";
		return '<div class="cu-table-wrap cu-live-wrap"><table class="cu-table cu-live-table">' +
			'<caption class="screen-reader-text">Visiteurs en ligne, du plus récent au plus ancien (heure du site)</caption>' +
			'<thead><tr><th scope="col">Heure</th><th scope="col">Pays</th>' + ( showIp ? '<th scope="col">Adresse IP</th>' : "" ) + '<th scope="col">Page en cours</th><th scope="col">Appareil</th><th scope="col">Source</th></tr></thead>' +
			"<tbody>" + rows + "</tbody></table></div>" + more;
	}

	// « Dernière visite reçue » : distingue « personne en ligne » de « plus rien n'arrive ».
	function lastHitText( seconds ) {
		if ( null == seconds ) return "Aucune visite reçue pour l’instant.";
		if ( seconds < 60 ) return "Dernière visite reçue à l’instant.";
		if ( seconds < 3600 ) return "Dernière visite reçue il y a " + Math.round( seconds / 60 ) + " min.";
		if ( seconds < 86400 ) return "Dernière visite reçue il y a " + Math.round( seconds / 3600 ) + " h.";
		var days = Math.round( seconds / 86400 );
		return "Dernière visite reçue il y a " + days + ( days > 1 ? " jours." : " jour." );
	}

	// Diagnostic « Vérifier le suivi » : page servie, script, envoi, exclusions.
	var CHECK_BADGES = {
		ok: [ "cu-badge-green", "OK" ],
		warn: [ "cu-badge-yellow", "À vérifier" ],
		error: [ "cu-badge-red", "Bloquant" ],
		info: [ "cu-badge-blue", "Info" ],
	};
	function checkItem( status, title, detail ) {
		var badge = CHECK_BADGES[ status ] || CHECK_BADGES.info;
		return '<li class="cu-check"><span class="cu-badge ' + badge[ 0 ] + '">' + badge[ 1 ] + "</span>" +
			'<span class="cu-check-title">' + esc( title ) + "</span>" +
			( detail ? '<span class="cu-check-detail">' + esc( detail ) + "</span>" : "" ) + "</li>";
	}
	function runTrackingCheck() {
		var btn = document.getElementById( "cu-tracking-check" );
		var box = document.getElementById( "cu-tracking-report" );
		if ( ! btn || ! box ) return;
		btn.disabled = true;
		box.hidden = false;
		box.innerHTML = '<li class="cu-check"><span class="cu-spinner" aria-hidden="true"></span><span class="cu-check-title">Vérification en cours : test de la page d’accueil et de l’envoi des visites (jusqu’à 20 s)…</span></li>';
		api.apiFetch( "analytics/tracking-check" )
			.then( function ( data ) {
				var checks = ( data && data.checks ) || [];
				box.innerHTML = checks.length
					? checks.map( function ( c ) { return checkItem( c.status, c.title, c.detail ); } ).join( "" )
					: checkItem( "info", "Aucun contrôle n’a été renvoyé." );
			} )
			.catch( function ( err ) {
				box.innerHTML = checkItem( "error", "Le diagnostic n’a pas pu être lancé.", errorText( err ) );
			} )
			.then( function () { btn.disabled = false; } );
	}

	function startRealtimeLoop() {
		if ( state.realtimeTimer ) clearInterval( state.realtimeTimer );
		state.realtimeTimer = null;
		if ( document.hidden ) return;
		loadRealtime();
		state.realtimeTimer = setInterval( loadRealtime, cfg.realtimeRefresh * 1000 );
	}

	function pauseRealtimeLoop() {
		if ( state.realtimeTimer ) clearInterval( state.realtimeTimer );
		state.realtimeTimer = null;
	}

	function onVisibilityChange() {
		if ( document.hidden ) pauseRealtimeLoop();
		else startRealtimeLoop();
	}

	// -------------------------------------------------------------------------
	// KPI
	// -------------------------------------------------------------------------
	// Décimales au format français (« 1,53 », « 12,5 % ») comme les entiers.
	var decimalFormat = new Intl.NumberFormat( "fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	// Valeur indisponible (aucune visite suivie en continu) : « n/d », jamais 0 %.
	function fmtPct( val ) { return null == val ? "n/d" : api.formatNumber( val ) + " %"; }
	function money( val, currency ) { return decimalFormat.format( Number( val ) || 0 ) + " " + currency; }

	function cuFmtVal( fmt, val ) {
		if ( null == val ) return "n/d";
		if ( fmt === "duration" ) return api.formatDuration( val );
		if ( fmt === "percent" ) return fmtPct( Math.round( ( Number( val ) || 0 ) * 10 ) / 10 );
		if ( fmt === "decimal" ) return decimalFormat.format( Number( val ) || 0 );
		return api.formatNumber( val );
	}
	// Compteur animé (count-up) : les chiffres s'animent de 0 à leur valeur.
	function animateKpi( node, fmt, to ) {
		if ( ! node ) return;
		if ( null == to ) { node.textContent = "n/d"; return; }
		to = Number( to ) || 0;
		// Préférence système OU réglage du plugin (« Animations » désactivées).
		var wrap = document.querySelector( ".cu-wrap" );
		var reduce = ( window.matchMedia && window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches )
			|| ( wrap && "off" === wrap.getAttribute( "data-cu-motion" ) );
		if ( reduce || to === 0 ) { node.textContent = cuFmtVal( fmt, to ); return; }
		var dur = 650, t0 = null;
		function step( ts ) {
			if ( ! t0 ) t0 = ts;
			var p = Math.min( 1, ( ts - t0 ) / dur );
			var eased = 1 - Math.pow( 1 - p, 3 );
			var cur = to * eased;
			node.textContent = cuFmtVal( fmt, ( fmt === "decimal" ) ? cur : Math.round( cur ) );
			if ( p < 1 ) { requestAnimationFrame( step ); }
			else { node.textContent = cuFmtVal( fmt, to ); }
		}
		requestAnimationFrame( step );
	}

	// -------------------------------------------------------------------------
	// « En bref » — carte d'ouverture (jour / semaine / mois / année)
	// -------------------------------------------------------------------------
	var briefPeriod = "week";
	var briefChart = null; // graphique « En bref » affiché (détruit à chaque changement de période)
	function briefStat( label, val ) {
		return '<div class="cu-brief-stat"><span>' + esc( label ) + '</span><b>' + esc( val ) + '</b></div>';
	}
	function loadBrief( period, refreshToken ) {
		if ( period ) briefPeriod = period;
		var body = document.getElementById( "cu-brief-body" );
		if ( body ) body.innerHTML = '<div class="cu-loading"><div class="cu-spinner"></div></div>';
		analyticsFetch( "brief", "analytics/brief?period=" + encodeURIComponent( briefPeriod ) + ( refreshToken ? "&refresh=" + encodeURIComponent( refreshToken ) : "" ) )
			.then( function ( d ) {
				var b = document.getElementById( "cu-brief-body" );
				if ( ! b ) return;
				var n = d.narrative || {}, chips = [];
				if ( n.best && n.best.value ) { chips.push( api.icon( "trophy" ) + ' ' + esc( n.best.label ) + ' : <b>' + esc( n.best.value ) + '</b>' ); }
				if ( n.rising_source && n.rising_source.delta > 0 ) { chips.push( api.icon( "trend-up" ) + ' ' + esc( n.rising_source.label ) + ' <b>+' + fmtPct( n.rising_source.delta ) + '</b>' ); }
				else if ( n.rising_source && n.rising_source.is_new ) { chips.push( api.icon( "trend-up" ) + ' ' + esc( n.rising_source.label ) + ' <b>nouvelle source</b>' ); }
				if ( n.human_percent != null ) { chips.push( api.icon( "user" ) + ' <b>' + fmtPct( n.human_percent ) + '</b> humain' ); }
				var narrative = chips.length ? '<div class="cu-brief-narrative">' + chips.join( '<span class="cu-brief-sep">·</span>' ) + '</div>' : '';

				// Nouveaux : connus seulement pour les visites suivies en continu.
				var measuredVisits = null != d.measured ? Number( d.measured ) || 0 : Number( d.sessions.value ) || 0;
				var newShare = measuredVisits ? Math.round( ( Number( d.new_visitors ) || 0 ) / measuredVisits * 100 ) : null;
				b.innerHTML =
					'<div class="cu-brief-main">' +
						'<div class="cu-brief-hero">' +
							'<b class="cu-brief-num" id="cu-brief-num">0</b>' +
							'<div class="cu-brief-sub"><span>visites · ' + esc( d.period_label ) + '</span>' + deltaHtml( d.sessions.delta ) + '</div>' +
						'</div>' +
						'<div class="cu-brief-stats">' +
							briefStat( "Humain", fmtPct( d.human_percent ) ) +
							briefStat( "Pages vues", api.formatNumber( d.pageviews ) ) +
							briefStat( "Nouveaux", fmtPct( newShare ) ) +
							briefStat( "Conv. déclarées", api.formatNumber( d.conversions ) ) +
						"</div>" +
					"</div>" +
					'<div class="cu-brief-chart" id="cu-brief-chart"></div>' + narrative;

				animateKpi( document.getElementById( "cu-brief-num" ), "number", d.sessions.value );
				// Courbe détaillée (survol, épinglage, détail de chaque tranche) : cu-brief-chart.js.
				if ( briefChart ) briefChart.destroy();
				briefChart = null;
				var chartBox = document.getElementById( "cu-brief-chart" );
				if ( chartBox && window.cuBriefChart && d.series && d.series.length ) {
					briefChart = window.cuBriefChart.render( chartBox, d, {
						loadPoint: function ( chartPeriod, key ) {
							return api.apiFetch( "analytics/brief-point?period=" + encodeURIComponent( chartPeriod ) + "&key=" + encodeURIComponent( key ) );
						},
						sourceLabel: function ( source ) { return SOURCE_NAMES[ source ] || source; },
						deviceLabel: function ( device ) { return DEVICE_NAMES[ device ] || device; },
					} );
				}
			} )
			.catch( function ( err ) {
				renderError( "cu-brief-body", err );
			} );
	}

	function loadKpi() {
		page.querySelectorAll( "#cu-kpi-grid .cu-kpi" ).forEach( function ( card ) {
			var value = card.querySelector( ".cu-kpi-value" );
			var delta = card.querySelector( ".cu-kpi-delta-wrap" );
			if ( value ) {
				value.textContent = "…";
				value.removeAttribute( "title" );
			}
			if ( delta ) delta.textContent = "";
		} );
		var quality = document.getElementById( "cu-traffic-quality" );
		if ( quality ) {
			quality.textContent = "Chargement…";
			quality.style.color = "";
		}

		analyticsFetch( "summary", "analytics/summary?" + qp() )
			.then( function ( data ) {
				var kpis = [
					{ id: "sessions",      key: "sessions",      fmt: "number" },
					{ id: "pageviews",     key: "pageviews",     fmt: "number" },
					{ id: "pages_session", key: "pages_session", fmt: "decimal" },
					{ id: "avg_duration",  key: "avg_duration",  fmt: "duration" },
					{ id: "bounce_rate",   key: "bounce_rate",   fmt: "percent", lowerIsBetter: true },
					{ id: "cta_clicks",    key: "cta_clicks",    fmt: "number" },
					{ id: "ctr",           key: "ctr",           fmt: "percent" },
					{ id: "conversions",   key: "conversions",   fmt: "number" },
				];

				kpis.forEach( function ( k ) {
					var el = document.getElementById( "cu-kpi-" + k.id );
					if ( ! el ) return;
					var d = data[ k.key ];
					if ( ! d ) {
						// Indicateur absent de la réponse : ne pas laisser « … » à l'infini.
						el.querySelector( ".cu-kpi-value" ).textContent = "—";
						return;
					}

					animateKpi( el.querySelector( ".cu-kpi-value" ), k.fmt, d.value );
					el.querySelector( ".cu-kpi-delta-wrap" ).innerHTML = null == d.value ? "" : deltaHtml( d.delta, k.lowerIsBetter );

					if ( d.spark && d.spark.length ) {
						api.sparkline( el.querySelector( ".cu-kpi-spark" ), d.spark );
					}
				} );

				// Conversions CONFIRMÉES par le serveur (toutes visites) : affichées à part,
				// jamais additionnées aux conversions déclarées par le navigateur.
				var confirmedEl = document.getElementById( "cu-kpi-conversions-confirmed" );
				var cc = data.conversions_confirmed;
				if ( confirmedEl ) {
					if ( cc && ( cc.cf7_tracked || cc.woo_tracked ) ) {
						var parts = [];
						if ( cc.cf7_tracked ) parts.push( api.formatNumber( cc.cf7 ) + " message" + ( cc.cf7 > 1 ? "s" : "" ) + " CF7" );
						if ( cc.woo_tracked ) parts.push( api.formatNumber( cc.woo ) + " commande" + ( cc.woo > 1 ? "s" : "" ) + " payée" + ( cc.woo > 1 ? "s" : "" ) );
						confirmedEl.innerHTML = "Confirmées (serveur, toutes visites) : <b>" + esc( api.formatNumber( cc.value ) ) + "</b> · " + esc( parts.join( " + " ) );
					} else {
						confirmedEl.textContent = "";
					}
				}

				// Qualité du trafic : part d'humains réels vs trafic filtré.
				var q = data.traffic_quality;
				var qEl = document.getElementById( "cu-traffic-quality" );
				if ( qEl && q ) {
					if ( "boolean" === typeof q.datacenter_filter ) cfg.dcFilter = q.datacenter_filter;
					qEl.style.color = "";
					var pct = ( q.human_percent != null ) ? Number( q.human_percent ) : null;
					var tone = null === pct ? "var(--cu-text-dim)" : ( pct >= 85 ? "var(--cu-success)" : ( pct >= 60 ? "var(--cu-warning)" : "var(--cu-danger)" ) );
					qEl.innerHTML =
						'<span style="font-weight:700;color:' + tone + ';">' + fmtPct( pct ) + ' humain</span>' +
						'<span style="color:var(--cu-text-dim);"> · ' + api.formatNumber( q.filtered || 0 ) + ' visite(s) filtrée(s) (' + esc( filteredLabel() ) + ')' +
						( cfg.dcFilter ? "" : " · filtrage datacenter/VPN inactif" ) + '</span>';
					// Couverture : les taux par visite ne portent que sur les visites suivies.
					var cov = data.coverage;
					if ( cov && cov.anonymous > 0 ) {
						qEl.innerHTML += '<span style="color:var(--cu-text-dim);"> · rebond, pages / visite, durée et CTR calculés sur ' + api.formatNumber( cov.measured ) +
							' visite(s) suivie(s) en continu (' + fmtPct( cov.percent ) + ' des visites) ; ' + api.formatNumber( cov.anonymous ) + ' visite(s) comptée(s) de façon anonyme, page par page</span>';
					}
				}

				// Ring gauges « Santé du trafic » (réutilise les données du résumé).
				if ( window.cuViz ) {
					var humanPct  = ( q && q.human_percent != null ) ? Math.round( q.human_percent ) : null;
					var bouncePct = ( data.bounce_rate && data.bounce_rate.value != null ) ? Math.round( data.bounce_rate.value ) : null;
					var ctrPct    = ( data.ctr && data.ctr.value != null ) ? Math.round( data.ctr.value ) : null;
					var cv = window.cuViz.cssVar;
					var good = cv( "--obs-good", "#007a3d" ), warn = cv( "--obs-warn", "#8a5a00" ),
						crit = cv( "--obs-crit", "#c41d1d" ), acc = cv( "--obs-a1", "#3858e9" );
					gauge( "cu-gauge-human",  humanPct || 0,  null === humanPct ? acc : ( humanPct >= 85 ? good : ( humanPct >= 60 ? warn : crit ) ) );
					gauge( "cu-gauge-bounce", bouncePct || 0, null === bouncePct ? acc : ( bouncePct <= 40 ? good : ( bouncePct <= 60 ? warn : crit ) ) );
					gauge( "cu-gauge-ctr",    ctrPct || 0,    acc );
					setText( "cu-gauge-human-val",  null === humanPct ? "n/d" : humanPct + "%" );
					setText( "cu-gauge-bounce-val", null === bouncePct ? "n/d" : bouncePct + "%" );
					setText( "cu-gauge-ctr-val",    null === ctrPct ? "n/d" : ctrPct + "%" );
					if ( q ) setText( "cu-gauge-human-sub", api.formatNumber( q.filtered || 0 ) + " filtrée(s)" );
				}
			} )
			.catch( renderKpiError );
	}

	// -------------------------------------------------------------------------
	// Contacts & leads (WhatsApp + Contact Form 7) : rendu dans cu-contacts.js
	// -------------------------------------------------------------------------
	var contactsView = null;
	function loadContacts() {
		var box = document.getElementById( "cu-contacts" );
		if ( ! box || ! window.cuContacts ) return;
		box.setAttribute( "aria-busy", "true" );
		box.classList.add( "is-loading" );
		analyticsFetch( "contacts", "analytics/contacts?" + qp() )
			.then( function ( data ) {
				if ( contactsView ) contactsView.destroy();
				box.classList.remove( "is-loading" );
				contactsView = window.cuContacts.render( box, data, {
					sourceLabel: function ( source ) { return SOURCE_NAMES[ source ] || source; },
					countryName: countryName,
					settingsUrl: "admin.php?page=cyberpush-ultra-settings",
				} );
			} )
			.catch( function ( err ) {
				if ( isSilentRequestError( err ) ) return;
				if ( contactsView ) contactsView.destroy();
				contactsView = null;
				box.classList.remove( "is-loading" );
				box.setAttribute( "aria-busy", "false" );
				renderError( "cu-contacts", err );
			} );
	}

	// -------------------------------------------------------------------------
	// Content breakdown
	// -------------------------------------------------------------------------
	function loadBreakdown() {
		var el = document.getElementById( "cu-breakdown-list" );
		if ( el ) el.innerHTML = '<div class="cu-loading"><div class="cu-spinner"></div></div>';

		analyticsFetch( "breakdown", "analytics/content-breakdown?" + qp() )
			.then( function ( data ) {
				if ( ! el ) return;
				if ( ! data || ! data.length ) { empty( "cu-breakdown-list" ); return; }
				el.innerHTML = data.map( function ( r ) {
					var percent = Math.max( 0, Math.min( 100, Number( r.percent ) || 0 ) );
					return '<div style="margin-bottom:12px;">' +
						'<div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:12px;">' +
						'<span>' + esc( r.label ) + '</span>' +
						'<strong>' + api.formatNumber( r.pageviews ) + ' pages vues <span style="color:var(--cu-text-dim)">(' + fmtPct( percent ) + ')</span></strong>' +
						'</div><div class="cu-bar-track"><div class="cu-bar-fill" style="width:' + percent + '%;"></div></div></div>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-breakdown-list", err ); } );
	}

	// -------------------------------------------------------------------------
	// Timeseries chart
	// -------------------------------------------------------------------------
	function loadTimeseries() {
		clearCanvas( "cu-chart-timeseries" );
		loading( "cu-timeseries-legend" );
		analyticsFetch( "timeseries", "analytics/timeseries?" + qp() )
			.then( function ( data ) {
				var canvas = document.getElementById( "cu-chart-timeseries" );
				if ( ! canvas || ! data.labels ) return;
				var viz = api.vizColors();

				// Observatory : grande aire dégradée animée de la métrique principale
				// (Visites), avec crosshair + tooltip. Repli sur lineChart si besoin.
				if ( window.cuViz ) {
					window.cuViz.areaChart( canvas, {
						labels: data.labels,
						data: data.sessions || [],
						fmt: function ( v ) { return api.formatNumber( Math.round( v ) ) + " visites"; }
					} );
				} else {
					api.lineChart( canvas, data.labels, [ { label: "Visites", color: viz[ 0 ], data: data.sessions } ] );
				}

				var sum = function ( a ) { return ( a || [] ).reduce( function ( s, v ) { return s + ( Number( v ) || 0 ); }, 0 ); };
				// Alternative textuelle du graphique (lecteurs d'écran) : total et pic.
				var peak = 0, peakAt = "";
				( data.sessions || [] ).forEach( function ( v, i ) {
					if ( ( Number( v ) || 0 ) > peak ) { peak = Number( v ) || 0; peakAt = data.labels[ i ] || ""; }
				} );
				canvas.setAttribute( "role", "img" );
				canvas.setAttribute( "aria-label", "Évolution des visites : " + api.formatNumber( sum( data.sessions ) ) + " visite(s) sur la période" +
					( peak > 0 ? ", maximum " + api.formatNumber( peak ) + ( peakAt ? " (" + peakAt + ")" : "" ) : "" ) + "." );
				var chips = [
					{ label: "Visites",     color: viz[ 0 ], val: sum( data.sessions ) },
					{ label: "Pages vues",  color: viz[ 2 ], val: sum( data.pageviews ) },
					{ label: "CTA",         color: viz[ 5 ], val: sum( data.cta ) },
					{ label: "Conversions déclarées", color: viz[ 6 ], val: sum( data.conversions ) },
				];
				var legend = document.getElementById( "cu-timeseries-legend" );
				if ( legend ) {
					// La courbe ne trace que les sessions : ces pastilles sont des TOTAUX
					// de la période, pas des séries manquantes du graphique.
					legend.innerHTML = '<span style="font-size:12px;font-weight:700;color:var(--cu-text-dim);">Totaux de la période :</span>' + chips.map( function ( c ) {
						return '<span style="display:inline-flex;align-items:center;gap:7px;font-size:12px;color:var(--cu-text-dim);">' +
							'<span style="width:9px;height:9px;border-radius:50%;background:' + c.color + ';display:inline-block;"></span>' +
							esc( c.label ) + ' <strong style="color:var(--cu-text);font-variant-numeric:tabular-nums;">' + api.formatNumber( c.val ) + '</strong></span>';
					} ).join( "" );
				}
			} )
			.catch( function ( err ) {
				if ( isSilentRequestError( err ) ) return;
				clearCanvas( "cu-chart-timeseries" );
				renderError( "cu-timeseries-legend", err );
			} );
	}

	// -------------------------------------------------------------------------
	// Heatmap
	// -------------------------------------------------------------------------
	function loadHeatmap() {
		loading( "cu-heatmap-container" );
		analyticsFetch( "heatmap", "analytics/heatmap?" + qp() )
			.then( function ( data ) {
				var container = document.getElementById( "cu-heatmap-container" );
				if ( ! container || ! data.grid ) return;
				var heatAccent = api.cssVar( "--cu-accent", "#3858e9" );

				var daysShort = [ "Lun", "Mar", "Mer", "Jeu", "Ven", "Sam", "Dim" ];
				var daysFull  = [ "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche" ];
				var maxVal = data.max || 1;

				// Repère la cellule de pic (1re occurrence du max) pour la souligner.
				var peakD = -1, peakH = -1;
				for ( var pd = 0; pd < 7 && peakD === -1; pd++ ) {
					for ( var ph = 0; ph < 24; ph++ ) {
						var pv = data.grid[ pd ] ? ( data.grid[ pd ][ ph ] || 0 ) : 0;
						if ( maxVal > 0 && pv === maxVal ) { peakD = pd; peakH = ph; break; }
					}
				}

				function cellColor( ratio ) {
					// Plancher d'opacité (0.14) : l'activité faible reste visible.
					return api.withAlpha( heatAccent, ( 0.14 + 0.86 * ratio ).toFixed( 3 ) );
				}

				var html = '<div class="cu-heatmap">';
				// Ligne d'en-tête : coin vide + 24 heures (label 1 sur 3, aéré).
				html += '<div class="cu-heatmap-label"></div>';
				for ( var h = 0; h < 24; h++ ) {
					html += '<div class="cu-heatmap-label cu-heatmap-hour">' + ( h % 3 === 0 ? h + 'h' : '' ) + '</div>';
				}
				// Lignes jour par jour
				for ( var d = 0; d < 7; d++ ) {
					html += '<div class="cu-heatmap-label">' + daysShort[ d ] + '</div>';
					for ( var hh = 0; hh < 24; hh++ ) {
						var val = data.grid[ d ] ? ( data.grid[ d ][ hh ] || 0 ) : 0;
						var style = val > 0 ? 'background:' + cellColor( val / maxVal ) + ';' : '';
						var cls = 'cu-heatmap-cell' + ( d === peakD && hh === peakH ? ' is-peak' : '' );
						var title = daysFull[ d ] + ', ' + hh + 'h–' + ( ( hh + 1 ) % 24 ) + 'h · ' + val + ' arrivée' + ( val > 1 ? 's' : '' );
						html += '<div class="' + cls + '" title="' + esc( title ) + '" style="' + style + '"></div>';
					}
				}
				html += '</div>';

				// Légende dégradée « Moins → Plus » (repère pro type GitHub).
				html += '<div class="cu-heatmap-legend"><span>Moins</span>';
				[ 0.14, 0.35, 0.56, 0.78, 1 ].forEach( function ( o ) {
					html += '<span class="cu-heatmap-swatch" style="background:' + api.withAlpha( heatAccent, o ) + ';"></span>';
				} );
				html += '<span>Plus</span></div>';

				container.innerHTML = html;
			} )
			.catch( function ( err ) { renderError( "cu-heatmap-container", err ); } );
	}

	// -------------------------------------------------------------------------
	// Content performance table
	// -------------------------------------------------------------------------
	function loadContentTable() {
		var wrap = document.getElementById( "cu-content-table-wrap" );
		var pager = document.getElementById( "cu-content-pagination" );
		if ( wrap ) wrap.innerHTML = '<div class="cu-loading"><div class="cu-spinner"></div></div>';
		if ( pager ) pager.innerHTML = '<span style="color:var(--cu-text-dim);font-size:12px;">Chargement…</span>';

		var params = qp() +
			"&type=" + encodeURIComponent( state.contentType ) +
			"&search=" + encodeURIComponent( state.contentSearch ) +
			"&sort=" + encodeURIComponent( state.contentSort ) +
			"&page=" + state.contentPage;

		analyticsFetch( "content", "analytics/content-performance?" + params )
			.then( function ( data ) {
				if ( ! wrap ) return;
				var total = Math.max( 0, parseInt( data.total, 10 ) || 0 );
				var totalPages = Math.max( 0, parseInt( data.total_pages, 10 ) || 0 );
				var perPage = Math.max( 1, parseInt( data.per_page, 10 ) || 20 );
				var serverPage = Math.max( 1, parseInt( data.page, 10 ) || state.contentPage );

				if ( ! data.rows || ! data.rows.length ) {
					// Après une suppression ou un changement de filtre, la page demandée
					// peut ne plus exister. Revenir à la dernière page réelle, sans
					// remplacer le total fourni par le serveur par un faux zéro.
					if ( total > 0 && totalPages > 0 && serverPage > totalPages ) {
						state.contentPage = totalPages;
						loadContentTable();
						return;
					}
					state.contentPage = totalPages > 0 ? Math.min( serverPage, totalPages ) : 1;
					wrap.innerHTML = '<div class="cu-empty">Aucune donnée pour cette période.</div>';
					renderPagination( total, totalPages, perPage );
					return;
				}
				state.contentPage = totalPages > 0 ? Math.min( serverPage, totalPages ) : serverPage;

				var html = '<table class="cu-table"><thead><tr>' +
					'<th>Titre</th><th>Type</th><th>Auteur</th><th><abbr title="Visites suivies ayant vu ce contenu + pages anonymes de ce contenu">Visites</abbr></th>' +
					'<th><abbr title="Pages vues">PV</abbr></th><th><abbr title="Temps moyen passé sur ce contenu par visite">Dur. moy.</abbr></th><th><abbr title="Calculé sur les visites suivies en continu uniquement">Rebond</abbr></th>' +
					'<th>Entrées</th><th>Sorties</th><th>Scroll</th>' +
					'<th>CTA</th><th>CTR</th><th><abbr title="Conversions déclarées par le navigateur">Conv.</abbr></th><th>Diagnostic</th>' +
					'</tr></thead><tbody>';

				data.rows.forEach( function ( r ) {
					html += '<tr>' +
						'<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc( r.title ) + '">' + esc( r.title ) + '</td>' +
						'<td><span class="cu-badge cu-badge-gray">' + esc( r.type_label || r.object_type ) + '</span></td>' +
						'<td>' + esc( r.author ) + '</td>' +
						'<td>' + api.formatNumber( r.sessions ) + '</td>' +
						'<td>' + api.formatNumber( r.pageviews ) + '</td>' +
						'<td>' + api.formatDuration( r.avg_time ) + '</td>' +
						'<td>' + fmtPct( r.bounce_rate ) + '</td>' +
						'<td>' + api.formatNumber( r.entries ) + '</td>' +
						'<td>' + api.formatNumber( r.exits ) + '</td>' +
						'<td>' + fmtPct( r.avg_scroll ) + '</td>' +
						'<td>' + api.formatNumber( r.cta_clicks ) + '</td>' +
						'<td>' + fmtPct( r.ctr ) + '</td>' +
						'<td>' + api.formatNumber( r.conversions ) + '</td>' +
						'<td><span class="cu-badge ' + esc( r.diag_class ) + '">' + esc( r.diag ) + '</span></td>' +
						'</tr>';
				} );

				html += '</tbody></table>';
				wrap.innerHTML = html;
				renderPagination( total, totalPages, perPage );
			} )
			.catch( function ( err ) {
				if ( isSilentRequestError( err ) ) return;
				renderError( "cu-content-table-wrap", err );
				if ( pager ) pager.innerHTML = "";
			} );
	}

	function renderPagination( total, totalPages, perPage ) {
		var el = document.getElementById( "cu-content-pagination" );
		if ( ! el ) return;

		var html = '<span style="color:var(--cu-text-dim);font-size:12px;">' + api.formatNumber( total ) + ' résultats</span>';

		if ( totalPages > 1 ) {
			html += ' &nbsp; ';
			if ( state.contentPage > 1 ) {
				html += '<button class="cu-btn cu-btn-sm" id="cu-pag-prev">&#8592; Préc.</button> ';
			}
			html += '<span style="font-size:12px;color:var(--cu-text-dim);">Page ' + state.contentPage + ' / ' + totalPages + '</span>';
			if ( state.contentPage < totalPages ) {
				html += ' <button class="cu-btn cu-btn-sm" id="cu-pag-next">Suiv. &#8594;</button>';
			}
		}

		el.innerHTML = html;

		var prevBtn = document.getElementById( "cu-pag-prev" );
		var nextBtn = document.getElementById( "cu-pag-next" );
		if ( prevBtn ) prevBtn.addEventListener( "click", function () { state.contentPage--; loadContentTable(); } );
		if ( nextBtn ) nextBtn.addEventListener( "click", function () { state.contentPage++; loadContentTable(); } );
	}

	// -------------------------------------------------------------------------
	// Entry / Exit pages
	// -------------------------------------------------------------------------
	function loadEntryExit() {
		loading( "cu-entry-list" );
		loading( "cu-exit-list" );

		analyticsFetch( "entry-pages", "analytics/entry-exit-pages?" + qp() + "&mode=entry" )
			.then( function ( data ) {
				var el = document.getElementById( "cu-entry-list" );
				if ( ! el ) return;
				if ( ! data.length ) { empty( "cu-entry-list" ); return; }
				el.innerHTML = data.slice( 0, 10 ).map( function ( r ) {
					return '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--cu-panel-border);font-size:12px;">' +
						'<span style="color:var(--cu-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80%;" title="' + esc( r.url ) + '">' + esc( urlPath( r.url ) ) + '</span>' +
						'<strong>' + api.formatNumber( r.n ) + '</strong></div>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-entry-list", err ); } );

		analyticsFetch( "exit-pages", "analytics/entry-exit-pages?" + qp() + "&mode=exit" )
			.then( function ( data ) {
				var el = document.getElementById( "cu-exit-list" );
				if ( ! el ) return;
				if ( ! data.length ) { empty( "cu-exit-list" ); return; }
				el.innerHTML = data.slice( 0, 10 ).map( function ( r ) {
					return '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--cu-panel-border);font-size:12px;">' +
						'<span style="color:var(--cu-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80%;" title="' + esc( r.url ) + '">' + esc( urlPath( r.url ) ) + '</span>' +
						'<strong>' + api.formatNumber( r.n ) + '</strong></div>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-exit-list", err ); } );
	}

	// -------------------------------------------------------------------------
	// Visitor loyalty
	// -------------------------------------------------------------------------
	function loadLoyalty() {
		loading( "cu-loyalty-content" );
		analyticsFetch( "loyalty", "analytics/visitor-loyalty?" + qp() )
			.then( function ( d ) {
				var el = document.getElementById( "cu-loyalty-content" );
				if ( ! el ) return;
				el.innerHTML = '<div class="cu-grid cu-grid-2" style="gap:20px;">' +
					'<div><div style="font-size:13px;color:var(--cu-text-dim);margin-bottom:6px;">Nouveaux visiteurs</div>' +
					'<div style="font-size:28px;font-weight:800;">' + api.formatNumber( d.new ) +
					' <span style="font-size:14px;color:var(--cu-text-dim)">(' + fmtPct( d.new_percent ) + ')</span></div>' +
					'<div class="cu-bar-track" style="margin-top:8px;"><div class="cu-bar-fill" style="width:' + clampPct( d.new_percent ) + '%;background:var(--cu-accent-2);"></div></div></div>' +
					'<div><div style="font-size:13px;color:var(--cu-text-dim);margin-bottom:6px;">Visiteurs fidèles</div>' +
					'<div style="font-size:28px;font-weight:800;">' + api.formatNumber( d.returning ) +
					' <span style="font-size:14px;color:var(--cu-text-dim)">(' + fmtPct( d.ret_percent ) + ')</span></div>' +
					'<div class="cu-bar-track" style="margin-top:8px;"><div class="cu-bar-fill" style="width:' + clampPct( d.ret_percent ) + '%;background:var(--cu-accent);"></div></div></div>' +
					'</div>';
			} )
			.catch( function ( err ) { renderError( "cu-loyalty-content", err ); } );
	}

	// -------------------------------------------------------------------------
	// Scroll depth
	// -------------------------------------------------------------------------
	function loadScrollDepth() {
		loading( "cu-scroll-content" );
		analyticsFetch( "scroll", "analytics/scroll-depth?" + qp() )
			.then( function ( d ) {
				var el = document.getElementById( "cu-scroll-content" );
				if ( ! el ) return;
				var bars = [
					{ label: "25%",  val: d.p25 },
					{ label: "50%",  val: d.p50 },
					{ label: "75%",  val: d.p75 },
					{ label: "90%+", val: d.p90 },
				];
				el.innerHTML = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">' +
					bars.map( function ( b ) {
						return '<div style="text-align:center;">' +
							'<div style="font-size:24px;font-weight:800;color:var(--cu-accent-2);">' + fmtPct( b.val ) + '</div>' +
							'<div style="font-size:12px;color:var(--cu-text-dim);margin-top:4px;">Ont scrollé à ' + b.label + '</div>' +
							'<div class="cu-bar-track" style="margin-top:8px;"><div class="cu-bar-fill" style="width:' + clampPct( b.val ) + '%;"></div></div>' +
							'</div>';
					} ).join( "" ) +
					'</div>';
			} )
			.catch( function ( err ) { renderError( "cu-scroll-content", err ); } );
	}

	// -------------------------------------------------------------------------
	// Technical breakdown (donuts)
	// -------------------------------------------------------------------------
	function loadTech() {
		var keys = [ "devices", "browsers", "os" ];
		keys.forEach( function ( key ) {
			clearCanvas( "cu-donut-" + key );
			loading( "cu-donut-legend-" + key );
		} );
		analyticsFetch( "tech", "analytics/technical-breakdown?" + qp() )
			.then( function ( data ) {
				var MAX_SLICES = 6;
				keys.forEach( function ( key ) {
					var canvas = document.getElementById( "cu-donut-" + key );
					if ( ! canvas ) return;
					var rows = ( data[ key ] || [] ).filter( function ( r ) { return ( parseInt( r.value, 10 ) || 0 ) > 0; } );
					if ( ! rows.length ) {
						clearCanvas( "cu-donut-" + key );
						empty( "cu-donut-legend-" + key );
						return;
					}
					// Au-delà de 6 parts, un donut devient illisible : regrouper le reste.
					if ( rows.length > MAX_SLICES ) {
						var others = rows.slice( MAX_SLICES - 1 ).reduce( function ( s, r ) { return s + ( parseInt( r.value, 10 ) || 0 ); }, 0 );
						rows = rows.slice( 0, MAX_SLICES - 1 ).concat( [ { label: "Autres", value: others } ] );
					}
					var chartData = rows.map( function ( r, ci ) {
						var label = r.label || "Inconnu";
						// Le serveur stocke des codes anglais (« mobile », « desktop »…).
						if ( "devices" === key && DEVICE_NAMES[ String( label ).toLowerCase() ] ) label = DEVICE_NAMES[ String( label ).toLowerCase() ];
						return { label: label, value: parseInt( r.value, 10 ) || 0, color: donutColor( ci ) };
					} );
					api.donutChart( canvas, chartData );
					donutLegend( "cu-donut-legend-" + key, chartData );
				} );
			} )
			.catch( function ( err ) {
				if ( isSilentRequestError( err ) ) return;
				keys.forEach( function ( key ) {
					clearCanvas( "cu-donut-" + key );
					renderError( "cu-donut-legend-" + key, err );
				} );
			} );
	}

	// -------------------------------------------------------------------------
	// Countries
	// -------------------------------------------------------------------------
	// Carte choroplèthe : une seule teinte, du clair (peu) au foncé (beaucoup) ;
	// gris = aucune visite. Le tableau à côté donne les valeurs exactes.
	var GEO_RAMP = [ "#cde2fb", "#86b6ef", "#3987e5", "#1c5cab", "#0d366b" ];
	var GEO_EMPTY = "#e3e8f2";
	var geoState = { data: null, metric: "sessions", expanded: false };

	function geoName( code ) {
		return countryName( code ).replace( " (" + String( code ).toUpperCase() + ")", "" );
	}

	function geoBin( value, max ) {
		if ( ! value || ! max ) return -1;
		return Math.max( 0, Math.min( GEO_RAMP.length - 1, Math.ceil( Math.sqrt( value / max ) * GEO_RAMP.length ) - 1 ) );
	}

	function geoDuration( seconds ) {
		if ( null == seconds ) return "n/d";
		var s = Math.max( 0, Math.round( seconds ) );
		return Math.floor( s / 60 ) + " min " + ( s % 60 < 10 ? "0" : "" ) + ( s % 60 ) + " s";
	}

	function geoMapSvg( rows, metric ) {
		var world = window.cuWorldMap;
		if ( ! world || ! world.paths ) return "";
		var byCode = {};
		var max = 0;
		rows.forEach( function ( r ) {
			byCode[ r.code ] = r;
			max = Math.max( max, Number( r[ metric ] ) || 0 );
		} );
		var parts = [];
		Object.keys( world.paths ).forEach( function ( code ) {
			// Le Sahara occidental est dessiné avec le Maroc (même région, même infobulle).
			if ( "EH" === code && world.paths.MA ) return;
			var d = world.paths[ code ].path;
			if ( "MA" === code && world.paths.EH ) d += " " + world.paths.EH.path;
			var r = byCode[ code ];
			var bin = geoBin( r ? Number( r[ metric ] ) || 0 : 0, max );
			parts.push( '<path class="cu-geo-region' + ( bin >= 0 ? " has-data" : "" ) + '" data-code="' + code + '" d="' + d + '" fill="' + ( bin >= 0 ? GEO_RAMP[ bin ] : GEO_EMPTY ) + '"' + ( "MA" === code ? ' stroke="' + ( bin >= 0 ? GEO_RAMP[ bin ] : GEO_EMPTY ) + '"' : "" ) + "></path>" );
		} );
		var top = rows[ 0 ];
		var label = rows.length
			? "Carte des pays : " + rows.length + " pays, en tête " + geoName( top.code ) + " (" + fmtPct( top.percent ) + " des visites). Valeurs détaillées dans le tableau."
			: "Carte des pays : aucune visite localisée sur la période.";
		return '<svg class="cu-geo-svg" viewBox="0 0 ' + Math.round( world.width ) + " " + Math.round( world.height ) + '" role="img" aria-label="' + esc( label ) + '" focusable="false">' + parts.join( "" ) + "</svg>";
	}

	function geoTooltipHtml( r ) {
		var lines = [ "<strong>" + esc( geoName( r.code ) ) + "</strong>" ];
		lines.push( api.formatNumber( r.sessions ) + " visite" + ( r.sessions > 1 ? "s" : "" ) + " · " + fmtPct( r.percent ) );
		lines.push( api.formatNumber( r.pageviews ) + " page" + ( r.pageviews > 1 ? "s" : "" ) + " vue" + ( r.pageviews > 1 ? "s" : "" ) );
		if ( r.wa ) lines.push( api.formatNumber( r.wa ) + " clic" + ( r.wa > 1 ? "s" : "" ) + " WhatsApp" );
		if ( r.leads ) lines.push( "Taux de contact : " + fmtPct( r.contact_rate ) );
		return lines.join( "<br>" );
	}

	function renderCountries() {
		var el = document.getElementById( "cu-countries-list" );
		var data = geoState.data;
		if ( ! el || ! data ) return;
		var rows = data.rows || [];
		var metric = geoState.metric;
		var waOn = false !== data.tracking_whatsapp;
		var sorted = rows.slice().sort( function ( a, b ) { return ( Number( b[ metric ] ) || 0 ) - ( Number( a[ metric ] ) || 0 ) || b.sessions - a.sessions; } );
		var visible = geoState.expanded ? sorted : sorted.slice( 0, 12 );
		var max = sorted.length ? Number( sorted[ 0 ][ metric ] ) || 0 : 0;
		var offMap = rows.filter( function ( r ) { return ! ( window.cuWorldMap && window.cuWorldMap.paths && ( window.cuWorldMap.paths[ r.code ] || "EH" === r.code ) ); } );

		var toolbar = '<div class="cu-geo-toolbar">' +
			'<div class="cu-geo-switch" role="group" aria-label="Colorer la carte selon">' +
			'<button type="button" data-geo-metric="sessions" aria-pressed="' + ( "sessions" === metric ) + '">Visites</button>' +
			( waOn ? '<button type="button" data-geo-metric="wa" aria-pressed="' + ( "wa" === metric ) + '">Clics WhatsApp</button>' : "" ) +
			'<button type="button" data-geo-metric="leads" aria-pressed="' + ( "leads" === metric ) + '">Prises de contact</button>' +
			"</div>" +
			'<div class="cu-geo-kpis"><span><b>' + api.formatNumber( rows.length ) + "</b> pays</span>" +
			"<span><b>" + api.formatNumber( data.known || 0 ) + "</b> visites localisées</span>" +
			( data.unknown && data.unknown.visits ? '<span class="cu-geo-unknown"><b>' + api.formatNumber( data.unknown.visits ) + "</b> sans pays</span>" : "" ) +
			"</div></div>";

		var legend = '<div class="cu-geo-legend" aria-hidden="true"><span>Moins</span>' +
			GEO_RAMP.map( function ( c ) { return '<i style="background:' + c + '"></i>'; } ).join( "" ) +
			"<span>Plus</span>" + '<i class="cu-geo-legend-empty" style="background:' + GEO_EMPTY + '"></i><span>Aucune</span>' +
			"</div>";

		var metricLabel = { sessions: "Visites", wa: "WhatsApp", leads: "Contacts" }[ metric ];
		var table = rows.length ? '<table class="cu-geo-table"><thead><tr><th scope="col">Pays</th><th scope="col" class="num">Visites</th><th scope="col" class="num">%</th>' +
			( waOn ? '<th scope="col" class="num">WhatsApp</th>' : "" ) + '<th scope="col" class="num" title="Prises de contact ÷ visites">Contact</th></tr></thead><tbody>' +
			visible.map( function ( r ) {
				var v = Number( r[ metric ] ) || 0;
				var bar = max ? Math.max( 2, Math.round( v / max * 100 ) ) : 0;
				return '<tr data-code="' + esc( r.code ) + '" title="' + esc( metricLabel + " : " + api.formatNumber( v ) + " · durée moyenne " + geoDuration( r.avg_duration ) ) + '">' +
					'<td><span class="cu-geo-code">' + esc( r.code ) + "</span>" + esc( geoName( r.code ) ) +
					'<span class="cu-geo-bar" aria-hidden="true"><span style="width:' + bar + '%"></span></span></td>' +
					'<td class="num">' + api.formatNumber( r.sessions ) + "</td>" +
					'<td class="num dim">' + fmtPct( r.percent ) + "</td>" +
					( waOn ? '<td class="num">' + ( r.wa ? '<span class="cu-geo-wa">' + api.formatNumber( r.wa ) + "</span>" : '<span class="dim">0</span>' ) + "</td>" : "" ) +
					'<td class="num dim">' + ( r.leads ? fmtPct( r.contact_rate ) : "—" ) + "</td></tr>";
			} ).join( "" ) + "</tbody></table>" +
			( sorted.length > 12 ? '<button type="button" class="cu-btn cu-btn-sm cu-geo-more" data-geo-more>' + ( geoState.expanded ? "Réduire" : "Voir les " + sorted.length + " pays" ) + "</button>" : "" )
			: '<div class="cu-empty">Aucune visite localisée sur la période.</div>';

		// Source du pays : ce qui rend les chiffres fiables (ou pas).
		var geo = data.geo || {};
		var source;
		if ( geo.cloudflare ) {
			source = "Pays fourni par Cloudflare pour chaque visite (même derrière le relais de l’hébergeur) ; base DB-IP locale en secours.";
		} else if ( ! geo.configured ) {
			source = "Aucune source de pays configurée : choisissez « Base locale DB-IP » dans Réglages › Analytics.";
		} else if ( geo.local_db && geo.local_db.available ) {
			source = "Pays déduit de l’adresse IP par la base DB-IP installée sur votre serveur" + ( geo.local_db.edition ? " (édition " + geo.local_db.edition + ")" : "" ) + " ; l’adresse n’est ni enregistrée ni transmise.";
		} else {
			source = "Base de pays pas encore installée" + ( geo.local_db && geo.local_db.error ? " (" + geo.local_db.error + ")" : "" ) + " : pays estimé d’après le fuseau horaire du navigateur. Téléchargez la base depuis Réglages › Analytics.";
		}
		var notes = '<p class="cu-geo-note">' + esc( source ) +
			( offMap.length ? " Trop petits pour la carte (voir le tableau) : " + esc( offMap.map( function ( r ) { return geoName( r.code ); } ).join( ", " ) ) + "." : "" ) +
			( data.unknown && data.unknown.visits ? " " + api.formatNumber( data.unknown.visits ) + " visite(s) sans pays (adresse locale ou non reconnue)." : "" ) + "</p>";

		el.innerHTML = toolbar + '<div class="cu-geo-body"><div class="cu-geo-map">' + ( geoMapSvg( rows, metric ) || '<div class="cu-empty">Fond de carte indisponible.</div>' ) +
			'<div class="cu-geo-tip" role="status" hidden></div>' + legend + "</div>" +
			'<div class="cu-geo-side">' + table + "</div></div>" + notes;
		bindCountries( el, rows );
	}

	function bindCountries( el, rows ) {
		var byCode = {};
		rows.forEach( function ( r ) { byCode[ r.code ] = r; } );
		var map = el.querySelector( ".cu-geo-map" );
		var tip = el.querySelector( ".cu-geo-tip" );
		function regionOf( code ) { return el.querySelector( '.cu-geo-region[data-code="' + code + '"]' ); }
		function show( code, x, y ) {
			var r = byCode[ code ];
			if ( ! tip || ! map ) return;
			if ( ! r ) { tip.hidden = true; return; }
			tip.innerHTML = geoTooltipHtml( r );
			tip.hidden = false;
			var box = map.getBoundingClientRect();
			var left = Math.min( Math.max( 8, x - box.left + 14 ), box.width - tip.offsetWidth - 8 );
			var top = Math.max( 8, y - box.top - tip.offsetHeight - 12 );
			tip.style.left = left + "px";
			tip.style.top = top + "px";
		}
		if ( map ) {
			map.addEventListener( "mousemove", function ( e ) {
				var path = e.target && e.target.closest ? e.target.closest( ".cu-geo-region" ) : null;
				Array.prototype.forEach.call( el.querySelectorAll( ".cu-geo-region.is-hover" ), function ( p ) { if ( p !== path ) p.classList.remove( "is-hover" ); } );
				if ( ! path ) { tip.hidden = true; return; }
				path.classList.add( "is-hover" );
				show( path.getAttribute( "data-code" ), e.clientX, e.clientY );
			} );
			map.addEventListener( "mouseleave", function () {
				tip.hidden = true;
				Array.prototype.forEach.call( el.querySelectorAll( ".cu-geo-region.is-hover" ), function ( p ) { p.classList.remove( "is-hover" ); } );
			} );
		}
		// Survol d'une ligne du tableau : le pays s'allume sur la carte.
		Array.prototype.forEach.call( el.querySelectorAll( ".cu-geo-table tbody tr" ), function ( tr ) {
			var code = tr.getAttribute( "data-code" );
			tr.addEventListener( "mouseenter", function () { var p = regionOf( "EH" === code ? "MA" : code ); if ( p ) p.classList.add( "is-hover" ); } );
			tr.addEventListener( "mouseleave", function () { var p = regionOf( "EH" === code ? "MA" : code ); if ( p ) p.classList.remove( "is-hover" ); } );
		} );
		Array.prototype.forEach.call( el.querySelectorAll( "[data-geo-metric]" ), function ( btn ) {
			btn.addEventListener( "click", function () {
				geoState.metric = btn.getAttribute( "data-geo-metric" );
				renderCountries();
			} );
		} );
		var more = el.querySelector( "[data-geo-more]" );
		if ( more ) more.addEventListener( "click", function () {
			geoState.expanded = ! geoState.expanded;
			renderCountries();
		} );
	}

	function loadCountries() {
		loading( "cu-countries-list" );
		analyticsFetch( "countries", "analytics/countries?" + qp() )
			.then( function ( data ) {
				// Rapport antérieur encore en cache (simple liste) : même rendu.
				geoState.data = Array.isArray( data ) ? { rows: data, total: 0, known: 0, unknown: { visits: 0 }, geo: {} } : ( data || { rows: [] } );
				renderCountries();
			} )
			.catch( function ( err ) { renderError( "cu-countries-list", err ); } );
	}

	// -------------------------------------------------------------------------
	// Clics sortants (liens externes, téléchargements, appels, e-mails)
	// -------------------------------------------------------------------------
	var OB_KINDS = {
		external: { label: "Lien externe", plural: "Liens externes" },
		download: { label: "Téléchargement", plural: "Téléchargements" },
		tel: { label: "Appel", plural: "Appels (tel:)" },
		mail: { label: "E-mail", plural: "E-mails (mailto:)" },
	};

	function obTarget( link ) {
		if ( "tel" === link.kind ) return "+" + link.target;
		if ( "mail" === link.kind ) return "…@" + link.target;
		return String( link.target ).replace( /^https?:\/\/(www\.)?/i, "" );
	}

	function loadOutbound() {
		loading( "cu-outbound" );
		analyticsFetch( "outbound", "analytics/outbound?" + qp() )
			.then( function ( d ) {
				var el = document.getElementById( "cu-outbound" );
				if ( ! el ) return;
				if ( ! d.tracking && ! d.total ) {
					el.innerHTML = '<div class="cu-empty">Suivi des clics sortants désactivé (Réglages › Analytics).</div>';
					return;
				}
				var totals = d.totals || {};
				var chips = '<div class="cu-ob-chips">' + Object.keys( OB_KINDS ).map( function ( k ) {
					return '<div class="cu-ob-chip"><span class="cu-ob-kind cu-ob-' + k + '">' + esc( OB_KINDS[ k ].plural ) + "</span><b>" + api.formatNumber( totals[ k ] || 0 ) + "</b></div>";
				} ).join( "" ) + '<div class="cu-ob-chip cu-ob-total"><span>Total</span><b>' + api.formatNumber( d.total || 0 ) + "</b>" +
					( null != d.delta ? '<em class="' + ( d.delta >= 0 ? "up" : "down" ) + '">' + ( d.delta >= 0 ? "+" : "" ) + api.formatNumber( d.delta ) + " %</em>" : "" ) + "</div></div>";
				if ( ! d.total ) {
					el.innerHTML = chips + '<div class="cu-empty">Aucun clic sortant sur la période. Les liens vers d’autres sites, les fichiers (PDF, ZIP…), les numéros (tel:) et les adresses e-mail (mailto:) sont comptés automatiquement.</div>';
					return;
				}
				var links = ( d.links || [] ).map( function ( l ) {
					return "<tr><td><span class=\"cu-ob-kind cu-ob-" + esc( l.kind ) + "\">" + esc( OB_KINDS[ l.kind ] ? OB_KINDS[ l.kind ].label : l.kind ) + "</span></td>" +
						'<td class="cu-ob-link">' + ( l.label ? "<strong>" + esc( l.label ) + "</strong>" : "" ) + '<span class="dim">' + esc( obTarget( l ) ) + "</span></td>" +
						'<td class="num">' + api.formatNumber( l.clicks ) + '</td><td class="num dim">' + api.formatNumber( l.visits ) + "</td></tr>";
				} ).join( "" );
				var side = "";
				if ( ( d.domains || [] ).length ) {
					side += '<div class="cu-tech-title">Sites de destination</div>' + barList( d.domains.map( function ( x ) { return { name: x.host, clicks: x.clicks }; } ), "name", "clicks", true );
				}
				if ( ( d.pages || [] ).length ) {
					side += '<div class="cu-tech-title" style="margin-top:16px;">Pages d’où partent les clics</div>' + barList( d.pages.map( function ( p ) { return { name: p.title || p.path, clicks: p.clicks }; } ), "name", "clicks", true );
				}
				el.innerHTML = chips + '<div class="cu-ob-body"><div><table class="cu-geo-table cu-ob-table"><thead><tr><th scope="col">Type</th><th scope="col">Lien</th><th scope="col" class="num">Clics</th><th scope="col" class="num">Visites</th></tr></thead><tbody>' +
					links + "</tbody></table></div><div>" + side + "</div></div>";
			} )
			.catch( function ( err ) { renderError( "cu-outbound", err ); } );
	}

	// -------------------------------------------------------------------------
	// Langues et écrans
	// -------------------------------------------------------------------------
	var langNames = null;
	try {
		if ( window.Intl && "function" === typeof window.Intl.DisplayNames ) {
			langNames = new window.Intl.DisplayNames( [ "fr" ], { type: "language" } );
		}
	} catch ( e ) { langNames = null; }

	function languageName( tag ) {
		try {
			var name = langNames ? langNames.of( String( tag ) ) : "";
			return name ? name.charAt( 0 ).toUpperCase() + name.slice( 1 ) : String( tag );
		} catch ( e ) {
			return String( tag );
		}
	}

	function screenName( key ) {
		var w = parseInt( String( key ).split( "x" )[ 0 ], 10 ) || 0;
		var kind = w < 768 ? "mobile" : ( w < 1200 ? "tablette / petit écran" : "ordinateur" );
		return String( key ).replace( "x", " × " ) + " (" + kind + ")";
	}

	function loadAudience() {
		[ "cu-audience-languages", "cu-audience-screens" ].forEach( loading );
		analyticsFetch( "audience", "analytics/audience?" + qp() )
			.then( function ( d ) {
				var langEl = document.getElementById( "cu-audience-languages" );
				var scrEl = document.getElementById( "cu-audience-screens" );
				if ( ! langEl || ! scrEl ) return;
				var none = '<div class="cu-empty">' + ( d.ready ? "Pas encore de données : elles apparaissent avec les nouvelles visites." : "Mise à jour de la base de données en attente." ) + "</div>";
				langEl.innerHTML = ( d.languages || [] ).length ? barList( d.languages.map( function ( r ) { return { name: languageName( r.key ) + " · " + r.key, visits: r.visits }; } ), "name", "visits", true ) : none;
				scrEl.innerHTML = ( d.screens || [] ).length ? barList( d.screens.map( function ( r ) { return { name: screenName( r.key ), visits: r.visits }; } ), "name", "visits", true ) : none;
			} )
			.catch( function ( err ) { renderError( "cu-audience-languages", err ); renderError( "cu-audience-screens", err ); } );
	}

	// -------------------------------------------------------------------------
	// Traffic sources
	// -------------------------------------------------------------------------
	function loadSources() {
		loading( "cu-sources-list" );
		analyticsFetch( "sources", "analytics/traffic-sources?" + qp() )
			.then( function ( data ) {
				var el = document.getElementById( "cu-sources-list" );
				if ( ! el ) return;
				if ( ! data.length ) { empty( "cu-sources-list" ); return; }
				el.innerHTML = barList( data, "label", "sessions" );
			} )
			.catch( function ( err ) { renderError( "cu-sources-list", err ); } );
	}

	// -------------------------------------------------------------------------
	// Top domaines référents (vrais domaines externes)
	// -------------------------------------------------------------------------
	function loadReferrers() {
		loading( "cu-referrers-list" );
		analyticsFetch( "referrers", "analytics/referrers?" + qp() )
			.then( function ( data ) {
				var el = document.getElementById( "cu-referrers-list" );
				if ( ! el ) return;
				if ( ! data.length ) {
					el.innerHTML = '<div style="color:var(--cu-text-dim);font-size:12px;padding:6px 0;">Aucun domaine référent sur la période (trafic direct ou interne uniquement).</div>';
					return;
				}
				el.innerHTML = data.map( function ( r ) {
					var initial = ( r.host || "?" ).charAt( 0 ).toUpperCase();
					return '<div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--cu-panel-border);font-size:12px;">' +
						'<span style="flex:0 0 24px;height:24px;line-height:24px;text-align:center;border-radius:6px;background:var(--cu-bg-soft);font-weight:700;">' + esc( initial ) + '</span>' +
						'<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc( r.host ) +
						' <span class="cu-badge cu-badge-gray" style="font-size:10px;">' + esc( r.label ) + '</span></span>' +
						'<strong>' + api.formatNumber( r.sessions ) + '</strong>' +
						'<span style="flex:0 0 52px;text-align:right;color:var(--cu-text-dim);">' + fmtPct( r.percent ) + '</span>' +
						'</div>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-referrers-list", err ); } );
	}

	// -------------------------------------------------------------------------
	// Nouvelles « cases » : Trafic IA / Pays récurrents / Parcours / Erreurs JS
	// -------------------------------------------------------------------------
	function statBox( label, big, sub ) {
		return '<div style="flex:1;min-width:120px;background:var(--cu-bg-soft);border-radius:10px;padding:12px 14px;">' +
			'<div style="font-size:11px;color:var(--cu-text-dim);text-transform:uppercase;letter-spacing:.3px;">' + esc( label ) + '</div>' +
			'<div style="font-size:22px;font-weight:800;">' + esc( big ) + '</div>' +
			'<div style="font-size:11px;color:var(--cu-text-dim);">' + esc( sub ) + '</div></div>';
	}
	function line2( a, b ) {
		return '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--cu-panel-border);font-size:12px;"><span>' + esc( a ) + '</span><strong>' + esc( b ) + '</strong></div>';
	}

	function loadAiTraffic() {
		loading( "cu-ai-traffic" );
		analyticsFetch( "ai-traffic", "analytics/ai-traffic?" + qp() )
			.then( function ( d ) {
				var el = document.getElementById( "cu-ai-traffic" );
				if ( ! el ) return;
				var hasRef = d.llms && d.llms.length;
				var hasCit = d.citations && d.citations.length;
				if ( ! hasRef && ! hasCit ) {
					el.innerHTML = '<div class="cu-empty">Aucun trafic IA sur la période. Dès qu\'un visiteur arrivera depuis ChatGPT/Perplexity — ou qu\'un robot se déclarant IA lira votre site — il apparaîtra ici.</div>';
					return;
				}
				var ia = d.compare.ia, g = d.compare.google, html = "";
				html += '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">' +
					statBox( "Visites IA", api.formatNumber( ia.sessions ), fmtPct( ia.rate ) + " conv. déclarée" ) +
					statBox( "Visites moteurs de recherche", api.formatNumber( g.sessions ), fmtPct( g.rate ) + " conv. déclarée" ) +
					( ( ia.rate > 0 && g.rate > 0 ) ? statBox( "IA vs moteurs", api.formatNumber( Math.round( ia.rate / g.rate * 10 ) / 10 ) + "×", "conversion déclarée" ) : "" ) +
					"</div>";
				if ( hasRef ) {
					html += '<div style="font-size:12px;font-weight:700;color:var(--cu-text-dim);text-transform:uppercase;margin:6px 0;">Par IA</div>';
					html += d.llms.map( function ( l ) {
						var cr = undefined !== l.rate ? l.rate : ( l.sessions ? Math.round( l.conversions / l.sessions * 1000 ) / 10 : 0 );
						return line2( l.llm, api.formatNumber( l.sessions ) + " visite(s) · " + fmtPct( cr ) + " conv. déclarée" );
					} ).join( "" );
				}
				if ( hasCit ) {
					html += '<div style="font-size:12px;font-weight:700;color:var(--cu-text-dim);text-transform:uppercase;margin:14px 0 6px;">Passages de robots IA (' + esc( api.formatNumber( d.citations_total ) ) + ')</div>';
					html += '<p class="cu-ai-note">Robot identifié par le User-Agent qu’il déclare (non vérifié, falsifiable)' +
						( d.citations_sampled ? " ; au plus un passage compté par minute et par robot." : "." ) + "</p>";
					html += d.citations.map( function ( c ) { return line2( c.bot, api.formatNumber( c.hits ) + " passage(s)" ); } ).join( "" );
				}
				el.innerHTML = html;
			} )
			.catch( function ( err ) { renderError( "cu-ai-traffic", err ); } );
	}

	function loadRecurringCountries() {
		loading( "cu-recountries-list" );
		analyticsFetch( "recurring-countries", "analytics/recurring-countries?" + qp() )
			.then( function ( data ) {
				var el = document.getElementById( "cu-recountries-list" );
				if ( ! el ) return;
				if ( ! data.length ) { el.innerHTML = '<div class="cu-empty">Pas encore assez de données (min. 3 visites par pays).</div>'; return; }
				// Conteneur défilant : sur mobile, le tableau ne doit pas être coupé.
				el.innerHTML = '<div class="cu-table-wrap"><table class="cu-table"><thead><tr><th>Pays</th><th>Visites</th><th>Revenants</th><th>Récurrence</th></tr></thead><tbody>' +
					data.map( function ( r ) {
						return '<tr><td>' + esc( countryName( r.code ) ) + '</td>' +
							'<td>' + api.formatNumber( r.sessions ) + '</td>' +
							'<td>' + api.formatNumber( r.returning ) + '</td>' +
							'<td><strong>' + fmtPct( r.rate ) + '</strong></td></tr>';
					} ).join( "" ) + '</tbody></table></div>';
			} )
			.catch( function ( err ) { renderError( "cu-recountries-list", err ); } );
	}

	function loadPaths() {
		loading( "cu-paths-list" );
		analyticsFetch( "paths", "analytics/paths?" + qp() )
			.then( function ( data ) {
				var el = document.getElementById( "cu-paths-list" );
				if ( ! el ) return;
				if ( ! data.length ) { el.innerHTML = '<div class="cu-empty">Aucun parcours multi-pages sur la période.</div>'; return; }
				el.innerHTML = data.map( function ( r ) {
					return '<div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid var(--cu-panel-border);font-size:12px;">' +
						'<span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc( r.path ) + '</span><strong>' + api.formatNumber( r.count ) + '</strong></div>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-paths-list", err ); } );
	}

	function loadJsErrors() {
		loading( "cu-jserrors-list" );
		analyticsFetch( "js-errors", "analytics/js-errors?" + qp() )
			.then( function ( data ) {
				var el = document.getElementById( "cu-jserrors-list" );
				if ( ! el ) return;
				if ( ! data.length ) { el.innerHTML = '<div class="cu-empty">Aucune erreur JavaScript détectée.</div>'; return; }
				el.innerHTML = data.map( function ( r ) {
					return '<div style="padding:8px 0;border-bottom:1px solid var(--cu-panel-border);font-size:12px;">' +
						'<div style="display:flex;justify-content:space-between;gap:10px;"><strong style="flex:1;min-width:0;white-space:nowrap;color:var(--cu-danger);overflow:hidden;text-overflow:ellipsis;">' + esc( r.message ) + '</strong><span class="cu-badge cu-badge-red">' + api.formatNumber( r.count ) + '</span></div>' +
						( r.url ? '<div style="color:var(--cu-text-dim);margin-top:2px;overflow-wrap:anywhere;">' + esc( r.url ) + ( r.source ? " · " + esc( r.source ) : "" ) + '</div>' : "" ) +
						'</div>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-jserrors-list", err ); } );
	}

	// -------------------------------------------------------------------------
	// Categories / Tags / Authors
	// -------------------------------------------------------------------------
	function loadCategories() {
		loading( "cu-categories-list" );
		analyticsFetch( "categories", "analytics/categories?" + qp() )
			.then( function ( data ) {
				var el = document.getElementById( "cu-categories-list" );
				if ( ! el ) return;
				if ( ! data.length ) { empty( "cu-categories-list" ); return; }
				el.innerHTML = data.slice( 0, 10 ).map( function ( r ) {
					return '<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--cu-panel-border);font-size:12px;">' +
						'<span>' + esc( r.name ) + '</span><strong>' + api.formatNumber( r.pageviews ) + ' vues</strong></div>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-categories-list", err ); } );
	}

	function loadTags() {
		loading( "cu-tags-list" );
		analyticsFetch( "tags", "analytics/tags?" + qp() )
			.then( function ( data ) {
				var el = document.getElementById( "cu-tags-list" );
				if ( ! el ) return;
				if ( ! data.length ) { empty( "cu-tags-list" ); return; }
				el.innerHTML = data.slice( 0, 15 ).map( function ( r ) {
					return '<span class="cu-pill" style="margin:3px;font-size:11px;">' + esc( r.name ) +
						' <strong title="Pages vues">' + api.formatNumber( r.pageviews ) + '</strong></span>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-tags-list", err ); } );
	}

	function loadAuthors() {
		loading( "cu-authors-list" );
		analyticsFetch( "authors", "analytics/authors?" + qp() )
			.then( function ( data ) {
				var el = document.getElementById( "cu-authors-list" );
				if ( ! el ) return;
				if ( ! data.length ) { empty( "cu-authors-list" ); return; }
				el.innerHTML = data.slice( 0, 10 ).map( function ( r ) {
					return '<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--cu-panel-border);font-size:12px;">' +
						'<span>' + esc( r.author ) + ' <span class="cu-badge cu-badge-gray">' + api.formatNumber( r.posts ) + ' contenu' + ( r.posts > 1 ? "s" : "" ) + '</span></span>' +
						'<strong>' + api.formatNumber( r.pageviews ) + ' vues</strong></div>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-authors-list", err ); } );
	}

	// -------------------------------------------------------------------------
	// WooCommerce
	// -------------------------------------------------------------------------
	function loadWoo() {
		var el = document.getElementById( "cu-woo-content" );
		if ( ! el ) return;
		el.innerHTML = '<div class="cu-loading"><div class="cu-spinner"></div></div>';

		analyticsFetch( "woocommerce", "analytics/woocommerce?" + qp() )
			.then( function ( d ) {
				if ( ! d.available ) {
					el.innerHTML = '<div class="cu-empty">WooCommerce non disponible.</div>';
					return;
				}
				var currency = d.currency_symbol || "€";
				var products = d.top_products || [];
				var productRows = products.length ? products.map( function ( p ) {
					return '<tr><td>' + esc( p.name ) + '</td><td>' + api.formatNumber( p.views ) + '</td>' +
						'<td>' + api.formatNumber( p.cart ) + '</td><td>' + api.formatNumber( p.purchases ) + '</td>' +
						'<td>' + fmtPct( p.conv_rate ) + '</td></tr>';
				} ).join( "" ) : '<tr><td colspan="5" style="color:var(--cu-text-dim);">Aucune vue de produit sur la période.</td></tr>';
				el.innerHTML =
					'<div class="cu-grid cu-grid-4" style="margin-bottom:20px;">' +
					kpiMini( "Vues produits", api.formatNumber( d.product_views ) ) +
					kpiMini( "Clics CTA produit", api.formatNumber( d.cart_adds ) ) +
					kpiMini( "Achats", api.formatNumber( d.purchases ) ) +
					kpiMini( "Taux conv.", fmtPct( d.conv_rate ) ) +
					kpiMini( "CA", money( d.revenue, currency ) ) +
					kpiMini( "Panier moy.", money( d.avg_order, currency ) ) +
					kpiMini( "Commandes", api.formatNumber( d.orders ) ) +
					'</div>' +
					( d.partial
						? '<div class="cu-empty" style="text-align:left;margin:-8px 0 16px;">' + api.icon( "alert" ) + ' Volume de commandes trop important pour cette période : CA, panier moyen et achats ne couvrent que les ' +
							api.formatNumber( d.orders ) + ' commandes les plus récentes. Choisissez une période plus courte pour un total exact.</div>'
						: '' ) +
					'<div style="font-size:12px;font-weight:700;color:var(--cu-text-dim);margin-bottom:8px;text-transform:uppercase;">Top produits</div>' +
					'<div class="cu-table-wrap"><table class="cu-table"><thead><tr>' +
					'<th>Produit</th><th>Vues</th><th>CTA</th><th>Achats</th><th>Conv.</th>' +
					'</tr></thead><tbody>' + productRows + '</tbody></table></div>';
			} )
			.catch( function ( err ) { renderError( "cu-woo-content", err ); } );
	}

	function kpiMini( label, value ) {
		return '<div class="cu-card cu-kpi">' +
			'<div class="cu-kpi-label">' + esc( label ) + '</div>' +
			'<div class="cu-kpi-value" style="font-size:20px;">' + esc( value ) + '</div>' +
			'</div>';
	}

	// -------------------------------------------------------------------------
	// Insights
	// -------------------------------------------------------------------------
	function loadInsights() {
		var grid = document.getElementById( "cu-insights-grid" );
		if ( grid ) grid.innerHTML = '<div class="cu-loading"><div class="cu-spinner"></div></div>';

		analyticsFetch( "insights", "analytics/insights?" + qp() )
			.then( function ( data ) {
				if ( ! grid ) return;
				if ( ! data || ! data.length ) {
					grid.innerHTML = '<div class="cu-empty">Pas encore assez de données pour générer des insights.</div>';
					return;
				}

				var iconMap = {
					trending_up: "trending-up",
					rising:      "trending-up",
					high_bounce: "flag",
					best_hours:  "clock",
					top_sources: "globe",
					top_products:"cart",
				};

				grid.innerHTML = data.map( function ( ins ) {
					var icon = iconMap[ ins.type ] || "sparkles";
					var itemsHtml = "";
					if ( ins.items && ins.items.length ) {
						itemsHtml = '<ul style="margin:8px 0 0;padding:0;list-style:none;">' +
							ins.items.map( function ( item ) {
								var extra = "";
								if ( item.delta !== undefined )      extra = ' <span class="cu-badge cu-badge-green">+' + fmtPct( Math.round( item.delta * 10 ) / 10 ) + '</span>';
								else if ( item.bounce_rate !== undefined ) extra = ' <span class="cu-badge cu-badge-red">' + fmtPct( item.bounce_rate ) + '</span>';
								else if ( item.sessions !== undefined )    extra = ' <span class="cu-badge cu-badge-gray">' + api.formatNumber( item.sessions ) + '</span>';
								else if ( item.conv_rate !== undefined )   extra = ' <span class="cu-badge cu-badge-purple">' + fmtPct( item.conv_rate ) + '</span>';
								else if ( item.hour !== undefined )        extra = ' <span class="cu-badge cu-badge-gray">' + api.formatNumber( item.sessions || 0 ) + ' visites</span>';
								var title = item.title || item.label || item.source || item.hour || "";
								return '<li style="padding:5px 0;border-bottom:1px solid var(--cu-panel-border);font-size:12px;display:flex;justify-content:space-between;align-items:center;">' +
									'<span>' + esc( title ) + '</span>' + extra + '</li>';
							} ).join( "" ) +
							'</ul>';
					}
					return '<div class="cu-card">' +
						'<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-weight:700;font-size:13px;">' +
						'<span style="color:var(--cu-accent-2);">' + getCuIcon( icon ) + '</span>' +
						esc( ins.title ) + '</div>' +
						itemsHtml + '</div>';
				} ).join( "" );
			} )
			.catch( function ( err ) { renderError( "cu-insights-grid", err ); } );
	}

	// Inline SVG helper (minimal, reuses known icon names)
	function getCuIcon( name ) {
		var icons = {
			"trending-up": '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path d="M16 7h6v6"/></svg>',
			"flag":        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3v18"/><path d="M4 4h13l-2 4 2 4H4"/></svg>',
			"clock":       '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
			"globe":       '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/></svg>',
			"cart":        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h2l2.4 12.2a2 2 0 0 0 2 1.6h8.2a2 2 0 0 0 2-1.6L21 7H6"/></svg>',
			"sparkles":    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><path d="m6 6 2 2"/><path d="m16 16 2 2"/><path d="m6 18 2-2"/><path d="m16 8 2-2"/></svg>',
		};
		return icons[ name ] || icons[ "sparkles" ];
	}

	// -------------------------------------------------------------------------
	// CSV Export
	// -------------------------------------------------------------------------
	function exportCsv() {
		// Un export peut prendre plusieurs secondes : un double clic en lançait deux.
		if ( btnExportCsv && btnExportCsv.disabled ) return;
		if ( btnExportCsv ) btnExportCsv.disabled = true;
		var url = api.restUrl( "analytics/export?range=" + encodeURIComponent( state.range ) + "&format=csv" );

		// Use fetch + blob to handle auth headers
		fetch( url, {
			headers: {
				"X-WP-Nonce": window.cuAdminConfig ? window.cuAdminConfig.nonce : "",
			},
			credentials: "same-origin",
		} )
		.then( function ( r ) {
			if ( ! r.ok ) throw new Error( "Export HTTP " + r.status );
			var type = r.headers.get( "content-type" ) || "";
			if ( type.indexOf( "text/csv" ) === -1 ) throw new Error( "Réponse d'export invalide" );
			return r.blob();
		} )
		.then( function ( blob ) {
			var a = document.createElement( "a" );
			var objectUrl = URL.createObjectURL( blob );
			a.href = objectUrl;
			a.download = "analytics-export-" + state.range + "j.csv";
			document.body.appendChild( a );
			a.click();
			a.remove();
			URL.revokeObjectURL( objectUrl );
		} )
		.catch( function ( err ) { api.toast( errorText( err ), "error" ); } )
		.then( function () { if ( btnExportCsv ) btnExportCsv.disabled = false; } );
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------
	var pageInitialized = false;
	function initPage() {
		if ( pageInitialized ) return;
		pageInitialized = true;
		document.addEventListener( "visibilitychange", onVisibilityChange );
		window.addEventListener( "pagehide", pauseRealtimeLoop );
		window.addEventListener( "pageshow", function () {
			if ( ! document.hidden ) startRealtimeLoop();
		} );
		startRealtimeLoop();
		var checkBtn = document.getElementById( "cu-tracking-check" );
		if ( checkBtn ) checkBtn.addEventListener( "click", runTrackingCheck );
		// Carte « En bref » : sélecteur de période indépendant (jour/semaine/mois/année).
		var pills = document.getElementById( "cu-brief-periods" );
		if ( pills ) {
			var syncPills = function ( active ) {
				pills.querySelectorAll( ".cu-brief-pill" ).forEach( function ( b ) {
					var on = b === active;
					b.classList.toggle( "is-active", on );
					b.setAttribute( "role", "tab" );
					b.setAttribute( "aria-selected", on ? "true" : "false" );
					b.setAttribute( "aria-controls", "cu-brief-body" );
					// Onglets ARIA : seul l'onglet actif est dans l'ordre de tabulation,
					// les flèches passent d'un onglet à l'autre.
					b.setAttribute( "tabindex", on ? "0" : "-1" );
				} );
			};
			var selectPill = function ( btn ) {
				if ( ! btn || btn.classList.contains( "is-active" ) ) return;
				syncPills( btn );
				loadBrief( btn.getAttribute( "data-period" ) );
			};
			syncPills( pills.querySelector( ".cu-brief-pill.is-active" ) );
			pills.addEventListener( "click", function ( e ) {
				selectPill( e.target.closest( "[data-period]" ) );
			} );
			pills.addEventListener( "keydown", function ( e ) {
				var list = Array.prototype.slice.call( pills.querySelectorAll( ".cu-brief-pill" ) );
				var index = list.indexOf( document.activeElement );
				if ( index < 0 ) return;
				var next = null;
				if ( "ArrowRight" === e.key || "ArrowDown" === e.key ) next = list[ ( index + 1 ) % list.length ];
				else if ( "ArrowLeft" === e.key || "ArrowUp" === e.key ) next = list[ ( index - 1 + list.length ) % list.length ];
				else if ( "Home" === e.key ) next = list[ 0 ];
				else if ( "End" === e.key ) next = list[ list.length - 1 ];
				if ( ! next ) return;
				e.preventDefault();
				next.focus();
				selectPill( next );
			} );
		}
		loadBrief( "week" );
		loadAll();
		initLazyReports();

		// Observatory : entrée en cascade des sections (IntersectionObserver).
		// Guardé : si cu-viz n'est pas là, on ne masque rien (contenu visible).
		if ( window.cuViz ) {
			page.querySelectorAll( ".cu-section, .cu-brief" ).forEach( function ( el ) {
				el.classList.add( "cu-reveal" );
			} );
			window.cuViz.reveal( page );
		}
	}

	if ( "loading" === document.readyState ) {
		document.addEventListener( "DOMContentLoaded", initPage );
	} else {
		initPage();
	}

} )();

/* CyberPush Ultra — graphique « Évolution des attaques » (cu-attack-chart.js)
 * Aires empilées SVG en relief, révélées à l'ouverture, infobulle détaillée
 * (détail du jour chargé à la demande), légende interactive, navigation au
 * clavier et actualisation en direct. Vanilla JS, aucune dépendance externe.
 * Dépend de cu-admin.js (window.cuAdmin).
 */
( function () {
	"use strict";

	var SVG_NS = "http://www.w3.org/2000/svg";
	var MAX_LAYERS = 6; // 5 familles principales + « Autres »
	var OTHER = "other";
	var GRAY_INDEX = 7; // --cu-viz-8, réservé à « Autres »
	var uid = 0;

	// -------------------------------------------------------------------------
	// Utilitaires
	// -------------------------------------------------------------------------
	function esc( value ) {
		return String( null == value ? "" : value ).replace( /&/g, "&amp;" ).replace( /</g, "&lt;" ).replace( />/g, "&gt;" ).replace( /"/g, "&quot;" );
	}

	function num( value ) {
		return cuAdmin.formatNumber( value );
	}

	// « 1 250 » → « 1,3 k » pour les graduations.
	function compact( value ) {
		if ( value >= 1000000 ) return ( Math.round( value / 100000 ) / 10 ).toString().replace( ".", "," ) + " M";
		if ( value >= 10000 ) return Math.round( value / 1000 ) + " k";
		if ( value >= 1000 ) return ( Math.round( value / 100 ) / 10 ).toString().replace( ".", "," ) + " k";
		return String( value );
	}

	function pct( part, whole ) {
		return whole > 0 ? Math.round( part / whole * 100 ) : 0;
	}

	// Évolution : en pourcentage, ou en multiple au-delà de ×10 (« +110 300 % » ne se lit pas).
	function trendBadge( current, previous ) {
		if ( ! ( previous > 0 ) ) return "";
		var ratio = current / previous;
		if ( ratio >= 10 ) return '<span class="cu-kpi-delta down">▲ ×' + num( Math.round( ratio ) ) + "</span>";
		return cuAdmin.deltaBadge( ( current - previous ) / previous * 100, true );
	}

	// Les dates arrivent en « AAAA-MM-JJ » (heure du site) : lecture en date locale.
	function toDate( iso ) {
		var parts = String( iso || "" ).split( "-" );
		return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) );
	}
	function dayLong( iso ) {
		return toDate( iso ).toLocaleDateString( "fr-FR", { weekday: "long", day: "numeric", month: "long" } );
	}
	function dayShort( iso ) {
		return toDate( iso ).toLocaleDateString( "fr-FR", { weekday: "short", day: "2-digit", month: "2-digit" } );
	}
	function dayTick( iso ) {
		return toDate( iso ).toLocaleDateString( "fr-FR", { day: "2-digit", month: "2-digit" } );
	}
	function isWeekend( iso ) {
		var weekday = toDate( iso ).getDay();
		return 0 === weekday || 6 === weekday;
	}
	function clock( mysqlDate ) {
		var match = /(\d{2}:\d{2})/.exec( String( mysqlDate || "" ) );
		return match ? match[ 1 ] : "";
	}

	var regionNames = null;
	try { regionNames = new Intl.DisplayNames( [ "fr" ], { type: "region" } ); } catch ( e ) { regionNames = null; }
	function countryLabel( code ) {
		code = String( code || "" ).toUpperCase();
		if ( ! /^[A-Z]{2}$/.test( code ) ) return "";
		try {
			var name = regionNames ? regionNames.of( code ) : "";
			return name && name !== code ? name : code;
		} catch ( e ) {
			return code;
		}
	}

	function el( tag, attrs, parent ) {
		var node = document.createElementNS( SVG_NS, tag );
		Object.keys( attrs || {} ).forEach( function ( key ) { node.setAttribute( key, attrs[ key ] ); } );
		if ( parent ) parent.appendChild( node );
		return node;
	}

	function reducedMotion( root ) {
		var wrap = root.closest( ".cu-wrap" );
		if ( wrap && "off" === wrap.getAttribute( "data-cu-motion" ) ) return true;
		return !! ( window.matchMedia && window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches );
	}

	function easeOut( t ) {
		return 1 - Math.pow( 1 - t, 3 );
	}

	// Courbe monotone partagée (cu-admin.js) : lisse sans inventer de creux ni de pic.
	var monotone = cuAdmin.smoothPath;

	// -------------------------------------------------------------------------
	// Composant
	// -------------------------------------------------------------------------
	function mount( root, opts ) {
		var id = "cu-ac-" + ( ++uid );
		var state = {
			data: null,
			layers: [],
			hidden: {},
			active: -1,
			pinned: false,
			revealed: false,
			geometry: null,
			dayCache: {},
			detailTimer: null,
			announceTimer: null,
			width: 0,
		};

		root.classList.add( "cu-ac" );
		root.innerHTML =
			'<div class="cu-ac-summary"></div>' +
			'<div class="cu-ac-stage" tabindex="0" role="group" aria-roledescription="graphique" aria-describedby="' + id + '-help" aria-label="Évolution des attaques bloquées, jour par jour">' +
				'<svg class="cu-ac-svg" aria-hidden="true" focusable="false"></svg>' +
				'<div class="cu-ac-tip" hidden></div>' +
				'<div class="cu-ac-state"><span class="cu-spinner"></span> Chargement…</div>' +
			"</div>" +
			'<p class="cu-ac-help" id="' + id + '-help">Survolez un jour pour le détail · cliquez pour l’épingler · ← → au clavier</p>' +
			'<div class="cu-ac-legend" role="group" aria-label="Types d’attaque affichés"></div>' +
			'<p class="screen-reader-text" aria-live="polite"></p>' +
			'<div class="screen-reader-text cu-ac-sr"><table></table></div>';

		var summary = root.querySelector( ".cu-ac-summary" );
		var stage = root.querySelector( ".cu-ac-stage" );
		var svg = root.querySelector( ".cu-ac-svg" );
		var tip = root.querySelector( ".cu-ac-tip" );
		var status = root.querySelector( ".cu-ac-state" );
		var legend = root.querySelector( ".cu-ac-legend" );
		var live = root.querySelector( "p.screen-reader-text" );
		var table = root.querySelector( ".cu-ac-sr table" );

		// --- Données -----------------------------------------------------------
		function buildLayers( data ) {
			var families = Object.keys( data.rules || {} ).sort( function ( p, q ) { return data.rules[ q ] - data.rules[ p ]; } );
			var keep = families.length > MAX_LAYERS
				? families.filter( function ( f ) { return OTHER !== f; } ).slice( 0, MAX_LAYERS - 1 )
				: families.filter( function ( f ) { return OTHER !== f; } );
			var merged = families.some( function ( f ) { return keep.indexOf( f ) === -1; } );
			var palette = cuAdmin.vizColors();
			var used = {};
			used[ GRAY_INDEX ] = true;
			var layers = keep.map( function ( family ) {
				var want = opts.colorIndex ? opts.colorIndex( family ) : -1;
				if ( want < 0 || used[ want ] ) {
					want = 0;
					while ( used[ want ] && want < palette.length - 1 ) want++;
				}
				used[ want ] = true;
				return { key: family, label: opts.label( family ), color: palette[ want % palette.length ], families: [ family ] };
			} );
			if ( merged ) {
				layers.push( {
					key: OTHER,
					label: opts.label( OTHER ),
					color: palette[ GRAY_INDEX ],
					families: families.filter( function ( f ) { return keep.indexOf( f ) === -1; } ),
				} );
			}
			layers.forEach( function ( layer ) {
				layer.values = data.days.map( function ( day ) {
					return layer.families.reduce( function ( sum, f ) { return sum + ( day.rules[ f ] || 0 ); }, 0 );
				} );
				layer.total = layer.values.reduce( function ( sum, v ) { return sum + v; }, 0 );
			} );
			return layers;
		}

		function visibleLayers() {
			return state.layers.filter( function ( layer ) { return ! state.hidden[ layer.key ]; } );
		}

		function load( first ) {
			return opts.load().then( function ( data ) {
				if ( ! data || ! data.days ) throw new Error( "Réponse inattendue." );
				var today = data.days.length ? data.days[ data.days.length - 1 ].date : "";
				delete state.dayCache[ today ]; // la journée en cours évolue encore
				state.data = data;
				state.layers = buildLayers( data );
				renderSummary( first );
				renderLegend();
				renderTable();
				render();
				if ( state.active >= 0 ) setActive( Math.min( state.active, data.days.length - 1 ), true );
			} ).catch( function ( err ) {
				if ( first ) {
					status.hidden = false;
					status.innerHTML = '<span class="cu-ac-error">' + esc( err && err.message ? err.message : "Données indisponibles." ) + "</span>";
				}
			} );
		}

		// --- Résumé ------------------------------------------------------------
		function countUp( node, target, animate ) {
			if ( ! animate || target <= 0 ) {
				node.textContent = num( target );
				return;
			}
			var start = null;
			function step( now ) {
				if ( null === start ) start = now;
				var t = Math.min( 1, ( now - start ) / 900 );
				node.textContent = num( Math.round( target * easeOut( t ) ) );
				if ( t < 1 ) window.requestAnimationFrame( step );
			}
			window.requestAnimationFrame( step );
		}

		function renderSummary( first ) {
			var sum = state.data.summary || {};
			var top = state.layers.filter( function ( layer ) { return OTHER !== layer.key; } )
				.sort( function ( p, q ) { return q.total - p.total; } )[ 0 ];
			var trend = sum.prev_total > 0 ? trendBadge( sum.total || 0, sum.prev_total ) : "";
			var tiles = [
				{ label: "Attaques bloquées · " + ( Number( sum.days ) || 14 ) + " j", value: sum.total || 0, extra: trend ? trend + '<span class="cu-ac-sub">vs ' + ( Number( sum.days ) || 14 ) + " j précédents</span>" : '<span class="cu-ac-sub">' + ( sum.prev_total === 0 && sum.total > 0 ? "aucune la période précédente" : "&nbsp;" ) + "</span>", main: true },
				{ label: "Moyenne par jour", text: String( sum.avg || 0 ).replace( ".", "," ), extra: '<span class="cu-ac-sub">attaques / jour</span>' },
				{ label: "Jour le plus chargé", value: sum.peak ? sum.peak.total : 0, extra: '<span class="cu-ac-sub">' + ( sum.peak ? esc( dayShort( sum.peak.date ) ) : "—" ) + "</span>" },
				{ label: "Menace n°1", text: top && top.total ? top.label : "—", extra: top && top.total ? '<span class="cu-ac-sub"><i class="cu-ac-dot" style="background:' + top.color + '"></i>' + pct( top.total, sum.total ) + " % des blocages</span>" : '<span class="cu-ac-sub">&nbsp;</span>', small: true },
				{ label: "IP uniques bloquées", value: sum.unique_ips || 0, extra: '<span class="cu-ac-sub">sur la période</span>' },
			];
			summary.innerHTML = tiles.map( function ( tile, n ) {
				return '<div class="cu-ac-stat' + ( tile.main ? " is-main" : "" ) + '">' +
					'<span class="cu-ac-stat-label">' + esc( tile.label ) + "</span>" +
					'<span class="cu-ac-stat-value' + ( tile.small ? " is-text" : "" ) + '" data-n="' + n + '">' + ( undefined !== tile.text ? esc( tile.text ) : "0" ) + "</span>" +
					tile.extra + "</div>";
			} ).join( "" );
			var animate = first && ! reducedMotion( root );
			tiles.forEach( function ( tile, n ) {
				if ( undefined === tile.text ) countUp( summary.querySelector( '[data-n="' + n + '"]' ), tile.value, animate );
			} );
		}

		// --- Légende -----------------------------------------------------------
		function renderLegend() {
			legend.innerHTML = state.layers.map( function ( layer ) {
				var on = ! state.hidden[ layer.key ];
				return '<button type="button" class="cu-ac-chip" data-key="' + esc( layer.key ) + '" aria-pressed="' + ( on ? "true" : "false" ) + '" title="Clic : masquer ou afficher · double-clic : isoler">' +
					'<i class="cu-ac-dot" style="background:' + layer.color + '"></i>' +
					'<span class="cu-ac-chip-label">' + esc( layer.label ) + "</span>" +
					'<span class="cu-ac-chip-n">' + num( layer.total ) + "</span></button>";
			} ).join( "" );
		}

		legend.addEventListener( "click", function ( ev ) {
			var chip = ev.target.closest( ".cu-ac-chip" );
			if ( ! chip ) return;
			var key = chip.getAttribute( "data-key" );
			// Masquer la dernière série visible réaffiche tout (jamais de graphique vide).
			if ( ! state.hidden[ key ] && 1 === visibleLayers().length ) {
				state.hidden = {};
			} else {
				state.hidden[ key ] = ! state.hidden[ key ];
			}
			renderLegend();
			render();
			if ( state.active >= 0 ) setActive( state.active, true );
		} );
		legend.addEventListener( "dblclick", function ( ev ) {
			var chip = ev.target.closest( ".cu-ac-chip" );
			if ( ! chip ) return;
			var key = chip.getAttribute( "data-key" );
			state.hidden = {};
			state.layers.forEach( function ( layer ) { if ( layer.key !== key ) state.hidden[ layer.key ] = true; } );
			renderLegend();
			render();
		} );
		legend.addEventListener( "mouseover", function ( ev ) {
			var chip = ev.target.closest( ".cu-ac-chip" );
			highlight( chip ? chip.getAttribute( "data-key" ) : "" );
		} );
		legend.addEventListener( "mouseleave", function () { highlight( "" ); } );

		function highlight( key ) {
			root.classList.toggle( "is-focus", !! key && ! state.hidden[ key ] );
			svg.querySelectorAll( ".cu-ac-layer" ).forEach( function ( node ) {
				node.classList.toggle( "is-hot", node.getAttribute( "data-key" ) === key );
			} );
		}

		// --- Tableau accessible ------------------------------------------------
		function renderTable() {
			var head = "<caption>Attaques bloquées par jour et par type</caption><thead><tr><th scope=\"col\">Jour</th><th scope=\"col\">Total</th>" +
				state.layers.map( function ( layer ) { return '<th scope="col">' + esc( layer.label ) + "</th>"; } ).join( "" ) + "</tr></thead>";
			var body = state.data.days.map( function ( day, n ) {
				return '<tr><th scope="row">' + esc( dayLong( day.date ) ) + "</th><td>" + num( day.total ) + "</td>" +
					state.layers.map( function ( layer ) { return "<td>" + num( layer.values[ n ] ) + "</td>"; } ).join( "" ) + "</tr>";
			} ).join( "" );
			table.innerHTML = head + "<tbody>" + body + "</tbody>";
		}

		// --- Rendu SVG ---------------------------------------------------------
		function render() {
			var days = state.data ? state.data.days : [];
			var width = Math.max( 280, Math.floor( stage.clientWidth ) );
			var height = width < 600 ? 230 : 300;
			state.width = width;
			while ( svg.firstChild ) svg.removeChild( svg.firstChild );
			svg.setAttribute( "viewBox", "0 0 " + width + " " + height );
			svg.setAttribute( "width", width );
			svg.setAttribute( "height", height );
			status.hidden = true;
			if ( ! days.length ) return;

			var total = state.data.summary ? state.data.summary.total : 0;
			root.classList.toggle( "is-empty", ! total );
			if ( ! total ) {
				status.hidden = false;
				status.innerHTML = '<span class="cu-ac-calm">' + cuAdmin.icon( "check" ) + " Aucune attaque bloquée sur la période : tout est calme.</span>";
			}

			var pad = { l: 46, r: 18, t: 34, b: 30 };
			var plotW = width - pad.l - pad.r;
			var plotH = height - pad.t - pad.b;
			var count = days.length;
			var step = count > 1 ? plotW / ( count - 1 ) : 0;
			var layers = visibleLayers();
			var stacks = days.map( function ( day, n ) {
				var acc = 0;
				return layers.map( function ( layer ) { acc += layer.values[ n ]; return acc; } );
			} );
			var maxStack = stacks.reduce( function ( m, s ) { return Math.max( m, s.length ? s[ s.length - 1 ] : 0 ); }, 0 );
			var scale = cuAdmin.niceScale( maxStack, 4 );
			var yMax = scale.max;
			function xAt( n ) { return pad.l + ( count > 1 ? n * step : plotW / 2 ); }
			function yAt( v ) { return pad.t + plotH - ( v / yMax ) * plotH; }
			state.geometry = { pad: pad, plotW: plotW, plotH: plotH, step: step, count: count, xAt: xAt, yAt: yAt, stacks: stacks, layers: layers, height: height };

			// Définitions : dégradés en relief, halo de la ligne totale, masque de révélation.
			var defs = el( "defs", {}, svg );
			layers.forEach( function ( layer, n ) {
				var grad = el( "linearGradient", { id: id + "-g" + n, x1: "0", y1: "0", x2: "0", y2: "1" }, defs );
				el( "stop", { offset: "0%", "stop-color": layer.color, "stop-opacity": "0.92" }, grad );
				el( "stop", { offset: "55%", "stop-color": layer.color, "stop-opacity": "0.62" }, grad );
				el( "stop", { offset: "100%", "stop-color": layer.color, "stop-opacity": "0.30" }, grad );
			} );
			var sheen = el( "linearGradient", { id: id + "-sheen", x1: "0", y1: "0", x2: "0", y2: "1" }, defs );
			el( "stop", { offset: "0%", "stop-color": "#ffffff", "stop-opacity": "0.55" }, sheen );
			el( "stop", { offset: "40%", "stop-color": "#ffffff", "stop-opacity": "0" }, sheen );
			var glow = el( "filter", { id: id + "-glow", x: "-5%", y: "-30%", width: "110%", height: "160%" }, defs );
			el( "feGaussianBlur", { stdDeviation: "5" }, glow );
			var clip = el( "clipPath", { id: id + "-clip" }, defs );
			var clipRect = el( "rect", { x: "0", y: "0", width: String( width ), height: String( height ) }, clip );

			// Fond : week-ends teintés, grille pointillée, graduations.
			var back = el( "g", { class: "cu-ac-back" }, svg );
			days.forEach( function ( day, n ) {
				if ( isWeekend( day.date ) && step >= 18 ) {
					el( "rect", { class: "cu-ac-weekend", x: ( xAt( n ) - step / 2 ).toFixed( 1 ), y: pad.t, width: step.toFixed( 1 ), height: plotH }, back );
				}
			} );
			for ( var g = 0; g <= 4; g++ ) {
				var gv = scale.step * g;
				var gy = yAt( gv );
				el( "line", { class: 0 === g ? "cu-ac-axis" : "cu-ac-grid", x1: pad.l, x2: width - pad.r, y1: gy.toFixed( 1 ), y2: gy.toFixed( 1 ) }, back );
				var label = el( "text", { class: "cu-ac-ylabel", x: pad.l - 10, y: ( gy + 4 ).toFixed( 1 ), "text-anchor": "end" }, back );
				label.textContent = compact( gv );
			}
			var every = step >= 46 ? 1 : ( step >= 24 ? 2 : 3 );
			days.forEach( function ( day, n ) {
				var last = n === count - 1;
				if ( ! last && ( count - 1 - n ) % every !== 0 ) return;
				var tick = el( "text", { class: "cu-ac-xlabel" + ( last ? " is-today" : "" ), x: xAt( n ).toFixed( 1 ), y: height - 8, "text-anchor": last ? "end" : "middle" }, back );
				tick.textContent = last ? "Auj." : dayTick( day.date );
			} );

			// Aires empilées (la plus volumineuse en bas), arête supérieure soulignée.
			var plot = el( "g", { class: "cu-ac-plot", "clip-path": "url(#" + id + "-clip)" }, svg );
			var lower = days.map( function ( day, n ) { return [ xAt( n ), yAt( 0 ) ]; } );
			layers.forEach( function ( layer, li ) {
				var upper = days.map( function ( day, n ) { return [ xAt( n ), yAt( stacks[ n ][ li ] ) ]; } );
				var reversedLower = lower.slice().reverse();
				var area = monotone( upper ) + "L" + monotone( reversedLower ).slice( 1 ) + "Z";
				var group = el( "g", { class: "cu-ac-layer", "data-key": layer.key }, plot );
				el( "path", { class: "cu-ac-area", d: area, fill: "url(#" + id + "-g" + li + ")" }, group );
				el( "path", { class: "cu-ac-sheen", d: area, fill: "url(#" + id + "-sheen)" }, group );
				el( "path", { class: "cu-ac-edge", d: monotone( upper ), stroke: layer.color }, group );
				lower = upper;
			} );
			if ( layers.length && total ) {
				var crest = monotone( lower );
				el( "path", { class: "cu-ac-crest-glow", d: crest, filter: "url(#" + id + "-glow)" }, plot );
				el( "path", { class: "cu-ac-crest", d: crest }, plot );
			}

			// Pic de la période (séries visibles) et pouls du jour en cours.
			var peakIndex = -1, peakValue = 0;
			stacks.forEach( function ( s, n ) {
				var v = s.length ? s[ s.length - 1 ] : 0;
				if ( v > peakValue ) { peakValue = v; peakIndex = n; }
			} );
			var marks = el( "g", { class: "cu-ac-marks" }, svg );
			if ( peakIndex >= 0 ) {
				var px = xAt( peakIndex ), py = yAt( peakValue );
				var flagText = "Pic · " + num( peakValue );
				var flagW = 14 + flagText.length * 6.6;
				var fx = Math.max( pad.l, Math.min( width - pad.r - flagW, px - flagW / 2 ) );
				el( "line", { class: "cu-ac-peak-line", x1: px.toFixed( 1 ), x2: px.toFixed( 1 ), y1: ( py - 6 ).toFixed( 1 ), y2: "26" }, marks );
				el( "rect", { class: "cu-ac-peak-flag", x: fx.toFixed( 1 ), y: "6", width: flagW.toFixed( 1 ), height: "20", rx: "10" }, marks );
				var flag = el( "text", { class: "cu-ac-peak-text", x: ( fx + flagW / 2 ).toFixed( 1 ), y: "20", "text-anchor": "middle" }, marks );
				flag.textContent = flagText;
			}
			var lastIndex = count - 1;
			var lastValue = stacks[ lastIndex ].length ? stacks[ lastIndex ][ stacks[ lastIndex ].length - 1 ] : 0;
			var tx = xAt( lastIndex ), ty = yAt( lastValue );
			el( "circle", { class: "cu-ac-pulse", cx: tx.toFixed( 1 ), cy: ty.toFixed( 1 ), r: "5" }, marks );
			el( "circle", { class: "cu-ac-today", cx: tx.toFixed( 1 ), cy: ty.toFixed( 1 ), r: "4.5" }, marks );

			// Curseur : bande du jour, ligne verticale, points de chaque couche.
			var cursor = el( "g", { class: "cu-ac-cursor", visibility: "hidden" }, svg );
			el( "rect", { class: "cu-ac-band", x: "0", y: pad.t, width: Math.max( 8, step ).toFixed( 1 ), height: plotH, rx: "8" }, cursor );
			el( "line", { class: "cu-ac-crosshair", x1: "0", x2: "0", y1: pad.t - 4, y2: pad.t + plotH }, cursor );
			layers.forEach( function ( layer ) {
				el( "circle", { class: "cu-ac-dot-point", r: "4", stroke: layer.color, cx: "0", cy: "0" }, cursor );
			} );
			el( "circle", { class: "cu-ac-dot-total", r: "6", cx: "0", cy: "0" }, cursor );

			// Zone de capture du pointeur.
			el( "rect", { class: "cu-ac-hit", x: pad.l - Math.max( 8, step / 2 ), y: "0", width: plotW + Math.max( 16, step ), height: height }, svg );

			if ( ! state.revealed ) {
				state.revealed = true;
				if ( ! reducedMotion( root ) ) {
					var t0 = null;
					clipRect.setAttribute( "width", "0" );
					var grow = function ( now ) {
						if ( null === t0 ) t0 = now;
						var t = Math.min( 1, ( now - t0 ) / 1100 );
						clipRect.setAttribute( "width", String( width * easeOut( t ) ) );
						if ( t < 1 ) window.requestAnimationFrame( grow );
					};
					window.requestAnimationFrame( grow );
				}
			}
		}

		// --- Curseur & infobulle -----------------------------------------------
		function indexFromEvent( ev ) {
			var geo = state.geometry;
			if ( ! geo ) return -1;
			var rect = svg.getBoundingClientRect();
			var x = ( ev.clientX - rect.left ) * ( state.width / rect.width );
			if ( geo.count < 2 ) return 0;
			return Math.max( 0, Math.min( geo.count - 1, Math.round( ( x - geo.pad.l ) / geo.step ) ) );
		}

		function setActive( n, keepDetail ) {
			var geo = state.geometry;
			if ( ! geo || ! state.data || n < 0 ) return;
			state.active = n;
			var day = state.data.days[ n ];
			var cursor = svg.querySelector( ".cu-ac-cursor" );
			var x = geo.xAt( n );
			cursor.setAttribute( "visibility", "visible" );
			var band = cursor.querySelector( ".cu-ac-band" );
			var bandW = Math.max( 8, geo.step || 24 );
			band.setAttribute( "x", ( x - bandW / 2 ).toFixed( 1 ) );
			band.setAttribute( "width", bandW.toFixed( 1 ) );
			var cross = cursor.querySelector( ".cu-ac-crosshair" );
			cross.setAttribute( "x1", x.toFixed( 1 ) );
			cross.setAttribute( "x2", x.toFixed( 1 ) );
			var stack = geo.stacks[ n ];
			cursor.querySelectorAll( ".cu-ac-dot-point" ).forEach( function ( dot, li ) {
				dot.setAttribute( "cx", x.toFixed( 1 ) );
				dot.setAttribute( "cy", geo.yAt( stack[ li ] ).toFixed( 1 ) );
				dot.setAttribute( "visibility", geo.layers[ li ].values[ n ] > 0 ? "visible" : "hidden" );
			} );
			var totalDot = cursor.querySelector( ".cu-ac-dot-total" );
			totalDot.setAttribute( "cx", x.toFixed( 1 ) );
			totalDot.setAttribute( "cy", geo.yAt( stack.length ? stack[ stack.length - 1 ] : 0 ).toFixed( 1 ) );
			root.classList.add( "is-active" );

			// Grand écran : synthèse à gauche, enquête (heures, IP, origine, cibles) à droite.
			tip.classList.toggle( "is-wide", state.width >= 820 && day.total > 0 );
			tip.innerHTML = '<div class="cu-ac-tip-grid"><div class="cu-ac-tip-main">' + tipHead( day, n ) + tipRules( day, n ) + "</div>" +
				( day.total ? '<div class="cu-ac-tip-detail">' + skeleton() + "</div>" : "" ) + "</div>" + tipFoot();
			tip.hidden = false;
			placeTip( x );
			if ( day.total ) {
				requestDetail( day.date, n, keepDetail );
			}
			announce( day );
		}

		function clearActive() {
			state.active = -1;
			state.pinned = false;
			root.classList.remove( "is-active", "is-pinned" );
			var cursor = svg.querySelector( ".cu-ac-cursor" );
			if ( cursor ) cursor.setAttribute( "visibility", "hidden" );
			tip.hidden = true;
		}

		function placeTip( x ) {
			if ( "static" === window.getComputedStyle( tip ).position ) return; // mobile : panneau sous le graphique
			var tipW = tip.offsetWidth;
			var left = x + 18;
			if ( left + tipW > state.width - 6 ) left = x - 18 - tipW;
			left = Math.max( 6, Math.min( left, state.width - tipW - 6 ) );
			tip.style.left = left + "px";
			tip.style.top = "8px";
		}

		function tipHead( day, n ) {
			var prev = n > 0 ? state.data.days[ n - 1 ].total : null;
			var trend = "";
			if ( null !== prev && day.total > 0 ) {
				trend = prev > 0 ? trendBadge( day.total, prev ) + '<span class="cu-ac-sub">vs veille</span>' : '<span class="cu-ac-new">reprise après un jour calme</span>';
			}
			var today = n === state.data.days.length - 1;
			return '<div class="cu-ac-tip-head"><span class="cu-ac-tip-date">' + esc( dayLong( day.date ) ) + ( today ? ' <span class="cu-ac-today-tag">aujourd’hui</span>' : "" ) + "</span>" +
				'<span class="cu-ac-pin" aria-hidden="true">' + cuAdmin.icon( "pin" ) + " épinglé</span></div>" +
				'<div class="cu-ac-tip-total"><strong>' + num( day.total ) + "</strong> " + ( day.total > 1 ? "attaques bloquées" : "attaque bloquée" ) + "</div>" +
				( trend ? '<div class="cu-ac-tip-trend">' + trend + "</div>" : "" );
		}

		function tipRules( day, n ) {
			if ( ! day.total ) return '<p class="cu-ac-tip-calm">Aucune attaque bloquée ce jour-là.</p>';
			var rows = state.layers.map( function ( layer ) { return { layer: layer, value: layer.values[ n ] }; } )
				.filter( function ( r ) { return r.value > 0; } )
				.sort( function ( p, q ) { return q.value - p.value; } );
			return '<ul class="cu-ac-rules">' + rows.map( function ( r ) {
				var share = pct( r.value, day.total );
				var off = state.hidden[ r.layer.key ] ? " is-off" : "";
				return '<li class="cu-ac-rule' + off + '"><i class="cu-ac-dot" style="background:' + r.layer.color + '"></i>' +
					'<span class="cu-ac-rule-name">' + esc( r.layer.label ) + "</span>" +
					'<b class="cu-ac-rule-n">' + num( r.value ) + "</b>" +
					'<span class="cu-ac-rule-pct">' + share + " %</span>" +
					'<span class="cu-ac-meter"><span style="width:' + share + "%;background:" + r.layer.color + '"></span></span></li>';
			} ).join( "" ) + "</ul>" +
				'<div class="cu-ac-tip-meta">' + cuAdmin.icon( "globe" ) + " " + num( day.unique_ips ) + ( day.unique_ips > 1 ? " IP différentes" : " IP" ) + "</div>";
		}

		function tipFoot() {
			return '<div class="cu-ac-tip-foot">' + ( state.pinned ? "Échap ou clic pour fermer" : "Cliquez pour épingler ce jour" ) + "</div>";
		}

		function skeleton() {
			return '<div class="cu-ac-skel"><span></span><span></span><span></span></div>';
		}

		function requestDetail( date, n, immediate ) {
			window.clearTimeout( state.detailTimer );
			var run = function () {
				if ( ! state.dayCache[ date ] ) {
					state.dayCache[ date ] = opts.loadDay( date ).catch( function ( err ) {
						delete state.dayCache[ date ];
						throw err;
					} );
				}
				state.dayCache[ date ].then( function ( detail ) {
					if ( state.active === n ) fillDetail( detail );
				} ).catch( function () {
					var box = tip.querySelector( ".cu-ac-tip-detail" );
					if ( state.active === n && box ) box.innerHTML = '<p class="cu-ac-sub">Détail indisponible pour ce jour.</p>';
				} );
			};
			if ( state.dayCache[ date ] || immediate ) run();
			else state.detailTimer = window.setTimeout( run, 110 );
		}

		function fillDetail( detail ) {
			var box = tip.querySelector( ".cu-ac-tip-detail" );
			if ( ! box || ! detail ) return;
			var hours = ( detail.hours || [] ).map( function ( v ) { return Number( v ) || 0; } );
			var maxHour = Math.max.apply( null, hours.concat( [ 1 ] ) );
			var peakHour = hours.indexOf( Math.max.apply( null, hours.concat( [ 0 ] ) ) );
			var html = '<div class="cu-ac-sec">Activité heure par heure</div>' +
				'<div class="cu-ac-hours" aria-hidden="true">' + hours.map( function ( v, h ) {
					return '<span class="' + ( h === peakHour && v > 0 ? "is-peak" : "" ) + '" style="height:' + Math.max( 6, Math.round( v / maxHour * 100 ) ) + '%" title="' + h + " h : " + num( v ) + '"></span>';
				} ).join( "" ) + "</div>" +
				'<div class="cu-ac-hours-axis"><span>0 h</span><span>6 h</span><span>12 h</span><span>18 h</span><span>23 h</span></div>' +
				'<p class="cu-ac-sub">' + ( peakHour >= 0 && hours[ peakHour ] > 0 ? "Pointe à " + peakHour + " h (" + num( hours[ peakHour ] ) + ")" : "" ) +
				( detail.first_at ? " · de " + esc( clock( detail.first_at ) ) + " à " + esc( clock( detail.last_at ) ) : "" ) + "</p>";

			if ( detail.top_ips && detail.top_ips.length ) {
				html += '<div class="cu-ac-sec">IP les plus agressives</div><ol class="cu-ac-ips">' + detail.top_ips.slice( 0, 3 ).map( function ( row ) {
					return "<li>" +
						'<span class="cu-ac-ip-top"><code class="cu-ac-ip">' + esc( row.ip ) + "</code>" +
						( row.country ? '<span class="cu-ac-cc" title="' + esc( countryLabel( row.country ) ) + '">' + esc( row.country ) + "</span>" : "" ) +
						'<b class="cu-ac-ip-n">' + num( row.count ) + "</b></span>" +
						'<span class="cu-ac-ip-rule">' + esc( opts.label( row.rule ) ) +
						( row.last_at ? " · dernière à " + esc( clock( row.last_at ) ) : "" ) +
						( row.blocked ? ' <span class="cu-ac-banned">bannie</span>' : "" ) + "</span>" +
						"</li>";
				} ).join( "" ) + "</ol>";
			}
			if ( detail.countries && detail.countries.length ) {
				html += '<div class="cu-ac-sec">Origine</div><div class="cu-ac-countries">' + detail.countries.slice( 0, 4 ).map( function ( c ) {
					return '<span class="cu-ac-country"><span class="cu-ac-cc">' + esc( c.code ) + "</span>" + esc( countryLabel( c.code ) ) + " <b>" + num( c.count ) + "</b></span>";
				} ).join( "" ) + "</div>";
			}
			if ( detail.targets && detail.targets.length ) {
				html += '<div class="cu-ac-sec">Cibles</div><ul class="cu-ac-targets">' + detail.targets.slice( 0, 2 ).map( function ( t ) {
					return '<li><code title="' + esc( t.path ) + '">' + esc( t.path ) + "</code><b>" + num( t.count ) + "</b></li>";
				} ).join( "" ) + "</ul>";
			}
			box.innerHTML = html;
			if ( state.active >= 0 && state.geometry ) placeTip( state.geometry.xAt( state.active ) );
		}

		function announce( day ) {
			window.clearTimeout( state.announceTimer );
			state.announceTimer = window.setTimeout( function () {
				live.textContent = dayLong( day.date ) + " : " + num( day.total ) + ( day.total > 1 ? " attaques bloquées" : " attaque bloquée" ) + ", " + num( day.unique_ips ) + " IP.";
			}, 350 );
		}

		// --- Événements --------------------------------------------------------
		svg.addEventListener( "pointermove", function ( ev ) {
			if ( state.pinned || ! state.data ) return;
			var n = indexFromEvent( ev );
			if ( n !== state.active ) setActive( n );
		} );
		svg.addEventListener( "pointerleave", function () {
			if ( ! state.pinned ) clearActive();
		} );
		svg.addEventListener( "click", function ( ev ) {
			if ( ! state.data ) return;
			var n = indexFromEvent( ev );
			if ( state.pinned && n === state.active ) {
				state.pinned = false;
				root.classList.remove( "is-pinned" );
				setActive( n, true );
				return;
			}
			state.pinned = true;
			root.classList.add( "is-pinned" );
			setActive( n, true );
		} );
		stage.addEventListener( "keydown", function ( ev ) {
			if ( ! state.data ) return;
			var last = state.data.days.length - 1;
			var n = state.active < 0 ? last : state.active;
			if ( "ArrowLeft" === ev.key ) n = Math.max( 0, n - 1 );
			else if ( "ArrowRight" === ev.key ) n = Math.min( last, n + 1 );
			else if ( "Home" === ev.key ) n = 0;
			else if ( "End" === ev.key ) n = last;
			else if ( "Escape" === ev.key ) { clearActive(); return; }
			else if ( "Enter" === ev.key || " " === ev.key ) {
				ev.preventDefault();
				state.pinned = ! state.pinned;
				root.classList.toggle( "is-pinned", state.pinned );
				setActive( n, true );
				return;
			} else return;
			ev.preventDefault();
			setActive( n, true );
		} );
		stage.addEventListener( "focus", function () {
			if ( state.data && state.active < 0 ) setActive( state.data.days.length - 1, true );
		} );
		stage.addEventListener( "blur", function () {
			if ( ! state.pinned ) clearActive();
		} );
		document.addEventListener( "keydown", function ( ev ) {
			if ( "Escape" === ev.key && state.pinned ) clearActive();
		} );
		// Un clic ailleurs sur la page referme le jour épinglé.
		document.addEventListener( "click", function ( ev ) {
			if ( state.pinned && ! root.contains( ev.target ) ) clearActive();
		} );

		// Redessine à la largeur du conteneur (menu WordPress replié, rotation…).
		if ( window.ResizeObserver ) {
			var resizeTimer = null;
			new window.ResizeObserver( function () {
				window.clearTimeout( resizeTimer );
				resizeTimer = window.setTimeout( function () {
					if ( state.data && Math.floor( stage.clientWidth ) !== state.width ) {
						var keep = state.active;
						render();
						if ( keep >= 0 ) setActive( keep, true );
					}
				}, 120 );
			} ).observe( stage );
		}

		// En direct : actualisation périodique tant que l'onglet est visible.
		if ( opts.refreshMs ) {
			window.setInterval( function () {
				if ( ! document.hidden && ! state.pinned ) load( false );
			}, opts.refreshMs );
		}

		load( true );
		return { refresh: function () { return load( false ); } };
	}

	window.cuAttackChart = { mount: mount };
} )();

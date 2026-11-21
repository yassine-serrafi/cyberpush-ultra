/* CyberPush Ultra — graphique de la carte « En bref » (cu-brief-chart.js)
 * Courbe des visites en relief (dégradé « aurora », halo, reflet), période
 * précédente en pointillés, record, conversions et pouls de la tranche en cours.
 * Infobulle riche hors du cadre de la carte (détail chargé à la demande),
 * épinglage, clavier et panneau bas sur mobile. Vanilla JS, sans dépendance.
 * Dépend de cu-admin.js (window.cuAdmin).
 */
( function () {
	"use strict";

	var SVG_NS = "http://www.w3.org/2000/svg";
	var uid = 0;
	var tip = null; // une seule infobulle, partagée par les rendus successifs

	// -------------------------------------------------------------------------
	// Utilitaires
	// -------------------------------------------------------------------------
	function esc( value ) {
		return String( null == value ? "" : value ).replace( /&/g, "&amp;" ).replace( /</g, "&lt;" ).replace( />/g, "&gt;" ).replace( /"/g, "&quot;" );
	}
	function num( value ) {
		return cuAdmin.formatNumber( value );
	}
	function decimal( value, digits ) {
		return ( Math.round( value * Math.pow( 10, digits ) ) / Math.pow( 10, digits ) ).toString().replace( ".", "," );
	}
	function compact( value ) {
		if ( value >= 10000 ) return Math.round( value / 1000 ) + " k";
		if ( value >= 1000 ) return decimal( value / 1000, 1 ) + " k";
		return String( Math.round( value ) );
	}
	function toDate( key ) {
		var parts = String( key || "" ).split( "-" );
		return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] || 1 ) - 1, Number( parts[ 2 ] || 1 ) );
	}
	function capitalize( text ) {
		return text ? text.charAt( 0 ).toUpperCase() + text.slice( 1 ) : text;
	}
	function el( tag, attrs, parent ) {
		var node = document.createElementNS( SVG_NS, tag );
		Object.keys( attrs || {} ).forEach( function ( key ) { node.setAttribute( key, attrs[ key ] ); } );
		if ( parent ) parent.appendChild( node );
		return node;
	}
	function reducedMotion( node ) {
		var wrap = node.closest( ".cu-wrap" );
		if ( wrap && "off" === wrap.getAttribute( "data-cu-motion" ) ) return true;
		return !! ( window.matchMedia && window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches );
	}
	function isSheet() {
		return !! ( window.matchMedia && window.matchMedia( "(max-width: 600px)" ).matches );
	}
	function easeOut( t ) {
		return 1 - Math.pow( 1 - t, 3 );
	}

	var regionNames = null;
	try { regionNames = new Intl.DisplayNames( [ "fr" ], { type: "region" } ); } catch ( e ) { regionNames = null; }
	function countryLabel( code ) {
		try {
			var name = regionNames ? regionNames.of( code ) : "";
			return name && name !== code ? name : code;
		} catch ( e ) {
			return code;
		}
	}

	// Libellés d'une tranche selon la période.
	function bucketTitle( period, key ) {
		if ( "day" === period ) {
			var hour = Number( key );
			return hour + " h – " + ( hour + 1 ) + " h";
		}
		if ( "year" === period ) {
			return capitalize( toDate( key ).toLocaleDateString( "fr-FR", { month: "long", year: "numeric" } ) );
		}
		return capitalize( toDate( key ).toLocaleDateString( "fr-FR", { weekday: "long", day: "numeric", month: "long" } ) );
	}
	function bucketCompare( period, key ) {
		if ( "day" === period ) return "hier à la même heure";
		if ( "year" === period ) return toDate( key ).toLocaleDateString( "fr-FR", { month: "long", year: "numeric" } );
		return toDate( key ).toLocaleDateString( "fr-FR", { weekday: "long", day: "numeric", month: "long" } );
	}
	function tickLabel( period, key ) {
		if ( "day" === period ) return key + " h";
		if ( "year" === period ) return toDate( key ).toLocaleDateString( "fr-FR", { month: "short" } );
		if ( "week" === period ) return toDate( key ).toLocaleDateString( "fr-FR", { weekday: "short", day: "2-digit" } );
		return toDate( key ).toLocaleDateString( "fr-FR", { day: "2-digit", month: "2-digit" } );
	}
	function currentWord( period ) {
		return { day: "heure en cours", week: "aujourd’hui", month: "aujourd’hui", year: "mois en cours" }[ period ] || "en cours";
	}

	// -------------------------------------------------------------------------
	// Infobulle (portail dans .cu-wrap : jamais rognée par la carte)
	// -------------------------------------------------------------------------
	function getTip( host ) {
		if ( tip && tip.isConnected ) return tip;
		tip = document.createElement( "div" );
		tip.className = "cu-bc-tip";
		tip.hidden = true;
		( host.closest( ".cu-wrap" ) || document.body ).appendChild( tip );
		return tip;
	}

	// -------------------------------------------------------------------------
	// Rendu
	// -------------------------------------------------------------------------
	function render( root, data, opts ) {
		var id = "cu-bc-" + ( ++uid );
		var period = data.period;
		var points = ( data.series || [] ).map( function ( p ) {
			return {
				key: String( p.key ),
				sessions: Number( p.sessions ) || 0,
				pageviews: Number( p.pageviews ) || 0,
				fresh: Number( p.new ) || 0,
				// Indicateurs par visite : visites suivies en continu seulement.
				measured: null == p.measured ? ( Number( p.sessions ) || 0 ) : Number( p.measured ),
				pagesVisit: null == p.pages_visit ? null : Number( p.pages_visit ),
				bounce: null == p.bounce_rate ? null : Number( p.bounce_rate ),
				duration: null == p.avg_duration ? null : Number( p.avg_duration ),
				conversions: Number( p.conversions ) || 0,
				prev: Number( p.prev ) || 0,
				prevKey: String( p.prev_key || "" ),
				future: !! p.future,
			};
		} );
		var lastLive = -1;
		points.forEach( function ( p, n ) { if ( ! p.future ) lastLive = n; } );
		var state = { active: -1, pinned: false, geometry: null, cache: {}, timer: null, announce: null, width: 0, alive: true };
		var bubble = getTip( root );

		root.classList.add( "cu-bc" );
		root.innerHTML =
			'<div class="cu-bc-stage" tabindex="0" role="group" aria-roledescription="graphique" aria-describedby="' + id + '-help" aria-label="Visites ' + esc( data.period_label || "" ) + ', tranche par tranche">' +
				'<svg class="cu-bc-svg" aria-hidden="true" focusable="false"></svg>' +
			"</div>" +
			'<div class="cu-bc-foot">' +
				'<span class="cu-bc-key"><i class="cu-bc-key-line"></i>Cette période</span>' +
				'<span class="cu-bc-key"><i class="cu-bc-key-partial"></i>Tranche en cours</span>' +
				'<span class="cu-bc-key"><i class="cu-bc-key-ghost"></i>Période précédente</span>' +
				'<span class="cu-bc-key"><i class="cu-bc-key-conv"></i>Conversions déclarées</span>' +
				'<span class="cu-bc-help" id="' + id + '-help">Survolez pour le détail · cliquez pour épingler · ← → au clavier</span>' +
			"</div>" +
			'<p class="screen-reader-text" aria-live="polite"></p>' +
			'<div class="screen-reader-text"><table></table></div>';
		var stage = root.querySelector( ".cu-bc-stage" );
		var svg = root.querySelector( ".cu-bc-svg" );
		var live = root.querySelector( "p.screen-reader-text" );
		root.querySelector( "table" ).innerHTML = "<caption>Visites par tranche, comparées à la période précédente</caption><thead><tr><th scope=\"col\">Tranche</th><th scope=\"col\">Visites</th><th scope=\"col\">Période précédente</th><th scope=\"col\">Pages vues</th><th scope=\"col\">Conversions déclarées</th></tr></thead><tbody>" +
			points.filter( function ( p ) { return ! p.future; } ).map( function ( p ) {
				return '<tr><th scope="row">' + esc( bucketTitle( period, p.key ) ) + "</th><td>" + num( p.sessions ) + "</td><td>" + num( p.prev ) + "</td><td>" + num( p.pageviews ) + "</td><td>" + num( p.conversions ) + "</td></tr>";
			} ).join( "" ) + "</tbody>";

		function draw( animate ) {
			var width = Math.max( 260, Math.floor( stage.clientWidth ) );
			var height = width < 560 ? 170 : 214;
			state.width = width;
			while ( svg.firstChild ) svg.removeChild( svg.firstChild );
			svg.setAttribute( "viewBox", "0 0 " + width + " " + height );
			svg.setAttribute( "width", width );
			svg.setAttribute( "height", height );
			if ( ! points.length ) return;

			var pad = { l: 40, r: 16, t: 28, b: 26 };
			var plotW = width - pad.l - pad.r;
			var plotH = height - pad.t - pad.b;
			var count = points.length;
			var step = count > 1 ? plotW / ( count - 1 ) : 0;
			var peak = points.reduce( function ( m, p ) { return Math.max( m, p.future ? 0 : p.sessions, p.prev ); }, 0 );
			var scale = cuAdmin.niceScale( peak, 3 );
			var yMax = scale.max;
			function xAt( n ) { return pad.l + ( count > 1 ? n * step : plotW / 2 ); }
			function yAt( v ) { return pad.t + plotH - ( v / yMax ) * plotH; }
			state.geometry = { pad: pad, plotH: plotH, step: step, count: count, xAt: xAt, yAt: yAt, height: height };

			var accent = cuAdmin.cssVar( "--cu-accent", "#3858e9" );
			var teal = cuAdmin.cssVar( "--cu-accent-2", "#0fb5c9" );
			var mint = cuAdmin.cssVar( "--cu-mint", "#34d399" );

			var defs = el( "defs", {}, svg );
			var fill = el( "linearGradient", { id: id + "-fill", x1: "0", y1: "0", x2: "0", y2: "1" }, defs );
			el( "stop", { offset: "0%", "stop-color": accent, "stop-opacity": "0.55" }, fill );
			el( "stop", { offset: "55%", "stop-color": teal, "stop-opacity": "0.22" }, fill );
			el( "stop", { offset: "100%", "stop-color": teal, "stop-opacity": "0" }, fill );
			var stroke = el( "linearGradient", { id: id + "-stroke", x1: "0", y1: "0", x2: "1", y2: "0" }, defs );
			el( "stop", { offset: "0%", "stop-color": accent }, stroke );
			el( "stop", { offset: "60%", "stop-color": teal }, stroke );
			el( "stop", { offset: "100%", "stop-color": mint }, stroke );
			var sheen = el( "linearGradient", { id: id + "-sheen", x1: "0", y1: "0", x2: "0", y2: "1" }, defs );
			el( "stop", { offset: "0%", "stop-color": "#ffffff", "stop-opacity": "0.5" }, sheen );
			el( "stop", { offset: "30%", "stop-color": "#ffffff", "stop-opacity": "0" }, sheen );
			var glow = el( "filter", { id: id + "-glow", x: "-5%", y: "-40%", width: "110%", height: "180%" }, defs );
			el( "feGaussianBlur", { stdDeviation: "5" }, glow );
			var clip = el( "clipPath", { id: id + "-clip" }, defs );
			var clipRect = el( "rect", { x: "0", y: "0", width: String( width ), height: String( height ) }, clip );

			var back = el( "g", { class: "cu-bc-back" }, svg );
			for ( var g = 0; g <= 3; g++ ) {
				var gv = scale.step * g;
				var gy = yAt( gv );
				el( "line", { class: 0 === g ? "cu-bc-axis" : "cu-bc-grid", x1: pad.l, x2: width - pad.r, y1: gy.toFixed( 1 ), y2: gy.toFixed( 1 ) }, back );
				var ylabel = el( "text", { class: "cu-bc-ylabel", x: pad.l - 9, y: ( gy + 4 ).toFixed( 1 ), "text-anchor": "end" }, back );
				ylabel.textContent = compact( gv );
			}
			// Graduations : toutes les 3 h (jour) ; sinon alignées sur la dernière tranche,
			// assez espacées pour ne jamais se chevaucher.
			var every = "day" === period ? ( width < 560 ? 6 : 3 ) : Math.max( 1, Math.ceil( 56 / Math.max( step, 1 ) ) );
			points.forEach( function ( p, n ) {
				var shown = "day" === period ? 0 === n % every : 0 === ( count - 1 - n ) % every;
				if ( ! shown ) return;
				var anchor = 0 === n ? "start" : ( n === count - 1 ? "end" : "middle" );
				var tick = el( "text", { class: "cu-bc-xlabel" + ( n === lastLive ? " is-now" : "" ), x: xAt( n ).toFixed( 1 ), y: height - 7, "text-anchor": anchor }, back );
				tick.textContent = tickLabel( period, p.key );
			} );
			if ( "day" === period && lastLive < count - 1 ) {
				// Heures à venir : zone voilée, la courbe s'arrête à l'heure en cours.
				el( "rect", { class: "cu-bc-future", x: ( xAt( lastLive ) + step / 2 ).toFixed( 1 ), y: pad.t, width: Math.max( 0, xAt( count - 1 ) - xAt( lastLive ) - step / 2 + pad.r ).toFixed( 1 ), height: plotH }, back );
			}

			// Période précédente : fantôme en pointillés (toutes les tranches).
			var ghost = points.map( function ( p, n ) { return [ xAt( n ), yAt( p.prev ) ]; } );
			el( "path", { class: "cu-bc-ghost", d: cuAdmin.smoothPath( ghost ) }, svg );

			// Période en cours : aire en relief + ligne « aurora » avec halo.
			var plot = el( "g", { class: "cu-bc-plot", "clip-path": "url(#" + id + "-clip)" }, svg );
			var livePts = points.slice( 0, lastLive + 1 ).map( function ( p, n ) { return [ xAt( n ), yAt( p.sessions ) ]; } );
			if ( livePts.length ) {
				var line = cuAdmin.smoothPath( livePts );
				var base = yAt( 0 ).toFixed( 1 );
				var area = line + "L" + livePts[ livePts.length - 1 ][ 0 ].toFixed( 1 ) + "," + base + "L" + livePts[ 0 ][ 0 ].toFixed( 1 ) + "," + base + "Z";
				el( "path", { class: "cu-bc-area", d: area, fill: "url(#" + id + "-fill)" }, plot );
				el( "path", { class: "cu-bc-sheen", d: area, fill: "url(#" + id + "-sheen)" }, plot );
				// La tranche en cours est incomplète : son dernier segment est en pointillés
				// (sinon un mois commencé il y a 7 jours ressemble à une chute du trafic).
				var segments = line.split( "C" );
				var solid = livePts.length > 1 ? segments.slice( 0, segments.length - 1 ).join( "C" ) : line;
				if ( livePts.length > 1 ) {
					var from = livePts[ livePts.length - 2 ];
					el( "path", { class: "cu-bc-line-partial", d: "M" + from[ 0 ].toFixed( 1 ) + "," + from[ 1 ].toFixed( 1 ) + "C" + segments[ segments.length - 1 ], stroke: "url(#" + id + "-stroke)" }, plot );
				}
				if ( livePts.length > 2 || 1 === livePts.length ) {
					el( "path", { class: "cu-bc-line-glow", d: solid, stroke: accent, filter: "url(#" + id + "-glow)" }, plot );
					el( "path", { class: "cu-bc-line", d: solid, stroke: "url(#" + id + "-stroke)" }, plot );
				}
			}

			// Conversions : perles menthe sur la courbe, taille selon le nombre.
			var marks = el( "g", { class: "cu-bc-marks", "clip-path": "url(#" + id + "-clip)" }, svg );
			points.forEach( function ( p, n ) {
				if ( p.future || ! p.conversions ) return;
				var r = Math.min( 7, 3 + Math.log( p.conversions + 1 ) / Math.LN2 );
				el( "circle", { class: "cu-bc-conv", cx: xAt( n ).toFixed( 1 ), cy: yAt( p.sessions ).toFixed( 1 ), r: r.toFixed( 1 ) }, marks );
			} );

			// Record de la période et pouls de la tranche en cours.
			var best = -1;
			points.forEach( function ( p, n ) { if ( ! p.future && p.sessions > 0 && ( best < 0 || p.sessions > points[ best ].sessions ) ) best = n; } );
			var flags = el( "g", { class: "cu-bc-flags" }, svg );
			if ( best >= 0 ) {
				var bx = xAt( best ), by = yAt( points[ best ].sessions );
				var text = "Record · " + num( points[ best ].sessions );
				var fw = 16 + text.length * 6.4;
				var fx = Math.max( pad.l, Math.min( width - pad.r - fw, bx - fw / 2 ) );
				el( "line", { class: "cu-bc-record-line", x1: bx.toFixed( 1 ), x2: bx.toFixed( 1 ), y1: ( by - 7 ).toFixed( 1 ), y2: "24" }, flags );
				el( "rect", { class: "cu-bc-record", x: fx.toFixed( 1 ), y: "4", width: fw.toFixed( 1 ), height: "20", rx: "10" }, flags );
				var label = el( "text", { class: "cu-bc-record-text", x: ( fx + fw / 2 ).toFixed( 1 ), y: "18", "text-anchor": "middle" }, flags );
				label.textContent = text;
			}
			if ( lastLive >= 0 ) {
				var nx = xAt( lastLive ).toFixed( 1 ), ny = yAt( points[ lastLive ].sessions ).toFixed( 1 );
				el( "circle", { class: "cu-bc-pulse", cx: nx, cy: ny, r: "5" }, flags );
				el( "circle", { class: "cu-bc-now", cx: nx, cy: ny, r: "4.5" }, flags );
			}

			var cursor = el( "g", { class: "cu-bc-cursor", visibility: "hidden" }, svg );
			el( "rect", { class: "cu-bc-band", x: "0", y: pad.t, width: Math.max( 10, step ).toFixed( 1 ), height: plotH, rx: "9" }, cursor );
			el( "line", { class: "cu-bc-cross", x1: "0", x2: "0", y1: pad.t - 2, y2: pad.t + plotH }, cursor );
			el( "circle", { class: "cu-bc-ghost-dot", r: "4", cx: "0", cy: "0" }, cursor );
			el( "circle", { class: "cu-bc-dot", r: "6.5", cx: "0", cy: "0" }, cursor );
			el( "rect", { class: "cu-bc-hit", x: pad.l - Math.max( 8, step / 2 ), y: "0", width: plotW + Math.max( 16, step ), height: height }, svg );

			if ( animate && ! reducedMotion( root ) ) {
				var t0 = null;
				clipRect.setAttribute( "width", "0" );
				var grow = function ( now ) {
					if ( ! state.alive ) return;
					if ( null === t0 ) t0 = now;
					var t = Math.min( 1, ( now - t0 ) / 950 );
					clipRect.setAttribute( "width", String( width * easeOut( t ) ) );
					if ( t < 1 ) window.requestAnimationFrame( grow );
				};
				window.requestAnimationFrame( grow );
			}
		}

		// --- Infobulle ---------------------------------------------------------
		function metric( label, value ) {
			return '<div class="cu-bc-m"><span>' + esc( label ) + "</span><b>" + value + "</b></div>";
		}

		function setActive( n, immediate ) {
			var geo = state.geometry;
			if ( ! geo || n < 0 ) return;
			n = Math.min( n, lastLive );
			if ( n < 0 ) return;
			state.active = n;
			var p = points[ n ];
			var x = geo.xAt( n );
			var cursor = svg.querySelector( ".cu-bc-cursor" );
			cursor.setAttribute( "visibility", "visible" );
			var band = cursor.querySelector( ".cu-bc-band" );
			var bw = Math.max( 10, geo.step || 24 );
			band.setAttribute( "x", ( x - bw / 2 ).toFixed( 1 ) );
			band.setAttribute( "width", bw.toFixed( 1 ) );
			var cross = cursor.querySelector( ".cu-bc-cross" );
			cross.setAttribute( "x1", x.toFixed( 1 ) );
			cross.setAttribute( "x2", x.toFixed( 1 ) );
			var dot = cursor.querySelector( ".cu-bc-dot" );
			dot.setAttribute( "cx", x.toFixed( 1 ) );
			dot.setAttribute( "cy", geo.yAt( p.sessions ).toFixed( 1 ) );
			var gdot = cursor.querySelector( ".cu-bc-ghost-dot" );
			gdot.setAttribute( "cx", x.toFixed( 1 ) );
			gdot.setAttribute( "cy", geo.yAt( p.prev ).toFixed( 1 ) );
			root.classList.add( "is-active" );

			var trend = "";
			if ( p.prev > 0 ) {
				var ratio = p.sessions / p.prev;
				trend = ( ratio >= 10 ? '<span class="cu-kpi-delta up">▲ ×' + num( Math.round( ratio ) ) + "</span>" : cuAdmin.deltaBadge( ( p.sessions - p.prev ) / p.prev * 100 ) ) +
					'<span class="cu-bc-vs">vs ' + esc( bucketCompare( period, p.prevKey ) ) + " (" + num( p.prev ) + ")</span>";
			} else if ( p.sessions > 0 ) {
				trend = '<span class="cu-bc-vs">aucune visite ' + esc( "day" === period ? "hier à la même heure" : "sur la tranche équivalente" ) + "</span>";
			}
			var head = '<div class="cu-bc-tip-head"><span class="cu-bc-tip-date">' + esc( bucketTitle( period, p.key ) ) + "</span>" +
				( n === lastLive ? '<span class="cu-bc-now-tag">' + esc( currentWord( period ) ) + "</span>" : "" ) +
				'<span class="cu-bc-pin">' + cuAdmin.icon( "pin" ) + " épinglé</span></div>";
			var main = head +
				'<div class="cu-bc-tip-total"><strong>' + num( p.sessions ) + "</strong> " + ( p.sessions > 1 ? "visites" : "visite" ) + "</div>" +
				( trend ? '<div class="cu-bc-tip-trend">' + trend + "</div>" : "" );
			if ( p.sessions > 0 ) {
				main += '<div class="cu-bc-metrics">' +
					metric( "Pages vues", num( p.pageviews ) ) +
					metric( "Pages / visite", null == p.pagesVisit ? "n/d" : decimal( p.pagesVisit, 1 ) ) +
					metric( "Durée moyenne", null === p.duration ? "n/d" : esc( cuAdmin.formatDuration( p.duration ) ) ) +
					metric( "Rebond", null === p.bounce ? "n/d" : decimal( p.bounce, 0 ) + " %" ) +
					metric( "Nouveaux", p.measured ? decimal( p.fresh / p.measured * 100, 0 ) + " %" : "n/d" ) +
					metric( "Conv. déclarées", '<span class="' + ( p.conversions ? "cu-bc-conv-n" : "" ) + '">' + num( p.conversions ) + "</span>" ) +
					"</div>";
			} else {
				main += '<p class="cu-bc-calm">Aucune visite sur cette tranche.</p>';
			}
			bubble.classList.toggle( "is-wide", p.sessions > 0 && ! isSheet() );
			bubble.classList.toggle( "is-pinned", state.pinned );
			bubble.innerHTML = '<div class="cu-bc-tip-grid"><div class="cu-bc-tip-main">' + main + "</div>" +
				( p.sessions > 0 ? '<div class="cu-bc-tip-detail"><div class="cu-bc-skel"><span></span><span></span><span></span><span></span></div></div>' : "" ) + "</div>" +
				'<div class="cu-bc-tip-foot">' + ( state.pinned ? "Échap ou clic ailleurs pour fermer" : "Cliquez pour épingler cette tranche" ) + "</div>";
			bubble.hidden = false;
			place();
			if ( p.sessions > 0 ) requestDetail( p.key, n, immediate );
			window.clearTimeout( state.announce );
			state.announce = window.setTimeout( function () {
				live.textContent = bucketTitle( period, p.key ) + " : " + num( p.sessions ) + " visites, " + num( p.pageviews ) + " pages vues, " + num( p.conversions ) + " conversions.";
			}, 350 );
		}

		function place() {
			if ( bubble.hidden || state.active < 0 || ! state.geometry ) return;
			if ( isSheet() ) {
				bubble.style.left = "";
				bubble.style.top = "";
				return;
			}
			var rect = svg.getBoundingClientRect();
			var scale = rect.width / state.width;
			var px = rect.left + state.geometry.xAt( state.active ) * scale;
			var tw = bubble.offsetWidth, th = bubble.offsetHeight;
			var left = px + 22;
			if ( left + tw > window.innerWidth - 12 ) left = px - 22 - tw;
			left = Math.max( 12, Math.min( left, window.innerWidth - tw - 12 ) );
			var top = rect.top - 8;
			// Jamais sous la barre d'administration WordPress (32 px, 46 px sur mobile).
			var bar = document.getElementById( "wpadminbar" );
			var minTop = ( bar ? bar.getBoundingClientRect().bottom : 0 ) + 10;
			top = Math.max( minTop, Math.min( top, window.innerHeight - th - 12 ) );
			bubble.style.left = Math.round( left ) + "px";
			bubble.style.top = Math.round( top ) + "px";
		}

		function hide() {
			state.active = -1;
			state.pinned = false;
			root.classList.remove( "is-active", "is-pinned" );
			var cursor = svg.querySelector( ".cu-bc-cursor" );
			if ( cursor ) cursor.setAttribute( "visibility", "hidden" );
			bubble.hidden = true;
			bubble.classList.remove( "is-pinned" );
		}

		function requestDetail( key, n, immediate ) {
			window.clearTimeout( state.timer );
			var run = function () {
				if ( ! state.cache[ key ] ) {
					state.cache[ key ] = opts.loadPoint( period, key ).catch( function ( err ) {
						delete state.cache[ key ];
						throw err;
					} );
				}
				state.cache[ key ].then( function ( detail ) {
					if ( state.alive && state.active === n ) fillDetail( detail );
				} ).catch( function () {
					var box = bubble.querySelector( ".cu-bc-tip-detail" );
					if ( state.active === n && box ) box.innerHTML = '<p class="cu-bc-vs">Détail indisponible.</p>';
				} );
			};
			if ( state.cache[ key ] || immediate ) run();
			else state.timer = window.setTimeout( run, 110 );
		}

		function fillDetail( detail ) {
			var box = bubble.querySelector( ".cu-bc-tip-detail" );
			if ( ! box || ! detail ) return;
			var html = "";
			// Valeurs du serveur normalisées : nombres réels, textes échappés plus bas.
			var sources = ( detail.sources || [] ).map( function ( row ) { return { source: String( row.source || "" ), count: Number( row.count ) || 0 }; } );
			var srcTotal = sources.reduce( function ( sum, row ) { return sum + row.count; }, 0 );
			if ( sources.length ) {
				var colors = cuAdmin.vizColors();
				html += '<div class="cu-bc-sec">Sources</div><ul class="cu-bc-bars">' + sources.map( function ( row, i ) {
					var share = srcTotal ? Math.round( row.count / srcTotal * 100 ) : 0;
					return '<li><span class="cu-bc-bar-name">' + esc( opts.sourceLabel( row.source ) ) + "</span><b>" + share + ' %</b><span class="cu-bc-bar"><span style="width:' + share + "%;background:" + colors[ i % colors.length ] + '"></span></span></li>';
				} ).join( "" ) + "</ul>";
			}
			if ( detail.pages && detail.pages.length ) {
				html += '<div class="cu-bc-sec">Pages les plus vues</div><ol class="cu-bc-pages">' + detail.pages.slice( 0, 3 ).map( function ( page ) {
					var name = page.title || ( "/" === page.path ? "Page d’accueil" : page.path );
					return '<li><span class="cu-bc-page" title="' + esc( page.path ) + '">' + esc( name ) + "</span><b>" + num( page.count ) + "</b></li>";
				} ).join( "" ) + "</ol>";
			}
			if ( detail.countries && detail.countries.length ) {
				html += '<div class="cu-bc-sec">Pays</div><div class="cu-bc-chips">' + detail.countries.slice( 0, 3 ).map( function ( c ) {
					return '<span class="cu-bc-chip"><span class="cu-bc-cc">' + esc( c.code ) + "</span>" + esc( countryLabel( c.code ) ) + " <b>" + num( c.count ) + "</b></span>";
				} ).join( "" ) + "</div>";
			}
			var devices = ( detail.devices || [] ).map( function ( row ) { return { device: String( row.device || "" ).toLowerCase(), count: Number( row.count ) || 0 }; } );
			var devTotal = devices.reduce( function ( sum, row ) { return sum + row.count; }, 0 );
			if ( devTotal ) {
				var palette = { mobile: cuAdmin.cssVar( "--cu-accent", "#3858e9" ), desktop: cuAdmin.cssVar( "--cu-accent-2", "#0fb5c9" ), tablet: cuAdmin.cssVar( "--cu-mint", "#34d399" ) };
				html += '<div class="cu-bc-sec">Appareils</div><div class="cu-bc-split">' + devices.map( function ( row ) {
					return '<span style="flex:' + row.count + ";background:" + ( palette[ row.device ] || "#8a93ad" ) + '"></span>';
				} ).join( "" ) + '</div><div class="cu-bc-split-key">' + devices.map( function ( row ) {
					return '<span><i style="background:' + ( palette[ row.device ] || "#8a93ad" ) + '"></i>' + esc( opts.deviceLabel( row.device ) ) + " " + Math.round( row.count / devTotal * 100 ) + " %</span>";
				} ).join( "" ) + "</div>";
			}
			box.innerHTML = html || '<p class="cu-bc-vs">Pas de détail pour cette tranche.</p>';
			place();
		}

		// --- Événements --------------------------------------------------------
		function indexAt( ev ) {
			var geo = state.geometry;
			if ( ! geo ) return -1;
			var rect = svg.getBoundingClientRect();
			var x = ( ev.clientX - rect.left ) * ( state.width / rect.width );
			if ( geo.count < 2 ) return 0;
			return Math.max( 0, Math.min( geo.count - 1, Math.round( ( x - geo.pad.l ) / geo.step ) ) );
		}
		var handlers = {
			move: function ( ev ) {
				if ( state.pinned ) return;
				var n = Math.min( indexAt( ev ), lastLive );
				if ( n !== state.active ) setActive( n );
			},
			leave: function () { if ( ! state.pinned ) hide(); },
			click: function ( ev ) {
				var n = Math.min( indexAt( ev ), lastLive );
				if ( state.pinned && n === state.active ) {
					state.pinned = false;
					root.classList.remove( "is-pinned" );
				} else {
					state.pinned = true;
					root.classList.add( "is-pinned" );
				}
				setActive( n, true );
			},
			key: function ( ev ) {
				var n = state.active < 0 ? lastLive : state.active;
				if ( "ArrowLeft" === ev.key ) n = Math.max( 0, n - 1 );
				else if ( "ArrowRight" === ev.key ) n = Math.min( lastLive, n + 1 );
				else if ( "Home" === ev.key ) n = 0;
				else if ( "End" === ev.key ) n = lastLive;
				else if ( "Escape" === ev.key ) { hide(); return; }
				else if ( "Enter" === ev.key || " " === ev.key ) {
					ev.preventDefault();
					state.pinned = ! state.pinned;
					root.classList.toggle( "is-pinned", state.pinned );
					setActive( n, true );
					return;
				} else return;
				ev.preventDefault();
				setActive( n, true );
			},
			focus: function () { if ( state.active < 0 && lastLive >= 0 ) setActive( lastLive, true ); },
			blur: function () { if ( ! state.pinned ) hide(); },
			docKey: function ( ev ) { if ( "Escape" === ev.key && state.pinned ) hide(); },
			docClick: function ( ev ) { if ( state.pinned && ! root.contains( ev.target ) && ! bubble.contains( ev.target ) ) hide(); },
			scroll: function () { if ( state.pinned ) place(); else if ( state.active >= 0 ) hide(); },
		};
		svg.addEventListener( "pointermove", handlers.move );
		svg.addEventListener( "pointerleave", handlers.leave );
		svg.addEventListener( "click", handlers.click );
		stage.addEventListener( "keydown", handlers.key );
		stage.addEventListener( "focus", handlers.focus );
		stage.addEventListener( "blur", handlers.blur );
		document.addEventListener( "keydown", handlers.docKey );
		document.addEventListener( "click", handlers.docClick );
		window.addEventListener( "scroll", handlers.scroll, { passive: true } );

		var observer = null;
		if ( window.ResizeObserver ) {
			var resizeTimer = null;
			observer = new window.ResizeObserver( function () {
				window.clearTimeout( resizeTimer );
				resizeTimer = window.setTimeout( function () {
					if ( state.alive && Math.floor( stage.clientWidth ) !== state.width ) {
						var keep = state.active;
						draw( false );
						if ( keep >= 0 ) setActive( keep, true );
					}
				}, 120 );
			} );
			observer.observe( stage );
		}

		draw( true );

		return {
			destroy: function () {
				state.alive = false;
				hide();
				if ( observer ) observer.disconnect();
				document.removeEventListener( "keydown", handlers.docKey );
				document.removeEventListener( "click", handlers.docClick );
				window.removeEventListener( "scroll", handlers.scroll );
			},
		};
	}

	window.cuBriefChart = { render: render };
} )();

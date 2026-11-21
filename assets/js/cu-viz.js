/* =============================================================================
   CyberPush Ultra — cu-viz.js : moteur de visualisation « Observatory ».
   Canvas rétina, animations rAF, tooltips. Aucune dépendance externe.
   Expose window.cuViz : areaChart, sparkline, ringGauge, countUp, reveal, fillRanks.
   Respecte prefers-reduced-motion.
   ============================================================================= */
( function () {
	"use strict";

	// wp_localize_script() transmet les booléens en chaînes ("1" / "") : une
	// comparaison stricte à false ne désactivait jamais les animations.
	var MOTION_SETTING = window.cuAdminConfig ? window.cuAdminConfig.showAnimations : true;
	var REDUCED = ( window.matchMedia && window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches )
		|| false === MOTION_SETTING || "" === MOTION_SETTING || "0" === MOTION_SETTING || 0 === MOTION_SETTING;

	function cssVar( name, fallback ) {
		try {
			var host = document.querySelector( "#cu-analytics-page" ) || document.documentElement;
			var v = getComputedStyle( host ).getPropertyValue( name );
			return ( v && v.trim() ) || fallback;
		} catch ( e ) { return fallback; }
	}

	function setupCanvas( canvas, w, h ) {
		var ratio = window.devicePixelRatio || 1;
		var pixelWidth = Math.round( w * ratio );
		var pixelHeight = Math.round( h * ratio );
		// Réaffecter width/height modifie la taille intrinsèque du canvas et
		// force un recalcul de mise en page. Au survol, cela provoquait des
		// micro-sauts sur certains navigateurs et écrans Retina.
		if ( canvas.width !== pixelWidth ) canvas.width = pixelWidth;
		if ( canvas.height !== pixelHeight ) canvas.height = pixelHeight;
		var ctx = canvas.getContext( "2d" );
		ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
		ctx.clearRect( 0, 0, w, h );
		return ctx;
	}

	// Redessin au redimensionnement : sans lui, un canvas garde sa taille en
	// pixels et apparaît étiré/flou (fenêtre redimensionnée, menu WP replié).
	var tracked = [];
	var pendingRedraw = [];
	var redrawTimer = 0;
	var resizeObserver = window.ResizeObserver ? new ResizeObserver( function ( entries ) {
		entries.forEach( function ( entry ) { scheduleRedraw( entry.target ); } );
	} ) : null;

	function canvasSize( canvas ) {
		return canvas.clientWidth + "x" + canvas.clientHeight;
	}

	function scheduleRedraw( canvas ) {
		if ( -1 === pendingRedraw.indexOf( canvas ) ) pendingRedraw.push( canvas );
		clearTimeout( redrawTimer );
		redrawTimer = setTimeout( function () {
			var list = pendingRedraw;
			pendingRedraw = [];
			list.forEach( function ( c ) {
				if ( ! c.isConnected || ! c.__cuRedraw || canvasSize( c ) === c.__cuSize ) return;
				c.__cuRedraw();
			} );
			tracked = tracked.filter( function ( c ) { return c.isConnected; } );
		}, 150 );
	}

	function trackForRedraw( canvas, redraw ) {
		canvas.__cuRedraw = redraw;
		canvas.__cuSize = canvasSize( canvas );
		if ( -1 === tracked.indexOf( canvas ) ) {
			tracked.push( canvas );
			if ( resizeObserver ) resizeObserver.observe( canvas );
		}
	}

	if ( ! resizeObserver ) {
		window.addEventListener( "resize", function () { tracked.forEach( scheduleRedraw ); } );
	}

	// Plafond « rond » d'une échelle découpée en `steps` graduations (1, 2, 2,5 ou 5 × 10^n).
	function niceCeil( value, steps ) {
		var raw = Math.max( value, 1 ) / steps;
		var mag = Math.pow( 10, Math.floor( Math.log( raw ) / Math.LN10 ) );
		var nice = [ 1, 2, 2.5, 5, 10 ].map( function ( m ) { return m * mag; } ).filter( function ( v ) { return v >= raw; } )[ 0 ];
		return nice * steps;
	}

	var compactFormat = null;
	try { compactFormat = new Intl.NumberFormat( "fr-FR", { notation: "compact", maximumFractionDigits: 1 } ); } catch ( e ) { compactFormat = null; }
	function compactNumber( v ) {
		v = Math.round( Number( v ) || 0 );
		return compactFormat ? compactFormat.format( v ) : String( v );
	}

	function easeOut( t ) { return 1 - Math.pow( 1 - t, 3 ); }

	function animate( duration, onFrame, onDone ) {
		if ( REDUCED || ! duration ) { onFrame( 1 ); if ( onDone ) onDone(); return; }
		var start = null;
		function step( ts ) {
			if ( null === start ) start = ts;
			var p = Math.min( 1, ( ts - start ) / duration );
			onFrame( easeOut( p ) );
			if ( p < 1 ) { requestAnimationFrame( step ); }
			else if ( onDone ) { onDone(); }
		}
		requestAnimationFrame( step );
	}

	// -------------------------------------------------------------------------
	// AREA CHART — dégradé, dessin animé, crosshair + tooltip au survol.
	// opts : { labels:[], data:[], color, fmt(fn), duration }
	// -------------------------------------------------------------------------
	function areaChart( canvas, opts ) {
		if ( ! canvas ) return;
		opts = opts || {};
		trackForRedraw( canvas, function () { areaChart( canvas, Object.assign( {}, opts, { instant: true } ) ); } );
		var data = ( opts.data || [] ).map( Number );
		var labels = opts.labels || [];
		var fmt = opts.fmt || function ( v ) { return String( v ); };
		var w = canvas.clientWidth || 600, h = canvas.clientHeight || 220;
		var color1 = opts.color || cssVar( "--obs-a1", "#3858e9" );
		var color2 = cssVar( "--obs-a2", "#0b8a9a" );
		var grid = cssVar( "--cu-panel-border", "#e2e7f5" );
		var labelColor = cssVar( "--cu-text-faint", "#5f6788" );

		// Échelle « ronde » (0, 50, 100, 150…) : une courbe sans graduation ne
		// permet pas de lire la moindre valeur.
		var min = Math.min.apply( null, data.concat( [ 0 ] ) );
		var max = niceCeil( Math.max.apply( null, data.concat( [ 1 ] ) ), 4 );
		var range = ( max - min ) || 1;
		var axisFmt = opts.axisFmt || compactNumber;
		var axisFont = "10px " + cssVar( "--cu-font", "sans-serif" );
		var measureCtx = canvas.getContext( "2d" );
		measureCtx.font = axisFont;
		var axisW = Math.ceil( Math.max( measureCtx.measureText( axisFmt( max ) ).width, measureCtx.measureText( axisFmt( min ) ).width ) );
		var pad = { t: 14, r: 14, b: 22, l: axisW + 12 };
		var n = data.length;
		var innerW = w - pad.l - pad.r, innerH = h - pad.t - pad.b;
		var stepX = innerW / Math.max( n - 1, 1 );

		var pts = data.map( function ( v, i ) {
			return {
				x: pad.l + i * stepX,
				y: pad.t + innerH - ( ( v - min ) / range ) * innerH,
				v: v,
				label: labels[ i ] != null ? labels[ i ] : ""
			};
		} );

		function draw( progress, hoverIdx ) {
			var ctx = setupCanvas( canvas, w, h );
			if ( ! n ) return;

			// grille horizontale
			ctx.strokeStyle = grid; ctx.lineWidth = 1;
			ctx.fillStyle = labelColor; ctx.font = axisFont; ctx.textAlign = "right"; ctx.textBaseline = "middle";
			for ( var g = 0; g <= 4; g++ ) {
				var gy = pad.t + ( innerH / 4 ) * g;
				ctx.beginPath(); ctx.moveTo( pad.l, gy ); ctx.lineTo( w - pad.r, gy ); ctx.stroke();
				ctx.fillText( axisFmt( max - ( range / 4 ) * g ), pad.l - 6, gy );
			}
			ctx.textAlign = "left"; ctx.textBaseline = "alphabetic";

			var shown = Math.max( 2, Math.round( n * progress ) );
			var vis = pts.slice( 0, shown );

			// aire dégradée
			var grad = ctx.createLinearGradient( 0, pad.t, 0, h - pad.b );
			grad.addColorStop( 0, hexA( color1, .28 ) );
			grad.addColorStop( 1, hexA( color1, 0 ) );
			ctx.beginPath();
			ctx.moveTo( vis[ 0 ].x, h - pad.b );
			vis.forEach( function ( p ) { ctx.lineTo( p.x, p.y ); } );
			ctx.lineTo( vis[ vis.length - 1 ].x, h - pad.b );
			ctx.closePath();
			ctx.fillStyle = grad; ctx.fill();

			// ligne dégradée
			var lg = ctx.createLinearGradient( pad.l, 0, w - pad.r, 0 );
			lg.addColorStop( 0, color1 ); lg.addColorStop( 1, color2 );
			ctx.beginPath();
			vis.forEach( function ( p, i ) { i ? ctx.lineTo( p.x, p.y ) : ctx.moveTo( p.x, p.y ); } );
			ctx.strokeStyle = lg; ctx.lineWidth = 2.4; ctx.lineJoin = "round"; ctx.lineCap = "round";
			ctx.stroke();

			// point final lumineux
			var last = vis[ vis.length - 1 ];
			ctx.beginPath(); ctx.arc( last.x, last.y, 3.5, 0, Math.PI * 2 );
			ctx.fillStyle = color2; ctx.fill();
			ctx.beginPath(); ctx.arc( last.x, last.y, 7, 0, Math.PI * 2 );
			ctx.fillStyle = hexA( color2, .18 ); ctx.fill();

			// labels X clairsemés
			ctx.fillStyle = labelColor; ctx.font = "10px " + cssVar( "--cu-font", "sans-serif" );
			var stepL = Math.max( Math.floor( n / 6 ), 1 );
			labels.forEach( function ( lb, i ) {
				if ( i % stepL !== 0 ) return;
				ctx.fillText( String( lb ), Math.min( pad.l + i * stepX, w - pad.r - 24 ), h - 6 );
			} );

			// crosshair + point survolé
			if ( hoverIdx != null && pts[ hoverIdx ] ) {
				var hp = pts[ hoverIdx ];
				ctx.strokeStyle = hexA( color1, .35 ); ctx.lineWidth = 1;
				ctx.beginPath(); ctx.moveTo( hp.x, pad.t ); ctx.lineTo( hp.x, h - pad.b ); ctx.stroke();
				ctx.beginPath(); ctx.arc( hp.x, hp.y, 4.5, 0, Math.PI * 2 );
				ctx.fillStyle = "#fff"; ctx.fill();
				ctx.lineWidth = 2.5; ctx.strokeStyle = color1; ctx.stroke();
			}
		}

		animate( REDUCED || opts.instant ? 0 : ( opts.duration || 1100 ), function ( p ) { draw( p ); } );

		// ---- interaction survol ----
		var tip = ensureTip( canvas );
		var hoverFrame = 0;
		var hoverX = 0;
		function drawHover() {
			hoverFrame = 0;
			var rect = canvas.getBoundingClientRect();
			var mx = hoverX - rect.left;
			var idx = Math.round( ( mx - pad.l ) / stepX );
			idx = Math.max( 0, Math.min( n - 1, idx ) );
			draw( 1, idx );
			if ( tip && pts[ idx ] ) {
				tip.innerHTML = "<b>" + fmt( pts[ idx ].v ) + "</b><br>" + escapeHtml( pts[ idx ].label );
				tip.style.left = ( pts[ idx ].x ) + "px";
				tip.style.top = ( pts[ idx ].y - 6 ) + "px";
				tip.classList.add( "on" );
			}
		}
		function onMove( e ) {
			hoverX = e.clientX;
			if ( ! hoverFrame ) hoverFrame = requestAnimationFrame( drawHover );
		}
		function onLeave() {
			if ( hoverFrame ) cancelAnimationFrame( hoverFrame );
			hoverFrame = 0;
			draw( 1 );
			if ( tip ) tip.classList.remove( "on" );
		}
		canvas.onmousemove = onMove;
		canvas.onmouseleave = onLeave;
	}

	// -------------------------------------------------------------------------
	// SPARKLINE — mini courbe sans axes.
	// -------------------------------------------------------------------------
	function sparkline( canvas, data, opts ) {
		if ( ! canvas ) return;
		opts = opts || {};
		var rawData = data;
		trackForRedraw( canvas, function () { sparkline( canvas, rawData, Object.assign( {}, opts, { instant: true } ) ); } );
		data = ( data || [] ).map( Number );
		var w = canvas.clientWidth || 120, h = canvas.clientHeight || 34;
		var color = opts.color || cssVar( "--obs-a1", "#3858e9" );
		// Mini-courbe sans axe : échelle min/max naturelle des données.
		var max = Math.max.apply( null, data.concat( [ 1 ] ) );
		var min = Math.min.apply( null, data.concat( [ 0 ] ) );
		var range = ( max - min ) || 1;
		var n = data.length, stepX = ( w - 4 ) / Math.max( n - 1, 1 );
		var pts = data.map( function ( v, i ) {
			return { x: 2 + i * stepX, y: 2 + ( h - 4 ) - ( ( v - min ) / range ) * ( h - 4 ) };
		} );
		function draw( progress ) {
			var ctx = setupCanvas( canvas, w, h );
			if ( n < 2 ) return;
			var shown = Math.max( 2, Math.round( n * progress ) );
			var vis = pts.slice( 0, shown );
			var g = ctx.createLinearGradient( 0, 0, 0, h );
			g.addColorStop( 0, hexA( color, .22 ) ); g.addColorStop( 1, hexA( color, 0 ) );
			ctx.beginPath(); ctx.moveTo( vis[ 0 ].x, h );
			vis.forEach( function ( p ) { ctx.lineTo( p.x, p.y ); } );
			ctx.lineTo( vis[ vis.length - 1 ].x, h ); ctx.closePath();
			ctx.fillStyle = g; ctx.fill();
			ctx.beginPath();
			vis.forEach( function ( p, i ) { i ? ctx.lineTo( p.x, p.y ) : ctx.moveTo( p.x, p.y ); } );
			ctx.strokeStyle = color; ctx.lineWidth = 1.8; ctx.lineJoin = "round"; ctx.stroke();
		}
		animate( REDUCED || opts.instant ? 0 : ( opts.duration || 800 ), draw );
	}

	// -------------------------------------------------------------------------
	// RING GAUGE — arc animé de 0 → value (%).
	// opts : { value(0-100), color, track }
	// -------------------------------------------------------------------------
	function ringGauge( canvas, value, opts ) {
		if ( ! canvas ) return;
		opts = opts || {};
		var rawValue = value;
		trackForRedraw( canvas, function () { ringGauge( canvas, rawValue, Object.assign( {}, opts, { instant: true } ) ); } );
		value = Math.max( 0, Math.min( 100, Number( value ) || 0 ) );
		var size = Math.min( canvas.clientWidth || 132, canvas.clientHeight || 132 );
		var color1 = opts.color || cssVar( "--obs-a1", "#3858e9" );
		// Couleur de statut imposée (bon / moyen / critique) : arc uni, sans dégradé
		// vers le bleu qui brouillerait le message.
		var color2 = opts.color ? color1 : cssVar( "--obs-a2", "#0b8a9a" );
		// Ne PAS nommer cette variable « track » : elle masquerait trackForRedraw()
		// (hoisting) et ferait planter le rendu de toutes les jauges.
		var trackColor = cssVar( "--cu-bg-soft", "#eef2fb" );
		var cx = size / 2, cy = size / 2, r = size / 2 - 9, lw = 11;
		var startA = -Math.PI / 2;
		function draw( progress ) {
			var ctx = setupCanvas( canvas, size, size );
			ctx.lineCap = "round";
			// piste
			ctx.beginPath(); ctx.arc( cx, cy, r, 0, Math.PI * 2 );
			ctx.strokeStyle = trackColor; ctx.lineWidth = lw; ctx.stroke();
			// arc valeur (dégradé)
			var end = startA + ( value / 100 ) * Math.PI * 2 * progress;
			var g = ctx.createLinearGradient( 0, 0, size, size );
			g.addColorStop( 0, color1 ); g.addColorStop( 1, color2 );
			ctx.beginPath(); ctx.arc( cx, cy, r, startA, end );
			ctx.strokeStyle = g; ctx.lineWidth = lw; ctx.stroke();
		}
		animate( REDUCED || opts.instant ? 0 : ( opts.duration || 1000 ), draw );
	}

	// -------------------------------------------------------------------------
	// COUNT-UP — anime un nombre de 0 (ou valeur actuelle) → to.
	// -------------------------------------------------------------------------
	function countUp( el, to, opts ) {
		if ( ! el ) return;
		opts = opts || {};
		var fmt = opts.fmt || function ( v ) { return Math.round( v ).toLocaleString( "fr-FR" ); };
		var from = Number( opts.from ) || 0;
		to = Number( to ) || 0;
		animate( REDUCED ? 0 : ( opts.duration || 900 ), function ( p ) {
			el.textContent = fmt( from + ( to - from ) * p );
		}, function () { el.textContent = fmt( to ); } );
	}

	// -------------------------------------------------------------------------
	// FILL RANKS — anime les barres .cu-rank-fill (largeur depuis data-pct).
	// -------------------------------------------------------------------------
	function fillRanks( root ) {
		( root || document ).querySelectorAll( ".cu-rank-fill" ).forEach( function ( bar, i ) {
			var pct = Math.max( 0, Math.min( 100, Number( bar.getAttribute( "data-pct" ) ) || 0 ) );
			if ( REDUCED ) { bar.style.width = pct + "%"; return; }
			bar.style.transitionDelay = ( i * 45 ) + "ms";
			requestAnimationFrame( function () {
				requestAnimationFrame( function () { bar.style.width = pct + "%"; } );
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// REVEAL — entrée en cascade des .cu-reveal via IntersectionObserver.
	// -------------------------------------------------------------------------
	function reveal( root ) {
		var items = ( root || document ).querySelectorAll( ".cu-reveal:not(.is-in)" );
		if ( REDUCED || ! ( "IntersectionObserver" in window ) ) {
			items.forEach( function ( el ) { el.classList.add( "is-in" ); } );
			return;
		}
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( en, i ) {
				if ( en.isIntersecting ) {
					var el = en.target;
					el.style.transitionDelay = Math.min( i * 55, 300 ) + "ms";
					el.classList.add( "is-in" );
					io.unobserve( el );
				}
			} );
		}, { threshold: 0.12, rootMargin: "0px 0px -40px 0px" } );
		items.forEach( function ( el ) { io.observe( el ); } );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------
	function ensureTip( canvas ) {
		var host = canvas.closest( ".cu-tile" ) || canvas.parentNode;
		if ( ! host ) return null;
		if ( getComputedStyle( host ).position === "static" ) host.style.position = "relative";
		var tip = host.querySelector( ".cu-viz-tip" );
		if ( ! tip ) {
			tip = document.createElement( "div" );
			tip.className = "cu-viz-tip";
			host.appendChild( tip );
		}
		return tip;
	}

	// #rrggbb → rgba(r,g,b,a) ; passe les couleurs déjà rgba/hsl telles quelles.
	function hexA( c, a ) {
		c = ( c || "" ).trim();
		if ( c.charAt( 0 ) === "#" && ( c.length === 7 || c.length === 4 ) ) {
			var f = c.length === 4;
			var r = parseInt( f ? c[ 1 ] + c[ 1 ] : c.slice( 1, 3 ), 16 );
			var g = parseInt( f ? c[ 2 ] + c[ 2 ] : c.slice( 3, 5 ), 16 );
			var b = parseInt( f ? c[ 3 ] + c[ 3 ] : c.slice( 5, 7 ), 16 );
			return "rgba(" + r + "," + g + "," + b + "," + a + ")";
		}
		return c; // fallback (rgba/hsl/var déjà résolu)
	}

	function escapeHtml( str ) {
		return String( str == null ? "" : str ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}

	window.cuViz = {
		areaChart: areaChart,
		sparkline: sparkline,
		ringGauge: ringGauge,
		countUp: countUp,
		fillRanks: fillRanks,
		reveal: reveal,
		cssVar: cssVar,
		reduced: REDUCED
	};
} )();

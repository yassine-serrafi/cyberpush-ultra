/* CyberPush Ultra — visualisations de la page « Audit SEO » (cu-seo-viz.js)
 * Cadrans en relief (jauge du score global, orbes du centre de santé) et
 * courbe de tendance détaillée : score global, moyenne des pages, erreurs et
 * alertes, objectif 80. Une seule infobulle riche, rendue dans .cu-wrap (jamais
 * rognée par une carte), au survol comme au clavier ; panneau bas sur mobile.
 * Vanilla JS, sans dépendance. Dépend de cu-admin.js (window.cuAdmin).
 */
( function () {
	"use strict";

	var SVG_NS = "http://www.w3.org/2000/svg";
	var TIP_ID = "cu-sx-tip";
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
	function decimal( value, digits ) {
		var factor = Math.pow( 10, digits );
		return ( Math.round( value * factor ) / factor ).toString().replace( ".", "," );
	}
	function clamp( value, low, high ) {
		return Math.max( low, Math.min( high, value ) );
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
	function toDate( key ) {
		var parts = String( key || "" ).split( "-" );
		return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] || 1 ) - 1, Number( parts[ 2 ] || 1 ) );
	}
	function capitalize( text ) {
		return text ? text.charAt( 0 ).toUpperCase() + text.slice( 1 ) : text;
	}
	function longDate( key ) {
		return capitalize( toDate( key ).toLocaleDateString( "fr-FR", { weekday: "long", day: "numeric", month: "long" } ) );
	}
	function shortDate( key ) {
		return toDate( key ).toLocaleDateString( "fr-FR", { day: "2-digit", month: "2-digit" } );
	}

	// -------------------------------------------------------------------------
	// Tons sémantiques — mêmes seuils que la répartition (≥ 80 / 50–79 / < 50)
	// -------------------------------------------------------------------------
	var TONE_VARS = {
		good: [ "--cu-tone-good-2", "#4ade80", "--cu-tone-good", "#0f9d58", "--cu-success", "#007a3d" ],
		mid: [ "--cu-tone-mid-2", "#fcd34d", "--cu-tone-mid", "#e8890c", "--cu-warning", "#8a5a00" ],
		low: [ "--cu-tone-low-2", "#fda4af", "--cu-tone-low", "#e5484d", "--cu-danger", "#c41d1d" ],
		na: [ "--cu-panel-border", "#e2e7f5", "--cu-text-faint", "#5f6788", "--cu-text-faint", "#5f6788" ],
	};
	function tone( score ) {
		if ( null == score || "" === score || isNaN( score ) ) return "na";
		return score >= 80 ? "good" : ( score >= 50 ? "mid" : "low" );
	}
	function toneColors( name ) {
		var v = TONE_VARS[ name ] || TONE_VARS.na;
		return { light: cuAdmin.cssVar( v[ 0 ], v[ 1 ] ), deep: cuAdmin.cssVar( v[ 2 ], v[ 3 ] ), text: cuAdmin.cssVar( v[ 4 ], v[ 5 ] ) };
	}

	// Écart en points (scores) ou en nombre (anomalies) : flèche = sens réel,
	// couleur = bonne ou mauvaise nouvelle.
	function delta( value, lowerIsBetter, unit ) {
		value = Math.round( Number( value ) || 0 );
		// Français : singulier jusqu'à 1 (« 1 pt », « 0 pt »), pluriel à partir de 2.
		var suffix = unit ? " " + ( "pts" === unit && Math.abs( value ) < 2 ? "pt" : unit ) : "";
		if ( 0 === value ) return '<span class="cu-kpi-delta flat">= 0' + suffix + "</span>";
		var good = lowerIsBetter ? value < 0 : value > 0;
		return '<span class="cu-kpi-delta ' + ( good ? "up" : "down" ) + '">' + ( value > 0 ? "▲ +" : "▼ −" ) + num( Math.abs( value ) ) + suffix + "</span>";
	}

	// -------------------------------------------------------------------------
	// Infobulle partagée (portail dans .cu-wrap)
	// -------------------------------------------------------------------------
	var tip = null;
	var tipOwner = null;

	function getTip( host ) {
		if ( tip && tip.isConnected ) return tip;
		tip = document.createElement( "div" );
		tip.id = TIP_ID;
		tip.className = "cu-sx-tip";
		tip.setAttribute( "role", "tooltip" );
		tip.hidden = true;
		( host.closest( ".cu-wrap" ) || document.body ).appendChild( tip );
		return tip;
	}

	// À droite de l'ancre si la place le permet, sinon à gauche, sinon dessous ;
	// jamais sous la barre d'administration WordPress (32 px, 46 px sur mobile).
	function placeTip( rect, gap ) {
		if ( ! tip || tip.hidden ) return;
		if ( isSheet() ) {
			tip.style.left = "";
			tip.style.top = "";
			return;
		}
		gap = null == gap ? 14 : gap;
		var tw = tip.offsetWidth, th = tip.offsetHeight;
		var vw = window.innerWidth, vh = window.innerHeight;
		var left = rect.right + gap;
		var top = rect.top;
		if ( left + tw > vw - 12 ) left = rect.left - gap - tw;
		if ( left < 12 ) {
			left = clamp( rect.left + rect.width / 2 - tw / 2, 12, Math.max( 12, vw - tw - 12 ) );
			top = rect.bottom + gap;
			if ( top + th > vh - 12 ) top = rect.top - gap - th;
		}
		var bar = document.getElementById( "wpadminbar" );
		var minTop = ( bar ? bar.getBoundingClientRect().bottom : 0 ) + 10;
		top = clamp( top, minTop, Math.max( minTop, vh - th - 12 ) );
		tip.style.left = Math.round( left ) + "px";
		tip.style.top = Math.round( top ) + "px";
	}

	function showTip( owner, host, html, rect, opts ) {
		var box = getTip( host );
		tipOwner = owner;
		box.className = "cu-sx-tip" + ( opts && opts.cls ? " " + opts.cls : "" );
		box.innerHTML = html;
		box.hidden = false;
		placeTip( rect, opts && opts.gap );
		return box;
	}

	function hideTip( owner ) {
		if ( ! tip || ( owner && owner !== tipOwner ) ) return;
		tip.hidden = true;
		tipOwner = null;
	}

	/**
	 * Infobulle au survol (souris) ET au focus clavier d'un élément focalisable ;
	 * Échap la ferme. `build` renvoie le HTML (déjà échappé) à afficher.
	 */
	function bindTip( node, build, opts ) {
		var owner = {};
		function open() {
			var html = build();
			if ( ! html ) return;
			showTip( owner, node, html, node.getBoundingClientRect(), opts );
			node.setAttribute( "aria-describedby", TIP_ID );
		}
		function close() {
			hideTip( owner );
			node.removeAttribute( "aria-describedby" );
		}
		node.addEventListener( "pointerenter", function ( ev ) {
			if ( "touch" !== ev.pointerType ) open();
		} );
		node.addEventListener( "pointerleave", function () {
			if ( document.activeElement !== node ) close();
		} );
		node.addEventListener( "focus", open );
		node.addEventListener( "blur", close );
		node.addEventListener( "keydown", function ( ev ) {
			if ( "Escape" === ev.key ) close();
		} );
	}

	window.addEventListener( "scroll", function () {
		if ( ! tip || tip.hidden || ! tipOwner ) return;
		if ( tipOwner.onScroll ) tipOwner.onScroll();
		else hideTip();
	}, { passive: true } );

	// -------------------------------------------------------------------------
	// Cadrans en relief
	// -------------------------------------------------------------------------
	// Angles en degrés, 0° = 3 h, sens horaire (repère SVG).
	function polar( cx, cy, r, deg ) {
		var rad = deg * Math.PI / 180;
		return [ cx + r * Math.cos( rad ), cy + r * Math.sin( rad ) ];
	}
	function arcPath( cx, cy, r, from, to ) {
		var span = clamp( to - from, 0.01, 359.99 );
		var p0 = polar( cx, cy, r, from ), p1 = polar( cx, cy, r, from + span );
		return "M" + p0[ 0 ].toFixed( 2 ) + "," + p0[ 1 ].toFixed( 2 ) + "A" + r + "," + r + " 0 " + ( span > 180 ? 1 : 0 ) + " 1 " + p1[ 0 ].toFixed( 2 ) + "," + p1[ 1 ].toFixed( 2 );
	}

	// Jauge du score global : arc de 270° ouvert en bas, graduations, zones
	// faible / moyen / bon et repère de l'objectif.
	var GAUGE = { w: 260, h: 228, cx: 130, cy: 124, r: 94, stroke: 20, start: 135, sweep: 270, ticks: true, zones: true, target: 80, dome: 52, glow: 6, halo: 22, label: "SCORE GLOBAL", labelDy: -29, valueSize: 58, valueDy: 19, unitDy: 40 };
	// Orbe du centre de santé : anneau complet.
	var ORB = { w: 120, h: 120, cx: 60, cy: 60, r: 44, stroke: 11, start: -90, sweep: 360, ticks: false, zones: false, target: null, dome: 31, glow: 3.5, halo: 9, label: "", valueSize: 26, valueDy: 9, unitDy: 0 };

	/**
	 * Dessine un cadran dans `root` (contenu remplacé) : halo, dôme central,
	 * piste creusée, arc de valeur en dégradé avec reflet et ombre (effet de
	 * relief), lueur et bouton au bout de l'arc. L'arc part de la valeur
	 * précédemment affichée (rafraîchissement) ou de 0 (premier rendu).
	 */
	function dial( root, geo, value, opts ) {
		opts = opts || {};
		var id = "cu-sx-d" + ( ++uid );
		var has = null != value && "" !== value && ! isNaN( value );
		var target = has ? clamp( Number( value ), 0, 100 ) : 0;
		var previous = parseFloat( root.getAttribute( "data-cu-value" ) );
		var from = isNaN( previous ) ? 0 : previous;
		root.setAttribute( "data-cu-value", has ? String( target ) : "" );
		var name = has ? tone( target ) : "na";
		var colors = toneColors( name );
		var accent = cuAdmin.cssVar( "--cu-accent", "#3858e9" );
		var cx = geo.cx, cy = geo.cy, r = geo.r, sw = geo.stroke;
		var outer = r + sw / 2, inner = r - sw / 2;
		function angle( v ) { return geo.start + geo.sweep * clamp( v, 0, 100 ) / 100; }

		var svg = el( "svg", { class: "cu-sx-dial is-" + name, viewBox: "0 0 " + geo.w + " " + geo.h, "aria-hidden": "true", focusable: "false" } );
		var defs = el( "defs", {}, svg );
		var halo = el( "radialGradient", { id: id + "-halo" }, defs );
		// Lueur en anneau autour de l'arc uniquement : le centre du cadran reste blanc.
		var ring = ( outer / ( outer + geo.halo ) * 100 ).toFixed( 1 );
		el( "stop", { offset: ( ring * 0.8 ).toFixed( 1 ) + "%", "stop-color": colors.light, "stop-opacity": "0" }, halo );
		el( "stop", { offset: ring + "%", "stop-color": colors.light, "stop-opacity": has ? "0.32" : "0" }, halo );
		el( "stop", { offset: "100%", "stop-color": colors.light, "stop-opacity": "0" }, halo );
		var dome = el( "radialGradient", { id: id + "-dome", cx: "40%", cy: "30%", r: "78%" }, defs );
		el( "stop", { offset: "0%", "stop-color": "#ffffff" }, dome );
		el( "stop", { offset: "68%", "stop-color": cuAdmin.cssVar( "--cu-panel-2", "#f8f9fd" ) }, dome );
		el( "stop", { offset: "100%", "stop-color": cuAdmin.cssVar( "--cu-bg-soft", "#eef2fb" ) }, dome );
		var lift = el( "filter", { id: id + "-lift", x: "-40%", y: "-40%", width: "180%", height: "180%" }, defs );
		el( "feDropShadow", { dx: "0", dy: "3", stdDeviation: "4", "flood-color": accent, "flood-opacity": "0.18" }, lift );
		var glow = el( "filter", { id: id + "-glow", x: "-30%", y: "-30%", width: "160%", height: "160%" }, defs );
		el( "feGaussianBlur", { stdDeviation: String( geo.glow ) }, glow );
		// Dégradé fixe, clair → profond : l'arc « s'intensifie » à mesure qu'il avance.
		var grad = 360 === geo.sweep
			? el( "linearGradient", { id: id + "-arc", gradientUnits: "userSpaceOnUse", x1: cx - outer, y1: cy - outer, x2: cx + outer, y2: cy + outer }, defs )
			: el( "linearGradient", { id: id + "-arc", gradientUnits: "userSpaceOnUse", x1: cx - outer, y1: cy, x2: cx + outer, y2: cy }, defs );
		el( "stop", { offset: "0%", "stop-color": colors.light }, grad );
		el( "stop", { offset: "100%", "stop-color": colors.deep }, grad );

		el( "circle", { class: "cu-sx-halo", cx: cx, cy: cy, r: ( outer + geo.halo ).toFixed( 1 ), fill: "url(#" + id + "-halo)" }, svg );
		if ( geo.zones ) {
			[ [ 0, 50, "low" ], [ 50, 80, "mid" ], [ 80, 100, "good" ] ].forEach( function ( zone ) {
				var a0 = angle( zone[ 0 ] ) + ( zone[ 0 ] ? 1.4 : 0 );
				var a1 = angle( zone[ 1 ] ) - ( zone[ 1 ] < 100 ? 1.4 : 0 );
				el( "path", { class: "cu-sx-zone", d: arcPath( cx, cy, outer + 7, a0, a1 ), stroke: toneColors( zone[ 2 ] ).deep }, svg );
			} );
		}
		el( "path", { class: "cu-sx-track", d: arcPath( cx, cy, r, geo.start, geo.start + geo.sweep ), "stroke-width": sw, "stroke-linecap": 360 === geo.sweep ? "butt" : "round" }, svg );
		el( "path", { class: "cu-sx-track-shade", d: arcPath( cx, cy, inner + 1.6, geo.start, geo.start + geo.sweep ) }, svg );
		if ( geo.ticks ) {
			var ticks = el( "g", { class: "cu-sx-ticks" }, svg );
			for ( var mark = 0; mark <= 100; mark += 5 ) {
				var major = 0 === mark % 25;
				var t0 = polar( cx, cy, inner - 6, angle( mark ) );
				var t1 = polar( cx, cy, inner - ( major ? 13 : 9 ), angle( mark ) );
				el( "line", { class: major ? "is-major" : "", x1: t0[ 0 ].toFixed( 1 ), y1: t0[ 1 ].toFixed( 1 ), x2: t1[ 0 ].toFixed( 1 ), y2: t1[ 1 ].toFixed( 1 ) }, ticks );
				if ( major ) {
					var tl = polar( cx, cy, inner - 23, angle( mark ) );
					var tx = el( "text", { class: "cu-sx-tick-label", x: tl[ 0 ].toFixed( 1 ), y: ( tl[ 1 ] + 3.5 ).toFixed( 1 ), "text-anchor": "middle" }, ticks );
					tx.textContent = String( mark );
				}
			}
		}
		el( "circle", { class: "cu-sx-dome", cx: cx, cy: cy, r: geo.dome, fill: "url(#" + id + "-dome)", filter: "url(#" + id + "-lift)" }, svg );

		var group = el( "g", { class: "cu-sx-value" }, svg );
		var glowArc = el( "path", { class: "cu-sx-arc-glow", stroke: colors.deep, "stroke-width": sw, filter: "url(#" + id + "-glow)" }, group );
		var mainArc = el( "path", { class: "cu-sx-arc", stroke: "url(#" + id + "-arc)", "stroke-width": sw }, group );
		var shine = el( "path", { class: "cu-sx-arc-hi", "stroke-width": Math.max( 2, sw * 0.14 ).toFixed( 1 ) }, group );
		var shade = el( "path", { class: "cu-sx-arc-lo", "stroke-width": Math.max( 2, sw * 0.14 ).toFixed( 1 ) }, group );
		var knob = el( "circle", { class: "cu-sx-knob", r: ( sw / 2 + 1.5 ).toFixed( 1 ), stroke: colors.deep, "stroke-width": ( sw * 0.2 ).toFixed( 1 ), filter: "url(#" + id + "-lift)" }, group );

		if ( null != geo.target ) {
			var ta = angle( geo.target );
			var n0 = polar( cx, cy, inner - 1, ta ), n1 = polar( cx, cy, outer + 11, ta );
			el( "line", { class: "cu-sx-target", x1: n0[ 0 ].toFixed( 1 ), y1: n0[ 1 ].toFixed( 1 ), x2: n1[ 0 ].toFixed( 1 ), y2: n1[ 1 ].toFixed( 1 ) }, svg );
			var nl = polar( cx, cy, outer + 21, ta );
			var nt = el( "text", { class: "cu-sx-target-label", x: nl[ 0 ].toFixed( 1 ), y: ( nl[ 1 ] + 4 ).toFixed( 1 ), "text-anchor": "middle" }, svg );
			nt.textContent = String( geo.target );
		}
		if ( geo.label ) {
			var label = el( "text", { class: "cu-sx-dial-label", x: cx, y: cy + geo.labelDy, "text-anchor": "middle" }, svg );
			label.textContent = geo.label;
		}
		var valueText = el( "text", { class: "cu-sx-dial-value", x: cx, y: cy + geo.valueDy, "text-anchor": "middle", fill: colors.text, "font-size": geo.valueSize }, svg );
		if ( geo.unitDy ) {
			var unit = el( "text", { class: "cu-sx-dial-unit", x: cx, y: cy + geo.unitDy, "text-anchor": "middle" }, svg );
			unit.textContent = "/100";
		}

		function paint( v ) {
			var end = angle( v );
			var d = arcPath( cx, cy, r, geo.start, end );
			glowArc.setAttribute( "d", d );
			mainArc.setAttribute( "d", d );
			shine.setAttribute( "d", arcPath( cx, cy, outer - 3.4, geo.start, end ) );
			shade.setAttribute( "d", arcPath( cx, cy, inner + 3.2, geo.start, end ) );
			var tipPoint = polar( cx, cy, r, end );
			knob.setAttribute( "cx", tipPoint[ 0 ].toFixed( 2 ) );
			knob.setAttribute( "cy", tipPoint[ 1 ].toFixed( 2 ) );
			valueText.textContent = String( Math.round( v ) );
		}

		while ( root.firstChild ) root.removeChild( root.firstChild );
		root.appendChild( svg );
		if ( ! has ) {
			group.setAttribute( "visibility", "hidden" );
			valueText.textContent = opts.empty || "n/d";
			valueText.setAttribute( "font-size", String( Math.round( geo.valueSize * 0.62 ) ) );
			valueText.setAttribute( "y", String( cy + geo.valueDy * 0.6 ) );
			return { tone: name };
		}
		if ( reducedMotion( root ) || from === target ) {
			paint( target );
			return { tone: name };
		}
		var started = null;
		var duration = opts.duration || 1100;
		paint( from );
		var step = function ( now ) {
			if ( ! svg.isConnected ) return;
			if ( null === started ) started = now;
			var k = Math.min( 1, ( now - started ) / duration );
			paint( from + ( target - from ) * easeOut( k ) );
			if ( k < 1 ) window.requestAnimationFrame( step );
		};
		window.requestAnimationFrame( step );
		return { tone: name };
	}

	function gauge( root, value, opts ) {
		return dial( root, GAUGE, value, opts );
	}
	function orb( root, value, opts ) {
		return dial( root, ORB, value, opts );
	}

	// -------------------------------------------------------------------------
	// Courbe de tendance
	// -------------------------------------------------------------------------
	/**
	 * rows : relevés quotidiens { snap_date, score_global, score_avg,
	 * issues_error/_warning/_notice, pages_total, indexable_count, created_at }.
	 * opts.verdict(score) : libellé du verdict (mêmes seuils que la page).
	 */
	function trend( root, rows, opts ) {
		opts = opts || {};
		var id = "cu-sx-t" + ( ++uid );
		var origin = rows.length ? toDate( String( rows[ 0 ].snap_date || "" ).slice( 0, 10 ) ).getTime() : 0;
		var points = rows.map( function ( row ) {
			var key = String( row.snap_date || "" ).slice( 0, 10 );
			var global = row.score_global;
			return {
				key: key,
				day: Math.round( ( toDate( key ).getTime() - origin ) / 86400000 ),
				global: null == global || "" === global ? null : Number( global ),
				avg: Number( row.score_avg ) || 0,
				err: Number( row.issues_error ) || 0,
				warn: Number( row.issues_warning ) || 0,
				notice: Number( row.issues_notice ) || 0,
				pages: Number( row.pages_total ) || 0,
				indexable: Number( row.indexable_count ) || 0,
				at: String( row.created_at || "" ),
			};
		} );
		// Série principale = score global (le chiffre de la jauge). Avant son premier
		// relevé, seule la moyenne des pages existe : elle devient la série principale.
		var hasGlobal = points.some( function ( p ) { return null !== p.global; } );
		points.forEach( function ( p ) { p.main = hasGlobal ? p.global : p.avg; } );
		var mainName = hasGlobal ? "Score global" : "Score moyen des pages";
		var last = points.length - 1;
		var lastMain = -1;
		points.forEach( function ( p, n ) { if ( null !== p.main ) lastMain = n; } );
		var owner = { pinned: false };
		var state = { active: -1, geometry: null, width: 0, alive: true, announce: null };

		root.classList.add( "cu-sx-chart" );
		root.innerHTML =
			'<div class="cu-sx-stage" tabindex="0" role="group" aria-roledescription="graphique" aria-describedby="' + id + '-help" aria-label="' + esc( mainName ) + ', relevé par relevé">' +
				'<svg class="cu-sx-svg" aria-hidden="true" focusable="false"></svg>' +
			"</div>" +
			'<div class="cu-sx-foot">' +
				'<span class="cu-sx-key"><i class="cu-sx-key-main"></i>' + esc( mainName ) + "</span>" +
				( hasGlobal ? '<span class="cu-sx-key"><i class="cu-sx-key-avg"></i>Moyenne des pages</span>' : "" ) +
				'<span class="cu-sx-key"><i class="cu-sx-key-issues"></i>Erreurs + alertes</span>' +
				'<span class="cu-sx-key"><i class="cu-sx-key-target"></i>Objectif 80</span>' +
				'<span class="cu-sx-help" id="' + id + '-help">Survolez pour le détail · cliquez pour épingler · ← → au clavier</span>' +
			"</div>" +
			'<p class="screen-reader-text" aria-live="polite"></p>' +
			'<div class="screen-reader-text"><table></table></div>';
		var stage = root.querySelector( ".cu-sx-stage" );
		var svg = root.querySelector( ".cu-sx-svg" );
		var live = root.querySelector( "p.screen-reader-text" );
		root.querySelector( "table" ).innerHTML = "<caption>Évolution du score SEO, relevé par relevé</caption><thead><tr><th scope=\"col\">Date</th><th scope=\"col\">" + esc( mainName ) + "</th>" + ( hasGlobal ? "<th scope=\"col\">Moyenne des pages</th>" : "" ) + "<th scope=\"col\">Erreurs</th><th scope=\"col\">Alertes</th><th scope=\"col\">Infos</th><th scope=\"col\">Pages</th></tr></thead><tbody>" +
			points.map( function ( p ) {
				return '<tr><th scope="row">' + esc( longDate( p.key ) ) + "</th><td>" + ( null === p.main ? "non relevé" : p.main + "/100" ) + "</td>" + ( hasGlobal ? "<td>" + p.avg + "/100</td>" : "" ) + "<td>" + num( p.err ) + "</td><td>" + num( p.warn ) + "</td><td>" + num( p.notice ) + "</td><td>" + num( p.pages ) + "</td></tr>";
			} ).join( "" ) + "</tbody>";

		function draw( animate ) {
			var width = Math.max( 260, Math.floor( stage.clientWidth ) );
			var narrow = width < 560;
			var pad = { l: 34, r: narrow ? 12 : 52, t: 30, b: 24 };
			var plotH = narrow ? 150 : 184;
			var gap = 18;
			var bandH = narrow ? 34 : 44;
			var height = pad.t + plotH + gap + bandH + pad.b;
			var plotW = width - pad.l - pad.r;
			var span = last > 0 ? points[ last ].day - points[ 0 ].day : 0;
			var bandTop = pad.t + plotH + gap;
			state.width = width;
			while ( svg.firstChild ) svg.removeChild( svg.firstChild );
			svg.setAttribute( "viewBox", "0 0 " + width + " " + height );
			svg.setAttribute( "width", width );
			svg.setAttribute( "height", height );
			if ( ! points.length ) return;

			function xAt( n ) { return span ? pad.l + ( points[ n ].day - points[ 0 ].day ) / span * plotW : pad.l + plotW / 2; }
			function yAt( v ) { return pad.t + plotH - clamp( v, 0, 100 ) / 100 * plotH; }
			var issuePeak = points.reduce( function ( m, p ) { return Math.max( m, p.err + p.warn ); }, 0 );
			var bandScale = cuAdmin.niceScale( issuePeak, 2 );
			function bandY( v ) { return bandTop + bandH - v / bandScale.max * bandH; }
			var dayW = span ? plotW / span : plotW;
			state.geometry = { xAt: xAt, yAt: yAt, bandY: bandY, pad: pad, plotH: plotH, bandTop: bandTop, bandH: bandH, dayW: dayW };

			var accent = cuAdmin.cssVar( "--cu-accent", "#3858e9" );
			var teal = cuAdmin.cssVar( "--cu-accent-2", "#0fb5c9" );
			var mint = cuAdmin.cssVar( "--cu-mint", "#34d399" );
			var defs = el( "defs", {}, svg );
			var fill = el( "linearGradient", { id: id + "-fill", x1: "0", y1: "0", x2: "0", y2: "1" }, defs );
			el( "stop", { offset: "0%", "stop-color": accent, "stop-opacity": "0.4" }, fill );
			el( "stop", { offset: "55%", "stop-color": teal, "stop-opacity": "0.12" }, fill );
			el( "stop", { offset: "100%", "stop-color": teal, "stop-opacity": "0" }, fill );
			var stroke = el( "linearGradient", { id: id + "-stroke", x1: "0", y1: "0", x2: "1", y2: "0" }, defs );
			el( "stop", { offset: "0%", "stop-color": accent }, stroke );
			el( "stop", { offset: "60%", "stop-color": teal }, stroke );
			el( "stop", { offset: "100%", "stop-color": mint }, stroke );
			var sheen = el( "linearGradient", { id: id + "-sheen", x1: "0", y1: "0", x2: "0", y2: "1" }, defs );
			el( "stop", { offset: "0%", "stop-color": "#ffffff", "stop-opacity": "0.55" }, sheen );
			el( "stop", { offset: "28%", "stop-color": "#ffffff", "stop-opacity": "0" }, sheen );
			var glow = el( "filter", { id: id + "-glow", x: "-5%", y: "-40%", width: "110%", height: "180%" }, defs );
			el( "feGaussianBlur", { stdDeviation: "5" }, glow );
			[ "err", "warn" ].forEach( function ( kind ) {
				var shade = toneColors( "err" === kind ? "low" : "mid" );
				var bar = el( "linearGradient", { id: id + "-" + kind, x1: "0", y1: "0", x2: "0", y2: "1" }, defs );
				el( "stop", { offset: "0%", "stop-color": shade.light }, bar );
				el( "stop", { offset: "100%", "stop-color": shade.deep }, bar );
			} );
			var clip = el( "clipPath", { id: id + "-clip" }, defs );
			var clipRect = el( "rect", { x: "0", y: "0", width: String( width ), height: String( height ) }, clip );

			// Fond : zones de score, grille, objectif, axes.
			var back = el( "g", { class: "cu-sx-back" }, svg );
			[ [ 80, 100, "good", "Bon" ], [ 50, 80, "mid", "Moyen" ], [ 0, 50, "low", "Faible" ] ].forEach( function ( zone ) {
				el( "rect", { class: "cu-sx-zoneband", x: pad.l, y: yAt( zone[ 1 ] ).toFixed( 1 ), width: plotW, height: ( yAt( zone[ 0 ] ) - yAt( zone[ 1 ] ) ).toFixed( 1 ), fill: toneColors( zone[ 2 ] ).light }, back );
				if ( ! narrow ) {
					var zl = el( "text", { class: "cu-sx-zone-label is-" + zone[ 2 ], x: width - pad.r + 9, y: ( ( yAt( zone[ 0 ] ) + yAt( zone[ 1 ] ) ) / 2 + 4 ).toFixed( 1 ) }, back );
					zl.textContent = zone[ 3 ];
				}
			} );
			[ 0, 25, 50, 75, 100 ].forEach( function ( v ) {
				var gy = yAt( v ).toFixed( 1 );
				el( "line", { class: 0 === v ? "cu-sx-axis" : "cu-sx-grid", x1: pad.l, x2: width - pad.r, y1: gy, y2: gy }, back );
				var yl = el( "text", { class: "cu-sx-ylabel", x: pad.l - 8, y: ( yAt( v ) + 4 ).toFixed( 1 ), "text-anchor": "end" }, back );
				yl.textContent = String( v );
			} );
			var ty = yAt( 80 ).toFixed( 1 );
			el( "line", { class: "cu-sx-target-line", x1: pad.l, x2: width - pad.r, y1: ty, y2: ty }, back );
			var tLabel = el( "text", { class: "cu-sx-target-text", x: pad.l + 8, y: ( yAt( 80 ) - 6 ).toFixed( 1 ) }, back );
			tLabel.textContent = "Objectif 80";

			// Dates : la dernière toujours, les autres assez espacées pour ne jamais se chevaucher.
			var lastX = Infinity;
			for ( var lab = last; lab >= 0; lab-- ) {
				var x = xAt( lab );
				if ( lab !== last && lastX - x < 58 ) continue;
				if ( lab !== last && lab !== 0 && x - pad.l < 28 ) continue;
				lastX = x;
				var anchor = lab === last ? ( last ? "end" : "middle" ) : ( 0 === lab ? "start" : "middle" );
				var tick = el( "text", { class: "cu-sx-xlabel" + ( lab === last ? " is-now" : "" ), x: x.toFixed( 1 ), y: height - 7, "text-anchor": anchor }, back );
				tick.textContent = shortDate( points[ lab ].key );
			}

			// Bande basse : erreurs (rouge) + alertes (ambre) empilées, en relief.
			var band = el( "g", { class: "cu-sx-band" }, svg );
			el( "line", { class: "cu-sx-axis", x1: pad.l, x2: width - pad.r, y1: bandY( 0 ).toFixed( 1 ), y2: bandY( 0 ).toFixed( 1 ) }, band );
			var bandLabel = el( "text", { class: "cu-sx-band-label", x: pad.l, y: ( bandTop - 5 ).toFixed( 1 ) }, band );
			bandLabel.textContent = "Erreurs + alertes";
			var barW = clamp( dayW * 0.56, 3, 14 );
			points.forEach( function ( p, i ) {
				var bx = ( xAt( i ) - barW / 2 ).toFixed( 1 );
				if ( p.err ) {
					el( "rect", { class: "cu-sx-bar", x: bx, y: bandY( p.err ).toFixed( 1 ), width: barW.toFixed( 1 ), height: Math.max( 1, bandY( 0 ) - bandY( p.err ) ).toFixed( 1 ), rx: Math.min( 3, barW / 2 ).toFixed( 1 ), fill: "url(#" + id + "-err)" }, band );
				}
				if ( p.warn ) {
					el( "rect", { class: "cu-sx-bar", x: bx, y: bandY( p.err + p.warn ).toFixed( 1 ), width: barW.toFixed( 1 ), height: Math.max( 1, bandY( p.err ) - bandY( p.err + p.warn ) ).toFixed( 1 ), rx: Math.min( 3, barW / 2 ).toFixed( 1 ), fill: "url(#" + id + "-warn)" }, band );
				}
			} );

			// Moyenne des pages (série secondaire) — uniquement si le score global existe.
			var plot = el( "g", { class: "cu-sx-plot", "clip-path": "url(#" + id + "-clip)" }, svg );
			if ( hasGlobal && points.length > 1 ) {
				el( "path", { class: "cu-sx-avg", d: cuAdmin.smoothPath( points.map( function ( p, i ) { return [ xAt( i ), yAt( p.avg ) ]; } ) ) }, plot );
			}
			// Série principale : aire « aurora » en relief, par tronçons de relevés consécutifs.
			var runs = [];
			var run = [];
			points.forEach( function ( p, i ) {
				if ( null === p.main ) {
					if ( run.length ) runs.push( run );
					run = [];
				} else {
					run.push( i );
				}
			} );
			if ( run.length ) runs.push( run );
			var base = yAt( 0 ).toFixed( 1 );
			runs.forEach( function ( indices, r ) {
				var pts = indices.map( function ( i ) { return [ xAt( i ), yAt( points[ i ].main ) ]; } );
				if ( 1 === pts.length ) {
					el( "circle", { class: "cu-sx-solo", cx: pts[ 0 ][ 0 ].toFixed( 1 ), cy: pts[ 0 ][ 1 ].toFixed( 1 ), r: "4.5" }, plot );
					return;
				}
				var line = cuAdmin.smoothPath( pts );
				var area = line + "L" + pts[ pts.length - 1 ][ 0 ].toFixed( 1 ) + "," + base + "L" + pts[ 0 ][ 0 ].toFixed( 1 ) + "," + base + "Z";
				// Tronçon qui commence en cours de période (premier relevé du score
				// global) : l'aire apparaît en fondu au lieu d'un bord vertical net.
				var areaAttrs = {};
				if ( indices[ 0 ] > 0 ) {
					var fadeId = id + "-fade" + r;
					var fade = el( "linearGradient", { id: fadeId + "-g", gradientUnits: "userSpaceOnUse", x1: pts[ 0 ][ 0 ].toFixed( 1 ), x2: ( pts[ 0 ][ 0 ] + Math.min( 48, ( pts[ pts.length - 1 ][ 0 ] - pts[ 0 ][ 0 ] ) / 2 ) ).toFixed( 1 ), y1: "0", y2: "0" }, defs );
					el( "stop", { offset: "0%", "stop-color": "#fff", "stop-opacity": "0" }, fade );
					el( "stop", { offset: "100%", "stop-color": "#fff", "stop-opacity": "1" }, fade );
					var mask = el( "mask", { id: fadeId, maskUnits: "userSpaceOnUse", x: "0", y: "0", width: String( width ), height: String( height ) }, defs );
					el( "rect", { x: "0", y: "0", width: String( width ), height: String( height ), fill: "url(#" + fadeId + "-g)" }, mask );
					areaAttrs.mask = "url(#" + fadeId + ")";
				}
				el( "path", Object.assign( { class: "cu-sx-area", d: area, fill: "url(#" + id + "-fill)" }, areaAttrs ), plot );
				el( "path", Object.assign( { class: "cu-sx-sheen", d: area, fill: "url(#" + id + "-sheen)" }, areaAttrs ), plot );
				el( "path", { class: "cu-sx-line-glow", d: line, stroke: accent, filter: "url(#" + id + "-glow)" }, plot );
				el( "path", { class: "cu-sx-line", d: line, stroke: "url(#" + id + "-stroke)" }, plot );
			} );

			// Meilleur relevé + pouls du dernier.
			var flags = el( "g", { class: "cu-sx-flags" }, svg );
			var best = -1;
			points.forEach( function ( p, i ) { if ( null !== p.main && ( best < 0 || p.main > points[ best ].main ) ) best = i; } );
			var mainCount = points.filter( function ( p ) { return null !== p.main; } ).length;
			if ( best >= 0 && mainCount > 1 ) {
				var bestX = xAt( best ), bestY = yAt( points[ best ].main );
				var text = "Meilleur · " + points[ best ].main;
				var fw = 16 + text.length * 6.3;
				var fx = clamp( bestX - fw / 2, pad.l, width - pad.r - fw );
				el( "line", { class: "cu-sx-record-line", x1: bestX.toFixed( 1 ), x2: bestX.toFixed( 1 ), y1: ( bestY - 7 ).toFixed( 1 ), y2: "24" }, flags );
				el( "rect", { class: "cu-sx-record", x: fx.toFixed( 1 ), y: "4", width: fw.toFixed( 1 ), height: "20", rx: "10" }, flags );
				var rt = el( "text", { class: "cu-sx-record-text", x: ( fx + fw / 2 ).toFixed( 1 ), y: "18", "text-anchor": "middle" }, flags );
				rt.textContent = text;
			}
			if ( lastMain >= 0 ) {
				var nx = xAt( lastMain ).toFixed( 1 ), ny = yAt( points[ lastMain ].main ).toFixed( 1 );
				el( "circle", { class: "cu-sx-pulse", cx: nx, cy: ny, r: "5" }, flags );
				el( "circle", { class: "cu-sx-now", cx: nx, cy: ny, r: "4.5" }, flags );
			}

			var cursor = el( "g", { class: "cu-sx-cursor", visibility: "hidden" }, svg );
			el( "rect", { class: "cu-sx-cband", x: "0", y: pad.t, width: "10", height: ( bandTop + bandH - pad.t ).toFixed( 1 ), rx: "9" }, cursor );
			el( "line", { class: "cu-sx-cross", x1: "0", x2: "0", y1: pad.t - 2, y2: ( bandTop + bandH ).toFixed( 1 ) }, cursor );
			el( "circle", { class: "cu-sx-avg-dot", r: "4", cx: "0", cy: "0" }, cursor );
			el( "circle", { class: "cu-sx-dot", r: "6.5", cx: "0", cy: "0" }, cursor );
			el( "rect", { class: "cu-sx-hit", x: pad.l - 14, y: "0", width: plotW + 28, height: height }, svg );

			if ( animate && ! reducedMotion( root ) ) {
				var t0 = null;
				clipRect.setAttribute( "width", "0" );
				var grow = function ( now ) {
					if ( ! state.alive ) return;
					if ( null === t0 ) t0 = now;
					var k = Math.min( 1, ( now - t0 ) / 950 );
					clipRect.setAttribute( "width", String( width * easeOut( k ) ) );
					if ( k < 1 ) window.requestAnimationFrame( grow );
				};
				window.requestAnimationFrame( grow );
			}
		}

		// --- Infobulle -----------------------------------------------------------
		function metric( label, value, extra ) {
			return '<div class="cu-sx-m"><span>' + esc( label ) + "</span><b>" + value + "</b>" + ( extra || "" ) + "</div>";
		}
		function snapshotTime( p ) {
			var m = /\s(\d{2}):(\d{2})/.exec( p.at );
			return m ? "relevé à " + Number( m[ 1 ] ) + " h " + m[ 2 ] : "";
		}

		function content( n ) {
			var p = points[ n ];
			var prev = n > 0 ? points[ n - 1 ] : null;
			var name = tone( p.main );
			var verdict = null !== p.main && opts.verdict ? opts.verdict( p.main ) : "";
			var when = snapshotTime( p );
			var html = '<div class="cu-sx-tip-head"><span class="cu-sx-tip-date">' + esc( longDate( p.key ) ) + "</span>" +
				( when ? '<span class="cu-sx-tip-when">' + esc( when ) + "</span>" : "" ) +
				'<span class="cu-sx-pin">' + cuAdmin.icon( "pin" ) + " épinglé</span></div>";
			if ( null === p.main ) {
				html += '<div class="cu-sx-tip-total"><strong class="is-na">—</strong></div>' +
					'<p class="cu-sx-note">Score global non relevé ce jour-là : seule la moyenne des pages existe pour cette date.</p>';
			} else {
				html += '<div class="cu-sx-tip-total"><strong class="is-' + name + '">' + p.main + "</strong>/100" +
					( verdict ? ' <span class="cu-sx-verdict is-' + name + '">' + esc( verdict ) + "</span>" : "" ) + "</div>" +
					'<div class="cu-sx-tip-sub">' + esc( mainName ) + "</div>";
				if ( prev && null !== prev.main ) {
					html += '<div class="cu-sx-tip-trend">' + delta( p.main - prev.main, false, "pts" ) +
						'<span class="cu-sx-vs">vs ' + esc( shortDate( prev.key ) ) + " (" + prev.main + ")</span></div>";
				}
			}
			var gapToGoal = null === p.main ? null : 80 - p.main;
			html += '<div class="cu-sx-metrics">' +
				( hasGlobal ? metric( "Moyenne des pages", p.avg + "<small>/100</small>", prev ? delta( p.avg - prev.avg, false, "" ) : "" ) : "" ) +
				metric( "Objectif 80", null === gapToGoal ? "—" : ( gapToGoal <= 0 ? '<span class="cu-sx-ok">' + cuAdmin.icon( "check" ) + " atteint</span>" : "encore " + gapToGoal + " pts" ) ) +
				metric( "Erreurs", '<span class="cu-sx-n-err">' + num( p.err ) + "</span>", prev ? delta( p.err - prev.err, true, "" ) : "" ) +
				metric( "Alertes", '<span class="cu-sx-n-warn">' + num( p.warn ) + "</span>", prev ? delta( p.warn - prev.warn, true, "" ) : "" ) +
				metric( "Infos", num( p.notice ), prev ? delta( p.notice - prev.notice, true, "" ) : "" ) +
				metric( "Pages inventoriées", num( p.pages ), p.pages ? '<em class="cu-sx-sub">' + num( p.indexable ) + " indexable" + ( p.indexable > 1 ? "s" : "" ) + "</em>" : "" ) +
				"</div>" +
				'<div class="cu-sx-tip-foot">' + ( owner.pinned ? "Échap ou clic ailleurs pour fermer" : "Cliquez pour épingler ce relevé" ) + "</div>";
			return html;
		}

		function anchorRect( n ) {
			var rect = svg.getBoundingClientRect();
			var scale = rect.width / state.width;
			var px = rect.left + state.geometry.xAt( n ) * scale;
			return { left: px - 8, right: px + 8, top: rect.top - 8, bottom: rect.top - 8, width: 16 };
		}

		function setActive( n ) {
			var geo = state.geometry;
			if ( ! geo || n < 0 || n > last ) return;
			state.active = n;
			var p = points[ n ];
			var x = geo.xAt( n );
			var cursor = svg.querySelector( ".cu-sx-cursor" );
			cursor.setAttribute( "visibility", "visible" );
			var bw = clamp( geo.dayW * 0.9, 14, 34 );
			var cband = cursor.querySelector( ".cu-sx-cband" );
			cband.setAttribute( "x", ( x - bw / 2 ).toFixed( 1 ) );
			cband.setAttribute( "width", bw.toFixed( 1 ) );
			var cross = cursor.querySelector( ".cu-sx-cross" );
			cross.setAttribute( "x1", x.toFixed( 1 ) );
			cross.setAttribute( "x2", x.toFixed( 1 ) );
			var dot = cursor.querySelector( ".cu-sx-dot" );
			dot.setAttribute( "visibility", null === p.main ? "hidden" : "visible" );
			dot.setAttribute( "cx", x.toFixed( 1 ) );
			dot.setAttribute( "cy", geo.yAt( null === p.main ? 0 : p.main ).toFixed( 1 ) );
			var adot = cursor.querySelector( ".cu-sx-avg-dot" );
			adot.setAttribute( "visibility", hasGlobal ? "visible" : "hidden" );
			adot.setAttribute( "cx", x.toFixed( 1 ) );
			adot.setAttribute( "cy", geo.yAt( p.avg ).toFixed( 1 ) );
			root.classList.add( "is-active" );
			var box = showTip( owner, root, content( n ), anchorRect( n ), { gap: 22, cls: owner.pinned ? "is-pinned" : "" } );
			box.classList.toggle( "is-pinned", owner.pinned );
			stage.setAttribute( "aria-describedby", id + "-help " + TIP_ID );
			window.clearTimeout( state.announce );
			state.announce = window.setTimeout( function () {
				live.textContent = longDate( p.key ) + " : " + ( null === p.main ? "score non relevé" : mainName + " " + p.main + " sur 100" ) + ", " + num( p.err ) + " erreurs, " + num( p.warn ) + " alertes.";
			}, 350 );
		}

		function hide() {
			state.active = -1;
			owner.pinned = false;
			root.classList.remove( "is-active", "is-pinned" );
			var cursor = svg.querySelector( ".cu-sx-cursor" );
			if ( cursor ) cursor.setAttribute( "visibility", "hidden" );
			stage.setAttribute( "aria-describedby", id + "-help" );
			hideTip( owner );
		}
		owner.onScroll = function () {
			if ( owner.pinned && state.active >= 0 ) placeTip( anchorRect( state.active ), 22 );
			else hide();
		};

		// --- Événements --------------------------------------------------------
		function indexAt( ev ) {
			var geo = state.geometry;
			if ( ! geo ) return -1;
			var rect = svg.getBoundingClientRect();
			var x = ( ev.clientX - rect.left ) * ( state.width / rect.width );
			var bestN = 0, bestD = Infinity;
			for ( var i = 0; i <= last; i++ ) {
				var d = Math.abs( geo.xAt( i ) - x );
				if ( d < bestD ) { bestD = d; bestN = i; }
			}
			return bestN;
		}
		var handlers = {
			move: function ( ev ) {
				if ( owner.pinned ) return;
				var n = indexAt( ev );
				if ( n !== state.active || tipOwner !== owner ) setActive( n );
			},
			leave: function () { if ( ! owner.pinned ) hide(); },
			click: function ( ev ) {
				var n = indexAt( ev );
				owner.pinned = ! ( owner.pinned && n === state.active );
				root.classList.toggle( "is-pinned", owner.pinned );
				setActive( n );
			},
			key: function ( ev ) {
				var n = state.active < 0 ? last : state.active;
				if ( "ArrowLeft" === ev.key ) n = Math.max( 0, n - 1 );
				else if ( "ArrowRight" === ev.key ) n = Math.min( last, n + 1 );
				else if ( "Home" === ev.key ) n = 0;
				else if ( "End" === ev.key ) n = last;
				else if ( "Escape" === ev.key ) { hide(); return; }
				else if ( "Enter" === ev.key || " " === ev.key ) {
					ev.preventDefault();
					owner.pinned = ! owner.pinned;
					root.classList.toggle( "is-pinned", owner.pinned );
					setActive( n );
					return;
				} else return;
				ev.preventDefault();
				setActive( n );
			},
			focus: function () { if ( state.active < 0 && last >= 0 ) setActive( last ); },
			blur: function () { if ( ! owner.pinned ) hide(); },
			docKey: function ( ev ) { if ( "Escape" === ev.key && owner.pinned ) hide(); },
			docClick: function ( ev ) {
				if ( owner.pinned && ! root.contains( ev.target ) && ! ( tip && tip.contains( ev.target ) ) ) hide();
			},
		};
		svg.addEventListener( "pointermove", handlers.move );
		svg.addEventListener( "pointerleave", handlers.leave );
		svg.addEventListener( "click", handlers.click );
		stage.addEventListener( "keydown", handlers.key );
		stage.addEventListener( "focus", handlers.focus );
		stage.addEventListener( "blur", handlers.blur );
		document.addEventListener( "keydown", handlers.docKey );
		document.addEventListener( "click", handlers.docClick );

		var observer = null;
		if ( window.ResizeObserver ) {
			var resizeTimer = null;
			observer = new window.ResizeObserver( function () {
				window.clearTimeout( resizeTimer );
				resizeTimer = window.setTimeout( function () {
					if ( state.alive && Math.floor( stage.clientWidth ) !== state.width ) {
						var keep = state.active;
						draw( false );
						if ( keep >= 0 ) setActive( keep );
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
			},
		};
	}

	window.cuSeoViz = {
		tone: tone,
		toneColors: toneColors,
		delta: delta,
		decimal: decimal,
		gauge: gauge,
		orb: orb,
		trend: trend,
		bindTip: bindTip,
		hideTip: hideTip,
	};
} )();

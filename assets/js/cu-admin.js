/* CyberPush Ultra — utilitaires partagés par toutes les pages d'administration.
 * Expose window.cuAdmin utilisé par cu-analytics.js, cu-firewall.js, cu-seo.js,
 * cu-settings.js et cu-logs.js.
 */
( function () {
	"use strict";

	var config = window.cuAdminConfig || {};

	// Thème clair unique (« Lumière ») : seul le réglage des animations est appliqué.
	function applyMotion() {
		var wrap = document.querySelector( ".cu-wrap" );
		if ( ! wrap ) return;
		// wp_localize_script() convertit les booléens en chaînes ("1" / "") :
		// une comparaison stricte à false ne serait donc jamais vraie.
		var motion = config.showAnimations;
		var motionOff = false === motion || "" === motion || "0" === motion || 0 === motion;
		wrap.setAttribute( "data-cu-motion", motionOff ? "off" : "on" );
	}

	/**
	 * Construit l'URL complète d'une route REST du plugin. Avec les permaliens
	 * « Simples », rest_url() renvoie « ?rest_route=/… » : la query string de la
	 * route doit alors être jointe par « & », sinon l'URL contient deux « ? ».
	 */
	function restUrl( path ) {
		var base = String( config.restUrl || "" ).replace( /\/$/, "" );
		path = String( path || "" ).replace( /^\//, "" );
		var query = "";
		var qIndex = path.indexOf( "?" );
		if ( -1 !== qIndex ) {
			query = path.slice( qIndex + 1 );
			path = path.slice( 0, qIndex );
		}
		var url = base + "/" + path;
		if ( query ) {
			url += ( -1 === url.indexOf( "?" ) ? "?" : "&" ) + query;
		}
		return url;
	}

	function apiFetch( path, options ) {
		options = options || {};
		var url = restUrl( path );
		// Cache-busting sur les lectures (GET) : garantit des données fraîches,
		// insensible à tout cache navigateur/proxy (Analytics temps réel, SEO…).
		var method = ( options.method || "GET" ).toUpperCase();
		if ( "GET" === method ) {
			url += ( url.indexOf( "?" ) === -1 ? "?" : "&" ) + "_=" + Date.now();
		}
		var headers = Object.assign(
			{ "X-WP-Nonce": config.nonce, "Content-Type": "application/json" },
			options.headers || {}
		);
		var fetchOptions = Object.assign( {}, options, { headers: headers, credentials: "same-origin" } );
		if ( fetchOptions.body && typeof fetchOptions.body !== "string" ) {
			fetchOptions.body = JSON.stringify( fetchOptions.body );
		}
		return fetch( url, fetchOptions ).then( function ( response ) {
			return response.text().then( function ( raw ) {
				var data = {};
				if ( raw ) {
					try {
						data = JSON.parse( raw );
					} catch ( e ) {
						if ( response.ok ) {
							throw new Error( "Réponse serveur invalide (HTTP " + response.status + ")." );
						}
					}
				}
				if ( ! response.ok ) {
					// Nonce expiré (onglet admin resté ouvert 12 à 24 h) : message
					// clair plutôt que « Le lien que vous avez suivi a expiré ».
					if ( data && "rest_cookie_invalid_nonce" === data.code ) {
						throw new Error( ( config.i18n && config.i18n.sessionExpired ) || "Session expirée : rechargez la page." );
					}
					var message = ( data && data.message ) || ( config.i18n && config.i18n.error ) || ( "Erreur HTTP " + response.status );
					throw new Error( message );
				}
				return data;
			} );
		} );
	}

	function toast( message, type ) {
		var stack = document.querySelector( ".cu-toast-stack" );
		if ( ! stack ) {
			stack = document.createElement( "div" );
			stack.className = "cu-toast-stack";
			// Comme les modales, la pile de toasts doit vivre DANS .cu-wrap pour
			// hériter des variables de thème (--cu-panel, --cu-text…) ; sinon le
			// toast s'affiche sans fond ni couleur. (position:fixed => plein écran.)
			var host = document.querySelector( ".cu-wrap" ) || document.body;
			host.appendChild( stack );
		}
		var el = document.createElement( "div" );
		el.className = "cu-toast " + ( type || "" );
		el.textContent = message;
		stack.appendChild( el );
		setTimeout( function () {
			el.remove();
		}, 4000 );
	}

	/**
	 * Instant réel (ms) d'une date SQL « Y-m-d H:i:s » exprimée en heure du SITE.
	 * Date.parse() la lirait dans le fuseau du navigateur : les « il y a X min »
	 * seraient décalés de plusieurs heures si les deux fuseaux diffèrent.
	 */
	function siteTime( mysqlDate ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec( String( mysqlDate || "" ) );
		if ( ! m ) return NaN;
		var utc = Date.UTC( +m[1], +m[2] - 1, +m[3], +m[4], +m[5], +( m[6] || 0 ) );
		return utc - ( Number( config.gmtOffset ) || 0 ) * 1000;
	}

	function formatNumber( n ) {
		n = Number( n ) || 0;
		return new Intl.NumberFormat( "fr-FR" ).format( n );
	}

	function formatDuration( seconds ) {
		seconds = Math.round( Number( seconds ) || 0 );
		var m = Math.floor( seconds / 60 );
		var s = seconds % 60;
		return m + "m " + ( s < 10 ? "0" : "" ) + s + "s";
	}

	/**
	 * Badge de variation. `lowerIsBetter` inverse la couleur pour les métriques
	 * où une hausse est une mauvaise nouvelle (taux de rebond, attaques…) :
	 * la flèche suit le sens réel, la couleur suit le bon ou le mauvais signal.
	 */
	function deltaBadge( value, lowerIsBetter ) {
		value = Number( value ) || 0;
		var rounded = Math.round( Math.abs( value ) * 10 ) / 10;
		// Format français : « 12,4 % » (virgule décimale, espace avant %).
		var shown = rounded.toFixed( 1 ).replace( ".", "," ) + " %";
		if ( 0 === rounded ) {
			return '<span class="cu-kpi-delta flat">= ' + shown + "</span>";
		}
		var good = lowerIsBetter ? value < 0 : value > 0;
		var arrow = value > 0 ? "▲" : "▼";
		return '<span class="cu-kpi-delta ' + ( good ? "up" : "down" ) + '">' + arrow + " " + shown + "</span>";
	}

	/**
	 * Petit rendu de courbe en SVG (aucune dépendance externe requise).
	 * data: number[]
	 */
	function sparkline( container, data, color ) {
		if ( ! container ) return;
		if ( ! data || ! data.length ) {
			container.innerHTML = "";
			return;
		}
		var w = 140,
			h = 34;
		var max = Math.max.apply( null, data ),
			min = Math.min.apply( null, data );
		var range = max - min || 1;
		var step = w / ( data.length - 1 || 1 );
		var points = data
			.map( function ( v, i ) {
				var x = i * step;
				var y = h - ( ( v - min ) / range ) * h;
				return x.toFixed( 1 ) + "," + y.toFixed( 1 );
			} )
			.join( " " );
		var stroke = color || cssVar( "--cu-accent", "#3858e9" );
		container.innerHTML =
			'<svg viewBox="0 0 ' + w + " " + h + '" preserveAspectRatio="none" width="100%" height="100%" aria-hidden="true">' +
			'<polygon fill="' + withAlpha( stroke, 0.12 ) + '" points="0,' + h + " " + points + " " + w + "," + h + '" />' +
			'<polyline fill="none" stroke="' + stroke + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="' + points + '" /></svg>';
	}

	/**
	 * Icônes SVG en ligne (même dessin que CU_Icons côté PHP) : remplacent les
	 * emoji, dont le rendu varie selon le système et ignore la couleur du texte.
	 */
	var ICONS = {
		"trend-up": '<path d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path d="M16 7h6v6"/>',
		"trend-down": '<path d="M22 17 13.5 8.5 8.5 13.5 2 7"/><path d="M16 17h6v-6"/>',
		trophy: '<path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3"/><path d="M7 5H4v2a3 3 0 0 0 3 3"/>',
		user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		chart: '<path d="M4 19V9"/><path d="M10 19V5"/><path d="M16 19v-7"/><path d="M22 19H2"/>',
		book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
		alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		sparkles: '<path d="M12 3l1.8 4.6L18.5 9l-4.7 1.4L12 15l-1.8-4.6L5.5 9l4.7-1.4z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
		bolt: '<path d="M13 2 3 14h7l-1 8 11-14h-7l0-6z"/>',
		key: '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 9.2-9.2"/><path d="m17 6 3 3"/><path d="m14 9 2 2"/>',
		check: '<path d="M5 12l5 5 9-10"/>',
		globe: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>',
		pin: '<path d="M12 17v5"/><path d="M9 3h6l-1 6 4 4H6l4-4z"/>',
		octagon: '<path d="M7.9 2h8.2L22 7.9v8.2L16.1 22H7.9L2 16.1V7.9z"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
		info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
		link: '<path d="M9 17H7a5 5 0 1 1 0-10h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><path d="M8 12h8"/>',
		unlink: '<path d="M9 17H7a5 5 0 0 1-1.5-9.8"/><path d="M15 7h2a5 5 0 0 1 2.3 9.4"/><path d="M8 12h3"/><path d="m3 3 18 18"/>',
		copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
		layers: '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
		map: '<path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14"/><path d="M15 6v14"/>',
		bot: '<rect x="4" y="8" width="16" height="12" rx="2"/><path d="M12 8V4"/><circle cx="12" cy="3" r="1"/><path d="M9 13h.01"/><path d="M15 13h.01"/>',
		tag: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/>',
		chevron: '<path d="m9 18 6-6-6-6"/>',
		brain: '<path d="M9 4a3 3 0 0 0-3 3 3 3 0 0 0-2 5.2A3 3 0 0 0 6 17a3 3 0 0 0 6 1V5a2 2 0 0 0-3-1z"/><path d="M15 4a3 3 0 0 1 3 3 3 3 0 0 1 2 5.2A3 3 0 0 1 18 17a3 3 0 0 1-6 1"/>',
	};
	function icon( name ) {
		return '<svg class="cu-icon cu-icon-inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ( ICONS[ name ] || ICONS.sparkles ) + "</svg>";
	}

	/**
	 * Palette des graphiques, lue dans les jetons CSS (--cu-viz-1 … --cu-viz-10) :
	 * une seule source de vérité pour les couleurs, toutes ≥ 4:1 sur blanc.
	 */
	var VIZ_FALLBACK = [ "#3858e9", "#d9480f", "#0b8a9a", "#c9356e", "#7a4fd6", "#b26200", "#138a4a", "#5c6670", "#0f6fbf", "#a1458f" ];
	function vizColors() {
		return VIZ_FALLBACK.map( function ( fallback, i ) {
			return cssVar( "--cu-viz-" + ( i + 1 ), fallback );
		} );
	}

	// Couleur #rrggbb (ou #rgb) → rgba() avec l'opacité demandée.
	function withAlpha( color, alpha ) {
		var hex = String( color || "" ).trim().replace( "#", "" );
		if ( 3 === hex.length ) {
			hex = hex.replace( /(.)/g, "$1$1" );
		}
		if ( ! /^[0-9a-f]{6}$/i.test( hex ) ) {
			return color;
		}
		var n = parseInt( hex, 16 );
		return "rgba(" + ( ( n >> 16 ) & 255 ) + "," + ( ( n >> 8 ) & 255 ) + "," + ( n & 255 ) + "," + alpha + ")";
	}

	/**
	 * Petit graphique en aires/lignes multi-séries, dessiné en Canvas.
	 * series: [{ label, color, data: number[] }], labels: string[]
	 */
	// Plafond « rond » d'une échelle en `steps` graduations (1, 2, 2,5 ou 5 × 10^n).
	function niceCeil( value, steps ) {
		var raw = Math.max( value, 1 ) / steps;
		var mag = Math.pow( 10, Math.floor( Math.log( raw ) / Math.LN10 ) );
		var nice = [ 1, 2, 2.5, 5, 10 ].map( function ( m ) { return m * mag; } ).filter( function ( v ) { return v >= raw; } )[ 0 ];
		return nice * steps;
	}

	function lineChart( canvas, labels, series ) {
		if ( ! canvas ) return;
		var ctx = canvas.getContext( "2d" );
		var ratio = window.devicePixelRatio || 1;
		var width = canvas.clientWidth || 600;
		var height = canvas.clientHeight || 240;
		canvas.width = width * ratio;
		canvas.height = height * ratio;
		ctx.scale( ratio, ratio );
		ctx.clearRect( 0, 0, width, height );

		if ( ! series.length || ! series[ 0 ].data.length ) {
			return;
		}

		var padding = 28;
		var allValues = series.reduce( function ( acc, s ) {
			return acc.concat( s.data );
		}, [] );
		// Échelle « ronde » (0, 25, 50, 75, 100…) affichée à gauche : sans
		// graduation, une courbe ne permet de lire aucune valeur.
		var max = niceCeil( Math.max.apply( null, allValues.concat( [ 1 ] ) ), 4 );
		var count = series[ 0 ].data.length;
		var font = "11px " + cssVar( "--cu-font", "sans-serif" );
		ctx.font = font;
		var axisW = Math.ceil( ctx.measureText( formatNumber( max ) ).width ) + 10;
		var left = Math.max( padding, axisW + 6 );
		var stepX = ( width - left - padding ) / Math.max( count - 1, 1 );
		var bottom = height - padding;

		// Grille horizontale pointillée, discrète.
		var gridColor = cssVar( "--cu-panel-border", "#e2e7f5" );
		var labelColor = cssVar( "--cu-text-faint", "#5f6788" );
		ctx.strokeStyle = gridColor;
		ctx.lineWidth = 1;
		ctx.fillStyle = labelColor;
		ctx.textAlign = "right";
		ctx.textBaseline = "middle";
		for ( var g = 0; g <= 4; g++ ) {
			var gy = padding + ( ( height - padding * 2 ) / 4 ) * g;
			ctx.setLineDash( 4 === g ? [] : [ 3, 4 ] );
			ctx.beginPath();
			ctx.moveTo( left, gy );
			ctx.lineTo( width - padding, gy );
			ctx.stroke();
			ctx.fillText( formatNumber( max - ( max / 4 ) * g ), left - 8, gy );
		}
		ctx.setLineDash( [] );
		ctx.textAlign = "left";
		ctx.textBaseline = "alphabetic";

		// Courbes lissées (Catmull-Rom → Bézier), aire en dégradé sous la 1re série,
		// point final mis en évidence.
		series.forEach( function ( s, index ) {
			var pts = s.data.map( function ( v, i ) {
				return [ left + i * stepX, bottom - ( v / max ) * ( height - padding * 2 ) ];
			} );
			var trace = function () {
				ctx.moveTo( pts[ 0 ][ 0 ], pts[ 0 ][ 1 ] );
				for ( var i = 0; i < pts.length - 1; i++ ) {
					var p0 = pts[ i - 1 ] || pts[ i ], p1 = pts[ i ], p2 = pts[ i + 1 ], p3 = pts[ i + 2 ] || p2;
					ctx.bezierCurveTo(
						p1[ 0 ] + ( p2[ 0 ] - p0[ 0 ] ) / 6, Math.min( bottom, p1[ 1 ] + ( p2[ 1 ] - p0[ 1 ] ) / 6 ),
						p2[ 0 ] - ( p3[ 0 ] - p1[ 0 ] ) / 6, Math.min( bottom, p2[ 1 ] - ( p3[ 1 ] - p1[ 1 ] ) / 6 ),
						p2[ 0 ], p2[ 1 ]
					);
				}
			};
			if ( 0 === index ) {
				var fill = ctx.createLinearGradient( 0, padding, 0, bottom );
				fill.addColorStop( 0, withAlpha( s.color, 0.22 ) );
				fill.addColorStop( 1, withAlpha( s.color, 0 ) );
				ctx.beginPath();
				trace();
				ctx.lineTo( pts[ pts.length - 1 ][ 0 ], bottom );
				ctx.lineTo( pts[ 0 ][ 0 ], bottom );
				ctx.closePath();
				ctx.fillStyle = fill;
				ctx.fill();
			}
			ctx.beginPath();
			trace();
			ctx.strokeStyle = s.color;
			ctx.lineWidth = 0 === index ? 2.5 : 2;
			ctx.lineJoin = "round";
			ctx.lineCap = "round";
			ctx.stroke();
			var last = pts[ pts.length - 1 ];
			ctx.beginPath();
			ctx.arc( last[ 0 ], last[ 1 ], 4, 0, Math.PI * 2 );
			ctx.fillStyle = "#fff";
			ctx.fill();
			ctx.lineWidth = 2.5;
			ctx.stroke();
		} );

		ctx.fillStyle = labelColor;
		ctx.font = font;
		if ( labels && labels.length ) {
			var labelStep = Math.max( Math.floor( labels.length / 6 ), 1 );
			labels.forEach( function ( label, i ) {
				if ( i % labelStep !== 0 ) return;
				var x = left + i * stepX;
				ctx.fillText( label, x - 10, height - 8 );
			} );
		}
	}

	/**
	 * Anneau (donut) simple pour les répartitions (appareils, navigateurs...).
	 * data: [{ label, value, color }]
	 */
	function donutChart( canvas, data ) {
		if ( ! canvas ) return;
		var ctx = canvas.getContext( "2d" );
		var ratio = window.devicePixelRatio || 1;
		var size = Math.min( canvas.clientWidth || 160, canvas.clientHeight || 160 );
		canvas.width = size * ratio;
		canvas.height = size * ratio;
		ctx.scale( ratio, ratio );
		ctx.clearRect( 0, 0, size, size );

		var total = data.reduce( function ( a, d ) {
			return a + d.value;
		}, 0 ) || 1;
		var cx = size / 2,
			cy = size / 2,
			r = size / 2 - 6,
			start = -Math.PI / 2;

		// Anneau épais à segments arrondis, séparés par un fin liseré blanc.
		var thickness = Math.max( 10, r * 0.34 );
		var ring = r - thickness / 2;
		var gap = data.length > 1 ? 0.025 : 0;
		ctx.lineWidth = thickness;
		ctx.lineCap = "butt";
		data.forEach( function ( d ) {
			var angle = ( d.value / total ) * Math.PI * 2;
			if ( angle <= 0 ) return;
			ctx.beginPath();
			ctx.arc( cx, cy, ring, start + gap / 2, start + Math.max( angle - gap / 2, gap / 2 ) );
			ctx.strokeStyle = d.color;
			ctx.stroke();
			start += angle;
		} );
	}

	/**
	 * Lit une variable CSS du conteneur .cu-wrap (pour rendre les graphiques
	 * Canvas cohérents avec le thème clair/sombre en cours).
	 */
	function cssVar( name, fallback ) {
		var wrap = document.querySelector( ".cu-wrap" ) || document.documentElement;
		var val = getComputedStyle( wrap ).getPropertyValue( name );
		return ( val && val.trim() ) || fallback;
	}

	function debounce( fn, wait ) {
		var timer;
		return function () {
			var args = arguments,
				ctx = this;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( ctx, args );
			}, wait || 300 );
		};
	}

	function confirmAction( message ) {
		return window.confirm( message || ( config.i18n && config.i18n.confirm ) || "Confirmer ?" );
	}

	function openModal( html ) {
		var backdrop = document.createElement( "div" );
		backdrop.className = "cu-modal-backdrop";
		// Bouton « × » toujours présent : Échap et le clic hors fenêtre ne sont
		// pas évidents (surtout au tactile).
		backdrop.innerHTML = '<div class="cu-modal" role="dialog" aria-modal="true" tabindex="-1">' +
			'<button type="button" class="cu-modal-close" aria-label="Fermer" title="Fermer">×</button>' + html + "</div>";
		backdrop.querySelector( ".cu-modal-close" ).addEventListener( "click", function () {
			backdrop.remove();
		} );
		function onKey( e ) {
			if ( "Escape" === e.key && backdrop.isConnected ) {
				backdrop.remove();
			}
			if ( ! backdrop.isConnected ) {
				document.removeEventListener( "keydown", onKey );
			}
		}
		backdrop.addEventListener( "click", function ( e ) {
			if ( e.target === backdrop ) backdrop.remove();
		} );
		document.addEventListener( "keydown", onKey );
		// IMPORTANT : on rattache la modale À L'INTÉRIEUR de .cu-wrap (et non au
		// <body>). Les couleurs de la modale (--cu-panel, --cu-text…) et le thème
		// clair/sombre sont portés par .cu-wrap ; hors de ce conteneur, ces
		// variables CSS sont indéfinies → popup transparente / cassée.
		// .cu-wrap n'ayant ni transform ni filter, le position:fixed reste bien
		// relatif au viewport (plein écran).
		var host = document.querySelector( ".cu-wrap" ) || document.body;
		host.appendChild( backdrop );
		var dialog = backdrop.querySelector( ".cu-modal" );
		if ( dialog && ! dialog.contains( document.activeElement ) ) {
			dialog.focus( { preventScroll: true } );
		}
		return backdrop;
	}

	/**
	 * Accessibilité : donne un nom aux champs dont le libellé visible n'est pas
	 * relié (interrupteur « .cu-switch » vide, <label> sans for=…). Sans cela,
	 * un lecteur d'écran annonce « case à cocher » sans dire laquelle. Le nom
	 * vient du texte affiché à côté ; un nom déjà présent n'est jamais remplacé.
	 */
	function labelUnnamedControls( root ) {
		( root || document ).querySelectorAll( ".cu-wrap input:not([type=hidden]), .cu-wrap select, .cu-wrap textarea" ).forEach( function ( el ) {
			if ( el.getAttribute( "aria-label" ) || el.getAttribute( "aria-labelledby" ) ) return;
			if ( el.id && document.querySelector( 'label[for="' + el.id + '"]' ) ) return;
			var wrap = el.closest( "label" );
			if ( wrap && wrap.textContent.trim() ) return;
			var name = "";
			var row = el.closest( ".cu-field-row" );
			var strong = row && row.querySelector( ".cu-field-row-text strong" );
			if ( strong ) {
				name = strong.textContent;
			} else {
				var field = el.closest( ".cu-field" );
				var lab = field && field.querySelector( "label" );
				if ( lab && ! lab.contains( el ) ) name = lab.textContent;
			}
			name = ( name || el.getAttribute( "placeholder" ) || el.getAttribute( "title" ) || "" ).replace( /\s+/g, " " ).trim();
			if ( name ) el.setAttribute( "aria-label", name );
		} );
	}

	/**
	 * Courbe monotone (Fritsch–Carlson) : lisse sans dépasser les valeurs réelles
	 * (jamais de creux sous zéro ni de pic inventé entre deux jours).
	 */
	function smoothPath( points ) {
		var n = points.length;
		if ( ! n ) return "";
		if ( 1 === n ) return "M" + points[ 0 ][ 0 ] + "," + points[ 0 ][ 1 ];
		var dx = [], slope = [], tangent = [];
		for ( var a = 0; a < n - 1; a++ ) {
			dx[ a ] = points[ a + 1 ][ 0 ] - points[ a ][ 0 ];
			slope[ a ] = dx[ a ] ? ( points[ a + 1 ][ 1 ] - points[ a ][ 1 ] ) / dx[ a ] : 0;
		}
		tangent[ 0 ] = slope[ 0 ];
		tangent[ n - 1 ] = slope[ n - 2 ];
		for ( var b = 1; b < n - 1; b++ ) {
			tangent[ b ] = slope[ b - 1 ] * slope[ b ] <= 0 ? 0 : ( slope[ b - 1 ] + slope[ b ] ) / 2;
		}
		for ( var c = 0; c < n - 1; c++ ) {
			if ( 0 === slope[ c ] ) {
				tangent[ c ] = 0;
				tangent[ c + 1 ] = 0;
				continue;
			}
			var alpha = tangent[ c ] / slope[ c ];
			var beta = tangent[ c + 1 ] / slope[ c ];
			var norm = alpha * alpha + beta * beta;
			if ( norm > 9 ) {
				var tau = 3 / Math.sqrt( norm );
				tangent[ c ] = tau * alpha * slope[ c ];
				tangent[ c + 1 ] = tau * beta * slope[ c ];
			}
		}
		var path = "M" + points[ 0 ][ 0 ].toFixed( 1 ) + "," + points[ 0 ][ 1 ].toFixed( 1 );
		for ( var k = 0; k < n - 1; k++ ) {
			var third = dx[ k ] / 3;
			path += "C" + ( points[ k ][ 0 ] + third ).toFixed( 1 ) + "," + ( points[ k ][ 1 ] + tangent[ k ] * third ).toFixed( 1 ) +
				" " + ( points[ k + 1 ][ 0 ] - third ).toFixed( 1 ) + "," + ( points[ k + 1 ][ 1 ] - tangent[ k + 1 ] * third ).toFixed( 1 ) +
				" " + points[ k + 1 ][ 0 ].toFixed( 1 ) + "," + points[ k + 1 ][ 1 ].toFixed( 1 );
		}
		return path;
	}

	/**
	 * Échelle « ronde » pour des comptages : pas entier de 1, 2, 2,5 (≥ 10), 5 ou
	 * 10 × 10^n, choisi pour que `ticks` intervalles couvrent le maximum. Les
	 * graduations restent ainsi exactes (jamais « 1,33 » arrondi en « 1 »).
	 */
	function niceScale( maxValue, ticks ) {
		ticks = ticks || 4;
		var raw = Math.max( 1, Number( maxValue ) || 0 ) / ticks;
		var power = Math.pow( 10, Math.floor( Math.log( raw ) / Math.LN10 ) );
		var factors = power >= 10 ? [ 1, 2, 2.5, 5, 10 ] : [ 1, 2, 5, 10 ];
		var step = 10 * power;
		for ( var f = 0; f < factors.length; f++ ) {
			if ( factors[ f ] * power >= raw ) { step = factors[ f ] * power; break; }
		}
		step = Math.max( 1, step );
		return { max: step * ticks, step: step, ticks: ticks };
	}

	/**
	 * Tableaux qui défilent horizontalement (écran étroit, colonnes nombreuses) :
	 * la zone devient atteignable au clavier (WCAG 2.1.1) uniquement quand elle
	 * défile vraiment, sans ajouter d'arrêt de tabulation inutile sinon. Les zones
	 * déjà déclarées dans le HTML (tabindex présent) sont laissées telles quelles.
	 */
	function syncScrollRegions() {
		document.querySelectorAll( ".cu-wrap .cu-table-wrap, .cu-wrap .cu-scroll-region" ).forEach( function ( region ) {
			var managed = "1" === region.getAttribute( "data-cu-auto-region" );
			if ( ! managed && region.hasAttribute( "tabindex" ) ) return;
			var scrolls = region.scrollWidth > region.clientWidth + 1;
			if ( scrolls && ! managed ) {
				var section = region.closest( ".cu-section" );
				var heading = section && section.querySelector( ".cu-section-title" );
				var title = heading ? heading.textContent.replace( /\s+/g, " " ).trim() : "";
				region.setAttribute( "tabindex", "0" );
				region.setAttribute( "role", "region" );
				region.setAttribute( "aria-label", title ? "Tableau défilant : " + title : "Tableau défilant" );
				region.setAttribute( "data-cu-auto-region", "1" );
			} else if ( ! scrolls && managed ) {
				[ "tabindex", "role", "aria-label", "data-cu-auto-region" ].forEach( function ( attr ) { region.removeAttribute( attr ); } );
			}
		} );
	}

	function watchScrollRegions() {
		var host = document.querySelector( ".cu-wrap" );
		if ( ! host ) return;
		var timer = null;
		var schedule = function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( syncScrollRegions, 200 );
		};
		schedule();
		window.addEventListener( "resize", schedule );
		// Les tableaux sont remplis après les appels REST : réévaluer à chaque rendu.
		if ( window.MutationObserver ) {
			new window.MutationObserver( schedule ).observe( host, { childList: true, subtree: true } );
		}
	}

	document.addEventListener( "DOMContentLoaded", applyMotion );
	document.addEventListener( "DOMContentLoaded", function () { labelUnnamedControls(); } );
	document.addEventListener( "DOMContentLoaded", watchScrollRegions );

	window.cuAdmin = {
		config: config,
		restUrl: restUrl,
		siteTime: siteTime,
		apiFetch: apiFetch,
		toast: toast,
		formatNumber: formatNumber,
		formatDuration: formatDuration,
		deltaBadge: deltaBadge,
		sparkline: sparkline,
		lineChart: lineChart,
		donutChart: donutChart,
		debounce: debounce,
		confirmAction: confirmAction,
		openModal: openModal,
		labelUnnamedControls: labelUnnamedControls,
		cssVar: cssVar,
		vizColors: vizColors,
		icon: icon,
		withAlpha: withAlpha,
		smoothPath: smoothPath,
		niceScale: niceScale,
	};
} )();

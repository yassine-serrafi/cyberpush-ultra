/* CyberPush Ultra — section « Contacts & leads » de la page Analytics (cu-contacts.js)
 * WhatsApp (clics qui ouvrent une conversation) et Contact Form 7 (vu, commencé,
 * envoyé, refusé, abandonné) réunis en « leads » : héros en relief, colonnes
 * empilées jour par jour (infobulle épinglable, clavier), anneau de répartition,
 * cartes détaillées, carte jour × heure et sources. Vanilla JS, sans dépendance.
 * Dépend de cu-admin.js (window.cuAdmin).
 */
( function () {
	"use strict";

	var SVG_NS = "http://www.w3.org/2000/svg";
	var uid = 0;

	// -------------------------------------------------------------------------
	// Icônes (trait 2 px, grille 24, comme cu-admin.js)
	// -------------------------------------------------------------------------
	var ICONS = {
		wa: '<path d="M3.6 20.4 4.9 16A8.6 8.6 0 1 1 8 19.1z"/><path d="M9.2 8.4c.2-.5.5-.5.8-.5h.5c.2 0 .4 0 .5.4l.7 1.6c.1.2.1.4 0 .5l-.5.7c-.1.2-.1.3 0 .5a6 6 0 0 0 2.6 2.5c.2.1.4.1.5-.1l.6-.7c.2-.2.3-.2.5-.1l1.6.8c.2.1.3.2.3.4 0 .5-.2 1.1-.7 1.5-.6.4-1.4.6-2.5.2a9.2 9.2 0 0 1-4.6-4c-.7-1.3-.7-2.5-.3-3.7z"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7 8.5 6 8.5-6"/>',
		target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2"/>',
		funnel: '<path d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		mobile: '<rect x="7" y="2.5" width="10" height="19" rx="2.2"/><path d="M11 18h2"/>',
		desktop: '<rect x="2.5" y="4" width="19" height="12.5" rx="2"/><path d="M8 20.5h8"/><path d="M12 16.5v4"/>',
		tablet: '<rect x="4" y="2.5" width="16" height="19" rx="2.2"/><path d="M11 18h2"/>',
		pin: '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.4"/>',
		float: '<rect x="3" y="3" width="13" height="13" rx="2.5"/><circle cx="17.5" cy="17.5" r="3.5"/>',
		cursor: '<path d="m4.5 4.5 6.8 16 2.3-6.8 6.9-2.4z"/>',
		alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		ban: '<circle cx="12" cy="12" r="9"/><path d="m5.7 5.7 12.6 12.6"/>',
		x: '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
		exit: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
		shield: '<path d="M12 3l8 3v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="m9 12 2 2 4-4"/>',
		spark: '<path d="M12 3l1.8 4.6L18.5 9l-4.7 1.4L12 15l-1.8-4.6L5.5 9l4.7-1.4z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
		trophy: '<path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3"/><path d="M7 5H4v2a3 3 0 0 0 3 3"/>',
		flame: '<path d="M12 22c4 0 7-2.7 7-6.8 0-4.2-3.2-6.4-4.4-10.2-2 1.2-3 3.3-3 5.1-1.2-.5-2-1.6-2.3-2.9C7.1 9.2 5 11.8 5 15.2 5 19.3 8 22 12 22z"/>',
		route: '<circle cx="6" cy="19" r="2.5"/><circle cx="18" cy="5" r="2.5"/><path d="M8.5 19H16a3.5 3.5 0 0 0 0-7H8a3.5 3.5 0 0 1 0-7h7.5"/>',
		edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
		user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		repeat: '<path d="M17 2l4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
		hourglass: '<path d="M6 2h12"/><path d="M6 22h12"/><path d="M7 2v4a5 5 0 0 0 10 0V2"/><path d="M7 22v-4a5 5 0 0 1 10 0v4"/>',
		gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		pinned: '<path d="M12 17v5"/><path d="M9 3h6l-1 6 4 4H6l4-4z"/>',
		globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z"/>',
	};
	function icon( name, cls ) {
		return '<svg class="cu-ct-ic' + ( cls ? " " + cls : "" ) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ( ICONS[ name ] || ICONS.spark ) + "</svg>";
	}

	// -------------------------------------------------------------------------
	// Formats (français)
	// -------------------------------------------------------------------------
	function esc( value ) {
		return String( null == value ? "" : value ).replace( /&/g, "&amp;" ).replace( /</g, "&lt;" ).replace( />/g, "&gt;" ).replace( /"/g, "&quot;" );
	}
	function int( value ) {
		var parsed = Math.round( Number( value ) );
		return isFinite( parsed ) ? parsed : 0;
	}
	function num( value ) {
		return cuAdmin.formatNumber( int( value ) );
	}
	function pct( value, digits ) {
		if ( null == value ) return "n/d"; // aucune visite suivie : jamais un faux 0 %
		var factor = Math.pow( 10, null == digits ? 1 : digits );
		var rounded = Math.round( ( Number( value ) || 0 ) * factor ) / factor;
		return String( rounded ).replace( ".", "," ) + " %";
	}
	// En français, 0 et 1 restent au singulier.
	function plural( count, one, many ) {
		return Math.abs( Number( count ) || 0 ) >= 2 ? many : one;
	}
	function dur( seconds ) {
		if ( null == seconds ) return "—";
		var total = Math.max( 0, int( seconds ) );
		if ( total < 60 ) return total + " s";
		if ( total < 3600 ) {
			var secs = total % 60;
			return Math.floor( total / 60 ) + " min" + ( secs ? " " + ( secs < 10 ? "0" : "" ) + secs : "" );
		}
		var mins = Math.floor( total % 3600 / 60 );
		return Math.floor( total / 3600 ) + " h " + ( mins < 10 ? "0" : "" ) + mins;
	}
	function ago( seconds ) {
		var total = Math.max( 0, int( seconds ) );
		if ( total < 60 ) return "à l’instant";
		if ( total < 3600 ) return "il y a " + Math.floor( total / 60 ) + " min";
		if ( total < 86400 ) return "il y a " + Math.floor( total / 3600 ) + " h";
		var days = Math.floor( total / 86400 );
		return "il y a " + days + " " + plural( days, "jour", "jours" );
	}
	// Numéro international lisible : « +212 612 345 678 ».
	function phone( digits ) {
		var clean = String( digits || "" ).replace( /\D+/g, "" );
		return clean ? "+" + clean.replace( /(\d)(?=(\d{3})+$)/g, "$1 " ) : "";
	}
	function toDate( key ) {
		var parts = String( key || "" ).split( "-" );
		return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] || 1 ) - 1, Number( parts[ 2 ] || 1 ) );
	}
	function capitalize( text ) {
		return text ? text.charAt( 0 ).toUpperCase() + text.slice( 1 ) : text;
	}
	function longDay( key ) {
		return capitalize( toDate( key ).toLocaleDateString( "fr-FR", { weekday: "long", day: "numeric", month: "long" } ) );
	}
	function tickDay( key, compact ) {
		return toDate( key ).toLocaleDateString( "fr-FR", compact ? { day: "2-digit", month: "2-digit" } : { weekday: "short", day: "2-digit" } );
	}
	function reducedMotion( node ) {
		var wrap = node && node.closest ? node.closest( ".cu-wrap" ) : null;
		if ( wrap && "off" === wrap.getAttribute( "data-cu-motion" ) ) return true;
		return !! ( window.matchMedia && window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches );
	}
	function isSheet() {
		return !! ( window.matchMedia && window.matchMedia( "(max-width: 600px)" ).matches );
	}
	function svgEl( tag, attrs, parent ) {
		var node = document.createElementNS( SVG_NS, tag );
		Object.keys( attrs || {} ).forEach( function ( name ) { node.setAttribute( name, attrs[ name ] ); } );
		if ( parent ) parent.appendChild( node );
		return node;
	}
	function easeOut( t ) {
		return 1 - Math.pow( 1 - t, 3 );
	}

	// -------------------------------------------------------------------------
	// Libellés
	// -------------------------------------------------------------------------
	var WIDGET_NAMES = {
		joinchat: "Joinchat",
		"click-to-chat": "Click to Chat",
		"nta-whatsapp": "WP WhatsApp (NinjaTeam)",
		qlwapp: "Social Chat (QuadLayers)",
		chaty: "Chaty",
		"whatsapp-button": "Bouton WhatsApp",
		link: "Lien WhatsApp",
		script: "Ouverture par script",
		marker: "Bouton marqué (data-cu-whatsapp)",
	};
	var KIND_NAMES = { chat: "Conversation", group: "Groupe", channel: "Chaîne", catalog: "Catalogue", "short": "Lien court", unknown: "Conversation" };
	var WEEKDAYS = [ "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche" ];
	var WEEKDAYS_SHORT = [ "Lun", "Mar", "Mer", "Jeu", "Ven", "Sam", "Dim" ];

	function deviceKey( device ) {
		var value = String( device || "" ).toLowerCase();
		if ( "mobile" === value ) return "mobile";
		if ( "tablette" === value || "tablet" === value ) return "tablet";
		if ( "desktop" === value || "ordinateur" === value ) return "desktop";
		return value ? "other" : "";
	}
	var DEVICE_LABELS = { mobile: "Mobile", desktop: "Ordinateur", tablet: "Tablette", other: "Autre" };
	function widgetName( key ) {
		return WIDGET_NAMES[ key ] || ( key ? capitalize( String( key ).replace( /[-_]+/g, " " ) ) : "Lien WhatsApp" );
	}

	// -------------------------------------------------------------------------
	// Petits blocs HTML
	// -------------------------------------------------------------------------
	// Évolution : sans période précédente, « nouveau » plutôt qu'un faux « +100 % ».
	function delta( value, prev ) {
		if ( null === value ) return '<span class="cu-kpi-delta is-new" title="Aucune donnée sur la période précédente">nouveau</span>';
		if ( ! prev && ! value ) return "";
		return cuAdmin.deltaBadge( null == value ? 0 : value );
	}
	function kpi( label, value, sub, tone ) {
		return '<div class="cu-ct-kpi' + ( tone ? " is-" + tone : "" ) + '"><span>' + esc( label ) + "</span><b>" + value + "</b>" + ( sub ? "<small>" + sub + "</small>" : "" ) + "</div>";
	}
	function chip( iconName, label, value, tone, title ) {
		return '<span class="cu-ct-chip' + ( tone ? " is-" + tone : "" ) + '"' + ( title ? ' title="' + esc( title ) + '"' : "" ) + '><span class="cu-ct-dot">' + icon( iconName ) + "</span>" + esc( label ) + ( null != value ? " <b>" + value + "</b>" : "" ) + "</span>";
	}
	function block( title, iconName, body, extraClass ) {
		return '<section class="cu-ct-block' + ( extraClass ? " " + extraClass : "" ) + '"><h3>' + icon( iconName ) + esc( title ) + "</h3>" + body + "</section>";
	}
	function emptyLine( text ) {
		return '<p class="cu-ct-muted">' + esc( text ) + "</p>";
	}
	function split( parts, total, ariaLabel ) {
		var used = parts.filter( function ( part ) { return part.value > 0; } );
		if ( ! total || ! used.length ) return "";
		return '<div class="cu-ct-split" role="img" aria-label="' + esc( ariaLabel + " : " + used.map( function ( part ) { return part.label + " " + Math.round( part.value / total * 100 ) + " %"; } ).join( ", " ) ) + '">' +
			used.map( function ( part ) {
				var share = part.value / total * 100;
				return '<span class="is-' + part.tone + '" style="flex:' + part.value + '" title="' + esc( part.label + " : " + num( part.value ) + " (" + Math.round( share ) + " %)" ) + '">' + ( share >= 14 ? Math.round( share ) + " %" : "" ) + "</span>";
			} ).join( "" ) + "</div>" +
			'<div class="cu-ct-split-key">' + used.map( function ( part ) {
				return '<span><i class="is-' + part.tone + '"></i>' + icon( part.icon ) + esc( part.label ) + " <b>" + num( part.value ) + "</b></span>";
			} ).join( "" ) + "</div>";
	}
	function pageLink( page ) {
		var title = page.title || page.path || "/";
		return page.url ? '<a class="cu-ct-link" href="' + esc( page.url ) + '" target="_blank" rel="noopener noreferrer" title="' + esc( page.path || page.url ) + '">' + esc( title ) + "</a>" : esc( title );
	}
	function visitorTags( row, ctx ) {
		var tags = [];
		tags.push( '<span class="cu-ct-tag">' + esc( ctx.sourceLabel( row.source ) ) + "</span>" );
		var dev = deviceKey( row.device );
		if ( dev ) tags.push( '<span class="cu-ct-tag">' + icon( "other" === dev ? "desktop" : dev ) + esc( DEVICE_LABELS[ dev ] ) + "</span>" );
		if ( row.country ) tags.push( '<span class="cu-ct-tag"><span class="cu-ct-cc">' + esc( row.country ) + "</span></span>" );
		if ( row.anon ) tags.push( '<span class="cu-ct-tag">Visiteur anonyme (contexte de cette page seulement)</span>' );
		else tags.push( '<span class="cu-ct-tag">' + icon( row.is_new ? "user" : "repeat" ) + ( row.is_new ? "Nouveau" : "Fidèle" ) + "</span>" );
		return tags;
	}

	// -------------------------------------------------------------------------
	// Points forts calculés (phrases courtes, chiffres vérifiables plus bas)
	// -------------------------------------------------------------------------
	function peakSlot( heat ) {
		var best = { value: 0, day: -1, hour: -1 };
		for ( var dayIndex = 0; dayIndex < 7; dayIndex++ ) {
			for ( var hourIndex = 0; hourIndex < 24; hourIndex++ ) {
				var value = int( heat.wa[ dayIndex ][ hourIndex ] ) + int( heat.cf7[ dayIndex ][ hourIndex ] );
				if ( value > best.value ) best = { value: value, day: dayIndex, hour: hourIndex };
			}
		}
		return best;
	}
	function insights( data, ctx ) {
		var out = [];
		var wa = data.whatsapp || {};
		var cf = data.cf7 || {};
		var bestSource = ( data.sources || [] ).filter( function ( src ) { return src.sessions >= 20 && src.visitors > 0; } )
			.sort( function ( a, b ) { return b.rate - a.rate; } )[ 0 ];
		if ( bestSource ) {
			out.push( { icon: "trophy", html: "<b>" + esc( ctx.sourceLabel( bestSource.source ) ) + "</b> convertit le mieux : <b>" + pct( bestSource.rate ) + "</b> des visites vous contactent" } );
		}
		var peak = data.heat ? peakSlot( data.heat ) : { value: 0 };
		if ( peak.value >= 2 ) {
			out.push( { icon: "flame", html: "Pic de contacts le <b>" + WEEKDAYS[ peak.day ] + " entre " + peak.hour + " h et " + ( peak.hour + 1 ) + " h</b>" } );
		}
		var devices = wa.devices || [];
		var devTotal = devices.reduce( function ( sum, row ) { return sum + int( row.count ); }, 0 );
		var mobileRow = devices.filter( function ( row ) { return "mobile" === deviceKey( row.key ); } )[ 0 ];
		if ( devTotal >= 5 && mobileRow ) {
			out.push( { icon: "mobile", html: "<b>" + pct( mobileRow.count / devTotal * 100, 0 ) + "</b> des clics WhatsApp viennent d’un mobile" } );
		}
		if ( null != wa.median_seconds && int( wa.clicks ) >= 3 ) {
			out.push( { icon: "hourglass", html: "On vous écrit sur WhatsApp après <b>" + dur( wa.median_seconds ) + "</b> sur la page (médiane)" } );
		}
		var fields = cf.fields || [];
		if ( fields.length && int( fields[ 0 ].count ) >= 2 ) {
			out.push( { icon: "alert", tone: "warn", html: "Champ le plus refusé : <b>" + esc( fields[ 0 ].field ) + "</b> (" + num( fields[ 0 ].count ) + " " + plural( fields[ 0 ].count, "refus", "refus" ) + ")" } );
		}
		if ( int( cf.attempts ) >= 5 && Number( cf.success_rate ) < 80 ) {
			out.push( { icon: "alert", tone: "warn", html: "Seulement <b>" + pct( cf.success_rate, 0 ) + "</b> des envois de formulaire aboutissent" } );
		}
		return out.slice( 0, 5 );
	}

	// -------------------------------------------------------------------------
	// Héros
	// -------------------------------------------------------------------------
	function mixRing( waCount, cfCount ) {
		var total = waCount + cfCount;
		var radius = 62, stroke = 20, size = 172, center = size / 2;
		var circ = 2 * Math.PI * radius;
		var gap = total && waCount && cfCount ? 6 : 0;
		var waLen = total ? Math.max( 0, circ * waCount / total - gap ) : 0;
		var cfLen = total ? Math.max( 0, circ * cfCount / total - gap ) : 0;
		var id = "cu-ct-mix-" + ( ++uid );
		var waShare = total ? Math.round( waCount / total * 100 ) : 0;
		var lead = waCount >= cfCount ? { share: waShare, label: "WhatsApp" } : { share: 100 - waShare, label: "Formulaires" };
		var svg = '<svg viewBox="0 0 ' + size + " " + size + '" aria-hidden="true" focusable="false"><defs>' +
			'<linearGradient id="' + id + '-wa" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#5ee38f"/><stop offset="1" stop-color="#0e8f45"/></linearGradient>' +
			'<linearGradient id="' + id + '-cf" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a98cff"/><stop offset="1" stop-color="#5434c0"/></linearGradient>' +
			'<radialGradient id="' + id + '-dome" cx="50%" cy="38%" r="62%"><stop offset="0" stop-color="#ffffff"/><stop offset="0.7" stop-color="#f6f8fe"/><stop offset="1" stop-color="#e6ebf8"/></radialGradient>' +
			'<filter id="' + id + '-lift" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="4" stdDeviation="5" flood-color="#141a33" flood-opacity="0.16"/></filter>' +
			"</defs>" +
			'<circle class="cu-ct-mix-track" cx="' + center + '" cy="' + center + '" r="' + radius + '" stroke-width="' + stroke + '"/>' +
			'<g transform="rotate(-90 ' + center + " " + center + ')" filter="url(#' + id + '-lift)">' +
			( waLen ? '<circle class="cu-ct-mix-arc" cx="' + center + '" cy="' + center + '" r="' + radius + '" stroke="url(#' + id + '-wa)" stroke-width="' + stroke + '" stroke-dasharray="' + waLen.toFixed( 2 ) + " " + circ.toFixed( 2 ) + '" stroke-dashoffset="' + ( -gap / 2 ).toFixed( 2 ) + '"/>' : "" ) +
			( cfLen ? '<circle class="cu-ct-mix-arc" cx="' + center + '" cy="' + center + '" r="' + radius + '" stroke="url(#' + id + '-cf)" stroke-width="' + stroke + '" stroke-dasharray="' + cfLen.toFixed( 2 ) + " " + circ.toFixed( 2 ) + '" stroke-dashoffset="' + ( -( circ * waCount / total ) - gap / 2 ).toFixed( 2 ) + '"/>' : "" ) +
			"</g>" +
			'<circle class="cu-ct-mix-shine" cx="' + center + '" cy="' + center + '" r="' + ( radius + stroke / 2 - 2 ) + '"/>' +
			'<circle cx="' + center + '" cy="' + center + '" r="' + ( radius - stroke / 2 - 5 ) + '" fill="url(#' + id + '-dome)" filter="url(#' + id + '-lift)"/>' +
			"</svg>";
		return '<div class="cu-ct-mix" role="img" aria-label="' + esc( total ? "Répartition des leads : WhatsApp " + waShare + " %, formulaires " + ( 100 - waShare ) + " %" : "Aucun lead sur la période" ) + '">' + svg +
			'<div class="cu-ct-mix-center"><b>' + ( total ? lead.share + " %" : "—" ) + "</b><span>" + esc( total ? lead.label : "aucun lead" ) + "</span></div></div>";
	}

	function heroHtml( data, ctx ) {
		var leads = data.leads || {};
		var wa = data.whatsapp || {};
		var cf = data.cf7 || {};
		var total = int( leads.value );
		var waCount = int( wa.clicks );
		// Messages de formulaire comptés UNE fois : le serveur (envoi réellement parti)
		// fait foi, le navigateur n'en voit qu'une partie.
		var cfCount = null != cf.messages ? int( cf.messages ) : int( cf.sent );
		var cfDelta = null != cf.messages ? cf.messages_delta : cf.delta;
		var cfPrev = null != cf.messages ? cf.prev_messages : cf.prev;
		var period = int( data.range ) + " derniers jours";
		var lede = total
			? "<b>" + num( waCount ) + "</b> " + plural( waCount, "clic WhatsApp", "clics WhatsApp" ) + " (intentions : l’envoi du message n’est pas vérifiable) et <b>" + num( cfCount ) + "</b> " + plural( cfCount, "message de formulaire", "messages de formulaires" ) +
				" : <b>" + pct( leads.rate ) + "</b> des visites se terminent par une prise de contact" +
				( int( leads.visitors ) ? " (" + num( leads.visitors ) + " " + plural( leads.visitors, "visiteur", "visiteurs" ) + ")." : "." )
			: "Aucune prise de contact sur les " + esc( period ) + ". Le suivi est prêt : chaque clic WhatsApp et chaque message de formulaire apparaîtra ici.";
		var rateDelta = Number( leads.prev_rate ) > 0 ? ( Number( leads.rate ) - Number( leads.prev_rate ) ) / Number( leads.prev_rate ) * 100 : ( Number( leads.rate ) > 0 ? null : 0 );
		var pills =
			'<li class="cu-ct-pill is-wa"><span class="cu-ct-orb is-wa">' + icon( "wa" ) + '</span><span class="cu-ct-pill-txt"><span>WhatsApp</span><b>' + num( waCount ) + "</b><small>" + plural( waCount, "clic", "clics" ) + " " + delta( wa.delta, wa.prev ) + "</small></span></li>" +
			'<li class="cu-ct-pill is-cf"><span class="cu-ct-orb is-cf">' + icon( "mail" ) + '</span><span class="cu-ct-pill-txt"><span>Formulaires</span><b>' + num( cfCount ) + "</b><small>" + plural( cfCount, "message", "messages" ) + " " + delta( cfDelta, cfPrev ) + "</small></span></li>" +
			'<li class="cu-ct-pill is-rate"><span class="cu-ct-orb is-rate">' + icon( "target" ) + '</span><span class="cu-ct-pill-txt"><span>Taux de contact</span><b>' + pct( leads.rate ) + "</b><small>des visites " + ( Number( leads.rate ) || Number( leads.prev_rate ) ? delta( rateDelta, leads.prev_rate ) : "" ) + "</small></span></li>";
		var tips = insights( data, ctx );
		return '<div class="cu-ct-hero">' +
			'<div class="cu-ct-hero-top">' +
				'<div class="cu-ct-hero-main">' +
					'<span class="cu-ct-eyebrow">' + icon( "spark" ) + "Leads · " + esc( period ) + "</span>" +
					'<div class="cu-ct-big"><b class="cu-ct-num" data-count="' + total + '">' + num( total ) + '</b><span class="cu-ct-unit">' + plural( total, "prise de contact", "prises de contact" ) + "</span>" + delta( leads.delta, leads.prev ) +
					( int( leads.prev ) || total ? '<span class="cu-ct-vs">vs ' + num( leads.prev ) + " sur les " + esc( period ) + " précédents</span>" : "" ) + "</div>" +
					'<p class="cu-ct-lede">' + lede + "</p>" +
				"</div>" +
				'<ul class="cu-ct-pills">' + pills + "</ul>" +
				mixRing( waCount, cfCount ) +
			"</div>" +
			'<div class="cu-ct-chart" data-ct="chart"></div>' +
			( tips.length ? '<ul class="cu-ct-insights">' + tips.map( function ( tip ) {
				return '<li class="cu-ct-insight' + ( tip.tone ? " is-" + tip.tone : "" ) + '">' + icon( tip.icon ) + "<span>" + tip.html + "</span></li>";
			} ).join( "" ) + "</ul>" : "" ) +
		"</div>";
	}

	// -------------------------------------------------------------------------
	// Carte WhatsApp
	// -------------------------------------------------------------------------
	function statusPill( on, offText ) {
		return on ? '<span class="cu-ct-status is-on"><i></i>Suivi actif</span>' : '<span class="cu-ct-status"><i></i>' + esc( offText || "Suivi désactivé" ) + "</span>";
	}
	function settingsHint( ctx, text ) {
		return '<div class="cu-ct-note">' + icon( "gear" ) + "<p>" + esc( text ) + ' <a href="' + esc( ctx.settingsUrl ) + '">Réglages &gt; Analytics</a></p></div>';
	}

	function whatsappCard( data, ctx ) {
		var wa = data.whatsapp || {};
		var on = !! ( data.tracking && data.tracking.whatsapp );
		var clicks = int( wa.clicks );
		var head = '<header class="cu-ct-card-head"><span class="cu-ct-orb is-wa is-lg">' + icon( "wa" ) + '</span><div><h2>WhatsApp</h2><p>Clics qui ouvrent WhatsApp : une intention de contact, l’envoi du message n’est pas vérifiable</p></div>' + statusPill( on ) + "</header>";
		var kpis = '<div class="cu-ct-kpis">' +
			kpi( "Clics", num( clicks ), delta( wa.delta, wa.prev ) || "&nbsp;", "wa" ) +
			kpi( "Visiteurs uniques", num( wa.visitors ), int( wa.prev_visitors ) ? "contre " + num( wa.prev_visitors ) : "&nbsp;" ) +
			kpi( "Taux de clic", pct( wa.rate, 2 ), "des visites" ) +
			kpi( "Avant le clic", dur( wa.median_seconds ), null != wa.avg_scroll ? "lecture " + int( wa.avg_scroll ) + " %" : "médiane" ) +
			"</div>";
		if ( ! on ) {
			return '<article class="cu-ct-card is-wa">' + head + kpis + settingsHint( ctx, "Le suivi des clics WhatsApp est désactivé : aucun nouveau clic n’est enregistré." ) + "</article>";
		}
		if ( ! clicks ) {
			return '<article class="cu-ct-card is-wa">' + head + kpis +
				'<div class="cu-ct-note is-wa">' + icon( "wa" ) + "<p><b>Aucun clic WhatsApp sur la période.</b> Sont reconnus automatiquement : liens wa.me, api.whatsapp.com, web.whatsapp.com, whatsapp://, groupes et chaînes, ainsi que les boutons Joinchat, Click to Chat, NinjaTeam, QuadLayers et Chaty. Pour tester : ouvrez le site en navigation privée, cliquez sur votre bouton, puis « Actualiser ».</p></div>" +
				"</article>";
		}

		var placements = {};
		( wa.placements || [] ).forEach( function ( row ) { placements[ row.key || "" ] = int( row.count ); } );
		var placeTotal = ( placements.floating || 0 ) + ( placements.inline || 0 );
		var where = split( [
			{ label: "Bouton flottant", value: placements.floating || 0, tone: "wa", icon: "float" },
			{ label: "Dans la page", value: placements.inline || 0, tone: "teal", icon: "cursor" },
		], placeTotal, "Emplacement des clics" ) || emptyLine( "Emplacement non mesuré pour ces clics." );

		var pages = ( wa.pages || [] );
		var maxClicks = pages.reduce( function ( max, page ) { return Math.max( max, int( page.clicks ) ); }, 0 ) || 1;
		var pagesHtml = pages.length ? '<ol class="cu-ct-rank is-wa">' + pages.map( function ( page ) {
			return "<li>" +
				'<span class="cu-ct-rank-name">' + pageLink( page ) + "</span>" +
				'<span class="cu-ct-rank-val">' + num( page.clicks ) + ( int( page.sessions ) ? ' <span class="cu-ct-rate" title="' + esc( num( page.visitors ) + " visite(s) avec clic sur " + num( page.sessions ) + " visites de cette page" ) + '">' + pct( page.rate ) + "</span>" : "" ) + "</span>" +
				'<span class="cu-ct-rank-bar"><span style="width:' + Math.max( 3, int( page.clicks ) / maxClicks * 100 ).toFixed( 1 ) + '%"></span></span>' +
				"</li>";
		} ).join( "" ) + "</ol>" : emptyLine( "Aucune page sur la période." );

		var devices = ( wa.devices || [] ).map( function ( row ) { return { key: deviceKey( row.key ) || "other", count: int( row.count ) }; } );
		var devMap = {};
		devices.forEach( function ( row ) { devMap[ row.key ] = ( devMap[ row.key ] || 0 ) + row.count; } );
		var devTotal = devices.reduce( function ( sum, row ) { return sum + row.count; }, 0 );
		var devicesHtml = split( [
			{ label: "Mobile", value: devMap.mobile || 0, tone: "wa", icon: "mobile" },
			{ label: "Ordinateur", value: devMap.desktop || 0, tone: "teal", icon: "desktop" },
			{ label: "Tablette", value: devMap.tablet || 0, tone: "cf", icon: "tablet" },
			{ label: "Autre", value: devMap.other || 0, tone: "gray", icon: "desktop" },
		], devTotal, "Appareils" ) || emptyLine( "—" );

		var audience = wa.audience || {};
		var audTotal = int( audience.new ) + int( audience.returning );
		var audienceHtml = split( [
			{ label: "Nouveaux", value: int( audience.new ), tone: "wa", icon: "user" },
			{ label: "Fidèles", value: int( audience.returning ), tone: "cf", icon: "repeat" },
		], audTotal, "Visiteurs" ) || "";

		var numbers = ( wa.numbers || [] ).map( function ( row ) {
			return chip( "wa", phone( row.phone ), num( row.count ), "wa", "Dernier clic " + ago( row.ago ) );
		} );
		var widgets = ( wa.widgets || [] ).map( function ( row ) {
			return chip( "link" === row.key ? "cursor" : "spark", widgetName( row.key ), num( row.count ) );
		} );
		var kinds = ( wa.kinds || [] ).filter( function ( row ) { return row.key && "chat" !== row.key && "unknown" !== row.key; } ).map( function ( row ) {
			return chip( "route", KIND_NAMES[ row.key ] || row.key, num( row.count ) );
		} );
		var countries = ( wa.countries || [] ).filter( function ( row ) { return row.key; } ).map( function ( row ) {
			return '<span class="cu-ct-chip"><span class="cu-ct-cc">' + esc( row.key ) + "</span>" + esc( ctx.countryName( row.key ).replace( /\s*\([A-Z]{2}\)$/, "" ) ) + " <b>" + num( row.count ) + "</b></span>";
		} );

		var recent = ( wa.recent || [] ).map( function ( row ) {
			var tags = visitorTags( row, ctx );
			tags.unshift( '<span class="cu-ct-tag is-wa">' + icon( "floating" === row.placement ? "float" : "cursor" ) + ( "floating" === row.placement ? "Bouton flottant" : ( "inline" === row.placement ? "Dans la page" : esc( widgetName( row.widget ) ) ) ) + "</span>" );
			return '<li><span class="cu-ct-when" title="' + esc( row.at ) + '">' + esc( ago( row.ago ) ) + '</span><div class="cu-ct-feed-main"><div class="cu-ct-feed-title">' + pageLink( row ) + ( row.label ? ' <span class="cu-ct-feed-label">« ' + esc( row.label ) + " »</span>" : "" ) + '</div><div class="cu-ct-feed-meta">' + tags.join( "" ) + ( row.phone ? '<span class="cu-ct-tag">' + esc( phone( row.phone ) ) + "</span>" : "" ) + "</div></div></li>";
		} );

		return '<article class="cu-ct-card is-wa">' + head + kpis +
			block( "Où ils cliquent", "pin", where ) +
			block( "Pages qui ouvrent le plus de conversations", "route", pagesHtml ) +
			'<div class="cu-ct-cols">' +
				block( "Appareils", "mobile", devicesHtml ) +
				( audienceHtml ? block( "Visiteurs", "user", audienceHtml ) : "" ) +
			"</div>" +
			( numbers.length || widgets.length ? block( "Numéros et boutons", "wa", '<div class="cu-ct-chips">' + numbers.concat( widgets, kinds ).join( "" ) + "</div>" ) : "" ) +
			( countries.length ? block( "Pays", "globe", '<div class="cu-ct-chips">' + countries.join( "" ) + "</div>" ) : "" ) +
			( recent.length ? block( "Derniers clics", "clock", '<ul class="cu-ct-feed">' + recent.join( "" ) + "</ul>" ) : "" ) +
			"</article>";
	}

	// -------------------------------------------------------------------------
	// Carte Contact Form 7
	// -------------------------------------------------------------------------
	function funnelHtml( funnel ) {
		var steps = [
			{ key: "views", label: "Formulaire vu", icon: "target" },
			{ key: "starts", label: "Commencé", icon: "edit" },
			{ key: "submitters", label: "Envoi tenté", icon: "cursor" },
			{ key: "senders", label: "Message reçu", icon: "mail" },
		];
		var top = int( funnel.views ) || 1;
		return '<ol class="cu-ct-funnel">' + steps.map( function ( step, index ) {
			var value = int( funnel[ step.key ] );
			var width = Math.max( 4, value / top * 100 );
			var prevValue = index ? int( funnel[ steps[ index - 1 ].key ] ) : 0;
			var keep = index && prevValue ? Math.round( value / prevValue * 100 ) : null;
			return '<li class="cu-ct-step">' +
				( null !== keep ? '<span class="cu-ct-keep" title="Part des visiteurs de l’étape précédente">' + keep + " % continuent</span>" : "" ) +
				'<span class="cu-ct-step-name">' + icon( step.icon ) + esc( step.label ) + "</span>" +
				'<span class="cu-ct-step-val">' + num( value ) + "</span>" +
				'<span class="cu-ct-step-bar"><span style="width:' + width.toFixed( 1 ) + '%"></span></span>' +
				"</li>";
		} ).join( "" ) + "</ol>";
	}

	function cf7Card( data, ctx ) {
		var cf = data.cf7 || {};
		var on = !! ( data.tracking && data.tracking.cf7 );
		var sent = int( cf.sent );
		var head = '<header class="cu-ct-card-head"><span class="cu-ct-orb is-cf is-lg">' + icon( "mail" ) + '</span><div><h2>Contact Form 7</h2><p>Messages envoyés depuis vos formulaires</p></div>' +
			( cf.active ? statusPill( on ) : '<span class="cu-ct-status is-off"><i></i>Non installé</span>' ) + "</header>";
		if ( ! cf.active && ! sent && ! ( cf.forms || [] ).length ) {
			return '<article class="cu-ct-card is-cf">' + head +
				'<div class="cu-ct-note is-cf">' + icon( "mail" ) + "<p><b>Contact Form 7 n’est pas actif sur ce site.</b> Dès qu’il l’est, chaque formulaire est suivi sans réglage : vues, débuts de saisie, envois réussis, champs refusés, spam, échecs d’envoi et abandons, avec une confirmation côté serveur.</p></div></article>";
		}
		var funnel = cf.funnel || {};
		var serverInfo = cf.server || {};
		var messages = null != cf.messages ? int( cf.messages ) : sent;
		var messagesSub = int( serverInfo.sent ) ? num( serverInfo.sent ) + " " + plural( serverInfo.sent, "confirmé", "confirmés" ) + " par le serveur" : ( delta( cf.delta, cf.prev ) || "&nbsp;" );
		var kpis = '<div class="cu-ct-kpis">' +
			kpi( "Messages reçus", num( messages ), messagesSub, "cf" ) +
			kpi( "Envois réussis", int( cf.attempts ) ? pct( cf.success_rate, 0 ) : "—", int( cf.attempts ) ? num( cf.attempts ) + " " + plural( cf.attempts, "tentative", "tentatives" ) : "aucune tentative" ) +
			kpi( "Conversion", int( funnel.views ) ? pct( cf.conversion ) : "—", "vus → reçus" ) +
			kpi( "Remplissage", dur( cf.median_fill ), "médiane" ) +
			"</div>";
		if ( ! on ) {
			return '<article class="cu-ct-card is-cf">' + head + kpis + settingsHint( ctx, "Le suivi Contact Form 7 est désactivé : aucun nouvel envoi n’est enregistré." ) + "</article>";
		}

		var issues = [
			chip( "alert", "Champs invalides", num( cf.invalid ), int( cf.invalid ) ? "warn" : "", "Envoi refusé : champ obligatoire vide, e-mail mal formé, case non cochée…" ),
			chip( "ban", "Spam bloqué", num( cf.spam ), "", "Envois classés comme spam par Contact Form 7 (Akismet, reCAPTCHA, Turnstile…)" ),
			chip( "x", "Échecs d’envoi", num( cf.failed ), int( cf.failed ) ? "bad" : "", "Le formulaire était valide mais le courriel n’est pas parti : vérifiez la configuration e-mail (SMTP)." ),
			chip( "exit", "Abandons", num( cf.abandon ), "", "Formulaire commencé puis page quittée sans envoi réussi." ),
		].join( "" );
		var fields = ( cf.fields || [] );
		var maxField = fields.reduce( function ( max, row ) { return Math.max( max, int( row.count ) ); }, 0 ) || 1;
		var fieldsHtml = fields.length ? '<ol class="cu-ct-rank is-warn">' + fields.map( function ( row ) {
			return '<li><span class="cu-ct-rank-name"><code>' + esc( row.field ) + '</code></span><span class="cu-ct-rank-val">' + num( row.count ) + '</span><span class="cu-ct-rank-bar"><span style="width:' + Math.max( 3, int( row.count ) / maxField * 100 ).toFixed( 1 ) + '%"></span></span></li>';
		} ).join( "" ) + "</ol>" : "";

		var forms = cf.forms || [];
		var formsHtml = forms.length ? '<div class="cu-table-wrap cu-ct-table-wrap" tabindex="0" role="region" aria-label="Statistiques par formulaire"><table class="cu-ct-table"><thead><tr>' +
			'<th scope="col">Formulaire</th><th scope="col">Vus</th><th scope="col">Commencés</th><th scope="col">Reçus</th><th scope="col">Conversion</th><th scope="col"><abbr title="Médiane du temps de remplissage">Durée</abbr></th></tr></thead><tbody>' +
			forms.map( function ( form ) {
				return '<tr><th scope="row"><span class="cu-ct-form">' + esc( form.title ) + "</span>" +
					( form.edit_url ? ' <a class="cu-ct-edit" href="' + esc( form.edit_url ) + '" title="Modifier ce formulaire">' + icon( "edit" ) + '<span class="screen-reader-text">Modifier « ' + esc( form.title ) + " »</span></a>" : ( form.exists ? "" : ' <span class="cu-ct-tag">supprimé</span>' ) ) +
					( int( form.server ) ? '<span class="cu-ct-form-sub">' + num( form.server ) + " " + plural( form.server, "envoi confirmé", "envois confirmés" ) + " par le serveur</span>" : "" ) +
					"</th><td>" + num( form.views ) + "</td><td>" + num( form.starts ) + '</td><td class="is-strong">' + num( form.sent ) + "</td><td>" +
					( int( form.views ) ? '<span class="cu-ct-rate is-cf">' + pct( form.rate ) + "</span>" : "—" ) + "</td><td>" + esc( dur( form.median_fill ) ) + "</td></tr>";
			} ).join( "" ) + "</tbody></table></div>" : emptyLine( "Aucun formulaire publié." );

		var server = cf.server || {};
		var serverSent = int( server.sent );
		var tracked = int( server.tracked );
		var serverHtml = "";
		if ( serverSent ) {
			var serverText = "<b>" + num( serverSent ) + " " + plural( serverSent, "envoi confirmé", "envois confirmés" ) + "</b> par le serveur";
			if ( tracked <= serverSent ) {
				serverText += ", dont <b>" + num( tracked ) + "</b> " + plural( tracked, "vu", "vus" ) + " par le suivi du navigateur (" + Math.round( tracked / serverSent * 100 ) + " %)." +
					( tracked < serverSent ? ' <span class="cu-ct-muted">L’écart vient des visiteurs qui refusent les statistiques, des bloqueurs de publicité ou des envois sans JavaScript.</span>' : ' <span class="cu-ct-muted">Tous les envois réussis sont suivis.</span>' );
			} else {
				serverText += " et <b>" + num( tracked ) + "</b> vus par le navigateur." + ' <span class="cu-ct-muted">Le serveur ne compte que les courriels réellement partis : un envoi annoncé réussi puis bloqué ensuite (filtre, extension tierce) n’y figure pas.</span>';
			}
			serverHtml = '<footer class="cu-ct-server">' + icon( "shield" ) + "<p>" + serverText + "</p></footer>";
		}

		var recent = ( cf.recent || [] ).map( function ( row ) {
			var tags = visitorTags( row, ctx );
			tags.unshift( '<span class="cu-ct-tag is-cf">' + icon( "mail" ) + esc( row.form ) + "</span>" );
			if ( null != row.fill ) tags.push( '<span class="cu-ct-tag">' + icon( "clock" ) + esc( dur( row.fill ) ) + "</span>" );
			return '<li><span class="cu-ct-when" title="' + esc( row.at ) + '">' + esc( ago( row.ago ) ) + '</span><div class="cu-ct-feed-main"><div class="cu-ct-feed-title">' + pageLink( row ) + '</div><div class="cu-ct-feed-meta">' + tags.join( "" ) + "</div></div></li>";
		} );

		var hasActivity = int( funnel.views ) || sent || int( cf.attempts );
		return '<article class="cu-ct-card is-cf">' + head + kpis +
			( hasActivity ? block( "Entonnoir", "funnel", funnelHtml( funnel ) ) : '<div class="cu-ct-note is-cf">' + icon( "mail" ) + "<p><b>Aucune activité de formulaire sur la période.</b> Les vues, débuts de saisie et envois de vos " + num( cf.forms_total ) + " " + plural( cf.forms_total, "formulaire", "formulaires" ) + " apparaîtront ici.</p></div>" ) +
			( hasActivity ? block( "Ce qui bloque", "alert", '<div class="cu-ct-chips">' + issues + "</div>" + ( fieldsHtml ? '<p class="cu-ct-sub">Champs les plus souvent refusés</p>' + fieldsHtml : "" ) ) : "" ) +
			block( "Par formulaire", "mail", formsHtml ) +
			( recent.length ? block( "Derniers messages", "clock", '<ul class="cu-ct-feed">' + recent.join( "" ) + "</ul>" ) : "" ) +
			serverHtml +
			"</article>";
	}

	// -------------------------------------------------------------------------
	// Jour × heure et sources
	// -------------------------------------------------------------------------
	function heatCard( data ) {
		var heat = data.heat || { wa: [], cf7: [] };
		var max = 0;
		var cells = [];
		for ( var row = 0; row < 7; row++ ) {
			for ( var col = 0; col < 24; col++ ) {
				var waVal = int( ( heat.wa[ row ] || [] )[ col ] );
				var cfVal = int( ( heat.cf7[ row ] || [] )[ col ] );
				max = Math.max( max, waVal + cfVal );
				cells.push( { row: row, col: col, wa: waVal, cf: cfVal } );
			}
		}
		var peak = peakSlot( heat );
		var grid = "";
		cells.forEach( function ( cell ) {
			if ( 0 === cell.col ) grid += '<span class="cu-ct-heat-day">' + WEEKDAYS_SHORT[ cell.row ] + "</span>";
			var total = cell.wa + cell.cf;
			var ratio = max ? total / max : 0;
			var tone = cell.cf > cell.wa ? "cf" : "wa";
			var label = capitalize( WEEKDAYS[ cell.row ] ) + " " + cell.col + " h – " + ( cell.col + 1 ) + " h : " + total + " " + plural( total, "contact", "contacts" ) + ( total ? " (" + cell.wa + " WhatsApp, " + cell.cf + " " + plural( cell.cf, "formulaire", "formulaires" ) + ")" : "" );
			grid += '<span class="cu-ct-cell' + ( total ? " is-on is-" + tone : "" ) + ( total && cell.row === peak.day && cell.col === peak.hour ? " is-peak" : "" ) + '" title="' + esc( label ) + '"' +
				( total ? ' style="--ct-a:' + ( 0.22 + ratio * 0.78 ).toFixed( 2 ) + '"' : "" ) + "></span>";
		} );
		var hours = '<span class="cu-ct-heat-day"></span>';
		for ( var hour = 0; hour < 24; hour++ ) hours += '<span class="cu-ct-heat-hour">' + ( 0 === hour % 3 ? hour + " h" : "" ) + "</span>";
		var summary = peak.value ? "Pic le " + WEEKDAYS[ peak.day ] + " entre " + peak.hour + " h et " + ( peak.hour + 1 ) + " h (" + peak.value + " " + plural( peak.value, "contact", "contacts" ) + ")." : "Aucun contact sur la période.";
		return '<article class="cu-ct-card is-heat"><header class="cu-ct-card-head"><span class="cu-ct-orb is-rate is-lg">' + icon( "clock" ) + "</span><div><h2>Quand on vous contacte</h2><p>Leads par jour et par heure (heure du site)</p></div>" +
			( peak.value ? '<span class="cu-ct-status is-peak">' + icon( "flame" ) + capitalize( WEEKDAYS_SHORT[ peak.day ] ) + " " + peak.hour + " h</span>" : "" ) + "</header>" +
			'<div class="cu-ct-heat-wrap" tabindex="0" role="region" aria-label="Carte des contacts par jour et heure"><div class="cu-ct-heat" role="img" aria-label="' + esc( summary ) + '">' + grid + hours + "</div></div>" +
			'<div class="cu-ct-heat-key"><span><i class="is-wa"></i>WhatsApp dominant</span><span><i class="is-cf"></i>Formulaires dominants</span><span class="cu-ct-heat-scale">moins <i></i><i></i><i></i><i></i> plus</span></div>' +
			"</article>";
	}

	function sourcesCard( data, ctx ) {
		var sources = ( data.sources || [] ).filter( function ( src ) { return src.sessions > 0 || src.leads > 0; } );
		var maxRate = sources.reduce( function ( max, src ) { return Math.max( max, Number( src.rate ) || 0 ); }, 0 ) || 1;
		var body = sources.length ? '<div class="cu-table-wrap cu-ct-table-wrap" tabindex="0" role="region" aria-label="Contacts par source de trafic"><table class="cu-ct-table is-sources"><thead><tr>' +
			'<th scope="col">Source</th><th scope="col">Visites</th><th scope="col"><span class="cu-ct-th is-wa">WhatsApp</span></th><th scope="col"><span class="cu-ct-th is-cf">Formulaires</span></th><th scope="col"><abbr title="Visites suivies ayant contacté ÷ visites suivies en continu de cette source">Taux de contact</abbr></th></tr></thead><tbody>' +
			sources.map( function ( src ) {
				return '<tr><th scope="row">' + esc( ctx.sourceLabel( src.source ) ) + "</th><td>" + num( src.sessions ) + "</td><td>" + ( int( src.wa ) ? num( src.wa ) : '<span class="cu-ct-zero">0</span>' ) + "</td><td>" + ( int( src.cf7 ) ? num( src.cf7 ) : '<span class="cu-ct-zero">0</span>' ) + "</td>" +
					'<td class="cu-ct-rate-cell"><span class="cu-ct-minibar"><span style="width:' + Math.max( src.rate > 0 ? 6 : 0, ( Number( src.rate ) || 0 ) / maxRate * 100 ).toFixed( 1 ) + '%"></span></span><b>' + ( src.sessions ? pct( src.rate ) : "—" ) + "</b></td></tr>";
			} ).join( "" ) + "</tbody></table></div>" : emptyLine( "Aucune visite sur la période." );
		return '<article class="cu-ct-card is-sources"><header class="cu-ct-card-head"><span class="cu-ct-orb is-teal is-lg">' + icon( "route" ) + "</span><div><h2>Sources qui génèrent des contacts</h2><p>Taux de contact = visiteurs qui vous contactent ÷ visites</p></div></header>" + body + "</article>";
	}

	// -------------------------------------------------------------------------
	// Colonnes empilées jour par jour (WhatsApp + formulaires), fantôme de la
	// période précédente, record, infobulle riche épinglable, clavier.
	// -------------------------------------------------------------------------
	function getTip( host ) {
		var node = document.createElement( "div" );
		node.className = "cu-bc-tip cu-ct-tip";
		node.hidden = true;
		node.setAttribute( "role", "tooltip" );
		( host.closest( ".cu-wrap" ) || document.body ).appendChild( node );
		return node;
	}

	function mountChart( root, data ) {
		var id = "cu-ct-c" + ( ++uid );
		var points = ( data.series || [] ).map( function ( p ) {
			return {
				key: String( p.key ),
				wa: int( p.wa ),
				waVisitors: int( p.wa_visitors ),
				cf: int( p.cf7 ),
				cfErrors: int( p.cf7_errors ),
				cfViews: int( p.cf7_views ),
				visitors: int( p.visitors ),
				sessions: int( p.sessions ),
				measured: null != p.measured ? int( p.measured ) : int( p.sessions ),
				prev: int( p.prev ),
				prevKey: String( p.prev_key || "" ),
			};
		} );
		var last = points.length - 1;
		var state = { active: -1, pinned: false, geometry: null, width: 0, alive: true, announce: null };
		var bubble = getTip( root );
		root.innerHTML =
			'<div class="cu-ct-stage" tabindex="0" role="group" aria-roledescription="graphique" aria-describedby="' + id + '-help" aria-label="Contacts par jour sur ' + int( data.range ) + ' jours : clics WhatsApp et messages de formulaire">' +
				'<svg class="cu-ct-svg" aria-hidden="true" focusable="false"></svg>' +
			"</div>" +
			'<div class="cu-ct-foot">' +
				'<span class="cu-ct-key is-wa"><i></i>Clics WhatsApp</span>' +
				'<span class="cu-ct-key is-cf"><i></i>Messages de formulaire (vus par le navigateur)</span>' +
				'<span class="cu-ct-key is-ghost"><i></i>Période précédente</span>' +
				'<span class="cu-ct-help" id="' + id + '-help">Survolez pour le détail · cliquez pour épingler · ← → au clavier</span>' +
			"</div>" +
			'<p class="screen-reader-text" aria-live="polite"></p>' +
			'<div class="screen-reader-text"><table><caption>Contacts par jour</caption><thead><tr><th scope="col">Jour</th><th scope="col">WhatsApp</th><th scope="col">Formulaires</th><th scope="col">Visites</th><th scope="col">Période précédente</th></tr></thead><tbody>' +
				points.map( function ( p ) { return '<tr><th scope="row">' + esc( longDay( p.key ) ) + "</th><td>" + p.wa + "</td><td>" + p.cf + "</td><td>" + p.sessions + "</td><td>" + p.prev + "</td></tr>"; } ).join( "" ) +
			"</tbody></table></div>";
		var stage = root.querySelector( ".cu-ct-stage" );
		var svg = root.querySelector( ".cu-ct-svg" );
		var live = root.querySelector( "p.screen-reader-text" );
		if ( ! points.some( function ( p ) { return p.wa + p.cf + p.prev > 0; } ) ) {
			stage.insertAdjacentHTML( "beforeend", '<p class="cu-ct-chart-empty">' + icon( "spark" ) + "En attente du premier contact : les colonnes apparaîtront dès le premier clic WhatsApp ou message de formulaire.</p>" );
		}

		function topRounded( x, y, w, h, r ) {
			r = Math.max( 0, Math.min( r, w / 2, h ) );
			return "M" + x.toFixed( 1 ) + "," + ( y + h ).toFixed( 1 ) + "V" + ( y + r ).toFixed( 1 ) + "Q" + x.toFixed( 1 ) + "," + y.toFixed( 1 ) + " " + ( x + r ).toFixed( 1 ) + "," + y.toFixed( 1 ) +
				"H" + ( x + w - r ).toFixed( 1 ) + "Q" + ( x + w ).toFixed( 1 ) + "," + y.toFixed( 1 ) + " " + ( x + w ).toFixed( 1 ) + "," + ( y + r ).toFixed( 1 ) + "V" + ( y + h ).toFixed( 1 ) + "Z";
		}

		function draw( animate ) {
			var width = Math.max( 280, Math.floor( stage.clientWidth ) );
			var height = width < 560 ? 190 : 236;
			state.width = width;
			while ( svg.firstChild ) svg.removeChild( svg.firstChild );
			svg.setAttribute( "viewBox", "0 0 " + width + " " + height );
			svg.setAttribute( "width", width );
			svg.setAttribute( "height", height );
			if ( ! points.length ) return;

			var pad = { l: 36, r: 12, t: 30, b: 26 };
			var plotW = width - pad.l - pad.r;
			var plotH = height - pad.t - pad.b;
			var count = points.length;
			var slot = plotW / count;
			var barW = Math.max( 3, Math.min( 26, slot * 0.62 ) );
			var peakVal = points.reduce( function ( max, p ) { return Math.max( max, p.wa + p.cf, p.prev ); }, 0 );
			var scale = cuAdmin.niceScale( Math.max( 4, peakVal ), 4 );
			var yMax = scale.max;
			function xAt( n ) { return pad.l + slot * n + slot / 2; }
			function yAt( v ) { return pad.t + plotH - ( v / yMax ) * plotH; }
			state.geometry = { pad: pad, slot: slot, count: count, xAt: xAt, yAt: yAt, plotH: plotH, height: height };

			var defs = svgEl( "defs", {}, svg );
			var waGrad = svgEl( "linearGradient", { id: id + "-wa", x1: "0", y1: "0", x2: "0", y2: "1" }, defs );
			svgEl( "stop", { offset: "0", "stop-color": "#4fdc86" }, waGrad );
			svgEl( "stop", { offset: "1", "stop-color": "#0f9447" }, waGrad );
			var cfGrad = svgEl( "linearGradient", { id: id + "-cf", x1: "0", y1: "0", x2: "0", y2: "1" }, defs );
			svgEl( "stop", { offset: "0", "stop-color": "#a487ff" }, cfGrad );
			svgEl( "stop", { offset: "1", "stop-color": "#6040d0" }, cfGrad );
			// Reflet latéral : la colonne paraît bombée (cylindre éclairé à gauche).
			var gloss = svgEl( "linearGradient", { id: id + "-gloss", x1: "0", y1: "0", x2: "1", y2: "0" }, defs );
			svgEl( "stop", { offset: "0", "stop-color": "#ffffff", "stop-opacity": "0.05" }, gloss );
			svgEl( "stop", { offset: "0.28", "stop-color": "#ffffff", "stop-opacity": "0.42" }, gloss );
			svgEl( "stop", { offset: "0.55", "stop-color": "#ffffff", "stop-opacity": "0" }, gloss );
			svgEl( "stop", { offset: "1", "stop-color": "#000000", "stop-opacity": "0.12" }, gloss );
			var lift = svgEl( "filter", { id: id + "-lift", x: "-40%", y: "-10%", width: "180%", height: "130%" }, defs );
			svgEl( "feDropShadow", { dx: "0", dy: "3", stdDeviation: "2.6", "flood-color": "#141a33", "flood-opacity": "0.2" }, lift );

			var back = svgEl( "g", { class: "cu-ct-back" }, svg );
			for ( var g = 0; g <= scale.ticks; g++ ) {
				var gv = scale.step * g;
				var gy = yAt( gv );
				svgEl( "line", { class: 0 === g ? "cu-ct-axis" : "cu-ct-grid", x1: pad.l, x2: width - pad.r, y1: gy.toFixed( 1 ), y2: gy.toFixed( 1 ) }, back );
				var ylabel = svgEl( "text", { class: "cu-ct-ylabel", x: pad.l - 8, y: ( gy + 4 ).toFixed( 1 ), "text-anchor": "end" }, back );
				ylabel.textContent = String( gv );
			}
			var every = Math.max( 1, Math.ceil( ( width < 560 ? 44 : 58 ) / slot ) );
			points.forEach( function ( p, n ) {
				if ( 0 !== ( last - n ) % every ) return;
				var anchor = n === last ? "end" : ( 0 === n ? "start" : "middle" );
				var tick = svgEl( "text", { class: "cu-ct-xlabel" + ( n === last ? " is-now" : "" ), x: ( n === last ? xAt( n ) + barW / 2 : xAt( n ) ).toFixed( 1 ), y: height - 7, "text-anchor": anchor }, back );
				tick.textContent = n === last ? "auj." : tickDay( p.key, count > 14 );
			} );

			var band = svgEl( "rect", { class: "cu-ct-band", x: "0", y: pad.t - 6, width: slot.toFixed( 1 ), height: plotH + 6, rx: "8", visibility: "hidden" }, svg );

			var cols = svgEl( "g", { class: "cu-ct-cols", filter: "url(#" + id + "-lift)" }, svg );
			points.forEach( function ( p, n ) {
				var stack = p.wa + p.cf;
				if ( ! stack ) return;
				var x = xAt( n ) - barW / 2;
				var base = yAt( 0 );
				var waTop = yAt( p.wa );
				var top = yAt( stack );
				var radius = Math.min( 7, barW / 2 );
				var col = svgEl( "g", { class: "cu-ct-col" }, cols );
				if ( animate && ! reducedMotion( root ) ) col.style.animationDelay = Math.min( 600, n * 14 ) + "ms";
				if ( p.wa ) svgEl( "path", { class: "cu-ct-seg is-wa", d: p.cf ? "M" + x.toFixed( 1 ) + "," + base.toFixed( 1 ) + "V" + waTop.toFixed( 1 ) + "H" + ( x + barW ).toFixed( 1 ) + "V" + base.toFixed( 1 ) + "Z" : topRounded( x, waTop, barW, base - waTop, radius ), fill: "url(#" + id + "-wa)" }, col );
				if ( p.cf ) svgEl( "path", { class: "cu-ct-seg is-cf", d: topRounded( x, top, barW, waTop - top, radius ), fill: "url(#" + id + "-cf)" }, col );
				if ( p.wa && p.cf ) svgEl( "line", { class: "cu-ct-seam", x1: x.toFixed( 1 ), x2: ( x + barW ).toFixed( 1 ), y1: waTop.toFixed( 1 ), y2: waTop.toFixed( 1 ) }, col );
				svgEl( "path", { class: "cu-ct-gloss", d: topRounded( x, top, barW, base - top, radius ), fill: "url(#" + id + "-gloss)" }, col );
			} );

			// Période précédente : ligne fantôme en pointillés.
			var ghost = points.map( function ( p, n ) { return [ xAt( n ), yAt( p.prev ) ]; } );
			if ( points.some( function ( p ) { return p.prev > 0; } ) ) {
				svgEl( "path", { class: "cu-ct-ghost", d: cuAdmin.smoothPath( ghost ) }, svg );
			}

			// Record de la période.
			var best = -1;
			points.forEach( function ( p, n ) { if ( p.wa + p.cf > 0 && ( best < 0 || p.wa + p.cf > points[ best ].wa + points[ best ].cf ) ) best = n; } );
			if ( best >= 0 ) {
				var flags = svgEl( "g", { class: "cu-ct-flags" }, svg );
				var bx = xAt( best );
				var by = yAt( points[ best ].wa + points[ best ].cf );
				var text = "Record · " + ( points[ best ].wa + points[ best ].cf );
				var fw = 16 + text.length * 6.3;
				var fx = Math.max( pad.l, Math.min( width - pad.r - fw, bx - fw / 2 ) );
				svgEl( "line", { class: "cu-bc-record-line", x1: bx.toFixed( 1 ), x2: bx.toFixed( 1 ), y1: ( by - 4 ).toFixed( 1 ), y2: "24" }, flags );
				svgEl( "rect", { class: "cu-ct-record", x: fx.toFixed( 1 ), y: "4", width: fw.toFixed( 1 ), height: "20", rx: "10" }, flags );
				var label = svgEl( "text", { class: "cu-bc-record-text", x: ( fx + fw / 2 ).toFixed( 1 ), y: "18", "text-anchor": "middle" }, flags );
				label.textContent = text;
			}

			svgEl( "rect", { class: "cu-ct-hit", x: pad.l, y: "0", width: plotW, height: height }, svg );
			state.band = band;
			state.barW = barW;
		}

		function metric( labelText, value ) {
			return '<div class="cu-bc-m"><span>' + esc( labelText ) + "</span><b>" + value + "</b></div>";
		}

		function setActive( n ) {
			var geo = state.geometry;
			if ( ! geo || n < 0 || n > last ) return;
			state.active = n;
			var p = points[ n ];
			state.band.setAttribute( "x", ( geo.xAt( n ) - geo.slot / 2 ).toFixed( 1 ) );
			state.band.setAttribute( "width", geo.slot.toFixed( 1 ) );
			state.band.setAttribute( "visibility", "visible" );
			root.classList.add( "is-active" );
			var leadsDay = p.wa + p.cf;
			var trend = "";
			if ( p.prev > 0 ) {
				trend = cuAdmin.deltaBadge( ( leadsDay - p.prev ) / p.prev * 100 ) + '<span class="cu-bc-vs">vs ' + esc( longDay( p.prevKey ).toLowerCase() ) + " (" + num( p.prev ) + ")</span>";
			} else if ( leadsDay > 0 ) {
				trend = '<span class="cu-bc-vs">aucun contact le jour équivalent de la période précédente</span>';
			}
			var html = '<div class="cu-bc-tip-head"><span class="cu-bc-tip-date">' + esc( longDay( p.key ) ) + "</span>" +
				( n === last ? '<span class="cu-bc-now-tag">aujourd’hui</span>' : "" ) +
				'<span class="cu-bc-pin">' + icon( "pinned" ) + " épinglé</span></div>" +
				'<div class="cu-bc-tip-total cu-ct-tip-total"><strong>' + num( leadsDay ) + "</strong> " + plural( leadsDay, "contact", "contacts" ) + "</div>" +
				( trend ? '<div class="cu-bc-tip-trend">' + trend + "</div>" : "" ) +
				'<div class="cu-ct-tip-split"><span class="is-wa" style="flex:' + ( p.wa || 0 ) + '"></span><span class="is-cf" style="flex:' + ( p.cf || 0 ) + '"></span></div>' +
				'<div class="cu-bc-metrics">' +
					metric( "Clics WhatsApp", '<span class="cu-ct-n is-wa">' + num( p.wa ) + "</span>" ) +
					metric( "Messages (navigateur)", '<span class="cu-ct-n is-cf">' + num( p.cf ) + "</span>" ) +
					metric( "Visites", num( p.sessions ) ) +
					metric( "Taux de contact (visites suivies)", p.measured ? pct( Math.min( 100, p.visitors / p.measured * 100 ) ) : "—" ) +
					metric( "Visiteurs WhatsApp", num( p.waVisitors ) ) +
					metric( "Envois refusés", p.cfErrors ? '<span class="cu-ct-n is-warn">' + num( p.cfErrors ) + "</span>" : "0" ) +
				"</div>" +
				'<div class="cu-bc-tip-foot">' + ( state.pinned ? "Échap ou clic ailleurs pour fermer" : "Cliquez pour épingler ce jour" ) + "</div>";
			bubble.innerHTML = html;
			bubble.classList.remove( "is-wide" );
			bubble.classList.toggle( "is-pinned", state.pinned );
			bubble.hidden = false;
			place();
			window.clearTimeout( state.announce );
			state.announce = window.setTimeout( function () {
				live.textContent = longDay( p.key ) + " : " + leadsDay + " " + plural( leadsDay, "contact", "contacts" ) + ", dont " + p.wa + " WhatsApp et " + p.cf + " " + plural( p.cf, "message", "messages" ) + ".";
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
			var ratio = rect.width / state.width;
			var px = rect.left + state.geometry.xAt( state.active ) * ratio;
			var tw = bubble.offsetWidth;
			var th = bubble.offsetHeight;
			var left = px + 20;
			if ( left + tw > window.innerWidth - 12 ) left = px - 20 - tw;
			left = Math.max( 12, Math.min( left, window.innerWidth - tw - 12 ) );
			var bar = document.getElementById( "wpadminbar" );
			var minTop = ( bar ? bar.getBoundingClientRect().bottom : 0 ) + 10;
			var top = Math.max( minTop, Math.min( rect.top - 8, window.innerHeight - th - 12 ) );
			bubble.style.left = Math.round( left ) + "px";
			bubble.style.top = Math.round( top ) + "px";
		}

		function hide() {
			state.active = -1;
			state.pinned = false;
			root.classList.remove( "is-active", "is-pinned" );
			if ( state.band ) state.band.setAttribute( "visibility", "hidden" );
			bubble.hidden = true;
			bubble.classList.remove( "is-pinned" );
		}

		function indexAt( ev ) {
			var geo = state.geometry;
			if ( ! geo ) return -1;
			var rect = svg.getBoundingClientRect();
			var x = ( ev.clientX - rect.left ) * ( state.width / rect.width );
			return Math.max( 0, Math.min( geo.count - 1, Math.floor( ( x - geo.pad.l ) / geo.slot ) ) );
		}

		var handlers = {
			move: function ( ev ) {
				if ( state.pinned ) return;
				var n = indexAt( ev );
				if ( n !== state.active ) setActive( n );
			},
			leave: function () { if ( ! state.pinned ) hide(); },
			click: function ( ev ) {
				var n = indexAt( ev );
				state.pinned = ! ( state.pinned && n === state.active );
				root.classList.toggle( "is-pinned", state.pinned );
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
					state.pinned = ! state.pinned;
					root.classList.toggle( "is-pinned", state.pinned );
					setActive( n );
					return;
				} else return;
				ev.preventDefault();
				setActive( n );
			},
			focus: function () { if ( state.active < 0 ) setActive( last ); },
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
				window.removeEventListener( "scroll", handlers.scroll );
				if ( bubble.parentNode ) bubble.parentNode.removeChild( bubble );
			},
		};
	}

	// Compteur animé du grand chiffre (respecte le mouvement réduit).
	function countUp( node ) {
		if ( ! node ) return;
		var target = int( node.getAttribute( "data-count" ) );
		if ( ! target || reducedMotion( node ) ) return;
		var start = null;
		node.textContent = "0";
		var step = function ( now ) {
			if ( null === start ) start = now;
			var t = Math.min( 1, ( now - start ) / 800 );
			node.textContent = num( Math.round( target * easeOut( t ) ) );
			if ( t < 1 ) window.requestAnimationFrame( step );
		};
		window.requestAnimationFrame( step );
	}

	// -------------------------------------------------------------------------
	// Point d'entrée
	// -------------------------------------------------------------------------
	function render( root, data, ctx ) {
		data = data || {};
		ctx = ctx || {};
		ctx.sourceLabel = ctx.sourceLabel || function ( source ) { return source; };
		ctx.countryName = ctx.countryName || function ( code ) { return code; };
		ctx.settingsUrl = ctx.settingsUrl || "admin.php?page=cyberpush-ultra-settings";
		root.innerHTML = heroHtml( data, ctx ) +
			'<div class="cu-ct-duo">' + whatsappCard( data, ctx ) + cf7Card( data, ctx ) + "</div>" +
			'<div class="cu-ct-duo is-bottom">' + heatCard( data ) + sourcesCard( data, ctx ) + "</div>";
		root.setAttribute( "aria-busy", "false" );
		countUp( root.querySelector( ".cu-ct-num" ) );
		var chartHost = root.querySelector( "[data-ct='chart']" );
		var chart = chartHost && ( data.series || [] ).length ? mountChart( chartHost, data ) : null;
		return {
			destroy: function () {
				if ( chart ) chart.destroy();
				chart = null;
			},
		};
	}

	window.cuContacts = { render: render };
} )();

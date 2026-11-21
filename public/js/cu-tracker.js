/* CyberPush Ultra - balise de mesure d'audience (aucune dependance externe).
 *
 * Deux niveaux de mesure :
 *  - continue : identifiant de visite (localStorage, 30 min d'inactivite) et
 *               marqueur « deja venu » (13 mois au plus, jamais prolonge) ;
 *  - anonyme  : ni cookie, ni stockage dans le navigateur (seule la lecture de
 *               l'opposition cu_optout), ni identifiant persistant : chaque page vue
 *               est comptee seule, avec un identifiant aleatoire EN MEMOIRE qui ne
 *               sert qu'a rattacher les evenements de CETTE page (defilement, duree,
 *               clics). Il change a chaque chargement de page.
 * Choix du niveau (cfg.anonFallback = false : « anonyme » devient « rien ») :
 *  - opposition (cu_optout) : rien ;  signal GPC respecte : anonyme ;
 *  - exempt   : accord ou aucun bandeau -> continue ; refus, ou bandeau present
 *               sans reponse -> anonyme ;
 *  - consent  : accord -> continue ; sinon -> anonyme ;
 *  - standard : continue, sauf refus (WP Consent API, cuAnalytics.consent(false)) -> anonyme.
 * Sources du consentement : window.cuAnalytics.consent(), WP Consent API, cookies
 * de bandeaux reconnus, Google Consent Mode v2 (window.dataLayer, analytics_storage).
 * Les URL sont minimisees (parametres hors utm_* retires, referent reduit au
 * domaine). Les evenements sont envoyes par lots (sendBeacon). */
( function () {
	"use strict";

	var cfg = window.cuTrackerConfig;
	if ( ! cfg ) return;
	// Script charge deux fois (optimiseur qui le duplique) : la seconde copie ne
	// fait rien (sinon chaque page serait comptee deux fois).
	if ( window.cuAnalytics && true === window.cuAnalytics.loaded ) return;

	var MODE = "consent" === cfg.mode || "standard" === cfg.mode ? cfg.mode : "exempt";
	var FULL = "full";
	var ANON = "anon";
	var HOSTS = ( cfg.hosts || [] ).map( function ( host ) { return String( host ).toLowerCase(); } );
	var SESSION_KEY = "cu_session_id";
	var SESSION_TS_KEY = "cu_session_id_ts";
	var LANDING_KEY = "cu_landing_url";
	var VISITOR_KEY = "cu_visitor_seen";
	var VISITOR_TS_KEY = "cu_visitor_seen_ts";
	var OPTOUT_KEY = "cu_optout";
	var TRANSPORT_KEY = "cu_transport";
	// Identifiant « deja venu » : 13 mois au plus, jamais prolonge par une visite.
	var VISITOR_MAX_AGE = 390 * 24 * 60 * 60 * 1000;
	// Au-dela de 30 min sans geste, l'onglet ouvert ne signale plus de presence.
	var IDLE_LIMIT = 30 * 60 * 1000;
	var MAX_BATCH = Math.max( 1, Math.min( 50, parseInt( cfg.maxBatch, 10 ) || 20 ) );
	var FLUSH_DELAY = 10000;
	var ALLOW_PARAMS = ( cfg.allowParams || [] ).map( function ( name ) { return String( name ).toLowerCase(); } );
	var EMAIL_RE = /[^\s@\/?#&=<>"'(),;:]+@[^\s@\/?#&=<>"'(),;:]+\.[a-z]{2,}/i;

	var session = null;
	var level = null; // niveau applique sur cette page : null (rien), ANON ou FULL
	var shown = false; // la page a reellement ete affichee (ni prerendu, ni masquee)
	var pageviewSent = false; // une page vue au plus par affichage de page
	var gcm = { index: 0, seen: false, update: null }; // Google Consent Mode v2
	var trackingStarted = false;
	var trackingListeners = [];
	var hideHandlers = [];
	var leaveHandlers = [];
	var queue = [];
	var flushTimer = null;
	var transport = null; // "rest", "ajax" ou "probing", memorise pour l'onglet
	var apiConsent = "boolean" === typeof window.cuAnalyticsConsent ? window.cuAnalyticsConsent : null;
	var storageCleared = false;
	var lastActivity = Date.now();
	var lastSent = 0;
	var pageStart = Date.now(); // delai avant un contact (WhatsApp, formulaire)
	// Identifiant de CETTE page, en memoire seulement (mesure anonyme ; devient
	// l'identifiant de visite si l'accord arrive pendant la page).
	var pageId = uuid();
	// Jeton aleatoire de CETTE page vue, en memoire seulement (jamais stocke, change
	// a chaque chargement) : le serveur ignore un lot rejoue a l'identique (relance
	// reseau, bascule de l'API REST vers admin-ajax) au lieu de compter deux pages vues.
	var pageToken = uuid().replace( /-/g, "" ).slice( 0, 16 ).toLowerCase();
	// Fuseau horaire du navigateur (« Europe/Paris ») : joint a la page vue, il ne
	// sert au serveur qu'a estimer le pays quand l'adresse n'a pas pu etre situee.
	// Il n'est pas enregistre.
	var timeZone = "";
	try {
		timeZone = String( ( window.Intl && Intl.DateTimeFormat().resolvedOptions().timeZone ) || "" ).substring( 0, 64 );
	} catch ( e ) {
		timeZone = "";
	}
	// Langue du navigateur (« fr-fr ») et taille d'écran (« 1920x1080 ») : jointes
	// a la page vue pour les rapports « Langues » et « Ecrans ».
	var browserLang = "";
	var screenSize = "";
	try {
		var langMatch = String( navigator.language || "" ).toLowerCase().replace( /_/g, "-" ).match( /^[a-z]{2,3}(?:-[a-z0-9]{2,8})?/ );
		browserLang = langMatch ? langMatch[ 0 ].substring( 0, 16 ) : "";
		if ( window.screen && window.screen.width > 0 && window.screen.height > 0 ) {
			screenSize = Math.round( window.screen.width ) + "x" + Math.round( window.screen.height );
		}
	} catch ( e ) {
		browserLang = "";
		screenSize = "";
	}

	// -------------------------------------------------------------------------
	// Stockage (toujours protege : navigation privee, stockage bloque…)
	// -------------------------------------------------------------------------
	function lsGet( key ) {
		try { return window.localStorage.getItem( key ); } catch ( e ) { return null; }
	}
	function lsSet( key, value ) {
		try { window.localStorage.setItem( key, value ); } catch ( e ) {}
	}
	function lsDel( key ) {
		try { window.localStorage.removeItem( key ); } catch ( e ) {}
	}

	function clearAnalyticsStorage() {
		[ SESSION_KEY, SESSION_TS_KEY, LANDING_KEY, VISITOR_KEY, VISITOR_TS_KEY ].forEach( lsDel );
		try { window.sessionStorage.removeItem( TRANSPORT_KEY ); } catch ( e ) {}
	}

	function uuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === "function" ) {
			return window.crypto.randomUUID();
		}
		var bytes = new Uint8Array( 16 );
		if ( window.crypto && typeof window.crypto.getRandomValues === "function" ) {
			window.crypto.getRandomValues( bytes );
			bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
			bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
			var hex = Array.prototype.map.call( bytes, function ( b ) { return ( b + 0x100 ).toString( 16 ).slice( 1 ); } );
			return hex.slice( 0, 4 ).join( "" ) + "-" + hex.slice( 4, 6 ).join( "" ) + "-" + hex.slice( 6, 8 ).join( "" ) + "-" + hex.slice( 8, 10 ).join( "" ) + "-" + hex.slice( 10 ).join( "" );
		}
		return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0,
				v = "x" === c ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
	}

	// -------------------------------------------------------------------------
	// Minimisation : URL sans parametres (hors utm_* et parametres de structure),
	// referent reduit a son origine. Le serveur applique les memes regles.
	// -------------------------------------------------------------------------
	function cleanUrl( href ) {
		try {
			var url = new URL( href, window.location.href );
			if ( ! /^https?:$/i.test( url.protocol ) ) return "";
			var kept = [];
			url.searchParams.forEach( function ( value, name ) {
				var key = String( name ).toLowerCase();
				// Jamais une valeur contenant une adresse e-mail (lien de newsletter personnalise).
				if ( EMAIL_RE.test( String( value ) ) ) return;
				if ( ( 0 === key.indexOf( "utm_" ) || ALLOW_PARAMS.indexOf( key ) !== -1 ) && kept.length < 10 ) {
					kept.push( encodeURIComponent( name ) + "=" + encodeURIComponent( value ) );
				}
			} );
			return url.protocol + "//" + url.host + url.pathname + ( kept.length ? "?" + kept.join( "&" ) : "" );
		} catch ( e ) {
			return "";
		}
	}

	function referrerOrigin() {
		try {
			if ( ! document.referrer ) return "";
			var ref = new URL( document.referrer );
			return /^https?:$/i.test( ref.protocol ) ? ref.protocol + "//" + ref.host + "/" : "";
		} catch ( e ) {
			return "";
		}
	}

	var pageUrl = cleanUrl( window.location.href );

	// Session (30 min d'inactivite par defaut). Uniquement apres accord en mode
	// consentement : aucune ecriture avant.
	function getSession() {
		var existing = lsGet( SESSION_KEY );
		var ts = Number( lsGet( SESSION_TS_KEY ) || 0 );
		var seenAt = Number( lsGet( VISITOR_TS_KEY ) || 0 );
		var seen = "1" === lsGet( VISITOR_KEY ) && seenAt > 0 && Date.now() - seenAt < VISITOR_MAX_AGE;
		if ( ! seen ) {
			lsDel( VISITOR_KEY );
			lsDel( VISITOR_TS_KEY );
		}
		var maxAge = ( parseInt( cfg.sessionMinutes, 10 ) || 30 ) * 60 * 1000;
		var isNew = ! seen;
		if ( ! seen ) {
			lsSet( VISITOR_KEY, "1" );
			lsSet( VISITOR_TS_KEY, String( Date.now() ) );
		}
		if ( existing && /^[0-9a-f-]{36}$/i.test( existing ) && ts && Date.now() - ts < maxAge ) {
			lsSet( SESSION_TS_KEY, String( Date.now() ) );
			return { id: existing, isNew: false };
		}
		var id = uuid();
		lsSet( SESSION_KEY, id );
		lsSet( SESSION_TS_KEY, String( Date.now() ) );
		lsSet( LANDING_KEY, pageUrl );
		return { id: id, isNew: isNew };
	}

	// Une lecture longue ne coupe plus la session : chaque envoi la prolonge. Une
	// visite deja expiree (plus de 30 min sans envoi) n'est jamais prolongee par un
	// envoi tardif (depart de la page, retour sur l'onglet) : la page suivante
	// commence bien une nouvelle visite.
	function touchSession() {
		if ( ! session || FULL !== level ) return;
		var ts = Number( lsGet( SESSION_TS_KEY ) || 0 );
		var maxAge = ( parseInt( cfg.sessionMinutes, 10 ) || 30 ) * 60 * 1000;
		if ( ts && Date.now() - ts >= maxAge ) return;
		lsSet( SESSION_TS_KEY, String( Date.now() ) );
	}

	// Page d'entree (= une visite en mesure anonyme) : arrivee sans referent ou
	// depuis un autre site, par une vraie navigation (ni rechargement, ni retour
	// arriere). Sans API Navigation Timing : navigation.
	function isEntryPage() {
		var type = "navigate";
		try {
			var list = window.performance && "function" === typeof window.performance.getEntriesByType ? window.performance.getEntriesByType( "navigation" ) : [];
			if ( list && list[ 0 ] && list[ 0 ].type ) type = String( list[ 0 ].type );
		} catch ( e ) {}
		if ( "navigate" !== type ) return false;
		var host = "";
		try {
			host = document.referrer ? new URL( document.referrer ).hostname.toLowerCase() : "";
		} catch ( e ) {
			host = "";
		}
		return ! host || HOSTS.indexOf( host ) === -1;
	}
	var entryPage = isEntryPage();

	// Signaux d'automatisation certains uniquement (navigateur pilote, Chrome sans
	// interface). Les dimensions de fenetre ne sont pas utilisees : des WebViews et
	// navigateurs integres de vrais visiteurs renvoient parfois 0.
	function automationHint() {
		try {
			if ( true === navigator.webdriver ) return 1;
			var brands = navigator.userAgentData && navigator.userAgentData.brands;
			for ( var i = 0; brands && i < brands.length; i++ ) {
				if ( brands[ i ] && /headless/i.test( String( brands[ i ].brand ) ) ) return 1;
			}
		} catch ( e ) {}
		return 0;
	}

	// -------------------------------------------------------------------------
	// Opposition et consentement
	// -------------------------------------------------------------------------
	// Opposition du visiteur (seule lecture du stockage permise en mesure anonyme).
	function optOutFlag() {
		return "1" === lsGet( OPTOUT_KEY );
	}

	function gpcSignal() {
		return !! cfg.gpc && true === navigator.globalPrivacyControl;
	}

	function optedOut() {
		return optOutFlag() || gpcSignal();
	}

	// WP Consent API. Sans bandeau qui la pilote (aucun type de consentement),
	// l'API repond « oui » par defaut : ce n'est pas un consentement.
	function wpConsent( strict ) {
		if ( typeof window.wp_has_consent !== "function" ) return null;
		if ( strict ) {
			var type = window.wp_consent_type || ( window.consent_api && window.consent_api.consent_type ) || "";
			if ( ! type ) return null;
		}
		try {
			return !! window.wp_has_consent( "statistics" );
		} catch ( e ) {
			return false;
		}
	}

	// Cookies de bandeaux reconnus : true (accord), false (bandeau repondu sans
	// accord), null (aucune reponse pour l'instant).
	function cookieConsent() {
		var rules = cfg.consentCookies || [];
		if ( ! rules.length ) return null;
		var jar = document.cookie ? document.cookie.split( /;\s*/ ) : [];
		var answered = false;
		for ( var i = 0; i < rules.length; i++ ) {
			for ( var j = 0; j < jar.length; j++ ) {
				var eq = jar[ j ].indexOf( "=" );
				if ( eq < 1 || jar[ j ].slice( 0, eq ) !== rules[ i ].name ) continue;
				answered = true;
				var value = jar[ j ].slice( eq + 1 );
				try { value = decodeURIComponent( value ); } catch ( e ) {}
				if ( String( value ).indexOf( rules[ i ].value ) !== -1 ) return true;
			}
		}
		return answered ? false : null;
	}

	// Google Consent Mode v2 (Cookiebot, Axeptio, Didomi, OneTrust, Usercentrics,
	// iubenda…) : ['consent', 'default' | 'update', { analytics_storage }] dans
	// window.dataLayer. Le dernier « update » est la reponse ; un « default » seul
	// signale un bandeau sans reponse. Lecture incrementale (entrees nouvelles).
	function scanConsentMode() {
		var layer = window.dataLayer;
		if ( ! layer || "number" !== typeof layer.length ) return;
		if ( gcm.index > layer.length ) gcm.index = 0; // dataLayer remplace
		for ( ; gcm.index < layer.length; gcm.index++ ) {
			var item = layer[ gcm.index ];
			if ( ! item || "object" !== typeof item || "consent" !== item[ 0 ] ) continue;
			if ( "default" !== item[ 1 ] && "update" !== item[ 1 ] ) continue;
			gcm.seen = true;
			var params = item[ 2 ];
			if ( "update" === item[ 1 ] && params && "object" === typeof params && "string" === typeof params.analytics_storage ) {
				gcm.update = "granted" === params.analytics_storage ? true : ( "denied" === params.analytics_storage ? false : gcm.update );
			}
		}
	}

	// true (accord « statistiques »), false (refus) ou null (pas encore de reponse).
	function consentState() {
		if ( null !== apiConsent ) return apiConsent;
		var wp = wpConsent( true );
		var ck = cookieConsent();
		scanConsentMode();
		if ( true === wp || true === ck || true === gcm.update ) return true;
		if ( false === wp || false === ck || false === gcm.update ) return false;
		return null;
	}

	// Un gestionnaire de consentement est-il present (meme sans reponse) ?
	function consentManagerPresent() {
		if ( cfg.cmp ) return true;
		var type = window.wp_consent_type || ( window.consent_api && window.consent_api.consent_type ) || "";
		if ( type && "function" === typeof window.wp_has_consent ) return true;
		if ( null !== cookieConsent() ) return true;
		scanConsentMode();
		return gcm.seen;
	}

	// Niveau de mesure : FULL (continue), ANON (anonyme) ou null (rien).
	function decideLevel() {
		if ( optOutFlag() ) return null;
		var fallback = cfg.anonFallback ? ANON : null;
		if ( gpcSignal() ) return fallback;
		if ( "standard" === MODE ) {
			if ( false === apiConsent ) return fallback;
			if ( cfg.consentApi ) {
				if ( "function" !== typeof window.wp_has_consent ) return fallback;
				return true === wpConsent( false ) ? FULL : fallback;
			}
			return FULL;
		}
		var state = consentState();
		if ( true === state ) return FULL;
		if ( "consent" === MODE || false === state ) return fallback;
		// Mode exempte sans reponse : suivi continu seulement sans bandeau.
		return consentManagerPresent() ? fallback : FULL;
	}

	// Refus ou retrait explicite (opposition, GPC, refus du bandeau) : les
	// identifiants deja stockes doivent disparaitre.
	function explicitlyDenied() {
		if ( optedOut() ) return true;
		if ( "standard" === MODE ) {
			return false === apiConsent || ( !! cfg.consentApi && "function" === typeof window.wp_has_consent && false === wpConsent( false ) );
		}
		return false === consentState();
	}

	// -------------------------------------------------------------------------
	// Envoi par lots
	// -------------------------------------------------------------------------
	function addTrackingListener( target, eventName, handler, options ) {
		target.addEventListener( eventName, handler, options );
		trackingListeners.push( function () {
			target.removeEventListener( eventName, handler, options );
		} );
	}

	function stopTracking() {
		trackingStarted = false;
		session = null;
		queue = [];
		hideHandlers = [];
		leaveHandlers = [];
		if ( flushTimer ) clearTimeout( flushTimer );
		flushTimer = null;
		while ( trackingListeners.length ) trackingListeners.pop()();
	}

	// urgent : envoi immediat (page vue, contact juste avant de quitter la page).
	function enqueue( eventType, payload, urgent ) {
		if ( ! trackingStarted || ! session ) return;
		// Le consentement et l'opposition sont reverifies avant chaque evenement.
		sync();
		if ( ! trackingStarted || ! session ) return;
		var event = { event_type: eventType };
		Object.keys( payload || {} ).forEach( function ( key ) { event[ key ] = payload[ key ]; } );
		queue.push( event );
		touchSession();
		if ( urgent || queue.length >= MAX_BATCH ) {
			flush( false );
		} else if ( ! flushTimer ) {
			flushTimer = setTimeout( function () { flushTimer = null; flush( false ); }, FLUSH_DELAY );
		}
	}

	function batchBody( events ) {
		var body = {
			session_id: session.id,
			object_id: cfg.objectId,
			object_type: cfg.objectType,
			url: pageUrl,
			pv: pageToken,
			wd: automationHint(),
			events: events,
		};
		if ( events.some( function ( event ) { return "pageview" === event.event_type; } ) ) {
			if ( timeZone ) body.tz = timeZone;
			if ( browserLang ) body.lang = browserLang;
			if ( screenSize ) body.scr = screenSize;
		}
		if ( ANON === level ) {
			// Page anonyme : ni « nouveau visiteur », ni page d'arrivee stockee.
			body.anon = 1;
			body.entry = entryPage ? 1 : 0;
			body.referrer = entryPage ? referrerOrigin() : "";
		} else {
			body.is_new_session = session.isNew;
			body.referrer = referrerOrigin();
			body.landing_url = lsGet( LANDING_KEY ) || pageUrl;
		}
		return JSON.stringify( body );
	}

	// unloading : la page se ferme, seul sendBeacon est fiable.
	function flush( unloading ) {
		if ( flushTimer ) clearTimeout( flushTimer );
		flushTimer = null;
		if ( ! queue.length || ! session ) return;
		// Mesure anonyme : la voie d'envoi n'est memorisee que pour la page.
		if ( null === transport && FULL === level ) transport = sessionGet( TRANSPORT_KEY );
		// Detection de la voie d'envoi en cours : les evenements attendent son
		// resultat au lieu d'etre perdus sur une API REST fermee.
		if ( "probing" === transport && ! unloading ) return;

		var data = batchBody( queue.splice( 0, MAX_BATCH ) );
		lastSent = Date.now();

		// Premier envoi de l'onglet : fetch, pour lire la reponse. Une API REST
		// fermee aux visiteurs (extension de securite, hebergeur) ne repond pas
		// par le JSON du plugin : on bascule alors sur admin-ajax.php.
		if ( ! transport && window.fetch && ! unloading ) {
			transport = "probing";
			post( cfg.restUrl, data ).then( function ( response ) {
				return response.json().then( function ( json ) {
					return !! json && "object" === typeof json && "ok" in json;
				}, function () { return false; } );
			} ).catch( function () { return false; } ).then( function ( restWorks ) {
				if ( restWorks || ! cfg.ajaxUrl ) {
					remember( "rest" );
				} else {
					remember( "ajax" );
					post( cfg.ajaxUrl, data ).catch( function () {} );
				}
				if ( queue.length ) flush( false );
			} );
			return;
		}

		var url = "ajax" === transport && cfg.ajaxUrl ? cfg.ajaxUrl : cfg.restUrl;
		var queued = false;
		if ( navigator.sendBeacon ) {
			try {
				queued = navigator.sendBeacon( url, new Blob( [ data ], { type: "application/json" } ) );
			} catch ( e ) {}
		}
		if ( ! queued && window.fetch ) {
			post( url, data ).catch( function () {} );
		}
		if ( queue.length ) flush( unloading );
	}

	function post( url, data ) {
		return fetch( url, { method: "POST", body: data, headers: { "Content-Type": "application/json" }, keepalive: true, credentials: "same-origin" } );
	}

	function remember( value ) {
		transport = value;
		if ( FULL !== level ) return;
		try {
			window.sessionStorage.setItem( TRANSPORT_KEY, value );
		} catch ( e ) {}
	}

	function sessionGet( key ) {
		try {
			var value = window.sessionStorage.getItem( key );
			return "rest" === value || "ajax" === value ? value : null;
		} catch ( e ) {
			return null;
		}
	}

	function closestElement( target, selector ) {
		return target && target.closest ? target.closest( selector ) : null;
	}

	function pageSeconds() {
		return Math.max( 0, Math.round( ( Date.now() - pageStart ) / 1000 ) );
	}

	function scrollNow() {
		var doc = document.documentElement;
		var height = ( doc.scrollHeight || document.body.scrollHeight ) - doc.clientHeight;
		return height > 0 ? Math.min( 100, Math.round( ( window.scrollY || doc.scrollTop ) / height * 100 ) ) : 100;
	}

	// Message d'erreur sans parametres d'URL ni adresse e-mail.
	function cleanErrorText( text ) {
		return String( text || "" )
			.replace( /((?:https?:)?\/\/[^\s?#"'<>]+)[?#][^\s"'<>]*/gi, "$1" )
			.replace( /[^\s@<>"'()]+@[^\s@<>"'()]+\.[a-z]{2,}/gi, "[e-mail]" )
			.substring( 0, 200 );
	}

	// -------------------------------------------------------------------------
	// WhatsApp : reconnait toutes les formes de lien de conversation. Seul le
	// numero de destination (celui du site) est garde, jamais le message.
	// -------------------------------------------------------------------------
	var WA_HOSTS = /^(?:www\.)?(?:wa\.me|wa\.link|(?:api|web|chat)\.whatsapp\.com|whatsapp\.com)$/i;
	// Meme liste d'hotes, recherchee dans un texte (attribut onclick, data-href…).
	var WA_URL_IN_TEXT = /(?:https?:)?\/\/(?:www\.)?(?:wa\.me|wa\.link|(?:api|web|chat)\.whatsapp\.com|whatsapp\.com)\/[^\s'"<>)\\]*|whatsapp:\/\/[^\s'"<>)\\]+/i;
	var WA_WORD = /whats\s?app/i;

	function waPhone( value ) {
		var digits = String( value || "" ).replace( /\D+/g, "" ).replace( /^00/, "" );
		return /^[1-9]\d{5,14}$/.test( digits ) ? digits : "";
	}

	function whatsappInfo( raw ) {
		if ( ! raw ) return null;
		var href = String( raw ).trim();
		if ( ! href ) return null;
		// Android : intent://send/33612345678#Intent;scheme=smsto;package=com.whatsapp;end
		if ( /^intent:/i.test( href ) ) {
			if ( ! /package=com\.whatsapp/i.test( href ) ) return null;
			var intent = href.match( /^intent:\/\/send\/?([+\d\s-]+)/i );
			return { kind: "chat", phone: intent ? waPhone( intent[ 1 ] ) : "" };
		}
		if ( /^whatsapp:/i.test( href ) ) {
			var appPhone = href.match( /[?&]phone=([^&#]+)/i );
			var isChat = !! appPhone || /^whatsapp:\/\/(?:send|chat)/i.test( href );
			var number = "";
			try { number = appPhone ? waPhone( decodeURIComponent( appPhone[ 1 ] ) ) : ""; } catch ( e ) { number = ""; }
			return { kind: isChat ? "chat" : "unknown", phone: number };
		}
		var url;
		try { url = new URL( href, window.location.href ); } catch ( e ) { return null; }
		if ( ! /^https?:$/i.test( url.protocol ) || ! WA_HOSTS.test( url.hostname ) ) return null;
		var host = url.hostname.toLowerCase().replace( /^www\./, "" );
		var path = url.pathname.replace( /\/+$/, "" );
		var info = { kind: "unknown", phone: "" };
		var parts = path.split( "/" ).filter( Boolean );
		if ( "wa.link" === host ) {
			info.kind = "short";
		} else if ( "chat.whatsapp.com" === host ) {
			info.kind = "group";
		} else if ( "wa.me" === host ) {
			if ( ! parts.length ) {
				info.kind = "chat"; // wa.me/?text=… : le visiteur choisit le contact
			} else if ( "message" === parts[ 0 ] || "qr" === parts[ 0 ] ) {
				info.kind = "short";
			} else if ( "c" === parts[ 0 ] ) {
				info.kind = "catalog";
				info.phone = waPhone( parts[ 1 ] );
			} else if ( "channel" === parts[ 0 ] ) {
				info.kind = "channel";
			} else {
				info.kind = "chat";
				info.phone = waPhone( parts[ 0 ] );
			}
		} else if ( "send" === parts[ 0 ] || ( ! parts.length && url.searchParams.get( "phone" ) ) ) {
			info.kind = "chat";
			info.phone = waPhone( url.searchParams.get( "phone" ) );
		} else if ( "channel" === parts[ 0 ] ) {
			info.kind = "channel";
		} else if ( "catalog" === parts[ 0 ] ) {
			info.kind = "catalog";
			info.phone = waPhone( parts[ 1 ] );
		} else if ( "message" === parts[ 0 ] ) {
			info.kind = "short";
		} else if ( "web.whatsapp.com" === host && ! parts.length ) {
			info.kind = "chat";
		} else {
			return null; // site vitrine (whatsapp.com/download…) : pas une conversation
		}
		return info;
	}

	// Boutons des extensions WhatsApp courantes (du plus precis au plus generique).
	var WA_WIDGETS = [
		[ "joinchat", ".joinchat" ],
		[ "click-to-chat", ".ht-ctc, .ht_ctc_chat_style, .ht-ctc-sc-chat, .ctc_chat, #ctc_chat, [href='#ctc_chat']" ],
		[ "nta-whatsapp", ".wa__btn_popup, .wa__popup_chat_box, .wa__button, .nta-wa-button, [class*='nta-wa'], [class*='nta_wa']" ],
		[ "qlwapp", ".qlwapp, [class*='qlwapp']" ],
		[ "chaty", ".chaty-widget, .chaty-channel, [class*='chaty-']" ],
		[ "whatsapp-button", "[class*='whatsapp' i], [id*='whatsapp' i], [aria-label*='whatsapp' i], [title*='whatsapp' i]" ],
	];

	function waWidget( node ) {
		if ( ! node || 1 !== node.nodeType || ! node.closest ) return null;
		for ( var i = 0; i < WA_WIDGETS.length; i++ ) {
			var hit = null;
			// Selecteur « i » inconnu d'un vieux navigateur : on passe au suivant.
			try { hit = node.closest( WA_WIDGETS[ i ][ 1 ] ); } catch ( e ) { hit = null; }
			if ( hit ) return { id: WA_WIDGETS[ i ][ 0 ], el: hit };
		}
		return null;
	}

	// Texte du bouton (contenu du site, jamais une saisie du visiteur).
	function waLabel( el ) {
		if ( ! el || 1 !== el.nodeType ) return "";
		var text = el.getAttribute( "aria-label" ) || el.getAttribute( "title" ) || el.textContent || "";
		return text.replace( /\s+/g, " " ).trim().substring( 0, 80 );
	}

	// Bouton flottant (fixe a l'ecran) ou lien place dans le contenu.
	function isFloating( el ) {
		for ( var node = el, depth = 0; node && 1 === node.nodeType && depth < 8; node = node.parentElement, depth++ ) {
			var position = window.getComputedStyle( node ).position;
			if ( "fixed" === position || "sticky" === position ) return true;
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Evenements suivis
	// -------------------------------------------------------------------------
	function bindTrackingEvents() {
		// Profondeur de lecture (25 / 50 / 75 / 90 / 100 %), envoyee par lots.
		if ( cfg.trackScroll ) {
			var milestones = [ 25, 50, 75, 90, 100 ];
			var reached = {};
			addTrackingListener( window, "scroll", function () {
				var percent = scrollNow();
				milestones.forEach( function ( m ) {
					if ( percent >= m && ! reached[ m ] ) {
						reached[ m ] = true;
						enqueue( "scroll", { scroll_percent: m } );
					}
				} );
			}, { passive: true } );
		}

		// Clics CTA : marqueurs CyberPush, boutons usuels et ajout au panier. Seul
		// le nombre est utilise : ni libelle ni lien ne sont envoyes.
		if ( cfg.trackCta || cfg.trackWoo ) {
			addTrackingListener( document, "click", function ( e ) {
				var el = closestElement( e.target, "[data-cu-cta], .button, .btn, .wp-block-button__link, .add_to_cart_button" );
				if ( el && ( cfg.trackCta || ( cfg.trackWoo && el.matches( ".add_to_cart_button" ) ) ) ) {
					enqueue( "cta_click" );
				}
			} );
		}

		// Conversions DECLAREES : formulaires explicitement marques data-cu-conversion
		// (une soumission n'est pas une preuve d'envoi). Les formulaires Contact
		// Form 7 sont suivis a part : pas de double comptage.
		if ( cfg.trackForms ) {
			addTrackingListener( document, "submit", function ( e ) {
				var form = e.target;
				if ( ! form || ! form.matches || ! form.matches( "[data-cu-conversion]" ) ) return;
				if ( cfg.trackCf7 && closestElement( form, ".wpcf7" ) ) return;
				enqueue( "conversion" );
			} );
		}

		if ( cfg.trackWhatsapp ) bindWhatsapp();
		if ( cfg.trackCf7 ) bindContactForm7();
		if ( cfg.trackOutbound ) bindOutbound();

		// Erreurs JavaScript cote visiteur (bornees a 5 par page).
		var errCount = 0;
		addTrackingListener( window, "error", function ( e ) {
			if ( errCount >= 5 || ! e ) return;
			// En phase de capture, window reçoit aussi les échecs de chargement de
			// ressources (image 404, script bloqué) : ce ne sont pas des erreurs JS.
			if ( e.target && e.target !== window ) return;
			var message = cleanErrorText( e.message || ( e.error && e.error.message ) || "" );
			if ( ! message ) return;
			errCount++;
			enqueue( "js_error", {
				message: message,
				source: cleanUrl( e.filename || "" ).replace( /\?.*$/, "" ).substring( 0, 300 ),
				line: e.lineno || 0,
			} );
		}, true );

		// Duree de lecture / sortie de page. Le temps VISIBLE est envoye par
		// segments a chaque masquage de la page (le serveur additionne les durees).
		var start = Date.now();
		onPageHide( function () {
			if ( null === start ) return;
			var seconds = Math.round( ( Date.now() - start ) / 1000 );
			start = null;
			if ( seconds > 0 ) enqueue( "exit", { duration: seconds } );
		} );
		onPageShow( function () {
			if ( null === start ) start = Date.now();
		} );

		// Signal de presence : tant que l'onglet est visible et que le visiteur a
		// agi depuis moins de 30 min, un « ping » leger (ou le lot en attente)
		// garde sa session dans le temps reel (fenetre de 5 min).
		var heartbeat = Math.max( 0, Number( cfg.heartbeat ) || 0 ) * 1000;
		if ( heartbeat ) {
			var markActive = function () { lastActivity = Date.now(); };
			[ "pointerdown", "keydown", "scroll", "touchstart", "wheel" ].forEach( function ( name ) {
				addTrackingListener( window, name, markActive, { passive: true, capture: true } );
			} );
			var timer = setInterval( function () {
				var now = Date.now();
				if ( "visible" === document.visibilityState && now - lastActivity < IDLE_LIMIT && now - lastSent >= heartbeat - 1000 ) {
					if ( queue.length ) flush( false );
					else enqueue( "ping", null, true );
				}
			}, heartbeat );
			trackingListeners.push( function () { clearInterval( timer ); } );
			// Retour sur l'onglet : la presence est signalee tout de suite.
			onPageShow( function () {
				markActive();
				if ( Date.now() - lastSent >= heartbeat ) enqueue( "ping", null, true );
			} );
		}

		// Masquage (onglet change, application en arriere-plan) et depart de la
		// page : chaque gestionnaire ajoute ses evenements, puis UN envoi groupe.
		addTrackingListener( document, "visibilitychange", function () {
			if ( "hidden" !== document.visibilityState ) return;
			hideHandlers.slice().forEach( function ( fn ) { fn(); } );
			flush( true );
		} );
		addTrackingListener( window, "pagehide", function () {
			hideHandlers.slice().forEach( function ( fn ) { fn(); } );
			leaveHandlers.slice().forEach( function ( fn ) { fn(); } );
			flush( true );
		} );
	}

	// -------------------------------------------------------------------------
	// Clics WhatsApp : une INTENTION de contact (l'envoi du message n'est pas
	// verifiable). Trois sources, un seul clic compte (1,5 s d'ecart) : lien
	// clique, ouverture par script (window.open…) et evenement « joinchat:open ».
	// En dernier recours, un bouton d'extension suivi d'un depart immediat.
	// -------------------------------------------------------------------------
	function bindWhatsapp() {
		var lastWaAt = 0;
		var lastClick = { el: null, at: 0 };
		var pending = null;

		function recentClick() {
			return Date.now() - lastClick.at < 1500 ? lastClick.el : null;
		}

		function record( info, el, widgetId, fallback ) {
			var now = Date.now();
			pending = null;
			if ( now - lastWaAt < 1500 ) return;
			lastWaAt = now;
			var anchor = el ? ( closestElement( el, "a, button, [role='button']" ) || el ) : null;
			var found = el ? waWidget( el ) : null;
			enqueue( "whatsapp", {
				label: waLabel( anchor ),
				wa_kind: info.kind,
				wa_phone: info.phone,
				wa_widget: widgetId || ( found ? found.id : ( fallback || "link" ) ),
				wa_placement: anchor ? ( isFloating( anchor ) ? "floating" : "inline" ) : "",
				page_seconds: pageSeconds(),
				scroll_percent: scrollNow(),
			}, true );
		}

		// Element reellement clique : dans un shadow DOM ouvert, l'evenement vu par
		// le document designe l'hote ; le chemin compose donne le vrai element.
		function realTarget( e ) {
			try {
				var path = "function" === typeof e.composedPath ? e.composedPath() : [];
				if ( path && path[ 0 ] && 1 === path[ 0 ].nodeType ) return path[ 0 ];
			} catch ( err ) {}
			return e.target;
		}

		// Lien clique, y compris quand il entoure un composant a shadow DOM.
		function linkFromEvent( e, target ) {
			var link = closestElement( target, "a[href], area[href]" );
			if ( link ) return link;
			try {
				var path = "function" === typeof e.composedPath ? e.composedPath() : [];
				for ( var i = 0; i < path.length && i < 15; i++ ) {
					var node = path[ i ];
					if ( node && 1 === node.nodeType && node.matches && node.matches( "a[href], area[href]" ) ) return node;
				}
			} catch ( err ) {}
			return null;
		}

		// Adresse WhatsApp ecrite dans le bouton lui-meme (onclick="location.href=…",
		// data-href, data-url…) : contenu du site, jamais une saisie du visiteur.
		function infoFromAttributes( el ) {
			for ( var node = el, depth = 0; node && 1 === node.nodeType && depth < 3; node = node.parentElement, depth++ ) {
				var attrs = node.attributes || [];
				for ( var i = 0; i < attrs.length && i < 40; i++ ) {
					var name = String( attrs[ i ].name ).toLowerCase();
					if ( "onclick" !== name && 0 !== name.indexOf( "data-" ) ) continue;
					var found = String( attrs[ i ].value || "" ).substring( 0, 2000 ).match( WA_URL_IN_TEXT );
					var info = found ? whatsappInfo( found[ 0 ].replace( /&amp;/g, "&" ) ) : null;
					if ( info ) return info;
				}
			}
			return null;
		}

		// Le bouton clique se presente-t-il lui-meme comme un bouton WhatsApp
		// (classe, identifiant, libelle, image) ? Un grand bloc qui contiendrait
		// le mot ne compte pas : seul l'element cliquable est examine.
		function looksLikeWhatsapp( el ) {
			if ( ! el || 1 !== el.nodeType ) return false;
			var cls = el.getAttribute( "class" ) || "";
			var own = [ cls, el.id || "", el.getAttribute( "aria-label" ) || "", el.getAttribute( "title" ) || "", el.getAttribute( "name" ) || "" ].join( " " );
			if ( WA_WORD.test( own ) ) return true;
			var text = ( el.textContent || "" ).replace( /\s+/g, " " ).trim();
			if ( text && text.length <= 60 && WA_WORD.test( text ) ) return true;
			try {
				return !! ( el.querySelector && el.querySelector( "img[alt*='whatsapp' i], img[src*='whatsapp' i], [class*='whatsapp' i], [aria-label*='whatsapp' i], [title*='whatsapp' i]" ) );
			} catch ( err ) {
				return false;
			}
		}

		function onClick( e ) {
			if ( "auxclick" === e.type && 1 !== e.button ) return; // clic molette : nouvel onglet
			var target = realTarget( e );
			lastClick = { el: target, at: Date.now() };
			var link = linkFromEvent( e, target );
			var info = link ? whatsappInfo( link.getAttribute( "href" ) ) : null;
			if ( info ) {
				record( info, link, "" );
				return;
			}
			var clickable = closestElement( target, "a, button, [role='button'], [onclick], [data-cu-whatsapp], input[type='button'], input[type='submit']" ) || target;
			// Marqueur explicite pose par le site : data-cu-whatsapp (numero ou lien facultatif).
			var marked = closestElement( target, "[data-cu-whatsapp]" );
			if ( marked ) {
				var value = marked.getAttribute( "data-cu-whatsapp" ) || "";
				record( whatsappInfo( value ) || { kind: waPhone( value ) ? "chat" : "unknown", phone: waPhone( value ) }, marked, "", "marker" );
				return;
			}
			// Bouton qui ouvre WhatsApp par son propre script, avec l'adresse en clair.
			info = infoFromAttributes( clickable );
			if ( info ) {
				record( info, clickable, "", "script" );
				return;
			}
			// Bouton sans adresse lisible : retenu 1,5 s, compte seulement si le
			// visiteur quitte aussitot la page vers l'application (ou si le script du
			// bouton ouvre WhatsApp : window.open et la navigation sont observes).
			var closing = closestElement( target, "[class*='close'], [class*='Close'], [aria-label*='ermer'], [aria-label*='lose']" );
			var widget = waWidget( target );
			pending = null;
			if ( closing || ! widget || "joinchat" === widget.id ) return;
			var href = link ? String( link.getAttribute( "href" ) || "" ).trim() : "";
			var pseudo = ! link || ! href || "#" === href.charAt( 0 ) || /^javascript:/i.test( href );
			var sameSite = false;
			if ( link && ! pseudo ) {
				try { sameSite = HOSTS.indexOf( new URL( href, window.location.href ).hostname.toLowerCase() ) !== -1; } catch ( err ) { sameSite = true; }
			}
			if ( "whatsapp-button" !== widget.id ) {
				// Extension WhatsApp reconnue : son bouton, hors lien vers une page du site.
				if ( pseudo ) pending = { el: widget.el, id: widget.id, at: Date.now() };
			} else if ( ! sameSite && looksLikeWhatsapp( clickable ) ) {
				// Bouton generique : lien de redirection externe (raccourcisseur) ou
				// bouton pilote par script. Un lien vers une page du site n'est pas compte.
				pending = { el: clickable, id: widget.id, at: Date.now() };
			}
		}
		addTrackingListener( document, "click", onClick, true );
		addTrackingListener( document, "auxclick", onClick, true );

		// Joinchat annonce l'ouverture (lien, canal, numero) juste avant de la faire.
		addTrackingListener( document, "joinchat:open", function ( e ) {
			var detail = ( e && e.detail ) || {};
			if ( detail.chat_channel && "whatsapp" !== String( detail.chat_channel ).toLowerCase() ) return;
			var info = whatsappInfo( detail.link ) || { kind: "chat", phone: waPhone( detail.chat_id ) };
			record( info, recentClick(), "joinchat" );
		} );

		// Extensions qui ouvrent WhatsApp par script : l'appel d'origine est
		// toujours execute tel quel, le suivi se contente de l'observer.
		if ( "function" === typeof window.open ) {
			var nativeOpen = window.open;
			var observedOpen = function ( url ) {
				try {
					var info = whatsappInfo( url );
					if ( info ) record( info, recentClick(), "", "script" );
				} catch ( err ) {}
				return nativeOpen.apply( this || window, arguments );
			};
			window.open = observedOpen;
			trackingListeners.push( function () {
				if ( window.open === observedOpen ) window.open = nativeOpen;
			} );
		}

		// Bouton dont le script change l'adresse de la page (location.href = …,
		// location.assign) : l'API Navigation annonce la destination avant le depart.
		// Simple observation : la navigation n'est ni retardee ni modifiee.
		try {
			if ( window.navigation && "function" === typeof window.navigation.addEventListener ) {
				var onNavigate = function ( e ) {
					try {
						var info = e && e.destination ? whatsappInfo( e.destination.url ) : null;
						if ( info ) record( info, recentClick(), "", "script" );
					} catch ( err ) {}
				};
				window.navigation.addEventListener( "navigate", onNavigate );
				trackingListeners.push( function () {
					try { window.navigation.removeEventListener( "navigate", onNavigate ); } catch ( err ) {}
				} );
			}
		} catch ( err ) {}

		// Lien cree puis « clique » par un script (jamais insere dans la page, donc
		// invisible pour l'ecoute des clics) : l'appel d'origine est execute tel quel.
		try {
			var anchorProto = window.HTMLAnchorElement && window.HTMLAnchorElement.prototype;
			if ( anchorProto && "function" === typeof anchorProto.click ) {
				var nativeAnchorClick = anchorProto.click;
				var ownAnchorClick = Object.prototype.hasOwnProperty.call( anchorProto, "click" );
				var observedAnchorClick = function () {
					try {
						var info = whatsappInfo( this.getAttribute( "href" ) );
						if ( info ) record( info, recentClick() || this, "", "script" );
					} catch ( err ) {}
					return nativeAnchorClick.apply( this, arguments );
				};
				anchorProto.click = observedAnchorClick;
				trackingListeners.push( function () {
					if ( anchorProto.click !== observedAnchorClick ) return;
					if ( ownAnchorClick ) anchorProto.click = nativeAnchorClick;
					else delete anchorProto.click;
				} );
			}
		} catch ( err ) {}

		function flushPending() {
			if ( pending && Date.now() - pending.at < 1500 ) {
				record( { kind: "unknown", phone: "" }, pending.el, pending.id );
			}
			pending = null;
		}
		addTrackingListener( window, "blur", flushPending );
		onPageHide( flushPending );
		addTrackingListener( window, "pageshow", function ( e ) {
			if ( e && e.persisted ) pageStart = Date.now();
		} );
	}

	// -------------------------------------------------------------------------
	// Clics sortants : lien vers un autre site, telechargement d'un fichier,
	// appel (tel:) ou e-mail (mailto:). Seul le LIEN du site est garde (adresse
	// sans parametres ; pour un e-mail, son domaine) avec le texte du lien.
	// WhatsApp a son propre suivi (contacts) : jamais compte ici.
	// -------------------------------------------------------------------------
	var DOWNLOAD_RE = /\.(pdf|zip|rar|7z|gz|tgz|docx?|xlsx?|pptx?|odt|ods|odp|csv|txt|rtf|epub|mp3|wav|m4a|mp4|mov|avi|webm|apk|exe|msi|dmg|pkg|iso)$/i;

	function outboundInfo( raw ) {
		var href = String( raw || "" ).trim();
		if ( ! href || "#" === href.charAt( 0 ) || /^javascript:/i.test( href ) || whatsappInfo( href ) ) return null;
		if ( /^tel:/i.test( href ) ) {
			var digits = href.replace( /^tel:/i, "" ).replace( /[^\d]/g, "" ).replace( /^00/, "" );
			return /^\d{3,15}$/.test( digits ) ? { kind: "tel", target: digits } : null;
		}
		if ( /^mailto:/i.test( href ) ) {
			var mail = href.replace( /^mailto:/i, "" ).split( "?" )[ 0 ].split( "," )[ 0 ];
			var at = mail.lastIndexOf( "@" );
			var domain = at > 0 ? mail.slice( at + 1 ).toLowerCase() : "";
			try { domain = decodeURIComponent( domain ); } catch ( e ) {}
			return /^[a-z0-9.-]+\.[a-z]{2,}$/i.test( domain ) ? { kind: "mail", target: domain } : null;
		}
		var url;
		try { url = new URL( href, window.location.href ); } catch ( e ) { return null; }
		if ( ! /^https?:$/i.test( url.protocol ) ) return null;
		var target = url.protocol + "//" + url.host + url.pathname;
		if ( DOWNLOAD_RE.test( url.pathname ) ) return { kind: "download", target: target };
		if ( HOSTS.indexOf( url.hostname.toLowerCase() ) !== -1 ) return null; // page du site
		return { kind: "external", target: target };
	}

	function bindOutbound() {
		var last = { key: "", at: 0 };
		function onClick( e ) {
			if ( "auxclick" === e.type && 1 !== e.button ) return;
			var link = closestElement( e.target, "a[href], area[href]" );
			var info = link ? outboundInfo( link.getAttribute( "href" ) ) : null;
			if ( ! info ) return;
			var key = info.kind + "|" + info.target;
			if ( key === last.key && Date.now() - last.at < 1500 ) return;
			last = { key: key, at: Date.now() };
			enqueue( "outbound", { ob_kind: info.kind, ob_target: info.target.substring( 0, 500 ), label: waLabel( link ) }, true );
		}
		addTrackingListener( document, "click", onClick, true );
		addTrackingListener( document, "auxclick", onClick, true );
	}

	// -------------------------------------------------------------------------
	// Contact Form 7 : formulaire vu, commence, issue de chaque envoi (reussi,
	// champ invalide, spam, echec du courriel) et abandon. Aucun contenu saisi
	// n'est lu : seulement l'identifiant du formulaire et le NOM des champs refuses.
	// -------------------------------------------------------------------------
	var CF7_OUTCOMES = { mail_sent: "sent", mail_failed: "failed", validation_failed: "invalid", acceptance_missing: "invalid", spam: "spam", aborted: "aborted" };

	function bindContactForm7() {
		var started = new WeakMap();
		var succeeded = new WeakSet();
		var startedList = [];
		var reported = new WeakSet();

		function formRef( node ) {
			var wrap = closestElement( node, ".wpcf7" );
			if ( ! wrap ) return null;
			var form = node.matches && node.matches( "form" ) ? node : ( closestElement( node, "form" ) || wrap.querySelector( "form" ) );
			return form ? { wrap: wrap, form: form } : null;
		}

		function formId( ref, detail ) {
			var id = detail && ( detail.contactFormId || detail.id );
			if ( ! id ) id = ref.wrap.getAttribute( "data-wpcf7-id" );
			if ( ! id ) {
				var hidden = ref.form.querySelector( "input[name='_wpcf7']" );
				if ( hidden ) id = hidden.value;
			}
			id = parseInt( id, 10 );
			return id > 0 ? id : 0;
		}

		function report( ref, status, extra, detail ) {
			var id = formId( ref, detail );
			if ( ! id ) return;
			var payload = {
				form_id: String( id ),
				cf7_status: status,
				label: ( ref.form.getAttribute( "aria-label" ) || "" ).substring( 0, 120 ),
				page_seconds: pageSeconds(),
			};
			Object.keys( extra || {} ).forEach( function ( key ) { payload[ key ] = extra[ key ]; } );
			// Issue d'un envoi : immediate (le visiteur peut quitter la page aussitot).
			enqueue( "cf7", payload, "view" !== status && "start" !== status );
		}

		// Vu : une bonne partie du formulaire a l'ecran, une seule fois par page.
		if ( "function" === typeof window.IntersectionObserver ) {
			var seen = new window.IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( ! entry.isIntersecting || ( entry.intersectionRatio < 0.3 && entry.intersectionRect.height < 250 ) ) return;
					seen.unobserve( entry.target );
					var ref = formRef( entry.target );
					if ( ref ) report( ref, "view" );
				} );
			}, { threshold: [ 0, 0.1, 0.2, 0.3 ] } );
			Array.prototype.forEach.call( document.querySelectorAll( ".wpcf7 form" ), function ( form ) { seen.observe( form ); } );
			trackingListeners.push( function () { seen.disconnect(); } );
		}

		// Commence : premier champ touche.
		addTrackingListener( document, "focusin", function ( e ) {
			var ref = formRef( e.target );
			if ( ! ref || started.has( ref.form ) ) return;
			started.set( ref.form, Date.now() );
			startedList.push( ref );
			report( ref, "start" );
		}, true );

		// Contact Form 7 emet « wpcf7submit » apres chaque tentative, avec le statut
		// renvoye par son API (5.4+ : sur le formulaire ; avant : sur son conteneur).
		addTrackingListener( document, "wpcf7submit", function ( e ) {
			var detail = ( e && e.detail ) || {};
			var response = detail.apiResponse || {};
			var status = CF7_OUTCOMES[ detail.status || response.status ];
			var ref = formRef( e.target );
			if ( ! status || ! ref ) return;
			var since = started.get( ref.form );
			var extra = { fill_seconds: since ? Math.round( ( Date.now() - since ) / 1000 ) : "" };
			if ( "invalid" === status ) {
				extra.cf7_fields = ( response.invalid_fields || [] ).map( function ( field ) {
					var name = field && field.field ? String( field.field ) : "";
					if ( ! name && field && field.into ) {
						var match = String( field.into ).match( /form-control-wrap[.[]?(?:data-name=["']?)?([\w-]+)/ );
						name = match ? match[ 1 ] : "";
					}
					return name.toLowerCase().replace( /[^a-z0-9_-]/g, "" ).substring( 0, 40 );
				} ).filter( Boolean ).slice( 0, 6 ).join( "," );
			}
			if ( "sent" === status ) succeeded.add( ref.form );
			report( ref, status, extra, detail );
		} );

		// Abandon : commence mais jamais envoye avec succes avant de QUITTER la page
		// (un simple changement d'onglet n'est pas un abandon).
		onPageLeave( function () {
			startedList.forEach( function ( ref ) {
				if ( succeeded.has( ref.form ) || reported.has( ref.form ) ) return;
				reported.add( ref.form );
				report( ref, "abandon" );
			} );
		} );
	}

	// La page est masquee (onglet change, application mise en arriere-plan,
	// navigation) : le gestionnaire s'execute avant l'envoi groupe.
	function onPageHide( handler ) {
		hideHandlers.push( handler );
	}

	// La page est quittee (pagehide uniquement).
	function onPageLeave( handler ) {
		leaveHandlers.push( handler );
	}

	// La page redevient visible, y compris apres une restauration depuis le bfcache.
	function onPageShow( handler ) {
		addTrackingListener( document, "visibilitychange", function () {
			if ( "visible" === document.visibilityState ) handler();
		} );
		addTrackingListener( window, "pageshow", function ( e ) {
			if ( e && e.persisted ) handler();
		} );
	}

	// Demarre la mesure de la page au niveau courant (une seule page vue).
	function startMeasure() {
		if ( FULL === level ) {
			session = getSession();
			storageCleared = false;
		} else {
			session = { id: pageId, isNew: false };
		}
		trackingStarted = true;
		pageviewSent = true;
		// Page vue envoyee tout de suite : l'heure d'arrivee sert de borne aux durees.
		enqueue( "pageview", null, true );
		if ( trackingStarted ) bindTrackingEvents();
	}

	// Accord donne pendant une page anonyme : la page, deja comptee, devient le
	// debut de la visite suivie (meme identifiant, aucune page vue renvoyee). Le
	// serveur la fait passer de « page anonyme d'entree » a « visite suivie ».
	function upgradeToContinuous() {
		var seenAt = Number( lsGet( VISITOR_TS_KEY ) || 0 );
		var seen = "1" === lsGet( VISITOR_KEY ) && seenAt > 0 && Date.now() - seenAt < VISITOR_MAX_AGE;
		if ( ! seen ) {
			lsSet( VISITOR_KEY, "1" );
			lsSet( VISITOR_TS_KEY, String( Date.now() ) );
		}
		lsSet( SESSION_KEY, pageId );
		lsSet( SESSION_TS_KEY, String( Date.now() ) );
		lsSet( LANDING_KEY, pageUrl );
		level = FULL;
		session = { id: pageId, isNew: ! seen };
		storageCleared = false;
	}

	// Passe au niveau demande. Une page vue au plus par affichage : apres un
	// changement en cours de page (refus, opposition), rien n'est renvoye pour
	// cette page ; les pages suivantes partent du bon niveau.
	function applyLevel( next ) {
		if ( next === level ) return;
		if ( ANON === level && FULL === next && trackingStarted ) {
			upgradeToContinuous();
			return;
		}
		// Un bandeau apparu sans reponse ne coupe pas une visite suivie en cours :
		// seul un refus (ou une opposition) l'arrete.
		if ( FULL === level && ANON === next && trackingStarted && ! explicitlyDenied() ) return;
		if ( trackingStarted ) stopTracking();
		level = next;
		if ( next && ! pageviewSent ) startMeasure();
	}

	// Applique l'etat courant (consentement, opposition) ; efface les identifiants
	// stockes en cas de refus / retrait explicite. Avant l'affichage reel de la
	// page, rien ne demarre.
	function sync() {
		if ( shown ) applyLevel( decideLevel() );
		if ( ! storageCleared && explicitlyDenied() ) {
			clearAnalyticsStorage();
			storageCleared = true;
		}
	}

	// La page n'est mesuree qu'une fois reellement affichee : pas pendant un
	// prerendu, pas dans un onglet ouvert en arriere-plan tant qu'il n'est pas vu.
	// Jamais affichee : rien n'est envoye.
	function whenShown( callback ) {
		if ( document.prerendering ) {
			var onActivate = function () {
				document.removeEventListener( "prerenderingchange", onActivate );
				whenShown( callback );
			};
			document.addEventListener( "prerenderingchange", onActivate );
			return;
		}
		if ( "hidden" === document.visibilityState ) {
			var onVisible = function () {
				if ( "visible" !== document.visibilityState ) return;
				document.removeEventListener( "visibilitychange", onVisible );
				callback();
			};
			document.addEventListener( "visibilitychange", onVisible );
			return;
		}
		callback();
	}

	// API publique pour les bandeaux de consentement (CMP) et les integrateurs :
	//   window.cuAnalytics.consent( true | false )  accord / retrait « statistiques »
	//   window.cuAnalytics.optOut() / optIn()        opposition du visiteur
	// Avant le chargement du script : window.cuAnalyticsConsent = true | false.
	window.cuAnalytics = {
		loaded: true,
		mode: MODE,
		// Niveau applique : "full" (continue), "anon" (anonyme) ou null.
		level: function () { return level; },
		consent: function ( granted ) {
			apiConsent = true === granted ? true : ( false === granted ? false : null );
			sync();
		},
		optOut: function () {
			lsSet( OPTOUT_KEY, "1" );
			sync();
		},
		optIn: function () {
			lsDel( OPTOUT_KEY );
			sync();
		},
		isOptedOut: optedOut,
	};

	// Bouton [cyberpush_optout] (cette page ou un autre onglet).
	window.addEventListener( "cu_optout_change", sync );
	window.addEventListener( "storage", function ( e ) {
		if ( e && OPTOUT_KEY === e.key ) sync();
	} );

	if ( "standard" !== MODE || cfg.consentApi ) {
		[ "wp_listen_for_consent_change", "wp_consent_type_defined", "cmplz_status_change", "cmplz_fire_categories", "cookieyes_consent_update", "setCookieNotice" ].forEach( function ( name ) {
			document.addEventListener( name, function () { setTimeout( sync, 0 ); } );
		} );
	}
	// Bandeaux qui posent leur cookie ou poussent leur choix dans dataLayer sans
	// evenement exploitable : verification legere tant que la page est visible.
	if ( "standard" !== MODE ) {
		setInterval( function () {
			if ( "visible" === document.visibilityState ) sync();
		}, 2000 );
	}
	sync(); // effacement immediat en cas de refus / opposition
	whenShown( function () {
		shown = true;
		pageStart = Date.now();
		lastActivity = Date.now();
		sync();
	} );
} )();

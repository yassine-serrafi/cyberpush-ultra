/* CyberPush Ultra — Centre de contrôle (Dashboard).
 * Aucune logique métier ici : chaque carte lit une route REST déjà utilisée par
 * les autres pages (Analytics, Firewall, Spam, SEO, IndexNow, Logs) et la met en
 * forme. Une route en échec n'affecte que sa carte. Tout texte est échappé. */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	var page = document.getElementById( "cu-dashboard-page" );
	if ( ! cu || ! page ) return;

	var LINKS = {};
	try { LINKS = JSON.parse( page.getAttribute( "data-links" ) || "{}" ); } catch ( e ) { LINKS = {}; }
	var range = 7;
	var data = {};
	var GEO_RAMP = [ "#cde2fb", "#86b6ef", "#3987e5", "#1c5cab", "#0d366b" ];
	var regionNames = null;
	try { regionNames = new Intl.DisplayNames( [ "fr" ], { type: "region" } ); } catch ( e ) { regionNames = null; }

	function $( id ) { return document.getElementById( id ); }
	function esc( s ) {
		return String( null == s ? "" : s ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}
	function n( v ) { return cu.formatNumber( Number( v ) || 0 ); }
	function frDate( s ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec( String( s || "" ) );
		return m ? m[ 3 ] + "/" + m[ 2 ] + ( m[ 4 ] ? " " + m[ 4 ] + ":" + m[ 5 ] : "/" + m[ 1 ] ) : "—";
	}
	function shortDay( key ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec( String( key || "" ) );
		return m ? m[ 3 ] + "/" + m[ 2 ] : String( key || "" );
	}
	function country( code ) {
		if ( ! /^[A-Z]{2}$/.test( String( code || "" ) ) ) return "Inconnu";
		try { return regionNames ? regionNames.of( code ) : code; } catch ( e ) { return code; }
	}
	function pct( v ) { return null == v ? "n/d" : cu.formatNumber( v ) + " %"; }
	function duration( s ) {
		s = Math.max( 0, Math.round( Number( s ) || 0 ) );
		return s >= 60 ? Math.floor( s / 60 ) + " min " + ( s % 60 < 10 ? "0" : "" ) + ( s % 60 ) + " s" : s + " s";
	}
	function delta( d ) {
		if ( null == d || ! isFinite( d ) ) return "";
		var up = d >= 0;
		return '<span class="cu-db-delta ' + ( up ? "up" : "down" ) + '">' + ( up ? "▲ +" : "▼ " ) + cu.formatNumber( Math.round( d * 10 ) / 10 ) + " %</span>";
	}
	function fail( id, err ) {
		var el = $( id );
		if ( el ) el.innerHTML = '<div class="cu-empty">' + esc( ( err && err.message ) || "Données indisponibles." ) + "</div>";
	}
	function get( key, path ) {
		return cu.apiFetch( path ).then( function ( d ) { data[ key ] = d; return d; }, function ( e ) { data[ key ] = null; throw e; } );
	}
	function linkTo( key, hash ) { return ( LINKS[ key ] || "#" ) + ( hash ? "#" + hash : "" ); }

	// ---------------------------------------------------------------------
	// Graphiques SVG (survol : infobulle)
	// ---------------------------------------------------------------------
	function niceMax( v ) {
		if ( v <= 4 ) return 4;
		var p = Math.pow( 10, Math.floor( Math.log10( v ) ) );
		var s = [ 1, 2, 2.5, 5, 10 ].find( function ( k ) { return k * p >= v; } ) || 10;
		return s * p;
	}

	function chart( el, points, opts ) {
		// points : [{ label, value, prev?, tip }]
		if ( ! el ) return;
		if ( ! points.length ) { el.innerHTML = '<div class="cu-empty">Aucune donnée.</div>'; return; }
		var W = 640, H = opts.height || 220, L = 34, R = 10, T = 12, B = 26;
		var max = niceMax( Math.max.apply( null, points.map( function ( p ) { return Math.max( p.value || 0, p.prev || 0 ); } ).concat( [ 1 ] ) ) );
		var iw = W - L - R, ih = H - T - B;
		var step = iw / Math.max( 1, points.length - ( opts.bars ? 0 : 1 ) );
		function x( i ) { return opts.bars ? L + step * i + step / 2 : L + step * i; }
		function y( v ) { return T + ih - ( v / max ) * ih; }
		var svg = [];
		for ( var g = 0; g <= 4; g++ ) {
			var gv = max / 4 * g, gy = y( gv );
			svg.push( '<line x1="' + L + '" x2="' + ( W - R ) + '" y1="' + gy + '" y2="' + gy + '" class="cu-db-grid-line"/>' );
			svg.push( '<text x="' + ( L - 6 ) + '" y="' + ( gy + 3 ) + '" class="cu-db-axis" text-anchor="end">' + esc( cu.formatNumber( Math.round( gv ) ) ) + "</text>" );
		}
		var every = Math.ceil( points.length / 7 );
		points.forEach( function ( p, i ) {
			if ( i % every === 0 || i === points.length - 1 ) svg.push( '<text x="' + x( i ) + '" y="' + ( H - 6 ) + '" class="cu-db-axis" text-anchor="middle">' + esc( p.label ) + "</text>" );
		} );
		if ( opts.bars ) {
			var bw = Math.max( 4, Math.min( 26, step - 6 ) );
			points.forEach( function ( p, i ) {
				var h = Math.max( p.value ? 2 : 0, ih - ( y( p.value ) - T ) );
				svg.push( '<rect x="' + ( x( i ) - bw / 2 ) + '" y="' + ( T + ih - h ) + '" width="' + bw + '" height="' + h + '" rx="3" class="cu-db-bar"/>' );
			} );
		} else {
			var line = points.map( function ( p, i ) { return ( i ? "L" : "M" ) + x( i ).toFixed( 1 ) + "," + y( p.value || 0 ).toFixed( 1 ); } ).join( "" );
			if ( points.some( function ( p ) { return null != p.prev; } ) ) {
				svg.push( '<path d="' + points.map( function ( p, i ) { return ( i ? "L" : "M" ) + x( i ).toFixed( 1 ) + "," + y( p.prev || 0 ).toFixed( 1 ); } ).join( "" ) + '" class="cu-db-line-prev"/>' );
			}
			svg.push( '<path d="' + line + "L" + x( points.length - 1 ).toFixed( 1 ) + "," + ( T + ih ) + "L" + L + "," + ( T + ih ) + 'Z" class="cu-db-area"/>' );
			svg.push( '<path d="' + line + '" class="cu-db-line"/>' );
		}
		svg.push( '<line class="cu-db-cross" x1="0" x2="0" y1="' + T + '" y2="' + ( T + ih ) + '" visibility="hidden"/>' );
		svg.push( '<circle class="cu-db-dot-hover" r="4" visibility="hidden"/>' );
		el.innerHTML = '<svg viewBox="0 0 ' + W + " " + H + '" class="cu-db-svg" role="img" aria-label="' + esc( opts.label || "" ) + '">' + svg.join( "" ) + '<rect class="cu-db-hit" x="' + L + '" y="' + T + '" width="' + iw + '" height="' + ih + '" fill="transparent"/></svg><div class="cu-db-tip" hidden></div>';
		var s = el.querySelector( "svg" ), tip = el.querySelector( ".cu-db-tip" ), cross = el.querySelector( ".cu-db-cross" ), dot = el.querySelector( ".cu-db-dot-hover" );
		s.addEventListener( "mousemove", function ( e ) {
			var box = s.getBoundingClientRect();
			var vx = ( e.clientX - box.left ) / box.width * W;
			var i = Math.max( 0, Math.min( points.length - 1, Math.round( opts.bars ? ( vx - L - step / 2 ) / step : ( vx - L ) / step ) ) );
			var p = points[ i ];
			cross.setAttribute( "x1", x( i ) ); cross.setAttribute( "x2", x( i ) ); cross.setAttribute( "visibility", "visible" );
			if ( ! opts.bars ) { dot.setAttribute( "cx", x( i ) ); dot.setAttribute( "cy", y( p.value || 0 ) ); dot.setAttribute( "visibility", "visible" ); }
			tip.innerHTML = p.tip;
			tip.hidden = false;
			var left = x( i ) / W * box.width;
			tip.style.left = Math.min( Math.max( 4, left - tip.offsetWidth / 2 ), box.width - tip.offsetWidth - 4 ) + "px";
		} );
		s.addEventListener( "mouseleave", function () { tip.hidden = true; cross.setAttribute( "visibility", "hidden" ); dot.setAttribute( "visibility", "hidden" ); } );
	}

	// ---------------------------------------------------------------------
	// Tuiles d'état et points d'attention
	// ---------------------------------------------------------------------
	function tile( key, level, value, sub ) {
		var el = $( "cu-db-tile-" + key );
		if ( ! el ) return;
		el.classList.remove( "is-loading", "is-ok", "is-warn", "is-bad", "is-off" );
		el.classList.add( "is-" + level );
		el.querySelector( ".cu-db-tile-value" ).textContent = value;
		el.querySelector( ".cu-db-tile-sub" ).textContent = sub || " ";
		var labels = { ok: "OK", warn: "À surveiller", bad: "Action requise", off: "Inactif" };
		el.setAttribute( "title", labels[ level ] || "" );
	}

	function attention() {
		var items = [];
		var add = function ( level, text, href ) { items.push( { level: level, text: text, href: href } ); };
		var fs = data.fwStatus;
		if ( fs && ( ! fs.enabled || fs.kill_switch ) ) add( "bad", fs.kill_switch ? "Pare-feu coupé par la constante de secours (wp-config.php)." : "Pare-feu désactivé : aucune requête n’est filtrée.", linkTo( "firewall" ) );
		var v = data.vulns;
		if ( v ) {
			( v.rows || [] ).forEach( function ( r ) {
				var server = [ "php", "mariadb", "mysql", "apache", "nginx", "curl", "imagemagick" ].indexOf( r.type ) !== -1;
				if ( Number( r.total ) > 0 && ! server ) add( "bad", r.name + " " + r.version + " : " + r.total + " vulnérabilité(s) connue(s)" + ( r.fix ? " — mettre à jour vers " + r.fix : "" ) + ".", linkTo( "firewall", "cu-vulns-section" ) );
				if ( "php" === r.type && r.lifecycle && ( "eol" === r.lifecycle.state || r.lifecycle.near ) ) add( "warn", ( r.lifecycle.branch || "PHP" ) + ( "eol" === r.lifecycle.state ? " n’est plus maintenue" : " arrive en fin de support" ) + ( r.lifecycle.end ? " (" + frDate( r.lifecycle.end ) + ")" : "" ) + " : prévoyez la mise à niveau avec votre hébergeur.", linkTo( "firewall", "cu-vulns-section" ) );
			} );
			if ( "error" === v.status ) add( "warn", "La base de vulnérabilités n’a pas répondu : les derniers résultats datent du " + ( v.synced_at ? frDate( v.synced_at ) : "—" ) + ".", linkTo( "firewall", "cu-vulns-section" ) );
		}
		var lg = data.logins;
		if ( lg && lg.kpis ) {
			if ( lg.kpis.new_admins > 0 ) add( "bad", lg.kpis.new_admins + " création(s) ou promotion(s) d’administrateur sur la période : vérifiez qu’elles sont légitimes.", linkTo( "firewall", "cu-logins-section" ) );
			var alerts = ( lg.items || [] ).filter( function ( r ) { return "alert" === r.severity && ( "login" === r.event ); } ).length;
			if ( alerts ) add( "bad", alerts + " connexion(s) inhabituelle(s) après plusieurs échecs de mot de passe.", linkTo( "firewall", "cu-logins-section" ) );
			else if ( lg.kpis.new_ips > 0 ) add( "warn", lg.kpis.new_ips + " connexion(s) administrateur depuis une nouvelle adresse IP ou un nouveau pays (pas forcément une attaque).", linkTo( "firewall", "cu-logins-section" ) );
		}
		var au = data.audit;
		if ( au && au.kpi && au.kpi.critical_24h > 0 ) add( "bad", au.kpi.critical_24h + " événement(s) de sécurité critique(s) ces dernières 24 h.", linkTo( "firewall", "cu-audit-section" ) );
		if ( au && au.core_check && au.core_check.issues_total > 0 ) add( "bad", au.core_check.issues_total + " fichier(s) du cœur WordPress différent(s) des empreintes officielles.", linkTo( "firewall", "cu-audit-section" ) );
		var geo = data.countries && data.countries.geo;
		if ( geo && ! geo.cloudflare && ( ! geo.configured || ( geo.local_db && ! geo.local_db.available ) ) ) add( "warn", "Pays des visiteurs : base de pays non installée, le pays est seulement estimé.", linkTo( "settings" ) );
		var seo = data.seo;
		if ( seo && seo.issues && seo.issues.error > 0 ) add( "warn", "SEO : " + seo.issues.error + " erreur(s) sur " + ( seo.issue_pages ? seo.issue_pages.error : "?" ) + " page(s) (ex. " + ( ( seo.top_issues || [] )[ 0 ] ? seo.top_issues[ 0 ].message.toLowerCase() : "voir le détail" ) + ").", linkTo( "seo" ) );
		var logs = data.logs;
		var errs = logs ? ( logs.by_level || [] ).reduce( function ( s, r ) { return s + ( "error" === r.level ? Number( r.total ) || 0 : 0 ); }, 0 ) : 0;
		if ( errs ) add( "warn", errs + " erreur(s) technique(s) dans les journaux (24 h).", linkTo( "logs" ) );

		var el = $( "cu-db-attention" );
		var bad = items.filter( function ( i ) { return "bad" === i.level; } ).length;
		var head = items.length
			? '<div class="cu-db-att-head ' + ( bad ? "is-bad" : "is-warn" ) + '"><strong>' + items.length + " point" + ( items.length > 1 ? "s" : "" ) + " d’attention</strong>" + ( bad ? " · " + bad + " action" + ( bad > 1 ? "s" : "" ) + " recommandée" + ( bad > 1 ? "s" : "" ) : "" ) + "</div>"
			: '<div class="cu-db-att-head is-ok"><strong>Aucun point d’attention</strong> · les modules ne signalent rien qui demande une action.</div>';
		items.sort( function ( a, b ) { return ( "bad" === b.level ) - ( "bad" === a.level ); } );
		el.innerHTML = head + ( items.length ? '<ul class="cu-db-att-list">' + items.slice( 0, 8 ).map( function ( i ) {
			return '<li class="is-' + i.level + '"><span class="cu-db-att-dot" aria-hidden="true"></span><span>' + esc( i.text ) + '</span><a href="' + esc( i.href ) + '">Voir</a></li>';
		} ).join( "" ) + "</ul>" : "" );
	}

	// ---------------------------------------------------------------------
	// Rendus par carte
	// ---------------------------------------------------------------------
	function renderAudience() {
		var b = data.brief, s = data.summary, rt = data.realtime;
		if ( b ) {
			var series = ( b.series || [] ).filter( function ( p ) { return ! p.future; } );
			chart( $( "cu-db-traffic" ), series.map( function ( p ) {
				return { label: shortDay( p.key ), value: p.sessions, prev: p.prev, tip: "<b>" + esc( shortDay( p.key ) ) + "</b><br>" + n( p.sessions ) + " visite(s) · " + n( p.pageviews ) + " page(s)<br><span>Période précédente : " + n( p.prev ) + "</span>" };
			} ), { height: 230, label: "Visites par jour sur la période, comparées à la période précédente." } );
			$( "cu-db-traffic-title" ).textContent = "Visites par jour — " + ( b.period_label || "" );
			$( "cu-db-traffic-sub" ).innerHTML = "<b>" + n( b.sessions && b.sessions.value ) + "</b> visites " + delta( b.sessions && b.sessions.delta );
			$( "cu-db-traffic-legend" ).innerHTML = '<span><i class="cu-db-sw cur"></i>Période</span><span><i class="cu-db-sw prev"></i>Période précédente</span>';
			kpi( "visits", n( b.sessions && b.sessions.value ), delta( b.sessions && b.sessions.delta ) || ( b.new_visitors ? n( b.new_visitors ) + " nouveaux" : "" ) );
			kpi( "pageviews", n( b.pageviews ), s && s.pages_session ? cu.formatNumber( s.pages_session.value ) + " page(s) / visite" : "" );
		} else {
			fail( "cu-db-traffic" );
		}
		if ( s ) {
			kpi( "duration", s.avg_duration ? duration( s.avg_duration.value ) : "—", "par visite suivie" );
			kpi( "bounce", s.bounce_rate ? pct( s.bounce_rate.value ) : "—", "plus bas = mieux" );
			kpi( "human", s.traffic_quality ? pct( s.traffic_quality.human_percent ) : "—", s.traffic_quality ? n( s.traffic_quality.filtered ) + " visite(s) filtrée(s)" : "" );
		}
		if ( rt ) kpi( "online", n( rt.online ), rt.online ? "visiteur(s) actif(s)" : ( rt.last_hit_seconds ? "dernière visite il y a " + humanAgo( rt.last_hit_seconds ) : "" ) );
	}

	function humanAgo( sec ) {
		sec = Number( sec ) || 0;
		if ( sec < 3600 ) return Math.max( 1, Math.round( sec / 60 ) ) + " min";
		if ( sec < 86400 ) return Math.round( sec / 3600 ) + " h";
		return Math.round( sec / 86400 ) + " j";
	}

	function kpi( key, value, sub ) {
		var el = $( "cu-db-kpi-" + key );
		if ( ! el ) return;
		el.querySelector( ".cu-db-kpi-value" ).textContent = value;
		el.querySelector( ".cu-db-kpi-sub" ).innerHTML = sub || "&nbsp;";
	}

	function renderContacts() {
		var c = data.contacts, el = $( "cu-db-contacts" );
		if ( ! c ) return fail( "cu-db-contacts" );
		var wa = c.whatsapp ? c.whatsapp.clicks : 0, cf = c.cf7 ? c.cf7.sent : 0;
		var series = ( c.series || [] ).map( function ( p ) { return ( Number( p.wa ) || 0 ) + ( Number( p.cf7 ) || 0 ); } );
		var max = Math.max.apply( null, series.concat( [ 1 ] ) );
		el.innerHTML = '<div class="cu-db-big"><span>' + n( c.leads && c.leads.value ) + "</span> prises de contact " + delta( c.leads && c.leads.delta ) + "</div>" +
			'<div class="cu-db-chips"><span class="cu-db-chip wa"><b>' + n( wa ) + "</b> clics WhatsApp</span><span class=\"cu-db-chip cf\"><b>" + n( cf ) + "</b> messages de formulaire</span>" +
			( c.leads && null != c.leads.rate ? '<span class="cu-db-chip"><b>' + pct( c.leads.rate ) + "</b> des visites</span>" : "" ) + "</div>" +
			'<div class="cu-db-spark" aria-hidden="true">' + series.map( function ( v ) { return '<i style="height:' + Math.max( v ? 8 : 2, Math.round( v / max * 100 ) ) + '%"></i>'; } ).join( "" ) + "</div>" +
			( ( c.sources || [] ).length ? '<div class="cu-db-mini-list">' + c.sources.slice( 0, 3 ).map( function ( s ) {
				return "<div><span>" + esc( s.source ) + "</span><b>" + n( s.leads ) + "</b></div>";
			} ).join( "" ) + "</div>" : "" );
	}

	function renderCountries() {
		var d = data.countries, el = $( "cu-db-countries" );
		if ( ! d ) return fail( "cu-db-countries" );
		var rows = Array.isArray( d ) ? d : ( d.rows || [] );
		if ( ! rows.length ) { el.innerHTML = '<div class="cu-empty">Aucune visite localisée sur la période.</div>'; return; }
		var world = window.cuWorldMap, map = "";
		if ( world && world.paths ) {
			var max = Math.max.apply( null, rows.map( function ( r ) { return r.sessions; } ).concat( [ 1 ] ) );
			var by = {}; rows.forEach( function ( r ) { by[ r.code ] = r; } );
			map = '<svg class="cu-db-map" viewBox="0 0 ' + Math.round( world.width ) + " " + Math.round( world.height ) + '" role="img" aria-label="Carte des pays des visiteurs">' + Object.keys( world.paths ).map( function ( code ) {
				if ( "EH" === code && world.paths.MA ) return "";
				var r = by[ code ], dd = world.paths[ code ].path + ( "MA" === code && world.paths.EH ? " " + world.paths.EH.path : "" );
				var fill = r ? GEO_RAMP[ Math.max( 0, Math.min( 4, Math.ceil( Math.sqrt( r.sessions / max ) * 5 ) - 1 ) ) ] : "#e3e8f2";
				return '<path d="' + dd + '" fill="' + fill + '"' + ( "MA" === code ? ' stroke="' + fill + '"' : "" ) + ">" + ( r ? "<title>" + esc( country( code ) + " : " + r.sessions + " visite(s)" ) + "</title>" : "" ) + "</path>";
			} ).join( "" ) + "</svg>";
		}
		el.innerHTML = map + '<div class="cu-db-mini-list">' + rows.slice( 0, 5 ).map( function ( r ) {
			return '<div><span><em class="cu-db-cc">' + esc( r.code ) + "</em>" + esc( country( r.code ) ) + "</span><b>" + n( r.sessions ) + ' <small>' + pct( r.percent ) + "</small></b></div>";
		} ).join( "" ) + "</div>" + ( d.unknown && d.unknown.visits ? '<p class="cu-db-note">' + n( d.unknown.visits ) + " visite(s) sans pays.</p>" : "" );
	}

	function renderOutbound() {
		var o = data.outbound, src = data.sources, el = $( "cu-db-outbound" );
		var html = "";
		if ( src && src.length ) {
			var total = src.reduce( function ( s, r ) { return s + ( Number( r.sessions ) || 0 ); }, 0 ) || 1;
			html += '<div class="cu-db-card-title">Sources de trafic</div>' + src.slice( 0, 4 ).map( function ( r ) {
				var p = Math.round( r.sessions / total * 100 );
				return '<div class="cu-db-bar-row"><div><span>' + esc( r.label || r.source ) + "</span><b>" + n( r.sessions ) + " <small>" + p + ' %</small></b></div><div class="cu-bar-track"><div class="cu-bar-fill" style="width:' + p + '%"></div></div></div>';
			} ).join( "" );
		}
		if ( o ) {
			var t = o.totals || {};
			html += '<div class="cu-db-card-title" style="margin-top:14px;">Clics sortants (' + n( o.total ) + ")</div>" +
				'<div class="cu-db-chips"><span class="cu-db-chip"><b>' + n( t.external ) + "</b> liens externes</span><span class=\"cu-db-chip\"><b>" + n( t.download ) + "</b> téléchargements</span><span class=\"cu-db-chip\"><b>" + n( t.tel ) + "</b> appels</span><span class=\"cu-db-chip\"><b>" + n( t.mail ) + "</b> e-mails</span></div>";
		}
		el.innerHTML = html || '<div class="cu-empty">Données indisponibles.</div>';
	}

	function renderSecurity() {
		var ts = data.attacks, db = data.fwDash, fs = data.fwStatus;
		if ( ts ) {
			chart( $( "cu-db-attacks" ), ( ts.days || [] ).map( function ( p ) {
				return { label: shortDay( p.date ), value: p.total, tip: "<b>" + esc( shortDay( p.date ) ) + "</b><br>" + n( p.total ) + " attaque(s) bloquée(s)<br><span>" + n( p.unique_ips ) + " adresse(s) IP</span>" };
			} ), { bars: true, height: 170, label: "Attaques bloquées par jour sur 14 jours." } );
			var sm = ts.summary || {};
			$( "cu-db-attacks-sub" ).innerHTML = "<b>" + n( sm.total ) + "</b> sur 14 jours · " + n( sm.unique_ips ) + " IP distinctes";
		} else {
			fail( "cu-db-attacks" );
		}
		if ( db ) {
			$( "cu-db-fw-kpis" ).innerHTML = [
				[ "Attaques 24 h", db.attacks_24h ], [ "Blocages actifs", db.active_blocks ], [ "Tentatives de connexion", db.login_protection ], [ "Robots vérifiés", db.goodbot_verified ],
			].map( function ( k ) { return "<div><span>" + esc( k[ 0 ] ) + "</span><b>" + n( k[ 1 ] ) + "</b></div>"; } ).join( "" );
		}
		if ( fs ) {
			tile( "firewall", fs.enabled && ! fs.kill_switch ? "ok" : "bad", fs.enabled && ! fs.kill_switch ? "Actif" : "Désactivé", db ? n( db.attacks_24h ) + " attaque(s) bloquée(s) en 24 h · mode " + ( fs.mode || "normal" ) : "" );
		}
	}

	var EVENTS = {
		login: "Connexion", session: "Accès (session ouverte)", admin_created: "Administrateur créé", privileged_user_created: "Compte à privilèges créé",
		role_elevated: "Promu administrateur", privileges_granted: "Privilèges accordés", super_admin_granted: "Super-administrateur accordé",
		role_caps_added: "Rôle renforcé", role_created_privileged: "Rôle privilégié créé", admin_appeared: "Administrateur apparu hors WordPress",
		role_demoted: "Rôle admin retiré", privileges_removed: "Privilèges retirés", admin_deleted: "Administrateur supprimé", admin_disappeared: "Administrateur disparu hors WordPress",
		password_changed: "Mot de passe modifié", password_reset: "Mot de passe réinitialisé", user_email_changed: "E-mail modifié",
		app_password_created: "Mot de passe d’application créé", app_password_deleted: "Mot de passe d’application supprimé",
	};

	function renderLogins() {
		var d = data.logins, el = $( "cu-db-logins" );
		if ( ! d ) { fail( "cu-db-logins" ); tile( "logins", "off", "—", "données indisponibles" ); return; }
		var k = d.kpis || {}, s = d.settings || {};
		if ( ! s.watch ) tile( "logins", "off", "Désactivée", "surveillance des connexions coupée" );
		else if ( k.new_admins > 0 ) tile( "logins", "bad", n( k.new_admins ) + " nouvel(s) admin", "à vérifier sur la période" );
		else if ( k.new_ips > 0 ) tile( "logins", "warn", n( k.new_ips ) + " nouvelle(s) IP", n( k.logins ) + " connexion(s) sur la période" );
		else tile( "logins", "ok", n( k.logins ) + " connexion(s)", "aucune adresse inhabituelle" );
		var items = ( d.items || [] ).slice( 0, 6 );
		el.innerHTML = '<div class="cu-db-mini-kpis compact"><div><span>Connexions</span><b>' + n( k.logins ) + "</b></div><div><span>Nouvelles IP</span><b>" + n( k.new_ips ) + "</b></div><div><span>Nouveaux admins</span><b>" + n( k.new_admins ) + "</b></div></div>" +
			( items.length ? '<ul class="cu-db-feed">' + items.map( function ( r ) {
				var badge = "alert" === r.severity ? '<span class="cu-badge cu-badge-red">Alerte</span>' : ( "new" === r.ip_status || "new" === r.country_status ? '<span class="cu-badge cu-badge-yellow">Nouvelle IP</span>' : ( "warning" === r.severity ? '<span class="cu-badge cu-badge-yellow">Attention</span>' : "" ) );
				return "<li><span class=\"cu-db-feed-date\">" + esc( frDate( r.date ) ) + "</span><span><b>" + esc( r.user_login ) + "</b> · " + esc( EVENTS[ r.event ] || r.event ) + ( r.ip ? ' <span class="cu-db-mono">' + esc( r.ip ) + "</span>" : "" ) + ( r.country ? " · " + esc( country( r.country ) ) : "" ) + "</span>" + badge + "</li>";
			} ).join( "" ) + "</ul>" : '<div class="cu-empty">Aucune connexion enregistrée sur la période.</div>' );
	}

	function renderAudit() {
		var a = data.audit, el = $( "cu-db-audit" );
		if ( ! a ) { fail( "cu-db-audit" ); tile( "fim", "off", "—", "données indisponibles" ); return; }
		var sev = { critical: [ "cu-badge-red", "Critique" ], high: [ "cu-badge-red", "Élevé" ], medium: [ "cu-badge-yellow", "Moyen" ], low: [ "cu-badge-gray", "Faible" ] };
		var core = a.core_check || {}, scan = a.last_scan || {};
		if ( false === a.enabled ) tile( "fim", "off", "Désactivée", "journal de sécurité coupé" );
		else if ( core.issues_total > 0 ) tile( "fim", "bad", n( core.issues_total ) + " fichier(s) modifié(s)", "cœur WordPress à vérifier" );
		else if ( scan.changes > 0 ) tile( "fim", "warn", n( scan.changes ) + " changement(s)", "au dernier scan d’intégrité" );
		else tile( "fim", "ok", "Intègre", scan.finished_at ? "dernier scan : " + frDate( scan.finished_at ) + " · " + n( scan.files ) + " fichiers" : "scan en attente" );
		var items = ( a.items || [] ).slice( 0, 6 );
		el.innerHTML = '<div class="cu-db-mini-kpis compact"><div><span>Critiques 24 h</span><b>' + n( a.kpi && a.kpi.critical_24h ) + "</b></div><div><span>Critiques 7 j</span><b>" + n( a.kpi && a.kpi.critical_7d ) + "</b></div><div><span>Cœur WordPress</span><b>" + ( "ok" === core.status ? "Conforme" : esc( core.status || "—" ) ) + "</b></div></div>" +
			( items.length ? '<ul class="cu-db-feed">' + items.map( function ( r ) {
				var s = sev[ r.severity ] || sev.low;
				return "<li><span class=\"cu-db-feed-date\">" + esc( frDate( r.created_at ) ) + "</span><span>" + esc( r.message ) + "</span><span class=\"cu-badge " + s[ 0 ] + '">' + s[ 1 ] + "</span></li>";
			} ).join( "" ) + "</ul>" : '<div class="cu-empty">Aucun événement enregistré.</div>' );
	}

	function renderVulns() {
		var v = data.vulns, el = $( "cu-db-vulns" );
		if ( ! v ) { fail( "cu-db-vulns" ); tile( "vulns", "off", "—", "données indisponibles" ); return; }
		var server = [ "php", "mariadb", "mysql", "apache", "nginx", "curl", "imagemagick" ];
		var rows = v.rows || [];
		var wp = rows.filter( function ( r ) { return Number( r.total ) > 0 && server.indexOf( r.type ) === -1; } );
		var srv = rows.filter( function ( r ) { return Number( r.total ) > 0 && server.indexOf( r.type ) !== -1; } );
		var alerts = wp.reduce( function ( s, r ) { return s + Number( r.total ); }, 0 );
		if ( ! v.online ) tile( "vulns", "off", "Consultation coupée", n( rows.length ) + " composants inventoriés" );
		else if ( ! v.synced_at ) tile( "vulns", "warn", "Pas encore vérifié", "lancez « Vérifier maintenant »" );
		else if ( alerts ) tile( "vulns", "bad", n( alerts ) + " à corriger", n( wp.length ) + " composant(s) · vérifié le " + frDate( v.synced_at ) );
		else tile( "vulns", srv.length ? "warn" : "ok", "Aucune connue", ( srv.length ? "serveur : à confirmer avec l’hébergeur · " : "" ) + "vérifié le " + frDate( v.synced_at ) );
		var list = wp.concat( srv ).slice( 0, 6 );
		el.innerHTML = '<div class="cu-db-mini-kpis compact"><div><span>Composants</span><b>' + n( rows.length ) + "</b></div><div><span>Alertes WordPress</span><b>" + n( alerts ) + "</b></div><div><span>Côté serveur</span><b>" + n( srv.reduce( function ( s, r ) { return s + Number( r.total ); }, 0 ) ) + "</b></div></div>" +
			( list.length ? '<ul class="cu-db-feed">' + list.map( function ( r ) {
				var isServer = server.indexOf( r.type ) !== -1;
				return "<li><span><b>" + esc( r.name ) + "</b> " + esc( r.version ) + ( r.fix ? ' → <span class="cu-db-fix">' + esc( r.fix ) + "</span>" : "" ) + "</span><span class=\"cu-badge " + ( isServer ? "cu-badge-yellow" : "cu-badge-red" ) + '">' + esc( r.total ) + ( isServer ? " (à confirmer)" : " faille(s)" ) + "</span></li>";
			} ).join( "" ) + "</ul>" : '<div class="cu-empty">Aucune vulnérabilité connue pour les versions installées. Ce n’est pas une garantie absolue.</div>' );
	}

	function ring( score ) {
		var s = Math.max( 0, Math.min( 100, Number( score ) || 0 ) ), r = 34, c = 2 * Math.PI * r;
		var col = s >= 80 ? "var(--cu-success)" : ( s >= 50 ? "#e8890c" : "var(--cu-danger)" );
		return '<svg viewBox="0 0 84 84" class="cu-db-ring" role="img" aria-label="Score SEO ' + s + ' sur 100"><circle cx="42" cy="42" r="' + r + '" class="bg"/><circle cx="42" cy="42" r="' + r + '" stroke="' + col + '" stroke-dasharray="' + ( c * s / 100 ).toFixed( 1 ) + " " + c.toFixed( 1 ) + '" transform="rotate(-90 42 42)" class="fg"/><text x="42" y="47" text-anchor="middle">' + s + "</text></svg>";
	}

	function renderSeo() {
		var s = data.seo, el = $( "cu-db-seo" );
		if ( ! s ) { fail( "cu-db-seo" ); tile( "seo", "off", "—", "données indisponibles" ); return; }
		if ( false === s.enabled ) { tile( "seo", "off", "Désactivé", "audit SEO coupé" ); el.innerHTML = '<div class="cu-empty">Audit SEO désactivé.</div>'; return; }
		var score = s.score_model && null != s.score_model.total ? s.score_model.total : s.avg_score;
		tile( "seo", score >= 80 ? "ok" : ( score >= 50 ? "warn" : "bad" ), ( null != score ? score : "—" ) + " / 100", n( s.issues && s.issues.error ) + " erreur(s) · " + n( s.total ) + " page(s) analysée(s)" );
		var health = s.health || {};
		var bars = [ [ "Technique", health.technique ], [ "Contenu", health.contenu ], [ "Balises", health.balises ], [ "Prêt pour l’IA", health.ai_ready ] ].filter( function ( b ) { return null != b[ 1 ]; } );
		el.innerHTML = '<div class="cu-db-seo-head">' + ring( score ) + '<div><div class="cu-db-card-title">Score global</div><div class="cu-db-note">' + n( s.crawled ) + " page(s) analysée(s)" + ( s.last_crawled ? " · " + esc( frDate( s.last_crawled ) ) : "" ) + "</div>" +
			'<div class="cu-db-chips"><span class="cu-db-chip bad"><b>' + n( s.issues && s.issues.error ) + '</b> erreurs</span><span class="cu-db-chip warn"><b>' + n( s.issues && s.issues.warning ) + "</b> avertissements</span></div></div></div>" +
			bars.map( function ( b ) { return '<div class="cu-db-bar-row"><div><span>' + esc( b[ 0 ] ) + "</span><b>" + n( b[ 1 ] ) + ' / 100</b></div><div class="cu-bar-track"><div class="cu-bar-fill" style="width:' + Math.max( 0, Math.min( 100, b[ 1 ] ) ) + '%"></div></div></div>'; } ).join( "" ) +
			( ( s.top_issues || [] ).length ? '<ul class="cu-db-feed">' + s.top_issues.slice( 0, 3 ).map( function ( i ) { return "<li><span>" + esc( i.message ) + '</span><span class="cu-badge ' + ( "error" === i.severity ? "cu-badge-red" : "cu-badge-yellow" ) + '">' + n( i.pages ) + " page(s)</span></li>"; } ).join( "" ) + "</ul>" : "" );
	}

	function renderSystem() {
		var sp = data.spam, ix = data.indexnow, lg = data.logs, el = $( "cu-db-system" );
		var html = "";
		if ( sp ) {
			var t = ( sp.overview && sp.overview.totals ) || {};
			var on = sp.settings && sp.settings.enabled;
			tile( "spam", on ? "ok" : "off", on ? n( t.blocked ) + " bloqué(s)" : "Désactivé", on ? n( t.examined ) + " envoi(s) examiné(s) · " + range + " j" : "" );
			html += '<div class="cu-db-card-title">Anti-spam (' + range + ' j)</div><div class="cu-db-mini-kpis compact"><div><span>Examinés</span><b>' + n( t.examined ) + "</b></div><div><span>Bloqués</span><b>" + n( t.blocked ) + "</b></div><div><span>Suspects</span><b>" + n( t.suspect ) + "</b></div></div>";
		} else {
			tile( "spam", "off", "—", "données indisponibles" );
		}
		if ( ix ) {
			var st = ix.stats || {};
			tile( "indexnow", ix.enabled ? "ok" : "off", ix.enabled ? "Actif" : "Désactivé", ix.enabled ? n( st.total ) + " URL envoyée(s)" + ( st.last_submit ? " · dernier envoi " + frDate( st.last_submit ) : "" ) : "soumission aux moteurs coupée" );
			html += '<div class="cu-db-card-title" style="margin-top:14px;">IndexNow</div><p class="cu-db-note">' + ( ix.enabled ? n( st.total ) + " URL envoyée(s) · " + n( ix.queue && ix.queue.count ) + " en file d’attente" : "Désactivé : les nouveaux contenus ne sont pas signalés aux moteurs." ) + "</p>";
		} else {
			tile( "indexnow", "off", "—", "données indisponibles" );
		}
		if ( lg ) {
			var levels = {};
			( lg.by_level || [] ).forEach( function ( r ) { levels[ r.level ] = Number( r.total ) || 0; } );
			html += '<div class="cu-db-card-title" style="margin-top:14px;">Journaux (24 h)</div><div class="cu-db-mini-kpis compact"><div><span>Total</span><b>' + n( lg.last_24h ) + "</b></div><div><span>Avertissements</span><b>" + n( levels.warning ) + "</b></div><div><span>Erreurs</span><b>" + n( levels.error ) + "</b></div></div>";
		}
		el.innerHTML = html || '<div class="cu-empty">Données indisponibles.</div>';
	}

	// ---------------------------------------------------------------------
	// Chargement
	// ---------------------------------------------------------------------
	function load() {
		var days = String( range );
		var period = 30 === range ? "month" : "week";
		var jobs = [
			get( "brief", "analytics/brief?period=" + period ).then( renderAudience, function () { renderAudience(); } ),
			get( "summary", "analytics/summary?range=" + days ).then( renderAudience, function () {} ),
			get( "realtime", "analytics/realtime" ).then( renderAudience, function () {} ),
			get( "contacts", "analytics/contacts?range=" + days ).then( renderContacts, function ( e ) { fail( "cu-db-contacts", e ); } ),
			get( "countries", "analytics/countries?range=" + days ).then( renderCountries, function ( e ) { fail( "cu-db-countries", e ); } ),
			get( "sources", "analytics/traffic-sources?range=" + days ).then( renderOutbound, function () {} ),
			get( "outbound", "analytics/outbound?range=" + days ).then( renderOutbound, function () { renderOutbound(); } ),
			get( "fwStatus", "firewall/status" ).then( renderSecurity, function () {} ),
			get( "fwDash", "firewall/dashboard" ).then( renderSecurity, function () {} ),
			get( "attacks", "firewall/attacks-timeseries" ).then( renderSecurity, function () { renderSecurity(); } ),
			get( "vulns", "firewall/vulns" ).then( renderVulns, function () { renderVulns(); } ),
			get( "logins", "firewall/logins?mark=0&days=" + days ).then( renderLogins, function () { renderLogins(); } ),
			get( "audit", "firewall/audit/list?per_page=6" ).then( renderAudit, function () { renderAudit(); } ),
			get( "spam", "spam/status?days=" + days ).then( renderSystem, function () { renderSystem(); } ),
			get( "seo", "seo/overview" ).then( renderSeo, function () { renderSeo(); } ),
			get( "indexnow", "indexnow/status" ).then( renderSystem, function () { renderSystem(); } ),
			get( "logs", "logs/summary" ).then( renderSystem, function () { renderSystem(); } ),
		];
		var settle = jobs.map( function ( p ) { return p.catch( function () {} ); } );
		Promise.all( settle ).then( function () {
			attention();
			var now = new Date();
			$( "cu-db-updated" ).textContent = "Mis à jour à " + ( now.getHours() < 10 ? "0" : "" ) + now.getHours() + ":" + ( now.getMinutes() < 10 ? "0" : "" ) + now.getMinutes();
		} );
	}

	Array.prototype.forEach.call( page.querySelectorAll( "[data-db-range]" ), function ( btn ) {
		btn.addEventListener( "click", function () {
			range = Number( btn.getAttribute( "data-db-range" ) ) || 7;
			Array.prototype.forEach.call( page.querySelectorAll( "[data-db-range]" ), function ( b ) { b.setAttribute( "aria-pressed", String( b === btn ) ); } );
			load();
		} );
	} );
	$( "cu-db-refresh" ).addEventListener( "click", load );
	load();
	// Temps réel et état de sécurité rafraîchis chaque minute, sans recharger la page.
	setInterval( function () {
		if ( "visible" !== document.visibilityState ) return;
		get( "realtime", "analytics/realtime" ).then( renderAudience, function () {} );
	}, 60000 );
} )();

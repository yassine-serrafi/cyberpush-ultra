/* CyberPush Ultra — Radar de surveillance passive (section de la page Firewall).
 * Observe les visiteurs suspects (datacenter/proxy/bot) sans les bloquer :
 * l'admin sélectionne, copie la liste, puis bloque en masse quand il veut.
 * Dépend de window.cuAdmin (apiFetch, toast, confirmAction, formatNumber).
 */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	if ( ! cu ) return;

	var state = { page: 1 };
	var enrichmentOn = true; // mis à jour à chaque chargement (réponse serveur)

	function $( id ) { return document.getElementById( id ); }

	function esc( str ) {
		return String( str == null ? "" : str ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}

	var signalLabels = {
		empty_ua:   "UA vide",
		bot:        "Bot connu",
		nonbrowser: "Non-navigateur",
		flooder:    "Flood",
		rate:       "Débit élevé",
	};

	// Même lecture que le journal de sécurité : « il y a 12 min » (heure exacte au survol).
	function relTime( mysqlDate ) {
		if ( ! mysqlDate ) return "—";
		var t = cu.siteTime ? cu.siteTime( mysqlDate ) : NaN;
		if ( isNaN( t ) ) return mysqlDate;
		var diff = Math.max( 0, Math.floor( ( Date.now() - t ) / 1000 ) );
		if ( diff < 90 ) return "à l'instant";
		if ( diff < 3600 ) return "il y a " + Math.floor( diff / 60 ) + " min";
		if ( diff < 86400 ) return "il y a " + Math.floor( diff / 3600 ) + " h";
		return "il y a " + Math.floor( diff / 86400 ) + " j";
	}

	function scoreBadge( score ) {
		score = Number( score ) || 0;
		var cls = score >= 80 ? "cu-badge-red" : score >= 60 ? "cu-badge-yellow" : score >= 40 ? "cu-badge-purple" : "cu-badge-gray";
		return '<span class="cu-badge ' + cls + '">' + score + "</span>";
	}

	// ---------------------------------------------------------------------
	function load() {
		var params =
			"?hours=" + encodeURIComponent( $( "cu-radar-window" ).value ) +
			"&filter=" + encodeURIComponent( $( "cu-radar-filter" ).value ) +
			"&min_score=" + encodeURIComponent( $( "cu-radar-minscore" ).value ) +
			"&page=" + state.page;

		var tbody = $( "cu-radar-tbody" );
		// Chaque chargement enrichit jusqu'à 6 IP via une API externe (lent) : une
		// réponse ancienne peut arriver APRÈS celle d'un filtre plus récent. Seule
		// la dernière demande a le droit d'afficher son résultat.
		var seq = ++loadSeq;
		cu.apiFetch( "firewall/radar/list" + params )
			.then( function ( d ) { if ( seq === loadSeq ) render( d ); } )
			.catch( function ( e ) {
				if ( seq !== loadSeq ) return;
				if ( tbody ) tbody.innerHTML = '<tr><td colspan="8" class="cu-empty">' + esc( e.message || "Erreur" ) + "</td></tr>";
			} );
	}
	var loadSeq = 0;

	function render( data ) {
		var c = data.counts || {};
		$( "cu-radar-c-total" ).textContent = cu.formatNumber ? cu.formatNumber( c.total || 0 ) : ( c.total || 0 );
		$( "cu-radar-c-datacenter" ).textContent = c.datacenter || 0;
		$( "cu-radar-c-proxy" ).textContent = c.proxy || 0;
		// Enrichissement désactivé (aucune IP envoyée à un tiers) : « à enrichir »
		// n'a pas de sens, on l'indique au lieu d'afficher une file qui ne bouge pas.
		enrichmentOn = ! data.enrichment || !! data.enrichment.enabled;
		var pending = $( "cu-radar-c-pending" );
		pending.textContent = enrichmentOn ? ( c.pending || 0 ) : "Désactivé";
		pending.title = enrichmentOn ? "" : "Aucun fournisseur de géolocalisation externe : aucune IP n'est transmise à un service tiers.";

		if ( data.autoblock ) applyAutoblock( data.autoblock );

		var tbody = $( "cu-radar-tbody" );
		var items = data.items || [];
		if ( ! items.length ) {
			tbody.innerHTML = '<tr><td colspan="8" class="cu-empty">' + esc( "Aucun suspect dans le filet pour ces critères. Le radar se remplit au fil du trafic." ) + "</td></tr>";
		} else {
			tbody.innerHTML = items.map( rowHtml ).join( "" );
		}
		$( "cu-radar-check-all" ).checked = false;

		// Pagination.
		var pag = $( "cu-radar-pagination" );
		if ( pag ) {
			if ( data.pages > 1 ) {
				pag.innerHTML =
					'<button class="cu-btn cu-btn-sm" ' + ( data.page <= 1 ? "disabled" : "" ) + ' id="cu-radar-prev">‹</button>' +
					'<span style="margin:0 10px;font-size:12px;color:var(--cu-text-dim);">Page ' + data.page + " / " + data.pages + "</span>" +
					'<button class="cu-btn cu-btn-sm" ' + ( data.page >= data.pages ? "disabled" : "" ) + ' id="cu-radar-next">›</button>';
				var prev = $( "cu-radar-prev" ), next = $( "cu-radar-next" );
				if ( prev ) prev.addEventListener( "click", function () { if ( state.page > 1 ) { state.page--; load(); } } );
				if ( next ) next.addEventListener( "click", function () { state.page++; load(); } );
			} else {
				pag.innerHTML = "";
			}
		}
	}

	function rowHtml( it ) {
		var badges = ( it.signals || [] ).map( function ( s ) {
			return '<span class="cu-badge cu-badge-gray">' + esc( signalLabels[ s ] || s ) + "</span>";
		} );
		if ( Number( it.is_datacenter ) ) badges.unshift( '<span class="cu-badge cu-badge-red">Datacenter</span>' );
		if ( Number( it.is_proxy ) ) badges.unshift( '<span class="cu-badge cu-badge-yellow">Proxy/VPN</span>' );

		var status = "";
		if ( Number( it.is_whitelisted ) ) status = ' <span class="cu-badge cu-badge-green">Whitelist</span>';
		else if ( Number( it.is_blocked ) ) status = ' <span class="cu-badge cu-badge-red">Déjà bloquée</span>';

		var org = it.org ? esc( it.org ) : '<span style="color:var(--cu-text-faint);">—</span>';
		var asn = it.asn ? '<div style="font-size:11px;color:var(--cu-text-faint);">' + esc( it.asn ) + "</div>" : "";

		return "<tr>" +
			'<td><input type="checkbox" class="cu-radar-row" data-ip="' + esc( it.ip ) + '" aria-label="' + esc( "Sélectionner " + it.ip ) + '"' + ( Number( it.is_whitelisted ) || Number( it.is_blocked ) ? " disabled" : "" ) + "></td>" +
			"<td><code>" + esc( it.ip ) + "</code>" + status + "</td>" +
			"<td>" + esc( it.country_code || "—" ) + "</td>" +
			"<td>" + org + asn + "</td>" +
			'<td style="max-width:240px;">' + ( badges.join( " " ) || "—" ) + "</td>" +
			"<td>" + ( it.hits || 0 ) + "</td>" +
			"<td>" + scoreBadge( it.score ) + "</td>" +
			'<td style="font-size:11px;color:var(--cu-text-dim);white-space:nowrap;" title="' + esc( it.last_seen || "" ) + '">' + esc( relTime( it.last_seen ) ) + "</td>" +
			"</tr>";
	}

	// ---------------------------------------------------------------------
	function selectedIps() {
		var out = [];
		document.querySelectorAll( ".cu-radar-row:checked" ).forEach( function ( cb ) {
			if ( cb.dataset.ip ) out.push( cb.dataset.ip );
		} );
		return out;
	}

	function requireSelection() {
		var ips = selectedIps();
		if ( ! ips.length ) {
			cu.toast( "Sélectionnez au moins une IP.", "error" );
			return null;
		}
		return ips;
	}

	function blockSelection() {
		var ips = requireSelection();
		if ( ! ips ) return;
		var mode = $( "cu-radar-block-mode" ).value;
		var dur  = $( "cu-radar-block-duration" ).value;
		var label = mode === "cidr" ? "les plages /24 de " + ips.length + " IP" : ips.length + " IP";
		var durLabel = { "24h": "24 heures", "7d": "7 jours", "30j": "30 jours", "permanent": "une durée illimitée" }[ dur ] || dur;
		if ( ! cu.confirmAction( "Bloquer " + label + " pour " + durLabel + " ?" ) ) return;
		cu.apiFetch( "firewall/radar/block-bulk", { method: "POST", body: { ips: ips, mode: mode, duration: dur } } )
			.then( function ( res ) {
				var msg = ( res.blocked || 0 ) + " IP bloquée(s).";
				if ( res.skipped ) msg += " " + res.skipped + " ignorée(s) : adresse protégée (la vôtre, celle du serveur ou d'un relais, ou privée).";
				cu.toast( msg, "success" );
				load();
			} )
			.catch( function ( e ) { cu.toast( e.message || "Échec du blocage.", "error" ); } );
	}

	function ignoreSelection() {
		var ips = requireSelection();
		if ( ! ips ) return;
		cu.apiFetch( "firewall/radar/ignore", { method: "POST", body: { ips: ips } } )
			.then( function ( res ) {
				cu.toast( ( res.ignored || 0 ) + " IP ignorée(s).", "success" );
				load();
			} )
			.catch( function ( e ) { cu.toast( e.message || "Échec.", "error" ); } );
	}

	function copyList() {
		var ips = selectedIps();
		if ( ! ips.length ) {
			// Rien de coché → on copie toutes les IP affichées.
			document.querySelectorAll( ".cu-radar-row" ).forEach( function ( cb ) { if ( cb.dataset.ip ) ips.push( cb.dataset.ip ); } );
		}
		if ( ! ips.length ) { cu.toast( "Aucune IP à copier.", "error" ); return; }
		if ( $( "cu-radar-block-mode" ).value === "cidr" ) {
			ips = ips.map( function ( ip ) {
				if ( ip.indexOf( ":" ) !== -1 ) return ip;
				var p = ip.split( "." );
				return p.length === 4 ? p[0] + "." + p[1] + "." + p[2] + ".0/24" : ip;
			} );
			ips = ips.filter( function ( v, i, a ) { return a.indexOf( v ) === i; } );
		}
		var text = ips.join( "\n" );
		var done = function () { cu.toast( ips.length + " IP copiée(s).", "success" ); };
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, function () { fallbackCopy( text, done ); } );
		} else {
			fallbackCopy( text, done );
		}
	}

	function fallbackCopy( text, done ) {
		var ta = document.createElement( "textarea" );
		ta.value = text; ta.style.position = "fixed"; ta.style.opacity = "0";
		document.body.appendChild( ta ); ta.select();
		try { document.execCommand( "copy" ); done(); } catch ( e ) { /* noop */ }
		ta.remove();
	}

	// ---------------------------------------------------------------------
	// Auto-blocage intelligent (opt-in) : peuplement + sauvegarde des réglages.
	// ---------------------------------------------------------------------
	var abReady = false; // évite de sauver pendant le peuplement initial

	// Seuils grisés = réellement désactivés : sans cela, ils restaient modifiables
	// au clavier (Tab) tout en paraissant inactifs. .value reste lisible.
	function setAbOptsState( on ) {
		var opts = $( "cu-radar-ab-opts" );
		if ( ! opts ) return;
		opts.classList.toggle( "is-off", ! on );
		opts.querySelectorAll( "select, input" ).forEach( function ( el ) { el.disabled = ! on; } );
	}

	function applyAutoblock( ab ) {
		var en = $( "cu-radar-ab-enabled" );
		if ( en ) en.checked = !! ab.enabled;
		if ( $( "cu-radar-ab-score" ) ) $( "cu-radar-ab-score" ).value = String( ab.min_score || 90 );
		if ( $( "cu-radar-ab-hits" ) ) $( "cu-radar-ab-hits" ).value = String( ab.min_hits || 3 );
		if ( $( "cu-radar-ab-mode" ) ) $( "cu-radar-ab-mode" ).value = ab.mode === "cidr" ? "cidr" : "ip";
		if ( $( "cu-radar-ab-duration" ) ) $( "cu-radar-ab-duration" ).value = ab.duration || "7d";
		setAbOptsState( !! ab.enabled );
		abReady = true;
	}

	function saveAutoblock( e ) {
		if ( ! abReady ) return;
		var toggle = $( "cu-radar-ab-enabled" );
		if ( e && e.target === toggle && toggle.checked && ! cu.confirmAction(
			"Activer l'auto-blocage ? Toutes les 5 minutes, les IP datacenter/proxy dont le score et le nombre d'observations dépassent vos seuils seront bloquées automatiquement (jamais les robots de moteurs vérifiés ni la whitelist)." +
			( enrichmentOn ? "" : "\n\nAttention : l'enrichissement réseau est désactivé (aucun fournisseur de géolocalisation externe). Sans lui, aucune IP n'est reconnue comme datacenter ou proxy et l'auto-blocage ne bloquera rien." )
		) ) {
			toggle.checked = false;
			return;
		}
		var patch = {
			radar_autoblock_enabled:  $( "cu-radar-ab-enabled" ).checked,
			radar_autoblock_min_score: parseInt( $( "cu-radar-ab-score" ).value, 10 ) || 90,
			radar_autoblock_min_hits:  parseInt( $( "cu-radar-ab-hits" ).value, 10 ) || 3,
			radar_autoblock_mode:     $( "cu-radar-ab-mode" ).value,
			radar_autoblock_duration: $( "cu-radar-ab-duration" ).value
		};
		setAbOptsState( patch.radar_autoblock_enabled );
		cu.apiFetch( "settings/firewall", { method: "POST", body: patch } )
			.then( function () {
				cu.toast( patch.radar_autoblock_enabled ? "Auto-blocage activé." : "Auto-blocage désactivé / mis à jour.", "success" );
			} )
			.catch( function ( err ) { cu.toast( err.message || "Échec de l'enregistrement.", "error" ); } );
	}

	// ---------------------------------------------------------------------
	function bind() {
		[ "cu-radar-window", "cu-radar-filter", "cu-radar-minscore" ].forEach( function ( id ) {
			$( id ).addEventListener( "change", function () { state.page = 1; load(); } );
		} );
		$( "cu-radar-refresh" ).addEventListener( "click", function () { load(); } );
		$( "cu-radar-block" ).addEventListener( "click", blockSelection );
		$( "cu-radar-ignore" ).addEventListener( "click", ignoreSelection );
		$( "cu-radar-copy" ).addEventListener( "click", copyList );

		$( "cu-radar-check-all" ).addEventListener( "change", function () {
			var on = this.checked;
			document.querySelectorAll( ".cu-radar-row" ).forEach( function ( cb ) { if ( ! cb.disabled ) cb.checked = on; } );
		} );
		$( "cu-radar-select-dc" ).addEventListener( "click", function () {
			document.querySelectorAll( "#cu-radar-tbody tr" ).forEach( function ( tr ) {
				var cb = tr.querySelector( ".cu-radar-row" );
				if ( cb && ! cb.disabled && tr.innerHTML.indexOf( ">Datacenter<" ) !== -1 ) cb.checked = true;
			} );
		} );

		// Auto-blocage : sauvegarde au changement de n'importe quel contrôle.
		[ "cu-radar-ab-enabled", "cu-radar-ab-score", "cu-radar-ab-hits", "cu-radar-ab-mode", "cu-radar-ab-duration" ].forEach( function ( id ) {
			var el = $( id );
			if ( el ) el.addEventListener( "change", saveAutoblock );
		} );
	}

	document.addEventListener( "DOMContentLoaded", function () {
		if ( ! document.getElementById( "cu-radar-section" ) ) return;
		bind();
		load();
	} );
} )();

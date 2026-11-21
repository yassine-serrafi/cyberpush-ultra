/* CyberPush Ultra — page « Index Now » (indexation instantanée IndexNow).
 * Dépend de window.cuAdmin (apiFetch, toast, confirmAction).
 */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	if ( ! cu ) return;

	var state = { keyLocation: "", endpoints: {} };

	function $( id ) { return document.getElementById( id ); }

	function reasonLabel( reason ) {
		switch ( reason ) {
			case "auto": return "Automatique";
			case "bulk": return "Envoi global";
			case "manual": return "Manuel";
			default: return reason || "—";
		}
	}

	function engineLabel( host ) {
		// « … — recommandé » sert au choix dans le menu, pas à l'historique.
		var label = state.endpoints[ host ] || host || "—";
		return String( label ).split( " — " )[ 0 ];
	}

	// « 2026-10-03 14:22:11 » (heure du site) → « 03/10/2026 14:22 ».
	function formatDate( mysql ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec( String( mysql || "" ) );
		return m ? m[ 3 ] + "/" + m[ 2 ] + "/" + m[ 1 ] + " " + m[ 4 ] + ":" + m[ 5 ] : String( mysql || "" );
	}

	function setVerified( verifiedAt ) {
		var badge = $( "cu-in-verify-badge" );
		if ( ! badge ) return;
		badge.className = "cu-in-verify-badge cu-badge " + ( verifiedAt ? "cu-badge-green" : "cu-badge-gray" );
		badge.textContent = verifiedAt ? "Vérifiée" : "Non vérifiée";
		badge.title = verifiedAt ? "Dernière preuve : " + formatDate( verifiedAt ) : "Cliquez sur « Vérifier la propriété »";
	}

	function setKeyLocation( location ) {
		state.keyLocation = location || "";
		var link = $( "cu-in-keyfile-link" );
		if ( link ) {
			link.href = state.keyLocation || "#";
			link.hidden = ! state.keyLocation;
		}
		var copy = $( "cu-in-copy-key" );
		if ( copy ) copy.disabled = ! state.keyLocation;
	}

	function escapeHtml( str ) {
		return String( str == null ? "" : str ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}

	// ---------------------------------------------------------------------
	// Chargement de l'état
	// ---------------------------------------------------------------------
	// Erreur réseau du navigateur (« Failed to fetch »…) : message clair en français.
	function friendlyError( e ) {
		var msg = ( e && e.message ) ? String( e.message ) : "";
		if ( ! msg || ( e && "TypeError" === e.name ) || /failed to fetch|networkerror|load failed|network request failed/i.test( msg ) ) {
			return new Error( "Le serveur WordPress ne répond pas (connexion interrompue ou serveur indisponible). Vérifiez votre connexion puis réessayez." );
		}
		return e;
	}
	function apiFetch( path, opts ) {
		return cu.apiFetch( path, opts ).catch( function ( e ) { throw friendlyError( e ); } );
	}

	function loadStatus() {
		apiFetch( "indexnow/status" )
			.then( render )
			.catch( function ( e ) {
				var msg = e.message || "Erreur de chargement.";
				cu.toast( msg, "error" );
				// Jamais bloqué sur « Chargement… » : l'état est dit indisponible.
				var stateLabel = $( "cu-in-state" );
				if ( stateLabel && /Chargement/.test( stateLabel.textContent ) ) stateLabel.textContent = "Indisponible";
				var body = $( "cu-in-history-body" );
				if ( body && body.querySelector( ".cu-loading" ) ) {
					body.innerHTML = '<tr><td colspan="5" class="cu-empty">' + escapeHtml( msg ) + ' <button type="button" class="cu-btn cu-btn-sm" id="cu-in-retry">Réessayer</button></td></tr>';
					var retry = $( "cu-in-retry" );
					if ( retry ) retry.addEventListener( "click", function () {
						body.innerHTML = '<tr><td colspan="5" class="cu-loading"><span class="cu-spinner"></span> Chargement…</td></tr>';
						loadStatus();
					} );
				}
			} );
	}

	function render( data ) {
		state.endpoints = data.endpoints || {};

		// Statut / activation
		var enabled = !! data.enabled;
		$( "cu-in-enabled" ).checked = enabled;
		$( "cu-in-auto" ).checked = !! data.auto_submit;
		var dot = $( "cu-in-dot" );
		var stateLabel = $( "cu-in-state" );
		dot.className = "cu-in-dot " + ( enabled ? "is-live" : "is-off" );
		stateLabel.textContent = enabled ? "Actif" : "Désactivé";

		// Module coupé : le serveur refuserait tout envoi. On le dit avant le clic.
		var offHint = "Activez d'abord le module (carte « Statut »).";
		[ "cu-in-submit", "cu-in-submit-all", "cu-in-verify", "cu-in-regen" ].forEach( function ( id ) {
			var b = $( id );
			if ( ! b ) return;
			b.disabled = ! enabled;
			b.title = enabled ? ( "cu-in-regen" === id ? "Régénérer une nouvelle clé" : "" ) : offHint;
		} );
		$( "cu-in-auto" ).disabled = ! enabled;

		// Envois bloqués (site en noindex, préproduction, domaine différent de la clé).
		var blocked = $( "cu-in-blocked" );
		var blockedText = $( "cu-in-blocked-text" );
		if ( blocked && blockedText ) {
			blockedText.textContent = data.blocked_reason || "";
			blocked.hidden = ! ( enabled && data.blocked_reason );
		}
		if ( enabled && data.blocked_reason ) {
			stateLabel.textContent = "Actif — envois bloqués";
			dot.className = "cu-in-dot is-off";
		}

		// Clé + fichier + preuve de propriété (aucune clé n'existe tant que le module est inactif)
		$( "cu-in-key" ).textContent = data.key_masked || ( enabled ? "—" : "Générée à l'activation" );
		setKeyLocation( data.key_location );
		setVerified( data.verified_at );

		// Endpoint select
		var sel = $( "cu-in-endpoint" );
		if ( sel && ! sel.dataset.filled ) {
			sel.innerHTML = "";
			Object.keys( state.endpoints ).forEach( function ( host ) {
				var opt = document.createElement( "option" );
				opt.value = host;
				opt.textContent = state.endpoints[ host ];
				sel.appendChild( opt );
			} );
			sel.dataset.filled = "1";
		}
		if ( sel && data.endpoint ) sel.value = data.endpoint;

		// Quota / total
		var stats = data.stats || {};
		var dayCount = Number( stats.day_count ) || 0;
		$( "cu-in-day-count" ).textContent = cu.formatNumber( dayCount );

		$( "cu-in-total" ).textContent = cu.formatNumber( stats.total || 0 );
		var sends = Number( stats.sends ) || 0;
		var lastLine = stats.last_submit ? "Dernier envoi : " + formatDate( stats.last_submit ) : "Aucun envoi encore";
		if ( sends > 0 ) {
			lastLine = sends + ( sends > 1 ? " envois" : " envoi" ) + " · " + lastLine;
		}
		// File d'envoi automatique (WP-Cron) : visible si quelque chose attend.
		var queue = data.queue || {};
		var waiting = Number( queue.count ) || 0;
		if ( waiting > 0 ) {
			lastLine += " · " + waiting + " en attente d'envoi" + ( queue.oldest ? " depuis " + formatDate( queue.oldest ) : "" );
		}
		$( "cu-in-last" ).textContent = lastLine;

		// URL connues → libellé du bouton global
		var btnAll = $( "cu-in-submit-all" );
		if ( btnAll && enabled && data.known_urls != null ) {
			btnAll.title = cu.formatNumber( data.known_urls ) + " URL publiques indexables (accueil, articles, pages, produits et CPT ; noindex et contenus masqués exclus)";
		}

		renderHistory( data.history || [] );
	}

	function renderHistory( items ) {
		var body = $( "cu-in-history-body" );
		if ( ! body ) return;
		if ( ! items.length ) {
			body.innerHTML = '<tr><td colspan="5" class="cu-empty">Aucun envoi pour le moment. Publiez un contenu ou poussez une URL.</td></tr>';
			return;
		}
		body.innerHTML = items.map( function ( h ) {
			var badge = h.ok
				? '<span class="cu-badge cu-badge-green">HTTP ' + escapeHtml( h.code ) + "</span>"
				: '<span class="cu-badge cu-badge-red">' + ( h.code ? "HTTP " + escapeHtml( h.code ) : "Échec" ) + "</span>";
			var skippedCount = Number( h.skipped ) || 0;
			var skipped = skippedCount > 0
				? ' <span class="cu-pill cu-pill-ghost" title="URL ignorées (déjà poussées &lt; 24 h ou invalides)">' + skippedCount + " ignorée" + ( skippedCount > 1 ? "s" : "" ) + "</span>"
				: "";
			return "<tr>" +
				'<td style="white-space:nowrap;">' + escapeHtml( formatDate( h.time ) ) + "</td>" +
				'<td><span class="cu-badge cu-badge-gray">' + escapeHtml( reasonLabel( h.reason ) ) + "</span></td>" +
				"<td>" + urlCell( h ) + "</td>" +
				"<td>" + escapeHtml( engineLabel( h.engine ) ) + "</td>" +
				'<td>' + badge + skipped + ' <span class="cu-in-hist-msg" title="' + escapeHtml( h.message ) + '">' + escapeHtml( h.message ) + "</span></td>" +
				"</tr>";
		} ).join( "" );
	}

	/**
	 * Cellule « URL » : si l'envoi contient plusieurs URL, on les rend toutes
	 * dans un bloc déroulable (<details>) — pour « voir tout le tout » et non
	 * plus une seule URL suivie d'un « +28 » opaque.
	 */
	function urlCell( h ) {
		var urls = Array.isArray( h.urls ) && h.urls.length ? h.urls : ( h.sample ? [ h.sample ] : [] );
		if ( ! urls.length ) return "—";
		if ( urls.length === 1 ) {
			return '<a class="cu-in-hist-url" href="' + escapeHtml( urls[ 0 ] ) + '" target="_blank" rel="noopener" title="' + escapeHtml( urls[ 0 ] ) + '">' + escapeHtml( urls[ 0 ] ) + "</a>";
		}
		var list = urls.map( function ( u ) {
			return '<li><a href="' + escapeHtml( u ) + '" target="_blank" rel="noopener">' + escapeHtml( u ) + "</a></li>";
		} ).join( "" );
		var count = Number( h.count ) || urls.length;
		if ( h.truncated && count > urls.length ) {
			list += '<li class="cu-in-hist-more">' + ( count - urls.length ) + " autre(s) URL non détaillée(s)…</li>";
		}
		return '<details class="cu-in-hist-details">' +
			'<summary><span class="cu-in-hist-url">' + escapeHtml( urls[ 0 ] ) + '</span>' +
			' <span class="cu-pill cu-pill-ghost">' + count + " URL</span></summary>" +
			'<ul class="cu-in-hist-urls">' + list + "</ul>" +
			"</details>";
	}

	// ---------------------------------------------------------------------
	// Enregistrement d'un réglage (route générique /settings/indexnow)
	// ---------------------------------------------------------------------
	function saveSetting( patch ) {
		return apiFetch( "settings/indexnow", { method: "POST", body: patch } )
			.then( function () {
				cu.toast( "Enregistré.", "success" );
				return true;
			} )
			.catch( function ( e ) {
				cu.toast( e.message || "Échec de l'enregistrement.", "error" );
				// Restaure les contrôles si l'écriture a échoué côté serveur.
				loadStatus();
				return false;
			} );
	}

	// ---------------------------------------------------------------------
	// Actions
	// ---------------------------------------------------------------------
	function submitUrls() {
		var raw = $( "cu-in-urls" ).value.trim();
		if ( ! raw ) {
			cu.toast( "Saisissez au moins une URL.", "error" );
			return;
		}
		var urls = raw.split( /[\r\n]+/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
		var btn = $( "cu-in-submit" );
		var out = $( "cu-in-submit-result" );
		btn.disabled = true;
		out.textContent = "Envoi…";
		apiFetch( "indexnow/submit", { method: "POST", body: { urls: urls } } )
			.then( function ( res ) {
				reportSubmit( res, out );
				if ( res.success ) $( "cu-in-urls" ).value = "";
				loadStatus();
			} )
			.catch( function ( e ) {
				out.textContent = e.message || "Échec.";
				cu.toast( e.message || "Échec de la soumission.", "error" );
			} )
			.finally( function () { btn.disabled = false; } );
	}

	function submitAll() {
		if ( ! cu.confirmAction( "Pousser toutes les URL publiées vers le moteur IndexNow ?\n\nLes URL déjà poussées il y a moins de 24 h seront ignorées automatiquement (anti-doublon, pour ne pas gaspiller le quota)." ) ) {
			return;
		}
		var btn = $( "cu-in-submit-all" );
		btn.disabled = true;
		apiFetch( "indexnow/submit-all", { method: "POST" } )
			.then( function ( res ) {
				// Toutes les URL sont déjà passées récemment → message informatif, pas une erreur.
				if ( res.all_recent ) {
					cu.toast( res.message, "info" );
				} else if ( res.blocked ) {
					cu.toast( res.message, "error" );
				} else {
					reportSubmit( res, null );
				}
				loadStatus();
			} )
			.catch( function ( e ) {
				cu.toast( e.message || "Échec de l'envoi global.", "error" );
			} )
			.finally( function () { btn.disabled = false; } );
	}

	function reportSubmit( res, out ) {
		var recent  = Number( res.recent ) || 0;
		var invalid = Math.max( 0, ( Number( res.skipped ) || 0 ) - recent );
		var parts = [];
		if ( recent > 0 ) parts.push( recent + " déjà poussée(s) < 24 h" );
		if ( invalid > 0 ) parts.push( invalid + " invalide(s) ou hors du site" );
		var extra = parts.length ? " · " + parts.join( ", " ) : "";
		var msg = res.success
			? res.submitted + " URL soumise(s)" + extra + " — " + res.message
			: "Échec : " + res.message + extra;
		if ( out ) out.textContent = msg;
		cu.toast( msg, res.success ? "success" : "error" );
	}

	function verify() {
		var badge = $( "cu-in-verify-badge" );
		badge.className = "cu-in-verify-badge cu-badge cu-badge-gray";
		badge.textContent = "Vérification…";
		var btn = $( "cu-in-verify" );
		if ( btn ) btn.disabled = true;
		apiFetch( "indexnow/verify", { method: "POST" } )
			.then( function ( res ) {
				if ( res.success ) {
					setVerified( res.verified_at || "1" );
				} else {
					badge.className = "cu-in-verify-badge cu-badge cu-badge-red";
					badge.textContent = "Échec";
					badge.title = res.message || "";
				}
				cu.toast( res.message, res.success ? "success" : "error" );
			} )
			.catch( function ( e ) {
				badge.className = "cu-in-verify-badge cu-badge cu-badge-red";
				badge.textContent = "Erreur";
				cu.toast( e.message || "Vérification impossible.", "error" );
			} )
			.finally( function () { if ( btn ) btn.disabled = false; } );
	}

	function regenerate() {
		if ( ! cu.confirmAction( "Régénérer la clé ? L'ancien fichier-clé cessera d'être valide. À faire uniquement si nécessaire." ) ) {
			return;
		}
		var btn = $( "cu-in-regen" );
		btn.disabled = true;
		apiFetch( "indexnow/regenerate-key", { method: "POST" } )
			.then( function ( res ) {
				$( "cu-in-key" ).textContent = res.key_masked || "—";
				setKeyLocation( res.key_location );
				// La preuve de propriété concernait l'ancienne clé.
				setVerified( "" );
				cu.toast( "Nouvelle clé générée. Cliquez sur « Vérifier la propriété ».", "success" );
			} )
			.catch( function ( e ) {
				cu.toast( e.message || "Échec de la régénération.", "error" );
			} )
			.finally( function () { btn.disabled = false; } );
	}

	function copyKeyLocation() {
		if ( ! state.keyLocation ) return;
		var done = function () { cu.toast( "URL du fichier-clé copiée.", "success" ); };
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( state.keyLocation ).then( done, function () { fallbackCopy( state.keyLocation, done ); } );
		} else {
			fallbackCopy( state.keyLocation, done );
		}
	}

	function fallbackCopy( text, done ) {
		var ta = document.createElement( "textarea" );
		ta.value = text;
		ta.style.position = "fixed";
		ta.style.opacity = "0";
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( "copy" ); done(); } catch ( e ) { /* noop */ }
		ta.remove();
	}

	// ---------------------------------------------------------------------
	// Init
	// ---------------------------------------------------------------------
	function bind() {
		$( "cu-in-enabled" ).addEventListener( "change", function () {
			saveSetting( { enabled: this.checked } ).then( function ( saved ) {
				if ( saved ) loadStatus();
			} );
		} );
		$( "cu-in-auto" ).addEventListener( "change", function () {
			saveSetting( { auto_submit: this.checked } );
		} );
		$( "cu-in-endpoint" ).addEventListener( "change", function () {
			saveSetting( { endpoint: this.value } );
		} );
		$( "cu-in-submit" ).addEventListener( "click", submitUrls );
		$( "cu-in-submit-all" ).addEventListener( "click", submitAll );
		$( "cu-in-verify" ).addEventListener( "click", verify );
		$( "cu-in-regen" ).addEventListener( "click", regenerate );
		$( "cu-in-copy-key" ).addEventListener( "click", copyKeyLocation );
		$( "cu-in-refresh-history" ).addEventListener( "click", loadStatus );
	}

	function init() {
		if ( ! document.querySelector( ".cu-indexnow" ) ) return;
		bind();
		loadStatus();
	}

	// Le script peut être exécuté après DOMContentLoaded (chargement différé, cache
	// d'optimisation) : l'écouteur seul ne se déclencherait alors jamais.
	if ( "loading" === document.readyState ) {
		document.addEventListener( "DOMContentLoaded", init );
	} else {
		init();
	}
} )();

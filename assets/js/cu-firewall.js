/* CyberPush Ultra — Firewall admin page (cu-firewall.js)
 * Dépend de cu-admin.js (window.cuAdmin exposé).
 * Vanilla JS, aucune dépendance externe.
 */
( function () {
	"use strict";

	var api   = cuAdmin.apiFetch;
	var toast = cuAdmin.toast;
	var fmt   = cuAdmin.formatNumber;

	// -------------------------------------------------------------------------
	// État de pagination
	// -------------------------------------------------------------------------
	var blocksState = { page: 1, search: "", total: 0, pages: 1 };
	var logsState   = { page: 1, search: "", ip: "", status: "", type: "", range: "24", total: 0, pages: 1 };
	// Numéros de la dernière demande : une réponse plus ancienne n'écrase jamais la plus récente.
	var blocksSeq = 0;
	var logsSeq   = 0;

	// -------------------------------------------------------------------------
	// Couleurs par règle (graphique)
	// -------------------------------------------------------------------------
	// Index dans la palette du design system (--cu-viz-1 … --cu-viz-10) : chaque
	// famille d'attaque garde sa couleur, toutes lisibles sur fond clair (≥ 4:1).
	var ruleColorIndex = {
		flood_404:          5,
		probe_detection:    1,
		scanner_detection:  0,
		bad_bot:            3,
		login_protection:   2,
		xmlrpc_protection:  9,
		rate_limit:         4,
		other:              7,
		upload_protection:  6,
		restapi_protection: 8,
		rest_batch:         1,
		rest_sqli_author:   3,
		rest_user_enum:     5,
		enum_escalation:    9,
		hotlink:            2,
	};
	var ruleLabels = {
		flood_404:         "Flood 404",
		probe_detection:   "Sonde",
		scanner_detection: "Scanner",
		bad_bot:           "Bot malveillant",
		login_protection:  "Login",
		login_rate_limit:  "Limite de connexion",
		trusted_country_spared: "Pays de confiance (épargné)",
		xmlrpc_protection: "XML-RPC",
		rate_limit:        "Rate limit",
		upload_protection: "Upload",
		restapi_protection:"REST API",
		user_enumeration:  "Énumération",
		maintenance_mode:  "Maintenance",
		strict_mode:       "Mode strict",
		method_block:      "Méthode HTTP",
		url_keyword:       "Mot-clé URL",
		rest_batch:        "API batch (wp2shell)",
		rest_sqli_author:  "SQLi author (REST)",
		rest_user_enum:    "Énumération REST",
		enum_escalation:   "Énumération (récidive)",
		goodbot_spared:    "Crawler vérifié (épargné)",
		goodbot_verified:  "Crawler de recherche vérifié",
		hotlink:           "Hotlink",
		other:             "Autres protections",
	};

	// Libellé lisible d'une règle, y compris les règles WAF dynamiques (waf_sqli_0…).
	function ruleLabel( rule ) {
		if ( ! rule ) return rule || "";
		if ( ruleLabels[ rule ] ) return ruleLabels[ rule ];
		if ( rule.indexOf( "waf_" ) === 0 ) {
			var wafMap = { sqli: "WAF · SQLi", xss: "WAF · XSS", traversal: "WAF · Traversée", rce: "WAF · RCE", error: "WAF · Analyse interrompue" };
			return wafMap[ rule.split( "_" )[ 1 ] ] || "WAF";
		}
		return rule;
	}

	// -------------------------------------------------------------------------
	// Chargement statut
	// -------------------------------------------------------------------------
	function loadStatus() {
		api( "firewall/status" ).then( function ( data ) {
			// Badge statut.
			var badge = document.getElementById( "cu-fw-status-badge" );
			if ( badge ) {
				badge.textContent = data.enabled ? "Actif" : "Inactif";
				badge.className   = "cu-pill " + ( data.enabled ? "cu-pill-success" : "cu-pill-danger" );
			}

			// Toggle.
			var toggle = document.getElementById( "cu-fw-toggle" );
			if ( toggle ) {
				toggle.checked  = !! data.enabled;
				toggle.disabled = false;
			}

			// Dernière attaque.
			if ( data.last_attack ) {
				var la   = data.last_attack;
				var el   = document.getElementById( "cu-fw-last-attack" );
				var det  = document.getElementById( "cu-fw-last-attack-detail" );
				if ( el && det ) {
					el.style.display = "";
					det.textContent  = la.ip + " (" + ( la.country || "?" ) + ") — " + ( ruleLabel( la.type ) ) + " — il y a " + formatElapsed( la.elapsed );
				}
			}

			// Modules.
			renderModules( data.modules );
		} ).catch( function ( err ) {
			toast( err.message, "error" );
		} );
	}

	// -------------------------------------------------------------------------
	// Chargement dashboard (KPI)
	// -------------------------------------------------------------------------
	function loadDashboard() {
		api( "firewall/dashboard?range=24h" ).then( function ( d ) {
			setText( "cu-kpi-active-blocks", fmt( d.active_blocks ) );
			setText( "cu-kpi-permanent",     fmt( d.permanent_blocks ) );
			setText( "cu-kpi-attacks-24h",   fmt( d.attacks_24h ) );
			setText( "cu-kpi-unique-ips",    fmt( d.unique_attacker_ips ) );
			setText( "cu-kpi-flood404",      fmt( d.flood_404 ) );
			setText( "cu-kpi-login",         fmt( d.login_protection ) );
			setText( "cu-kpi-goodbot",       fmt( d.goodbot_verified || 0 ) );

			// Delta attaques.
			var deltaEl = document.getElementById( "cu-kpi-attacks-delta" );
			if ( deltaEl ) {
				// Sans période de comparaison, effacer l'ancien badge (sinon il reste affiché).
				deltaEl.innerHTML = d.attacks_prev > 0
					? cuAdmin.deltaBadge( ( ( d.attacks_24h - d.attacks_prev ) / d.attacks_prev ) * 100, true )
					: "";
			}

			// Blocages count badge.
			setText( "cu-blocks-count-badge", d.active_blocks + " actifs" );
		} ).catch( function ( err ) {
			toast( err.message, "error" );
		} );
	}

	// -------------------------------------------------------------------------
	// Graphique attaques 14 jours
	// -------------------------------------------------------------------------
	// Aires empilées par famille d'attaque, infobulle détaillée (détail du jour
	// chargé au survol) et actualisation en direct : voir cu-attack-chart.js.
	var attackChart = null;
	function loadAttacksChart() {
		var root = document.getElementById( "cu-fw-attacks" );
		if ( ! root || ! window.cuAttackChart ) return;
		if ( attackChart ) {
			attackChart.refresh();
			return;
		}
		attackChart = window.cuAttackChart.mount( root, {
			load: function () { return api( "firewall/attacks-timeseries?days=14" ); },
			loadDay: function ( date ) { return api( "firewall/attacks-day?date=" + encodeURIComponent( date ) ); },
			label: ruleLabel,
			colorIndex: function ( rule ) { return rule in ruleColorIndex ? ruleColorIndex[ rule ] : -1; },
			refreshMs: 60000,
		} );
	}

	// -------------------------------------------------------------------------
	// Blocages : liste + pagination
	// -------------------------------------------------------------------------
	function loadBlocks() {
		var tbody = document.getElementById( "cu-blocks-tbody" );
		if ( tbody ) tbody.innerHTML = '<tr><td colspan="8" class="cu-loading"><span class="cu-spinner"></span>Chargement…</td></tr>';

		var path = "firewall/blocks?page=" + blocksState.page;
		if ( blocksState.search ) path += "&search=" + encodeURIComponent( blocksState.search );

		// Recherche/pagination rapides : ignorer une réponse dépassée par une plus récente.
		var seq = ++blocksSeq;
		api( path ).then( function ( d ) {
			if ( seq !== blocksSeq ) return;
			blocksState.total = d.total;
			blocksState.pages = d.pages;
			renderBlocksTable( d.items );
			renderPagination( "cu-blocks-pagination", blocksState, loadBlocks );
		} ).catch( function ( err ) {
			if ( seq !== blocksSeq ) return;
			if ( tbody ) tbody.innerHTML = '<tr><td colspan="8" style="color:var(--cu-danger);padding:14px;">' + esc( err.message ) + '</td></tr>';
		} );
	}

	function renderBlocksTable( items ) {
		var tbody = document.getElementById( "cu-blocks-tbody" );
		if ( ! tbody ) return;
		if ( ! items || ! items.length ) {
			tbody.innerHTML = '<tr><td colspan="8"><div class="cu-empty">Aucun blocage actif.</div></td></tr>';
			return;
		}
		tbody.innerHTML = items.map( function ( row ) {
			var type_badge = row.type === "auto"
				? '<span class="cu-badge cu-badge-yellow">auto</span>'
				: row.type === "login"
					? '<span class="cu-badge cu-badge-gray" title="Seule la connexion est suspendue ; le site reste consultable.">connexion</span>'
					: '<span class="cu-badge cu-badge-purple">manuel</span>';
			var expires = row.expires_at
				? '<span title="' + esc( row.expires_at ) + '">' + esc( fmtDate( row.expires_at ) ) + '</span>'
				: '<span class="cu-badge cu-badge-red">Permanent</span>';
			return '<tr>'
				+ '<td><code style="color:var(--cu-accent-2-text)">' + esc( row.ip_or_cidr ) + '</code></td>'
				+ '<td>' + ( row.country_code ? '<span class="cu-badge cu-badge-gray">' + esc( row.country_code ) + '</span>' : '—' ) + '</td>'
				+ '<td>' + type_badge + '</td>'
				+ '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc( row.reason || '' ) + '">' + esc( row.reason || '—' ) + '</td>'
				+ '<td>' + fmt( row.hits ) + '</td>'
				+ '<td>' + fmtDate( row.last_seen ) + '</td>'
				+ '<td>' + expires + '</td>'
				+ '<td style="white-space:nowrap;">'
					+ '<button class="cu-btn cu-btn-sm" data-action="unblock" data-id="' + esc( row.id ) + '" title="Débloquer">Débloquer</button> '
					+ '<button class="cu-btn cu-btn-sm" data-action="permanent" data-id="' + esc( row.id ) + '" title="Rendre ce blocage définitif" style="color:var(--cu-danger);">Permanent</button> '
					+ '<button class="cu-btn cu-btn-sm" data-action="whitelist-block" data-id="' + esc( row.id ) + '" title="Débloquer et ajouter à la liste blanche">Whitelist</button>'
				+ '</td>'
			+ '</tr>';
		} ).join( "" );
	}

	// -------------------------------------------------------------------------
	// Whitelist
	// -------------------------------------------------------------------------
	function loadWhitelist() {
		api( "firewall/whitelist" ).then( function ( d ) {
			var tbody = document.getElementById( "cu-wl-tbody" );
			if ( ! tbody ) return;
			if ( ! d.items || ! d.items.length ) {
				tbody.innerHTML = '<tr><td colspan="5"><div class="cu-empty">Aucune entrée dans la whitelist.</div></td></tr>';
				return;
			}
			tbody.innerHTML = d.items.map( function ( row ) {
				var expires = ! row.expires_at
					? '<span class="cu-badge cu-badge-gray">Permanente</span>'
					: row.expired
						? '<span class="cu-badge cu-badge-red" title="' + esc( row.expires_at ) + '">Expirée</span>'
						: '<span title="' + esc( row.expires_at ) + '">' + esc( fmtDate( row.expires_at ) ) + '</span>';
				return '<tr' + ( row.expired ? ' style="opacity:.6;"' : '' ) + '>'
					+ '<td><code style="color:var(--cu-success)">' + esc( row.ip_or_cidr ) + '</code></td>'
					+ '<td>' + esc( row.label || '—' ) + '</td>'
					+ '<td>' + esc( fmtDate( row.created_at ) ) + '</td>'
					+ '<td>' + expires + '</td>'
					+ '<td><button class="cu-btn cu-btn-sm cu-btn-danger" data-action="del-wl" data-id="' + esc( row.id ) + '">Supprimer</button></td>'
				+ '</tr>';
			} ).join( "" );
		} ).catch( function ( err ) {
			toast( err.message, "error" );
		} );
	}

	// -------------------------------------------------------------------------
	// Pays de confiance
	// -------------------------------------------------------------------------
	function loadTrustedCountries() {
		api( "firewall/trusted-countries" ).then( function ( d ) {
			renderTrustedCountries( d.trusted_countries || [] );
		} ).catch( function () {} );
	}

	function renderTrustedCountries( countries ) {
		var el = document.getElementById( "cu-tc-list" );
		if ( ! el ) return;
		if ( ! countries.length ) {
			el.innerHTML = '<span style="color:var(--cu-text-dim);font-size:13px;">Aucun pays de confiance configuré.</span>';
			return;
		}
		el.innerHTML = countries.map( function ( c ) {
			return '<span class="cu-pill" style="gap:8px;">'
				+ esc( countryName( c ) )
				+ '<button type="button" data-action="del-tc" data-code="' + esc( c ) + '" aria-label="Retirer ' + esc( countryName( c ) ) + '" title="Retirer" style="background:none;border:none;color:var(--cu-danger);cursor:pointer;padding:0;font-size:14px;line-height:1;">×</button>'
				+ '</span>';
		} ).join( "" );
	}

	// -------------------------------------------------------------------------
	// Top attaquants
	// -------------------------------------------------------------------------
	function loadTopAttackers() {
		api( "firewall/top-attackers?range=24h" ).then( function ( d ) {
			var tbody = document.getElementById( "cu-top-attackers-tbody" );
			if ( ! tbody ) return;
			if ( ! d.items || ! d.items.length ) {
				tbody.innerHTML = '<tr><td colspan="6"><div class="cu-empty">Aucune attaque enregistrée.</div></td></tr>';
				return;
			}
			tbody.innerHTML = d.items.map( function ( row ) {
				var rules = ( row.rules || "" ).split( "," ).map( function ( r ) {
					return '<span class="cu-badge cu-badge-purple" style="font-size:10px;">' + esc( ruleLabel( r.trim() ) ) + '</span>';
				} ).join( " " );
				return '<tr>'
					+ '<td><code style="color:var(--cu-danger)">' + esc( row.ip ) + '</code></td>'
					+ '<td>' + ( row.country_code ? '<span class="cu-badge cu-badge-gray">' + esc( row.country_code ) + '</span>' : '—' ) + '</td>'
					+ '<td><strong>' + fmt( row.attacks ) + '</strong></td>'
					+ '<td>' + rules + '</td>'
					+ '<td>' + fmtDate( row.last_seen ) + '</td>'
					+ '<td><button class="cu-btn cu-btn-sm cu-btn-danger" data-action="quick-ban" data-ip="' + esc( row.ip ) + '">Bannir</button></td>'
				+ '</tr>';
			} ).join( "" );
		} ).catch( function () {} );
	}

	// -------------------------------------------------------------------------
	// Modules de protection (config rapide)
	// -------------------------------------------------------------------------
	function renderModules( modules ) {
		var el = document.getElementById( "cu-modules-list" );
		if ( ! el || ! modules ) return;
		var moduleLabels = {
			flood_404:         "Flood 404",
			probe_detection:   "Détection de sondes",
			scanner_detection: "Détection de scanners",
			bad_bot:           "Bots malveillants",
			login_protection:  "Protection wp-login.php",
			xmlrpc_protection: "Protection XML-RPC",
			restapi_protection:"Protection REST API",
			upload_protection: "Protection Upload",
			rate_limit:        "Rate limiting",
		};
		el.innerHTML = modules.map( function ( m ) {
			return '<div class="cu-field-row">'
				+ '<div class="cu-field-row-text">'
					+ '<strong>' + esc( moduleLabels[ m.key ] || m.key ) + '</strong>'
					+ '<span>' + esc( fmt( m.hits_24h ) ) + ' blocage(s) en 24h</span>'
				+ '</div>'
				+ '<label class="cu-switch">'
					+ '<input type="checkbox" class="cu-module-toggle" data-module="' + esc( m.key ) + '"' + ( m.mode ? ' data-mode="' + esc( m.mode ) + '"' : "" ) + ' aria-label="' + esc( "Activer : " + ( moduleLabels[ m.key ] || m.key ) ) + '" ' + ( m.enabled ? "checked" : "" ) + '>'
					+ '<span class="slider"></span>'
				+ '</label>'
			+ '</div>';
		} ).join( "" );
	}

	// -------------------------------------------------------------------------
	// Journal
	// -------------------------------------------------------------------------
	function loadLogs() {
		var tbody = document.getElementById( "cu-logs-tbody" );
		if ( tbody ) tbody.innerHTML = '<tr><td colspan="8" class="cu-loading"><span class="cu-spinner"></span>Chargement…</td></tr>';

		var path = "firewall/logs?page=" + logsState.page
			+ "&range=" + logsState.range + "h";
		if ( logsState.search ) path += "&search=" + encodeURIComponent( logsState.search );
		if ( logsState.ip )     path += "&ip=" + encodeURIComponent( logsState.ip );
		if ( logsState.status ) path += "&status=" + encodeURIComponent( logsState.status );
		if ( logsState.type )   path += "&type=" + encodeURIComponent( logsState.type );

		var seq = ++logsSeq;
		api( path ).then( function ( d ) {
			if ( seq !== logsSeq ) return;
			logsState.total = d.total;
			logsState.pages = d.pages;
			renderLogsTable( d.items );
			renderPagination( "cu-logs-pagination", logsState, loadLogs );
		} ).catch( function ( err ) {
			if ( seq !== logsSeq ) return;
			if ( tbody ) tbody.innerHTML = '<tr><td colspan="8" style="color:var(--cu-danger);padding:14px;">' + esc( err.message ) + '</td></tr>';
		} );
	}

	function renderLogsTable( items ) {
		var tbody = document.getElementById( "cu-logs-tbody" );
		if ( ! tbody ) return;
		if ( ! items || ! items.length ) {
			tbody.innerHTML = '<tr><td colspan="8"><div class="cu-empty">Aucun log pour ces critères.</div></td></tr>';
			return;
		}
		var severityClass = { low: "cu-badge-gray", medium: "cu-badge-yellow", high: "cu-badge-red", critical: "cu-badge-red" };
		var severityLabel = { low: "Faible", medium: "Moyenne", high: "Élevée", critical: "Critique" };
		tbody.innerHTML = items.map( function ( row, idx ) {
			var decision = row.decision === "blocked"
				? '<span class="cu-badge cu-badge-red">Bloqué</span>'
				: row.decision === "observed"
					? '<span class="cu-badge cu-badge-yellow">Détecté</span>'
					: '<span class="cu-badge cu-badge-green">Autorisé</span>';
			var sev = row.severity
				? '<span class="cu-badge ' + ( severityClass[ row.severity ] || "cu-badge-gray" ) + '">' + esc( severityLabel[ row.severity ] || row.severity ) + '</span>'
				: '—';
			var detail = logDetailHtml( row );
			var caret = detail ? '<span class="cu-audit-caret">▸</span> ' : "";
			var trCls = detail ? ' class="cu-audit-clickable" data-target="cu-log-d-' + idx + '"' : "";
			var main = '<tr' + trCls + '>'
				+ '<td style="white-space:nowrap;font-size:12px;" title="' + esc( row.created_at ) + '">' + caret + esc( fmtDate( row.created_at ) ) + '</td>'
				+ '<td><code style="font-size:12px;">' + esc( row.ip ) + '</code></td>'
				+ '<td>' + ( row.country_code ? '<span class="cu-badge cu-badge-gray">' + esc( row.country_code ) + '</span>' : '—' ) + '</td>'
				+ '<td>' + ( row.method ? '<span class="cu-badge cu-badge-purple">' + esc( row.method ) + '</span>' : '—' ) + '</td>'
				+ '<td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;" title="' + esc( row.url || '' ) + '">' + esc( ( row.url || '' ).slice( 0, 60 ) ) + '</td>'
				+ '<td>' + ( row.rule ? '<span class="cu-badge cu-badge-purple">' + esc( ruleLabel( row.rule ) ) + '</span>' : '—' ) + '</td>'
				+ '<td>' + decision + '</td>'
				+ '<td>' + sev + '</td>'
			+ '</tr>';
			if ( detail ) {
				main += '<tr class="cu-audit-detail-row" id="cu-log-d-' + idx + '" hidden><td colspan="8">' + detail + '</td></tr>';
			}
			return main;
		} ).join( "" );

		// Ligne dépliable : voir POURQUOI la requête a été jugée (surtout le
		// User-Agent, qui déclenche les règles « Scanner » / « Bot malveillant »).
		tbody.querySelectorAll( ".cu-audit-clickable" ).forEach( function ( r ) {
			r.addEventListener( "click", function () {
				var d = document.getElementById( r.dataset.target );
				if ( d ) { d.hidden = ! d.hidden; r.classList.toggle( "is-open", ! d.hidden ); }
			} );
		} );
	}

	// Détail d'une ligne de log : User-Agent, URL complète, référent, code HTTP.
	function logDetailHtml( row ) {
		var kv = [];
		kv.push( [ "User-Agent", row.user_agent || "(vide)" ] );
		if ( row.url ) kv.push( [ "URL complète", row.url ] );
		if ( row.referer ) kv.push( [ "Référent", row.referer ] );
		if ( row.response_code ) kv.push( [ "Code HTTP", row.response_code ] );
		return '<div class="cu-audit-detail">' + kv.map( function ( p ) {
			return '<div class="cu-audit-kv"><span class="cu-audit-k">' + esc( p[0] ) + '</span><span class="cu-audit-v">' + esc( p[1] ) + '</span></div>';
		} ).join( "" ) + '</div>';
	}

	// -------------------------------------------------------------------------
	// Statistiques globales
	// -------------------------------------------------------------------------
	function loadGlobalStats() {
		api( "firewall/global-stats" ).then( function ( d ) {
			setText( "cu-gs-total-blocked", fmt( d.total_blocked ) );
			setText( "cu-gs-blocked-ips",   fmt( d.blocked_ips ) );
			// Sans \u00e9chantillon des requ\u00eates autoris\u00e9es (mode d\u00e9bogage d\u00e9sactiv\u00e9),
			// aucun taux n'est estimable : on l'indique plut\u00f4t que d'afficher 100 %.
			setText( "cu-gs-block-rate", null === d.block_rate || undefined === d.block_rate ? "\u2014" : fmt( d.block_rate ) + "\u00a0%" );
			setText( "cu-gs-block-rate-note", d.sampling ? "" : "\u00c9chantillonnage d\u00e9sactiv\u00e9 (mode d\u00e9bogage requis)" );
			setText( "cu-gs-avg-time",      d.avg_time_between_s !== null ? formatElapsed( d.avg_time_between_s ) : "—" );

			// Top pays.
			var countries = document.getElementById( "cu-gs-top-countries" );
			if ( countries && d.top_countries ) {
				var maxC = d.top_countries[0] ? d.top_countries[0].cnt : 1;
				countries.innerHTML = d.top_countries.map( function ( c ) {
					return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">'
						+ '<span class="cu-badge cu-badge-gray" style="min-width:32px;justify-content:center;">' + esc( c.country_code || '??' ) + '</span>'
						+ '<div class="cu-bar-track" style="flex:1"><div class="cu-bar-fill" style="width:' + Math.round( ( c.cnt / maxC ) * 100 ) + '%"></div></div>'
						+ '<span style="font-size:12px;color:var(--cu-text-dim);min-width:32px;text-align:right;">' + fmt( c.cnt ) + '</span>'
					+ '</div>';
				} ).join( "" );
			}

			// Top types.
			var types = document.getElementById( "cu-gs-top-types" );
			if ( types && d.top_types ) {
				var maxT = d.top_types[0] ? d.top_types[0].cnt : 1;
				types.innerHTML = d.top_types.map( function ( t ) {
					return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">'
						+ '<span class="cu-badge cu-badge-purple" style="flex:0 0 130px;justify-content:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc( ruleLabel( t.rule ) ) + '">' + esc( ruleLabel( t.rule ) ) + '</span>'
						+ '<div class="cu-bar-track" style="flex:1"><div class="cu-bar-fill" style="width:' + Math.round( ( t.cnt / maxT ) * 100 ) + '%"></div></div>'
						+ '<span style="font-size:12px;color:var(--cu-text-dim);min-width:32px;text-align:right;">' + fmt( t.cnt ) + '</span>'
					+ '</div>';
				} ).join( "" );
			}
		} ).catch( function () {} );
	}

	// -------------------------------------------------------------------------
	// Pagination
	// -------------------------------------------------------------------------
	function renderPagination( containerId, state, callback ) {
		var el = document.getElementById( containerId );
		if ( ! el ) return;
		if ( state.pages <= 1 ) {
			el.innerHTML = "";
			return;
		}
		var html = '<span style="font-size:12px;color:var(--cu-text-dim);">Page ' + state.page + ' / ' + state.pages + ' (' + fmt( state.total ) + ' entrées)</span>';
		if ( state.page > 1 ) {
			html += '<button class="cu-btn cu-btn-sm" data-page="' + ( state.page - 1 ) + '">« Préc.</button>';
		}
		// Pages autour de la courante.
		for ( var p = Math.max( 1, state.page - 2 ); p <= Math.min( state.pages, state.page + 2 ); p++ ) {
			html += '<button class="cu-btn cu-btn-sm' + ( p === state.page ? ' cu-btn-primary' : '' ) + '" data-page="' + p + '">' + p + '</button>';
		}
		if ( state.page < state.pages ) {
			html += '<button class="cu-btn cu-btn-sm" data-page="' + ( state.page + 1 ) + '">Suiv. »</button>';
		}
		el.innerHTML = html;
		// Un seul gestionnaire par conteneur : addEventListener à chaque rendu
		// empilait les écouteurs (N rechargements simultanés après N recherches).
		el.onclick = function ( e ) {
			var btn = e.target.closest( "[data-page]" );
			if ( ! btn ) return;
			state.page = parseInt( btn.dataset.page, 10 );
			callback();
		};
	}

	// -------------------------------------------------------------------------
	// Gestion des événements délégués
	// -------------------------------------------------------------------------
	function bindEvents() {
		var page = document.getElementById( "cu-firewall-page" );
		if ( ! page ) return;

		// Toggle firewall on/off.
		var toggle = document.getElementById( "cu-fw-toggle" );
		if ( toggle ) {
			toggle.addEventListener( "change", function () {
				if ( ! cuAdmin.confirmAction( "Confirmer le changement d'état du pare-feu ?" ) ) {
					toggle.checked = ! toggle.checked;
					return;
				}
				var wanted = toggle.checked;
				toggle.disabled = true;
				api( "firewall/toggle", { method: "POST", body: { enabled: wanted } } ).then( function ( d ) {
					toggle.checked = !! d.enabled;
					toast( d.enabled ? "Pare-feu activé." : "Pare-feu désactivé.", "success" );
					loadStatus();
				} ).catch( function ( err ) {
					toast( err.message, "error" );
					toggle.checked = ! wanted;
				} ).then( function () {
					toggle.disabled = false;
				} );
			} );
		}

		// Actualiser.
		var refresh = document.getElementById( "cu-fw-refresh" );
		if ( refresh ) {
			refresh.addEventListener( "click", function () {
				loadAll();
			} );
		}

		// Recherche blocages.
		var blocksSearch = document.getElementById( "cu-blocks-search" );
		if ( blocksSearch ) {
			blocksSearch.addEventListener( "input", cuAdmin.debounce( function () {
				blocksState.search = blocksSearch.value.trim();
				blocksState.page   = 1;
				loadBlocks();
			}, 400 ) );
		}

		// Ouvrir modal bannissement.
		var banOpen = document.getElementById( "cu-ban-open" );
		if ( banOpen ) {
			banOpen.addEventListener( "click", function () {
				openBanModal( "" );
			} );
		}

		// Actions dans le tableau des blocages (délégation).
		var blocksTbody = document.getElementById( "cu-blocks-tbody" );
		if ( blocksTbody ) {
			blocksTbody.addEventListener( "click", function ( e ) {
				var btn = e.target.closest( "[data-action]" );
				if ( ! btn ) return;
				var action = btn.dataset.action;
				var id     = btn.dataset.id;
				if ( action === "unblock" ) {
					if ( ! cuAdmin.confirmAction( "Débloquer cette IP ?" ) ) return;
					api( "firewall/blocks/" + id, { method: "DELETE" } ).then( function () {
						toast( "IP débloquée.", "success" );
						loadBlocks();
						loadDashboard();
					} ).catch( function ( err ) { toast( err.message, "error" ); } );
				} else if ( action === "permanent" ) {
					if ( ! cuAdmin.confirmAction( "Rendre ce blocage permanent ?" ) ) return;
					api( "firewall/blocks/" + id + "/permanent", { method: "POST" } ).then( function () {
						toast( "Blocage rendu permanent.", "success" );
						loadBlocks();
					} ).catch( function ( err ) { toast( err.message, "error" ); } );
				} else if ( action === "whitelist-block" ) {
					if ( ! cuAdmin.confirmAction( "Whitelister cette IP et supprimer le blocage ?" ) ) return;
					api( "firewall/blocks/" + id + "/whitelist", { method: "POST" } ).then( function () {
						toast( "IP déplacée vers la whitelist.", "success" );
						loadBlocks();
						loadWhitelist();
					} ).catch( function ( err ) { toast( err.message, "error" ); } );
				}
			} );
		}

		// Whitelist — ajouter.
		var wlAdd = document.getElementById( "cu-wl-add" );
		if ( wlAdd ) {
			wlAdd.addEventListener( "click", function () {
				var ip       = ( document.getElementById( "cu-wl-ip" ).value || "" ).trim();
				var label    = ( document.getElementById( "cu-wl-label" ).value || "" ).trim();
				var durEl    = document.getElementById( "cu-wl-duration" );
				var duration = durEl ? durEl.value : "permanent";
				if ( ! ip ) { toast( "Veuillez saisir une IP.", "error" ); return; }
				postWithConfirm( "firewall/whitelist", { ip: ip, label: label, duration: duration } ).then( function ( d ) {
					if ( ! d ) return; // confirmation refusée
					toast( "IP ajoutée à la whitelist.", "success" );
					document.getElementById( "cu-wl-ip" ).value    = "";
					document.getElementById( "cu-wl-label" ).value = "";
					loadWhitelist();
				} ).catch( function ( err ) { toast( err.message, "error" ); } );
			} );
		}

		// Whitelist — supprimer (délégation).
		var wlTbody = document.getElementById( "cu-wl-tbody" );
		if ( wlTbody ) {
			wlTbody.addEventListener( "click", function ( e ) {
				var btn = e.target.closest( "[data-action='del-wl']" );
				if ( ! btn ) return;
				if ( ! cuAdmin.confirmAction( "Retirer cette IP de la whitelist ?" ) ) return;
				api( "firewall/whitelist/" + btn.dataset.id, { method: "DELETE" } ).then( function () {
					toast( "IP retirée de la whitelist.", "success" );
					loadWhitelist();
				} ).catch( function ( err ) { toast( err.message, "error" ); } );
			} );
		}

		// Pays de confiance — ajouter.
		var tcAdd = document.getElementById( "cu-tc-add" );
		if ( tcAdd ) {
			tcAdd.addEventListener( "click", function () {
				var code = ( document.getElementById( "cu-tc-add-code" ).value || "" ).trim().toUpperCase();
				if ( ! /^[A-Z]{2}$/.test( code ) ) { toast( "Code pays invalide (ex: FR).", "error" ); return; }
				api( "firewall/trusted-countries" ).then( function ( d ) {
					var list = d.trusted_countries || [];
					if ( list.indexOf( code ) !== -1 ) { toast( "Pays déjà dans la liste.", "error" ); return; }
					list.push( code );
					return api( "firewall/trusted-countries", { method: "POST", body: { trusted_countries: list } } );
				} ).then( function ( d ) {
					if ( ! d ) return;
					toast( "Pays ajouté.", "success" );
					document.getElementById( "cu-tc-add-code" ).value = "";
					renderTrustedCountries( d.trusted_countries );
				} ).catch( function ( err ) { toast( err.message, "error" ); } );
			} );
		}

		// Pays de confiance — supprimer (délégation).
		var tcList = document.getElementById( "cu-tc-list" );
		if ( tcList ) {
			tcList.addEventListener( "click", function ( e ) {
				var btn = e.target.closest( "[data-action='del-tc']" );
				if ( ! btn ) return;
				var code = btn.dataset.code;
				api( "firewall/trusted-countries" ).then( function ( d ) {
					var list = ( d.trusted_countries || [] ).filter( function ( c ) { return c !== code; } );
					return api( "firewall/trusted-countries", { method: "POST", body: { trusted_countries: list } } );
				} ).then( function ( d ) {
					if ( ! d ) return;
					toast( "Pays retiré.", "success" );
					renderTrustedCountries( d.trusted_countries );
				} ).catch( function ( err ) { toast( err.message, "error" ); } );
			} );
		}

		// Top attaquants — quick ban (délégation).
		var attackersTbody = document.getElementById( "cu-top-attackers-tbody" );
		if ( attackersTbody ) {
			attackersTbody.addEventListener( "click", function ( e ) {
				var btn = e.target.closest( "[data-action='quick-ban']" );
				if ( ! btn ) return;
				openBanModal( btn.dataset.ip );
			} );
		}

		// Logs — filtres.
		var logsSearchBtn = document.getElementById( "cu-logs-search-btn" );
		if ( logsSearchBtn ) {
			logsSearchBtn.addEventListener( "click", function () {
				logsState.page   = 1;
				logsState.search = ( document.getElementById( "cu-logs-search" ).value || "" ).trim();
				logsState.ip     = ( document.getElementById( "cu-logs-ip" ).value || "" ).trim();
				logsState.status = document.getElementById( "cu-logs-status" ).value;
				logsState.type   = document.getElementById( "cu-logs-type" ).value;
				logsState.range  = document.getElementById( "cu-logs-range" ).value || "24";
				loadLogs();
			} );
		}
		// Appliquer les filtres logs à l'appui sur Entrée.
		[ "cu-logs-search", "cu-logs-ip" ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.addEventListener( "keydown", function ( e ) {
					if ( e.key === "Enter" ) {
						document.getElementById( "cu-logs-search-btn" ).click();
					}
				} );
			}
		} );

		// Purge logs.
		var purgeBtn = document.getElementById( "cu-logs-purge" );
		if ( purgeBtn ) {
			purgeBtn.addEventListener( "click", function () {
				if ( ! cuAdmin.confirmAction( "Purger le journal ? (les 24 dernières heures seront conservées)" ) ) return;
				api( "firewall/logs/purge", { method: "POST" } ).then( function ( d ) {
					toast( d.deleted + " entrée(s) supprimée(s).", "success" );
					loadLogs();
				} ).catch( function ( err ) { toast( err.message, "error" ); } );
			} );
		}

		// Module toggles (délégation — les éléments sont rendus dynamiquement).
		var modulesList = document.getElementById( "cu-modules-list" );
		var xmlrpcLastMode = ""; // survit au re-rendu de la liste après sauvegarde
		if ( modulesList ) {
			modulesList.addEventListener( "change", function ( e ) {
				var input = e.target;
				if ( ! input.classList.contains( "cu-module-toggle" ) ) return;
				var moduleKey = input.dataset.module;
				var val       = input.checked;
				var settingMap = {
					flood_404:         "flood_404_enabled",
					probe_detection:   "probe_detection_enabled",
					scanner_detection: "scanner_detection_enabled",
					bad_bot:           "bad_bot_blocking_enabled",
					login_protection:  "login_protection_enabled",
					restapi_protection:"restapi_protection_enabled",
					upload_protection: "upload_protection_enabled",
					rate_limit:        "rate_limit_enabled",
					xmlrpc_protection: "xmlrpc_protection", // spécial : valeur "limited" ou "off"
				};
				var settingKey = settingMap[ moduleKey ];
				if ( ! settingKey ) return;
				var payload = {};
				if ( moduleKey === "xmlrpc_protection" ) {
					// Réactiver restaure le mode choisi : « disabled » (protection maximale)
					// ne doit jamais redescendre en « limited » à cause de l'interrupteur.
					if ( val ) {
						payload[ settingKey ] = "disabled" === xmlrpcLastMode ? "disabled" : "limited";
					} else {
						xmlrpcLastMode = input.dataset.mode || "limited";
						payload[ settingKey ] = "off";
					}
				} else {
					payload[ settingKey ] = val;
				}
				input.disabled = true;
				cuAdmin.apiFetch( "settings/firewall", { method: "POST", body: payload } )
					.then( function () {
						toast( "Module mis à jour.", "success" );
						loadStatus();
					} )
					.catch( function ( err ) {
						input.checked = ! val;
						input.disabled = false;
						toast( err.message || "Impossible de sauvegarder le module.", "error" );
					} );
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Modal bannissement
	// -------------------------------------------------------------------------
	function openBanModal( prefillIp ) {
		var tpl  = document.getElementById( "cu-ban-modal" );
		if ( ! tpl ) return;
		var modal = cuAdmin.openModal( tpl.innerHTML );
		var ipEl  = modal.querySelector( "#cu-ban-ip" );
		if ( ipEl && prefillIp ) ipEl.value = prefillIp;

		modal.querySelector( "#cu-ban-cancel" ).addEventListener( "click", function () {
			modal.remove();
		} );
		modal.querySelector( "#cu-ban-submit" ).addEventListener( "click", function () {
			var ip       = ( modal.querySelector( "#cu-ban-ip" ).value || "" ).trim();
			var reason   = ( modal.querySelector( "#cu-ban-reason" ).value || "" ).trim();
			var duration = modal.querySelector( "#cu-ban-duration" ).value;
			var comment  = ( modal.querySelector( "#cu-ban-comment" ).value || "" ).trim();
			if ( ! ip ) { toast( "Veuillez saisir une IP.", "error" ); return; }
			var submit = this;
			submit.disabled = true;
			postWithConfirm( "firewall/blocks", { ip: ip, reason: reason, duration: duration, comment: comment } )
				.then( function ( d ) {
					if ( ! d ) { submit.disabled = false; return; } // confirmation refusée
					toast( "IP " + ( d.ip || ip ) + " bannie.", "success" );
					modal.remove();
					loadBlocks();
					loadDashboard();
				} )
				.catch( function ( err ) { toast( err.message, "error" ); submit.disabled = false; } );
		} );
	}

	/**
	 * POST avec confirmation explicite : pour une plage très large (ou une
	 * adresse privée en whitelist), le serveur répond { needs_confirm, message }
	 * sans rien enregistrer ; on ne renvoie la demande qu'après accord.
	 * Résout avec la réponse finale, ou null si l'administrateur annule.
	 */
	function postWithConfirm( path, body ) {
		return api( path, { method: "POST", body: body } ).then( function ( d ) {
			if ( ! d || ! d.needs_confirm ) return d;
			if ( ! cuAdmin.confirmAction( String( d.message || "Confirmer cette plage très large ?" ) ) ) return null;
			var confirmed = Object.assign( {}, body, { confirm_broad: true } );
			return api( path, { method: "POST", body: confirmed } );
		} );
	}

	// -------------------------------------------------------------------------
	// Utilitaires
	// -------------------------------------------------------------------------
	function setText( id, value ) {
		var el = document.getElementById( id );
		if ( el ) el.textContent = value;
	}

	function esc( str ) {
		return String( str ).replace( /&/g, "&amp;" ).replace( /</g, "&lt;" ).replace( />/g, "&gt;" ).replace( /"/g, "&quot;" );
	}

	function fmtDate( dt ) {
		if ( ! dt ) return "—";
		try {
			var d = new Date( dt.replace( " ", "T" ) );
			return d.toLocaleString( "fr-FR", { day: "2-digit", month: "2-digit", year: "2-digit", hour: "2-digit", minute: "2-digit" } );
		} catch ( e ) {
			return dt;
		}
	}

	// « FR » → « France (FR) » (repli sur le code si le navigateur ne sait pas).
	var regionNames = null;
	try { regionNames = new Intl.DisplayNames( [ "fr" ], { type: "region" } ); } catch ( e ) { regionNames = null; }
	function countryName( code ) {
		code = String( code || "" ).toUpperCase();
		try {
			var name = regionNames && /^[A-Z]{2}$/.test( code ) ? regionNames.of( code ) : "";
			return name && name !== code ? name + " (" + code + ")" : code;
		} catch ( err ) {
			return code;
		}
	}

	function formatElapsed( seconds ) {
		seconds = Math.round( seconds || 0 );
		if ( seconds < 60 )   return seconds + "\u00a0s";
		if ( seconds < 3600 ) return Math.floor( seconds / 60 ) + "\u00a0min";
		if ( seconds < 86400 ) return Math.floor( seconds / 3600 ) + "\u00a0h";
		return Math.floor( seconds / 86400 ) + "\u00a0j";
	}

	// -------------------------------------------------------------------------
	// Chargement global
	// -------------------------------------------------------------------------
	function loadAll() {
		loadStatus();
		loadDashboard();
		loadAttacksChart();
		loadBlocks();
		loadWhitelist();
		loadTrustedCountries();
		loadTopAttackers();
		loadLogs();
		loadGlobalStats();
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------
	document.addEventListener( "DOMContentLoaded", function () {
		if ( ! document.getElementById( "cu-firewall-page" ) ) return;
		bindEvents();
		loadAll();
	} );

} )();

/* CyberPush Ultra — page Réglages */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	if ( ! cu ) return;

	var page = document.getElementById( "cu-settings-page" );
	if ( ! page ) return;

	function escapeHtml( value ) {
		return String( value == null ? "" : value ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}

	// Onglets
	var tabs = page.querySelectorAll( ".cu-settings-tab" );
	tabs.forEach( function ( tab ) {
		tab.addEventListener( "click", function () {
			tabs.forEach( function ( t ) {
				t.classList.remove( "is-active" );
			} );
			page.querySelectorAll( ".cu-settings-panel" ).forEach( function ( p ) {
				p.classList.remove( "is-active" );
			} );
			tab.classList.add( "is-active" );
			var panel = page.querySelector( '.cu-settings-panel[data-panel="' + tab.dataset.tab + '"]' );
			if ( panel ) panel.classList.add( "is-active" );
			window.location.hash = tab.dataset.tab;
		} );
	} );

	if ( window.location.hash ) {
		var hashTab = window.location.hash.slice( 1 );
		var initial = /^[a-z0-9_-]+$/i.test( hashTab )
			? page.querySelector( '.cu-settings-tab[data-tab="' + hashTab + '"]' )
			: null;
		if ( initial ) initial.click();
	}

	function setDeep( obj, path, value ) {
		var parts = path.split( "." );
		var cursor = obj;
		for ( var i = 0; i < parts.length - 1; i++ ) {
			if ( ! cursor[ parts[ i ] ] || typeof cursor[ parts[ i ] ] !== "object" ) {
				cursor[ parts[ i ] ] = {};
			}
			cursor = cursor[ parts[ i ] ];
		}
		cursor[ parts[ parts.length - 1 ] ] = value;
	}

	function collectPanelValues( panel ) {
		var values = {};
		panel.querySelectorAll( "[data-key]" ).forEach( function ( field ) {
			var key = field.dataset.key;
			var value;
			if ( field.disabled ) return;
			if ( "1" === field.dataset.secret ) {
				// Secret : jamais renvoyé par le serveur. Vide = conserver la clé
				// enregistrée ; « Effacer » envoie une chaîne vide explicite.
				if ( "1" === field.dataset.cleared ) {
					value = "";
				} else if ( "" === field.value.trim() ) {
					return;
				} else {
					value = field.value.trim();
				}
				setDeep( values, key, value );
				return;
			}
			if ( "checkbox" === field.type ) {
				value = field.checked;
			} else if ( "number" === field.type ) {
				// Champ vidé : on conserve la valeur enregistrée plutôt que d'envoyer 0
				// (qui désactiverait par exemple le cache sans prévenir).
				if ( "" === field.value.trim() ) return;
				value = Number( field.value );
			} else if ( "csv" === field.dataset.type ) {
				value = field.value
					.split( "," )
					.map( function ( v ) {
						return v.trim();
					} )
					.filter( Boolean );
			} else if ( "lines" === field.dataset.type ) {
				value = field.value
					.split( "\n" )
					.map( function ( v ) {
						return v.trim();
					} )
					.filter( Boolean );
			} else {
				value = field.value;
			}
			setDeep( values, key, value );
		} );
		return values;
	}

	function getDeep( obj, path ) {
		var parts = path.split( "." );
		var cursor = obj;
		for ( var i = 0; i < parts.length; i++ ) {
			if ( ! cursor || typeof cursor !== "object" || ! Object.prototype.hasOwnProperty.call( cursor, parts[ i ] ) ) {
				return undefined;
			}
			cursor = cursor[ parts[ i ] ];
		}
		return cursor;
	}

	function fieldLabel( field ) {
		var wrap = field.closest( ".cu-field, .cu-field-row" );
		var label = wrap ? wrap.querySelector( "label, strong" ) : null;
		return label ? label.textContent.trim() : field.dataset.key;
	}

	/**
	 * Réaffiche les valeurs réellement enregistrées (le serveur borne certains
	 * nombres et nettoie les listes) et renvoie les libellés des champs ajustés.
	 */
	function applyStoredValues( panel, stored ) {
		var adjusted = [];
		if ( ! stored || typeof stored !== "object" ) return adjusted;
		panel.querySelectorAll( "[data-key]" ).forEach( function ( field ) {
			if ( field.disabled || "1" === field.dataset.secret ) return;
			var value = getDeep( stored, field.dataset.key );
			if ( undefined === value || null === value ) return;
			if ( "checkbox" === field.type ) {
				field.checked = !! value;
			} else if ( "number" === field.type ) {
				if ( "" !== field.value.trim() && Number( field.value ) !== Number( value ) ) {
					adjusted.push( fieldLabel( field ) + " → " + value );
				}
				field.value = String( value );
			} else if ( Array.isArray( value ) ) {
				field.value = value.join( "csv" === field.dataset.type ? ", " : "\n" );
			} else if ( "object" !== typeof value ) {
				field.value = String( value );
			}
		} );
		return adjusted;
	}

	page.querySelectorAll( "[data-secret-clear]" ).forEach( function ( btn ) {
		btn.addEventListener( "click", function () {
			var field = btn.parentNode.querySelector( '[data-secret="1"]' );
			if ( ! field ) return;
			field.value = "";
			field.dataset.cleared = "1";
			field.placeholder = "Clé supprimée à l’enregistrement";
			btn.disabled = true;
		} );
	} );
	page.querySelectorAll( '[data-secret="1"]' ).forEach( function ( field ) {
		field.addEventListener( "input", function () {
			if ( "" !== field.value ) field.dataset.cleared = "";
		} );
	} );

	page.querySelectorAll( "[data-save]" ).forEach( function ( btn ) {
		btn.addEventListener( "click", function () {
			var module = btn.dataset.save;
			var panel = btn.closest( ".cu-settings-panel" );
			var values = collectPanelValues( panel );
			btn.disabled = true;
			cu.apiFetch( "settings/" + module, { method: "POST", body: values } )
				.then( function ( stored ) {
					var adjusted = applyStoredValues( panel, stored );
					if ( adjusted.length ) {
						cu.toast( "Enregistré, avec des valeurs ramenées dans les limites autorisées : " + adjusted.join( " ; " ), "info" );
					} else {
						cu.toast( cu.config.i18n.saved, "success" );
					}
					// Ne jamais laisser une clé saisie visible dans le DOM après l'envoi.
					panel.querySelectorAll( '[data-secret="1"]' ).forEach( function ( field ) {
						if ( "1" === field.dataset.cleared ) {
							field.placeholder = "Aucune clé enregistrée";
						} else if ( "" !== field.value ) {
							field.placeholder = "Clé enregistrée — laisser vide pour la conserver";
						}
						field.value = "";
						field.dataset.cleared = "";
					} );
				} )
				.catch( function ( err ) {
					cu.toast( err.message, "error" );
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	} );

	page.querySelectorAll( "[data-erase]" ).forEach( function ( btn ) {
		btn.addEventListener( "click", function () {
			var module = btn.dataset.erase;
			var names = { analytics: "Analytics", firewall: "Firewall", seo: "SEO", logs: "Logs" };
			if ( ! cu.confirmAction( "Supprimer DÉFINITIVEMENT toutes les données du module « " + ( names[ module ] || module ) + " » ? Les réglages sont conservés. Cette action est irréversible." ) ) return;
			btn.disabled = true;
			cu.apiFetch( "system/erase-module-data", { method: "POST", body: { module: module } } )
				.then( function () {
					cu.toast( "Données du module « " + ( names[ module ] || module ) + " » supprimées.", "success" );
				} )
				.catch( function ( err ) {
					cu.toast( err.message, "error" );
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	} );

	var aiTestBtn = document.getElementById( "cu-ai-test" );
	if ( aiTestBtn ) {
		aiTestBtn.addEventListener( "click", function () {
			aiTestBtn.disabled = true;
			cu.apiFetch( "ai/test-connection", { method: "POST" } )
				.then( function ( res ) {
					cu.toast( res.message, res.success ? "success" : "error" );
				} )
				.catch( function ( err ) {
					cu.toast( err.message, "error" );
				} )
				.finally( function () {
					aiTestBtn.disabled = false;
				} );
		} );
	}

	function loadSystemInfo() {
		var box = document.getElementById( "cu-system-info" );
		if ( ! box ) return;
		cu.apiFetch( "system/info" )
			.then( function ( info ) {
				var rows = [
					[ "Version du plugin", info.plugin_version ],
					[ "Version de la base de données", info.db_version ],
					[ "Tables installées", info.tables_exist ? "Oui" : ( "Non — migration requise" + ( info.missing_tables && info.missing_tables.length ? " (absentes : " + info.missing_tables.join( ", " ) + ")" : " (colonnes manquantes)" ) ) ],
					[ "WordPress", info.wp_version ],
					[ "PHP", info.php_version ],
					[ "WooCommerce détecté", info.woocommerce_active ? "Oui" : "Non" ],
					[ "Yoast SEO détecté", info.yoast_active ? "Oui" : "Non" ],
					[ "Rank Math détecté", info.rankmath_active ? "Oui" : "Non" ],
				];
				var countsHtml = Object.keys( info.table_counts || {} )
					.map( function ( k ) {
						return "<div class=\"cu-field-row\"><div class=\"cu-field-row-text\"><strong>" + escapeHtml( k ) + "</strong></div><span>" + escapeHtml( cu.formatNumber( info.table_counts[ k ] ) ) + "</span></div>";
					} )
					.join( "" );
				box.className = "";
				box.innerHTML =
					rows
						.map( function ( r ) {
							return '<div class="cu-field-row"><div class="cu-field-row-text"><strong>' + escapeHtml( r[ 0 ] ) + "</strong></div><span>" + escapeHtml( r[ 1 ] ) + "</span></div>";
						} )
						.join( "" ) + countsHtml;
			} )
			.catch( function ( err ) {
				box.className = "cu-empty";
				box.textContent = err.message;
			} );
	}

	loadSystemInfo();

	// --- Sauvegarde : export / import des réglages ---
	var exportBtn = document.getElementById( "cu-settings-export" );
	if ( exportBtn ) {
		exportBtn.addEventListener( "click", function () {
			exportBtn.disabled = true;
			cu.apiFetch( "settings-export" )
				.then( function ( data ) {
					var blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: "application/json" } );
					var url = URL.createObjectURL( blob );
					var a = document.createElement( "a" );
					a.href = url;
					a.download = "cyberpush-ultra-reglages.json";
					document.body.appendChild( a );
					a.click();
					a.remove();
					URL.revokeObjectURL( url );
					cu.toast( "Réglages exportés. Les clés API ne sont pas incluses dans le fichier.", "success" );
				} )
				.catch( function ( err ) {
					cu.toast( err.message, "error" );
				} )
				.finally( function () {
					exportBtn.disabled = false;
				} );
		} );
	}

	var importInput = document.getElementById( "cu-settings-import-file" );
	if ( importInput ) {
		importInput.addEventListener( "change", function () {
			var file = importInput.files && importInput.files[ 0 ];
			if ( ! file ) return;
			if ( ! cu.confirmAction( "Importer ce fichier ? Les réglages des modules qu'il contient remplaceront les réglages actuels." ) ) {
				importInput.value = "";
				return;
			}
			var reader = new FileReader();
			reader.onload = function () {
				var payload;
				try {
					payload = JSON.parse( reader.result );
				} catch ( e ) {
					cu.toast( "JSON invalide.", "error" );
					importInput.value = "";
					return;
				}
				cu.apiFetch( "settings-import", { method: "POST", body: payload } )
					.then( function ( res ) {
						cu.toast( ( cu.config.i18n.saved || "OK" ) + " (" + ( res.modules || [] ).length + ")", "success" );
						setTimeout( function () { window.location.reload(); }, 800 );
					} )
					.catch( function ( err ) {
						cu.toast( err.message, "error" );
					} )
					.finally( function () {
						importInput.value = "";
					} );
			};
			reader.readAsText( file );
		} );
	}

	// --- Nettoyage & maintenance ---
	page.querySelectorAll( "[data-maintenance]" ).forEach( function ( btn ) {
		btn.addEventListener( "click", function () {
			var action = btn.dataset.maintenance;
			if ( "cleanup_expired" === action && ! cu.confirmAction() ) return;
			btn.disabled = true;
			cu.apiFetch( "system/maintenance", { method: "POST", body: { action: action } } )
				.then( function ( res ) {
					cu.toast( res.message || cu.config.i18n.saved, "success" );
				} )
				.catch( function ( err ) {
					cu.toast( err.message, "error" );
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	} );

	// --- Alertes e-mail : test d'envoi ---
	var alertTest = document.getElementById( "cu-alert-test" );
	var alertEmail = document.getElementById( "cu-alert-email" );
	if ( alertTest && alertEmail ) {
		var savedAlertEmail = alertEmail.value;
		var alertSave = page.querySelector( '[data-save="audit"]' );
		if ( alertSave ) {
			// L'adresse testée est celle ENREGISTRÉE : on mémorise la valeur au clic
			// « Enregistrer » (le serveur ne renvoie pas d'erreur sur ce champ).
			alertSave.addEventListener( "click", function () {
				savedAlertEmail = alertEmail.value;
			} );
		}
		alertTest.addEventListener( "click", function () {
			if ( alertEmail.value.trim() !== savedAlertEmail.trim() ) {
				cu.toast( "Enregistrez d'abord la nouvelle adresse : le test utilise l'adresse enregistrée.", "error" );
				return;
			}
			if ( alertEmail.value.trim() && ! alertEmail.checkValidity() ) {
				cu.toast( "Adresse e-mail invalide.", "error" );
				return;
			}
			alertTest.disabled = true;
			cu.apiFetch( "firewall/audit/test-email", { method: "POST" } )
				.then( function ( res ) {
					cu.toast( res.message || cu.config.i18n.saved, "success" );
				} )
				.catch( function ( err ) {
					cu.toast( err.message, "error" );
				} )
				.finally( function () {
					alertTest.disabled = false;
				} );
		} );
	}

	// --- Auto-tests développeur ---
	page.querySelectorAll( "[data-selftest]" ).forEach( function ( btn ) {
		btn.addEventListener( "click", function () {
			var target = btn.dataset.selftest;
			btn.disabled = true;
			cu.apiFetch( "system/self-test", { method: "POST", body: { target: target } } )
				.then( function ( res ) {
					cu.toast( res.message, res.success ? "success" : "error" );
				} )
				.catch( function ( err ) {
					cu.toast( err.message, "error" );
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	} );

	// --- Journal d'erreurs du plugin (Développeur) ---
	var errlogOutput = document.getElementById( "cu-errlog-output" );
	var errlogRefresh = document.getElementById( "cu-errlog-refresh" );
	var errlogClear = document.getElementById( "cu-errlog-clear" );

	function renderErrorLog() {
		if ( ! errlogOutput ) return;
		errlogOutput.textContent = cu.config.i18n.loading || "Chargement…";
		cu.apiFetch( "system/error-log" )
			.then( function ( data ) {
				var lines = data.lines || [];
				errlogOutput.textContent = lines.length ? lines.join( "\n" ) : "Aucune erreur enregistrée.";
			} )
			.catch( function ( err ) {
				errlogOutput.textContent = err.message;
			} );
	}

	if ( errlogRefresh ) {
		errlogRefresh.addEventListener( "click", renderErrorLog );
	}
	if ( errlogClear ) {
		errlogClear.addEventListener( "click", function () {
			if ( ! cu.confirmAction() ) return;
			cu.apiFetch( "system/error-log", { method: "DELETE" } )
				.then( function () {
					cu.toast( cu.config.i18n.saved, "success" );
					renderErrorLog();
				} )
				.catch( function ( err ) {
					cu.toast( err.message, "error" );
				} );
		} );
	}

	var installBtn = document.getElementById( "cu-install-tables" );
	if ( installBtn ) {
		installBtn.addEventListener( "click", function () {
			installBtn.disabled = true;
			cu.apiFetch( "system/install-tables", { method: "POST" } )
				.then( function () {
					cu.toast( cu.config.i18n.saved, "success" );
					loadSystemInfo();
				} )
				.catch( function ( err ) {
					cu.toast( err.message, "error" );
				} )
				.finally( function () {
					installBtn.disabled = false;
				} );
		} );
	}
} )();

/* CyberPush Ultra — page « Audit SEO ».
 * Auditeur type Semrush/Rank Math : analyse le rendu FINAL de chaque page.
 * Dépend de window.cuAdmin (apiFetch, toast, confirmAction, lineChart, openModal).
 */
( function () {
	"use strict";

	var cu = window.cuAdmin;
	if ( ! cu ) return;

	var state = { page: 1, filter: "", search: "", scanning: false, auditing: false };

	// Jetons de requête : en tapant vite dans la recherche (ou en changeant de
	// filtre), une réponse plus ancienne ne doit jamais écraser la plus récente.
	var pagesSeq = 0;
	var indexSeq = 0;

	function $( id ) { return document.getElementById( id ); }

	// Erreur réseau du navigateur (« Failed to fetch », « NetworkError… »,
	// « Load failed ») : message clair en français au lieu du texte brut.
	function friendlyError( e ) {
		var msg = ( e && e.message ) ? String( e.message ) : "";
		if ( ! msg || ( e && "TypeError" === e.name ) || /failed to fetch|networkerror|load failed|network request failed/i.test( msg ) ) {
			return new Error( "Le serveur WordPress ne répond pas (connexion interrompue ou serveur indisponible). Vérifiez votre connexion puis réessayez." );
		}
		return e;
	}
	// Toutes les requêtes de la page passent par ici (même API que cu.apiFetch).
	function apiFetch( path, opts ) {
		return cu.apiFetch( path, opts ).catch( function ( e ) { throw friendlyError( e ); } );
	}

	// Pastille d'état (CSS) : remplace les emoji ronds de couleur.
	var DOT_COLORS = { green: 1, yellow: 1, orange: 1, red: 1, gray: 1 };
	function dot( color ) {
		return '<span class="cu-dot cu-dot-' + ( DOT_COLORS[ color ] ? color : "gray" ) + '" aria-hidden="true"></span>';
	}

	function escapeHtml( str ) {
		return String( str == null ? "" : str ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ c ];
		} );
	}

	function relTime( mysqlDate ) {
		if ( ! mysqlDate ) return "jamais";
		var t = cu.siteTime( mysqlDate );
		if ( isNaN( t ) ) return mysqlDate;
		var diff = Math.max( 0, Math.floor( ( Date.now() - t ) / 1000 ) );
		if ( diff < 90 ) return "à l'instant";
		if ( diff < 3600 ) return "il y a " + Math.floor( diff / 60 ) + " min";
		if ( diff < 86400 ) return "il y a " + Math.floor( diff / 3600 ) + " h";
		return "il y a " + Math.floor( diff / 86400 ) + " j";
	}

	// Date absolue « 17/11/2026 10:52 » d'une date SQL (heure du site), sans conversion.
	function siteDate( mysqlDate ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec( String( mysqlDate || "" ) );
		if ( ! m ) return "";
		return m[ 3 ] + "/" + m[ 2 ] + "/" + m[ 1 ] + ( m[ 4 ] ? " " + m[ 4 ] + ":" + m[ 5 ] : "" );
	}

	// Nombre sûr pour insertion HTML (jamais une chaîne brute du serveur).
	function num( v ) {
		var n = Number( v );
		return isFinite( n ) ? n : 0;
	}

	// Une autre analyse SEO tourne (tâche planifiée, autre onglet) : le serveur
	// répond busy = true au lieu de lancer un second traitement en parallèle.
	// On patiente puis on relance la MÊME requête (jamais deux en même temps).
	var BUSY_WAIT_MS = 5000;
	var BUSY_MAX_TRIES = 36; // ≈ 3 minutes
	function apiWhenFree( path, opts, onWait, tries ) {
		tries = tries || 0;
		return apiFetch( path, opts ).then( function ( res ) {
			if ( res && res.busy ) {
				if ( tries >= BUSY_MAX_TRIES ) throw new Error( res.message || "Une autre analyse SEO est en cours. Réessayez plus tard." );
				if ( onWait ) onWait( res.message || "" );
				return new Promise( function ( resolve ) { setTimeout( resolve, BUSY_WAIT_MS ); } )
					.then( function () { return apiWhenFree( path, opts, onWait, tries + 1 ); } );
			}
			return res;
		} );
	}

	// Nature d'un constat (au lieu d'un pourcentage de « confiance » inventé).
	var BASIS = {
		observed: { dot: "green", label: "Fait observé dans le rendu de la page" },
		practice: { dot: "green", label: "Bonne pratique SEO (seuil reconnu, à adapter)" },
		heuristic: { dot: "yellow", label: "Indice à interpréter (heuristique)" },
		check: { dot: "yellow", label: "Fait mesuré, peut être volontaire : à vérifier" },
	};

	// Met en forme la réponse IA (texte) en HTML sûr : échappe tout, met en gras
	// les intitulés de section, convertit les listes et les sauts de ligne.
	function insightToHtml( text ) {
		var safe = escapeHtml( text ).replace( /\r/g, "" );
		var lines = safe.split( "\n" ).map( function ( l ) {
			// Les IA répondent souvent en Markdown (« ### 1) DIAGNOSTIC »,
			// « **ACTIONS** ») : on retire ces marques avant la mise en forme.
			var t = l.replace( /^\s*#{1,6}\s*/, "" ).replace( /\*\*|__/g, "" ).trim();
			if ( ! t ) return "";
			if ( /^(1\)|2\)|3\)|DIAGNOSTIC|ACTIONS|POTENTIEL|À SURVEILLER|A SURVEILLER)/i.test( t ) ) {
				return "<strong>" + t + "</strong>";
			}
			if ( /^([-•*]|\d+\.)\s+/.test( t ) ) {
				return '<div class="cu-seo-insight-li">' + t.replace( /^([-•*]|\d+\.)\s+/, "" ) + "</div>";
			}
			return "<div>" + t + "</div>";
		} );
		return lines.join( "" );
	}

	function scoreColor( score ) {
		if ( score == null ) return "var(--cu-text-faint)";
		if ( score >= 80 ) return "var(--cu-success)";
		if ( score >= 50 ) return "var(--cu-warning)";
		return "var(--cu-danger)";
	}

	// Verdict d'un score, aligné sur les MÊMES seuils que les couleurs et la
	// répartition (« Bonnes ≥ 80 ») : un 71 ne peut pas être « Bon » en orange.
	function seoVerdict( s ) {
		if ( s == null ) return "";
		if ( s >= 90 ) return "Excellent";
		if ( s >= 80 ) return "Bon";
		if ( s >= 70 ) return "Correct";
		if ( s >= 50 ) return "Moyen — à travailler";
		if ( s >= 30 ) return "Faible";
		return "Critique";
	}
	var SCORE_TARGET = "viser 80+";

	// Seuils officiels Google des Core Web Vitals (bon / à améliorer / mauvais).
	function cwvRating( metric, v ) {
		if ( v == null ) return "";
		var t = { lcp: [ 2500, 4000 ], inp: [ 200, 500 ], cls: [ 0.1, 0.25 ] }[ metric ];
		if ( ! t ) return "";
		return v <= t[ 0 ] ? "is-good" : ( v <= t[ 1 ] ? "is-ni" : "is-poor" );
	}

	function scoreBadge( score ) {
		if ( score == null ) return '<span class="cu-badge cu-badge-gray">—</span>';
		var cls = score >= 80 ? "cu-badge-green" : score >= 50 ? "cu-badge-yellow" : "cu-badge-red";
		return '<span class="cu-badge ' + cls + '">' + score + "</span>";
	}

	// =====================================================================
	// Vue d'ensemble
	// =====================================================================
	function loadOverview() {
		apiFetch( "seo/overview" )
			.then( renderOverview )
			.catch( function ( e ) {
				var msg = e.message || "Erreur de chargement.";
				cu.toast( msg, "error" );
				// Jamais une section bloquée sur « Chargement… » : on dit pourquoi.
				[ "cu-seo-health", "cu-seo-top-issues", "cu-seo-reco" ].forEach( function ( id ) {
					var el = $( id );
					if ( el && el.querySelector( ".cu-loading" ) ) el.innerHTML = '<div class="cu-empty">' + escapeHtml( msg ) + "</div>";
				} );
				var title = $( "cu-seo-hero-title" );
				if ( title && ! lastOverview ) title.textContent = "Données indisponibles";
			} );
	}

	var sourceLabels = {
		wordpress: "WordPress ★★★★★",
		analytics: "CyberPush Analytics ★★★★★",
		psi: "PageSpeed ★★★★★",
		heuristique: "Heuristique ★★★☆☆",
		gsc: "Search Console (à venir)",
		dataforseo: "DataForSEO (à venir)",
		ia: "IA ★★★☆☆",
	};

	var viz = window.cuSeoViz;
	var lastOverview = null;

	function plural( n, one, many ) {
		return cu.formatNumber( n ) + " " + ( Number( n ) > 1 ? many : one );
	}
	// « 1,4 pt » / « 9,5 pts » (singulier en dessous de 2, comme en français).
	function pts( value ) {
		return viz.decimal( value, 1 ) + ( Math.abs( value ) < 2 ? " pt" : " pts" );
	}
	// Part en % bornée à [0, 100] : jamais « 115 % » pendant une reprise d'analyse.
	function percent( part, whole ) {
		return whole > 0 ? Math.max( 0, Math.min( 100, Math.round( part / whole * 100 ) ) ) : 0;
	}
	// Date du jour dans le fuseau du SITE (les relevés sont datés en heure du site).
	function siteToday() {
		return new Date( Date.now() + ( Number( cu.config.gmtOffset ) || 0 ) * 1000 ).toISOString().slice( 0, 10 );
	}
	function shortDay( key ) {
		var m = /^\d{4}-(\d{2})-(\d{2})/.exec( key || "" );
		return m ? m[ 2 ] + "/" + m[ 1 ] : String( key || "" );
	}

	// Points gagnés / perdus par composante, sur 100 : chaque composante mesurée
	// pèse son poids rapporté à la somme des poids mesurés (les composantes en
	// attente de données sont exclues, comme dans le calcul serveur).
	// Composantes normalisées (nombres réels, textes) : aucune valeur brute du
	// serveur n'est insérée telle quelle dans le HTML.
	function components( model ) {
		return ( ( model && model.components ) || [] ).map( function ( c ) {
			return {
				key: String( c.key || "" ),
				label: String( c.label || "" ),
				weight: Number( c.weight ) || 0,
				value: null == c.value || "" === c.value ? null : clampScore( c.value ),
				source: String( c.source || "" ),
			};
		} );
	}
	function clampScore( v ) {
		return Math.max( 0, Math.min( 100, Math.round( Number( v ) || 0 ) ) );
	}
	function contributions( model ) {
		var active = components( model ).filter( function ( c ) { return null !== c.value; } );
		var sum = active.reduce( function ( s, c ) { return s + c.weight; }, 0 ) || 1;
		return active.map( function ( c ) {
			var share = c.weight / sum * 100;
			return { c: c, share: share, earned: share * c.value / 100, lost: share * ( 100 - c.value ) / 100 };
		} );
	}

	function renderOverview( d ) {
		lastOverview = d;
		renderHero( d );
		renderHealth( d.health || {}, d.score_model || null );
		renderScoreModel( d.score_model || null );
		renderReco( d.recommendations || [] );
		renderMission( d.recommendations || [] );
		renderTimeline( d.trend || [] );
		renderTrend( d.trend || [] );
		renderTopIssues( d.top_issues || [], Number( d.crawled ) || 0 );
	}

	// =====================================================================
	// Héros « Santé SEO du site » : jauge en relief + répartition + anomalies
	// =====================================================================
	function globalScore( d ) {
		// Score EXPLICABLE (composantes pondérées), le même que celui de l'historique.
		return ( d && d.score_model && d.crawled ) ? clampScore( d.score_model.total ) : null;
	}

	// Dernier relevé du score global AVANT aujourd'hui : base de l'écart affiché.
	function previousGlobal( trend ) {
		var today = siteToday();
		for ( var i = trend.length - 1; i >= 0; i-- ) {
			var row = trend[ i ];
			if ( row.score_global != null && "" !== row.score_global && String( row.snap_date ).slice( 0, 10 ) < today ) {
				return { score: Number( row.score_global ), day: String( row.snap_date ).slice( 0, 10 ) };
			}
		}
		return null;
	}

	function renderHero( d ) {
		var score = globalScore( d );
		var name = viz.tone( score );
		var gaugeEl = $( "cu-seo-gauge" );
		viz.gauge( gaugeEl, score, { empty: "—" } );
		gaugeEl.setAttribute( "aria-label", score == null
			? "Score global : aucune page analysée"
			: "Score global : " + score + " sur 100, " + seoVerdict( score ) + ". Objectif : 80." );
		if ( ! gaugeEl.dataset.tip ) {
			gaugeEl.dataset.tip = "1";
			viz.bindTip( gaugeEl, gaugeTip );
		}

		var pill = $( "cu-seo-verdict" );
		pill.className = "cu-seo-verdict-pill is-" + name;
		pill.textContent = score == null ? "En attente d'analyse" : seoVerdict( score );

		var title = $( "cu-seo-hero-title" );
		if ( score == null ) {
			title.textContent = "Lancez une première analyse pour obtenir votre score";
		} else if ( score >= 80 ) {
			title.textContent = "Objectif atteint : votre site est bien optimisé";
		} else {
			title.innerHTML = "Encore <span class=\"is-" + name + "\">" + plural( 80 - score, "point", "points" ) + "</span> pour atteindre l'objectif 80";
		}

		var chips = [];
		var prev = score == null ? null : previousGlobal( d.trend || [] );
		if ( prev ) {
			chips.push( '<span class="cu-seo-chip3">' + viz.delta( score - prev.score, false, "pts" ) + "<em>depuis le " + escapeHtml( shortDay( prev.day ) ) + "</em></span>" );
		}
		if ( d.crawled ) {
			chips.push( '<span class="cu-seo-chip3">' + cu.icon( "check" ) + "<b>" + cu.formatNumber( d.crawled ) + "</b> page" + ( d.crawled > 1 ? "s" : "" ) + " analysée" + ( d.crawled > 1 ? "s" : "" ) + ( d.total > d.crawled ? " sur " + cu.formatNumber( d.total ) : "" ) + "</span>" );
			chips.push( '<span class="cu-seo-chip3"><b>' + cu.formatNumber( d.indexable ) + "</b> indexable" + ( d.indexable > 1 ? "s" : "" ) + " · " + percent( d.indexable, d.crawled ) + " %</span>" );
		}
		if ( d.dom_missing ) chips.push( '<span class="cu-seo-chip3 is-bad" title="Demandez à votre hébergeur d\'activer l\'extension PHP DOM (php-xml)">Extension PHP DOM absente : analyse impossible</span>' );
		if ( d.pending ) chips.push( '<span class="cu-seo-chip3 is-wait"><b>' + cu.formatNumber( d.pending ) + "</b> en attente</span>" );
		if ( d.failed ) chips.push( '<span class="cu-seo-chip3 is-bad" title="Pages en erreur HTTP (4xx/5xx) ou injoignables lors de la dernière analyse"><b>' + cu.formatNumber( d.failed ) + "</b> en erreur</span>" );
		if ( d.skipped ) chips.push( '<span class="cu-seo-chip3 is-soft" title="URL qui ne servent pas de page HTML (fichier PDF…) ou qui redirigent vers une page déjà analysée : non notées"><b>' + cu.formatNumber( d.skipped ) + "</b> non analysable" + ( d.skipped > 1 ? "s" : "" ) + "</span>" );
		if ( d.last_crawled ) chips.push( '<span class="cu-seo-chip3 is-soft">Analyse ' + escapeHtml( relTime( d.last_crawled ) ) + "</span>" );
		$( "cu-seo-hero-meta" ).innerHTML = chips.join( "" );

		renderDistribution( d );
		renderAnomalies( d );
	}

	function gaugeTip() {
		var d = lastOverview;
		var score = globalScore( d );
		if ( score == null ) {
			return '<div class="cu-sx-tip-title">Score global</div><p class="cu-sx-tip-text">Aucune page analysée pour l\'instant : cliquez « Analyser tout le site ».</p>';
		}
		var name = viz.tone( score );
		var parts = contributions( d.score_model ).sort( function ( a, b ) { return b.share - a.share; } );
		var lever = parts.slice().sort( function ( a, b ) { return b.lost - a.lost; } )[ 0 ];
		var rows = parts.map( function ( p ) {
			return '<li><span class="cu-sx-sw" style="background:' + compColor( p.c.key ) + '"></span><span class="cu-sx-row-name">' + escapeHtml( p.c.label ) + "</span>" +
				'<span class="cu-sx-row-note is-' + viz.tone( p.c.value ) + '">' + p.c.value + "</span>" +
				"<b>" + viz.decimal( p.earned, 1 ) + "<small> / " + viz.decimal( p.share, 1 ) + "</small></b></li>";
		} ).join( "" );
		var pending = components( d.score_model ).filter( function ( c ) { return null === c.value; } ).map( function ( c ) { return c.label; } );
		return '<div class="cu-sx-tip-head"><span class="cu-sx-tip-date">Score global</span></div>' +
			'<div class="cu-sx-tip-total"><strong class="is-' + name + '">' + score + "</strong>/100 " +
			'<span class="cu-sx-verdict is-' + name + '">' + escapeHtml( seoVerdict( score ) ) + "</span></div>" +
			'<p class="cu-sx-tip-text">Moyenne pondérée de ' + parts.length + " composantes mesurées : chaque note sur 100 compte selon son poids.</p>" +
			'<div class="cu-sx-sec">Points obtenus / points possibles</div><ul class="cu-sx-rows">' + rows + "</ul>" +
			( lever && lever.lost >= 0.5 ? '<p class="cu-sx-lever">' + cu.icon( "trend-up" ) + "<span>Plus gros levier : <b>" + escapeHtml( lever.c.label ) + "</b> (+" + pts( lever.lost ) + " possibles)</span></p>" : "" ) +
			( pending.length ? '<p class="cu-sx-note">' + escapeHtml( pending.join( ", " ) ) + " : en attente de données, exclu" + ( pending.length > 1 ? "s" : "" ) + " du calcul (son poids est réparti sur les autres) plutôt qu'estimé.</p>" : "" ) +
			'<div class="cu-sx-tip-foot">' + ( score >= 80 ? "Objectif 80 atteint." : "Objectif 80 : encore " + plural( 80 - score, "point", "points" ) + "." ) + "</div>";
	}

	// Barre empilée en relief : bonnes / moyennes / faibles. Chaque segment filtre le tableau.
	function renderDistribution( d ) {
		var box = $( "cu-seo-dist" );
		var key = $( "cu-seo-dist-key" );
		var dist = d.distribution || {};
		var segs = [
			{ k: "good", n: Number( dist.good ) || 0, label: "Bonnes", range: "score ≥ 80", filter: "good" },
			{ k: "mid", n: Number( dist.medium ) || 0, label: "Moyennes", range: "score de 50 à 79", filter: "medium" },
			{ k: "low", n: Number( dist.poor ) || 0, label: "Faibles", range: "score < 50", filter: "poor" },
		];
		var total = segs.reduce( function ( s, x ) { return s + x.n; }, 0 );
		$( "cu-seo-dist-total" ).textContent = total ? plural( total, "page", "pages" ) : "";
		if ( ! total ) {
			box.innerHTML = '<div class="cu-seo-dist-empty">Aucune page analysée pour l\'instant.</div>';
			key.innerHTML = "";
			return;
		}
		box.innerHTML = segs.filter( function ( s ) { return s.n > 0; } ).map( function ( s ) {
			var share = percent( s.n, total );
			return '<button type="button" class="cu-seo-dist-seg is-' + s.k + '" style="flex-grow:' + s.n + '" data-filter="' + s.filter + '" data-k="' + s.k + '" aria-label="' + s.label + " : " + plural( s.n, "page", "pages" ) + ", " + share + ' %. Afficher ces pages dans le tableau.">' +
				( share >= 12 ? "<span>" + share + " %</span>" : "" ) + "</button>";
		} ).join( "" );
		key.innerHTML = segs.map( function ( s ) {
			return '<span class="cu-seo-dist-k is-' + s.k + '"><i></i>' + s.label + " <b>" + cu.formatNumber( s.n ) + "</b><em>" + percent( s.n, total ) + " %</em></span>";
		} ).join( "" );
		box.querySelectorAll( ".cu-seo-dist-seg" ).forEach( function ( seg ) {
			var s = segs.filter( function ( x ) { return x.k === seg.dataset.k; } )[ 0 ];
			seg.addEventListener( "click", function () { applyPageFilter( seg.dataset.filter ); } );
			viz.bindTip( seg, function () {
				return '<div class="cu-sx-tip-head"><span class="cu-sx-tip-date">Pages ' + escapeHtml( s.label.toLowerCase() ) + " · " + escapeHtml( s.range ) + "</span></div>" +
					'<div class="cu-sx-tip-total"><strong class="is-' + s.k + '">' + cu.formatNumber( s.n ) + "</strong> page" + ( s.n > 1 ? "s" : "" ) + " · " + percent( s.n, total ) + " %</div>" +
					'<p class="cu-sx-tip-text">' + ( "good" === s.k ? "Optimisées : à maintenir et à enrichir." : "mid" === s.k ? "Correctes mais perfectibles : souvent des balises ou un contenu à étoffer." : "Freinent votre visibilité : à corriger en priorité." ) + "</p>" +
					'<div class="cu-sx-tip-foot">Cliquez pour afficher ces pages dans le tableau.</div>';
			}, { cls: "is-compact" } );
		} );
	}

	var ANOMALY_KINDS = [
		{ k: "error", label: "Erreurs", icon: "octagon", filter: "errors", hint: "Bloquent ou pénalisent l'indexation : à corriger en premier." },
		{ k: "warning", label: "Alertes", icon: "alert", filter: "warnings", hint: "Freinent le classement : à traiter ensuite." },
		{ k: "notice", label: "Infos", icon: "info", filter: "", hint: "Améliorations conseillées, sans urgence." },
	];

	function renderAnomalies( d ) {
		var box = $( "cu-seo-anoms" );
		// Même population que le numérateur (pages à l'analyse à jour).
		var crawled = Number( d.analysed != null ? d.analysed : d.crawled ) || 0;
		var pages = d.issue_pages || {};
		box.innerHTML = ANOMALY_KINDS.map( function ( a ) {
			var n = Number( ( d.issues || {} )[ a.k ] ) || 0;
			var touched = Number( pages[ a.k ] ) || 0;
			var share = percent( touched, crawled );
			return '<button type="button" class="cu-seo-anom is-' + a.k + '" data-k="' + a.k + '" aria-label="' + a.label + " : " + cu.formatNumber( n ) + ", sur " + plural( touched, "page", "pages" ) + ( a.filter ? ". Afficher ces pages dans le tableau." : ". Voir les problèmes les plus fréquents." ) + '">' +
				'<span class="cu-seo-anom-ico">' + cu.icon( a.icon ) + "</span>" +
				'<span class="cu-seo-anom-n">' + cu.formatNumber( n ) + "</span>" +
				'<span class="cu-seo-anom-label">' + a.label + "</span>" +
				'<span class="cu-seo-anom-bar"><i style="width:' + share + '%"></i></span>' +
				'<span class="cu-seo-anom-sub">' + plural( touched, "page", "pages" ) + " · " + share + " %</span>" +
				"</button>";
		} ).join( "" );
		box.querySelectorAll( ".cu-seo-anom" ).forEach( function ( btn ) {
			var a = ANOMALY_KINDS.filter( function ( x ) { return x.k === btn.dataset.k; } )[ 0 ];
			btn.addEventListener( "click", function () {
				if ( a.filter ) {
					applyPageFilter( a.filter );
				} else {
					var top = $( "cu-seo-top-card" );
					if ( top ) top.scrollIntoView( { behavior: "smooth", block: "start" } );
				}
			} );
			viz.bindTip( btn, function () {
				var n = Number( ( lastOverview.issues || {} )[ a.k ] ) || 0;
				var touched = Number( ( lastOverview.issue_pages || {} )[ a.k ] ) || 0;
				return '<div class="cu-sx-tip-head"><span class="cu-sx-tip-date">' + a.label + "</span></div>" +
					'<div class="cu-sx-tip-total"><strong class="is-' + a.k + '">' + cu.formatNumber( n ) + "</strong> sur " + plural( touched, "page", "pages" ) + "</div>" +
					'<p class="cu-sx-tip-text">' + escapeHtml( a.hint ) + "</p>" +
					'<div class="cu-sx-tip-foot">' + ( a.filter ? "Cliquez pour afficher ces pages dans le tableau." : "Cliquez pour voir les problèmes les plus fréquents." ) + "</div>";
			}, { cls: "is-compact" } );
		} );
	}

	// Filtre le tableau « Pages analysées » et l'amène à l'écran.
	function applyPageFilter( filter ) {
		var select = $( "cu-seo-filter" );
		if ( select ) select.value = filter;
		state.filter = filter;
		state.page = 1;
		loadPages();
		var table = $( "cu-seo-tbody" );
		var card = table && table.closest( ".cu-card" );
		if ( card ) card.scrollIntoView( { behavior: "smooth", block: "start" } );
	}

	// Mission de la semaine : les 3 actions les plus prioritaires, cochables.
	function renderMission( recos ) {
		var card = $( "cu-seo-mission" );
		var body = $( "cu-seo-mission-body" );
		if ( ! card || ! body ) return;
		var top = recos.slice( 0, 3 );
		if ( ! top.length ) { card.hidden = true; return; }
		card.hidden = false;
		var weekKey = "cu-seo-mission-" + new Date().getFullYear() + "-" + weekNumber();
		var done = {};
		try { done = JSON.parse( localStorage.getItem( weekKey ) || "{}" ); } catch ( e ) { done = {}; }

		body.innerHTML = top.map( function ( r, i ) {
			var diff = DIFFICULTY[ r.difficulty ] || DIFFICULTY.medium;
			var stars = ""; for ( var s = 1; s <= 5; s++ ) { stars += ( s <= num( r.impact ) ? "★" : "☆" ); }
			var checked = done[ r.id ] ? "checked" : "";
			return '<label class="cu-seo-mission-item ' + ( done[ r.id ] ? "is-done" : "" ) + '" data-id="' + escapeHtml( r.id ) + '">' +
				'<input type="checkbox" ' + checked + ">" +
				'<span class="cu-seo-mission-num">' + ( i + 1 ) + "</span>" +
				'<span class="cu-seo-mission-main"><b>' + escapeHtml( r.title ) + "</b><em>" + escapeHtml( r.action ) + "</em></span>" +
				'<span class="cu-seo-mission-tags"><span class="cu-seo-stars">' + stars + "</span><span class=\"cu-pill cu-pill-ghost\">" + diff.dot + " " + diff.time + "</span></span>" +
				"</label>";
		} ).join( "" );

		body.querySelectorAll( ".cu-seo-mission-item input" ).forEach( function ( cb ) {
			cb.addEventListener( "change", function () {
				var item = cb.closest( ".cu-seo-mission-item" );
				var id = item.dataset.id;
				done[ id ] = cb.checked;
				item.classList.toggle( "is-done", cb.checked );
				try { localStorage.setItem( weekKey, JSON.stringify( done ) ); } catch ( e ) {}
			} );
		} );
	}

	function weekNumber() {
		var d = new Date();
		var onejan = new Date( d.getFullYear(), 0, 1 );
		return Math.ceil( ( ( d - onejan ) / 86400000 + onejan.getDay() + 1 ) / 7 );
	}

	// Timeline des erreurs : aujourd'hui vs point précédent (motivant).
	function renderTimeline( trend ) {
		var el = $( "cu-seo-timeline" );
		if ( ! el ) return;
		if ( trend.length < 2 ) { el.textContent = ""; return; }
		var last = trend[ trend.length - 1 ], prev = trend[ trend.length - 2 ];
		var now = ( Number( last.issues_error ) || 0 ) + ( Number( last.issues_warning ) || 0 );
		var before = ( Number( prev.issues_error ) || 0 ) + ( Number( prev.issues_warning ) || 0 );
		var delta = now - before;
		if ( delta < 0 ) {
			el.className = "cu-seo-timeline is-good";
			el.innerHTML = cu.icon( "trend-down" ) + " " + plural( Math.abs( delta ), "problème corrigé", "problèmes corrigés" ) + " depuis le " + escapeHtml( shortDay( prev.snap_date ) );
		} else if ( delta > 0 ) {
			el.className = "cu-seo-timeline is-bad";
			el.innerHTML = cu.icon( "trend-up" ) + " +" + plural( delta, "nouveau problème", "nouveaux problèmes" ) + " depuis le " + escapeHtml( shortDay( prev.snap_date ) );
		} else {
			el.className = "cu-seo-timeline";
			el.textContent = "Stable depuis le " + shortDay( prev.snap_date );
		}
	}

	// --- Centre de santé : un orbe en relief par indicateur ----------------------
	var COMP_COLORS = { contenu: 1, balises: 3, performance: 2, ai_ready: 5, technique: 7, ctr: 8, maillage: 9, indexation: 4 };
	function compColor( key ) {
		var colors = cu.vizColors();
		return colors[ ( COMP_COLORS[ key ] || 8 ) - 1 ];
	}

	// Ce que mesure chaque indicateur (fidèle aux contrôles réels de l'audit).
	var HEALTH = [
		{ key: "technique", comp: "technique", label: "Technique", src: "WordPress (audit local)", stars: 5,
			what: "Code HTTP, indexabilité (noindex), rendu JavaScript, viewport mobile, langue déclarée, contenu mixte HTTP/HTTPS, favicon.",
			advice: "Corrigez d'abord les pages en erreur HTTP ou bloquées par un noindex involontaire." },
		{ key: "contenu", comp: "contenu", label: "Contenu", src: "WordPress (audit local)", stars: 5,
			what: "Titres H1/H2, longueur du texte, texte alternatif des images, liens ; avec un mot-clé cible : sa présence dans le H1, l'URL, l'introduction et les intertitres.",
			advice: "Étoffez les pages de moins de 300 mots et donnez un H1 unique à chaque page." },
		{ key: "balises", comp: "balises", label: "Balises", src: "WordPress (audit local)", stars: 5,
			what: "Balise title (présence, longueur, largeur affichée), meta description, canonical, Open Graph / Twitter et données structurées.",
			advice: "Rédigez une meta description de 140 à 160 caractères pour chaque page importante." },
		{ key: "performance", comp: "performance", label: "Performance", src: "Google PageSpeed Insights", stars: 5,
			what: "Score PageSpeed moyen des pages mesurées (Core Web Vitals : LCP, INP, CLS).",
			advice: "Allégez les images et le JavaScript des pages les plus lentes.",
			na: "Ajoutez une clé PageSpeed (Réglages › SEO), puis « Mesurer la vitesse » sur une page." },
		{ key: "ai_ready", comp: "ai_ready", label: "AI-Ready", src: "Heuristique CyberPush", stars: 3,
			what: "Facilité pour les IA (ChatGPT, Gemini, AI Overviews) à comprendre et citer vos pages : données structurées (FAQ comprise), contenu suffisant, meta description, structure H1/H2, langue, sources externes.",
			advice: "Ajoutez des données structurées (FAQ, Article) et une réponse directe en début de page." },
		{ key: "autorite", comp: null, label: "Autorité", src: "DataForSEO (à venir)", stars: 4,
			what: "Popularité du site mesurée par les liens entrants (backlinks). N'entre pas dans le score global.",
			advice: "",
			na: "Nécessite une source de backlinks (DataForSEO) — à venir." },
	];

	function starRating( n ) {
		var out = "";
		for ( var i = 1; i <= 5; i++ ) out += i <= n ? "★" : "☆";
		return out;
	}

	function renderHealth( h, model ) {
		var box = $( "cu-seo-health" );
		if ( ! box ) return;
		// Squelette construit une seule fois : les orbes s'animent ensuite de leur
		// ancienne valeur vers la nouvelle (rafraîchissements pendant une analyse).
		if ( ! box.querySelector( ".cu-seo-orb" ) ) {
			box.innerHTML = HEALTH.map( function ( it, i ) {
				return '<div class="cu-seo-orb" tabindex="0" role="img" data-i="' + i + '">' +
					'<div class="cu-seo-orb-dial"></div>' +
					'<span class="cu-seo-orb-name">' + it.label + "</span>" +
					'<span class="cu-seo-orb-state"></span>' +
					'<span class="cu-seo-orb-weight"></span>' +
					"</div>";
			} ).join( "" );
			box.querySelectorAll( ".cu-seo-orb" ).forEach( function ( node ) {
				viz.bindTip( node, function () { return orbTip( HEALTH[ Number( node.dataset.i ) ] ); } );
			} );
		}
		// Avant toute analyse, le modèle ne contient que des zéros : aucun point à afficher.
		var parts = ( lastOverview && lastOverview.crawled ) ? contributions( model ) : [];
		box.querySelectorAll( ".cu-seo-orb" ).forEach( function ( node ) {
			var it = HEALTH[ Number( node.dataset.i ) ];
			var v = h[ it.key ];
			v = v == null ? null : Number( v );
			var name = viz.tone( v );
			node.className = "cu-seo-orb is-" + name;
			viz.orb( node.querySelector( ".cu-seo-orb-dial" ), v, { empty: "n/d" } );
			var stateEl = node.querySelector( ".cu-seo-orb-state" );
			stateEl.className = "cu-seo-orb-state is-" + name;
			stateEl.textContent = v == null ? "Indisponible" : { good: "Bon", mid: "À travailler", low: "Faible" }[ name ];
			var part = parts.filter( function ( p ) { return p.c.key === it.comp; } )[ 0 ];
			node.querySelector( ".cu-seo-orb-weight" ).textContent = part
				? viz.decimal( part.earned, 1 ) + " / " + viz.decimal( part.share, 1 ) + " pts"
				: ( it.comp ? ( null === v ? "en attente" : "hors calcul" ) : "hors score" );
			node.setAttribute( "aria-label", it.label + " : " + ( v == null ? "donnée indisponible" : v + " sur 100, " + stateEl.textContent ) );
		} );
	}

	function orbTip( it ) {
		var d = lastOverview || {};
		var v = ( d.health || {} )[ it.key ];
		v = v == null ? null : Number( v );
		var name = viz.tone( v );
		var part = d.crawled ? contributions( d.score_model ).filter( function ( p ) { return p.c.key === it.comp; } )[ 0 ] : null;
		var html = '<div class="cu-sx-tip-head"><span class="cu-sx-tip-date">' + escapeHtml( it.label ) + "</span></div>";
		html += v == null
			? '<div class="cu-sx-tip-total"><strong class="is-na">n/d</strong></div><p class="cu-sx-note">' + escapeHtml( it.na || "Lancez « Analyser tout le site »." ) + "</p>"
			: '<div class="cu-sx-tip-total"><strong class="is-' + name + '">' + v + "</strong>/100 <span class=\"cu-sx-verdict is-" + name + "\">" + { good: "Bon", mid: "À travailler", low: "Faible" }[ name ] + "</span></div>";
		html += '<div class="cu-sx-sec">Ce qui est mesuré</div><p class="cu-sx-tip-text">' + escapeHtml( it.what ) + "</p>";
		if ( part ) {
			html += '<div class="cu-sx-metrics">' +
				'<div class="cu-sx-m"><span>Poids dans le score</span><b>' + viz.decimal( part.share, 1 ) + " pts</b></div>" +
				'<div class="cu-sx-m"><span>À gagner</span><b class="cu-sx-gain">+' + pts( part.lost ) + "</b></div>" +
				"</div>";
		}
		html += '<div class="cu-sx-src">Source : ' + escapeHtml( it.src ) + ' <span class="cu-sx-stars">' + starRating( it.stars ) + "</span></div>";
		if ( it.advice && v != null && v < 80 ) html += '<p class="cu-sx-lever">' + cu.icon( "sparkles" ) + " " + escapeHtml( it.advice ) + "</p>";
		return html;
	}

	// --- Score expliqué : d'où viennent les points, où se cachent ceux qui manquent ---
	function renderScoreModel( m ) {
		var box = $( "cu-seo-breakdown" );
		if ( ! box ) return;
		var parts = contributions( m );
		if ( ! m || ! m.components || ! parts.length || ! ( lastOverview && lastOverview.crawled ) ) { box.innerHTML = ""; return; }
		var earnedSum = parts.reduce( function ( s, p ) { return s + p.earned; }, 0 );
		var piece = function ( p, lost ) {
			var w = lost ? p.lost : p.earned;
			if ( w < 0.05 ) return "";
			// Étiquette dans le segment s'il est assez large ; les segments étroits la masquent sur mobile.
			var label = w >= 7 ? "<span" + ( w < 13 ? " class=\"is-sm\"" : "" ) + ">" + ( lost ? "+" : "" ) + viz.decimal( w, 1 ) + "</span>" : "";
			return '<button type="button" class="cu-seo-stack-p' + ( lost ? " is-lost" : "" ) + '" style="flex-grow:' + w.toFixed( 3 ) + ";--c:" + compColor( p.c.key ) + '" data-key="' + escapeHtml( p.c.key ) + '" data-lost="' + ( lost ? 1 : 0 ) + '" aria-label="' + escapeHtml( p.c.label ) + " : " + viz.decimal( w, 1 ) + ( lost ? " points à gagner" : " points obtenus" ) + '">' + label + "</button>";
		};
		var stack = parts.map( function ( p ) { return piece( p, false ); } ).join( "" ) +
			parts.slice().sort( function ( a, b ) { return b.lost - a.lost; } ).map( function ( p ) { return piece( p, true ); } ).join( "" );

		var gains = parts.slice().sort( function ( a, b ) { return b.lost - a.lost; } ).map( function ( p ) {
			var name = viz.tone( p.c.value );
			return '<div class="cu-seo-gain">' +
				'<span class="cu-seo-gain-name"><i style="background:' + compColor( p.c.key ) + '"></i>' + escapeHtml( p.c.label ) + "<em>poids " + p.c.weight + "</em></span>" +
				'<span class="cu-seo-gain-track"><span class="cu-seo-gain-fill is-' + name + '" style="width:' + p.c.value + '%"></span></span>' +
				'<b class="cu-seo-gain-val is-' + name + '">' + p.c.value + "</b>" +
				'<span class="cu-seo-gain-pts' + ( p.lost < 0.5 ? " is-done" : "" ) + '">' + ( p.lost < 0.5 ? "au max" : "+" + pts( p.lost ) ) + "</span>" +
				'<span class="cu-seo-gain-src">' + sourceDot( p.c.source ) + "</span>" +
				"</div>";
		} ).join( "" );
		var pendingRows = components( m ).filter( function ( c ) { return null === c.value; } ).map( function ( c ) {
			return '<div class="cu-seo-gain is-pending"><span class="cu-seo-gain-name"><i></i>' + escapeHtml( c.label ) + "<em>poids " + c.weight + "</em></span>" +
				'<span class="cu-seo-gain-wait">En attente de données — exclu du calcul plutôt qu\'estimé</span>' +
				'<span class="cu-seo-gain-src">' + sourceDot( c.source ) + "</span></div>";
		} ).join( "" );

		box.innerHTML =
			'<div class="cu-seo-bd-head"><b>D\'où viennent vos ' + clampScore( m.total ) + ' points</b><span>Couleur pleine : points obtenus · hachures : points encore à gagner. Survolez un segment pour le détail.</span></div>' +
			'<div class="cu-seo-stack-wrap">' +
				'<div class="cu-seo-stack">' + stack + "</div>" +
				'<span class="cu-seo-stack-mark" style="left:' + earnedSum.toFixed( 2 ) + '%"><b>' + clampScore( m.total ) + "</b></span>" +
				'<span class="cu-seo-stack-goal' + ( Math.abs( earnedSum - 80 ) < 9 ? " is-near" : "" ) + '" style="left:80%"><em>objectif 80</em></span>' +
			"</div>" +
			'<div class="cu-seo-stack-scale" aria-hidden="true"><span>0</span><span>25</span><span>50</span><span>75</span><span>100</span></div>' +
			'<div class="cu-seo-gains-title">Où gagner des points — par ordre d\'impact</div>' +
			'<div class="cu-seo-gains">' + gains + pendingRows + "</div>";

		box.querySelectorAll( ".cu-seo-stack-p" ).forEach( function ( btn ) {
			viz.bindTip( btn, function () {
				var p = contributions( lastOverview.score_model ).filter( function ( x ) { return x.c.key === btn.dataset.key; } )[ 0 ];
				if ( ! p ) return "";
				var lost = "1" === btn.dataset.lost;
				var name = viz.tone( p.c.value );
				return '<div class="cu-sx-tip-head"><span class="cu-sx-sw" style="background:' + compColor( p.c.key ) + '"></span><span class="cu-sx-tip-date">' + escapeHtml( p.c.label ) + "</span></div>" +
					'<div class="cu-sx-tip-total"><strong class="' + ( lost ? "is-good" : "is-" + name ) + '">' + ( lost ? "+" : "" ) + viz.decimal( lost ? p.lost : p.earned, 1 ) + "</strong> " + ( Math.abs( lost ? p.lost : p.earned ) < 2 ? "pt " : "pts " ) + ( lost ? "à gagner" : "obtenu" + ( Math.abs( p.earned ) < 2 ? "" : "s" ) ) + "</div>" +
					'<div class="cu-sx-metrics">' +
						'<div class="cu-sx-m"><span>Note</span><b class="is-' + name + '">' + p.c.value + "<small>/100</small></b></div>" +
						'<div class="cu-sx-m"><span>Poids</span><b>' + viz.decimal( p.share, 1 ) + " pts</b></div>" +
					"</div>" +
					'<p class="cu-sx-tip-text">' + ( lost
						? "Monter " + escapeHtml( p.c.label ) + " de " + p.c.value + " à 100 rapporterait " + viz.decimal( p.lost, 1 ) + " points au score global."
						: escapeHtml( p.c.label ) + " noté " + p.c.value + "/100 apporte " + viz.decimal( p.earned, 1 ) + " des " + viz.decimal( p.share, 1 ) + " points qu'il peut valoir." ) + "</p>" +
					'<div class="cu-sx-src">Source : ' + escapeHtml( ( sourceLabels[ p.c.source ] || p.c.source ).split( " ★" )[ 0 ] ) + "</div>";
			}, { cls: "is-compact" } );
		} );
	}

	// --- Systèmes de fiabilité / difficulté / impact (langage clair) ---
	var DIFFICULTY = {
		very_easy: { label: "Très facile", time: "≈ 1 min", dot: dot( "green" ) },
		easy:      { label: "Facile", time: "≈ 5 min", dot: dot( "yellow" ) },
		medium:    { label: "Moyen", time: "≈ 20 min", dot: dot( "orange" ) },
		advanced:  { label: "Avancé", time: "développeur", dot: dot( "red" ) },
	};
	var IMPACT = {
		critical: { label: "Très fort", dot: dot( "red" ) },
		strong:   { label: "Fort", dot: dot( "green" ) },
		medium:   { label: "Moyen", dot: dot( "yellow" ) },
		low:      { label: "Faible", dot: dot( "gray" ) },
	};
	function sourceDot( source ) {
		var greens = { wordpress: 1, analytics: 1, psi: 1 };
		var d = greens[ source ] ? "green" : ( "ia" === source || "heuristique" === source ? "yellow" : "gray" );
		return dot( d ) + " " + escapeHtml( ( sourceLabels[ source ] || source ).split( " ★" )[ 0 ] );
	}

	function renderReco( recos ) {
		var box = $( "cu-seo-reco" );
		if ( ! box ) return;
		if ( ! recos.length ) {
			box.innerHTML = '<div class="cu-empty">' + ( state.scanning ? "Analyse en cours…" : "Aucune action prioritaire — le site est en bonne santé." ) + "</div>";
			return;
		}
		var labels = { high: "Priorité élevée", medium: "Priorité moyenne", low: "Priorité faible" };
		box.innerHTML = recos.map( function ( r, idx ) {
			var stars = "";
			for ( var i = 1; i <= 5; i++ ) { stars += ( i <= num( r.impact ) ? "★" : "☆" ); }
			var diff = DIFFICULTY[ r.difficulty ] || DIFFICULTY.medium;
			var imp = IMPACT[ r.impact_level ] || IMPACT.medium;
			var prio = labels[ r.priority ] ? r.priority : "low";
			var basis = BASIS[ r.basis ] || BASIS.practice;
			var pages = num( r.pages );
			var howSteps = ( r.how || [] ).map( function ( s ) { return "<li>" + escapeHtml( s ) + "</li>"; } ).join( "" );

			return '<div class="cu-seo-reco-item is-' + prio + '">' +
				'<div class="cu-seo-reco-head">' +
					'<span class="cu-seo-reco-prio is-' + prio + '">' + labels[ prio ] + "</span>" +
					'<span class="cu-seo-reco-impact" title="Impact qualitatif, fixé par type de problème (pas une mesure de trafic)">' + imp.dot + " Impact " + imp.label + "</span>" +
					'<span class="cu-seo-reco-diff" title="Difficulté / temps">' + diff.dot + " " + diff.label + " · " + diff.time + "</span>" +
				"</div>" +
				'<div class="cu-seo-reco-title">' + escapeHtml( r.title ) + "</div>" +
				'<div class="cu-seo-reco-action">' + escapeHtml( r.action ) + "</div>" +
				'<div class="cu-seo-reco-btns">' +
					'<button class="cu-btn cu-btn-sm cu-seo-toggle" data-target="why-' + idx + '">Pourquoi ?</button>' +
					( howSteps ? '<button class="cu-btn cu-btn-sm cu-seo-toggle" data-target="how-' + idx + '">Voir comment corriger</button>' : "" ) +
					( pages > 0 && r.id ? '<button class="cu-btn cu-btn-sm cu-seo-urls-btn" data-reco="' + escapeHtml( r.id ) + '" data-target="urls-' + idx + '">Voir les ' + pages + ' URL</button>' : "" ) +
				"</div>" +
				'<div class="cu-seo-reco-panel" id="why-' + idx + '" hidden>' +
					( r.what ? '<p><b>C\'est quoi ?</b> ' + escapeHtml( r.what ) + "</p>" : "" ) +
					'<p><b>Pourquoi c\'est important ?</b> ' + escapeHtml( r.why ) + "</p>" +
					( r.rationale ? '<p><b>Pourquoi cette priorité ?</b> ' + escapeHtml( r.rationale ) + "</p>" : "" ) +
				"</div>" +
				( howSteps ? '<div class="cu-seo-reco-panel" id="how-' + idx + '" hidden><b>Comment corriger :</b><ol>' + howSteps + "</ol></div>" : "" ) +
				( pages > 0 && r.id ? '<div class="cu-seo-reco-panel cu-seo-urls-panel" id="urls-' + idx + '" hidden></div>' : "" ) +
				'<div class="cu-seo-reco-meta">' +
					'<span class="cu-seo-stars" title="Impact qualitatif (1 à 5)">' + stars + "</span>" +
					'<span class="cu-pill cu-pill-ghost">' + pages + " élément(s)</span>" +
					'<span class="cu-seo-conf" title="Nature du constat">' + dot( basis.dot ) + " " + escapeHtml( r.basis_label || basis.label ) + "</span>" +
					'<span class="cu-seo-src-tag" title="Source de la donnée">' + sourceDot( r.source ) + "</span>" +
				"</div>" +
			"</div>";
		} ).join( "" );

		box.querySelectorAll( ".cu-seo-toggle" ).forEach( function ( btn ) {
			btn.addEventListener( "click", function () {
				var el = document.getElementById( btn.dataset.target );
				if ( el ) el.hidden = ! el.hidden;
			} );
		} );

		// Bouton « Voir les N URL » : ouvre le panneau et charge la liste au 1er clic.
		box.querySelectorAll( ".cu-seo-urls-btn" ).forEach( function ( btn ) {
			btn.addEventListener( "click", function () {
				var panel = document.getElementById( btn.dataset.target );
				if ( ! panel ) return;
				panel.hidden = ! panel.hidden;
				if ( ! panel.hidden && ! panel.dataset.loaded ) {
					loadRecoUrls( btn.dataset.reco, panel );
				}
			} );
		} );
	}

	/**
	 * Charge à la demande les URL concernées par une recommandation et les
	 * affiche dans son panneau (liens cliquables + note explicative par page).
	 */
	function loadRecoUrls( ref, panel ) {
		panel.dataset.loaded = "1";
		panel.innerHTML = '<div class="cu-loading"><span class="cu-spinner"></span> Chargement des URL…</div>';
		// ref = id de recommandation (chaîne) OU { code: "…" } pour un code d'anomalie.
		var query = ( ref && ref.code )
			? "seo/reco-urls?code=" + encodeURIComponent( ref.code )
			: "seo/reco-urls?id=" + encodeURIComponent( ref );
		apiFetch( query )
			.then( function ( d ) {
				var items = ( d && d.items ) || [];
				if ( ! items.length ) {
					panel.innerHTML = '<div class="cu-empty">Aucune URL à lister pour l\'instant — relancez « Analyser tout le site » pour rafraîchir l\'audit technique.</div>';
					return;
				}
				var rows = items.map( function ( it ) {
					var note = it.note ? ' <span class="cu-seo-url-note">' + escapeHtml( it.note ) + "</span>" : "";
					return '<li><a href="' + escapeHtml( it.url ) + '" target="_blank" rel="noopener">' + escapeHtml( it.url ) + "</a>" + note + "</li>";
				} ).join( "" );
				var count = num( d.count );
				var more = count > items.length ? '<li class="cu-seo-url-more">+ ' + ( count - items.length ) + " autre(s) non listée(s)…</li>" : "";
				panel.innerHTML = '<b>URL concernées (' + count + ') :</b><ul class="cu-seo-url-list">' + rows + more + "</ul>";
			} )
			.catch( function ( e ) {
				panel.dataset.loaded = "";
				panel.innerHTML = '<div class="cu-empty">' + escapeHtml( e.message || "Erreur de chargement." ) + "</div>";
			} );
	}

	// --- Tendance : courbe détaillée (30 ou 90 jours) ---------------------------
	var trendState = { chart: null, rows: [], days: 30, signature: "" };

	function renderTrend( trend ) {
		trendState.rows = trend;
		drawTrend();
	}

	function drawTrend() {
		var box = $( "cu-seo-trend" );
		var empty = $( "cu-seo-trend-empty" );
		var meta = $( "cu-seo-trend-meta" );
		var range = $( "cu-seo-trend-range" );
		var rows = trendState.rows;
		var dayMs = 86400000;
		var lastKey = rows.length ? String( rows[ rows.length - 1 ].snap_date ).slice( 0, 10 ) : "";
		var toTime = function ( key ) { return new Date( key + "T00:00:00Z" ).getTime(); };
		var history = rows.length ? Math.round( ( toTime( lastKey ) - toTime( String( rows[ 0 ].snap_date ).slice( 0, 10 ) ) ) / dayMs ) + 1 : 0;
		// Le choix 30 j / 90 j n'a de sens qu'avec plus de 30 jours d'historique.
		if ( range ) range.hidden = history <= 30;
		var days = history > 30 ? trendState.days : 90;
		var shown = rows.filter( function ( r ) {
			return toTime( lastKey ) - toTime( String( r.snap_date ).slice( 0, 10 ) ) < days * dayMs;
		} );
		var signature = JSON.stringify( shown ) + "|" + days;
		if ( signature === trendState.signature ) return;
		trendState.signature = signature;
		if ( trendState.chart ) {
			trendState.chart.destroy();
			trendState.chart = null;
		}
		if ( ! shown.length ) {
			box.innerHTML = "";
			box.hidden = true;
			empty.hidden = false;
			meta.innerHTML = "";
			return;
		}
		box.hidden = false;
		empty.hidden = 1 !== shown.length;
		// Valeur lisible sans survol : dernier relevé + évolution sur la période affichée.
		var hasGlobal = shown.some( function ( r ) { return r.score_global != null && "" !== r.score_global; } );
		var pick = function ( r ) { return hasGlobal ? ( r.score_global == null || "" === r.score_global ? null : Number( r.score_global ) ) : Number( r.score_avg ); };
		var series = shown.map( pick ).filter( function ( v ) { return null !== v && ! isNaN( v ); } );
		if ( series.length ) {
			var current = series[ series.length - 1 ];
			meta.innerHTML = '<b class="is-' + viz.tone( current ) + '">' + current + "</b>/100" +
				( series.length > 1 ? " " + viz.delta( current - series[ 0 ], false, "pts" ) + '<em>sur ' + ( history > 30 ? days + " j" : plural( shown.length, "relevé", "relevés" ) ) + "</em>" : "" );
		} else {
			meta.innerHTML = "";
		}
		trendState.chart = viz.trend( box, shown, { verdict: seoVerdict } );
	}

	// --- Problèmes les plus fréquents : barres proportionnelles aux pages touchées ---
	var SEVERITY = {
		error: { label: "Erreur", icon: "octagon" },
		warning: { label: "Alerte", icon: "alert" },
		notice: { label: "Info", icon: "info" },
	};

	function renderTopIssues( items, crawled ) {
		var box = $( "cu-seo-top-issues" );
		if ( ! items.length ) {
			box.innerHTML = '<div class="cu-empty">' + ( state.scanning ? "Analyse en cours…" : "Aucune anomalie détectée." ) + "</div>";
			return;
		}
		var peak = items.reduce( function ( m, it ) { return Math.max( m, Number( it.pages || it.total ) || 0 ); }, 1 );
		box.innerHTML = items.map( function ( it, idx ) {
			var sev = SEVERITY[ it.severity ] ? it.severity : "notice";
			var touched = Number( it.pages || it.total ) || 0;
			var total = Number( it.total ) || 0;
			var share = percent( touched, crawled );
			var width = Math.max( 3, Math.round( touched / peak * 100 ) );
			var inner =
				'<span class="cu-seo-top-sev is-' + sev + '" title="' + SEVERITY[ sev ].label + '">' + cu.icon( SEVERITY[ sev ].icon ) + "</span>" +
				'<span class="cu-seo-top-main">' +
					'<span class="cu-seo-top-msg">' + escapeHtml( it.message ) + "</span>" +
					'<span class="cu-seo-top-bar is-' + sev + '"><i style="width:' + width + '%"></i></span>' +
				"</span>" +
				'<span class="cu-seo-top-count"><b>' + cu.formatNumber( touched ) + "</b><em>" + ( crawled ? share + " % des pages" : ( touched > 1 ? "pages" : "page" ) ) + ( total > touched ? " · " + cu.formatNumber( total ) + " fois" : "" ) + "</em></span>";
			if ( ! it.code ) {
				return '<div class="cu-seo-top-row">' + inner + "</div>";
			}
			return '<button type="button" class="cu-seo-top-row cu-seo-top-clickable" data-code="' + escapeHtml( it.code ) + '" data-target="topurls-' + idx + '" aria-expanded="false" aria-controls="topurls-' + idx + '">' +
				inner + '<span class="cu-seo-top-chev">' + cu.icon( "chevron" ) + "</span></button>" +
				'<div class="cu-seo-reco-panel cu-seo-urls-panel" id="topurls-' + idx + '" hidden></div>';
		} ).join( "" );

		box.querySelectorAll( ".cu-seo-top-clickable" ).forEach( function ( row ) {
			row.addEventListener( "click", function () {
				var panel = document.getElementById( row.dataset.target );
				if ( ! panel ) return;
				panel.hidden = ! panel.hidden;
				row.setAttribute( "aria-expanded", panel.hidden ? "false" : "true" );
				if ( ! panel.hidden && ! panel.dataset.loaded ) {
					loadRecoUrls( { code: row.dataset.code }, panel );
				}
			} );
		} );
	}

	// =====================================================================
	// Table des pages
	// =====================================================================
	function loadPages() {
		var q = "seo/pages?page=" + state.page + "&filter=" + encodeURIComponent( state.filter ) + "&search=" + encodeURIComponent( state.search );
		var seq = ++pagesSeq;
		apiFetch( q )
			.then( function ( d ) { if ( seq === pagesSeq ) renderPages( d ); } )
			.catch( function ( e ) {
				if ( seq !== pagesSeq ) return;
				$( "cu-seo-tbody" ).innerHTML = '<tr><td colspan="8" class="cu-empty">' + escapeHtml( e.message || "Erreur." ) + "</td></tr>";
			} );
	}

	function renderPages( d ) {
		var body = $( "cu-seo-tbody" );
		// Page demandée devenue hors limites (pages supprimées, purge…) : revenir à la dernière.
		if ( ! d.items.length && d.pages > 0 && state.page > d.pages ) {
			state.page = d.pages;
			loadPages();
			return;
		}
		if ( ! d.items.length ) {
			body.innerHTML = '<tr><td colspan="8" class="cu-empty">' +
				( state.search || state.filter ? "Aucune page ne correspond." : "Aucune page dans l'inventaire. Cliquez « Analyser tout le site »." ) + "</td></tr>";
			$( "cu-seo-pager" ).innerHTML = "";
			return;
		}
		body.innerHTML = d.items.map( function ( p ) {
			var state = String( p.crawl_state || "" );
			var failed = "error" === state;
			var httpErr = "http_error" === state;
			var skipped = "skipped" === state;
			var pending = ! failed && ! httpErr && ! skipped && ( "done" !== state || p.score === null );
			var title = p.title || "(sans titre)";
			var path = String( p.url || "" ).replace( /^https?:\/\/[^/]+/, "" ) || "/";
			var audited = "done" === state;
			var idx = ! audited ? '<span class="cu-seo-muted">—</span>'
				: ( num( p.indexable ) ? '<span class="cu-badge cu-badge-green">Oui</span>' : '<span class="cu-badge cu-badge-red">noindex</span>' );
			var traffic = p.visits_30d == null ? '<span class="cu-seo-muted" title="Aucune donnée Analytics pour cette page">—</span>' : "<b>" + cu.formatNumber( p.visits_30d ) + "</b>";
			var scoreCell;
			if ( failed ) {
				scoreCell = '<span class="cu-badge cu-badge-red" title="Page injoignable ou erreur serveur lors de la dernière analyse — nouvel essai automatique dans l\'heure">' + ( num( p.http_status ) ? "HTTP " + num( p.http_status ) : "Injoignable" ) + "</span>";
			} else if ( httpErr ) {
				scoreCell = '<span class="cu-badge cu-badge-red" title="La page répond par une erreur HTTP : seule cette anomalie est relevée, sans score">HTTP ' + num( p.http_status ) + "</span>";
			} else if ( skipped && num( p.http_status ) >= 300 && num( p.http_status ) < 400 ) {
				scoreCell = '<span class="cu-badge cu-badge-gray" title="Cette URL redirige vers une page déjà analysée pour elle-même : non notée, ni comptée dans les doublons">Redirection ' + num( p.http_status ) + "</span>";
			} else if ( skipped ) {
				scoreCell = '<span class="cu-badge cu-badge-gray" title="Pas une page HTML (fichier) ou analyse impossible sur ce serveur : non notée">Non analysable</span>';
			} else {
				scoreCell = pending ? '<span class="cu-badge cu-badge-gray">…</span>' : scoreBadge( num( p.score ) );
			}
			return '<tr data-id="' + num( p.id ) + '">' +
				'<td class="cu-seo-cell-page"><strong>' + escapeHtml( title ) + "</strong><span>" + escapeHtml( path ) + "</span></td>" +
				"<td>" + scoreCell + "</td>" +
				"<td>" + traffic + "</td>" +
				"<td>" + ( num( p.issues_error ) ? '<span class="cu-badge cu-badge-red">' + num( p.issues_error ) + "</span>" : "0" ) + "</td>" +
				"<td>" + ( num( p.issues_warning ) ? '<span class="cu-badge cu-badge-yellow">' + num( p.issues_warning ) + "</span>" : "0" ) + "</td>" +
				"<td>" + ( audited ? cu.formatNumber( p.word_count ) : "—" ) + "</td>" +
				"<td>" + idx + "</td>" +
				'<td><button class="cu-btn cu-btn-sm cu-seo-detail-btn" data-id="' + num( p.id ) + '">Détails</button></td>' +
				"</tr>";
		} ).join( "" );

		body.querySelectorAll( ".cu-seo-detail-btn" ).forEach( function ( btn ) {
			btn.addEventListener( "click", function () { openDetail( btn.dataset.id ); } );
		} );

		renderPager( d );
	}

	function renderPager( d ) {
		var pager = $( "cu-seo-pager" );
		if ( d.pages <= 1 ) { pager.innerHTML = ""; return; }
		pager.innerHTML =
			'<button class="cu-btn cu-btn-sm" ' + ( d.page <= 1 ? "disabled" : "" ) + ' id="cu-seo-prev">‹</button>' +
			'<span class="cu-seo-pageinfo">' + num( d.page ) + " / " + num( d.pages ) + " · " + cu.formatNumber( d.total ) + " pages</span>" +
			'<button class="cu-btn cu-btn-sm" ' + ( d.page >= d.pages ? "disabled" : "" ) + ' id="cu-seo-next">›</button>';
		var prev = $( "cu-seo-prev" ), next = $( "cu-seo-next" );
		if ( prev ) prev.addEventListener( "click", function () { state.page = Math.max( 1, state.page - 1 ); loadPages(); } );
		if ( next ) next.addEventListener( "click", function () { state.page = state.page + 1; loadPages(); } );
	}

	// =====================================================================
	// Détail d'une page (modale)
	// =====================================================================
	function openDetail( id ) {
		apiFetch( "seo/page?id=" + id )
			.then( function ( d ) { renderDetail( d ); } )
			.catch( function ( e ) { cu.toast( e.message || "Erreur.", "error" ); } );
	}

	function renderDetail( d ) {
		var p = d.page;
		var snap = p.snapshot || {};
		var score = p.score === null ? null : Number( p.score );

		// Aperçu SERP.
		var serpTitle = snap.title || p.title || "(sans titre)";
		var serpDesc = snap.meta_description || "Aucune meta description — Google génèrera un extrait automatiquement.";
		var serp =
			'<div class="cu-seo-serp">' +
				'<div class="cu-seo-serp-url">' + escapeHtml( p.url ) + "</div>" +
				'<div class="cu-seo-serp-title">' + escapeHtml( serpTitle ) + "</div>" +
				'<div class="cu-seo-serp-desc">' + escapeHtml( serpDesc ) + "</div>" +
			"</div>";

		// Chips de score.
		function chip( label, v ) {
			return '<div class="cu-seo-chip"><span>' + label + '</span><b style="color:' + scoreColor( v == null ? null : num( v ) ) + '">' + ( v == null ? "—" : num( v ) ) + "</b></div>";
		}
		var perf = snap.perf || null;
		var scoreRow =
			'<div class="cu-seo-scorerow">' +
				'<div class="cu-seo-chip cu-seo-chip-big" title="Optimisation on-page — viser 80+"><span>Global</span><b style="color:' + scoreColor( score ) + '">' + ( score == null ? "—" : score ) + "</b></div>" +
				chip( "Contenu", p.score_content ) +
				chip( "Balises", p.score_meta ) +
				chip( "Technique", p.score_technical ) +
				( snap.readability && snap.readability.available ? chip( "Lisibilité", snap.readability.score ) : "" ) +
				chip( "AI-Ready", p.ai_ready ) +
				( perf ? chip( "Vitesse", perf.score ) : "" ) +
			"</div>";
		var verdictBlock = ( score != null )
			? '<div class="cu-seo-verdict">Optimisation on-page : <b style="color:' + scoreColor( score ) + '">' + seoVerdict( score ) + "</b> <em>· " + SCORE_TARGET + "</em></div>"
			: "";

		// Web Vitals (PageSpeed Insights) : date, stratégie et portée des données.
		var cwv = "";
		var strategyLabel = { mobile: "mobile", desktop: "ordinateur" };
		if ( perf ) {
			var cwvLabel = { "is-good": "bon", "is-ni": "à améliorer", "is-poor": "mauvais" };
			var cwvCell = function ( name, metric, raw, shown ) {
				var rating = cwvRating( metric, raw == null ? null : Number( raw ) );
				return '<span class="' + rating + '" title="Seuils Google : ' + ( { lcp: "bon ≤ 2,5 s · mauvais > 4 s", inp: "bon ≤ 200 ms · mauvais > 500 ms", cls: "bon ≤ 0,1 · mauvais > 0,25" }[ metric ] ) + '">' +
					name + " <b>" + shown + "</b>" + ( rating ? "<em>" + cwvLabel[ rating ] + "</em>" : "" ) + "</span>";
			};
			var scope = { url: "données terrain (CrUX) de cette URL", origin: "pas de données terrain pour cette URL : celles de tout le site (CrUX origine)", none: "pas de données terrain (CrUX) : seul le score Lighthouse (laboratoire) est disponible" }[ perf.field_scope ] || "";
			cwv = '<div class="cu-seo-psi-meta">' + cu.icon( "bolt" ) + " PageSpeed " + escapeHtml( strategyLabel[ perf.strategy ] || perf.strategy || "" ) +
				( perf.checked ? " · mesuré le " + escapeHtml( siteDate( perf.checked ) ) + " (" + escapeHtml( relTime( perf.checked ) ) + ")" : "" ) +
				( perf.score != null ? " · score Lighthouse <b>" + num( perf.score ) + "</b>/100" : "" ) +
				( scope ? "<em>" + escapeHtml( scope ) + "</em>" : "" ) + "</div>" +
				'<div class="cu-seo-cwv">' +
				cwvCell( "LCP", "lcp", perf.lcp, perf.lcp != null ? ( num( perf.lcp ) / 1000 ).toFixed( 2 ).replace( ".", "," ) + " s" : "—" ) +
				cwvCell( "INP", "inp", perf.inp, perf.inp != null ? num( perf.inp ) + " ms" : "—" ) +
				cwvCell( "CLS", "cls", perf.cls, perf.cls != null ? num( perf.cls ).toFixed( 2 ).replace( ".", "," ) : "—" ) +
				"</div>";
		}
		if ( snap.perf_error && snap.perf_error.at ) {
			cwv += '<div class="cu-seo-psi-meta is-unavailable">' + cu.icon( "info" ) + " Dernière tentative le " + escapeHtml( siteDate( snap.perf_error.at ) ) + " : " +
				escapeHtml( snap.perf_error.message || "mesure indisponible" ) + "<em>Ce n'est pas un problème SEO de la page ; " + ( perf ? "la mesure précédente reste affichée." : "aucune mesure n'est enregistrée." ) + "</em></div>";
		}

		// Page non analysable : on dit pourquoi, sans aucun constat inventé.
		var unanalysable = "";
		if ( "skipped" === p.crawl_state && "redirected" === snap.unanalysable ) {
			unanalysable = '<div class="cu-seo-jswarn">' + cu.icon( "info" ) + " Redirection" + ( num( snap.redirect_status ) ? " HTTP " + num( snap.redirect_status ) : "" ) +
				( snap.final_url ? " vers " + escapeHtml( snap.final_url ) : "" ) + " — cette destination est analysée pour elle-même ; cette URL n'est pas notée.</div>";
		} else if ( "skipped" === p.crawl_state ) {
			var why = "not_html" === snap.unanalysable
				? "Cette URL sert un fichier (" + ( snap.content_type || "type inconnu" ) + "), pas une page HTML : elle n'est pas notée. Excluez-la de l'audit si elle n'a pas à y figurer."
				: ( "dom_missing" === snap.unanalysable
					? "L'extension PHP DOM (php-xml) n'est pas installée sur le serveur : aucune page ne peut être analysée."
					: "Le contenu reçu n'est pas exploitable : la page n'est pas notée." );
			unanalysable = '<div class="cu-seo-jswarn">' + cu.icon( "info" ) + " Non analysable — " + escapeHtml( why ) + "</div>";
		}

		// Présence observée dans Google (Serper) — indicatif, hors score.
		var indexLine = "";
		var ix = d.index || null;
		if ( ix && ix.status && "unchecked" !== ix.status ) {
			indexLine = '<div class="cu-seo-psi-meta">' + cu.icon( "search" ) + " Google (via Serper) : " + indexStatusText( ix ) +
				( ix.checked ? " · vérifié le " + escapeHtml( siteDate( ix.checked ) ) : "" ) +
				"<em>Indicatif : ce n'est pas Search Console, et cela n'entre dans aucun score.</em></div>";
		}

		// Anomalies groupées.
		var groups = { error: [], warning: [], notice: [] };
		( d.issues || [] ).forEach( function ( i ) { if ( groups[ i.severity ] ) groups[ i.severity ].push( i ); } );
		function relBadge( i ) {
			if ( ! i.source && ! i.basis ) return "";
			var b = BASIS[ i.basis ] || null;
			return '<div class="cu-seo-issue-src">' + ( b ? dot( b.dot ) + " " : "" ) + escapeHtml( i.source || "" ) + ( b ? " · " + escapeHtml( b.label ) : "" ) + "</div>";
		}
		function issueBlock( sev, label, cls ) {
			if ( ! groups[ sev ].length ) return "";
			return '<div class="cu-seo-igroup"><div class="cu-seo-igroup-h ' + cls + '">' + label + " (" + groups[ sev ].length + ")</div>" +
				groups[ sev ].map( function ( i ) {
					return '<div class="cu-seo-issue"><div class="cu-seo-issue-msg">' + escapeHtml( i.message ) + "</div>" +
						( i.detail ? '<div class="cu-seo-issue-detail">' + escapeHtml( i.detail ) + "</div>" : "" ) +
						relBadge( i ) + "</div>";
				} ).join( "" ) + "</div>";
		}
		var issuesHtml = issueBlock( "error", "Erreurs", "is-error" ) + issueBlock( "warning", "Alertes", "is-warn" ) + issueBlock( "notice", "Recommandations", "is-notice" );
		if ( ! issuesHtml ) issuesHtml = '<div class="cu-empty">Aucune anomalie sur cette page.</div>';

		// Trafic réel (notre Analytics) — donnée mesurée, pas estimée.
		var tr = d.traffic || {};
		var trafficBlock = "";
		if ( tr.visits != null || tr.views != null ) {
			trafficBlock = '<div class="cu-seo-traffic"><span class="cu-seo-traffic-label">' + cu.icon( "chart" ) + ' Trafic réel · 30 j <em>(CyberPush Analytics)</em></span>' +
				'<div class="cu-seo-traffic-vals"><div><b>' + cu.formatNumber( tr.visits || 0 ) + "</b><span>visites</span></div>" +
				'<div><b>' + cu.formatNumber( tr.views || 0 ) + "</b><span>pages vues</span></div></div></div>";
		}

		// Lisibilité (dimension séparée, comme Yoast/AIOSEO) — indicatif, jamais un facteur Google.
		var rd = snap.readability || null;
		var readBlock = "";
		if ( rd && rd.available ) {
			readBlock = '<div class="cu-seo-traffic"><span class="cu-seo-traffic-label">' + cu.icon( "book" ) + ' Lisibilité <em>(indicatif — pas un facteur de classement Google)</em></span>' +
				'<div class="cu-seo-traffic-vals">' +
				'<div><b>' + ( rd.score != null ? num( rd.score ) : "—" ) + "</b><span>/ 100</span></div>" +
				'<div><b>' + ( rd.flesch != null ? num( rd.flesch ) : "—" ) + "</b><span>indice de lecture</span></div>" +
				'<div><b>' + ( rd.avg_sentence != null ? num( rd.avg_sentence ) : "—" ) + "</b><span>mots / phrase</span></div>" +
				'<div><b>' + ( rd.long_sentence_pct != null ? num( rd.long_sentence_pct ) : 0 ) + "%</b><span>phrases longues</span></div>" +
				"</div></div>";
		} else if ( rd && rd.available === false ) {
			readBlock = "language" === rd.reason
				? '<div class="cu-seo-jswarn">' + cu.icon( "book" ) + ' Lisibilité : non évaluée pour une langue écrite sans espaces entre les mots (chinois, japonais, thaï…).</div>'
				: '<div class="cu-seo-jswarn">' + cu.icon( "book" ) + ' Lisibilité : texte trop court pour une analyse fiable (≥ 120 mots requis).</div>';
		}

		// Faits techniques.
		var scopeLabel = { main: "zone <main>", role_main: "zone principale", article: "article", entry_content: "contenu de l'article", elementor: "contenu Elementor", body: "page hors menus, en-tête, pied de page" };
		var facts = '<div class="cu-seo-facts">' +
			fact( "Mots (contenu principal)", cu.formatNumber( snap.word_count || 0 ) + ( snap.word_count_page ? " / " + cu.formatNumber( snap.word_count_page ) + " sur la page" : "" ) ) +
			( snap.content_scope ? fact( "Zone analysée", scopeLabel[ snap.content_scope ] || snap.content_scope ) : "" ) +
			( snap.final_url ? fact( "Redirige vers", snap.final_url ) : "" ) +
			fact( "Images", ( snap.images_total || 0 ) + ( snap.images_missing_alt ? " (" + snap.images_missing_alt + " sans alt)" : "" ) ) +
			fact( "Liens internes", snap.links_internal || 0 ) +
			fact( "Liens externes", snap.links_external || 0 ) +
			fact( "H1", ( snap.h1 || [] ).length ) +
			fact( "Canonical", snap.canonical ? "Oui" : "Non" ) +
			fact( "Robots", snap.robots || "index,follow" ) +
			fact( "Langue", snap.lang || "—" ) +
			fact( "Viewport", snap.viewport ? "Oui" : "Non" ) +
			fact( "Schema", ( snap.schema_types && snap.schema_types.length ) ? snap.schema_types.join( ", " ) : "Aucun" ) +
			fact( "HTTP", snap.http_status || p.http_status || "—" ) +
			fact( "Poids", snap.bytes ? Math.round( snap.bytes / 1024 ) + " Ko" : "—" ) +
			"</div>";
		var jsWarn = snap.js_only ? '<div class="cu-seo-jswarn">' + cu.icon( "alert" ) + ' Contenu majoritairement rendu en JavaScript : l\'audit peut être partiel.</div>' : "";

		// Éditeur mot-clé (uniquement si lié à un post).
		var aiReady = false !== d.ai_configured;
		var aiOffHint = "Aucun fournisseur IA configuré (Réglages → IA)";
		var editor = "";
		if ( p.post_id && Number( p.post_id ) ) {
			var m = d.meta || {};
			// Dernière suggestion IA conservée : ré-affichée, jamais appliquée au site.
			var lastAi = ( m.ai_meta_title || m.ai_meta_description )
				? '<div class="cu-seo-ai-item"><span>Titre proposé</span><b>' + escapeHtml( m.ai_meta_title || "" ) + "</b></div>" +
					'<div class="cu-seo-ai-item"><span>Description proposée</span><b>' + escapeHtml( m.ai_meta_description || "" ) + "</b></div>" +
					'<div class="cu-seo-ai-note">Dernière suggestion IA' + ( m.updated_at ? " (enregistrée le " + escapeHtml( siteDate( m.updated_at ) ) + ")" : "" ) + " — jamais appliquée automatiquement : recopiez-la dans votre éditeur ou extension SEO si elle vous convient.</div>"
				: "";
			editor =
				'<div class="cu-seo-editor">' +
					'<div class="cu-section-title">Optimisation</div>' +
					'<div class="cu-field"><label>Mot-clé cible</label><input type="text" class="cu-input" id="cu-seo-kw" value="' + escapeHtml( m.target_keyword || "" ) + '" placeholder="ex: référencement naturel"></div>' +
					'<div class="cu-field"><label>Notes</label><textarea class="cu-textarea" id="cu-seo-notes" rows="2">' + escapeHtml( m.notes || "" ) + "</textarea></div>" +
					'<div class="cu-seo-editor-actions">' +
						'<button class="cu-btn cu-btn-primary cu-btn-sm" id="cu-seo-save-meta">Enregistrer &amp; ré-auditer</button>' +
						'<button class="cu-btn cu-btn-sm" id="cu-seo-ai"' + ( aiReady ? "" : ' disabled title="' + escapeHtml( aiOffHint ) + '"' ) + ">" + cu.icon( "sparkles" ) + ' Suggérer title + meta (IA)</button>' +
					"</div>" +
					( aiReady ? "" : '<div class="cu-seo-ai-note">' + escapeHtml( aiOffHint ) + " : suggestions IA indisponibles.</div>" ) +
					'<div class="cu-seo-ai-out" id="cu-seo-ai-out"' + ( lastAi ? "" : " hidden" ) + ">" + lastAi + "</div>" +
				"</div>";
		}

		// Actions.
		var psiInfo = d.psi || {};
		var psiReady = false !== psiInfo.ready;
		var actions = '<div class="cu-seo-detail-actions">' +
			'<button class="cu-btn cu-btn-sm" id="cu-seo-reaudit">↻ Ré-analyser</button>' +
			'<button class="cu-btn cu-btn-sm" id="cu-seo-psi"' + ( psiReady ? ' title="PageSpeed Insights (' + escapeHtml( strategyLabel[ psiInfo.strategy ] || "mobile" ) + "). Un résultat de moins de " + num( psiInfo.cache_days || 7 ) + ' jours est réutilisé si la page n\'a pas changé."' : ' disabled title="Activez PageSpeed Insights et renseignez la clé dans Réglages → SEO"' ) + ">" + cu.icon( "bolt" ) + ( perf ? " Re-mesurer la vitesse" : " Mesurer la vitesse" ) + "</button>" +
			'<a class="cu-btn cu-btn-sm" href="' + escapeHtml( p.url ) + '" target="_blank" rel="noopener">Voir la page ↗</a>' +
			( d.edit_link ? '<a class="cu-btn cu-btn-sm" href="' + escapeHtml( d.edit_link ) + '" target="_blank" rel="noopener">Éditer ↗</a>' : "" ) +
			"</div>";

		// Bloc « consultant IA ».
		var aiInsight =
			'<div class="cu-seo-insight-wrap">' +
				'<button class="cu-btn cu-btn-primary cu-btn-sm" id="cu-seo-insight-btn"' + ( aiReady ? "" : ' disabled title="' + escapeHtml( aiOffHint ) + '"' ) + ">" + cu.icon( "brain" ) + ' Analyse IA : quoi corriger en premier</button>' +
				( aiReady ? "" : '<span class="cu-seo-ai-note"> ' + escapeHtml( aiOffHint ) + ".</span>" ) +
				'<div class="cu-seo-insight" id="cu-seo-insight" hidden></div>' +
			"</div>";

		var checklists =
			'<div class="cu-seo-checks-grid">' +
				renderChecklist( "Confiance (E-E-A-T)", d.eeat, "Signaux d'expertise, d'autorité et de fiabilité valorisés par Google." ) +
				renderChecklist( "AI-Readiness", d.ai_checklist, "Prêt à être compris et cité par ChatGPT, Copilot, Gemini, AI Overview." ) +
			"</div>";

		var html =
			'<div class="cu-seo-detail" data-id="' + num( p.id ) + '" data-post="' + num( p.post_id ) + '">' +
				'<h2>' + escapeHtml( snap.title || p.title || p.url ) + "</h2>" +
				// Ordre de lecture : ce qui ne va pas (diagnostic) avant les outils d'édition.
				unanalysable + serp + scoreRow + verdictBlock + trafficBlock + readBlock + cwv + indexLine + jsWarn + actions +
				'<div class="cu-section-title">Diagnostic</div>' + issuesHtml +
				aiInsight + editor +
				'<div class="cu-section-title">Confiance & IA</div>' + checklists +
				'<div class="cu-section-title">Rendu final analysé</div>' + facts +
			"</div>";

		var modal = cu.openModal( html );
		bindDetail( modal, p );
	}

	function fact( label, value ) {
		return '<div class="cu-seo-fact"><span>' + label + "</span><b>" + escapeHtml( value ) + "</b></div>";
	}

	function renderChecklist( title, obj, hint ) {
		if ( ! obj || ! obj.checks ) return "";
		var rows = obj.checks.map( function ( c ) {
			return '<div class="cu-seo-check ' + ( c.ok ? "is-ok" : "is-ko" ) + '"><span>' + ( c.ok ? "✓" : "✗" ) + "</span>" + escapeHtml( c.label ) + "</div>";
		} ).join( "" );
		return '<div class="cu-seo-checklist">' +
			'<div class="cu-seo-check-head"><b>' + escapeHtml( title ) + '</b><span style="color:' + scoreColor( num( obj.score ) ) + '">' + num( obj.score ) + "%</span></div>" +
			'<div class="cu-seo-check-hint">' + escapeHtml( hint ) + "</div>" + rows +
			"</div>";
	}

	function bindDetail( modal, p ) {
		var reaudit = modal.querySelector( "#cu-seo-reaudit" );
		if ( reaudit ) reaudit.addEventListener( "click", function () {
			reaudit.disabled = true;
			apiWhenFree( "seo/audit-page", { method: "POST", body: { id: p.id } }, function () { reaudit.textContent = "En attente d'une autre analyse…"; } )
				.then( function () { modal.remove(); openDetail( p.id ); loadOverview(); loadPages(); cu.toast( "Page ré-analysée.", "success" ); } )
				.catch( function ( e ) { cu.toast( e.message, "error" ); reaudit.disabled = false; reaudit.textContent = "↻ Ré-analyser"; } );
		} );

		// Vitesse : 1er clic = réutilise une mesure récente (page inchangée) ;
		// le bouton propose alors de forcer une nouvelle mesure.
		var psi = modal.querySelector( "#cu-seo-psi" );
		var psiForce = false;
		if ( psi ) psi.addEventListener( "click", function () {
			var label = psi.innerHTML;
			psi.disabled = true; psi.innerHTML = cu.icon( "bolt" ) + " Mesure… (jusqu'à 45 s)";
			apiFetch( "seo/psi", { method: "POST", body: { id: p.id, force: psiForce ? 1 : 0 } } )
				.then( function ( res ) {
					if ( res && res.cached ) {
						psiForce = true;
						psi.disabled = false;
						psi.innerHTML = cu.icon( "bolt" ) + " Forcer une nouvelle mesure";
						cu.toast( "Mesure du " + siteDate( res.cwv && res.cwv.checked ) + " réutilisée (moins de " + num( res.cache_days ) + " jours, page inchangée). Cliquez à nouveau pour forcer.", "info" );
						return;
					}
					if ( res && res.busy ) {
						// Mesure déjà en cours pour cette page : aucun second appel facturé.
						cu.toast( res.message || "Une mesure de cette page est déjà en cours.", "info" );
						psi.disabled = false; psi.innerHTML = label;
						return;
					}
					if ( res && res.unavailable ) {
						cu.toast( ( res.message || "Mesure indisponible." ) + " — ce n'est pas un problème SEO.", "info" );
						modal.remove(); openDetail( p.id );
						return;
					}
					modal.remove(); openDetail( p.id ); loadPages(); loadOverview(); cu.toast( "Vitesse mesurée.", "success" );
				} )
				.catch( function ( e ) { cu.toast( e.message, "error" ); psi.disabled = false; psi.innerHTML = label; } );
		} );

		var insightBtn = modal.querySelector( "#cu-seo-insight-btn" );
		if ( insightBtn ) insightBtn.addEventListener( "click", function () {
			insightBtn.disabled = true; insightBtn.innerHTML = cu.icon( "brain" ) + " Analyse en cours…";
			var out = modal.querySelector( "#cu-seo-insight" );
			apiFetch( "seo/ai-insight", { method: "POST", body: { id: p.id } } )
				.then( function ( res ) {
					insightBtn.disabled = !! ( res && res.not_configured );
					insightBtn.innerHTML = cu.icon( "brain" ) + " Relancer l'analyse IA";
					if ( ! res.success ) { cu.toast( res.message || "Échec IA (vérifiez la clé dans Réglages > IA).", res.not_configured ? "info" : "error" ); return; }
					out.hidden = false;
					out.innerHTML = insightToHtml( res.insight ) +
						'<div class="cu-seo-ai-note">Interprétation IA des données de l\'audit — sans chiffre de gain ; à vérifier avant d\'agir.</div>';
				} )
				.catch( function ( e ) { insightBtn.disabled = false; insightBtn.innerHTML = cu.icon( "brain" ) + " Analyse IA : quoi corriger en premier"; cu.toast( e.message, "error" ); } );
		} );

		var saveMeta = modal.querySelector( "#cu-seo-save-meta" );
		if ( saveMeta ) saveMeta.addEventListener( "click", function () {
			saveMeta.disabled = true;
			apiFetch( "seo/meta", { method: "POST", body: {
				post_id: p.post_id,
				target_keyword: modal.querySelector( "#cu-seo-kw" ).value,
				notes: modal.querySelector( "#cu-seo-notes" ).value,
			} } )
				.then( function ( res ) {
					modal.remove(); openDetail( p.id ); loadOverview(); loadPages();
					cu.toast( res && res.message ? res.message : ( res && false === res.reaudited ? "Enregistré." : "Enregistré et ré-audité." ), "success" );
				} )
				.catch( function ( e ) { cu.toast( e.message, "error" ); saveMeta.disabled = false; } );
		} );

		var ai = modal.querySelector( "#cu-seo-ai" );
		if ( ai ) ai.addEventListener( "click", function () {
			ai.disabled = true; ai.innerHTML = cu.icon( "sparkles" ) + " Génération…";
			var out = modal.querySelector( "#cu-seo-ai-out" );
			apiFetch( "seo/ai-meta", { method: "POST", body: { id: p.id } } )
				.then( function ( res ) {
					ai.disabled = !! ( res && res.not_configured );
					ai.innerHTML = cu.icon( "sparkles" ) + " Suggérer title + meta (IA)";
					if ( ! res.success ) { cu.toast( res.message || "Échec IA.", res.not_configured ? "info" : "error" ); return; }
					out.hidden = false;
					out.innerHTML =
						'<div class="cu-seo-ai-item"><span>Titre proposé</span><b>' + escapeHtml( res.meta_title ) + "</b></div>" +
						'<div class="cu-seo-ai-item"><span>Description proposée</span><b>' + escapeHtml( res.meta_description ) + "</b></div>" +
						( res.notes ? '<div class="cu-seo-ai-note">' + escapeHtml( res.notes ) + "</div>" : "" ) +
						'<div class="cu-seo-ai-note">Suggestion IA' + ( res.based_on ? " basée sur le rendu du " + escapeHtml( siteDate( res.based_on ) ) : "" ) + " — rien n'est modifié sur le site.</div>";
				} )
				.catch( function ( e ) { ai.disabled = false; ai.innerHTML = cu.icon( "sparkles" ) + " Suggérer title + meta (IA)"; cu.toast( e.message, "error" ); } );
		} );
	}

	// =====================================================================
	// Résumé IA « État de votre site » (généré à la demande, mis en cache)
	// =====================================================================
	function loadAiSummary() {
		apiFetch( "seo/ai-summary" )
			.then( function ( res ) { if ( res.has_data ) renderSummary( res.summary ); } )
			.catch( function () {} );
	}

	function genAiSummary() {
		var btn = $( "cu-seo-summary-gen" );
		btn.disabled = true;
		var body = $( "cu-seo-summary-body" );
		body.innerHTML = '<div class="cu-loading"><span class="cu-spinner"></span> L\'IA analyse vos données réelles…</div>';
		apiFetch( "seo/ai-summary", { method: "POST" } )
			.then( function ( res ) {
				btn.disabled = false;
				if ( ! res.success ) { body.innerHTML = '<div class="cu-empty">' + escapeHtml( res.message || "Échec (vérifiez la clé IA dans Réglages > IA)." ) + "</div>"; return; }
				renderSummary( res );
			} )
			.catch( function ( e ) { btn.disabled = false; cu.toast( e.message, "error" ); body.innerHTML = '<div class="cu-empty">' + escapeHtml( e.message ) + "</div>"; } );
	}

	function renderSummary( s ) {
		var body = $( "cu-seo-summary-body" );
		if ( ! body ) return;
		$( "cu-seo-summary-gen" ).querySelector( "span" ).textContent = "Régénérer";
		body.innerHTML = '<div class="cu-seo-summary-text">' + insightToHtml( s.text ) + "</div>" +
			'<div class="cu-seo-summary-foot">' + dot( "yellow" ) + ' Analyse IA — interprétation de vos données réelles, sans chiffre inventé · ' + ( s.generated_at ? relTime( s.generated_at ) : "" ) + "</div>";
	}

	// =====================================================================
	// Audit technique du site (transverse, persisté)
	// =====================================================================
	var saData = null;

	function loadSiteAudit() {
		apiFetch( "seo/site-audit" )
			.then( function ( res ) {
				if ( res.has_data ) { saData = res.audit; renderSiteAudit( saData ); }
			} )
			.catch( function ( e ) {
				// Ne pas laisser croire qu'aucun audit n'existe : le chargement a échoué.
				var body = $( "cu-seo-sa-body" );
				if ( body && ! saData ) body.innerHTML = '<div class="cu-empty">' + escapeHtml( e.message || "Erreur de chargement." ) + "</div>";
			} );
	}

	function runSiteAudit() {
		// Un seul audit à la fois (le bouton et la fin d'une analyse complète
		// peuvent le déclencher en même temps).
		if ( state.auditing ) return;
		state.auditing = true;
		var btn = $( "cu-seo-sa-run" );
		btn.disabled = true;
		var body = $( "cu-seo-sa-body" );
		var waitMsg = function () { body.innerHTML = '<div class="cu-loading"><span class="cu-spinner"></span> En attente de la fin d\'une autre analyse SEO…</div>'; };
		body.innerHTML = '<div class="cu-loading"><span class="cu-spinner"></span> Vérification des liens…</div>';
		drainLinks( 0, 0, function () {
			body.innerHTML = '<div class="cu-loading"><span class="cu-spinner"></span> Analyse transverse (orphelines, doublons, profondeur, sitemap, robots.txt)…</div>';
			apiWhenFree( "seo/site-audit", { method: "POST" }, waitMsg )
				.then( function ( res ) {
					state.auditing = false;
					btn.disabled = false;
					if ( ! res.success ) { body.innerHTML = '<div class="cu-empty">' + escapeHtml( res.message || "Rien à analyser." ) + "</div>"; return; }
					saData = res; renderSiteAudit( res );
					loadOverview(); // rafraîchit maillage + recommandations avec les données transverses
					cu.toast( res.partial ? "Audit technique terminé (partiel : voir le détail)." : "Audit technique terminé.", res.partial ? "info" : "success" );
				} )
				.catch( function ( e ) { state.auditing = false; btn.disabled = false; cu.toast( e.message, "error" ); body.innerHTML = '<div class="cu-empty">' + escapeHtml( e.message ) + "</div>"; } );
		}, waitMsg );
	}

	// Contrôle des liens par lots (≤ 40 s chacun côté serveur) tant qu'il en reste.
	function drainLinks( guard, checkedSoFar, done, onWait ) {
		if ( guard > 400 ) { done(); return; }
		apiWhenFree( "seo/check-links", { method: "POST", body: { limit: 15 } }, onWait )
			.then( function ( res ) {
				var checked = checkedSoFar + num( res.checked );
				var body = $( "cu-seo-sa-body" );
				if ( body && num( res.remaining ) > 0 ) {
					body.innerHTML = '<div class="cu-loading"><span class="cu-spinner"></span> Vérification des liens… ' + cu.formatNumber( checked ) + " vérifié(s), " + cu.formatNumber( num( res.remaining ) ) + " restant(s)</div>";
				}
				if ( num( res.remaining ) > 0 && num( res.checked ) > 0 ) { drainLinks( guard + 1, checked, done, onWait ); }
				else { done(); }
			} )
			.catch( function () { done(); } );
	}

	function renderSiteAudit( a ) {
		$( "cu-seo-sa-time" ).textContent = a.generated_at ? "Audit " + relTime( a.generated_at ) : "";

		function tile( id, label, value, cls, icon ) {
			// null = non calculé (audit partiel) : « n/d », jamais un faux 0.
			if ( value == null ) {
				return '<div class="cu-seo-sa-tile cu-seo-sa-static is-na">' +
					'<span class="cu-seo-sa-ico">' + cu.icon( icon ) + "</span><b>n/d</b>" +
					'<span class="cu-seo-sa-label">' + label + "</span>" +
					'<em class="cu-seo-sa-hint">non calculé (audit partiel)</em></div>';
			}
			var n = num( value );
			var hint = n ? ( "is-bad" === cls ? "à corriger" : "à vérifier" ) : "aucun problème";
			return '<button type="button" class="cu-seo-sa-tile ' + ( cls || "" ) + '" data-list="' + id + '" aria-pressed="false">' +
				'<span class="cu-seo-sa-ico">' + cu.icon( icon ) + "</span>" +
				"<b>" + cu.formatNumber( n ) + "</b>" +
				'<span class="cu-seo-sa-label">' + label + "</span>" +
				'<em class="cu-seo-sa-hint">' + hint + "</em></button>";
		}
		function staticTile( label, value, cls, icon, hint ) {
			return '<div class="cu-seo-sa-tile cu-seo-sa-static ' + cls + '">' +
				'<span class="cu-seo-sa-ico">' + cu.icon( icon ) + "</span>" +
				"<b>" + value + "</b>" +
				'<span class="cu-seo-sa-label">' + label + "</span>" +
				'<em class="cu-seo-sa-hint">' + escapeHtml( hint ) + "</em></div>";
		}
		var sm = a.sitemap || {}, rb = a.robots || {};
		var smVal = sm.found ? "✓" : "✗", smCls = sm.found ? "is-ok" : "is-bad";
		var smHint = ! sm.found ? "introuvable"
			: ( "index" === sm.type ? plural( num( sm.entries ), "sous-sitemap", "sous-sitemaps" ) + " (index)" : plural( num( sm.entries ), "URL", "URL" ) );
		var rbErr = ! rb.found && rb.error;
		var rbVal = rb.found ? ( rb.blocks_all ? "!" : "✓" ) : ( rbErr ? "?" : "✗" );
		var rbCls = rb.found ? ( rb.blocks_all ? "is-bad" : "is-ok" ) : ( rbErr ? "is-warn" : "is-warn" );
		var rbHint = rb.found
			? ( rb.blocks_google ? "bloque Googlebot" : ( rb.blocks_generic ? "bloque les robots génériques" : ( rb.blocked && rb.blocked.length ? "bloque certaines pages" : "n'empêche pas l'exploration" ) ) )
			: ( "server" === rb.error ? "erreur serveur " + num( rb.status ) : ( "unreachable" === rb.error ? "vérification impossible" : "absent (aucune restriction)" ) );

		// Pages non lues (erreur) : les orphelines ne sont que « probables ».
		var orphanTile = a.orphans_uncertain
			? '<button type="button" class="cu-seo-sa-tile is-na" data-list="orphans" aria-pressed="false">' +
				'<span class="cu-seo-sa-ico">' + cu.icon( "unlink" ) + "</span><b>~" + cu.formatNumber( num( a.orphans_candidates ) ) + "</b>" +
				'<span class="cu-seo-sa-label">Pages orphelines</span><em class="cu-seo-sa-hint">à confirmer (pages non lues)</em></button>'
			: tile( "orphans", "Pages orphelines", a.orphans_count, a.orphans_count ? "is-warn" : "is-ok", "unlink" );
		var tiles =
			orphanTile +
			tile( "broken", "Liens cassés", a.broken_count, a.broken_count ? "is-bad" : "is-ok", "alert" ) +
			tile( "dup_titles", "Titres dupliqués", a.dup_titles_count, a.dup_titles_count ? "is-warn" : "is-ok", "copy" ) +
			tile( "dup_metas", "Metas dupliquées", a.dup_metas_count, a.dup_metas_count ? "is-warn" : "is-ok", "tag" ) +
			tile( "deep", "Trop profondes", a.deep_count, a.deep_count ? "is-warn" : "is-ok", "layers" ) +
			staticTile( "Sitemap", smVal, smCls, "map", smHint ) +
			staticTile( "robots.txt", rbVal, rbCls, "bot", rbHint );

		var notes = [];
		if ( a.partial ) notes.push( '<div class="cu-seo-jswarn">' + cu.icon( "alert" ) + " Audit partiel — " + escapeHtml( a.partial_reason || "graphe de liens trop volumineux." ) + "</div>" );
		if ( a.incomplete && ! a.partial ) notes.push( '<div class="cu-seo-jswarn">' + cu.icon( "alert" ) + " Audit incomplet — " + escapeHtml( a.incomplete_reason || "des pages n'ont pas pu être lues." ) + "</div>" );
		if ( num( a.uncrawled ) > 0 && ! a.partial ) notes.push( '<div class="cu-seo-scorehint" style="text-align:left">' + cu.icon( "info" ) + " " + plural( num( a.uncrawled ), "page n'a", "pages n'ont" ) + " pas encore été analysée(s) : leurs liens ne sont pas encore connus, le nombre de pages orphelines peut être surestimé.</div>" );
		if ( rb.found && rb.blocked && rb.blocked.length ) {
			notes.push( '<div class="cu-seo-jswarn">' + cu.icon( "alert" ) + " robots.txt bloque l'exploration : " + rb.blocked.map( function ( b ) {
				return escapeHtml( b.path ) + " pour " + escapeHtml( b.agent ) + " (règle « " + escapeHtml( b.rule ) + " »)";
			} ).join( " ; " ) + ".</div>" );
		} else if ( "server" === rb.error ) {
			notes.push( '<div class="cu-seo-jswarn">' + cu.icon( "alert" ) + " robots.txt répond par une erreur serveur (HTTP " + num( rb.status ) + ") : Google suspend l'exploration du site tant que cela dure.</div>" );
		} else if ( "unreachable" === rb.error ) {
			notes.push( '<div class="cu-seo-scorehint" style="text-align:left">' + cu.icon( "info" ) + " robots.txt : vérification impossible (pas de réponse du serveur). Ce n'est pas un constat SEO.</div>" );
		} else if ( rb.found && ! rb.has_sitemap ) {
			notes.push( '<div class="cu-seo-scorehint" style="text-align:left">' + cu.icon( "info" ) + " Astuce : ajoutez une directive « Sitemap: » dans robots.txt.</div>" );
		}
		if ( sm.found && sm.url ) notes.push( '<div class="cu-seo-scorehint" style="text-align:left">Sitemap lu : <a href="' + escapeHtml( sm.url ) + '" target="_blank" rel="noopener">' + escapeHtml( sm.url ) + "</a>" + ( sm.truncated ? " (fichier tronqué à 5 Mo : décompte partiel)" : "" ) + "</div>" );
		else if ( sm.rejected && sm.rejected.length ) notes.push( '<div class="cu-seo-scorehint" style="text-align:left">Réponse non XML ignorée pour : ' + sm.rejected.map( escapeHtml ).join( ", " ) + "</div>" );

		$( "cu-seo-sa-body" ).innerHTML =
			'<div class="cu-seo-sa-tiles">' + tiles + "</div>" + notes.join( "" ) +
			'<div class="cu-seo-sa-detail" id="cu-seo-sa-detail"></div>';

		var saTiles = document.querySelectorAll( "#cu-seo-sa-body .cu-seo-sa-tile[data-list]" );
		saTiles.forEach( function ( t ) {
			t.addEventListener( "click", function () {
				saTiles.forEach( function ( other ) { other.setAttribute( "aria-pressed", other === t ? "true" : "false" ); } );
				showSaList( t.dataset.list );
			} );
		} );
	}

	function showSaList( which ) {
		var box = $( "cu-seo-sa-detail" );
		if ( ! saData || ! box ) return;
		var html = "";
		if ( "orphans" === which ) {
			html = saList( saData.orphans_uncertain ? "Orphelines PROBABLES — à confirmer (des pages n'ont pas pu être lues)" : "Pages orphelines (aucun lien interne entrant)", ( saData.orphans || [] ).map( function ( o ) {
				return '<a href="' + escapeHtml( o.url ) + '" target="_blank" rel="noopener">' + escapeHtml( o.title || o.url ) + "</a>";
			} ) );
		} else if ( "broken" === which ) {
			html = saList( "Liens cassés", ( saData.broken || [] ).map( function ( b ) {
				return '<span class="cu-badge cu-badge-red">' + ( num( b.http_status ) || "0" ) + '</span> ' + escapeHtml( b.url ) + ' <em>— sur ' + escapeHtml( b.source || "" ) + "</em>";
			} ) );
		} else if ( "dup_titles" === which ) {
			html = saList( "Titres dupliqués", ( saData.dup_titles || [] ).map( function ( d ) {
				return '<span class="cu-badge cu-badge-yellow">×' + num( d.c ) + "</span> " + escapeHtml( d.title );
			} ) );
		} else if ( "dup_metas" === which ) {
			html = saList( "Meta descriptions dupliquées", ( saData.dup_metas || [] ).map( function ( d ) {
				return '<span class="cu-badge cu-badge-yellow">×' + num( d.c ) + "</span> " + escapeHtml( d.meta_desc );
			} ) );
		} else if ( "deep" === which ) {
			html = saList( "Pages trop profondes (max " + num( saData.max_depth ) + " clics)", ( saData.deep_pages || [] ).map( function ( p ) {
				return '<span class="cu-badge cu-badge-purple">' + num( p.depth ) + " clics</span> " + escapeHtml( p.url );
			} ) );
		}
		box.innerHTML = html || '<div class="cu-empty">Rien à afficher ici.</div>';
	}

	function saList( title, items ) {
		if ( ! items.length ) return '<div class="cu-seo-sa-list"><h4>' + escapeHtml( title ) + '</h4><div class="cu-empty">Aucun élément.</div></div>';
		return '<div class="cu-seo-sa-list"><h4>' + escapeHtml( title ) + " (" + items.length + ")</h4>" +
			items.map( function ( i ) { return '<div class="cu-seo-sa-item">' + i + "</div>"; } ).join( "" ) + "</div>";
	}

	// =====================================================================
	// Analyse complète (crawl par lots reprenables)
	// =====================================================================
	function runFullScan() {
		if ( state.scanning ) return;
		state.scanning = true;
		var btn = $( "cu-seo-scan" );
		btn.disabled = true;
		var progress = $( "cu-seo-progress" );
		progress.hidden = false;
		setProgress( 0, "Découverte des URL…", "" );
		var waiting = function () { setProgress( 0, "En attente de la fin d'une autre analyse SEO (tâche planifiée ou autre onglet)…", "" ); };

		apiWhenFree( "seo/crawl", { method: "POST", body: { mode: "discover" } }, waiting )
			.then( function ( res ) {
				var total = num( res.total );
				if ( num( res.removed ) > 0 ) cu.toast( plural( num( res.removed ), "URL retirée", "URL retirées" ) + " de l'inventaire (plus publiées ou exclues du suivi).", "info" );
				if ( num( res.too_long ) > 0 ) cu.toast( plural( num( res.too_long ), "URL ignorée", "URL ignorées" ) + " : plus de 500 caractères (voir Logs).", "info" );
				if ( ! total ) {
					finishScan( "Aucune URL à analyser." );
					return;
				}
				loopBatch( total, 0 );
			} )
			.catch( function ( e ) { cu.toast( e.message, "error" ); finishScan(); } );
	}

	function loopBatch( total, guard ) {
		if ( guard > 500 ) { finishScan( "Analyse interrompue (trop de lots)." ); return; }
		var waiting = function () { setProgress( 0, "En attente de la fin d'une autre analyse SEO…", "" ); };
		apiWhenFree( "seo/crawl", { method: "POST", body: { mode: "batch", limit: 0 } }, waiting )
			.then( function ( res ) {
				var done = ( res.total || total ) - ( res.pending || 0 );
				var t = res.total || total;
				var pct = t ? Math.round( ( done / t ) * 100 ) : 100;
				setProgress( pct, "Analyse des pages…", done + " / " + t );
				// Aperçu = agrégats SQL lourds : un rafraîchissement tous les 5 lots
				// suffit (finishScan le recharge de toute façon à la fin).
				if ( 0 === guard % 5 ) loadOverview();
				if ( res.pending > 0 && res.processed > 0 ) {
					loopBatch( t, guard + 1 );
				} else {
					// Lot sans progression alors qu'il reste des pages : ne pas
					// annoncer « terminée » (pages inaccessibles, délai serveur…).
					finishScan( res.pending > 0
						? "Analyse arrêtée : " + res.pending + " page(s) n'ont pas pu être analysées (voir la page Logs)."
						: "Analyse terminée : " + t + " page(s)." );
					loadPages();
					runSiteAudit(); // enchaîne l'audit technique transverse
				}
			} )
			.catch( function ( e ) { cu.toast( e.message, "error" ); finishScan(); } );
	}

	function setProgress( pct, label, count ) {
		$( "cu-seo-progress-fill" ).style.width = pct + "%";
		$( "cu-seo-progress-label" ).textContent = label;
		$( "cu-seo-progress-count" ).textContent = count;
	}

	function finishScan( message ) {
		state.scanning = false;
		$( "cu-seo-scan" ).disabled = false;
		var progress = $( "cu-seo-progress" );
		setTimeout( function () { progress.hidden = true; }, 1200 );
		if ( message ) cu.toast( message, "success" );
		loadOverview();
		loadIndex(); // rafraîchit la section Indexation : les nouvelles pages apparaissent en « À vérifier »
	}

	// =====================================================================
	// Export / purge
	// =====================================================================
	function exportCsv() {
		var url = cu.restUrl( "seo/export" );
		var rows = null;
		cu.toast( "Préparation de l'export (toutes les anomalies)…", "info" );
		fetch( url, { headers: { "X-WP-Nonce": cu.config.nonce }, credentials: "same-origin" } )
			.then( function ( r ) {
				// Une erreur REST (403/409/500) renvoie du JSON : ne jamais la
				// télécharger comme s'il s'agissait d'un CSV valide.
				var type = r.headers.get( "content-type" ) || "";
				if ( ! r.ok || -1 === type.indexOf( "text/csv" ) ) throw new Error( "Export HTTP " + r.status );
				rows = r.headers.get( "X-CU-Export-Rows" );
				return r.blob();
			} )
			.then( function ( blob ) {
				var a = document.createElement( "a" );
				var objectUrl = URL.createObjectURL( blob );
				a.href = objectUrl;
				a.download = "cyberpush-seo-audit.csv";
				document.body.appendChild( a ); a.click(); a.remove();
				URL.revokeObjectURL( objectUrl );
				if ( null !== rows && "" !== rows ) cu.toast( "Export complet : " + cu.formatNumber( num( rows ) ) + " ligne(s) d'anomalies (pages ayant au moins une anomalie).", "success" );
			} )
			.catch( function ( e ) { cu.toast( "Export impossible" + ( e && /^Export HTTP/.test( e.message || "" ) ? " (" + e.message + ")." : " : le serveur ne répond pas." ), "error" ); } );
	}

	function purge() {
		if ( ! cu.confirmAction( "Vider tout l'audit SEO (inventaire, anomalies, liens, historique) ? Les réglages sont conservés." ) ) return;
		apiFetch( "seo/purge", { method: "POST" } )
			.then( function ( res ) {
				if ( res && res.busy ) { cu.toast( "Impossible de vider l'audit pendant une analyse en cours. Réessayez dans un instant.", "error" ); return; }
				cu.toast( "Audit SEO vidé.", "success" );
				// Toutes les sections reflètent l'état vidé (et non d'anciennes données).
				saData = null;
				$( "cu-seo-sa-time" ).textContent = "";
				$( "cu-seo-sa-body" ).innerHTML = '<div class="cu-empty">Aucun audit technique encore. Cliquez « Lancer l\'audit technique ».</div>';
				state.page = 1;
				indexState.page = 1;
				loadOverview();
				loadPages();
				loadIndex();
				// Le résumé IA est supprimé côté serveur : retirer l'ancien texte affiché.
				$( "cu-seo-summary-gen" ).querySelector( "span" ).textContent = "Générer le résumé";
				$( "cu-seo-summary-body" ).innerHTML = '<p class="cu-seo-scorehint" style="text-align:left;margin:8px 0 0;">L\'IA lit vos données réelles et vous explique la situation + un plan d\'action pour la semaine. Elle n\'invente aucun chiffre.</p>';
			} )
			.catch( function ( e ) { cu.toast( e.message, "error" ); } );
	}

	// =====================================================================
	// Présence observée dans Google (API Serper.dev) — indicatif, hors score
	// =====================================================================
	// Statuts : found (observée dans les résultats), not_found (non trouvée dans
	// les résultats interrogés — PAS « désindexée »), error (vérification
	// impossible : jamais un problème SEO), legacy (ancienne méthode imprécise,
	// à revérifier), unchecked (jamais vérifiée).
	var indexState = { running: false, page: 1, filter: "" };

	var INDEX_STATUS = {
		found:     { cls: "cu-badge-green", label: "Observée dans Google" },
		not_found: { cls: "cu-badge-yellow", label: "Non trouvée dans les résultats" },
		error:     { cls: "cu-badge-gray", label: "Vérification impossible" },
		legacy:    { cls: "cu-badge-gray", label: "Ancien résultat — à revérifier" },
		unchecked: { cls: "cu-badge-gray", label: "Jamais vérifiée" },
	};

	// Texte court d'un statut (détail de page).
	function indexStatusText( ix ) {
		var s = INDEX_STATUS[ ix.status ] || INDEX_STATUS.unchecked;
		var out = "<b>" + escapeHtml( s.label ) + "</b>";
		if ( "error" === ix.status && ix.reason_label ) out += " (" + escapeHtml( ix.reason_label ) + ")";
		if ( "not_found" === ix.status && ix.variant ) out += " — une variante apparaît : " + escapeHtml( ix.variant );
		return out;
	}

	function indexStatusBadge( p ) {
		var s = INDEX_STATUS[ p.status ] || INDEX_STATUS.unchecked;
		var tip = "";
		if ( "found" === p.status ) {
			tip = "Adresse exacte présente parmi les résultats Google renvoyés par Serper" + ( p.query ? " pour « " + p.query + " »" : "" ) + ". Ce n'est pas une confirmation Search Console.";
		} else if ( "not_found" === p.status ) {
			tip = "L'adresse exacte ne figurait pas parmi les " + num( p.results ) + " résultat(s) renvoyé(s)" + ( p.query ? " pour « " + p.query + " »" : "" ) + ". Cela ne prouve pas que la page est désindexée.";
		} else if ( "error" === p.status ) {
			tip = "La vérification a échoué (" + ( p.reason_label || "erreur du service" ) + ") : ce n'est pas un problème SEO. Nouvel essai automatique possible après quelques heures.";
		} else if ( "legacy" === p.status ) {
			tip = "Résultat obtenu avec l'ancienne méthode (correspondance par préfixe, imprécise) : relancez une vérification.";
		}
		var extra = "";
		if ( "not_found" === p.status && p.variant ) {
			extra = '<div class="cu-seo-index-note">Variante présente : ' + escapeHtml( p.variant ) + "</div>";
		} else if ( "error" === p.status && p.previous && p.previous.status ) {
			var prev = INDEX_STATUS[ p.previous.status ] || null;
			if ( prev ) extra = '<div class="cu-seo-index-note">Dernière observation : ' + escapeHtml( prev.label.toLowerCase() ) + ( p.previous.checked ? " le " + escapeHtml( siteDate( p.previous.checked ) ) : "" ) + "</div>";
		}
		return '<span class="cu-badge ' + s.cls + '"' + ( tip ? ' title="' + escapeHtml( tip ) + '"' : "" ) + ">" + escapeHtml( s.label ) + "</span>" + extra;
	}

	function indexChip( kind, value, label, title ) {
		return '<div class="cu-seo-index-chip cu-seo-index-chip-' + kind + '"' + ( title ? ' title="' + escapeHtml( title ) + '"' : "" ) + "><b>" + cu.formatNumber( num( value ) ) + "</b><span>" + escapeHtml( label ) + "</span></div>";
	}

	function loadIndex() {
		var q = "seo/index-overview?page=" + indexState.page + "&filter=" + encodeURIComponent( indexState.filter );
		var seq = ++indexSeq;
		apiFetch( q )
			.then( function ( d ) { if ( seq === indexSeq ) renderIndex( d ); } )
			.catch( function ( e ) {
				if ( seq !== indexSeq ) return;
				var b = $( "cu-seo-index-tbody" );
				if ( b ) b.innerHTML = '<tr><td colspan="3" class="cu-empty">' + escapeHtml( e.message || "Erreur." ) + "</td></tr>";
			} );
	}

	function renderIndexBanner( d ) {
		var banner = $( "cu-seo-index-banner" );
		if ( ! banner ) return;
		if ( d.has_key && d.blocked && d.blocked.message ) {
			banner.hidden = false;
			banner.innerHTML = cu.icon( "alert" ) + " <b>Vérification arrêtée" + ( d.blocked.at ? " le " + escapeHtml( siteDate( d.blocked.at ) ) : "" ) + " :</b> " + escapeHtml( d.blocked.message ) +
				( d.auto ? " L'auto-vérification planifiée est suspendue en attendant." : "" );
		} else {
			banner.hidden = true;
			banner.innerHTML = "";
		}
	}

	// Dernier état connu (compteurs) : sert à annoncer le coût avant un passage.
	var lastIndex = null;
	// Au-delà de ce nombre de pages, confirmation explicite avant de consommer des crédits.
	var INDEX_CONFIRM_FROM = 20;

	function renderIndex( d ) {
		lastIndex = d;
		renderIndexBanner( d );
		var ic = $( "cu-seo-index-check" );
		if ( ic && d.has_key ) {
			ic.title = num( d.due ) + " page(s) à vérifier : jusqu'à " + num( d.due ) + " requête(s) (crédits) Serper.dev, 1 par page.";
		}
		var chips = $( "cu-seo-index-chips" );
		if ( chips ) {
			if ( ! d.has_key ) {
				chips.innerHTML = '<div class="cu-seo-index-nokey">' + cu.icon( "key" ) + ' Ajoutez votre clé <b>Serper.dev</b> dans <a href="admin.php?page=cyberpush-ultra-settings">Réglages → SEO</a> pour observer la présence de vos pages dans Google.</div>';
			} else {
				var cache = d.cache || {};
				chips.innerHTML =
					indexChip( "indexed", d.found, "Observées", "Adresse exacte vue dans les résultats Google (via Serper)" ) +
					indexChip( "notfound", d.not_found, "Non trouvées", "Absentes des résultats interrogés — ne prouve pas une désindexation" ) +
					indexChip( "unchecked", d.due, "À vérifier", "Jamais vérifiées ou résultat expiré (observée : " + num( cache.found_days ) + " j, non trouvée : " + num( cache.not_found_days ) + " j, erreur : " + num( cache.error_hours ) + " h)" ) +
					( num( d.errors ) ? indexChip( "error", d.errors, "Vérif. impossible", "Erreur du service, délai dépassé ou réponse invalide : pas un problème SEO" ) : "" ) +
					( num( d.legacy ) ? indexChip( "unchecked", d.legacy, "Ancien résultat", "Méthode précédente (imprécise) : à revérifier" ) : "" ) +
					indexChip( "total", d.total, "Pages concernées", "Pages indexables (sans noindex) qui ne sont ni en erreur HTTP ni des fichiers" );
				if ( d.last_checked ) {
					chips.innerHTML += '<div class="cu-seo-index-last">Dernière vérification : ' + escapeHtml( siteDate( d.last_checked ) ) + " (" + escapeHtml( relTime( d.last_checked ) ) + ") · source : recherche Google « site: » via Serper.dev, pas Search Console · hors score SEO</div>";
				}
			}
		}
		var body = $( "cu-seo-index-tbody" );
		if ( ! body ) return;
		var items = d.items || [];
		if ( ! items.length ) {
			body.innerHTML = '<tr><td colspan="3" class="cu-empty">' +
				( indexState.filter ? "Aucune page pour ce filtre." : "Aucune page concernée. Lancez d'abord « Analyser tout le site »." ) + "</td></tr>";
			renderIndexPager( d );
			return;
		}
		body.innerHTML = items.map( function ( p ) {
			var path = p.url;
			try { path = new URL( p.url ).pathname || p.url; } catch ( e ) { path = p.url; }
			var title = p.title || path || p.url;
			return "<tr>" +
				'<td class="cu-seo-cell-page"><strong>' + escapeHtml( title ) + "</strong>" +
				'<span><a href="' + escapeHtml( p.url ) + '" target="_blank" rel="noopener">' + escapeHtml( path ) + "</a></span></td>" +
				"<td>" + indexStatusBadge( p ) + "</td>" +
				"<td>" + ( p.checked ? '<span title="' + escapeHtml( relTime( p.checked ) ) + '">' + escapeHtml( siteDate( p.checked ) ) + "</span>" : "—" ) + "</td>" +
				"</tr>";
		} ).join( "" );
		renderIndexPager( d );
	}

	function renderIndexPager( d ) {
		var pager = $( "cu-seo-index-pager" );
		if ( ! pager ) return;
		var totalPages = num( d.pages_total );
		if ( totalPages <= 1 ) { pager.innerHTML = ""; return; }
		var cur = num( d.page ) || 1;
		pager.innerHTML =
			'<button class="cu-btn cu-btn-sm" ' + ( cur <= 1 ? "disabled" : "" ) + ' id="cu-seo-index-prev">‹</button>' +
			'<span class="cu-seo-pageinfo">' + cur + " / " + totalPages + " · " + cu.formatNumber( num( d.total_list ) ) + " page(s)</span>" +
			'<button class="cu-btn cu-btn-sm" ' + ( cur >= totalPages ? "disabled" : "" ) + ' id="cu-seo-index-next">›</button>';
		var prev = $( "cu-seo-index-prev" ), next = $( "cu-seo-index-next" );
		if ( prev ) prev.addEventListener( "click", function () { indexState.page = Math.max( 1, indexState.page - 1 ); loadIndex(); } );
		if ( next ) next.addEventListener( "click", function () { indexState.page = indexState.page + 1; loadIndex(); } );
	}

	function setIndexProgress( pct, label ) {
		var fill = $( "cu-seo-index-bar-fill" );
		var lab  = $( "cu-seo-index-progress-label" );
		if ( fill ) fill.style.width = Math.min( 100, Math.max( 0, pct ) ) + "%";
		if ( lab ) lab.textContent = label || "";
	}

	/**
	 * Confirmation avant de consommer des crédits Serper : nombre estimé de
	 * requêtes pour un passage volumineux, et relance explicite seulement après
	 * un arrêt « clé refusée / crédits épuisés ». Renvoie null si annulé, sinon
	 * { retryBlocked: bool }.
	 */
	function confirmIndexCost( reset ) {
		var d = lastIndex || {};
		var retryBlocked = false;
		if ( d.blocked && d.blocked.message ) {
			if ( ! cu.confirmAction( "La dernière vérification a été arrêtée : " + d.blocked.message + "\n\nRelancer quand même ? Au moins 1 requête Serper.dev sera envoyée pour tester la clé." ) ) return null;
			retryBlocked = true;
		}
		if ( reset ) {
			var all = num( d.total );
			if ( ! cu.confirmAction( "Revérifier TOUTES les pages (" + all + "), même celles vérifiées récemment ?\n\nCoût estimé : jusqu'à " + all + " requête(s) (crédits) Serper.dev, 1 par page. « Vérifier les pages en attente » ne vérifie que les pages jamais vérifiées ou dont le résultat a expiré." ) ) return null;
		} else {
			var due = num( d.due );
			if ( due >= INDEX_CONFIRM_FROM && ! cu.confirmAction( "Vérifier " + due + " page(s) en attente ?\n\nCoût estimé : jusqu'à " + due + " requête(s) (crédits) Serper.dev, 1 par page. Vous pourrez interrompre en fermant l'onglet." ) ) return null;
		}
		return { retryBlocked: retryBlocked };
	}

	function runIndexCheck( reset ) {
		// Un seul passage à la fois dans cet onglet (le serveur refuse aussi
		// deux passages simultanés, tous onglets et tâche planifiée confondus).
		if ( indexState.running ) return;
		var ok = confirmIndexCost( reset );
		if ( ! ok ) return;
		var btn  = $( "cu-seo-index-check" );
		var btn2 = $( "cu-seo-index-recheck" );
		var prog = $( "cu-seo-index-progress" );
		indexState.running = true;
		if ( btn ) btn.disabled = true;
		if ( btn2 ) btn2.disabled = true;
		if ( prog ) prog.hidden = false;
		setIndexProgress( 3, "Interrogation de Google via Serper…" );
		var run = { checked: 0, found: 0, not_found: 0, error: 0, start: null, retryBlocked: ok.retryBlocked };
		drainIndex( 0, reset ? 1 : 0, "", run, function ( err, message ) {
			indexState.running = false;
			if ( btn ) btn.disabled = false;
			if ( btn2 ) btn2.disabled = false;
			if ( prog ) prog.hidden = true;
			indexState.page = 1; // les pages vérifiées changent de statut → on revient en tête (problèmes d'abord)
			loadIndex();
			var summary = plural( run.checked, "page vérifiée", "pages vérifiées" ) +
				( run.checked ? " (" + run.found + " observée(s), " + run.not_found + " non trouvée(s), " + run.error + " impossible(s))" : "" );
			if ( err ) {
				cu.toast( ( message || "Vérification interrompue." ) + " " + summary + ".", "error" );
			} else {
				cu.toast( run.checked ? "Vérification terminée : " + summary + "." : "Rien à vérifier : toutes les pages ont un résultat récent.", "success" );
			}
		} );
	}

	function drainIndex( guard, reset, forceSince, run, done ) {
		if ( guard > 600 ) { done( "guard", "Vérification interrompue (trop de lots)." ); return; }
		var body = { limit: 4, reset: guard === 0 ? reset : 0 };
		if ( forceSince ) body.force_since = forceSince;
		if ( guard === 0 && run.retryBlocked ) body.retry_blocked = 1;
		apiWhenFree( "seo/check-index", { method: "POST", body: body }, function () { setIndexProgress( 3, "En attente de la fin d'une autre analyse SEO…" ); } )
			.then( function ( res ) {
				var r = res.run || {};
				run.checked += num( res.checked );
				run.found += num( r.found );
				run.not_found += num( r.not_found );
				run.error += num( r.error );
				if ( res.force_since ) forceSince = res.force_since;
				if ( null === run.start ) run.start = run.checked + num( res.remaining );
				if ( res.error ) { done( res.error, res.error_message ); return; }
				var total = Math.max( run.start, run.checked + num( res.remaining ) );
				var pct = total ? Math.round( run.checked / total * 100 ) : 100;
				setIndexProgress( Math.max( 3, pct ), "Vérification… " + run.checked + " / " + total + " (reste " + num( res.remaining ) + ") · 1 requête Serper par page" );
				if ( num( res.remaining ) > 0 && num( res.checked ) > 0 ) {
					setTimeout( function () { drainIndex( guard + 1, 0, forceSince, run, done ); }, 700 );
				} else {
					done();
				}
			} )
			.catch( function ( e ) { done( "http", e.message || "Erreur." ); } );
	}

	// =====================================================================
	// Init
	// =====================================================================
	function bind() {
		$( "cu-seo-scan" ).addEventListener( "click", runFullScan );
		$( "cu-seo-export" ).addEventListener( "click", exportCsv );
		$( "cu-seo-purge" ).addEventListener( "click", purge );
		$( "cu-seo-filter" ).addEventListener( "change", function () { state.filter = this.value; state.page = 1; loadPages(); } );
		$( "cu-seo-search" ).addEventListener( "input", cu.debounce( function () { state.search = $( "cu-seo-search" ).value.trim(); state.page = 1; loadPages(); }, 350 ) );
		$( "cu-seo-sa-run" ).addEventListener( "click", runSiteAudit );
		$( "cu-seo-summary-gen" ).addEventListener( "click", genAiSummary );
		var ic = $( "cu-seo-index-check" );
		if ( ic ) ic.addEventListener( "click", function () { runIndexCheck( false ); } );
		var ir = $( "cu-seo-index-recheck" );
		if ( ir ) ir.addEventListener( "click", function () { runIndexCheck( true ); } );
		var ifil = $( "cu-seo-index-filter" );
		if ( ifil ) ifil.addEventListener( "change", function () { indexState.filter = this.value; indexState.page = 1; loadIndex(); } );
		$( "cu-seo-trend-range" ).querySelectorAll( "[data-days]" ).forEach( function ( btn, i, all ) {
			btn.addEventListener( "click", function () {
				all.forEach( function ( other ) {
					var on = other === btn;
					other.classList.toggle( "is-active", on );
					other.setAttribute( "aria-pressed", on ? "true" : "false" );
				} );
				trendState.days = Number( btn.dataset.days ) || 30;
				drawTrend();
			} );
		} );
	}

	function init() {
		if ( ! document.querySelector( ".cu-seo" ) ) return;
		bind();
		loadOverview();
		loadPages();
		loadSiteAudit();
		loadAiSummary();
		loadIndex();
	}

	// Le script peut s'exécuter après DOMContentLoaded (chargement différé).
	if ( "loading" === document.readyState ) {
		document.addEventListener( "DOMContentLoaded", init );
	} else {
		init();
	}
} )();

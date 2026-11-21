<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module Analytics — CyberPush Ultra
 * Collecte, agrège et expose toutes les statistiques du site.
 */
class CU_Analytics {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_admin_routes' ) );
	}

	// -------------------------------------------------------------------------
	// Routes REST publiques (appelées par CU_Tracker)
	// -------------------------------------------------------------------------

	public static function register_public_routes() {
		register_rest_route(
			CU_REST_NAMESPACE,
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'CU_Analytics', 'handle_collect' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Routes REST d'administration
	// -------------------------------------------------------------------------

	public function register_admin_routes() {
		$admin_perm = function() {
			return current_user_can( 'manage_options' );
		};

		$routes = array(
			'/analytics/summary'             => 'handle_summary',
			'/analytics/brief'               => 'handle_brief',
			'/analytics/brief-point'         => 'handle_brief_point',
			'/analytics/realtime'            => 'handle_realtime',
			'/analytics/tracking-check'      => 'handle_tracking_check',
			'/analytics/content-breakdown'   => 'handle_content_breakdown',
			'/analytics/timeseries'          => 'handle_timeseries',
			'/analytics/heatmap'             => 'handle_heatmap',
			'/analytics/content-performance' => 'handle_content_performance',
			'/analytics/entry-exit-pages'    => 'handle_entry_exit_pages',
			'/analytics/visitor-loyalty'     => 'handle_visitor_loyalty',
			'/analytics/scroll-depth'        => 'handle_scroll_depth',
			'/analytics/technical-breakdown' => 'handle_technical_breakdown',
			'/analytics/countries'           => 'handle_countries',
			'/analytics/outbound'            => 'handle_outbound',
			'/analytics/audience'            => 'handle_audience',
			'/analytics/traffic-sources'     => 'handle_traffic_sources',
			'/analytics/referrers'           => 'handle_referrers',
			'/analytics/ai-traffic'          => 'handle_ai_traffic',
			'/analytics/recurring-countries' => 'handle_recurring_countries',
			'/analytics/paths'               => 'handle_paths',
			'/analytics/js-errors'           => 'handle_js_errors',
			'/analytics/categories'          => 'handle_categories',
			'/analytics/tags'                => 'handle_tags',
			'/analytics/authors'             => 'handle_authors',
			'/analytics/woocommerce'         => 'handle_woocommerce',
			'/analytics/insights'            => 'handle_insights',
			'/analytics/contacts'            => 'handle_contacts',
			'/analytics/export'              => 'handle_export',
		);

		foreach ( $routes as $route => $method ) {
			register_rest_route(
				CU_REST_NAMESPACE,
				$route,
				array(
					'methods'             => 'GET',
					'callback'            => function( WP_REST_Request $request ) use ( $method ) {
						return $this->dispatch_admin_report( $method, $request );
					},
					'permission_callback' => $admin_perm,
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function migration_error() {
		return new WP_Error(
			'cu_migration_required',
			__( 'Tables SQL manquantes — lancez la migration depuis Réglages.', 'cyberpush-ultra' ),
			array( 'status' => 409 )
		);
	}

	/** Les rapports Analytics ne doivent jamais dépendre des tables SEO/Firewall. */
	private static function report_tables_exist( $tables = array( 'sessions', 'events' ) ) {
		return CU_DB::tables_exist_for( $tables );
	}

	/**
	 * Transforme toute panne interne d'un rapport en réponse REST exploitable et
	 * journalisée. Cela évite qu'une erreur SQL soit castée en faux zéro puis cachée.
	 */
	private function dispatch_admin_report( $method, WP_REST_Request $request ) {
		try {
			return call_user_func( array( $this, $method ), $request );
		} catch ( Throwable $e ) {
			$signature = md5( $method . '|' . $e->getMessage() );
			$log_key   = 'cu_analytics_report_error_' . $signature;
			if ( ! get_transient( $log_key ) ) {
				set_transient( $log_key, 1, 5 * MINUTE_IN_SECONDS );
				CU_Logger::log(
					'analytics',
					'error',
					sprintf( __( 'Échec du rapport Analytics « %s ».', 'cyberpush-ultra' ), sanitize_key( $method ) ),
					array(
						'error' => CU_Text::substr( sanitize_text_field( $e->getMessage() ), 0, 500 ),
						'line'  => (int) $e->getLine(),
					)
				);
			}
			return new WP_Error(
				'cu_analytics_report_failed',
				__( 'Le rapport Analytics n’a pas pu être calculé. Consultez les journaux puis réessayez.', 'cyberpush-ultra' ),
				array( 'status' => 500 )
			);
		}
	}

	/** Lectures SQL strictes : une erreur ne doit jamais devenir [] / 0. */
	private static function report_get_results( $query, $output = OBJECT ) {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->get_results( $query, $output );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $result ) ) {
			throw new RuntimeException( $wpdb->last_error ? (string) $wpdb->last_error : 'Résultat SQL Analytics invalide.' );
		}
		return $result;
	}

	private static function report_get_row( $query, $output = OBJECT ) {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->get_row( $query, $output );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( (string) $wpdb->last_error );
		}
		return $result;
	}

	private static function report_get_var( $query ) {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->get_var( $query );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( (string) $wpdb->last_error );
		}
		return $result;
	}

	/**
	 * Titre destiné au JSON et aux exports : texte réel, sans entités HTML affichées
	 * littéralement. get_the_title() applique wptexturize (« &#8220; », « &#8217; »…) :
	 * toutes les entités sont décodées, pas seulement & < > " '.
	 */
	private static function display_title( $post, $fallback = '' ) {
		if ( ! $post ) {
			return (string) $fallback;
		}
		return wp_strip_all_tags( self::decode_text( get_the_title( $post ) ) );
	}

	/** Texte réel d'une valeur stockée ou filtrée avec des entités HTML (nommées ou numériques). */
	private static function decode_text( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Charge en une fois les articles, termes et auteurs d'une liste de lignes de
	 * contenu : sans cela, chaque ligne déclenche ses propres requêtes (N+1).
	 */
	private static function prime_content_rows( $rows ) {
		$ids = array();
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['object_id'] ) ) {
				$ids[] = (int) $row['object_id'];
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		_prime_post_caches( $ids, true, false );
		$authors = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$authors[] = (int) $post->post_author;
			}
		}
		if ( $authors ) {
			cache_users( array_values( array_unique( $authors ) ) );
		}
	}

	/** Catégories d'un article en texte réel (les noms de termes sont stockés échappés). */
	private static function category_names( $post ) {
		if ( ! $post || 'post' !== $post->post_type ) {
			return '';
		}
		$names = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		if ( ! is_array( $names ) ) {
			return '';
		}
		return implode( ', ', array_map( function ( $name ) {
			return self::decode_text( $name );
		}, $names ) );
	}

	/** Libellé lisible d'un type de contenu (« Articles » plutôt que « post »). */
	private static function content_type_label( $type ) {
		$type = (string) $type;
		$object = '' !== $type ? get_post_type_object( $type ) : null;
		if ( $object && ! empty( $object->labels->singular_name ) ) {
			return $object->labels->singular_name;
		}
		return $type;
	}

	private static function parse_range( $request ) {
		$range = (int) ( $request->get_param( 'range' ) ?: 30 );
		if ( ! in_array( $range, array( 7, 14, 30, 60, 90 ), true ) ) {
			$range = 30;
		}
		return $range;
	}

	private static function parse_segment( $request ) {
		$seg = $request->get_param( 'segment' ) ?: 'reliable';
		if ( ! in_array( $seg, array( 'reliable', 'suspect', 'all' ), true ) ) {
			$seg = 'reliable';
		}
		return $seg;
	}

	/** Retourne (date_start_current, date_end_current, date_start_prev, date_end_prev) */
	private static function period_dates( $range ) {
		$now        = current_time( 'timestamp' );
		$end        = gmdate( 'Y-m-d', $now );
		// Une plage de N jours inclut aujourd'hui et les N - 1 jours précédents.
		// L'ancien calcul retirait N jours et produisait donc N + 1 journées.
		$offset     = max( 0, (int) $range - 1 );
		$start      = gmdate( 'Y-m-d', strtotime( "-{$offset} days", $now ) );
		$prev_end   = gmdate( 'Y-m-d', strtotime( '-1 day', strtotime( $start ) ) );
		$prev_start = gmdate( 'Y-m-d', strtotime( "-{$offset} days", strtotime( $prev_end ) ) );
		return array( $start, $end, $prev_start, $prev_end );
	}

	/**
	 * Fragment WHERE du segment (tables sessions, alias s) ET de la portée, point
	 * de passage unique de tous les rapports. Une ligne de session est soit une
	 * visite suivie en continu (anon = 0), soit UNE page vue anonyme : page
	 * d'entrée (anon = 1, compte pour une visite) ou page interne (anon = 2, suite
	 * d'une visite déjà comptée).
	 *  - 'visits'   : visites (anon < 2) — visites, sources, pays, appareils… ;
	 *  - 'pages'    : toutes les lignes — pages vues et événements ;
	 *  - 'measured' : visites suivies en continu (anon = 0) — rebond, pages par
	 *                 visite, durée, parcours, nouveaux / fidèles…
	 */
	private static function segment_where( $segment, $scope, $alias = 's' ) {
		if ( 'reliable' === $segment ) {
			// Humain réel probable : ni bot, ni datacenter, ni proxy, ni exclu.
			$where = " AND {$alias}.is_reliable = 1 AND {$alias}.is_bot = 0 AND {$alias}.is_datacenter = 0 AND {$alias}.is_proxy = 0 AND {$alias}.is_excluded = 0 ";
		} elseif ( 'suspect' === $segment ) {
			// Tout ce qui est douteux : bot, non fiable, datacenter ou proxy.
			$where = " AND ({$alias}.is_reliable = 0 OR {$alias}.is_bot = 1 OR {$alias}.is_datacenter = 1 OR {$alias}.is_proxy = 1) AND {$alias}.is_excluded = 0 ";
		} else {
			$where = " AND {$alias}.is_excluded = 0 ";
		}
		if ( self::anon_ready() ) {
			if ( 'visits' === $scope ) {
				$where .= " AND {$alias}.anon < 2 ";
			} elseif ( 'measured' === $scope ) {
				$where .= " AND {$alias}.anon = 0 ";
			}
		}
		return $where;
	}

	/** La colonne sessions.anon existe-t-elle (schéma 1.9.1) ? */
	public static function anon_ready() {
		return self::schema_at_least( '1.9.1' );
	}

	/**
	 * Expression SQL du niveau de mesure d'une ligne de session : « s.anon », ou
	 * « 0 » avant la migration 1.9.1 (toutes les lignes sont alors suivies).
	 */
	private static function anon_col( $alias = 's' ) {
		return self::anon_ready() ? $alias . '.anon' : '0';
	}

	/**
	 * Couverture de la mesure continue : visites suivies ÷ visites. Base de tous
	 * les taux « par visite » (rebond, durée, pages par visite, CTR…).
	 *
	 * @return array{visits:int, measured:int, anonymous:int, percent:int|null}
	 */
	private static function coverage( $visits, $measured ) {
		$visits   = max( 0, (int) $visits );
		$measured = max( 0, min( (int) $measured, $visits ) );
		return array(
			'visits'    => $visits,
			'measured'  => $measured,
			'anonymous' => $visits - $measured,
			'percent'   => $visits > 0 ? (int) round( $measured / $visits * 100 ) : null,
		);
	}

	/** Taux en % arrondi, ou null (« n/d ») sans dénominateur : jamais un faux 0 %. */
	private static function rate( $part, $whole, $digits = 1 ) {
		return $whole > 0 ? round( min( 100, $part / $whole * 100 ), $digits ) : null;
	}

	/** Évolution entre deux valeurs pouvant être indisponibles (null). */
	private static function delta_or_null( $current, $previous ) {
		if ( null === $current || null === $previous ) {
			return null;
		}
		return self::delta( $current, $previous );
	}

	/**
	 * Évolution en %. Sans valeur précédente, aucun pourcentage n'a de sens :
	 * null (« nouveau ») si la valeur actuelle est positive, 0 si les deux sont nulles.
	 *
	 * @return float|null
	 */
	private static function delta( $current, $previous ) {
		if ( ! $previous ) {
			return $current > 0 ? null : 0.0;
		}
		return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
	}

	private static function get_transient_key( $suffix, $range, $segment, $extra = '' ) {
		// L'époque change quand l'enrichissement réseau reclasse des sessions
		// (datacenter/proxy) : tous les rapports en cache deviennent alors obsolètes.
		$epoch = (string) get_option( 'cu_analytics_cache_epoch', '' );
		return 'cu_analytics_' . md5( $suffix . '|' . $range . '|' . $segment . '|' . $extra . '|' . $epoch );
	}

	/** Lit un cache de rapport, ou le supprime lors d'une actualisation manuelle. */
	private static function get_cached_report( WP_REST_Request $request, $cache_key ) {
		// Dans WordPress, set_transient(..., 0) signifie « sans expiration » et non
		// « cache désactivé ». On supprime donc aussi les anciennes entrées pouvant
		// avoir été créées quand le réglage était à zéro.
		if ( $request->get_param( 'refresh' ) || 0 === self::cache_duration() ) {
			delete_transient( $cache_key );
			return false;
		}
		return get_transient( $cache_key );
	}

	/** Enregistre un rapport uniquement quand le cache Analytics est actif. */
	private static function set_cached_report( $cache_key, $data, $max_duration = null ) {
		$duration = self::cache_duration();
		if ( $duration <= 0 ) {
			delete_transient( $cache_key );
			return;
		}
		if ( null !== $max_duration ) {
			$duration = min( $duration, max( 1, (int) $max_duration ) );
		}
		set_transient( $cache_key, $data, $duration );
	}

	/** Cellule CSV sûre pour Excel/LibreOffice (échappement + anti-formule). */
	private static function csv_cell( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^[\x00-\x20]*[=+\-@]/u', $value ) ) {
			$value = "'" . $value;
		}
		return '"' . str_replace( '"', '""', $value ) . '"';
	}

	/** Décimal au format attendu par Excel en français (« 12,5 » et non « 12.5 », lu comme une date). */
	private static function csv_decimal( $value ) {
		return str_replace( '.', ',', (string) $value );
	}

	private static function cache_duration() {
		$minutes = (int) CU_Settings::get( 'analytics', 'cache_duration_minutes', 10 );
		return max( 0, min( 1440, $minutes ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Agrégation à la demande (throttlée) : les tableaux de contenu (top articles,
	 * catégories, auteurs…) lisent la table `content_daily`, remplie par le cron
	 * horaire. Sur un site à faible trafic, WP-Cron tarde → les articles semblent
	 * absents. Une lecture REST ne doit toutefois jamais exécuter les gros GROUP BY
	 * elle-même : elle programme un passage proche et sert le dernier snapshot.
	 */
	private static function maybe_rollup() {
		if ( get_transient( 'cu_rollup_recent' ) ) {
			return;
		}
		set_transient( 'cu_rollup_recent', 1, 2 * MINUTE_IN_SECONDS );
		if ( ! wp_next_scheduled( 'cu_cron_rollup_daily' ) || wp_next_scheduled( 'cu_cron_rollup_daily' ) > time() + 10 * MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( time() + 5, 'cu_cron_rollup_daily' );
		}
	}

	// -------------------------------------------------------------------------
	// User-Agent parser (simple heuristic)
	// -------------------------------------------------------------------------

	public static function parse_ua( $ua ) {
		$ua = (string) $ua;

		// Device
		if ( preg_match( '/\b(smart-tv|googletv|appletv|roku|firetv|hbbtv|netcast|viera|bravia|philipstv|webos|tizen)\b/i', $ua ) ) {
			$device = 'TV';
		} elseif ( preg_match( '/\b(ipad|tablet|kindle|silk|playbook)\b/i', $ua ) || ( preg_match( '/\bandroid\b/i', $ua ) && ! preg_match( '/\bmobile\b/i', $ua ) ) ) {
			// Une tablette Android n'inclut pas « Mobile » dans son User-Agent.
			$device = 'Tablette';
		} elseif ( preg_match( '/\b(mobile|android(?!.*tablet)|iphone|ipod|blackberry|windows phone|opera mini|opera mobi)\b/i', $ua ) ) {
			$device = 'Mobile';
		} else {
			$device = 'Desktop';
		}

		// Browser
		if ( preg_match( '/\bEdg(?:e|A|iOS)?\//i', $ua ) ) {
			$browser = 'Edge';
		} elseif ( preg_match( '/\bOPR\//i', $ua ) || preg_match( '/\bOpera\//i', $ua ) ) {
			$browser = 'Opera';
		} elseif ( preg_match( '/\b(?:Chrome|CriOS)\//i', $ua ) ) {
			// CriOS = Chrome sur iOS (son UA contient aussi « Safari/ »).
			$browser = 'Chrome';
		} elseif ( preg_match( '/\b(?:Firefox|FxiOS)\//i', $ua ) ) {
			$browser = 'Firefox';
		} elseif ( preg_match( '/\bSafari\//i', $ua ) ) {
			$browser = 'Safari';
		} else {
			$browser = 'Autre';
		}

		// OS
		if ( preg_match( '/\bWindows\b/i', $ua ) ) {
			$os = 'Windows';
		} elseif ( preg_match( '/\bAndroid\b/i', $ua ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/\b(iPhone|iPad|iPod)\b/i', $ua ) ) {
			$os = 'iOS';
		} elseif ( preg_match( '/\bMac OS X\b/i', $ua ) ) {
			$os = 'macOS';
		} elseif ( preg_match( '/\bLinux\b/i', $ua ) ) {
			$os = 'Linux';
		} else {
			$os = 'Autre';
		}

		return array( 'device' => $device, 'browser' => $browser, 'os' => $os );
	}

	/**
	 * Détecte si un UA est un bot / crawler / outil d'automatisation.
	 * Liste élargie 2026 (moteurs, bots IA, scrapers, headless). UA vide = bot.
	 */
	public static function is_bot_ua( $ua ) {
		$ua = (string) $ua;
		if ( '' === trim( $ua ) ) {
			return true; // un vrai navigateur envoie toujours un User-Agent
		}
		// « bot » en fin de mot (Googlebot/, AdsBot-Google, UptimeRobot…), sauf la
		// marque de téléphones CUBOT (« CUBOT X30 » : de vrais visiteurs Android).
		// Puis robots et outils sans « bot » dans leur nom : outils Google
		// (InspectionTool, GoogleOther…), mesure de performance (Lighthouse,
		// WebPageTest, GTmetrix), surveillance (Pingdom, StatusCake, Site24x7,
		// Uptime Kuma, Datadog, Checkly), automatisation et bibliothèques HTTP.
		$pattern = '/((?<!cu)bot\b|crawl|spider|slurp|facebookexternalhit|headless|prerender|phantomjs|selenium|puppeteer|playwright|cypress|lighthouse|\bptst\/|gtmetrix|pingdom|statuscake|site24x7|uptime-kuma|datadog|checkly|google-inspectiontool|googleother|mediapartners-google|apis-google|google-read-aloud|bytespider|dataforseo|mj12|scrapy|semrush|ahrefs|screaming|serpstat|megaindex|seokicks|chatgpt-user|perplexity-user|claude-web|claude-user|anthropic-ai|cohere-ai|meta-externalagent|google-extended|yandeximages|bingpreview|feedfetcher|python-requests|python-urllib|aiohttp|go-http-client|okhttp|axios|node-fetch|undici|java\/|curl\/|wget\/|libwww|httpclient|httpx|httpie|postmanruntime|heritrix|newrelicpinger|zabbix|check_http|masscan|zgrab|nmap)/i';
		return (bool) preg_match( $pattern, $ua );
	}

	/**
	 * Requête émise depuis l'adresse PUBLIQUE du serveur lui-même (préchargeur
	 * de cache, outil installé sur le serveur) : jamais un visiteur. Classement
	 * prudent : la boucle locale et les plages privées ne sont pas concernées
	 * (un site de test en local verrait sinon toutes ses visites écartées).
	 */
	public static function is_server_ip( $ip ) {
		$ip = CU_Geo::normalize_ip( (string) $ip );
		if ( '' === $ip || ! CU_Geo::is_public_ip( $ip ) ) {
			return false;
		}
		$server = isset( $_SERVER['SERVER_ADDR'] ) && is_string( $_SERVER['SERVER_ADDR'] ) ? CU_Geo::normalize_ip( (string) wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		return '' !== $server && $server === $ip;
	}

	/**
	 * Détecte si le trafic est « fiable » (vrai humain probable).
	 * Heuristique honnête côté UA + signal headless (navigator.webdriver).
	 * NB : le filtrage datacenter/proxy par IP est ajouté ensuite, de façon
	 * différée, par l'enrichissement réseau (cron) — voir enrich_pending_sessions().
	 *
	 * @param bool $webdriver Le client a signalé navigator.webdriver = true (automatisation).
	 */
	public static function is_reliable_ua( $ua, $is_bot, $webdriver = false ) {
		if ( $is_bot ) return false;
		if ( $webdriver ) return false; // navigateur piloté par automatisation
		$ua = (string) $ua;
		if ( '' === trim( $ua ) ) return false;
		// Linux Desktop sans navigateur connu = suspect (scraper générique). Les
		// navigateurs Linux à moteur propre (Konqueror/KHTML, NetSurf, SeaMonkey,
		// Epiphany, Falkon…) sont de vrais navigateurs : jamais « douteux » pour cela.
		if ( preg_match( '/\bLinux\b/i', $ua ) && ! preg_match( '/\b(Android|Chrome|Firefox|Safari|Mobile|Konqueror|KHTML|NetSurf|SeaMonkey|Epiphany|Falkon|Midori|PaleMoon)\b/i', $ua ) ) {
			return false;
		}
		// Signatures d'outils / bibliothèques HTTP (datacenter).
		if ( preg_match( '/(python-requests|go-http-client|java\/|curl\/|wget\/|okhttp|axios|libwww|ruby|perl|phantom|http_request|node-fetch|httpx)/i', $ua ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Host normalisé d'un referrer (sans « www. »), en excluant l'auto-référence
	 * (le domaine du site lui-même) : on ne veut que les VRAIS domaines externes
	 * pour le rapport « top domaines référents ».
	 *
	 * @return string '' si direct, interne, ou invalide.
	 */
	public static function referrer_host_from( $referrer_url ) {
		if ( empty( $referrer_url ) ) {
			return '';
		}
		$host = wp_parse_url( $referrer_url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return '';
		}
		$host = strtolower( preg_replace( '/^www\./i', '', $host ) );
		$self = strtolower( preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$is_subdomain = $self && strlen( $host ) > strlen( $self ) && substr( $host, -( strlen( $self ) + 1 ) ) === '.' . $self;
		if ( $self && ( $host === $self || $is_subdomain ) ) {
			return ''; // auto-référence : ignorée
		}
		return substr( $host, 0, 255 );
	}

	/** Correspondance de domaine stricte : domaine exact ou vrai sous-domaine. */
	private static function host_matches_domain( $host, $domain ) {
		$host   = strtolower( trim( (string) $host, ". \t\n\r\0\x0B" ) );
		$domain = strtolower( trim( (string) $domain, ". \t\n\r\0\x0B" ) );
		if ( '' === $host || '' === $domain ) return false;
		if ( $host === $domain ) return true;
		return strlen( $host ) > strlen( $domain ) && substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain;
	}

	/** Nom lisible du LLM à partir du host référent (ChatGPT, Perplexity…). */
	public static function ai_llm_name( $host ) {
		$host = strtolower( (string) $host );
		if ( false !== strpos( $host, 'chatgpt' ) || false !== strpos( $host, 'openai' ) || false !== strpos( $host, 'oai' ) ) { return 'ChatGPT'; }
		if ( false !== strpos( $host, 'perplexity' ) ) { return 'Perplexity'; }
		if ( false !== strpos( $host, 'claude' ) || false !== strpos( $host, 'anthropic' ) ) { return 'Claude'; }
		if ( false !== strpos( $host, 'gemini' ) || false !== strpos( $host, 'bard' ) ) { return 'Gemini'; }
		if ( false !== strpos( $host, 'copilot' ) ) { return 'Copilot'; }
		if ( false !== strpos( $host, 'you.com' ) ) { return 'You.com'; }
		if ( false !== strpos( $host, 'grok' ) || false !== strpos( $host, 'x.ai' ) ) { return 'Grok'; }
		if ( false !== strpos( $host, 'deepseek' ) ) { return 'DeepSeek'; }
		if ( false !== strpos( $host, 'mistral' ) || false !== strpos( $host, 'lechat' ) ) { return 'Mistral'; }
		return $host ? $host : 'IA';
	}

	/** Étiquette du crawler IA depuis l'UA (serveur), '' si non-IA. */
	public static function ai_crawler_label( $ua ) {
		$ua = (string) $ua;
		$map = array(
			'ChatGPT (OpenAI)'   => '/GPTBot|ChatGPT-User|OAI-SearchBot/i',
			'Perplexity'         => '/PerplexityBot|Perplexity-User/i',
			'Claude (Anthropic)' => '/ClaudeBot|Claude-Web|Claude-User|Claude-SearchBot|anthropic-ai/i',
			'Gemini (Google)'    => '/Google-Extended|Google-CloudVertexBot/i',
			'Bytespider (TikTok)'=> '/Bytespider/i',
			'Amazon'             => '/Amazonbot/i',
			'Apple'              => '/Applebot-Extended/i',
			'Common Crawl'       => '/CCBot/i',
			'Meta AI'            => '/meta-externalagent|FacebookBot/i',
			'Cohere'             => '/cohere-ai/i',
		);
		foreach ( $map as $name => $re ) {
			if ( preg_match( $re, $ua ) ) { return $name; }
		}
		return '';
	}

	/**
	 * Journalise côté SERVEUR les passages des crawlers IA (GPTBot, PerplexityBot,
	 * ClaudeBot…). Ces robots n'exécutent PAS le JavaScript → le beacon ne les voit
	 * jamais ; seul un log serveur les capte. Le robot est DÉCLARÉ par son
	 * User-Agent (falsifiable, non vérifié) : le rapport le présente comme tel.
	 * Sans cache objet persistant, au plus une écriture par minute et par robot :
	 * un faux User-Agent ne peut pas transformer chaque requête en écriture SQL.
	 */
	public static function log_ai_crawler() {
		if ( is_admin() ) {
			return;
		}
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 300 ) : '';
		$bot = self::ai_crawler_label( $ua );
		if ( '' === $bot ) {
			return;
		}
		$key  = 'cu_ai_hits_' . wp_date( 'Ymd', time(), wp_timezone() );
		$hits = get_transient( $key );
		if ( ! is_array( $hits ) ) {
			$hits = array();
		}
		if ( ! wp_using_ext_object_cache() ) {
			$minute = (int) floor( time() / MINUTE_IN_SECONDS );
			$seen   = isset( $hits['_m'] ) && is_array( $hits['_m'] ) ? $hits['_m'] : array();
			if ( isset( $seen[ $bot ] ) && (int) $seen[ $bot ] === $minute ) {
				return;
			}
			$seen[ $bot ] = $minute;
			$hits['_m']   = $seen;
		}
		$hits[ $bot ] = ( isset( $hits[ $bot ] ) ? (int) $hits[ $bot ] : 0 ) + 1;
		set_transient( $key, $hits, 40 * DAY_IN_SECONDS );
	}

	/** Catégorise la source de trafic depuis un referrer URL */
	public static function classify_referrer( $referrer_url ) {
		if ( empty( $referrer_url ) ) {
			return 'direct';
		}
		$host = wp_parse_url( $referrer_url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return 'direct';
		}
		$host = strtolower( trim( $host, '.' ) );
		$self = strtolower( trim( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '.' ) );
		if ( $self && ( $host === $self || self::host_matches_domain( $host, $self ) ) ) {
			return 'direct';
		}
		// IA
		$ai_hosts = array( 'chatgpt.com', 'chat.openai.com', 'openai.com', 'perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com', 'claude.ai', 'you.com', 'x.ai', 'grok.com', 'deepseek.com', 'mistral.ai', 'lechat.mistral.ai', 'phind.com', 'poe.com' );
		foreach ( $ai_hosts as $ai ) {
			if ( self::host_matches_domain( $host, $ai ) ) return 'ia';
		}
		// Webmails avant les moteurs de recherche (mail.google.com ne doit pas
		// être classé comme trafic SEO).
		$email = array( 'mail.google.com', 'outlook.live.com', 'outlook.office.com', 'mail.yahoo.com', 'hotmail.com' );
		foreach ( $email as $e ) {
			if ( self::host_matches_domain( $host, $e ) ) return 'email';
		}
		// Search engines
		$search = array( 'bing.com', 'yahoo.com', 'duckduckgo.com', 'ecosia.org', 'qwant.com' );
		foreach ( $search as $s ) {
			if ( self::host_matches_domain( $host, $s ) ) return 'google';
		}
		if ( preg_match( '/(^|\.)google\.[a-z]{2,}(\.[a-z]{2})?$/i', $host ) || preg_match( '/(^|\.)yandex\.[a-z]{2,}(\.[a-z]{2})?$/i', $host ) ) return 'google';
		// Social
		$social = array( 'facebook.com', 'fb.com', 't.co', 'twitter.com', 'x.com', 'instagram.com', 'linkedin.com', 'pinterest.com', 'tiktok.com', 'youtube.com', 'reddit.com', 'snapchat.com', 'tumblr.com', 'whatsapp.com' );
		foreach ( $social as $s ) {
			if ( self::host_matches_domain( $host, $s ) ) return 'social';
		}
		return 'referral';
	}

	// -------------------------------------------------------------------------
	// Confidentialité : modes « exempt » (défaut), « consent » et « standard »
	// -------------------------------------------------------------------------

	/** Événements enregistrés (liste filtrable). Un « ping » ne crée aucune ligne. */
	const STORED_EVENTS = array( 'pageview', 'scroll', 'cta_click', 'conversion', 'js_error', 'exit', 'whatsapp', 'cf7', 'outbound' );

	/**
	 * Types envoyés par les trackers 1.1.x encore en cache navigateur : acceptés
	 * puis ignorés. Aucun rapport ne les affichait ; libellés, liens, termes de
	 * recherche et identifiants de formulaire ne sont plus collectés.
	 */
	const DROPPED_EVENTS = array( 'download', 'external_link', 'form_open', 'form_submit', 'form_abandon', 'internal_search' );

	/** Nombre maximal d'événements traités par requête (lot du tracker). */
	const MAX_BATCH = 20;

	/** Durée maximale attribuée à un seul affichage de page (s). */
	const MAX_EXIT_SECONDS = 7200;

	/** Marge tolérée entre le temps mesuré par le navigateur et l'horloge du serveur (s). */
	const DURATION_MARGIN = 60;

	/** Types spéciaux envoyés par le tracker en plus des types de contenu WordPress. */
	const SPECIAL_OBJECT_TYPES = array( 'archive', 'search', '404' );

	/** Mode de confidentialité actif : exempt | consent | standard. */
	public static function privacy_mode() {
		$mode = (string) CU_Settings::get( 'analytics', 'privacy_mode', 'exempt' );
		return in_array( $mode, array( 'exempt', 'consent', 'standard' ), true ) ? $mode : 'exempt';
	}

	/** Version de schéma au moins égale à $version (mémorisée pour la requête). */
	private static function schema_at_least( $version ) {
		static $db_version = null;
		if ( null === $db_version ) {
			$db_version = (string) get_option( CU_OPTION_DB_VERSION, '0' );
		}
		return version_compare( $db_version, $version, '>=' );
	}

	/**
	 * L'IP du temps réel n'est conservée (chiffrée, effacée 1 h après la dernière
	 * activité) qu'en mode standard ET sur activation explicite du réglage.
	 */
	public static function live_ip_allowed() {
		return 'standard' === self::privacy_mode()
			&& (bool) CU_Settings::get( 'analytics', 'realtime_show_ip', false )
			&& self::schema_at_least( '1.8.7' );
	}

	/**
	 * Enrichissement réseau différé (pays, datacenter, proxy) par le fournisseur
	 * externe choisi. Jamais en mode exempté : aucune IP n'est transmise à un tiers.
	 */
	public static function remote_enrichment_allowed() {
		return 'exempt' !== self::privacy_mode() && CU_Geo::remote_lookups_allowed() && self::schema_at_least( '1.8.6' );
	}

	/** Le filtre « datacenter / proxy » a-t-il réellement un effet dans la configuration actuelle ? */
	public static function datacenter_filter_active() {
		return self::remote_enrichment_allowed() && (bool) CU_Settings::get( 'analytics', 'filter_datacenter', true );
	}

	/**
	 * Gestionnaire de consentement compatible détecté côté serveur (libellé), ou
	 * ''. Indicatif : un bandeau peut aussi piloter le suivi via l'API JavaScript
	 * window.cuAnalytics.consent(). Filtre `cu_analytics_consent_manager`.
	 */
	public static function consent_manager_detected() {
		$found = '';
		if ( defined( 'cmplz_version' ) || class_exists( 'COMPLIANZ' ) ) {
			$found = 'Complianz';
		} elseif ( defined( 'CLI_VERSION' ) || defined( 'CKY_APP_VERSION' ) || class_exists( 'Cookie_Law_Info' ) ) {
			$found = 'CookieYes';
		} elseif ( class_exists( 'Cookie_Notice' ) ) {
			$found = 'Cookie Notice';
		} elseif ( function_exists( 'wp_get_consent_type' ) && '' !== (string) wp_get_consent_type() ) {
			$found = 'WP Consent API';
		}
		return sanitize_text_field( (string) apply_filters( 'cu_analytics_consent_manager', $found ) );
	}

	/**
	 * Cookies de consentement reconnus par le tracker en mode « consent » : la
	 * statistique est autorisée si le cookie `name` existe et contient `value`.
	 * Filtre `cu_analytics_consent_cookies` pour ajouter un autre bandeau.
	 *
	 * @return array<int, array{name:string, value:string}>
	 */
	public static function consent_cookies() {
		$rules = apply_filters(
			'cu_analytics_consent_cookies',
			array(
				array( 'name' => 'cmplz_statistics', 'value' => 'allow' ),         // Complianz
				array( 'name' => 'cookieyes-consent', 'value' => 'analytics:yes' ), // CookieYes
				array( 'name' => 'cookie_notice_accepted', 'value' => 'true' ),    // Cookie Notice
			)
		);
		$out = array();
		foreach ( (array) $rules as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['name'], $rule['value'] ) || ! is_scalar( $rule['name'] ) || ! is_scalar( $rule['value'] ) ) {
				continue;
			}
			$name  = (string) $rule['name'];
			$value = CU_Text::substr( (string) $rule['value'], 0, 100 );
			if ( preg_match( '/^[A-Za-z0-9_.\-]{1,100}$/', $name ) && '' !== $value ) {
				$out[] = array( 'name' => $name, 'value' => $value );
			}
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Paramètres de requête conservés dans les URL, en plus de utm_* : ceux qui
	 * désignent la page elle-même avec les permaliens simples, et la langue
	 * (WPML/Polylang). Filtre `cu_analytics_allowed_query_params`.
	 *
	 * @return string[]
	 */
	public static function allowed_query_params() {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$base = array( 'lang' );
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			$base = array_merge( $base, array( 'p', 'page_id', 'cat', 'tag', 'post_type', 'paged' ) );
		}
		$memo = array();
		foreach ( (array) apply_filters( 'cu_analytics_allowed_query_params', $base ) as $name ) {
			$name = is_scalar( $name ) ? strtolower( trim( (string) $name ) ) : '';
			// Jamais le terme de recherche : la recherche interne n'est comptée que par page vue.
			if ( '' !== $name && 's' !== $name && preg_match( '/^[a-z0-9_\-\[\]]{1,40}$/', $name ) ) {
				$memo[] = $name;
			}
		}
		$memo = array_values( array_unique( $memo ) );
		return $memo;
	}

	/**
	 * URL minimisée : schéma, hôte, port et chemin ; requête limitée à utm_* et
	 * aux paramètres autorisés (aucun ?key=wc_order_…, e-mail, jeton…) ; jamais
	 * d'identifiants ni d'ancre. Échappée PUIS tronquée : tient dans VARCHAR($max).
	 *
	 * @param bool $no_query true : aucun paramètre (source d'une erreur JS…).
	 */
	public static function clean_url( $url, $max = 500, $no_query = false ) {
		$url = trim( (string) $url );
		if ( '' === $url || strlen( $url ) > 4096 ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$out  = $scheme . '://' . strtolower( (string) $parts['host'] ) . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$path = ( isset( $parts['path'] ) && '' !== $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( false !== strpos( $path, '@' ) || false !== stripos( $path, '%40' ) ) {
			// Adresse e-mail dans le chemin (/desinscription/jean@exemple.fr/) : masquée.
			$segments = explode( '/', $path );
			foreach ( $segments as $k => $segment ) {
				if ( self::looks_like_email( rawurldecode( $segment ) ) ) {
					$segments[ $k ] = '[e-mail]';
				}
			}
			$path = implode( '/', $segments );
		}
		$out .= $path;
		if ( ! $no_query && ! empty( $parts['query'] ) ) {
			$allowed = self::allowed_query_params();
			$kept    = array();
			foreach ( explode( '&', (string) $parts['query'] ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$name = strtolower( rawurldecode( (string) strstr( $pair . '=', '=', true ) ) );
				// Une valeur qui contient une adresse e-mail (utm_term=jean@exemple.fr,
				// lien de newsletter personnalisé) n'est jamais conservée ; les autres
				// valeurs de campagne restent intactes.
				$value = (string) substr( (string) strstr( $pair, '=' ), 1 );
				if ( ( 0 === strpos( $name, 'utm_' ) || in_array( $name, $allowed, true ) ) && ! self::looks_like_email( urldecode( $value ) ) ) {
					$kept[] = $pair;
				}
				if ( count( $kept ) >= 10 ) {
					break;
				}
			}
			if ( $kept ) {
				$out .= '?' . implode( '&', $kept );
			}
		}
		$out = esc_url_raw( $out, array( 'http', 'https' ) );
		if ( strlen( $out ) > $max ) {
			// Coupe sur une frontière de caractère (un octet UTF-8 orphelin ferait
			// échouer l'INSERT en mode strict), puis jamais de séquence %XX tronquée.
			$out = function_exists( 'mb_strcut' ) ? mb_strcut( $out, 0, $max, 'UTF-8' ) : (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', substr( $out, 0, $max ) );
			$out = (string) preg_replace( '/%[0-9a-f]?$/i', '', $out );
		}
		return $out;
	}

	/** La valeur contient-elle une adresse e-mail (donnée personnelle à ne jamais stocker) ? */
	private static function looks_like_email( $value ) {
		return (bool) preg_match( '/[^\s@\/?#&=<>"\'(),;:]+@[^\s@\/?#&=<>"\'(),;:]+\.[a-z]{2,}/i', (string) $value );
	}

	/** Chemin lisible (sans domaine ni paramètres) d'une URL stockée, y compris d'avant la minimisation. */
	private static function display_path( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return '' === $path ? '/' : CU_Text::substr( $path, 0, 300 );
	}

	/** URL affichable : données antérieures à 1.2.0 re-minimisées à la lecture. */
	private static function display_url( $url ) {
		$clean = self::clean_url( (string) $url );
		return '' !== $clean ? $clean : self::display_path( $url );
	}

	/**
	 * Texte d'une erreur JavaScript sans paramètres d'URL ni adresse e-mail
	 * (un message peut citer une URL complète ou une valeur saisie).
	 */
	private static function clean_error_text( $text ) {
		$text = (string) $text;
		$text = (string) preg_replace( '#((?:https?:)?//[^\s?\#"\'<>]+)[?\#][^\s"\'<>]*#i', '$1', $text );
		$text = (string) preg_replace( '/[^\s@<>"\'()]+@[^\s@<>"\'()]+\.[a-z]{2,}/i', '[e-mail]', $text );
		return CU_Text::substr( trim( sanitize_text_field( $text ) ), 0, 200 );
	}

	// -------------------------------------------------------------------------
	// Origine, exclusions et limites de la collecte publique
	// -------------------------------------------------------------------------

	/**
	 * Hôtes du site : home, son alias www / sans www, site_url, plus les domaines
	 * ajoutés par le filtre `cu_analytics_allowed_hosts` (WPML, Polylang…).
	 *
	 * @return string[]
	 */
	public static function allowed_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $site_url ) {
			$host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
			if ( '' === $host ) {
				continue;
			}
			$hosts[] = $host;
			$hosts[] = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
		}
		foreach ( (array) apply_filters( 'cu_analytics_allowed_hosts', array() ) as $extra ) {
			if ( ! is_scalar( $extra ) ) {
				continue;
			}
			$extra = strtolower( trim( (string) $extra ) );
			if ( false !== strpos( $extra, '://' ) ) {
				$extra = strtolower( (string) wp_parse_url( $extra, PHP_URL_HOST ) );
			}
			$extra = trim( $extra, '.' );
			if ( '' !== $extra && preg_match( '/^[a-z0-9.\-\[\]:]+$/', $extra ) ) {
				$hosts[] = $extra;
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/** L'hôte appartient-il au site (voir allowed_hosts()) ? */
	public static function is_allowed_host( $host ) {
		$host = strtolower( trim( (string) $host, ". \t\n\r\0\x0B" ) );
		return '' !== $host && in_array( $host, self::allowed_hosts(), true );
	}

	/**
	 * Schéma + hôte + port d'une Origin (ou d'un Referer) comparés au site.
	 * « null » (iframe sandboxée, data:, fichier local) n'est jamais le site. Un
	 * site déclaré en http mais servi en https derrière un proxy TLS reste accepté.
	 */
	public static function is_site_origin( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || 'null' === strtolower( $value ) ) {
			return false;
		}
		$parts = wp_parse_url( $value );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! is_array( $home ) ) {
			return false;
		}
		$defaults    = array( 'http' => 80, 'https' => 443 );
		$scheme      = strtolower( (string) $parts['scheme'] );
		$home_scheme = isset( $home['scheme'] ) ? strtolower( (string) $home['scheme'] ) : 'https';
		if ( ! isset( $defaults[ $scheme ], $defaults[ $home_scheme ] ) ) {
			return false;
		}
		if ( $scheme !== $home_scheme && ! ( 'http' === $home_scheme && 'https' === $scheme ) ) {
			return false;
		}
		$port      = isset( $parts['port'] ) ? (int) $parts['port'] : $defaults[ $scheme ];
		$home_port = isset( $home['port'] ) ? (int) $home['port'] : $defaults[ $home_scheme ];
		$upgraded  = $scheme !== $home_scheme && ! isset( $home['port'] ) && $port === $defaults[ $scheme ];
		if ( $port !== $home_port && ! $upgraded ) {
			return false;
		}
		return self::is_allowed_host( $parts['host'] );
	}

	/**
	 * Contrôle d'origine du beacon. Ce n'est PAS une authentification : un script
	 * hors navigateur forge librement Origin et Referer. Il empêche seulement un
	 * autre site de faire envoyer de fausses visites par le navigateur de ses
	 * propres visiteurs.
	 *
	 * @return bool|null true = page du site, null = aucun en-tête (outil hors navigateur), false = refus.
	 */
	private static function request_origin_state() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) && is_string( $_SERVER['HTTP_ORIGIN'] ) ? trim( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( '' !== $origin ) {
			return self::is_site_origin( $origin );
		}
		$referer = isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ? trim( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( '' !== $referer ) {
			return self::is_site_origin( $referer );
		}
		return null;
	}

	/** L'IP figure-t-elle dans les IP / plages CIDR exclues ? */
	public static function ip_is_excluded( $ip ) {
		foreach ( (array) CU_Settings::get( 'analytics', 'excluded_ips', array() ) as $entry ) {
			if ( is_scalar( $entry ) && CU_Geo::ip_in_cidr( $ip, (string) $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Utilisateur connecté. Sans nonce, l'API REST traite la requête en anonyme :
	 * le cookie de connexion est donc relu (sans authentifier l'appel) afin
	 * d'appliquer les exclusions d'administrateurs et de rôles côté serveur.
	 */
	private static function request_user_id() {
		$user_id = (int) get_current_user_id();
		if ( ! $user_id && defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			$user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
		}
		return $user_id;
	}

	private static function user_is_excluded( $user_id, array $settings ) {
		if ( ! $user_id ) {
			return false;
		}
		if ( ! empty( $settings['exclude_admins'] ) && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		return $user && array_intersect( (array) ( $settings['excluded_roles'] ?? array() ), (array) $user->roles );
	}

	/**
	 * Compteur à fenêtre fixe ATOMIQUE (CU_DB::counter_increment) : avec un cache
	 * objet persistant, incrément en mémoire ; sinon une seule requête
	 * INSERT … ON DUPLICATE KEY UPDATE. L'ancien transient (lecture puis
	 * réécriture) perdait des incréments sous requêtes simultanées.
	 *
	 * @param int $add 0 = lecture seule.
	 * @return int Valeur après ajout.
	 */
	private static function counter( $key, $window, $add = 0 ) {
		if ( (int) $add <= 0 ) {
			return CU_DB::counter_value( $key, $window );
		}
		$state = CU_DB::counter_increment( $key, $window, (int) $add );
		return (int) $state['count'];
	}

	/**
	 * Compte, pour le diagnostic « Vérifier le suivi », ce que les plafonds
	 * anti-abus ont fait aujourd'hui (journée UTC) : « declassed » = nouvelle
	 * visite enregistrée mais classée douteuse (IP partagée au-delà du plafond
	 * normal), « dropped » = visite écartée (plafond dur), « rate » = adresse
	 * ayant reçu des refus 429 (une fois par adresse et par minute).
	 */
	private static function note_limited( $kind ) {
		self::counter( 'cu_collect_limited_' . sanitize_key( (string) $kind ), DAY_IN_SECONDS, 1 );
	}

	/** Valeurs du jour des compteurs de note_limited(). */
	private static function limited_today() {
		$out = array();
		foreach ( array( 'declassed', 'dropped', 'rate' ) as $kind ) {
			$out[ $kind ] = self::counter( 'cu_collect_limited_' . $kind, DAY_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * Limites de la route publique : par IPv4 ou préfixe IPv6 /64 (changer
	 * d'adresse dans son /64 ne contourne rien) puis plafond global par minute.
	 *
	 * @return WP_REST_Response|null Réponse 429, ou null si la requête passe.
	 */
	private static function rate_limited( $ip, $events ) {
		$per_bucket = max( 10, (int) apply_filters( 'cu_analytics_collect_events_per_minute', 240 ) );
		$global     = max( 100, (int) apply_filters( 'cu_analytics_collect_global_per_minute', 6000 ) );
		$bucket     = 'cu_collect_rl_' . CU_Geo::ip_key( CU_Geo::rate_bucket( $ip ) );
		// Déjà au-delà : refuser sans réécrire le compteur (aucune écriture pendant un flood).
		if ( self::counter( $bucket, MINUTE_IN_SECONDS ) >= $per_bucket ) {
			$limited = true;
		} else {
			$limited = self::counter( $bucket, MINUTE_IN_SECONDS, $events ) > $per_bucket
				|| self::counter( 'cu_collect_global', MINUTE_IN_SECONDS, $events ) > $global;
			if ( $limited ) {
				// Premier refus de cette adresse pour la minute : signalé au diagnostic.
				self::note_limited( 'rate' );
			}
		}
		if ( ! $limited ) {
			return null;
		}
		$response = new WP_REST_Response( array( 'ok' => false, 'error' => 'rate_limited' ), 429 );
		$response->header( 'Retry-After', '60' );
		return $response;
	}

	/** Réponse générique : ne révèle jamais pourquoi une visite est ignorée. */
	private static function collect_ok( $ok = true, $status = 200 ) {
		return new WP_REST_Response( array( 'ok' => (bool) $ok ), (int) $status );
	}

	// -------------------------------------------------------------------------
	// POST /collect
	// -------------------------------------------------------------------------

	/**
	 * Empreinte d'IP du jour : HMAC dont le sel est tiré au sort chaque jour puis
	 * détruit. Elle ne sert qu'à propager l'enrichissement réseau entre sessions
	 * d'une même journée (mode standard / consentement avec fournisseur externe),
	 * ne permet pas de reconnaître un visiteur d'un jour à l'autre et est effacée
	 * des sessions dès le lendemain (daily_maintenance()).
	 */
	private static function ip_salt() {
		$today  = CU_DB::date_from_now( 'Ymd' );
		$stored = get_option( 'cu_ip_salt_daily' );
		if ( is_array( $stored ) && isset( $stored['day'], $stored['salt'] ) && $today === $stored['day'] && is_string( $stored['salt'] ) && strlen( $stored['salt'] ) >= 32 ) {
			return $stored['salt'];
		}
		try {
			$salt = bin2hex( random_bytes( 32 ) );
		} catch ( Throwable $e ) {
			$salt = wp_generate_password( 64, true, true );
		}
		update_option( 'cu_ip_salt_daily', array( 'day' => $today, 'salt' => $salt ), false );
		// Sel statique des versions 1.1.x : il rendait l'empreinte stable dans le temps.
		if ( false !== get_option( 'cu_ip_salt', false ) ) {
			delete_option( 'cu_ip_salt' );
		}
		return $salt;
	}

	private static function day_ip_hash( $ip ) {
		return hash_hmac( 'sha256', CU_Geo::normalize_ip( $ip ), self::ip_salt() );
	}

	/**
	 * Pays du visiteur sans aucun appel réseau, dans TOUS les modes (visite suivie,
	 * page anonyme, mode exempté) : pays fourni par l'infrastructure (CDN, serveur)
	 * ou lu dans la base locale. L'adresse IP n'est comparée qu'en mémoire : elle
	 * n'est ni enregistrée ni transmise, seul le code pays est gardé. Un résultat
	 * d'API externe n'est jamais utilisé pour une page anonyme ni en mode exempté,
	 * même déjà en cache. En dernier recours (base absente) : pays du fuseau
	 * horaire annoncé par le navigateur.
	 */
	private static function visitor_country( $ip, $anonymous = false, $timezone = '' ) {
		// Pays posé par Cloudflare (même derrière un relais d'hébergeur) ou par le
		// serveur : la source la plus sûre, sans appel réseau, dans tous les modes.
		$country = (string) CU_Geo::audience_cdn_country();
		if ( '' !== $country ) {
			return $country;
		}
		if ( ( $anonymous || 'exempt' === self::privacy_mode() ) && CU_Geo::remote_lookups_allowed() ) {
			$country = (string) CU_Geo::local_country( $ip );
		} else {
			$country = (string) CU_Geo::country_for_ip( $ip, false );
		}
		if ( '' === $country && '' !== $timezone && CU_Geo::lookup_configured() ) {
			$country = (string) CU_Geo::country_from_timezone( $timezone );
		}
		return $country;
	}

	/**
	 * Valide le contenu déclaré par le beacon. Seuls sont acceptés : les types
	 * de contenu publics dont l'objet existe, est publiquement visible et du bon
	 * type ; les taxonomies publiques (sans identifiant : le tracker n'en envoie
	 * pas) ; les types spéciaux du tracker. Sinon : 0 / ''. Un identifiant forgé
	 * ne peut donc plus créer de ligne d'agrégat.
	 *
	 * @return array{0:int,1:string}
	 */
	private static function validate_object( $object_id, $object_type ) {
		$object_id = is_numeric( $object_id ) ? absint( $object_id ) : 0;
		$type      = sanitize_key( substr( (string) $object_type, 0, 30 ) );
		if ( in_array( $type, self::SPECIAL_OBJECT_TYPES, true ) ) {
			return array( 0, $type );
		}
		if ( '' === $type ) {
			return array( 0, '' );
		}
		if ( ! post_type_exists( $type ) ) {
			$taxonomy = taxonomy_exists( $type ) ? get_taxonomy( $type ) : null;
			return array( 0, ( $taxonomy && ! empty( $taxonomy->public ) ) ? $type : '' );
		}
		$type_object = get_post_type_object( $type );
		if ( ! $type_object || empty( $type_object->public ) ) {
			return array( 0, '' );
		}
		if ( ! $object_id ) {
			return array( 0, $type );
		}
		$post = get_post( $object_id );
		if ( ! $post || $post->post_type !== $type ) {
			return array( 0, $type );
		}
		$viewable = function_exists( 'is_post_publicly_viewable' ) ? is_post_publicly_viewable( $post ) : ( 'publish' === get_post_status( $post ) );
		return $viewable ? array( (int) $post->ID, $type ) : array( 0, $type );
	}

	/**
	 * Lot d'événements (tracker 1.2+) ou événement unique (tracker 1.1.x en cache).
	 *
	 * @return array|null Événements normalisés (peut être vide), null si malformé.
	 */
	private static function batch_events( array $p, array $ctx ) {
		$raw = isset( $p['events'] ) ? $p['events'] : array( $ctx );
		if ( ! is_array( $raw ) || ! $raw ) {
			return null;
		}
		$out = array();
		foreach ( array_slice( array_values( $raw ), 0, self::MAX_BATCH ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$event = array_filter( $event, 'is_scalar' );
			$type  = isset( $event['event_type'] ) ? sanitize_key( substr( (string) $event['event_type'], 0, 30 ) ) : 'pageview';
			if ( '' === $type || in_array( $type, self::DROPPED_EVENTS, true ) ) {
				continue;
			}
			$event['event_type'] = $type;
			$out[]               = $event;
		}
		return $out;
	}

	public static function handle_collect( WP_REST_Request $request ) {
		if ( ! CU_DB::tables_exist_for( array( 'sessions', 'events' ) ) ) {
			return self::collect_ok( false );
		}
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( $_SERVER['CONTENT_LENGTH'] ) : 0;
		if ( strlen( (string) $request->get_body() ) > 32768 || $content_length > 32768 ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'payload_too_large' ), 413 );
		}
		$p = $request->get_json_params();
		if ( ! is_array( $p ) ) {
			return self::collect_ok( false, 400 );
		}

		// Sonde du diagnostic « Vérifier le suivi » : prouve que la route répond à
		// un visiteur anonyme, sans rien écrire ni révéler de réglage.
		if ( ! empty( $p['cu_diag'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'diag' => true ), 200 );
		}

		$settings = CU_Settings::module( 'analytics' );
		if ( empty( $settings['enabled'] ) ) {
			return self::collect_ok( false );
		}

		// Contrôles sans base de données d'abord : origine, format, URL.
		$browser = self::request_origin_state();
		if ( false === $browser ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'cross_site_collect' ), 403 );
		}
		$ctx    = array_filter( $p, 'is_scalar' );
		$events = self::batch_events( $p, $ctx );
		if ( null === $events ) {
			return self::collect_ok( false, 400 );
		}
		if ( ! $events ) {
			return self::collect_ok(); // uniquement des types abandonnés (ancien tracker)
		}
		$session_uuid = isset( $ctx['session_id'] ) ? (string) $ctx['session_id'] : '';
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $session_uuid ) ) {
			return self::collect_ok( false, 400 );
		}
		$session_uuid = strtolower( $session_uuid );
		$url          = self::clean_url( isset( $ctx['url'] ) ? $ctx['url'] : '' );
		if ( '' === $url || ! self::is_allowed_host( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			return self::collect_ok( false, 400 );
		}

		// Mesure anonyme (refus, absence de réponse au bandeau, GPC) : une ligne par
		// page vue, sans identifiant persistant ; de l'IP, seul le pays est déduit
		// en mémoire (l'adresse n'est ni enregistrée ni transmise). Le champ
		// « anon » ne fait que choisir ce chemin : il ne peut jamais rendre anonyme
		// une visite suivie (seul le passage anonyme → suivi existe, plus bas).
		$anonymous = ! empty( $ctx['anon'] );
		if ( $anonymous && ( ! self::anon_ready() || empty( $settings['anonymous_fallback'] ) ) ) {
			// Mesure anonyme coupée, ou schéma pas encore migré : rien n'est écrit
			// (une page anonyme ne doit jamais devenir une visite suivie).
			return self::collect_ok();
		}

		// Exclusions : même réponse que pour une visite comptée.
		$ip = self::get_client_ip();
		// Le User-Agent vient de la requête HTTP, jamais du JSON contrôlé par le client.
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? CU_Text::substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
		$is_bot = self::is_bot_ua( $ua );
		if ( ( $is_bot && ! empty( $settings['exclude_bots'] ) ) || self::ip_is_excluded( $ip ) || self::user_is_excluded( self::request_user_id(), $settings ) ) {
			return self::collect_ok();
		}

		$limited = self::rate_limited( $ip, count( $events ) );
		if ( $limited ) {
			return $limited;
		}

		// « Fiable » : UA de navigateur, non automatisé, en-têtes d'un vrai
		// navigateur (Origin ou Referer : un script nu n'en envoie aucun), et pas
		// un appel du serveur à lui-même. Sinon : segment « suspect », jamais
		// supprimé. Aucun autre en-tête n'est exigé (proxys et filtres d'entreprise
		// en retirent parfois) : un vrai visiteur ne doit pas être écarté.
		$reliable = self::is_reliable_ua( $ua, $is_bot, ! empty( $ctx['wd'] ) )
			&& null !== $browser
			&& ! self::is_server_ip( $ip );

		// Signal de présence seul (onglet visible) : prolonge une session existante.
		$stored = array_values( array_filter( $events, function ( $event ) {
			return 'ping' !== $event['event_type'];
		} ) );
		$now = current_time( 'mysql' );
		if ( ! $stored ) {
			self::touch_session( $session_uuid, $now );
			return self::collect_ok();
		}

		global $wpdb;
		$session = self::find_session( $session_uuid );
		if ( false === $session ) {
			self::collect_db_failure( 'session_lookup_failed', $wpdb->last_error );
			return self::collect_ok( false );
		}
		// Lot rejoué à l'identique (relance réseau, bascule REST → admin-ajax du
		// tracker) : la page vue porte le jeton aléatoire de SA page, déjà enregistré
		// pour cette visite. Rien n'est recompté. Un rechargement de page est un
		// nouveau chargement, donc un nouveau jeton : il reste une page vue distincte.
		$page_token = isset( $ctx['pv'] ) && preg_match( '/^[a-f0-9]{16}$/', strtolower( (string) $ctx['pv'] ) ) ? strtolower( (string) $ctx['pv'] ) : '';
		$has_view   = false;
		foreach ( $stored as $event ) {
			if ( 'pageview' === $event['event_type'] ) {
				$has_view = true;
				break;
			}
		}
		if ( $session && $has_view && '' !== $page_token && self::pageview_seen( (int) $session['id'], $page_token ) ) {
			self::touch_session( $session_uuid, $now );
			return self::collect_ok();
		}
		$max_events = max( 50, (int) apply_filters( 'cu_analytics_max_events_per_session', 500 ) );
		$country    = '';
		if ( ! $session ) {
			// Une visite commence toujours par une page vue : un clic ou un défilement
			// d'une session inconnue ne crée rien.
			if ( 'pageview' !== $stored[0]['event_type'] ) {
				return self::collect_ok();
			}
			// Une page anonyme crée une ligne par page vue : son propre plafond, pour
			// qu'une navigation normale ne soit pas coupée par celui des visites.
			// Deux niveaux, par adresse IP (ou /64) et par heure : au-delà du plafond
			// normal, la visite reste ENREGISTRÉE mais classée douteuse (bureau,
			// hôtel, réseau mobile derrière une même IP : jamais supprimée en
			// silence) ; au-delà du plafond dur (saturation volontaire), elle est
			// écartée. Les deux cas sont signalés par « Vérifier le suivi ».
			if ( $anonymous ) {
				$per_hour = max( 5, (int) apply_filters( 'cu_analytics_anon_pages_per_hour', 300 ) );
				$cap_key  = 'cu_collect_na_';
			} else {
				$per_hour = max( 5, (int) apply_filters( 'cu_analytics_new_sessions_per_hour', 120 ) );
				$cap_key  = 'cu_collect_ns_';
			}
			$hard_cap = max( $per_hour, (int) apply_filters( 'cu_analytics_new_sessions_hard_per_hour', 5 * $per_hour, $anonymous ) );
			$attempts = self::counter( $cap_key . CU_Geo::ip_key( CU_Geo::rate_bucket( $ip ) ), HOUR_IN_SECONDS, 1 );
			if ( $attempts > $hard_cap ) {
				self::note_limited( 'dropped' );
				return self::collect_ok();
			}
			if ( $attempts > $per_hour ) {
				$reliable = false;
				self::note_limited( 'declassed' );
			}
			$country = self::visitor_country( $ip, $anonymous, isset( $ctx['tz'] ) ? CU_Text::substr( (string) $ctx['tz'], 0, 64 ) : '' );
			if ( '' !== $country && in_array( $country, array_map( 'strtoupper', (array) $settings['excluded_countries'] ), true ) ) {
				return self::collect_ok();
			}
			$room          = $max_events;
			$first_seen    = $now;
			$duration_done = 0;
		} else {
			$room          = $max_events - self::stored_event_count( (int) $session['id'], $max_events );
			$first_seen    = (string) $session['first_seen'];
			$duration_done = (int) $session['duration'];
		}
		if ( $room <= 0 ) {
			self::touch_session( $session_uuid, $now );
			return self::collect_ok();
		}

		list( $object_id, $object_type ) = self::validate_object( isset( $ctx['object_id'] ) ? $ctx['object_id'] : 0, isset( $ctx['object_type'] ) ? $ctx['object_type'] : '' );

		// La durée d'une session ne peut dépasser le temps écoulé depuis son début.
		$elapsed   = max( 0, (int) strtotime( $now ) - (int) strtotime( $first_seen ) );
		$time_cap  = $elapsed + self::DURATION_MARGIN;
		$time_left = max( 0, $time_cap - $duration_done );
		$built     = self::build_event_rows( $stored, $room, $settings, $object_id, $object_type, $url, $time_left );
		if ( '' !== $page_token ) {
			foreach ( $built['rows'] as $i => $built_row ) {
				if ( 'pageview' === $built_row['event_type'] ) {
					$built['rows'][ $i ]['meta'] = wp_json_encode( array( 'pv' => $page_token ) );
				}
			}
		}
		if ( ! $built['rows'] ) {
			self::touch_session( $session_uuid, $now );
			return self::collect_ok();
		}

		// Jamais d'IP du temps réel pour une page anonyme, ni pour une ligne créée anonyme.
		$row_anon = $session && isset( $session['anon'] ) ? (int) $session['anon'] : 0;
		$live_ip  = ( ! $is_bot && ! $anonymous && 0 === $row_anon && self::live_ip_allowed() ) ? self::encrypt_enrichment_ip( $ip ) : '';
		if ( $session ) {
			$session_id = (int) $session['id'];
			$set        = array( 'last_seen = %s', 'exit_url = %s' );
			$values     = array( $now, $url );
			// Accord donné pendant une page d'entrée anonyme : cette page devient le
			// début d'une visite suivie (toujours UNE visite). Une page anonyme
			// interne (anon = 2) reste une page : sa visite est déjà comptée.
			if ( ! $anonymous && 1 === $row_anon ) {
				$set[]    = 'anon = 0';
				$set[]    = 'is_new = %d';
				$values[] = ! empty( $ctx['is_new_session'] ) ? 1 : 0;
			}
			if ( $built['pageviews'] ) {
				$set[]    = 'pageviews = pageviews + %d';
				$values[] = $built['pageviews'];
				// L'IP peut changer en cours de visite (Wi-Fi → 4G) : la plus récente.
				if ( $live_ip ) {
					$set[]    = 'live_ip = %s';
					$values[] = $live_ip;
				}
			}
			if ( $built['duration'] ) {
				$set[]    = 'duration = LEAST( duration + %d, %d )';
				$values[] = $built['duration'];
				$values[] = $time_cap;
			}
			$values[] = $session_id;
			if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . CU_DB::table( 'sessions' ) . ' SET ' . implode( ', ', $set ) . ' WHERE id = %d', $values ) ) ) {
				self::collect_db_failure( 'session_update_failed', $wpdb->last_error );
				return self::collect_ok( false );
			}
		} else {
			$anon_level = 0;
			if ( $anonymous ) {
				// Page d'entrée (= une visite) : arrivée sans référent ou depuis un autre
				// site, hors rechargement et retour arrière (décidé par le navigateur).
				// Un référent du site lui-même n'est jamais une entrée.
				$ref_host   = (string) wp_parse_url( isset( $ctx['referrer'] ) ? (string) $ctx['referrer'] : '', PHP_URL_HOST );
				$entry      = ! empty( $ctx['entry'] ) && ( '' === $ref_host || ! self::is_allowed_host( $ref_host ) );
				$anon_level = $entry ? 1 : 2;
			}
			$session_id = self::create_session( $session_uuid, $now, $url, $ctx, $ua, $is_bot, $reliable, $country, $ip, $live_ip, $built, $anon_level );
			if ( ! $session_id ) {
				return self::collect_ok( false );
			}
		}

		$rows = self::attach_contacts( $built, $session_id, $session ? true : false, $settings, $now, $page_token );
		if ( $rows && false === self::insert_event_rows( $rows, $session_id, $now ) ) {
			self::collect_db_failure( 'event_insert_failed', $wpdb->last_error );
			return self::collect_ok( false );
		}
		return self::collect_ok();
	}

	/** Prolonge la présence d'une session existante (temps réel), sans rien créer. */
	private static function touch_session( $session_uuid, $now ) {
		global $wpdb;
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . CU_DB::table( 'sessions' ) . ' SET last_seen = %s WHERE session_uuid = %s', $now, $session_uuid ) );
		if ( false === $done ) {
			self::collect_db_failure( 'session_ping_failed', $wpdb->last_error );
		}
	}

	/** @return array|null|false Ligne de session, null si inconnue, false en cas d'erreur SQL. */
	private static function find_session( $session_uuid ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, first_seen, duration' . ( self::anon_ready() ? ', anon' : '' ) . ' FROM ' . CU_DB::table( 'sessions' ) . ' WHERE session_uuid = %s', $session_uuid ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/** La page vue portant ce jeton de page est-elle déjà enregistrée pour la session ? */
	private static function pageview_seen( $session_id, $page_token ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . CU_DB::table( 'events' ) . " WHERE event_type = 'pageview' AND session_id = %d AND meta = %s LIMIT 1",
			(int) $session_id,
			wp_json_encode( array( 'pv' => (string) $page_token ) )
		) );
	}

	/** Nombre d'événements déjà stockés pour la session, borné (parcours d'index limité). */
	private static function stored_event_count( $session_id, $limit ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ( SELECT 1 FROM ' . CU_DB::table( 'events' ) . ' WHERE session_id = %d LIMIT %d ) cu_capped',
			$session_id,
			(int) $limit
		) );
	}

	/**
	 * Construit les lignes d'événements du lot (sans session_id). Les durées sont
	 * bornées par beacon et par le temps réellement écoulé ; les contacts sont
	 * validés ; les conversions du navigateur restent « déclarées ».
	 *
	 * @return array{rows:array, contacts:array, pageviews:int, duration:int}
	 */
	private static function build_event_rows( array $events, $room, array $settings, $object_id, $object_type, $url, $time_left ) {
		$allowed  = (array) apply_filters( 'cu_analytics_allowed_event_types', self::STORED_EVENTS );
		$out      = array( 'rows' => array(), 'contacts' => array(), 'pageviews' => 0, 'duration' => 0 );
		foreach ( $events as $event ) {
			if ( count( $out['rows'] ) >= $room ) {
				break;
			}
			$type = $event['event_type'];
			if ( 'ping' === $type || ! in_array( $type, $allowed, true ) ) {
				continue;
			}
			$row = array(
				'event_type'     => $type,
				'object_id'      => $object_id ? (int) $object_id : null,
				'object_type'    => '' !== $object_type ? $object_type : null,
				'url'            => $url,
				'scroll_percent' => null,
				'duration'       => null,
				'meta'           => null,
			);
			switch ( $type ) {
				case 'pageview':
					$out['pageviews']++;
					break;
				case 'scroll':
					if ( ! isset( $event['scroll_percent'] ) || ! is_numeric( $event['scroll_percent'] ) ) {
						continue 2;
					}
					$row['scroll_percent'] = min( 100, max( 0, (int) $event['scroll_percent'] ) );
					break;
				case 'exit':
					$seconds = isset( $event['duration'] ) && is_numeric( $event['duration'] ) ? absint( $event['duration'] ) : 0;
					$seconds = min( $seconds, self::MAX_EXIT_SECONDS, $time_left );
					if ( $seconds <= 0 ) {
						continue 2;
					}
					$time_left      -= $seconds;
					$out['duration'] += $seconds;
					$row['duration']  = $seconds;
					break;
				case 'js_error':
					$message = self::clean_error_text( isset( $event['message'] ) ? $event['message'] : '' );
					if ( '' === $message ) {
						continue 2; // échec de chargement de ressource : rien d'exploitable
					}
					$meta   = array( 'message' => $message );
					$source = self::clean_url( isset( $event['source'] ) ? $event['source'] : '', 300, true );
					if ( '' !== $source ) {
						$meta['source'] = $source;
					}
					if ( isset( $event['line'] ) && is_numeric( $event['line'] ) ) {
						$meta['line'] = absint( $event['line'] );
					}
					$row['meta'] = wp_json_encode( $meta );
					break;
				case 'conversion':
					// Envoyée par le navigateur (formulaire marqué data-cu-conversion) :
					// toujours « déclarée », jamais confirmée.
					$row['meta'] = wp_json_encode( array( 'declared' => 1, 'via' => 'form' ) );
					break;
				case 'outbound':
					if ( isset( $settings['track_outbound'] ) && empty( $settings['track_outbound'] ) ) {
						continue 2; // suivi coupé : une page en cache n'écrit rien
					}
					$outbound = self::outbound_payload( $event );
					if ( null === $outbound ) {
						continue 2;
					}
					$row['meta'] = wp_json_encode( $outbound );
					break;
				case 'whatsapp':
				case 'cf7':
					if ( empty( $settings[ 'whatsapp' === $type ? 'track_whatsapp' : 'track_cf7' ] ) ) {
						continue 2; // suivi coupé : une page en cache n'écrit rien
					}
					$contact = self::contact_payload( $type, $event );
					if ( null === $contact ) {
						continue 2;
					}
					$contact['scroll_pct'] = isset( $event['scroll_percent'] ) && is_numeric( $event['scroll_percent'] ) ? min( 100, max( 0, (int) $event['scroll_percent'] ) ) : null;
					$contact['row']        = count( $out['rows'] );
					$row['meta']           = wp_json_encode( array( 'contact' => $contact['channel'] . ':' . $contact['action'] ) );
					$out['contacts'][]     = $contact;
					break;
			}
			$out['rows'][] = $row;
		}
		return $out;
	}

	/** Extensions de fichiers comptées comme téléchargements (même liste que le tracker). */
	const DOWNLOAD_EXT_RE = '/\.(pdf|zip|rar|7z|gz|tgz|docx?|xlsx?|pptx?|odt|ods|odp|csv|txt|rtf|epub|mp3|wav|m4a|mp4|mov|avi|webm|apk|exe|msi|dmg|pkg|iso)$/i';

	/**
	 * Clic sortant envoyé par le tracker : lien externe ou téléchargement (adresse
	 * sans paramètres), appel (numéro du site) ou e-mail (DOMAINE seulement). Les
	 * liens WhatsApp sont suivis à part (contacts).
	 *
	 * @return array|null { k: genre, u: cible, l?: texte du lien }
	 */
	private static function outbound_payload( array $event ) {
		$kind   = isset( $event['ob_kind'] ) ? sanitize_key( substr( (string) $event['ob_kind'], 0, 10 ) ) : '';
		$target = isset( $event['ob_target'] ) ? trim( (string) $event['ob_target'] ) : '';
		switch ( $kind ) {
			case 'tel':
				$target = (string) preg_replace( '/\D+/', '', $target );
				if ( ! preg_match( '/^\d{3,15}$/', $target ) ) {
					return null;
				}
				break;
			case 'mail':
				$target = strtolower( substr( $target, 0, 190 ) );
				if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $target ) ) {
					return null;
				}
				break;
			case 'external':
			case 'download':
				$target = self::clean_url( $target, 300, true );
				$host   = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
				if ( '' === $target || '' === $host || preg_match( '/(^|\.)(wa\.me|wa\.link|whatsapp\.com)$/', $host ) ) {
					return null;
				}
				if ( 'download' === $kind ? ! preg_match( self::DOWNLOAD_EXT_RE, (string) wp_parse_url( $target, PHP_URL_PATH ) ) : self::is_allowed_host( $host ) ) {
					return null; // lien interne qui n'est pas un fichier
				}
				break;
			default:
				return null;
		}
		$label = isset( $event['label'] ) ? CU_Text::substr( sanitize_text_field( (string) $event['label'] ), 0, 80 ) : '';
		return array( 'k' => $kind, 'u' => $target ) + ( '' !== $label ? array( 'l' => $label ) : array() );
	}

	/**
	 * Crée la session. INSERT … ON DUPLICATE KEY UPDATE règle sans perte la course
	 * de deux premiers beacons simultanés : l'identifiant renvoyé est toujours
	 * celui de la ligne gagnante.
	 *
	 * @param bool $reliable Trafic humain probable (voir handle_collect()).
	 * @param int  $anon     0 = visite suivie, 1 = page anonyme d'entrée, 2 = page anonyme interne.
	 * @return int Identifiant, 0 en cas d'échec (journalisé).
	 */
	private static function create_session( $session_uuid, $now, $url, array $ctx, $ua, $is_bot, $reliable, $country, $ip, $live_ip, array $built, $anon = 0 ) {
		global $wpdb;
		$anon      = (int) $anon;
		$ua_parsed = self::parse_ua( $ua );
		// Page anonyme interne : provenance inconnue (la visite est déjà comptée).
		$referrer  = 2 !== $anon && isset( $ctx['referrer'] ) ? CU_Text::substr( (string) $ctx['referrer'], 0, 2000 ) : '';
		$landing   = $anon ? '' : self::clean_url( isset( $ctx['landing_url'] ) ? $ctx['landing_url'] : '' );
		if ( '' === $landing || ! self::is_allowed_host( (string) wp_parse_url( $landing, PHP_URL_HOST ) ) ) {
			$landing = $url; // une landing externe fausserait les rapports d'entrée
		}
		$source = self::classify_referrer( $referrer );
		$query  = (string) wp_parse_url( $landing, PHP_URL_QUERY );
		if ( ( ! $anon && ! empty( $ctx['utm_source'] ) ) || preg_match( '/(^|&)utm_source=[^&]/i', $query ) ) {
			$source = 'campaign';
		}
		// Ni empreinte d'IP, ni géolocalisation externe pour une page anonyme.
		$remote   = ! $is_bot && ! $anon && self::remote_enrichment_allowed();
		$row      = array(
			'session_uuid'    => $session_uuid,
			'first_seen'      => $now,
			'last_seen'       => $now,
			'ip_hash'         => $remote ? self::day_ip_hash( $ip ) : '',
			'country_code'    => '' !== $country ? $country : null,
			'device'          => $ua_parsed['device'],
			'browser'         => $ua_parsed['browser'],
			'os'              => $ua_parsed['os'],
			// Page anonyme : nouveau ou fidèle est inconnu (exclu des rapports de fidélité).
			'is_new'          => ! $anon && ! empty( $ctx['is_new_session'] ) ? 1 : 0,
			'is_bot'          => $is_bot ? 1 : 0,
			'is_reliable'     => $reliable ? 1 : 0,
			'is_excluded'     => 0,
			'referrer_source' => 2 === $anon ? null : $source,
			'referrer_host'   => 2 === $anon ? null : ( self::referrer_host_from( $referrer ) ?: null ),
			'landing_url'     => $landing,
			'exit_url'        => $url,
			'pageviews'       => (int) $built['pageviews'],
			'duration'        => min( (int) $built['duration'], self::DURATION_MARGIN ),
		);
		if ( self::anon_ready() ) {
			$row['anon'] = $anon;
		}
		// Langue du navigateur et taille d'écran (schéma 1.9.4).
		if ( self::schema_at_least( '1.9.4' ) ) {
			$lang          = isset( $ctx['lang'] ) ? strtolower( substr( (string) $ctx['lang'], 0, 16 ) ) : '';
			$scr           = isset( $ctx['scr'] ) ? substr( (string) $ctx['scr'], 0, 12 ) : '';
			$row['language'] = preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $lang ) ? $lang : null;
			$row['screen']   = preg_match( '/^[1-9]\d{2,4}x[1-9]\d{2,4}$/', $scr ) ? $scr : null;
		}
		if ( $remote ) {
			$token = self::encrypt_enrichment_ip( $ip );
			if ( $token ) {
				$row['geo_token']     = $token;
				$row['geo_queued_at'] = $now;
			}
		}
		if ( $live_ip ) {
			$row['live_ip'] = $live_ip;
		}
		$columns = array_keys( $row );
		$holders = array();
		foreach ( $row as $value ) {
			$holders[] = null === $value ? 'NULL' : ( is_int( $value ) ? '%d' : '%s' );
		}
		$sql = 'INSERT INTO ' . CU_DB::table( 'sessions' ) . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $holders ) . ')'
			. ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), last_seen = VALUES(last_seen), exit_url = VALUES(exit_url),'
			. ' pageviews = pageviews + VALUES(pageviews),'
			. ' duration = LEAST( duration + VALUES(duration), TIMESTAMPDIFF( SECOND, first_seen, VALUES(last_seen) ) + ' . (int) self::DURATION_MARGIN . ' )';
		$wpdb->last_error = '';
		$done             = $wpdb->query( $wpdb->prepare( $sql, array_values( array_filter( $row, function ( $value ) {
			return null !== $value;
		} ) ) ) );
		$session_id = false !== $done ? (int) $wpdb->insert_id : 0;
		if ( ! $session_id ) {
			self::collect_db_failure( 'session_insert_failed', $wpdb->last_error );
			return 0;
		}
		// Pays d'un nouveau visiteur : une géolocalisation ponctuelle (une seule en
		// attente à la fois) l'affiche dans le temps réel en quelques secondes.
		if ( ! empty( $row['geo_token'] ) && ! wp_next_scheduled( 'cu_cron_analytics_enrich_now' ) ) {
			wp_schedule_single_event( time(), 'cu_cron_analytics_enrich_now' );
		}
		return $session_id;
	}

	/**
	 * Enregistre les contacts du lot (WhatsApp, Contact Form 7) et ajoute les
	 * conversions DÉCLARÉES qui en découlent selon les réglages. Un même clic ou
	 * envoi reçu deux fois en quelques secondes n'est compté qu'une fois.
	 *
	 * @return array Lignes d'événements à insérer.
	 */
	private static function attach_contacts( array $built, $session_id, $existing_session, array $settings, $now, $page_token = '' ) {
		global $wpdb;
		$rows = $built['rows'];
		if ( ! $built['contacts'] ) {
			return $rows;
		}
		$table_ok = self::schema_at_least( '1.8.9' ) && CU_DB::tables_exist_for( array( 'contacts' ) );
		$c_tbl    = CU_DB::table( 'contacts' );
		$e_tbl    = CU_DB::table( 'events' );
		$seen     = array();
		$new_lead = false;
		foreach ( $built['contacts'] as $contact ) {
			$index = $contact['row'];
			unset( $contact['row'] );
			$is_click = 'whatsapp' === $contact['channel'];
			$is_sent  = 'cf7' === $contact['channel'] && 'sent' === $contact['action'];
			if ( ( $is_click || $is_sent ) && '' !== $page_token ) {
				// Doublon technique = le MÊME geste reçu deux fois (relance réseau, bascule
				// REST → admin-ajax) : même chargement de page (jeton aléatoire de la page,
				// jamais stocké dans le navigateur), même instant, même cible. L'empreinte
				// est gardée dans la ligne d'événement du contact. Un clic fait depuis un
				// autre chargement de page, ou plus tard sur la même page (le tracker
				// sépare déjà les clics de 1,5 s), est toujours un contact distinct.
				$signature = substr( md5( implode( '|', array(
					$page_token,
					$contact['channel'],
					$contact['action'],
					(string) $contact['target'],
					isset( $contact['kind'] ) ? (string) $contact['kind'] : '',
					isset( $contact['widget'] ) ? (string) $contact['widget'] : '',
					isset( $contact['page_seconds'] ) && null !== $contact['page_seconds'] ? (int) $contact['page_seconds'] : '',
					isset( $contact['fill_seconds'] ) && null !== $contact['fill_seconds'] ? (int) $contact['fill_seconds'] : '',
				) ) ), 0, 16 );
				$meta      = wp_json_encode( array( 'contact' => $contact['channel'] . ':' . $contact['action'], 'k' => $signature ) );
				$dup       = isset( $seen[ $signature ] );
				if ( ! $dup && $existing_session ) {
					$dup = (bool) $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$e_tbl} WHERE session_id = %d AND event_type = %s AND meta = %s LIMIT 1",
						$session_id,
						$contact['channel'],
						$meta
					) );
				}
				if ( $dup ) {
					unset( $rows[ $index ] );
					continue;
				}
				$seen[ $signature ]    = true;
				$rows[ $index ]['meta'] = $meta;
			} elseif ( $table_ok && ( $is_click || $is_sent ) ) {
				// Tracker antérieur encore en cache (aucun jeton de page) : même cible
				// reçue deux fois en moins de 5 s.
				$where = array( 'session_id = %d', 'channel = %s', 'action = %s', 'created_at >= %s' );
				$args  = array( $session_id, $contact['channel'], $contact['action'], gmdate( 'Y-m-d H:i:s', (int) strtotime( $now ) - 5 ) );
				if ( null === $contact['target'] ) {
					$where[] = 'target IS NULL';
				} else {
					$where[] = 'target = %s';
					$args[]  = (string) $contact['target'];
				}
				if ( $is_click ) {
					$where[] = 'kind = %s';
					$args[]  = (string) $contact['kind'];
					$where[] = 'widget = %s';
					$args[]  = (string) $contact['widget'];
				}
				$dup = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$c_tbl} WHERE " . implode( ' AND ', $where ) . ' LIMIT 1', $args ) );
				if ( $dup ) {
					unset( $rows[ $index ] );
					continue;
				}
			}
			if ( $is_click || $is_sent ) {
				$new_lead = true;
			}
			$row = $rows[ $index ];
			if ( $table_ok ) {
				$record = array_merge(
					array(
						'session_id'  => (int) $session_id,
						'object_id'   => $row['object_id'],
						'object_type' => $row['object_type'],
						'url'         => $row['url'],
						'created_at'  => $now,
					),
					$contact
				);
				if ( false === $wpdb->insert( $c_tbl, $record ) ) {
					self::collect_db_failure( 'contact_insert_failed', $wpdb->last_error );
				}
			}
			// Conversion déclarée (navigateur) : un clic WhatsApp n'est qu'une intention
			// de contact, un envoi CF7 vu par le navigateur n'est pas confirmé — la
			// confirmation serveur (wpcf7_mail_sent) est comptée séparément.
			if ( ( $is_click && ! empty( $settings['whatsapp_conversion'] ) ) || ( $is_sent && ! empty( $settings['cf7_conversion'] ) ) ) {
				$conversion               = $row;
				$conversion['event_type'] = 'conversion';
				$conversion['meta']       = wp_json_encode( array( 'declared' => 1, 'via' => $contact['channel'] ) );
				$rows[]                   = $conversion;
			}
		}
		if ( $new_lead ) {
			// Un nouveau contact (clic WhatsApp, message envoyé) doit apparaître tout
			// de suite : les rapports en cache (10 min par défaut) sont périmés.
			update_option( 'cu_analytics_cache_epoch', wp_generate_uuid4(), false );
		}
		return array_values( $rows );
	}

	/** INSERT multi-lignes d'un lot d'événements (une seule requête). */
	private static function insert_event_rows( array $rows, $session_id, $now ) {
		global $wpdb;
		$columns = array( 'session_id' => '%d', 'event_type' => '%s', 'object_id' => '%d', 'object_type' => '%s', 'url' => '%s', 'scroll_percent' => '%d', 'meta' => '%s', 'created_at' => '%s' );
		// Compatibilité de déploiement : une requête publique peut arriver avant dbDelta().
		if ( self::schema_at_least( '1.8.5' ) ) {
			$columns['duration'] = '%d';
		}
		$groups = array();
		$values = array();
		foreach ( $rows as $row ) {
			$row['session_id'] = (int) $session_id;
			$row['created_at'] = $now;
			$holders           = array();
			foreach ( $columns as $column => $format ) {
				$value = isset( $row[ $column ] ) ? $row[ $column ] : null;
				if ( null === $value ) {
					$holders[] = 'NULL';
				} else {
					$holders[] = $format;
					$values[]  = $value;
				}
			}
			$groups[] = '(' . implode( ', ', $holders ) . ')';
		}
		$wpdb->last_error = '';
		return $wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . CU_DB::table( 'events' ) . ' (' . implode( ', ', array_keys( $columns ) ) . ') VALUES ' . implode( ', ', $groups ),
			$values
		) );
	}

	// -------------------------------------------------------------------------
	// Contacts : WhatsApp et Contact Form 7
	// -------------------------------------------------------------------------

	/** Formes de lien WhatsApp reconnues par le tracker. */
	const WA_KINDS = array( 'chat', 'group', 'channel', 'catalog', 'short', 'unknown' );

	/**
	 * Étapes Contact Form 7 suivies dans le navigateur : formulaire vu, commencé,
	 * puis issue de l'envoi (réussi, champ invalide, spam, échec du courriel,
	 * interrompu) ou abandon. « server » est réservé au crochet wpcf7_mail_sent.
	 */
	const CF7_STATUSES = array( 'view', 'start', 'sent', 'invalid', 'spam', 'failed', 'aborted', 'abandon' );

	/** Issues d'un envoi Contact Form 7 (toutes les tentatives d'envoi). */
	const CF7_ATTEMPTS = array( 'sent', 'invalid', 'spam', 'failed', 'aborted' );

	/**
	 * Normalise le détail d'un contact envoyé par le tracker. Seul le numéro de
	 * destination (celui du site) est conservé, jamais le message pré-rempli ni
	 * une valeur saisie dans un formulaire.
	 *
	 * @return array|null Ligne prête pour cu_contacts, ou null si incohérente.
	 */
	private static function contact_payload( $event_type, array $p ) {
		$text = function ( $key, $max ) use ( $p ) {
			return isset( $p[ $key ] ) ? CU_Text::substr( sanitize_text_field( (string) $p[ $key ] ), 0, $max ) : '';
		};
		$seconds = function ( $key ) use ( $p ) {
			return isset( $p[ $key ] ) && is_numeric( $p[ $key ] ) ? min( DAY_IN_SECONDS, absint( $p[ $key ] ) ) : null;
		};
		$label = $text( 'label', 120 );
		$row   = array(
			'label'        => '' !== $label ? $label : null,
			'page_seconds' => $seconds( 'page_seconds' ),
		);

		if ( 'whatsapp' === $event_type ) {
			$kind      = sanitize_key( $text( 'wa_kind', 12 ) );
			$phone     = (string) preg_replace( '/\D+/', '', $text( 'wa_phone', 32 ) );
			$widget    = sanitize_key( $text( 'wa_widget', 30 ) );
			$placement = sanitize_key( $text( 'wa_placement', 10 ) );
			return $row + array(
				'channel'   => 'whatsapp',
				'action'    => 'click',
				'kind'      => in_array( $kind, self::WA_KINDS, true ) ? $kind : 'unknown',
				// Numéro international E.164 : 6 à 15 chiffres, sans « + » ni zéros de tête.
				'target'    => preg_match( '/^[1-9]\d{5,14}$/', $phone ) ? $phone : null,
				'widget'    => '' !== $widget ? $widget : 'link',
				'placement' => in_array( $placement, array( 'floating', 'inline' ), true ) ? $placement : null,
			);
		}

		$status = sanitize_key( $text( 'cf7_status', 12 ) );
		$form   = isset( $p['form_id'] ) && is_numeric( $p['form_id'] ) ? absint( $p['form_id'] ) : 0;
		if ( ! $form || ! in_array( $status, self::CF7_STATUSES, true ) ) {
			return null;
		}
		// Noms des champs refusés (« your-email »…), jamais leur contenu.
		$fields = array();
		foreach ( explode( ',', $text( 'cf7_fields', 400 ) ) as $field ) {
			$field = CU_Text::substr( sanitize_key( $field ), 0, 40 );
			if ( '' !== $field && ! in_array( $field, $fields, true ) ) {
				$fields[] = $field;
			}
			if ( count( $fields ) >= 6 ) {
				break;
			}
		}
		$fields = implode( ',', $fields );
		return $row + array(
			'channel'      => 'cf7',
			'action'       => $status,
			'target'       => (string) $form,
			'fill_seconds' => in_array( $status, self::CF7_ATTEMPTS, true ) ? $seconds( 'fill_seconds' ) : null,
			'fields'       => '' !== $fields && 'invalid' === $status ? CU_Text::substr( $fields, 0, 255 ) : null,
		);
	}

	/**
	 * Envoi CONFIRMÉ par le serveur (crochet wpcf7_mail_sent de Contact Form 7) :
	 * la référence des messages réellement partis, indépendante du navigateur.
	 * Aucune donnée de visiteur (ni IP, ni session) : un simple compteur par
	 * formulaire, compté dans tous les modes de confidentialité.
	 *
	 * @param object $contact_form Instance WPCF7_ContactForm.
	 */
	public static function record_cf7_server_sent( $contact_form ) {
		try {
			if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
				return;
			}
			if ( ! self::schema_at_least( '1.8.9' ) || ! CU_Settings::get( 'analytics', 'track_cf7', true ) || ! CU_DB::tables_exist_for( array( 'contacts' ) ) ) {
				return;
			}
			// Mêmes exclusions que le suivi du navigateur : l'administrateur qui teste
			// son formulaire et les adresses IP exclues ne sont pas des contacts.
			if ( self::user_is_excluded( self::request_user_id(), CU_Settings::module( 'analytics' ) ) || self::ip_is_excluded( self::get_client_ip() ) ) {
				return;
			}
			$form_id = absint( $contact_form->id() );
			if ( ! $form_id ) {
				return;
			}
			$container = 0;
			$url       = null;
			if ( class_exists( 'WPCF7_Submission' ) ) {
				$submission = WPCF7_Submission::get_instance();
				if ( $submission && method_exists( $submission, 'get_meta' ) ) {
					$container = absint( $submission->get_meta( 'container_post_id' ) );
					$page_url  = self::clean_url( (string) $submission->get_meta( 'url' ) );
					$url       = '' !== $page_url ? $page_url : null;
				}
			}
			$title = method_exists( $contact_form, 'title' ) ? CU_Text::substr( sanitize_text_field( (string) $contact_form->title() ), 0, 120 ) : '';
			global $wpdb;
			$wpdb->insert(
				CU_DB::table( 'contacts' ),
				array(
					'session_id'  => 0,
					'channel'     => 'cf7',
					'action'      => 'server',
					'target'      => (string) $form_id,
					'label'       => '' !== $title ? $title : null,
					'object_id'   => $container ? $container : null,
					'object_type' => $container ? ( get_post_type( $container ) ?: null ) : null,
					'url'         => $url,
					'created_at'  => current_time( 'mysql' ),
				)
			);
		} catch ( Throwable $e ) {
			// Le suivi ne doit jamais perturber l'envoi d'un formulaire.
			unset( $e );
		}
	}

	/** Chiffre une IP conservée temporairement (file de géolocalisation, IP du temps réel). */
	private static function encrypt_enrichment_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) return '';
		$key = hash( 'sha256', wp_salt( 'auth' ) . '|cu-analytics-geo', true );
		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				return 's.' . base64_encode( $nonce . sodium_crypto_secretbox( $ip, $nonce, $key ) );
			}
			if ( function_exists( 'openssl_encrypt' ) ) {
				$iv     = random_bytes( 12 );
				$tag    = '';
				$cipher = openssl_encrypt( $ip, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'cu-analytics', 16 );
				return false === $cipher ? '' : 'o.' . base64_encode( $iv . $tag . $cipher );
			}
		} catch ( Throwable $e ) {
			return '';
		}
		return '';
	}

	/** Déchiffre et valide l'IP d'un job ; toute charge altérée est ignorée. */
	private static function decrypt_enrichment_ip( $token ) {
		if ( ! is_string( $token ) || strlen( $token ) > 512 ) return '';
		$key = hash( 'sha256', wp_salt( 'auth' ) . '|cu-analytics-geo', true );
		$raw = base64_decode( substr( $token, 2 ), true );
		if ( false === $raw ) return '';
		try {
			if ( 0 === strpos( $token, 's.' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
				if ( strlen( $raw ) <= $nonce_len ) return '';
				$ip = sodium_crypto_secretbox_open( substr( $raw, $nonce_len ), substr( $raw, 0, $nonce_len ), $key );
			} elseif ( 0 === strpos( $token, 'o.' ) && function_exists( 'openssl_decrypt' ) ) {
				if ( strlen( $raw ) <= 28 ) return '';
				$ip = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'cu-analytics' );
			} else {
				return '';
			}
		} catch ( Throwable $e ) {
			return '';
		}
		return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Durée de conservation de l'IP du temps réel après la dernière activité (s). */
	const LIVE_IP_TTL = 3600;

	/**
	 * Efface l'IP chiffrée des visiteurs inactifs depuis plus de LIVE_IP_TTL, ou
	 * toutes les IP si leur conservation n'est pas permise (mode exempté ou
	 * consentement, réglage coupé). Lancée toutes les 5 minutes, bornée par
	 * l'index last_seen ; la tâche quotidienne couvre les 7 derniers jours.
	 */
	public static function purge_live_ips( $window = 6 * HOUR_IN_SECONDS ) {
		if ( ! self::schema_at_least( '1.8.7' ) || ! CU_DB::tables_exist_for( array( 'sessions' ) ) ) return;
		$allowed = self::live_ip_allowed();
		if ( $allowed ) {
			delete_transient( 'cu_live_ip_clean' );
		} elseif ( $window <= 6 * HOUR_IN_SECONDS && get_transient( 'cu_live_ip_clean' ) ) {
			return; // mode sans IP et dernière passe vide : plus aucune ligne n'en contient
		}
		global $wpdb;
		$s_tbl  = CU_DB::table( 'sessions' );
		$now    = (int) current_time( 'timestamp' );
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - ( $allowed ? self::LIVE_IP_TTL : 0 ) );
		$floor  = gmdate( 'Y-m-d H:i:s', $now - ( $allowed ? self::LIVE_IP_TTL : 0 ) - (int) $window );
		$done   = $wpdb->query( $wpdb->prepare( "UPDATE {$s_tbl} SET live_ip = NULL WHERE last_seen >= %s AND last_seen <= %s AND live_ip IS NOT NULL", $floor, $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $done ) {
			self::collect_db_failure( 'live_ip_purge_failed', $wpdb->last_error );
		} elseif ( ! $allowed && 0 === (int) $done ) {
			set_transient( 'cu_live_ip_clean', 1, DAY_IN_SECONDS );
		}
	}

	/** Traite une petite file SQL, sans créer un événement cron par visiteur. */
	public static function run_enrichment_queue() {
		if ( ! self::schema_at_least( '1.8.6' ) || ! CU_DB::tables_exist_for( array( 'sessions' ) ) ) return;
		self::purge_live_ips();
		global $wpdb;
		$s_tbl = CU_DB::table( 'sessions' );
		// Requêtes bornées par l'index geo_pending (enriched, geo_queued_at) : un
		// jeton n'existe jamais sans date de mise en file, ni après enrichissement.
		if ( ! CU_Settings::get( 'analytics', 'enabled', true ) || ! self::remote_enrichment_allowed() ) {
			// File inutile dans ce mode : effacer les jetons restants, puis plus rien.
			if ( $wpdb->get_var( "SELECT id FROM {$s_tbl} WHERE enriched = 0 AND geo_queued_at IS NOT NULL LIMIT 1" ) ) {
				$wpdb->query( "UPDATE {$s_tbl} SET geo_token = NULL, geo_queued_at = NULL WHERE enriched = 0 AND geo_queued_at IS NOT NULL" );
			}
			return;
		}

		// Ne conserver aucune charge chiffrée au-delà de 24 h si le fournisseur
		// reste indisponible. Chaque cycle traite au plus 25 sessions.
		$stale = CU_DB::date_from_now( 'Y-m-d H:i:s', -DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE {$s_tbl} SET geo_token = NULL, geo_queued_at = NULL WHERE enriched = 0 AND geo_queued_at < %s", $stale ) );
		$rows = $wpdb->get_results(
			"SELECT id, geo_token, ip_hash FROM {$s_tbl} WHERE enriched = 0 AND geo_queued_at IS NOT NULL AND geo_token IS NOT NULL ORDER BY geo_queued_at ASC LIMIT 25",
			ARRAY_A
		);
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			self::collect_db_failure( 'enrichment_queue_read_failed', $wpdb->last_error );
			return;
		}

		$changed = false;
		foreach ( $rows as $row ) {
			$ip = self::decrypt_enrichment_ip( $row['geo_token'] );
			if ( ! $ip ) {
				$wpdb->update( $s_tbl, array( 'geo_token' => null, 'geo_queued_at' => null ), array( 'id' => (int) $row['id'] ) );
				continue;
			}
			if ( self::enrich_session( (int) $row['id'], $ip, (bool) CU_Settings::get( 'analytics', 'filter_datacenter', true ), (string) $row['ip_hash'] ) ) {
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'cu_analytics_cache_epoch', wp_generate_uuid4(), false );
			if ( ! wp_next_scheduled( 'cu_cron_rollup_daily' ) || wp_next_scheduled( 'cu_cron_rollup_daily' ) > time() + 10 * MINUTE_IN_SECONDS ) {
				wp_schedule_single_event( time() + 5, 'cu_cron_rollup_daily' );
			}
		}
	}

	/**
	 * Enrichit une session avec les signaux réseau (datacenter / proxy / ASN) via
	 * CU_Geo::enrich() (cache 7 j par IP). Appelée par la file WP-Cron. Le
	 * résultat est propagé aux sessions du même jour portant la même empreinte.
	 * Un trafic datacenter/proxy est retiré du segment « fiable ». Budget par
	 * minute pour respecter la limite de l'API gratuite.
	 */
	public static function enrich_session( $session_db_id, $ip, $classify_network = true, $ip_hash = '' ) {
		if ( ! $session_db_id || empty( $ip ) ) {
			return false;
		}
		if ( ! self::remote_enrichment_allowed() || ! CU_DB::tables_exist_for( array( 'sessions' ) ) ) {
			return false;
		}

		// Budget d'appels par minute : ne compte QUE les vrais appels API (IP jamais
		// vues). Les IP déjà connues sont servies par le cache 7 j de enrich().
		$is_cached = ( false !== get_transient( CU_Geo::enrich_cache_key( $ip ) ) );
		if ( ! $is_cached ) {
			$minute_key = 'cu_enrich_budget_' . gmdate( 'YmdHi' );
			$used       = (int) get_transient( $minute_key );
			if ( $used >= 40 ) {
				return false; // budget épuisé : le prochain cycle reprendra la file
			}
			set_transient( $minute_key, $used + 1, 90 );
		}

		$info = CU_Geo::enrich( $ip );
		if ( empty( $info['source'] ) ) {
			return false; // enrichissement indisponible → nouvelle tentative bornée
		}

		global $wpdb;
		$s_tbl = CU_DB::table( 'sessions' );
		$is_dc = $classify_network && ! empty( $info['is_datacenter'] );
		$is_px = $classify_network && ! empty( $info['is_proxy'] );

		$data = array(
			'country_code'  => ! empty( $info['country'] ) ? substr( strtoupper( (string) $info['country'] ), 0, 2 ) : null,
			'enriched'      => 1,
			'geo_token'     => null,
			'geo_queued_at' => null,
		);
		if ( $classify_network ) {
			$data['is_datacenter'] = $is_dc ? 1 : 0;
			$data['is_proxy']      = $is_px ? 1 : 0;
			$data['asn']           = isset( $info['asn'] ) ? CU_Text::substr( (string) $info['asn'], 0, 60 ) : null;
		}
		$excluded_countries = array_map( 'strtoupper', (array) CU_Settings::get( 'analytics', 'excluded_countries', array() ) );
		if ( $data['country_code'] && in_array( $data['country_code'], $excluded_countries, true ) ) {
			$data['is_excluded'] = 1;
		}
		// Un humain réel ne navigue pas depuis un datacenter/proxy : hors « fiable ».
		if ( $is_dc || $is_px ) {
			$data['is_reliable'] = 0;
		}
		if ( false === $wpdb->update( $s_tbl, $data, array( 'id' => (int) $session_db_id ) ) ) {
			self::collect_db_failure( 'session_enrichment_failed', $wpdb->last_error );
			return false;
		}
		// Sessions sœurs du même jour (même empreinte, index ip_hash). Le sel change
		// chaque jour : aucune propagation possible d'un jour à l'autre.
		if ( '' !== (string) $ip_hash ) {
			$siblings = $data;
			unset( $siblings['geo_token'], $siblings['geo_queued_at'], $siblings['enriched'] );
			$set    = array();
			$values = array();
			foreach ( $siblings as $column => $value ) {
				$set[]    = null === $value ? "{$column} = NULL" : "{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
				if ( null !== $value ) {
					$values[] = $value;
				}
			}
			$values[] = (string) $ip_hash;
			$values[] = (int) $session_db_id;
			$wpdb->query( $wpdb->prepare( "UPDATE {$s_tbl} SET " . implode( ', ', $set ) . ' WHERE ip_hash = %s AND id <> %d', $values ) );
		}
		return true;
	}

	/**
	 * Journalise une panne SQL de collecte sans exposer de donnée visiteur. Une
	 * même panne (code + message) n'est écrite qu'une fois par 10 minutes : sans
	 * cela, chaque visite ajouterait une ligne au journal.
	 */
	private static function collect_db_failure( $code, $db_error = '' ) {
		if ( ! class_exists( 'CU_Logger' ) ) {
			return;
		}
		$code  = sanitize_key( $code );
		$error = CU_Text::substr( sanitize_text_field( (string) $db_error ), 0, 500 );
		$key   = 'cu_analytics_collect_err_' . md5( $code . '|' . $error );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		CU_Logger::log(
			'analytics',
			'error',
			sprintf( /* translators: %s: internal failure code */ __( 'Échec SQL pendant la collecte Analytics (%s).', 'cyberpush-ultra' ), $code ),
			array( 'db_error' => $error )
		);
	}

	/**
	 * Adresse du visiteur pour la mesure d'audience : Cloudflare reconnu même
	 * derrière un relais d'hébergeur, puis résolveur du pare-feu. Voir
	 * CU_Geo::audience_ip() (jamais utilisée pour bloquer).
	 */
	public static function get_client_ip() {
		return CU_Geo::audience_ip();
	}

	// -------------------------------------------------------------------------
	// Maintenance : transients, données héritées, tâches quotidiennes
	// -------------------------------------------------------------------------

	/**
	 * Supprime les transients du module (rapports, compteurs, passages des robots
	 * IA) et invalide les caches. À appeler après « Effacer les données Analytics ».
	 */
	public static function purge_transients() {
		global $wpdb;
		foreach ( array( 'cu_analytics_', 'cu_collect_', 'cu_ai_hits_', 'cu_spike_logged_', 'cu_enrich_budget_', 'cu_rollup_recent', 'cu_live_ip_clean' ) as $prefix ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			) );
		}
		// Cache objet persistant : les clés ne se listent pas ; les jours connus sont
		// supprimés un à un et l'époque invalide tous les rapports en cache.
		for ( $i = 0; $i <= 40; $i++ ) {
			delete_transient( 'cu_ai_hits_' . wp_date( 'Ymd', time() - $i * DAY_IN_SECONDS, wp_timezone() ) );
		}
		update_option( 'cu_analytics_cache_epoch', wp_generate_uuid4(), false );
		delete_option( 'cu_rollup_cursor' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Tâches quotidiennes de confidentialité (appelées par le nettoyage) : rotation
	 * du sel d'empreinte, effacement des empreintes d'IP de la veille et des IP
	 * du temps réel oubliées, minimisation des données antérieures à 1.2.0.
	 *
	 * @param float $deadline Horodatage microtime() à ne pas dépasser.
	 * @return bool false si une partie reste à faire (budget de temps épuisé).
	 */
	public static function daily_maintenance( $deadline ) {
		if ( ! CU_DB::tables_exist_for( array( 'sessions' ) ) ) {
			return true;
		}
		global $wpdb;
		$s_tbl = CU_DB::table( 'sessions' );
		self::ip_salt(); // détruit le sel de la veille même sans visite aujourd'hui
		$today = CU_DB::date_from_now( 'Y-m-d 00:00:00' );
		do {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$s_tbl} SET ip_hash = '' WHERE ip_hash <> '' AND first_seen < %s LIMIT 5000", $today ) );
		} while ( false !== $done && $done >= 5000 && microtime( true ) < $deadline );
		self::purge_live_ips( 7 * DAY_IN_SECONDS );
		$finished = self::minimise_legacy_data( $deadline );
		return $finished && ( false === $done || $done < 5000 );
	}

	/**
	 * Minimisation, une seule fois, des données collectées avant 1.2.0 : URL sans
	 * paramètres, référent complet et rôle effacés, méta d'événements jamais
	 * affichées supprimées. Par tranches d'identifiants (index primaire), reprise
	 * au même point si le budget de temps est épuisé.
	 *
	 * @return bool true quand tout est traité.
	 */
	public static function minimise_legacy_data( $deadline ) {
		$state = get_option( 'cu_analytics_minimised', array() );
		if ( '1.2.0' === $state ) {
			return true;
		}
		global $wpdb;
		$state = is_array( $state ) ? $state : array();
		$jobs  = array(
			'sessions' => "UPDATE %1\$s SET referrer_url = NULL, user_role = NULL, landing_url = SUBSTRING_INDEX( landing_url, '?', 1 ), exit_url = SUBSTRING_INDEX( exit_url, '?', 1 )" . ( self::schema_at_least( '1.8.7' ) ? ', live_ip = NULL' : '' ) . ' WHERE id > %%d AND id <= %%d',
			'events'   => "UPDATE %1\$s SET url = SUBSTRING_INDEX( url, '?', 1 ), meta = IF( event_type = 'js_error', meta, NULL ) WHERE id > %%d AND id <= %%d",
			'contacts' => "UPDATE %1\$s SET url = SUBSTRING_INDEX( url, '?', 1 ) WHERE id > %%d AND id <= %%d",
		);
		$finished = true;
		foreach ( $jobs as $short => $template ) {
			if ( ! CU_DB::tables_exist_for( array( $short ) ) ) {
				continue;
			}
			$table = CU_DB::table( $short );
			if ( ! isset( $state[ $short ] ) ) {
				// Les lignes écrites après la mise à jour sont déjà minimisées.
				$state[ $short ] = array( 0, (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ) );
			}
			list( $cursor, $max ) = array_map( 'intval', (array) $state[ $short ] );
			$sql = sprintf( $template, $table );
			while ( $cursor < $max ) {
				if ( microtime( true ) > $deadline ) {
					$finished = false;
					break 2;
				}
				$to = min( $max, $cursor + 20000 );
				if ( false === $wpdb->query( $wpdb->prepare( $sql, $cursor, $to ) ) ) {
					$finished = false;
					break 2;
				}
				$cursor          = $to;
				$state[ $short ] = array( $cursor, $max );
				update_option( 'cu_analytics_minimised', $state, false );
			}
		}
		if ( $finished ) {
			update_option( 'cu_analytics_minimised', '1.2.0', false );
		}
		return $finished;
	}

	/**
	 * Conversions confirmées côté serveur sur une période (dates Y-m-d, heure du
	 * site) : envois Contact Form 7 réellement partis (wpcf7_mail_sent) et
	 * commandes WooCommerce payées. Aucune n'est rattachée à une visite.
	 *
	 * @return array{value:int, cf7:int, woo:int, cf7_tracked:bool, woo_tracked:bool}
	 */
	private static function confirmed_conversions( $start, $end ) {
		global $wpdb;
		$out = array( 'value' => 0, 'cf7' => 0, 'woo' => 0, 'cf7_tracked' => false, 'woo_tracked' => false );
		if ( CU_Settings::get( 'analytics', 'track_cf7', true ) && self::report_tables_exist( array( 'contacts' ) ) ) {
			$out['cf7']         = (int) self::report_get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . CU_DB::table( 'contacts' ) . " WHERE channel = 'cf7' AND action = 'server' AND created_at BETWEEN %s AND %s",
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			) );
			$out['cf7_tracked'] = defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7_ContactForm' ) || $out['cf7'] > 0;
		}
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) ) {
			$paid  = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
			$found = wc_get_orders( array(
				'type'         => 'shop_order',
				'status'       => $paid,
				'date_created' => $start . '...' . $end,
				'limit'        => 1,
				'paginate'     => true,
				'return'       => 'ids',
			) );
			$out['woo']         = is_object( $found ) && isset( $found->total ) ? (int) $found->total : 0;
			$out['woo_tracked'] = true;
		}
		$out['value'] = $out['cf7'] + $out['woo'];
		return $out;
	}

	/**
	 * Totaux du résumé sur une période (dates Y-m-d) : visites (suivies + pages
	 * d'entrée anonymes), pages vues (toutes) et agrégats des seules visites
	 * suivies en continu (rebond, durée, pages par visite, CTR).
	 *
	 * @return array<string, int|float|null>
	 */
	private static function summary_totals( $start, $end, $seg_p ) {
		global $wpdb;
		$s_tbl = CU_DB::table( 'sessions' );
		$e_tbl = CU_DB::table( 'events' );
		$a     = self::anon_col();
		$from  = $start . ' 00:00:00';
		$to    = $end . ' 23:59:59';
		$row   = self::report_get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE( SUM( {$a} < 2 ), 0 ) AS sessions,
					COALESCE( SUM( {$a} = 0 ), 0 ) AS measured,
					COALESCE( SUM( s.pageviews ), 0 ) AS pageviews,
					COALESCE( SUM( CASE WHEN {$a} = 0 THEN s.pageviews ELSE 0 END ), 0 ) AS measured_pageviews,
					COALESCE( SUM( CASE WHEN {$a} > 0 THEN s.pageviews ELSE 0 END ), 0 ) AS anonymous_pageviews,
					AVG( CASE WHEN {$a} = 0 THEN s.duration END ) AS avg_duration,
					COALESCE( SUM( {$a} = 0 AND s.pageviews <= 1 ), 0 ) AS bounces
				FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_p}",
				$from,
				$to
			),
			ARRAY_A
		);
		// Clics CTA : tous (pages anonymes comprises) ; visites avec clic : suivies.
		$cta = self::report_get_row(
			$wpdb->prepare(
				"SELECT COUNT(e.id) AS clicks, COUNT(DISTINCT CASE WHEN {$a} = 0 THEN s.id END) AS click_sessions FROM {$e_tbl} e
				INNER JOIN {$s_tbl} s ON s.id = e.session_id
				WHERE e.event_type = 'cta_click' AND e.created_at BETWEEN %s AND %s
					AND s.first_seen BETWEEN %s AND %s {$seg_p}",
				$from,
				$to,
				$from,
				$to
			),
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : array();
		$cta = is_array( $cta ) ? $cta : array();
		return array(
			'sessions'            => (int) ( $row['sessions'] ?? 0 ),
			'measured'            => (int) ( $row['measured'] ?? 0 ),
			'pageviews'           => (int) ( $row['pageviews'] ?? 0 ),
			'measured_pageviews'  => (int) ( $row['measured_pageviews'] ?? 0 ),
			'anonymous_pageviews' => (int) ( $row['anonymous_pageviews'] ?? 0 ),
			'avg_duration'        => isset( $row['avg_duration'] ) ? (float) $row['avg_duration'] : null,
			'bounces'             => (int) ( $row['bounces'] ?? 0 ),
			'cta_clicks'          => (int) ( $cta['clicks'] ?? 0 ),
			'cta_sessions'        => (int) ( $cta['click_sessions'] ?? 0 ),
		);
	}

	// -------------------------------------------------------------------------
	// GET /analytics/summary
	// -------------------------------------------------------------------------

	public function handle_summary( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'summary', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end, $prev_start, $prev_end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		$e_tbl = CU_DB::table( 'events' );
		$seg_p = self::segment_where( $segment, 'pages' );
		$seg_v = self::segment_where( $segment, 'visits' );

		$cur  = self::summary_totals( $start, $end, $seg_p );
		$prev = self::summary_totals( $prev_start, $prev_end, $seg_p );

		// Conversions DÉCLARÉES par le navigateur (formulaires marqués, envois CF7
		// vus par le navigateur, clics WhatsApp si réglé), pages anonymes comprises.
		$conversions = function ( $from, $to ) use ( $wpdb, $e_tbl, $s_tbl, $seg_p ) {
			return (int) self::report_get_var(
				$wpdb->prepare(
					"SELECT COUNT(e.id) FROM {$e_tbl} e
					INNER JOIN {$s_tbl} s ON s.id = e.session_id
					WHERE e.event_type = 'conversion' AND e.created_at BETWEEN %s AND %s {$seg_p}",
					$from . ' 00:00:00',
					$to . ' 23:59:59'
				)
			);
		};
		$cur_conv  = $conversions( $start, $end );
		$prev_conv = $conversions( $prev_start, $prev_end );

		// Taux PAR VISITE : uniquement sur les visites suivies en continu. Une page
		// anonyme ne dit rien du reste de la visite (rebond, durée…) : jamais de
		// valeur inventée, « n/d » s'il n'y a aucune visite suivie.
		$per_visit = function ( $t ) {
			$m = $t['measured'];
			return array(
				'pps' => $m ? round( $t['measured_pageviews'] / $m, 2 ) : null,
				'dur' => $m && null !== $t['avg_duration'] ? round( (float) $t['avg_duration'] ) : null,
				'br'  => $m ? round( $t['bounces'] / $m * 100, 1 ) : null,
				'ctr' => $m ? round( $t['cta_sessions'] / $m * 100, 2 ) : null,
			);
		};
		$cur_r  = $per_visit( $cur );
		$prev_r = $per_visit( $prev );

		// Sparklines (visites par jour)
		$spark_rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT DATE(s.first_seen) AS d, COUNT(*) AS n
				FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_v}
				GROUP BY d ORDER BY d ASC",
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);

		$spark_sessions = array();
		$dt = new DateTime( $start );
		$dt_end = new DateTime( $end );
		$spark_map = array();
		foreach ( (array) $spark_rows as $sr ) {
			$spark_map[ $sr['d'] ] = (int) $sr['n'];
		}
		while ( $dt <= $dt_end ) {
			$d = $dt->format( 'Y-m-d' );
			$spark_sessions[] = $spark_map[ $d ] ?? 0;
			$dt->modify( '+1 day' );
		}

		$cur_sessions  = $cur['sessions'];
		$prev_sessions = $prev['sessions'];
		$data = array(
			'sessions'      => array( 'value' => $cur_sessions,      'delta' => self::delta( $cur_sessions, $prev_sessions ),           'spark' => $spark_sessions ),
			'pageviews'     => array( 'value' => $cur['pageviews'],  'delta' => self::delta( $cur['pageviews'], $prev['pageviews'] ),   'spark' => array() ),
			'pages_session' => array( 'value' => $cur_r['pps'],      'delta' => self::delta_or_null( $cur_r['pps'], $prev_r['pps'] ),   'spark' => array(), 'measured' => true ),
			'avg_duration'  => array( 'value' => $cur_r['dur'],      'delta' => self::delta_or_null( $cur_r['dur'], $prev_r['dur'] ),   'spark' => array(), 'measured' => true ),
			'bounce_rate'   => array( 'value' => $cur_r['br'],       'delta' => self::delta_or_null( $cur_r['br'], $prev_r['br'] ),     'spark' => array(), 'measured' => true ),
			'cta_clicks'    => array( 'value' => $cur['cta_clicks'], 'delta' => self::delta( $cur['cta_clicks'], $prev['cta_clicks'] ), 'spark' => array() ),
			'ctr'           => array( 'value' => $cur_r['ctr'],      'delta' => self::delta_or_null( $cur_r['ctr'], $prev_r['ctr'] ),   'spark' => array(), 'measured' => true ),
			'conversions'   => array( 'value' => $cur_conv,          'delta' => self::delta( $cur_conv, $prev_conv ),                   'spark' => array() ),
		);

		// Couverture : visites suivies en continu (base des taux par visite) et
		// visites comptées de façon anonyme (une page d'entrée = une visite).
		$data['coverage'] = self::coverage( $cur_sessions, $cur['measured'] ) + array(
			'anonymous_pageviews' => $cur['anonymous_pageviews'],
			'fallback'            => (bool) CU_Settings::get( 'analytics', 'anonymous_fallback', true ),
		);

		// Conversions CONFIRMÉES par le serveur (messages CF7 réellement partis,
		// commandes WooCommerce payées) : toutes visites confondues, jamais
		// additionnées aux conversions déclarées (un même envoi CF7 y figure deux fois).
		$confirmed      = self::confirmed_conversions( $start, $end );
		$prev_confirmed = self::confirmed_conversions( $prev_start, $prev_end );
		$data['conversions_confirmed'] = $confirmed + array( 'delta' => self::delta( $confirmed['value'], $prev_confirmed['value'] ) );

		// Qualité du trafic (transparence anti-faux-trafic) : part d'humains réels
		// vs trafic filtré (bot / datacenter / proxy), en visites. Indépendant du segment choisi.
		$rel_c = (int) self::report_get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s " . self::segment_where( 'reliable', 'visits' ),
				$start . ' 00:00:00', $end . ' 23:59:59' )
		);
		$sus_c = (int) self::report_get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s " . self::segment_where( 'suspect', 'visits' ),
				$start . ' 00:00:00', $end . ' 23:59:59' )
		);
		$tot_c = $rel_c + $sus_c;
		$data['traffic_quality'] = array(
			'human'         => $rel_c,
			'filtered'      => $sus_c,
			// Aucune visite : part inconnue (n/d), jamais un « 100 % humain » inventé.
			'human_percent' => $tot_c ? (int) round( $rel_c / $tot_c * 100 ) : null,
			// L'interface ne doit pas annoncer un filtrage datacenter/VPN qui n'a pas lieu.
			'datacenter_filter' => self::datacenter_filter_active(),
			'privacy_mode'      => self::privacy_mode(),
		);

		self::set_cached_report( $cache_key, $data );

		// Detect traffic spike
		// Une seule entrée par jour : le rapport est recalculé à chaque changement
		// de segment et à chaque expiration du cache.
		$spike_key = 'cu_spike_logged_' . wp_date( 'Ymd', time(), wp_timezone() );
		if ( $prev_sessions > 0 && $cur_sessions > $prev_sessions * 2 && ! get_transient( $spike_key ) ) {
			set_transient( $spike_key, 1, DAY_IN_SECONDS );
			CU_Logger::log( 'analytics', 'info', sprintf( __( 'Pic de trafic détecté : %d sessions (période précédente : %d)', 'cyberpush-ultra' ), $cur_sessions, $prev_sessions ) );
		}

		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/brief — Carte « En bref » (jour / semaine / mois / année)
	// -------------------------------------------------------------------------

	public function handle_brief( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$period = $request->get_param( 'period' );
		if ( ! in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ) {
			$period = 'week';
		}
		$cache_key = self::get_transient_key( 'brief2_' . $period, 0, 'reliable' ); // format « series » (v1.1.4)
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		$s     = CU_DB::table( 'sessions' );
		$e     = CU_DB::table( 'events' );
		$rel_p = self::segment_where( 'reliable', 'pages' );
		$rel_v = self::segment_where( 'reliable', 'visits' );
		$now   = current_time( 'timestamp' );

		list( $cs, $ce, $ps, $pe ) = self::brief_bounds( $period, $now );

		// Série détaillée : les totaux en sont déduits, ils concordent donc toujours
		// avec la courbe affichée.
		$series        = self::brief_series( $wpdb, $s, $e, $rel_p, $rel_v, $period, $now, $cs, $ce, $ps, $pe );
		$cur_sessions  = $series['totals']['sessions'];
		$prev_sessions = $series['totals']['prev'];
		$pageviews     = $series['totals']['pageviews'];
		$conversions   = $series['totals']['conversions'];
		$suspect       = (int) self::report_get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s " . self::segment_where( 'suspect', 'visits' ), $cs, $ce ) );
		$human_percent = ( $cur_sessions + $suspect ) ? (int) round( $cur_sessions / ( $cur_sessions + $suspect ) * 100 ) : null;

		$labels = array(
			'day'   => __( 'aujourd\'hui', 'cyberpush-ultra' ),
			'week'  => __( '7 derniers jours', 'cyberpush-ultra' ),
			'month' => __( '30 derniers jours', 'cyberpush-ultra' ),
			'year'  => __( '12 derniers mois', 'cyberpush-ultra' ),
		);

		$data = array(
			'period'        => $period,
			'period_label'  => $labels[ $period ],
			'sessions'      => array( 'value' => $cur_sessions, 'delta' => self::delta( $cur_sessions, $prev_sessions ), 'prev' => $prev_sessions ),
			'pageviews'     => $pageviews,
			'conversions'   => $conversions,
			// Nouveaux visiteurs : connus uniquement pour les visites suivies en continu.
			'new_visitors'  => $series['totals']['new'],
			'measured'      => $series['totals']['measured'],
			'coverage'      => self::coverage( $cur_sessions, $series['totals']['measured'] ),
			'human_percent' => $human_percent,
			'series'        => $series['points'],
			'narrative'     => array(
				'best'          => self::brief_best( $wpdb, $s, $rel_v, $period, $cs, $ce ),
				'rising_source' => self::brief_rising_source( $wpdb, $s, $rel_v, $cs, $ce, $ps, $pe ),
				'human_percent' => $human_percent,
			),
		);

		self::set_cached_report( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
		return rest_ensure_response( $data );
	}

	/**
	 * Bornes (heure du site) de la période de la carte « En bref » et de la
	 * période précédente équivalente. L'année couvre 12 mois calendaires : les
	 * 11 précédents et le mois en cours, comparés aux mêmes mois un an plus tôt.
	 *
	 * @return string[] array( début, fin, début précédent, fin précédente )
	 */
	private static function brief_bounds( $period, $now ) {
		if ( 'day' === $period ) {
			return array(
				gmdate( 'Y-m-d 00:00:00', $now ),
				gmdate( 'Y-m-d 23:59:59', $now ),
				gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day', $now ) ),
				gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day', $now ) ),
			);
		}
		if ( 'month' === $period ) {
			return array(
				gmdate( 'Y-m-d 00:00:00', strtotime( '-29 days', $now ) ),
				gmdate( 'Y-m-d 23:59:59', $now ),
				gmdate( 'Y-m-d 00:00:00', strtotime( '-59 days', $now ) ),
				gmdate( 'Y-m-d 23:59:59', strtotime( '-30 days', $now ) ),
			);
		}
		if ( 'year' === $period ) {
			$first = (int) strtotime( '-11 months', (int) strtotime( gmdate( 'Y-m-01', $now ) ) );
			return array(
				gmdate( 'Y-m-d 00:00:00', $first ),
				gmdate( 'Y-m-d 23:59:59', $now ),
				gmdate( 'Y-m-d 00:00:00', strtotime( '-12 months', $first ) ),
				gmdate( 'Y-m-d 23:59:59', strtotime( '-1 year', $now ) ),
			);
		}
		return array(
			gmdate( 'Y-m-d 00:00:00', strtotime( '-6 days', $now ) ),
			gmdate( 'Y-m-d 23:59:59', $now ),
			gmdate( 'Y-m-d 00:00:00', strtotime( '-13 days', $now ) ),
			gmdate( 'Y-m-d 23:59:59', strtotime( '-7 days', $now ) ),
		);
	}

	/**
	 * Série de la carte « En bref » : une tranche par heure (jour), par jour
	 * (semaine, mois) ou par mois (année). Pour chacune : visites, pages vues,
	 * nouveaux visiteurs, taux de rebond, durée moyenne, conversions et visites de
	 * la tranche équivalente de la période précédente. Trois requêtes groupées.
	 *
	 * @return array{points: array<int, array<string, mixed>>, totals: array<string, int>}
	 */
	private static function brief_series( $wpdb, $s, $e, $rel_p, $rel_v, $period, $now, $cs, $ce, $ps, $pe ) {
		if ( 'day' === $period ) {
			$key_s = 'HOUR(s.first_seen)';
			$key_e = 'HOUR(e.created_at)';
		} elseif ( 'year' === $period ) {
			$key_s = "DATE_FORMAT(s.first_seen, '%%Y-%%m')";
			$key_e = "DATE_FORMAT(e.created_at, '%%Y-%%m')";
		} else {
			$key_s = 'DATE(s.first_seen)';
			$key_e = 'DATE(e.created_at)';
		}
		// Visites = suivies + pages d'entrée anonymes ; pages vues = toutes ; rebond,
		// durée, pages par visite et nouveaux : visites suivies en continu seulement.
		$a   = self::anon_col();
		$cur = self::report_get_results( $wpdb->prepare(
			"SELECT {$key_s} AS k, COALESCE(SUM({$a} < 2),0) AS n, COALESCE(SUM(s.pageviews),0) AS pv,
				COALESCE(SUM({$a} = 0),0) AS m, COALESCE(SUM(CASE WHEN {$a} = 0 THEN s.pageviews ELSE 0 END),0) AS mpv,
				COALESCE(SUM(CASE WHEN {$a} = 0 THEN s.is_new ELSE 0 END),0) AS nw,
				COALESCE(SUM( {$a} = 0 AND s.pageviews <= 1 ),0) AS b, AVG(CASE WHEN {$a} = 0 THEN s.duration END) AS dur
			FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel_p} GROUP BY k",
			$cs,
			$ce
		), ARRAY_A );
		$prev = self::report_get_results( $wpdb->prepare( "SELECT {$key_s} AS k, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel_v} GROUP BY k", $ps, $pe ), ARRAY_A );
		$conv = self::report_get_results( $wpdb->prepare( "SELECT {$key_e} AS k, COUNT(e.id) AS n FROM {$e} e INNER JOIN {$s} s ON s.id = e.session_id WHERE e.event_type = 'conversion' AND e.created_at BETWEEN %s AND %s {$rel_p} GROUP BY k", $cs, $ce ), ARRAY_A );

		$totals = array( 'sessions' => 0, 'prev' => 0, 'pageviews' => 0, 'conversions' => 0, 'new' => 0, 'measured' => 0 );
		$cm = array();
		foreach ( $cur as $row ) {
			$cm[ (string) $row['k'] ] = $row;
			$totals['sessions']  += (int) $row['n'];
			$totals['pageviews'] += (int) $row['pv'];
			$totals['new']       += (int) $row['nw'];
			$totals['measured']  += (int) $row['m'];
		}
		$pm = array();
		foreach ( $prev as $row ) {
			$pm[ (string) $row['k'] ] = (int) $row['n'];
			$totals['prev']          += (int) $row['n'];
		}
		$vm = array();
		foreach ( $conv as $row ) {
			$vm[ (string) $row['k'] ] = (int) $row['n'];
			$totals['conversions']   += (int) $row['n'];
		}

		// Tranches de la période et tranche équivalente de la période précédente.
		$keys      = array();
		$prev_keys = array();
		if ( 'day' === $period ) {
			for ( $h = 0; $h < 24; $h++ ) {
				$keys[]      = (string) $h;
				$prev_keys[] = (string) $h;
			}
		} elseif ( 'year' === $period ) {
			$first = (int) strtotime( substr( $cs, 0, 10 ) );
			for ( $m = 0; $m < 12; $m++ ) {
				$month       = (int) strtotime( "+{$m} months", $first );
				$keys[]      = gmdate( 'Y-m', $month );
				$prev_keys[] = gmdate( 'Y-m', (int) strtotime( '-12 months', $month ) );
			}
		} else {
			$shift = ( 'week' === $period ? 7 : 30 ) * DAY_IN_SECONDS;
			$end   = (int) strtotime( substr( $ce, 0, 10 ) );
			for ( $t = (int) strtotime( substr( $cs, 0, 10 ) ); $t <= $end; $t += DAY_IN_SECONDS ) {
				$keys[]      = gmdate( 'Y-m-d', $t );
				$prev_keys[] = gmdate( 'Y-m-d', $t - $shift );
			}
		}

		$current_hour = (int) gmdate( 'G', $now );
		$points       = array();
		foreach ( $keys as $i => $key ) {
			$row = isset( $cm[ $key ] ) ? $cm[ $key ] : null;
			$n   = $row ? (int) $row['n'] : 0;
			$m   = $row ? (int) $row['m'] : 0;
			$points[] = array(
				'key'          => $key,
				'sessions'     => $n,
				'pageviews'    => $row ? (int) $row['pv'] : 0,
				// Base des indicateurs par visite de la tranche (visites suivies).
				'measured'     => $m,
				'pages_visit'  => $m ? round( (int) $row['mpv'] / $m, 2 ) : null,
				'new'          => $row ? (int) $row['nw'] : 0,
				'bounce_rate'  => $m ? round( (int) $row['b'] / $m * 100, 1 ) : null,
				'avg_duration' => $m && null !== $row['dur'] ? (int) round( (float) $row['dur'] ) : null,
				'conversions'  => isset( $vm[ $key ] ) ? $vm[ $key ] : 0,
				'prev'         => isset( $pm[ $prev_keys[ $i ] ] ) ? $pm[ $prev_keys[ $i ] ] : 0,
				'prev_key'     => $prev_keys[ $i ],
				'future'       => 'day' === $period && $i > $current_hour,
			);
		}
		return array( 'points' => $points, 'totals' => $totals );
	}

	/**
	 * Bornes d'une tranche de la carte « En bref » (heure, jour ou mois), validée
	 * contre la période en cours : toute autre valeur est refusée.
	 *
	 * @return string[]|null array( début, fin ) ou null si la tranche est invalide.
	 */
	private static function brief_bucket_range( $period, $key, $now ) {
		list( $cs, $ce ) = self::brief_bounds( $period, $now );
		if ( 'day' === $period ) {
			if ( ! preg_match( '/^\d{1,2}$/', $key ) || (int) $key > 23 ) {
				return null;
			}
			$hour = sprintf( '%02d', (int) $key );
			$day  = substr( $cs, 0, 10 );
			return array( "{$day} {$hour}:00:00", "{$day} {$hour}:59:59" );
		}
		if ( 'year' === $period ) {
			if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $key, $m ) || ! checkdate( (int) $m[2], 1, (int) $m[1] ) ) {
				return null;
			}
			$from = $key . '-01 00:00:00';
			$to   = gmdate( 'Y-m-t', (int) strtotime( $key . '-01' ) ) . ' 23:59:59';
		} else {
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $key, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return null;
			}
			$from = $key . ' 00:00:00';
			$to   = $key . ' 23:59:59';
		}
		if ( $from < $cs || $from > $ce ) {
			return null;
		}
		return array( $from, min( $to, $ce ) );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/brief-point — détail d'une tranche (infobulle « En bref »)
	// -------------------------------------------------------------------------

	public function handle_brief_point( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$period = (string) $request->get_param( 'period' );
		$key    = (string) $request->get_param( 'key' );
		if ( ! in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ) {
			return new WP_Error( 'cu_invalid_period', __( 'Période invalide.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$range = self::brief_bucket_range( $period, $key, current_time( 'timestamp' ) );
		if ( ! $range ) {
			return new WP_Error( 'cu_invalid_bucket', __( 'Tranche invalide pour cette période.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$cache_key = self::get_transient_key( 'brief_point_' . $period . '_' . $key, 0, 'reliable' );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		$s   = CU_DB::table( 'sessions' );
		$e   = CU_DB::table( 'events' );
		// Sources, pays, appareils : en visites ; pages : toutes les pages vues.
		$rel   = self::segment_where( 'reliable', 'visits' );
		$rel_p = self::segment_where( 'reliable', 'pages' );
		list( $from, $to ) = $range;

		$sources = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT s.referrer_source AS src, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel} GROUP BY src ORDER BY n DESC LIMIT 4", $from, $to ), ARRAY_A ) as $row ) {
			$sources[] = array( 'source' => $row['src'] ? sanitize_key( $row['src'] ) : 'direct', 'count' => (int) $row['n'] );
		}

		// Pages vues dans la tranche : chemin (sans paramètres) et titre réel du contenu.
		$rows  = self::report_get_results( $wpdb->prepare( "SELECT e.url, MAX(e.object_id) AS oid, COUNT(*) AS n FROM {$e} e INNER JOIN {$s} s ON s.id = e.session_id WHERE e.event_type = 'pageview' AND e.created_at BETWEEN %s AND %s {$rel_p} GROUP BY e.url ORDER BY n DESC LIMIT 20", $from, $to ), ARRAY_A );
		$paths = array();
		foreach ( $rows as $row ) {
			$path = (string) wp_parse_url( (string) $row['url'], PHP_URL_PATH );
			$path = '' === $path ? '/' : CU_Text::substr( $path, 0, 120 );
			if ( ! isset( $paths[ $path ] ) ) {
				$paths[ $path ] = array( 'path' => $path, 'oid' => (int) $row['oid'], 'count' => 0 );
			}
			$paths[ $path ]['count'] += (int) $row['n'];
		}
		uasort( $paths, function ( $a, $b ) { return $b['count'] - $a['count']; } );
		$pages = array();
		foreach ( array_slice( $paths, 0, 4 ) as $page ) {
			$post    = $page['oid'] > 0 ? get_post( $page['oid'] ) : null;
			$pages[] = array( 'path' => $page['path'], 'title' => $post ? self::display_title( $post ) : '', 'count' => $page['count'] );
		}

		$countries = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT s.country_code AS cc, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel} AND s.country_code IS NOT NULL AND s.country_code <> '' GROUP BY cc ORDER BY n DESC LIMIT 4", $from, $to ), ARRAY_A ) as $row ) {
			$countries[] = array( 'code' => strtoupper( (string) $row['cc'] ), 'count' => (int) $row['n'] );
		}
		$devices = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT s.device AS d, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel} GROUP BY d ORDER BY n DESC", $from, $to ), ARRAY_A ) as $row ) {
			$devices[] = array( 'device' => strtolower( (string) $row['d'] ), 'count' => (int) $row['n'] );
		}

		$data = array( 'key' => $key, 'sources' => $sources, 'pages' => $pages, 'countries' => $countries, 'devices' => $devices );
		self::set_cached_report( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
		return rest_ensure_response( $data );
	}

	/** « Meilleur moment » adapté à la période (heure / jour / mois). */
	private static function brief_best( $wpdb, $s, $rel, $period, $cs, $ce ) {
		if ( 'day' === $period ) {
			$r = self::report_get_row( $wpdb->prepare( "SELECT HOUR(s.first_seen) AS k, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel} GROUP BY k ORDER BY n DESC LIMIT 1", $cs, $ce ), ARRAY_A );
			if ( ! $r || ! (int) $r['n'] ) { return null; }
			return array( 'label' => __( 'Meilleure heure', 'cyberpush-ultra' ), 'value' => sprintf( '%dh', (int) $r['k'] ) );
		}
		if ( 'week' === $period ) {
			$r = self::report_get_row( $wpdb->prepare( "SELECT WEEKDAY(s.first_seen) AS k, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel} GROUP BY k ORDER BY n DESC LIMIT 1", $cs, $ce ), ARRAY_A );
			if ( ! $r || ! (int) $r['n'] ) { return null; }
			$days = array( __( 'lundi', 'cyberpush-ultra' ), __( 'mardi', 'cyberpush-ultra' ), __( 'mercredi', 'cyberpush-ultra' ), __( 'jeudi', 'cyberpush-ultra' ), __( 'vendredi', 'cyberpush-ultra' ), __( 'samedi', 'cyberpush-ultra' ), __( 'dimanche', 'cyberpush-ultra' ) );
			return array( 'label' => __( 'Meilleur jour', 'cyberpush-ultra' ), 'value' => isset( $days[ (int) $r['k'] ] ) ? $days[ (int) $r['k'] ] : '' );
		}
		if ( 'month' === $period ) {
			$r = self::report_get_row( $wpdb->prepare( "SELECT DATE(s.first_seen) AS k, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel} GROUP BY k ORDER BY n DESC LIMIT 1", $cs, $ce ), ARRAY_A );
			if ( ! $r || ! (int) $r['n'] ) { return null; }
			return array( 'label' => __( 'Meilleur jour', 'cyberpush-ultra' ), 'value' => date_i18n( 'j M', strtotime( $r['k'] ) ) );
		}
		// year → meilleur mois.
		$r = self::report_get_row( $wpdb->prepare( "SELECT DATE_FORMAT(s.first_seen,'%%Y-%%m') AS k, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel} GROUP BY k ORDER BY n DESC LIMIT 1", $cs, $ce ), ARRAY_A );
		if ( ! $r || ! (int) $r['n'] ) { return null; }
		return array( 'label' => __( 'Meilleur mois', 'cyberpush-ultra' ), 'value' => date_i18n( 'F Y', strtotime( $r['k'] . '-01' ) ) );
	}

	/** « Source qui monte » : plus fort delta positif vs période précédente. */
	private static function brief_rising_source( $wpdb, $s, $rel, $cs, $ce, $ps, $pe ) {
		$cur  = self::report_get_results( $wpdb->prepare( "SELECT referrer_source AS src, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel} GROUP BY referrer_source", $cs, $ce ), ARRAY_A );
		$prev = self::report_get_results( $wpdb->prepare( "SELECT referrer_source AS src, COUNT(*) AS n FROM {$s} s WHERE s.first_seen BETWEEN %s AND %s {$rel} GROUP BY referrer_source", $ps, $pe ), ARRAY_A );
		$pm   = array();
		foreach ( (array) $prev as $p ) {
			$prev_src        = $p['src'] ? $p['src'] : 'direct';
			$pm[ $prev_src ] = (int) $p['n'];
		}
		$labels = array(
			'direct'   => __( 'Direct', 'cyberpush-ultra' ),
			'google'   => __( 'SEO', 'cyberpush-ultra' ),
			'social'   => __( 'Réseaux sociaux', 'cyberpush-ultra' ),
			'referral' => __( 'Référents', 'cyberpush-ultra' ),
			'campaign' => __( 'Campagnes', 'cyberpush-ultra' ),
			'email'    => __( 'Email', 'cyberpush-ultra' ),
			'ia'       => __( 'IA', 'cyberpush-ultra' ),
		);
		$best       = null;
		$best_delta = 0;
		$new_best   = null; // source absente de la période précédente : « nouvelle », pas « +100 % »
		$new_n      = 0;
		foreach ( (array) $cur as $c ) {
			$src = $c['src'] ? $c['src'] : 'direct';
			$n   = (int) $c['n'];
			if ( $n < 3 ) { continue; } // trop faible pour être significatif
			$prevn = isset( $pm[ $src ] ) ? $pm[ $src ] : 0;
			$label = isset( $labels[ $src ] ) ? $labels[ $src ] : $src;
			if ( ! $prevn ) {
				if ( $n > $new_n ) {
					$new_n    = $n;
					$new_best = array( 'label' => $label, 'delta' => null, 'is_new' => true );
				}
				continue;
			}
			$delta = (int) round( ( $n - $prevn ) / $prevn * 100 );
			if ( $delta > $best_delta ) {
				$best_delta = $delta;
				$best       = array( 'label' => $label, 'delta' => $delta, 'is_new' => false );
			}
		}
		return $best ? $best : $new_best;
	}

	// -------------------------------------------------------------------------
	// GET /analytics/realtime
	// -------------------------------------------------------------------------

	public function handle_realtime( WP_REST_Request $request ) {
		nocache_headers(); // données live : jamais de cache
		if ( ! self::report_tables_exist() ) return self::migration_error();

		global $wpdb;
		$s_tbl = CU_DB::table( 'sessions' );
		$e_tbl = CU_DB::table( 'events' );
		$window = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 5 * MINUTE_IN_SECONDS );

		// Un visiteur en ligne est une visite SUIVIE en continu. Une page anonyme
		// n'est qu'une page ouverte : comptée à part, jamais additionnée aux
		// visiteurs (deux pages anonymes peuvent venir de la même personne).
		$rel_m = self::segment_where( 'reliable', 'measured' );
		$sus_m = self::segment_where( 'suspect', 'measured' );
		$rel_p = self::segment_where( 'reliable', 'pages' );
		$a     = self::anon_col();

		// LE vrai « actuellement en ligne » = humains réels (ni bot, ni datacenter, ni proxy).
		$online_humans = (int) self::report_get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$s_tbl} s WHERE s.last_seen >= %s {$rel_m}", $window )
		);
		// Trafic douteux actif en parallèle (transparence, jamais mélangé au chiffre humain).
		$online_suspect = (int) self::report_get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$s_tbl} s WHERE s.last_seen >= %s {$sus_m}", $window )
		);
		// Pages anonymes ouvertes (trafic fiable) : des pages, pas des visiteurs.
		$anon_pages = self::anon_ready() ? (int) self::report_get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$s_tbl} s WHERE s.last_seen >= %s {$rel_p} AND s.anon > 0", $window )
		) : 0;

		// Liste des visiteurs HUMAINS actifs : heures exactes (site), pays, page en
		// cours, appareil et source. L'IP n'est lue qu'en mode standard avec le
		// réglage activé ; elle n'est jamais affichée au-delà de LIVE_IP_TTL.
		$show_ip = self::live_ip_allowed();
		$ip_col  = $show_ip ? 's.live_ip' : 'NULL';
		$ip_from = (int) current_time( 'timestamp' ) - self::LIVE_IP_TTL;
		$active  = self::report_get_results(
			$wpdb->prepare(
				"SELECT s.first_seen, s.last_seen, s.country_code, {$ip_col} AS live_ip, s.pageviews,
					s.device, s.browser, s.os, s.referrer_source, s.referrer_host, {$a} AS anon,
					( s.geo_token IS NOT NULL AND s.enriched = 0 ) AS geo_pending,
					e.url, e.object_type
				FROM {$s_tbl} s
				INNER JOIN {$e_tbl} e ON e.id = (
					SELECT id FROM {$e_tbl} WHERE session_id = s.id ORDER BY id DESC LIMIT 1
				)
				WHERE s.last_seen >= %s {$rel_p}
				ORDER BY s.last_seen DESC
				LIMIT 50",
				$window
			),
			ARRAY_A
		);

		// L'IP est déchiffrée ici seulement, pour l'administrateur ; jamais la valeur stockée.
		$visitors = array();
		foreach ( (array) $active as $row ) {
			$fresh              = (int) strtotime( (string) $row['last_seen'] ) >= $ip_from;
			$row['ip']          = ( $show_ip && $fresh && ! empty( $row['live_ip'] ) ) ? self::decrypt_enrichment_ip( $row['live_ip'] ) : '';
			$row['geo_pending'] = ! empty( $row['geo_pending'] );
			$row['pageviews']   = (int) $row['pageviews'];
			$row['url']         = self::display_url( $row['url'] );
			// Visiteur anonyme : seul le contexte de cette page est connu.
			$row['anon']        = (int) $row['anon'] > 0;
			unset( $row['live_ip'] );
			$visitors[] = $row;
		}

		// Dernière visite reçue, tous segments confondus : distingue « personne en
		// ligne » de « plus rien n'arrive » (tracker absent, collecte bloquée…).
		$last_seen = self::report_get_var( "SELECT MAX(last_seen) FROM {$s_tbl}" );
		$last_hit  = $last_seen ? max( 0, current_time( 'timestamp' ) - (int) strtotime( $last_seen . ' UTC' ) ) : null;

		return rest_ensure_response( array(
			'visitors' => $visitors,
			'online'   => $online_humans,  // le vrai chiffre affiché
			'suspect'  => $online_suspect, // datacenter/bot actifs, à part
			'total'    => $online_humans,  // rétrocompat : « total » = humains désormais
			'anonymous_pages' => $anon_pages, // pages anonymes ouvertes, jamais des visiteurs
			'last_hit_seconds' => $last_hit,
			'show_ip'  => $show_ip,
			'privacy_mode' => self::privacy_mode(),
			// Sans géolocalisation (ni CDN), le pays reste inconnu. En mode exempté,
			// un fournisseur externe n'est jamais interrogé.
			'geo_enabled' => CU_Geo::lookup_configured() && ! ( 'exempt' === self::privacy_mode() && CU_Geo::remote_lookups_allowed() ),
		) );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/tracking-check
	// -------------------------------------------------------------------------

	/** User-Agent d'un navigateur courant : le site doit répondre comme à un visiteur. */
	const DIAG_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

	/**
	 * Diagnostic « Vérifier le suivi » : refait le trajet d'une visite (page servie
	 * aux visiteurs, script de suivi, envoi vers /collect) puis liste les réglages
	 * qui écartent des visites. Statut de chaque contrôle : ok | warn | error | info.
	 */
	public function handle_tracking_check( WP_REST_Request $request ) {
		nocache_headers();
		$checks = array();
		$add    = function ( $id, $status, $title, $detail = '' ) use ( &$checks ) {
			$checks[] = array( 'id' => $id, 'status' => $status, 'title' => $title, 'detail' => $detail );
		};

		if ( ! CU_Settings::get( 'analytics', 'enabled', true ) ) {
			$add( 'module', 'error', __( 'Le module Analytics est désactivé.', 'cyberpush-ultra' ), __( 'Aucun script de suivi n’est ajouté au site. Activez le module dans Réglages > Analytics.', 'cyberpush-ultra' ) );
			return rest_ensure_response( array( 'checks' => $checks ) );
		}

		// 1. Réception : le serveur reçoit-il encore des visites ?
		if ( self::report_tables_exist() ) {
			global $wpdb;
			$s_tbl = CU_DB::table( 'sessions' );
			$last  = self::report_get_var( "SELECT MAX(last_seen) FROM {$s_tbl}" );
			if ( ! $last ) {
				$add( 'reception', 'error', __( 'Aucune visite reçue pour l’instant.', 'cyberpush-ultra' ), __( 'Le serveur n’a encore enregistré aucune visite : les contrôles suivants montrent où le trajet s’arrête.', 'cyberpush-ultra' ) );
			} else {
				$ago = max( 0, current_time( 'timestamp' ) - (int) strtotime( $last . ' UTC' ) );
				$add(
					'reception',
					$ago <= HOUR_IN_SECONDS ? 'ok' : 'warn',
					/* translators: %s: durée, ex. « 5 minutes » */
					sprintf( __( 'Dernière visite reçue il y a %s.', 'cyberpush-ultra' ), human_time_diff( 0, max( 1, $ago ) ) ),
					$ago <= HOUR_IN_SECONDS ? '' : __( 'Plus aucune visite n’arrive depuis un moment : les contrôles suivants montrent où le trajet s’arrête.', 'cyberpush-ultra' )
				);
				$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS );
				$split = self::report_get_row(
					$wpdb->prepare(
						"SELECT SUM( is_reliable = 1 AND is_bot = 0 AND is_datacenter = 0 AND is_proxy = 0 AND is_excluded = 0 ) AS humans,
							SUM( is_excluded = 0 AND ( is_reliable = 0 OR is_bot = 1 OR is_datacenter = 1 OR is_proxy = 1 ) ) AS suspects
						FROM {$s_tbl} WHERE last_seen >= %s" . ( self::anon_ready() ? ' AND anon < 2' : '' ),
						$since
					),
					ARRAY_A
				);
				$humans   = (int) ( isset( $split['humans'] ) ? $split['humans'] : 0 );
				$suspects = (int) ( isset( $split['suspects'] ) ? $split['suspects'] : 0 );
				if ( $suspects > 0 && 0 === $humans ) {
					$add(
						'segment',
						'warn',
						/* translators: %d: nombre de visites */
						sprintf( _n( 'La seule visite des dernières 24 h est classée douteuse.', 'Les %d visites des dernières 24 h sont toutes classées douteuses.', $suspects, 'cyberpush-ultra' ), $suspects ),
						__( 'Robot, VPN, relais privé iCloud, proxy ou datacenter : ces visites sont comptées à part (« filtrées »), jamais comme humaines. Si le site passe par un CDN ou un proxy non déclaré, tous les visiteurs portent son adresse IP.', 'cyberpush-ultra' )
					);
				}
			}
		}

		// 2. La page servie aux visiteurs contient-elle le script, sans retard ?
		$page = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'             => 8,
				'redirection'         => 3,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'user-agent'          => self::DIAG_UA,
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8' ),
				'limit_response_size' => 3 * MB_IN_BYTES,
			)
		);
		$home_html = '';
		if ( is_wp_error( $page ) ) {
			$add(
				'page',
				'warn',
				__( 'La page d’accueil n’a pas pu être testée depuis le serveur.', 'cyberpush-ultra' ),
				/* translators: %s: message d'erreur réseau */
				sprintf( __( 'L’hébergeur refuse les requêtes du site vers lui-même (%s). Vérifiez à la main : ouvrez le site en navigation privée, puis Outils de développement > Réseau, et cherchez « cu-tracker.js » et « collect ».', 'cyberpush-ultra' ), $page->get_error_message() )
			);
		} else {
			$code = (int) wp_remote_retrieve_response_code( $page );
			$home_html = $code < 400 ? (string) wp_remote_retrieve_body( $page ) : '';
			$scan = self::scan_tracker_markup( (string) wp_remote_retrieve_body( $page ) );
			if ( $code >= 400 ) {
				/* translators: %d: code HTTP */
				$add( 'page', 'warn', sprintf( __( 'La page d’accueil répond HTTP %d au serveur.', 'cyberpush-ultra' ), $code ), __( 'Un pare-feu (hébergeur, CDN) filtre les requêtes du site vers lui-même : le test ne voit pas la page servie aux visiteurs.', 'cyberpush-ultra' ) );
			} elseif ( ! $scan['script'] && ! $scan['config'] ) {
				$add( 'page', 'error', __( 'Le script de suivi est absent de la page servie aux visiteurs.', 'cyberpush-ultra' ), __( 'Cause habituelle : la page vient d’un cache créé avant l’installation du plugin. Videz le cache de votre extension de cache et de votre CDN (Cloudflare…), puis relancez ce test.', 'cyberpush-ultra' ) );
			} elseif ( ! $scan['script'] ) {
				$add( 'page', 'warn', __( 'Le script de suivi a été fusionné avec d’autres scripts.', 'cyberpush-ultra' ), __( 'Sa configuration est présente, mais « cu-tracker.js » a été regroupé par un optimiseur. Si aucune visite n’arrive, excluez « cu-tracker.js » de la fusion JavaScript.', 'cyberpush-ultra' ) );
			} elseif ( $scan['delayed'] ) {
				/* translators: %s: nom de l'extension ou du service */
				$add( 'page', 'warn', sprintf( __( 'Le script de suivi est retardé par %s.', 'cyberpush-ultra' ), $scan['delayed'] ), __( 'Il ne s’exécute qu’après un geste du visiteur (défilement, clic) : une visite sans interaction n’est pas comptée. Excluez « cu-tracker.js » et « cuTrackerConfig » du retard JavaScript de cette extension.', 'cyberpush-ultra' ) );
			} elseif ( ! $scan['config'] ) {
				$add( 'page', 'error', __( 'La configuration du script de suivi est absente.', 'cyberpush-ultra' ), __( 'Un optimiseur a retiré le bloc « cuTrackerConfig » : le script s’arrête sans rien envoyer. Excluez-le de la minification et de la fusion JavaScript.', 'cyberpush-ultra' ) );
			} else {
				$add( 'page', 'ok', __( 'Le script de suivi est présent sur la page d’accueil.', 'cyberpush-ultra' ) );
			}
		}

		// 3. L'envoi des visites est-il accepté pour un visiteur anonyme ?
		$rest = self::probe_collect( rest_url( CU_REST_NAMESPACE . '/collect' ) );
		if ( 'ok' === $rest['state'] ) {
			$add( 'collect', 'ok', __( 'L’envoi des visites (API REST) fonctionne.', 'cyberpush-ultra' ) );
		} else {
			$ajax = self::probe_collect( add_query_arg( 'action', 'cu_collect', admin_url( 'admin-ajax.php' ) ) );
			if ( 'ok' === $ajax['state'] ) {
				/* translators: %s: code HTTP ou d'erreur */
				$add( 'collect', 'warn', sprintf( __( 'L’API REST est fermée aux visiteurs (%s).', 'cyberpush-ultra' ), $rest['why'] ), __( 'La collecte passe automatiquement par admin-ajax.php : les visites sont bien comptées. Pour revenir à la voie normale, autorisez la route « cyberpush-ultra/v1/collect » dans l’extension qui ferme l’API REST.', 'cyberpush-ultra' ) );
			} elseif ( 'network' === $rest['state'] && 'network' === $ajax['state'] ) {
				/* translators: %s: message d'erreur réseau */
				$add( 'collect', 'warn', __( 'L’envoi des visites n’a pas pu être testé depuis le serveur.', 'cyberpush-ultra' ), sprintf( __( 'L’hébergeur refuse les requêtes du site vers lui-même (%s) ; cela ne bloque pas forcément les visiteurs.', 'cyberpush-ultra' ), $rest['why'] ) );
			} else {
				/* translators: 1: résultat API REST, 2: résultat admin-ajax */
				$add( 'collect', 'error', __( 'Aucune voie d’envoi des visites ne répond.', 'cyberpush-ultra' ), sprintf( __( 'API REST : %1$s. admin-ajax.php : %2$s. Une extension de sécurité ou le pare-feu de l’hébergeur bloque la collecte.', 'cyberpush-ultra' ), $rest['why'], $ajax['why'] ) );
			}
		}

		// 4. Réglages et contexte qui écartent des visites.
		$ip = self::get_client_ip();
		if ( $ip && self::ip_is_excluded( $ip ) ) {
			/* translators: %s: adresse IP */
			$add( 'ip', 'warn', sprintf( __( 'Votre adresse IP (%s) est exclue du suivi.', 'cyberpush-ultra' ), $ip ), __( 'Toutes les visites depuis cette connexion sont ignorées, même dans un autre navigateur. Testez depuis un téléphone en 4G/5G, ou retirez cette IP (ou sa plage) dans Réglages > Analytics.', 'cyberpush-ultra' ) );
		}
		$hint = CU_Geo::untrusted_proxy_hint();
		if ( $hint ) {
			/* translators: %s: adresse IP du proxy */
			$add( 'proxy', 'warn', __( 'Proxy ou CDN non approuvé détecté.', 'cyberpush-ultra' ), sprintf( __( 'Les visiteurs arrivent avec l’adresse du proxy (%s) : ils peuvent être classés « datacenter » et filtrés. Approuvez ce proxy (avis en haut de la page).', 'cyberpush-ultra' ), $hint['proxy'] ) );
		}
		// Source du pays des visiteurs.
		$geo_status = CU_Geo::local_db_status();
		if ( CU_Geo::behind_cloudflare() ) {
			$add( 'geo', 'ok', __( 'Site servi par Cloudflare : le pays et l’adresse de chaque visiteur sont ceux transmis par Cloudflare.', 'cyberpush-ultra' ), __( 'Reconnu même lorsqu’un relais de l’hébergeur se place entre Cloudflare et WordPress. La base de pays locale sert de secours.', 'cyberpush-ultra' ) );
		} elseif ( ! CU_Geo::lookup_configured() ) {
			$add( 'geo', 'warn', __( 'Aucune source de pays n’est configurée : les visiteurs apparaissent sans pays.', 'cyberpush-ultra' ), __( 'Choisissez « Base locale DB-IP » dans Réglages > Analytics > Pays des visiteurs.', 'cyberpush-ultra' ) );
		} elseif ( CU_Geo::local_provider_active() && ! $geo_status['available'] ) {
			$add(
				'geo',
				'warn',
				__( 'La base de pays n’est pas encore installée sur le serveur.', 'cyberpush-ultra' ),
				( '' !== $geo_status['error'] ? sprintf( /* translators: %s: message d'erreur */ __( 'Dernier essai : %s.', 'cyberpush-ultra' ), $geo_status['error'] ) . ' ' : '' )
				. __( 'En attendant, le pays est estimé d’après le fuseau horaire du navigateur. Lancez le téléchargement depuis Réglages > Analytics > Pays des visiteurs.', 'cyberpush-ultra' )
			);
		} elseif ( $geo_status['available'] ) {
			/* translators: %s: édition AAAA-MM */
			$add( 'geo', 'ok', sprintf( __( 'Base de pays installée sur le serveur (édition %s).', 'cyberpush-ultra' ), '' !== $geo_status['edition'] ? $geo_status['edition'] : '—' ) );
		}
		// Plafonds anti-abus de la collecte : jamais de perte silencieuse.
		$limited = self::limited_today();
		if ( $limited['declassed'] > 0 || $limited['dropped'] > 0 || $limited['rate'] > 0 ) {
			$parts = array();
			if ( $limited['declassed'] > 0 ) {
				/* translators: %d: nombre de visites */
				$parts[] = sprintf( _n( '%d nouvelle visite enregistrée mais classée douteuse (plus de visites que le plafond normal depuis une même adresse IP)', '%d nouvelles visites enregistrées mais classées douteuses (plus de visites que le plafond normal depuis une même adresse IP)', $limited['declassed'], 'cyberpush-ultra' ), $limited['declassed'] );
			}
			if ( $limited['dropped'] > 0 ) {
				/* translators: %d: nombre de visites */
				$parts[] = sprintf( _n( '%d visite écartée par le plafond anti-saturation d’une adresse IP', '%d visites écartées par le plafond anti-saturation d’une adresse IP', $limited['dropped'], 'cyberpush-ultra' ), $limited['dropped'] );
			}
			if ( $limited['rate'] > 0 ) {
				/* translators: %d: nombre de minutes */
				$parts[] = sprintf( _n( '%d minute avec des envois refusés temporairement (HTTP 429, trop d’événements depuis une même adresse IP)', '%d minutes avec des envois refusés temporairement (HTTP 429, trop d’événements depuis une même adresse IP)', $limited['rate'], 'cyberpush-ultra' ), $limited['rate'] );
			}
			$add(
				'limits',
				( $limited['dropped'] > 0 || $limited['rate'] > 0 ) ? 'warn' : 'info',
				__( 'Les plafonds anti-abus de la collecte ont agi aujourd’hui (depuis 0 h UTC).', 'cyberpush-ultra' ),
				implode( ' ; ', $parts ) . '. ' . __( 'Cause habituelle : beaucoup de visiteurs derrière une même adresse IP (entreprise, école, hôtel, réseau mobile) ou un CDN / proxy non approuvé ; sinon un envoi automatisé. Les plafonds se règlent avec les filtres cu_analytics_new_sessions_per_hour, cu_analytics_new_sessions_hard_per_hour et cu_analytics_collect_events_per_minute.', 'cyberpush-ultra' )
			);
		}
		$mode      = self::privacy_mode();
		$anon_fb   = (bool) CU_Settings::get( 'analytics', 'anonymous_fallback', true );
		$anon_text = __( 'Sans accord (refus, bandeau sans réponse, signal GPC), chaque page vue est comptée de façon anonyme : sans cookie, sans stockage dans le navigateur, sans identifiant ; l’adresse IP n’est ni enregistrée ni transmise (seul le pays en est déduit). Rebond, durée et parcours ne sont calculés que sur les visites suivies en continu.', 'cyberpush-ultra' );
		if ( 'consent' === $mode ) {
			$cmp = self::consent_manager_detected();
			if ( '' !== $cmp ) {
				/* translators: %s: nom du gestionnaire de consentement */
				$add( 'consent', 'info', sprintf( __( 'Mode consentement : les visites ayant accepté les statistiques sont suivies en continu (gestionnaire détecté : %s).', 'cyberpush-ultra' ), $cmp ), $anon_fb ? $anon_text : __( 'Mesure anonyme désactivée : rien n’est collecté ni stocké dans le navigateur avant l’accord. Pour tester, acceptez la catégorie « Statistiques » dans le bandeau.', 'cyberpush-ultra' ) );
			} elseif ( $anon_fb ) {
				$add( 'consent', 'warn', __( 'Mode consentement : aucun gestionnaire de consentement compatible détecté côté serveur, les visites sans accord sont mesurées de façon anonyme.', 'cyberpush-ultra' ), __( 'Pages vues, sources, pays et appareils restent comptés, mais rebond, durée et parcours ne portent que sur les visites ayant accepté. Un bandeau compatible Google Consent Mode est reconnu dans le navigateur ; sinon installez Complianz, CookieYes, Cookie Notice ou WP Consent API, ou faites appeler window.cuAnalytics.consent(true) par votre bandeau après l’accord « Statistiques ».', 'cyberpush-ultra' ) );
			} else {
				$add( 'consent', 'error', __( 'Mode consentement : aucun gestionnaire de consentement compatible détecté côté serveur, seules les visites ayant accepté sont comptées.', 'cyberpush-ultra' ), __( 'Un bandeau compatible Google Consent Mode est reconnu dans le navigateur ; sinon installez Complianz, CookieYes, Cookie Notice ou WP Consent API, ou faites appeler window.cuAnalytics.consent(true) par votre bandeau après l’accord « Statistiques ». Vous pouvez aussi activer la mesure anonyme ou choisir le mode exempté dans Réglages > Analytics.', 'cyberpush-ultra' ) );
			}
		} elseif ( 'exempt' === $mode ) {
			$add( 'consent', 'info', __( 'Mode exempté : mesure d’audience minimisée, sans consentement préalable.', 'cyberpush-ultra' ), __( 'Aucune IP affichée ni conservée, URL sans paramètres personnels, référent réduit au domaine. Le visiteur peut s’y opposer (shortcode [cyberpush_optout]).', 'cyberpush-ultra' ) . ' ' . ( $anon_fb ? __( 'Si un bandeau de consentement est présent et que le visiteur refuse ou ne répond pas, ou si son navigateur envoie le signal GPC, ses pages sont comptées de façon anonyme (sans cookie ni identifiant).', 'cyberpush-ultra' ) : __( 'Mesure anonyme désactivée : un refus, une absence de réponse au bandeau ou le signal GPC suppriment toute mesure.', 'cyberpush-ultra' ) ) );
		} elseif ( function_exists( 'wp_has_consent' ) ) {
			$add( 'consent', 'info', __( 'Le suivi respecte le bandeau cookies (WP Consent API).', 'cyberpush-ultra' ), $anon_fb ? __( 'Un visiteur qui refuse la catégorie « Statistiques » n’est plus suivi : ses pages sont comptées de façon anonyme.', 'cyberpush-ultra' ) : __( 'Un visiteur qui refuse la catégorie « Statistiques » n’est pas compté.', 'cyberpush-ultra' ) );
		}
		$countries = (array) CU_Settings::get( 'analytics', 'excluded_countries', array() );
		if ( $countries ) {
			/* translators: %s: liste de codes pays */
			$add( 'countries', 'info', sprintf( __( 'Pays exclus du suivi : %s.', 'cyberpush-ultra' ), implode( ', ', array_map( 'strtoupper', array_map( 'strval', $countries ) ) ) ) );
		}
		if ( CU_Settings::get( 'analytics', 'exclude_admins', true ) ) {
			$add( 'admins', 'info', __( 'Les administrateurs connectés ne sont pas comptés.', 'cyberpush-ultra' ), __( 'Pour vous voir dans le temps réel, ouvrez le site dans une fenêtre de navigation privée, sans être connecté.', 'cyberpush-ultra' ) );
		}

		// 5. Contacts : bouton WhatsApp et formulaires Contact Form 7.
		$last_contact = function ( $channel ) {
			if ( ! self::report_tables_exist( array( 'contacts' ) ) ) {
				return '';
			}
			global $wpdb;
			$action = 'cf7' === $channel ? array( 'sent', 'server' ) : array( 'click', 'click' );
			$last   = self::report_get_var( $wpdb->prepare( 'SELECT MAX( created_at ) FROM ' . CU_DB::table( 'contacts' ) . ' WHERE channel = %s AND action IN ( %s, %s )', $channel, $action[0], $action[1] ) );
			return $last ? human_time_diff( 0, max( 1, current_time( 'timestamp' ) - (int) strtotime( $last . ' UTC' ) ) ) : '';
		};
		if ( ! CU_Settings::get( 'analytics', 'track_whatsapp', true ) ) {
			$add( 'whatsapp', 'info', __( 'Le suivi des clics WhatsApp est désactivé.', 'cyberpush-ultra' ), __( 'Activez-le dans Réglages > Analytics > Contacts & leads.', 'cyberpush-ultra' ) );
		} else {
			$widget = '' !== $home_html ? self::scan_whatsapp_markup( $home_html ) : '';
			$ago    = $last_contact( 'whatsapp' );
			/* translators: %s: durée, ex. « 3 heures » */
			$detail = '' !== $ago ? sprintf( __( 'Dernier clic enregistré il y a %s.', 'cyberpush-ultra' ), $ago ) : __( 'Aucun clic enregistré pour l’instant : testez depuis une fenêtre de navigation privée, puis actualisez la page Analytics.', 'cyberpush-ultra' );
			if ( '' !== $widget ) {
				/* translators: %s: nom de l'extension WhatsApp détectée */
				$add( 'whatsapp', 'ok', sprintf( __( 'Bouton WhatsApp détecté sur la page d’accueil (%s) : ses clics sont suivis.', 'cyberpush-ultra' ), $widget ), $detail );
			} elseif ( '' !== $home_html ) {
				$add( 'whatsapp', 'info', __( 'Aucun bouton WhatsApp visible dans la page d’accueil servie.', 'cyberpush-ultra' ), '' !== $ago ? $detail : __( 'S’il n’apparaît que sur certaines pages ou s’il est ajouté par JavaScript, ses clics sont tout de même suivis (liens wa.me, api.whatsapp.com, Joinchat, Click to Chat, NinjaTeam, QuadLayers, Chaty…).', 'cyberpush-ultra' ) );
			}
		}
		if ( defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7_ContactForm' ) ) {
			if ( ! CU_Settings::get( 'analytics', 'track_cf7', true ) ) {
				$add( 'cf7', 'info', __( 'Contact Form 7 est installé mais son suivi est désactivé.', 'cyberpush-ultra' ), __( 'Activez-le dans Réglages > Analytics > Contacts & leads.', 'cyberpush-ultra' ) );
			} else {
				$forms = post_type_exists( 'wpcf7_contact_form' ) ? count( get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'publish', 'numberposts' => 100, 'fields' => 'ids', 'suppress_filters' => true ) ) ) : 0;
				$ago   = $last_contact( 'cf7' );
				$add(
					'cf7',
					'ok',
					/* translators: %d: nombre de formulaires Contact Form 7 */
					sprintf( _n( 'Contact Form 7 détecté (%d formulaire) : vues, envois, erreurs et abandons sont suivis.', 'Contact Form 7 détecté (%d formulaires) : vues, envois, erreurs et abandons sont suivis.', $forms, 'cyberpush-ultra' ), $forms ),
					/* translators: %s: durée, ex. « 2 jours » */
					'' !== $ago ? sprintf( __( 'Dernier message réussi enregistré il y a %s.', 'cyberpush-ultra' ), $ago ) : __( 'Chaque envoi est aussi confirmé par le serveur, même si le visiteur bloque le suivi.', 'cyberpush-ultra' )
				);
			}
		}

		return rest_ensure_response( array( 'checks' => $checks ) );
	}

	/**
	 * Cherche un bouton ou lien WhatsApp dans le HTML servi aux visiteurs.
	 *
	 * @return string Nom de l'extension reconnue, « un lien WhatsApp », ou ''.
	 */
	private static function scan_whatsapp_markup( $html ) {
		$signatures = array(
			'Joinchat'                 => '/class\s*=\s*["\'][^"\']*\bjoinchat\b/i',
			'Click to Chat'            => '/\bht[-_]ctc\b|\bht_ctc_chat|\bctc_chat\b/i',
			'WP WhatsApp (NinjaTeam)'  => '/\bwa__btn_popup\b|\bwa__popup|\bnta[-_]wa[-_]/i',
			'Social Chat (QuadLayers)' => '/\bqlwapp\b/i',
			'Chaty'                    => '/\bchaty-(?:widget|channel)\b/i',
		);
		foreach ( $signatures as $name => $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				return $name;
			}
		}
		if ( preg_match( '#(?:https?:)?//(?:www\.)?(?:wa\.me|(?:api|web|chat)\.whatsapp\.com|whatsapp\.com/(?:send|channel|catalog)|wa\.link)\b|whatsapp://#i', $html ) ) {
			return __( 'lien WhatsApp', 'cyberpush-ultra' );
		}
		return '';
	}

	/**
	 * Cherche la balise du tracker dans le HTML servi aux visiteurs et repère les
	 * optimiseurs qui la retardent (type réécrit, src déplacé).
	 *
	 * @return array{script:bool,config:bool,delayed:string}
	 */
	private static function scan_tracker_markup( $html ) {
		$out = array( 'script' => false, 'config' => false !== strpos( $html, 'cuTrackerConfig' ), 'delayed' => '' );
		if ( ! preg_match_all( '#<script\b[^>]*>#i', $html, $tags ) ) {
			return $out;
		}
		foreach ( $tags[0] as $tag ) {
			if ( false === stripos( $tag, 'cu-tracker' ) ) {
				continue;
			}
			$out['script'] = true;
			$type = preg_match( '#\stype\s*=\s*["\']?([^"\'\s>]+)#i', $tag, $t ) ? strtolower( $t[1] ) : '';
			$signatures = array(
				'WP Rocket'                => 'rocketlazyloadscript' === $type || false !== stripos( $tag, 'data-rocket-src' ),
				'LiteSpeed Cache'          => 'litespeed/javascript' === $type,
				'Perfmatters'              => 'pmdelayedscript' === $type,
				'WP Meteor'                => 'javascript/blocked' === $type || false !== stripos( $tag, 'data-wpmeteor' ),
				'FlyingPress'              => (bool) preg_match( '#data-loading-method\s*=\s*["\']?user-interaction#i', $tag ),
				'Cloudflare Rocket Loader' => (bool) preg_match( '#^[0-9a-f]+-text/javascript$#', $type ),
			);
			foreach ( $signatures as $name => $hit ) {
				if ( $hit ) {
					$out['delayed'] = $name;
					break;
				}
			}
			if ( ! $out['delayed'] && 'text/plain' === $type ) {
				$out['delayed'] = __( 'le bandeau cookies (en attente de consentement)', 'cyberpush-ultra' );
			} elseif ( ! $out['delayed'] && '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
				$out['delayed'] = __( 'un optimiseur JavaScript', 'cyberpush-ultra' );
			}
			break;
		}
		return $out;
	}

	/**
	 * Envoie la sonde du diagnostic vers une voie de collecte, comme le ferait un
	 * visiteur anonyme (aucun cookie).
	 *
	 * @return array{state:string,why:string} state : ok | blocked | network.
	 */
	private static function probe_collect( $url ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 6,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'user-agent'  => self::DIAG_UA,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( array( 'cu_diag' => 1 ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'state' => 'network', 'why' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) && ! empty( $data['diag'] ) ) {
			return array( 'state' => 'ok', 'why' => '' );
		}
		$why = 'HTTP ' . $code;
		if ( is_array( $data ) && ! empty( $data['code'] ) && is_scalar( $data['code'] ) ) {
			$why .= ' — ' . sanitize_key( (string) $data['code'] );
		}
		return array( 'state' => 'blocked', 'why' => $why );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/contacts — Contacts & leads (WhatsApp + Contact Form 7)
	// -------------------------------------------------------------------------

	/** Médiane d'une liste d'entiers (null si vide). */
	private static function median( array $values ) {
		$count = count( $values );
		if ( ! $count ) {
			return null;
		}
		sort( $values );
		$mid = intdiv( $count, 2 );
		return $count % 2 ? (int) $values[ $mid ] : (int) round( ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2 );
	}

	public function handle_contacts( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist( array( 'sessions', 'events', 'contacts' ) ) ) return self::migration_error();

		$range     = self::parse_range( $request );
		$segment   = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'contacts', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end, $prev_start, $prev_end ) = self::period_dates( $range );
		$from     = $start . ' 00:00:00';
		$to       = $end . ' 23:59:59';
		$pfrom    = $prev_start . ' 00:00:00';
		$pto      = $prev_end . ' 23:59:59';
		$c_tbl    = CU_DB::table( 'contacts' );
		$s_tbl    = CU_DB::table( 'sessions' );
		$e_tbl    = CU_DB::table( 'events' );
		// Contacts et pages : toutes les lignes (pages anonymes comprises) ; visites :
		// visites suivies + pages d'entrée anonymes ; « visiteurs » qui contactent et
		// taux de contact : visites suivies en continu uniquement (une page anonyme
		// ne permet pas de savoir si deux contacts viennent de la même visite).
		$seg_w    = self::segment_where( $segment, 'pages' );
		$seg_v    = self::segment_where( $segment, 'visits' );
		$seg_m    = self::segment_where( $segment, 'measured' );
		$a        = self::anon_col();
		$settings = CU_Settings::module( 'analytics' );
		$now_ts   = current_time( 'timestamp' );
		// Toutes les lectures « navigateur » passent par la session (segment fiable,
		// suspect ou tout). Un lead = un clic WhatsApp ou un message CF7 réussi.
		$base     = "FROM {$c_tbl} c INNER JOIN {$s_tbl} s ON s.id = c.session_id WHERE c.created_at BETWEEN %s AND %s {$seg_w}";
		$lead     = "( c.channel = 'whatsapp' OR ( c.channel = 'cf7' AND c.action = 'sent' ) )";
		$attempts = "'" . implode( "','", self::CF7_ATTEMPTS ) . "'";
		$wa_where = "{$base} AND c.channel = 'whatsapp'";
		$cf_where = "{$base} AND c.channel = 'cf7'";
		$percent  = function ( $part, $whole ) {
			return $whole > 0 ? round( min( 100, $part / $whole * 100 ), 2 ) : 0;
		};
		// Taux par visite : null (« n/d ») sans visite suivie, jamais un faux 0 %.
		$visit_rate = function ( $part, $whole ) {
			return self::rate( $part, $whole, 2 );
		};
		$ints = function ( $row ) {
			return array_map( 'intval', is_array( $row ) ? $row : array() );
		};

		// --- Totaux, période en cours et précédente ---------------------------------
		$totals = function ( $from_at, $to_at ) use ( $wpdb, $base, $lead, $ints, $a ) {
			return $ints( self::report_get_row( $wpdb->prepare(
				"SELECT SUM( c.channel = 'whatsapp' ) AS wa,
					COUNT( DISTINCT CASE WHEN c.channel = 'whatsapp' AND {$a} = 0 THEN c.session_id END ) AS wa_visitors,
					SUM( c.channel = 'cf7' AND c.action = 'sent' ) AS cf7,
					COUNT( DISTINCT CASE WHEN c.channel = 'cf7' AND c.action = 'sent' THEN c.session_id END ) AS cf7_senders,
					COUNT( DISTINCT CASE WHEN {$lead} AND {$a} = 0 THEN c.session_id END ) AS lead_visitors
				{$base}",
				$from_at,
				$to_at
			), ARRAY_A ) );
		};
		$count_sessions = function ( $from_at, $to_at, $scope_where ) use ( $wpdb, $s_tbl ) {
			return (int) self::report_get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s {$scope_where}", $from_at, $to_at ) );
		};
		$cur           = $totals( $from, $to ) + array( 'wa' => 0, 'wa_visitors' => 0, 'cf7' => 0, 'cf7_senders' => 0, 'lead_visitors' => 0 );
		$prev          = $totals( $pfrom, $pto ) + array( 'wa' => 0, 'wa_visitors' => 0, 'cf7' => 0, 'cf7_senders' => 0, 'lead_visitors' => 0 );
		$sessions      = $count_sessions( $from, $to, $seg_v );
		$prev_sessions = $count_sessions( $pfrom, $pto, $seg_v );
		$measured      = $count_sessions( $from, $to, $seg_m );
		$prev_measured = $count_sessions( $pfrom, $pto, $seg_m );

		// --- Courbe jour par jour (+ fantôme de la période précédente) ---------------
		$days = array();
		foreach ( self::report_get_results( $wpdb->prepare(
			"SELECT DATE( c.created_at ) AS d,
				SUM( c.channel = 'whatsapp' ) AS wa,
				COUNT( DISTINCT CASE WHEN c.channel = 'whatsapp' AND {$a} = 0 THEN c.session_id END ) AS wa_visitors,
				SUM( c.channel = 'cf7' AND c.action = 'sent' ) AS cf7,
				SUM( c.channel = 'cf7' AND c.action IN ( 'invalid', 'spam', 'failed', 'aborted' ) ) AS cf7_errors,
				COUNT( DISTINCT CASE WHEN c.channel = 'cf7' AND c.action = 'view' THEN c.session_id END ) AS cf7_views,
				COUNT( DISTINCT CASE WHEN {$lead} AND {$a} = 0 THEN c.session_id END ) AS visitors
			{$base} GROUP BY d",
			$from,
			$to
		), ARRAY_A ) as $row ) {
			$days[ $row['d'] ] = $ints( $row );
		}
		$day_sessions = array();
		$day_measured = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT DATE( s.first_seen ) AS d, COUNT(*) AS n, COALESCE( SUM( {$a} = 0 ), 0 ) AS m FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s {$seg_v} GROUP BY d", $from, $to ), ARRAY_A ) as $row ) {
			$day_sessions[ $row['d'] ] = (int) $row['n'];
			$day_measured[ $row['d'] ] = (int) $row['m'];
		}
		$prev_days = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT DATE( c.created_at ) AS d, SUM( {$lead} ) AS n {$base} GROUP BY d", $pfrom, $pto ), ARRAY_A ) as $row ) {
			$prev_days[ $row['d'] ] = (int) $row['n'];
		}
		$series  = array();
		$day     = new DateTime( $start );
		$prev_d  = new DateTime( $prev_start );
		$last    = new DateTime( $end );
		while ( $day <= $last ) {
			$key      = $day->format( 'Y-m-d' );
			$pkey     = $prev_d->format( 'Y-m-d' );
			$row      = isset( $days[ $key ] ) ? $days[ $key ] : array();
			$series[] = array(
				'key'         => $key,
				'wa'          => $row['wa'] ?? 0,
				'wa_visitors' => $row['wa_visitors'] ?? 0,
				'cf7'         => $row['cf7'] ?? 0,
				'cf7_errors'  => $row['cf7_errors'] ?? 0,
				'cf7_views'   => $row['cf7_views'] ?? 0,
				'visitors'    => $row['visitors'] ?? 0,
				'sessions'    => $day_sessions[ $key ] ?? 0,
				'measured'    => $day_measured[ $key ] ?? 0,
				'prev'        => $prev_days[ $pkey ] ?? 0,
				'prev_key'    => $pkey,
			);
			$day->modify( '+1 day' );
			$prev_d->modify( '+1 day' );
		}

		// --- Jour × heure (heure du site, lundi en premier) -------------------------
		$empty_week = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		$heat       = array( 'wa' => $empty_week, 'cf7' => $empty_week );
		foreach ( self::report_get_results( $wpdb->prepare(
			"SELECT DAYOFWEEK( c.created_at ) AS wd, HOUR( c.created_at ) AS h,
				SUM( c.channel = 'whatsapp' ) AS wa, SUM( c.channel = 'cf7' ) AS cf7
			{$base} AND {$lead} GROUP BY wd, h",
			$from,
			$to
		), ARRAY_A ) as $row ) {
			$weekday = ( (int) $row['wd'] + 5 ) % 7; // DAYOFWEEK : 1 = dimanche.
			$hour    = max( 0, min( 23, (int) $row['h'] ) );
			$heat['wa'][ $weekday ][ $hour ]  = (int) $row['wa'];
			$heat['cf7'][ $weekday ][ $hour ] = (int) $row['cf7'];
		}

		// --- Sources : visites, leads et taux de contact -----------------------------
		// Une page anonyme interne n'a pas de provenance connue : « unknown ».
		$src_expr     = self::anon_ready()
			? "CASE WHEN s.anon = 2 THEN 'unknown' ELSE COALESCE( NULLIF( s.referrer_source, '' ), 'direct' ) END"
			: "COALESCE( NULLIF( s.referrer_source, '' ), 'direct' )";
		$src_sessions = array();
		$src_measured = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT {$src_expr} AS src, COUNT(*) AS n, COALESCE( SUM( {$a} = 0 ), 0 ) AS m FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s {$seg_v} GROUP BY src", $from, $to ), ARRAY_A ) as $row ) {
			$src_sessions[ (string) $row['src'] ] = (int) $row['n'];
			$src_measured[ (string) $row['src'] ] = (int) $row['m'];
		}
		$src_leads = array();
		foreach ( self::report_get_results( $wpdb->prepare(
			"SELECT {$src_expr} AS src, SUM( c.channel = 'whatsapp' ) AS wa, SUM( c.channel = 'cf7' ) AS cf7,
				COUNT( DISTINCT CASE WHEN {$a} = 0 THEN c.session_id END ) AS visitors
			{$base} AND {$lead} GROUP BY src",
			$from,
			$to
		), ARRAY_A ) as $row ) {
			$src_leads[ (string) $row['src'] ] = $ints( $row );
		}
		$sources = array();
		foreach ( array_unique( array_merge( array_keys( $src_sessions ), array_keys( $src_leads ) ) ) as $src ) {
			$src_row   = $src_leads[ $src ] ?? array();
			$visits    = $src_sessions[ $src ] ?? 0;
			$src_m     = $src_measured[ $src ] ?? 0;
			$visitors  = $src_row['visitors'] ?? 0;
			$sources[] = array(
				'source'   => (string) $src,
				'sessions' => $visits,
				'measured' => $src_m,
				'wa'       => $src_row['wa'] ?? 0,
				'cf7'      => $src_row['cf7'] ?? 0,
				'leads'    => ( $src_row['wa'] ?? 0 ) + ( $src_row['cf7'] ?? 0 ),
				'visitors' => $visitors,
				'rate'     => $visit_rate( $visitors, $src_m ),
			);
		}
		usort( $sources, function ( $a, $b ) {
			return ( $b['leads'] - $a['leads'] ) ?: ( $b['sessions'] - $a['sessions'] );
		} );
		$sources = array_slice( $sources, 0, 8 );

		// --- Pages (titre réel, taux de clic sur les visites de la page) -------------
		$page_key  = "CASE WHEN c.object_id > 0 THEN CONCAT( 'o', c.object_id ) ELSE c.url END";
		$pages_for = function ( $where, $limit ) use ( $wpdb, $from, $to, $page_key ) {
			return self::report_get_results( $wpdb->prepare(
				"SELECT {$page_key} AS k, MAX( c.object_id ) AS oid, MAX( c.url ) AS url,
					COUNT(*) AS n, COUNT( DISTINCT c.session_id ) AS v
				{$where} GROUP BY k ORDER BY n DESC LIMIT " . (int) $limit,
				$from,
				$to
			), ARRAY_A );
		};
		$wa_pages_raw = $pages_for( $wa_where, 8 );
		$cf_pages_raw = $pages_for( "{$cf_where} AND c.action = 'sent'", 6 );

		$object_ids = array();
		$page_urls  = array();
		foreach ( $wa_pages_raw as $row ) {
			if ( (int) $row['oid'] > 0 ) {
				$object_ids[] = (int) $row['oid'];
			} elseif ( '' !== (string) $row['url'] ) {
				$page_urls[] = (string) $row['url'];
			}
		}
		$page_visits = array();
		if ( $object_ids ) {
			$holders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
			foreach ( self::report_get_results( $wpdb->prepare(
				"SELECT CONCAT( 'o', e.object_id ) AS k, COUNT( DISTINCT e.session_id ) AS n
				FROM {$e_tbl} e INNER JOIN {$s_tbl} s ON s.id = e.session_id
				WHERE e.event_type = 'pageview' AND e.object_id IN ({$holders}) AND e.created_at BETWEEN %s AND %s {$seg_w}
				GROUP BY e.object_id",
				array_merge( $object_ids, array( $from, $to ) )
			), ARRAY_A ) as $row ) {
				$page_visits[ (string) $row['k'] ] = (int) $row['n'];
			}
		}
		if ( $page_urls ) {
			$holders = implode( ',', array_fill( 0, count( $page_urls ), '%s' ) );
			foreach ( self::report_get_results( $wpdb->prepare(
				"SELECT e.url AS k, COUNT( DISTINCT e.session_id ) AS n
				FROM {$e_tbl} e INNER JOIN {$s_tbl} s ON s.id = e.session_id
				WHERE e.event_type = 'pageview' AND e.url IN ({$holders}) AND e.created_at BETWEEN %s AND %s {$seg_w}
				GROUP BY e.url",
				array_merge( $page_urls, array( $from, $to ) )
			), ARRAY_A ) as $row ) {
				$page_visits[ (string) $row['k'] ] = (int) $row['n'];
			}
		}

		// --- Détails WhatsApp ---------------------------------------------------------
		$group = function ( $expr, $where, $limit = 0 ) use ( $wpdb, $from, $to ) {
			$rows = self::report_get_results( $wpdb->prepare(
				"SELECT {$expr} AS k, COUNT(*) AS n {$where} GROUP BY k ORDER BY n DESC" . ( $limit ? ' LIMIT ' . (int) $limit : '' ),
				$from,
				$to
			), ARRAY_A );
			$out = array();
			foreach ( $rows as $row ) {
				$out[] = array( 'key' => null === $row['k'] ? '' : (string) $row['k'], 'count' => (int) $row['n'] );
			}
			return $out;
		};
		$wa_audience = array( 'new' => 0, 'returning' => 0 );
		// Nouveau ou fidèle : connu seulement pour une visite suivie en continu.
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT s.is_new AS k, COUNT( DISTINCT c.session_id ) AS n {$wa_where} AND {$a} = 0 GROUP BY k", $from, $to ), ARRAY_A ) as $row ) {
			$wa_audience[ (int) $row['k'] ? 'new' : 'returning' ] += (int) $row['n'];
		}
		$wa_scroll  = self::report_get_var( $wpdb->prepare( "SELECT AVG( c.scroll_pct ) {$wa_where} AND c.scroll_pct IS NOT NULL", $from, $to ) );
		$wa_seconds = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT c.page_seconds AS v {$wa_where} AND c.page_seconds IS NOT NULL ORDER BY c.id DESC LIMIT 5000", $from, $to ), ARRAY_A ) as $row ) {
			$wa_seconds[] = (int) $row['v'];
		}
		$wa_numbers = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT c.target AS phone, COUNT(*) AS n, MAX( c.created_at ) AS last_at {$wa_where} AND c.target IS NOT NULL GROUP BY c.target ORDER BY n DESC LIMIT 6", $from, $to ), ARRAY_A ) as $row ) {
			$wa_numbers[] = array( 'phone' => (string) $row['phone'], 'count' => (int) $row['n'], 'ago' => max( 0, $now_ts - (int) strtotime( $row['last_at'] . ' UTC' ) ) );
		}

		// --- Contact Form 7 -----------------------------------------------------------
		$cf_tot = $ints( self::report_get_row( $wpdb->prepare(
			"SELECT
				COUNT( DISTINCT CASE WHEN c.action = 'view' THEN c.session_id END ) AS views,
				COUNT( DISTINCT CASE WHEN c.action = 'start' THEN c.session_id END ) AS starts,
				COUNT( DISTINCT CASE WHEN c.action IN ({$attempts}) THEN c.session_id END ) AS submitters,
				COUNT( DISTINCT CASE WHEN c.action = 'sent' THEN c.session_id END ) AS senders,
				SUM( c.action = 'sent' ) AS sent, SUM( c.action = 'invalid' ) AS invalid, SUM( c.action = 'spam' ) AS spam,
				SUM( c.action = 'failed' ) AS failed, SUM( c.action = 'aborted' ) AS aborted, SUM( c.action = 'abandon' ) AS abandon,
				SUM( c.action IN ({$attempts}) ) AS attempts
			{$cf_where}",
			$from,
			$to
		), ARRAY_A ) ) + array_fill_keys( array( 'views', 'starts', 'submitters', 'senders', 'sent', 'invalid', 'spam', 'failed', 'aborted', 'abandon', 'attempts' ), 0 );
		// Un entonnoir ne remonte jamais : un envoi au clavier peut précéder
		// l'apparition à l'écran, ou se faire sans focus (remplissage automatique).
		$funnel = array( 'views' => $cf_tot['views'], 'starts' => $cf_tot['starts'], 'submitters' => $cf_tot['submitters'], 'senders' => $cf_tot['senders'] );
		$funnel['submitters'] = max( $funnel['submitters'], $funnel['senders'] );
		$funnel['starts']     = max( $funnel['starts'], $funnel['submitters'] );
		$funnel['views']      = max( $funnel['views'], $funnel['starts'] );

		$form_stats = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT c.target AS form, c.action, COUNT(*) AS n, COUNT( DISTINCT c.session_id ) AS v {$cf_where} GROUP BY c.target, c.action", $from, $to ), ARRAY_A ) as $row ) {
			$form_id = absint( $row['form'] );
			if ( $form_id ) {
				$form_stats[ $form_id ][ (string) $row['action'] ] = array( 'n' => (int) $row['n'], 'v' => (int) $row['v'] );
			}
		}
		$fills = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT c.target AS form, c.fill_seconds AS v {$cf_where} AND c.action = 'sent' AND c.fill_seconds IS NOT NULL ORDER BY c.id DESC LIMIT 5000", $from, $to ), ARRAY_A ) as $row ) {
			$fills[ absint( $row['form'] ) ][] = (int) $row['v'];
		}
		$all_fills = array();
		foreach ( $fills as $list ) {
			$all_fills = array_merge( $all_fills, $list );
		}
		$fields = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT c.fields AS f {$cf_where} AND c.action = 'invalid' AND c.fields IS NOT NULL ORDER BY c.id DESC LIMIT 3000", $from, $to ), ARRAY_A ) as $row ) {
			foreach ( explode( ',', (string) $row['f'] ) as $name ) {
				$name = sanitize_key( $name );
				if ( '' !== $name ) {
					$fields[ $name ] = ( $fields[ $name ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $fields );
		$top_fields = array();
		foreach ( array_slice( $fields, 0, 6, true ) as $name => $count ) {
			$top_fields[] = array( 'field' => (string) $name, 'count' => (int) $count );
		}

		// Envois confirmés par le serveur (indépendants du navigateur et du segment),
		// comparés aux envois vus par le suivi sur tout le trafic non exclu.
		$server = array();
		foreach ( self::report_get_results( $wpdb->prepare( "SELECT c.target AS form, COUNT(*) AS n FROM {$c_tbl} c WHERE c.channel = 'cf7' AND c.action = 'server' AND c.created_at BETWEEN %s AND %s GROUP BY c.target", $from, $to ), ARRAY_A ) as $row ) {
			$server[ absint( $row['form'] ) ] = (int) $row['n'];
		}
		$server_total  = array_sum( $server );
		$server_prev   = (int) self::report_get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c_tbl} c WHERE c.channel = 'cf7' AND c.action = 'server' AND c.created_at BETWEEN %s AND %s", $pfrom, $pto ) );
		$tracked_total = (int) self::report_get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c_tbl} c INNER JOIN {$s_tbl} s ON s.id = c.session_id WHERE c.channel = 'cf7' AND c.action = 'sent' AND c.created_at BETWEEN %s AND %s AND s.is_excluded = 0", $from, $to ) );
		// Un message CF7 est vu deux fois (navigateur « sent » + serveur wpcf7_mail_sent) :
		// il n'est compté qu'une fois. Le serveur fait foi ; le navigateur ne l'emporte
		// que s'il en voit davantage (suivi serveur ajouté en cours de période). Le
		// segment « suspect » ne concerne que le navigateur.
		$messages      = 'suspect' === $segment ? $cur['cf7'] : max( $server_total, $cur['cf7'] );
		$prev_messages = 'suspect' === $segment ? $prev['cf7'] : max( $server_prev, $prev['cf7'] );

		$cf7_active = defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7_ContactForm' );
		$known      = array();
		if ( post_type_exists( 'wpcf7_contact_form' ) ) {
			$known = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ) );
		}
		$form_ids = array_values( array_unique( array_map( 'intval', array_merge( array_keys( $form_stats ), array_keys( $server ), (array) $known ) ) ) );

		// --- Titres réels (une seule passe de cache pour toutes les listes) -----------
		$recent_wa  = self::report_get_results( $wpdb->prepare(
			"SELECT c.created_at, c.url, c.object_id, c.widget, c.placement, c.target, c.kind, c.label,
				s.referrer_source, s.device, s.country_code, s.is_new, {$a} AS anon
			{$wa_where} ORDER BY c.created_at DESC, c.id DESC LIMIT 8",
			$from,
			$to
		), ARRAY_A );
		$recent_cf7 = self::report_get_results( $wpdb->prepare(
			"SELECT c.created_at, c.url, c.object_id, c.target, c.fill_seconds,
				s.referrer_source, s.device, s.country_code, s.is_new, {$a} AS anon
			{$cf_where} AND c.action = 'sent' ORDER BY c.created_at DESC, c.id DESC LIMIT 6",
			$from,
			$to
		), ARRAY_A );
		$prime = $form_ids;
		foreach ( array( $wa_pages_raw, $cf_pages_raw, $recent_wa, $recent_cf7 ) as $list ) {
			foreach ( $list as $row ) {
				$oid = (int) ( $row['oid'] ?? $row['object_id'] ?? 0 );
				if ( $oid > 0 ) {
					$prime[] = $oid;
				}
			}
		}
		$prime = array_values( array_unique( array_filter( $prime ) ) );
		if ( $prime ) {
			_prime_post_caches( $prime, false, false );
		}
		$home_path = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		$describe  = function ( $oid, $url ) use ( $home_path ) {
			$post  = (int) $oid > 0 ? get_post( (int) $oid ) : null;
			$path  = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
			$title = $post ? self::display_title( $post ) : '';
			if ( '' === $title ) {
				$title = ( '' === $path || '/' === $path || untrailingslashit( $path ) === $home_path ) ? __( 'Page d’accueil', 'cyberpush-ultra' ) : rawurldecode( $path );
			}
			return array( 'title' => $title, 'path' => '' !== $path ? rawurldecode( $path ) : '/', 'url' => esc_url_raw( self::display_url( $url ) ) );
		};
		$form_title = function ( $form_id ) {
			$post = $form_id ? get_post( $form_id ) : null;
			if ( $post && 'wpcf7_contact_form' === $post->post_type ) {
				return self::display_title( $post );
			}
			/* translators: %d: identifiant du formulaire Contact Form 7 */
			return sprintf( __( 'Formulaire n° %d', 'cyberpush-ultra' ), $form_id );
		};
		// Visiteur anonyme : seul le contexte de cette page est connu (provenance
		// d'une page interne inconnue, nouveau ou fidèle jamais connu).
		$visitor = function ( $row ) use ( $now_ts ) {
			$anon = (int) $row['anon'];
			return array(
				'ago'     => max( 0, $now_ts - (int) strtotime( $row['created_at'] . ' UTC' ) ),
				'at'      => (string) $row['created_at'],
				'source'  => 2 === $anon ? 'unknown' : ( '' !== (string) $row['referrer_source'] ? (string) $row['referrer_source'] : 'direct' ),
				'device'  => (string) $row['device'],
				'country' => (string) $row['country_code'],
				'is_new'  => $anon ? null : (bool) (int) $row['is_new'],
				'anon'    => $anon > 0,
			);
		};

		$wa_pages = array();
		foreach ( $wa_pages_raw as $row ) {
			$visits     = $page_visits[ (int) $row['oid'] > 0 ? 'o' . (int) $row['oid'] : (string) $row['url'] ] ?? 0;
			$wa_pages[] = $describe( $row['oid'], $row['url'] ) + array(
				'clicks'   => (int) $row['n'],
				'visitors' => (int) $row['v'],
				'sessions' => $visits,
				'rate'     => $percent( (int) $row['v'], $visits ),
			);
		}
		$cf_pages = array();
		foreach ( $cf_pages_raw as $row ) {
			$cf_pages[] = $describe( $row['oid'], $row['url'] ) + array( 'sent' => (int) $row['n'] );
		}
		$wa_recent = array();
		foreach ( $recent_wa as $row ) {
			$wa_recent[] = $visitor( $row ) + $describe( $row['object_id'], $row['url'] ) + array(
				'widget'    => (string) $row['widget'],
				'placement' => (string) $row['placement'],
				'phone'     => (string) $row['target'],
				'kind'      => (string) $row['kind'],
				'label'     => (string) $row['label'],
			);
		}
		$cf_recent = array();
		foreach ( $recent_cf7 as $row ) {
			$cf_recent[] = $visitor( $row ) + $describe( $row['object_id'], $row['url'] ) + array(
				'form'  => $form_title( absint( $row['target'] ) ),
				'fill'  => null === $row['fill_seconds'] ? null : (int) $row['fill_seconds'],
			);
		}
		$forms = array();
		foreach ( $form_ids as $form_id ) {
			$stat     = $form_stats[ $form_id ] ?? array();
			$post     = get_post( $form_id );
			$exists   = $post && 'wpcf7_contact_form' === $post->post_type;
			$views    = $stat['view']['v'] ?? 0;
			$starts   = $stat['start']['v'] ?? 0;
			$senders  = $stat['sent']['v'] ?? 0;
			$tries    = 0;
			foreach ( self::CF7_ATTEMPTS as $attempt ) {
				$tries += $stat[ $attempt ]['n'] ?? 0;
			}
			$forms[] = array(
				'id'          => $form_id,
				'title'       => $form_title( $form_id ),
				'exists'      => (bool) $exists,
				'edit_url'    => $exists ? admin_url( 'admin.php?page=wpcf7&post=' . $form_id . '&action=edit' ) : '',
				'views'       => max( $views, $starts, $senders ),
				'starts'      => max( $starts, $senders ),
				'sent'        => $stat['sent']['n'] ?? 0,
				'attempts'    => $tries,
				'invalid'     => $stat['invalid']['n'] ?? 0,
				'spam'        => $stat['spam']['n'] ?? 0,
				'failed'      => ( $stat['failed']['n'] ?? 0 ) + ( $stat['aborted']['n'] ?? 0 ),
				'abandon'     => $stat['abandon']['n'] ?? 0,
				'rate'        => $percent( $senders, max( $views, $starts, $senders ) ),
				'median_fill' => self::median( $fills[ $form_id ] ?? array() ),
				'server'      => $server[ $form_id ] ?? 0,
			);
		}
		usort( $forms, function ( $a, $b ) {
			return ( $b['sent'] - $a['sent'] ) ?: ( ( $b['views'] - $a['views'] ) ?: strcasecmp( $a['title'], $b['title'] ) );
		} );

		// Prises de contact = clics WhatsApp (intentions : l'envoi du message n'est pas
		// vérifiable) + messages de formulaire (comptés une seule fois).
		$leads      = $cur['wa'] + $messages;
		$prev_leads = $prev['wa'] + $prev_messages;
		$data       = array(
			'range'    => $range,
			'segment'  => $segment,
			'start'    => $start,
			'end'      => $end,
			'tracking' => array(
				'whatsapp'            => (bool) ( $settings['track_whatsapp'] ?? true ),
				'cf7'                 => (bool) ( $settings['track_cf7'] ?? true ),
				'whatsapp_conversion' => (bool) ( $settings['whatsapp_conversion'] ?? true ),
				'cf7_conversion'      => (bool) ( $settings['cf7_conversion'] ?? true ),
			),
			'sessions' => array( 'value' => $sessions, 'prev' => $prev_sessions, 'measured' => $measured, 'prev_measured' => $prev_measured ),
			// Taux de contact et visiteurs : calculés sur les visites suivies en continu.
			'coverage' => self::coverage( $sessions, $measured ),
			'leads'    => array(
				'value'     => $leads,
				'prev'      => $prev_leads,
				'delta'     => self::delta( $leads, $prev_leads ),
				'visitors'  => $cur['lead_visitors'],
				'rate'      => $visit_rate( $cur['lead_visitors'], $measured ),
				'prev_rate' => $visit_rate( $prev['lead_visitors'], $prev_measured ),
			),
			'series'   => $series,
			'heat'     => $heat,
			'sources'  => $sources,
			'whatsapp' => array(
				'intent'         => true, // un clic ouvre WhatsApp : ce n'est pas un message reçu
				'clicks'         => $cur['wa'],
				'prev'           => $prev['wa'],
				'delta'          => self::delta( $cur['wa'], $prev['wa'] ),
				'visitors'       => $cur['wa_visitors'],
				'prev_visitors'  => $prev['wa_visitors'],
				'rate'           => $visit_rate( $cur['wa_visitors'], $measured ),
				'prev_rate'      => $visit_rate( $prev['wa_visitors'], $prev_measured ),
				'median_seconds' => self::median( $wa_seconds ),
				'avg_scroll'     => null === $wa_scroll ? null : (int) round( (float) $wa_scroll ),
				'pages'          => $wa_pages,
				'devices'        => $group( 's.device', $wa_where ),
				'placements'     => $group( 'c.placement', $wa_where ),
				'widgets'        => $group( 'c.widget', $wa_where, 8 ),
				'kinds'          => $group( 'c.kind', $wa_where ),
				'countries'      => $group( 's.country_code', $wa_where, 6 ),
				'audience'       => $wa_audience,
				'numbers'        => $wa_numbers,
				'recent'         => $wa_recent,
			),
			'cf7'      => array(
				'active'       => $cf7_active,
				'forms_total'  => count( (array) $known ),
				'sent'         => $cur['cf7'],
				'prev'         => $prev['cf7'],
				'delta'        => self::delta( $cur['cf7'], $prev['cf7'] ),
				'messages'      => $messages,
				'prev_messages' => $prev_messages,
				'messages_delta' => self::delta( $messages, $prev_messages ),
				'senders'      => $cur['cf7_senders'],
				'attempts'     => $cf_tot['attempts'],
				'success_rate' => $percent( $cf_tot['sent'], $cf_tot['attempts'] ),
				'invalid'      => $cf_tot['invalid'],
				'spam'         => $cf_tot['spam'],
				'failed'       => $cf_tot['failed'] + $cf_tot['aborted'],
				'abandon'      => $cf_tot['abandon'],
				'funnel'       => $funnel,
				'conversion'   => $percent( $funnel['senders'], $funnel['views'] ),
				'median_fill'  => self::median( $all_fills ),
				'server'       => array(
					'sent'     => $server_total,
					'tracked'  => $tracked_total,
					'coverage' => $server_total > 0 ? (int) round( min( 100, $tracked_total / $server_total * 100 ) ) : null,
				),
				'forms'        => array_slice( $forms, 0, 12 ),
				'fields'       => $top_fields,
				'pages'        => $cf_pages,
				'recent'       => $cf_recent,
			),
		);

		self::set_cached_report( $cache_key, $data );
		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/content-breakdown
	// -------------------------------------------------------------------------

	public function handle_content_breakdown( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'breakdown', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl  = CU_DB::table( 'sessions' );
		$e_tbl  = CU_DB::table( 'events' );
		// Répartition en PAGES VUES (toutes, pages anonymes comprises) : une page
		// anonyme ne dit pas si deux pages appartiennent à la même visite.
		$seg_w  = self::segment_where( $segment, 'pages' );
		$a      = self::anon_col();

		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT e.object_type, COUNT(DISTINCT CASE WHEN {$a} = 0 THEN s.id END) AS sessions, COUNT(e.id) AS pageviews
				FROM {$e_tbl} e
				INNER JOIN {$s_tbl} s ON s.id = e.session_id
				WHERE e.event_type = 'pageview' AND e.created_at BETWEEN %s AND %s {$seg_w}
				GROUP BY e.object_type
				ORDER BY pageviews DESC
				LIMIT 30",
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);

		$total_pageviews = array_sum( array_column( (array) $rows, 'pageviews' ) ) ?: 1;

		// Map object_type to WordPress labels
		$type_labels = array(
			'post'    => __( 'Articles', 'cyberpush-ultra' ),
			'page'    => __( 'Pages', 'cyberpush-ultra' ),
			'archive' => __( 'Archives', 'cyberpush-ultra' ),
			'search'  => __( 'Recherche', 'cyberpush-ultra' ),
			'404'     => __( 'Pages 404', 'cyberpush-ultra' ),
		);
		foreach ( array_merge( get_post_types( array(), 'objects' ), get_taxonomies( array( 'public' => true ), 'objects' ) ) as $pt ) {
			if ( ! isset( $type_labels[ $pt->name ] ) ) {
				$type_labels[ $pt->name ] = $pt->labels->name ?? $pt->name;
			}
		}

		$result = array();
		foreach ( (array) $rows as $row ) {
			$type = $row['object_type'] ?: 'autre';
			$result[] = array(
				'type'       => $type,
				'label'      => $type_labels[ $type ] ?? $type,
				// Visites suivies en continu ayant vu ce type ; part : en pages vues.
				'sessions'   => (int) $row['sessions'],
				'pageviews'  => (int) $row['pageviews'],
				'percent'    => round( $row['pageviews'] / $total_pageviews * 100, 1 ),
			);
		}

		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/timeseries
	// -------------------------------------------------------------------------

	public function handle_timeseries( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'timeseries', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		$e_tbl = CU_DB::table( 'events' );
		// Visites (suivies + pages d'entrée anonymes), pages vues et événements : tous.
		$seg_w = self::segment_where( $segment, 'pages' );
		$a     = self::anon_col();

		$session_rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT DATE(s.first_seen) AS d, COALESCE(SUM({$a} < 2),0) AS sessions, SUM(s.pageviews) AS pageviews
				FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_w}
				GROUP BY d ORDER BY d ASC",
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);

		$cta_rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT DATE(e.created_at) AS d,
					SUM(e.event_type = 'cta_click') AS cta,
					SUM(e.event_type = 'conversion') AS conversions
				FROM {$e_tbl} e
				INNER JOIN {$s_tbl} s ON s.id = e.session_id
				WHERE e.event_type IN ('cta_click','conversion') AND e.created_at BETWEEN %s AND %s {$seg_w}
				GROUP BY d ORDER BY d ASC",
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);

		$session_map = array();
		foreach ( (array) $session_rows as $r ) $session_map[ $r['d'] ] = $r;
		$cta_map = array();
		foreach ( (array) $cta_rows as $r ) $cta_map[ $r['d'] ] = $r;

		$labels = array();
		$sessions = array(); $pageviews = array(); $cta = array(); $conversions = array();

		$dt = new DateTime( $start );
		$dt_end = new DateTime( $end );
		while ( $dt <= $dt_end ) {
			$d = $dt->format( 'Y-m-d' );
			$labels[]      = $dt->format( 'd/m' );
			$sessions[]    = (int) ( $session_map[ $d ]['sessions'] ?? 0 );
			$pageviews[]   = (int) ( $session_map[ $d ]['pageviews'] ?? 0 );
			$cta[]         = (int) ( $cta_map[ $d ]['cta'] ?? 0 );
			$conversions[] = (int) ( $cta_map[ $d ]['conversions'] ?? 0 );
			$dt->modify( '+1 day' );
		}

		$data = compact( 'labels', 'sessions', 'pageviews', 'cta', 'conversions' );
		self::set_cached_report( $cache_key, $data );
		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/heatmap
	// -------------------------------------------------------------------------

	public function handle_heatmap( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'heatmap2', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		// Arrivées de visites (suivies + pages d'entrée anonymes) par jour et heure.
		$seg_w = self::segment_where( $segment, 'visits' );

		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT WEEKDAY(s.first_seen) AS dow, HOUR(s.first_seen) AS h, COUNT(*) AS n
				FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_w}
				GROUP BY dow, h",
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);

		// Build 7×24 grid (0=Monday ... 6=Sunday)
		$grid = array();
		for ( $d = 0; $d < 7; $d++ ) {
			for ( $h = 0; $h < 24; $h++ ) {
				$grid[ $d ][ $h ] = 0;
			}
		}
		$max = 1;
		foreach ( (array) $rows as $r ) {
			$grid[ (int) $r['dow'] ][ (int) $r['h'] ] = (int) $r['n'];
			if ( (int) $r['n'] > $max ) $max = (int) $r['n'];
		}

		$data = array( 'grid' => $grid, 'max' => $max );
		self::set_cached_report( $cache_key, $data );
		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/content-performance
	// -------------------------------------------------------------------------

	public function handle_content_performance( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist( array( 'sessions', 'events', 'content_daily' ) ) ) return self::migration_error();
		self::maybe_rollup();

		$range             = self::parse_range( $request );
		$requested_segment = self::parse_segment( $request );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );

		$cd_tbl = CU_DB::table( 'content_daily' );
		$s_tbl  = CU_DB::table( 'sessions' );
		$e_tbl  = CU_DB::table( 'events' );

		$type_filter   = sanitize_key( $request->get_param( 'type' ) ?: '' );
		$search        = sanitize_text_field( $request->get_param( 'search' ) ?: '' );
		$sort_by       = sanitize_key( $request->get_param( 'sort' ) ?: 'sessions' );
		$page          = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$per_page      = max( 5, min( 100, (int) CU_Settings::get( 'dashboard', 'rows_per_table', 20 ) ) );

		$allowed_sorts = array( 'sessions', 'pageviews', 'bounces', 'cta_clicks', 'conversions', 'avg_scroll', 'total_duration', 'entries', 'exits', 'measured_sessions' );
		if ( ! in_array( $sort_by, $allowed_sorts, true ) ) $sort_by = 'sessions';

		$cache_key = self::get_transient_key( 'content2', $range, 'reliable', md5( wp_json_encode( array( $type_filter, $search, $sort_by, $page, $per_page, $requested_segment ) ) ) );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		// `content_daily` contient volontairement le trafic humain fiable. La
		// pagination et la recherche sont faites en SQL avant toute hydratation WP.
		$where_parts = array( "cd.stat_date BETWEEN %s AND %s" );
		$where_vals  = array( $start, $end );
		$join_sql    = '';

		if ( $type_filter ) {
			$where_parts[] = "cd.object_type = %s";
			$where_vals[]  = $type_filter;
		}
		if ( $search ) {
			$join_sql      = " LEFT JOIN {$wpdb->posts} p ON p.ID = cd.object_id ";
			$where_parts[] = 'p.post_title LIKE %s';
			$where_vals[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where_parts );
		$count_sql = "SELECT COUNT(*) FROM (
			SELECT cd.object_id, cd.object_type FROM {$cd_tbl} cd {$join_sql}
			{$where_sql} GROUP BY cd.object_id, cd.object_type
		) cu_content_count";
		$total       = (int) self::report_get_var( $wpdb->prepare( $count_sql, ...$where_vals ) );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );
		$offset      = ( $page - 1 ) * $per_page;
		$query_vals  = array_merge( $where_vals, array( $per_page, $offset ) );

		$measured_col = self::anon_ready() ? 'SUM(cd.measured_sessions)' : 'SUM(cd.sessions)';
		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT cd.object_id, cd.object_type,
					SUM(cd.sessions) AS sessions, SUM(cd.pageviews) AS pageviews,
					{$measured_col} AS measured_sessions,
					SUM(cd.entries) AS entries, SUM(cd.exits) AS exits,
					SUM(cd.bounces) AS bounces,
					SUM(cd.cta_clicks) AS cta_clicks, SUM(cd.conversions) AS conversions,
					CASE WHEN SUM(cd.sessions) > 0
						THEN SUM(cd.avg_scroll * cd.sessions) / SUM(cd.sessions) ELSE 0 END AS avg_scroll,
					SUM(cd.total_duration) AS total_duration
				FROM {$cd_tbl} cd {$join_sql}
				{$where_sql}
				GROUP BY cd.object_id, cd.object_type
				ORDER BY {$sort_by} DESC
				LIMIT %d OFFSET %d",
				...$query_vals
			),
			ARRAY_A
		);

		// Les taux sont basés sur des VISITES SUIVIES uniques ayant déclenché
		// l'action (rebond, CTR, conversion : jamais sur une page anonyme, dont la
		// suite de visite est inconnue), tandis que la colonne « clics » conserve le
		// nombre brut d'événements, pages anonymes comprises.
		$engagement_map = array();
		if ( ! empty( $rows ) ) {
			$pairs = array();
			$pair_values = array( $start . ' 00:00:00', $end . ' 23:59:59' );
			foreach ( $rows as $metric_row ) {
				$pairs[]       = '(e.object_id = %d AND e.object_type = %s)';
				$pair_values[] = (int) $metric_row['object_id'];
				$pair_values[] = (string) $metric_row['object_type'];
			}
			$reliable = self::segment_where( 'reliable', 'measured' );
			$engagement_rows = self::report_get_results(
				$wpdb->prepare(
					"SELECT e.object_id, e.object_type,
						COUNT(DISTINCT CASE WHEN e.event_type = 'cta_click' THEN e.session_id END) AS cta_sessions,
						COUNT(DISTINCT CASE WHEN e.event_type = 'conversion' THEN e.session_id END) AS conversion_sessions
					FROM {$e_tbl} e INNER JOIN {$s_tbl} s ON s.id = e.session_id
					WHERE e.created_at BETWEEN %s AND %s {$reliable}
						AND e.event_type IN ('cta_click','conversion')
						AND (" . implode( ' OR ', $pairs ) . ")
					GROUP BY e.object_id, e.object_type",
					...$pair_values
				),
				ARRAY_A
			);
			foreach ( $engagement_rows as $engagement_row ) {
				$engagement_map[ $engagement_row['object_type'] . '|' . $engagement_row['object_id'] ] = $engagement_row;
			}
		}

		// Enrich with WP post data
		self::prime_content_rows( $rows );
		$result = array();
		foreach ( (array) $rows as $row ) {
			$obj_id = (int) $row['object_id'];
			$post   = $obj_id ? get_post( $obj_id ) : null;
			$title  = self::display_title( $post, '#' . $obj_id );

			$sessions   = (int) $row['sessions'];
			$measured   = (int) $row['measured_sessions'];
			$bounces    = (int) $row['bounces'];
			$cta_clicks = (int) $row['cta_clicks'];
			$conversions = (int) $row['conversions'];
			$total_dur  = (int) $row['total_duration'];
			$pageviews  = (int) $row['pageviews'];
			$engagement = $engagement_map[ $row['object_type'] . '|' . $obj_id ] ?? array();
			$cta_sessions        = min( $measured, (int) ( $engagement['cta_sessions'] ?? 0 ) );
			$conversion_sessions = min( $measured, (int) ( $engagement['conversion_sessions'] ?? 0 ) );

			// Taux par visite : sur les visites suivies (null = « n/d », jamais 0 %).
			$bounce_rate = self::rate( $bounces, $measured, 1 );
			$avg_time    = $sessions ? round( $total_dur / $sessions ) : 0;
			$ctr         = self::rate( $cta_sessions, $measured, 2 );
			$conv_rate   = self::rate( $conversion_sessions, $measured, 2 );

			// Diagnostic — jamais de verdict sur un échantillon trop petit (une
			// seule visite convertie donnerait sinon « très rentable » à 100 %).
			if ( $measured < 10 ) {
				$diag       = __( 'peu de données', 'cyberpush-ultra' );
				$diag_class = 'cu-badge-gray';
			} elseif ( $conv_rate > 5 ) {
				$diag       = __( 'très rentable', 'cyberpush-ultra' );
				$diag_class = 'cu-badge-purple';
			} elseif ( $bounce_rate > 70 ) {
				$diag       = __( 'fort rebond', 'cyberpush-ultra' );
				$diag_class = 'cu-badge-red';
			} elseif ( $sessions > 50 && $bounce_rate < 40 && $avg_time > 60 ) {
				$diag       = __( 'excellent', 'cyberpush-ultra' );
				$diag_class = 'cu-badge-green';
			} else {
				$diag       = __( 'à optimiser', 'cyberpush-ultra' );
				$diag_class = 'cu-badge-yellow';
			}

			// Author & category
			$author   = $post ? get_the_author_meta( 'display_name', $post->post_author ) : '';
			$category = self::category_names( $post );

			$result[] = array(
				'object_id'   => $obj_id,
				'object_type' => $row['object_type'],
				'type_label'  => self::content_type_label( $row['object_type'] ),
				'title'       => $title,
				'author'      => $author,
				'category'    => $category,
				'date'        => $post ? $post->post_date : '',
				'sessions'    => $sessions,
				'measured_sessions' => $measured,
				'pageviews'   => $pageviews,
				'avg_time'    => $avg_time,
				'bounce_rate' => $bounce_rate,
				'entries'     => (int) $row['entries'],
				'exits'       => (int) $row['exits'],
				'avg_scroll'  => round( (float) $row['avg_scroll'] ),
				'cta_clicks'  => $cta_clicks,
				'cta_sessions'=> $cta_sessions,
				'ctr'         => $ctr,
				'conversions' => $conversions,
				'conv_rate'   => $conv_rate,
				'diag'        => $diag,
				'diag_class'  => $diag_class,
			);
		}

		$data = array(
			'rows'       => array_values( $result ),
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages' => $total ? $total_pages : 0,
			'segment'     => 'reliable',
			'requested_segment' => $requested_segment,
		);
		self::set_cached_report( $cache_key, $data );
		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/entry-exit-pages
	// -------------------------------------------------------------------------

	public function handle_entry_exit_pages( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$mode    = in_array( $request->get_param( 'mode' ), array( 'entry', 'exit' ), true ) ? $request->get_param( 'mode' ) : 'entry';
		$cache_key = self::get_transient_key( 'entryexit_' . $mode, $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		$col   = 'entry' === $mode ? 'landing_url' : 'exit_url';
		// Entrées : toute visite (une page d'entrée anonyme est une entrée). Sorties :
		// visites suivies en continu seulement (la suite d'une page anonyme est inconnue).
		$seg_w_bare = self::segment_where( $segment, 'entry' === $mode ? 'visits' : 'measured' );

		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT s.{$col} AS url, COUNT(*) AS n
				FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_w_bare}
				AND s.{$col} IS NOT NULL AND s.{$col} <> ''
				GROUP BY url ORDER BY n DESC LIMIT 60",
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);

		// Les URL d'avant 1.2.0 peuvent porter des paramètres personnels : elles
		// sont re-minimisées à la lecture puis regroupées.
		$merged = array();
		foreach ( (array) $rows as $row ) {
			$url = self::display_url( $row['url'] );
			$merged[ $url ] = ( isset( $merged[ $url ] ) ? $merged[ $url ] : 0 ) + (int) $row['n'];
		}
		arsort( $merged );
		$result = array();
		foreach ( array_slice( $merged, 0, 20, true ) as $url => $n ) {
			$result[] = array( 'url' => (string) $url, 'n' => (int) $n );
		}
		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/visitor-loyalty
	// -------------------------------------------------------------------------

	public function handle_visitor_loyalty( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'loyalty', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		// Nouveau / fidèle : connu uniquement pour une visite suivie en continu.
		$seg_w = self::segment_where( $segment, 'measured' );

		$counts = self::report_get_row(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( s.is_new = 1 ), 0 ) AS fresh, COALESCE( SUM( s.is_new = 0 ), 0 ) AS back
				FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s {$seg_w}",
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);
		$new_count = (int) ( $counts['fresh'] ?? 0 );
		$ret_count = (int) ( $counts['back'] ?? 0 );

		$total = $new_count + $ret_count ?: 1;

		$data = array(
			'new'           => $new_count,
			'returning'     => $ret_count,
			'new_percent'   => round( $new_count / $total * 100, 1 ),
			'ret_percent'   => round( $ret_count / $total * 100, 1 ),
			'measured_only' => true,
		);
		self::set_cached_report( $cache_key, $data );
		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/scroll-depth
	// -------------------------------------------------------------------------

	public function handle_scroll_depth( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'scroll2', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$e_tbl = CU_DB::table( 'events' );
		$s_tbl = CU_DB::table( 'sessions' );
		$seg_w = self::segment_where( $segment, 'pages' );

		// Par PAGE VUE (pages anonymes comprises) : une page est une paire
		// (ligne de session, URL). Part des pages vues lues jusqu'à 25 / 50 / 75 / 90 %.
		$row = self::report_get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT CASE WHEN e.event_type = 'scroll' AND e.scroll_percent >= 25 THEN CONCAT(e.session_id, '|', e.url) END) AS p25,
					COUNT(DISTINCT CASE WHEN e.event_type = 'scroll' AND e.scroll_percent >= 50 THEN CONCAT(e.session_id, '|', e.url) END) AS p50,
					COUNT(DISTINCT CASE WHEN e.event_type = 'scroll' AND e.scroll_percent >= 75 THEN CONCAT(e.session_id, '|', e.url) END) AS p75,
					COUNT(DISTINCT CASE WHEN e.event_type = 'scroll' AND e.scroll_percent >= 90 THEN CONCAT(e.session_id, '|', e.url) END) AS p90,
					COUNT(DISTINCT CASE WHEN e.event_type = 'pageview' THEN CONCAT(e.session_id, '|', e.url) END) AS total
				FROM {$e_tbl} e
				INNER JOIN {$s_tbl} s ON s.id = e.session_id
				WHERE e.event_type IN ('pageview','scroll') AND e.created_at BETWEEN %s AND %s
					AND s.first_seen BETWEEN %s AND %s {$seg_w}",
				$start . ' 00:00:00',
				$end . ' 23:59:59',
				$start . ' 00:00:00',
				$end . ' 23:59:59'
			),
			ARRAY_A
		);

		$row   = $row ?: array( 'p25' => 0, 'p50' => 0, 'p75' => 0, 'p90' => 0, 'total' => 0 );
		$total = (int) $row['total'];
		$share = function ( $n ) use ( $total ) {
			return $total > 0 ? round( min( 100, (int) $n / $total * 100 ), 1 ) : null;
		};

		$data = array(
			'p25'   => $share( $row['p25'] ),
			'p50'   => $share( $row['p50'] ),
			'p75'   => $share( $row['p75'] ),
			'p90'   => $share( $row['p90'] ),
			'pages' => $total,
			'unit'  => 'pageviews',
		);
		self::set_cached_report( $cache_key, $data );
		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/technical-breakdown
	// -------------------------------------------------------------------------

	public function handle_technical_breakdown( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'tech', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		$seg_w = self::segment_where( $segment, 'visits' );

		$device_rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT device AS label, COUNT(*) AS value FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_w}
				GROUP BY device ORDER BY value DESC",
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);

		$browser_rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT browser AS label, COUNT(*) AS value FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_w}
				GROUP BY browser ORDER BY value DESC",
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);

		$os_rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT os AS label, COUNT(*) AS value FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_w}
				GROUP BY os ORDER BY value DESC",
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);

		$data = array(
			'devices'  => array_values( (array) $device_rows ),
			'browsers' => array_values( (array) $browser_rows ),
			'os'       => array_values( (array) $os_rows ),
		);

		self::set_cached_report( $cache_key, $data );
		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/countries
	// -------------------------------------------------------------------------

	public function handle_countries( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		// « countries2 » : format enrichi (carte), distinct des rapports déjà en cache.
		$cache_key = self::get_transient_key( 'countries2', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$from  = $start . ' 00:00:00';
		$to    = $end . ' 23:59:59';
		$s_tbl = CU_DB::table( 'sessions' );
		$seg_v = self::segment_where( $segment, 'visits' );
		$seg_p = self::segment_where( $segment, 'pages' );
		$a     = self::anon_col();
		$code  = "COALESCE( NULLIF( s.country_code, '' ), '' )";

		// Visites (suivies + pages d'entrée anonymes) ; durée et nouveaux visiteurs
		// uniquement sur les visites suivies en continu (seules à les mesurer).
		$by = array();
		foreach ( (array) self::report_get_results( $wpdb->prepare(
			"SELECT {$code} AS code, COUNT(*) AS visits, COALESCE( SUM( {$a} = 0 ), 0 ) AS measured,
				COALESCE( SUM( CASE WHEN {$a} = 0 THEN s.duration ELSE 0 END ), 0 ) AS dur,
				COALESCE( SUM( CASE WHEN {$a} = 0 AND s.is_new = 1 THEN 1 ELSE 0 END ), 0 ) AS new_v
			FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s {$seg_v} GROUP BY code",
			$from,
			$to
		), ARRAY_A ) as $r ) {
			$by[ (string) $r['code'] ] = array(
				'visits'   => (int) $r['visits'],
				'measured' => (int) $r['measured'],
				'dur'      => (int) $r['dur'],
				'new'      => (int) $r['new_v'],
			);
		}
		// Pages vues : toutes les lignes (pages anonymes internes comprises).
		foreach ( (array) self::report_get_results( $wpdb->prepare(
			"SELECT {$code} AS code, COALESCE( SUM( s.pageviews ), 0 ) AS pv FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s {$seg_p} GROUP BY code",
			$from,
			$to
		), ARRAY_A ) as $r ) {
			$by[ (string) $r['code'] ]['pv'] = (int) $r['pv'];
		}
		// Prises de contact par pays : clics WhatsApp et messages Contact Form 7 réussis.
		if ( self::report_tables_exist( array( 'contacts' ) ) ) {
			$c_tbl = CU_DB::table( 'contacts' );
			foreach ( (array) self::report_get_results( $wpdb->prepare(
				"SELECT {$code} AS code, COALESCE( SUM( c.channel = 'whatsapp' ), 0 ) AS wa, COALESCE( SUM( c.channel = 'cf7' AND c.action = 'sent' ), 0 ) AS cf7
				FROM {$c_tbl} c INNER JOIN {$s_tbl} s ON s.id = c.session_id
				WHERE c.created_at BETWEEN %s AND %s {$seg_p} GROUP BY code",
				$from,
				$to
			), ARRAY_A ) as $r ) {
				$by[ (string) $r['code'] ]['wa']  = (int) $r['wa'];
				$by[ (string) $r['code'] ]['cf7'] = (int) $r['cf7'];
			}
		}

		$total = 0;
		foreach ( $by as $row ) {
			$total += isset( $row['visits'] ) ? (int) $row['visits'] : 0;
		}
		$rows    = array();
		$unknown = array( 'visits' => 0, 'pageviews' => 0, 'wa' => 0, 'leads' => 0 );
		foreach ( $by as $key => $row ) {
			$row += array( 'visits' => 0, 'measured' => 0, 'dur' => 0, 'new' => 0, 'pv' => 0, 'wa' => 0, 'cf7' => 0 );
			$item = array(
				'code'         => (string) $key,
				'sessions'     => $row['visits'],
				'percent'      => $total > 0 ? round( $row['visits'] / $total * 100, 1 ) : 0,
				'pageviews'    => $row['pv'],
				'wa'           => $row['wa'],
				'leads'        => $row['wa'] + $row['cf7'],
				// Taux de contact par visite : null (« n/d ») sans visite.
				'contact_rate' => self::rate( $row['wa'] + $row['cf7'], $row['visits'] ),
				'avg_duration' => $row['measured'] > 0 ? (int) round( $row['dur'] / $row['measured'] ) : null,
				'new_percent'  => self::rate( $row['new'], $row['measured'], 0 ),
			);
			if ( '' === $item['code'] ) {
				$unknown = array( 'visits' => $item['sessions'], 'percent' => $item['percent'], 'pageviews' => $item['pageviews'], 'wa' => $item['wa'], 'leads' => $item['leads'] );
				continue;
			}
			if ( $item['sessions'] > 0 || $item['leads'] > 0 ) {
				$rows[] = $item;
			}
		}
		usort( $rows, function ( $x, $y ) {
			return ( $y['sessions'] - $x['sessions'] ) ?: ( $y['leads'] - $x['leads'] ) ?: strcmp( $x['code'], $y['code'] );
		} );

		$db     = CU_Geo::local_db_status();
		$result = array(
			'rows'    => array_slice( $rows, 0, 250 ),
			'total'   => $total,
			'known'   => $total - (int) $unknown['visits'],
			'unknown' => $unknown,
			'geo'     => array(
				'configured' => CU_Geo::lookup_configured(),
				'provider'   => (string) CU_Settings::get( 'analytics', 'geo_provider', 'none' ),
				'cloudflare' => CU_Geo::behind_cloudflare(),
				'local_db'   => array( 'available' => (bool) $db['available'], 'edition' => (string) $db['edition'], 'error' => (string) $db['error'] ),
			),
			'tracking_whatsapp' => (bool) CU_Settings::get( 'analytics', 'track_whatsapp', true ),
		);

		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/outbound — clics sortants (liens externes, téléchargements,
	// appels, e-mails)
	// -------------------------------------------------------------------------

	public function handle_outbound( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range     = self::parse_range( $request );
		$segment   = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'outbound', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end, $prev_start, $prev_end ) = self::period_dates( $range );
		$e_tbl = CU_DB::table( 'events' );
		$s_tbl = CU_DB::table( 'sessions' );
		$seg_p = self::segment_where( $segment, 'pages' );
		$base  = "FROM {$e_tbl} e INNER JOIN {$s_tbl} s ON s.id = e.session_id WHERE e.event_type = 'outbound' AND e.created_at BETWEEN %s AND %s {$seg_p}";

		$totals = array( 'external' => 0, 'download' => 0, 'tel' => 0, 'mail' => 0 );
		$links  = array();
		$hosts  = array();
		foreach ( (array) self::report_get_results( $wpdb->prepare(
			"SELECT e.meta AS meta, COUNT(*) AS n, COUNT( DISTINCT e.session_id ) AS v {$base} GROUP BY e.meta ORDER BY n DESC LIMIT 500",
			$start . ' 00:00:00',
			$end . ' 23:59:59'
		), ARRAY_A ) as $row ) {
			$meta = json_decode( (string) $row['meta'], true );
			if ( ! is_array( $meta ) || empty( $meta['k'] ) || ! isset( $totals[ $meta['k'] ], $meta['u'] ) ) {
				continue;
			}
			$kind = (string) $meta['k'];
			$key  = $kind . '|' . (string) $meta['u'];
			$n    = (int) $row['n'];
			$totals[ $kind ] += $n;
			if ( ! isset( $links[ $key ] ) ) {
				$links[ $key ] = array( 'kind' => $kind, 'target' => (string) $meta['u'], 'label' => '', 'label_n' => 0, 'clicks' => 0, 'visits' => 0 );
			}
			$links[ $key ]['clicks'] += $n;
			$links[ $key ]['visits'] += (int) $row['v'];
			// Libellé le plus fréquent du lien (texte affiché sur le site).
			if ( ! empty( $meta['l'] ) && $n > $links[ $key ]['label_n'] ) {
				$links[ $key ]['label']   = (string) $meta['l'];
				$links[ $key ]['label_n'] = $n;
			}
			if ( 'external' === $kind || 'download' === $kind ) {
				$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( (string) $meta['u'], PHP_URL_HOST ) ) );
				if ( '' !== $host && ! self::is_allowed_host( $host ) && ! self::is_allowed_host( 'www.' . $host ) ) {
					$hosts[ $host ] = ( isset( $hosts[ $host ] ) ? $hosts[ $host ] : 0 ) + $n;
				}
			}
		}
		usort( $links, function ( $x, $y ) {
			return $y['clicks'] - $x['clicks'];
		} );
		$links = array_map( function ( $link ) {
			unset( $link['label_n'] );
			return $link;
		}, array_slice( $links, 0, 20 ) );
		arsort( $hosts );
		$domains = array();
		foreach ( array_slice( $hosts, 0, 10, true ) as $host => $n ) {
			$domains[] = array( 'host' => (string) $host, 'clicks' => (int) $n );
		}

		// Pages d'où partent les clics (titre réel quand la page est un contenu).
		$pages = array();
		foreach ( (array) self::report_get_results( $wpdb->prepare(
			"SELECT MAX( e.object_id ) AS oid, e.url AS url, COUNT(*) AS n {$base} GROUP BY e.url ORDER BY n DESC LIMIT 8",
			$start . ' 00:00:00',
			$end . ' 23:59:59'
		), ARRAY_A ) as $row ) {
			$post  = (int) $row['oid'] > 0 ? get_post( (int) $row['oid'] ) : null;
			$title = $post ? self::display_title( $post, '' ) : '';
			$pages[] = array( 'title' => $title, 'path' => self::display_path( (string) $row['url'] ), 'clicks' => (int) $row['n'] );
		}

		$prev = (int) self::report_get_var( $wpdb->prepare( "SELECT COUNT(*) {$base}", $prev_start . ' 00:00:00', $prev_end . ' 23:59:59' ) );
		$sum  = array_sum( $totals );
		$result = array(
			'tracking' => (bool) CU_Settings::get( 'analytics', 'track_outbound', true ),
			'totals'   => $totals,
			'total'    => $sum,
			'prev'     => $prev,
			'delta'    => self::delta_or_null( $sum, $prev ),
			'links'    => $links,
			'domains'  => $domains,
			'pages'    => $pages,
		);
		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/audience — langues des navigateurs et tailles d'écran
	// -------------------------------------------------------------------------

	public function handle_audience( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range     = self::parse_range( $request );
		$segment   = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'audience', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		$result = array( 'ready' => self::schema_at_least( '1.9.4' ), 'languages' => array(), 'screens' => array(), 'measured' => 0 );
		if ( $result['ready'] ) {
			global $wpdb;
			list( $start, $end ) = self::period_dates( $range );
			$s_tbl = CU_DB::table( 'sessions' );
			$seg_v = self::segment_where( $segment, 'visits' );
			$args  = array( $start . ' 00:00:00', $end . ' 23:59:59' );
			$top   = function ( $column, $limit ) use ( $wpdb, $s_tbl, $seg_v, $args ) {
				$rows  = (array) self::report_get_results( $wpdb->prepare(
					"SELECT s.{$column} AS k, COUNT(*) AS n FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s {$seg_v} AND s.{$column} IS NOT NULL AND s.{$column} <> '' GROUP BY s.{$column} ORDER BY n DESC LIMIT " . (int) $limit,
					$args[0],
					$args[1]
				), ARRAY_A );
				return array_map( function ( $row ) {
					return array( 'key' => (string) $row['k'], 'visits' => (int) $row['n'] );
				}, $rows );
			};
			$result['languages'] = $top( 'language', 15 );
			$result['screens']   = $top( 'screen', 15 );
			// Visites qui portent ces informations (envoyées par le tracker 1.3.3+).
			$result['measured'] = (int) self::report_get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s_tbl} s WHERE s.first_seen BETWEEN %s AND %s {$seg_v} AND s.language IS NOT NULL", $args[0], $args[1] ) );
		}
		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/traffic-sources
	// -------------------------------------------------------------------------

	public function handle_traffic_sources( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'sources', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		$seg_w = self::segment_where( $segment, 'visits' );

		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT referrer_source AS source, COUNT(*) AS sessions FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_w}
				GROUP BY referrer_source ORDER BY sessions DESC",
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);

		$source_labels = array(
			'direct'   => __( 'Direct', 'cyberpush-ultra' ),
			'google'   => __( 'Moteurs de recherche / SEO', 'cyberpush-ultra' ),
			'social'   => __( 'Réseaux sociaux', 'cyberpush-ultra' ),
			'referral' => __( 'Référents', 'cyberpush-ultra' ),
			'campaign' => __( 'Campagnes UTM', 'cyberpush-ultra' ),
			'email'    => __( 'Email', 'cyberpush-ultra' ),
			'ia'       => __( 'IA (ChatGPT, Perplexity…)', 'cyberpush-ultra' ),
		);

		$total = (int) self::report_get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_w}",
				$start . ' 00:00:00', $end . ' 23:59:59'
			)
		) ?: 1;
		$result = array();
		foreach ( (array) $rows as $r ) {
			$src = $r['source'] ?: 'direct';
			$result[] = array(
				'source'   => $src,
				'label'    => $source_labels[ $src ] ?? $src,
				'sessions' => (int) $r['sessions'],
				'percent'  => round( $r['sessions'] / $total * 100, 1 ),
			);
		}

		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/referrers — Top domaines référents (vrais domaines externes)
	// -------------------------------------------------------------------------

	public function handle_referrers( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range     = self::parse_range( $request );
		$segment   = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'referrers', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		$seg_w = self::segment_where( $segment, 'visits' );
		$a     = self::anon_col();

		// GROUP BY host ; MIN(source) pour rester compatible ONLY_FULL_GROUP_BY.
		// Pages vues par référent : visites suivies seulement (la suite d'une
		// page d'entrée anonyme n'est pas rattachée à sa provenance).
		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT s.referrer_host AS host, MIN(s.referrer_source) AS source,
					COUNT(*) AS sessions, SUM(CASE WHEN {$a} = 0 THEN s.pageviews ELSE 0 END) AS pageviews
				FROM {$s_tbl} s
				WHERE s.first_seen BETWEEN %s AND %s {$seg_w}
					AND s.referrer_host IS NOT NULL AND s.referrer_host <> ''
				GROUP BY s.referrer_host
				ORDER BY sessions DESC LIMIT 30",
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);

		$source_labels = array(
			'google'   => __( 'Moteur de recherche', 'cyberpush-ultra' ),
			'social'   => __( 'Réseau social', 'cyberpush-ultra' ),
			'referral' => __( 'Référent', 'cyberpush-ultra' ),
			'campaign' => __( 'Campagne', 'cyberpush-ultra' ),
			'email'    => __( 'Email', 'cyberpush-ultra' ),
			'ia'       => __( 'IA', 'cyberpush-ultra' ),
		);

		$total  = array_sum( array_column( (array) $rows, 'sessions' ) ) ?: 1;
		$result = array();
		foreach ( (array) $rows as $r ) {
			$src = $r['source'] ?: 'referral';
			$result[] = array(
				'host'      => $r['host'],
				'source'    => $src,
				'label'     => $source_labels[ $src ] ?? __( 'Référent', 'cyberpush-ultra' ),
				'sessions'  => (int) $r['sessions'],
				'pageviews' => (int) $r['pageviews'],
				'percent'   => round( $r['sessions'] / $total * 100, 1 ),
			);
		}

		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/ai-traffic — Trafic IA (LLM référents + conversion + citations)
	// -------------------------------------------------------------------------

	public function handle_ai_traffic( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range     = self::parse_range( $request );
		$cache_key = self::get_transient_key( 'aitraffic', $range, 'reliable' );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		$e_tbl = CU_DB::table( 'events' );
		$rel   = self::segment_where( 'reliable', 'visits' );
		$a     = self::anon_col();

		// Référents IA (visiteurs venus d'un chatbot) par LLM, + conversions. Le taux
		// de conversion par visite porte sur les visites suivies en continu (la suite
		// d'une page d'entrée anonyme n'est pas rattachée à sa provenance).
		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT s.referrer_host AS host,
					COUNT(DISTINCT s.id) AS sessions,
					COUNT(DISTINCT CASE WHEN {$a} = 0 THEN s.id END) AS measured,
					COUNT(DISTINCT CASE WHEN e.event_type = 'conversion' AND {$a} = 0 THEN s.id END) AS conversions
				FROM {$s_tbl} s
				LEFT JOIN {$e_tbl} e ON e.session_id = s.id AND e.event_type = 'conversion'
					AND e.created_at BETWEEN %s AND %s
				WHERE s.first_seen BETWEEN %s AND %s {$rel} AND s.referrer_source = 'ia'
				GROUP BY s.referrer_host ORDER BY sessions DESC LIMIT 30",
				$start . ' 00:00:00', $end . ' 23:59:59',
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);

		$llms = array();
		foreach ( (array) $rows as $r ) {
			$name = self::ai_llm_name( $r['host'] );
			if ( ! isset( $llms[ $name ] ) ) {
				$llms[ $name ] = array( 'llm' => $name, 'sessions' => 0, 'measured' => 0, 'conversions' => 0, 'rate' => null );
			}
			$llms[ $name ]['sessions']    += (int) $r['sessions'];
			$llms[ $name ]['measured']    += (int) $r['measured'];
			$llms[ $name ]['conversions'] += (int) $r['conversions'];
		}
		foreach ( $llms as &$llm_row ) {
			$llm_row['rate'] = self::rate( $llm_row['conversions'], $llm_row['measured'], 2 );
		}
		unset( $llm_row );
		$llms = array_values( $llms );
		usort( $llms, fn( $a, $b ) => $b['sessions'] - $a['sessions'] );

		// Conversion IA vs organique (Google), calculée uniquement avec les données du site.
		$cmp = self::report_get_results(
			$wpdb->prepare(
				"SELECT s.referrer_source AS src,
					COUNT(DISTINCT s.id) AS sessions,
					COUNT(DISTINCT CASE WHEN {$a} = 0 THEN s.id END) AS measured,
					COUNT(DISTINCT CASE WHEN e.event_type = 'conversion' AND {$a} = 0 THEN s.id END) AS conversions
				FROM {$s_tbl} s
				LEFT JOIN {$e_tbl} e ON e.session_id = s.id AND e.event_type = 'conversion'
					AND e.created_at BETWEEN %s AND %s
				WHERE s.first_seen BETWEEN %s AND %s {$rel} AND s.referrer_source IN ('ia','google')
				GROUP BY s.referrer_source",
				$start . ' 00:00:00', $end . ' 23:59:59',
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);
		$compare = array();
		foreach ( (array) $cmp as $c ) {
			$sess = (int) $c['sessions'];
			$meas = (int) $c['measured'];
			$conv = (int) $c['conversions'];
			$compare[ $c['src'] ] = array( 'sessions' => $sess, 'measured' => $meas, 'conversions' => $conv, 'rate' => self::rate( $conv, $meas, 2 ) );
		}

		// Passages des robots IA (journal serveur, JS non exécuté), identifiés par
		// le User-Agent qu'ils déclarent : non vérifié, donc falsifiable.
		$citations = array();
		$cit_total = 0;
		$dt        = new DateTime( $start );
		$dt_end    = new DateTime( $end );
		while ( $dt <= $dt_end ) {
			$h = get_transient( 'cu_ai_hits_' . $dt->format( 'Ymd' ) );
			if ( is_array( $h ) ) {
				foreach ( $h as $bot => $n ) {
					if ( '_m' === $bot || ! is_scalar( $n ) ) {
						continue; // horodatage interne de limitation des écritures
					}
					$citations[ $bot ] = ( isset( $citations[ $bot ] ) ? $citations[ $bot ] : 0 ) + (int) $n;
					$cit_total        += (int) $n;
				}
			}
			$dt->modify( '+1 day' );
		}
		arsort( $citations );
		$cit_list = array();
		foreach ( $citations as $bot => $n ) {
			$cit_list[] = array( 'bot' => $bot, 'hits' => (int) $n );
		}

		$data = array(
			'llms'            => $llms,
			'total_sessions'  => array_sum( array_column( $llms, 'sessions' ) ),
			'compare'         => array(
				'ia'     => isset( $compare['ia'] ) ? $compare['ia'] : array( 'sessions' => 0, 'measured' => 0, 'conversions' => 0, 'rate' => null ),
				'google' => isset( $compare['google'] ) ? $compare['google'] : array( 'sessions' => 0, 'measured' => 0, 'conversions' => 0, 'rate' => null ),
			),
			'citations'       => $cit_list,
			'citations_total' => $cit_total,
			// Sans cache objet persistant : au plus un passage compté par minute et par robot.
			'citations_sampled' => ! wp_using_ext_object_cache(),
		);
		self::set_cached_report( $cache_key, $data );
		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/recurring-countries — Pays les plus récurrents (fidélité)
	// -------------------------------------------------------------------------

	public function handle_recurring_countries( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range     = self::parse_range( $request );
		$cache_key = self::get_transient_key( 'recountries', $range, 'reliable' );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		$rel   = self::segment_where( 'reliable', 'visits' );
		$a     = self::anon_col();

		try {
			// Visites par pays : toutes ; récurrence : visites suivies en continu
			// seulement (nouveau ou fidèle est inconnu pour une page anonyme).
			$rows = self::report_get_results(
				$wpdb->prepare(
					"SELECT country_code AS code, COUNT(*) AS sessions,
						SUM(CASE WHEN {$a} = 0 THEN 1 ELSE 0 END) AS measured,
						SUM(CASE WHEN {$a} = 0 AND is_new = 0 THEN 1 ELSE 0 END) AS `returning`
					FROM {$s_tbl} s
					WHERE s.first_seen BETWEEN %s AND %s {$rel} AND country_code IS NOT NULL AND country_code <> ''
					GROUP BY country_code HAVING sessions >= 3
					ORDER BY sessions DESC LIMIT 40",
					$start . ' 00:00:00', $end . ' 23:59:59'
				),
				ARRAY_A
			);

			$result = array();
			foreach ( (array) $rows as $r ) {
				if ( isset( $r['code'] ) && isset( $r['sessions'] ) && isset( $r['returning'] ) ) {
					$sess = (int) $r['sessions'];
					$meas = (int) $r['measured'];
					$ret  = (int) $r['returning'];
					$result[] = array(
						'code' => $r['code'],
						'sessions' => $sess,
						'measured' => $meas,
						'returning' => $ret,
						'rate' => self::rate( $ret, $meas, 1 ),
					);
				}
			}

			// Trim par taux de récurrence (les pays les plus FIDÈLES d'abord).
			usort( $result, function ( $x, $y ) {
				$rx = null === $x['rate'] ? -1 : $x['rate'];
				$ry = null === $y['rate'] ? -1 : $y['rate'];
				if ( $rx == $ry ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
					return $y['sessions'] - $x['sessions'];
				}
				return ( $ry < $rx ) ? -1 : 1;
			} );
			$result = array_slice( $result, 0, 20 );
		} catch ( Throwable $e ) {
			CU_Logger::log(
				'analytics',
				'error',
				sprintf( __( 'Échec du rapport Analytics « handle_recurring_countries » : %s', 'cyberpush-ultra' ), $e->getMessage() ),
				array( 'line' => (int) $e->getLine() )
			);
			
			return new WP_Error(
				'cu_analytics_report_failed',
				__( 'Le rapport Analytics n’a pas pu être calculé. Consultez les journaux puis réessayez.', 'cyberpush-ultra' ),
				array( 'status' => 500 )
			);
		}

		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/paths — Top parcours de navigation (page A → B → C)
	// -------------------------------------------------------------------------

	public function handle_paths( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range     = self::parse_range( $request );
		$cache_key = self::get_transient_key( 'paths', $range, 'reliable' );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$s_tbl = CU_DB::table( 'sessions' );
		$e_tbl = CU_DB::table( 'events' );
		// Parcours : uniquement les visites suivies en continu (des pages anonymes
		// ne peuvent pas être reliées entre elles).
		$rel   = self::segment_where( 'reliable', 'measured' );

		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT recent.sid, recent.url
				FROM (
					SELECT e.id, e.session_id AS sid, e.url AS url
					FROM {$e_tbl} e INNER JOIN {$s_tbl} s ON s.id = e.session_id
					WHERE e.event_type = 'pageview' AND e.created_at BETWEEN %s AND %s {$rel}
					ORDER BY e.id DESC LIMIT 8000
				) recent
				ORDER BY recent.sid ASC, recent.id ASC",
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);

		$paths   = array();
		$cur_sid = null;
		$seq     = array();
		foreach ( (array) $rows as $r ) {
			if ( $r['sid'] !== $cur_sid ) {
				if ( count( $seq ) >= 2 ) {
					$p = implode( ' → ', array_slice( $seq, 0, 4 ) );
					$paths[ $p ] = ( isset( $paths[ $p ] ) ? $paths[ $p ] : 0 ) + 1;
				}
				$seq     = array();
				$cur_sid = $r['sid'];
			}
			$path = preg_replace( '#^https?://[^/]+#', '', (string) $r['url'] );
			// Sans query string ni ancre : « /page/?utm_source=x » et « /page/ »
			// sont la même étape du parcours.
			$path = (string) preg_replace( '/[?#].*$/', '', $path );
			if ( '' === $path ) { $path = '/'; }
			$path = CU_Text::substr( $path, 0, 60 );
			if ( empty( $seq ) || end( $seq ) !== $path ) { $seq[] = $path; }
		}
		if ( count( $seq ) >= 2 ) {
			$p = implode( ' → ', array_slice( $seq, 0, 4 ) );
			$paths[ $p ] = ( isset( $paths[ $p ] ) ? $paths[ $p ] : 0 ) + 1;
		}
		arsort( $paths );

		$result = array();
		foreach ( $paths as $p => $n ) {
			$result[] = array( 'path' => $p, 'count' => (int) $n );
			if ( count( $result ) >= 15 ) { break; }
		}

		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/js-errors — Erreurs JavaScript côté visiteur
	// -------------------------------------------------------------------------

	public function handle_js_errors( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist() ) return self::migration_error();

		$range   = self::parse_range( $request );
		$segment = self::parse_segment( $request );
		$cache_key = self::get_transient_key( 'jserrors', $range, $segment );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$e_tbl = CU_DB::table( 'events' );
		$s_tbl = CU_DB::table( 'sessions' );
		$seg_w = self::segment_where( $segment, 'pages' );

		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT e.url, e.meta, e.created_at FROM {$e_tbl} e
				INNER JOIN {$s_tbl} s ON s.id = e.session_id
				WHERE e.event_type = 'js_error' AND e.created_at BETWEEN %s AND %s {$seg_w}
				ORDER BY e.created_at DESC LIMIT 300",
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);

		$agg = array();
		foreach ( (array) $rows as $r ) {
			$m   = $r['meta'] ? json_decode( $r['meta'], true ) : array();
			// Messages et sources d'avant 1.2.0 re-minimisés à la lecture (paramètres d'URL, e-mails).
			$msg = ( is_array( $m ) && ! empty( $m['message'] ) ) ? self::clean_error_text( (string) $m['message'] ) : '';
			$msg = '' !== $msg ? CU_Text::substr( $msg, 0, 180 ) : __( 'Erreur inconnue', 'cyberpush-ultra' );
			if ( ! isset( $agg[ $msg ] ) ) {
				$agg[ $msg ] = array( 'message' => $msg, 'count' => 0, 'url' => '', 'source' => '', 'last' => $r['created_at'] );
			}
			$agg[ $msg ]['count']++;
			if ( '' === $agg[ $msg ]['url'] ) {
				$agg[ $msg ]['url']    = $r['url'] ? self::display_path( $r['url'] ) : '';
				$agg[ $msg ]['source'] = ( is_array( $m ) && ! empty( $m['source'] ) ) ? CU_Text::substr( self::clean_url( (string) $m['source'], 300, true ), 0, 120 ) : '';
			}
		}
		$result = array_values( $agg );
		usort( $result, fn( $a, $b ) => $b['count'] - $a['count'] );
		$result = array_slice( $result, 0, 20 );

		self::set_cached_report( $cache_key, $result );
		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/categories
	// -------------------------------------------------------------------------

	public function handle_categories( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist( array( 'sessions', 'events', 'content_daily' ) ) ) return self::migration_error();
		self::maybe_rollup();

		// `content_daily` ne contient que le trafic fiable : le segment ne s'applique pas.
		return rest_ensure_response( self::cached_taxonomy_report( $request, 'category', 10 ) );
	}

	/** Rapport par terme mis en cache comme les autres rapports. */
	private static function cached_taxonomy_report( WP_REST_Request $request, $taxonomy, $limit ) {
		$range     = self::parse_range( $request );
		$cache_key = self::get_transient_key( 'tax_' . $taxonomy, $range, 'reliable' );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		list( $start, $end ) = self::period_dates( $range );
		$data = self::taxonomy_report( $taxonomy, $start, $end, $limit );
		self::set_cached_report( $cache_key, $data );
		return $data;
	}

	/**
	 * Agrège le trafic par terme directement en SQL, sur TOUS les articles de la
	 * période (et non sur un échantillon des plus vus, qui sous-estimait les totaux).
	 */
	private static function taxonomy_report( $taxonomy, $start, $end, $limit ) {
		global $wpdb;
		$cd_tbl = CU_DB::table( 'content_daily' );
		$rows   = self::report_get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, SUM(cd.sessions) AS sessions, SUM(cd.pageviews) AS pageviews
				FROM {$cd_tbl} cd
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = cd.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE cd.stat_date BETWEEN %s AND %s AND cd.object_type = 'post'
				GROUP BY t.term_id, t.name
				ORDER BY pageviews DESC, sessions DESC LIMIT %d",
				$taxonomy, $start, $end, $limit
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $rows as $row ) {
			$result[] = array(
				// Les noms de termes sont stockés échappés (« &amp; ») : texte réel pour le JSON.
				'name'      => self::decode_text( $row['name'] ),
				'sessions'  => (int) $row['sessions'],
				'pageviews' => (int) $row['pageviews'],
			);
		}
		return $result;
	}

	// -------------------------------------------------------------------------
	// GET /analytics/tags
	// -------------------------------------------------------------------------

	public function handle_tags( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist( array( 'sessions', 'events', 'content_daily' ) ) ) return self::migration_error();
		self::maybe_rollup();

		return rest_ensure_response( self::cached_taxonomy_report( $request, 'post_tag', 15 ) );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/authors
	// -------------------------------------------------------------------------

	public function handle_authors( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist( array( 'sessions', 'events', 'content_daily' ) ) ) return self::migration_error();
		self::maybe_rollup();

		$range     = self::parse_range( $request );
		$cache_key = self::get_transient_key( 'authors', $range, 'reliable' );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$cd_tbl = CU_DB::table( 'content_daily' );

		// Jointure SQL sur tous les contenus de la période (l'ancien échantillon des
		// 100 plus vus sous-estimait les auteurs prolifiques). Le type doit concorder :
		// un même identifiant ne désigne pas forcément le même objet selon le type.
		// Comparaison binaire : les tables WordPress et celles du plugin peuvent avoir
		// des collations différentes (« Illegal mix of collations » sinon).
		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT p.post_author AS author_id, SUM(cd.sessions) AS sessions, SUM(cd.pageviews) AS pageviews,
					SUM(cd.cta_clicks) AS cta, COUNT(DISTINCT cd.object_id) AS posts
				FROM {$cd_tbl} cd
				INNER JOIN {$wpdb->posts} p ON p.ID = cd.object_id AND CAST(p.post_type AS BINARY) = CAST(cd.object_type AS BINARY)
				WHERE cd.stat_date BETWEEN %s AND %s
				GROUP BY p.post_author
				ORDER BY pageviews DESC, sessions DESC LIMIT 10",
				$start, $end
			),
			ARRAY_A
		);

		$author_stats = array();
		foreach ( $rows as $row ) {
			$name = get_the_author_meta( 'display_name', (int) $row['author_id'] );
			$author_stats[] = array(
				'author'    => '' !== (string) $name ? $name : __( 'Auteur supprimé', 'cyberpush-ultra' ),
				'sessions'  => (int) $row['sessions'],
				'pageviews' => (int) $row['pageviews'],
				'cta'       => (int) $row['cta'],
				'posts'     => (int) $row['posts'],
			);
		}
		self::set_cached_report( $cache_key, $author_stats );
		return rest_ensure_response( $author_stats );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/woocommerce
	// -------------------------------------------------------------------------

	public function handle_woocommerce( WP_REST_Request $request ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return rest_ensure_response( array( 'available' => false ) );
		}
		if ( ! self::report_tables_exist( array( 'sessions', 'events', 'content_daily' ) ) ) return self::migration_error();
		self::maybe_rollup();

		$range = self::parse_range( $request );
		$cache_key = self::get_transient_key( 'woocommerce', $range, 'reliable' );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$cd_tbl = CU_DB::table( 'content_daily' );

		// Product views
		$prod_rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT cd.object_id, SUM(cd.sessions) AS views, SUM(cd.cta_clicks) AS cart_clicks, SUM(cd.conversions) AS purchases
				FROM {$cd_tbl} cd
				WHERE cd.stat_date BETWEEN %s AND %s AND cd.object_type = 'product'
				GROUP BY cd.object_id ORDER BY views DESC LIMIT 20",
				$start, $end
			),
			ARRAY_A
		);
		$prod_totals = self::report_get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(cd.sessions), 0) AS views,
					COALESCE(SUM(cd.cta_clicks), 0) AS cart_clicks
				FROM {$cd_tbl} cd
				WHERE cd.stat_date BETWEEN %s AND %s AND cd.object_type = 'product'",
				$start, $end
			),
			ARRAY_A
		);

		$total_views     = (int) ( $prod_totals['views'] ?? 0 );
		$total_purchases = 0;
		$total_cart      = (int) ( $prod_totals['cart_clicks'] ?? 0 );
		$products        = array();
		$product_indexes = array();
		$product_orders  = array();

		foreach ( (array) $prod_rows as $r ) {
			$post = get_post( (int) $r['object_id'] );
			$product_indexes[ (int) $r['object_id'] ] = count( $products );
			$product_orders[ (int) $r['object_id'] ]  = 0;
			$products[] = array(
				'id'        => (int) $r['object_id'],
				'name'      => self::display_title( $post, '#' . $r['object_id'] ),
				'views'     => (int) $r['views'],
				'purchases' => 0,
				'cart'      => (int) $r['cart_clicks'],
				'conv_rate' => 0,
			);
		}

		// API CRUD WooCommerce : compatible avec le stockage classique et HPOS.
		$revenue     = 0;
		$order_count = 0;
		$page         = 1;
		// Une grosse boutique (des milliers de commandes sur 90 jours) chargerait
		// chaque WC_Order dans une seule requête REST : dépassement de
		// max_execution_time → erreur fatale. Au-delà du budget, on s'arrête et
		// le résultat est signalé comme partiel plutôt que de tout perdre.
		$max_exec  = (int) ini_get( 'max_execution_time' );
		$budget    = $max_exec > 0 ? max( 5, min( 20, (int) floor( $max_exec / 2 ) ) ) : 20;
		$deadline  = microtime( true ) + $budget;
		$partial   = false;
		do {
			$order_page = wc_get_orders( array(
				'status'      => array( 'completed', 'processing' ),
				'date_created'=> $start . ' 00:00:00...' . $end . ' 23:59:59',
				'limit'       => 100,
				'paged'       => $page,
				'paginate'    => true,
			) );
			$orders    = is_object( $order_page ) && isset( $order_page->orders ) ? (array) $order_page->orders : array();
			$max_pages = is_object( $order_page ) && isset( $order_page->max_num_pages ) ? max( 1, (int) $order_page->max_num_pages ) : 1;

			foreach ( $orders as $order ) {
				if ( ! is_a( $order, 'WC_Order' ) ) continue;
				// Chiffre d'affaires NET : un remboursement (partiel ou total) ne
				// doit pas rester compté comme une vente.
				$revenue += max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
				$order_count++;
				$seen_products = array();
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					// Une extension peut filtrer les lignes : n'utiliser que les vrais produits.
					if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) continue;
					$product_id = (int) $item->get_product_id();
					$quantity   = max( 1, (int) $item->get_quantity() );
					$total_purchases += $quantity;
					if ( isset( $product_indexes[ $product_id ] ) ) {
						$products[ $product_indexes[ $product_id ] ]['purchases'] += $quantity;
						$seen_products[ $product_id ] = true;
					}
				}
				foreach ( array_keys( $seen_products ) as $product_id ) {
					$product_orders[ $product_id ]++;
				}
			}
			$page++;
			if ( $page <= $max_pages && microtime( true ) > $deadline ) {
				$partial = true;
				break;
			}
		} while ( $page <= $max_pages );

		foreach ( $products as &$product_row ) {
			$product_row['conv_rate'] = $product_row['views'] ? round( $product_orders[ $product_row['id'] ] / $product_row['views'] * 100, 2 ) : 0;
		}
		unset( $product_row );

		$avg_order = $order_count ? round( $revenue / $order_count, 2 ) : 0;
		$conv_rate = $total_views ? round( $order_count / $total_views * 100, 2 ) : 0;

		$data = array(
			'available'      => true,
			'product_views'  => $total_views,
			'cart_adds'      => $total_cart,
			'purchases'      => $total_purchases,
			'conv_rate'      => $conv_rate,
			'revenue'        => $revenue,
			'avg_order'      => $avg_order,
			'orders'         => $order_count,
			'currency_symbol'=> html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'top_products'   => $products,
			'segment'        => 'reliable',
			// Commandes les plus récentes d'abord (ordre par défaut de wc_get_orders) :
			// un résultat partiel couvre donc la fin de la période.
			'partial'        => $partial,
		);
		// Un résultat partiel reste en cache toute la durée réglée (au lieu de
		// 5 min) : le recalculer refait le même travail coûteux pour le même plafond.
		self::set_cached_report( $cache_key, $data, $partial ? 30 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/insights
	// -------------------------------------------------------------------------

	public function handle_insights( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist( array( 'sessions', 'events', 'content_daily' ) ) ) return self::migration_error();
		self::maybe_rollup();

		$range = self::parse_range( $request );
		$cache_key = self::get_transient_key( 'insights', $range, 'reliable' );
		$cached    = self::get_cached_report( $request, $cache_key );
		if ( false !== $cached ) return rest_ensure_response( $cached );

		global $wpdb;
		list( $start, $end, $prev_start, $prev_end ) = self::period_dates( $range );
		$cd_tbl = CU_DB::table( 'content_daily' );
		$s_tbl  = CU_DB::table( 'sessions' );

		$insights = array();

		try {
			// 1. Articles gagnant en visibilité (comparaison période précédente)
			$cur_rows = self::report_get_results(
				$wpdb->prepare(
					"SELECT object_id, SUM(sessions) AS s FROM {$cd_tbl} WHERE stat_date BETWEEN %s AND %s GROUP BY object_id",
					$start, $end
				), ARRAY_A
			);
			$prev_rows = self::report_get_results(
				$wpdb->prepare(
					"SELECT object_id, SUM(sessions) AS s FROM {$cd_tbl} WHERE stat_date BETWEEN %s AND %s GROUP BY object_id",
					$prev_start, $prev_end
				), ARRAY_A
			);
			$prev_map = array();
			foreach ( (array) $prev_rows as $r ) {
				if ( isset( $r['object_id'] ) && isset( $r['s'] ) ) {
					$prev_map[ $r['object_id'] ] = (int) $r['s'];
				}
			}

			$rising = array();
			foreach ( (array) $cur_rows as $r ) {
				if ( isset( $r['object_id'] ) && isset( $r['s'] ) ) {
					$prev_s = $prev_map[ $r['object_id'] ] ?? 0;
					// Au moins 5 visites avant : « 1 → 2 » n'est pas une tendance (+100 %).
					if ( $prev_s >= 5 && $r['s'] > $prev_s * 1.5 ) {
						$post = get_post( (int) $r['object_id'] );
						if ( $post ) {
							$rising[] = array(
								'title' => self::display_title( $post, '#' . $r['object_id'] ),
								'delta' => self::delta( (int) $r['s'], $prev_s ),
							);
						}
					}
				}
			}
			// <=> : les deltas sont des décimaux, une soustraction serait tronquée en entier.
			usort( $rising, fn( $a, $b ) => $b['delta'] <=> $a['delta'] );
			if ( ! empty( $rising ) ) {
				$insights[] = array(
					'type' => 'rising',
					'icon' => 'trending-up',
					// Tous les types suivis (articles, pages, produits…), pas seulement les articles.
					'title' => __( 'Contenus en progression', 'cyberpush-ultra' ),
					'items' => array_slice( $rising, 0, 3 ),
				);
			}

			// 2. Pages à fort rebond
			// Rebond : sur les visites suivies en continu (base mesurée).
			$measured_col = self::anon_ready() ? 'measured_sessions' : 'sessions';
			$high_bounce = self::report_get_results(
				$wpdb->prepare(
					"SELECT object_id, SUM({$measured_col}) AS total, SUM(bounces) AS bounces FROM {$cd_tbl}
					WHERE stat_date BETWEEN %s AND %s
					GROUP BY object_id HAVING SUM({$measured_col}) > 10 AND (SUM(bounces) / SUM({$measured_col})) > 0.7
					ORDER BY (SUM(bounces) / SUM({$measured_col})) DESC LIMIT 5",
					$start, $end
				), ARRAY_A
			);
			if ( ! empty( $high_bounce ) ) {
				$items = array();
				foreach ( $high_bounce as $r ) {
					if ( isset( $r['object_id'] ) ) {
						$post = get_post( (int) $r['object_id'] );
						if ( $post ) {
							$items[] = array(
								'title'       => self::display_title( $post, '#' . $r['object_id'] ),
								'bounce_rate' => isset( $r['total'], $r['bounces'] ) && $r['total'] > 0 ? round( $r['bounces'] / $r['total'] * 100, 1 ) : 0,
							);
						}
					}
				}
				if ( ! empty( $items ) ) {
					$insights[] = array(
						'type'  => 'high_bounce',
						'icon'  => 'flag',
						'title' => __( 'Pages à fort rebond à optimiser', 'cyberpush-ultra' ),
						'items' => $items,
					);
				}
			}

			// 3. Meilleure heure de publication (heure avec le plus de sessions)
			$reliable_sessions = self::segment_where( 'reliable', 'visits' );
			$best_hour = self::report_get_results(
				$wpdb->prepare(
					"SELECT HOUR(s.first_seen) AS h, COUNT(*) AS n FROM {$s_tbl} s
					WHERE s.first_seen BETWEEN %s AND %s {$reliable_sessions}
					GROUP BY h ORDER BY n DESC LIMIT 3",
					$start . ' 00:00:00', $end . ' 23:59:59'
				), ARRAY_A
			);
			if ( ! empty( $best_hour ) ) {
				$hours = array();
				foreach ( $best_hour as $r ) {
					if ( isset( $r['h'] ) && isset( $r['n'] ) ) {
						$hours[] = array(
							'hour' => (int) $r['h'] . 'h',
							'sessions' => (int) $r['n'],
						);
					}
				}
				if ( ! empty( $hours ) ) {
					$insights[] = array(
						'type'  => 'best_hours',
						'icon'  => 'clock',
						'title' => __( 'Meilleures heures de trafic', 'cyberpush-ultra' ),
						'items' => $hours,
					);
				}
			}

			// 4. Meilleures sources de trafic
			$top_sources = self::report_get_results(
				$wpdb->prepare(
					"SELECT referrer_source AS source, COUNT(*) AS n FROM {$s_tbl} s
					WHERE s.first_seen BETWEEN %s AND %s {$reliable_sessions}
					GROUP BY referrer_source ORDER BY n DESC LIMIT 3",
					$start . ' 00:00:00', $end . ' 23:59:59'
				), ARRAY_A
			);
			if ( ! empty( $top_sources ) ) {
				$source_labels = array(
					'direct' => __( 'Direct', 'cyberpush-ultra' ),
					'google' => __( 'Moteurs de recherche', 'cyberpush-ultra' ),
					'social' => __( 'Réseaux sociaux', 'cyberpush-ultra' ),
					'referral' => __( 'Référents', 'cyberpush-ultra' ),
					'campaign' => __( 'Campagnes', 'cyberpush-ultra' ),
					'email' => __( 'Email', 'cyberpush-ultra' ),
					'ia' => __( 'IA', 'cyberpush-ultra' ),
				);
				$items = array();
				foreach ( $top_sources as $r ) {
					$source = $r['source'] ? $r['source'] : 'direct';
					if ( isset( $source_labels[ $source ] ) ) {
						$items[] = array(
							'source'   => $source,
							'label'    => $source_labels[ $source ],
							'sessions' => (int) $r['n'],
						);
					} else {
						$items[] = array(
							'source'   => $source,
							'label'    => $source,
							'sessions' => (int) $r['n'],
						);
					}
				}
				if ( ! empty( $items ) ) {
					$insights[] = array(
						'type'  => 'top_sources',
						'icon'  => 'globe',
						'title' => __( 'Meilleures sources de trafic', 'cyberpush-ultra' ),
						'items' => $items,
					);
				}
			}

			// 5. Produits WooCommerce avec événements de conversion suivis (si dispo)
			if ( class_exists( 'WooCommerce' ) ) {
				$top_conv = self::report_get_results(
					$wpdb->prepare(
						"SELECT object_id, SUM(sessions) AS views, SUM(conversions) AS conv FROM {$cd_tbl}
						WHERE stat_date BETWEEN %s AND %s AND object_type = 'product'
						GROUP BY object_id HAVING SUM(sessions) > 5
						ORDER BY (SUM(conversions)/SUM(sessions)) DESC LIMIT 3",
						$start, $end
					), ARRAY_A
				);
				if ( ! empty( $top_conv ) ) {
					$items = array();
					foreach ( $top_conv as $r ) {
						if ( isset( $r['object_id'] ) ) {
							$post = get_post( (int) $r['object_id'] );
							if ( $post ) {
								$items[] = array(
									'title'     => self::display_title( $post, '#' . $r['object_id'] ),
									'conv_rate' => isset( $r['views'] ) && isset( $r['conv'] ) && $r['views'] > 0 ? round( $r['conv'] / $r['views'] * 100, 2 ) : 0,
								);
							}
						}
					}
					if ( ! empty( $items ) ) {
						$insights[] = array(
							'type'  => 'top_products',
							'icon'  => 'cart',
							'title' => __( 'Produits avec le plus de conversions déclarées', 'cyberpush-ultra' ),
							'items' => $items,
						);
					}
				}
			}
		} catch ( Throwable $e ) {
			// Log the specific error for debugging
			CU_Logger::log(
				'analytics',
				'error',
				sprintf( __( 'Échec du rapport Analytics « handle_insights » : %s', 'cyberpush-ultra' ), $e->getMessage() ),
				array( 'line' => (int) $e->getLine() )
			);
			
			// Return a graceful error response instead of letting it bubble up to cause a 500
			return new WP_Error(
				'cu_analytics_report_failed',
				__( 'Le rapport Analytics n’a pas pu être calculé. Consultez les journaux puis réessayez.', 'cyberpush-ultra' ),
				array( 'status' => 500 )
			);
		}

		self::set_cached_report( $cache_key, $insights );
		return rest_ensure_response( $insights );
	}

	// -------------------------------------------------------------------------
	// GET /analytics/export
	// -------------------------------------------------------------------------

	public function handle_export( WP_REST_Request $request ) {
		if ( ! self::report_tables_exist( array( 'sessions', 'events', 'content_daily' ) ) ) return self::migration_error();

		$range  = self::parse_range( $request );
		$format = sanitize_key( $request->get_param( 'format' ) ?: 'csv' );
		if ( 'csv' !== $format ) {
			return new WP_Error( 'cu_invalid_export_format', __( 'Format d’export non pris en charge.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}

		// Re-use content-performance data (all pages, no pagination)
		global $wpdb;
		list( $start, $end ) = self::period_dates( $range );
		$cd_tbl = CU_DB::table( 'content_daily' );
		$e_tbl  = CU_DB::table( 'events' );
		$s_tbl  = CU_DB::table( 'sessions' );

		$measured_col = self::anon_ready() ? 'SUM(cd.measured_sessions)' : 'SUM(cd.sessions)';
		$rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT cd.object_id, cd.object_type,
					SUM(cd.sessions) AS sessions, SUM(cd.pageviews) AS pageviews,
					{$measured_col} AS measured_sessions,
					SUM(cd.entries) AS entries, SUM(cd.exits) AS exits,
					SUM(cd.bounces) AS bounces, SUM(cd.cta_clicks) AS cta_clicks,
					SUM(cd.conversions) AS conversions,
					CASE WHEN SUM(cd.sessions) > 0
						THEN SUM(cd.avg_scroll * cd.sessions) / SUM(cd.sessions) ELSE 0 END AS avg_scroll,
					SUM(cd.total_duration) AS total_duration
				FROM {$cd_tbl} cd
				WHERE cd.stat_date BETWEEN %s AND %s
				GROUP BY cd.object_id, cd.object_type
				ORDER BY sessions DESC",
				$start, $end
			),
			ARRAY_A
		);
		$cta_session_rows = self::report_get_results(
			$wpdb->prepare(
				"SELECT e.object_id, e.object_type, COUNT(DISTINCT e.session_id) AS cta_sessions
				FROM {$e_tbl} e INNER JOIN {$s_tbl} s ON s.id = e.session_id
				WHERE e.event_type = 'cta_click' AND e.created_at BETWEEN %s AND %s " . self::segment_where( 'reliable', 'measured' ) . "
				GROUP BY e.object_id, e.object_type",
				$start . ' 00:00:00', $end . ' 23:59:59'
			),
			ARRAY_A
		);
		$cta_session_map = array();
		foreach ( $cta_session_rows as $cta_session_row ) {
			$cta_session_map[ $cta_session_row['object_type'] . '|' . $cta_session_row['object_id'] ] = (int) $cta_session_row['cta_sessions'];
		}
		// Contacts par contenu (clics WhatsApp, messages Contact Form 7 réussis).
		$contact_map = array();
		if ( self::report_tables_exist( array( 'contacts' ) ) ) {
			$c_tbl = CU_DB::table( 'contacts' );
			foreach ( self::report_get_results(
				$wpdb->prepare(
					"SELECT c.object_id, c.object_type, SUM( c.channel = 'whatsapp' ) AS wa, SUM( c.channel = 'cf7' AND c.action = 'sent' ) AS cf7
					FROM {$c_tbl} c INNER JOIN {$s_tbl} s ON s.id = c.session_id
					WHERE c.object_id IS NOT NULL AND c.created_at BETWEEN %s AND %s " . self::segment_where( 'reliable', 'pages' ) . "
					GROUP BY c.object_id, c.object_type",
					$start . ' 00:00:00', $end . ' 23:59:59'
				),
				ARRAY_A
			) as $contact_row ) {
				$contact_map[ $contact_row['object_type'] . '|' . $contact_row['object_id'] ] = $contact_row;
			}
		}

		$headers_csv = array(
			__( 'Titre', 'cyberpush-ultra' ),
			__( 'Type', 'cyberpush-ultra' ),
			__( 'Auteur', 'cyberpush-ultra' ),
			__( 'Catégorie', 'cyberpush-ultra' ),
			__( 'Visites (dont pages anonymes)', 'cyberpush-ultra' ),
			__( 'Visites suivies en continu', 'cyberpush-ultra' ),
			__( 'Pages vues', 'cyberpush-ultra' ),
			__( 'Temps moyen sur le contenu (s)', 'cyberpush-ultra' ),
			__( 'Rebond (%, visites suivies)', 'cyberpush-ultra' ),
			__( 'Entrées', 'cyberpush-ultra' ),
			__( 'Sorties', 'cyberpush-ultra' ),
			__( 'Scroll moy. (%)', 'cyberpush-ultra' ),
			__( 'Clics CTA', 'cyberpush-ultra' ),
			__( 'CTR (%, visites suivies)', 'cyberpush-ultra' ),
			__( 'Conversions déclarées', 'cyberpush-ultra' ),
			__( 'Clics WhatsApp', 'cyberpush-ultra' ),
			__( 'Messages Contact Form 7', 'cyberpush-ultra' ),
		);

		$lines = array( implode( ';', array_map( array( __CLASS__, 'csv_cell' ), $headers_csv ) ) );
		self::prime_content_rows( $rows );
		foreach ( (array) $rows as $row ) {
			$post     = $row['object_id'] ? get_post( (int) $row['object_id'] ) : null;
			$title    = self::display_title( $post, '#' . $row['object_id'] );
			$author   = $post ? get_the_author_meta( 'display_name', $post->post_author ) : '';
			$category = self::category_names( $post );
			$sessions = (int) $row['sessions'];
			$measured = (int) $row['measured_sessions'];
			$avg_time = $sessions ? round( $row['total_duration'] / $sessions ) : 0;
			// Sans visite suivie : cellule « n/d », jamais un faux 0 %.
			$bounce   = self::rate( (int) $row['bounces'], $measured, 1 );
			$cta_sessions = min( $measured, $cta_session_map[ $row['object_type'] . '|' . $row['object_id'] ] ?? 0 );
			$ctr      = self::rate( $cta_sessions, $measured, 2 );
			$contacts = $contact_map[ $row['object_type'] . '|' . $row['object_id'] ] ?? array();

			$lines[] = implode( ';', array(
				self::csv_cell( $title ),
				self::csv_cell( self::content_type_label( $row['object_type'] ) ),
				self::csv_cell( $author ),
				self::csv_cell( $category ),
				$sessions,
				$measured,
				(int) $row['pageviews'],
				$avg_time,
				null === $bounce ? 'n/d' : self::csv_decimal( $bounce ),
				(int) $row['entries'],
				(int) $row['exits'],
				round( (float) $row['avg_scroll'] ),
				(int) $row['cta_clicks'],
				null === $ctr ? 'n/d' : self::csv_decimal( $ctr ),
				(int) $row['conversions'],
				(int) ( $contacts['wa'] ?? 0 ),
				(int) ( $contacts['cf7'] ?? 0 ),
			) );
		}

		$csv = "\xEF\xBB\xBF" . implode( "\n", $lines ); // UTF-8 BOM for Excel

		// On streame directement le CSV puis on arrête l'exécution : renvoyer la
		// chaîne via WP_REST_Response la ferait ré-encoder en JSON (guillemets +
		// \n littéraux) et corromprait le fichier téléchargé.
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="analytics-export-' . $range . 'j.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}

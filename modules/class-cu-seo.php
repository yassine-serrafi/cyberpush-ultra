<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module SEO — CyberPush Ultra
 * -----------------------------------------------------------------------------
 * Auditeur SEO on-page + technique, façon Semrush / Rank Math, en mode
 * AUDIT UNIQUEMENT (aucune balise émise sur le front → zéro conflit avec
 * Yoast/Rank Math s'ils sont présents).
 *
 * Principe clé : on analyse le RENDU FINAL de chaque page — récupéré via une
 * requête loopback qui exécute réellement le serveur (Elementor, Divi, Bricks,
 * shortcodes tous rendus), pas le post_content brut. Voir CU_SEO_Renderer.
 *
 * Le crawl fonctionne par lots reprenables (compatible cPanel mutualisé,
 * sans serveur permanent) : le JS admin enchaîne les lots avec progression.
 */
class CU_SEO {

	private static $instance = null;

	/** Verrou partagé (cron + REST) : une seule analyse SEO à la fois. */
	const LOCK_OPTION = 'cu_seo_run_lock';
	/** Durée de vie max d'un verrou (un worker mort ne bloque pas plus longtemps). */
	const LOCK_TTL = 300;
	/** État de la découverte d'URL (dernier passage complet, signature des réglages). */
	const DISCOVERY_OPTION = 'cu_seo_discovery';
	/** Dernier blocage Serper (clé refusée / quota) : stoppe l'auto-vérification. */
	const SERPER_STATE_OPTION = 'cu_seo_serper_state';
	/** Longueur max d'une URL stockable (colonnes url VARCHAR(500)). */
	const MAX_URL_LENGTH = 500;
	/** Budget temps d'une requête REST de traitement par lot (proxys ~60 s). */
	const BATCH_BUDGET = 45;
	/** Résultats PageSpeed réutilisés tant que la page n'a pas changé. */
	const PSI_CACHE_DAYS = 7;

	/** Jeton du verrou détenu par CETTE requête (verrou réentrant). */
	private $lock_token = null;

	/** Bilan de la dernière découverte (renvoyé à l'interface). */
	private $last_discovery = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Une page publiée/mise à jour est marquée pour ré-analyse.
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'trashed_post', array( $this, 'on_trashed_post' ) );
		add_action( 'deleted_post', array( $this, 'on_trashed_post' ) );
		// Terme supprimé : son archive sort de l'inventaire (sinon fausse 404).
		add_action( 'pre_delete_term', array( $this, 'on_pre_delete_term' ), 10, 2 );
	}

	public function permission_admin() {
		return current_user_can( 'manage_options' );
	}

	private function enabled() {
		return (bool) CU_Settings::get( 'seo', 'enabled', true );
	}

	// =====================================================================
	// Routes REST
	// =====================================================================

	public function register_routes() {
		$ns = CU_REST_NAMESPACE;

		$get  = function ( $cb ) { return array( 'methods' => 'GET', 'callback' => array( $this, $cb ), 'permission_callback' => array( $this, 'permission_admin' ) ); };
		$post = function ( $cb ) { return array( 'methods' => 'POST', 'callback' => array( $this, $cb ), 'permission_callback' => array( $this, 'permission_admin' ) ); };

		register_rest_route( $ns, '/seo/overview', $get( 'rest_overview' ) );
		register_rest_route( $ns, '/seo/reco-urls', $get( 'rest_reco_urls' ) );
		register_rest_route( $ns, '/seo/pages', $get( 'rest_pages' ) );
		register_rest_route( $ns, '/seo/page', $get( 'rest_page' ) );
		register_rest_route( $ns, '/seo/crawl', $post( 'rest_crawl' ) );
		register_rest_route( $ns, '/seo/audit-page', $post( 'rest_audit_page' ) );
		register_rest_route( $ns, '/seo/psi', $post( 'rest_psi' ) );
		register_rest_route( $ns, '/seo/meta', $post( 'rest_save_meta' ) );
		register_rest_route( $ns, '/seo/ai-meta', $post( 'rest_ai_meta' ) );
		register_rest_route( $ns, '/seo/ai-insight', $post( 'rest_ai_insight' ) );
		register_rest_route( $ns, '/seo/check-links', $post( 'rest_check_links' ) );
		register_rest_route( $ns, '/seo/index-overview', $get( 'rest_index_overview' ) );
		register_rest_route( $ns, '/seo/check-index', $post( 'rest_check_index' ) );
		register_rest_route( $ns, '/seo/site-audit', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'rest_get_site_audit' ), 'permission_callback' => array( $this, 'permission_admin' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'rest_run_site_audit' ), 'permission_callback' => array( $this, 'permission_admin' ) ),
		) );
		register_rest_route( $ns, '/seo/ai-summary', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'rest_get_ai_summary' ), 'permission_callback' => array( $this, 'permission_admin' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'rest_gen_ai_summary' ), 'permission_callback' => array( $this, 'permission_admin' ) ),
		) );
		register_rest_route( $ns, '/seo/purge', $post( 'rest_purge' ) );
		register_rest_route( $ns, '/seo/export', $get( 'rest_export' ) );
	}

	private function require_tables() {
		if ( ! CU_DB::tables_exist() ) {
			return new WP_Error( 'cu_migration_required', __( 'Migration SQL requise.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		return true;
	}

	// =====================================================================
	// Verrou inter-processus (cron + REST)
	// =====================================================================

	/**
	 * Acquiert le verrou SEO. add_option() s'appuie sur l'unicité de
	 * option_name : un seul processus peut le créer (atomique, contrairement à
	 * get_transient + set_transient). Un verrou plus vieux que LOCK_TTL (worker
	 * tué) est repris par compare-and-swap sur sa valeur exacte.
	 * Réentrant : un appel imbriqué dans la même requête réussit sans rien faire.
	 *
	 * @return string|false Jeton, ou false si une autre analyse est en cours.
	 */
	private function acquire_lock() {
		global $wpdb;
		if ( null !== $this->lock_token ) {
			return 'reentrant';
		}
		$now   = time();
		$token = $now . '|' . wp_generate_uuid4();
		if ( add_option( self::LOCK_OPTION, $token, '', false ) ) {
			$this->lock_token = $token;
			return $token;
		}
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$current = get_option( self::LOCK_OPTION, false );
		if ( false === $current ) {
			if ( add_option( self::LOCK_OPTION, $token, '', false ) ) {
				$this->lock_token = $token;
				return $token;
			}
			return false;
		}
		$current = is_scalar( $current ) ? (string) $current : '';
		$created = (int) strtok( $current, '|' );
		if ( $created > 0 && $created <= $now && ( $now - $created ) < self::LOCK_TTL ) {
			return false;
		}
		$replaced = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$token,
			self::LOCK_OPTION,
			$current
		) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		if ( 1 !== $replaced ) {
			return false;
		}
		$this->lock_token = $token;
		return $token;
	}

	/** Libère le verrou s'il appartient encore à cette requête. */
	private function release_lock( $token ) {
		global $wpdb;
		if ( 'reentrant' === $token || null === $this->lock_token || $token !== $this->lock_token ) {
			return;
		}
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			self::LOCK_OPTION,
			(string) $token
		) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$this->lock_token = null;
	}

	/**
	 * Exécute $callback sous verrou ; sinon réponse « occupé » (HTTP 200,
	 * busy = true) que l'interface sait attendre puis relancer.
	 */
	private function locked( $callback ) {
		$token = $this->acquire_lock();
		if ( false === $token ) {
			return rest_ensure_response( array(
				'success' => false,
				'busy'    => true,
				'message' => __( 'Une autre analyse SEO est en cours (tâche planifiée ou autre onglet). Nouvel essai dans quelques secondes…', 'cyberpush-ultra' ),
			) );
		}
		try {
			return call_user_func( $callback );
		} finally {
			$this->release_lock( $token );
		}
	}

	// =====================================================================
	// Découverte des URL
	// =====================================================================

	private function tracked_post_types() {
		if ( 'all_public' === CU_Settings::get( 'seo', 'crawl_scope', 'tracked' ) ) {
			return get_post_types( array( 'public' => true ), 'names' );
		}
		$tracked = (array) CU_Settings::get( 'wordpress', 'tracked_post_types', array( 'post', 'page' ) );
		return ! empty( $tracked ) ? $tracked : array( 'post', 'page' );
	}

	/**
	 * Taxonomies dont les archives (catégories, étiquettes, étiquettes produit…)
	 * doivent être crawlées, d'après la sélection de l'utilisateur. Une taxonomie
	 * décochée (ex. post_tag / product_tag) est simplement absente de la liste.
	 */
	private function enabled_taxonomies() {
		$map = (array) CU_Settings::get( 'seo', 'crawl_taxonomies', array() );
		$out = array();
		foreach ( $map as $tax => $on ) {
			if ( $on && taxonomy_exists( $tax ) ) {
				$out[] = $tax;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function is_excluded_url( $url ) {
		$patterns = (array) CU_Settings::get( 'seo', 'excluded_url_patterns', array() );
		$url      = (string) $url;
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query    = (string) wp_parse_url( $url, PHP_URL_QUERY );
		foreach ( $patterns as $pat ) {
			$pat = trim( (string) $pat );
			if ( '' === $pat ) {
				continue;
			}
			if ( '?' === $pat ) {
				// En permaliens simples, « ?p=123 » EST l'adresse canonique d'un
				// contenu : seule une query string superflue est alors exclue.
				if ( '' !== $query && '' !== (string) get_option( 'permalink_structure' ) ) {
					return true;
				}
				continue;
			}
			if ( '/' === $pat[0] ) {
				// Segment entier : « /feed » exclut /feed/ mais jamais /feedback/.
				$segment = untrailingslashit( $pat );
				if ( '' === $segment ) {
					continue; // « / » seul exclurait tout le site.
				}
				if ( preg_match( '#' . preg_quote( $segment, '#' ) . '(?:/|$)#i', $path ) ) {
					return true;
				}
				continue;
			}
			if ( false !== stripos( $url, $pat ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Signature des réglages qui déterminent l'ensemble des URL : si elle
	 * change (structure des permaliens, types suivis, exclusions…), la
	 * prochaine découverte est complète et nettoie l'inventaire.
	 */
	private function discovery_signature() {
		return md5( wp_json_encode( array(
			(string) get_option( 'permalink_structure' ),
			home_url( '/' ),
			array_values( (array) $this->tracked_post_types() ),
			(bool) CU_Settings::get( 'seo', 'crawl_home', true ),
			(bool) CU_Settings::get( 'seo', 'crawl_terms', false ),
			$this->enabled_taxonomies(),
			array_values( (array) CU_Settings::get( 'seo', 'excluded_url_patterns', array() ) ),
		) ) );
	}

	/**
	 * Alimente cu_seo_pages avec toutes les URL publiques connues, sans
	 * dupliquer (clé url_hash). Retourne le nombre de nouvelles URL ajoutées.
	 *
	 * - 'full' : parcours complet par tranches (cache des contenus et des
	 *   termes amorcé en 2 requêtes par tranche au lieu de ~3 par contenu),
	 *   puis RETRAIT des URL qui ne font plus partie du site suivi (contenu
	 *   dépublié hors hook, terme supprimé, type de contenu ou taxonomie
	 *   retirés du suivi, nouvelle exclusion) — fini les fausses 404.
	 * - 'incremental' : seulement les contenus modifiés depuis le dernier
	 *   passage (le hook save_post couvre déjà le cas courant).
	 * - 'auto' (cron) : complet au plus une fois par jour ou si les réglages
	 *   ont changé, incrémental sinon.
	 *
	 * @param string $mode full | incremental | auto
	 */
	public function discover_urls( $mode = 'full' ) {
		global $wpdb;
		$table   = CU_DB::table( 'seo_pages' );
		$posts   = $wpdb->posts;
		$now     = current_time( 'mysql' );
		$started = time();
		$added   = 0;

		$state     = get_option( self::DISCOVERY_OPTION, array() );
		$state     = is_array( $state ) ? $state : array();
		$signature = $this->discovery_signature();
		if ( 'auto' === $mode ) {
			$full = empty( $state['last_full'] ) || ( $started - (int) $state['last_full'] ) > DAY_IN_SECONDS
				|| ! isset( $state['signature'] ) || $state['signature'] !== $signature;
			$mode = $full ? 'full' : 'incremental';
		}
		$full = ( 'full' === $mode );

		// URL déjà connues (1 seule requête → pas de N+1).
		$known = $wpdb->get_col( "SELECT url_hash FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->last_error ) {
			CU_Logger::log( 'seo', 'error', __( 'Impossible de lire l’inventaire SEO existant.', 'cyberpush-ultra' ) );
			$this->last_discovery = array( 'mode' => $mode, 'added' => 0, 'removed' => 0, 'too_long' => 0 );
			return 0;
		}
		$known = array_flip( (array) $known );
		$seen  = array();

		$failed          = 0;
		$too_long        = 0;
		$too_long_sample = '';
		$add_url = function ( $url, $post_id, $object_type ) use ( &$known, &$seen, &$added, &$failed, &$too_long, &$too_long_sample, $table, $now ) {
			$url = esc_url_raw( (string) $url );
			if ( '' === $url || $this->is_excluded_url( $url ) ) {
				return;
			}
			// Colonne url VARCHAR(500) : une URL plus longue serait refusée (mode
			// SQL strict) ou tronquée (et ne correspondrait plus à son hash).
			if ( strlen( $url ) > self::MAX_URL_LENGTH ) {
				$too_long++;
				$too_long_sample = $url;
				return;
			}
			$hash          = md5( $url ); // calculé sur la valeur EXACTEMENT stockée
			$seen[ $hash ] = true;
			if ( isset( $known[ $hash ] ) ) {
				return;
			}
			global $wpdb;
			$inserted = $wpdb->insert( $table, array(
				'post_id'     => $post_id ? (int) $post_id : null,
				'url'         => $url,
				'url_hash'    => $hash,
				'object_type' => sanitize_key( $object_type ),
				'crawl_state' => 'pending',
				'created_at'  => $now,
			) );
			if ( false !== $inserted ) {
				$known[ $hash ] = true;
				$added++;
			} else {
				$failed++;
			}
		};

		if ( $full && CU_Settings::get( 'seo', 'crawl_home', true ) ) {
			$add_url( home_url( '/' ), 0, 'home' );
		}

		// Parcours par tranches sur l'ID (pas d'OFFSET qui ralentit en fin de
		// table). Même périmètre que l'ancien get_posts(fields=ids) : statut
		// publish, types suivis, filtres de requête non appliqués.
		$types = array_values( array_filter( array_map( 'strval', (array) $this->tracked_post_types() ) ) );
		$posts_complete = true;
		if ( ! empty( $types ) ) {
			$type_ph  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$since    = '';
			if ( ! $full ) {
				$last  = ! empty( $state['last_run'] ) ? (int) $state['last_run'] : $started - DAY_IN_SECONDS;
				$since = gmdate( 'Y-m-d H:i:s', $last - HOUR_IN_SECONDS ); // marge de sécurité
			}
			$last_id = 0;
			$chunk   = 200;
			do {
				$params = array_merge( $types, array( $last_id ) );
				$sql    = "SELECT ID FROM {$posts} WHERE post_status = 'publish' AND post_type IN ( {$type_ph} ) AND ID > %d";
				if ( '' !== $since ) {
					$sql     .= ' AND post_modified_gmt >= %s';
					$params[] = $since;
				}
				$sql     .= ' ORDER BY ID ASC LIMIT %d';
				$params[] = $chunk;
				$ids      = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( $wpdb->last_error ) {
					CU_Logger::log( 'seo', 'error', __( 'Impossible de lister les contenus pour l’inventaire SEO.', 'cyberpush-ultra' ), array( 'db_error' => CU_SEO_Renderer_truncate( $wpdb->last_error, 500 ) ) );
					$posts_complete = false;
					break;
				}
				$ids = array_map( 'intval', (array) $ids );
				if ( empty( $ids ) ) {
					break;
				}
				// 2 requêtes pour toute la tranche (contenus + termes, utiles aux
				// permaliens %category% / %product_cat%) au lieu de ~3 par contenu.
				if ( function_exists( '_prime_post_caches' ) ) {
					_prime_post_caches( $ids, true, false );
				}
				foreach ( $ids as $pid ) {
					$link = get_permalink( $pid );
					if ( $link ) {
						$add_url( $link, $pid, get_post_type( $pid ) );
					}
				}
				$this->release_post_cache( $ids );
				$last_id = (int) end( $ids );
			} while ( count( $ids ) === $chunk );
		}

		$terms_complete = true;
		if ( $full && CU_Settings::get( 'seo', 'crawl_terms', false ) ) {
			$taxes = $this->enabled_taxonomies();
			if ( ! empty( $taxes ) ) {
				$term_offset = 0;
				$term_batch  = 500;
				do {
					$terms = get_terms( array( 'taxonomy' => $taxes, 'hide_empty' => true, 'number' => $term_batch, 'offset' => $term_offset, 'orderby' => 'term_id', 'order' => 'ASC', 'update_term_meta_cache' => false ) );
					if ( is_wp_error( $terms ) ) {
						CU_Logger::log( 'seo', 'error', $terms->get_error_message() );
						$terms_complete = false;
						break;
					}
					foreach ( $terms as $term ) {
						$link = get_term_link( $term );
						if ( ! is_wp_error( $link ) ) {
							$add_url( $link, 0, $term->taxonomy );
						}
					}
					$term_offset += $term_batch;
				} while ( count( $terms ) === $term_batch );
			}
		}

		if ( $failed > 0 ) {
			CU_Logger::log( 'seo', 'error', sprintf( /* translators: %d: failed URL insertions */ __( '%d URL n’ont pas pu être ajoutées à l’inventaire SEO.', 'cyberpush-ultra' ), $failed ), array( 'db_error' => CU_SEO_Renderer_truncate( $wpdb->last_error, 500 ) ) );
		}
		// Journalisé une seule fois tant que le nombre ne change pas (le cron
		// repasse toutes les heures).
		if ( $too_long > 0 && ( ! isset( $state['too_long'] ) || (int) $state['too_long'] !== $too_long ) ) {
			CU_Logger::log( 'seo', 'warning', sprintf(
				/* translators: 1: count, 2: max length */
				__( '%1$d URL de plus de %2$d caractères ignorées par l’audit SEO (non stockables).', 'cyberpush-ultra' ),
				$too_long,
				self::MAX_URL_LENGTH
			), array( 'exemple' => CU_SEO_Renderer_truncate( $too_long_sample, 200 ) ) );
		}

		// Nettoyage : seulement après un passage COMPLET et réussi (sinon on
		// supprimerait des pages simplement non parcourues), jamais si rien
		// n'a été vu, et jamais une ligne créée pendant ce passage (save_post
		// concurrent).
		$removed = 0;
		if ( $full && $posts_complete && $terms_complete && 0 === $failed && ! empty( $seen ) ) {
			$removed = $this->prune_inventory( $seen, wp_date( 'Y-m-d H:i:s', $started, wp_timezone() ) );
		}

		$state['last_run']  = $started;
		$state['too_long']  = $too_long;
		if ( $full && $posts_complete && $terms_complete ) {
			$state['last_full'] = $started;
			$state['signature'] = $signature;
		}
		update_option( self::DISCOVERY_OPTION, $state, false );

		$this->last_discovery = array( 'mode' => $mode, 'added' => $added, 'removed' => $removed, 'too_long' => $too_long );
		return $added;
	}

	/**
	 * Libère la mémoire des contenus amorcés par tranche : sans cela, le cache
	 * objet non persistant gardait TOUS les contenus (post_content compris)
	 * jusqu'à la fin de la requête → épuisement mémoire sur les gros sites.
	 */
	private function release_post_cache( $ids ) {
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			// Cache persistant : ne PAS supprimer les entrées (ce serait les
			// invalider pour tout le site) ; vider seulement la copie locale.
			if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			return;
		}
		foreach ( (array) $ids as $id ) {
			wp_cache_delete( (int) $id, 'posts' );
		}
	}

	/**
	 * Retire de l'inventaire les URL absentes d'un passage complet de la
	 * découverte. Parcours par tranches d'ID (mémoire bornée).
	 *
	 * @param array<string,bool> $seen    Hash des URL vues pendant le passage.
	 * @param string             $started Date (heure du site) du début du passage.
	 * @return int Nombre de lignes supprimées.
	 */
	private function prune_inventory( $seen, $started ) {
		global $wpdb;
		$table   = CU_DB::table( 'seo_pages' );
		$last_id = 0;
		$stale   = array();
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, url_hash FROM {$table} WHERE id > %d AND created_at < %s ORDER BY id ASC LIMIT 2000",
				$last_id,
				$started
			), ARRAY_N );
			if ( $wpdb->last_error || ! is_array( $rows ) ) {
				return 0;
			}
			foreach ( $rows as $r ) {
				$last_id = (int) $r[0];
				if ( ! isset( $seen[ $r[1] ] ) ) {
					$stale[] = (int) $r[0];
				}
			}
		} while ( count( $rows ) === 2000 );

		if ( empty( $stale ) ) {
			return 0;
		}
		$this->forget_page_ids( $stale );
		CU_Logger::log( 'seo', 'info', sprintf(
			/* translators: %d: count */
			__( '%d URL retirées de l’inventaire SEO (plus publiées, supprimées ou exclues du suivi).', 'cyberpush-ultra' ),
			count( $stale )
		) );
		return count( $stale );
	}

	/** Anomalies et liens dont la page n'existe plus (course analyse / corbeille). */
	private function purge_orphan_rows() {
		global $wpdb;
		$pages = CU_DB::table( 'seo_pages' );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DELETE i FROM ' . CU_DB::table( 'seo_issues' ) . " i LEFT JOIN {$pages} p ON p.id = i.page_id WHERE p.id IS NULL" );
		$wpdb->query( 'DELETE l FROM ' . CU_DB::table( 'seo_links' ) . " l LEFT JOIN {$pages} p ON p.id = l.source_page_id WHERE p.id IS NULL" );
		// phpcs:enable
	}

	/** Supprime des pages de l'inventaire avec leurs anomalies et liens sortants. */
	private function forget_page_ids( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// La PAGE d'abord : une analyse en cours sur cette page (transaction +
			// verrou de ligne) se termine avant, puis ses anomalies et liens sont
			// supprimés ; une analyse qui démarre après ne trouve plus la page et
			// n'écrit rien. Sinon, des anomalies pouvaient rester orphelines.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CU_DB::table( 'seo_pages' ) . " WHERE id IN ( {$ph} )", $chunk ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CU_DB::table( 'seo_issues' ) . " WHERE page_id IN ( {$ph} )", $chunk ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CU_DB::table( 'seo_links' ) . " WHERE source_page_id IN ( {$ph} )", $chunk ) );
			// phpcs:enable
		}
	}

	// =====================================================================
	// Crawl + audit
	// =====================================================================

	/**
	 * Traite un lot de pages à (ré)analyser. Reprenable : renvoie la
	 * progression pour que le JS enchaîne les lots.
	 */
	public function crawl_batch( $limit = 0 ) {
		global $wpdb;
		$table = CU_DB::table( 'seo_pages' );
		$limit = $limit > 0 ? (int) $limit : (int) CU_Settings::get( 'seo', 'crawl_batch_size', 8 );
		$limit = max( 1, min( 25, $limit ) );

		// Sans l'extension DOM, aucune page ne peut être lue : on le dit une
		// fois, clairement, au lieu de crawler pour produire des pages vides.
		if ( ! self::dom_available() ) {
			return new WP_Error( 'cu_seo_dom_missing', self::dom_missing_message(), array( 'status' => 503 ) );
		}

		$recrawl_days = max( 1, (int) CU_Settings::get( 'seo', 'recrawl_days', 7 ) );
		$stale        = CU_DB::date_from_now( 'Y-m-d H:i:s', -$recrawl_days * DAY_IN_SECONDS );
		// Une page injoignable est retentée après une heure (panne passagère),
		// jamais dans la même série de lots (sinon boucle sur la même page).
		$retry        = CU_DB::date_from_now( 'Y-m-d H:i:s', -HOUR_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE crawl_state = 'pending' OR last_crawled IS NULL OR last_crawled < %s
				OR ( crawl_state = 'error' AND last_crawled < %s )
			 ORDER BY ( last_crawled IS NULL ) DESC, last_crawled ASC
			 LIMIT %d",
			$stale, $retry, $limit
		), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'cu_seo_crawl_read_failed', __( 'Impossible de lire la file d’analyse SEO.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		// Budget en temps réel : 8 pages × 15 s de délai dépasseraient les coupures
		// des proxys (Nginx ~60 s, Cloudflare 100 s) → erreur 504/524 côté admin
		// alors que le serveur continue. On ne démarre une page que si elle peut
		// finir dans le budget ; la première est toujours traitée (progression
		// garantie), le reste part au lot suivant.
		$timeout   = max( 5, min( 30, (int) CU_Settings::get( 'seo', 'request_timeout', 15 ) ) );
		$deadline  = microtime( true ) + self::BATCH_BUDGET;
		$processed = 0;
		foreach ( (array) $rows as $row ) {
			if ( $processed > 0 && microtime( true ) + $timeout > $deadline ) {
				break;
			}
			// Chaque page dispose au plus du délai réglé, REDIRECTIONS COMPRISES
			// (avant : jusqu'à 6 sauts × délai, ce qui crevait le budget du lot).
			$result = $this->crawl_single( $row, microtime( true ) + $timeout );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$processed++;
		}

		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = '' !== $wpdb->last_error;
		$pending = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE crawl_state = 'pending' OR last_crawled IS NULL OR last_crawled < %s
				OR ( crawl_state = 'error' AND last_crawled < %s )",
			$stale,
			$retry
		) );
		if ( $read_failed || $wpdb->last_error ) {
			return new WP_Error( 'cu_seo_crawl_count_failed', __( 'Impossible de calculer la progression de l’analyse SEO.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		return array(
			'processed' => $processed,
			'pending'   => $pending,
			'total'     => $total,
		);
	}

	/** L'extension PHP DOM (DOMDocument + DOMXPath) est-elle disponible ? */
	public static function dom_available() {
		return class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' );
	}

	private static function dom_missing_message() {
		return __( 'Analyse impossible : l’extension PHP « DOM » (paquet php-xml) n’est pas installée sur le serveur. Demandez à votre hébergeur de l’activer ; aucune page n’a été notée en attendant.', 'cyberpush-ultra' );
	}

	/**
	 * Récupère le rendu final, l'audite, et enregistre tout.
	 *
	 * Seul un HTML réellement exploitable est audité. Sinon une seule
	 * information honnête est enregistrée (jamais un « document vide » qui
	 * inventerait « titre absent », « H1 absent »…) :
	 *  - réseau / délai / réponse vide / 5xx → état `error`, données
	 *    précédentes conservées, nouvel essai dans l'heure ;
	 *  - 4xx (et code final hors 2xx) → état `http_error`, seule l'anomalie
	 *    « code HTTP » ; plus de score ni de liens sortants pour cette page ;
	 *  - fichier non HTML (PDF…) ou extension DOM absente → état `skipped`,
	 *    raison affichée, AUCUNE anomalie.
	 *
	 * @param array $row      Ligne seo_pages.
	 * @param float $deadline Échéance absolue de la récupération (0 = délai réglé).
	 */
	private function crawl_single( $row, $deadline = 0 ) {
		global $wpdb;
		$pages  = CU_DB::table( 'seo_pages' );
		$issues = CU_DB::table( 'seo_issues' );
		$links  = CU_DB::table( 'seo_links' );
		$now    = current_time( 'mysql' );
		$page_id = (int) $row['id'];
		$url     = $row['url'];

		if ( ! self::dom_available() ) {
			return $this->record_unanalysable( $row, array(), $now, 'dom_missing' );
		}

		$fetch  = CU_SEO_Renderer::fetch( $url, $deadline );
		$status = (int) $fetch['http_status'];
		if ( 0 === $status ) {
			return $this->record_fetch_failure( $row, $fetch, $now, 'network' );
		}
		if ( $status >= 500 ) {
			return $this->record_fetch_failure( $row, $fetch, $now, 'server' );
		}
		if ( $status < 200 || $status >= 300 ) {
			return $this->record_http_error( $row, $fetch, $now );
		}
		if ( '' === trim( (string) $fetch['html'] ) ) {
			return $this->record_fetch_failure( $row, $fetch, $now, 'empty' );
		}
		if ( ! empty( $fetch['not_html'] ) ) {
			return $this->record_unanalysable( $row, $fetch, $now, 'not_html' );
		}
		$final_url = ! empty( $fetch['final_url'] ) ? $fetch['final_url'] : $url;
		// URL de l'inventaire qui redirige vers une AUTRE page déjà inventoriée :
		// cette destination est analysée pour elle-même. Enregistrer ici son
		// titre, sa description, son score et ses liens en ferait un faux
		// « doublon » (titres/descriptions), fausserait les moyennes et
		// présenterait cette URL comme source des liens de la destination.
		if ( ! empty( $fetch['redirected'] ) && $this->is_other_inventory_url( $final_url, $page_id ) ) {
			return $this->record_redirect( $row, $fetch, $now, $final_url );
		}
		$parsed    = CU_SEO_Renderer::parse( $fetch['html'], $final_url, isset( $fetch['x_robots'] ) ? $fetch['x_robots'] : array() );
		if ( empty( $parsed['analysable'] ) ) {
			return $this->record_unanalysable( $row, $fetch, $now, (string) $parsed['not_analysable_reason'] );
		}

		$context = array(
			'http_status'    => $status,
			'url'            => $url,
			'target_keyword' => $this->get_target_keyword( (int) $row['post_id'] ),
			'redirected_to'  => ! empty( $fetch['redirected'] ) ? $final_url : '',
			'canonical'      => $this->canonical_context( isset( $parsed['canonical_url'] ) ? $parsed['canonical_url'] : '', $final_url ),
		);
		$audit  = CU_SEO_Audit::analyze( $parsed, $context );
		$counts = array( 'error' => 0, 'warning' => 0, 'notice' => 0 );
		foreach ( $audit['issues'] as $iss ) {
			if ( isset( $counts[ $iss['severity'] ] ) ) {
				$counts[ $iss['severity'] ]++;
			}
		}

		// Snapshot compact pour l'écran de détail (« voir le rendu final »).
		$snapshot = array(
			'fetched_at'       => $now,
			'http_status'      => $fetch['http_status'],
			'time_ms'          => $fetch['time_ms'],
			'bytes'            => $fetch['bytes'],
			'error'            => $fetch['error'],
			'title'            => $parsed['title'],
			'meta_description' => $parsed['meta_description'],
			'canonical'        => $parsed['canonical'],
			'canonical_url'    => isset( $parsed['canonical_url'] ) ? $parsed['canonical_url'] : '',
			'robots'           => $parsed['robots'],
			'robots_sources'   => isset( $parsed['robots_sources'] ) ? $parsed['robots_sources'] : array(),
			'h1'               => $parsed['h1'],
			'headings'         => array_slice( $parsed['headings'], 0, 40 ),
			'word_count'       => $parsed['word_count'],
			'text_sample'      => CU_SEO_Renderer_truncate( $parsed['text_sample'], 1500 ),
			'images_total'     => count( $parsed['images'] ),
			'images_missing_alt' => $parsed['images_missing_alt'],
			'images'           => array_slice( $parsed['images'], 0, 20 ),
			'links_internal'   => count( $parsed['links_internal'] ),
			'links_external'   => count( $parsed['links_external'] ),
			'og'               => $parsed['og'],
			'twitter'          => $parsed['twitter'],
			'schema_types'     => $parsed['schema_types'],
			'lang'             => $parsed['lang'],
			'viewport'         => $parsed['viewport'],
			'favicon'          => $parsed['favicon'],
			'mixed_content'    => $parsed['mixed_content'],
			'insecure_forms'   => $parsed['insecure_forms'],
			'js_only'          => $parsed['js_only'],
			// Transparence : sur quoi portent le nombre de mots et la densité.
			'word_count_page'  => $parsed['word_count_page'],
			'content_scope'    => $parsed['content_scope'],
			'final_url'        => ! empty( $fetch['redirected'] ) ? $final_url : '',
			'content_type'     => $fetch['content_type'],
			'readability'      => isset( $audit['readability'] ) ? array(
				'available'         => $audit['readability']['available'],
				'score'             => $audit['readability']['score'],
				'flesch'            => $audit['readability']['flesch'],
				'avg_sentence'      => $audit['readability']['avg_sentence'],
				'long_sentence_pct' => $audit['readability']['long_sentence_pct'],
				'transition_pct'    => $audit['readability']['transition_pct'],
				'reason'            => isset( $audit['readability']['reason'] ) ? $audit['readability']['reason'] : '',
				'lang'              => isset( $audit['readability']['lang'] ) ? $audit['readability']['lang'] : '',
			) : null,
		);
		// Conserve les mesures externes (PageSpeed, indexation) : relues juste
		// avant l'écriture, pour ne pas écraser une mesure faite pendant le crawl.
		$snapshot = $this->keep_external_measures( $snapshot, $page_id, $row );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->crawl_db_error( 'transaction_start' );
		}
		// La page a pu sortir de l'inventaire pendant la récupération (contenu mis
		// à la corbeille) : ne rien écrire, sinon ses anomalies resteraient
		// orphelines. Verrou de ligne : la suppression concurrente attend la fin.
		if ( ! $this->page_still_exists( $page_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return true;
		}

		$updated = $wpdb->update( $pages, array(
			'title'          => CU_SEO_Renderer_truncate( $parsed['title'], 255 ),
			'meta_desc'      => CU_SEO_Renderer_truncate( $parsed['meta_description'], 320 ),
			'http_status'    => $status,
			'indexable'      => $parsed['indexable'] ? 1 : 0,
			'word_count'     => $parsed['word_count'],
			'score'          => $audit['scores']['overall'],
			'score_content'  => $audit['scores']['content'],
			'score_meta'     => $audit['scores']['meta'],
			'score_technical' => $audit['scores']['technical'],
			'ai_ready'       => CU_SEO_Audit::ai_ready_score( $parsed ),
			'issues_error'   => $counts['error'],
			'issues_warning' => $counts['warning'],
			'issues_notice'  => $counts['notice'],
			'snapshot'       => wp_json_encode( $snapshot ),
			'crawl_state'    => 'done',
			'last_crawled'   => $now,
			'last_audited'   => $now,
		), array( 'id' => $page_id ) );
		if ( false === $updated ) {
			return $this->crawl_db_error( 'page_update' );
		}

		// Anomalies : on remplace celles de la page.
		if ( false === $wpdb->delete( $issues, array( 'page_id' => $page_id ) ) ) {
			return $this->crawl_db_error( 'issues_delete' );
		}
		foreach ( $audit['issues'] as $iss ) {
			$inserted = $wpdb->insert( $issues, array(
				'page_id'    => $page_id,
				'category'   => $iss['category'],
				'code'       => $iss['code'],
				'severity'   => $iss['severity'],
				'message'    => CU_SEO_Renderer_truncate( $iss['message'], 255 ),
				'detail'     => CU_SEO_Renderer_truncate( $iss['detail'], 500 ),
				'created_at' => $now,
			) );
			if ( false === $inserted ) {
				return $this->crawl_db_error( 'issue_insert' );
			}
		}

		// Liens : on remplace. Une même URL n'est stockée qu'UNE fois par page
		// (menu + pied de page + contenu la répétaient jusqu'à 3 fois), dans la
		// limite de 150 liens distincts, liens internes d'abord.
		if ( false === $wpdb->delete( $links, array( 'source_page_id' => $page_id ) ) ) {
			return $this->crawl_db_error( 'links_delete' );
		}
		$all_links = self::distinct_links( array_merge( $parsed['links_internal'], $parsed['links_external'] ), 150 );
		foreach ( array_chunk( $all_links, 50 ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $lnk ) {
				$values[] = '( %d, %s, %s, %s, %s, %d, %s )';
				array_push( $params, $page_id, $lnk['url'], $lnk['hash'], CU_SEO_Renderer_truncate( $lnk['anchor'], 255 ), $lnk['type'], $lnk['nofollow'] ? 1 : 0, $now );
			}
			// Insertion groupée (50 lignes par requête au lieu de 50 requêtes).
			$inserted = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$links} ( source_page_id, url, url_hash, anchor, type, is_nofollow, created_at ) VALUES " . implode( ', ', $values ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			) );
			if ( false === $inserted ) {
				return $this->crawl_db_error( 'link_insert' );
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return $this->crawl_db_error( 'transaction_commit' );
		}
		return true;
	}

	/**
	 * Dédoublonne les liens d'une page (même URL = même hash) en gardant la
	 * première occurrence ; une ancre vide est complétée par une occurrence
	 * suivante qui en a une, et le lien est « suivi » si une occurrence l'est.
	 *
	 * @return array<int, array{url:string, hash:string, anchor:string, type:string, nofollow:bool}>
	 */
	public static function distinct_links( $links, $max = 150 ) {
		$out = array();
		$pos = array();
		foreach ( (array) $links as $lnk ) {
			if ( empty( $lnk['url'] ) || strlen( (string) $lnk['url'] ) > self::MAX_URL_LENGTH ) {
				continue;
			}
			$hash = md5( (string) $lnk['url'] );
			if ( isset( $pos[ $hash ] ) ) {
				$i = $pos[ $hash ];
				if ( '' === (string) $out[ $i ]['anchor'] && '' !== (string) $lnk['anchor'] ) {
					$out[ $i ]['anchor'] = (string) $lnk['anchor'];
				}
				if ( empty( $lnk['nofollow'] ) ) {
					$out[ $i ]['nofollow'] = false;
				}
				continue;
			}
			if ( count( $out ) >= $max ) {
				continue; // on continue pour compléter ancres/nofollow des liens déjà retenus
			}
			$pos[ $hash ] = count( $out );
			$out[]        = array(
				'url'      => (string) $lnk['url'],
				'hash'     => $hash,
				'anchor'   => (string) $lnk['anchor'],
				'type'     => 'internal' === $lnk['type'] ? 'internal' : 'external',
				'nofollow' => ! empty( $lnk['nofollow'] ),
			);
		}
		return $out;
	}

	/**
	 * Recopie dans un nouveau snapshot les mesures EXTERNES déjà acquises
	 * (PageSpeed, indexation) — relues en base juste avant l'écriture.
	 */
	private function keep_external_measures( $snapshot, $page_id, $row ) {
		global $wpdb;
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot FROM ' . CU_DB::table( 'seo_pages' ) . ' WHERE id = %d', (int) $page_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( null === $current && ! empty( $row['snapshot'] ) ) {
			$current = $row['snapshot'];
		}
		$prev = $current ? json_decode( (string) $current, true ) : array();
		if ( is_array( $prev ) ) {
			foreach ( array( 'perf', 'perf_error', 'index' ) as $key ) {
				if ( isset( $prev[ $key ] ) ) {
					$snapshot[ $key ] = $prev[ $key ];
				}
			}
		}
		return $snapshot;
	}

	/**
	 * Enregistre un échec de récupération sans inventer de constat : une seule
	 * anomalie, l'état `error` (hors des moyennes du site) et une nouvelle
	 * tentative automatique une heure plus tard. Les données de la dernière
	 * analyse réussie (score, liens) restent en place : la panne peut être
	 * passagère.
	 *
	 * @param string $kind network | server (5xx) | empty (réponse vide)
	 */
	private function record_fetch_failure( $row, $fetch, $now, $kind = 'network' ) {
		$status = (int) $fetch['http_status'];
		if ( 'server' === $kind ) {
			$code    = 'http_error';
			$message = sprintf( __( 'La page renvoie une erreur serveur (HTTP %d)', 'cyberpush-ultra' ), $status );
			$detail  = __( 'Le serveur a répondu par une erreur pendant l’analyse ; elle peut être passagère (surcharge, maintenance). Aucun autre constat n’est fait sur cette page. Nouvel essai automatique dans l’heure, ou cliquez « Ré-analyser ».', 'cyberpush-ultra' );
		} elseif ( 'empty' === $kind ) {
			$code    = 'fetch_failed';
			$message = __( 'Réponse vide pendant l’analyse', 'cyberpush-ultra' );
			$detail  = sprintf( __( 'Le serveur a répondu HTTP %d sans aucun contenu. Vérifiez que la page s’affiche ; nouvel essai automatique dans l’heure.', 'cyberpush-ultra' ), $status );
		} else {
			$reason  = '' !== (string) $fetch['error'] ? (string) $fetch['error'] : __( 'aucune réponse dans le délai imparti', 'cyberpush-ultra' );
			$code    = 'fetch_failed';
			$message = __( 'Page injoignable pendant l’analyse', 'cyberpush-ultra' );
			/* translators: %s: raison technique de l'échec */
			$detail  = sprintf( __( 'Aucun contenu n’a pu être récupéré (%s). Vérifiez que la page s’affiche ; une nouvelle tentative est faite automatiquement dans l’heure, ou cliquez « Ré-analyser ».', 'cyberpush-ultra' ), $reason );
		}
		return $this->record_page_state( $row, $fetch, $now, array(
			'state'     => 'error',
			'keep_data' => true,
			'issue'     => array( 'code' => $code, 'severity' => 'error', 'message' => $message, 'detail' => $detail ),
		) );
	}

	/**
	 * Code HTTP final hors 2xx (404, 410, 403, 3xx sans destination…) : seule
	 * l'anomalie « code HTTP » est enregistrée. La page n'a plus de score ni de
	 * liens sortants (elle n'en a plus réellement pour les visiteurs).
	 */
	private function record_http_error( $row, $fetch, $now ) {
		$status = (int) $fetch['http_status'];
		return $this->record_page_state( $row, $fetch, $now, array(
			'state'     => 'http_error',
			'keep_data' => false,
			'issue'     => array(
				'code'     => 'http_error',
				'severity' => 'error',
				'message'  => sprintf( __( 'La page renvoie un code HTTP %d', 'cyberpush-ultra' ), $status ),
				'detail'   => __( 'Une page non accessible (4xx) ne peut pas être indexée. Si elle a été supprimée volontairement, retirez les liens qui y mènent (ou redirigez-la en 301) ; sinon restaurez-la. Aucun autre constat n’est fait sur cette page.', 'cyberpush-ultra' ),
			),
		) );
	}

	/**
	 * Page impossible à analyser (fichier non HTML, extension DOM absente) :
	 * état `skipped`, raison stockée et affichée, AUCUNE anomalie ni score.
	 */
	private function record_unanalysable( $row, $fetch, $now, $reason ) {
		$fetch = array_merge( array( 'http_status' => 0, 'time_ms' => 0, 'error' => '', 'content_type' => '' ), (array) $fetch );
		return $this->record_page_state( $row, $fetch, $now, array(
			'state'     => 'skipped',
			'keep_data' => false,
			'reason'    => sanitize_key( '' !== $reason ? $reason : 'unknown' ),
			'issue'     => null,
		) );
	}

	/**
	 * URL de l'inventaire qui redirige vers une autre page de l'inventaire :
	 * état `skipped` (raison « redirected »), sans score, titre ni liens, avec une
	 * seule information (code de la redirection, nombre de sauts, destination).
	 */
	private function record_redirect( $row, $fetch, $now, $final_url ) {
		$code = (int) ( isset( $fetch['redirect_status'] ) ? $fetch['redirect_status'] : 0 );
		$hops = max( 1, (int) ( isset( $fetch['redirect_hops'] ) ? $fetch['redirect_hops'] : 1 ) );
		$kind = in_array( $code, array( 301, 308 ), true ) ? __( 'permanente', 'cyberpush-ultra' )
			: ( in_array( $code, array( 302, 303, 307 ), true ) ? __( 'temporaire', 'cyberpush-ultra' ) : '' );
		/* translators: 1: URL de destination, 2: code HTTP, 3: nature (permanente/temporaire), 4: nombre de sauts */
		$detail = sprintf( __( 'Destination : %1$s (HTTP %2$d%3$s, %4$d saut(s)). Cette destination est analysée pour elle-même : cette URL n’est ni notée, ni comptée dans les titres ou descriptions en double. Faites pointer vos liens internes directement vers l’URL finale.', 'cyberpush-ultra' ), $final_url, $code, '' !== $kind ? ' — ' . $kind : '', $hops );
		if ( $hops > 1 ) {
			$detail .= ' ' . __( 'Chaîne de redirections : réduisez-la à un seul saut.', 'cyberpush-ultra' );
		}
		if ( $code ) {
			$fetch['http_status'] = $code;
		}
		return $this->record_page_state( $row, $fetch, $now, array(
			'state'     => 'skipped',
			'keep_data' => false,
			'reason'    => 'redirected',
			'snapshot'  => array(
				'final_url'      => esc_url_raw( (string) $final_url ),
				'redirect_hops'  => $hops,
				'redirect_status' => $code,
			),
			'issue'     => array(
				'category' => 'technical',
				'code'     => 'redirected',
				'severity' => 'notice',
				'message'  => __( 'L\'URL de l\'inventaire redirige vers une autre adresse', 'cyberpush-ultra' ),
				'detail'   => $detail,
			),
		) );
	}

	/** La destination d'une redirection est-elle une AUTRE page de l'inventaire ? */
	private function is_other_inventory_url( $url, $page_id ) {
		global $wpdb;
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		$hashes = array( md5( $url ) );
		if ( false === strpos( $url, '?' ) ) {
			$hashes[] = md5( '/' === substr( $url, -1 ) ? untrailingslashit( $url ) : trailingslashit( $url ) );
		}
		$found = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . CU_DB::table( 'seo_pages' ) . ' WHERE url_hash IN ( %s, %s ) AND id <> %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$hashes[0],
			isset( $hashes[1] ) ? $hashes[1] : $hashes[0],
			(int) $page_id
		) );
		return ! empty( $found );
	}

	/**
	 * Nature du canonical d'une page, à partir des seules données connues :
	 * self (la page elle-même), other (autre URL du site), broken (autre URL
	 * du site en erreur lors de sa dernière analyse), external (autre domaine).
	 * Aucune requête HTTP supplémentaire.
	 *
	 * @return array{kind?:string, target?:string, status?:int}
	 */
	private function canonical_context( $canonical, $page_url ) {
		$canonical = (string) $canonical;
		$want      = '' !== $canonical ? self::index_match_key( $canonical ) : '';
		if ( '' === $want ) {
			return array();
		}
		if ( $want === self::index_match_key( $page_url ) ) {
			return array( 'kind' => 'self', 'target' => $canonical );
		}
		$c = wp_parse_url( $canonical );
		$h = wp_parse_url( home_url( '/' ) );
		$strip = static function ( $parts ) {
			$host   = preg_replace( '/^www\./', '', rtrim( strtolower( isset( $parts['host'] ) ? (string) $parts['host'] : '' ), '.' ) );
			$scheme = strtolower( isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https' );
			$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
			// http ↔ https sur les ports par défaut : même site.
			return $host . ':' . ( in_array( $port, array( 80, 443 ), true ) ? 'std' : $port );
		};
		if ( ! is_array( $c ) || ! is_array( $h ) || $strip( $c ) !== $strip( $h ) ) {
			return array( 'kind' => 'external', 'target' => $canonical );
		}
		global $wpdb;
		$url    = esc_url_raw( $canonical );
		$alt    = false === strpos( $url, '?' ) ? ( '/' === substr( $url, -1 ) ? untrailingslashit( $url ) : trailingslashit( $url ) ) : $url;
		$target = $wpdb->get_row( $wpdb->prepare(
			'SELECT http_status, crawl_state FROM ' . CU_DB::table( 'seo_pages' ) . ' WHERE url_hash IN ( %s, %s ) LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			md5( $url ),
			md5( $alt )
		), ARRAY_A );
		if ( is_array( $target ) && ( 'http_error' === $target['crawl_state'] || (int) $target['http_status'] >= 400 ) ) {
			return array( 'kind' => 'broken', 'target' => $canonical, 'status' => (int) $target['http_status'] );
		}
		return array( 'kind' => 'other', 'target' => $canonical );
	}

	/** La page est-elle toujours dans l'inventaire ? (verrou de ligne pendant la transaction) */
	private function page_still_exists( $page_id ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CU_DB::table( 'seo_pages' ) . ' WHERE id = %d FOR UPDATE', (int) $page_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $wpdb->last_error ) {
			return true; // dans le doute, comportement historique (écriture)
		}
		return null !== $found;
	}

	/** Écriture commune des états « sans audit » (transaction). */
	private function record_page_state( $row, $fetch, $now, $opts ) {
		global $wpdb;
		$pages   = CU_DB::table( 'seo_pages' );
		$issues  = CU_DB::table( 'seo_issues' );
		$page_id = (int) $row['id'];

		$snapshot = $row['snapshot'] ? json_decode( $row['snapshot'], true ) : array();
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		if ( empty( $opts['keep_data'] ) ) {
			// Les constats de la dernière analyse ne valent plus pour cette URL.
			$snapshot = $this->keep_external_measures( array(), $page_id, $row );
		}
		$snapshot['fetched_at']   = $now;
		$snapshot['http_status']  = (int) $fetch['http_status'];
		$snapshot['time_ms']      = (int) $fetch['time_ms'];
		$snapshot['error']        = (string) $fetch['error'];
		$snapshot['content_type'] = isset( $fetch['content_type'] ) ? (string) $fetch['content_type'] : '';
		$snapshot['unanalysable'] = isset( $opts['reason'] ) ? (string) $opts['reason'] : '';
		if ( ! empty( $opts['snapshot'] ) && is_array( $opts['snapshot'] ) ) {
			$snapshot = array_merge( $snapshot, $opts['snapshot'] );
		}

		$issue = isset( $opts['issue'] ) ? $opts['issue'] : null;
		$data  = array(
			'http_status'    => (int) $fetch['http_status'],
			'issues_error'   => ( $issue && 'error' === $issue['severity'] ) ? 1 : 0,
			'issues_warning' => ( $issue && 'warning' === $issue['severity'] ) ? 1 : 0,
			'issues_notice'  => ( $issue && 'notice' === $issue['severity'] ) ? 1 : 0,
			'snapshot'       => wp_json_encode( $snapshot ),
			'crawl_state'    => $opts['state'],
			'last_crawled'   => $now,
		);
		if ( empty( $opts['keep_data'] ) ) {
			// Plus de note : la page sort des moyennes et de la répartition.
			$data = array_merge( $data, array(
				'score'           => null,
				'score_content'   => null,
				'score_meta'      => null,
				'score_technical' => null,
				'ai_ready'        => null,
				'word_count'      => 0,
				'last_audited'    => $now,
			) );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->crawl_db_error( 'transaction_start' );
		}
		if ( ! $this->page_still_exists( $page_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return true; // retirée de l'inventaire entre-temps (corbeille…)
		}
		if ( false === $wpdb->update( $pages, $data, array( 'id' => $page_id ) ) ) {
			return $this->crawl_db_error( 'page_update' );
		}
		if ( false === $wpdb->delete( $issues, array( 'page_id' => $page_id ) ) ) {
			return $this->crawl_db_error( 'issues_delete' );
		}
		if ( $issue ) {
			$inserted = $wpdb->insert( $issues, array(
				'page_id'    => $page_id,
				'category'   => isset( $issue['category'] ) ? $issue['category'] : 'technical',
				'code'       => $issue['code'],
				'severity'   => $issue['severity'],
				'message'    => CU_SEO_Renderer_truncate( $issue['message'], 255 ),
				'detail'     => CU_SEO_Renderer_truncate( $issue['detail'], 500 ),
				'created_at' => $now,
			) );
			if ( false === $inserted ) {
				return $this->crawl_db_error( 'issue_insert' );
			}
		}
		if ( empty( $opts['keep_data'] ) && false === $wpdb->delete( CU_DB::table( 'seo_links' ), array( 'source_page_id' => $page_id ) ) ) {
			return $this->crawl_db_error( 'links_delete' );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return $this->crawl_db_error( 'transaction_commit' );
		}
		return true;
	}

	/** Annule une écriture de crawl et rend l’erreur visible dans le journal. */
	private function crawl_db_error( $operation ) {
		global $wpdb;
		$db_error = CU_SEO_Renderer_truncate( (string) $wpdb->last_error, 500 );
		$wpdb->query( 'ROLLBACK' );
		CU_Logger::log( 'seo', 'error', __( 'Échec SQL pendant l’enregistrement d’une analyse SEO.', 'cyberpush-ultra' ), array(
			'operation' => sanitize_key( $operation ),
			'db_error'  => $db_error,
		) );
		return new WP_Error( 'cu_seo_crawl_write_failed', __( 'Impossible d’enregistrer l’analyse SEO.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
	}

	/**
	 * Trafic RÉEL (pas estimé) par contenu, issu de NOTRE propre base Analytics
	 * (cu_content_daily). Source ⭐⭐⭐⭐⭐ : ce sont les visites réellement
	 * enregistrées sur le site. Retourne post_id => array( visits, views ).
	 *
	 * @param int[] $post_ids
	 * @param int   $days Fenêtre (jours).
	 */
	private function get_traffic_map( $post_ids, $days = 30 ) {
		$post_ids = array_values( array_filter( array_map( 'intval', (array) $post_ids ) ) );
		if ( empty( $post_ids ) || ! CU_DB::tables_exist() ) {
			return array();
		}
		global $wpdb;
		$daily      = CU_DB::table( 'content_daily' );
		$cutoff     = CU_DB::date_from_now( 'Y-m-d', -$days * DAY_IN_SECONDS );
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$params     = array_merge( array( $cutoff ), $post_ids );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT object_id, SUM(sessions) AS visits, SUM(pageviews) AS views
			 FROM {$daily} WHERE stat_date >= %s AND object_id IN ( {$placeholders} )
			 GROUP BY object_id", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$map = array();
		foreach ( (array) $rows as $r ) {
			$map[ (int) $r['object_id'] ] = array( 'visits' => (int) $r['visits'], 'views' => (int) $r['views'] );
		}
		return $map;
	}

	private function get_target_keyword( $post_id ) {
		if ( ! $post_id ) {
			return '';
		}
		global $wpdb;
		$meta = CU_DB::table( 'seo_meta' );
		$kw = $wpdb->get_var( $wpdb->prepare( "SELECT target_keyword FROM {$meta} WHERE post_id = %d", $post_id ) );
		return $kw ? $kw : '';
	}

	/**
	 * Audit E-E-A-T (Expérience, Expertise, Autorité, Confiance) — signaux que
	 * Google valorise pour juger la fiabilité d'un contenu. 100 % local : chaque
	 * critère est un fait vérifiable (pas d'estimation).
	 */
	private function compute_eeat( $row, $snap ) {
		$checks = array();
		$add = function ( $label, $ok, $source = 'wordpress' ) use ( &$checks ) {
			$checks[] = array( 'label' => $label, 'ok' => (bool) $ok, 'source' => $source );
		};

		$snap   = is_array( $snap ) ? $snap : array();
		$schema = isset( $snap['schema_types'] ) ? (array) $snap['schema_types'] : array();
		$ext    = isset( $snap['links_external'] ) ? (int) $snap['links_external'] : 0;

		$add( __( 'Site sécurisé (HTTPS)', 'cyberpush-ultra' ), 0 === strpos( $row['url'], 'https://' ) );
		$add( __( 'Sources externes citées', 'cyberpush-ultra' ), $ext > 0 );
		$add( __( 'Balisage auteur / organisation (schema)', 'cyberpush-ultra' ), (bool) array_intersect( $schema, array( 'Person', 'Organization', 'Author', 'NewsArticle' ) ) );

		if ( $row['post_id'] ) {
			$post = get_post( (int) $row['post_id'] );
			if ( $post ) {
				$add( __( 'Auteur identifié', 'cyberpush-ultra' ), '' !== get_the_author_meta( 'display_name', $post->post_author ) );
				$add( __( 'Biographie de l\'auteur renseignée', 'cyberpush-ultra' ), '' !== trim( (string) get_the_author_meta( 'description', $post->post_author ) ) );
				$add( __( 'Date de publication', 'cyberpush-ultra' ), '' !== $post->post_date && '0000-00-00 00:00:00' !== $post->post_date );
				$add( __( 'Contenu récent (mis à jour < 1 an)', 'cyberpush-ultra' ), strtotime( $post->post_modified ) > strtotime( '-365 days' ) );
			}
		}

		$add( __( 'Page « Contact »', 'cyberpush-ultra' ), $this->site_has_page( array( 'contact', 'nous-contacter' ) ) );
		$add( __( 'Mentions légales', 'cyberpush-ultra' ), $this->site_has_page( array( 'mentions-legales', 'mentions', 'legal' ) ) );
		$add( __( 'Politique de confidentialité', 'cyberpush-ultra' ), $this->site_has_page( array( 'politique-de-confidentialite', 'confidentialite', 'privacy-policy' ) ) );

		$ok = 0;
		foreach ( $checks as $c ) {
			if ( $c['ok'] ) { $ok++; }
		}
		$total = max( 1, count( $checks ) );
		return array( 'score' => (int) round( ( $ok / $total ) * 100 ), 'checks' => $checks );
	}

	/**
	 * Checklist « AI-Readiness » — les signaux concrets (pas un score opaque)
	 * qui rendent une page compréhensible et citable par les moteurs génératifs.
	 */
	private function compute_ai_checklist( $snap ) {
		$snap   = is_array( $snap ) ? $snap : array();
		$schema = isset( $snap['schema_types'] ) ? (array) $snap['schema_types'] : array();
		$h1     = isset( $snap['h1'] ) ? count( (array) $snap['h1'] ) : 0;
		$has_h2 = false;
		foreach ( (array) ( isset( $snap['headings'] ) ? $snap['headings'] : array() ) as $h ) {
			if ( 2 === (int) $h['level'] ) { $has_h2 = true; break; }
		}
		$checks = array(
			array( 'label' => __( 'Structure claire (1 H1 + des H2)', 'cyberpush-ultra' ), 'ok' => ( 1 === $h1 && $has_h2 ) ),
			array( 'label' => __( 'Données structurées (Schema.org)', 'cyberpush-ultra' ), 'ok' => ! empty( $schema ) ),
			array( 'label' => __( 'FAQ / Q&A balisée', 'cyberpush-ultra' ), 'ok' => (bool) array_intersect( $schema, array( 'FAQPage', 'QAPage', 'HowTo' ) ) ),
			array( 'label' => __( 'Contenu suffisant (≥ 300 mots)', 'cyberpush-ultra' ), 'ok' => ( isset( $snap['word_count'] ) ? (int) $snap['word_count'] : 0 ) >= 300 ),
			array( 'label' => __( 'Sources / références externes', 'cyberpush-ultra' ), 'ok' => ( isset( $snap['links_external'] ) ? (int) $snap['links_external'] : 0 ) > 0 ),
			array( 'label' => __( 'Résumé exploitable (meta description)', 'cyberpush-ultra' ), 'ok' => ! empty( $snap['meta_description'] ) ),
			array( 'label' => __( 'Images décrites (attribut alt)', 'cyberpush-ultra' ), 'ok' => 0 === ( isset( $snap['images_missing_alt'] ) ? (int) $snap['images_missing_alt'] : 0 ) ),
			array( 'label' => __( 'Langue déclarée', 'cyberpush-ultra' ), 'ok' => ! empty( $snap['lang'] ) ),
		);
		$ok = 0;
		foreach ( $checks as $c ) {
			if ( $c['ok'] ) { $ok++; }
		}
		return array( 'score' => (int) round( ( $ok / count( $checks ) ) * 100 ), 'checks' => $checks );
	}

	/** Détecte (avec cache) l'existence d'une page de confiance par ses slugs usuels. */
	private function site_has_page( $slugs ) {
		static $cache = array();
		foreach ( (array) $slugs as $slug ) {
			if ( ! isset( $cache[ $slug ] ) ) {
				// Seule une page PUBLIÉE compte : un brouillon « Politique de
				// confidentialité » (créé d'office par WordPress) n'est pas visible.
				$p = get_page_by_path( $slug );
				$cache[ $slug ] = ( $p instanceof WP_Post ) && 'publish' === $p->post_status && '' === (string) $p->post_password;
			}
			if ( $cache[ $slug ] ) {
				return true;
			}
		}
		return false;
	}

	// =====================================================================
	// PageSpeed Insights (Core Web Vitals)
	// =====================================================================

	/**
	 * Interroge PageSpeed Insights.
	 *
	 * - Clé envoyée dans l'en-tête X-Goog-Api-Key (jamais dans l'URL, qui
	 *   finit dans les journaux de proxy).
	 * - Échec du SERVICE (400 Lighthouse, 403 clé/API refusée, 429 quota, 5xx,
	 *   délai, réponse incomplète) : « mesure indisponible », jamais un
	 *   problème SEO de la page, sans effet sur le score.
	 *
	 * @return array|WP_Error WP_Error seulement pour un défaut de configuration ;
	 *                        sinon array( ok, score, cwv ) ou array( ok=false, unavailable=true, reason, message ).
	 */
	public function query_psi( $url ) {
		if ( ! CU_Settings::get( 'seo', 'psi_enabled', false ) ) {
			return new WP_Error( 'cu_psi_disabled', __( 'PageSpeed Insights est désactivé : activez-le dans Réglages > SEO.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$key = trim( (string) CU_Settings::get( 'seo', 'psi_api_key', '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'cu_psi_no_key', __( 'Clé API PageSpeed Insights manquante (Réglages SEO).', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$strategy = 'desktop' === CU_Settings::get( 'seo', 'psi_strategy', 'mobile' ) ? 'desktop' : 'mobile';
		// http_build_query encode réellement les valeurs (add_query_arg ne le
		// fait pas : une URL de page avec « & » cassait la requête).
		$endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query( array(
			'url'      => (string) $url,
			'strategy' => $strategy,
			'category' => 'performance',
		), '', '&', PHP_QUERY_RFC3986 );

		$resp = wp_remote_get( $endpoint, array(
			'timeout'             => 45,
			'redirection'         => 0,
			'limit_response_size' => 8 * MB_IN_BYTES,
			'headers'             => array( 'X-Goog-Api-Key' => $key ),
		) );
		return self::parse_psi_response( $resp, $strategy );
	}

	/**
	 * Interprète la réponse PSI (séparé pour être testable hors WordPress).
	 *
	 * @param array|WP_Error $resp     Réponse wp_remote_get().
	 * @param string         $strategy mobile | desktop
	 */
	public static function parse_psi_response( $resp, $strategy ) {
		$unavailable = function ( $reason, $message ) {
			return array( 'ok' => false, 'unavailable' => true, 'reason' => $reason, 'message' => $message );
		};
		if ( is_wp_error( $resp ) ) {
			$timeout = false !== stripos( $resp->get_error_message(), 'timed out' ) || false !== stripos( $resp->get_error_message(), 'cURL error 28' );
			return $unavailable( $timeout ? 'timeout' : 'unreachable', $timeout
				? __( 'Mesure indisponible : PageSpeed Insights n’a pas répondu dans le délai (45 s).', 'cyberpush-ultra' )
				: __( 'Mesure indisponible : service PageSpeed Insights injoignable.', 'cyberpush-ultra' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$api  = ( is_array( $body ) && isset( $body['error']['message'] ) ) ? CU_SEO_Renderer_truncate( wp_strip_all_tags( (string) $body['error']['message'] ), 200 ) : '';
		if ( 429 === $code ) {
			return $unavailable( 'quota', __( 'Mesure indisponible : quota PageSpeed Insights atteint. Réessayez plus tard.', 'cyberpush-ultra' ) );
		}
		if ( 401 === $code || 403 === $code ) {
			return $unavailable( 'key', __( 'Mesure indisponible : clé PageSpeed refusée (clé invalide, restreinte ou API non activée dans Google Cloud).', 'cyberpush-ultra' ) );
		}
		if ( 400 === $code ) {
			/* translators: %s: message de l'API */
			return $unavailable( 'lighthouse', sprintf( __( 'Mesure indisponible : Lighthouse n’a pas pu charger la page (%s). Ce n’est pas, à lui seul, un problème SEO.', 'cyberpush-ultra' ), '' !== $api ? $api : 'HTTP 400' ) );
		}
		if ( $code >= 500 ) {
			return $unavailable( 'service', sprintf( __( 'Mesure indisponible : erreur du service PageSpeed (HTTP %d).', 'cyberpush-ultra' ), $code ) );
		}
		if ( 200 !== $code || ! is_array( $body ) ) {
			return $unavailable( 'invalid', sprintf( __( 'Mesure indisponible : réponse inattendue de PageSpeed (HTTP %d).', 'cyberpush-ultra' ), $code ) );
		}
		if ( ! isset( $body['lighthouseResult']['categories']['performance']['score'] ) || ! is_numeric( $body['lighthouseResult']['categories']['performance']['score'] ) ) {
			return $unavailable( 'incomplete', __( 'Mesure indisponible : PageSpeed n’a pas renvoyé de score de performance.', 'cyberpush-ultra' ) );
		}
		$score = (int) round( (float) $body['lighthouseResult']['categories']['performance']['score'] * 100 );

		// Données TERRAIN (CrUX) : de l'URL si disponibles, sinon PSI peut
		// renvoyer celles de toute l'origine (origin_fallback) — on le dit.
		$le      = isset( $body['loadingExperience'] ) && is_array( $body['loadingExperience'] ) ? $body['loadingExperience'] : array();
		$metrics = isset( $le['metrics'] ) && is_array( $le['metrics'] ) ? $le['metrics'] : array();
		$scope   = empty( $metrics ) ? 'none' : ( ! empty( $le['origin_fallback'] ) ? 'origin' : 'url' );
		$cwv = array(
			'lcp'         => isset( $metrics['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ) ? (int) $metrics['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] : null,
			'cls'         => isset( $metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ) ? $metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100 : null,
			'inp'         => isset( $metrics['INTERACTION_TO_NEXT_PAINT']['percentile'] ) ? (int) $metrics['INTERACTION_TO_NEXT_PAINT']['percentile'] : null,
			'field_scope' => $scope,
			'strategy'    => $strategy,
			'checked'     => current_time( 'mysql' ),
		);
		return array( 'ok' => true, 'score' => max( 0, min( 100, $score ) ), 'cwv' => $cwv );
	}

	// =====================================================================
	// Historique (tendance)
	// =====================================================================

	/**
	 * La colonne seo_history.score_global arrive avec le schéma 1.8.8. Tant que la
	 * migration n'a pas tourné (elle attend la visite d'un administrateur), le
	 * cron ne doit ni l'écrire ni la lire : la requête échouerait entièrement.
	 */
	private function history_has_global() {
		return version_compare( (string) get_option( CU_OPTION_DB_VERSION, '0' ), '1.8.8', '>=' );
	}

	public function update_history_snapshot() {
		global $wpdb;
		$history = CU_DB::table( 'seo_history' );

		$agg = $this->site_aggregate();
		if ( null === $agg ) {
			CU_Logger::log( 'seo', 'error', __( 'Impossible de calculer l’historique SEO.', 'cyberpush-ultra' ) );
			return false;
		}
		if ( ! $agg['total'] ) {
			return true;
		}

		$row = array(
			'snap_date'       => current_time( 'Y-m-d' ),
			'score_avg'       => (int) round( (float) $agg['avg_score'] ),
			'pages_total'     => (int) $agg['total'],
			'indexable_count' => (int) $agg['indexable_crawled'],
			'issues_error'    => (int) $agg['err'],
			'issues_warning'  => (int) $agg['warn'],
			'issues_notice'   => (int) $agg['notice'],
			'created_at'      => current_time( 'mysql' ),
		);
		// Score global (le même que l'anneau de la page) : la courbe de tendance et
		// l'anneau affichent ainsi le même chiffre pour la même journée.
		if ( $this->history_has_global() && (int) $agg['crawled'] > 0 ) {
			$site_audit           = get_option( self::SITE_AUDIT_OPTION, array() );
			$model                = $this->score_model( $agg, is_array( $site_audit ) ? $site_audit : array() );
			$row['score_global'] = (int) $model['total'];
		}
		$replaced = $wpdb->replace( $history, $row );
		if ( false === $replaced ) {
			CU_Logger::log( 'seo', 'error', __( 'Impossible d’enregistrer l’historique SEO.', 'cyberpush-ultra' ), array( 'db_error' => CU_SEO_Renderer_truncate( $wpdb->last_error, 500 ) ) );
			return false;
		}
		return true;
	}

	/** Tâche cron : ré-analyse incrémentale + snapshot d'historique quotidien. */
	public static function run_scan_cycle() {
		$self = self::instance();
		if ( ! $self->enabled() || ! CU_DB::tables_exist() ) {
			return;
		}
		// Le verrou de WP-Cron expire après 60 s ; un cycle (crawl jusqu'à 45 s +
		// audit + Serper) peut le dépasser et être relancé en parallèle : pages
		// crawlées deux fois et quota Serper (payant) consommé en double.
		// Verrou ATOMIQUE (add_option) partagé avec les lots lancés depuis
		// l'administration : jamais deux analyses SEO en même temps.
		$token = $self->acquire_lock();
		if ( false === $token ) {
			return;
		}
		try {
			$self->discover_urls( 'auto' );
			$crawl = $self->crawl_batch( (int) CU_Settings::get( 'seo', 'crawl_batch_size', 8 ) );
			if ( is_wp_error( $crawl ) ) {
				// Extension DOM absente : une entrée de journal par jour, pas par heure.
				if ( 'cu_seo_dom_missing' !== $crawl->get_error_code() || false === get_transient( 'cu_seo_dom_missing_logged' ) ) {
					CU_Logger::log( 'seo', 'error', $crawl->get_error_message() );
					if ( 'cu_seo_dom_missing' === $crawl->get_error_code() ) {
						set_transient( 'cu_seo_dom_missing_logged', 1, DAY_IN_SECONDS );
					}
				}
				return;
			}
			$self->update_history_snapshot();
			if ( $self->site_audit_due( (int) $crawl['processed'] ) ) {
				$self->run_site_audit();
			}
			// Auto-vérification d'indexation (opt-in, throttlée, respecte le quota Serper).
			$self->maybe_auto_check_index();
		} finally {
			$self->release_lock( $token );
		}
	}

	/**
	 * L'audit transverse ne tourne en tâche planifiée que si des pages ont
	 * été (ré)analysées depuis, ou au moins une fois par jour : inutile de
	 * relire le graphe des liens toutes les heures sur un site qui ne bouge pas.
	 */
	private function site_audit_due( $processed ) {
		$audit = get_option( self::SITE_AUDIT_OPTION, array() );
		$at    = ( is_array( $audit ) && ! empty( $audit['generated_at'] ) ) ? strtotime( (string) $audit['generated_at'] ) : 0;
		$now   = strtotime( current_time( 'mysql' ) );
		if ( ! $at || ( $now - $at ) > DAY_IN_SECONDS ) {
			return true;
		}
		return $processed > 0 && ( $now - $at ) >= HOUR_IN_SECONDS;
	}

	// =====================================================================
	// Audit technique du site (transverse — nécessite le graphe complet)
	// =====================================================================

	const SITE_AUDIT_OPTION = 'cu_seo_site_audit';

	/**
	 * Analyse transverse : pages orphelines, profondeur de clic (BFS depuis
	 * l'accueil), titres/metas dupliqués, liens cassés, sitemap.xml, robots.txt.
	 * Le résultat est PERSISTÉ (option) → relu sans recalcul à chaque affichage.
	 *
	 * Mémoire BORNÉE (gros sites, méga-menus) : l'ancien code chargeait tout le
	 * graphe de liens en tableaux PHP (~110 Mo pour 200 000 liens). Désormais :
	 *  - les couples (source, cible) sont lus DÉDOUBLONNÉS par SQL, par tranches
	 *    de 100 pages sources, la cible déjà résolue en id de page ;
	 *  - chaque page reçoit un index entier ; liste d'adjacence = chaîne binaire
	 *    (4 octets par lien), liens entrants = 1 octet par page ;
	 *  - au-delà d'un plafond mémoire, l'audit s'arrête PROPREMENT et se déclare
	 *    « partiel » : orphelines et profondeur ne sont alors ni calculées ni
	 *    écrites (pas de chiffres faux), les autres contrôles restent valides.
	 */
	public function run_site_audit() {
		global $wpdb;
		$pages = CU_DB::table( 'seo_pages' );
		$links = CU_DB::table( 'seo_links' );

		// Ménage : anomalies et liens d'une page sortie de l'inventaire pendant
		// une analyse concurrente (ils gonflaient les totaux).
		$this->purge_orphan_rows();

		// --- 1. Inventaire compact : id de page ↔ index entier --------------
		$ids       = array();
		$idx_of    = array();
		$depth_old = '';
		$state     = '';
		$home_idx  = -1;
		$home_hash = md5( esc_url_raw( home_url( '/' ) ) );
		$last_id   = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, url_hash, depth, crawl_state FROM {$pages} WHERE id > %d ORDER BY id ASC LIMIT 5000",
				$last_id
			), ARRAY_N );
			if ( $wpdb->last_error || ! is_array( $rows ) ) {
				return array( 'success' => false, 'message' => __( 'Impossible de lire les pages pour l’audit technique.', 'cyberpush-ultra' ) );
			}
			foreach ( $rows as $r ) {
				$i               = count( $ids );
				$ids[]           = (int) $r[0];
				$idx_of[ (int) $r[0] ] = $i;
				$depth_old      .= chr( null === $r[2] ? 255 : min( 254, (int) $r[2] ) );
				// d = analysée, p = jamais analysée, x = erreur / non analysable.
				$state          .= 'done' === $r[3] ? 'd' : ( 'pending' === $r[3] ? 'p' : 'x' );
				if ( $r[1] === $home_hash ) {
					$home_idx = $i;
				}
				$last_id = (int) $r[0];
			}
			$wpdb->flush();
		} while ( 5000 === count( $rows ) );
		$n = count( $ids );
		if ( 0 === $n ) {
			return array( 'success' => false, 'message' => __( 'Aucune page dans l\'inventaire. Lancez d\'abord une analyse.', 'cyberpush-ultra' ) );
		}

		// --- 2. Graphe des liens internes (couples distincts, par tranches) -
		$pos        = 0;
		$sql_failed = false;
		$next_chunk = function () use ( &$pos, &$sql_failed, $ids, $idx_of, $n, $links, $pages ) {
			global $wpdb;
			if ( $pos >= $n ) {
				return null;
			}
			$from = $ids[ $pos ];
			$to   = $ids[ min( $n, $pos + 100 ) - 1 ];
			$pos += 100;
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT l.source_page_id, p.id FROM {$links} l
				 INNER JOIN {$pages} p ON p.url_hash = l.url_hash
				 WHERE l.type = 'internal' AND l.source_page_id BETWEEN %d AND %d",
				$from,
				$to
			), ARRAY_N );
			if ( $wpdb->last_error || ! is_array( $rows ) ) {
				$sql_failed = true;
				return null;
			}
			$pairs = array(); // [ source, cible, source, cible… ] (index entiers)
			foreach ( $rows as $r ) {
				$s = (int) $r[0];
				$t = (int) $r[1];
				if ( isset( $idx_of[ $s ], $idx_of[ $t ] ) ) {
					$pairs[] = $idx_of[ $s ];
					$pairs[] = $idx_of[ $t ];
				}
			}
			$wpdb->flush();
			return $pairs;
		};
		$graph = self::compute_link_graph( $n, $home_idx, $next_chunk );
		if ( $sql_failed ) {
			return array( 'success' => false, 'message' => __( 'Impossible de lire le graphe de liens SEO.', 'cyberpush-ultra' ) );
		}
		$partial = $graph['partial'];
		if ( $partial ) {
			CU_Logger::log( 'seo', 'warning', __( 'Audit technique partiel : graphe de liens trop volumineux pour la mémoire disponible.', 'cyberpush-ultra' ), array(
				'pages' => $n,
				'liens' => $graph['edges'],
			) );
		}

		// --- 3. Profondeur (écrite seulement si le graphe est complet) ------
		$max_depth_setting = (int) CU_Settings::get( 'seo', 'max_crawl_depth', 3 );
		$deep_ids   = array();
		$deep_count = 0;
		$max_depth  = 0;
		if ( ! $partial ) {
			$changes = array();
			for ( $i = 0; $i < $n; $i++ ) {
				$new = $graph['depth'][ $i ];
				if ( $new !== $depth_old[ $i ] ) {
					$changes[ ord( $new ) ][] = $ids[ $i ];
				}
				if ( "\xFF" === $new ) {
					continue;
				}
				$d         = ord( $new );
				$max_depth = max( $max_depth, $d );
				if ( $d > $max_depth_setting && 'd' === $state[ $i ] ) {
					$deep_count++;
					if ( count( $deep_ids ) < 100 ) {
						$deep_ids[ $ids[ $i ] ] = $d;
					}
				}
			}
			// Écritures groupées par valeur (NULL = inatteignable) : quelques
			// requêtes au lieu d'une par page modifiée.
			foreach ( $changes as $d => $list ) {
				$set = 255 === (int) $d ? 'NULL' : (string) (int) $d;
				foreach ( array_chunk( $list, 500 ) as $chunk ) {
					$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
					if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$pages} SET depth = {$set} WHERE id IN ( {$ph} )", $chunk ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						return array( 'success' => false, 'message' => __( 'Impossible d’enregistrer la profondeur des pages.', 'cyberpush-ultra' ) );
					}
				}
			}
		}

		// --- 4. Orphelines : pages analysées sans aucun lien interne entrant -
		$orphan_ids   = array();
		$orphan_all   = array();
		$orphan_count = 0;
		if ( ! $partial ) {
			for ( $i = 0; $i < $n; $i++ ) {
				if ( $i === $home_idx || 'd' !== $state[ $i ] || "\0" !== $graph['inbound'][ $i ] ) {
					continue;
				}
				$orphan_count++;
				$orphan_all[] = $ids[ $i ];
				if ( count( $orphan_ids ) < 100 ) {
					$orphan_ids[] = $ids[ $i ];
				}
			}
		}
		// Pages en erreur (délai, 5xx…) jamais lues : leurs liens sortants sont
		// INCONNUS. Les pages qu'elles lient peuvent sembler orphelines ou plus
		// profondes qu'en réalité : ces résultats ne sont alors pas affirmés.
		$unread_sources = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$pages} p WHERE p.crawl_state = 'error'
			 AND NOT EXISTS ( SELECT 1 FROM {$links} l WHERE l.source_page_id = p.id )" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$uncertain = ! $partial && $unread_sources > 0;
		// Orphelines qui n'ont pas non plus de lien interne sortant : comptées une
		// seule fois dans « Renforcer le maillage interne ».
		$orphans_no_outlinks = 0;
		foreach ( array_chunk( $orphan_all, 500 ) as $chunk ) {
			$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$orphans_no_outlinks += (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT( DISTINCT page_id ) FROM ' . CU_DB::table( 'seo_issues' ) . " WHERE code = 'no_internal_links' AND page_id IN ( {$ph} )", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$chunk
			) );
		}
		unset( $orphan_all );
		$details    = $this->page_details( array_merge( $orphan_ids, array_keys( $deep_ids ) ) );
		$orphans    = array();
		$deep_pages = array();
		foreach ( $orphan_ids as $id ) {
			if ( isset( $details[ $id ] ) ) {
				$orphans[] = array( 'url' => $details[ $id ]['url'], 'title' => $details[ $id ]['title'] );
			}
		}
		foreach ( $deep_ids as $id => $d ) {
			if ( isset( $details[ $id ] ) ) {
				$deep_pages[] = array( 'url' => $details[ $id ]['url'], 'depth' => $d );
			}
		}
		$graph_edges = (int) $graph['edges'];
		unset( $graph, $idx_of, $details );

		// --- Titres dupliqués (pages réellement analysées) ---
		$dup_titles = $wpdb->get_results(
			"SELECT title, COUNT(*) AS c FROM {$pages} WHERE crawl_state = 'done' AND title <> '' GROUP BY title HAVING c > 1 ORDER BY c DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = $wpdb->last_error || ! is_array( $dup_titles );

		// --- Meta descriptions dupliquées ---
		$dup_metas = $wpdb->get_results(
			"SELECT meta_desc, COUNT(*) AS c FROM {$pages} WHERE crawl_state = 'done' AND meta_desc <> '' AND meta_desc IS NOT NULL GROUP BY meta_desc HAVING c > 1 ORDER BY c DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = $read_failed || $wpdb->last_error || ! is_array( $dup_metas );

		// --- Liens cassés (déjà testés par check-links) ---
		// url/http_status agrégés (MIN/MAX) pour compatibilité ONLY_FULL_GROUP_BY.
		$broken = $wpdb->get_results(
			"SELECT MIN(l.url) AS url, MAX(l.http_status) AS http_status, MIN(p.url) AS source FROM {$links} l
			 INNER JOIN {$pages} p ON p.id = l.source_page_id
			 WHERE l.checked_at IS NOT NULL AND ( l.http_status = 0 OR l.http_status >= 400 )
			 GROUP BY l.url_hash ORDER BY http_status DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = $read_failed || $wpdb->last_error || ! is_array( $broken );
		// Nombre RÉEL de liens cassés distincts (la liste, elle, est bornée à 200).
		$broken_total = (int) $wpdb->get_var(
			"SELECT COUNT( DISTINCT l.url_hash ) FROM {$links} l INNER JOIN {$pages} p ON p.id = l.source_page_id
			 WHERE l.checked_at IS NOT NULL AND ( l.http_status = 0 OR l.http_status >= 400 )" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = $read_failed || $wpdb->last_error;
		$links_unchecked = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT url_hash ) FROM {$links} WHERE checked_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_failed = $read_failed || $wpdb->last_error;
		if ( $read_failed ) {
			return array( 'success' => false, 'message' => __( 'Impossible de terminer les contrôles SQL de l’audit technique.', 'cyberpush-ultra' ) );
		}

		// --- robots.txt puis sitemap (les sitemaps déclarés dans robots.txt
		// sont essayés en premier) ---
		$sample  = $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$pages} WHERE crawl_state = 'done' AND url_hash <> %s ORDER BY id DESC LIMIT 1", $home_hash ) );
		$robots  = $this->check_robots( $sample ? (string) $sample : '' );
		$sitemap = $this->check_sitemap( isset( $robots['sitemaps'] ) ? $robots['sitemaps'] : array() );

		$result = array(
			'generated_at'    => current_time( 'mysql' ),
			'pages_total'     => $n,
			'partial'         => $partial,
			'partial_reason'  => $partial ? __( 'Graphe de liens trop volumineux pour la mémoire PHP disponible : pages orphelines et profondeur non calculées (aucun chiffre approximatif affiché). Les autres contrôles sont complets.', 'cyberpush-ultra' ) : '',
			'uncrawled'       => substr_count( $state, 'p' ),
			'links_graph'     => $graph_edges,
			'orphans'         => $orphans,
			// Pages non lues : orphelines seulement « probables » (non comptées
			// comme un fait, ni dans le score, ni dans le plan d'action).
			'orphans_count'   => ( $partial || $uncertain ) ? null : $orphan_count,
			'orphans_uncertain' => $uncertain,
			'orphans_candidates' => $partial ? null : $orphan_count,
			'orphans_no_outlinks' => $uncertain ? 0 : $orphans_no_outlinks,
			'unread_sources'  => $unread_sources,
			'incomplete'      => $uncertain,
			'incomplete_reason' => $uncertain ? sprintf(
				/* translators: %d: nombre de pages */
				_n( '%d page n’a pas pu être lue (délai ou erreur serveur) : ses liens sont inconnus. Les pages orphelines et la profondeur de clic ne peuvent pas être confirmées ; relancez l’analyse de cette page.', '%d pages n’ont pas pu être lues (délai ou erreur serveur) : leurs liens sont inconnus. Les pages orphelines et la profondeur de clic ne peuvent pas être confirmées ; relancez l’analyse de ces pages.', $unread_sources, 'cyberpush-ultra' ),
				$unread_sources
			) : '',
			'deep_pages'      => $deep_pages,
			'deep_count'      => $partial ? null : $deep_count,
			'max_depth'       => $partial ? null : $max_depth,
			'depth_available' => ! $partial && $home_idx >= 0,
			'dup_titles'      => $dup_titles,
			'dup_titles_count' => count( (array) $dup_titles ),
			'dup_metas'       => $dup_metas,
			'dup_metas_count' => count( (array) $dup_metas ),
			'broken'          => $broken,
			'broken_count'    => max( count( (array) $broken ), $broken_total ),
			'links_unchecked' => $links_unchecked,
			'sitemap'         => $sitemap,
			'robots'          => $robots,
		);

		update_option( self::SITE_AUDIT_OPTION, $result, false );
		return array_merge( array( 'success' => true ), $result );
	}

	/** Plafond des liens internes distincts gardés en mémoire (4 octets chacun). */
	const GRAPH_MAX_EDGES = 5000000;

	/**
	 * Calcule liens entrants + profondeur de clic (BFS) sur un graphe fourni
	 * par tranches, en mémoire compacte. Pure (testable hors WordPress).
	 *
	 * @param int      $n          Nombre de pages (index 0..n-1).
	 * @param int      $home_idx   Index de l'accueil (-1 si absent).
	 * @param callable $next_chunk Renvoie une liste plate [s, t, s, t…] ou null à la fin.
	 * @param int      $mem_cap    Plafond absolu memory_get_usage() (0 = automatique).
	 * @return array{inbound:string, depth:string, edges:int, partial:bool}
	 *         inbound : 1 octet par page ("\0" = aucun lien entrant) ;
	 *         depth   : 1 octet par page (255 = inatteignable ou non calculé).
	 */
	public static function compute_link_graph( $n, $home_idx, $next_chunk, $mem_cap = 0 ) {
		$n        = (int) $n;
		$inbound  = str_repeat( "\0", max( 0, $n ) );
		$depth    = str_repeat( "\xFF", max( 0, $n ) );
		$adj      = array();
		$edges    = 0;
		$partial  = false;
		$mem_cap  = $mem_cap > 0 ? (int) $mem_cap : self::graph_memory_cap();

		while ( null !== ( $pairs = call_user_func( $next_chunk ) ) ) {
			$by_src = array();
			$count  = count( $pairs );
			for ( $k = 0; $k + 1 < $count; $k += 2 ) {
				$s = $pairs[ $k ];
				$t = $pairs[ $k + 1 ];
				if ( $s === $t || $s < 0 || $t < 0 || $s >= $n || $t >= $n ) {
					continue; // lien d'une page vers elle-même : ni entrant ni chemin
				}
				$by_src[ $s ][] = $t;
				$inbound[ $t ]  = "\1";
			}
			unset( $pairs );
			foreach ( $by_src as $s => $targets ) {
				$packed     = pack( 'V*', ...$targets );
				$adj[ $s ]  = isset( $adj[ $s ] ) ? $adj[ $s ] . $packed : $packed;
				$edges     += count( $targets );
			}
			unset( $by_src );
			if ( memory_get_usage() > $mem_cap || $edges > self::GRAPH_MAX_EDGES ) {
				$partial = true;
				break;
			}
		}

		if ( ! $partial && $home_idx >= 0 && $home_idx < $n ) {
			$depth[ $home_idx ] = "\0";
			$queue = array( $home_idx );
			// Curseur plutôt qu'array_shift() (O(n) par appel) : BFS linéaire.
			for ( $head = 0; isset( $queue[ $head ] ); $head++ ) {
				$cur = $queue[ $head ];
				if ( ! isset( $adj[ $cur ] ) ) {
					continue;
				}
				$next = chr( min( 254, ord( $depth[ $cur ] ) + 1 ) );
				foreach ( unpack( 'V*', $adj[ $cur ] ) as $t ) {
					if ( "\xFF" === $depth[ $t ] ) {
						$depth[ $t ] = $next;
						$queue[]     = $t;
					}
				}
			}
		}
		if ( $partial ) {
			$inbound = str_repeat( "\0", max( 0, $n ) ); // non fiable : jamais exploité
		}
		return array( 'inbound' => $inbound, 'depth' => $depth, 'edges' => $edges, 'partial' => $partial );
	}

	/** Plafond mémoire de l'audit : la moitié de la marge restante (min. 8 Mo). */
	private static function graph_memory_cap() {
		$used  = memory_get_usage();
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		if ( $limit <= 0 ) {
			return $used + 256 * 1048576;
		}
		return $used + max( 8 * 1048576, (int) ( ( $limit - $used ) * 0.5 ) );
	}

	/** url + titre d'une liste (courte) de pages, par id. */
	private function page_details( $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, url, title FROM ' . CU_DB::table( 'seo_pages' ) . " WHERE id IN ( {$ph} )", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['id'] ] = array( 'url' => (string) $r['url'], 'title' => (string) $r['title'] );
		}
		return $out;
	}

	/** Hôte + port de l'accueil (comparaison des URL déclarées dans robots.txt). */
	private static function home_origin_key() {
		$p = wp_parse_url( home_url( '/' ) );
		return strtolower( isset( $p['host'] ) ? (string) $p['host'] : '' ) . ':' . ( isset( $p['port'] ) ? (int) $p['port'] : 0 );
	}

	/**
	 * Cherche un sitemap XML valide : d'abord ceux déclarés dans robots.txt
	 * (même hôte et port que le site), puis les emplacements usuels — cœur
	 * WordPress (wp-sitemap.xml), Yoast / Rank Math (sitemap_index.xml),
	 * générique (sitemap.xml). Une réponse 200 qui n'est PAS du XML de sitemap
	 * (page HTML « introuvable » servie en 200) est rejetée.
	 */
	private function check_sitemap( $declared = array() ) {
		$home_key   = self::home_origin_key();
		$candidates = array();
		foreach ( (array) $declared as $u ) {
			$p = wp_parse_url( (string) $u );
			if ( is_array( $p ) && ! empty( $p['host'] ) && strtolower( $p['host'] ) . ':' . ( isset( $p['port'] ) ? (int) $p['port'] : 0 ) === $home_key ) {
				$candidates[] = esc_url_raw( (string) $u );
			}
		}
		$candidates = array_merge( $candidates, array( home_url( '/wp-sitemap.xml' ), home_url( '/sitemap_index.xml' ), home_url( '/sitemap.xml' ) ) );
		$candidates = array_slice( array_values( array_unique( array_filter( $candidates ) ) ), 0, 5 );
		$rejected   = array();
		foreach ( $candidates as $url ) {
			$resp = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 3, 'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), 'limit_response_size' => 5 * MB_IN_BYTES ) );
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				continue;
			}
			$body = (string) wp_remote_retrieve_body( $resp );
			$info = self::inspect_sitemap( $body );
			if ( ! $info['valid'] ) {
				$rejected[] = $url;
				continue;
			}
			return array(
				'found'     => true,
				'url'       => $url,
				'type'      => $info['type'],
				'entries'   => $info['entries'],
				'truncated' => strlen( $body ) >= 5 * MB_IN_BYTES,
				'rejected'  => $rejected,
			);
		}
		return array( 'found' => false, 'url' => '', 'type' => '', 'entries' => 0, 'truncated' => false, 'rejected' => $rejected );
	}

	/**
	 * Nature d'un sitemap : index (N sous-sitemaps) ou urlset (N URL).
	 *
	 * @return array{valid:bool, type:string, entries:int}
	 */
	public static function inspect_sitemap( $body ) {
		$body = (string) $body;
		$head = substr( $body, 0, 4000 );
		$head = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $head );
		// Prologue admis avant la racine : déclaration XML, feuille XSL, commentaires.
		$head = (string) preg_replace( '/^(?:\s*<\?[^>]*\?>|\s*<!--.*?-->)*/s', '', $head );
		if ( preg_match( '/^\s*<(?:[a-z0-9_-]+:)?sitemapindex[\s>]/i', $head ) ) {
			return array( 'valid' => true, 'type' => 'index', 'entries' => (int) preg_match_all( '/<(?:[a-z0-9_-]+:)?sitemap[\s>]/i', $body ) );
		}
		if ( preg_match( '/^\s*<(?:[a-z0-9_-]+:)?urlset[\s>]/i', $head ) ) {
			return array( 'valid' => true, 'type' => 'urlset', 'entries' => (int) preg_match_all( '/<(?:[a-z0-9_-]+:)?url[\s>]/i', $body ) );
		}
		return array( 'valid' => false, 'type' => '', 'entries' => 0 );
	}

	/**
	 * Contrôle robots.txt : présence, blocage RÉEL de l'accueil et d'une page
	 * de contenu pour Googlebot et pour les robots génériques (*), directives
	 * Sitemap. RÈGLE : les moteurs ne lisent robots.txt QU'À la racine de
	 * l'hôte (RFC 9309), port compris : pour une install en sous-dossier
	 * (domaine.com/pito/), c'est domaine.com/robots.txt qui compte.
	 */
	private function check_robots( $sample_url = '' ) {
		$parts  = wp_parse_url( home_url( '/' ) );
		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = ! empty( $parts['host'] ) ? $parts['host'] : '';
		$port   = ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$url    = $host ? $scheme . '://' . $host . $port . '/robots.txt' : home_url( '/robots.txt' );

		$home_path = ! empty( $parts['path'] ) ? (string) $parts['path'] : '/';
		$paths     = array( $home_path );
		if ( '' !== $sample_url ) {
			$sp = wp_parse_url( $sample_url );
			if ( is_array( $sp ) ) {
				$sample_path = ( isset( $sp['path'] ) ? (string) $sp['path'] : '/' ) . ( isset( $sp['query'] ) ? '?' . $sp['query'] : '' );
				if ( $sample_path !== $home_path ) {
					$paths[] = $sample_path;
				}
			}
		}

		$out = array(
			'found'          => false,
			'url'            => $url,
			'status'         => 0,
			'error'          => '',
			'blocks_all'     => false,
			'blocks_google'  => false,
			'blocks_generic' => false,
			'blocked'        => array(),
			'checked_paths'  => $paths,
			'has_sitemap'    => false,
			'sitemaps'       => array(),
		);
		$resp = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 3, 'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), 'limit_response_size' => 512 * KB_IN_BYTES ) );
		if ( is_wp_error( $resp ) ) {
			$out['error'] = 'unreachable'; // vérification impossible, pas « absent »
			return $out;
		}
		$code          = (int) wp_remote_retrieve_response_code( $resp );
		$out['status'] = $code;
		if ( $code >= 500 ) {
			// Google suspend l'exploration tant que robots.txt répond en 5xx.
			$out['error'] = 'server';
			return $out;
		}
		if ( 200 !== $code ) {
			return $out; // 4xx : fichier absent = aucune restriction
		}
		$body         = (string) wp_remote_retrieve_body( $resp );
		$out['found'] = true;
		$groups       = self::parse_robots( $body );
		foreach ( array( 'googlebot' => 'Googlebot', '*' => '*' ) as $agent => $label ) {
			foreach ( $paths as $path ) {
				$verdict = self::robots_allows( $groups, $agent, $path );
				if ( $verdict['allowed'] ) {
					continue;
				}
				$out['blocked'][] = array( 'agent' => $label, 'path' => $path, 'rule' => $verdict['rule'] );
				if ( $path === $home_path ) {
					$out[ 'googlebot' === $agent ? 'blocks_google' : 'blocks_generic' ] = true;
				}
			}
		}
		$out['blocks_all'] = $out['blocks_google'] || $out['blocks_generic'];
		if ( preg_match_all( '/^\s*Sitemap\s*:\s*(\S+)/im', $body, $m ) ) {
			$out['sitemaps'] = array_slice( array_values( array_unique( array_map( 'esc_url_raw', $m[1] ) ) ), 0, 10 );
		}
		$out['has_sitemap'] = ! empty( $out['sitemaps'] );
		return $out;
	}

	/**
	 * Découpe robots.txt en groupes { agents[], rules[ {allow, path} ] } selon
	 * la RFC 9309 : des lignes user-agent consécutives forment un groupe ; les
	 * lignes Sitemap n'appartiennent à aucun groupe ; un Disallow vide n'est
	 * pas une règle (il autorise tout).
	 */
	public static function parse_robots( $body ) {
		$groups    = array();
		$current   = null;
		$in_agents = false;
		$body      = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $body );
		foreach ( preg_split( '/\R/', $body ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', (string) $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( null === $current || ! $in_agents ) {
					if ( null !== $current ) {
						$groups[] = $current;
					}
					$current = array( 'agents' => array(), 'rules' => array() );
				}
				// Jeton produit seulement : « Googlebot/2.1 » → « googlebot ».
				$token = strtolower( trim( (string) strtok( $value, '/ ' ) ) );
				if ( '' !== $token ) {
					$current['agents'][] = $token;
				}
				$in_agents = true;
				continue;
			}
			if ( 'sitemap' === $field || null === $current ) {
				continue;
			}
			$in_agents = false;
			if ( ( 'allow' === $field || 'disallow' === $field ) && '' !== $value ) {
				$current['rules'][] = array( 'allow' => 'allow' === $field, 'path' => $value );
			}
		}
		if ( null !== $current ) {
			$groups[] = $current;
		}
		return $groups;
	}

	/**
	 * Le chemin est-il autorisé pour ce robot ? Groupes du robot (sinon « * »),
	 * règle la plus LONGUE qui correspond ; à longueur égale, Allow l'emporte
	 * (comportement documenté de Google). Jokers « * » et ancre « $ » gérés.
	 *
	 * @return array{allowed:bool, rule:string}
	 */
	public static function robots_allows( $groups, $agent, $path ) {
		$agent   = strtolower( (string) $agent );
		$rules   = array();
		$matched = false;
		if ( '*' !== $agent ) {
			foreach ( (array) $groups as $g ) {
				if ( in_array( $agent, $g['agents'], true ) ) {
					$rules   = array_merge( $rules, $g['rules'] );
					$matched = true;
				}
			}
		}
		if ( ! $matched ) {
			foreach ( (array) $groups as $g ) {
				if ( in_array( '*', $g['agents'], true ) ) {
					$rules = array_merge( $rules, $g['rules'] );
				}
			}
		}
		$best = null;
		$len  = -1;
		foreach ( $rules as $r ) {
			if ( ! self::robots_rule_matches( $r['path'], (string) $path ) ) {
				continue;
			}
			$l = strlen( $r['path'] );
			if ( $l > $len || ( $l === $len && $r['allow'] && ! $best['allow'] ) ) {
				$best = $r;
				$len  = $l;
			}
		}
		return array(
			'allowed' => null === $best || $best['allow'],
			'rule'    => null === $best ? '' : ( $best['allow'] ? 'Allow: ' : 'Disallow: ' ) . $best['path'],
		);
	}

	/** Correspondance d'un motif robots.txt (préfixe, « * », « $ » final). */
	public static function robots_rule_matches( $pattern, $path ) {
		$pattern  = (string) $pattern;
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		$re = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
		return 1 === preg_match( $re, (string) $path );
	}

	// =====================================================================
	// Hooks contenu
	// =====================================================================

	public function on_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( get_post_type( $post_id ), (array) $this->tracked_post_types(), true ) ) {
			return;
		}
		if ( ! CU_DB::tables_exist() ) {
			return;
		}
		// Même normalisation que discover_urls() : sinon le hash différerait et
		// la même page apparaîtrait deux fois dans l'inventaire.
		$url = 'publish' === get_post_status( $post_id ) ? esc_url_raw( (string) get_permalink( $post_id ) ) : '';
		if ( ! $url || $this->is_excluded_url( $url ) ) {
			// Dépublié (brouillon, privé, programmé) ou désormais exclu : son
			// ancienne URL renverrait une 404 et fausserait les scores du site.
			$this->forget_post( $post_id );
			return;
		}
		if ( strlen( $url ) > self::MAX_URL_LENGTH ) {
			// Non stockable sans troncature (colonne VARCHAR(500)) : ignorée.
			$this->forget_post( $post_id );
			CU_Logger::log( 'seo', 'warning', sprintf(
				/* translators: %d: max length */
				__( 'URL de plus de %d caractères ignorée par l’audit SEO.', 'cyberpush-ultra' ),
				self::MAX_URL_LENGTH
			), array( 'post_id' => (int) $post_id ) );
			return;
		}
		global $wpdb;
		$table    = CU_DB::table( 'seo_pages' );
		$hash     = md5( $url );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", $hash ) );
		// Changement de slug : on réutilise la ligne du contenu au lieu de laisser
		// l'ancienne URL (devenue une redirection) dans l'inventaire.
		$own = $existing ? null : $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d ORDER BY id ASC LIMIT 1", (int) $post_id ) );
		if ( $wpdb->last_error ) {
			CU_Logger::log( 'seo', 'error', __( 'Impossible de mettre en file le contenu SEO modifié.', 'cyberpush-ultra' ) );
			return;
		}
		if ( $existing ) {
			$result = $wpdb->update( $table, array( 'crawl_state' => 'pending', 'post_id' => (int) $post_id ), array( 'id' => (int) $existing ) );
		} elseif ( $own ) {
			$result = $wpdb->update( $table, array(
				'url'         => $url,
				'url_hash'    => $hash,
				'crawl_state' => 'pending',
			), array( 'id' => (int) $own ) );
		} else {
			$result = $wpdb->insert( $table, array(
				'post_id'     => (int) $post_id,
				'url'         => $url,
				'url_hash'    => $hash,
				'object_type' => get_post_type( $post_id ),
				'crawl_state' => 'pending',
				'created_at'  => current_time( 'mysql' ),
			) );
		}
		if ( false === $result ) {
			CU_Logger::log( 'seo', 'error', __( 'Impossible de mettre en file le contenu SEO modifié.', 'cyberpush-ultra' ), array( 'db_error' => CU_SEO_Renderer_truncate( $wpdb->last_error, 500 ) ) );
		}
	}

	/** Corbeille ou suppression définitive : le contenu sort de l'inventaire SEO. */
	public function on_trashed_post( $post_id ) {
		if ( ! CU_DB::tables_exist() ) {
			return;
		}
		$this->forget_post( $post_id );
	}

	/** Retire toutes les lignes SEO (page, anomalies, liens sortants) d'un contenu. */
	private function forget_post( $post_id ) {
		global $wpdb;
		$pages = CU_DB::table( 'seo_pages' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$pages} WHERE post_id = %d", (int) $post_id ) );
		$this->forget_page_ids( (array) $ids );
	}

	/**
	 * Terme sur le point d'être supprimé : son archive sort de l'inventaire
	 * (son lien est encore calculable à ce stade, plus après la suppression).
	 */
	public function on_pre_delete_term( $term_id, $taxonomy ) {
		if ( ! CU_DB::tables_exist() ) {
			return;
		}
		$term = get_term( (int) $term_id, (string) $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) || ! $link ) {
			return;
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . CU_DB::table( 'seo_pages' ) . ' WHERE url_hash = %s AND ( post_id IS NULL OR post_id = 0 )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			md5( esc_url_raw( (string) $link ) )
		) );
		$this->forget_page_ids( (array) $ids );
	}


	// =====================================================================
	// Endpoints REST
	// =====================================================================

	public function rest_overview() {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		global $wpdb;
		$issues  = CU_DB::table( 'seo_issues' );
		$history = CU_DB::table( 'seo_history' );

		$agg = $this->site_aggregate();
		if ( null === $agg ) {
			return new WP_Error( 'cu_seo_overview_failed', __( 'Impossible de lire l’audit SEO.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		// NB : on agrège category/severity/message avec MIN() pour rester
		// compatible avec le mode SQL ONLY_FULL_GROUP_BY (défaut MySQL 5.7+/MariaDB).
		// « pages » : une même anomalie peut être relevée plusieurs fois sur une page.
		// Jointure sur les pages : une anomalie dont la page a disparu (analyse
		// concurrente d'une mise à la corbeille) ne gonfle pas les totaux.
		$pages_t = CU_DB::table( 'seo_pages' );
		$top = $wpdb->get_results(
			"SELECT i.code AS code, MIN(i.category) AS category, MIN(i.severity) AS severity, MIN(i.message) AS message, COUNT(*) AS total, COUNT( DISTINCT i.page_id ) AS pages
			 FROM {$issues} i INNER JOIN {$pages_t} p ON p.id = i.page_id GROUP BY i.code ORDER BY
			 FIELD( MIN(i.severity), 'error', 'warning', 'notice' ), total DESC LIMIT 8", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 90 relevés (un par jour) : la courbe propose 30 jours ou 90 jours.
		$global_col = $this->history_has_global() ? 'score_global' : 'NULL AS score_global';
		$trend = $wpdb->get_results(
			"SELECT snap_date, score_avg, {$global_col}, pages_total, indexable_count, issues_error, issues_warning, issues_notice, created_at FROM {$history} ORDER BY snap_date DESC LIMIT 90", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$trend = array_reverse( (array) $trend );

		// Plan d'action : nombre de PAGES distinctes concernées par code + audit
		// technique. Les recommandations qui regroupent plusieurs codes (titre ou
		// description absents, H1) comptent chaque page une seule fois.
		$code_rows = $wpdb->get_results( "SELECT i.code AS code, COUNT( DISTINCT i.page_id ) AS c FROM {$issues} i INNER JOIN {$pages_t} p ON p.id = i.page_id GROUP BY i.code", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$code_counts = array();
		foreach ( (array) $code_rows as $cr ) {
			$code_counts[ $cr['code'] ] = (int) $cr['c'];
		}
		foreach ( self::reco_code_groups() as $group => $group_codes ) {
			if ( count( $group_codes ) < 2 ) {
				continue;
			}
			$ph = implode( ',', array_fill( 0, count( $group_codes ), '%s' ) );
			$code_counts[ '@' . $group ] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT( DISTINCT i.page_id ) FROM {$issues} i INNER JOIN {$pages_t} p ON p.id = i.page_id WHERE i.code IN ( {$ph} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$group_codes
			) );
		}
		$site_audit = get_option( self::SITE_AUDIT_OPTION, array() );
		$recommendations = CU_SEO_Recommender::build( $code_counts, is_array( $site_audit ) ? $site_audit : array(), (int) $agg['crawled'] );

		// Centre de santé SEO (sous-scores honnêtes ; Autorité = backlinks, à venir).
		// Aucune page analysée → aucune mesure : null (« n/d ») et non un faux 0/100.
		$has_crawl = (int) $agg['crawled'] > 0;
		$health = array(
			'technique'   => $has_crawl ? (int) round( (float) $agg['avg_technical'] ) : null,
			'contenu'     => $has_crawl ? (int) round( (float) $agg['avg_content'] ) : null,
			'balises'     => $has_crawl ? (int) round( (float) $agg['avg_meta'] ) : null,
			'performance' => ( null !== $agg['avg_perf'] ) ? (int) round( (float) $agg['avg_perf'] ) : null,
			'ai_ready'    => $has_crawl ? (int) round( (float) $agg['avg_ai_ready'] ) : null,
			'autorite'    => null, // nécessite des données de backlinks (DataForSEO — à venir)
		);

		$score_model = $this->score_model( $agg, is_array( $site_audit ) ? $site_audit : array() );

		return rest_ensure_response( array(
			'enabled'      => $this->enabled(),
			'total'        => (int) $agg['total'],
			'crawled'      => (int) $agg['crawled'],
			'pending'      => (int) $agg['pending'],
			'avg_score'    => (int) round( (float) $agg['avg_score'] ),
			'categories'   => array(
				'content'   => (int) round( (float) $agg['avg_content'] ),
				'meta'      => (int) round( (float) $agg['avg_meta'] ),
				'technical' => (int) round( (float) $agg['avg_technical'] ),
			),
			'distribution' => array(
				'good'   => (int) $agg['good'],
				'medium' => (int) $agg['medium'],
				'poor'   => (int) $agg['poor'],
			),
			'issues'       => array(
				'error'   => (int) $agg['err'],
				'warning' => (int) $agg['warn'],
				'notice'  => (int) $agg['notice'],
			),
			// Pages touchées par sévérité (une page compte une fois par sévérité),
			// parmi les pages dont l'analyse est à jour (`analysed`, même population
			// que le numérateur : une page repassée « en attente » garde ses
			// anciennes anomalies et faisait dépasser 100 %).
			'issue_pages'  => array(
				'error'   => (int) $agg['pages_err'],
				'warning' => (int) $agg['pages_warn'],
				'notice'  => (int) $agg['pages_notice'],
			),
			'analysed'     => (int) $agg['analysed'],
			'indexable'    => (int) $agg['indexable_crawled'],
			'failed'       => (int) $agg['failed'],
			'http_errors'  => (int) $agg['http_errors'],
			'skipped'      => (int) $agg['skipped'],
			// Extension DOM absente : aucune page ne peut être analysée (bandeau clair).
			'dom_missing'  => ! self::dom_available(),
			'ai_configured' => CU_AI::is_configured(),
			'last_crawled' => $agg['last_crawled'],
			'top_issues'   => $top,
			'trend'        => $trend,
			'health'       => $health,
			'score_model'  => $score_model,
			'recommendations' => $recommendations,
			'psi_enabled'  => (bool) CU_Settings::get( 'seo', 'psi_enabled', false ) && '' !== trim( (string) CU_Settings::get( 'seo', 'psi_api_key', '' ) ),
		) );
	}

	/**
	 * Agrégats de l'inventaire SEO (une seule requête), partagés par l'aperçu et
	 * par le relevé quotidien de l'historique. null en cas d'erreur SQL.
	 *
	 * @return array<string, mixed>|null
	 */
	private function site_aggregate() {
		global $wpdb;
		$pages = CU_DB::table( 'seo_pages' );
		$agg   = $wpdb->get_row( "SELECT
			COUNT(*) AS total,
			SUM( crawl_state = 'done' ) AS crawled,
			SUM( crawl_state = 'pending' ) AS pending,
			SUM( crawl_state IN ( 'error', 'http_error' ) ) AS failed,
			SUM( crawl_state = 'http_error' ) AS http_errors,
			SUM( crawl_state = 'skipped' ) AS skipped,
			AVG( CASE WHEN crawl_state = 'done' THEN score END ) AS avg_score,
			AVG( CASE WHEN crawl_state = 'done' THEN score_content END ) AS avg_content,
			AVG( CASE WHEN crawl_state = 'done' THEN score_meta END ) AS avg_meta,
			AVG( CASE WHEN crawl_state = 'done' THEN score_technical END ) AS avg_technical,
			AVG( CASE WHEN crawl_state = 'done' THEN ai_ready END ) AS avg_ai_ready,
			AVG( perf_score ) AS avg_perf,
			SUM( indexable ) AS indexable_count,
			SUM( crawl_state = 'done' AND indexable = 1 ) AS indexable_crawled,
			SUM( issues_error ) AS err,
			SUM( issues_warning ) AS warn,
			SUM( issues_notice ) AS notice,
			SUM( crawl_state <> 'pending' AND issues_error > 0 ) AS pages_err,
			SUM( crawl_state <> 'pending' AND issues_warning > 0 ) AS pages_warn,
			SUM( crawl_state <> 'pending' AND issues_notice > 0 ) AS pages_notice,
			SUM( crawl_state <> 'pending' ) AS analysed,
			SUM( crawl_state = 'done' AND score >= 80 ) AS good,
			SUM( crawl_state = 'done' AND score >= 50 AND score < 80 ) AS medium,
			SUM( crawl_state = 'done' AND score < 50 ) AS poor,
			MAX( last_crawled ) AS last_crawled
			FROM {$pages}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->last_error || ! is_array( $agg ) ) {
			return null;
		}
		return $agg;
	}

	/**
	 * Score EXPLICABLE : somme de composantes pondérées, chacune avec sa source
	 * et son niveau de fiabilité. Les composantes en attente de données
	 * (CTR/indexation réelle via GSC) sont marquées et exclues du calcul
	 * (repondération) plutôt qu'inventées.
	 *
	 * @param array<string, mixed> $agg        Agrégats de site_aggregate().
	 * @param array<string, mixed> $site_audit Dernier audit technique transverse.
	 * @return array{total: int, components: array<int, array<string, mixed>>, pending: array<int, string>}
	 */
	/** Codes d'anomalie derrière chaque recommandation du plan d'action. */
	private static function reco_code_groups() {
		return array(
			'meta_missing' => array( 'metadesc_missing', 'title_missing' ),
			'noindex'      => array( 'noindex' ),
			'thin_content' => array( 'thin_content' ),
			'schema'       => array( 'schema_missing' ),
			'headings'     => array( 'h1_missing', 'h1_multiple' ),
			'img_alt'      => array( 'img_no_alt' ),
			'keyword'      => array( 'kw_not_in_title', 'kw_not_in_h1', 'kw_not_in_desc' ),
		);
	}

	private function score_model( $agg, $site_audit ) {
		$crawled_n   = max( 1, (int) $agg['crawled'] );
		$orphan_ratio = ! empty( $site_audit['orphans_count'] ) ? min( 1, (int) $site_audit['orphans_count'] / $crawled_n ) : 0;
		$maillage    = isset( $site_audit['orphans_count'] ) ? (int) round( 100 - min( 100, $orphan_ratio * 300 ) ) : null;
		// Seules les pages déjà analysées ont un statut d'indexabilité réel (les
		// pages en attente valent 1 par défaut et feraient dépasser 100 %).
		$index_proxy = (int) min( 100, round( ( (int) $agg['indexable_crawled'] / $crawled_n ) * 100 ) );

		// Pondération « contenu-first » : le contenu et les balises pèsent le plus.
		// La technique et l'indexation (acquises d'office sur WordPress) sont du
		// minimum vital → poids faible pour ne pas gonfler la note d'un site vide.
		$components = array(
			array( 'key' => 'contenu', 'label' => __( 'Contenu', 'cyberpush-ultra' ), 'weight' => 24, 'value' => (int) round( (float) $agg['avg_content'] ), 'source' => 'wordpress', 'reliability' => 5 ),
			array( 'key' => 'balises', 'label' => __( 'Balises', 'cyberpush-ultra' ), 'weight' => 20, 'value' => (int) round( (float) $agg['avg_meta'] ), 'source' => 'wordpress', 'reliability' => 5 ),
			array( 'key' => 'performance', 'label' => __( 'Performance (CWV)', 'cyberpush-ultra' ), 'weight' => 16, 'value' => ( null !== $agg['avg_perf'] ) ? (int) round( (float) $agg['avg_perf'] ) : null, 'source' => 'psi', 'reliability' => 5 ),
			array( 'key' => 'ai_ready', 'label' => __( 'AI-Ready', 'cyberpush-ultra' ), 'weight' => 12, 'value' => (int) round( (float) $agg['avg_ai_ready'] ), 'source' => 'heuristique', 'reliability' => 3 ),
			array( 'key' => 'technique', 'label' => __( 'Technique', 'cyberpush-ultra' ), 'weight' => 10, 'value' => (int) round( (float) $agg['avg_technical'] ), 'source' => 'wordpress', 'reliability' => 5 ),
			array( 'key' => 'ctr', 'label' => __( 'CTR', 'cyberpush-ultra' ), 'weight' => 9, 'value' => null, 'source' => 'gsc', 'reliability' => 5 ),
			array( 'key' => 'maillage', 'label' => __( 'Maillage interne', 'cyberpush-ultra' ), 'weight' => 6, 'value' => $maillage, 'source' => 'wordpress', 'reliability' => 5 ),
			array( 'key' => 'indexation', 'label' => __( 'Indexation', 'cyberpush-ultra' ), 'weight' => 3, 'value' => $index_proxy, 'source' => 'wordpress', 'reliability' => 3 ),
		);
		$sum_w = 0; $sum_wv = 0; $pending = array();
		foreach ( $components as $comp ) {
			if ( null === $comp['value'] ) {
				$pending[] = $comp['key'];
			} else {
				$sum_w  += $comp['weight'];
				$sum_wv += $comp['weight'] * $comp['value'];
			}
		}
		return array(
			'total'      => $sum_w ? (int) round( $sum_wv / $sum_w ) : 0,
			'components' => $components,
			'pending'    => $pending,
		);
	}

	/**
	 * Liste les URL concernées par une recommandation du plan d'action.
	 * Chaque item du plan expose un `id` ; on le mappe soit vers un ou plusieurs
	 * codes d'anomalie (table seo_issues → URL de la page), soit vers une liste
	 * de l'audit technique (liens cassés, orphelines, pages profondes, doublons).
	 * On répond à la demande (au clic) pour ne pas alourdir l'aperçu.
	 */
	public function rest_reco_urls( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id    = sanitize_key( (string) $request->get_param( 'id' ) );
		$code  = sanitize_key( (string) $request->get_param( 'code' ) );
		$items = array();
		$total = null; // nombre RÉEL d'éléments (la liste affichée est plafonnée)

		// Accès direct par code d'anomalie (utilisé par « Problèmes les plus
		// fréquents » : chaque ligne porte son propre code).
		if ( '' !== $code ) {
			$items = $this->urls_for_codes( array( $code ), $total );
			return rest_ensure_response( array(
				'code'      => $code,
				'count'     => (int) $total,
				'items'     => array_slice( $items, 0, self::RECO_URLS_MAX ),
				'truncated' => (int) $total > count( $items ),
			) );
		}

		// Recommandations adossées directement à des codes d'anomalie par page.
		$code_map = self::reco_code_groups();

		if ( isset( $code_map[ $id ] ) ) {
			$items = $this->urls_for_codes( $code_map[ $id ], $total );
		} elseif ( 'broken_links' === $id ) {
			$audit = get_option( self::SITE_AUDIT_OPTION, array() );
			$total = isset( $audit['broken_count'] ) ? (int) $audit['broken_count'] : null;
			foreach ( (array) ( isset( $audit['broken'] ) ? $audit['broken'] : array() ) as $b ) {
				$status = isset( $b['http_status'] ) ? (int) $b['http_status'] : 0;
				$note   = ( $status ? 'HTTP ' . $status : __( 'injoignable', 'cyberpush-ultra' ) );
				if ( ! empty( $b['source'] ) ) {
					$note .= ' · ' . sprintf( __( 'lié depuis %s', 'cyberpush-ultra' ), $b['source'] );
				}
				$items[] = array( 'url' => isset( $b['url'] ) ? $b['url'] : '', 'note' => $note );
			}
		} elseif ( 'deep_pages' === $id ) {
			$audit = get_option( self::SITE_AUDIT_OPTION, array() );
			$total = isset( $audit['deep_count'] ) ? (int) $audit['deep_count'] : null;
			foreach ( (array) ( isset( $audit['deep_pages'] ) ? $audit['deep_pages'] : array() ) as $d ) {
				$items[] = array(
					'url'  => isset( $d['url'] ) ? $d['url'] : '',
					'note' => sprintf( __( '%d clics de l\'accueil', 'cyberpush-ultra' ), isset( $d['depth'] ) ? (int) $d['depth'] : 0 ),
				);
			}
		} elseif ( 'internal_linking' === $id ) {
			$audit     = get_option( self::SITE_AUDIT_OPTION, array() );
			$uncertain = ! empty( $audit['orphans_uncertain'] );
			$seen      = array();
			// Orphelines « probables » (pages non lues) : non affirmées, non listées ici.
			if ( ! $uncertain ) {
				foreach ( (array) ( isset( $audit['orphans'] ) ? $audit['orphans'] : array() ) as $o ) {
					$u = isset( $o['url'] ) ? $o['url'] : '';
					if ( '' === $u ) {
						continue;
					}
					$seen[ $u ] = true;
					$items[]    = array( 'url' => $u, 'note' => __( 'orpheline (aucun lien entrant)', 'cyberpush-ultra' ) );
				}
			}
			// Pages sans lien interne sortant (code d'anomalie), hors orphelines déjà listées.
			$no_out = 0;
			foreach ( $this->urls_for_codes( array( 'no_internal_links' ), $no_out ) as $m ) {
				if ( empty( $seen[ $m['url'] ] ) ) {
					$m['note'] = __( 'aucun lien interne sortant', 'cyberpush-ultra' );
					$items[]   = $m;
				}
			}
			$orphans = ( ! $uncertain && isset( $audit['orphans_count'] ) ) ? (int) $audit['orphans_count'] : 0;
			$overlap = ( ! $uncertain && isset( $audit['orphans_no_outlinks'] ) ) ? (int) $audit['orphans_no_outlinks'] : 0;
			$total   = max( count( $items ), $orphans + (int) $no_out - $overlap );
		} elseif ( 'duplicates' === $id ) {
			$items = $this->urls_for_duplicates( $total );
		}

		$total = null === $total ? count( $items ) : max( (int) $total, count( $items ) );
		return rest_ensure_response( array(
			'id'        => $id,
			'count'     => $total,
			'items'     => array_slice( $items, 0, self::RECO_URLS_MAX ),
			'truncated' => $total > min( count( $items ), self::RECO_URLS_MAX ),
		) );
	}

	/** Nombre maximal d'URL listées au clic (le total réel est toujours renvoyé). */
	const RECO_URLS_MAX = 500;

	/**
	 * URL (+ note) des pages portant au moins un des codes d'anomalie donnés.
	 * Une page n'apparaît qu'une fois même si elle cumule plusieurs codes.
	 *
	 * @param string[] $codes
	 * @param int|null $total Reçoit le nombre RÉEL de pages concernées (la liste est plafonnée).
	 */
	private function urls_for_codes( $codes, &$total = null ) {
		global $wpdb;
		$total = 0;
		$codes = array_values( array_filter( array_map( 'strval', (array) $codes ) ) );
		if ( empty( $codes ) ) {
			return array();
		}
		$pages  = CU_DB::table( 'seo_pages' );
		$issues = CU_DB::table( 'seo_issues' );
		$ph     = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$total  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT( DISTINCT i.page_id ) FROM {$issues} i INNER JOIN {$pages} p ON p.id = i.page_id WHERE i.code IN ($ph)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$codes
		) );
		$sql    = $wpdb->prepare(
			"SELECT p.url AS url, MIN(p.title) AS title, MIN(i.detail) AS detail, MIN(i.message) AS message
			 FROM {$issues} i INNER JOIN {$pages} p ON p.id = i.page_id
			 WHERE i.code IN ($ph)
			 GROUP BY p.id, p.url ORDER BY p.url LIMIT %d",
			array_merge( $codes, array( self::RECO_URLS_MAX ) )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$note = trim( (string) ( ! empty( $r['detail'] ) ? $r['detail'] : $r['message'] ) );
			$out[] = array( 'url' => $r['url'], 'title' => $r['title'], 'note' => $note );
		}
		$total = max( $total, count( $out ) );
		return $out;
	}

	/**
	 * URL partageant un titre ou une meta description avec une autre page
	 * (pages analysées uniquement : une URL qui redirige n'est pas un doublon).
	 *
	 * @param int|null $total Reçoit le nombre réel de pages concernées.
	 */
	private function urls_for_duplicates( &$total = null ) {
		global $wpdb;
		$pages = CU_DB::table( 'seo_pages' );
		$out   = array();
		$seen  = array();

		$dup_title_sql = "SELECT title FROM {$pages} WHERE crawl_state = 'done' AND title <> '' GROUP BY title HAVING COUNT(*) > 1";
		$dup_meta_sql  = "SELECT meta_desc FROM {$pages} WHERE crawl_state = 'done' AND meta_desc <> '' AND meta_desc IS NOT NULL GROUP BY meta_desc HAVING COUNT(*) > 1";
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$pages} p WHERE p.crawl_state = 'done' AND ( p.title IN ( {$dup_title_sql} ) OR p.meta_desc IN ( {$dup_meta_sql} ) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results(
			"SELECT p.url AS url, p.title AS title FROM {$pages} p
			 INNER JOIN ( {$dup_title_sql} ) d ON d.title = p.title
			 WHERE p.crawl_state = 'done'
			 ORDER BY p.title, p.url LIMIT " . self::RECO_URLS_MAX, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $r ) {
			$seen[ $r['url'] ] = true;
			$out[] = array(
				'url'  => $r['url'],
				'note' => sprintf( __( 'titre en double : « %s »', 'cyberpush-ultra' ), (string) $r['title'] ),
			);
		}

		$rows2 = $wpdb->get_results(
			"SELECT p.url AS url FROM {$pages} p
			 INNER JOIN ( {$dup_meta_sql} ) d ON d.meta_desc = p.meta_desc
			 WHERE p.crawl_state = 'done'
			 ORDER BY p.meta_desc, p.url LIMIT " . self::RECO_URLS_MAX, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows2 as $r ) {
			if ( ! empty( $seen[ $r['url'] ] ) ) {
				continue;
			}
			$out[] = array( 'url' => $r['url'], 'note' => __( 'meta description en double', 'cyberpush-ultra' ) );
		}

		$total = max( (int) $total, count( $out ) );
		return $out;
	}

	public function rest_pages( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		global $wpdb;
		$table = CU_DB::table( 'seo_pages' );

		$where  = array( '1=1' );
		$params = array();

		$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
		switch ( $filter ) {
			case 'errors':   $where[] = 'issues_error > 0'; break;
			case 'warnings': $where[] = 'issues_warning > 0'; break;
			case 'good':     $where[] = "crawl_state = 'done' AND score >= 80"; break;
			case 'medium':   $where[] = "crawl_state = 'done' AND score >= 50 AND score < 80"; break;
			case 'poor':     $where[] = "crawl_state = 'done' AND score < 50"; break;
			case 'noindex':  $where[] = 'indexable = 0'; break;
			// Parenthèses indispensables : sans elles, le OR annulerait la recherche (AND prioritaire).
			case 'pending':  $where[] = "( crawl_state = 'pending' OR last_crawled IS NULL )"; break;
			case 'failed':   $where[] = "crawl_state IN ( 'error', 'http_error' )"; break;
			case 'skipped':  $where[] = "crawl_state = 'skipped'"; break;
		}

		$search = is_scalar( $request->get_param( 'search' ) ) ? trim( (string) $request->get_param( 'search' ) ) : '';
		if ( '' !== $search ) {
			$where[]  = '( url LIKE %s OR title LIKE %s )';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$per_page  = 25;
		$offset    = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows_sql    = "SELECT id, post_id, url, title, object_type, http_status, indexable, word_count, score, score_content, score_meta, score_technical, perf_score, ai_ready, issues_error, issues_warning, issues_notice, crawl_state, last_crawled FROM {$table} WHERE {$where_sql} ORDER BY ( score IS NULL ) DESC, score ASC, id ASC LIMIT %d OFFSET %d"; // id : ordre stable entre deux pages de résultats
		$rows_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Trafic réel (30 j) depuis notre Analytics, attaché à chaque page.
		$traffic = $this->get_traffic_map( wp_list_pluck( (array) $rows, 'post_id' ), 30 );
		foreach ( $rows as &$r ) {
			$pid = (int) $r['post_id'];
			$r['visits_30d'] = isset( $traffic[ $pid ] ) ? $traffic[ $pid ]['visits'] : null;
		}
		unset( $r );

		return rest_ensure_response( array(
			'items'    => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		) );
	}

	public function rest_page( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		global $wpdb;
		$pages  = CU_DB::table( 'seo_pages' );
		$issues = CU_DB::table( 'seo_issues' );
		$meta   = CU_DB::table( 'seo_meta' );

		$id  = (int) $request->get_param( 'id' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pages} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'cu_not_found', __( 'Page introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}

		$snapshot        = $row['snapshot'] ? json_decode( $row['snapshot'], true ) : array();
		$row['snapshot'] = is_array( $snapshot ) ? $snapshot : array(); // JSON corrompu → vide, jamais null

		$issue_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT category, code, severity, message, detail FROM {$issues} WHERE page_id = %d
			 ORDER BY FIELD( severity, 'error', 'warning', 'notice' )", $id ), ARRAY_A );

		// Fiabilité (Phase 1) : chaque constat porte SA source + son indice de
		// confiance, reconstruits depuis le code (déterministe, non stocké en base).
		foreach ( $issue_rows as &$ir ) {
			$code = isset( $ir['code'] ) ? $ir['code'] : '';
			$rel  = CU_SEO_Audit::reliability( $code );
			if ( 'readability' === $ir['category'] ) {
				$rel = array( 'source' => __( 'Lisibilité — indicatif', 'cyberpush-ultra' ), 'confidence' => 60, 'basis' => 'heuristic' );
			}
			$ir['source']     = $rel['source'];
			$ir['confidence'] = $rel['confidence'];
			$ir['basis']      = $rel['basis'];
		}
		unset( $ir );

		$page_meta = array( 'target_keyword' => '', 'notes' => '', 'ai_meta_title' => '', 'ai_meta_description' => '', 'updated_at' => '' );
		if ( $row['post_id'] ) {
			// ai_meta_* : DERNIÈRE suggestion IA, ré-affichée telle quelle (jamais
			// appliquée au site — l'utilisateur la recopie s'il la retient).
			$m = $wpdb->get_row( $wpdb->prepare( "SELECT target_keyword, notes, ai_meta_title, ai_meta_description, updated_at FROM {$meta} WHERE post_id = %d", (int) $row['post_id'] ), ARRAY_A );
			if ( $m ) {
				$page_meta = $m;
			}
		}

		// Trafic réel (30 j) depuis notre Analytics.
		$traffic = array( 'visits' => null, 'views' => null );
		if ( $row['post_id'] ) {
			$tmap = $this->get_traffic_map( array( (int) $row['post_id'] ), 30 );
			if ( isset( $tmap[ (int) $row['post_id'] ] ) ) {
				$traffic = $tmap[ (int) $row['post_id'] ];
			}
		}

		$snap = $row['snapshot']; // déjà décodé plus haut

		return rest_ensure_response( array(
			'page'    => $row,
			'issues'  => $issue_rows,
			'meta'    => $page_meta,
			'traffic' => $traffic,
			'eeat'    => $this->compute_eeat( $row, $snap ),
			'ai_checklist' => $this->compute_ai_checklist( $snap ),
			'edit_link' => $row['post_id'] ? get_edit_post_link( (int) $row['post_id'], 'raw' ) : '',
			'ai_configured' => CU_AI::is_configured(),
			'psi'     => array(
				'ready'      => (bool) CU_Settings::get( 'seo', 'psi_enabled', false ) && '' !== trim( (string) CU_Settings::get( 'seo', 'psi_api_key', '' ) ),
				'strategy'   => 'desktop' === CU_Settings::get( 'seo', 'psi_strategy', 'mobile' ) ? 'desktop' : 'mobile',
				'cache_days' => self::PSI_CACHE_DAYS,
			),
			// Indexation observée via Serper (informatif, hors score).
			'index'   => $this->index_item( $row, $snap ),
		) );
	}

	public function rest_crawl( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		// Verrou partagé avec la tâche planifiée : jamais deux crawls en parallèle.
		return $this->locked( function () use ( $request ) {
			$mode  = sanitize_key( (string) $request->get_param( 'mode' ) );
			$added = 0;
			$disc  = array( 'removed' => 0, 'too_long' => 0 );
			if ( 'discover' === $mode || 'full' === $mode ) {
				$added = $this->discover_urls( 'full' );
				$disc  = array_merge( $disc, $this->last_discovery );
				if ( 'discover' === $mode ) {
					global $wpdb;
					$table = CU_DB::table( 'seo_pages' );
					$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$read_failed = '' !== $wpdb->last_error;
					$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE crawl_state = 'pending' OR last_crawled IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( $read_failed || $wpdb->last_error ) {
						return new WP_Error( 'cu_seo_discover_count_failed', __( 'Impossible de calculer l’inventaire SEO.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
					}
					return rest_ensure_response( array(
						'success'   => true,
						'added'     => $added,
						'removed'   => (int) $disc['removed'],
						'too_long'  => (int) $disc['too_long'],
						'total'     => $total,
						'pending'   => $pending,
						'processed' => 0,
					) );
				}
			}
			$limit  = (int) $request->get_param( 'limit' );
			$result = $this->crawl_batch( $limit );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$this->update_history_snapshot();
			$result['success'] = true;
			$result['added']   = $added;
			return rest_ensure_response( $result );
		} );
	}

	public function rest_audit_page( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		global $wpdb;
		$table = CU_DB::table( 'seo_pages' );
		$id  = (int) $request->get_param( 'id' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'cu_not_found', __( 'Page introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		if ( ! self::dom_available() ) {
			return new WP_Error( 'cu_seo_dom_missing', self::dom_missing_message(), array( 'status' => 503 ) );
		}
		return $this->locked( function () use ( $row ) {
			$result = $this->crawl_single( $row );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( array( 'success' => true ) );
		} );
	}

	/**
	 * Mesure PageSpeed d'une page. Un résultat récent (moins de PSI_CACHE_DAYS
	 * jours, même stratégie, contenu non modifié depuis) est RÉUTILISÉ sauf si
	 * `force` = 1 : chaque appel consomme du quota Google et prend ~30 s.
	 */
	public function rest_psi( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		global $wpdb;
		$table = CU_DB::table( 'seo_pages' );
		$id  = (int) $request->get_param( 'id' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'cu_not_found', __( 'Page introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		$strategy = 'desktop' === CU_Settings::get( 'seo', 'psi_strategy', 'mobile' ) ? 'desktop' : 'mobile';
		$snapshot = $row['snapshot'] ? json_decode( $row['snapshot'], true ) : array();
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		if ( ! $request->get_param( 'force' ) && $this->psi_is_fresh( $row, $snapshot, $strategy ) ) {
			return rest_ensure_response( array(
				'success'    => true,
				'cached'     => true,
				'score'      => isset( $snapshot['perf']['score'] ) ? (int) $snapshot['perf']['score'] : null,
				'cwv'        => $snapshot['perf'],
				'cache_days' => self::PSI_CACHE_DAYS,
			) );
		}

		// Une seule mesure à la fois pour une page et une stratégie : deux clics
		// (ou deux onglets) simultanés consommaient deux fois le quota Google.
		// Compteur atomique (fenêtre de 90 s, au-delà du délai PSI de 45 s).
		$lock_key = 'seo_psi|' . (int) $id . '|' . $strategy;
		$inflight = CU_DB::counter_increment( $lock_key, 90 );
		if ( (int) $inflight['count'] > 1 ) {
			return rest_ensure_response( array(
				'success' => false,
				'busy'    => true,
				'message' => __( 'Une mesure PageSpeed de cette page est déjà en cours (autre clic ou autre onglet). Patientez quelques secondes puis rouvrez la page : le résultat sera réutilisé sans nouvel appel.', 'cyberpush-ultra' ),
			) );
		}
		try {
			$psi = $this->query_psi( $row['url'] );
		} finally {
			CU_DB::counter_reset( $lock_key, 90 );
		}
		if ( is_wp_error( $psi ) ) {
			return $psi; // défaut de configuration (désactivé, clé absente)
		}
		// Relu juste avant l'écriture (un crawl a pu passer pendant les ~30 s).
		$current  = $wpdb->get_var( $wpdb->prepare( "SELECT snapshot FROM {$table} WHERE id = %d", $id ) );
		$snapshot = $current ? json_decode( (string) $current, true ) : array();
		$snapshot = is_array( $snapshot ) ? $snapshot : array();

		if ( empty( $psi['ok'] ) ) {
			// Mesure indisponible : on le note (date, raison) SANS toucher au
			// dernier score valide ni au score SEO.
			$snapshot['perf_error'] = array(
				'at'       => current_time( 'mysql' ),
				'reason'   => (string) $psi['reason'],
				'message'  => (string) $psi['message'],
				'strategy' => $strategy,
			);
			$wpdb->update( $table, array( 'snapshot' => wp_json_encode( $snapshot ) ), array( 'id' => $id ) );
			return rest_ensure_response( array(
				'success'     => false,
				'unavailable' => true,
				'message'     => (string) $psi['message'],
				'previous'    => isset( $snapshot['perf'] ) ? $snapshot['perf'] : null,
			) );
		}

		$snapshot['perf']          = $psi['cwv'];
		$snapshot['perf']['score'] = $psi['score'];
		unset( $snapshot['perf_error'] );
		$updated = $wpdb->update( $table, array(
			'perf_score' => $psi['score'],
			'snapshot'   => wp_json_encode( $snapshot ),
		), array( 'id' => $id ) );
		if ( false === $updated ) {
			return new WP_Error( 'cu_psi_save_failed', __( 'Impossible d’enregistrer le résultat PageSpeed.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'success' => true, 'cached' => false, 'score' => $psi['score'], 'cwv' => $snapshot['perf'] ) );
	}

	/** Dernière mesure PSI encore valable pour cette page et cette stratégie ? */
	private function psi_is_fresh( $row, $snapshot, $strategy ) {
		if ( empty( $snapshot['perf']['checked'] ) || ! isset( $snapshot['perf']['score'] ) ) {
			return false;
		}
		if ( ( isset( $snapshot['perf']['strategy'] ) ? $snapshot['perf']['strategy'] : '' ) !== $strategy ) {
			return false;
		}
		$checked = strtotime( (string) $snapshot['perf']['checked'] );
		$now     = strtotime( current_time( 'mysql' ) );
		if ( ! $checked || ( $now - $checked ) > self::PSI_CACHE_DAYS * DAY_IN_SECONDS ) {
			return false;
		}
		if ( ! empty( $row['post_id'] ) ) {
			$post = get_post( (int) $row['post_id'] );
			if ( $post && strtotime( (string) $post->post_modified ) > $checked ) {
				return false; // contenu modifié depuis la mesure
			}
		}
		return true;
	}

	public function rest_save_meta( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id ) {
			return new WP_Error( 'cu_bad_request', __( 'Cette page n\'est pas liée à un contenu WordPress éditable.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$meta = CU_DB::table( 'seo_meta' );
		$keyword = sanitize_text_field( (string) $request->get_param( 'target_keyword' ) );
		$notes   = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );

		// INSERT ... ON DUPLICATE (et non replace) pour préserver les colonnes
		// ai_meta_* déjà générées par l'IA.
		$saved = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$meta} ( post_id, target_keyword, notes, updated_at )
			 VALUES ( %d, %s, %s, %s )
			 ON DUPLICATE KEY UPDATE target_keyword = VALUES(target_keyword), notes = VALUES(notes), updated_at = VALUES(updated_at)",
			$post_id, $keyword, $notes, current_time( 'mysql' )
		) );
		if ( false === $saved ) {
			return new WP_Error( 'cu_seo_meta_save_failed', __( 'Impossible d’enregistrer les notes SEO.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		// Ré-audit immédiat pour refléter le nouveau mot-clé cible.
		$table = CU_DB::table( 'seo_pages' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'cu_seo_page_read_failed', __( 'Impossible de relire la page SEO.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		if ( ! $row ) {
			return rest_ensure_response( array( 'success' => true, 'reaudited' => false ) );
		}
		$token = $this->acquire_lock();
		if ( false === $token || ! self::dom_available() ) {
			// Une analyse tourne déjà : la page passe en file, sans attendre.
			$wpdb->update( $table, array( 'crawl_state' => 'pending' ), array( 'id' => (int) $row['id'] ) );
			return rest_ensure_response( array(
				'success'   => true,
				'reaudited' => false,
				'message'   => __( 'Enregistré. La page sera ré-analysée au prochain lot (une analyse est déjà en cours).', 'cyberpush-ultra' ),
			) );
		}
		try {
			$crawl = $this->crawl_single( $row );
		} finally {
			$this->release_lock( $token );
		}
		if ( is_wp_error( $crawl ) ) {
			return $crawl;
		}
		return rest_ensure_response( array( 'success' => true, 'reaudited' => true ) );
	}

	/** Réponse uniforme quand aucun fournisseur IA n'est configuré. */
	private function ai_not_configured() {
		return rest_ensure_response( array(
			'success'        => false,
			'not_configured' => true,
			'message'        => __( 'Aucun fournisseur IA n’est configuré : renseignez une clé (ou une URL Ollama) dans Réglages → IA. L’audit SEO fonctionne sans IA.', 'cyberpush-ultra' ),
		) );
	}

	/**
	 * Suggestion IA de title + meta description. SUGGESTION seulement : rien
	 * n'est appliqué au site. Durée bornée : le rendu déjà analysé est réutilisé
	 * s'il a moins de 24 h ; sinon UN loopback limité à 20 s (redirections
	 * comprises), puis l'appel IA (borné par CU_AI).
	 */
	public function rest_ai_meta( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! CU_AI::is_configured() ) {
			return $this->ai_not_configured();
		}
		global $wpdb;
		$table = CU_DB::table( 'seo_pages' );
		$id  = (int) $request->get_param( 'id' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'cu_not_found', __( 'Page introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}

		$snap  = $row['snapshot'] ? json_decode( $row['snapshot'], true ) : array();
		$snap  = is_array( $snap ) ? $snap : array();
		$title = isset( $snap['title'] ) ? (string) $snap['title'] : (string) $row['title'];
		$text  = isset( $snap['text_sample'] ) ? (string) $snap['text_sample'] : '';
		$desc  = isset( $snap['meta_description'] ) ? (string) $snap['meta_description'] : '';
		$basis = ! empty( $snap['fetched_at'] ) ? (string) $snap['fetched_at'] : '';
		$age   = ! empty( $row['last_crawled'] ) ? strtotime( current_time( 'mysql' ) ) - strtotime( (string) $row['last_crawled'] ) : PHP_INT_MAX;
		$fresh = 'done' === $row['crawl_state'] && '' !== trim( $text ) && $age >= 0 && $age < DAY_IN_SECONDS;

		if ( ! $fresh && self::dom_available() ) {
			$fetch = CU_SEO_Renderer::fetch( $row['url'], microtime( true ) + 20 );
			if ( $fetch['ok'] && empty( $fetch['not_html'] ) && $fetch['http_status'] >= 200 && $fetch['http_status'] < 300 ) {
				$parsed = CU_SEO_Renderer::parse( $fetch['html'], $fetch['final_url'] );
				if ( ! empty( $parsed['analysable'] ) ) {
					$title = $parsed['title'];
					$text  = $parsed['text_sample'];
					$desc  = $parsed['meta_description'];
					$basis = current_time( 'mysql' );
				}
			}
		}
		if ( '' === trim( $text ) && '' === trim( $title ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => __( 'Aucun contenu exploitable pour cette page : lancez d’abord son analyse.', 'cyberpush-ultra' ) ) );
		}

		$suggestion = CU_AI::suggest_seo_meta( $title, $text, $title, $desc );
		if ( empty( $suggestion['success'] ) ) {
			return rest_ensure_response( $suggestion );
		}
		$suggestion['based_on'] = $basis; // date du rendu lu par l'IA

		if ( $row['post_id'] ) {
			$meta = CU_DB::table( 'seo_meta' );
			// Conservée pour être ré-affichée dans le détail de la page (jamais appliquée).
			$saved = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$meta} ( post_id, ai_meta_title, ai_meta_description, updated_at )
				 VALUES ( %d, %s, %s, %s )
				 ON DUPLICATE KEY UPDATE ai_meta_title = VALUES(ai_meta_title), ai_meta_description = VALUES(ai_meta_description), updated_at = VALUES(updated_at)",
				(int) $row['post_id'], $suggestion['meta_title'], $suggestion['meta_description'], current_time( 'mysql' )
			) );
			if ( false === $saved ) {
				return new WP_Error( 'cu_ai_meta_save_failed', __( 'La suggestion IA a été générée mais n’a pas pu être enregistrée.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response( $suggestion );
	}

	/**
	 * Conseil IA « consultant SEO » pour une page : à partir du rendu final et
	 * des anomalies détectées, l'IA explique pourquoi la page peut sous-performer
	 * et quelles actions concrètes traiter en premier. Rien n'est écrit sur le
	 * site (audit seul) et aucun gain chiffré n'est demandé à l'IA.
	 */
	public function rest_ai_insight( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! CU_AI::is_configured() ) {
			return $this->ai_not_configured();
		}
		global $wpdb;
		$pages  = CU_DB::table( 'seo_pages' );
		$issues = CU_DB::table( 'seo_issues' );
		$id  = (int) $request->get_param( 'id' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pages} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'cu_not_found', __( 'Page introuvable.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}

		$snap = $row['snapshot'] ? json_decode( $row['snapshot'], true ) : array();
		$snap = is_array( $snap ) ? $snap : array();
		$issue_rows = $wpdb->get_results( $wpdb->prepare( "SELECT message FROM {$issues} WHERE page_id = %d", $id ), ARRAY_A );
		$issue_list = array();
		foreach ( (array) $issue_rows as $ir ) {
			$issue_list[] = '- ' . $ir['message'];
		}
		$kw = $row['post_id'] ? $this->get_target_keyword( (int) $row['post_id'] ) : '';

		$prompt = sprintf(
			"Tu es un consultant SEO senior francophone. RÈGLE ABSOLUE : base-toi UNIQUEMENT sur les données fournies ci-dessous. N'invente JAMAIS de chiffre (trafic, position, volume, CTR, pourcentage de gain) qui ne serait pas donné ; si une donnée manque, dis-le. Ton rôle est d'INTERPRÉTER ces données, pas de les imaginer.\n\n".
			"URL: %s\nTitre: %s\nMeta description: %s\nMot-clé cible: %s\nNombre de mots (contenu principal): %d\nH1: %s\nLiens internes: %d · externes: %d\nDonnées structurées: %s\nScore SEO (audit on-page): %s/100 · AI-Ready: %s/100\n\nAnomalies détectées:\n%s\n\nExtrait du contenu (rendu final):\n%s\n\n".
			"Réponds en français, de façon actionnable et priorisée. Structure ta réponse en 3 blocs courts:\n1) DIAGNOSTIC (pourquoi cette page peut sous-performer, 2-3 phrases)\n2) ACTIONS PRIORITAIRES (liste de 3 à 5 actions concrètes, la plus importante d'abord)\n3) À SURVEILLER (ce qu'il faudra vérifier après correction, par exemple dans Google Search Console — sans promettre de gain ni donner de chiffre). Sois direct, pas de blabla.",
			$row['url'],
			isset( $snap['title'] ) ? $snap['title'] : $row['title'],
			isset( $snap['meta_description'] ) ? $snap['meta_description'] : '',
			$kw ? $kw : '(non défini)',
			(int) $row['word_count'],
			( ! empty( $snap['h1'] ) ? implode( ' | ', (array) $snap['h1'] ) : '(aucun)' ),
			isset( $snap['links_internal'] ) ? (int) $snap['links_internal'] : 0,
			isset( $snap['links_external'] ) ? (int) $snap['links_external'] : 0,
			( ! empty( $snap['schema_types'] ) ? implode( ', ', (array) $snap['schema_types'] ) : 'aucune' ),
			null !== $row['score'] ? (int) $row['score'] : '—',
			null !== $row['ai_ready'] ? (int) $row['ai_ready'] : '—',
			$issue_list ? implode( "\n", $issue_list ) : 'Aucune anomalie majeure.',
			CU_Text::substr( (string) ( isset( $snap['text_sample'] ) ? $snap['text_sample'] : '' ), 0, 1200 )
		);

		$raw = CU_AI::complete( $prompt );
		if ( is_wp_error( $raw ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $raw->get_error_message() ) );
		}

		return rest_ensure_response( array( 'success' => true, 'insight' => wp_strip_all_tags( $raw ) ) );
	}

	/**
	 * Vérifie un lot de liens (URL distinctes jamais testées). Budget de 40 s
	 * PAR REQUÊTE, redirections comprises : le JS enchaîne les lots tant que
	 * `remaining` > 0 (avant : jusqu'à 30 liens × HEAD+GET × 8 s → 504/524).
	 */
	public function rest_check_links( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! CU_Settings::get( 'seo', 'check_broken_links', true ) ) {
			return rest_ensure_response( array( 'success' => true, 'checked' => 0, 'remaining' => 0, 'broken' => 0 ) );
		}
		return $this->locked( function () use ( $request ) {
			return $this->check_links_batch( (int) $request->get_param( 'limit' ) );
		} );
	}

	private function check_links_batch( $limit ) {
		global $wpdb;
		$links = CU_DB::table( 'seo_links' );
		$limit = max( 1, min( 30, $limit ? (int) $limit : 15 ) );

		// On vérifie chaque URL distincte non encore testée.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT MIN(id) AS id, MIN(url) AS url, MIN(type) AS type FROM {$links} WHERE checked_at IS NULL GROUP BY url_hash LIMIT %d", $limit ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'cu_links_read_failed', __( 'Impossible de lire les liens à vérifier.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		$broken   = 0;
		$checked  = 0;
		$now      = current_time( 'mysql' );
		$deadline = microtime( true ) + 40;
		foreach ( (array) $rows as $r ) {
			$left = $deadline - microtime( true );
			if ( $checked > 0 && $left < 8 ) {
				break; // le reste part au lot suivant
			}
			// HEAD puis GET éventuel, jusqu'à 3 sauts chacun : délai par requête
			// calculé pour tenir dans le temps restant.
			$timeout = max( 2, min( 6, ( $left - 1 ) / 6 ) );
			$args    = array( 'timeout' => $timeout, 'redirection' => 2, 'sslverify' => true );
			if ( 'internal' === $r['type'] ) {
				// Lien vers le site lui-même : même convention SSL que les loopbacks
				// du cœur, et jeton signé pour que le pare-feu ne bannisse pas
				// l'IP du serveur pendant une vérification en rafale.
				// Sans suivi de redirection : le jeton ne doit jamais partir vers un
				// autre hôte, et un 3xx interne n'est pas un lien cassé.
				$args['sslverify']   = (bool) apply_filters( 'https_local_ssl_verify', false );
				$args['redirection'] = 0;
			}
			// Jeton d'audit à usage unique : un nouveau pour chaque requête (HEAD puis GET).
			$audit  = 'internal' === $r['type'] ? CU_SEO_Renderer::audit_headers_for( $r['url'] ) : array();
			$resp   = wp_safe_remote_request( $r['url'], array_merge( $args, array( 'method' => 'HEAD', 'headers' => $audit ) ) );
			$status = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
			// Certains serveurs refusent HEAD → on retente en GET léger.
			if ( 0 === $status || $status >= 400 ) {
				$audit  = 'internal' === $r['type'] ? CU_SEO_Renderer::audit_headers_for( $r['url'] ) : array();
				$resp2  = wp_safe_remote_get( $r['url'], array_merge( $args, array( 'limit_response_size' => 1024, 'headers' => $audit ) ) );
				$status = is_wp_error( $resp2 ) ? 0 : (int) wp_remote_retrieve_response_code( $resp2 );
			}
			if ( 0 === $status || $status >= 400 ) {
				$broken++;
			}
			// Marque toutes les occurrences de cette URL (même hash) comme testées.
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$links} SET http_status = %d, checked_at = %s WHERE url_hash = %s",
				$status, $now, md5( $r['url'] ) ) );
			if ( false === $updated ) {
				return new WP_Error( 'cu_links_save_failed', __( 'Impossible d’enregistrer le contrôle des liens.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
			}
			$checked++;
		}

		$remaining = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT url_hash ) FROM {$links} WHERE checked_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->last_error ) {
			return new WP_Error( 'cu_links_count_failed', __( 'Impossible de calculer les liens restants.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'success' => true, 'checked' => $checked, 'remaining' => $remaining, 'broken' => $broken ) );
	}

	// =====================================================================
	// Indexation observée dans Google (API Serper.dev — requête « site: »)
	// =====================================================================
	//
	// CE QUE C'EST : une recherche Google « site:URL » faite par Serper.dev,
	// dont on lit les liens organiques renvoyés. Ce n'est PAS Google Search
	// Console : une page « observée » est apparue dans ces résultats à la date
	// indiquée ; une page « non trouvée » n'y figurait pas (ce qui ne prouve pas
	// qu'elle est désindexée). L'indexation est INFORMATIVE : elle n'entre dans
	// aucun score, et une vérification impossible n'est jamais un problème SEO.
	//
	// Statuts (colonne index_status) : found | not_found | error. Les anciens
	// statuts (indexed / not_indexed / unknown, méthode par préfixe imprécise)
	// sont présentés « à revérifier ».

	/** Ancienne fenêtre de fraîcheur (compatibilité). */
	const INDEX_STALE_DAYS = 30;
	/** Page observée : revérifiée au plus tôt après N jours. */
	const INDEX_FOUND_DAYS = 30;
	/** Page non trouvée : revérifiée au plus tôt après N jours. */
	const INDEX_NOT_FOUND_DAYS = 7;
	/** Vérification impossible : nouvel essai au plus tôt après N heures. */
	const INDEX_ERROR_HOURS = 6;

	/** Pages concernées : indexables (pas de noindex), ni 4xx/5xx, ni non-HTML. */
	private function index_base_where() {
		return "indexable = 1 AND crawl_state <> 'skipped' AND ( http_status IS NULL OR http_status < 400 )";
	}

	/**
	 * Clause « à vérifier » (cache par statut) + paramètres.
	 *
	 * @param string $force_since Date (heure du site) : tout ce qui est plus
	 *                            ancien est à revérifier (« Tout revérifier »).
	 * @return array{0:string, 1:array}
	 */
	private function index_due_clause( $force_since = '' ) {
		$sql    = "( index_checked IS NULL OR index_status NOT IN ( 'found', 'not_found', 'error' )
			OR ( index_status = 'found' AND index_checked < %s )
			OR ( index_status = 'not_found' AND index_checked < %s )
			OR ( index_status = 'error' AND index_checked < %s )";
		$params = array(
			CU_DB::date_from_now( 'Y-m-d H:i:s', -self::INDEX_FOUND_DAYS * DAY_IN_SECONDS ),
			CU_DB::date_from_now( 'Y-m-d H:i:s', -self::INDEX_NOT_FOUND_DAYS * DAY_IN_SECONDS ),
			CU_DB::date_from_now( 'Y-m-d H:i:s', -self::INDEX_ERROR_HOURS * HOUR_IN_SECONDS ),
		);
		if ( '' !== $force_since ) {
			$sql     .= ' OR index_checked < %s';
			$params[] = $force_since;
		}
		return array( $sql . ' )', $params );
	}

	/**
	 * Forme comparable d'une URL pour la correspondance Serper :
	 * schéma et hôte en minuscules, port par défaut retiré, fragment ignoré,
	 * %XX décodés (casse indifférente, accents encodés ou non), barre finale
	 * équivalente ; chemin et requête restent SIGNIFICATIFS. www et sans-www
	 * sont des URL différentes. $loose = true ignore schéma et « www. » (pour
	 * signaler une VARIANTE, jamais pour conclure « observée »).
	 */
	public static function index_match_key( $url, $loose = false ) {
		$p = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return '';
		}
		$scheme = strtolower( isset( $p['scheme'] ) ? (string) $p['scheme'] : '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host = rtrim( strtolower( (string) $p['host'] ), '.' );
		$port = isset( $p['port'] ) ? (int) $p['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		$path  = rtrim( rawurldecode( isset( $p['path'] ) ? (string) $p['path'] : '' ), '/' );
		$query = ( isset( $p['query'] ) && '' !== $p['query'] ) ? '?' . rawurldecode( (string) $p['query'] ) : '';
		if ( $loose ) {
			return '//' . preg_replace( '/^www\./', '', $host ) . ( $port ? ':' . $port : '' ) . $path . $query;
		}
		return $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path . $query;
	}

	/** Cible de l'opérateur site: (sans schéma, que Google ignore). */
	public static function serper_query_target( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return (string) $url;
		}
		return strtolower( (string) $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' )
			. ( isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/' )
			. ( isset( $p['query'] ) && '' !== $p['query'] ? '?' . $p['query'] : '' );
	}

	/** Résultat normalisé d'une vérification Serper. */
	private static function serper_result( $status, $reason = '', $extra = array() ) {
		return array_merge( array(
			'status'  => $status,  // found | not_found | error | invalid_key | quota
			'reason'  => $reason,
			'match'   => '',
			'variant' => '',
			'results' => 0,
			'query'   => '',
		), $extra );
	}

	/**
	 * Interprète la réponse Serper pour une URL cible (testable hors WP).
	 * Décision sur la comparaison EXACTE (normalisée) des liens organiques :
	 * « site:/page » renvoie aussi /page-2 ou /page/sous-page, et pour
	 * l'accueil n'importe quelle page du site — ce ne sont PAS des preuves.
	 */
	public static function interpret_serper_response( $resp, $target_url ) {
		if ( is_wp_error( $resp ) ) {
			$msg = $resp->get_error_message();
			$to  = false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'cURL error 28' );
			return self::serper_result( 'error', $to ? 'timeout' : 'unreachable' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$msg  = ( is_array( $body ) && isset( $body['message'] ) && is_string( $body['message'] ) ) ? $body['message'] : '';
		if ( 401 === $code || 403 === $code ) {
			return self::serper_result( 'invalid_key', 'http_' . $code );
		}
		if ( 429 === $code || 402 === $code ) {
			return self::serper_result( 'quota', 'http_' . $code );
		}
		if ( 400 === $code && preg_match( '/credit|quota|limit/i', $msg ) ) {
			return self::serper_result( 'quota', 'credits' );
		}
		if ( 400 === $code && preg_match( '/api[ _-]?key|unauthori[sz]ed/i', $msg ) ) {
			return self::serper_result( 'invalid_key', 'http_400' );
		}
		if ( 200 !== $code ) {
			return self::serper_result( 'error', 'http_' . $code );
		}
		if ( ! is_array( $body ) ) {
			return self::serper_result( 'error', 'bad_response' );
		}
		if ( ! array_key_exists( 'organic', $body ) ) {
			// Réponse Serper valide sans bloc organique = Google n'a rien renvoyé.
			if ( ! isset( $body['searchParameters'] ) ) {
				return self::serper_result( 'error', 'empty_response' );
			}
			$organic = array();
		} elseif ( ! is_array( $body['organic'] ) ) {
			return self::serper_result( 'error', 'bad_response' );
		} else {
			$organic = $body['organic'];
		}

		$want       = self::index_match_key( $target_url );
		$want_loose = self::index_match_key( $target_url, true );
		if ( '' === $want ) {
			return self::serper_result( 'error', 'bad_target' );
		}
		$variant = '';
		foreach ( $organic as $item ) {
			$link = ( is_array( $item ) && isset( $item['link'] ) && is_string( $item['link'] ) ) ? $item['link'] : '';
			if ( '' === $link ) {
				continue;
			}
			if ( self::index_match_key( $link ) === $want ) {
				return self::serper_result( 'found', '', array( 'match' => esc_url_raw( $link ), 'results' => count( $organic ) ) );
			}
			if ( '' === $variant && self::index_match_key( $link, true ) === $want_loose ) {
				$variant = esc_url_raw( $link );
			}
		}
		return self::serper_result( 'not_found', $variant ? 'variant' : '', array( 'variant' => $variant, 'results' => count( $organic ) ) );
	}

	/** Langue / pays de la recherche d'après la langue du site (fr_FR → fr / fr). */
	private static function serper_locale() {
		$locale = strtolower( (string) ( function_exists( 'get_locale' ) ? get_locale() : 'fr_FR' ) );
		$hl     = substr( $locale, 0, 2 );
		$gl     = strlen( $locale ) >= 5 ? substr( $locale, 3, 2 ) : $hl;
		$hl     = preg_match( '/^[a-z]{2}$/', $hl ) ? $hl : 'fr';
		$gl     = preg_match( '/^[a-z]{2}$/', $gl ) ? $gl : 'fr';
		return array( $hl, $gl );
	}

	/** Une vérification Serper pour une URL (délai borné). */
	private function query_serper( $url, $timeout = 10 ) {
		$key = trim( (string) CU_Settings::get( 'seo', 'serper_api_key', '' ) );
		if ( '' === $key ) {
			return self::serper_result( 'invalid_key', 'no_key' );
		}
		$query = 'site:' . self::serper_query_target( $url );
		list( $hl, $gl ) = self::serper_locale();
		$resp = wp_remote_post(
			'https://google.serper.dev/search',
			array(
				'timeout'             => max( 3, min( 10, $timeout ) ),
				'redirection'         => 0,
				'limit_response_size' => 512 * KB_IN_BYTES,
				'headers'             => array(
					'X-API-KEY'    => $key,
					'Content-Type' => 'application/json',
				),
				'body'                => wp_json_encode( array( 'q' => $query, 'gl' => $gl, 'hl' => $hl, 'num' => 10 ) ),
			)
		);
		$res          = self::interpret_serper_response( $resp, $url );
		$res['query'] = $query;
		return $res;
	}

	/** Mémorise un arrêt « clé refusée / quota » (avec la clé concernée). */
	private function set_serper_block( $error ) {
		update_option( self::SERPER_STATE_OPTION, array(
			'error' => $error,
			'at'    => current_time( 'mysql' ),
			'key'   => md5( trim( (string) CU_Settings::get( 'seo', 'serper_api_key', '' ) ) ),
		), false );
	}

	private function clear_serper_block() {
		if ( false !== get_option( self::SERPER_STATE_OPTION, false ) ) {
			delete_option( self::SERPER_STATE_OPTION );
		}
	}

	/**
	 * Blocage Serper encore actif ? Clé refusée : tant que la clé n'a pas
	 * changé. Quota : 12 h (puis l'auto-vérification retente une fois).
	 *
	 * @return array|null
	 */
	private function serper_block() {
		$s = get_option( self::SERPER_STATE_OPTION, array() );
		if ( ! is_array( $s ) || empty( $s['error'] ) ) {
			return null;
		}
		if ( ( isset( $s['key'] ) ? $s['key'] : '' ) !== md5( trim( (string) CU_Settings::get( 'seo', 'serper_api_key', '' ) ) ) ) {
			return null; // nouvelle clé : on retente
		}
		if ( 'quota' === $s['error'] && ( strtotime( current_time( 'mysql' ) ) - strtotime( (string) $s['at'] ) ) > 12 * HOUR_IN_SECONDS ) {
			return null;
		}
		$s['message'] = self::serper_error_message( $s['error'] );
		return $s;
	}

	private static function serper_error_message( $error ) {
		if ( 'invalid_key' === $error ) {
			return __( 'Clé Serper.dev refusée (invalide ou révoquée). Vérifiez-la dans Réglages → SEO. Aucune page n’a été marquée.', 'cyberpush-ultra' );
		}
		if ( 'quota' === $error ) {
			return __( 'Quota (crédits) Serper.dev épuisé. La vérification est arrêtée ; aucune page n’a été marquée. Réessayez après recharge des crédits.', 'cyberpush-ultra' );
		}
		if ( 'db' === $error ) {
			return __( 'Impossible d’enregistrer le résultat en base de données.', 'cyberpush-ultra' );
		}
		return '';
	}

	/** Libellé lisible d'une raison de « vérification impossible ». */
	private static function serper_reason_label( $reason ) {
		$reason = (string) $reason;
		if ( 'timeout' === $reason ) {
			return __( 'délai de réponse dépassé', 'cyberpush-ultra' );
		}
		if ( 'unreachable' === $reason ) {
			return __( 'service Serper injoignable', 'cyberpush-ultra' );
		}
		if ( 'bad_response' === $reason || 'empty_response' === $reason ) {
			return __( 'réponse vide ou illisible', 'cyberpush-ultra' );
		}
		if ( 0 === strpos( $reason, 'http_' ) ) {
			return sprintf( __( 'réponse HTTP %s du service', 'cyberpush-ultra' ), substr( $reason, 5 ) );
		}
		return $reason;
	}

	/** Statut d'indexation d'une ligne, prêt pour l'interface. */
	private function index_item( $row, $snap ) {
		$checked = isset( $row['index_checked'] ) ? $row['index_checked'] : null;
		$raw     = isset( $row['index_status'] ) ? (string) $row['index_status'] : '';
		$status  = null === $checked ? 'unchecked' : ( in_array( $raw, array( 'found', 'not_found', 'error' ), true ) ? $raw : 'legacy' );
		$detail  = ( is_array( $snap ) && isset( $snap['index'] ) && is_array( $snap['index'] ) ) ? $snap['index'] : array();
		$reason  = isset( $detail['reason'] ) ? (string) $detail['reason'] : '';
		return array(
			'status'       => $status,
			'checked'      => $checked,
			'reason'       => $reason,
			'reason_label' => 'error' === $status ? self::serper_reason_label( $reason ) : '',
			'match'        => isset( $detail['match'] ) ? (string) $detail['match'] : '',
			'variant'      => isset( $detail['variant'] ) ? (string) $detail['variant'] : '',
			'results'      => isset( $detail['results'] ) ? (int) $detail['results'] : 0,
			'query'        => isset( $detail['query'] ) ? (string) $detail['query'] : '',
			'previous'     => isset( $detail['previous'] ) && is_array( $detail['previous'] ) ? $detail['previous'] : null,
		);
	}

	/** Compteurs d'indexation agrégés (pages concernées uniquement). */
	private function index_counts() {
		global $wpdb;
		$pages = CU_DB::table( 'seo_pages' );
		$base  = $this->index_base_where();
		list( $due_sql, $due_params ) = $this->index_due_clause();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				SUM( index_checked IS NOT NULL AND index_status = 'found' )     AS found,
				SUM( index_checked IS NOT NULL AND index_status = 'not_found' ) AS not_found,
				SUM( index_checked IS NOT NULL AND index_status = 'error' )     AS errors,
				SUM( index_checked IS NOT NULL AND index_status NOT IN ( 'found', 'not_found', 'error' ) ) AS legacy,
				SUM( index_checked IS NULL )                                    AS never,
				SUM( {$due_sql} )                                               AS due,
				COUNT(*)                                                         AS total,
				MAX( index_checked )                                             AS last_checked
			FROM {$pages} WHERE {$base}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$due_params
		), ARRAY_A );
		$block = $this->serper_block();
		return array(
			'found'         => (int) ( $row['found'] ?? 0 ),
			'not_found'     => (int) ( $row['not_found'] ?? 0 ),
			'errors'        => (int) ( $row['errors'] ?? 0 ),
			'legacy'        => (int) ( $row['legacy'] ?? 0 ),
			'never'         => (int) ( $row['never'] ?? 0 ),
			'due'           => (int) ( $row['due'] ?? 0 ),
			'total'         => (int) ( $row['total'] ?? 0 ),
			'last_checked'  => isset( $row['last_checked'] ) ? $row['last_checked'] : null,
			'has_key'       => '' !== trim( (string) CU_Settings::get( 'seo', 'serper_api_key', '' ) ),
			'blocked'       => $block ? array( 'error' => $block['error'], 'at' => $block['at'], 'message' => $block['message'] ) : null,
			'auto'          => (bool) CU_Settings::get( 'seo', 'index_auto', false ),
			'cache'         => array(
				'found_days'     => self::INDEX_FOUND_DAYS,
				'not_found_days' => self::INDEX_NOT_FOUND_DAYS,
				'error_hours'    => self::INDEX_ERROR_HOURS,
			),
		);
	}

	/**
	 * Vue de la section indexation : compteurs GLOBAUX + liste PAGINÉE
	 * (filtrable par statut).
	 */
	public function rest_index_overview( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		global $wpdb;
		$pages = CU_DB::table( 'seo_pages' );

		// Filtre par statut (les compteurs, eux, restent toujours globaux).
		$where  = array( $this->index_base_where() );
		$params = array();
		$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
		switch ( $filter ) {
			case 'found':     $where[] = "index_checked IS NOT NULL AND index_status = 'found'"; break;
			case 'not_found': $where[] = "index_checked IS NOT NULL AND index_status = 'not_found'"; break;
			case 'error':     $where[] = "index_checked IS NOT NULL AND index_status = 'error'"; break;
			case 'legacy':    $where[] = "index_checked IS NOT NULL AND index_status NOT IN ( 'found', 'not_found', 'error' )"; break;
			case 'unchecked': $where[] = 'index_checked IS NULL'; break;
			case 'due':
				list( $due_sql, $params ) = $this->index_due_clause();
				$where[] = $due_sql;
				break;
		}
		$where_sql = implode( ' AND ', $where );

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = 25;
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql  = "SELECT COUNT(*) FROM {$pages} WHERE {$where_sql}";
		$total_list = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Non trouvées d'abord, puis vérifications impossibles, anciens
		// résultats, jamais vérifiées, et enfin pages observées.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, post_id, url, title, index_status, index_checked, snapshot
			FROM {$pages} WHERE {$where_sql}
			ORDER BY CASE WHEN index_checked IS NULL THEN 3 WHEN index_status = 'not_found' THEN 0 WHEN index_status = 'error' THEN 1 WHEN index_status = 'found' THEN 4 ELSE 2 END, url ASC
			LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out               = $this->index_counts();
		$out['success']    = true;
		$out['stale_days'] = self::INDEX_STALE_DAYS;
		$out['items']      = array_map( function ( $r ) {
			$snap = $r['snapshot'] ? json_decode( $r['snapshot'], true ) : array();
			return array_merge( array(
				'id'    => (int) $r['id'],
				'url'   => $r['url'],
				'title' => $r['title'],
			), $this->index_item( $r, $snap ) );
		}, (array) $rows );
		$out['page']        = $page;
		$out['per_page']    = $per_page;
		$out['total_list']  = $total_list;
		$out['pages_total'] = (int) ceil( $total_list / $per_page );
		return rest_ensure_response( $out );
	}

	/**
	 * Vérifie un lot d'URL via Serper (drain côté JS jusqu'à remaining = 0).
	 * `reset` = 1 → « Tout revérifier » : renvoie `force_since`, que le JS
	 * renvoie aux lots suivants ; une page déjà vérifiée pendant CE passage
	 * n'est jamais revérifiée (pas de crédit consommé deux fois).
	 */
	public function rest_check_index( WP_REST_Request $request ) {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( '' === trim( (string) CU_Settings::get( 'seo', 'serper_api_key', '' ) ) ) {
			return new WP_Error( 'cu_seo_no_serper', __( 'Ajoutez votre clé API Serper.dev dans Réglages → SEO pour vérifier l\'indexation.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		// Clé refusée / crédits épuisés lors d'un passage précédent : aucune
		// requête (payante) n'est relancée sans demande EXPLICITE de
		// l'administrateur (retry_blocked = 1, après confirmation dans l'interface).
		$block = $this->serper_block();
		if ( null !== $block && 1 !== (int) $request->get_param( 'retry_blocked' ) ) {
			$out                  = $this->index_counts();
			$out['success']       = false;
			$out['checked']       = 0;
			$out['remaining']     = $out['due'];
			$out['error']         = (string) $block['error'];
			$out['error_message'] = (string) $block['message'];
			$out['needs_confirm'] = true;
			$out['run']           = array( 'found' => 0, 'not_found' => 0, 'error' => 0 );
			$out['force_since']   = '';
			return rest_ensure_response( $out );
		}
		return $this->locked( function () use ( $request ) {
			$limit       = max( 1, min( 8, (int) $request->get_param( 'limit' ) ?: 4 ) );
			$force_since = '';
			if ( 1 === (int) $request->get_param( 'reset' ) ) {
				$force_since = current_time( 'mysql' );
			} else {
				$fs = (string) $request->get_param( 'force_since' );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fs ) ) {
					$age = strtotime( current_time( 'mysql' ) ) - strtotime( $fs );
					if ( $age >= 0 && $age <= 6 * HOUR_IN_SECONDS ) {
						$force_since = $fs;
					}
				}
			}
			$run = $this->process_index_checks( $limit, $force_since, 40 );

			$out                  = $this->index_counts();
			$out['success']       = true;
			$out['checked']       = $run['checked'];
			$out['remaining']     = $run['remaining'];
			$out['error']         = $run['error'];
			$out['error_message'] = self::serper_error_message( $run['error'] );
			$out['run']           = $run['stats'];
			$out['force_since']   = $force_since;
			return rest_ensure_response( $out );
		} );
	}

	/**
	 * Cœur de la vérification d'indexation, partagé entre le bouton (REST) et
	 * l'auto-vérification (cron). Traite jusqu'à $limit pages « à vérifier »
	 * (cache : observée 30 j, non trouvée 7 j, erreur 6 h) dans un budget de
	 * temps. S'arrête net si la clé est refusée ou le quota épuisé (aucune
	 * page n'est alors marquée).
	 *
	 * @return array{checked:int, remaining:int, error:string, stats:array}
	 */
	private function process_index_checks( $limit, $force_since = '', $budget = 40 ) {
		global $wpdb;
		$pages = CU_DB::table( 'seo_pages' );
		$base  = $this->index_base_where();
		$stats = array( 'found' => 0, 'not_found' => 0, 'error' => 0 );
		list( $due_sql, $due_params ) = $this->index_due_clause( $force_since );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, url, index_status, index_checked FROM {$pages}
			WHERE {$base} AND {$due_sql}
			ORDER BY index_checked IS NULL DESC, index_checked ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( $due_params, array( (int) $limit ) )
		), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return array( 'checked' => 0, 'remaining' => 0, 'error' => 'db', 'stats' => $stats );
		}

		$deadline = microtime( true ) + max( 5, (int) $budget );
		$err      = '';
		$checked  = 0;
		foreach ( $rows as $r ) {
			$left = $deadline - microtime( true );
			if ( $checked > 0 && $left < 11 ) {
				break; // le reste part au lot suivant
			}
			$res = $this->query_serper( $r['url'], min( 10, $left - 1 ) );
			if ( in_array( $res['status'], array( 'invalid_key', 'quota' ), true ) ) {
				// Clé refusée / quota : on arrête TOUT, sans marquer la page.
				$err = $res['status'];
				if ( 'no_key' !== $res['reason'] ) {
					$this->set_serper_block( $err );
				}
				break;
			}
			$now     = current_time( 'mysql' );
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT snapshot FROM {$pages} WHERE id = %d", (int) $r['id'] ) );
			$snap    = $current ? json_decode( (string) $current, true ) : array();
			$snap    = is_array( $snap ) ? $snap : array();
			$detail  = array(
				'checked' => $now,
				'reason'  => $res['reason'],
				'match'   => $res['match'],
				'variant' => $res['variant'],
				'results' => $res['results'],
				'query'   => $res['query'],
			);
			// Une erreur n'efface pas la dernière OBSERVATION valable : elle
			// reste consultable (« dernière observation : … »).
			if ( 'error' === $res['status'] ) {
				if ( in_array( $r['index_status'], array( 'found', 'not_found' ), true ) && ! empty( $r['index_checked'] ) ) {
					$detail['previous'] = array( 'status' => $r['index_status'], 'checked' => $r['index_checked'] );
				} elseif ( isset( $snap['index']['previous'] ) ) {
					$detail['previous'] = $snap['index']['previous'];
				}
			}
			$snap['index'] = $detail;
			$updated = $wpdb->update(
				$pages,
				array( 'index_status' => $res['status'], 'index_checked' => $now, 'snapshot' => wp_json_encode( $snap ) ),
				array( 'id' => (int) $r['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				$err = 'db';
				break;
			}
			$checked++;
			$stats[ $res['status'] ]++;
			if ( 'error' !== $res['status'] ) {
				$this->clear_serper_block(); // la clé fonctionne de nouveau
			}
		}

		$remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$pages} WHERE {$base} AND {$due_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$due_params
		) );
		if ( $wpdb->last_error ) {
			$err = 'db';
		}

		return array(
			'checked'   => $checked,
			'remaining' => $remaining,
			'error'     => $err,
			'stats'     => $stats,
		);
	}

	/**
	 * Auto-vérification d'indexation (appelée par le cron, sous verrou).
	 * Opt-in, mêmes règles de cache que le bouton, budget de 25 s, et rien
	 * tant qu'une clé refusée / un quota épuisé n'a pas été résolu.
	 */
	public function maybe_auto_check_index() {
		if ( ! CU_Settings::get( 'seo', 'index_auto', false ) ) {
			return;
		}
		if ( '' === trim( (string) CU_Settings::get( 'seo', 'serper_api_key', '' ) ) ) {
			return;
		}
		if ( null !== $this->serper_block() ) {
			return;
		}
		$per = (int) CU_Settings::get( 'seo', 'index_auto_per_cycle', 10 );
		$run = $this->process_index_checks( max( 1, min( 50, $per ) ), '', 25 );
		if ( in_array( $run['error'], array( 'invalid_key', 'quota' ), true ) ) {
			CU_Logger::log( 'seo', 'warning', self::serper_error_message( $run['error'] ) );
		}
	}

	const AI_SUMMARY_OPTION = 'cu_seo_ai_summary';

	/** Renvoie le résumé IA en cache (généré à la demande, pas à chaque affichage). */
	public function rest_get_ai_summary() {
		$data = get_option( self::AI_SUMMARY_OPTION, null );
		return rest_ensure_response( array(
			'has_data' => ! empty( $data ),
			'summary'  => $data ? $data : null,
		) );
	}

	/**
	 * Génère le résumé « État de votre site » + plan de la semaine. L'IA
	 * INTERPRÈTE uniquement les données réelles fournies — jamais d'invention.
	 * Résultat mis en cache (option).
	 */
	public function rest_gen_ai_summary() {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! CU_AI::is_configured() ) {
			return $this->ai_not_configured();
		}
		global $wpdb;
		$pages   = CU_DB::table( 'seo_pages' );
		$issues  = CU_DB::table( 'seo_issues' );

		$agg = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(crawl_state='done') AS crawled,
			AVG( CASE WHEN crawl_state='done' THEN score END ) AS avg_score,
			SUM(crawl_state = 'done' AND indexable = 1) AS indexable_count, SUM(issues_error) AS err, SUM(issues_warning) AS warn,
			AVG( perf_score ) AS avg_perf, AVG( CASE WHEN crawl_state='done' THEN ai_ready END ) AS avg_ai
			FROM {$pages}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $agg || ! $agg['crawled'] ) {
			return rest_ensure_response( array( 'success' => false, 'message' => __( 'Lancez d\'abord une analyse du site.', 'cyberpush-ultra' ) ) );
		}

		$top = $wpdb->get_results( "SELECT MIN(message) AS message, COUNT(*) AS c FROM {$issues}
			GROUP BY code ORDER BY FIELD(MIN(severity),'error','warning','notice'), c DESC LIMIT 8", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$top_list = array();
		foreach ( (array) $top as $t ) {
			$top_list[] = '- ' . $t['message'] . ' (' . (int) $t['c'] . ' page(s))';
		}

		$site_audit = get_option( self::SITE_AUDIT_OPTION, array() );
		$facts = array();
		if ( is_array( $site_audit ) ) {
			if ( ! empty( $site_audit['orphans_count'] ) )   { $facts[] = '- ' . (int) $site_audit['orphans_count'] . ' page(s) orphelines'; }
			if ( ! empty( $site_audit['broken_count'] ) )    { $facts[] = '- ' . (int) $site_audit['broken_count'] . ' lien(s) cassé(s)'; }
			if ( ! empty( $site_audit['deep_count'] ) )      { $facts[] = '- ' . (int) $site_audit['deep_count'] . ' page(s) trop profondes'; }
			if ( ! empty( $site_audit['partial'] ) )         { $facts[] = '- Audit technique partiel : pages orphelines et profondeur non calculées (site trop volumineux pour la mémoire disponible).'; }
		}

		// Trafic réel global (30 j) depuis notre Analytics.
		$traffic_line = '';
		if ( CU_DB::tables_exist() ) {
			$daily  = CU_DB::table( 'content_daily' );
			$cutoff = CU_DB::date_from_now( 'Y-m-d', -30 * DAY_IN_SECONDS );
			$visits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(sessions) FROM {$daily} WHERE stat_date >= %s", $cutoff ) );
			if ( $visits > 0 ) {
				$traffic_line = sprintf( "Trafic réel du site (30 derniers jours, source Analytics interne) : %d visites.\n", $visits );
			}
		}

		$perf_line = ( null !== $agg['avg_perf'] ) ? ( 'Score de performance moyen (PageSpeed) : ' . (int) round( (float) $agg['avg_perf'] ) . '/100.' ) : 'Performance non mesurée (clé PageSpeed non configurée).';

		$prompt = sprintf(
			"Tu es un consultant SEO francophone. RÈGLE ABSOLUE : utilise UNIQUEMENT les données ci-dessous, n'invente AUCUN chiffre (surtout pas de trafic gagné). Tu INTERPRÈTES, tu n'imagines pas.\n\n".
			"DONNÉES RÉELLES DU SITE :\n".
			"Pages analysées : %d\nScore SEO moyen : %d/100\nPages indexables : %d\nErreurs : %d · Alertes : %d\nScore AI-Ready moyen : %d/100\n%s\n%s\n\n".
			"Problèmes les plus fréquents :\n%s\n\nAudit technique :\n%s\n\n".
			"Rédige en français, clair et sans jargon, en 2 blocs :\n".
			"1) ÉTAT DE VOTRE SITE : 3-4 phrases résumant la situation (ce qui va bien, ce qui limite la visibilité).\n".
			"2) PLAN D'ACTION CETTE SEMAINE : 3 actions concrètes priorisées (la plus impactante d'abord). Parle de PRIORITÉ et de POTENTIEL, jamais d'un pourcentage de trafic garanti.",
			(int) $agg['crawled'],
			(int) round( (float) $agg['avg_score'] ),
			(int) $agg['indexable_count'],
			(int) $agg['err'],
			(int) $agg['warn'],
			(int) round( (float) $agg['avg_ai'] ),
			$perf_line,
			$traffic_line,
			$top_list ? implode( "\n", $top_list ) : 'Aucun problème majeur.',
			$facts ? implode( "\n", $facts ) : 'Rien à signaler.'
		);

		$raw = CU_AI::complete( $prompt );
		if ( is_wp_error( $raw ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $raw->get_error_message() ) );
		}

		$summary = array( 'text' => wp_strip_all_tags( $raw ), 'generated_at' => current_time( 'mysql' ), 'score' => (int) round( (float) $agg['avg_score'] ) );
		update_option( self::AI_SUMMARY_OPTION, $summary, false );

		return rest_ensure_response( array_merge( array( 'success' => true ), $summary ) );
	}

	public function rest_get_site_audit() {
		$data = get_option( self::SITE_AUDIT_OPTION, null );
		return rest_ensure_response( array(
			'has_data' => ! empty( $data ),
			'audit'    => $data ? $data : null,
		) );
	}

	public function rest_run_site_audit() {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return $this->locked( function () {
			return rest_ensure_response( $this->run_site_audit() );
		} );
	}

	public function rest_purge() {
		$check = $this->require_tables();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		// Jamais pendant une analyse (elle écrirait dans des tables vidées).
		return $this->locked( function () {
			global $wpdb;
			foreach ( array( 'seo_pages', 'seo_issues', 'seo_links', 'seo_history' ) as $short ) {
				if ( false === $wpdb->query( 'TRUNCATE TABLE ' . CU_DB::table( $short ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					return new WP_Error( 'cu_seo_purge_failed', __( 'Impossible de réinitialiser toutes les données SEO.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
				}
			}
			delete_option( self::SITE_AUDIT_OPTION );
			delete_option( self::AI_SUMMARY_OPTION );
			delete_option( self::DISCOVERY_OPTION );
			delete_option( self::SERPER_STATE_OPTION );
			CU_Logger::log( 'seo', 'warning', __( 'Données SEO réinitialisées (inventaire, anomalies, liens, historique, audit technique, résumé IA).', 'cyberpush-ultra' ) );
			return rest_ensure_response( array( 'success' => true ) );
		} );
	}

	public function rest_export() {
		if ( ! CU_DB::tables_exist() ) {
			return new WP_Error( 'cu_migration_required', __( 'Migration SQL requise.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$pages  = CU_DB::table( 'seo_pages' );
		$issues = CU_DB::table( 'seo_issues' );

		// Export COMPLET, lu par tranches de pages (mémoire bornée) dans le même
		// ordre qu'avant (score croissant, puis sévérité). L'ancien plafond de
		// 10 000 lignes coupait silencieusement les pages les mieux notées.
		$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$issues} i INNER JOIN {$pages} p ON p.id = i.page_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->last_error ) {
			return new WP_Error( 'cu_seo_export_failed', __( 'Impossible de préparer l’export SEO.', 'cyberpush-ultra' ), array( 'status' => 500 ) );
		}
		$cap = (int) apply_filters( 'cu_seo_export_max_rows', self::EXPORT_MAX_ROWS );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=cyberpush-seo-audit.csv' );
		header( 'X-CU-Export-Rows: ' . $total_rows );
		$out = fopen( 'php://output', 'w' );
		// BOM UTF-8 + séparateur « ; » : format attendu par Excel en français (cohérent avec l'export Analytics).
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'URL', 'Score', 'Sévérité', 'Catégorie', 'Anomalie' ), ';', '"', '' );
		$written = 0;
		$offset  = 0;
		$chunk   = 500;
		do {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.id FROM {$pages} p WHERE EXISTS ( SELECT 1 FROM {$issues} i WHERE i.page_id = p.id )
				 ORDER BY ( p.score IS NULL ), p.score ASC, p.id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$chunk,
				$offset
			) );
			$ids = array_map( 'intval', (array) $ids );
			if ( empty( $ids ) ) {
				break;
			}
			$offset += $chunk;
			$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.id AS pid, p.url, p.score, i.severity, i.category, i.message
				 FROM {$issues} i INNER JOIN {$pages} p ON p.id = i.page_id
				 WHERE i.page_id IN ( {$ph} )
				 ORDER BY ( p.score IS NULL ), p.score ASC, p.id ASC, FIELD( i.severity, 'error','warning','notice' ), i.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				if ( $cap > 0 && $written >= $cap ) {
					break 2;
				}
				unset( $row['pid'] );
				fputcsv( $out, array_map( array( $this, 'csv_safe_cell' ), array_values( $row ) ), ';', '"', '' );
				$written++;
			}
			$wpdb->flush();
		} while ( count( $ids ) === $chunk );
		if ( $written < $total_rows ) {
			// Limite de sécurité atteinte (ou lignes ajoutées pendant l'export) :
			// on le DIT dans le fichier, jamais un rapport partiel présenté complet.
			fputcsv( $out, array( sprintf(
				/* translators: 1: lignes exportées, 2: lignes existantes */
				__( 'EXPORT INCOMPLET : %1$d ligne(s) exportée(s) sur %2$d. Filtrez l’audit ou augmentez la limite (filtre cu_seo_export_max_rows).', 'cyberpush-ultra' ),
				$written,
				$total_rows
			), '', '', '', '' ), ';', '"', '' );
		}
		fclose( $out );
		exit;
	}

	/** Limite de sécurité de l'export CSV (lignes d'anomalies) ; 0 = sans limite. */
	const EXPORT_MAX_ROWS = 500000;

	/** Neutralise les formules lors de l’ouverture d’un export dans Excel/Calc. */
	private function csv_safe_cell( $value ) {
		$value = (string) $value;
		return preg_match( '/^[\x00-\x20]*[=+\-@]/u', $value ) ? "'" . $value : $value;
	}
}

/**
 * Petit utilitaire de troncature partagé (évite de dupliquer mb_substr).
 * Défini au niveau fichier pour rester accessible depuis le module.
 */
if ( ! function_exists( 'CU_SEO_Renderer_truncate' ) ) {
	function CU_SEO_Renderer_truncate( $str, $max ) {
		$str = (string) $str;
		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) && mb_strlen( $str ) > $max ) {
			return mb_substr( $str, 0, $max );
		}
		return strlen( $str ) > $max ? substr( $str, 0, $max ) : $str;
	}
}

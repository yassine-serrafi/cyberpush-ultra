<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module IndexNow — CyberPush Ultra
 * -----------------------------------------------------------------------------
 * Indexation instantanée « push » via le protocole ouvert IndexNow.
 *
 * Contrairement au scraping Google (mort en 2026 : JS obligatoire, CAPTCHA),
 * IndexNow est un protocole OFFICIEL et gratuit : on notifie directement les
 * moteurs d'un changement d'URL, une seule clé couvrant TOUT l'écosystème :
 *
 *   • SEO classique  → Bing, Yandex, Naver (Corée), Seznam (Tchéquie), Yep
 *   • GEO (moteurs IA) → Microsoft Copilot & ChatGPT Search s'appuient sur
 *     l'index Bing : pousser une URL via IndexNow accélère donc aussi son
 *     apparition dans les réponses des IA. IndexNow = SEO + GEO.
 *
 * Google ne participe pas au protocole (il garde sa propre découverte), mais
 * les moteurs participants se partagent automatiquement les URL soumises.
 *
 * Fonctionnement (discret et prévisible) :
 *   1. À l'ACTIVATION du module, une clé (hex, 32 car.) est générée, liée au
 *      domaine du site, et exposée dans {clé}.txt à la racine (fichier physique
 *      si possible, sinon point d'accès virtuel). Module désactivé : rien n'est
 *      servi et le fichier physique est retiré.
 *   2. Rien n'est envoyé si le site décourage l'indexation (Réglages › Lecture),
 *      hors environnement de production (WP_ENVIRONMENT_TYPE), ou si le domaine
 *      diffère de celui de la clé (copie de préproduction). Les contenus en
 *      noindex (Yoast, Rank Math, SEOPress, AIOSEO), protégés par mot de passe,
 *      de types exclus de la recherche ou produits WooCommerce masqués sont ignorés.
 *   3. Les publications sont mises en file et envoyées par WP-Cron (événement
 *      cu_cron_indexnow_flush) : l'enregistrement dans l'éditeur n'attend jamais
 *      le réseau. POST JSON { host, key, keyLocation, urlList[] } ; HTTP 200/202 = reçu.
 *   4. Anti-doublon 24 h : une seule option compacte (empreinte d'URL → date).
 *      Une URL qui disparaît (suppression, dépublication, changement d'adresse)
 *      est toujours signalée. Échec passager (délai, 429, 5xx) : nouvel essai
 *      automatique, borné (MAX_ATTEMPTS). Adresse de site non publique
 *      (localhost, IP privée, .test…) : rien n'est envoyé.
 */
class CU_IndexNow {

	private static $instance = null;

	/** @var array<string,int> Notifications collectées pendant la requête (« p:ID » ou « u:URL »). */
	private $pending = array();
	private $pending_hooked = false;

	/** @var array<int,bool>|null Produits WooCommerce masqués (calculé une fois). */
	private $hidden_products = null;
	/** @var bool|null Table AIOSEO présente ? */
	private $aioseo_table = null;

	/** Historique des soumissions (option) — plafonné. */
	const OPT_HISTORY = 'cu_indexnow_history';
	/** Statistiques cumulées + quota du jour (option). */
	const OPT_STATS   = 'cu_indexnow_stats';
	const HISTORY_MAX = 60;
	/** Nombre max d'URL détaillées mémorisées par entrée d'historique. */
	const HISTORY_URLS_MAX = 100;
	/** Limite d'URL par requête imposée par le protocole. */
	const MAX_URLS_PER_REQUEST = 10000;

	/** Option : dernière preuve de propriété réussie (liée à la clé). */
	const OPT_VERIFIED = 'cu_indexnow_verified';
	/** Option : domaine pour lequel la clé a été générée. */
	const OPT_KEY_HOST = 'cu_indexnow_key_host';
	/** Option : anti-doublon, empreinte courte (clé|URL) → horodatage du dernier envoi accepté. */
	const OPT_SENT     = 'cu_indexnow_sent';
	const SENT_TTL     = 86400;
	const SENT_MAX     = 20000;
	/** Option : file des notifications automatiques, vidée par WP-Cron. */
	const OPT_QUEUE    = 'cu_indexnow_queue';
	const QUEUE_MAX    = 10000;
	/** Événement WP-Cron ponctuel qui envoie la file. */
	const FLUSH_HOOK   = 'cu_cron_indexnow_flush';
	/** Transient : nombre d'URL publiques suivies (affichage). */
	const COUNT_CACHE  = 'cu_indexnow_known_count';
	/** Transient : tentatives déjà faites par entrée de file (échecs passagers). */
	const RETRY_CACHE  = 'cu_indexnow_retry';
	/** Nombre maximal d'envois d'une même notification (premier essai compris). */
	const MAX_ATTEMPTS = 5;

	/**
	 * Endpoints participants (registre officiel https://www.indexnow.org/searchengines.json).
	 * api.indexnow.org relaie vers TOUS les moteurs : c'est le choix par défaut
	 * recommandé. Les autres ciblent un moteur précis. La liste blanche des
	 * réglages (CU_Settings, enum « endpoint ») doit rester alignée.
	 */
	public static function endpoints() {
		return array(
			'api.indexnow.org'             => __( 'IndexNow (tous les moteurs) — recommandé', 'cyberpush-ultra' ),
			'www.bing.com'                 => __( 'Bing / Microsoft Copilot', 'cyberpush-ultra' ),
			'indexnow.amazonbot.amazon'    => __( 'Amazonbot', 'cyberpush-ultra' ),
			'yandex.com'                   => __( 'Yandex', 'cyberpush-ultra' ),
			'searchadvisor.naver.com'      => __( 'Naver (Corée)', 'cyberpush-ultra' ),
			'search.seznam.cz'             => __( 'Seznam (Tchéquie)', 'cyberpush-ultra' ),
			'indexnow.yep.com'             => __( 'Yep (Ahrefs)', 'cyberpush-ultra' ),
			'internetarchive.indexnow.org' => __( 'Internet Archive', 'cyberpush-ultra' ),
		);
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		// Fichier-clé virtuel : servi UNIQUEMENT module actif (contrôle très bon
		// marché sur les autres requêtes). Priorité 0 : avant la 404 de WordPress.
		add_action( 'init', array( $this, 'maybe_serve_key_file' ), 0 );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Activation / désactivation du module : clé et fichier physique suivent.
		add_action( 'add_option_' . CU_OPTION_SETTINGS, array( $this, 'on_settings_added' ), 10, 2 );
		add_action( 'update_option_' . CU_OPTION_SETTINGS, array( $this, 'on_settings_updated' ), 10, 2 );

		// Envoi différé de la file (toujours branché : un événement déjà planifié doit pouvoir se vider).
		add_action( self::FLUSH_HOOK, array( $this, 'process_queue' ) );

		// Soumission automatique à la publication / mise à jour d'un contenu.
		if ( $this->is_enabled() && CU_Settings::get( 'indexnow', 'auto_submit', true ) ) {
			add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 20, 3 );
			// Changement de slug d'un contenu publié : l'ancienne URL disparaît
			// (404 ou redirection) et doit être signalée, pas seulement la nouvelle.
			add_action( 'post_updated', array( $this, 'on_post_updated' ), 20, 3 );
			// Suppression définitive sans passer par la corbeille (EMPTY_TRASH_DAYS = 0,
			// WP-CLI…) : aucune transition de statut n'est déclenchée.
			add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 20, 2 );
		}
	}

	// -------------------------------------------------------------------------
	// Contenus éligibles
	// -------------------------------------------------------------------------

	/** Contenu suivi, publié et réellement public (sans mot de passe). */
	private function is_public_tracked_post( $post ) {
		return $post instanceof WP_Post
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& ! wp_is_post_revision( $post )
			&& ! wp_is_post_autosave( $post )
			&& in_array( $post->post_type, $this->tracked_post_types(), true );
	}

	/**
	 * Contenu indexable : pas de noindex posé par une extension SEO, pas de
	 * produit WooCommerce masqué. Lu au moment de l'ENVOI (les extensions SEO
	 * enregistrent leurs réglages après la transition de statut).
	 */
	private function post_is_indexable( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return false;
		}
		$id    = (int) $post->ID;
		// Yoast : '1' = noindex, '2' = index forcé, vide = réglage du type de contenu.
		$yoast = (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === $yoast || ( '2' !== $yoast && $this->yoast_type_noindex( $post->post_type ) ) ) {
			return false;
		}
		$rank_math = get_post_meta( $id, 'rank_math_robots', true );
		if ( ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) || ( is_string( $rank_math ) && false !== strpos( $rank_math, 'noindex' ) ) ) {
			return false;
		}
		// SEOPress : « yes » signifie « noindex ».
		if ( 'yes' === (string) get_post_meta( $id, '_seopress_robots_index', true ) ) {
			return false;
		}
		if ( $this->aioseo_noindex_ids( array( $id ) ) ) {
			return false;
		}
		if ( 'product' === $post->post_type && taxonomy_exists( 'product_visibility' )
			&& has_term( 'exclude-from-search', 'product_visibility', $post ) && has_term( 'exclude-from-catalog', 'product_visibility', $post ) ) {
			return false; // visibilité « Masqué »
		}
		return (bool) apply_filters( 'cu_indexnow_post_indexable', true, $post );
	}

	/** Yoast : type de contenu réglé en noindex par défaut (option wpseo_titles). */
	private function yoast_type_noindex( $post_type ) {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return false;
		}
		$titles = get_option( 'wpseo_titles', array() );
		return is_array( $titles ) && ! empty( $titles[ 'noindex-' . $post_type ] );
	}

	/** AIOSEO : contenus en noindex explicite (table aioseo_posts), requête groupée. */
	private function aioseo_noindex_ids( array $ids ) {
		if ( ! $ids || ! ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) ) {
			return array();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( null === $this->aioseo_table ) {
			$this->aioseo_table = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}
		if ( ! $this->aioseo_table ) {
			return array();
		}
		$ids      = array_map( 'intval', $ids );
		$in       = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$suppress = $wpdb->suppress_errors( true );
		$found    = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE post_id IN ({$in}) AND robots_default = 0 AND robots_noindex = 1", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $suppress );
		return array_map( 'intval', (array) $found );
	}

	/** Produits WooCommerce à visibilité « Masqué » (exclus de la recherche ET du catalogue). */
	private function hidden_product_ids() {
		if ( null !== $this->hidden_products ) {
			return $this->hidden_products;
		}
		$this->hidden_products = array();
		if ( ! taxonomy_exists( 'product_visibility' ) ) {
			return $this->hidden_products;
		}
		$search  = get_term_by( 'slug', 'exclude-from-search', 'product_visibility' );
		$catalog = get_term_by( 'slug', 'exclude-from-catalog', 'product_visibility' );
		if ( ! $search || ! $catalog ) {
			return $this->hidden_products;
		}
		$a = get_objects_in_term( (int) $search->term_id, 'product_visibility' );
		$b = get_objects_in_term( (int) $catalog->term_id, 'product_visibility' );
		if ( is_array( $a ) && is_array( $b ) ) {
			$this->hidden_products = array_fill_keys( array_map( 'intval', array_intersect( $a, $b ) ), true );
		}
		return $this->hidden_products;
	}

	/**
	 * Filtre groupé pour l'envoi global : retire les contenus en noindex et les
	 * produits masqués avec quelques requêtes (par blocs de 1000), sans charger
	 * toutes les métadonnées.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	private function filter_indexable_ids( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return $ids;
		}
		$drop   = $this->hidden_product_ids();
		$forced = array(); // Yoast « index » explicite (prime sur le réglage du type)
		$keys   = array( '_yoast_wpseo_meta-robots-noindex', 'rank_math_robots', '_seopress_robots_index' );
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN (%s,%s,%s)", array_merge( $chunk, $keys ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $r ) {
				$pid   = (int) $r['post_id'];
				$value = (string) $r['meta_value'];
				if ( '_yoast_wpseo_meta-robots-noindex' === $r['meta_key'] ) {
					if ( '1' === $value ) {
						$drop[ $pid ] = true;
					} elseif ( '2' === $value ) {
						$forced[ $pid ] = true;
					}
				} elseif ( 'rank_math_robots' === $r['meta_key'] && false !== strpos( $value, 'noindex' ) ) {
					$drop[ $pid ] = true;
				} elseif ( '_seopress_robots_index' === $r['meta_key'] && 'yes' === $value ) {
					$drop[ $pid ] = true;
				}
			}
			foreach ( $this->aioseo_noindex_ids( $chunk ) as $pid ) {
				$drop[ $pid ] = true;
			}
		}
		// Yoast : types de contenu en noindex par défaut.
		$noindex_types = array();
		foreach ( $this->tracked_post_types() as $type ) {
			if ( $this->yoast_type_noindex( $type ) ) {
				$noindex_types[] = $type;
			}
		}
		if ( $noindex_types ) {
			foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
				$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				$tin  = implode( ',', array_fill( 0, count( $noindex_types ), '%s' ) );
				$hits = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type IN ({$tin})", array_merge( $chunk, $noindex_types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( (array) $hits as $pid ) {
					if ( ! isset( $forced[ (int) $pid ] ) ) {
						$drop[ (int) $pid ] = true;
					}
				}
			}
		}
		$ids = array_values( array_filter( $ids, static function ( $id ) use ( $drop ) {
			return ! isset( $drop[ $id ] );
		} ) );
		return array_map( 'intval', (array) apply_filters( 'cu_indexnow_indexable_ids', $ids ) );
	}

	private function tracked_post_types() {
		// Tous les contenus publics et proposés à la recherche (produits et CPT
		// inclus), moins la liste d'exclusion WordPress du plugin.
		$tracked    = array_values( get_post_types( array( 'public' => true, 'exclude_from_search' => false ), 'names' ) );
		$excluded   = array_map( 'sanitize_key', (array) CU_Settings::get( 'wordpress', 'excluded_post_types', array() ) );
		$excluded[] = 'attachment';
		$tracked    = array_values( array_unique( array_filter( array_map( 'sanitize_key', $tracked ), static function ( $post_type ) use ( $excluded ) {
			return ! in_array( $post_type, $excluded, true ) && post_type_exists( $post_type ) && is_post_type_viewable( $post_type );
		} ) ) );
		$tracked = array_values( array_filter( (array) apply_filters( 'cu_indexnow_post_types', $tracked ), 'is_string' ) );
		return ! empty( $tracked ) ? $tracked : array( 'post', 'page' );
	}

	// -------------------------------------------------------------------------
	// Hooks de publication → file d'attente
	// -------------------------------------------------------------------------

	/**
	 * Publication, mise à jour, dépublication ou mise à la corbeille d'un contenu
	 * suivi. L'éligibilité (mot de passe, noindex, produit masqué) d'un contenu
	 * publié est évaluée à l'envoi ; l'URL qui disparaît est calculée maintenant.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		$is_published  = ( 'publish' === $new_status );
		$was_published = ( 'publish' === $old_status );
		if ( ! $is_published && ! $was_published ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->tracked_post_types(), true ) ) {
			return;
		}
		if ( $is_published ) {
			$this->enqueue( 'p:' . (int) $post->ID );
			return;
		}
		// Dépublication : jamais poussée si elle n'était pas indexable.
		if ( '' !== (string) $post->post_password || ! $this->post_is_indexable( $post ) ) {
			return;
		}
		// Il faut signaler l'URL PUBLIQUE qui disparaît. Au moment de la
		// transition, un brouillon n'a plus qu'un lien « ?p=ID » et un article mis
		// à la corbeille porte déjà le suffixe « __trashed » — ou « __trashed-2 »
		// quand un autre contenu du même nom est déjà dans la corbeille.
		$target              = clone $post;
		$target->post_status = 'publish';
		$target->post_name   = preg_replace( '/__trashed(?:-\d+)?$/', '', (string) $target->post_name );
		$url                 = get_permalink( $target );
		if ( $url ) {
			$this->enqueue( 'u:' . $url );
		}
	}

	public function on_post_updated( $post_id, $post_after, $post_before ) {
		if ( ! $this->is_public_tracked_post( $post_before ) || ! $this->is_public_tracked_post( $post_after ) ) {
			return; // les (dé)publications sont gérées par on_transition_post_status.
		}
		$old_url = get_permalink( $post_before );
		$new_url = get_permalink( $post_after );
		if ( $old_url && $new_url && $old_url !== $new_url && $this->post_is_indexable( $post_after ) ) {
			$this->enqueue( 'u:' . $old_url );
		}
	}

	public function on_before_delete_post( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( $this->is_public_tracked_post( $post ) && $this->post_is_indexable( $post ) ) {
			$url = get_permalink( $post );
			if ( $url ) {
				$this->enqueue( 'u:' . $url );
			}
		}
	}

	/** Collecte en mémoire ; une seule écriture de la file en fin de requête (édition groupée). */
	private function enqueue( $entry ) {
		if ( isset( $this->pending[ $entry ] ) ) {
			return;
		}
		$this->pending[ $entry ] = time();
		if ( ! $this->pending_hooked ) {
			$this->pending_hooked = true;
			add_action( 'shutdown', array( $this, 'persist_queue' ), 1 );
		}
	}

	/**
	 * Fin de requête : fusionne la file (option non autoload) et planifie un
	 * envoi WP-Cron immédiat. Aucun appel réseau ici, aucune coupure de la
	 * réponse (pas de fastcgi_finish_request) : les autres hooks shutdown
	 * gardent leur sortie.
	 */
	public function persist_queue() {
		if ( ! $this->pending ) {
			return;
		}
		wp_cache_delete( self::OPT_QUEUE, 'options' );
		$queue = get_option( self::OPT_QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		foreach ( $this->pending as $entry => $ts ) {
			if ( ! isset( $queue[ $entry ] ) ) {
				$queue[ $entry ] = (int) $ts;
			}
		}
		$this->pending = array();
		if ( count( $queue ) > self::QUEUE_MAX ) {
			$queue = array_slice( $queue, -self::QUEUE_MAX, null, true );
		}
		update_option( self::OPT_QUEUE, $queue, false );
		if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
			wp_schedule_single_event( time(), self::FLUSH_HOOK );
		}
	}

	/**
	 * WP-Cron : envoie la file (un seul appel IndexNow pour toutes les URL en attente).
	 *
	 * La file est retirée avant l'envoi (un second passage concurrent ne renvoie
	 * rien), mais une notification n'est PERDUE que si le moteur l'a acceptée
	 * ou refusée définitivement (4xx) : après un échec passager (délai, réseau,
	 * HTTP 429, 5xx), elle est remise en file et retentée plus tard, avec un
	 * délai croissant (5 min, 15 min, 1 h, 3 h) et au plus MAX_ATTEMPTS envois.
	 */
	public function process_queue() {
		wp_cache_delete( self::OPT_QUEUE, 'options' );
		$queue = get_option( self::OPT_QUEUE, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}
		delete_option( self::OPT_QUEUE ); // un second passage concurrent ne renverra rien
		if ( ! $this->is_enabled() || ! CU_Settings::get( 'indexnow', 'auto_submit', true ) ) {
			delete_transient( self::RETRY_CACHE );
			return;
		}
		$urls     = array();
		$gone     = array(); // URL qui disparaissent : jamais filtrées par l'anti-doublon
		$entry_of = array(); // URL → entrées de file correspondantes
		foreach ( array_keys( $queue ) as $entry ) {
			$entry = (string) $entry;
			if ( 0 === strpos( $entry, 'p:' ) ) {
				$post = get_post( (int) substr( $entry, 2 ) );
				if ( $this->is_public_tracked_post( $post ) && $this->post_is_indexable( $post ) ) {
					$url = get_permalink( $post );
					if ( $url ) {
						$urls[ $url ]       = $url;
						$entry_of[ $url ][] = $entry;
					}
				}
			} elseif ( 0 === strpos( $entry, 'u:' ) ) {
				$url = substr( $entry, 2 );
				if ( '' !== $url ) {
					$urls[ $url ]       = $url;
					$gone[ $url ]       = $url;
					$entry_of[ $url ][] = $entry;
				}
			}
		}
		// Contenus publiés que l'audit SEO a déjà vus en noindex (balise du thème,
		// en-tête HTTP…) : pas de notification contradictoire. Seul l'état connu
		// est consulté (aucune analyse supplémentaire).
		foreach ( $this->audit_noindex_urls( array_diff_key( $urls, $gone ) ) as $url ) {
			unset( $urls[ $url ] );
		}
		if ( ! $urls ) {
			$this->forget_attempts( array_keys( $queue ) );
			return;
		}
		$result = $this->submit( array_values( $urls ), 'auto', true, array_values( $gone ) );
		if ( ! empty( $result['retry'] ) ) {
			$failed = array();
			foreach ( $urls as $url ) {
				foreach ( isset( $entry_of[ $url ] ) ? $entry_of[ $url ] : array() as $entry ) {
					$failed[ $entry ] = isset( $queue[ $entry ] ) ? (int) $queue[ $entry ] : time();
				}
			}
			$this->requeue_failed( $failed );
			return;
		}
		$this->forget_attempts( array_keys( $queue ) );
	}

	/**
	 * Remet en file les entrées d'un envoi en échec passager, avec un compteur
	 * de tentatives (transient) et un nouvel essai planifié plus tard.
	 *
	 * @param array<string,int> $entries Entrée de file → date d'origine.
	 */
	private function requeue_failed( array $entries ) {
		$attempts = get_transient( self::RETRY_CACHE );
		$attempts = is_array( $attempts ) ? $attempts : array();
		$keep     = array();
		$dropped  = 0;
		$max_n    = 1;
		foreach ( $entries as $entry => $ts ) {
			$n = ( isset( $attempts[ $entry ] ) ? (int) $attempts[ $entry ] : 0 ) + 1;
			if ( $n >= self::MAX_ATTEMPTS ) {
				unset( $attempts[ $entry ] );
				$dropped++;
				continue;
			}
			$attempts[ $entry ] = $n;
			$keep[ $entry ]     = (int) $ts;
			$max_n              = max( $max_n, $n );
		}
		if ( $keep ) {
			// Relecture : des notifications ont pu arriver pendant l'envoi.
			wp_cache_delete( self::OPT_QUEUE, 'options' );
			$queue = get_option( self::OPT_QUEUE, array() );
			$queue = is_array( $queue ) ? $queue : array();
			foreach ( $keep as $entry => $ts ) {
				if ( ! isset( $queue[ $entry ] ) ) {
					$queue[ $entry ] = $ts;
				}
			}
			if ( count( $queue ) > self::QUEUE_MAX ) {
				$queue = array_slice( $queue, -self::QUEUE_MAX, null, true );
			}
			update_option( self::OPT_QUEUE, $queue, false );
			$delays = array( 1 => 5 * MINUTE_IN_SECONDS, 2 => 15 * MINUTE_IN_SECONDS, 3 => HOUR_IN_SECONDS, 4 => 3 * HOUR_IN_SECONDS );
			if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
				wp_schedule_single_event( time() + $delays[ min( 4, $max_n ) ], self::FLUSH_HOOK );
			}
		}
		if ( $attempts ) {
			set_transient( self::RETRY_CACHE, $attempts, 2 * DAY_IN_SECONDS );
		} else {
			delete_transient( self::RETRY_CACHE );
		}
		if ( $dropped ) {
			CU_Logger::log( 'seo', 'warning', sprintf(
				/* translators: 1: nombre de notifications, 2: nombre d'essais */
				__( 'IndexNow : %1$d notification(s) abandonnée(s) après %2$d tentatives (moteur indisponible). Utilisez « Pousser toutes mes URL » quand le service répond de nouveau.', 'cyberpush-ultra' ),
				$dropped,
				self::MAX_ATTEMPTS
			) );
		}
	}

	/** Oublie le compteur de tentatives d'entrées traitées (envoyées ou définitivement refusées). */
	private function forget_attempts( array $entries ) {
		$attempts = get_transient( self::RETRY_CACHE );
		if ( ! is_array( $attempts ) || ! $attempts ) {
			return;
		}
		foreach ( $entries as $entry ) {
			unset( $attempts[ (string) $entry ] );
		}
		if ( $attempts ) {
			set_transient( self::RETRY_CACHE, $attempts, 2 * DAY_IN_SECONDS );
		} else {
			delete_transient( self::RETRY_CACHE );
		}
	}

	/**
	 * URL que l'inventaire de l'audit SEO connaît déjà comme non indexables
	 * (analyse terminée, page servie en 200 avec un noindex dans le rendu ou
	 * l'en-tête HTTP). Lecture seule, par lots ; tables absentes = aucune.
	 *
	 * @param array<string,string> $urls
	 * @return string[]
	 */
	private function audit_noindex_urls( array $urls ) {
		if ( ! $urls || ! CU_DB::tables_exist_for( array( 'seo_pages' ) ) ) {
			return array();
		}
		global $wpdb;
		$by_hash = array();
		foreach ( $urls as $url ) {
			$by_hash[ md5( esc_url_raw( (string) $url ) ) ] = (string) $url;
		}
		$out = array();
		foreach ( array_chunk( array_keys( $by_hash ), 500 ) as $chunk ) {
			$ph       = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$suppress = $wpdb->suppress_errors( true );
			$found    = $wpdb->get_col( $wpdb->prepare(
				'SELECT url_hash FROM ' . CU_DB::table( 'seo_pages' ) . " WHERE url_hash IN ( {$ph} ) AND crawl_state = 'done' AND indexable = 0 AND http_status = 200", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$chunk
			) );
			$wpdb->suppress_errors( $suppress );
			foreach ( (array) $found as $hash ) {
				if ( isset( $by_hash[ $hash ] ) ) {
					$out[] = $by_hash[ $hash ];
				}
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// État / clé
	// -------------------------------------------------------------------------

	private function is_enabled() {
		return (bool) CU_Settings::get( 'indexnow', 'enabled', false );
	}

	/** Clé enregistrée et bien formée, sans jamais en créer une. */
	private function stored_key() {
		$key = trim( (string) CU_Settings::get( 'indexnow', 'key', '' ) );
		return preg_match( '/^[a-zA-Z0-9\-]{8,128}$/', $key ) ? $key : '';
	}

	/**
	 * Clé courante. Elle n'est créée (et le fichier écrit) que module ACTIF :
	 * un site qui n'utilise pas IndexNow n'expose rien.
	 */
	public function get_key() {
		$key = $this->stored_key();
		if ( '' === $key && $this->is_enabled() ) {
			$key = $this->generate_key();
		}
		return $key;
	}

	/**
	 * Génère une nouvelle clé, la lie au domaine actuel et (module actif) écrit
	 * le fichier physique {clé}.txt à la racine (best-effort ; le serveur virtuel
	 * prend le relais). L'ancien fichier-clé est retiré s'il est bien le nôtre.
	 */
	public function generate_key() {
		$previous = $this->stored_key();
		$bytes    = false;
		try {
			$bytes = random_bytes( 16 );
		} catch ( \Throwable $e ) {
			// Repli défensif pour les environnements dont la source aléatoire est indisponible.
		}
		$key = is_string( $bytes ) && 16 === strlen( $bytes )
			? bin2hex( $bytes )
			: substr( hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) ), 0, 32 );
		CU_Settings::update_module( 'indexnow', array( 'key' => $key ), false );
		$this->bind_key_host( $key );
		if ( $this->is_enabled() ) {
			$this->write_physical_key_file( $key );
		}
		if ( '' !== $previous && $previous !== $key ) {
			$this->remove_physical_key_file( $previous );
		}
		return $key;
	}

	/**
	 * Hôte non public : localhost, nom sans point, adresse IP privée, de
	 * bouclage ou réservée, domaine de premier niveau réservé ou non délégué
	 * (RFC 2606, 6761, 6762, 8375 et usages internes courants).
	 */
	public static function is_non_public_host( $host ) {
		$host = strtolower( rtrim( trim( (string) $host, " \t\n\r\0\x0B[]" ), '.' ) );
		if ( '' === $host ) {
			return false; // hôte introuvable : autre message dans submit()
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( 'localhost' === $host || false === strpos( $host, '.' ) ) {
			return true;
		}
		$tld = substr( (string) strrchr( $host, '.' ), 1 );
		if ( in_array( $tld, array( 'localhost', 'local', 'test', 'invalid', 'example', 'internal', 'lan', 'home', 'corp', 'localdomain', 'intranet', 'private' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/(?:^|\.)(?:example\.(?:com|net|org)|home\.arpa)$/', $host );
	}

	/** Domaine courant du site (home_url), en minuscules. */
	private function current_host() {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	private function bind_key_host( $key ) {
		update_option( self::OPT_KEY_HOST, array( 'key' => md5( (string) $key ), 'host' => $this->current_host() ), false );
	}

	/**
	 * Domaine pour lequel la clé a été générée. Une clé antérieure à 1.2.0 (ou
	 * importée) est liée au domaine actuel à sa première utilisation. Une copie
	 * de la base (préproduction) garde le domaine de production → envoi bloqué.
	 */
	private function bound_host( $key ) {
		$meta = get_option( self::OPT_KEY_HOST, array() );
		if ( is_array( $meta ) && isset( $meta['key'], $meta['host'] ) && hash_equals( (string) $meta['key'], md5( (string) $key ) ) ) {
			return strtolower( (string) $meta['host'] );
		}
		$this->bind_key_host( $key );
		return $this->current_host();
	}

	/**
	 * Raison pour laquelle rien ne doit partir, ou '' si l'envoi est permis.
	 * Filtre cu_indexnow_allow_environment( bool $allowed, string $env ).
	 */
	public function submission_blocker( $key = '' ) {
		if ( 1 !== (int) get_option( 'blog_public', 1 ) ) {
			return __( 'Le site demande aux moteurs de ne pas l’indexer (Réglages › Lecture) : IndexNow n’envoie rien.', 'cyberpush-ultra' );
		}
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( ! apply_filters( 'cu_indexnow_allow_environment', 'production' === $env, $env ) ) {
			/* translators: %s: type d'environnement */
			return sprintf( __( 'Environnement « %s » (WP_ENVIRONMENT_TYPE) : IndexNow n’envoie rien hors production.', 'cyberpush-ultra' ), $env );
		}
		// Site sur une adresse non publique (installation locale, IP privée,
		// domaine réservé) : les moteurs ne pourraient pas lire le fichier-clé,
		// et ces adresses n'ont rien à faire chez eux. WordPress vaut
		// « production » par défaut quand WP_ENVIRONMENT_TYPE n'est pas défini.
		$site_host = $this->current_host();
		if ( self::is_non_public_host( $site_host ) && ! apply_filters( 'cu_indexnow_allow_host', false, $site_host ) ) {
			/* translators: %s: hôte du site */
			return sprintf( __( 'L’adresse du site (%s) n’est pas publique (installation locale, adresse IP privée ou domaine réservé comme .test, .local, .localhost) : IndexNow n’envoie rien.', 'cyberpush-ultra' ), $site_host );
		}
		if ( '' !== (string) $key ) {
			$bound = $this->bound_host( $key );
			$host  = $this->current_host();
			if ( '' !== $bound && '' !== $host && $bound !== $host ) {
				/* translators: 1: domaine actuel, 2: domaine de la clé */
				return sprintf( __( 'Le domaine du site (%1$s) diffère de celui pour lequel la clé a été créée (%2$s) : copie de préproduction ou changement de domaine. Si ce site est bien la production, régénérez la clé.', 'cyberpush-ultra' ), $host, $bound );
			}
		}
		return '';
	}

	/**
	 * URL publique du fichier-clé (keyLocation transmis aux moteurs). Toujours
	 * sur le même hôte que le site → propriété vérifiable pour toutes nos URL.
	 */
	public function key_location( $key = null ) {
		$key = $key ? $key : $this->get_key();
		return home_url( '/' . $key . '.txt' );
	}

	/**
	 * Écrit physiquement le fichier-clé à la racine WordPress. Best-effort : sur
	 * hébergement en lecture seule, on échoue silencieusement et le point d'accès
	 * virtuel (maybe_serve_key_file) sert le même contenu à la même URL.
	 */
	private function write_physical_key_file( $key ) {
		if ( ! preg_match( '/^[a-zA-Z0-9\-]{8,128}$/', (string) $key ) ) {
			return false;
		}
		$path = trailingslashit( ABSPATH ) . $key . '.txt';
		if ( file_exists( $path ) ) {
			return true;
		}
		return (bool) @file_put_contents( $path, $key ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/** Supprime uniquement un fichier-clé dont le contenu est bien la clé. */
	private function remove_physical_key_file( $key ) {
		$key = (string) $key;
		if ( ! preg_match( '/^[a-zA-Z0-9\-]{8,128}$/', $key ) ) {
			return;
		}
		$path = trailingslashit( ABSPATH ) . $key . '.txt';
		if ( is_file( $path ) && trim( (string) @file_get_contents( $path ) ) === $key ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/** Réglages créés pour la première fois (import, installation) avec IndexNow actif. */
	public function on_settings_added( $option, $value ) {
		if ( $this->enabled_in( $value ) ) {
			$this->on_module_enabled();
		}
	}

	/** Bascule du module : la clé et le fichier suivent l'état actif/inactif. */
	public function on_settings_updated( $old, $new ) {
		$was = $this->enabled_in( $old );
		$is  = $this->enabled_in( $new );
		if ( $was === $is ) {
			return;
		}
		if ( $is ) {
			$this->on_module_enabled();
			return;
		}
		$key = $this->key_in( $new );
		$this->remove_physical_key_file( '' !== $key ? $key : $this->key_in( $old ) );
		delete_option( self::OPT_QUEUE );
		wp_clear_scheduled_hook( self::FLUSH_HOOK );
		delete_transient( self::COUNT_CACHE );
		delete_transient( self::RETRY_CACHE );
	}

	private function on_module_enabled() {
		$key = $this->stored_key();
		if ( '' === $key ) {
			$this->generate_key(); // écrit le fichier et lie le domaine
			return;
		}
		$this->bound_host( $key );
		$this->write_physical_key_file( $key );
	}

	private function enabled_in( $all ) {
		return is_array( $all ) && isset( $all['indexnow'] ) && is_array( $all['indexnow'] ) && ! empty( $all['indexnow']['enabled'] )
			&& ! in_array( $all['indexnow']['enabled'], array( 'false', '0', 'off', 'no' ), true );
	}

	private function key_in( $all ) {
		return ( is_array( $all ) && isset( $all['indexnow']['key'] ) && is_scalar( $all['indexnow']['key'] ) ) ? trim( (string) $all['indexnow']['key'] ) : '';
	}

	/**
	 * Désactivation de l'extension (appelée par CU_Deactivator) : retire le
	 * fichier-clé physique s'il est bien le nôtre et l'envoi planifié. La clé
	 * reste enregistrée ; le fichier sera réécrit au prochain envoi.
	 */
	public static function on_deactivate() {
		$self = self::instance();
		$self->remove_physical_key_file( $self->stored_key() );
		wp_clear_scheduled_hook( self::FLUSH_HOOK );
	}

	/**
	 * Sert le fichier-clé « virtuellement » quand la requête vise /{clé}.txt,
	 * module actif uniquement. Indispensable sur les hébergements où le plugin
	 * ne peut pas écrire à la racine.
	 */
	public function maybe_serve_key_file() {
		if ( empty( $_SERVER['REQUEST_URI'] ) || ! $this->is_enabled() ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '.txt' !== strtolower( substr( $path, -4 ) ) ) {
			return; // chemin rapide : toutes les autres requêtes
		}
		$key = $this->stored_key();
		if ( '' === $key ) {
			return;
		}
		$expected_path = wp_parse_url( $this->key_location( $key ), PHP_URL_PATH );
		$path          = '/' . ltrim( rawurldecode( $path ), '/' );
		$expected_path = '/' . ltrim( rawurldecode( (string) $expected_path ), '/' );
		if ( $path !== $expected_path ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo esc_html( $key );
		exit;
	}

	// -------------------------------------------------------------------------
	// Soumission
	// -------------------------------------------------------------------------

	/** URL http(s) bien formée, sans résolution DNS (wp_http_validate_url en ferait une par URL). */
	private function is_http_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		return (bool) preg_match( '/^(?:\[[0-9a-f:.]+\]|[^\s\/\\\\?#@:\[\]<>"\'`]+)$/iu', (string) $parts['host'] );
	}

	/** Empreinte anti-doublon (liée à la clé : une nouvelle clé repart de zéro). */
	private function sent_hash( $key, $url ) {
		return substr( md5( $key . '|' . $url ), 0, 12 );
	}

	/** Carte anti-doublon purgée des envois de plus de 24 h. */
	private function sent_map() {
		$map = get_option( self::OPT_SENT, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$limit = time() - self::SENT_TTL;
		foreach ( $map as $hash => $ts ) {
			if ( (int) $ts < $limit ) {
				unset( $map[ $hash ] );
			}
		}
		return $map;
	}

	private function save_sent_map( array $map ) {
		if ( count( $map ) > self::SENT_MAX ) {
			arsort( $map );
			$map = array_slice( $map, 0, self::SENT_MAX, true );
		}
		update_option( self::OPT_SENT, $map, false );
	}

	/**
	 * Soumet une liste d'URL au moteur IndexNow configuré.
	 *
	 * @param array    $urls          URL à soumettre.
	 * @param string   $reason        'manual' | 'auto' | 'bulk' (journalisation).
	 * @param bool     $respect_dedup Ignore les URL déjà soumises depuis <24 h.
	 * @param string[] $always        URL qui DISPARAISSENT (suppression, dépublication,
	 *                                changement d'adresse) : jamais retenues par
	 *                                l'anti-doublon, et retirées de celui-ci après
	 *                                envoi (une republication sera de nouveau signalée).
	 * @return array{success:bool, submitted:int, skipped:int, code:int, message:string, engine:string, retry?:bool}
	 */
	public function submit( $urls, $reason = 'manual', $respect_dedup = false, $always = array() ) {
		if ( ! $this->is_enabled() ) {
			return $this->fail( __( 'IndexNow est désactivé dans les réglages.', 'cyberpush-ultra' ) );
		}

		$key = $this->get_key();
		if ( '' === $key ) {
			return $this->fail( __( 'Aucune clé IndexNow disponible.', 'cyberpush-ultra' ) );
		}

		$blocked = $this->submission_blocker( $key );
		if ( '' !== $blocked ) {
			if ( 'auto' === $reason ) {
				CU_Logger::log( 'seo', 'info', __( 'IndexNow : envoi automatique ignoré.', 'cyberpush-ultra' ), array( 'reason' => $blocked ) );
			}
			$result            = $this->fail( $blocked );
			$result['blocked'] = true;
			return $result;
		}

		$host = $this->current_host();
		if ( '' === $host ) {
			return $this->fail( __( 'Hôte du site introuvable.', 'cyberpush-ultra' ) );
		}

		// Le fichier-clé est servi dans le dossier du site : le protocole n'accepte
		// que les URL situées sous ce dossier (sinon HTTP 422 côté moteur).
		$home_path = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

		// Normalisation : URL valides, même hôte et même dossier, dédoublonnées.
		$sent    = $this->sent_map();
		$clean   = array();
		$invalid = 0;
		$recent  = 0;
		$gone    = array();
		foreach ( (array) $always as $raw ) {
			$gone[ esc_url_raw( trim( (string) $raw ) ) ] = true;
		}
		foreach ( (array) $urls as $raw ) {
			$raw = trim( (string) $raw );
			// Un chemin du site (« /mon-article/ ») est complété avec l'adresse du site.
			if ( '' !== $raw && '/' === $raw[0] && 0 !== strpos( $raw, '//' ) ) {
				$raw = home_url( $raw );
			}
			$url = esc_url_raw( $raw );
			if ( '' === $url || ! $this->is_http_url( $url ) ) {
				$invalid++;
				continue;
			}
			// L'URL doit appartenir au domaine du site (sinon HTTP 422 côté moteur).
			if ( 0 !== strcasecmp( (string) wp_parse_url( $url, PHP_URL_HOST ), $host ) ) {
				$invalid++;
				continue;
			}
			$url_path = trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			if ( '/' !== $home_path && 0 !== strpos( $url_path, $home_path ) ) {
				$invalid++;
				continue;
			}
			if ( $respect_dedup && ! isset( $gone[ $url ] ) && isset( $sent[ $this->sent_hash( $key, $url ) ] ) ) {
				$recent++;
				continue;
			}
			$clean[ $url ] = $url;
		}

		$skipped = $invalid + $recent;
		$clean   = array_values( $clean );
		if ( empty( $clean ) ) {
			$message = ( $recent > 0 && 0 === $invalid )
				? sprintf(
					/* translators: %d = nombre d'URL déjà envoyées. */
					_n( '%d URL déjà poussée il y a moins de 24 h — rien de nouveau à envoyer.', '%d URL déjà poussées il y a moins de 24 h — rien de nouveau à envoyer.', $recent, 'cyberpush-ultra' ),
					$recent
				)
				: __( 'Aucune URL valide à soumettre (adresses de ce site uniquement).', 'cyberpush-ultra' );
			$result           = $this->fail( $message, $skipped );
			$result['recent'] = $recent;
			return $result;
		}
		if ( count( $clean ) > self::MAX_URLS_PER_REQUEST ) {
			$skipped += count( $clean ) - self::MAX_URLS_PER_REQUEST;
			$clean    = array_slice( $clean, 0, self::MAX_URLS_PER_REQUEST );
		}

		// Fichier physique supprimé (désactivation de l'extension…) : recréé sans bruit.
		$this->write_physical_key_file( $key );

		$endpoint = $this->endpoint_host();
		$response = wp_remote_post(
			'https://' . $endpoint . '/indexnow',
			array(
				// Envoi automatique (WP-Cron) : court ; manuel : l'administrateur attend la réponse.
				'timeout'             => 'auto' === $reason ? 8 : 15,
				'limit_response_size' => 64 * KB_IN_BYTES,
				'headers'             => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'host'        => $host,
						'key'         => $key,
						'keyLocation' => $this->key_location( $key ),
						'urlList'     => $clean,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = $this->fail( sprintf( __( 'Endpoint injoignable : %s', 'cyberpush-ultra' ), $response->get_error_message() ), $skipped );
			if ( 'auto' === $reason ) {
				$result['message'] .= ' ' . __( 'Nouvel essai automatique plus tard.', 'cyberpush-ultra' );
			}
			$result['retry'] = true; // échec passager (délai, réseau)
			$this->record( $reason, $clean, 0, $result['message'], $endpoint, $skipped );
			return $result;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$message = $this->message_for_code( $code, wp_remote_retrieve_body( $response ) );
		$success = ( 200 === $code || 202 === $code );
		// 429 (limite du moteur) et 5xx : passagers → nouvel essai. Les autres
		// 4xx (clé refusée, URL non conformes) ne changeraient pas en réessayant.
		$retry   = ! $success && ( 429 === $code || $code >= 500 );
		if ( $retry && 'auto' === $reason ) {
			$message .= ' ' . __( 'Nouvel essai automatique plus tard.', 'cyberpush-ultra' );
		}

		if ( $success ) {
			// Anti-doublon : une seule écriture pour tout le lot. Une URL qui
			// disparaît en sort : si elle est republiée, elle sera de nouveau signalée.
			$now = time();
			foreach ( $clean as $url ) {
				if ( isset( $gone[ $url ] ) ) {
					unset( $sent[ $this->sent_hash( $key, $url ) ] );
				} else {
					$sent[ $this->sent_hash( $key, $url ) ] = $now;
				}
			}
			$this->save_sent_map( $sent );
			$this->bump_stats( count( $clean ) );
			// HTTP 200 = le moteur a lu et validé le fichier-clé : propriété prouvée.
			if ( 200 === $code ) {
				$this->mark_verified( $key );
			}
			CU_Logger::log( 'seo', 'info', sprintf( __( 'IndexNow : %1$d URL soumise(s) à %2$s (HTTP %3$d)', 'cyberpush-ultra' ), count( $clean ), $endpoint, $code ), array( 'engine' => $endpoint, 'count' => count( $clean ) ) );
		} else {
			CU_Logger::log( 'seo', 'warning', sprintf( __( 'IndexNow : échec (HTTP %1$d) vers %2$s — %3$s', 'cyberpush-ultra' ), $code, $endpoint, $message ), array( 'engine' => $endpoint, 'code' => $code ) );
		}

		$this->record( $reason, $clean, $code, $message, $endpoint, $skipped );

		return array(
			'success'   => $success,
			'submitted' => $success ? count( $clean ) : 0,
			'skipped'   => $skipped,
			'recent'    => $recent,
			'code'      => $code,
			'message'   => $message,
			'engine'    => $endpoint,
			'retry'     => $retry,
		);
	}

	private function endpoint_host() {
		$endpoint = (string) CU_Settings::get( 'indexnow', 'endpoint', 'api.indexnow.org' );
		$allowed  = array_keys( self::endpoints() );
		return in_array( $endpoint, $allowed, true ) ? $endpoint : 'api.indexnow.org';
	}

	/**
	 * Traduit le code HTTP renvoyé par un endpoint IndexNow en message lisible.
	 * (Codes documentés par le protocole.)
	 */
	private function message_for_code( $code, $body = '' ) {
		switch ( $code ) {
			case 200:
				return __( 'URL reçues et acceptées.', 'cyberpush-ultra' );
			case 202:
				return __( 'URL reçues — validation de la clé en cours.', 'cyberpush-ultra' );
			case 400:
				return __( 'Requête invalide (format des URL).', 'cyberpush-ultra' );
			case 403:
				return __( 'Clé refusée : le fichier de vérification est introuvable ou incorrect.', 'cyberpush-ultra' );
			case 422:
				return __( 'URL non conformes (elles doivent appartenir à ce domaine) ou clé/keyLocation incohérents.', 'cyberpush-ultra' );
			case 429:
				return __( 'Trop de requêtes : espacez les envois (limite quotidienne atteinte).', 'cyberpush-ultra' );
			default:
				$body = trim( wp_strip_all_tags( (string) $body ) );
				return $code
					? sprintf( __( 'Réponse HTTP %1$d%2$s', 'cyberpush-ultra' ), $code, $body ? ' — ' . CU_Text::substr( $body, 0, 140 ) : '' )
					: __( 'Aucune réponse du serveur.', 'cyberpush-ultra' );
		}
	}

	/** Mémorise une preuve de propriété réussie pour la clé courante. */
	private function mark_verified( $key ) {
		update_option( self::OPT_VERIFIED, array( 'key_hash' => md5( (string) $key ), 'time' => current_time( 'mysql' ) ), false );
	}

	/** Date de la dernière preuve de propriété de la clé courante, ou ''. */
	private function verified_at( $key ) {
		$state = get_option( self::OPT_VERIFIED, array() );
		if ( is_array( $state ) && isset( $state['key_hash'], $state['time'] ) && hash_equals( (string) $state['key_hash'], md5( (string) $key ) ) ) {
			return sanitize_text_field( (string) $state['time'] );
		}
		return '';
	}

	private function fail( $message, $skipped = 0 ) {
		return array(
			'success'   => false,
			'submitted' => 0,
			'skipped'   => (int) $skipped,
			'code'      => 0,
			'message'   => $message,
			'engine'    => $this->endpoint_host(),
		);
	}

	// -------------------------------------------------------------------------
	// Statistiques & historique
	// -------------------------------------------------------------------------

	public function get_stats() {
		$stats = get_option( self::OPT_STATS, array() );
		$today = current_time( 'Y-m-d' );
		$day_count = ( isset( $stats['day'] ) && $stats['day'] === $today && isset( $stats['day_count'] ) ) ? (int) $stats['day_count'] : 0;
		return array(
			'total'       => isset( $stats['total'] ) ? (int) $stats['total'] : 0,
			'sends'       => isset( $stats['sends'] ) ? (int) $stats['sends'] : 0,
			'day_count'   => $day_count,
			'last_submit' => isset( $stats['last_submit'] ) ? sanitize_text_field( (string) $stats['last_submit'] ) : '',
		);
	}

	/**
	 * Incrémente les compteurs cumulés après un envoi RÉELLEMENT accepté.
	 * `total` = URL effectivement notifiées ; `sends` = nombre d'envois.
	 */
	private function bump_stats( $count ) {
		$stats = get_option( self::OPT_STATS, array() );
		$stats = is_array( $stats ) ? $stats : array();
		$today = current_time( 'Y-m-d' );
		if ( ! isset( $stats['day'] ) || $stats['day'] !== $today ) {
			$stats['day']       = $today;
			$stats['day_count'] = 0;
		}
		$stats['total']       = ( isset( $stats['total'] ) ? (int) $stats['total'] : 0 ) + (int) $count;
		$stats['sends']       = ( isset( $stats['sends'] ) ? (int) $stats['sends'] : 0 ) + 1;
		$stats['day_count']   = (int) $stats['day_count'] + (int) $count;
		$stats['last_submit'] = current_time( 'mysql' );
		update_option( self::OPT_STATS, $stats, false );
	}

	/**
	 * Journalise un envoi. On mémorise la LISTE des URL (plafonnée pour ne pas
	 * faire enfler l'option) afin que l'historique montre exactement ce qui est parti.
	 */
	private function record( $reason, $urls, $code, $message, $engine, $skipped = 0 ) {
		// Réutilise la version bornée/nettoyée pour que les anciennes entrées très
		// volumineuses ne soient pas réécrites indéfiniment dans l'option.
		$history = $this->get_history();
		$urls    = array_values( (array) $urls );
		$stored  = array_slice( $urls, 0, self::HISTORY_URLS_MAX );
		array_unshift(
			$history,
			array(
				'time'      => current_time( 'mysql' ),
				'reason'    => $reason,
				'count'     => count( $urls ),
				'sample'    => (string) reset( $urls ),
				'urls'      => $stored,
				'truncated' => count( $urls ) > count( $stored ),
				'skipped'   => (int) $skipped,
				'code'      => (int) $code,
				'message'   => $message,
				'engine'    => $engine,
				'ok'        => ( 200 === (int) $code || 202 === (int) $code ),
			)
		);
		$history = array_slice( $history, 0, self::HISTORY_MAX );
		update_option( self::OPT_HISTORY, $history, false );
	}

	/** Historique nettoyé. Validation des URL sans DNS : rapide même après un changement de domaine. */
	public function get_history() {
		$history = get_option( self::OPT_HISTORY, array() );
		if ( ! is_array( $history ) ) return array();
		$out = array();
		foreach ( array_slice( $history, 0, self::HISTORY_MAX ) as $entry ) {
			if ( ! is_array( $entry ) ) continue;
			$urls = array();
			foreach ( (array) ( $entry['urls'] ?? array() ) as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( $url && $this->is_http_url( $url ) ) $urls[] = $url;
				if ( count( $urls ) >= self::HISTORY_URLS_MAX ) break;
			}
			if ( empty( $urls ) && ! empty( $entry['sample'] ) ) {
				$sample = esc_url_raw( (string) $entry['sample'] );
				if ( $sample && $this->is_http_url( $sample ) ) $urls[] = $sample;
			}
			$count = min( self::MAX_URLS_PER_REQUEST, max( count( $urls ), isset( $entry['count'] ) ? (int) $entry['count'] : 0 ) );
			$entry['urls']      = $urls;
			$entry['count']     = $count;
			$entry['sample']    = ! empty( $urls ) ? $urls[0] : '';
			$entry['truncated'] = ! empty( $entry['truncated'] ) || $count > count( $urls );
			$entry['time']      = sanitize_text_field( (string) ( $entry['time'] ?? '' ) );
			$entry['reason']    = sanitize_key( (string) ( $entry['reason'] ?? '' ) );
			$entry['skipped']   = max( 0, (int) ( $entry['skipped'] ?? 0 ) );
			$entry['code']      = max( 0, min( 599, (int) ( $entry['code'] ?? 0 ) ) );
			$entry['message']   = sanitize_text_field( (string) ( $entry['message'] ?? '' ) );
			$engine             = (string) ( $entry['engine'] ?? '' );
			$entry['engine']    = isset( self::endpoints()[ $engine ] ) ? $engine : '';
			$entry['ok']        = in_array( $entry['code'], array( 200, 202 ), true );
			$out[] = $entry;
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Routes REST
	// -------------------------------------------------------------------------

	public function register_routes() {
		$ns = CU_REST_NAMESPACE;

		register_rest_route( $ns, '/indexnow/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_status' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $ns, '/indexnow/submit', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_submit' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $ns, '/indexnow/submit-all', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_submit_all' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $ns, '/indexnow/regenerate-key', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_regenerate_key' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( $ns, '/indexnow/verify', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_verify' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	public function admin_permission() {
		return current_user_can( 'manage_options' );
	}

	public function rest_status() {
		nocache_headers();
		$enabled = $this->is_enabled();
		// Module inactif : aucune clé n'est créée ni exposée.
		$key      = $enabled ? $this->get_key() : $this->stored_key();
		$endpoint = $this->endpoint_host();

		$queue  = get_option( self::OPT_QUEUE, array() );
		$queue  = is_array( $queue ) ? $queue : array();
		$oldest = $queue ? min( array_map( 'intval', $queue ) ) : 0;
		if ( $queue && $enabled && ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
			wp_schedule_single_event( time(), self::FLUSH_HOOK ); // file orpheline : replanifiée
		}

		return rest_ensure_response( array(
			'enabled'        => $enabled,
			'auto_submit'    => (bool) CU_Settings::get( 'indexnow', 'auto_submit', true ),
			'key_masked'     => '' !== $key ? $this->mask_key( $key ) : '',
			'key_location'   => ( $enabled && '' !== $key ) ? $this->key_location( $key ) : '',
			'verified_at'    => '' !== $key ? $this->verified_at( $key ) : '',
			'endpoint'       => $endpoint,
			'endpoints'      => self::endpoints(),
			'host'           => $this->current_host(),
			'key_host'       => ( $enabled && '' !== $key ) ? $this->bound_host( $key ) : '',
			'blocked_reason' => $enabled ? $this->submission_blocker( $key ) : '',
			'queue'          => array(
				'count'  => count( $queue ),
				'oldest' => $oldest ? wp_date( 'Y-m-d H:i:s', $oldest ) : '',
			),
			'stats'          => $this->get_stats(),
			'history'        => $this->get_history(),
			'known_urls'     => $enabled ? $this->count_known_urls() : null,
		) );
	}

	private function mask_key( $key ) {
		$len = strlen( $key );
		if ( $len <= 10 ) {
			return $key;
		}
		return substr( $key, 0, 6 ) . str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	public function rest_submit( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$urls = array();

		if ( isset( $body['urls'] ) && is_array( $body['urls'] ) ) {
			$urls = $body['urls'];
		} elseif ( isset( $body['urls'] ) && is_string( $body['urls'] ) ) {
			$urls = preg_split( '/[\r\n,]+/', $body['urls'] );
		}

		if ( empty( $urls ) ) {
			return new WP_Error( 'cu_indexnow_empty', __( 'Aucune URL fournie.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}

		if ( count( $urls ) > self::MAX_URLS_PER_REQUEST ) {
			return new WP_Error( 'cu_indexnow_too_many', sprintf( __( 'Maximum %d URL par envoi.', 'cyberpush-ultra' ), self::MAX_URLS_PER_REQUEST ), array( 'status' => 400 ) );
		}

		// Envoi manuel = demande délibérée (souvent juste après une mise à jour) :
		// le protocole autorise à re-signaler une URL modifiée, l'anti-doublon 24 h
		// ne s'applique donc qu'aux envois automatiques et globaux.
		return rest_ensure_response( $this->submit( $urls, 'manual', false ) );
	}

	/**
	 * Soumet en une fois les permaliens des contenus publiés et indexables.
	 * Respecte le plafond du protocole (10 000) ET l'anti-doublon 24 h ; passé
	 * `force=1`, on ignore l'anti-doublon (re-soumission volontaire).
	 */
	public function rest_submit_all( WP_REST_Request $request ) {
		if ( ! $this->is_enabled() ) {
			return rest_ensure_response( $this->fail( __( 'IndexNow est désactivé dans les réglages.', 'cyberpush-ultra' ) ) );
		}
		$blocked = $this->submission_blocker( $this->get_key() );
		if ( '' !== $blocked ) {
			$res            = $this->fail( $blocked );
			$res['blocked'] = true;
			return rest_ensure_response( $res );
		}
		$urls = $this->collect_known_urls();
		if ( empty( $urls ) ) {
			return new WP_Error( 'cu_indexnow_no_urls', __( 'Aucune URL publiée à soumettre pour le moment.', 'cyberpush-ultra' ), array( 'status' => 404 ) );
		}
		$force = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$res   = $this->submit( $urls, 'bulk', ! $force );
		// Toutes les URL viennent d'être poussées → rien de nouveau à envoyer
		// (information, pas une erreur ; le message vient de submit()).
		if ( ! $res['success'] && 0 === (int) $res['code'] && ! empty( $res['recent'] ) ) {
			$res['all_recent'] = true;
		}
		return rest_ensure_response( $res );
	}

	public function rest_regenerate_key() {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'cu_indexnow_disabled', __( 'Activez d’abord le module IndexNow.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$key = $this->generate_key();
		CU_Logger::log( 'seo', 'info', __( 'Clé IndexNow régénérée.', 'cyberpush-ultra' ), array( 'host' => $this->current_host() ) );
		return rest_ensure_response( array(
			'success'      => true,
			'key_masked'   => $this->mask_key( $key ),
			'key_location' => $this->key_location( $key ),
		) );
	}

	/**
	 * Vérifie que le fichier-clé est réellement accessible publiquement à
	 * keyLocation et qu'il renvoie bien la clé (preuve de propriété OK).
	 */
	public function rest_verify() {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'cu_indexnow_disabled', __( 'Activez d’abord le module IndexNow : le fichier-clé n’est servi que module actif.', 'cyberpush-ultra' ), array( 'status' => 400 ) );
		}
		$key      = $this->get_key();
		$location = $this->key_location( $key );
		// Aucune redirection : la preuve doit être servie exactement à keyLocation
		// et ne doit jamais permettre au serveur de suivre une cible externe.
		$response = wp_remote_get( $location, array( 'timeout' => 12, 'redirection' => 0, 'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), 'limit_response_size' => 1024, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => sprintf( __( 'Fichier-clé injoignable : %s', 'cyberpush-ultra' ), $response->get_error_message() ), 'location' => $location ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( wp_remote_retrieve_body( $response ) );

		if ( 200 === $code && $body === $key ) {
			$this->mark_verified( $key );
			return rest_ensure_response( array( 'success' => true, 'message' => __( 'Fichier-clé accessible et valide — propriété vérifiée.', 'cyberpush-ultra' ), 'location' => $location, 'verified_at' => $this->verified_at( $key ) ) );
		}
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => sprintf( __( 'Le fichier-clé est redirigé (HTTP %d) : servez-le directement à cette adresse, certains moteurs ne suivent pas les redirections. Vérifiez une redirection http→https ou www dans l’adresse du site (Réglages ▸ Général).', 'cyberpush-ultra' ), $code ), 'location' => $location ) );
		}
		if ( 200 === $code ) {
			return rest_ensure_response( array( 'success' => false, 'message' => __( 'Le fichier est accessible mais son contenu ne correspond pas à la clé.', 'cyberpush-ultra' ), 'location' => $location ) );
		}
		return rest_ensure_response( array( 'success' => false, 'message' => sprintf( __( 'Fichier-clé inaccessible (HTTP %d).', 'cyberpush-ultra' ), $code ), 'location' => $location ) );
	}

	// -------------------------------------------------------------------------
	// Collecte des URL connues
	// -------------------------------------------------------------------------

	/** Nombre d'URL publiques indexables (affichage, mis en cache 15 min). */
	private function count_known_urls() {
		$cached = get_transient( self::COUNT_CACHE );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$count = count( $this->collect_known_urls( true ) );
		set_transient( self::COUNT_CACHE, $count, 15 * MINUTE_IN_SECONDS );
		return $count;
	}

	/**
	 * URL à soumettre : la page d'accueil + les permaliens des contenus publiés,
	 * suivis et indexables (noindex et produits masqués exclus).
	 */
	private function collect_known_urls( $ids_only = false ) {
		$posts = get_posts( array(
			'post_type'              => $this->tracked_post_types(),
			'post_status'            => 'publish',
			'has_password'           => false, // contenus protégés : rien à explorer
			// Place pour la page d'accueil sans dépasser la limite du protocole.
			'posts_per_page'         => self::MAX_URLS_PER_REQUEST - 1,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'suppress_filters'       => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		) );
		$posts = $this->filter_indexable_ids( (array) $posts );
		if ( $ids_only ) {
			// Simple comptage pour l'interface : +1 pour la page d'accueil, sauf
			// si c'est une page statique déjà présente dans la liste.
			$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			return ( $front && in_array( $front, $posts, true ) ) ? $posts : array_merge( array( 0 ), $posts );
		}

		// La page d'accueil n'est pas un contenu quand elle liste les derniers
		// articles : sans elle, l'URL la plus importante du site n'était jamais poussée.
		$urls = array( home_url( '/' ) => home_url( '/' ) );
		foreach ( $posts as $pid ) {
			$link = get_permalink( $pid );
			if ( $link ) {
				$urls[ $link ] = $link; // page d'accueil statique : pas de doublon
			}
		}
		// Pages vues en noindex par l'audit SEO (rendu ou en-tête HTTP) : retirées.
		foreach ( $this->audit_noindex_urls( $urls ) as $noindex ) {
			unset( $urls[ $noindex ] );
		}
		return array_values( $urls );
	}
}

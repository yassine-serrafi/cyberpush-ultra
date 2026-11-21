<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vulnérabilités & versions : inventaire des composants réellement installés
 * (PHP, WordPress, extensions et thèmes actifs ou non, base de données, serveur
 * web, curl, ImageMagick), comparaison avec la base publique WPVulnerability et
 * cycle de maintenance de PHP (php.net). Le module INFORME : il n'installe ni ne
 * met à jour quoi que ce soit.
 *
 * Connexions externes (HTTPS uniquement, sans redirection, désactivables dans la
 * section Firewall › Vulnérabilités & versions) :
 *  - https://www.wpvulnerability.net/ : slug + version de chaque composant
 *    (aucune adresse du site, aucune donnée de visiteur, aucune clé) ;
 *  - https://www.php.net/releases/states.php (repli : endoflife.date) : dates de
 *    fin de support des branches PHP.
 * Aucune requête pendant la navigation des visiteurs : uniquement WP-Cron (une
 * fois par jour, par lots) et le bouton « Vérifier maintenant » d'un administrateur.
 *
 * Zéro faux positif évitable :
 *  - extensions / thèmes : la base renvoie TOUTES les failles connues du slug ;
 *    chacune est rapprochée de la version installée avec ses opérateurs exacts
 *    (lt, le, eq, ne, gt, ge, sémantique version_compare) ; une plage absente ou
 *    une faille « non corrigée » au-delà de la dernière version connue donne
 *    « À vérifier », jamais « Vulnérable » ;
 *  - extension dont l'en-tête Update URI désigne une autre source que
 *    wordpress.org, ou dont le nom diffère de celui de la base : « À vérifier »
 *    (un slug identique ne suffit pas à affirmer qu'il s'agit du même produit) ;
 *  - composants serveur : correctifs rétroportés possibles par l'hébergeur,
 *    l'alerte reste « d'après le numéro de version » ;
 *  - une panne de la source conserve le dernier résultat valide (daté) et ne
 *    déclenche jamais d'alerte.
 */
class CU_Vulns {

	const API            = 'https://www.wpvulnerability.net/';
	const PHP_STATES_URL = 'https://www.php.net/releases/states.php';
	const PHP_EOL_URL    = 'https://endoflife.date/api/php.json';

	const OPT_STATE    = 'cu_vuln_state';      // dernier résultat (non autochargé)
	const OPT_NOTIFIED = 'cu_vuln_notified';   // empreintes déjà signalées
	const OPT_SERVER   = 'cu_vuln_server_sw';  // serveur web vu sur une requête d'administrateur
	const HOOK         = 'cu_cron_vuln_sync';     // quotidien
	const HOOK_RESUME  = 'cu_cron_vuln_continue'; // reprise d'une synchronisation par lots
	const USER_DISMISS = 'cu_vuln_dismissed';

	/** Fraîcheur d'un composant déjà vérifié (la tâche passe toutes les 24 h). */
	const FRESH        = 20 * HOUR_IN_SECONDS;
	/** Budget d'une passe : au-delà, la suite est reprise une minute plus tard. */
	const PASS_SECONDS = 20;
	const PASS_FETCHES = 30;
	/** Failles détaillées gardées par composant (les compteurs restent exacts). */
	const KEEP_DETAILS = 60;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::HOOK, array( __CLASS__, 'cron_sync' ) );
		add_action( self::HOOK_RESUME, array( __CLASS__, 'cron_resume' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'remember_server_software' ) );
			add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
			add_action( 'admin_post_cu_vuln_dismiss', array( __CLASS__, 'handle_dismiss' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Réglages
	// -------------------------------------------------------------------------

	public static function online_enabled() {
		return (bool) CU_Settings::get( 'audit', 'vuln_online', true );
	}

	public static function email_enabled() {
		return (bool) CU_Settings::get( 'audit', 'vuln_email', true );
	}

	/** Planifie la vérification quotidienne (premier passage quelques minutes après). */
	public static function schedule( $first_delay = 600 ) {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + max( 60, (int) $first_delay ), 'daily', self::HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Inventaire local (aucun appel réseau)
	// -------------------------------------------------------------------------

	/**
	 * Composants installés, avec leur identifiant exact pour la base.
	 *
	 * @return array<string, array> clé => composant
	 */
	public static function inventory() {
		$items = array();

		// PHP : version réellement exécutée (le suffixe de distribution signale un
		// paquet qui peut contenir des correctifs rétroportés).
		$php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
		$items['php'] = array(
			'type'    => 'php',
			'slug'    => 'php',
			'name'    => 'PHP',
			'version' => $php_version,
			'raw'     => PHP_VERSION,
			'distro'  => '' !== (string) PHP_EXTRA_VERSION,
			'active'  => true,
			'branch'  => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
		);

		global $wp_version;
		$items['core'] = array(
			'type'    => 'core',
			'slug'    => 'wordpress',
			'name'    => 'WordPress',
			'version' => (string) $wp_version,
			'active'  => true,
		);

		// Base de données : MariaDB et MySQL ont des bases de failles distinctes.
		global $wpdb;
		$db_info = is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		if ( preg_match( '/mariadb/i', $db_info ) ) {
			if ( preg_match( '/(?:^|-)(\d{1,2}\.\d{1,2}\.\d{1,3})-mariadb/i', $db_info, $m ) || preg_match( '/(\d{1,2}\.\d{1,2}\.\d{1,3})/', preg_replace( '/^5\.5\.5-/', '', $db_info ), $m ) ) {
				$items['mariadb'] = array( 'type' => 'mariadb', 'slug' => 'mariadb', 'name' => 'MariaDB', 'version' => $m[1], 'raw' => $db_info, 'active' => true, 'distro' => (bool) preg_match( '/(ubuntu|deb|el\d|fc\d|cloud)/i', $db_info ) );
			}
		} elseif ( preg_match( '/^(\d{1,2}\.\d{1,2}\.\d{1,3})/', $db_info, $m ) ) {
			$items['mysql'] = array( 'type' => 'mysql', 'slug' => 'mysql', 'name' => 'MySQL', 'version' => $m[1], 'raw' => $db_info, 'active' => true, 'distro' => (bool) preg_match( '/(ubuntu|deb|el\d|fc\d|percona)/i', $db_info ) );
		}

		// Serveur web : uniquement si le nom ET la version sont publiés par le serveur.
		$server = self::server_software();
		if ( $server ) {
			$items[ $server['type'] ] = $server;
		}

		// Bibliothèques utilisées par WordPress, identifiées de façon fiable par PHP.
		if ( function_exists( 'curl_version' ) ) {
			$curl = curl_version();
			if ( is_array( $curl ) && ! empty( $curl['version'] ) && preg_match( '/^\d+\.\d+(\.\d+)?$/', (string) $curl['version'] ) ) {
				$items['curl'] = array( 'type' => 'curl', 'slug' => 'curl', 'name' => 'curl (bibliothèque)', 'version' => (string) $curl['version'], 'active' => true, 'distro' => true );
			}
		}
		if ( class_exists( 'Imagick' ) && is_callable( array( 'Imagick', 'getVersion' ) ) ) {
			try {
				$im = Imagick::getVersion();
				if ( is_array( $im ) && isset( $im['versionString'] ) && preg_match( '/ImageMagick\s+(\d+\.\d+\.\d+(?:-\d+)?)/', (string) $im['versionString'], $m ) ) {
					$items['imagemagick'] = array( 'type' => 'imagemagick', 'slug' => 'imagemagick', 'name' => 'ImageMagick', 'version' => $m[1], 'active' => true, 'distro' => true );
				}
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}

		// Extensions : actives ET inactives (leurs fichiers restent sur le serveur).
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		foreach ( (array) get_plugins() as $file => $data ) {
			$slug = false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			$slug = strtolower( (string) $slug );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,99}$/', $slug ) ) {
				continue;
			}
			$items[ 'plugin:' . $slug ] = array(
				'type'       => 'plugin',
				'slug'       => $slug,
				'file'       => $file,
				'name'       => wp_strip_all_tags( (string) $data['Name'] ),
				'version'    => trim( (string) $data['Version'] ),
				'active'     => is_plugin_active( $file ) || isset( $network[ $file ] ),
				'update_uri' => isset( $data['UpdateURI'] ) ? (string) $data['UpdateURI'] : '',
			);
		}

		// Thèmes : actifs (et parent du thème actif) ou non.
		$stylesheet = get_stylesheet();
		$template   = get_template();
		foreach ( (array) wp_get_themes() as $slug => $theme ) {
			$slug = strtolower( (string) $slug );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,99}$/', $slug ) ) {
				continue;
			}
			$items[ 'theme:' . $slug ] = array(
				'type'       => 'theme',
				'slug'       => $slug,
				'name'       => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
				'version'    => trim( (string) $theme->get( 'Version' ) ),
				'active'     => $slug === strtolower( $stylesheet ) || $slug === strtolower( $template ),
				'update_uri' => (string) $theme->get( 'UpdateURI' ),
			);
		}
		return $items;
	}

	/**
	 * Apache / nginx identifié par SERVER_SOFTWARE (« Apache/2.4.58 (Unix) »).
	 * Les tâches WP-Cron n'ont pas toujours cette variable : la dernière valeur
	 * vue sur une requête d'administrateur est conservée.
	 */
	private static function server_software() {
		$raw = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? substr( (string) $_SERVER['SERVER_SOFTWARE'], 0, 200 ) : '';
		if ( '' === $raw ) {
			$raw = (string) get_option( self::OPT_SERVER, '' );
		}
		if ( preg_match( '#^(apache|nginx)/(\d+\.\d+\.\d+)#i', trim( $raw ), $m ) ) {
			$type = strtolower( $m[1] );
			return array(
				'type'    => $type,
				'slug'    => $type,
				'name'    => 'apache' === $type ? 'Apache HTTPD' : 'nginx',
				'version' => $m[2],
				'raw'     => sanitize_text_field( $raw ),
				'active'  => true,
				'distro'  => (bool) preg_match( '/(ubuntu|debian|centos|red ?hat|el\d)/i', $raw ),
			);
		}
		return null;
	}

	/** Mémorise SERVER_SOFTWARE vu par un administrateur (pour les tâches planifiées). */
	public static function remember_server_software() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$raw = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( substr( (string) $_SERVER['SERVER_SOFTWARE'], 0, 200 ) ) : '';
		if ( '' !== $raw && get_option( self::OPT_SERVER ) !== $raw ) {
			update_option( self::OPT_SERVER, $raw, false );
		}
	}

	// -------------------------------------------------------------------------
	// Sources externes
	// -------------------------------------------------------------------------

	/**
	 * GET JSON sur une source autorisée : HTTPS, aucune redirection (pas de rebond
	 * vers une adresse interne), taille et durée bornées, aucune information sur
	 * le site (agent neutre).
	 *
	 * @return array|WP_Error Corps décodé.
	 */
	private static function get_json( $url, $timeout = 15 ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( 0 !== strpos( $url, 'https://' ) || ! in_array( $host, array( 'www.wpvulnerability.net', 'www.php.net', 'endoflife.date' ), true ) ) {
			return new WP_Error( 'cu_vuln_url', 'URL non autorisée.' );
		}
		$response = wp_safe_remote_get( $url, array(
			'timeout'             => $timeout,
			'redirection'         => 0,
			'limit_response_size' => 4 * MB_IN_BYTES,
			'user-agent'          => 'CyberPush-Ultra/' . ( defined( 'CU_VERSION' ) ? CU_VERSION : '1' ) . ' (WordPress vulnerability check)',
			'headers'             => array( 'Accept' => 'application/json' ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: code HTTP */
			return new WP_Error( 'cu_vuln_http', sprintf( __( 'Réponse HTTP %d de la source.', 'cyberpush-ultra' ), $code ) );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'cu_vuln_json', __( 'Réponse illisible de la source (format inattendu).', 'cyberpush-ultra' ) );
		}
		return $json;
	}

	/**
	 * Interroge WPVulnerability pour un composant.
	 *
	 * @return array|WP_Error Bloc « data » validé.
	 */
	private static function fetch_component( array $item ) {
		$path = 'core' === $item['type'] ? 'core/' : ( in_array( $item['type'], array( 'plugin', 'theme' ), true ) ? $item['type'] . '/' : $item['type'] . '/' );
		$key  = in_array( $item['type'], array( 'plugin', 'theme' ), true ) ? $item['slug'] : $item['version'];
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,100}$/', (string) $key ) ) {
			return new WP_Error( 'cu_vuln_key', __( 'Identifiant ou version illisible.', 'cyberpush-ultra' ) );
		}
		$json = self::get_json( self::API . $path . rawurlencode( (string) $key ) . '/' );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( ! array_key_exists( 'error', $json ) || 0 !== (int) $json['error'] ) {
			$message = isset( $json['message'] ) && is_string( $json['message'] ) ? sanitize_text_field( $json['message'] ) : __( 'erreur signalée par la source', 'cyberpush-ultra' );
			return new WP_Error( 'cu_vuln_api', CU_Text::substr( $message, 0, 200 ) );
		}
		if ( ! isset( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return new WP_Error( 'cu_vuln_shape', __( 'Réponse sans données (format inattendu).', 'cyberpush-ultra' ) );
		}
		$data = $json['data'];
		if ( isset( $data['vulnerability'] ) && null !== $data['vulnerability'] && ! is_array( $data['vulnerability'] ) ) {
			return new WP_Error( 'cu_vuln_shape', __( 'Liste de failles illisible (format inattendu).', 'cyberpush-ultra' ) );
		}
		$data['_updated'] = isset( $json['updated'] ) && is_numeric( $json['updated'] ) ? (int) $json['updated'] : 0;
		return $data;
	}

	/**
	 * Cycle de vie des branches PHP : php.net (source officielle), sinon
	 * endoflife.date. Mis en cache dans l'état (rafraîchi au plus une fois par jour).
	 *
	 * @return array{source:string, fetched_at:int, branches:array}|WP_Error
	 */
	private static function fetch_php_lifecycle() {
		$json = self::get_json( self::PHP_STATES_URL, 10 );
		if ( ! is_wp_error( $json ) ) {
			$branches = array();
			foreach ( $json as $major => $list ) {
				if ( ! is_array( $list ) ) {
					continue;
				}
				foreach ( $list as $branch => $info ) {
					if ( ! is_array( $info ) || ! preg_match( '/^\d+\.\d+$/', (string) $branch ) ) {
						continue;
					}
					$state = isset( $info['state'] ) ? sanitize_key( (string) $info['state'] ) : '';
					$branches[ (string) $branch ] = array(
						'state'        => in_array( $state, array( 'stable', 'security', 'eol' ), true ) ? $state : '',
						'active_end'   => isset( $info['active_support_end'] ) ? substr( (string) $info['active_support_end'], 0, 10 ) : '',
						'security_end' => isset( $info['security_support_end'] ) ? substr( (string) $info['security_support_end'], 0, 10 ) : '',
					);
				}
			}
			if ( $branches ) {
				return array( 'source' => 'php.net', 'fetched_at' => time(), 'branches' => $branches );
			}
		}
		$json = self::get_json( self::PHP_EOL_URL, 10 );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$branches = array();
		$today    = gmdate( 'Y-m-d' );
		foreach ( $json as $row ) {
			if ( ! is_array( $row ) || empty( $row['cycle'] ) || ! preg_match( '/^\d+\.\d+$/', (string) $row['cycle'] ) ) {
				continue;
			}
			$eol    = isset( $row['eol'] ) && is_string( $row['eol'] ) ? substr( $row['eol'], 0, 10 ) : '';
			$active = isset( $row['support'] ) && is_string( $row['support'] ) ? substr( $row['support'], 0, 10 ) : '';
			$state  = '' !== $eol && $eol < $today ? 'eol' : ( '' !== $active && $active < $today ? 'security' : 'stable' );
			$branches[ (string) $row['cycle'] ] = array( 'state' => $state, 'active_end' => $active, 'security_end' => $eol );
		}
		return $branches ? array( 'source' => 'endoflife.date', 'fetched_at' => time(), 'branches' => $branches ) : new WP_Error( 'cu_vuln_eol', __( 'Calendrier PHP illisible.', 'cyberpush-ultra' ) );
	}

	// -------------------------------------------------------------------------
	// Rapprochement version installée ↔ plages affectées
	// -------------------------------------------------------------------------

	/** Version exploitable pour une comparaison (au moins un chiffre). */
	private static function clean_version( $version ) {
		$version = ltrim( trim( (string) $version ), 'vV' );
		return preg_match( '/^\d+(?:[.\-+_]?[0-9A-Za-z]+)*$/', $version ) ? $version : '';
	}

	/**
	 * version_compare() sans faux écart dû aux zéros finaux : « 2.1 » et « 2.1.0 »
	 * sont la même version (PHP les juge différentes). Les versions purement
	 * numériques sont complétées au même nombre de segments avant comparaison.
	 */
	private static function vcmp( $a, $b, $op ) {
		if ( preg_match( '/^\d+(\.\d+)*$/', $a ) && preg_match( '/^\d+(\.\d+)*$/', $b ) ) {
			$pa = explode( '.', $a );
			$pb = explode( '.', $b );
			$n  = max( count( $pa ), count( $pb ) );
			$a  = implode( '.', array_pad( $pa, $n, '0' ) );
			$b  = implode( '.', array_pad( $pb, $n, '0' ) );
		}
		return version_compare( $a, $b, $op );
	}

	/**
	 * La version installée est-elle dans la plage décrite par la source ?
	 *
	 * @return string 'yes' (concernée), 'no' (hors plage), 'review' (impossible à trancher).
	 */
	public static function applicability( $version, $operator ) {
		$version = self::clean_version( $version );
		if ( '' === $version || ! is_array( $operator ) ) {
			return 'review';
		}
		$ops     = array( 'lt', 'le', 'eq', 'ne', 'gt', 'ge' );
		$min     = isset( $operator['min_version'] ) && is_scalar( $operator['min_version'] ) ? self::clean_version( $operator['min_version'] ) : '';
		$min_op  = isset( $operator['min_operator'] ) && is_string( $operator['min_operator'] ) ? strtolower( $operator['min_operator'] ) : '';
		$max     = isset( $operator['max_version'] ) && is_scalar( $operator['max_version'] ) ? self::clean_version( $operator['max_version'] ) : '';
		$max_op  = isset( $operator['max_operator'] ) && is_string( $operator['max_operator'] ) ? strtolower( $operator['max_operator'] ) : '';
		$unfixed = isset( $operator['unfixed'] ) && in_array( (string) $operator['unfixed'], array( '1', 'true' ), true );
		$has_min = '' !== $min && in_array( $min_op, $ops, true );
		$has_max = '' !== $max && in_array( $max_op, $ops, true );
		if ( ! $has_min && ! $has_max ) {
			return 'review'; // aucune plage exploitable : jamais « vulnérable » par supposition
		}
		if ( $has_min && ! self::vcmp( $version, $min, $min_op ) ) {
			return 'no';
		}
		if ( $has_max && ! self::vcmp( $version, $max, $max_op ) ) {
			// Faille sans correctif publié : la borne haute n'est que la dernière
			// version examinée par la source ; une version plus récente reste à vérifier.
			return ( $unfixed && self::vcmp( $version, $max, ">" ) ) ? "review" : "no";
		}
		return 'yes';
	}

	/** Mots significatifs d'un nom (pour vérifier qu'un slug désigne bien le même produit). */
	private static function name_tokens( $name ) {
		$name   = strtolower( remove_accents( wp_strip_all_tags( (string) $name ) ) );
		$tokens = preg_split( '/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY );
		$stop   = array( 'the', 'for', 'and', 'wp', 'wordpress', 'plugin', 'theme', 'by', 'pro', 'lite', 'free', 'de', 'la', 'le', 'les', 'pour' );
		return array_values( array_diff( array_filter( (array) $tokens, function ( $t ) { return strlen( $t ) >= 3; } ), $stop ) );
	}

	/**
	 * Le composant installé est-il bien celui que décrit la base ? Un en-tête
	 * Update URI qui pointe ailleurs que wordpress.org, ou un nom sans aucun mot
	 * commun, rend toute correspondance incertaine.
	 */
	private static function identity_doubt( array $item, array $data ) {
		$uri = isset( $item['update_uri'] ) ? trim( (string) $item['update_uri'] ) : '';
		if ( '' !== $uri && ! preg_match( '#^(https?://)?(w\.org|wordpress\.org)(/|$)#i', $uri ) ) {
			return 'update_uri';
		}
		$remote = isset( $data['name'] ) && is_string( $data['name'] ) ? $data['name'] : '';
		if ( '' === $remote ) {
			return '';
		}
		$local = self::name_tokens( $item['name'] );
		$other = self::name_tokens( $remote );
		if ( $local && $other && ! array_intersect( $local, $other ) && false === strpos( strtolower( $remote ), strtolower( $item['slug'] ) ) ) {
			return 'name';
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Normalisation d'une faille
	// -------------------------------------------------------------------------

	private static function severity_of( $impact ) {
		if ( ! is_array( $impact ) ) {
			return array( '', null );
		}
		foreach ( array( 'cvss3', 'cvss4', 'cvss2' ) as $block ) {
			if ( isset( $impact[ $block ] ) && is_array( $impact[ $block ] ) && ! empty( $impact[ $block ]['severity'] ) ) {
				$sev = sanitize_key( (string) $impact[ $block ]['severity'] );
				return array( in_array( $sev, array( 'critical', 'high', 'medium', 'low', 'none' ), true ) ? $sev : '', isset( $impact[ $block ]['score'] ) && is_numeric( $impact[ $block ]['score'] ) ? (float) $impact[ $block ]['score'] : null );
			}
		}
		if ( isset( $impact['cvss'] ) && is_array( $impact['cvss'] ) && ! empty( $impact['cvss']['severity'] ) ) {
			$map = array( 'c' => 'critical', 'h' => 'high', 'm' => 'medium', 'l' => 'low', 'n' => 'none' );
			$sev = strtolower( (string) $impact['cvss']['severity'] );
			return array( isset( $map[ $sev ] ) ? $map[ $sev ] : '', isset( $impact['cvss']['score'] ) && is_numeric( $impact['cvss']['score'] ) ? (float) $impact['cvss']['score'] : null );
		}
		return array( '', null );
	}

	/** Texte externe nettoyé puis rendu en texte brut (échappé à l'affichage). */
	private static function plain( $text, $max ) {
		return CU_Text::substr( trim( html_entity_decode( sanitize_text_field( (string) $text ), ENT_QUOTES, 'UTF-8' ) ), 0, $max );
	}

	private static function source_label( $link, $id ) {
		$host = strtolower( (string) wp_parse_url( (string) $link, PHP_URL_HOST ) );
		$map  = array( 'cve.org' => 'CVE', 'nvd.nist.gov' => 'NVD', 'wordfence.com' => 'Wordfence', 'patchstack.com' => 'Patchstack', 'wpscan.com' => 'WPScan', 'jvn.jp' => 'JVN', 'euvd.enisa.europa.eu' => 'EUVD', 'enisa.europa.eu' => 'EUVD' );
		foreach ( $map as $suffix => $label ) {
			if ( $host === $suffix || substr( $host, -strlen( '.' . $suffix ) ) === '.' . $suffix ) {
				return $label;
			}
		}
		return 0 === strpos( (string) $id, 'CVE-' ) ? 'CVE' : ( '' !== $host ? $host : __( 'Source', 'cyberpush-ultra' ) );
	}

	/**
	 * Faille réduite à ce que l'interface affiche (contenu externe nettoyé ;
	 * l'échappement HTML est fait à l'affichage).
	 */
	private static function normalize_vuln( array $vuln, $type ) {
		$refs  = array();
		$cve   = '';
		$title = '';
		$desc  = '';
		$dates = array();
		foreach ( isset( $vuln['source'] ) && is_array( $vuln['source'] ) ? $vuln['source'] : array() as $src ) {
			if ( ! is_array( $src ) ) {
				continue;
			}
			$id   = isset( $src['id'] ) && is_scalar( $src['id'] ) ? CU_Text::substr( sanitize_text_field( (string) $src['id'] ), 0, 80 ) : '';
			$link = isset( $src['link'] ) && is_string( $src['link'] ) ? esc_url_raw( $src['link'], array( 'https', 'http' ) ) : '';
			if ( '' === $cve && preg_match( '/^CVE-\d{4}-\d{3,}$/', $id ) ) {
				$cve = $id;
			}
			$name = isset( $src['name'] ) && is_string( $src['name'] ) ? self::plain( $src['name'], 200 ) : '';
			if ( '' === $title && '' !== $name && $name !== $id ) {
				$title = $name;
			}
			if ( '' === $desc && ! empty( $src['description'] ) && is_string( $src['description'] ) ) {
				$desc = trim( preg_replace( '/^\[[a-z]{2}\]\s*/i', '', self::plain( $src['description'], 2000 ) ) );
			}
			if ( ! empty( $src['date'] ) && preg_match( '/^(19[89]\d|2\d{3})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])/', (string) $src['date'] ) ) {
				$dates[] = substr( (string) $src['date'], 0, 10 );
			}
			if ( count( $refs ) < 5 && ( '' !== $id || '' !== $link ) ) {
				$refs[] = array( 'id' => $id, 'link' => $link, 'source' => self::source_label( $link, $id ) );
			}
		}
		$vname = isset( $vuln['name'] ) && is_string( $vuln['name'] ) ? self::plain( $vuln['name'], 200 ) : '';
		if ( '' === $title ) {
			$title = '' !== $vname && 'core' !== $type ? $vname : ( '' !== $cve ? $cve : __( 'Vulnérabilité publiée', 'cyberpush-ultra' ) );
		}
		if ( '' === $desc && ! empty( $vuln['description'] ) && is_string( $vuln['description'] ) ) {
			$desc = self::plain( $vuln['description'], 2000 );
		}
		list( $severity, $score ) = self::severity_of( isset( $vuln['impact'] ) ? $vuln['impact'] : null );
		$impact = isset( $vuln['impact'] ) && is_array( $vuln['impact'] ) ? $vuln['impact'] : array();
		$kev    = ! empty( $impact['kev'] ) || ( isset( $impact['ssvc'] ) && is_array( $impact['ssvc'] ) && ! empty( $impact['ssvc']['kev'] ) );
		$active = isset( $impact['ssvc']['exploitation'] ) && 'active' === $impact['ssvc']['exploitation'];
		$op     = isset( $vuln['operator'] ) && is_array( $vuln['operator'] ) ? $vuln['operator'] : null;
		$fixed  = '';
		$range  = '';
		$unfixed = false;
		if ( $op ) {
			$unfixed = isset( $op['unfixed'] ) && in_array( (string) $op['unfixed'], array( '1', 'true' ), true );
			$sym     = array( 'lt' => '<', 'le' => '≤', 'eq' => '=', 'ne' => '≠', 'gt' => '>', 'ge' => '≥' );
			$parts   = array();
			if ( ! empty( $op['min_version'] ) && isset( $sym[ (string) $op['min_operator'] ] ) ) {
				$parts[] = $sym[ (string) $op['min_operator'] ] . ' ' . sanitize_text_field( (string) $op['min_version'] );
			}
			if ( ! empty( $op['max_version'] ) && isset( $sym[ (string) $op['max_operator'] ] ) ) {
				$parts[] = $sym[ (string) $op['max_operator'] ] . ' ' . sanitize_text_field( (string) $op['max_version'] );
				if ( 'lt' === $op['max_operator'] && ! $unfixed ) {
					$fixed = sanitize_text_field( (string) $op['max_version'] );
				}
			}
			$range = implode( ' et ', $parts );
		}
		sort( $dates );
		$uuid = isset( $vuln['uuid'] ) && is_string( $vuln['uuid'] ) && preg_match( '/^[A-Za-z0-9-]{8,80}$/', $vuln['uuid'] ) ? $vuln['uuid'] : md5( $title . '|' . $range );
		return array(
			'uuid'      => $uuid,
			'id'        => '' !== $cve ? $cve : ( isset( $refs[0]['id'] ) ? $refs[0]['id'] : '' ),
			'title'     => $title,
			'desc'      => ( function_exists( 'mb_strlen' ) ? mb_strlen( $desc, 'UTF-8' ) : strlen( $desc ) ) > 500 ? rtrim( CU_Text::substr( $desc, 0, 497 ) ) . '…' : $desc,
			'severity'  => $severity,
			'score'     => $score,
			'kev'       => $kev,
			'exploited' => $active,
			'range'     => $range,
			'fixed'     => $fixed,
			'unfixed'   => $unfixed,
			'published' => $dates ? $dates[0] : '',
			'updated'   => $dates ? end( $dates ) : '',
			'refs'      => $refs,
		);
	}

	private static function severity_rank( $sev ) {
		$rank = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
		return isset( $rank[ $sev ] ) ? $rank[ $sev ] : 0;
	}

	// -------------------------------------------------------------------------
	// Évaluation d'un composant
	// -------------------------------------------------------------------------

	/**
	 * Résultat d'un composant à partir des données de la source.
	 *
	 * @return array Composant enrichi (état, failles applicables, à vérifier, cycle de vie).
	 */
	private static function evaluate( array $item, array $data, $php_cycle ) {
		$type    = $item['type'];
		$out     = $item + array( 'found' => true, 'vulns' => array(), 'review' => array(), 'counts' => array(), 'total' => 0, 'notes' => array(), 'lifecycle' => null, 'link' => '' );
		$doubt   = '';
		$applies = array();
		$review  = array();
		if ( in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			$out['found'] = isset( $data['name'] ) && is_string( $data['name'] ) && '' !== $data['name'];
			$out['link']  = isset( $data['link'] ) && is_string( $data['link'] ) ? esc_url_raw( $data['link'] ) : '';
			if ( ! empty( $data['closed'] ) && 1 === (int) $data['closed'] ) {
				$out['notes'][] = array( 'level' => 'warn', 'code' => 'closed', 'text' => __( 'Retirée du répertoire WordPress.org : plus de mises à jour garanties.', 'cyberpush-ultra' ) );
			}
			if ( ! empty( $data['latest'] ) && is_numeric( $data['latest'] ) && (int) $data['latest'] < time() - 2 * YEAR_IN_SECONDS ) {
				/* translators: %s: date */
				$out['notes'][] = array( 'level' => 'info', 'code' => 'stale', 'text' => sprintf( __( 'Dernière mise à jour publiée le %s : extension peu maintenue.', 'cyberpush-ultra' ), wp_date( 'd/m/Y', (int) $data['latest'] ) ) );
			}
			$doubt = $out['found'] ? self::identity_doubt( $item, $data ) : '';
			if ( 'update_uri' === $doubt ) {
				$out['notes'][] = array( 'level' => 'info', 'code' => 'identity', 'text' => __( 'Mise à jour fournie par une autre source que WordPress.org : le rapprochement par slug est incertain.', 'cyberpush-ultra' ) );
			} elseif ( 'name' === $doubt ) {
				$out['notes'][] = array( 'level' => 'info', 'code' => 'identity', 'text' => __( 'Le nom installé diffère de celui de la base pour ce slug : correspondance à vérifier.', 'cyberpush-ultra' ) );
			}
		}
		if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			// Cycle de vie fourni par la base pour les logiciels serveur.
			$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
			$out['lifecycle'] = array(
				'state'  => array( 'm' => 'maintained', 's' => 'security', 'd' => 'eol', 'eol' => 'eol' )[ $status ] ?? '',
				'end'    => isset( $data['date_end'] ) && is_string( $data['date_end'] ) ? substr( $data['date_end'], 0, 10 ) : '',
				'branch' => isset( $data['name'] ) && is_string( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
				'source' => 'WPVulnerability',
			);
		}
		if ( 'php' === $type && is_array( $php_cycle ) && isset( $php_cycle['branches'][ $item['branch'] ] ) ) {
			// Échéances officielles de php.net : prioritaires.
			$b = $php_cycle['branches'][ $item['branch'] ];
			$out['lifecycle'] = array(
				'state'      => array( 'stable' => 'maintained', 'security' => 'security', 'eol' => 'eol' )[ $b['state'] ] ?? '',
				'end'        => $b['security_end'],
				'active_end' => $b['active_end'],
				'branch'     => 'PHP ' . $item['branch'],
				'source'     => $php_cycle['source'],
			);
		}
		if ( is_array( $out['lifecycle'] ) && 'eol' !== $out['lifecycle']['state'] && ! empty( $out['lifecycle']['end'] ) && strtotime( $out['lifecycle']['end'] ) ) {
			$left = strtotime( $out['lifecycle']['end'] ) - time();
			if ( $left < 0 ) {
				$out['lifecycle']['state'] = 'eol';
			} elseif ( $left < 183 * DAY_IN_SECONDS ) {
				$out['lifecycle']['near'] = true;
			}
		}

		foreach ( isset( $data['vulnerability'] ) && is_array( $data['vulnerability'] ) ? $data['vulnerability'] : array() as $vuln ) {
			if ( ! is_array( $vuln ) ) {
				continue;
			}
			// WordPress : la base ne renvoie que les failles de la version exacte demandée.
			$verdict = 'core' === $type ? 'yes' : self::applicability( $item['version'], isset( $vuln['operator'] ) ? $vuln['operator'] : null );
			if ( 'no' === $verdict ) {
				continue;
			}
			if ( 'yes' === $verdict && '' !== $doubt ) {
				$verdict = 'review';
			}
			$norm = self::normalize_vuln( $vuln, $type );
			if ( 'yes' === $verdict ) {
				$applies[ $norm['uuid'] ] = $norm;
			} else {
				$review[ $norm['uuid'] ] = $norm;
			}
		}
		// Chaîne d'approvisionnement (rachat malveillant d'une extension…).
		if ( 'plugin' === $type && isset( $data['supplychain'] ) && is_array( $data['supplychain'] ) ) {
			foreach ( $data['supplychain'] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['verdict'] ) || ! in_array( $entry['verdict'], array( 'malicious', 'suspicious' ), true ) ) {
					continue;
				}
				$range   = isset( $entry['affected_versions'] ) && is_array( $entry['affected_versions'] ) ? $entry['affected_versions'] : array();
				$verdict = self::applicability( $item['version'], $range );
				if ( 'no' === $verdict ) {
					continue;
				}
				$link = isset( $entry['wpbeacon_url'] ) && is_string( $entry['wpbeacon_url'] ) ? esc_url_raw( $entry['wpbeacon_url'] ) : '';
				$norm = array(
					'uuid'      => 'sc-' . ( isset( $entry['audit_id'] ) ? (int) $entry['audit_id'] : md5( wp_json_encode( $range ) ) ),
					'id'        => __( 'Chaîne d’approvisionnement', 'cyberpush-ultra' ),
					'title'     => 'malicious' === $entry['verdict'] ? __( 'Version compromise (code malveillant publié dans une mise à jour)', 'cyberpush-ultra' ) : __( 'Version suspecte (audit de la chaîne d’approvisionnement)', 'cyberpush-ultra' ),
					'desc'      => __( 'Audit publié par WPVulnerability : une mise à jour de cette extension a été détournée. Consultez le rapport et passez à une version saine ou supprimez l’extension.', 'cyberpush-ultra' ),
					'severity'  => 'malicious' === $entry['verdict'] ? 'critical' : 'high',
					'score'     => null,
					'kev'       => false,
					'exploited' => 'malicious' === $entry['verdict'],
					'range'     => ( ! empty( $range['min_version'] ) ? '≥ ' . sanitize_text_field( (string) $range['min_version'] ) : '' ) . ( ! empty( $range['max_version'] ) ? ' et < ' . sanitize_text_field( (string) $range['max_version'] ) : '' ),
					'fixed'     => ! empty( $range['max_version'] ) ? sanitize_text_field( (string) $range['max_version'] ) : '',
					'unfixed'   => empty( $range['max_version'] ),
					'published' => isset( $entry['published_at'] ) ? substr( sanitize_text_field( (string) $entry['published_at'] ), 0, 10 ) : '',
					'updated'   => '',
					'refs'      => '' !== $link ? array( array( 'id' => 'WPBeacon', 'link' => $link, 'source' => 'WPVulnerability' ) ) : array(),
				);
				if ( 'yes' === $verdict ) {
					$applies[ $norm['uuid'] ] = $norm;
				} else {
					$review[ $norm['uuid'] ] = $norm;
				}
			}
		}

		$sort = function ( $a, $b ) {
			return ( self::severity_rank( $b['severity'] ) - self::severity_rank( $a['severity'] ) ) ?: ( ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 ) ) ?: strcmp( (string) $b['published'], (string) $a['published'] );
		};
		$applies = array_values( $applies );
		$review  = array_values( $review );
		usort( $applies, $sort );
		usort( $review, $sort );
		$counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0 );
		$fix    = '';
		foreach ( $applies as $v ) {
			$counts[ isset( $counts[ $v['severity'] ] ) && '' !== $v['severity'] ? $v['severity'] : 'unknown' ]++;
			// Version minimale qui corrige TOUTES les failles applicables (si toutes sont corrigées).
			if ( null !== $fix ) {
				if ( '' === $v['fixed'] ) {
					$fix = null;
				} elseif ( '' === $fix || version_compare( $v['fixed'], $fix, '>' ) ) {
					$fix = $v['fixed'];
				}
			}
		}
		$out['total']      = count( $applies );
		$out['counts']     = $counts;
		// WordPress : la base ne publie pas de version corrective (« inconnue », jamais « non publiée »).
		$out['fix']        = $applies ? ( 'core' === $type ? '' : $fix ) : '';
		$out['vulns']      = array_slice( $applies, 0, self::KEEP_DETAILS );
		$out['review']     = array_slice( $review, 0, 20 );
		$out['review_n']   = count( $review );
		$out['source_upd'] = isset( $data['_updated'] ) ? (int) $data['_updated'] : 0;
		return $out;
	}

	/** État lisible d'un composant pour l'interface. */
	private static function status_of( array $c ) {
		if ( empty( $c['checked'] ) ) {
			return empty( $c['error'] ) ? 'pending' : 'unknown';
		}
		if ( ! empty( $c['total'] ) ) {
			// PHP, base de données, serveur web, bibliothèques : d'après le numéro de
			// version seulement (correctifs rétroportés possibles par l'hébergeur).
			return self::actionable( $c ) ? 'vulnerable' : 'server';
		}
		if ( is_array( $c['lifecycle'] ?? null ) && 'eol' === ( $c['lifecycle']['state'] ?? '' ) ) {
			return 'eol';
		}
		if ( ! empty( $c['review_n'] ) ) {
			return 'review';
		}
		if ( in_array( $c['type'], array( 'plugin', 'theme' ), true ) && empty( $c['found'] ) ) {
			return 'unlisted';
		}
		return 'ok';
	}

	// -------------------------------------------------------------------------
	// Synchronisation
	// -------------------------------------------------------------------------

	public static function state() {
		$state = get_option( self::OPT_STATE, array() );
		return is_array( $state ) ? $state + array( 'components' => array(), 'synced_at' => 0, 'attempt_at' => 0, 'status' => '', 'error' => '', 'php_cycle' => null, 'pending' => 0 ) : array( 'components' => array() );
	}

	/** Tâche quotidienne : vérification complète (composants plus vieux que 20 h). */
	public static function cron_sync() {
		self::sync( false );
	}

	/** Reprise d'une synchronisation interrompue par son budget de temps. */
	public static function cron_resume() {
		self::sync( false, true );
	}

	/**
	 * Vérifie l'inventaire auprès des sources, par lots bornés.
	 *
	 * @param bool $force  Ignorer la fraîcheur (bouton « Vérifier maintenant »).
	 * @param bool $resume Passe de reprise (garde le drapeau « forcé » de la passe initiale).
	 * @return array État après la passe.
	 */
	public static function sync( $force = false, $resume = false ) {
		$lock = CU_DB::lock_acquire( 'cu_vuln_lock', 5 * MINUTE_IN_SECONDS );
		if ( false === $lock ) {
			return self::state(); // une autre passe tourne déjà
		}
		try {
			$state     = self::state();
			$previous  = isset( $state['components'] ) && is_array( $state['components'] ) ? $state['components'] : array();
			$inventory = self::inventory();
			$started   = microtime( true );
			$fetches   = 0;
			$errors    = array();
			$online    = self::online_enabled();
			if ( $resume && ! empty( $state['force_since'] ) ) {
				$force = true;
			}
			$force_since = $force ? ( $resume && ! empty( $state['force_since'] ) ? (int) $state['force_since'] : time() ) : 0;

			$state['attempt_at'] = time();

			// Cycle de vie PHP (au plus une fois par jour, dernier résultat conservé en cas d'échec).
			$cycle = is_array( $state['php_cycle'] ) ? $state['php_cycle'] : null;
			if ( $online && ( ! $cycle || $force || time() - (int) $cycle['fetched_at'] > DAY_IN_SECONDS ) ) {
				$fresh = self::fetch_php_lifecycle();
				if ( is_wp_error( $fresh ) ) {
					$errors[] = 'PHP : ' . $fresh->get_error_message();
				} else {
					$cycle = $fresh;
				}
				$fetches++;
			}
			$state['php_cycle'] = $cycle;

			$components = array();
			$pending    = 0;
			$failures   = 0;
			$aborted    = false;
			foreach ( $inventory as $key => $item ) {
				$prev = isset( $previous[ $key ] ) && is_array( $previous[ $key ] ) ? $previous[ $key ] : null;
				$same = $prev && isset( $prev['version'] ) && $prev['version'] === $item['version'];
				$keep = $same && ! empty( $prev['checked'] ) && (
					( ! $force && time() - (int) $prev['checked'] < self::FRESH )
					|| ( $force && (int) $prev['checked'] >= $force_since )
				);
				if ( ! $online ) {
					// Consultation coupée : inventaire à jour, dernier résultat seulement s'il porte sur la même version.
					$components[ $key ] = $same ? array_merge( $prev, array_intersect_key( $item, array_flip( array( 'name', 'active', 'update_uri' ) ) ) ) : $item + array( 'checked' => 0 );
					continue;
				}
				if ( $keep ) {
					$components[ $key ] = array_merge( $prev, array_intersect_key( $item, array_flip( array( 'name', 'active', 'update_uri', 'raw' ) ) ) );
					if ( 'php' === $item['type'] ) {
						// Échéances PHP mises à jour sans nouvel appel à la base.
						$components[ $key ] = self::refresh_php_cycle( $components[ $key ], $cycle );
					}
					continue;
				}
				if ( $aborted ) {
					// Source injoignable : rien n'est effacé, nouvel essai à la tâche suivante.
					$components[ $key ] = $prev && $same ? $prev : $item + array( 'checked' => 0 );
					continue;
				}
				if ( $fetches >= self::PASS_FETCHES || microtime( true ) - $started > self::PASS_SECONDS ) {
					$components[ $key ] = $prev && $same ? $prev : $item + array( 'checked' => 0 );
					$pending++;
					continue;
				}
				$data = self::fetch_component( $item );
				$fetches++;
				$failures = is_wp_error( $data ) ? $failures + 1 : 0;
				if ( $failures >= 3 ) {
					$aborted = true; // trois échecs de suite : pas d'acharnement sur une source en panne
				}
				if ( is_wp_error( $data ) ) {
					$errors[] = $item['name'] . ' : ' . $data->get_error_message();
					// Panne : on garde le dernier résultat valide de la même version (daté).
					$components[ $key ] = $prev && $same ? array_merge( $prev, array( 'error' => $data->get_error_message(), 'stale' => true ) ) : $item + array( 'checked' => 0, 'error' => $data->get_error_message() );
					continue;
				}
				$eval               = self::evaluate( $item, $data, $cycle );
				$eval['checked']    = time();
				$eval['error']      = '';
				$eval['stale']      = false;
				$components[ $key ] = $eval;
			}
			foreach ( $components as $key => $c ) {
				$components[ $key ]['status'] = self::status_of( $c );
			}

			$state['components']  = $components;
			$state['pending']     = $pending;
			$state['force_since'] = $pending ? $force_since : 0;
			$state['errors']      = array_slice( $errors, 0, 10 );
			if ( $aborted ) {
				$pending              = 0;
				$state['pending']     = 0;
				$state['force_since'] = 0;
			}
			if ( ! $online ) {
				$state['status'] = 'disabled';
			} elseif ( $aborted || ( $errors && count( $errors ) >= max( 1, $fetches ) ) ) {
				$state['status'] = 'error'; // aucune réponse valide : rien n'est effacé
				$state['error']  = $errors[0];
			} else {
				$state['status'] = $pending ? 'partial' : ( $errors ? 'degraded' : 'ok' );
				$state['error']  = $errors ? $errors[0] : '';
				if ( ! $pending ) {
					$state['synced_at'] = time();
				}
			}
			update_option( self::OPT_STATE, $state, false );

			if ( $pending && ! wp_next_scheduled( self::HOOK_RESUME ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_RESUME );
			}
			if ( $online && ! $pending && 'error' !== $state['status'] ) {
				self::notify( $components );
			}
			if ( 'error' === $state['status'] ) {
				CU_Logger::log( 'firewall', 'warning', __( 'Vulnérabilités : la source ne répond pas, les derniers résultats connus sont conservés.', 'cyberpush-ultra' ), array( 'error' => CU_Text::substr( (string) $state['error'], 0, 200 ) ) );
			}
			return $state;
		} finally {
			CU_DB::lock_release( 'cu_vuln_lock', $lock );
		}
	}

	private static function refresh_php_cycle( array $c, $cycle ) {
		if ( is_array( $cycle ) && isset( $c['branch'], $cycle['branches'][ $c['branch'] ] ) ) {
			$fake = array( 'vulnerability' => array(), 'status' => '', '_updated' => 0 );
			$life = self::evaluate( $c, $fake, $cycle );
			$c['lifecycle'] = $life['lifecycle'];
		}
		return $c;
	}

	// -------------------------------------------------------------------------
	// Alertes : e-mail (nouveautés importantes) et avis d'administration
	// -------------------------------------------------------------------------

	/** Composants qui relèvent d'une mise à jour WordPress (l'administrateur peut agir). */
	private static function actionable( array $c ) {
		return in_array( $c['type'], array( 'core', 'plugin', 'theme' ), true );
	}

	/**
	 * Signale les failles nouvelles (ou dont la gravité / le correctif ont changé)
	 * une seule fois par composant, version et faille. E-mail : gravité élevée ou
	 * critique, exploitation active, ou chaîne d'approvisionnement.
	 */
	private static function notify( array $components ) {
		$seen = get_option( self::OPT_NOTIFIED, array() );
		$seen = is_array( $seen ) ? $seen : array();
		$now  = array();
		$news = array();
		foreach ( $components as $c ) {
			if ( empty( $c['checked'] ) || ! empty( $c['stale'] ) || ! self::actionable( $c ) ) {
				// Composant non vérifié cette fois : ses empreintes restent (pas de fausse « nouveauté » au retour).
				foreach ( $seen as $k => $sig ) {
					if ( 0 === strpos( $k, $c['type'] . ':' . $c['slug'] . '|' ) ) {
						$now[ $k ] = $sig;
					}
				}
				continue;
			}
			foreach ( (array) $c['vulns'] as $v ) {
				$k         = $c['type'] . ':' . $c['slug'] . '|' . $c['version'] . '|' . $v['uuid'];
				$sig       = $v['severity'] . '|' . $v['fixed'];
				$now[ $k ] = $sig;
				if ( isset( $seen[ $k ] ) && $seen[ $k ] === $sig ) {
					continue;
				}
				$important = self::severity_rank( $v['severity'] ) >= 3 || ! empty( $v['exploited'] ) || ! empty( $v['kev'] ) || 0 === strpos( $v['uuid'], 'sc-' );
				if ( $important ) {
					$news[] = array( 'c' => $c, 'v' => $v, 'changed' => isset( $seen[ $k ] ) );
				}
			}
		}
		update_option( self::OPT_NOTIFIED, array_slice( $now, 0, 3000, true ), false );
		if ( ! $news ) {
			return;
		}
		CU_Logger::log( 'firewall', 'warning', sprintf( /* translators: %d: nombre */ _n( 'Vulnérabilités : %d nouvelle alerte importante concerne un composant installé.', 'Vulnérabilités : %d nouvelles alertes importantes concernent des composants installés.', count( $news ), 'cyberpush-ultra' ), count( $news ) ) );
		if ( ! self::email_enabled() || ! class_exists( 'CU_Audit' ) || ! method_exists( 'CU_Audit', 'send_security_alert' ) ) {
			return;
		}
		$lines = array(
			__( 'CyberPush Ultra a trouvé des vulnérabilités connues qui concernent des composants installés sur votre site :', 'cyberpush-ultra' ),
			'',
		);
		$labels = self::severity_labels();
		foreach ( array_slice( $news, 0, 20 ) as $n ) {
			$c       = $n['c'];
			$v       = $n['v'];
			$lines[] = sprintf( '• %s %s — %s%s', $c['name'], $c['version'], $v['title'], $n['changed'] ? ' (' . __( 'mise à jour de l’avis', 'cyberpush-ultra' ) . ')' : '' );
			$lines[] = '  ' . sprintf( /* translators: 1: gravité, 2: référence */ __( 'Gravité : %1$s · Référence : %2$s', 'cyberpush-ultra' ), isset( $labels[ $v['severity'] ] ) ? $labels[ $v['severity'] ] : __( 'non publiée', 'cyberpush-ultra' ), '' !== $v['id'] ? $v['id'] : '—' );
			$lines[] = '  ' . ( '' !== $v['fixed'] ? sprintf( /* translators: %s: version */ __( 'Action : mettre à jour vers la version %s ou plus récente.', 'cyberpush-ultra' ), $v['fixed'] ) : __( 'Action : aucun correctif publié pour l’instant — envisagez de désactiver ou remplacer ce composant.', 'cyberpush-ultra' ) );
			if ( ! empty( $v['refs'][0]['link'] ) ) {
				$lines[] = '  ' . $v['refs'][0]['link'];
			}
			$lines[] = '';
		}
		if ( count( $news ) > 20 ) {
			/* translators: %d: nombre */
			$lines[] = sprintf( __( '… et %d autre(s) : voir le détail dans l’administration.', 'cyberpush-ultra' ), count( $news ) - 20 );
		}
		$lines[] = __( 'Détail et suivi : ', 'cyberpush-ultra' ) . admin_url( 'admin.php?page=cyberpush-ultra-firewall#cu-vulns-section' );
		$lines[] = '';
		$lines[] = __( 'Une vulnérabilité connue ne signifie pas que le site a été piraté : elle indique qu’une mise à jour est nécessaire. Source : WPVulnerability (base publique).', 'cyberpush-ultra' );
		/* translators: %s: nom du site */
		CU_Audit::instance()->send_security_alert( sprintf( __( '[%s] Vulnérabilités connues à corriger', 'cyberpush-ultra' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), $lines, 'vulnerabilities' );
	}

	public static function severity_labels() {
		return array(
			'critical' => __( 'critique', 'cyberpush-ultra' ),
			'high'     => __( 'élevée', 'cyberpush-ultra' ),
			'medium'   => __( 'moyenne', 'cyberpush-ultra' ),
			'low'      => __( 'faible', 'cyberpush-ultra' ),
		);
	}

	/** Empreinte de l'ensemble des failles applicables (avis masqué jusqu'à un changement). */
	private static function alert_fingerprint( array $state ) {
		$keys = array();
		foreach ( (array) $state['components'] as $c ) {
			if ( self::actionable( $c ) ) {
				foreach ( (array) ( $c['vulns'] ?? array() ) as $v ) {
					$keys[] = $c['slug'] . '|' . $c['version'] . '|' . $v['uuid'];
				}
			}
		}
		sort( $keys );
		return $keys ? md5( implode( ',', $keys ) ) : '';
	}

	/**
	 * Avis sobre (tableau de bord, extensions, mises à jour, écrans CyberPush) tant
	 * qu'une faille connue concerne WordPress, une extension ou un thème installé.
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! ( in_array( $screen->id, array( 'dashboard', 'plugins', 'update-core', 'themes' ), true ) || false !== strpos( $screen->id, 'cyberpush-ultra' ) ) ) {
			return;
		}
		if ( false !== strpos( $screen->id, 'cyberpush-ultra-firewall' ) || false !== strpos( $screen->id, 'cyberpush-ultra-dashboard' ) ) {
			return; // le détail est déjà affiché sur la page
		}
		$state = self::state();
		$fp    = self::alert_fingerprint( $state );
		if ( '' === $fp || get_user_meta( get_current_user_id(), self::USER_DISMISS, true ) === $fp ) {
			return;
		}
		$total = 0;
		$top   = 0;
		$names = array();
		foreach ( (array) $state['components'] as $c ) {
			if ( self::actionable( $c ) && ! empty( $c['total'] ) ) {
				$total += (int) $c['total'];
				$top   += (int) ( $c['counts']['critical'] ?? 0 ) + (int) ( $c['counts']['high'] ?? 0 );
				$names[] = $c['name'] . ( ! empty( $c['fix'] ) ? ' → ' . $c['fix'] : '' );
			}
		}
		$url     = admin_url( 'admin.php?page=cyberpush-ultra-firewall#cu-vulns-section' );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=cu_vuln_dismiss' ), 'cu_vuln_dismiss' );
		echo '<div class="notice notice-warning"><p><strong>CyberPush Ultra — '
			. esc_html( sprintf( /* translators: %d: nombre */ _n( '%d vulnérabilité connue concerne un composant installé', '%d vulnérabilités connues concernent des composants installés', $total, 'cyberpush-ultra' ), $total ) )
			. ( $top ? esc_html( sprintf( /* translators: %d: nombre */ __( ' (dont %d de gravité élevée ou critique)', 'cyberpush-ultra' ), $top ) ) : '' )
			. '</strong></p><p>' . esc_html__( 'À mettre à jour :', 'cyberpush-ultra' ) . ' ' . esc_html( implode( ', ', array_slice( $names, 0, 6 ) ) . ( count( $names ) > 6 ? '…' : '' ) )
			. '. <a href="' . esc_url( $url ) . '">' . esc_html__( 'Voir le détail', 'cyberpush-ultra' ) . '</a> · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Masquer jusqu’à la prochaine alerte', 'cyberpush-ultra' ) . '</a></p></div>';
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'cyberpush-ultra' ), 403 );
		}
		check_admin_referer( 'cu_vuln_dismiss' );
		update_user_meta( get_current_user_id(), self::USER_DISMISS, self::alert_fingerprint( self::state() ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	// -------------------------------------------------------------------------
	// REST (administrateurs uniquement ; nonce wp_rest via cookie)
	// -------------------------------------------------------------------------

	public function register_routes() {
		$perm = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route( CU_REST_NAMESPACE, '/firewall/vulns', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_state' ), 'permission_callback' => $perm ) );
		register_rest_route( CU_REST_NAMESPACE, '/firewall/vulns/sync', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_sync' ), 'permission_callback' => $perm ) );
		register_rest_route( CU_REST_NAMESPACE, '/firewall/vulns/settings', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_settings' ), 'permission_callback' => $perm ) );
	}

	/** État pour l'interface : inventaire à jour + derniers résultats. */
	private static function payload() {
		$state     = self::state();
		$inventory = self::inventory();
		$rows      = array();
		foreach ( $inventory as $key => $item ) {
			$c = isset( $state['components'][ $key ] ) && is_array( $state['components'][ $key ] ) ? $state['components'][ $key ] : array();
			if ( $c && ( $c['version'] ?? '' ) !== $item['version'] ) {
				$c = array(); // mis à jour depuis la dernière vérification : résultat périmé
			}
			$row             = array_merge( $item, $c, array_intersect_key( $item, array_flip( array( 'name', 'active', 'version' ) ) ) );
			$row['key']      = $key;
			$row['status']   = $c ? ( $c['status'] ?? self::status_of( $row ) ) : ( self::online_enabled() ? 'pending' : 'offline' );
			unset( $row['update_uri'], $row['file'] );
			$rows[] = $row;
		}
		$order = array( 'php' => 0, 'core' => 1, 'mariadb' => 2, 'mysql' => 2, 'apache' => 3, 'nginx' => 3, 'curl' => 4, 'imagemagick' => 4, 'plugin' => 5, 'theme' => 6 );
		usort( $rows, function ( $a, $b ) use ( $order ) {
			return ( $order[ $a['type'] ] - $order[ $b['type'] ] ) ?: ( (int) ! empty( $b['total'] ) - (int) ! empty( $a['total'] ) ) ?: ( (int) $b['active'] - (int) $a['active'] ) ?: strcasecmp( $a['name'], $b['name'] );
		} );
		return array(
			'online'     => self::online_enabled(),
			'email'      => self::email_enabled(),
			'status'     => (string) $state['status'],
			'error'      => (string) $state['error'],
			'errors'     => isset( $state['errors'] ) ? (array) $state['errors'] : array(),
			'synced_at'  => (int) $state['synced_at'] ? wp_date( 'Y-m-d H:i', (int) $state['synced_at'] ) : '',
			'synced_ts'  => (int) $state['synced_at'],
			'attempt_at' => (int) $state['attempt_at'] ? wp_date( 'Y-m-d H:i', (int) $state['attempt_at'] ) : '',
			'next_run'   => wp_next_scheduled( self::HOOK ) ? wp_date( 'Y-m-d H:i', (int) wp_next_scheduled( self::HOOK ) ) : '',
			'pending'    => (int) $state['pending'],
			'php_source' => is_array( $state['php_cycle'] ) ? (string) $state['php_cycle']['source'] : '',
			'rows'       => $rows,
		);
	}

	public function rest_state() {
		nocache_headers();
		return rest_ensure_response( self::payload() );
	}

	/** « Vérifier maintenant » : une passe immédiate (bornée), la suite en arrière-plan. */
	public function rest_sync( WP_REST_Request $request ) {
		$continue = (bool) $request->get_param( 'continue' );
		if ( ! $continue ) {
			$last = (int) get_transient( 'cu_vuln_manual' );
			if ( $last && time() - $last < 60 ) {
				return new WP_Error( 'cu_vuln_wait', __( 'Une vérification vient d’être lancée : patientez une minute avant d’en demander une autre.', 'cyberpush-ultra' ), array( 'status' => 429 ) );
			}
			set_transient( 'cu_vuln_manual', time(), 2 * MINUTE_IN_SECONDS );
		}
		if ( ! self::online_enabled() ) {
			return new WP_Error( 'cu_vuln_offline', __( 'La consultation en ligne est désactivée : activez-la pour vérifier les composants.', 'cyberpush-ultra' ), array( 'status' => 409 ) );
		}
		self::sync( ! $continue, $continue );
		return rest_ensure_response( self::payload() );
	}

	public function rest_settings( WP_REST_Request $request ) {
		$values = array();
		foreach ( array( 'online' => 'vuln_online', 'email' => 'vuln_email' ) as $param => $key ) {
			$value = $request->get_param( $param );
			if ( null !== $value ) {
				$values[ $key ] = (bool) rest_sanitize_boolean( $value );
			}
		}
		if ( $values ) {
			CU_Settings::update_module( 'audit', $values );
		}
		if ( ! empty( $values['vuln_online'] ) ) {
			self::schedule( 120 );
		}
		return rest_ensure_response( self::payload() );
	}
}

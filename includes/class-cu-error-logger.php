<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Journal des erreurs PHP PROVOQUÉES PAR LE PLUGIN (et elles seules).
 *
 * Ce qui est réellement capté :
 *   - les erreurs FATALES (E_ERROR, E_PARSE, E_CORE/COMPILE_ERROR, E_USER_ERROR,
 *     E_RECOVERABLE_ERROR non gérée) dont le fichier fautif est dans le dossier
 *     du plugin — y compris une exception/Throwable non capturée, que PHP
 *     transforme en E_ERROR « Uncaught … ». Lecture via error_get_last() dans
 *     register_shutdown_function, seul moyen de les voir ;
 *   - les écritures explicites de secours (fallback()), par exemple quand la
 *     table des logs est inaccessible.
 *
 * Ce qui n'est PAS capté, volontairement : warnings, notices et deprecated non
 * fatals. Le plugin n'installe ni set_error_handler() ni set_exception_handler() :
 * PHP n'a qu'un seul emplacement de gestionnaire, et un gestionnaire « chaîné »
 * ne peut pas connaître le masque de niveaux du gestionnaire précédent ni
 * laisser passer à l'identique les exceptions qu'il lève (Whoops, conversion
 * en ErrorException…). Ne pas toucher à cet emplacement est la seule façon de
 * garantir zéro interférence ; ces erreurs restent dans le journal PHP du
 * serveur (error_log / WP_DEBUG_LOG) comme pour toute extension.
 *
 * Chaque erreur est écrite dans DEUX puits : la base (page Logs, avec
 * regroupement « ×N » par CU_Logger) + un fichier de secours journalier
 * (utile si la base est justement la cause du problème). Le fichier est borné :
 * une même erreur au plus une fois toutes les 10 minutes, 1 Mo par jour,
 * 30 jours / 30 fichiers au maximum, purge quotidienne indépendante du réglage
 * de nettoyage. Les fichiers sont des .php commençant par une garde `exit` et
 * des lignes `//` ; « <? » et « ?> » sont neutralisés pour qu'aucun message ne
 * puisse sortir du commentaire.
 */
class CU_Error_Logger {

	/** Fenêtre anti-répétition par erreur (secondes). */
	const RATE_WINDOW    = 600;
	/** Taille maximale d'un fichier journalier. */
	const MAX_FILE_BYTES = 1048576;
	/** Rétention des fichiers (jours) et nombre maximal de fichiers. */
	const RETENTION_DAYS = 30;
	const MAX_FILES      = 30;
	/** Marqueur écrit une fois quand le fichier du jour est plein. */
	const FULL_MARKER    = 'JOURNAL DU JOUR PLEIN';

	private static $instance = null;

	/** @var array Hachages des erreurs déjà vues dans la requête (dédup). */
	private static $seen = array();

	/** @var bool Empêche une erreur de journalisation de rappeler l'enregistrement. */
	private static $recording = false;

	/** @var string|null Dossier des journaux, résolu une fois par requête. */
	private static $dir_memo = null;

	private $registered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		register_shutdown_function( array( $this, 'on_shutdown' ) );

		// Purge quotidienne TOUJOURS active (même si le nettoyage automatique est
		// coupé ou que les tables manquent) : fichiers de secours + plafond de
		// lignes de la table des logs. Réutilise l'événement cron existant.
		if ( function_exists( 'add_action' ) ) {
			add_action( 'cu_cron_cleanup', array( __CLASS__, 'scheduled_maintenance' ), 5 );
		}
	}

	// -------------------------------------------------------------------------
	// Capture
	// -------------------------------------------------------------------------

	/** Erreurs FATALES (dont exceptions non capturées) — seul point d'interception. */
	public function on_shutdown() {
		$e = error_get_last();
		if ( empty( $e ) || ! isset( $e['type'] ) ) {
			return;
		}
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( in_array( $e['type'], $fatal, true ) && $this->is_plugin_file( isset( $e['file'] ) ? $e['file'] : '' ) ) {
			$this->record( 'error', 'Erreur fatale (' . $this->label_for( $e['type'] ) . ')', $e['message'], $e['file'], $e['line'] );
		}
	}

	// -------------------------------------------------------------------------
	// Cœur
	// -------------------------------------------------------------------------

	private function record( $level, $label, $message, $file, $line ) {
		if ( self::$recording ) {
			return;
		}
		self::$recording = true;

		$rel   = $this->relative_path( $file );
		$msg   = $this->truncate( (string) $message, 400 );
		$short = sprintf( '%s : %s (%s:%d)', $label, $msg, $rel, (int) $line );

		// Les nombres variables (octets, IDs…) ne doivent pas créer une « nouvelle » erreur.
		$hash = md5( $level . '|' . preg_replace( '/\d+/', '#', $msg ) . '|' . $file . '|' . (int) $line );
		if ( isset( self::$seen[ $hash ] ) || self::rate_limited( $hash ) ) {
			self::$recording = false;
			return;
		}
		self::$seen[ $hash ] = true;

		// Puits 1 : base de données → visible sur la page Logs (module "system").
		try {
			if ( class_exists( 'CU_DB' ) && class_exists( 'CU_Logger' ) && CU_DB::tables_exist_for( array( 'logs' ) ) ) {
				CU_Logger::log( 'system', $level, $short, array(
					'type' => $label,
					'file' => $rel,
					'line' => (int) $line,
				) );
			}
		} catch ( \Throwable $ignore ) {
			// La base est peut-être la cause : on se rabat sur le fichier.
		}

		// Puits 2 : fichier de secours (toujours tenté).
		self::write_file( $level, $short );
		self::$recording = false;
	}

	/**
	 * Une même erreur au plus une fois par fenêtre, d'une requête à l'autre (un
	 * fichier cassé produit la même fatale à chaque visite). Sans base
	 * disponible, la borne de taille du fichier reste le garde-fou.
	 */
	private static function rate_limited( $hash ) {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return false;
		}
		try {
			$key = 'cu_errlog_' . substr( $hash, 0, 12 );
			if ( get_transient( $key ) ) {
				return true;
			}
			set_transient( $key, 1, self::RATE_WINDOW );
		} catch ( \Throwable $ignore ) {
			return false;
		}
		return false;
	}

	/** Une ligne ne peut ni sortir du commentaire PHP, ni en ouvrir un nouveau, ni se couper. */
	private static function neutralize( $line ) {
		$line = str_replace( array( '<?', '?>' ), array( '< ?', '? >' ), (string) $line );
		return (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $line );
	}

	private static function write_file( $level, $line ) {
		try {
			$dir = self::log_dir();
			if ( ! $dir ) {
				return;
			}
			// Extension PHP + lignes commentées : même si Nginx ignore .htaccess,
			// une requête web directe n'expose jamais le contenu du journal.
			$file  = $dir . '/error-' . gmdate( 'Y-m-d' ) . '.php';
			$stamp = '// [' . gmdate( 'Y-m-d H:i:s' ) . '] ';
			$entry = $stamp . strtoupper( (string) $level ) . '  ' . self::neutralize( $line ) . "\n";
			clearstatcache( true, $file );
			$exists = file_exists( $file );
			$size   = $exists ? (int) @filesize( $file ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$cap    = max( 65536, (int) ( function_exists( 'apply_filters' ) ? apply_filters( 'cu_error_log_max_bytes', self::MAX_FILE_BYTES ) : self::MAX_FILE_BYTES ) );
			if ( $size >= $cap ) {
				return;
			}
			if ( $size + strlen( $entry ) > $cap ) {
				if ( self::ends_with_marker( $file ) ) {
					return;
				}
				$entry = $stamp . 'WARNING  ' . self::FULL_MARKER . ' (' . (int) round( $cap / 1024 ) . ' Ko) : erreurs suivantes ignorées jusqu’à demain.' . "\n";
			}
			if ( ! $exists ) {
				$entry = "<?php defined( 'ABSPATH' ) || exit;\n" . $entry;
			}
			// LOCK_EX évite la corruption lors d'écritures concurrentes.
			@file_put_contents( $file, $entry, FILE_APPEND | LOCK_EX ); // phpcs:ignore
		} catch ( \Throwable $ignore ) {
			// Rien : la journalisation ne doit jamais casser le site.
		}
	}

	private static function ends_with_marker( $file ) {
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return true; // illisible : ne rien ajouter
		}
		$tail = '';
		if ( 0 === fseek( $handle, -400, SEEK_END ) || 0 === fseek( $handle, 0 ) ) {
			$tail = (string) fread( $handle, 400 );
		}
		fclose( $handle );
		return false !== strpos( $tail, self::FULL_MARKER );
	}

	/**
	 * Écrit directement dans le puits de secours sans retenter la base.
	 * Utilisé notamment quand CU_Logger ne peut pas insérer une ligne SQL.
	 */
	public static function fallback( $level, $message, $context = array() ) {
		if ( self::$recording ) {
			return;
		}
		self::$recording = true;
		try {
			$level = in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'error';
			$line  = self::instance()->truncate( (string) $message, 500 );
			if ( ! empty( $context ) ) {
				if ( class_exists( 'CU_Logger' ) && is_array( $context ) ) {
					$context = CU_Logger::sanitize_context( $context );
				}
				$json = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( is_string( $json ) && '' !== $json ) {
					$line .= ' | ' . self::instance()->truncate( $json, 700 );
				}
			}
			$hash = md5( 'fallback|' . $level . '|' . preg_replace( '/\d+/', '#', (string) $message ) );
			if ( ! isset( self::$seen[ $hash ] ) ) {
				self::$seen[ $hash ] = true;
				self::write_file( $level, $line );
			}
		} catch ( \Throwable $ignore ) {
			// La journalisation ne doit jamais casser le site.
		}
		self::$recording = false;
	}

	// -------------------------------------------------------------------------
	// Helpers publics (utilisés par les endpoints Réglages > Développeur)
	// -------------------------------------------------------------------------

	/** Dossier des journaux d'erreurs (créé + protégé si nécessaire). */
	public static function log_dir() {
		if ( null !== self::$dir_memo ) {
			return self::$dir_memo;
		}
		if ( ! function_exists( 'wp_get_upload_dir' ) ) {
			return '';
		}
		$up = wp_get_upload_dir();
		if ( empty( $up['basedir'] ) ) {
			return '';
		}
		$dir = rtrim( $up['basedir'], '/\\' ) . '/cyberpush-ultra';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) ) {
			// Empêche l'accès web direct aux journaux (Apache 2.2/2.4 et IIS).
			$apache_file  = $dir . '/.htaccess';
			$apache_rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
			$legacy_rules = "Order allow,deny\nDeny from all\n";
			if ( ! file_exists( $apache_file ) || $legacy_rules === @file_get_contents( $apache_file ) ) { // phpcs:ignore
				@file_put_contents( $apache_file, $apache_rules ); // phpcs:ignore
			}
			if ( ! file_exists( $dir . '/web.config' ) ) {
				$iis_rules = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></system.webServer></configuration>';
				@file_put_contents( $dir . '/web.config', $iis_rules ); // phpcs:ignore
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				@file_put_contents( $dir . '/index.php', "<?php // Silence is golden." ); // phpcs:ignore
			}
			self::migrate_legacy_logs( $dir );
		}
		self::$dir_memo = is_dir( $dir ) ? $dir : '';
		return self::$dir_memo;
	}

	/** Convertit les anciens .log en fichiers PHP non divulgables par Nginx. */
	private static function migrate_legacy_logs( $dir ) {
		foreach ( (array) glob( $dir . '/error-*.log' ) as $legacy ) {
			$stem = sanitize_file_name( pathinfo( $legacy, PATHINFO_FILENAME ) );
			$dest = $dir . '/' . $stem . '-legacy.php';
			if ( file_exists( $dest ) ) {
				$dest = $dir . '/' . $stem . '-legacy-' . substr( md5( $legacy ), 0, 8 ) . '.php';
			}
			$in  = @fopen( $legacy, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out = $in ? @fopen( $dest, 'xb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $in || ! $out ) {
				if ( is_resource( $in ) ) {
					fclose( $in );
				}
				continue;
			}
			$ok = false !== fwrite( $out, "<?php defined( 'ABSPATH' ) || exit;\n" );
			while ( $ok && false !== ( $line = fgets( $in ) ) ) {
				$ok = false !== fwrite( $out, '// ' . self::neutralize( rtrim( $line, "\r\n" ) ) . "\n" );
			}
			$ok = $ok && feof( $in ) && fflush( $out );
			fclose( $in );
			fclose( $out );
			if ( $ok ) {
				@unlink( $legacy ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} else {
				@unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/** Renvoie les dernières lignes du journal d'erreurs fichier (tous jours confondus). */
	public static function tail( $max_lines = 200 ) {
		$max_lines = max( 1, min( 1000, (int) $max_lines ) );
		$dir = self::log_dir();
		if ( ! $dir ) {
			return array();
		}
		$files = array_merge(
			(array) glob( $dir . '/error-*.php' ),
			(array) glob( $dir . '/error-*.log' )
		);
		if ( empty( $files ) ) {
			return array();
		}
		rsort( $files ); // plus récents d'abord
		$lines = array();
		foreach ( $files as $f ) {
			$remaining = $max_lines - count( $lines );
			$lines     = array_merge( $lines, self::tail_file( $f, $remaining ) );
			if ( count( $lines ) >= $max_lines ) {
				break;
			}
		}
		return array_slice( $lines, 0, $max_lines );
	}

	/** Lit seulement la fin d'un fichier pour éviter de charger un gros journal en mémoire. */
	private static function tail_file( $file, $max_lines ) {
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle || 0 !== fseek( $handle, 0, SEEK_END ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			return array();
		}

		$position = ftell( $handle );
		$buffer   = '';
		$needed   = max( 1, (int) $max_lines ) + 2;
		while ( $position > 0 && substr_count( $buffer, "\n" ) < $needed ) {
			$size      = min( 8192, $position );
			$position -= $size;
			if ( 0 !== fseek( $handle, $position ) ) {
				break;
			}
			$chunk = fread( $handle, $size );
			if ( false === $chunk ) {
				break;
			}
			$buffer = $chunk . $buffer;
		}
		fclose( $handle );

		$rows = preg_split( '/\r\n|\n|\r/', $buffer );
		if ( $position > 0 && ! empty( $rows ) ) {
			array_shift( $rows ); // Le premier fragment peut être une ligne incomplète.
		}
		$rows = array_reverse( array_values( array_filter( $rows, static function ( $line ) {
			$trimmed = ltrim( $line );
			return '' !== $trimmed && 0 !== strpos( $trimmed, '<?php' );
		} ) ) );

		$rows = array_map( static function ( $line ) {
			return 0 === strpos( $line, '// ' ) ? substr( $line, 3 ) : $line;
		}, $rows );
		return array_slice( $rows, 0, max( 1, (int) $max_lines ) );
	}

	/**
	 * Supprime les fichiers de secours plus anciens que la rétention, et ne
	 * garde au plus que les MAX_FILES plus récents.
	 */
	public static function prune( $days = 90 ) {
		$dir = self::log_dir();
		if ( ! $dir ) {
			return 0;
		}
		$cutoff  = time() - max( 1, (int) $days ) * DAY_IN_SECONDS;
		$deleted = 0;
		$files   = array_merge( (array) glob( $dir . '/error-*.php' ), (array) glob( $dir . '/error-*.log' ) );
		$kept    = array();
		foreach ( $files as $file ) {
			$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $mtime && $mtime < $cutoff ) {
				if ( @unlink( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					$deleted++;
				}
				continue;
			}
			$kept[ $file ] = (int) $mtime;
		}
		if ( count( $kept ) > self::MAX_FILES ) {
			arsort( $kept );
			foreach ( array_slice( array_keys( $kept ), self::MAX_FILES ) as $file ) {
				if ( @unlink( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					$deleted++;
				}
			}
		}
		return $deleted;
	}

	/** Maintenance quotidienne (cu_cron_cleanup), indépendante des réglages et des tables. */
	public static function scheduled_maintenance() {
		$days = (int) ( function_exists( 'apply_filters' ) ? apply_filters( 'cu_error_log_retention_days', self::RETENTION_DAYS ) : self::RETENTION_DAYS );
		self::prune( max( 1, $days ) );
		if ( class_exists( 'CU_Logger' ) ) {
			CU_Logger::enforce_cap();
		}
	}

	/** Supprime tous les fichiers de journal d'erreurs. */
	public static function clear() {
		$dir = self::log_dir();
		if ( ! $dir ) {
			return 0;
		}
		$files = array_merge(
			(array) glob( $dir . '/error-*.php' ),
			(array) glob( $dir . '/error-*.log' )
		);
		$deleted = 0;
		foreach ( (array) $files as $f ) {
			if ( @unlink( $f ) ) { // phpcs:ignore
				$deleted++;
			}
		}
		return $deleted;
	}

	// -------------------------------------------------------------------------
	// Utilitaires internes
	// -------------------------------------------------------------------------

	private function is_plugin_file( $file ) {
		if ( empty( $file ) || ! defined( 'CU_PLUGIN_DIR' ) ) {
			return false;
		}
		$file = str_replace( '\\', '/', $file );
		$base = rtrim( str_replace( '\\', '/', CU_PLUGIN_DIR ), '/' ) . '/';
		return 0 === strpos( $file, $base );
	}

	private function relative_path( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		$base = defined( 'CU_PLUGIN_DIR' ) ? str_replace( '\\', '/', CU_PLUGIN_DIR ) : '';
		return $base ? ltrim( str_replace( $base, '', $file ), '/' ) : basename( $file );
	}

	private function label_for( $errno ) {
		$map = array(
			E_ERROR             => 'E_ERROR',
			E_WARNING           => 'E_WARNING',
			E_PARSE             => 'E_PARSE',
			E_NOTICE            => 'E_NOTICE',
			E_CORE_ERROR        => 'E_CORE_ERROR',
			E_CORE_WARNING      => 'E_CORE_WARNING',
			E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
			E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
			E_USER_ERROR        => 'E_USER_ERROR',
			E_USER_WARNING      => 'E_USER_WARNING',
			E_USER_NOTICE       => 'E_USER_NOTICE',
			E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			E_DEPRECATED        => 'E_DEPRECATED',
			E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
		);
		return isset( $map[ $errno ] ) ? $map[ $errno ] : ( 'E_' . $errno );
	}

	private function truncate( $str, $len ) {
		$str = trim( preg_replace( '/\s+/', ' ', (string) $str ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $str, 0, $len ) : substr( $str, 0, $len );
	}
}

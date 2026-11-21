<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Journal d'activité unifié. Chaque module (Analytics, Firewall, SEO, IA,
 * WooCommerce, Système) écrit ici pour alimenter la page "Logs" qui
 * fournit une chronologie complète de tout ce que fait le plugin.
 *
 * Anti-inondation : un même message (module + niveau + texte) répété dans la
 * minute ne crée pas de nouvelle ligne ; la ligne existante porte un compteur
 * (contexte « repeat », affiché « ×N »). Dans une même requête, les répétitions
 * sont comptées en mémoire et écrites une seule fois en fin de requête.
 *
 * Confidentialité du contexte : les clés secrètes (mot de passe, jeton…) ne
 * sont jamais stockées, et un identifiant saisi (« username ») qui ne
 * correspond à aucun compte est masqué — c'est souvent un mot de passe tapé
 * dans le mauvais champ.
 */
class CU_Logger {

	const MODULES = array( 'analytics', 'firewall', 'seo', 'ai', 'woocommerce', 'system' );
	const LEVELS  = array( 'debug', 'info', 'warning', 'error' );

	/** Fenêtre de regroupement des messages identiques (secondes). */
	const THROTTLE_WINDOW = 60;
	/** Plafond de lignes de la table (garde-fou, même si le nettoyage automatique est coupé). */
	const ROW_CAP = 100000;
	/** Clés de contexte jamais stockées en clair. */
	const SECRET_KEYS = array( 'password', 'pass', 'pwd', 'passwd', 'user_pass', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie', 'nonce' );
	/** Identifiants SAISIS par un visiteur : gardés seulement s'ils désignent un compte existant. */
	const TYPED_LOGIN_KEYS = array( 'username', 'user_login', 'login', 'log' );

	/** @var array<string, array{id:int, pending:int}> Lignes déjà écrites dans cette requête, par signature. */
	private static $memo = array();
	private static $flush_hooked = false;

	/**
	 * @param string $module  analytics | firewall | seo | ai | woocommerce | system
	 * @param string $level   debug | info | warning | error
	 * @param string $message Message court et lisible (ex: "IP bloquée", "Article réindexé").
	 * @param array  $context Détails additionnels (facultatif), stockés en JSON.
	 * @return int|false 1 si écrit ou compté, false sinon.
	 */
	public static function log( $module, $level, $message, $context = array() ) {
		global $wpdb;

		if ( ! CU_DB::tables_exist_for( array( 'logs' ) ) ) {
			return false;
		}

		if ( 'debug' === $level && ! CU_Settings::get( 'developer', 'debug_mode', false ) ) {
			return false;
		}

		$message = is_scalar( $message ) ? (string) $message : wp_json_encode( $message );
		if ( ! is_string( $message ) ) {
			$message = __( 'Message de journal invalide.', 'cyberpush-ultra' );
		}
		$module  = in_array( $module, self::MODULES, true ) ? $module : 'system';
		$level   = in_array( $level, self::LEVELS, true ) ? $level : 'info';
		$message = CU_Text::substr( $message, 0, 500 );
		$sig     = md5( $module . '|' . $level . '|' . $message );
		$table   = CU_DB::table( 'logs' );

		// 1. Déjà écrit dans cette requête : simple compteur en mémoire.
		if ( isset( self::$memo[ $sig ] ) ) {
			self::$memo[ $sig ]['pending']++;
			self::hook_flush();
			return 1;
		}

		// 2. Même message dans la dernière minute (autre requête) : la ligne
		//    existante est incrémentée en fin de requête au lieu d'en créer une.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE created_at >= %s AND module = %s AND level = %s AND message = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				CU_DB::date_from_now( 'Y-m-d H:i:s', -self::THROTTLE_WINDOW ),
				$module,
				$level,
				$message
			),
			ARRAY_A
		);
		if ( is_array( $row ) && ! empty( $row['id'] ) ) {
			self::remember( $sig, (int) $row['id'], 1 );
			return 1;
		}

		$context = self::sanitize_context( is_array( $context ) ? $context : array( 'value' => $context ) );
		$result  = $wpdb->insert(
			$table,
			array(
				'created_at' => current_time( 'mysql' ),
				'module'     => $module,
				'level'      => $level,
				'message'    => $message,
				'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
			)
		);
		if ( false === $result ) {
			if ( class_exists( 'CU_Error_Logger' ) ) {
				CU_Error_Logger::fallback(
					'error',
					__( 'Impossible d’écrire dans la table des logs.', 'cyberpush-ultra' ),
					array(
						'module'   => $module,
						'level'    => $level,
						'message'  => CU_Text::substr( $message, 0, 300 ),
						'db_error' => CU_Text::substr( (string) $wpdb->last_error, 0, 300 ),
					)
				);
			}
			return false;
		}
		self::remember( $sig, (int) $wpdb->insert_id, 0 );
		return 1;
	}

	private static function remember( $sig, $id, $pending ) {
		if ( count( self::$memo ) >= 200 ) {
			self::flush_repeats(); // longue tâche cron : mémoire bornée
			self::$memo = array();
		}
		self::$memo[ $sig ] = array( 'id' => (int) $id, 'pending' => (int) $pending );
		if ( $pending > 0 ) {
			self::hook_flush();
		}
	}

	private static function hook_flush() {
		if ( ! self::$flush_hooked && function_exists( 'add_action' ) ) {
			self::$flush_hooked = true;
			// Très tard : les répétitions journalisées par d'autres callbacks shutdown sont comptées.
			add_action( 'shutdown', array( __CLASS__, 'flush_repeats' ), 9999 );
		}
	}

	/** Écrit les compteurs « ×N » accumulés (une requête UPDATE par message répété). */
	public static function flush_repeats() {
		global $wpdb;
		$table = CU_DB::table( 'logs' );
		foreach ( self::$memo as $sig => $entry ) {
			if ( $entry['pending'] <= 0 || $entry['id'] <= 0 ) {
				continue;
			}
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT context FROM {$table} WHERE id = %d", $entry['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ctx = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
			$ctx = is_array( $ctx ) ? $ctx : array();
			$ctx['repeat']  = ( isset( $ctx['repeat'] ) ? max( 1, (int) $ctx['repeat'] ) : 1 ) + (int) $entry['pending'];
			$ctx['last_at'] = current_time( 'mysql' );
			$wpdb->update( $table, array( 'context' => wp_json_encode( $ctx ) ), array( 'id' => $entry['id'] ) );
			self::$memo[ $sig ]['pending'] = 0;
		}
	}

	/**
	 * Politique de contexte : secrets retirés, identifiant saisi masqué s'il ne
	 * correspond à aucun compte (souvent un mot de passe tapé dans le champ
	 * identifiant), chaînes longues tronquées.
	 */
	public static function sanitize_context( array $context, $depth = 0 ) {
		$out = array();
		foreach ( $context as $key => $value ) {
			$lkey = is_string( $key ) ? strtolower( $key ) : $key;
			if ( is_string( $lkey ) && in_array( $lkey, self::SECRET_KEYS, true ) ) {
				$out[ $key ] = '[masqué]';
				continue;
			}
			if ( is_string( $lkey ) && in_array( $lkey, self::TYPED_LOGIN_KEYS, true ) && is_scalar( $value ) ) {
				$out[ $key ] = self::mask_typed_login( (string) $value );
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = $depth < 4 ? self::sanitize_context( $value, $depth + 1 ) : '[…]';
			} elseif ( is_string( $value ) && CU_Text::length( $value ) > 2000 ) {
				$out[ $key ] = CU_Text::substr( $value, 0, 2000 ) . '…';
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/** « admin » (compte existant) reste lisible ; « Tr0ub4dor&3 » devient « Tr…(11) ». */
	private static function mask_typed_login( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$exists = ( function_exists( 'username_exists' ) && username_exists( $value ) )
			|| ( function_exists( 'email_exists' ) && is_email( $value ) && email_exists( $value ) );
		if ( $exists ) {
			return $value;
		}
		$len = CU_Text::length( $value );
		return CU_Text::substr( $value, 0, $len > 6 ? 2 : 1 ) . '…(' . $len . ')';
	}

	/**
	 * Purge le journal en conservant uniquement les dernières 24 heures.
	 */
	public static function purge_keep_last_24h() {
		global $wpdb;
		if ( ! CU_DB::tables_exist_for( array( 'logs' ) ) ) {
			return false;
		}
		$table = CU_DB::table( 'logs' );
		$result = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", CU_DB::date_from_now( 'Y-m-d H:i:s', -DAY_IN_SECONDS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( class_exists( 'CU_Error_Logger' ) ) {
			CU_Error_Logger::prune( 1 );
		}
		return $result;
	}

	public static function purge_all() {
		global $wpdb;
		if ( ! CU_DB::tables_exist_for( array( 'logs' ) ) ) {
			return false;
		}
		self::$memo = array();
		$table = CU_DB::table( 'logs' );
		return $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Plafond dur de la table (branché sur cu_cron_cleanup par CU_Error_Logger) :
	 * s'applique même si le nettoyage automatique par âge est désactivé.
	 */
	public static function enforce_cap() {
		global $wpdb;
		if ( ! CU_DB::tables_exist_for( array( 'logs' ) ) ) {
			return;
		}
		$table = CU_DB::table( 'logs' );
		$max   = max( 1000, (int) apply_filters( 'cu_logs_row_cap', self::ROW_CAP ) );
		$cut   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $cut > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}

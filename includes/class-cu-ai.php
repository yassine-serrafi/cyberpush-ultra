<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Abstraction multi-fournisseurs pour les fonctionnalités d'Intelligence
 * Artificielle (optimisation SEO, génération de Meta, FAQ, maillage...).
 * Utilisée principalement par le module SEO.
 */
class CU_AI {

	/** Option (non autoloadée) du total de jetons consommés. */
	const TOKENS_OPTION = 'cu_ai_tokens_used';

	/**
	 * Teste la connexion au fournisseur actuellement configuré.
	 *
	 * @return array{success:bool, message:string}
	 */
	public static function test_connection() {
		$settings = CU_Settings::module( 'ai' );
		$provider = $settings['provider'];
		$keys     = $settings['api_keys'];

		switch ( $provider ) {
			case 'openai':
				if ( empty( $keys['openai'] ) ) {
					return array( 'success' => false, 'message' => __( "Clé API OpenAI manquante.", 'cyberpush-ultra' ) );
				}
				$response = wp_remote_get(
					'https://api.openai.com/v1/models',
					array(
						'timeout'             => 10,
						'limit_response_size' => 256 * KB_IN_BYTES,
						'headers'             => array( 'Authorization' => 'Bearer ' . $keys['openai'] ),
					)
				);
				break;

			case 'anthropic':
				if ( empty( $keys['anthropic'] ) ) {
					return array( 'success' => false, 'message' => __( 'Clé API Anthropic manquante.', 'cyberpush-ultra' ) );
				}
				$response = wp_remote_post(
					'https://api.anthropic.com/v1/messages',
					array(
						'timeout'             => 10,
						'limit_response_size' => 256 * KB_IN_BYTES,
						'headers' => array(
							'x-api-key'         => $keys['anthropic'],
							'anthropic-version' => '2023-06-01',
							'Content-Type'      => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'model'      => self::provider_model( $settings, 'anthropic', 'claude-haiku-4-5' ),
								'max_tokens' => 8,
								'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
							)
						),
					)
				);
				break;

			case 'google':
				if ( empty( $keys['google'] ) ) {
					return array( 'success' => false, 'message' => __( 'Clé API Google Gemini manquante.', 'cyberpush-ultra' ) );
				}
				$response = wp_remote_get(
					'https://generativelanguage.googleapis.com/v1beta/models',
					array( 'timeout' => 10, 'limit_response_size' => 256 * KB_IN_BYTES, 'headers' => array( 'x-goog-api-key' => $keys['google'] ) )
				);
				break;

			case 'mistral':
				if ( empty( $keys['mistral'] ) ) {
					return array( 'success' => false, 'message' => __( 'Clé API Mistral manquante.', 'cyberpush-ultra' ) );
				}
				$response = wp_remote_get(
					'https://api.mistral.ai/v1/models',
					array( 'timeout' => 10, 'limit_response_size' => 256 * KB_IN_BYTES, 'headers' => array( 'Authorization' => 'Bearer ' . $keys['mistral'] ) )
				);
				break;

			case 'ollama':
				$url = self::ollama_endpoint( isset( $keys['ollama_url'] ) ? $keys['ollama_url'] : '', '/api/tags' );
				if ( is_wp_error( $url ) ) {
					return array( 'success' => false, 'message' => $url->get_error_message() );
				}
				$response = wp_remote_get( $url, array( 'timeout' => 5, 'redirection' => 0, 'limit_response_size' => 256 * KB_IN_BYTES ) );
				break;

			case 'custom':
				if ( empty( $keys['custom_url'] ) ) {
					return array( 'success' => false, 'message' => __( "URL du fournisseur personnalisé manquante.", 'cyberpush-ultra' ) );
				}
				$url = esc_url_raw( $keys['custom_url'] );
				if ( ! wp_http_validate_url( $url ) ) {
					return array( 'success' => false, 'message' => __( 'URL du fournisseur personnalisé invalide.', 'cyberpush-ultra' ) );
				}
				$response = wp_safe_remote_get(
					$url,
					array(
						'timeout'             => 10,
						'redirection'         => 0,
						'limit_response_size' => 256 * KB_IN_BYTES,
						'headers' => ! empty( $keys['custom_key'] ) ? array( 'Authorization' => 'Bearer ' . $keys['custom_key'] ) : array(),
					)
				);
				break;

			default:
				return array( 'success' => false, 'message' => __( 'Fournisseur IA non pris en charge.', 'cyberpush-ultra' ) );
		}

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => self::redact_secrets( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( ( $code >= 200 && $code < 300 ) || ( 'custom' === $provider && 405 === (int) $code ) ) {
			return array( 'success' => true, 'message' => __( 'Connexion réussie.', 'cyberpush-ultra' ) );
		}

		return array(
			/* translators: %d: HTTP status code */
			'success' => false,
			'message' => sprintf( __( 'Le fournisseur a répondu avec le code %d.', 'cyberpush-ultra' ), $code ),
		);
	}

	/**
	 * Demande au fournisseur configuré de proposer un Meta Title et une
	 * Meta Description optimisés pour un contenu donné. Les propositions
	 * sont retournées sans jamais être appliquées automatiquement : c'est
	 * à l'utilisateur de valider les changements.
	 *
	 * @return array{success:bool, message?:string, meta_title?:string, meta_description?:string, notes?:string}
	 */
	public static function suggest_seo_meta( $title, $content_excerpt, $current_meta_title = '', $current_meta_description = '' ) {
		$settings = CU_Settings::module( 'ai' );
		$title_max = (int) CU_Settings::get( 'seo', 'title_max', 60 );
		$description_max = (int) CU_Settings::get( 'seo', 'meta_desc_max', 160 );

		$prompt = sprintf(
			"Tu es un expert SEO francophone. Voici un contenu WordPress:\n\nTitre: %s\nExtrait: %s\nMeta Title actuel: %s\nMeta Description actuelle: %s\n\nPropose un NOUVEAU Meta Title (max %d caractères) et une NOUVELLE Meta Description (max %d caractères) optimisés pour le SEO, en %s, avec un ton %s. Réponds STRICTEMENT en JSON avec les clés meta_title, meta_description, notes.",
			$title,
			CU_Text::substr( wp_strip_all_tags( $content_excerpt ), 0, 1500 ),
			$current_meta_title,
			$current_meta_description,
			$title_max,
			$description_max,
			$settings['language'] === 'fr' ? 'français' : $settings['language'],
			$settings['tone']
		);

		$raw = self::complete( $prompt );

		if ( is_wp_error( $raw ) ) {
			return array( 'success' => false, 'message' => $raw->get_error_message() );
		}

		$json = self::extract_json( $raw );

		if ( ! $json || empty( $json['meta_title'] ) ) {
			return array( 'success' => false, 'message' => __( "Réponse de l'IA illisible.", 'cyberpush-ultra' ) );
		}

		return array(
			'success'           => true,
			// Texte brut sans balises (l'affichage est échappé côté JS) : « Prix < 10 € »
			// ne doit pas devenir « Prix &lt; 10 € ».
			'meta_title'        => CU_Text::substr( self::plain_text( $json['meta_title'] ), 0, $title_max ),
			'meta_description'  => CU_Text::substr( self::plain_text( isset( $json['meta_description'] ) ? $json['meta_description'] : '' ), 0, $description_max ),
			'notes'             => CU_Text::substr( self::plain_text( isset( $json['notes'] ) ? $json['notes'] : '' ), 0, 1000 ),
		);
	}

	/**
	 * Le fournisseur IA choisi dispose-t-il de ce qu'il faut pour répondre
	 * (clé ou URL) ? Sert à afficher un état honnête, sans appel réseau.
	 */
	public static function is_configured() {
		$settings = CU_Settings::module( 'ai' );
		$keys     = isset( $settings['api_keys'] ) && is_array( $settings['api_keys'] ) ? $settings['api_keys'] : array();
		$provider = isset( $settings['provider'] ) ? (string) $settings['provider'] : '';
		if ( 'ollama' === $provider ) {
			return ! empty( $keys['ollama_url'] );
		}
		if ( 'custom' === $provider ) {
			return ! empty( $keys['custom_url'] );
		}
		return '' !== $provider && ! empty( $keys[ $provider ] );
	}

	/**
	 * Envoie un prompt texte au fournisseur configuré et renvoie la réponse brute.
	 * Plafond global d'appels par minute : protège le quota et la facture du
	 * fournisseur contre un double-clic, une boucle JS ou une tâche répétée.
	 *
	 * @return string|WP_Error
	 */
	public static function complete( $prompt ) {
		if ( ! self::take_call_budget() ) {
			return new WP_Error( 'cu_ai_rate_limited', __( 'Trop de requêtes IA en une minute : réessayez dans un instant.', 'cyberpush-ultra' ), array( 'status' => 429 ) );
		}
		$result = self::dispatch_complete( $prompt );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), self::redact_secrets( $result->get_error_message() ), $result->get_error_data() );
		}
		return $result;
	}

	private static function dispatch_complete( $prompt ) {
		$settings = CU_Settings::module( 'ai' );
		$provider = $settings['provider'];
		$keys     = $settings['api_keys'];

		switch ( $provider ) {
			case 'openai':
				if ( empty( $keys['openai'] ) ) {
					return new WP_Error( 'cu_ai_no_key', __( 'Clé API OpenAI manquante.', 'cyberpush-ultra' ) );
				}
				return self::openai_compatible_complete(
					'https://api.openai.com/v1/chat/completions',
					$keys['openai'],
					self::provider_model( $settings, 'openai', 'gpt-4o-mini' ),
					$prompt
				);

			case 'anthropic':
				if ( empty( $keys['anthropic'] ) ) {
					return new WP_Error( 'cu_ai_no_key', __( 'Clé API Anthropic manquante.', 'cyberpush-ultra' ) );
				}
				$response = wp_remote_post(
					'https://api.anthropic.com/v1/messages',
					array(
						'timeout'             => 30,
						'limit_response_size' => 2 * MB_IN_BYTES,
						'headers' => array(
							'x-api-key'         => $keys['anthropic'],
							'anthropic-version' => '2023-06-01',
							'Content-Type'      => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'model'      => self::provider_model( $settings, 'anthropic', 'claude-haiku-4-5' ),
								'max_tokens' => 1024,
								'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
							)
						),
					)
				);
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$http_error = self::response_http_error( $response );
				if ( $http_error ) {
					return $http_error;
				}
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				self::track_tokens( (int) ( isset( $body['usage']['input_tokens'] ) ? $body['usage']['input_tokens'] : 0 ) + (int) ( isset( $body['usage']['output_tokens'] ) ? $body['usage']['output_tokens'] : 0 ) );
				return isset( $body['content'][0]['text'] ) ? $body['content'][0]['text'] : new WP_Error( 'cu_ai_bad_response', __( 'Réponse invalide du fournisseur IA.', 'cyberpush-ultra' ) );

			case 'google':
				if ( empty( $keys['google'] ) ) {
					return new WP_Error( 'cu_ai_no_key', __( 'Clé API Google Gemini manquante.', 'cyberpush-ultra' ) );
				}
				$model = self::provider_model( $settings, 'google', 'gemini-3.5-flash' );
				$response = wp_remote_post(
					'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
					array(
						'timeout'             => 30,
						'limit_response_size' => 2 * MB_IN_BYTES,
						'headers' => array( 'x-goog-api-key' => $keys['google'], 'Content-Type' => 'application/json' ),
						'body'    => wp_json_encode( array( 'contents' => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ) ) ),
					)
				);
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$http_error = self::response_http_error( $response );
				if ( $http_error ) {
					return $http_error;
				}
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				self::track_tokens( isset( $body['usageMetadata']['totalTokenCount'] ) ? (int) $body['usageMetadata']['totalTokenCount'] : 0 );
				return isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ? $body['candidates'][0]['content']['parts'][0]['text'] : new WP_Error( 'cu_ai_bad_response', __( 'Réponse invalide de Google Gemini.', 'cyberpush-ultra' ) );

			case 'mistral':
				if ( empty( $keys['mistral'] ) ) {
					return new WP_Error( 'cu_ai_no_key', __( 'Clé API Mistral manquante.', 'cyberpush-ultra' ) );
				}
				return self::openai_compatible_complete(
					'https://api.mistral.ai/v1/chat/completions',
					$keys['mistral'],
					self::provider_model( $settings, 'mistral', 'mistral-small-latest' ),
					$prompt
				);

			case 'ollama':
				$url = self::ollama_endpoint( isset( $keys['ollama_url'] ) ? $keys['ollama_url'] : '', '/api/generate' );
				if ( is_wp_error( $url ) ) {
					return $url;
				}
				$response = wp_remote_post(
					$url,
					array(
						'timeout'             => 60,
						'redirection'         => 0,
						'limit_response_size' => 2 * MB_IN_BYTES,
						'body'    => wp_json_encode(
							array(
								'model'  => self::provider_model( $settings, 'ollama', 'llama3' ),
								'prompt' => $prompt,
								'stream' => false,
							)
						),
					)
				);
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$http_error = self::response_http_error( $response );
				if ( $http_error ) {
					return $http_error;
				}
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				return isset( $body['response'] ) ? $body['response'] : new WP_Error( 'cu_ai_bad_response', __( 'Réponse invalide du fournisseur IA.', 'cyberpush-ultra' ) );

			case 'custom':
				if ( empty( $keys['custom_url'] ) ) {
					return new WP_Error( 'cu_ai_no_url', __( 'URL du fournisseur personnalisé manquante.', 'cyberpush-ultra' ) );
				}
				$url = esc_url_raw( $keys['custom_url'] );
				if ( ! wp_http_validate_url( $url ) ) {
					return new WP_Error( 'cu_ai_bad_url', __( 'URL du fournisseur personnalisé invalide.', 'cyberpush-ultra' ) );
				}
				return self::openai_compatible_complete( $url, isset( $keys['custom_key'] ) ? $keys['custom_key'] : '', self::provider_model( $settings, 'custom', 'default' ), $prompt, true );

			default:
				return new WP_Error( 'cu_ai_unsupported', __( "Ce fournisseur IA n'est pas encore pris en charge.", 'cyberpush-ultra' ) );
		}
	}

	/** Évite de conserver un modèle d'un autre fournisseur après un changement. */
	private static function provider_model( $settings, $provider, $fallback ) {
		$model = isset( $settings['model'] ) ? trim( (string) $settings['model'] ) : '';
		if ( '' === $model ) {
			return $fallback;
		}

		$known_prefixes = array(
			'openai'    => array( 'gpt-', 'o1', 'o3', 'o4' ),
			'anthropic' => array( 'claude-' ),
			'google'    => array( 'gemini-' ),
			'mistral'   => array( 'mistral-', 'codestral-' ),
		);
		foreach ( $known_prefixes as $owner => $prefixes ) {
			if ( $owner === $provider ) {
				continue;
			}
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( strtolower( $model ), $prefix ) ) {
					return $fallback;
				}
			}
		}
		if ( 'default' === strtolower( $model ) || ( 'ollama' === $provider && 'gpt-4o-mini' === $model ) ) {
			return $fallback;
		}
		return $model;
	}

	/** Appel d'un endpoint compatible avec le format Chat Completions d'OpenAI. */
	private static function openai_compatible_complete( $url, $key, $model, $prompt, $safe_url = false ) {
		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== trim( (string) $key ) ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$http_method = $safe_url ? 'wp_safe_remote_post' : 'wp_remote_post';
		$response    = $http_method( $url, array(
			'timeout'             => 30,
			'redirection'         => 0,
			'limit_response_size' => 2 * MB_IN_BYTES,
			'headers'             => $headers,
			'body'                => wp_json_encode( array(
				'model'    => $model,
				'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ),
			) ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$http_error = self::response_http_error( $response );
		if ( $http_error ) {
			return $http_error;
		}
		self::track_tokens( isset( $body['usage']['total_tokens'] ) ? (int) $body['usage']['total_tokens'] : 0 );
		return isset( $body['choices'][0]['message']['content'] ) && is_string( $body['choices'][0]['message']['content'] )
			? $body['choices'][0]['message']['content']
			: new WP_Error( 'cu_ai_bad_response', __( 'Réponse invalide du fournisseur IA.', 'cyberpush-ultra' ) );
	}

	/**
	 * Compteur de jetons dans sa propre option, incrémentée en SQL de façon
	 * atomique. (Réécrire tout `cu_settings` après un appel de 30 à 60 s
	 * effaçait les réglages enregistrés entre-temps.)
	 */
	private static function track_tokens( $tokens ) {
		$tokens = (int) $tokens;
		if ( $tokens <= 0 ) {
			return;
		}
		global $wpdb;
		if ( false === get_option( self::TOKENS_OPTION, false ) ) {
			add_option( self::TOKENS_OPTION, 0, '', false );
		}
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = CAST( option_value AS UNSIGNED ) + %d WHERE option_name = %s",
			$tokens,
			self::TOKENS_OPTION
		) );
		wp_cache_delete( self::TOKENS_OPTION, 'options' );
	}

	/** Total des jetons consommés depuis l'installation (affiché dans les Réglages). */
	public static function tokens_used() {
		return max( 0, (int) get_option( self::TOKENS_OPTION, 0 ) );
	}

	/** Plafond global d'appels IA par minute (filtre `cu_ai_calls_per_minute`). */
	private static function take_call_budget() {
		$max = max( 1, (int) apply_filters( 'cu_ai_calls_per_minute', 20 ) );
		// Compteur atomique (minute calendaire) : le transient lu puis réécrit
		// laissait passer toutes les requêtes simultanées.
		$state = CU_DB::counter_increment( 'ai_calls', MINUTE_IN_SECONDS );
		return (int) $state['count'] <= $max;
	}

	/**
	 * URL d'appel Ollama. Ollama tourne volontairement sur le réseau local
	 * (localhost, LAN) : ces adresses restent autorisées. Sont refusés les
	 * services de métadonnées cloud et adresses link-local (vol de jetons
	 * d'instance), les identifiants dans l'URL, les requêtes et fragments.
	 *
	 * @return string|WP_Error
	 */
	private static function ollama_endpoint( $base, $path ) {
		$parts = wp_parse_url( trim( (string) $base ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'cu_ai_bad_url', __( 'URL Ollama invalide.', 'cyberpush-ultra' ) );
		}
		// Forme canonique de l'hôte AVANT tout contrôle, et c'est elle qui est
		// appelée : « 169.254.169.254. », « METADATA.GOOGLE.INTERNAL. » (point
		// final DNS), 2852039166, 0xa9fea9fe ou 0251.0376.0251.0376 (notations
		// IPv4 que cURL accepte) désignent la même cible que la forme interdite.
		$host = rtrim( strtolower( trim( $parts['host'], '[]' ) ), '.' );
		$ipv4 = self::numeric_ipv4( $host );
		if ( '' !== $ipv4 ) {
			$host = $ipv4;
		}
		if ( '' === $host || in_array( $host, array( 'metadata', 'metadata.google.internal', 'metadata.azure.com', 'instance-data', 'instance-data.ec2.internal' ), true ) ) {
			return new WP_Error( 'cu_ai_bad_url', __( 'Adresse Ollama refusée (service de métadonnées cloud).', 'cyberpush-ultra' ) );
		}
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : (array) @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( $ips as $ip ) {
			// IPv6 qui encapsule une IPv4 (::ffff:a.b.c.d) : contrôlée comme l'IPv4.
			if ( preg_match( '/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', (string) $ip, $m ) ) {
				$ip = $m[1];
			}
			foreach ( array( '169.254.0.0/16', 'fe80::/10', 'fd00:ec2::/32', '0.0.0.0/8', '100.100.100.200/32' ) as $forbidden ) {
				if ( CU_Geo::ip_in_cidr( $ip, $forbidden ) ) {
					return new WP_Error( 'cu_ai_bad_url', __( 'Adresse Ollama refusée (link-local / métadonnées cloud).', 'cyberpush-ultra' ) );
				}
			}
		}
		$url = strtolower( $parts['scheme'] ) . '://' . ( false !== strpos( $host, ':' ) ? '[' . $host . ']' : $host )
			. ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' )
			. ( ! empty( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '' );
		return $url . $path;
	}

	/**
	 * Notations IPv4 numériques acceptées par les résolveurs (inet_aton) :
	 * entier décimal, hexadécimal (0x…), octal (0…), 1 à 4 parties. Renvoie la
	 * forme pointée canonique, ou '' si l'hôte n'est pas une telle notation.
	 */
	private static function numeric_ipv4( $host ) {
		if ( ! preg_match( '/^(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+)){0,3}$/i', $host ) ) {
			return '';
		}
		$values = array();
		foreach ( explode( '.', $host ) as $part ) {
			if ( 0 === stripos( $part, '0x' ) ) {
				$value = hexdec( substr( $part, 2 ) );
			} elseif ( strlen( $part ) > 1 && '0' === $part[0] ) {
				if ( ! preg_match( '/^[0-7]+$/', $part ) ) {
					return '';
				}
				$value = octdec( $part );
			} else {
				$value = (float) $part;
			}
			$values[] = $value;
		}
		$count = count( $values );
		$last  = $values[ $count - 1 ];
		$max   = array( 1 => 4294967295, 2 => 16777215, 3 => 65535, 4 => 255 );
		if ( $last > $max[ $count ] ) {
			return '';
		}
		$number = 0;
		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( $values[ $i ] > 255 ) {
				return '';
			}
			$number = $number * 256 + $values[ $i ];
		}
		$number = $number * pow( 256, 5 - $count ) + $last;
		$octets = array();
		for ( $i = 3; $i >= 0; $i-- ) {
			$octets[] = (int) fmod( floor( $number / pow( 256, $i ) ), 256 );
		}
		return implode( '.', $octets );
	}

	/** Masque toute clé d'API qu'un fournisseur recopierait dans un message d'erreur. */
	private static function redact_secrets( $message ) {
		$message = preg_replace( '/\b(sk-ant-|sk-proj-|sk-|AIza|Bearer\s+)[A-Za-z0-9_\-*.]{6,}/', '$1••••', (string) $message );
		return is_string( $message ) ? $message : '';
	}

	/** Convertit uniformément les réponses HTTP non-2xx des fournisseurs en WP_Error. */
	private static function response_http_error( $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = '';
		if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
			$message = $body['error']['message'];
		} elseif ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
			$message = $body['error'];
		} elseif ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
			$message = $body['message'];
		}
		$message = sanitize_text_field( $message );
		if ( '' === $message ) {
			$message = sprintf( /* translators: %d: HTTP status code */ __( 'Le fournisseur IA a répondu avec le code %d.', 'cyberpush-ultra' ), $code );
		}

		return new WP_Error( 'cu_ai_http_error', $message, array( 'status' => $code ) );
	}

	/** Réponse IA → une ligne de texte brut, sans balise ni caractère de contrôle. */
	private static function plain_text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = wp_strip_all_tags( (string) $value, true );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	private static function extract_json( $raw ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		if ( preg_match( '/\{.*\}/s', $raw, $matches ) ) {
			$decoded = json_decode( $matches[0], true );
			return is_array( $decoded ) ? $decoded : null;
		}
		return null;
	}
}

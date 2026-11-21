<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CU_SEO_Renderer — le cœur de l'audit SEO.
 * -----------------------------------------------------------------------------
 * On n'analyse JAMAIS le `post_content` brut en base : Elementor, Divi, Bricks
 * et les shortcodes n'y stockent que du JSON ou des balises non expansées.
 * À la place, on récupère l'URL PUBLIQUE réelle via une requête loopback
 * (comme Googlebot) : le serveur exécute PHP, rend le page builder + les
 * shortcodes, et nous renvoie le HTML FINAL exact que voit un visiteur.
 * On le parse ensuite avec DOMDocument (natif PHP, aucune dépendance) —
 * parfaitement compatible avec un hébergement mutualisé cPanel.
 *
 * Limite honnête : une page rendue à 100 % en JavaScript côté navigateur
 * (SPA React/Vue sans SSR) ne s'exécutera pas dans un simple wp_remote_get.
 * Elementor/Divi/Bricks/shortcodes rendent tous côté serveur → capturés
 * parfaitement. Le module signale les pages qui semblent JS-only.
 */
class CU_SEO_Renderer {

	/**
	 * Récupère le HTML final d'une URL via une requête loopback non bloquante.
	 *
	 * Budget de temps GLOBAL (redirections comprises) : chaque saut ne reçoit
	 * que le temps restant avant $deadline. Sans cela, 6 sauts × 30 s pouvaient
	 * dépasser de loin le budget d'un lot (erreurs 504/524 côté admin).
	 *
	 * @param string $url      URL du site à récupérer.
	 * @param float  $deadline Échéance absolue (microtime) ; 0 = délai réglé.
	 * @return array{ok:bool, http_status:int, html:string, time_ms:int, bytes:int, final_url:string, error:string, content_type:string, not_html:bool, redirected:bool}
	 */
	public static function fetch( $url, $deadline = 0 ) {
		$url    = esc_url_raw( (string) $url );
		$home   = self::origin_parts( home_url( '/' ) );
		$target = self::origin_parts( $url );
		if ( '' === $url || null === $home || null === $target || ! self::same_crawl_origin( $target, $home ) ) {
			return self::fetch_result( false, 0, '', 0, $url, __( 'URL de crawl invalide ou externe au site.', 'cyberpush-ultra' ) );
		}

		$timeout = max( 5, min( 30, (int) CU_Settings::get( 'seo', 'request_timeout', 15 ) ) );
		$ua      = (string) CU_Settings::get( 'seo', 'user_agent', 'CyberPushBot/1.0 (+SEO Audit)' );

		$start       = microtime( true );
		$deadline    = $deadline > 0 ? (float) $deadline : $start + $timeout;
		$current_url = $url;
		$response    = null;
		$first_hop   = 0; // code de la PREMIÈRE redirection (301, 302…), pour le diagnostic
		$hops        = 0;
		for ( $redirects = 0; $redirects <= 5; $redirects++ ) {
			$remaining = $deadline - microtime( true );
			if ( $remaining < 1 ) {
				$response = new WP_Error( 'cu_seo_deadline', __( 'Délai dépassé (réponse ou chaîne de redirections trop lente).', 'cyberpush-ultra' ) );
				break;
			}
			$response = wp_remote_get(
				$current_url,
				array(
					'timeout'     => min( $timeout, $remaining ),
					'redirection' => 0,
					// Requête loopback vers le site lui-même : même convention que le
					// cœur WordPress (certificats auto-signés en local/préproduction).
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
					// Une page anormalement volumineuse ne doit pas saturer la memoire PHP.
					'limit_response_size' => 5 * MB_IN_BYTES,
					'user-agent'  => $ua,
					'headers'     => array_merge(
						array( 'Accept' => 'text/html,application/xhtml+xml' ),
						self::audit_headers_for( $current_url )
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				break;
			}
			$redirect_code = (int) wp_remote_retrieve_response_code( $response );
			$location      = wp_remote_retrieve_header( $response, 'location' );
			if ( $redirect_code < 300 || $redirect_code >= 400 || ! $location ) {
				break;
			}
			if ( 5 === $redirects ) {
				$response = new WP_Error( 'cu_seo_too_many_redirects', __( 'Trop de redirections pendant le crawl.', 'cyberpush-ultra' ) );
				break;
			}
			$next_url    = esc_url_raw( self::resolve_url( $location, $current_url ) );
			$next_origin = self::origin_parts( $next_url );
			if ( null === $next_origin || ! self::same_crawl_origin( $next_origin, $home ) ) {
				$response = new WP_Error( 'cu_seo_external_redirect', __( 'Redirection externe refusée pendant le crawl.', 'cyberpush-ultra' ) );
				break;
			}
			if ( 0 === $first_hop ) {
				$first_hop = $redirect_code;
			}
			$hops++;
			$current_url = $next_url;
		}
		$time_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return self::fetch_result( false, 0, '', $time_ms, $current_url, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$out  = self::fetch_result( ( $code >= 200 && $code < 400 && '' !== $body ), $code, $body, $time_ms, $current_url, '' );

		// Type de contenu : une URL qui sert un PDF, une image ou un flux (ex. page
		// de fichier joint redirigée vers le fichier) n'est pas une page HTML.
		// L'analyser produirait des constats absurdes (« titre absent »…).
		$type = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
		$type = trim( (string) strtok( $type, ';' ) );
		$out['content_type'] = $type;
		$out['not_html']     = ( '' !== $type && ! in_array( $type, array( 'text/html', 'application/xhtml+xml' ), true ) )
			|| 0 === strpos( ltrim( substr( $body, 0, 16 ) ), '%PDF' );
		$out['redirected']   = self::comparable_url( $current_url ) !== self::comparable_url( $url );
		$out['redirect_status'] = $out['redirected'] ? (int) $first_hop : 0;
		$out['redirect_hops']   = $out['redirected'] ? (int) $hops : 0;
		// En-tête X-Robots-Tag de la réponse finale (noindex posé par le serveur,
		// fréquent pour les fichiers ou les configurations Nginx/Apache). Chaque
		// en-tête est gardé séparément : « googlebot: noindex » ne vaut que pour Google.
		$x_robots = wp_remote_retrieve_header( $response, 'x-robots-tag' );
		$out['x_robots'] = array_values( array_filter( array_map( 'strval', is_array( $x_robots ) ? $x_robots : ( '' !== (string) $x_robots ? array( (string) $x_robots ) : array() ) ), 'strlen' ) );
		return $out;
	}

	/** Structure de retour homogène de fetch(). */
	private static function fetch_result( $ok, $code, $body, $time_ms, $final_url, $error ) {
		return array(
			'ok'           => (bool) $ok,
			'http_status'  => (int) $code,
			'html'         => (string) $body,
			'time_ms'      => (int) $time_ms,
			'bytes'        => strlen( (string) $body ),
			'final_url'    => (string) $final_url,
			'error'        => (string) $error,
			'content_type' => '',
			'not_html'     => false,
			'redirected'   => false,
			'redirect_status' => 0,
			'redirect_hops'   => 0,
			'x_robots'     => array(),
		);
	}

	/**
	 * Schéma, hôte et port d'une URL http(s) — null sinon.
	 *
	 * @return array{scheme:string, host:string, port:?int, effective_port:int}|null
	 */
	private static function origin_parts( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return null;
		}
		$scheme = strtolower( isset( $p['scheme'] ) ? (string) $p['scheme'] : '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}
		$port = isset( $p['port'] ) ? (int) $p['port'] : null;
		return array(
			'scheme'         => $scheme,
			'host'           => strtolower( (string) $p['host'] ),
			'port'           => $port,
			'effective_port' => null !== $port ? $port : ( 'https' === $scheme ? 443 : 80 ),
		);
	}

	/**
	 * Même site pour le crawl : même hôte ET même port. Le passage http ↔ https
	 * sur les ports par défaut reste admis (redirection HTTPS classique), mais
	 * un autre port (ex. :8080 d'un autre service) est refusé.
	 */
	private static function same_crawl_origin( $a, $home ) {
		if ( $a['host'] !== $home['host'] ) {
			return false;
		}
		if ( null === $a['port'] && null === $home['port'] ) {
			return true;
		}
		return $a['effective_port'] === $home['effective_port'];
	}

	/** Forme comparable d'une URL (casse du schéma/hôte, port par défaut, fragment). */
	private static function comparable_url( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return (string) $url;
		}
		$scheme = strtolower( isset( $p['scheme'] ) ? (string) $p['scheme'] : 'https' );
		$port   = isset( $p['port'] ) ? (int) $p['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		return $scheme . '://' . strtolower( (string) $p['host'] ) . ( $port ? ':' . $port : '' )
			. ( isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/' )
			. ( isset( $p['query'] ) && '' !== $p['query'] ? '?' . $p['query'] : '' );
	}

	/** Durée de validité d'un jeton d'audit (secondes). */
	const AUDIT_TOKEN_TTL = 120;

	/**
	 * En-têtes à joindre à une requête loopback d'audit (crawl, contrôle d'un
	 * lien interne). Le jeton permet au pare-feu de ne pas traiter le serveur
	 * comme un robot agressif. Il est :
	 *  - lié au chemin exact demandé (inutilisable sur une autre URL) ;
	 *  - valable 2 minutes et à usage unique ;
	 *  - envoyé seulement au schéma + hôte + port exacts du site (jamais à la
	 *    variante www/sans www, ni en HTTP clair sur un site HTTPS).
	 * Appeler une fois PAR requête HTTP (un HEAD puis un GET = deux appels).
	 *
	 * @return array<string,string> En-têtes (vide si l'URL n'est pas le site).
	 */
	public static function audit_headers_for( $url ) {
		if ( ! self::is_exact_home_origin( $url ) ) {
			return array();
		}
		$path  = self::audit_path( wp_parse_url( (string) $url, PHP_URL_PATH ) );
		$ts    = (string) time();
		$nonce = bin2hex( random_bytes( 8 ) );
		return array( 'X-CyberPush-SEO-Audit' => 'v2.' . $ts . '.' . $nonce . '.' . self::audit_signature( $ts, $nonce, $path ) );
	}

	/**
	 * Valide le jeton d'audit de la requête courante (appelé par le pare-feu).
	 * Résultat mémorisé pour la requête : le pare-feu l'interroge plusieurs fois.
	 */
	public static function is_valid_audit_request() {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$memo  = false;
		$token = isset( $_SERVER['HTTP_X_CYBERPUSH_SEO_AUDIT'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_X_CYBERPUSH_SEO_AUDIT'] ) ) : '';
		if ( '' === $token || strlen( $token ) > 120 ) {
			return false;
		}
		$parts = explode( '.', $token );
		if ( 4 !== count( $parts ) || 'v2' !== $parts[0] ) {
			return false;
		}
		list( , $ts, $nonce, $signature ) = $parts;
		// preg_match plutôt que ctype_digit() : l'extension ctype est facultative.
		if ( ! preg_match( '/^\d{1,12}$/', $ts ) || abs( time() - (int) $ts ) > self::AUDIT_TOKEN_TTL
			|| ! preg_match( '/^[a-f0-9]{16}$/', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = self::audit_path( strtok( $uri, '?' ) );
		if ( ! hash_equals( self::audit_signature( $ts, $nonce, $path ), $signature ) ) {
			return false;
		}
		// Usage unique : un jeton intercepté ne peut pas être rejoué.
		$used_key = 'cu_seo_tok_' . $nonce;
		if ( false !== get_transient( $used_key ) ) {
			return false;
		}
		set_transient( $used_key, 1, self::AUDIT_TOKEN_TTL + 60 );
		$memo = true;
		return true;
	}

	private static function audit_signature( $ts, $nonce, $path ) {
		$secret = hash( 'sha256', wp_salt( 'auth' ) . '|cu-seo-audit' );
		return hash_hmac( 'sha256', 'v2|' . $ts . '|' . $nonce . '|' . $path, $secret );
	}

	/** Chemin canonique pour la signature (décodé, '/' par défaut). */
	private static function audit_path( $path ) {
		$path = rawurldecode( (string) $path );
		return '' === $path ? '/' : $path;
	}

	/** L'URL vise-t-elle exactement l'origine du site (schéma, hôte, port) ? */
	private static function is_exact_home_origin( $url ) {
		$target = wp_parse_url( (string) $url );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $target ) || ! is_array( $home ) || empty( $target['host'] ) || empty( $home['host'] ) ) {
			return false;
		}
		$t_scheme = strtolower( isset( $target['scheme'] ) ? $target['scheme'] : '' );
		$h_scheme = strtolower( isset( $home['scheme'] ) ? $home['scheme'] : '' );
		$t_port   = isset( $target['port'] ) ? (int) $target['port'] : ( 'https' === $t_scheme ? 443 : 80 );
		$h_port   = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $h_scheme ? 443 : 80 );
		return $t_scheme === $h_scheme
			&& strtolower( $target['host'] ) === strtolower( $home['host'] )
			&& $t_port === $h_port;
	}

	/**
	 * Parse le HTML final en une structure exploitable par le moteur d'audit.
	 *
	 * @param string   $html     HTML complet rendu.
	 * @param string   $base_url URL de la page (résolution des liens relatifs).
	 * @param string[] $x_robots Valeurs de l'en-tête HTTP X-Robots-Tag (fetch()).
	 * @return array
	 */
	public static function parse( $html, $base_url, $x_robots = array() ) {
		$data = self::empty_data();

		// Aucune donnée exploitable : on le DIT (analysable = false + raison)
		// au lieu de renvoyer un document vide que l'audit lirait comme
		// « titre absent », « H1 absent »… sur une page peut-être saine.
		if ( '' === trim( (string) $html ) ) {
			$data['analysable']            = false;
			$data['not_analysable_reason'] = 'empty';
			return $data;
		}
		// L'extension DOM est courante mais non obligatoire dans PHP. Un audit
		// SEO indisponible ne doit jamais rendre le site ou l'administration blanc.
		if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			$data['analysable']            = false;
			$data['not_analysable_reason'] = 'dom_missing';
			return $data;
		}

		// Indice « page JS-only » : très peu de texte mais beaucoup de <script>.
		$script_count = substr_count( strtolower( $html ), '<script' );

		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		// Le préfixe XML force l'UTF-8 sans recourir à mb_convert_encoding
		// (déprécié avec HTML-ENTITIES en PHP 8.1+).
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $dom );

		$host_base = wp_parse_url( $base_url, PHP_URL_HOST );
		$is_https  = ( 'https' === wp_parse_url( $base_url, PHP_URL_SCHEME ) );

		// --- Titre ---------------------------------------------------------
		$title_nodes = $dom->getElementsByTagName( 'title' );
		if ( $title_nodes->length ) {
			$data['title'] = self::clean( $title_nodes->item( 0 )->textContent );
		}
		$data['title_length'] = self::len( $data['title'] );

		// --- Balises <meta> ------------------------------------------------
		// Directives robots CUMULÉES : toutes les balises robots, celles propres
		// à Google et Bing, et l'en-tête HTTP X-Robots-Tag. Comme chez Google, la
		// directive la plus restrictive l'emporte (un « noindex » suivi d'un
		// « index, follow » laisse la page en noindex).
		$directives = array();
		$sources    = array();
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			$name     = strtolower( trim( $meta->getAttribute( 'name' ) ) );
			$property = strtolower( $meta->getAttribute( 'property' ) );
			$content  = $meta->getAttribute( 'content' );

			if ( 'description' === $name ) {
				$data['meta_description'] = self::clean( $content );
			} elseif ( in_array( $name, array( 'robots', 'googlebot', 'bingbot' ), true ) ) {
				$directives = array_merge( $directives, self::robots_tokens( $content ) );
				$sources[ 'meta ' . $name ] = true;
			} elseif ( 'viewport' === $name ) {
				$data['viewport'] = true;
			} elseif ( 0 === strpos( $property, 'og:' ) ) {
				$data['og'][ substr( $property, 3 ) ] = self::clean( $content );
			} elseif ( 0 === strpos( $name, 'twitter:' ) ) {
				$data['twitter'][ substr( $name, 8 ) ] = self::clean( $content );
			}
		}
		$header_tokens = self::x_robots_tokens( $x_robots );
		if ( $header_tokens ) {
			$directives = array_merge( $directives, $header_tokens );
			$sources['X-Robots-Tag'] = true;
		}
		$directives                      = array_values( array_unique( $directives ) );
		$data['robots']                  = implode( ', ', $directives );
		$data['robots_sources']          = array_keys( $sources );
		$data['meta_description_length'] = self::len( $data['meta_description'] );
		$data['indexable']               = ! in_array( 'noindex', $directives, true ) && ! in_array( 'none', $directives, true );

		// --- <html lang> ---------------------------------------------------
		$html_nodes = $dom->getElementsByTagName( 'html' );
		if ( $html_nodes->length ) {
			$data['lang'] = self::clean( $html_nodes->item( 0 )->getAttribute( 'lang' ) );
		}

		// --- <link rel> : canonical, favicon, hreflang --------------------
		foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
			$rel  = strtolower( $link->getAttribute( 'rel' ) );
			$href = $link->getAttribute( 'href' );
			if ( 'canonical' === $rel ) {
				$data['canonical'] = self::clean( $href );
				// Forme absolue (un canonical relatif est valide) : sert au diagnostic
				// « canonical vers une autre URL / une page en erreur ».
				$data['canonical_url'] = '' !== $data['canonical'] ? self::resolve_url( $data['canonical'], $base_url ) : '';
			} elseif ( false !== strpos( $rel, 'icon' ) ) {
				$data['favicon'] = true;
			} elseif ( 'alternate' === $rel && $link->getAttribute( 'hreflang' ) ) {
				$data['hreflang'][] = strtolower( $link->getAttribute( 'hreflang' ) );
			}
		}

		// --- Titres H1..H6 -------------------------------------------------
		for ( $l = 1; $l <= 6; $l++ ) {
			foreach ( $dom->getElementsByTagName( 'h' . $l ) as $h ) {
				$text = self::clean( $h->textContent );
				if ( '' === $text ) {
					continue;
				}
				if ( 1 === $l ) {
					$data['h1'][] = $text;
				}
				$data['headings'][] = array( 'level' => $l, 'text' => self::truncate( $text, 120 ) );
			}
		}

		// --- Schema JSON-LD ------------------------------------------------
		// Lire les données structurées AVANT de retirer les scripts du DOM pour
		// le calcul du texte visible. Sinon le scan supprimait sa propre source et
		// signalait à tort qu'aucun schéma n'était présent.
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			$json = json_decode( trim( $node->textContent ), true );
			if ( is_array( $json ) ) {
				self::collect_schema_types( $json, $data['schema_types'] );
			}
		}
		$data['schema_types'] = array_values( array_unique( $data['schema_types'] ) );

		// --- Contenu mixte (ressources http:// sur une page https) ---------
		// Seules les SOUS-RESSOURCES chargées automatiquement par le navigateur
		// comptent (images, scripts, iframes, médias, feuilles de style…). Un
		// simple lien <a href="http://…"> n'est PAS du contenu mixte. Calculé
		// avant le retrait des <script> du DOM (sinon on les raterait).
		if ( $is_https ) {
			list( $data['mixed_content'], $data['insecure_forms'] ) = self::count_mixed_content( $xpath );
		}

		// --- Contenu visible (word count + échantillon) --------------------
		// On retire scripts/styles/noscript pour ne compter que le texte réel.
		foreach ( $xpath->query( '//script | //style | //noscript | //template' ) as $node ) {
			$node->parentNode->removeChild( $node );
		}
		$body_nodes = $dom->getElementsByTagName( 'body' );
		$body_node  = $body_nodes->length ? $body_nodes->item( 0 ) : null;
		$body_text  = $body_node ? self::node_text( $body_node, $xpath ) : '';
		$data['word_count_page'] = self::count_words( $body_text );
		$data['js_only']         = ( $data['word_count_page'] < 40 && $script_count >= 3 );

		// --- Contenu PRINCIPAL (mots, densité, lisibilité, intro) ----------
		// Menus, en-tête, pied de page, barres latérales et bandeau cookies
		// sont exclus : sinon le nombre de mots et la densité du mot-clé
		// mesuraient en partie la navigation, identique sur toutes les pages.
		$main = $body_node ? self::main_content( $dom, $xpath, $body_node ) : null;
		if ( $main ) {
			$data['content_scope'] = $main['scope'];
			$main_text             = $main['text'];
			$para_texts            = $main['paragraphs'];
		} else {
			$main_text  = $body_text;
			$para_texts = array();
		}
		$data['word_count']   = self::count_words( $main_text );
		$data['text_sample']  = self::truncate( $main_text, 2000 );
		$data['content_text'] = self::truncate( $main_text, 200000 );

		// --- Paragraphes du contenu principal (lisibilité + mot-clé intro) -
		foreach ( $para_texts as $ptext ) {
			if ( '' === $ptext ) {
				continue;
			}
			$data['paragraphs'][] = self::truncate( $ptext, 1000 );
			if ( '' === $data['first_paragraph'] ) {
				$pw = preg_split( '/\s+/u', $ptext, -1, PREG_SPLIT_NO_EMPTY );
				if ( is_array( $pw ) && count( $pw ) >= 8 ) {
					$data['first_paragraph'] = self::truncate( $ptext, 600 );
				}
			}
			if ( count( $data['paragraphs'] ) >= 80 ) {
				break; // borne de sécurité
			}
		}

		// --- Images (après suppression des scripts, mais img reste) --------
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( '' === $src && $img->getAttribute( 'data-src' ) ) {
				$src = $img->getAttribute( 'data-src' ); // lazy-load
			}
			// alt="" (vide) = image DÉCORATIVE, pratique recommandée (WCAG) et
			// générée par WordPress : ce n'est pas un attribut manquant. Seuls
			// comptent l'attribut absent et un alt fait uniquement d'espaces (ni
			// décoratif explicite, ni description).
			$raw_alt    = $img->hasAttribute( 'alt' ) ? (string) $img->getAttribute( 'alt' ) : null;
			$alt        = null === $raw_alt ? '' : trim( $raw_alt );
			$decorative = ( '' === $raw_alt );
			$has_alt    = ( '' !== $alt ) || $decorative;
			$has_dims   = ( '' !== $img->getAttribute( 'width' ) && '' !== $img->getAttribute( 'height' ) );
			$data['images'][] = array(
				'src'        => self::truncate( self::resolve_url( $src, $base_url ), 300 ),
				'alt'        => self::truncate( $alt, 120 ),
				'has_alt'    => $has_alt,
				'decorative' => $decorative,
				'has_dims'   => $has_dims,
			);
			if ( ! $has_alt ) {
				$data['images_missing_alt']++;
			}
		}

		// --- Liens ---------------------------------------------------------
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || 0 === strpos( $href, '#' ) ) {
				continue;
			}
			// mailto:, tel:, sms:, whatsapp:, javascript:, data:… ne sont pas des pages :
			// résolus comme chemins relatifs, ils apparaissaient en « liens cassés ».
			if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) && ! preg_match( '#^https?://#i', $href ) ) {
				continue;
			}
			$resolved = self::resolve_url( $href, $base_url );
			// La colonne url fait 500 caractères : une URL tronquée serait une
			// AUTRE URL (fausse « cassée », hash sans correspondance). On l'ignore.
			if ( strlen( $resolved ) > 500 ) {
				$data['links_too_long']++;
				continue;
			}
			$host      = wp_parse_url( $resolved, PHP_URL_HOST );
			$rel       = strtolower( $a->getAttribute( 'rel' ) );
			$is_nofollow = ( false !== strpos( $rel, 'nofollow' ) );
			$anchor    = self::truncate( self::clean( $a->textContent ), 120 );
			// Même hôte (www indifférent) ET même port effectif : un autre service
			// du même serveur (ex. :8080) est un autre site.
			$type      = ( $host && $host_base && self::same_site_host( $host, $host_base ) && self::same_effective_port( $resolved, $base_url ) ) ? 'internal' : 'external';

			$entry = array(
				'url'      => $resolved,
				'anchor'   => $anchor,
				'nofollow' => $is_nofollow,
				'type'     => $type,
			);
			if ( 'internal' === $type ) {
				$data['links_internal'][] = $entry;
			} else {
				$data['links_external'][] = $entry;
			}
			if ( '' === $anchor ) {
				$data['links_empty_anchor']++;
			} elseif ( self::is_generic_anchor( $anchor ) ) {
				$data['links_generic_anchor']++;
			}
		}

		return $data;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Compte les sous-ressources chargées en http:// (contenu mixte) et les
	 * formulaires qui envoient leurs données en clair.
	 *
	 * @return int[] array( ressources, formulaires )
	 */
	private static function count_mixed_content( $xpath ) {
		$resources = 0;
		$query     = '//img | //script[@src] | //iframe[@src] | //frame[@src] | //source | //audio[@src] | //video'
			. ' | //track[@src] | //embed[@src] | //object[@data] | //input[@src] | //link[@href]';
		// Valeurs de rel qui déclenchent un téléchargement par le navigateur.
		$loading_rels = array( 'stylesheet', 'icon', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'mask-icon', 'preload', 'modulepreload', 'manifest' );
		$nodes        = $xpath->query( $query );
		foreach ( $nodes ? $nodes : array() as $node ) {
			$tag = strtolower( $node->nodeName );
			if ( 'link' === $tag ) {
				$rels = preg_split( '/\s+/', strtolower( trim( $node->getAttribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );
				if ( ! array_intersect( (array) $rels, $loading_rels ) ) {
					continue;
				}
			}
			$insecure = false;
			foreach ( array( 'src', 'href', 'data', 'poster' ) as $attr ) {
				if ( $node->hasAttribute( $attr ) && 0 === stripos( trim( $node->getAttribute( $attr ) ), 'http://' ) ) {
					$insecure = true;
				}
			}
			if ( ! $insecure && $node->hasAttribute( 'srcset' ) ) {
				foreach ( explode( ',', $node->getAttribute( 'srcset' ) ) as $candidate ) {
					if ( 0 === stripos( trim( $candidate ), 'http://' ) ) {
						$insecure = true;
						break;
					}
				}
			}
			if ( $insecure ) {
				$resources++;
			}
		}
		$forms = 0;
		$form_nodes = $xpath->query( '//form[@action]' );
		foreach ( $form_nodes ? $form_nodes : array() as $form ) {
			if ( 0 === stripos( trim( $form->getAttribute( 'action' ) ), 'http://' ) ) {
				$forms++;
			}
		}
		return array( $resources, $forms );
	}

	/**
	 * Texte d'un nœud : nœuds texte joints par un espace. textContent collait
	 * les blocs voisins (« <h2>Titre</h2><p>Texte</p> » → « TitreTexte »),
	 * fusionnant deux mots en un seul.
	 */
	private static function node_text( $node, $xp = null ) {
		$parts = array();
		if ( null === $xp ) {
			$xp = new DOMXPath( $node instanceof DOMDocument ? $node : $node->ownerDocument );
		}
		$texts = $xp->query( './/text()', $node );
		foreach ( $texts ? $texts : array() as $t ) {
			$parts[] = $t->nodeValue;
		}
		return self::clean( implode( ' ', $parts ) );
	}

	/**
	 * Localise le contenu PRINCIPAL de la page et en extrait le texte et les
	 * paragraphes, sans navigation, en-tête/pied de page, barres latérales ni
	 * bandeau cookies. Ordre : <main>, [role=main], <article> unique, conteneur
	 * de contenu usuel (WordPress, Elementor) ; à défaut <body> privé de ces zones.
	 *
	 * @return array{scope:string, text:string, paragraphs:string[]}
	 */
	private static function main_content( $dom, $xpath, $body ) {
		$candidates = array(
			'main'          => '//main[not(ancestor::main)]',
			'role_main'     => '//*[@role="main"][not(ancestor::*[@role="main"])]',
			'article'       => '//article[not(ancestor::article)]',
			'entry_content' => '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ") or contains(concat(" ", normalize-space(@class), " "), " post-content ") or contains(concat(" ", normalize-space(@class), " "), " page-content ")]',
			'elementor'     => '//*[@data-elementor-type="wp-page" or @data-elementor-type="wp-post" or @data-elementor-type="single-post" or @data-elementor-type="single-page" or @data-elementor-type="single"]',
		);
		$root  = null;
		$scope = 'body';
		foreach ( $candidates as $name => $query ) {
			$found = $xpath->query( $query );
			// Un seul candidat, et qui contient du texte : sinon (liste d'articles,
			// balise vide d'un thème) on passe au suivant.
			if ( $found && 1 === $found->length && '' !== trim( $found->item( 0 )->textContent ) ) {
				$root  = $found->item( 0 );
				$scope = $name;
				break;
			}
		}

		// Copie isolée : on y retire les zones hors contenu sans toucher au DOM
		// principal (les liens du menu restent comptés pour le maillage).
		$copy = new DOMDocument( '1.0', 'UTF-8' );
		$copy->appendChild( $copy->importNode( $root ? $root : $body, true ) );
		$cx = new DOMXPath( $copy );

		$cookie = 'contains(translate(@id, "COKIE", "cokie"), "cookie") or contains(translate(@class, "COKIE", "cokie"), "cookie-notice")'
			. ' or contains(translate(@class, "COKIE", "cokie"), "cookie-banner") or contains(translate(@class, "COKIE", "cokie"), "cookie-consent")'
			. ' or contains(@class, "cmplz-cookiebanner") or contains(@class, "cky-consent") or contains(@class, "moove-gdpr") or @id="CybotCookiebotDialog"';
		$noise  = '//nav | //aside | //*[@role="navigation" or @role="complementary" or @role="search"]'
			. ' | //*[@data-elementor-type="header" or @data-elementor-type="footer" or @data-elementor-type="popup"]'
			. ' | //*[' . $cookie . ']';
		if ( null === $root ) {
			// Pas de zone principale identifiable : <body> privé de l'en-tête et
			// du pied de page du SITE (ceux d'un article sont conservés).
			$noise .= ' | //header[not(ancestor::article)] | //footer[not(ancestor::article)] | //*[@role="banner" or @role="contentinfo"]';
		}
		$remove = $cx->query( $noise );
		$nodes  = array();
		foreach ( $remove ? $remove : array() as $n ) {
			$nodes[] = $n;
		}
		foreach ( $nodes as $n ) {
			if ( $n->parentNode ) {
				$n->parentNode->removeChild( $n );
			}
		}

		$paragraphs = array();
		$p_nodes    = $cx->query( '//p' );
		foreach ( $p_nodes ? $p_nodes : array() as $p ) {
			$paragraphs[] = self::node_text( $p, $cx );
			if ( count( $paragraphs ) >= 200 ) {
				break;
			}
		}
		return array(
			'scope'      => null === $root ? 'body' : $scope,
			'text'       => self::node_text( $copy, $cx ),
			'paragraphs' => $paragraphs,
		);
	}

	private static function empty_data() {
		return array(
			'analysable'              => true,
			'not_analysable_reason'   => '',
			'title'                   => '',
			'title_length'            => 0,
			'meta_description'        => '',
			'meta_description_length' => 0,
			'canonical'               => '',
			'canonical_url'           => '',
			'robots'                  => '',
			'robots_sources'          => array(),
			'indexable'               => true,
			'h1'                      => array(),
			'headings'                => array(),
			'word_count'              => 0,
			'word_count_page'         => 0,
			'content_scope'           => '',
			'content_text'            => '',
			'text_sample'             => '',
			'paragraphs'              => array(),
			'first_paragraph'         => '',
			'js_only'                 => false,
			'images'                  => array(),
			'images_missing_alt'      => 0,
			'links_internal'          => array(),
			'links_external'          => array(),
			'links_empty_anchor'      => 0,
			'links_generic_anchor'    => 0,
			'og'                      => array(),
			'twitter'                 => array(),
			'schema_types'            => array(),
			'lang'                    => '',
			'viewport'                => false,
			'hreflang'                => array(),
			'favicon'                 => false,
			'mixed_content'           => 0,
			'insecure_forms'          => 0,
			'links_too_long'          => 0,
		);
	}

	/** Résout une URL éventuellement relative par rapport à l'URL de la page. */
	private static function resolve_url( $url, $base ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( '#' === substr( $url, 0, 1 ) ) {
			return self::normalize_absolute_url( $base );
		}
		if ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) ) {
			return self::normalize_absolute_url( $url );
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
			return self::normalize_absolute_url( ( $scheme ? $scheme : 'https' ) . ':' . $url );
		}
		$parts  = wp_parse_url( $base );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return $url;
		}
		$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}
		if ( '?' === substr( $url, 0, 1 ) ) {
			return self::normalize_absolute_url( $origin . ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . $url );
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return self::normalize_absolute_url( $origin . $url );
		}
		// Relatif au répertoire courant.
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$dir  = rtrim( substr( $path, 0, strrpos( $path, '/' ) + 1 ), '' );
		return self::normalize_absolute_url( $origin . ( '' === $dir ? '/' : $dir ) . $url );
	}

	/** Normalise les segments de chemin et retire le fragment non envoyé au serveur. */
	private static function normalize_absolute_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$segments = array();
		foreach ( explode( '/', isset( $parts['path'] ) ? $parts['path'] : '/' ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		$path = '/' . implode( '/', $segments );
		if ( '/' !== $path && isset( $parts['path'] ) && '/' === substr( $parts['path'], -1 ) ) {
			$path .= '/';
		}
		$normalized = ( isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https' ) . '://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$normalized .= ':' . (int) $parts['port'];
		}
		$normalized .= $path;
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$normalized .= '?' . $parts['query'];
		}
		return $normalized;
	}

	/** Collecte récursivement les @type d'un bloc JSON-LD. */
	private static function collect_schema_types( $json, &$out ) {
		if ( isset( $json['@type'] ) ) {
			$types = is_array( $json['@type'] ) ? $json['@type'] : array( $json['@type'] );
			foreach ( $types as $t ) {
				if ( is_string( $t ) ) {
					$out[] = $t;
				}
			}
		}
		if ( isset( $json['@graph'] ) && is_array( $json['@graph'] ) ) {
			foreach ( $json['@graph'] as $sub ) {
				if ( is_array( $sub ) ) {
					self::collect_schema_types( $sub, $out );
				}
			}
		}
		// Tableau de plusieurs objets au premier niveau.
		if ( ! isset( $json['@type'] ) && ! isset( $json['@graph'] ) ) {
			foreach ( $json as $sub ) {
				if ( is_array( $sub ) ) {
					self::collect_schema_types( $sub, $out );
				}
			}
		}
	}

	/** Même site, en ignorant la casse et le préfixe « www. ». */
	private static function same_site_host( $host, $base ) {
		$strip = static function ( $h ) {
			return preg_replace( '/^www\./', '', strtolower( (string) $h ) );
		};
		return $strip( $host ) === $strip( $base );
	}

	/**
	 * Même port effectif (port par défaut du schéma si absent). Le passage
	 * http ↔ https sur les ports par défaut reste « même site » (redirection
	 * HTTPS classique), comme pour le crawl.
	 */
	private static function same_effective_port( $url, $base ) {
		$a = self::origin_parts( $url );
		$b = self::origin_parts( $base );
		if ( null === $a || null === $b ) {
			return true;
		}
		if ( null === $a['port'] && null === $b['port'] ) {
			return true;
		}
		return $a['effective_port'] === $b['effective_port'];
	}

	/**
	 * Nombre de mots sans les signes de ponctuation isolés : le texte est
	 * reconstitué en séparant les nœuds par une espace, si bien qu'une virgule
	 * placée après </a> ou </strong> devenait un « mot » (et « Bonjour ! »
	 * comptait deux mots en typographie française).
	 */
	public static function count_words( $text ) {
		$text = (string) preg_replace( '/(?<=^|\s)[^\p{L}\p{N}\s]+(?=\s|$)/u', ' ', (string) $text );
		return CU_Text::word_count( $text );
	}

	/** Directives d'une balise robots : « noindex, nofollow » → [ noindex, nofollow ]. */
	private static function robots_tokens( $content ) {
		$out = array();
		foreach ( explode( ',', strtolower( (string) $content ) ) as $token ) {
			$token = trim( (string) preg_replace( '/\s+/u', ' ', $token ) );
			if ( '' !== $token ) {
				$out[] = $token;
			}
		}
		return $out;
	}

	/**
	 * Directives d'en-têtes X-Robots-Tag applicables : celles sans robot
	 * désigné (« noindex ») et celles visant Googlebot ou Bingbot
	 * (« googlebot: noindex »). « otherbot: noindex » ne concerne pas ces moteurs.
	 *
	 * @param string[] $values Un élément par en-tête reçu.
	 * @return string[]
	 */
	public static function x_robots_tokens( $values ) {
		$out = array();
		// Directives qui contiennent elles-mêmes « : » (ce ne sont pas des robots).
		$valued = array( 'max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after' );
		foreach ( (array) $values as $value ) {
			$value = strtolower( trim( (string) $value ) );
			if ( '' === $value ) {
				continue;
			}
			if ( preg_match( '/^([a-z0-9_.\-]+)\s*:\s*(.*)$/s', $value, $m ) && ! in_array( $m[1], $valued, true ) ) {
				if ( ! in_array( $m[1], array( 'googlebot', 'bingbot' ), true ) ) {
					continue;
				}
				$value = $m[2];
			}
			$out = array_merge( $out, self::robots_tokens( $value ) );
		}
		return $out;
	}

	private static function is_generic_anchor( $anchor ) {
		$generic = array( 'cliquez ici', 'cliquer ici', 'ici', 'en savoir plus', 'lire la suite', 'lire plus', 'voir plus', 'click here', 'read more', 'learn more', 'more', 'this page', 'link', 'lien' );
		return in_array( CU_Text::lower( trim( $anchor ) ), $generic, true );
	}

	private static function clean( $str ) {
		// preg_replace(/u) renvoie null sur un UTF-8 invalide : jamais trim( null ) (PHP 8.1+).
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $str ) );
	}

	private static function len( $str ) {
		return CU_Text::length( $str );
	}

	private static function truncate( $str, $max ) {
		$str = (string) $str;
		if ( self::len( $str ) > $max ) {
			return CU_Text::substr( $str, 0, $max );
		}
		return $str;
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adresse IP du visiteur et résolution IP -> pays, partagées entre Analytics,
 * Firewall et Journal de sécurité.
 *
 * Résolution du pays (réglages Analytics) :
 *  - en-têtes d'infrastructure non falsifiables (mod_geoip, Cloudflare vérifié) ;
 *  - 'woocommerce' : base locale de WC_Geolocation, sans repli vers une API ;
 *  - 'maxmind'     : base locale GeoLite2 fournie via `cu_maxmind_database_path` ;
 *  - 'free'/'api'  : API HTTPS externe (ipwho.is), seul cas d'appel distant.
 * Géolocalisation désactivée ou fournisseur « Aucun » : aucun appel, aucun cache.
 */
class CU_Geo {

	/** @var array<string,string> Mémo de client_ip() pour la requête courante. */
	private static $client_ip_memo = array();

	// -----------------------------------------------------------------------
	// Helpers IP (publics : utilisés par tous les modules)
	// -----------------------------------------------------------------------

	/**
	 * Forme canonique d'une IP : IPv6 compressée en minuscules, IPv4-mappée
	 * (::ffff:a.b.c.d) ramenée à l'IPv4. Chaîne vide si invalide.
	 */
	public static function normalize_ip( $ip ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $bin ) {
			return '';
		}
		if ( 16 === strlen( $bin ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) ) {
			$bin = substr( $bin, 12 );
		}
		$out = @inet_ntop( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $out ? '' : strtolower( $out );
	}

	/**
	 * Normalise une entrée IP ou CIDR saisie par un administrateur. Retourne ''
	 * si invalide. Un masque est borné à la famille d'adresse.
	 */
	public static function normalize_ip_or_cidr( $value ) {
		$value = trim( (string) $value );
		if ( false === strpos( $value, '/' ) ) {
			return self::normalize_ip( $value );
		}
		list( $ip, $bits ) = explode( '/', $value, 2 );
		$ip = self::normalize_ip( $ip );
		if ( '' === $ip || ! preg_match( '/^\d{1,3}$/', $bits ) ) {
			return '';
		}
		$max = false !== strpos( $ip, ':' ) ? 128 : 32;
		$bits = (int) $bits;
		if ( $bits < 0 || $bits > $max ) {
			return '';
		}
		return $ip . '/' . $bits;
	}

	/** True si l'IP est privée, réservée, loopback ou invalide. */
	public static function is_private_ip( $ip ) {
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/** True si l'IP est une adresse publique routable (bannissable). */
	public static function is_public_ip( $ip ) {
		return '' !== self::normalize_ip( $ip ) && ! self::is_private_ip( $ip );
	}

	/**
	 * Clé de regroupement pour les compteurs (rate-limit, flood, tentatives).
	 * IPv4 : l'adresse. IPv6 : le préfixe /64 (un abonné reçoit en général un
	 * /64 entier : compter par adresse permettrait de contourner chaque limite
	 * en changeant simplement d'adresse). Les bannissements, eux, restent par IP.
	 */
	public static function rate_bucket( $ip ) {
		$ip = self::normalize_ip( $ip );
		if ( '' === $ip || false === strpos( $ip, ':' ) ) {
			return $ip;
		}
		$bin = inet_pton( $ip );
		return strtolower( (string) inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) ) . '/64';
	}

	/**
	 * Identifiant court et non réversible d'une IP pour les clés de cache
	 * (transients). Un md5 nu d'une IPv4 se retrouve par force brute en quelques
	 * secondes ; un HMAC à clé secrète (sels WordPress) ne le permet pas.
	 */
	public static function ip_key( $ip ) {
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : ( defined( 'NONCE_KEY' ) ? NONCE_KEY : 'cu' );
		return substr( hash_hmac( 'sha256', (string) $ip, $secret . '|cu-ip' ), 0, 32 );
	}

	/** Test d'appartenance IP ⊂ CIDR (ou égalité stricte), IPv4 et IPv6. */
	public static function ip_in_cidr( $ip, $cidr ) {
		$ip   = self::normalize_ip( $ip );
		$cidr = trim( (string) $cidr );
		if ( '' === $ip || '' === $cidr ) {
			return false;
		}
		if ( false === strpos( $cidr, '/' ) ) {
			return $ip === self::normalize_ip( $cidr );
		}
		list( $subnet, $bits ) = explode( '/', $cidr, 2 );
		if ( ! preg_match( '/^\d{1,3}$/', (string) $bits ) ) {
			return false;
		}
		$bits       = (int) $bits;
		$ip_bin     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$subnet_bin = @inet_pton( self::normalize_ip( $subnet ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false; // familles d'adresses différentes (IPv4 vs IPv6)
		}
		if ( $bits < 0 || $bits > strlen( $ip_bin ) * 8 ) {
			return false;
		}
		$whole = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( $whole > 0 && substr( $ip_bin, 0, $whole ) !== substr( $subnet_bin, 0, $whole ) ) {
			return false;
		}
		if ( $rem > 0 ) {
			$mask = ( 0xff << ( 8 - $rem ) ) & 0xff;
			if ( ( ord( $ip_bin[ $whole ] ) & $mask ) !== ( ord( $subnet_bin[ $whole ] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	// -----------------------------------------------------------------------
	// IP réelle du visiteur
	// -----------------------------------------------------------------------

	/** Sources d'IP acceptées par le réglage firewall.ip_source. */
	public static function ip_sources() {
		return array( 'auto', 'remote_addr', 'x_forwarded_for', 'x_real_ip', 'cf_connecting_ip' );
	}

	/**
	 * Résout l'IP réelle du visiteur sans jamais croire un en-tête simplement
	 * parce qu'il existe. Un en-tête de transfert n'est lu que si la connexion
	 * TCP (REMOTE_ADDR) provient d'un relais de confiance :
	 *  - Cloudflare (plages officielles) → CF-Connecting-IP ;
	 *  - relais privé/loopback (reverse-proxy local, Docker, répartiteur interne)
	 *    ou IP/CIDR déclarée dans « Proxys de confiance » → X-Forwarded-For lu de
	 *    DROITE à GAUCHE (l'entrée la plus à droite est écrite par notre relais,
	 *    les précédentes peuvent venir du client). X-Real-IP n'est utilisé en mode
	 *    auto que si le relais n'envoie aucun X-Forwarded-For.
	 * Le réglage « Source de l'IP visiteur » force une source précise lorsque
	 * l'hébergement l'exige (ex. relais qui ne pose que X-Real-IP).
	 *
	 * @return string IP normalisée, ou '0.0.0.0' en dernier recours.
	 */
	public static function client_ip() {
		$raw_remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$memo_key   = md5(
			$raw_remote . '|' . self::server_header( 'HTTP_X_FORWARDED_FOR' ) . '|' . self::server_header( 'HTTP_X_REAL_IP' ) . '|' . self::server_header( 'HTTP_CF_CONNECTING_IP' ) . '|' . self::server_header( 'HTTP_CF_CONNECTING_IPV6' ) . '|' . self::server_header( 'SERVER_ADDR' )
		);
		if ( isset( self::$client_ip_memo[ $memo_key ] ) ) {
			return self::$client_ip_memo[ $memo_key ];
		}
		$ip = self::resolve_client_ip( $raw_remote );
		if ( count( self::$client_ip_memo ) > 20 ) {
			self::$client_ip_memo = array();
		}
		self::$client_ip_memo[ $memo_key ] = $ip;
		return $ip;
	}

	private static function resolve_client_ip( $raw_remote ) {
		$remote = self::normalize_ip( $raw_remote );
		if ( '' === $remote ) {
			return '0.0.0.0';
		}

		$source = self::ip_source();
		if ( 'remote_addr' === $source ) {
			return $remote;
		}

		$from_cloudflare = self::is_cloudflare_ip( $remote );
		$from_relay      = self::is_private_ip( $remote ) || self::is_trusted_proxy( $remote );

		switch ( $source ) {
			case 'cf_connecting_ip':
				if ( $from_cloudflare || $from_relay ) {
					$ip = self::cf_connecting_ip();
					return '' !== $ip ? $ip : $remote;
				}
				return $remote;

			case 'x_real_ip':
				if ( $from_relay || $from_cloudflare ) {
					$ip = self::header_ip( 'HTTP_X_REAL_IP' );
					return '' !== $ip ? $ip : $remote;
				}
				return $remote;

			case 'x_forwarded_for':
				if ( $from_relay || $from_cloudflare ) {
					$ip = self::forwarded_client_ip( self::server_header( 'HTTP_X_FORWARDED_FOR' ) );
					return '' !== $ip ? $ip : $remote;
				}
				return $remote;
		}

		// Mode auto.
		if ( $from_cloudflare ) {
			$ip = self::cf_connecting_ip();
			if ( '' !== $ip ) {
				return $ip;
			}
		}
		if ( $from_relay ) {
			$xff = self::server_header( 'HTTP_X_FORWARDED_FOR' );
			if ( '' !== $xff ) {
				$ip = self::forwarded_client_ip( $xff );
				if ( '' !== $ip ) {
					return $ip;
				}
			} else {
				$ip = self::header_ip( 'HTTP_X_REAL_IP' );
				if ( '' !== $ip ) {
					return $ip;
				}
			}
		}
		return $remote;
	}

	/** Source d'IP configurée (réglage Firewall, filtrable). */
	private static function ip_source() {
		$source = class_exists( 'CU_Settings' ) ? (string) CU_Settings::get( 'firewall', 'ip_source', 'auto' ) : 'auto';
		$source = (string) apply_filters( 'cu_ip_source', $source );
		return in_array( $source, self::ip_sources(), true ) ? $source : 'auto';
	}

	/** Proxys publics déclarés (réglage + filtre historique `cu_trusted_proxy_cidrs`). */
	public static function trusted_proxies() {
		$list = class_exists( 'CU_Settings' ) ? (array) CU_Settings::get( 'firewall', 'trusted_proxies', array() ) : array();
		$list = array_merge( $list, (array) apply_filters( 'cu_trusted_proxy_cidrs', array() ) );
		$out  = array();
		foreach ( $list as $entry ) {
			$entry = self::normalize_ip_or_cidr( is_scalar( $entry ) ? $entry : '' );
			if ( '' !== $entry ) {
				$out[ $entry ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Relais de confiance : proxys déclarés uniquement. L'adresse publique du
	 * serveur n'est PAS approuvée d'office : en hébergement mutualisé, d'autres
	 * sites partagent cette adresse et pourraient forger X-Forwarded-For. Un relais
	 * installé sur le serveur se déclare (ou s'approuve en un clic depuis l'avis).
	 */
	private static function is_trusted_proxy( $ip ) {
		$ip = self::normalize_ip( $ip );
		foreach ( self::trusted_proxies() as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/** Adresse PUBLIQUE du serveur (SERVER_ADDR), ou '' (adresse privée, absente). */
	private static function server_address() {
		$server = self::normalize_ip( self::server_header( 'SERVER_ADDR' ) );
		return '' !== $server && self::is_public_ip( $server ) ? $server : '';
	}

	/**
	 * CF-Connecting-IP, ou l'adresse IPv6 réelle quand Cloudflare remplace les
	 * adresses IPv6 par une « pseudo IPv4 » (plage réservée 240.0.0.0/4, réglage
	 * Pseudo IPv4 « écraser les en-têtes ») : cette pseudo-adresse n'a ni pays ni
	 * sens pour un blocage.
	 */
	private static function cf_connecting_ip() {
		$ip = self::header_ip( 'HTTP_CF_CONNECTING_IP' );
		if ( '' !== $ip && false === strpos( $ip, ':' ) && self::ip_in_cidr( $ip, '240.0.0.0/4' ) ) {
			$v6 = self::header_ip( 'HTTP_CF_CONNECTING_IPV6' );
			if ( '' !== $v6 ) {
				return $v6;
			}
		}
		return $ip;
	}

	/**
	 * La requête a-t-elle traversé Cloudflare avant d'atteindre un relais de
	 * confiance (privé, déclaré ou le serveur lui-même) ? On lit X-Forwarded-For
	 * de droite à gauche en sautant les relais : la première entrée restante doit
	 * appartenir à Cloudflare. Un client ne peut pas falsifier cette entrée, car
	 * le relais ajoute toujours l'adresse qui s'est réellement connectée à lui.
	 */
	private static function cloudflare_via_relay() {
		$remote = self::normalize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		if ( '' === $remote || ! ( self::is_private_ip( $remote ) || self::is_trusted_proxy( $remote ) ) ) {
			return false;
		}
		$xff = self::server_header( 'HTTP_X_FORWARDED_FOR' );
		if ( '' === $xff ) {
			return false;
		}
		foreach ( array_slice( array_reverse( explode( ',', $xff ) ), 0, 20 ) as $part ) {
			$ip = self::normalize_ip( $part );
			if ( '' === $ip ) {
				continue;
			}
			if ( self::is_private_ip( $ip ) || self::is_trusted_proxy( $ip ) ) {
				continue;
			}
			return self::is_cloudflare_ip( $ip );
		}
		return false;
	}

	private static function server_header( $key ) {
		if ( empty( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			return '';
		}
		return substr( trim( (string) wp_unslash( $_SERVER[ $key ] ) ), 0, 1000 );
	}

	/** Première IP valide d'un en-tête mono-valeur (« a » ou « a, b »). */
	private static function header_ip( $key ) {
		$val = self::server_header( $key );
		if ( '' === $val ) {
			return '';
		}
		if ( false !== strpos( $val, ',' ) ) {
			$val = trim( explode( ',', $val )[0] );
		}
		return self::normalize_ip( $val );
	}

	/**
	 * Lit X-Forwarded-For de droite à gauche et renvoie la première adresse qui
	 * n'est ni privée, ni un proxy de confiance. Une entrée Cloudflare signifie
	 * que la requête a traversé Cloudflare avant notre relais : CF-Connecting-IP
	 * (écrasé par Cloudflare) donne alors le client. Si toute la chaîne est
	 * interne, l'entrée la plus à droite (vue par notre relais) est retenue.
	 */
	private static function forwarded_client_ip( $raw ) {
		if ( '' === $raw ) {
			return '';
		}
		$parts     = array_slice( array_reverse( explode( ',', $raw ) ), 0, 20 );
		$rightmost = '';
		foreach ( $parts as $part ) {
			$ip = self::normalize_ip( $part );
			if ( '' === $ip ) {
				continue;
			}
			if ( '' === $rightmost ) {
				$rightmost = $ip;
			}
			if ( self::is_private_ip( $ip ) || self::is_trusted_proxy( $ip ) ) {
				continue;
			}
			if ( self::is_cloudflare_ip( $ip ) ) {
				$cf = self::cf_connecting_ip();
				if ( '' !== $cf ) {
					return $cf;
				}
				continue;
			}
			return $ip;
		}
		return $rightmost;
	}

	/**
	 * Diagnostic pour l'administrateur (jamais utilisé pour décider d'un blocage).
	 * Analyse la requête de l'administrateur lui-même : son navigateur n'envoie
	 * normalement aucun en-tête de transfert, donc ce que l'on voit a été ajouté
	 * par l'infrastructure. Retourne un conseil ou null si tout est cohérent.
	 *
	 * @return array{type:string, proxy:string, client:string, header:string}|null
	 */
	public static function untrusted_proxy_hint() {
		$remote = self::normalize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		if ( '' === $remote ) {
			return null;
		}
		$resolved = self::client_ip();

		// Relais interne qui ne pose que X-Real-IP alors que le mode auto lit XFF.
		if ( self::is_private_ip( $remote ) && 'auto' === self::ip_source() ) {
			$xri = self::header_ip( 'HTTP_X_REAL_IP' );
			$xff = self::server_header( 'HTTP_X_FORWARDED_FOR' );
			if ( '' !== $xri && '' !== $xff && $xri !== $resolved && ! self::is_private_ip( $xri ) ) {
				return array( 'type' => 'ambiguous', 'proxy' => $remote, 'client' => $xri, 'header' => 'X-Real-IP' );
			}
			return null;
		}

		if ( self::is_private_ip( $remote ) || $resolved !== $remote ) {
			return null;
		}
		$headers = array(
			'HTTP_X_FORWARDED_FOR'   => 'X-Forwarded-For',
			'HTTP_X_REAL_IP'         => 'X-Real-IP',
			'HTTP_TRUE_CLIENT_IP'    => 'True-Client-IP',
			'HTTP_X_SUCURI_CLIENTIP' => 'X-Sucuri-ClientIP',
			'HTTP_FASTLY_CLIENT_IP'  => 'Fastly-Client-IP',
		);
		foreach ( $headers as $key => $label ) {
			$val = self::server_header( $key );
			if ( '' === $val ) {
				continue;
			}
			$client = 'HTTP_X_FORWARDED_FOR' === $key ? self::forwarded_client_ip( $val ) : self::header_ip( $key );
			// Une IP cliente privée = proxy d'entreprise côté visiteur, pas un CDN du site.
			if ( '' !== $client && $client !== $remote && ! self::is_private_ip( $client ) ) {
				return array( 'type' => 'untrusted', 'proxy' => $remote, 'client' => $client, 'header' => $label );
			}
		}
		return null;
	}

	/** L'IP appartient-elle au réseau Cloudflare (plages officielles) ? */
	public static function is_cloudflare_ip( $ip ) {
		foreach ( self::cloudflare_ranges() as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Requête reçue de Cloudflare : directement (adresse TCP dans ses plages) ou
	 * via un relais de confiance de l'hébergeur (voir cloudflare_via_relay()).
	 * Ses en-têtes CF-Connecting-IP / CF-IPCountry sont alors fiables.
	 */
	private static function is_cloudflare_request() {
		$remote = self::normalize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		return '' !== $remote && ( self::is_cloudflare_ip( $remote ) || self::cloudflare_via_relay() );
	}

	/**
	 * Plages IP officielles de Cloudflare (https://www.cloudflare.com/ips/).
	 * Modifiable via le filtre `cu_cloudflare_ip_ranges` si elles évoluent.
	 */
	private static function cloudflare_ranges() {
		static $ranges = null;
		if ( null === $ranges ) {
			$ranges = (array) apply_filters( 'cu_cloudflare_ip_ranges', array(
				// IPv4
				'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
				'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
				'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
				'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
				// IPv6
				'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
				'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
			) );
		}
		return $ranges;
	}

	// -----------------------------------------------------------------------
	// Pays
	// -----------------------------------------------------------------------

	/**
	 * Les appels distants (ipwho.is) ne sont permis que si l'administrateur a
	 * activé la géolocalisation ET choisi un fournisseur externe. Aucune autre
	 * fonction (Radar, Journal de sécurité…) ne contourne ce choix.
	 */
	public static function remote_lookups_allowed() {
		return (bool) CU_Settings::get( 'analytics', 'geo_lookup_enabled', false )
			&& in_array( CU_Settings::get( 'analytics', 'geo_provider', 'none' ), array( 'free', 'api' ), true );
	}

	/** Une source de pays (locale ou distante) est-elle configurée ? */
	public static function lookup_configured() {
		return (bool) CU_Settings::get( 'analytics', 'geo_lookup_enabled', false )
			&& 'none' !== CU_Settings::get( 'analytics', 'geo_provider', 'none' );
	}

	/**
	 * Pays ISO2 d'une IP, ou ''. $allow_remote = false interdit tout appel réseau
	 * (chemin public, collecte Analytics) ; le résultat distant est alors calculé
	 * plus tard par la file d'enrichissement.
	 */
	public static function country_for_ip( $ip, $allow_remote = true ) {
		$ip = self::normalize_ip( $ip );
		// Les IP vides, locales ou privées n'ont pas de pays (tests localhost…).
		if ( '' === $ip || self::is_private_ip( $ip ) ) {
			return apply_filters( 'cu_resolve_country_for_ip', '', $ip );
		}

		// L'infrastructure (Cloudflare, mod_geoip) fournit le pays de la requête
		// COURANTE : on ne l'utilise que pour l'IP de cette requête, jamais pour
		// une IP saisie par un administrateur ou lue en base.
		if ( $ip === self::client_ip() ) {
			$cdn_country = self::country_from_headers();
			if ( $cdn_country ) {
				return apply_filters( 'cu_resolve_country_for_ip', $cdn_country, $ip );
			}
		}

		if ( ! self::lookup_configured() ) {
			return apply_filters( 'cu_resolve_country_for_ip', '', $ip );
		}

		$provider = CU_Settings::get( 'analytics', 'geo_provider', 'none' );
		$country  = '';

		if ( 'local' === $provider ) {
			// Base locale (DB-IP Lite) : l'adresse est lue en mémoire, rien n'est
			// transmis à un tiers ni mis en cache par IP.
			$country = self::local_country( $ip );
		} elseif ( 'woocommerce' === $provider ) {
			if ( class_exists( 'WC_Geolocation' ) ) {
				// Base locale uniquement : ni repli « IP du serveur », ni API tierce
				// (WooCommerce interrogerait sinon ipinfo.io / ip-api.com en HTTP).
				$data    = WC_Geolocation::geolocate_ip( $ip, false, false );
				$country = isset( $data['country'] ) ? $data['country'] : '';
			}
		} elseif ( 'maxmind' === $provider ) {
			$country = self::maxmind_lookup( $ip );
		} else {
			// Fournisseur distant : cache 7 jours (y compris un résultat vide,
			// pour ne pas re-solliciter l'API à chaque vue de la même IP).
			$cache_key = 'cu_geo_' . self::ip_key( $ip );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return apply_filters( 'cu_resolve_country_for_ip', (string) $cached, $ip );
			}
			if ( ! $allow_remote || ! self::take_remote_budget() ) {
				// Pas de cache : la file différée (ou une vague plus calme) réessaiera.
				// D'ici là, la base locale (si elle est présente) donne déjà le pays.
				return apply_filters( 'cu_resolve_country_for_ip', self::local_country( $ip ), $ip );
			}
			$country = self::normalize_country( self::free_lookup( $ip ) );
			set_transient( $cache_key, $country, '' !== $country ? 7 * DAY_IN_SECONDS : HOUR_IN_SECONDS );
			if ( '' === $country ) {
				$country = self::local_country( $ip ); // service distant muet : base locale
			}
		}

		return apply_filters( 'cu_resolve_country_for_ip', self::normalize_country( $country ), $ip );
	}

	/**
	 * Plafond GLOBAL d'appels de géolocalisation distants par minute. Pendant une
	 * attaque distribuée (milliers d'IP neuves), chaque premier blocage attendrait
	 * sinon jusqu'à plusieurs secondes une API externe et saturerait les
	 * processus PHP : le site tomberait alors que le pare-feu « bloque ».
	 */
	private static function take_remote_budget() {
		$max = max( 1, (int) apply_filters( 'cu_geo_remote_per_minute', 30 ) );
		$key = 'cu_geo_budget_' . gmdate( 'YmdHi' );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	private static function maxmind_lookup( $ip ) {
		$db_path = apply_filters( 'cu_maxmind_database_path', '' );
		if ( empty( $db_path ) || ! file_exists( $db_path ) ) {
			return '';
		}
		if ( ! class_exists( '\\GeoIp2\\Database\\Reader' ) ) {
			// Sans la bibliothèque GeoIP2 : lecteur MMDB intégré (pays uniquement).
			try {
				return self::mmdb_country( (string) $db_path, $ip );
			} catch ( \Throwable $e ) {
				return '';
			}
		}
		try {
			$reader = new \GeoIp2\Database\Reader( $db_path );
			$record = $reader->country( $ip );
			return $record->country->isoCode ? $record->country->isoCode : '';
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Géolocalisation HTTPS sans clé (ipwho.is). Un seul fournisseur, déclaré
	 * dans l'interface ; d'autres peuvent être ajoutés via `cu_free_geo_providers`.
	 */
	private static function free_lookup( $ip ) {
		$providers = apply_filters( 'cu_free_geo_providers', array(
			array( 'https://ipwho.is/' . rawurlencode( $ip ) . '?fields=country_code', 'country_code' ),
		), $ip );

		foreach ( (array) $providers as $p ) {
			if ( ! is_array( $p ) || 2 !== count( $p ) || 0 !== strpos( (string) $p[0], 'https://' ) ) {
				continue;
			}
			$country = self::fetch_country( $p[0], $p[1] );
			if ( '' !== $country ) {
				return $country;
			}
		}
		return '';
	}

	/** Requête un endpoint JSON de géoloc et extrait le code pays à la clé donnée. */
	private static function fetch_country( $url, $key ) {
		$response = wp_safe_remote_get( $url, array(
			'timeout'             => 3,
			'redirection'         => 0,
			'limit_response_size' => 64 * KB_IN_BYTES,
			'headers'             => array( 'Accept' => 'application/json' ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body[ $key ] ) || ! is_string( $body[ $key ] ) ) {
			return '';
		}
		return $body[ $key ];
	}

	// -----------------------------------------------------------------------
	// Base locale IP → pays (DB-IP Lite, format MMDB)
	// -----------------------------------------------------------------------
	//
	// L'adresse IP est comparée EN MÉMOIRE à un fichier présent sur le serveur :
	// elle n'est ni transmise à un tiers, ni enregistrée, ni mise en cache. Seul
	// le code pays (deux lettres) est gardé. La base « IP to Country Lite » de
	// DB-IP (licence CC BY 4.0, attribution affichée dans Analytics et le
	// readme) est téléchargée par le site, sans compte ni clé, puis actualisée
	// chaque mois ; l'ancienne reste en place si un téléchargement échoue.

	const LOCAL_DB_FILE  = 'geo-country.mmdb';
	const LOCAL_DB_URL   = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz';
	const LOCAL_DB_HOOK  = 'cu_cron_geo_update';
	const LOCAL_DB_STATE = 'cu_geo_local_db';
	// Secours : mêmes données DB-IP Lite (CC BY 4.0) publiées sur le CDN jsDelivr.
	const LOCAL_DB_MIRROR = 'https://cdn.jsdelivr.net/npm/@ip-location-db/dbip-country-mmdb/dbip-country.mmdb';

	/** @var string|null Chemin de la base locale pour la requête courante ('' = absente). */
	private static $local_db_memo = null;
	/** @var array Bases MMDB ouvertes (par chemin). */
	private static $mmdb_open = array();
	/** @var array Blocs de 4 Kio déjà lus (par base et numéro de bloc). */
	private static $mmdb_blocks = array();

	/** Le pays vient-il de la base locale (fournisseur choisi dans les réglages) ? */
	public static function local_provider_active() {
		return (bool) CU_Settings::get( 'analytics', 'geo_lookup_enabled', false )
			&& 'local' === CU_Settings::get( 'analytics', 'geo_provider', 'none' );
	}

	/**
	 * Fichier de la base locale, ou '' s'il n'est pas (encore) disponible. Un
	 * autre fichier .mmdb (GeoLite2, DB-IP…) peut être imposé par le filtre
	 * `cu_geo_local_database_path`.
	 */
	public static function local_db_path() {
		if ( null !== self::$local_db_memo ) {
			return self::$local_db_memo;
		}
		self::$local_db_memo = '';
		$custom              = (string) apply_filters( 'cu_geo_local_database_path', '' );
		if ( '' !== $custom ) {
			if ( is_readable( $custom ) ) {
				self::$local_db_memo = $custom;
			}
			return self::$local_db_memo;
		}
		$dir = class_exists( 'CU_Error_Logger' ) ? (string) CU_Error_Logger::log_dir() : '';
		if ( '' !== $dir && is_readable( $dir . '/' . self::LOCAL_DB_FILE ) ) {
			self::$local_db_memo = $dir . '/' . self::LOCAL_DB_FILE;
		}
		return self::$local_db_memo;
	}

	/**
	 * Pays ISO2 d'une IP d'après la base locale, ou '' (base absente, adresse
	 * privée ou inconnue). Aucun appel réseau, aucune écriture.
	 */
	public static function local_country( $ip ) {
		$ip = self::normalize_ip( $ip );
		if ( '' === $ip || self::is_private_ip( $ip ) ) {
			return '';
		}
		$path = self::local_db_path();
		if ( '' === $path ) {
			return '';
		}
		try {
			return self::normalize_country( self::mmdb_country( $path, $ip ) );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Pays le plus probable d'un fuseau horaire IANA (« Europe/Paris » → FR),
	 * d'après les données de fuseaux de PHP. Simple repli quand l'adresse n'a pas
	 * pu être située (base locale absente) : moins fiable qu'une base IP (réglage
	 * de l'appareil, fuseaux partagés entre pays). Filtre `cu_geo_timezone_fallback`.
	 */
	public static function country_from_timezone( $timezone ) {
		if ( ! is_string( $timezone ) || ! preg_match( '#^[A-Za-z_]{2,20}(?:/[A-Za-z0-9_+\-]{1,30}){1,2}$#', $timezone ) ) {
			return '';
		}
		if ( ! apply_filters( 'cu_geo_timezone_fallback', true, $timezone ) ) {
			return '';
		}
		try {
			$zone     = new \DateTimeZone( $timezone );
			$location = $zone->getLocation();
		} catch ( \Throwable $e ) {
			return '';
		}
		return is_array( $location ) && isset( $location['country_code'] ) ? self::normalize_country( $location['country_code'] ) : '';
	}

	/** État de la base locale pour l'interface (édition, dates, dernière erreur). */
	public static function local_db_status() {
		$state = get_option( self::LOCAL_DB_STATE, array() );
		$state = is_array( $state ) ? $state : array();
		$path  = self::local_db_path();
		return array(
			'available'  => '' !== $path,
			'edition'    => isset( $state['edition'] ) ? (string) $state['edition'] : '',
			'updated_at' => isset( $state['updated_at'] ) ? (int) $state['updated_at'] : 0,
			'attempt_at' => isset( $state['attempt_at'] ) ? (int) $state['attempt_at'] : 0,
			'error'      => isset( $state['error'] ) ? (string) $state['error'] : '',
			'custom'     => '' !== (string) apply_filters( 'cu_geo_local_database_path', '' ),
		);
	}

	/** Planifie un téléchargement de la base locale en arrière-plan (WP-Cron). */
	public static function schedule_local_db_update( $delay = 15 ) {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::LOCAL_DB_HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::LOCAL_DB_HOOK );
		}
	}

	/**
	 * Tâche quotidienne : télécharge la base si elle manque, ou l'édition du mois
	 * si elle a changé. Au plus une tentative toutes les 6 h (base absente) ou
	 * tous les 3 jours (actualisation).
	 */
	public static function maybe_refresh_local_db() {
		if ( ! self::local_provider_active() ) {
			return;
		}
		$status = self::local_db_status();
		if ( $status['custom'] ) {
			return; // fichier fourni par l'administrateur : jamais remplacé
		}
		$since = time() - $status['attempt_at'];
		if ( ! $status['available'] ) {
			if ( $since >= 6 * HOUR_IN_SECONDS ) {
				self::update_local_db();
			}
			return;
		}
		if ( gmdate( 'Y-m' ) !== $status['edition'] && $since >= 3 * DAY_IN_SECONDS ) {
			self::update_local_db();
		}
	}

	/**
	 * Télécharge, décompresse, vérifie puis installe la base locale. L'ancienne
	 * n'est remplacée qu'après une vérification réussie du nouveau fichier.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function update_local_db() {
		$state               = get_option( self::LOCAL_DB_STATE, array() );
		$state               = is_array( $state ) ? $state : array();
		$state['attempt_at'] = time();
		update_option( self::LOCAL_DB_STATE, $state, false );

		$fail = function ( $message ) use ( &$state ) {
			$state['error'] = (string) $message;
			update_option( self::LOCAL_DB_STATE, $state, false );
			return array( 'ok' => false, 'message' => (string) $message );
		};
		$dir = class_exists( 'CU_Error_Logger' ) ? (string) CU_Error_Logger::log_dir() : '';
		if ( '' === $dir || ! wp_is_writable( $dir ) ) {
			return $fail( __( 'Le dossier wp-content/uploads/cyberpush-ultra n’est pas inscriptible.', 'cyberpush-ultra' ) );
		}
		if ( ! function_exists( 'gzopen' ) ) {
			return $fail( __( 'L’extension PHP zlib est nécessaire pour décompresser la base.', 'cyberpush-ultra' ) );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$have     = '' !== self::local_db_path() && isset( $state['edition'] ) ? (string) $state['edition'] : '';
		$current  = gmdate( 'Y-m' );
		$previous = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . ' -1 day' ) );
		if ( $have === $current ) {
			$state['error'] = '';
			update_option( self::LOCAL_DB_STATE, $state, false );
			return array( 'ok' => true, 'message' => sprintf( /* translators: %s: édition AAAA-MM */ __( 'La base de pays est déjà à jour (édition %s).', 'cyberpush-ultra' ), $have ) );
		}
		// L'édition du mois paraît le 1er : repli sur la précédente, mais jamais
		// vers une édition plus ancienne que celle déjà installée.
		$editions = array( $current );
		if ( '' === $have || strcmp( $have, $previous ) < 0 ) {
			$editions[] = $previous;
		}
		$error = __( 'Téléchargement impossible.', 'cyberpush-ultra' );
		foreach ( $editions as $edition ) {
			$archive = download_url( sprintf( self::LOCAL_DB_URL, $edition ), 60 );
			if ( is_wp_error( $archive ) ) {
				$error = $archive->get_error_message();
				continue;
			}
			$candidate = $dir . '/geo-country-' . strtolower( wp_generate_password( 10, false ) ) . '.tmp';
			$unpacked  = self::gunzip_to( $archive, $candidate, 96 * MB_IN_BYTES );
			@unlink( $archive ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( ! $unpacked || ! self::mmdb_usable( $candidate ) ) {
				self::mmdb_forget( $candidate );
				@unlink( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				$error = __( 'Le fichier téléchargé n’est pas une base valide.', 'cyberpush-ultra' );
				continue;
			}
			// Fichiers fermés avant le remplacement (indispensable sous Windows).
			$target = $dir . '/' . self::LOCAL_DB_FILE;
			self::mmdb_forget( $candidate );
			self::mmdb_forget( $target );
			if ( file_exists( $target ) ) {
				@unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			if ( ! @rename( $candidate, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				@unlink( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				return $fail( __( 'La base téléchargée n’a pas pu être installée (droits d’écriture).', 'cyberpush-ultra' ) );
			}
			self::$local_db_memo = null;
			$state               = array( 'edition' => $edition, 'updated_at' => time(), 'attempt_at' => time(), 'error' => '' );
			update_option( self::LOCAL_DB_STATE, $state, false );
			return array( 'ok' => true, 'message' => sprintf( /* translators: %s: édition AAAA-MM */ __( 'Base de pays installée (édition %s).', 'cyberpush-ultra' ), $edition ) );
		}
		// Site officiel injoignable depuis l'hébergeur (pare-feu sortant, blocage
		// géographique, panne) et aucune base en place : copie publique des mêmes
		// données DB-IP sur le CDN jsDelivr. Le site officiel reste retenté ensuite.
		if ( '' === $have ) {
			$mirror = (string) apply_filters( 'cu_geo_local_db_mirror', self::LOCAL_DB_MIRROR );
			if ( 0 === strpos( $mirror, 'https://' ) ) {
				$installed = self::install_local_db( download_url( $mirror, 120 ), $dir, 'miroir' );
				if ( true === $installed ) {
					return array( 'ok' => true, 'message' => __( 'Base de pays installée depuis le miroir jsDelivr (le site officiel DB-IP n’a pas répondu).', 'cyberpush-ultra' ) );
				}
				$error .= ' / ' . $installed;
			}
		}
		if ( '' !== $have ) {
			// Rien de plus récent à télécharger : la base en place reste valable.
			$state['error'] = '';
			update_option( self::LOCAL_DB_STATE, $state, false );
			return array( 'ok' => true, 'message' => sprintf( /* translators: %s: édition AAAA-MM */ __( 'La base de pays est déjà à jour (édition %s).', 'cyberpush-ultra' ), $have ) );
		}
		return $fail( $error );
	}

	/**
	 * Vérifie puis installe une base téléchargée (fichier .mmdb ou .mmdb.gz :
	 * gzopen lit aussi un fichier non compressé). L'ancienne base n'est remplacée
	 * qu'après vérification.
	 *
	 * @param string|WP_Error $archive Fichier temporaire de download_url().
	 * @return true|string true, ou le message d'erreur.
	 */
	private static function install_local_db( $archive, $dir, $edition ) {
		if ( is_wp_error( $archive ) ) {
			return $archive->get_error_message();
		}
		$candidate = $dir . '/geo-country-' . strtolower( wp_generate_password( 10, false ) ) . '.tmp';
		$unpacked  = self::gunzip_to( $archive, $candidate, 96 * MB_IN_BYTES );
		@unlink( $archive ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! $unpacked || ! self::mmdb_usable( $candidate ) ) {
			self::mmdb_forget( $candidate );
			@unlink( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return __( 'Le fichier téléchargé n’est pas une base valide.', 'cyberpush-ultra' );
		}
		$target = $dir . '/' . self::LOCAL_DB_FILE;
		self::mmdb_forget( $candidate );
		self::mmdb_forget( $target );
		if ( file_exists( $target ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( ! @rename( $candidate, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return __( 'La base téléchargée n’a pas pu être installée (droits d’écriture).', 'cyberpush-ultra' );
		}
		self::$local_db_memo = null;
		update_option( self::LOCAL_DB_STATE, array( 'edition' => (string) $edition, 'updated_at' => time(), 'attempt_at' => time(), 'error' => '' ), false );
		return true;
	}

	/** Décompresse un fichier .gz en flux (mémoire constante), avec taille maximale. */
	private static function gunzip_to( $source, $destination, $max_bytes ) {
		$in = @gzopen( $source, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $in ) {
			return false;
		}
		$out = @fopen( $destination, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			gzclose( $in );
			return false;
		}
		$total = 0;
		$ok    = true;
		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, 262144 );
			if ( false === $chunk ) {
				$ok = false;
				break;
			}
			$total += strlen( $chunk );
			if ( $total > $max_bytes || false === fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				$ok = false;
				break;
			}
		}
		gzclose( $in );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $ok && $total > 100000;
	}

	/** Le fichier est-il une base MMDB exploitable (structure lisible, pays renvoyés) ? */
	private static function mmdb_usable( $path ) {
		try {
			$db = self::mmdb_open( $path );
			if ( ! $db || $db['node_count'] < 1000 ) {
				return false;
			}
			$found = 0;
			foreach ( array( '8.8.8.8', '1.1.1.1', '9.9.9.9', '2001:4860:4860::8888' ) as $probe ) {
				if ( preg_match( '/^[A-Z]{2}$/', self::mmdb_country( $path, $probe ) ) ) {
					$found++;
				}
			}
			return $found >= 2;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Ferme une base ouverte et oublie ses blocs (avant remplacement du fichier). */
	private static function mmdb_forget( $path ) {
		if ( isset( self::$mmdb_open[ $path ] ) && is_array( self::$mmdb_open[ $path ] ) && is_resource( self::$mmdb_open[ $path ]['fh'] ) ) {
			fclose( self::$mmdb_open[ $path ]['fh'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		unset( self::$mmdb_open[ $path ] );
		self::$mmdb_blocks = array();
	}

	/**
	 * Ouvre une base MMDB (format public MaxMind DB 2.0, utilisé par DB-IP et
	 * GeoLite2) et lit ses métadonnées. Lecteur minimal intégré : aucune
	 * bibliothèque externe. Vérifié contre la table CSV de la même édition.
	 *
	 * @return array|null Descripteur (fichier ouvert, géométrie de l'arbre), ou null si illisible.
	 */
	private static function mmdb_open( $path ) {
		if ( array_key_exists( $path, self::$mmdb_open ) ) {
			return self::$mmdb_open[ $path ];
		}
		self::$mmdb_open[ $path ] = null;
		$size                     = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size || $size < 1024 ) {
			return null;
		}
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return null;
		}
		// Lectures courtes et dispersées : sans tampon de flux (sinon 8 Kio lus par nœud).
		if ( function_exists( 'stream_set_read_buffer' ) ) {
			stream_set_read_buffer( $fh, 0 );
		}
		$tail_len = (int) min( $size, 131072 );
		fseek( $fh, -$tail_len, SEEK_END );
		$tail   = (string) fread( $fh, $tail_len ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$marker = "\xab\xcd\xefMaxMind.com";
		$pos    = strrpos( $tail, $marker );
		if ( false === $pos ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return null;
		}
		$meta_start = $size - $tail_len + $pos + strlen( $marker );
		$db         = array( 'fh' => $fh, 'id' => md5( $path ), 'size' => $size, 'data_start' => $meta_start );
		$decoded    = self::mmdb_decode( $db, $meta_start );
		$meta       = $decoded[0];
		if ( ! is_array( $meta ) || empty( $meta['node_count'] ) || empty( $meta['record_size'] ) || ! in_array( (int) $meta['record_size'], array( 24, 28, 32 ), true ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return null;
		}
		$db['node_count']  = (int) $meta['node_count'];
		$db['record_size'] = (int) $meta['record_size'];
		$db['node_bytes']  = (int) ( $db['record_size'] / 4 );
		$db['tree_size']   = $db['node_count'] * $db['node_bytes'];
		$db['data_start']  = $db['tree_size'] + 16;
		$db['ip_version']  = isset( $meta['ip_version'] ) ? (int) $meta['ip_version'] : 6;
		if ( $db['data_start'] >= $size ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return null;
		}
		// Nœud de départ des adresses IPv4 dans un arbre IPv6 (96 bits à zéro).
		$db['v4_start'] = 0;
		if ( 6 === $db['ip_version'] ) {
			$node = 0;
			for ( $i = 0; $i < 96 && $node < $db['node_count']; $i++ ) {
				$node = self::mmdb_record( $db, $node, 0 );
			}
			$db['v4_start'] = $node;
		}
		self::$mmdb_open[ $path ] = $db;
		return $db;
	}

	/** Lit des octets par blocs de 4 Kio gardés en mémoire (une lecture disque par bloc). */
	private static function mmdb_bytes( array $db, $offset, $length ) {
		$out = '';
		while ( $length > 0 ) {
			$block = (int) floor( $offset / 4096 );
			$key   = $db['id'] . ':' . $block;
			if ( ! isset( self::$mmdb_blocks[ $key ] ) ) {
				if ( count( self::$mmdb_blocks ) > 256 ) {
					self::$mmdb_blocks = array();
				}
				fseek( $db['fh'], $block * 4096 );
				self::$mmdb_blocks[ $key ] = (string) fread( $db['fh'], 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			}
			$part = (string) substr( self::$mmdb_blocks[ $key ], $offset - $block * 4096, $length );
			if ( '' === $part ) {
				break;
			}
			$out    .= $part;
			$offset += strlen( $part );
			$length -= strlen( $part );
		}
		return $out;
	}

	/** Enregistrement gauche (bit 0) ou droit (bit 1) d'un nœud de l'arbre de recherche. */
	private static function mmdb_record( array $db, $node, $bit ) {
		fseek( $db['fh'], $node * $db['node_bytes'] );
		$buf = (string) fread( $db['fh'], $db['node_bytes'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( strlen( $buf ) !== $db['node_bytes'] ) {
			return $db['node_count'];
		}
		if ( 24 === $db['record_size'] ) {
			$u = unpack( 'N', "\x00" . substr( $buf, $bit ? 3 : 0, 3 ) );
		} elseif ( 28 === $db['record_size'] ) {
			$mid = ord( $buf[3] );
			$u   = $bit ? unpack( 'N', chr( $mid & 0x0F ) . substr( $buf, 4, 3 ) ) : unpack( 'N', chr( ( $mid >> 4 ) & 0x0F ) . substr( $buf, 0, 3 ) );
		} else {
			$u = unpack( 'N', substr( $buf, $bit ? 4 : 0, 4 ) );
		}
		return (int) $u[1];
	}

	/**
	 * Décode une valeur de la section de données (carte, tableau, chaîne, entier,
	 * booléen, pointeur). Profondeur et tailles bornées.
	 *
	 * @return array{0:mixed, 1:int} Valeur et position suivante.
	 */
	private static function mmdb_decode( array $db, $offset, $depth = 0 ) {
		if ( $depth > 12 || $offset < 0 || $offset >= $db['size'] ) {
			return array( null, $offset );
		}
		$head = self::mmdb_bytes( $db, $offset, 8 );
		if ( '' === $head ) {
			return array( null, $offset );
		}
		$head = str_pad( $head, 8, "\0" );
		$ctrl = ord( $head[0] );
		$type = $ctrl >> 5;
		$pos  = 1;
		if ( 1 === $type ) {
			// Pointeur vers une valeur déjà écrite ailleurs dans la section de données.
			$psize = ( $ctrl >> 3 ) & 0x3;
			$low   = $ctrl & 0x7;
			if ( 0 === $psize ) {
				$ptr = ( $low << 8 ) | ord( $head[1] );
			} elseif ( 1 === $psize ) {
				$ptr = ( ( $low << 16 ) | ( ord( $head[1] ) << 8 ) | ord( $head[2] ) ) + 2048;
			} elseif ( 2 === $psize ) {
				$ptr = ( ( $low << 24 ) | ( ord( $head[1] ) << 16 ) | ( ord( $head[2] ) << 8 ) | ord( $head[3] ) ) + 526336;
			} else {
				$u   = unpack( 'N', substr( $head, 1, 4 ) );
				$ptr = (int) $u[1];
			}
			$target = self::mmdb_decode( $db, $db['data_start'] + $ptr, $depth + 1 );
			return array( $target[0], $offset + 2 + $psize );
		}
		if ( 0 === $type ) {
			$type = 7 + ord( $head[1] );
			$pos  = 2;
		}
		$size = $ctrl & 0x1f;
		if ( 29 === $size ) {
			$size = 29 + ord( $head[ $pos ] );
			$pos += 1;
		} elseif ( 30 === $size ) {
			$size = 285 + ( ( ord( $head[ $pos ] ) << 8 ) | ord( $head[ $pos + 1 ] ) );
			$pos += 2;
		} elseif ( 31 === $size ) {
			$size = 65821 + ( ( ord( $head[ $pos ] ) << 16 ) | ( ord( $head[ $pos + 1 ] ) << 8 ) | ord( $head[ $pos + 2 ] ) );
			$pos += 3;
		}
		$offset += $pos;
		if ( 7 === $type ) {
			$map = array();
			for ( $i = 0; $i < $size && $i < 1000; $i++ ) {
				$key    = self::mmdb_decode( $db, $offset, $depth + 1 );
				$value  = self::mmdb_decode( $db, $key[1], $depth + 1 );
				$offset = $value[1];
				if ( is_string( $key[0] ) ) {
					$map[ $key[0] ] = $value[0];
				}
			}
			return array( $map, $offset );
		}
		if ( 11 === $type ) {
			$list = array();
			for ( $i = 0; $i < $size && $i < 1000; $i++ ) {
				$value  = self::mmdb_decode( $db, $offset, $depth + 1 );
				$offset = $value[1];
				$list[] = $value[0];
			}
			return array( $list, $offset );
		}
		if ( 14 === $type ) {
			return array( (bool) $size, $offset ); // booléen : la « taille » est la valeur
		}
		if ( 2 === $type || 4 === $type ) {
			if ( $size > 4096 ) {
				return array( '', $offset + $size );
			}
			return array( $size ? self::mmdb_bytes( $db, $offset, $size ) : '', $offset + $size );
		}
		if ( in_array( $type, array( 5, 6, 8, 9, 10 ), true ) ) {
			$raw = $size ? self::mmdb_bytes( $db, $offset, $size ) : '';
			$n   = 0;
			$max = min( strlen( $raw ), 7 );
			for ( $i = 0; $i < $max; $i++ ) {
				$n = ( $n << 8 ) | ord( $raw[ $i ] );
			}
			return array( $n, $offset + $size );
		}
		return array( null, $offset + $size ); // flottants et types inconnus : ignorés
	}

	/** Code pays (tel qu'écrit dans la base) d'une adresse IPv4 ou IPv6, ou ''. */
	private static function mmdb_country( $path, $ip ) {
		$db = self::mmdb_open( $path );
		if ( ! $db ) {
			return '';
		}
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $bin ) {
			return '';
		}
		$len = strlen( $bin );
		if ( 16 === $len && 0 === strpos( $bin, "\0\0\0\0\0\0\0\0\0\0\xff\xff" ) ) {
			$bin = substr( $bin, 12 ); // IPv4 encapsulée dans une IPv6
			$len = 4;
		}
		if ( 4 === $len ) {
			$node = 6 === $db['ip_version'] ? $db['v4_start'] : 0;
		} elseif ( 6 === $db['ip_version'] ) {
			$node = 0;
		} else {
			return '';
		}
		$bits = $len * 8;
		for ( $i = 0; $i < $bits && $node < $db['node_count']; $i++ ) {
			$node = self::mmdb_record( $db, $node, ( ord( $bin[ $i >> 3 ] ) >> ( 7 - ( $i & 7 ) ) ) & 1 );
		}
		if ( $node <= $db['node_count'] ) {
			return ''; // adresse absente de la base
		}
		$decoded = self::mmdb_decode( $db, $node - $db['node_count'] + $db['tree_size'] );
		$record  = $decoded[0];
		if ( ! is_array( $record ) ) {
			return '';
		}
		if ( isset( $record['country'] ) && is_array( $record['country'] ) && isset( $record['country']['iso_code'] ) && is_string( $record['country']['iso_code'] ) ) {
			return $record['country']['iso_code'];
		}
		foreach ( array( 'country_code', 'country' ) as $key ) {
			if ( isset( $record[ $key ] ) && is_string( $record[ $key ] ) ) {
				return $record[ $key ];
			}
		}
		return '';
	}

	// -----------------------------------------------------------------------
	// Enrichissement réseau (Radar / Analytics) : org / ASN / datacenter / proxy
	// -----------------------------------------------------------------------

	/**
	 * Enrichit une IP (organisation, AS, hébergement, proxy/VPN) via ipwho.is.
	 * Uniquement si les appels distants sont autorisés (voir
	 * remote_lookups_allowed()) ; sinon un résultat vide est renvoyé sans aucun
	 * appel ni écriture. Résultat valide mis en cache 7 jours.
	 *
	 * @return array{country:string, org:string, asn:string, is_datacenter:bool, is_proxy:bool, source:string}
	 */
	public static function enrich( $ip ) {
		$ip = self::normalize_ip( $ip );
		if ( '' === $ip || self::is_private_ip( $ip ) || ! self::remote_lookups_allowed() ) {
			return self::empty_enrich();
		}
		$cache_key = self::enrich_cache_key( $ip );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = self::fetch_enrich( $ip );
		// Un timeout ne doit pas devenir un faux négatif pendant sept jours : les
		// réponses valides restent longuement en cache, les échecs sont réessayés.
		set_transient( $cache_key, $data, ! empty( $data['source'] ) ? 7 * DAY_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $data;
	}

	/** Clé de cache d'enrichissement (partagée avec la file Analytics). */
	public static function enrich_cache_key( $ip ) {
		return 'cu_enrich_' . self::ip_key( self::normalize_ip( $ip ) );
	}

	private static function empty_enrich() {
		return array( 'country' => '', 'org' => '', 'asn' => '', 'is_datacenter' => false, 'is_proxy' => false, 'source' => '' );
	}

	private static function fetch_enrich( $ip ) {
		$resp = wp_safe_remote_get(
			'https://ipwho.is/' . rawurlencode( $ip ) . '?fields=success,country_code,connection,security',
			array( 'timeout' => 4, 'redirection' => 0, 'limit_response_size' => 64 * KB_IN_BYTES, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$b = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $b ) && ! empty( $b['success'] ) && isset( $b['connection'] ) && is_array( $b['connection'] ) ) {
				$conn     = $b['connection'];
				$security = isset( $b['security'] ) && is_array( $b['security'] ) ? $b['security'] : array();
				$org      = ! empty( $conn['org'] ) ? (string) $conn['org'] : ( ! empty( $conn['isp'] ) ? (string) $conn['isp'] : '' );
				$asn      = ! empty( $conn['asn'] ) ? ( 'AS' . (int) $conn['asn'] . ( ! empty( $conn['org'] ) ? ' ' . $conn['org'] : '' ) ) : '';
				return array(
					'country'       => self::normalize_country( isset( $b['country_code'] ) ? $b['country_code'] : '' ),
					'org'           => CU_Text::substr( sanitize_text_field( $org ), 0, 150 ),
					'asn'           => CU_Text::substr( sanitize_text_field( $asn ), 0, 60 ),
					'is_datacenter' => ! empty( $security['hosting'] ) || self::org_is_datacenter( $org ),
					'is_proxy'      => ! empty( $security['anonymous'] ) || ! empty( $security['proxy'] ) || ! empty( $security['vpn'] ) || ! empty( $security['tor'] ),
					'source'        => 'ipwho.is',
				);
			}
		}

		return self::empty_enrich();
	}

	/**
	 * Classe une organisation comme datacenter/hébergeur par mot-clé (repli quand
	 * l'API ne fournit pas de drapeau « hosting »). Liste filtrable.
	 */
	private static function org_is_datacenter( $org ) {
		if ( '' === $org ) {
			return false;
		}
		$keywords = apply_filters( 'cu_datacenter_keywords', array(
			'amazon', 'aws', 'google', 'microsoft', 'azure', 'ovh', 'hetzner', 'digitalocean',
			'linode', 'akamai', 'vultr', 'contabo', 'leaseweb', 'm247', 'choopa', 'oracle',
			'alibaba', 'aliyun', 'tencent', 'scaleway', 'online s.a.s', 'datacamp', 'g-core',
			'psychz', 'colocrossing', 'quadranet', 'hostwinds', 'hostinger', 'namecheap',
			'godaddy', 'digital ocean', 'gcore', 'ionos', 'hosting', 'datacenter',
			'data center', 'vps', 'dedicated server', 'colocation',
		) );
		$org_l = strtolower( $org );
		foreach ( $keywords as $kw ) {
			if ( '' !== $kw && false !== strpos( $org_l, strtolower( $kw ) ) ) {
				return true;
			}
		}
		return false;
	}

	// -----------------------------------------------------------------------
	// Pays fourni par l'infrastructure (Cloudflare, mod_geoip…)
	// -----------------------------------------------------------------------

	/**
	 * Pays de la requête COURANTE fourni par l'infrastructure (CDN vérifié ou
	 * variable serveur), sans aucun appel réseau ni cache. Utilisé par la
	 * mesure d'audience minimisée, qui n'interroge jamais de tiers.
	 */
	public static function cdn_country() {
		return self::country_from_headers();
	}

	// -----------------------------------------------------------------------
	// Mesure d'audience derrière Cloudflare / un relais d'hébergeur
	// -----------------------------------------------------------------------
	//
	// Le pare-feu exige une preuve que la connexion vient de Cloudflare (adresse
	// TCP dans ses plages) avant de croire CF-Connecting-IP / CF-IPCountry. Chez
	// beaucoup d'hébergeurs, un relais (mod_remoteip, nginx, front LiteSpeed…) se
	// place entre Cloudflare et WordPress : cette preuve disparaît et le pays
	// fourni par Cloudflare était ignoré (visiteurs sans pays, ou tous avec le
	// pays / l'adresse du relais). Pour la SEULE mesure d'audience, les en-têtes
	// posés par Cloudflare sont acceptés si la connexion vient de Cloudflare
	// (directement ou via un relais de confiance) OU d'un relais de l'hébergeur
	// déjà vu sur une requête d'administrateur authentifiée : un client qui
	// contacte le serveur en direct ne peut donc pas choisir l'adresse comptée
	// (limites anti-abus, compteurs anti-spam). Les décisions de blocage restent
	// sur client_ip().

	/** La connexion TCP vient-elle d'un relais d'hébergeur vu par un administrateur ? */
	private static function peer_is_known_relay() {
		$remote = self::normalize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		return '' !== $remote && class_exists( 'CU_Firewall' ) && method_exists( 'CU_Firewall', 'is_admin_relay' ) && CU_Firewall::is_admin_relay( $remote );
	}

	/**
	 * La requête courante semble traverser un relais d'HÉBERGEMENT (et non un
	 * proxy d'entreprise ou un VPN) : relais sur l'adresse du serveur, ou chaîne
	 * de transfert qui passe par Cloudflare. Sert à décider si le relais vu par un
	 * administrateur peut être mémorisé.
	 */
	public static function looks_like_host_relay() {
		$remote = self::normalize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		if ( '' === $remote || ! self::is_public_ip( $remote ) ) {
			return false;
		}
		if ( $remote === self::server_address() || preg_match( '/^[0-9a-f]{8,20}(?:-[A-Z]{3})?$/i', self::server_header( 'HTTP_CF_RAY' ) ) ) {
			return true;
		}
		foreach ( array_slice( array_reverse( explode( ',', self::server_header( 'HTTP_X_FORWARDED_FOR' ) ) ), 0, 20 ) as $part ) {
			$ip = self::normalize_ip( $part );
			if ( '' !== $ip && ! self::is_private_ip( $ip ) ) {
				return self::is_cloudflare_ip( $ip );
			}
		}
		return false;
	}

	/**
	 * Visiteur tel que décrit par Cloudflare (adresse et pays), ou null si la
	 * connexion ne vient ni de Cloudflare ni d'un relais connu. Mesure d'audience uniquement.
	 *
	 * @return array{ip:string, country:string}|null
	 */
	public static function cloudflare_visitor() {
		$cf_ip = self::cf_connecting_ip();
		if ( '' === $cf_ip || ! self::is_public_ip( $cf_ip ) ) {
			return null;
		}
		if ( ! self::is_cloudflare_request() && ! ( self::peer_is_known_relay() && preg_match( '/^[0-9a-f]{8,20}(?:-[A-Z]{3})?$/i', self::server_header( 'HTTP_CF_RAY' ) ) ) ) {
			return null;
		}
		return array(
			'ip'      => $cf_ip,
			'country' => self::normalize_country( self::server_header( 'HTTP_CF_IPCOUNTRY' ) ),
		);
	}

	/**
	 * Adresse du visiteur pour la mesure d'audience (limites anti-abus, pays,
	 * exclusions d'IP). Jamais utilisée pour bloquer qui que ce soit.
	 *  1. Cloudflare (voir cloudflare_visitor()) ;
	 *  2. résolveur du pare-feu (client_ip()) ;
	 *  3. relais de l'hébergeur vu par un administrateur (pas encore approuvé) :
	 *     son X-Forwarded-For / X-Real-IP. Sans cela, tous les visiteurs avaient
	 *     l'adresse du relais et partageaient les mêmes plafonds.
	 */
	public static function audience_ip() {
		$cf = self::cloudflare_visitor();
		if ( $cf ) {
			return $cf['ip'];
		}
		$ip     = self::client_ip();
		$remote = self::normalize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		if ( '' !== $remote && $ip === $remote && self::is_public_ip( $remote ) && self::peer_is_known_relay() ) {
			$forwarded = self::forwarded_client_ip( self::server_header( 'HTTP_X_FORWARDED_FOR' ) );
			if ( '' === $forwarded ) {
				$forwarded = self::header_ip( 'HTTP_X_REAL_IP' );
			}
			if ( '' !== $forwarded && $forwarded !== $remote ) {
				return $forwarded;
			}
		}
		return $ip;
	}

	/**
	 * Pays fourni par l'infrastructure pour la mesure d'audience : Cloudflare
	 * (même derrière un relais d'hébergeur), sinon variables du serveur / CDN
	 * reconnues par country_from_headers(). Aucun appel réseau.
	 */
	public static function audience_cdn_country() {
		$cf = self::cloudflare_visitor();
		if ( $cf && '' !== $cf['country'] ) {
			return $cf['country'];
		}
		return self::country_from_headers();
	}

	/** La requête courante est-elle passée par Cloudflare (signature reconnue) ? */
	public static function behind_cloudflare() {
		return null !== self::cloudflare_visitor();
	}

	/**
	 * Code pays fourni par le serveur ou le CDN pour la requête courante. Seules
	 * les sources non falsifiables par le client sont lues : variables serveur
	 * (mod_geoip), CF-IPCountry si la connexion vient de Cloudflare, en-têtes
	 * d'autres CDN uniquement après opt-in explicite (filtre).
	 *
	 * @return string Code ISO2 en majuscules, ou '' si indisponible.
	 */
	private static function country_from_headers() {
		foreach ( array( 'GEOIP_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE_V6', 'MM_COUNTRY_CODE' ) as $sv ) {
			if ( ! empty( $_SERVER[ $sv ] ) ) {
				$code = self::normalize_country( $_SERVER[ $sv ] );
				if ( $code ) {
					return $code;
				}
			}
		}

		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) && self::is_cloudflare_request() ) {
			$code = self::normalize_country( $_SERVER['HTTP_CF_IPCOUNTRY'] );
			if ( $code ) {
				return $code;
			}
		}

		$trusted_headers = (array) apply_filters( 'cu_trusted_country_headers', array() );
		$allowed_headers = array( 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY' );
		foreach ( array_intersect( $trusted_headers, $allowed_headers ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$code = self::normalize_country( $_SERVER[ $h ] );
				if ( $code ) {
					return $code;
				}
			}
		}

		return '';
	}

	/** Valide/normalise un code pays ISO2 (rejette XX = inconnu, T1 = Tor). */
	public static function normalize_country( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$code = strtoupper( substr( sanitize_text_field( wp_unslash( (string) $raw ) ), 0, 2 ) );
		if ( preg_match( '/^[A-Z]{2}$/', $code ) && ! in_array( $code, array( 'XX', 'T1' ), true ) ) {
			return $code;
		}
		return '';
	}
}

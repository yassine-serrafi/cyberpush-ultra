<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Utilitaires texte compatibles avec les hébergements où mbstring n'est pas
 * installé. WordPress ne l'exige pas : le plugin ne doit donc jamais produire
 * une erreur fatale pour une simple troncature ou comparaison SEO.
 */
class CU_Text {

	public static function substr( $value, $start, $length = null ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return null === $length ? mb_substr( $value, $start ) : mb_substr( $value, $start, $length );
		}
		return null === $length ? substr( $value, $start ) : substr( $value, $start, $length );
	}

	public static function length( $value ) {
		$value = (string) $value;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	public static function lower( $value ) {
		$value = (string) $value;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * Nombre de mots d'un texte, y compris pour les écritures sans espaces :
	 * chaque idéogramme chinois / kana japonais compte pour un mot (convention
	 * des outils SEO). Un simple découpage sur les espaces comptait une page
	 * chinoise de 2 000 caractères comme ~30 mots → faux « contenu mince ».
	 */
	public static function word_count( $text ) {
		$text  = (string) $text;
		$cjk   = '/[\p{Han}\p{Hiragana}\p{Katakana}]/u';
		$ideo  = (int) preg_match_all( $cjk, $text );
		$rest  = (string) preg_replace( $cjk, ' ', $text );
		$words = preg_split( '/\s+/u', trim( $rest ), -1, PREG_SPLIT_NO_EMPTY );
		return $ideo + ( is_array( $words ) ? count( $words ) : 0 );
	}

	public static function strpos( $haystack, $needle, $offset = 0 ) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;
		return function_exists( 'mb_strpos' ) ? mb_strpos( $haystack, $needle, $offset ) : strpos( $haystack, $needle, $offset );
	}
}

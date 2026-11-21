<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CU_SEO_Readability — analyse de lisibilité, façon Yoast / AIOSEO.
 * -----------------------------------------------------------------------------
 * FIABILITÉ : la lisibilité est un ENSEMBLE d'indices de forme (longueur des
 * phrases, des paragraphes, score de lecture, mots de transition), PAS un
 * facteur de classement Google. On l'affiche donc comme un guide de rédaction
 * « indicatif », avec des poids LÉGERS sur le score — jamais un couperet.
 *
 * On n'analyse une page QUE si elle contient assez de texte (>= 120 mots) :
 * en dessous, aucun indice n'est statistiquement fiable → on le dit honnêtement
 * plutôt que d'inventer une note.
 *
 * Formules utilisées (sources publiques et établies) :
 *   - Flesch Reading Ease (EN) : 206.835 - 1.015*(mots/phrases) - 84.6*(syll/mots)
 *   - Adaptation française Kandel & Moles : 207 - 1.015*(mots/phrases) - 73.6*(syll/mots)
 * Le comptage de syllabes est heuristique (groupes de voyelles) → estimation.
 */
class CU_SEO_Readability {

	const MIN_WORDS = 120; // en dessous, on ne juge pas (honnêteté statistique)

	/**
	 * @param array  $paragraphs Liste de paragraphes (texte propre du contenu principal).
	 * @param string $lang       Code langue ('fr', 'en'…) pour la formule adaptée.
	 * @param array  $headings   Titres [{level,text}] pour la distribution des sous-titres.
	 * @param int    $word_count Nombre total de mots de la page (fourni par le Renderer).
	 * @return array{available:bool, score:?int, flesch:?float, avg_sentence:?float,
	 *               long_sentence_pct:?int, long_paragraphs:?int, transition_pct:?int, issues:array}
	 */
	public static function analyze( $paragraphs, $lang = 'fr', $headings = array(), $word_count = 0 ) {
		$issues = array();
		$src    = __( ' (Indicatif — la lisibilité guide la rédaction ; ce n\'est pas un facteur de classement Google.)', 'cyberpush-ultra' );

		// Texte principal = concaténation des paragraphes réels du contenu.
		$text  = trim( (string) preg_replace( '/\s+/u', ' ', implode( ' ', array_map( 'strval', (array) $paragraphs ) ) ) );
		$words = '' === $text ? array() : preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$nb_words = is_array( $words ) ? count( $words ) : 0;

		$lang_l = strtolower( (string) $lang );
		// La langue déclarée (<html lang>) peut différer de celle du texte (site
		// « en-US » rédigé en français) : entre français et anglais seulement,
		// le texte lui-même tranche quand il est sans ambiguïté.
		$lang_l = self::effective_lang( $lang_l, is_array( $words ) ? $words : array() );
		// Écritures sans espaces entre les mots (chinois, japonais, thaï, lao,
		// khmer, birman) : « mots par phrase » n'a pas de sens → pas de note.
		$no_spaces = (bool) preg_match( '/^(zh|ja|th|lo|km|my)(?:[-_]|$)/', $lang_l );

		// Pas assez de matière : on le dit, on n'invente pas de note.
		if ( $nb_words < self::MIN_WORDS || $no_spaces ) {
			return array(
				'available'         => false,
				'score'             => null,
				'flesch'            => null,
				'avg_sentence'      => null,
				'long_sentence_pct' => null,
				'long_paragraphs'   => null,
				'transition_pct'    => null,
				'issues'            => $issues,
				'reason'            => $no_spaces ? 'language' : 'too_short',
			);
		}

		$is_fr = ( 0 === strpos( $lang_l, 'fr' ) );
		// Flesch (syllabes = voyelles latines) et la liste de connecteurs n'existent
		// qu'en français/anglais. En arabe, espagnol, allemand… ils produiraient
		// « peu de mots de transition » sur CHAQUE page et un indice sans valeur.
		// Langue non déclarée : comportement historique (site FR/EN le plus souvent).
		$lexical = $is_fr || 0 === strpos( $lang_l, 'en' ) || '' === $lang_l;

		// --- Phrases -------------------------------------------------------
		// Découpage PAR PARAGRAPHE : la fin d'un paragraphe termine toujours la
		// phrase. Sinon un <p> sans point final (bouton, légende, accroche
		// Elementor) se soudait au suivant en une « phrase » de 200 mots.
		$sentences = self::split_sentences( $paragraphs );
		$nb_sent   = max( 1, count( $sentences ) );

		$long_sentences = 0;
		$syllables      = 0;
		foreach ( $sentences as $s ) {
			$sw = preg_split( '/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $sw ) && count( $sw ) > 20 ) {
				$long_sentences++;
			}
		}
		foreach ( $words as $w ) {
			$syllables += self::count_syllables( $w );
		}

		$avg_sentence      = round( $nb_words / $nb_sent, 1 );
		$long_sentence_pct = (int) round( ( $long_sentences / $nb_sent ) * 100 );
		$syll_per_word     = $syllables / max( 1, $nb_words );

		// --- Flesch Reading Ease (adapte a la langue) ----------------------
		if ( ! $lexical ) {
			$flesch = null;
		} elseif ( $is_fr ) {
			$flesch = 207 - 1.015 * ( $nb_words / $nb_sent ) - 73.6 * $syll_per_word;
		} else {
			$flesch = 206.835 - 1.015 * ( $nb_words / $nb_sent ) - 84.6 * $syll_per_word;
		}
		if ( null !== $flesch ) {
			$flesch = round( max( 0, min( 100, $flesch ) ), 1 );
		}

		// --- Paragraphes trop longs ---------------------------------------
		$long_paragraphs = 0;
		foreach ( (array) $paragraphs as $p ) {
			$pw = preg_split( '/\s+/u', (string) $p, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $pw ) && count( $pw ) > 150 ) {
				$long_paragraphs++;
			}
		}

		// --- Mots de transition -------------------------------------------
		// Mots ENTIERS uniquement (« car » ≠ « Carrefour », « puis » ≠
		// « Puissance »), ponctuation admise autour (« Il est, cependant, … »).
		$trans_hits = 0;
		$trans_re   = $lexical ? self::transition_regex( $is_fr ) : '';
		foreach ( $lexical ? $sentences : array() as $s ) {
			if ( self::has_transition( $s, $trans_re ) ) {
				$trans_hits++;
			}
		}
		$transition_pct = $lexical ? (int) round( ( $trans_hits / $nb_sent ) * 100 ) : null;

		// --- Distribution des sous-titres ---------------------------------
		$sub = 0;
		foreach ( (array) $headings as $h ) {
			$lvl = isset( $h['level'] ) ? (int) $h['level'] : 0;
			if ( $lvl >= 2 && $lvl <= 4 ) { $sub++; }
		}
		$words_per_section = $word_count / max( 1, $sub + 1 );

		// ================= SCORE (0-100, déductions LÉGÈRES) ===============
		$score = 100;

		// null < 30 vaut true en PHP : garde explicite pour les langues sans indice.
		if ( null !== $flesch ) {
			if ( $flesch < 30 )      { $score -= 24; }
			elseif ( $flesch < 50 )  { $score -= 14; }
			elseif ( $flesch < 60 )  { $score -= 6; }
		}

		if ( $long_sentence_pct > 40 ) {
			$score -= 16;
			$issues[] = self::issue( 'readability', 'long_sentences', 'warning',
				sprintf( __( '%d%% des phrases dépassent 20 mots', 'cyberpush-ultra' ), $long_sentence_pct ),
				__( 'Des phrases longues fatiguent la lecture. Scindez-les : une idée par phrase se lit et se comprend mieux (humains comme IA).', 'cyberpush-ultra' ) . $src, 6 );
		} elseif ( $long_sentence_pct > 25 ) {
			$score -= 8;
			$issues[] = self::issue( 'readability', 'long_sentences', 'notice',
				sprintf( __( '%d%% des phrases dépassent 20 mots', 'cyberpush-ultra' ), $long_sentence_pct ),
				__( 'Quelques phrases sont longues. Raccourcir les plus lourdes améliore le confort de lecture.', 'cyberpush-ultra' ) . $src, 3 );
		}

		if ( null !== $flesch && $flesch < 50 ) {
			$issues[] = self::issue( 'readability', 'hard_to_read', 'notice',
				sprintf( __( 'Texte assez difficile à lire (indice de lecture %.0f/100)', 'cyberpush-ultra' ), $flesch ),
				__( 'Un score de lecture bas signale un texte dense. Phrases plus courtes, mots plus simples, listes à puces : la clarté aide le référencement ET la citation par les IA.', 'cyberpush-ultra' ) . $src, 4 );
		}

		if ( $long_paragraphs > 0 ) {
			$score -= min( 12, $long_paragraphs * 4 );
			$issues[] = self::issue( 'readability', 'long_paragraphs', 'notice',
				sprintf( __( '%d paragraphe(s) de plus de 150 mots', 'cyberpush-ultra' ), $long_paragraphs ),
				__( 'Des paragraphes trop longs découragent la lecture. Aérez en blocs de 3-4 phrases maximum.', 'cyberpush-ultra' ) . $src, 3 );
		}

		if ( null === $transition_pct ) {
			// Langue sans liste de connecteurs : critère non évalué.
		} elseif ( $transition_pct < 10 ) {
			$score -= 8;
			$issues[] = self::issue( 'readability', 'few_transitions', 'notice',
				sprintf( __( 'Peu de mots de transition (%d%% des phrases)', 'cyberpush-ultra' ), $transition_pct ),
				__( 'Les connecteurs (« ainsi », « cependant », « par conséquent »…) fluidifient le raisonnement et guident le lecteur. Visez environ 20 % des phrases.', 'cyberpush-ultra' ) . $src, 3 );
		} elseif ( $transition_pct < 20 ) {
			$score -= 4;
		}

		if ( $words_per_section > 300 ) {
			$score -= 8;
			$issues[] = self::issue( 'readability', 'subheading_distribution', 'notice',
				__( 'Sections longues sans sous-titre', 'cyberpush-ultra' ),
				__( 'De longues zones de texte sans sous-titre (H2/H3) rendent la page difficile à parcourir. Ajoutez des sous-titres tous les ~300 mots.', 'cyberpush-ultra' ) . $src, 3 );
		}

		return array(
			'available'         => true,
			'score'             => (int) max( 0, min( 100, $score ) ),
			'flesch'            => $flesch,
			'avg_sentence'      => $avg_sentence,
			'long_sentence_pct' => $long_sentence_pct,
			'long_paragraphs'   => $long_paragraphs,
			'transition_pct'    => $transition_pct,
			'lang'              => $lang_l,
			'issues'            => $issues,
		);
	}

	/**
	 * Langue retenue pour les formules : la langue déclarée, sauf si elle est
	 * française, anglaise ou absente ET que le texte est nettement dans l'autre
	 * de ces deux langues (mots-outils les plus fréquents). Aucune autre langue
	 * n'est devinée : ce n'est pas un détecteur de langue.
	 *
	 * @param string   $declared Langue déclarée (minuscules).
	 * @param string[] $words    Mots du texte.
	 */
	public static function effective_lang( $declared, $words ) {
		$declared = strtolower( (string) $declared );
		if ( '' !== $declared && 0 !== strpos( $declared, 'fr' ) && 0 !== strpos( $declared, 'en' ) ) {
			return $declared;
		}
		$fr_words = array( 'le', 'la', 'les', 'des', 'et', 'est', 'une', 'un', 'du', 'que', 'qui', 'pour', 'dans', 'pas', 'sur', 'avec', 'au', 'aux', 'ce', 'il', 'elle', 'nous', 'vous', 'sont', 'par', 'plus', 'mais', 'ou' );
		$en_words = array( 'the', 'and', 'is', 'of', 'to', 'in', 'that', 'it', 'for', 'with', 'on', 'are', 'was', 'this', 'you', 'be', 'as', 'at', 'by', 'from', 'have', 'not', 'or', 'but', 'they', 'we', 'an', 'which' );
		$fr = array_fill_keys( $fr_words, true );
		$en = array_fill_keys( $en_words, true );
		$hits_fr = 0;
		$hits_en = 0;
		foreach ( array_slice( (array) $words, 0, 3000 ) as $w ) {
			$w = CU_Text::lower( trim( (string) $w, " \t\n\r\0\x0B.,;:!?«»\"'()[]" ) );
			if ( isset( $fr[ $w ] ) ) {
				$hits_fr++;
			} elseif ( isset( $en[ $w ] ) ) {
				$hits_en++;
			}
		}
		if ( $hits_fr >= 10 && $hits_fr >= 3 * $hits_en ) {
			return 0 === strpos( $declared, 'fr' ) ? $declared : 'fr';
		}
		if ( $hits_en >= 10 && $hits_en >= 3 * $hits_fr ) {
			return 0 === strpos( $declared, 'en' ) ? $declared : 'en';
		}
		return $declared;
	}

	/** Libellé lisible de l'indice de lecture (bande Flesch). */
	public static function flesch_label( $flesch ) {
		if ( null === $flesch )   { return __( 'Non évalué', 'cyberpush-ultra' ); }
		if ( $flesch >= 70 )      { return __( 'Facile à lire', 'cyberpush-ultra' ); }
		if ( $flesch >= 60 )      { return __( 'Assez facile', 'cyberpush-ultra' ); }
		if ( $flesch >= 50 )      { return __( 'Moyen', 'cyberpush-ultra' ); }
		if ( $flesch >= 30 )      { return __( 'Difficile', 'cyberpush-ultra' ); }
		return __( 'Très difficile', 'cyberpush-ultra' );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private static function issue( $category, $code, $severity, $message, $detail, $weight ) {
		return array(
			'category'   => $category,
			'code'       => $code,
			'severity'   => $severity,
			'message'    => $message,
			'detail'     => $detail,
			'weight'     => (int) $weight,
			'source'     => __( 'Lisibilité — indicatif', 'cyberpush-ultra' ),
			'confidence' => 60,
			'basis'      => 'heuristic',
		);
	}

	/** Comptage de syllabes heuristique (groupes de voyelles), min 1 par mot. */
	private static function count_syllables( $word ) {
		$word = CU_Text::lower( preg_replace( '/[^\p{L}]/u', '', (string) $word ) );
		if ( '' === $word ) {
			return 0;
		}
		$groups = preg_match_all( '/[aeiouyàâäéèêëïîôöùûüœ]+/u', $word, $m );
		return max( 1, (int) $groups );
	}

	/**
	 * Découpe des paragraphes en phrases. Fins de phrase : . ! ? … (et « ... »),
	 * ponctuation arabe/ourdou (؟ ۔) et devanagari (।), suivies d'un espace ou
	 * de la fin du paragraphe. « 3.5 » ou « www.site.fr » ne coupent pas.
	 *
	 * @param string[] $paragraphs
	 * @return string[]
	 */
	public static function split_sentences( $paragraphs ) {
		$out = array();
		foreach ( (array) $paragraphs as $p ) {
			$p = trim( (string) preg_replace( '/\s+/u', ' ', (string) $p ) );
			if ( '' === $p ) {
				continue;
			}
			// Abréviations courantes (« M. Dupont », « Dr. », « env. 30 », « etc. et »,
			// « J. Martin ») : leur point ne termine pas la phrase. Il est remplacé
			// le temps du découpage par un caractère neutre, puis restauré.
			$p = self::protect_abbreviations( $p );
			// Guillemets / parenthèse fermants admis après la ponctuation, y compris
			// précédés d'une espace (typographie française : « Fin. »).
			$parts = preg_split( '/[.!?؟۔।…]+(?:\s*["»”’)\]])*(?:\s+|$)/u', $p, -1, PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $parts ) ) {
				$parts = array( $p ); // UTF-8 invalide : le paragraphe compte pour une phrase.
			}
			foreach ( $parts as $s ) {
				$s = trim( str_replace( self::ABBR_DOT, '.', $s ) );
				// Une « phrase » sans aucune lettre ni chiffre (puces, tirets) n'en est pas une.
				if ( '' !== $s && preg_match( '/[\p{L}\p{N}]/u', $s ) ) {
					$out[] = $s;
				}
			}
		}
		return $out;
	}

	/** Remplaçant temporaire du point d'une abréviation (U+2024, « point de conduite »). */
	const ABBR_DOT = "\xE2\x80\xA4";

	/**
	 * Neutralise le point des abréviations qui ne terminent pas une phrase :
	 *  - civilités et titres (M., Mme., Dr., Pr., Me., Mr., Mrs., Ms., St.) : toujours ;
	 *  - abréviations courantes (etc., env., ex., cf., vs., p., fig., vol., n°…)
	 *    et initiales d'une lettre : seulement si la suite commence par une
	 *    minuscule ou un chiffre (« etc. Ensuite » reste une fin de phrase) ;
	 *  - initiale majuscule suivie d'un nom (« J. Martin »).
	 * Volontairement court : ce n'est pas un analyseur linguistique.
	 */
	private static function protect_abbreviations( $text ) {
		$d      = self::ABBR_DOT;
		$titles = 'M|MM|Mme|Mmes|Mlle|Mlles|Dr|Drs|Pr|Me|Mgr|Mr|Mrs|Ms|St|Ste|Sr|Jr|Prof';
		$common = 'etc|env|ex|cf|vs|p|pp|fig|vol|chap|art|n°|no|nos|approx|av|apr|J\.-C|c\.-à-d|e\.g|i\.e|min|max|tél|tel|ca|resp';
		$out    = preg_replace( '/(?<![\p{L}\p{N}])(' . $titles . ')\.(?=\s+\S)/u', '$1' . $d, (string) $text );
		$out    = is_string( $out ) ? $out : (string) $text;
		$tmp    = preg_replace( '/(?<![\p{L}\p{N}])(' . $common . '|\p{L})\.(?=\s+[\p{Ll}\p{N}])/iu', '$1' . $d, $out );
		$out    = is_string( $tmp ) ? $tmp : $out;
		$tmp    = preg_replace( '/(?<![\p{L}\p{N}])(\p{Lu})\.(?=\s+\p{Lu}\p{Ll})/u', '$1' . $d, $out );
		return is_string( $tmp ) ? $tmp : $out;
	}

	/** Expression régulière (mots entiers) des connecteurs de la langue. */
	private static function transition_regex( $is_fr ) {
		$alts = array();
		foreach ( self::transitions( $is_fr ) as $t ) {
			$t = str_replace( '’', "'", $t );
			// Espaces internes souples (« par   ailleurs », espace insécable).
			$alts[] = str_replace( ' ', '\s+', preg_quote( $t, '/' ) );
		}
		$alts = array_unique( $alts );
		// Les expressions longues d'abord : « par conséquent » avant « par ».
		usort( $alts, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
		// Une élision avant le connecteur reste admise (« lorsqu'ensuite »).
		return '/(?<![\p{L}\p{N}])(?:' . implode( '|', $alts ) . ')(?![\p{L}\p{N}])/iu';
	}

	/** La phrase contient-elle un connecteur (mot entier) ? */
	public static function has_transition( $sentence, $regex ) {
		if ( '' === $regex ) {
			return false;
		}
		$s = str_replace( array( '’', "\xC2\xA0" ), array( "'", ' ' ), (string) $sentence );
		return 1 === preg_match( $regex, $s );
	}

	/** Expose l'expression pour les tests (FR par défaut). */
	public static function transition_pattern( $is_fr = true ) {
		return self::transition_regex( $is_fr );
	}

	/** Mots de transition (FR + EN de secours). */
	private static function transitions( $is_fr ) {
		// Formes accentuées (texte réel) + apostrophe typographique ’ des éditeurs WordPress.
		$fr = array( 'ainsi', 'alors', 'aussi', 'car', 'cependant', 'donc', 'enfin', 'ensuite', 'puis', 'toutefois', 'néanmoins', 'neanmoins', 'pourtant', 'par conséquent', 'par consequent', 'par ailleurs', 'en effet', 'en revanche', 'de plus', 'd\'abord', 'd’abord', 'finalement', 'notamment', 'par exemple', 'c\'est pourquoi', 'c’est pourquoi', 'grâce à', 'grace a', 'malgré', 'malgre', 'tandis que', 'bien que', 'afin de', 'pour cela', 'de même', 'de meme', 'au contraire', 'en outre', 'surtout' );
		$en = array( 'however', 'therefore', 'moreover', 'furthermore', 'thus', 'consequently', 'meanwhile', 'nevertheless', 'nonetheless', 'besides', 'in addition', 'for example', 'for instance', 'as a result', 'on the other hand', 'in contrast', 'first', 'second', 'finally', 'in conclusion', 'because', 'although', 'while', 'so that', 'in fact', 'indeed', 'similarly', 'likewise', 'notably' );
		// « second » est aussi un adjectif français courant (« le second tour ») :
		// il ne compte comme connecteur que sur une page anglaise.
		return $is_fr ? array_merge( $fr, array_diff( $en, array( 'second' ) ) ) : array_merge( $en, $fr );
	}
}

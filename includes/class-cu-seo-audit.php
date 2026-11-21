<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CU_SEO_Audit — moteur de règles + scoring, façon Rank Math / Semrush.
 * -----------------------------------------------------------------------------
 * Reçoit les données extraites par CU_SEO_Renderer (le RENDU FINAL de la page,
 * Elementor/shortcode inclus) et produit :
 *   - une liste d'anomalies typées (catégorie, sévérité, message, correctif) ;
 *   - trois sous-scores /100 (contenu, balises, technique) + un score global.
 *
 * Mode AUDIT UNIQUEMENT : on note et on conseille, on n'écrit rien sur le front.
 */
class CU_SEO_Audit {

	// Pondération d'une anomalie sur le score de sa catégorie.
	const WEIGHT_ERROR   = 30;
	const WEIGHT_WARNING = 12;
	const WEIGHT_NOTICE  = 4;

	/**
	 * Analyse une page.
	 *
	 * @param array $p       Données du Renderer (parse()).
	 * @param array $context array{ target_keyword?:string, http_status?:int, is_https?:bool }
	 * @return array{ issues: array<int,array>, scores: array{overall:int,content:int,meta:int,technical:int} }
	 */
	public static function analyze( $p, $context = array() ) {
		$s        = CU_Settings::module( 'seo' );
		$kw       = isset( $context['target_keyword'] ) ? trim( (string) $context['target_keyword'] ) : '';
		$status   = isset( $context['http_status'] ) ? (int) $context['http_status'] : 200;
		$issues   = array();

		$add = function ( $category, $code, $severity, $message, $detail = '', $weight = 0 ) use ( &$issues ) {
			$rel = self::reliability( $code );
			$issues[] = array(
				'category'   => $category,
				'code'       => $code,
				'severity'   => $severity,
				'message'    => $message,
				'detail'     => $detail,
				'weight'     => (int) $weight, // 0 = pénalité par défaut de la sévérité
				'source'     => $rel['source'],     // d'où vient le constat
				'confidence' => $rel['confidence'], // 0-100 : à quel point on l'assume
				'basis'      => $rel['basis'],      // observed | practice | heuristic
			);
		};

		// ================= TECHNIQUE =====================================
		if ( $status && ( $status < 200 || $status >= 400 ) ) {
			$add( 'technical', 'http_error', 'error',
				sprintf( __( 'La page renvoie un code HTTP %d', 'cyberpush-ultra' ), $status ),
				__( 'Une page non accessible (4xx/5xx) ne peut pas être indexée. Corrigez le lien ou restaurez la page.', 'cyberpush-ultra' ) );
		}
		if ( ! $p['indexable'] ) {
			$robots_src = ! empty( $p['robots_sources'] ) ? implode( ', ', array_map( 'strval', (array) $p['robots_sources'] ) ) : 'meta robots';
			$add( 'technical', 'noindex', 'error',
				__( 'Page en noindex — exclue des moteurs', 'cyberpush-ultra' ),
				/* translators: %s: sources des directives (meta robots, meta googlebot, X-Robots-Tag) */
				sprintf( __( 'Une directive robots contient « noindex » (ou « none ») — source : %s. Quand plusieurs directives existent, la plus restrictive s’applique. Si cette page doit être trouvée sur Google, retirez le noindex (balise ou en-tête HTTP X-Robots-Tag).', 'cyberpush-ultra' ), $robots_src ) );
		}
		if ( ! empty( $p['js_only'] ) ) {
			$add( 'technical', 'js_only', 'warning',
				__( 'Contenu rendu en JavaScript côté navigateur', 'cyberpush-ultra' ),
				__( 'Très peu de texte dans le HTML servi : le contenu semble injecté en JS. Les moteurs peuvent avoir du mal à le lire, et cet audit est partiel. Privilégiez un rendu serveur (SSR).', 'cyberpush-ultra' ) );
		}
		if ( ! $p['viewport'] ) {
			$add( 'technical', 'viewport_missing', 'warning',
				__( 'Balise viewport absente (mobile)', 'cyberpush-ultra' ),
				__( 'Sans <meta name="viewport">, la page n\'est pas adaptée au mobile — critère de classement majeur.', 'cyberpush-ultra' ) );
		}
		if ( '' === $p['lang'] ) {
			$add( 'technical', 'lang_missing', 'notice',
				__( 'Attribut lang absent sur <html>', 'cyberpush-ultra' ),
				__( 'Indiquez la langue (ex: <html lang="fr">) pour aider moteurs et lecteurs d\'écran.', 'cyberpush-ultra' ) );
		}
		if ( $p['mixed_content'] > 0 ) {
			$add( 'technical', 'mixed_content', 'warning',
				sprintf( __( '%d ressource(s) chargée(s) en HTTP sur une page HTTPS', 'cyberpush-ultra' ), $p['mixed_content'] ),
				__( 'Images, scripts, iframes, médias ou feuilles de style appelés en http:// : le navigateur les bloque ou affiche un avertissement de sécurité. Passez ces ressources en HTTPS. (Les simples liens http:// ne sont pas comptés.)', 'cyberpush-ultra' ) );
		}
		if ( ! empty( $p['insecure_forms'] ) ) {
			$add( 'technical', 'insecure_form', 'notice',
				sprintf( __( '%d formulaire(s) envoyé(s) en HTTP non chiffré', 'cyberpush-ultra' ), (int) $p['insecure_forms'] ),
				__( 'L\'attribut action pointe vers une adresse http:// : les navigateurs avertissent l\'utilisateur avant l\'envoi. Utilisez une adresse https://.', 'cyberpush-ultra' ) );
		}
		if ( ! empty( $context['redirected_to'] ) ) {
			$add( 'technical', 'redirected', 'notice',
				__( 'L\'URL de l\'inventaire redirige vers une autre adresse', 'cyberpush-ultra' ),
				sprintf( __( 'Destination : %s. L\'analyse porte sur la page de destination. Faites pointer vos liens internes directement vers l\'URL finale.', 'cyberpush-ultra' ), (string) $context['redirected_to'] ) );
		}
		if ( ! $p['favicon'] ) {
			$add( 'technical', 'favicon_missing', 'notice',
				__( 'Favicon non déclarée', 'cyberpush-ultra' ),
				__( 'Une favicon renforce la reconnaissance de la marque dans les onglets et les résultats.', 'cyberpush-ultra' ) );
		}

		// ================= BALISES (meta / social / schema) ==============
		$title_min = (int) $s['title_min'];
		$title_max = (int) $s['title_max'];
		if ( '' === $p['title'] ) {
			$add( 'meta', 'title_missing', 'error',
				__( 'Balise <title> absente', 'cyberpush-ultra' ),
				__( 'Le titre est le facteur on-page le plus visible dans les résultats. Ajoutez-en un unique et descriptif.', 'cyberpush-ultra' ) );
		} else {
			if ( $p['title_length'] < $title_min ) {
				$add( 'meta', 'title_short', 'warning',
					sprintf( __( 'Titre trop court (%1$d car., visez %2$d–%3$d)', 'cyberpush-ultra' ), $p['title_length'], $title_min, $title_max ),
					__( 'Un titre trop court sous-exploite l\'espace SERP. Enrichissez-le avec le mot-clé et une accroche.', 'cyberpush-ultra' ) );
			} elseif ( $p['title_length'] > $title_max ) {
				$add( 'meta', 'title_long', 'warning',
					sprintf( __( 'Titre trop long (%1$d car., visez %2$d–%3$d)', 'cyberpush-ultra' ), $p['title_length'], $title_min, $title_max ),
					__( 'Au-delà de ~60 caractères, Google tronque le titre. Raccourcissez l\'essentiel au début.', 'cyberpush-ultra' ) );
			}
		}

		// FIABILITÉ : Google tronque le titre par LARGEUR EN PIXELS (~600 px, coupe
		// souvent dès ~525 px), pas à un nombre fixe de caractères — deux titres de
		// même longueur s'affichent différemment. On complète donc le comptage de
		// caractères par une estimation en pixels (source : mesures Zyppy / SERP).
		if ( '' !== $p['title'] ) {
			$title_px = self::pixel_width( $p['title'] );
			if ( $title_px > 600 && $p['title_length'] <= $title_max ) {
				$add( 'meta', 'title_pixels', 'notice',
					sprintf( __( 'Titre large (~%d px) — risque de troncature dans Google', 'cyberpush-ultra' ), $title_px ),
					__( 'Google coupe les titres au-delà d\'environ 600 pixels (souvent dès ~525 px), indépendamment du nombre de caractères. Placez l\'essentiel et le mot-clé en début de titre.', 'cyberpush-ultra' ) );
			}
		}

		$md_min = (int) $s['meta_desc_min'];
		$md_max = (int) $s['meta_desc_max'];
		if ( '' === $p['meta_description'] ) {
			$add( 'meta', 'metadesc_missing', 'error',
				__( 'Meta description absente', 'cyberpush-ultra' ),
				__( 'La meta description influence le taux de clic. Rédigez un résumé incitatif de 120–160 caractères.', 'cyberpush-ultra' ) );
		} else {
			if ( $p['meta_description_length'] < $md_min ) {
				$add( 'meta', 'metadesc_short', 'warning',
					sprintf( __( 'Meta description trop courte (%1$d car., visez %2$d–%3$d)', 'cyberpush-ultra' ), $p['meta_description_length'], $md_min, $md_max ),
					__( 'Développez la description pour occuper l\'espace SERP et donner envie de cliquer.', 'cyberpush-ultra' ) );
			} elseif ( $p['meta_description_length'] > $md_max ) {
				$add( 'meta', 'metadesc_long', 'warning',
					sprintf( __( 'Meta description trop longue (%1$d car., visez %2$d–%3$d)', 'cyberpush-ultra' ), $p['meta_description_length'], $md_min, $md_max ),
					__( 'Au-delà de ~160 caractères, la description est tronquée. Placez l\'essentiel au début.', 'cyberpush-ultra' ) );
			}
		}

		if ( '' === $p['canonical'] ) {
			$add( 'meta', 'canonical_missing', 'notice',
				__( 'URL canonique non déclarée', 'cyberpush-ultra' ),
				__( 'Une balise canonical évite le contenu dupliqué (paramètres d\'URL, pagination). Recommandé.', 'cyberpush-ultra' ) );
		} elseif ( ! empty( $context['canonical'] ) && is_array( $context['canonical'] ) ) {
			// Diagnostic du canonical à partir des seules données déjà connues
			// (inventaire de l'audit), sans requête supplémentaire.
			$canon  = $context['canonical'];
			$target = isset( $canon['target'] ) ? (string) $canon['target'] : '';
			$kind   = isset( $canon['kind'] ) ? (string) $canon['kind'] : '';
			if ( 'broken' === $kind ) {
				$add( 'meta', 'canonical_broken', 'warning',
					/* translators: %d: code HTTP de la page désignée */
					sprintf( __( 'Le canonical désigne une page en erreur (HTTP %d)', 'cyberpush-ultra' ), isset( $canon['status'] ) ? (int) $canon['status'] : 0 ),
					/* translators: %s: URL canonique */
					sprintf( __( 'Canonical : %s. Cette adresse renvoyait une erreur lors de sa dernière analyse : les moteurs peuvent ignorer la balise, ou n’indexer aucune des deux pages. Faites pointer le canonical vers une page accessible (souvent la page elle-même).', 'cyberpush-ultra' ), $target ) );
			} elseif ( 'external' === $kind ) {
				$add( 'meta', 'canonical_external', 'warning',
					__( 'Le canonical désigne un autre domaine', 'cyberpush-ultra' ),
					/* translators: %s: URL canonique */
					sprintf( __( 'Canonical : %s. Les moteurs sont invités à indexer cette adresse externe à la place de la page. C’est voulu pour un contenu republié (syndication) ; sinon, corrigez la balise.', 'cyberpush-ultra' ), $target ) );
			} elseif ( 'other' === $kind ) {
				// Souvent VOLONTAIRE (variante, pagination, version imprimable) :
				// information neutre, sans effet sur le score.
				$add( 'meta', 'canonical_other', 'notice',
					__( 'Le canonical désigne une autre URL du site', 'cyberpush-ultra' ),
					/* translators: %s: URL canonique */
					sprintf( __( 'Canonical : %s. La page demande aux moteurs d’indexer cette autre adresse à sa place. C’est normal pour une variante (paramètres, pagination, version imprimable) ; si la page doit apparaître elle-même dans les résultats, vérifiez la balise.', 'cyberpush-ultra' ), $target ),
					-1 );
			}
		}

		// Open Graph / Twitter Card (partage social).
		if ( empty( $p['og']['title'] ) || empty( $p['og']['image'] ) ) {
			$add( 'social', 'og_incomplete', 'notice',
				__( 'Open Graph incomplet (partage social)', 'cyberpush-ultra' ),
				__( 'Ajoutez og:title, og:description et og:image pour un partage soigné sur Facebook/LinkedIn.', 'cyberpush-ultra' ) );
		}
		if ( empty( $p['twitter']['card'] ) ) {
			$add( 'social', 'twitter_missing', 'notice',
				__( 'Twitter Card absente', 'cyberpush-ultra' ),
				__( 'Déclarez twitter:card pour un aperçu enrichi sur X/Twitter.', 'cyberpush-ultra' ) );
		}

		// Données structurées (schema.org).
		if ( empty( $p['schema_types'] ) ) {
			$add( 'schema', 'schema_missing', 'notice',
				__( 'Aucune donnée structurée (schema.org)', 'cyberpush-ultra' ),
				__( 'Le balisage JSON-LD (Article, Product, FAQ, Breadcrumb…) débloque les résultats enrichis et aide les IA.', 'cyberpush-ultra' ) );
		}

		// ================= CONTENU =======================================
		$h1_count = count( $p['h1'] );
		if ( 0 === $h1_count ) {
			$add( 'content', 'h1_missing', 'error',
				__( 'Aucun titre H1', 'cyberpush-ultra' ),
				__( 'Chaque page doit avoir un H1 unique qui décrit son sujet principal.', 'cyberpush-ultra' ) );
		} elseif ( $h1_count > 1 ) {
			$add( 'content', 'h1_multiple', 'warning',
				sprintf( __( '%d titres H1 (un seul recommandé)', 'cyberpush-ultra' ), $h1_count ),
				__( 'Plusieurs H1 diluent le sujet principal. Gardez un seul H1 et passez le reste en H2.', 'cyberpush-ultra' ) );
		}

		// Sous-titres H2 (structure du contenu).
		$h2_count = 0;
		foreach ( $p['headings'] as $h ) {
			if ( 2 === (int) $h['level'] ) { $h2_count++; }
		}
		if ( 0 === $h2_count && $p['word_count'] >= 300 ) {
			$add( 'content', 'h2_missing', 'notice',
				__( 'Aucun sous-titre H2', 'cyberpush-ultra' ),
				__( 'Des H2 structurent le contenu, facilitent la lecture et aident Google (et les IA) à comprendre les sections traitées.', 'cyberpush-ultra' ) );
		}

		$min_words = (int) $s['content_min_words'];
		if ( $p['word_count'] < $min_words ) {
			// FIABILITÉ — aligné sur ce que font les grands plugins (Yoast, Rank Math,
			// AIOSEO) : le volume de contenu est UN feu tricolore parmi 15 à 70+ checks,
			// jamais un couperet. Et Google (John Mueller) confirme que le nombre de mots
			// n'est ni un facteur de classement ni un signe de contenu mince. On signale
			// donc un faible volume comme un INDICE à vérifier, avec une pénalité LÉGÈRE.
			$src = __( ' (Source : Google / John Mueller : le nombre de mots n\'est pas un facteur de classement ni un signe de contenu mince.)', 'cyberpush-ultra' );
			if ( $p['word_count'] < 50 ) {
				$add( 'content', 'thin_content', 'warning',
					sprintf( __( 'Contenu très court (%1$d mots) — à étoffer', 'cyberpush-ultra' ), $p['word_count'] ),
					__( 'Une page quasi vide couvre rarement une intention de recherche complète. À vérifier : répond-elle réellement au besoin de l\'internaute, mieux que les pages déjà classées ? Ce n\'est pas le volume qui compte, mais l\'utilité réelle.', 'cyberpush-ultra' ) . $src, 14 );
			} else {
				$add( 'content', 'thin_content', 'notice',
					sprintf( __( 'Contenu court (%1$d mots, repère indicatif ≥ %2$d)', 'cyberpush-ultra' ), $p['word_count'], $min_words ),
					__( 'Volume faible : vérifiez que la page traite son sujet en profondeur et répond complètement à l\'intention de recherche. Le nombre de mots n\'est qu\'un indice — c\'est la profondeur et l\'originalité qui pèsent.', 'cyberpush-ultra' ) . $src, 6 );
			}
		}

		if ( $p['images_missing_alt'] > 0 ) {
			$add( 'content', 'img_no_alt', 'warning',
				sprintf( __( '%d image(s) sans texte alternatif (attribut alt absent)', 'cyberpush-ultra' ), $p['images_missing_alt'] ),
				__( 'Le texte alternatif décrit l\'image aux moteurs et à l\'accessibilité. Renseignez-le, mot-clé inclus si pertinent. Une image purement décorative peut garder alt="" (vide) : elle n’est pas comptée ici. Un alt composé uniquement d’espaces est compté.', 'cyberpush-ultra' ) );
		}

		// Liens (repliés dans la catégorie contenu pour le score).
		if ( empty( $p['links_internal'] ) ) {
			$add( 'links', 'no_internal_links', 'warning',
				__( 'Aucun lien interne', 'cyberpush-ultra' ),
				__( 'Le maillage interne guide les visiteurs et distribue la popularité. Ajoutez des liens vers vos autres pages.', 'cyberpush-ultra' ) );
		}
		if ( empty( $p['links_external'] ) ) {
			$add( 'links', 'no_external_links', 'notice',
				__( 'Aucun lien externe', 'cyberpush-ultra' ),
				__( 'Un lien vers une source fiable renforce la crédibilité du contenu.', 'cyberpush-ultra' ) );
		}
		if ( $p['links_generic_anchor'] > 0 ) {
			$add( 'links', 'generic_anchor', 'notice',
				sprintf( __( '%d lien(s) à ancre générique (« cliquez ici »…)', 'cyberpush-ultra' ), $p['links_generic_anchor'] ),
				__( 'Des ancres descriptives (contenant le sujet cible) sont plus utiles aux moteurs qu\'un « cliquez ici ».', 'cyberpush-ultra' ) );
		}

		// ================= MOT-CLÉ CIBLE (optionnel) =====================
		if ( '' !== $kw ) {
			$kw_l   = CU_Text::lower( $kw );
			$in_title = ( false !== CU_Text::strpos( CU_Text::lower( $p['title'] ), $kw_l ) );
			$in_h1    = false;
			foreach ( $p['h1'] as $h ) {
				if ( false !== CU_Text::strpos( CU_Text::lower( $h ), $kw_l ) ) { $in_h1 = true; break; }
			}
			$in_desc = ( false !== CU_Text::strpos( CU_Text::lower( $p['meta_description'] ), $kw_l ) );
			$in_url  = isset( $context['url'] ) && self::keyword_in_slug( $kw, (string) $context['url'] );

			if ( ! $in_title ) {
				$add( 'meta', 'kw_not_in_title', 'warning',
					__( 'Mot-clé cible absent du titre', 'cyberpush-ultra' ),
					sprintf( __( 'Intégrez « %s » dans la balise <title>, idéalement au début.', 'cyberpush-ultra' ), $kw ) );
			}
			if ( ! $in_h1 ) {
				$add( 'content', 'kw_not_in_h1', 'notice',
					__( 'Mot-clé cible absent du H1', 'cyberpush-ultra' ),
					sprintf( __( 'Le H1 gagne à contenir « %s ».', 'cyberpush-ultra' ), $kw ) );
			}
			if ( ! $in_desc ) {
				$add( 'meta', 'kw_not_in_desc', 'notice',
					__( 'Mot-clé cible absent de la meta description', 'cyberpush-ultra' ),
					sprintf( __( 'Reprenez « %s » dans la description pour renforcer la pertinence.', 'cyberpush-ultra' ), $kw ) );
			}
			if ( ! $in_url ) {
				$add( 'content', 'kw_not_in_url', 'notice',
					__( 'Mot-clé cible absent du slug de l\'URL', 'cyberpush-ultra' ),
					sprintf( __( 'Un slug contenant « %s » (avec des tirets) est un signal de pertinence.', 'cyberpush-ultra' ), $kw ) );
			}

			// Google recommande explicitement le mot-clé dans le texte alternatif
			// des images ET dans le texte des liens (ancres).
			$in_alt = false;
			foreach ( $p['images'] as $img ) {
				if ( ! empty( $img['alt'] ) && false !== CU_Text::strpos( CU_Text::lower( $img['alt'] ), $kw_l ) ) { $in_alt = true; break; }
			}
			if ( ! empty( $p['images'] ) && ! $in_alt ) {
				$add( 'content', 'kw_not_in_alt', 'notice',
					__( 'Mot-clé cible absent du texte alternatif des images', 'cyberpush-ultra' ),
					sprintf( __( 'Google lit l\'attribut alt : décrire une image avec « %s » (quand c\'est pertinent) renforce la pertinence et le référencement Images.', 'cyberpush-ultra' ), $kw ) );
			}

			$in_anchor = false;
			foreach ( $p['links_internal'] as $lnk ) {
				if ( ! empty( $lnk['anchor'] ) && false !== CU_Text::strpos( CU_Text::lower( $lnk['anchor'] ), $kw_l ) ) { $in_anchor = true; break; }
			}
			if ( ! empty( $p['links_internal'] ) && ! $in_anchor ) {
				$add( 'content', 'kw_not_in_anchor', 'notice',
					__( 'Mot-clé cible absent des ancres de liens internes', 'cyberpush-ultra' ),
					sprintf( __( 'Google utilise le texte des liens : une ancre descriptive contenant « %s » aide à comprendre la page cible.', 'cyberpush-ultra' ), $kw ) );
			}

			// Mot-clé introduit tôt (premier paragraphe) : signal de pertinence.
			if ( '' !== $p['first_paragraph'] && false === CU_Text::strpos( CU_Text::lower( $p['first_paragraph'] ), $kw_l ) ) {
				$add( 'content', 'kw_not_in_intro', 'notice',
					__( 'Mot-clé cible absent du premier paragraphe', 'cyberpush-ultra' ),
					sprintf( __( 'Introduire « %s » dès les premières phrases confirme le sujet à Google et au lecteur.', 'cyberpush-ultra' ), $kw ) );
			}

			// Mot-clé dans au moins un sous-titre (H2/H3/H4), s'il en existe.
			$has_sub = false;
			$in_subheading = false;
			foreach ( $p['headings'] as $h ) {
				$lvl = isset( $h['level'] ) ? (int) $h['level'] : 0;
				if ( $lvl >= 2 && $lvl <= 4 ) {
					$has_sub = true;
					if ( ! empty( $h['text'] ) && false !== CU_Text::strpos( CU_Text::lower( $h['text'] ), $kw_l ) ) {
						$in_subheading = true;
						break;
					}
				}
			}
			if ( $has_sub && ! $in_subheading ) {
				$add( 'content', 'kw_not_in_subheading', 'notice',
					__( 'Mot-clé cible absent des sous-titres (H2/H3)', 'cyberpush-ultra' ),
					sprintf( __( 'Reprendre « %s » (ou une variante) dans un sous-titre renforce la structure thématique de la page.', 'cyberpush-ultra' ), $kw ) );
			}

			// Densité du mot-clé : ni trop (bourrage), ni trop peu (mot-clé sous-exploité).
			// Calculée sur TOUT le contenu principal — la même base que le nombre
			// de mots qui sert au seuil « peu présent » (avant : 2 000 premiers
			// caractères du <body>, menus compris, contre le total de la page).
			$density = self::keyword_density( isset( $p['content_text'] ) && '' !== $p['content_text'] ? $p['content_text'] : $p['text_sample'], $kw );
			$max_density = (float) $s['keyword_density_max'];
			if ( $density > $max_density ) {
				$add( 'content', 'kw_overopt', 'warning',
					sprintf( __( 'Sur-optimisation : densité du mot-clé %.1f%%', 'cyberpush-ultra' ), $density ),
					sprintf( __( 'Au-delà de %.1f%%, le bourrage de mots-clés est pénalisant. Variez le vocabulaire (synonymes, champ lexical).', 'cyberpush-ultra' ), $max_density ) );
			} elseif ( $p['word_count'] >= 100 && $density < 0.3 ) {
				$add( 'content', 'kw_underused', 'notice',
					sprintf( __( 'Mot-clé peu présent dans le contenu (densité %.1f%%)', 'cyberpush-ultra' ), $density ),
					sprintf( __( 'Le mot-clé « %s » apparaît très peu dans le texte. Employez-le naturellement dans le corps (sans bourrage) pour clarifier le sujet.', 'cyberpush-ultra' ), $kw ) );
			}
		}

		// ================= LISIBILITÉ (dimension séparée) ================
		// Comme Yoast/AIOSEO : la lisibilité est une note À PART (guide de rédaction),
		// pas mélangée au score SEO. Ses anomalies s'affichent dans le diagnostic mais
		// ne pèsent PAS sur les sous-scores contenu/balises/technique.
		$readability = CU_SEO_Readability::analyze(
			$p['paragraphs'],
			'' !== $p['lang'] ? $p['lang'] : 'fr',
			$p['headings'],
			(int) $p['word_count']
		);
		foreach ( $readability['issues'] as $ri ) {
			$issues[] = $ri;
		}

		// ================= SCORING =======================================
		$scores = self::score( $issues );
		$scores['readability'] = $readability['available'] ? $readability['score'] : null;

		return array( 'issues' => $issues, 'scores' => $scores, 'readability' => $readability );
	}

	/**
	 * Traduit les anomalies en trois sous-scores /100 + un score global.
	 * Bucket : meta/social/schema → « meta » ; content/links → « content » ;
	 * technical/performance → « technical ».
	 */
	private static function score( $issues ) {
		$deduct = array( 'content' => 0, 'meta' => 0, 'technical' => 0 );
		foreach ( $issues as $i ) {
			// La lisibilité est une dimension séparée (note propre) — elle ne
			// déduit pas des sous-scores SEO. Voir CU_SEO_Readability.
			if ( 'readability' === $i['category'] ) {
				continue;
			}
			// Information neutre (ex. canonical vers une autre URL, souvent voulu) :
			// affichée, sans pénalité.
			if ( isset( $i['weight'] ) && (int) $i['weight'] < 0 ) {
				continue;
			}
			$bucket = self::bucket( $i['category'] );
			if ( ! empty( $i['weight'] ) ) {
				$w = (int) $i['weight']; // pénalité explicite (proportionnelle)
			} else {
				$w = 'error' === $i['severity'] ? self::WEIGHT_ERROR
					: ( 'warning' === $i['severity'] ? self::WEIGHT_WARNING : self::WEIGHT_NOTICE );
			}
			$deduct[ $bucket ] += $w;
		}
		$content   = max( 0, 100 - $deduct['content'] );
		$meta      = max( 0, 100 - $deduct['meta'] );
		$technical = max( 0, 100 - $deduct['technical'] );
		// Pondération « contenu-first » : le contenu et les balises (le vrai travail
		// SEO) pèsent plus que la technique, propre d'office sur WordPress. Un site
		// techniquement sain mais sans contenu ne doit PAS obtenir une bonne note.
		$overall   = (int) round( $content * 0.40 + $meta * 0.35 + $technical * 0.25 );

		return array(
			'overall'   => $overall,
			'content'   => $content,
			'meta'      => $meta,
			'technical' => $technical,
		);
	}

	private static function bucket( $category ) {
		if ( in_array( $category, array( 'meta', 'social', 'schema' ), true ) ) {
			return 'meta';
		}
		if ( in_array( $category, array( 'technical', 'performance' ), true ) ) {
			return 'technical';
		}
		return 'content'; // content, links
	}

	/**
	 * Largeur approximative d'une chaîne en pixels, façon rendu SERP (~Arial 20px).
	 * Google tronque le titre par pixels, pas par caractères — cette estimation est
	 * « indicative » mais bien plus fiable qu'un simple comptage de caractères.
	 */
	public static function pixel_width( $str ) {
		$str = (string) $str;
		$narrow = array( 'i' => 4, 'j' => 4, 'l' => 4, 'f' => 5, 't' => 5, 'r' => 6, 'I' => 5, '.' => 5, ',' => 5, '\'' => 3, '!' => 5, ':' => 5, ';' => 5, ' ' => 5, '|' => 4 );
		$wide   = array( 'm' => 15, 'w' => 14, 'M' => 16, 'W' => 17, 'G' => 13, 'O' => 13, 'Q' => 13, 'H' => 13, 'D' => 13, '@' => 18 );
		$total  = 0;
		// Découpage en caractères sans mbstring ni ctype (extensions facultatives).
		$chars = preg_split( '//u', $str, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			$chars = str_split( $str ); // UTF-8 invalide : octet par octet.
		}
		foreach ( $chars as $ch ) {
			if ( isset( $narrow[ $ch ] ) ) {
				$total += $narrow[ $ch ];
			} elseif ( isset( $wide[ $ch ] ) ) {
				$total += $wide[ $ch ];
			} elseif ( preg_match( '/^\p{Lu}$/u', $ch ) ) {
				$total += 12;
			} elseif ( preg_match( '/^[0-9]$/', $ch ) ) {
				$total += 10;
			} else {
				$total += 9; // minuscule / accent / autre
			}
		}
		return (int) $total;
	}

	/**
	 * Le mot-clé figure-t-il dans le chemin de l'URL ? Comparaison sur la forme
	 * « slug » (accents retirés, tirets, minuscules — comme WordPress fabrique
	 * ses slugs) du mot-clé ET du chemin décodé : « référencement naturel »
	 * correspond à /referencement-naturel/ comme à /r%C3%A9f…/ ; un mot-clé
	 * cyrillique correspond à son slug encodé. Segments de slug entiers :
	 * « seo » ne correspond pas à /museo/.
	 */
	public static function keyword_in_slug( $keyword, $url ) {
		$kw_slug = self::slugify( $keyword );
		if ( '' === $kw_slug ) {
			return false;
		}
		$path     = rawurldecode( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			$slug = self::slugify( $segment );
			if ( '' !== $slug ) {
				$segments[] = $slug;
			}
		}
		if ( empty( $segments ) ) {
			return false;
		}
		return 1 === preg_match( '#(?:^|[/-])' . preg_quote( $kw_slug, '#' ) . '(?:$|[/-])#', implode( '/', $segments ) );
	}

	/** Forme « slug » WordPress d'un texte (repli sans sanitize_title). */
	private static function slugify( $text ) {
		$text = (string) $text;
		if ( function_exists( 'sanitize_title' ) ) {
			return (string) sanitize_title( $text );
		}
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $text ) ), '-' );
	}

	/** Densité (%) d'un mot-clé (mono ou multi-mots) dans un texte. */
	public static function keyword_density( $text, $keyword ) {
		$text    = CU_Text::lower( $text );
		$keyword = CU_Text::lower( trim( $keyword ) );
		if ( '' === $keyword || '' === $text ) {
			return 0.0;
		}
		$total = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$total = is_array( $total ) ? count( $total ) : 0;
		if ( $total < 1 ) {
			return 0.0;
		}
		// Mots entiers uniquement : « art » ne doit pas compter dans « partir ».
		$pattern     = '/(?<![\p{L}\p{N}])' . preg_quote( $keyword, '/' ) . '(?![\p{L}\p{N}])/u';
		$occurrences = preg_match_all( $pattern, $text );
		if ( false === $occurrences ) {
			$occurrences = substr_count( $text, $keyword ); // UTF-8 invalide : repli approximatif.
		}
		$kw_parts    = preg_split( '/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY );
		$kw_words    = max( 1, is_array( $kw_parts ) ? count( $kw_parts ) : 1 );
		return round( ( $occurrences * $kw_words / $total ) * 100, 1 );
	}

	/**
	 * Score « AI-Ready » (0-100) : à quel point la page est prête à être
	 * comprise et citée par les moteurs génératifs (ChatGPT, Copilot, Gemini,
	 * AI Overview). Heuristique honnête basée sur des signaux réels.
	 */
	public static function ai_ready_score( $p ) {
		$score = 0;
		// Données structurées = signal fort pour les IA.
		if ( ! empty( $p['schema_types'] ) ) {
			$score += 25;
			// FAQ / Q&A explicitement balisées : bonus (format de réponse directe).
			foreach ( $p['schema_types'] as $t ) {
				if ( in_array( $t, array( 'FAQPage', 'QAPage', 'HowTo' ), true ) ) { $score += 10; break; }
			}
		}
		// Contenu suffisant pour fournir du contexte.
		if ( $p['word_count'] >= 600 ) {
			$score += 20;
		} elseif ( $p['word_count'] >= 300 ) {
			$score += 10;
		}
		// Résumé exploitable (meta description).
		if ( '' !== $p['meta_description'] ) {
			$score += 15;
		}
		// Structure claire : un H1 + des H2.
		if ( 1 === count( $p['h1'] ) ) {
			$score += 10;
		}
		$has_h2 = false;
		foreach ( $p['headings'] as $h ) {
			if ( 2 === (int) $h['level'] ) { $has_h2 = true; break; }
		}
		if ( $has_h2 ) {
			$score += 10;
		}
		// Langue déclarée + liens externes (sources) = crédibilité.
		if ( '' !== $p['lang'] ) {
			$score += 5;
		}
		if ( ! empty( $p['links_external'] ) ) {
			$score += 5;
		}
		return min( 100, $score );
	}

	/**
	 * FIABILITÉ (Phase 1) — chaque constat assume sa SOURCE et sa CONFIANCE.
	 * Trois niveaux honnêtes :
	 *   🟢 observé   : lu directement dans le rendu final (fait quasi certain) ;
	 *   🟢 pratique  : seuil de bonne pratique reconnu (Google/Yoast), très fiable ;
	 *   🟡 indicatif : heuristique interprétative (à confirmer par un humain).
	 * On n'invente jamais un chiffre : on affiche d'où il vient et à quel point on l'assume.
	 *
	 * @return array{source:string, confidence:int}
	 */
	public static function reliability( $code ) {
		// Heuristiques / lisibilité = indicatif (à interpréter par un humain).
		$heuristic = array( 'thin_content', 'kw_overopt', 'kw_underused', 'long_sentences', 'hard_to_read', 'long_paragraphs', 'few_transitions', 'subheading_distribution', 'js_only' );
		if ( in_array( $code, $heuristic, true ) ) {
			return array( 'source' => __( 'Heuristique — indicatif', 'cyberpush-ultra' ), 'confidence' => 62, 'basis' => 'heuristic' );
		}
		// Fait mesuré, mais souvent volontaire : à vérifier par un humain.
		if ( in_array( $code, array( 'canonical_other', 'canonical_external' ), true ) ) {
			return array( 'source' => __( 'Rendu de la page — peut être volontaire', 'cyberpush-ultra' ), 'confidence' => 80, 'basis' => 'check' );
		}
		// Seuils de bonne pratique (le constat est mesuré ; l'« optimal » reste discutable).
		$bestpractice = array( 'title_short', 'title_long', 'title_pixels', 'metadesc_short', 'metadesc_long', 'kw_not_in_title', 'kw_not_in_h1', 'kw_not_in_desc', 'kw_not_in_url', 'kw_not_in_alt', 'kw_not_in_anchor', 'kw_not_in_intro', 'kw_not_in_subheading', 'h2_missing', 'generic_anchor', 'no_external_links', 'canonical_missing', 'og_incomplete', 'twitter_missing', 'schema_missing', 'favicon_missing' );
		if ( in_array( $code, $bestpractice, true ) ) {
			return array( 'source' => __( 'Bonne pratique SEO (Google/Yoast)', 'cyberpush-ultra' ), 'confidence' => 85, 'basis' => 'practice' );
		}
		// Sinon : fait observé directement dans le rendu de la page.
		return array( 'source' => __( 'Rendu de la page (WordPress)', 'cyberpush-ultra' ), 'confidence' => 96, 'basis' => 'observed' );
	}

	/** Libellés lisibles des catégories (pour l'UI). */
	public static function category_label( $category ) {
		$map = array(
			'content'     => __( 'Contenu', 'cyberpush-ultra' ),
			'meta'        => __( 'Balises', 'cyberpush-ultra' ),
			'technical'   => __( 'Technique', 'cyberpush-ultra' ),
			'links'       => __( 'Liens', 'cyberpush-ultra' ),
			'social'      => __( 'Social', 'cyberpush-ultra' ),
			'schema'      => __( 'Données structurées', 'cyberpush-ultra' ),
			'readability' => __( 'Lisibilité', 'cyberpush-ultra' ),
			'performance' => __( 'Performance', 'cyberpush-ultra' ),
		);
		return isset( $map[ $category ] ) ? $map[ $category ] : ucfirst( $category );
	}
}

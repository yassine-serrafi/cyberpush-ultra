<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CU_SEO_Recommender — la couche « consultant SEO ».
 * -----------------------------------------------------------------------------
 * Ne se contente pas de LISTER les problèmes : il les traduit en PLAN D'ACTION
 * priorisé (niveau 3). Pour chaque recommandation :
 *   - ce qu'il faut faire (action concrète) ;
 *   - POURQUOI (impact expliqué en langage clair) ;
 *   - un impact QUALITATIF et un effort, d'où découle la priorité ;
 *   - la règle de calcul de cette priorité, affichée telle quelle (rationale).
 *
 * 100 % local : calculé à partir des anomalies déjà détectées + l'audit
 * technique. Aucune API externe. AUCUN gain de trafic chiffré : sans données
 * Search Console (impressions, positions), tout pourcentage serait inventé.
 */
class CU_SEO_Recommender {

	/**
	 * Construit le plan d'action.
	 *
	 * @param array $counts Nombre de pages concernées par code d'anomalie.
	 * @param array $site   Résultat de l'audit technique du site (orphelines, liens cassés…).
	 * @param int   $total  Nombre total de pages analysées.
	 * @return array Liste de recommandations triées (priorité décroissante).
	 */
	public static function build( $counts, $site, $total ) {
		$total = max( 1, (int) $total );
		$recos = array();

		$c = function ( $code ) use ( $counts ) {
			return isset( $counts[ $code ] ) ? (int) $counts[ $code ] : 0;
		};
		// Pages DISTINCTES pour une recommandation qui regroupe plusieurs codes
		// (« @groupe » fourni par l'appelant) ; à défaut, la plus grande des
		// valeurs (jamais la somme : une page sans titre ET sans description
		// comptait deux fois).
		$pages_for = function ( $group, $codes ) use ( $counts, $c ) {
			if ( isset( $counts[ '@' . $group ] ) ) {
				return (int) $counts[ '@' . $group ];
			}
			$max = 0;
			foreach ( $codes as $code ) {
				$max = max( $max, $c( $code ) );
			}
			return $max;
		};

		// -- Balises manquantes (title + meta description) ------------------
		$meta_missing = $pages_for( 'meta_missing', array( 'metadesc_missing', 'title_missing' ) );
		if ( $meta_missing > 0 ) {
			$recos[] = self::reco(
				'meta_missing',
				__( 'Rédiger les balises title / meta manquantes', 'cyberpush-ultra' ),
				sprintf( __( '%d page(s) sans titre ou sans meta description.', 'cyberpush-ultra' ), $meta_missing ),
				__( 'Sans title, Google affiche un titre de substitution ; sans meta description, il compose lui-même l\'extrait à partir du texte. Ces deux éléments sont ce que l\'internaute voit en premier dans les résultats : vous perdez la main sur votre message.', 'cyberpush-ultra' ),
				5, 2, $meta_missing, $total
			);
		}

		// -- Liens cassés ---------------------------------------------------
		$broken = isset( $site['broken_count'] ) ? (int) $site['broken_count'] : 0;
		if ( $broken > 0 ) {
			$recos[] = self::reco(
				'broken_links',
				__( 'Réparer les liens cassés', 'cyberpush-ultra' ),
				sprintf( __( '%d lien(s) pointent vers une page en erreur (404/5xx).', 'cyberpush-ultra' ), $broken ),
				__( 'Les liens cassés gâchent l\'expérience utilisateur et gaspillent le « budget de crawl » de Google. Ils diluent aussi la popularité transmise dans votre site.', 'cyberpush-ultra' ),
				4, 2, $broken, $total
			);
		}

		// -- Pages en noindex (potentiellement involontaire) ----------------
		if ( $c( 'noindex' ) > 0 ) {
			$recos[] = self::reco(
				'noindex',
				__( 'Vérifier les pages en noindex', 'cyberpush-ultra' ),
				sprintf( __( '%d page(s) sont exclues des moteurs (noindex).', 'cyberpush-ultra' ), $c( 'noindex' ) ),
				__( 'Une page en noindex ne peut apparaître dans aucun résultat. Si l\'une d\'elles devait être trouvée sur Google, c\'est une perte de trafic directe et invisible.', 'cyberpush-ultra' ),
				5, 1, $c( 'noindex' ), $total
			);
		}

		// -- Maillage interne (orphelines + pages sans lien interne) --------
		// Une page à la fois orpheline et sans lien sortant compte une fois.
		// Orphelines non confirmées (pages non lues) : non comptées.
		$orphans   = ( empty( $site['orphans_uncertain'] ) && isset( $site['orphans_count'] ) ) ? (int) $site['orphans_count'] : 0;
		$overlap   = ( $orphans && isset( $site['orphans_no_outlinks'] ) ) ? min( $orphans, (int) $site['orphans_no_outlinks'] ) : 0;
		$weak_link = max( $orphans, $c( 'no_internal_links' ), $orphans + $c( 'no_internal_links' ) - $overlap );
		if ( $weak_link > 0 ) {
			$recos[] = self::reco(
				'internal_linking',
				__( 'Renforcer le maillage interne', 'cyberpush-ultra' ),
				sprintf( __( '%d page(s) orphelines ou sans lien interne sortant.', 'cyberpush-ultra' ), $weak_link ),
				__( 'Une page orpheline (aucun lien entrant) est quasi invisible pour Google et les visiteurs. Le maillage interne distribue la popularité et guide l\'exploration — un levier entièrement sous votre contrôle.', 'cyberpush-ultra' ),
				4, 2, $weak_link, $total
			);
		}

		// -- Pages trop profondes ------------------------------------------
		$deep = isset( $site['deep_count'] ) ? (int) $site['deep_count'] : 0;
		if ( $deep > 0 ) {
			$recos[] = self::reco(
				'deep_pages',
				__( 'Rapprocher les pages profondes de l\'accueil', 'cyberpush-ultra' ),
				sprintf(
					/* translators: 1: nombre de pages, 2: profondeur maximale réglée */
					__( '%1$d page(s) à plus de %2$d clics de l\'accueil.', 'cyberpush-ultra' ),
					$deep,
					max( 1, (int) CU_Settings::get( 'seo', 'max_crawl_depth', 3 ) )
				),
				__( 'Les pages trop éloignées de l\'accueil sont explorées moins souvent par Google et jugées moins importantes. Ajoutez des liens depuis des pages proches de l\'accueil.', 'cyberpush-ultra' ),
				3, 3, $deep, $total
			);
		}

		// -- Contenu mince --------------------------------------------------
		if ( $c( 'thin_content' ) > 0 ) {
			$recos[] = self::reco(
				'thin_content',
				__( 'Étoffer les contenus trop courts', 'cyberpush-ultra' ),
				sprintf( __( '%d page(s) avec un contenu jugé mince.', 'cyberpush-ultra' ), $c( 'thin_content' ) ),
				__( 'Les pages trop courtes couvrent mal leur sujet et se positionnent difficilement face à des contenus plus complets. Ajoutez des sections utiles, exemples, FAQ.', 'cyberpush-ultra' ),
				4, 4, $c( 'thin_content' ), $total
			);
		}

		// -- Doublons de titres / descriptions ------------------------------
		$dups = ( isset( $site['dup_titles_count'] ) ? (int) $site['dup_titles_count'] : 0 )
			+ ( isset( $site['dup_metas_count'] ) ? (int) $site['dup_metas_count'] : 0 );
		if ( $dups > 0 ) {
			$recos[] = self::reco(
				'duplicates',
				__( 'Dédupliquer titres et descriptions', 'cyberpush-ultra' ),
				sprintf( __( '%d groupe(s) de pages partagent le même titre ou la même description.', 'cyberpush-ultra' ), $dups ),
				__( 'Des titres/descriptions identiques empêchent Google de distinguer vos pages et se cannibalisent entre elles. Chaque page mérite un titre unique et spécifique.', 'cyberpush-ultra' ),
				3, 3, $dups, $total
			);
		}

		// -- Données structurées -------------------------------------------
		if ( $c( 'schema_missing' ) > 0 ) {
			$recos[] = self::reco(
				'schema',
				__( 'Ajouter des données structurées (Schema.org)', 'cyberpush-ultra' ),
				sprintf( __( '%d page(s) sans balisage JSON-LD.', 'cyberpush-ultra' ), $c( 'schema_missing' ) ),
				__( 'Le balisage (Article, Product, FAQ, Breadcrumb…) débloque les résultats enrichis (étoiles, FAQ dépliables) et aide les IA (ChatGPT, Copilot) à comprendre et citer votre contenu.', 'cyberpush-ultra' ),
				3, 3, $c( 'schema_missing' ), $total
			);
		}

		// -- Structure des titres (H1) --------------------------------------
		$h1 = $pages_for( 'headings', array( 'h1_missing', 'h1_multiple' ) );
		if ( $h1 > 0 ) {
			$recos[] = self::reco(
				'headings',
				__( 'Corriger la structure des titres H1', 'cyberpush-ultra' ),
				sprintf( __( '%d page(s) sans H1 ou avec plusieurs H1.', 'cyberpush-ultra' ), $h1 ),
				__( 'Un H1 unique et descriptif indique clairement le sujet de la page. Plusieurs H1 diluent ce signal ; aucun H1 le supprime.', 'cyberpush-ultra' ),
				3, 2, $h1, $total
			);
		}

		// -- Textes alternatifs des images ----------------------------------
		if ( $c( 'img_no_alt' ) > 0 ) {
			$recos[] = self::reco(
				'img_alt',
				__( 'Renseigner les textes alternatifs (ALT)', 'cyberpush-ultra' ),
				sprintf( __( '%d page(s) contiennent des images sans attribut alt.', 'cyberpush-ultra' ), $c( 'img_no_alt' ) ),
				__( 'Le texte alternatif permet le référencement dans Google Images (trafic supplémentaire) et améliore l\'accessibilité. Gain modeste mais rapide.', 'cyberpush-ultra' ),
				2, 2, $c( 'img_no_alt' ), $total
			);
		}

		// -- Mot-clé cible non optimisé -------------------------------------
		$kw = $pages_for( 'keyword', array( 'kw_not_in_title', 'kw_not_in_h1', 'kw_not_in_desc' ) );
		if ( $kw > 0 ) {
			$recos[] = self::reco(
				'keyword',
				__( 'Optimiser le placement du mot-clé cible', 'cyberpush-ultra' ),
				sprintf( __( '%d page(s) où le mot-clé cible manque dans le titre, le H1 ou la description.', 'cyberpush-ultra' ), $kw ),
				__( 'Placer le mot-clé cible dans le title, le H1 et la description renforce la pertinence perçue et le taux de clic sur la requête visée.', 'cyberpush-ultra' ),
				3, 2, $kw, $total
			);
		}

		// Tri par score de priorité décroissant.
		usort( $recos, function ( $a, $b ) {
			return $b['score'] - $a['score'];
		} );

		return $recos;
	}

	/**
	 * Fabrique une recommandation : impact et effort QUALITATIFS (échelle 1-5
	 * fixée par type de problème), priorité et ordre de tri déduits par une
	 * règle publique, exposée dans `rationale`. Aucun gain chiffré.
	 */
	private static function reco( $id, $title, $action, $why, $impact, $effort, $affected, $total ) {
		$affected = (int) $affected;

		// Ordre de tri (interne, non affiché comme une mesure) : impact ×
		// nombre de pages concernées, légèrement favorisé si l'effort est faible.
		$score = (int) round( $impact * ( 10 + $affected ) * ( 1 + ( 6 - $effort ) * 0.05 ) );

		$priority = 'low';
		if ( $impact >= 4 && $affected > 0 ) {
			$priority = 'high';
		} elseif ( $impact >= 3 ) {
			$priority = 'medium';
		}

		$meta = self::confidence_for( $id );
		$kb   = CU_SEO_Knowledge::get( $id );

		$rationale = sprintf(
			/* translators: 1: impact 1-5, 2: effort 1-5, 3: pages concernées, 4: pages analysées */
			__( 'Impact estimé %1$d/5 (selon le type de problème) · effort %2$d/5 · %3$d élément(s) concerné(s) sur %4$d page(s) analysée(s). Priorité élevée = impact ≥ 4 ; moyenne = impact 3 ; sinon faible. Aucun gain de trafic n\'est chiffré : il dépend de données (impressions, positions) que l\'audit ne mesure pas.', 'cyberpush-ultra' ),
			(int) $impact,
			(int) $effort,
			$affected,
			(int) $total
		);

		return array(
			'id'           => $id,
			'title'        => $title,
			'action'       => $action,
			'why'          => $why,
			'what'         => $kb ? $kb['what'] : '',
			'how'          => $kb ? $kb['how'] : array(),
			'difficulty'   => $kb ? $kb['difficulty'] : 'medium',
			'impact_level' => $kb ? $kb['impact_level'] : 'medium',
			'impact'       => (int) $impact,
			'effort'       => (int) $effort,
			'pages'        => $affected,
			'priority'     => $priority,
			'score'        => $score,
			'rationale'    => $rationale,
			'confidence'   => $meta['confidence'],
			'basis'        => $meta['basis'],
			'basis_label'  => $meta['basis_label'],
			'source'       => $meta['source'],
		);
	}

	/**
	 * Nature du constat derrière chaque recommandation, en clair. Pas de
	 * pourcentage de « confiance » affiché : ce serait une précision inventée.
	 * `confidence` reste exposé pour compatibilité (tri, anciens écrans).
	 */
	private static function confidence_for( $id ) {
		$measured = __( 'Fait mesuré dans le rendu des pages', 'cyberpush-ultra' );
		$map = array(
			'meta_missing'     => array( 99, 'observed', $measured ),
			'broken_links'     => array( 100, 'observed', __( 'Code HTTP réellement renvoyé par le lien', 'cyberpush-ultra' ) ),
			'noindex'          => array( 82, 'check', __( 'Fait mesuré, mais peut être volontaire : à vérifier', 'cyberpush-ultra' ) ),
			'internal_linking' => array( 92, 'observed', __( 'Graphe des liens internes relevés par l\'audit', 'cyberpush-ultra' ) ),
			'deep_pages'       => array( 90, 'observed', __( 'Graphe des liens internes relevés par l\'audit', 'cyberpush-ultra' ) ),
			'thin_content'     => array( 85, 'heuristic', __( 'Indice (nombre de mots) : la qualité réelle se juge à la lecture', 'cyberpush-ultra' ) ),
			'duplicates'       => array( 96, 'observed', $measured ),
			'schema'           => array( 90, 'practice', __( 'Bonne pratique : absence de balisage mesurée', 'cyberpush-ultra' ) ),
			'headings'         => array( 98, 'observed', $measured ),
			'img_alt'          => array( 99, 'observed', $measured ),
			'keyword'          => array( 88, 'practice', __( 'Bonne pratique appliquée à votre mot-clé cible', 'cyberpush-ultra' ) ),
		);
		$row = isset( $map[ $id ] ) ? $map[ $id ] : array( 85, 'practice', __( 'Bonne pratique SEO', 'cyberpush-ultra' ) );
		return array(
			'confidence'  => $row[0],
			'basis'       => $row[1],
			'basis_label' => $row[2],
			'source'      => 'wordpress', // audit local — voir légende de fiabilité
		);
	}
}

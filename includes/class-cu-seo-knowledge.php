<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CU_SEO_Knowledge — la « pédagogie » du plugin.
 * -----------------------------------------------------------------------------
 * Le plus gros défaut de Semrush/Ahrefs/Screaming Frog : ils MONTRENT beaucoup
 * de données mais EXPLIQUENT peu quoi faire. Cette base répond, pour chaque
 * type de problème, aux 5 questions qui comptent :
 *   1. Qu'est-ce que c'est ?        (what)
 *   2. Pourquoi c'est important ?   (why — porté par la reco / l'anomalie)
 *   3. Quel impact SEO ?            (impact_level)
 *   4. Comment corriger ?           (how : étapes concrètes, sans jargon)
 *   5. Quel enjeu ?                 (impact_level QUALITATIF — jamais de gain chiffré)
 *
 * + une estimation de DIFFICULTÉ (temps réaliste). Tout est en langage clair.
 * Règle : aucun chiffre non vérifiable (pourcentage de CTR, multiplicateur de
 * citations…) — seulement des faits documentés par Google ou mesurés par l'audit.
 */
class CU_SEO_Knowledge {

	/**
	 * @param string $id Identifiant de recommandation (ou code d'anomalie).
	 * @return array{what:string, how:array, difficulty:string, impact_level:string}|null
	 */
	public static function get( $id ) {
		$map = self::map();
		return isset( $map[ $id ] ) ? $map[ $id ] : null;
	}

	/**
	 * Niveaux de difficulté (clé => libellé + temps indicatif).
	 * very_easy | easy | medium | advanced
	 */
	public static function difficulty_labels() {
		return array(
			'very_easy' => array( 'label' => __( 'Très facile', 'cyberpush-ultra' ), 'time' => __( '≈ 1 minute', 'cyberpush-ultra' ) ),
			'easy'      => array( 'label' => __( 'Facile', 'cyberpush-ultra' ), 'time' => __( '≈ 5 minutes', 'cyberpush-ultra' ) ),
			'medium'    => array( 'label' => __( 'Moyen', 'cyberpush-ultra' ), 'time' => __( '≈ 20 minutes', 'cyberpush-ultra' ) ),
			'advanced'  => array( 'label' => __( 'Avancé', 'cyberpush-ultra' ), 'time' => __( 'développeur', 'cyberpush-ultra' ) ),
		);
	}

	/**
	 * Niveaux d'impact (clé => libellé). On ne promet JAMAIS un chiffre
	 * de trafic garanti — on parle de potentiel / priorité.
	 * critical | strong | medium | low
	 */
	public static function impact_labels() {
		return array(
			'critical' => __( 'Très fort', 'cyberpush-ultra' ),
			'strong'   => __( 'Fort', 'cyberpush-ultra' ),
			'medium'   => __( 'Moyen', 'cyberpush-ultra' ),
			'low'      => __( 'Faible', 'cyberpush-ultra' ),
		);
	}

	private static function map() {
		return array(
			'meta_missing' => array(
				'what'         => __( 'Le <title> est l\'un des éléments on-page les plus importants pour Google. La meta description, en revanche, N\'EST PAS un facteur de classement (Google le confirme depuis 2009) : elle sert d\'extrait dans les résultats et peut inciter au clic — l\'effet réel se mesure dans Search Console, pas à l\'avance. Quand elles manquent, Google en fabrique à votre place.', 'cyberpush-ultra' ),
				'source'       => __( 'Google Search Central', 'cyberpush-ultra' ),
				'impact_level' => 'strong',
				'difficulty'   => 'easy',
				'how'          => array(
					__( 'Ouvrir la page concernée dans WordPress.', 'cyberpush-ultra' ),
					__( 'Rédiger un titre unique de 50–60 caractères contenant le sujet principal.', 'cyberpush-ultra' ),
					__( 'Rédiger une description de 120–160 caractères qui donne envie de cliquer.', 'cyberpush-ultra' ),
					__( 'Enregistrer, puis relancer l\'analyse de la page.', 'cyberpush-ultra' ),
				),
			),
			'broken_links' => array(
				'what'         => __( 'Un lien cassé pointe vers une page qui n\'existe plus (erreur 404) ou un serveur en panne.', 'cyberpush-ultra' ),
				'impact_level' => 'strong',
				'difficulty'   => 'easy',
				'how'          => array(
					__( 'Repérer les liens cassés listés dans l\'audit technique.', 'cyberpush-ultra' ),
					__( 'Corriger l\'URL, la remplacer par une page valide, ou retirer le lien.', 'cyberpush-ultra' ),
					__( 'Pour un contenu déplacé, mettre en place une redirection 301.', 'cyberpush-ultra' ),
				),
			),
			'noindex' => array(
				'what'         => __( 'La balise « noindex » demande explicitement aux moteurs de NE PAS afficher la page dans les résultats.', 'cyberpush-ultra' ),
				'impact_level' => 'critical',
				'difficulty'   => 'very_easy',
				'how'          => array(
					__( 'Vérifier si le noindex est volontaire (page de remerciement, panier…) ou accidentel.', 'cyberpush-ultra' ),
					__( 'Si la page doit être trouvée sur Google, retirer le noindex (réglages SEO du contenu).', 'cyberpush-ultra' ),
				),
			),
			'internal_linking' => array(
				'what'         => __( 'Une page orpheline ne reçoit aucun lien depuis les autres pages du site. Google la découvre plus difficilement et la juge moins importante.', 'cyberpush-ultra' ),
				'impact_level' => 'strong',
				'difficulty'   => 'easy',
				'how'          => array(
					__( 'Identifier 2 à 3 contenus proches du sujet de la page orpheline.', 'cyberpush-ultra' ),
					__( 'Y ajouter un lien vers la page, avec une ancre descriptive (pas « cliquez ici »).', 'cyberpush-ultra' ),
					__( 'Ajouter la page aux menus ou aux articles liés si pertinent.', 'cyberpush-ultra' ),
				),
			),
			'deep_pages' => array(
				'what'         => __( 'La profondeur de clic est le nombre de clics nécessaires depuis l\'accueil pour atteindre une page. Plus une page est éloignée de l\'accueil, plus elle risque d\'être découverte et explorée tardivement. Le seuil (3 par défaut) est un repère réglable, pas une règle de Google.', 'cyberpush-ultra' ),
				'impact_level' => 'medium',
				'difficulty'   => 'medium',
				'how'          => array(
					__( 'Ajouter des liens vers ces pages depuis des pages proches de l\'accueil (catégories, pages piliers).', 'cyberpush-ultra' ),
					__( 'Revoir l\'architecture : regrouper, créer des pages de catégorie.', 'cyberpush-ultra' ),
				),
			),
			'thin_content' => array(
				'what'         => __( 'Le « contenu mince » désigne une page à faible VALEUR (peu de profondeur, d\'originalité ou d\'utilité) — pas une page à faible nombre de mots. Google (John Mueller) le répète : le nombre de mots n\'est ni un facteur de classement ni un signe de contenu mince. Une réponse courte mais complète peut très bien se classer ; l\'inverse (beaucoup de texte creux) est pénalisé.', 'cyberpush-ultra' ),
				'source'       => __( 'Google — John Mueller (Search Advocate)', 'cyberpush-ultra' ),
				'impact_level' => 'strong',
				'difficulty'   => 'medium',
				'how'          => array(
					__( 'Ajouter des sections utiles : exemples, détails, questions fréquentes.', 'cyberpush-ultra' ),
					__( 'Répondre réellement à l\'intention de recherche de l\'internaute.', 'cyberpush-ultra' ),
					__( 'Viser un contenu au moins aussi complet que les pages déjà bien classées.', 'cyberpush-ultra' ),
				),
			),
			'duplicates' => array(
				'what'         => __( 'Plusieurs pages partagent le même titre ou la même description. Google a du mal à les distinguer et elles se concurrencent.', 'cyberpush-ultra' ),
				'impact_level' => 'medium',
				'difficulty'   => 'easy',
				'how'          => array(
					__( 'Donner à chaque page un titre et une description uniques et spécifiques.', 'cyberpush-ultra' ),
					__( 'Fusionner les pages qui traitent exactement le même sujet.', 'cyberpush-ultra' ),
				),
			),
			'schema' => array(
				'what'         => __( 'Les données structurées (Schema.org / JSON-LD) décrivent le contenu dans un format que les moteurs comprennent : article, produit, FAQ, fil d\'Ariane… Elles rendent une page éligible à certains résultats enrichis, sans garantie d\'affichage : Google décide au cas par cas et a restreint plusieurs types (FAQ, How-To) ces dernières années. Elles n\'améliorent pas directement le classement.', 'cyberpush-ultra' ),
				'source'       => __( 'Google Search Central (données structurées)', 'cyberpush-ultra' ),
				'impact_level' => 'medium',
				'difficulty'   => 'medium',
				'how'          => array(
					__( 'Activer le balisage adapté au type de contenu (Article, Product, FAQ…).', 'cyberpush-ultra' ),
					__( 'Ajouter une FAQ balisée sur les pages qui s\'y prêtent.', 'cyberpush-ultra' ),
					__( 'Vérifier le résultat avec l\'outil de test des résultats enrichis de Google.', 'cyberpush-ultra' ),
				),
			),
			'headings' => array(
				'what'         => __( 'Le H1 est le titre principal visible de la page. Il indique à Google le sujet central. Il doit être unique.', 'cyberpush-ultra' ),
				'impact_level' => 'medium',
				'difficulty'   => 'very_easy',
				'how'          => array(
					__( 'S\'assurer que chaque page a un seul H1, clair et descriptif.', 'cyberpush-ultra' ),
					__( 'Passer les titres secondaires en H2 / H3.', 'cyberpush-ultra' ),
				),
			),
			'img_alt' => array(
				'what'         => __( 'Le texte alternatif (attribut alt) décrit une image. Il permet le référencement dans Google Images et l\'accessibilité aux lecteurs d\'écran.', 'cyberpush-ultra' ),
				'impact_level' => 'low',
				'difficulty'   => 'very_easy',
				'how'          => array(
					__( 'Ouvrir la bibliothèque de médias ou la page.', 'cyberpush-ultra' ),
					__( 'Renseigner un alt court et descriptif pour chaque image importante.', 'cyberpush-ultra' ),
				),
			),
			'keyword' => array(
				'what'         => __( 'Le mot-clé cible est l\'expression sur laquelle vous voulez être trouvé. Il gagne à figurer dans le title, le H1 et la description.', 'cyberpush-ultra' ),
				'impact_level' => 'medium',
				'difficulty'   => 'easy',
				'how'          => array(
					__( 'Définir le mot-clé cible de la page (dans le détail de la page).', 'cyberpush-ultra' ),
					__( 'L\'intégrer naturellement dans le title, le H1 et la description.', 'cyberpush-ultra' ),
					__( 'Éviter la sur-optimisation (répétition excessive).', 'cyberpush-ultra' ),
				),
			),
			'readability' => array(
				'what'         => __( 'La lisibilité mesure le confort de lecture : longueur des phrases et des paragraphes, mots de transition, sous-titres, indice de lecture (Flesch). Ce n\'est PAS un facteur de classement Google, mais un contenu clair est mieux lu, mieux compris et plus souvent cité par les IA (ChatGPT, Perplexity).', 'cyberpush-ultra' ),
				'source'       => __( 'Flesch Reading Ease ; bonnes pratiques Yoast / AIOSEO', 'cyberpush-ultra' ),
				'impact_level' => 'low',
				'difficulty'   => 'easy',
				'how'          => array(
					__( 'Raccourcir les phrases de plus de 20 mots (une idée par phrase).', 'cyberpush-ultra' ),
					__( 'Aérer : des paragraphes de 3-4 phrases, des sous-titres tous les ~300 mots.', 'cyberpush-ultra' ),
					__( 'Ajouter des mots de transition (« ainsi », « cependant », « par conséquent »…).', 'cyberpush-ultra' ),
				),
			),
		);
	}
}

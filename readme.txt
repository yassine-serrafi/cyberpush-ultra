=== CyberPush Ultra ===
Contributors: cyberpush
Tags: analytics, firewall, security, seo, indexnow
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Analytics respectueux de la vie privée, pare-feu applicatif, journal de sécurité, audit SEO et IndexNow, intégrés à WordPress.

== Description ==

* Centre de contrôle (Dashboard) : points d'attention de tous les modules, état de chaque protection, audience, contacts, sécurité, vulnérabilités, connexions, SEO et système sur une seule page.
* Analytics hébergé sur votre serveur : pages, sessions, défilement, conversions (déclarées / confirmées), WooCommerce, contacts WhatsApp et Contact Form 7, carte des pays, clics sortants (liens externes, téléchargements, appels, e-mails), langues et écrans, temps réel, robots d'IA.
* Pare-feu : WAF par signatures, limitation de débit, protection de la connexion, anti-énumération, durcissement REST et XML-RPC, listes IP/CIDR (IPv4/IPv6), vérification des robots des moteurs, Radar passif, compatibilité Cloudflare même derrière le relais de l'hébergeur.
* Vulnérabilités & versions : PHP, WordPress, extensions et thèmes (actifs ou non), base de données, serveur web, curl et ImageMagick comparés chaque jour à la base publique WPVulnerability ; cycle de maintenance officiel de PHP ; alertes sans faux positif évitable.
* Protection des connexions : connexions aux comptes administrateurs, nouvelles adresses IP et nouveaux pays par compte, connexion réussie après des échecs, créations / promotions / retraits d'administrateurs, mots de passe et e-mails modifiés. Surveillance et alertes uniquement, aucun blocage.
* Journal de sécurité : événements sensibles et surveillance de l'intégrité des fichiers (cœur, racine, wp-content).
* Anti-spam : formulaires natifs de WordPress (commentaires et avis, inscription, mot de passe oublié) et Contact Form 7, sans CAPTCHA et sans analyse du contenu des messages.
* Audit SEO (aucune balise émise sur le site) : rendu final des pages, règles techniques et de contenu, lisibilité FR/EN, maillage, PageSpeed Insights, présence dans Google via Serper.dev.
* IndexNow : soumission automatique et discrète des contenus indexables.

== Récupération en cas de blocage ==

Si vous êtes bloqué hors de votre site, ajoutez dans wp-config.php :

    define( 'CU_FIREWALL_DISABLED', true );

Connectez-vous, corrigez la liste de blocage (Firewall > Blocages actifs), puis retirez la ligne.

== Proxy / CDN ==

Réglages > Firewall > « Adresse IP des visiteurs ». Cloudflare et les relais internes sont reconnus automatiquement ; déclarez les autres proxys publics (Sucuri, BunnyCDN, Fastly, QUIC.cloud…) dans « Proxys de confiance ». Un en-tête de transfert n'est jamais cru s'il ne vient pas d'un relais de confiance.

Derrière Cloudflare, le pays et l'adresse de chaque visiteur sont ceux transmis par Cloudflare, y compris lorsqu'un relais de l'hébergeur (mod_remoteip, nginx, front LiteSpeed…) se place entre Cloudflare et WordPress. Un relais public de l'hébergeur vu sur une requête d'administrateur n'est jamais banni ; l'avis « configuration proxy / CDN » propose de l'approuver en un clic.

== Connexions externes ==

Aucune requête n'est faite pendant la navigation des visiteurs. Les tâches planifiées et les actions d'un administrateur contactent uniquement :

* download.db-ip.com (secours : cdn.jsdelivr.net) : base de pays, une fois par mois, sans donnée de visiteur ;
* www.wpvulnerability.net : nom technique et version de chaque composant installé, une fois par jour (désactivable dans Firewall > Vulnérabilités & versions) ;
* www.php.net (secours : endoflife.date) : calendrier de maintenance des versions de PHP ;
* raw.githubusercontent.com : liste publique des domaines d'adresses e-mail jetables (module Spam) ;
* les services que vous configurez vous-même : IndexNow, PageSpeed Insights, Serper.dev, fournisseur d'IA.

== Confidentialité ==

Réglages > Analytics > « Mode de collecte » :

* Minimisé (par défaut) : sans consentement, aucune IP stockée ni transmise (le pays est déduit en mémoire d'une base installée sur le serveur), URL sans paramètres, opposition via le shortcode [cyberpush_optout] et le signal GPC, conservation de 25 mois au plus.
* Consentement : suivi continu après l'accord du visiteur (WP Consent API, Complianz, CookieYes, Cookie Notice, Google Consent Mode, ou window.cuAnalytics.consent(true)) ; sans accord, mesure anonyme page par page si elle est activée.
* Standard : sans restriction (hors UE ou base légale gérée par le site).

Un texte d'aide est proposé dans Réglages > Confidentialité > Guide de la politique de confidentialité.

== Pays des visiteurs ==

Le pays est déterminé sur votre serveur, avec ou sans consentement : l'adresse IP est comparée en mémoire à une base locale, puis oubliée (ni enregistrée, ni transmise). Seul le code pays est conservé. La base « IP to Country Lite » de DB-IP (environ 4 Mo, licence CC BY 4.0) est téléchargée par le site depuis download.db-ip.com à l'activation, puis une fois par mois ; cette requête ne contient aucune donnée de visiteur. Tant qu'elle n'est pas installée, le pays est estimé d'après le fuseau horaire du navigateur. Réglages > Analytics > Géolocalisation permet de choisir une autre source ou de couper la détection. Données de pays : IP Geolocation by DB-IP (https://db-ip.com).

== Changelog ==

= 1.3.4 =
* Sécurité (relecture de la 1.3.3) : l'adresse publique du serveur n'est plus approuvée d'office comme relais — en hébergement mutualisé, un autre site partageant cette adresse aurait pu forger X-Forwarded-For. Les en-têtes Cloudflare ne sont plus acceptés pour la mesure d'audience et l'anti-spam que si la connexion vient de Cloudflare ou d'un relais déjà vu sur une requête d'administrateur (plus de contournement des plafonds ni de compteur anti-spam rempli au nom d'un tiers). Seul un relais d'hébergement (adresse du serveur, chaîne Cloudflare) est mémorisé, jamais un proxy d'entreprise ou une sortie VPN.
* Spam : délai de grâce de 7 jours propre à chaque formulaire WordPress (pages encore en cache après la mise à jour) ; inscription et mot de passe oublié ne sont plus jamais refusés pour des champs de protection absents (formulaires personnalisés), seulement marqués « suspects ».
* Vulnérabilités : « 2.1 » et « 2.1.0 » sont désormais la même version (plus de faux positif sur les zéros finaux).
* Protection des connexions : adresses IPv6 comparées par préfixe /64 (les adresses temporaires ne déclenchent plus « nouvelle IP ») ; état de référence des administrateurs mis à jour à l'activation, à la réactivation de la surveillance et à chaque changement de rôle tracé (plus de fausse alerte « apparu sans passer par WordPress » ni de double e-mail) ; le centre de contrôle ne marque plus les alertes de connexion comme lues.

= 1.3.3 =
* Nouveau centre de contrôle (Dashboard), premier élément du menu : points d'attention de tous les modules, état de chaque protection, audience comparée à la période précédente, contacts, pays, sources et clics sortants, attaques, connexions administrateurs, journal de sécurité, vulnérabilités, santé SEO, anti-spam, IndexNow et journaux. Il n'utilise que les routes existantes ; Analytics garde son adresse.
* Cloudflare : le pays et l'adresse des visiteurs sont corrects même derrière un relais de l'hébergeur (avant : pays vide, ou pays et adresse du relais pour tout le monde, voire visites classées « non fiables » et masquées). Relais installé sur l'adresse du serveur reconnu automatiquement ; Cloudflare reconnu derrière un relais de confiance ; pseudo-IPv4 de Cloudflare remplacée par l'adresse IPv6 réelle. Le pare-feu reste strict : un en-tête forgé ne change jamais l'adresse utilisée pour bloquer. Relais public vu par un administrateur jamais banni, approbation en un clic. Diagnostic « Vérifier le suivi » : source du pays affichée.
* Base de pays : secours automatique sur le miroir jsDelivr quand download.db-ip.com est injoignable depuis l'hébergeur.
* Analytics : carte du monde des pays (visites, clics WhatsApp ou prises de contact), tableau par pays (visites, part, pages vues, clics WhatsApp, taux de contact, durée moyenne) et visites sans pays ; nouvelle section « Clics sortants » (liens externes, téléchargements, appels tel:, e-mails mailto: — domaine seulement) ; nouvelle section « Langues et écrans ». Schéma 1.9.4 (colonnes sessions.language et sessions.screen, table cu_logins).
* Spam : protection des formulaires natifs de WordPress (commentaires et avis WooCommerce, inscription, mot de passe oublié). Utilisateurs connectés jamais examinés ; un commentaire sans les champs de protection est rangé dans « Indésirables » (récupérable) ; « champs absents » ne bloque que si le formulaire a déjà été vu avec la protection. Compteur d'envois correct malgré la double validation des commentaires par WordPress. Derrière un relais d'hébergeur non approuvé, plus de compteur partagé par tous les visiteurs.
* Firewall › Vulnérabilités & versions (nouveau) : inventaire réel, comparaison exacte des plages de versions, « À vérifier » au lieu de « Vulnérable » quand la source est imprécise ou le produit incertain (Update URI, nom différent), composants serveur signalés « d'après la version » (correctifs rétroportés possibles), cycle de vie PHP depuis php.net, chaîne d'approvisionnement, vérification quotidienne par lots avec verrou, arrêt après trois échecs, derniers résultats conservés en cas de panne, e-mail unique par faille importante, avis d'administration masquable.
* Firewall › Protection des connexions (nouveau) : connexions des administrateurs (IP, pays, navigateur, IP connue / nouvelle / premier accès observé), accès avec une session déjà ouverte depuis un autre réseau, connexion réussie après des échecs (par compte et par adresse), historique par compte, filtres et pagination, conservation 30 jours par défaut. Administrateur apparu ou disparu sans passer par WordPress détecté. Jamais de blocage, de déconnexion ni de modification de compte.
* Journal de sécurité : retrait du rôle administrateur, suppression d'un administrateur (avec son identifiant), réinitialisation de mot de passe par lien, mots de passe d'application créés ou supprimés ; changement d'e-mail d'un administrateur désormais critique (alerte e-mail).
* Interface : l'attribut hidden l'emporte sur les styles des classes (bandeau vide et message « aucun envoi » qui restaient visibles sur la page Spam).

= 1.3.2 =
* Pays des visiteurs : détecté par défaut, avec ou sans consentement, grâce à une base locale (DB-IP Lite, lecteur intégré, aucune bibliothèque externe). L'adresse IP est lue en mémoire, jamais enregistrée ni transmise ; téléchargement automatique puis mensuel, bouton de mise à jour dans Réglages > Analytics. Pays estimé d'après le fuseau horaire du navigateur tant que la base n'est pas installée. Les sites restés sur « Aucun fournisseur » passent à la base locale (migration 1.9.3, sans changement de table) ; un fournisseur déjà choisi est conservé. Le fournisseur « MaxMind » fonctionne désormais sans la bibliothèque GeoIP2.
* Clics WhatsApp : un clic n'est plus écarté à tort comme doublon d'un clic fait depuis un autre chargement de page (le doublon technique est reconnu par le jeton de la page). Boutons désormais reconnus : ouverture par location.href ou location.assign, adresse placée dans onclick ou data-*, lien dans un shadow DOM, lien créé par script, bouton générique « WhatsApp » ; marqueur facultatif data-cu-whatsapp pour un lien de redirection interne. Un nouveau contact apparaît tout de suite dans les rapports (cache invalidé).

= 1.3.1 =
Version corrective issue de la campagne QA de la 1.3.0 (aucune nouvelle fonctionnalité, schéma 1.9.2 inchangé).
* Pare-feu : les champs de saisie libre déclarés d'un formulaire Contact Form 7 publié ne sont plus refusés pour leur contenu (code, SQL ou chemins cités par un visiteur) ; URL, paramètres techniques et autres protections inchangés. Plus aucune résolution DNS inverse pendant une visite : les robots sans liste officielle d'IP (Yandex, Baidu…) sont vérifiés en arrière-plan et traités d'ici là en visiteurs ordinaires. Compteurs de débit atomiques sans cache objet (le plafond tient sous requêtes simultanées ; toujours 429 temporaire, jamais de bannissement). Signatures de scanners comparées sur le nom entier de l'outil (plus de bannissement d'applications dont le nom contient « hydra », « nmap »…), « Fuzz Faster U Fool » reconnu. Aide de l'anti-hotlink plus explicite.
* Spam : champ piège masqué sans décalage hors écran (plus de défilement horizontal sur les sites RTL). Limite d'envois comptée par formulaire et par IP, avec un message clair de limite temporaire. Paramètres invalides du journal sans erreur 500.
* Analytics : clics WhatsApp distincts rapprochés plus fusionnés à tort ; une page vue renvoyée deux fois n'est plus comptée deux fois (jeton aléatoire par chargement de page, non persistant) ; IP partagée : au-delà du plafond horaire, les visites sont conservées et classées douteuses au lieu d'être écartées, et les limitations sont signalées dans « Vérifier le suivi » (le filtre cu_analytics_new_sessions_per_hour fixe désormais ce seuil de déclassement) ; adresse e-mail retirée des paramètres d'URL enregistrés ; entités HTML décodées dans les exports CSV ; Claude-User / Claude-SearchBot classés avec Claude ; navigateurs Linux rares plus jugés douteux ; libellé « Visites moteurs de recherche » ; graphiques et onglets « En bref » plus accessibles ; une visite expirée n'est plus prolongée par un envoi tardif.
* Réglages et système : écritures simultanées des réglages sérialisées (plus de modification perdue) ; une clé API n'est plus effacée par un envoi ou un import vide ou masqué (seul le bouton « Effacer » le fait) ; adresse Ollama normalisée avant contrôle (point final, notations IP) ; plafond d'appels IA atomique ; table absente → erreur explicite « migration requise » au lieu d'une erreur 500 ou d'un faux succès ; valeurs bornées réaffichées après enregistrement ; tâches planifiées protégées contre la double exécution ; plus d'ALTER TABLE répétés sur un schéma à jour (MariaDB) ; tailles des champs et listes de réglages bornées ; désinstallation : « false », « no », « 0 » ne déclenchent plus l'effacement ; migration 1.9.1 idempotente.
* Intégrité et journal : plus d'alerte critique sur wp-content/upgrade pendant une mise à jour légitime ; mise à jour journalisée comme mise à jour ; le nettoyage applique aussi la rétention du journal de sécurité.
* SEO : en-tête X-Robots-Tag et balises googlebot/bingbot lus, directive la plus restrictive retenue ; canonical vers une autre page (information), vers une page en erreur ou un autre domaine (avertissement) ; alt="" accepté comme image décorative ; ponctuation plus comptée comme mot ; URL redirigées exclues des doublons et des moyennes ; comptes du plan d'action en pages distinctes, troncature annoncée ; export CSV complet ; pourcentages bornés à 100 % ; audit marqué incomplet quand des pages n'ont pas pu être lues (orphelines « probables ») ; page de confiance comptée seulement si publiée ; abréviations courantes ; port pris en compte pour les liens internes ; messages d'erreur réseau lisibles.
* IndexNow : nouvelle tentative limitée après un échec passager (5 envois au plus) ; suppressions et changements d'adresse toujours signalés ; URL « __trashed-2 » corrigée ; aucun envoi pour un hôte local ou non public ; pages connues noindex par l'audit écartées. Serper : coût estimé et confirmation avant une vérification volumineuse, aucune relance automatique après une clé refusée ou un quota épuisé. PageSpeed : une seule mesure simultanée par page et par stratégie.

= 1.3.0 =
* Pare-feu / SEO : débit excessif et rafales de 404 → réponse 429 temporaire, sans bannissement (signal recommandé par Google) ; robots.txt toujours servi ; liens REST des archives d'auteur plus annoncés quand la route est refusée ; un robot non vérifié est un visiteur ordinaire (ni privilège, ni traitement particulier) ; vérification des robots sans écriture en base pour les IP absentes des listes officielles.
* Analytics : mesure anonyme de secours (refus, bandeau sans réponse, GPC) sans cookie, sans stockage, sans identifiant ni donnée tirée de l'IP, une ligne par page vue (réglage « Mesure anonyme », actif par défaut) ; détection de Google Consent Mode v2 ; taux par visite (rebond, durée, pages / visite, CTR, parcours, fidélité) calculés sur les seules visites suivies en continu, avec leur part affichée.
* Analytics : trafic technique mieux séparé (CUBOT n'est plus pris pour un robot, outils Google / Lighthouse / WebPageTest / surveillance reconnus, appels depuis l'adresse publique du serveur, navigateurs automatisés ; classement prudent, aucun en-tête HTTP inhabituel n'écarte un visiteur), page vue seulement une fois la page réellement affichée, script chargé deux fois sans double comptage. Schéma 1.9.1 (sessions.anon, content_daily.measured_sessions).
* Spam (nouveau, Contact Form 7) : champ piège invisible et horodatage signé, sans JavaScript et compatible cache (délai de grâce de 7 jours) ; domaine de l'adresse e-mail vérifié (domaines réservés, Null MX, domaine sans messagerie, adresses jetables d'une liste publique CC0 mise à jour chaque semaine, listes d'autorisation et de refus), une erreur DNS ne bloque jamais ; limite d'envois répétés par IP sans bannissement ; passe avant reCAPTCHA / Turnstile ; aucune analyse du contenu des messages. Journal minimal (ni message, ni adresse complète, ni IP), conservation réglable (30 jours par défaut). Schéma 1.9.2 (table cu_spam_log).

= 1.2.0 =
* Sécurité : résolution de l'IP visiteur sans confiance aveugle dans X-Real-IP / X-Forwarded-For (proxys de confiance, normalisation IPv6).
* Sécurité : la connexion est refusée à toute IP verrouillée, administrateurs compris (liste blanche et constante de récupération), limite sur wp-login.php.
* Sécurité : admin-ajax.php / admin-post.php anonymes soumis à la liste de blocage, au débit et au WAF (refus sans bannissement).
* Pare-feu : 1re détection = refus, bannissement seulement en cas de récidive distincte ; bannissements automatiques jamais permanents ; un ban n'est jamais raccourci ; expressions régulières bornées (ReDoS) ; corps JSON/PUT/PATCH analysés ; 1 requête SQL par visite.
* Analytics : collecte publique durcie (objets validés, plafonds, Origin), conversions déclarées vs confirmées, envoi par lots, agrégation incrémentale avec rattrapage, modes de confidentialité.
* SEO : présence Google via Serper par correspondance exacte d'URL avec cache et gestion du quota ; PageSpeed via en-tête et cache ; lisibilité, robots.txt, contenu mixte et sitemap corrigés ; audit transverse en mémoire bornée.
* Intégrité des fichiers : racine et tout wp-content couverts, stockage en table, vérification des mises à jour par empreintes wordpress.org, actions sensibles tracées.
* IndexNow : jamais en préproduction ni pour les contenus noindex ; clé créée seulement module actif ; envoi différé.
* IA : compteur de jetons séparé (plus d'écrasement des réglages), plafond d'appels, URL Ollama protégée.
* Réglages : clés API jamais réaffichées ni exportées ; environ 35 réglages sans effet retirés ; migration automatique.

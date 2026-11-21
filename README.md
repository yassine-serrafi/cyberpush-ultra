# CyberPush Ultra

**Audience, sécurité, vulnérabilités, connexions, anti-spam et SEO pour WordPress — dans une seule extension libre, hébergée chez vous, sans compte ni service externe obligatoire.**

![Version](https://img.shields.io/badge/version-1.3.4-3858e9)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-GPL--2.0--or--later-green)
![Open source](https://img.shields.io/badge/open%20source-100%25%20gratuit-success)
![Interface](https://img.shields.io/badge/interface-fran%C3%A7ais-blue)

---

## Pourquoi j'ai rendu CyberPush Ultra open source

J'ai décidé de publier CyberPush Ultra en open source à cause de la **vague d'attaques visant PHP** de ces derniers mois. PHP fait tourner WordPress, donc des millions de sites, dont beaucoup sont tenus par des indépendants, des associations et des petites entreprises qui n'ont pas d'équipe de sécurité. Je veux **aider la communauté** avec un outil gratuit, lisible et vérifiable par tous, qui dit clairement à chaque propriétaire de site s'il est exposé et quoi faire.

### Ce qui s'est passé

- **Octobre 2026 — « PHP2Shell » (CVE-2026-109618 et CVE-2026-109619).**
  - **Gravité :** deux failles critiques d'exécution de code à distance, *sans authentification*, notées 9,2 (CVSS 4.0).
  - **Correctifs :** publiés le 21 octobre 2026 dans PHP 8.5.12, 8.4.27, 8.3.36 et 8.2.35.
  - **Exploitation :** l'agence américaine CISA les a ajoutées à son catalogue des vulnérabilités activement exploitées.
  - **Exposition :** plus de 22 millions d'instances exposées et potentiellement vulnérables, selon les mesures publiées.
  - **Mode opératoire :** des attaquants les ont enchaînées avec une faille du noyau Linux pour prendre le contrôle complet de serveurs.
  - *Sources :* [Unit 42 (Palo Alto Networks)](https://unit42.paloaltonetworks.com/cve-2026-109618-cve-2026-109619/), [Centre canadien pour la cybersécurité](https://www.cyber.gc.ca/en/alerts-advisories/al26-026-critical-vulnerabilities-impacting-php-cve-2026-109618-cve-2026-109619), [watchTowr](https://watchtowr.com/intelligence/php-cve-2026-109618-faq/), [Recorded Future](https://www.recordedfuture.com/blog/php2shell-vulnerability-exploitation).
- **19 novembre 2026 — nouvelle faille critique, CVE-2026-115560.**
  - **Correctif :** une nouvelle version de PHP la corrige.
  - **Conditions :** son exploitation dépend de la configuration des sessions, et les sources ne s'accordent pas encore sur une exploitation active.
  - *Source :* [synthèse Aviatrix](https://aviatrix.ai/threat-research-center/php-critical-rce-flaws-cve-2026-115560-php2shell-november-2026/).
- **Depuis 2024 — CVE-2024-4577 (PHP-CGI sous Windows).**
  - **Exploitation :** exploitée en masse quelques jours après sa publication, et toujours utilisée pour installer des rançongiciels et des portes dérobées.
  - *Sources :* [CISA](https://www.cisa.gov/news-events/alerts/2024/06/12/cisa-adds-two-known-exploited-vulnerabilities-catalog), [NVD](https://nvd.nist.gov/vuln/detail/CVE-2024-4577).
- **Et côté WordPress :**
  - **2024 :** Wordfence a recensé **8 233 nouvelles vulnérabilités** dans l'écosystème WordPress.
  - **Premier semestre 2025 :** Patchstack en a compté **environ 6 700**, dont **41,5 % jugées exploitables** dans de vraies attaques, le plus souvent de façon entièrement automatisée.
  - *Sources :* [Wordfence](https://www.wordfence.com/blog/2025/04/wordfence-the-worlds-leading-quality-wordpress-vulnerability-intelligence-provider/), [Patchstack](https://patchstack.com/?p=23258).

### Ce que CyberPush Ultra peut faire pour vous, honnêtement

Une extension WordPress **ne peut pas corriger PHP** : c'est à votre hébergeur (ou à vous, sur votre serveur) de le mettre à jour. Mais elle peut vous **prévenir** et vous **aider à réagir** :

- **Vulnérabilités & versions** :
  - compare chaque jour la version de PHP réellement utilisée par votre site, ainsi que WordPress, vos extensions, vos thèmes et votre base de données, à une base publique de vulnérabilités ;
  - indique la version qui corrige chaque faille ;
  - vous alerte par e-mail quand une faille importante vous concerne.
- **Intégrité des fichiers** : signale tout fichier ajouté ou modifié en dehors des mises à jour WordPress, en priorité un fichier PHP à la racine ou caché dans les médias. C'est la trace typique d'une porte dérobée déposée après une intrusion.
- **Protection des connexions** : vous dit qui se connecte à l'administration, depuis quelle adresse, et vous alerte si un administrateur apparaît sans passer par WordPress.
- **Pare-feu applicatif** : bloque les scanners, les sondes et les tentatives d'exploitation connues, sans jamais pouvoir vous enfermer dehors.

**À faire tout de suite :**
1. Vérifiez votre version de PHP (Firewall › Vulnérabilités & versions, ou demandez à votre hébergeur).
2. Elle doit être au moins **8.2.35, 8.3.36, 8.4.27 ou 8.5.12** dans sa branche.
3. Si votre branche n'est plus maintenue (8.1 ou plus ancienne), demandez à votre hébergeur de passer à une version supportée.

### Pourquoi c'est important que ce soit libre

- **Gratuit, sans version payante cachée, sans compte à créer** : la protection de base d'un site ne devrait pas dépendre d'un abonnement.
- **Code lisible par tous** : chacun peut vérifier ce que l'extension fait de ses données et de celles de ses visiteurs.
- **Amélioré par la communauté** : chaque correction profite à tous les sites qui l'utilisent.

Si CyberPush Ultra vous est utile, partagez-le, signalez les problèmes et proposez des améliorations. C'est comme ça qu'on protège mieux le web, ensemble.

---

## Sommaire

- [Pourquoi j'ai rendu CyberPush Ultra open source](#pourquoi-jai-rendu-cyberpush-ultra-open-source)
- [En un coup d'œil](#en-un-coup-dœil)
- [Ce qui distingue CyberPush Ultra](#ce-qui-distingue-cyberpush-ultra)
- [Modules en détail](#modules-en-détail)
- [Installation, mise à jour, désinstallation](#installation-mise-à-jour-désinstallation)
- [Premiers pas](#premiers-pas)
- [Réglages](#réglages)
- [Cloudflare, proxys et CDN](#cloudflare-proxys-et-cdn)
- [Confidentialité et RGPD](#confidentialité-et-rgpd)
- [Connexions externes](#connexions-externes)
- [Sécurité de l'extension elle-même](#sécurité-de-lextension-elle-même)
- [Performances](#performances)
- [Récupération en cas de blocage](#récupération-en-cas-de-blocage)
- [Pour les développeurs](#pour-les-développeurs)
- [Architecture](#architecture)
- [Limites connues](#limites-connues)
- [FAQ](#faq)
- [Contribuer](#contribuer)
- [Licence et crédits](#licence-et-crédits)

---

## En un coup d'œil

| Module | Ce que vous obtenez |
|---|---|
| **Dashboard** | Un centre de contrôle : points d'attention de tout le site, état de chaque protection, audience, contacts, sécurité, SEO. |
| **Analytics** | Statistiques hébergées chez vous, sans cookie obligatoire : visites, pages, sources, pays (carte du monde), WhatsApp, formulaires, clics sortants, langues, écrans, temps réel, trafic venu de l'IA. |
| **Firewall** | Pare-feu applicatif (WAF), limitation de débit, protection de la connexion, robots vérifiés, Radar, listes IP. |
| **Vulnérabilités & versions** | PHP, WordPress, extensions, thèmes, base de données, serveur web comparés chaque jour aux vulnérabilités publiques connues. |
| **Protection des connexions** | Qui se connecte à l'administration, depuis quelle adresse, nouveaux administrateurs, changements de privilèges. |
| **Journal de sécurité** | Changements sensibles et intégrité des fichiers (cœur, racine, `wp-content`). |
| **Spam** | Commentaires, avis, inscriptions, mots de passe oubliés et Contact Form 7, sans CAPTCHA. |
| **SEO** | Audit technique et de contenu sans conflit avec les extensions SEO, PageSpeed, indexation. |
| **IndexNow** | Signalement automatique des nouveaux contenus aux moteurs de recherche. |

> Les données restent sur votre serveur. Aucune adresse IP de visiteur n'est transmise à un tiers. Aucune clé d'API n'est nécessaire pour les fonctions principales.

---

## Ce qui distingue CyberPush Ultra

Ces points sont rarement réunis — souvent même absents — dans les extensions WordPress courantes de statistiques ou de sécurité.

### Un seul centre de contrôle pour tout le site
La page **Dashboard** regroupe les *points d'attention* de tous les modules : extension vulnérable à mettre à jour, nouvel administrateur, connexion inhabituelle, PHP en fin de support, erreurs SEO… Elle affiche aussi l'état de chaque protection, l'audience comparée à la période précédente, les contacts, les attaques bloquées et la santé SEO. Chaque carte mène au détail.

### Le suivi WhatsApp le plus complet
Chaque clic vers WhatsApp est compté avec **le numéro de destination, la page, le bouton (flottant ou dans le contenu), le pays, la source de la visite, le temps passé avant le clic et la profondeur de lecture**.

- **Liens reconnus :** `wa.me`, `api.whatsapp.com`, `web.whatsapp.com/send?phone=`, `whatsapp://`, liens Android `intent://`, groupes, chaînes, catalogues et liens courts.
- **Boutons reconnus :** ceux de Joinchat, Click to Chat, NinjaTeam, QuadLayers et Chaty, même ouverts par script (`window.open`, `location.href`, lien créé à la volée, shadow DOM).
- **Message pré-rempli :** jamais enregistré.

### Le pays des visiteurs sans stocker ni transmettre leur adresse IP
L'adresse est comparée **en mémoire** à une base DB-IP installée sur votre serveur, puis oubliée : seul le code pays est gardé. Cela fonctionne avec ou sans consentement.

Derrière **Cloudflare**, le pays fourni par Cloudflare est utilisé, **y compris quand l'hébergeur ajoute son propre relais** entre Cloudflare et WordPress. Dans ce montage, la plupart des outils affichent le pays du relais (ou aucun) pour tous les visiteurs.

### Un pare-feu conçu pour ne jamais vous enfermer dehors
- **Adresses jamais bannies :** celles de l'infrastructure (Cloudflare, serveur lui-même, proxys déclarés, relais de l'hébergeur). Un blocage ne peut donc pas couper tout le site.
- **En-têtes de transfert :** ils ne sont crus que s'ils viennent d'un relais vérifié. Un autre site du même hébergement mutualisé ne peut pas usurper une adresse.
- **Sortie de secours :** la constante `CU_FIREWALL_DISABLED` rend toujours la main.

### Une veille de vulnérabilités qui refuse les faux positifs
- **Ce qui est comparé :** les versions **réellement installées** (PHP, WordPress, extensions et thèmes actifs *et* inactifs, MariaDB/MySQL, Apache/nginx, curl, ImageMagick), chaque jour, à la base publique **WPVulnerability**, avec les opérateurs exacts des plages de versions.
- **Pas de supposition :** si la source est imprécise, ou si un slug pourrait désigner un autre produit, l'état est **« À vérifier »**, jamais « Vulnérable ».
- **Composants serveur :** ils sont signalés « d'après la version », car l'hébergeur peut avoir rétroporté des correctifs.
- **Cycle de maintenance de PHP :** il vient de **php.net**.
- **Chaîne d'approvisionnement :** les audits d'extensions rachetées et piégées sont pris en compte.

### Qui se connecte à votre administration, et depuis où
Chaque connexion d'un administrateur est enregistrée avec son adresse, son pays et son navigateur, et le plugin indique si l'adresse est **nouvelle pour ce compte**. Les adresses connues sont mémorisées sous forme d'empreinte HMAC, jamais en clair, et une adresse IPv6 est comparée par son préfixe /64.

Le module détecte aussi :
- une **session déjà ouverte réutilisée depuis un autre réseau**, distinguée d'une vraie connexion ;
- une **connexion réussie après plusieurs échecs** ;
- un **administrateur créé directement dans la base de données**, sans passer par WordPress.

Tout est surveillé, rien n'est bloqué automatiquement.

### Un anti-spam sans CAPTCHA qui ne lit pas vos messages
- **Protections :** un champ piège invisible et un horodatage signé (sans JavaScript, compatibles avec le cache), la vérification du domaine de l'adresse e-mail (domaine sans messagerie, Null MX, adresses jetables) et une limite d'envois répétés.
- **Formulaires couverts :** commentaires, avis WooCommerce, inscriptions, demandes de mot de passe et Contact Form 7.
- **Confidentialité :** le contenu des messages n'est **jamais analysé**.
- **Rien n'est perdu :** un commentaire douteux va dans « Indésirables », d'où il reste récupérable.

### Des statistiques honnêtes
- **Conversions :** celles « déclarées » par le navigateur sont distinguées de celles **confirmées** par le serveur (e-mail Contact Form 7 réellement parti, commande WooCommerce payée).
- **Trafic douteux :** robots, centres de données et trafic suspect forment un segment à part ; ils ne sont pas supprimés en silence.
- **Visites anonymes :** elles sont comptées sans être confondues avec les visites suivies.
- **Taux :** un taux sans dénominateur affiche « n/d », jamais un faux 0 %.

### SEO sans conflit
L'audit SEO lit le rendu final des pages (technique, contenu, lisibilité FR/EN, maillage, PageSpeed, présence dans Google) **sans émettre la moindre balise** : aucun conflit avec Yoast, Rank Math ou SEOPress.

### Le trafic venu de l'IA
Visiteurs arrivant de ChatGPT, Perplexity, Claude, Gemini, Copilot, Mistral, DeepSeek, Grok… et passages des robots d'IA qui lisent votre site. Ces robots sont journalisés côté serveur, car ils n'exécutent pas de JavaScript.

---

## Modules en détail

### Dashboard — centre de contrôle
- **Points d'attention :** classés par gravité, avec un lien direct vers la correction.
- **État des 7 protections :** pare-feu, vulnérabilités, connexions, intégrité des fichiers, anti-spam, SEO, IndexNow.
- **Audience sur 7 ou 30 jours :** comparée à la période précédente, avec les valeurs exactes au survol. S'y ajoutent les visiteurs en ligne, la durée, le rebond et la part de trafic humain.
- **Cartes :**
  - contacts & leads, carte des pays, sources et clics sortants ;
  - attaques bloquées sur 14 jours, dernières connexions administrateurs, événements de sécurité récents ;
  - vulnérabilités à corriger, santé SEO, anti-spam, IndexNow et journaux.
- **Fonctionnement :** il n'utilise que les routes déjà employées par les autres pages. Une source indisponible n'affecte que sa carte.

### Analytics
- **Audience :** pages, visites, défilement, durée, rebond, pages d'entrée et de sortie, parcours, fidélité, heures d'arrivée (jour × heure).
- **Contacts & leads :**
  - WhatsApp : numéros, boutons, emplacements, pages, pays, appareils, heures, délai avant le clic ;
  - Contact Form 7 : formulaire vu, commencé, envoyé, champ refusé, spam, échec, abandon ;
  - taux de contact par source.
- **Pays :** carte du monde colorée selon les visites, les clics WhatsApp ou les prises de contact. Le tableau par pays donne les visites, la part, les pages vues, les clics WhatsApp, le taux de contact et la durée moyenne, plus le nombre de visites sans pays.
- **Clics sortants :** liens vers d'autres sites, téléchargements (PDF, ZIP, documents, médias…), appels `tel:`, e-mails `mailto:` (domaine seulement), pages d'où partent les clics.
- **Langues et écrans :** langue du navigateur et résolution, avec le type d'appareil.
- **Autres rapports :** temps réel, sources de trafic, domaines référents, trafic IA, contenus (catégories, étiquettes, auteurs), WooCommerce, erreurs JavaScript des visiteurs, insights automatiques, export CSV.
- **Diagnostic « Vérifier le suivi » :** script présent dans la page servie, voie d'envoi (REST ou admin-ajax), proxy, source du pays, consentement, plafonds anti-abus, WhatsApp, Contact Form 7.

### Firewall
- **Filtrage :** WAF par signatures, limitation de débit, détection des scanners et des sondes de fichiers sensibles, flood 404.
- **Connexion :** verrou temporaire progressif (jamais permanent automatiquement), anti-énumération des comptes.
- **Durcissement :** REST et XML-RPC, blocage des téléversements dangereux.
- **Contrôle des adresses :** listes IP/CIDR IPv4 et IPv6, pays de confiance, Radar passif, vérification des robots des moteurs de recherche.

### Vulnérabilités & versions (dans Firewall)
- **Inventaire réel :** PHP (avec sa branche), WordPress, extensions et thèmes actifs ou non, base de données, serveur web, curl, ImageMagick.
- **États :**
  - *Vulnérabilité connue* ;
  - *Concerné d'après la version* (composants serveur) ;
  - *Fin de support* ;
  - *À vérifier* ;
  - *Non répertorié* (développement sur mesure) ;
  - *Aucune vulnérabilité connue*.
- **Détail de chaque faille :** référence CVE et avis original, description, gravité et score CVSS, exploitation active (catalogue KEV de la CISA), versions concernées, version corrective, dates, action conseillée.
- **Synchronisation :** quotidienne et par lots, avec un verrou contre les exécutions simultanées et un bouton « Vérifier maintenant ».
- **En cas de panne de la source :** le dernier résultat valide est conservé avec sa date, et rien n'est effacé.
- **Alertes :** un e-mail par faille importante (élevée, critique ou exploitée), jamais deux fois pour la même version. L'avis dans l'administration se masque jusqu'à la prochaine nouveauté.

### Protection des connexions (dans Firewall)
- **Indicateurs :** connexions réussies, nouvelles adresses IP ou nouveaux pays, nouveaux administrateurs, événements sensibles, dernière activité administrative.
- **Journal :** filtrable par période, compte, type d'événement et gravité, avec pagination et détail de chaque événement (navigateur, échecs récents, origine de l'action).
- **Historique par compte :** adresses récemment observées (première et dernière fois, nombre de connexions), pays déjà vus, derniers événements.
- **Rapprochement :** toutes les 15 minutes, l'extension vérifie qu'aucun administrateur n'est apparu ou n'a disparu en dehors de WordPress.
- **E-mails :**
  - envoyés par défaut pour une création ou une promotion d'administrateur, un changement de mot de passe ou d'e-mail, et une connexion après des échecs ;
  - en option pour une nouvelle adresse ou un nouveau pays.
- **Conservation :** 30 jours par défaut (réglable).

### Journal de sécurité et intégrité
- **Événements sensibles :** extensions et thèmes installés ou modifiés, administrateurs créés, promus, rétrogradés ou supprimés, mots de passe et e-mails modifiés, mots de passe d'application, options critiques, éditeur de fichiers.
- **Intégrité :** fichiers du cœur comparés aux empreintes officielles de wordpress.org, scan quotidien de la racine et de `wp-content` (extensions, thèmes, must-use, drop-ins, traductions, médias).
- **Alertes :** e-mail pour chaque événement critique, avec un plafond horaire anti-tempête.

### Spam
- **Formulaires couverts :** formulaires natifs WordPress et Contact Form 7.
- **Journal minimal :** ni message, ni adresse complète, ni IP.
- **Outils :** exclusion par formulaire, test d'une adresse e-mail, délai de grâce après installation (pages encore en cache).

### SEO
- **Évaluation :** score global et par catégorie (technique, contenu, balises, préparation à l'IA), problèmes par page, recommandations.
- **Services facultatifs :** PageSpeed Insights, indexation Google via Serper.dev, aide à la rédaction par IA avec votre propre clé.

### IndexNow
Soumission automatique et discrète des contenus indexables à Bing, Yandex, Seznam, Naver, Yep… avec file d'attente, vérification de la clé et historique.

### Logs, réglages et maintenance
Journal système filtrable et exportable, export et import des réglages (sans les clés d'API), auto-diagnostic, installation des tables, nettoyage, guide de politique de confidentialité.

---

## Installation, mise à jour, désinstallation

**Installation**
1. Téléchargez le ZIP de la dernière version (onglet *Releases*).
2. WordPress › Extensions › Ajouter › **Téléverser une extension**, choisissez le ZIP, puis **Activer**.
3. Le menu **CyberPush Ultra** s'ouvre sur le **Dashboard**.

**Prérequis** :
- WordPress 5.8 ou plus (testé jusqu'à 7.1) ;
- PHP 7.4 ou plus (testé en 8.2) — gardez votre PHP à jour, voir plus haut ;
- une base MySQL ou MariaDB prise en charge par WordPress ;
- extensions PHP conseillées : `zlib` (base de pays), `intl` (domaines accentués), `curl`.

**À l'activation :**
- les tables sont créées ;
- la base de pays est téléchargée en arrière-plan ;
- les tâches planifiées sont programmées ;
- les administrateurs existants forment l'état de référence (ils ne sont jamais signalés comme « nouveaux ») ;
- l'adresse de l'administrateur qui active l'extension est mise sur liste blanche.

**Mise à jour** : remplacez l'extension par la nouvelle version. La migration du schéma de base de données s'applique automatiquement à la première requête, sans perte de données (schéma actuel : 1.9.4).

**Désactivation** : les tâches planifiées sont retirées, rien n'est supprimé.

**Désinstallation** : les données ne sont effacées **que si** vous l'avez demandé dans Réglages › Sécurité (« Suppression des données à la désinstallation »). Sur un multisite, chaque site applique son propre choix.

---

## Premiers pas

1. **Dashboard** : lisez les points d'attention.
2. **Firewall › Vulnérabilités & versions › Vérifier maintenant** : premier inventaire de sécurité, avec votre version de PHP.
3. **Analytics › Vérifier le suivi** : confirme que les visites arrivent. Les administrateurs connectés ne sont pas comptés, testez depuis une fenêtre privée.
4. **Réglages › Alertes e-mail** : indiquez une adresse externe au site (Gmail…), qui reste joignable même si le site est compromis.
5. Si le site est derrière Cloudflare ou un relais d'hébergeur et que l'avis « configuration proxy / CDN » apparaît, approuvez le relais d'un clic.

Marqueurs facultatifs dans vos pages :

```html
<a href="#" data-cu-whatsapp="+212600000000">Écrivez-nous</a>   <!-- bouton WhatsApp sans lien direct -->
<form data-cu-conversion> … </form>                              <!-- conversion déclarée -->
<a class="..." data-cu-cta>Demander un devis</a>                  <!-- clic CTA -->
```

Shortcode d'opposition à la mesure d'audience, à placer dans votre politique de confidentialité : `[cyberpush_optout]`.

---

## Réglages

| Onglet | Contenu principal |
|---|---|
| Analytics | Mode de collecte (minimisé, consentement, standard), mesure anonyme, exclusions (rôles, IP, pays), événements suivis (CTA, défilement, formulaires, WooCommerce, WhatsApp, Contact Form 7, clics sortants), source du pays et mise à jour de la base. |
| Firewall | Mode, source de l'adresse IP, proxys de confiance, durées de blocage, protections (connexion, XML-RPC, REST, téléversements, scanners, sondes, flood 404). |
| SEO | Activation de l'audit, clés facultatives (PageSpeed, Serper.dev). |
| IA | Fournisseur, modèle, langue et ton (facultatif, votre propre clé). |
| WordPress | Types de contenu suivis et exclus. |
| Performance | Conservation des données : nettoyage automatique et durée en jours (25 mois au plus). |
| Système | État de la base de données, installation et mise à jour des tables, auto-diagnostic. |
| Sécurité | Journalisation des changements de réglages, suppression des données à la désinstallation. |
| Alertes e-mail | Journal de sécurité, e-mails critiques, adresse de réception, conservation, e-mail de test. |
| Sauvegarde | Export / import des réglages, nettoyage et maintenance. |
| Développeur | Mode débogage. |

Les réglages propres aux nouveaux modules se trouvent directement dans leur section : consultation en ligne et e-mail des vulnérabilités, surveillance des connexions, comptes inclus, e-mail pour une nouvelle adresse, durée de conservation.

---

## Cloudflare, proxys et CDN

| Montage | Comportement |
|---|---|
| Cloudflare → WordPress | Adresse `CF-Connecting-IP`, pays `CF-IPCountry` (connexion vérifiée dans les plages officielles). |
| Cloudflare → relais local de l'hébergeur (nginx, Varnish) → WordPress | Reconnu automatiquement : la chaîne `X-Forwarded-For` est lue jusqu'au nœud Cloudflare. |
| Cloudflare → relais de l'hébergeur (public ou sur l'adresse du serveur), non déclaré | Mesure d'audience correcte dès la première visite d'un administrateur. Le pare-feu ne bannit jamais ce relais et propose de l'**approuver en un clic**. Il n'est pas approuvé d'office, car en hébergement mutualisé d'autres sites partagent cette adresse. |
| Cloudflare « Pseudo IPv4 » | L'adresse IPv6 réelle (`CF-Connecting-IPv6`) remplace la pseudo-adresse. |
| Autres CDN (Sucuri, QUIC.cloud, BunnyCDN, Fastly…) | À déclarer dans Réglages › Firewall › Proxys de confiance. |

**Règle de sécurité :** pour **bloquer**, le pare-feu n'utilise jamais un en-tête qu'un client pourrait forger. La mesure d'audience et l'anti-spam ne lisent les en-têtes de Cloudflare que si la connexion vient de Cloudflare ou d'un relais déjà vu sur une requête d'administrateur : un client qui contacte le serveur en direct ne peut pas choisir l'adresse comptée.

---

## Confidentialité et RGPD

Trois modes de collecte (Réglages › Analytics) :

- **Minimisé** (par défaut) : mesure exemptée de consentement.
  - Aucune adresse IP n'est stockée ni transmise.
  - Les URL sont enregistrées sans paramètres personnels, et le référent est réduit au domaine.
  - Le signal GPC est respecté, et l'opposition passe par `[cyberpush_optout]`.
  - Les données sont conservées 25 mois au plus.
- **Consentement** : suivi continu après accord (WP Consent API, Complianz, CookieYes, Cookie Notice, Google Consent Mode v2, ou `window.cuAnalytics.consent(true)`). Sans accord, la mesure reste anonyme, page par page.
- **Standard** : sans restriction, pour un site hors UE ou dont la base légale est gérée par vous.

**Autres garanties :**
- **Adresses IP stockées :** le journal de sécurité et la protection des connexions conservent l'adresse des **administrateurs**, jamais celle des visiteurs publics. Ces données sont réservées aux administrateurs et ne sont exposées par aucune route publique.
- **Secrets :** aucun mot de passe, cookie ni jeton de session n'est stocké. Seule une empreinte courte du jeton sert à reconnaître une session.
- **Droits des personnes :** export et effacement des données personnelles via les outils de confidentialité de WordPress.
- **Politique de confidentialité :** un texte prêt à adapter est proposé dans Réglages › Confidentialité.

---

## Connexions externes

Aucune requête externe n'est faite pendant la navigation des visiteurs. Seuls WP-Cron et les actions d'un administrateur contactent les destinations suivantes :

| Destination | Contenu envoyé | Fréquence | Désactivable |
|---|---|---|---|
| `download.db-ip.com` (secours `cdn.jsdelivr.net`) | rien (téléchargement de la base de pays) | mensuelle | oui (source du pays) |
| `www.wpvulnerability.net` | nom technique et version de chaque composant | quotidienne | oui |
| `www.php.net` (secours `endoflife.date`) | rien | quotidienne | avec le précédent |
| `raw.githubusercontent.com` | rien (liste des domaines jetables) | hebdomadaire | oui |
| IndexNow, PageSpeed Insights, Serper.dev, fournisseur d'IA | selon le service | à votre demande | non configurés par défaut |

Aucune adresse du site, aucune donnée de visiteur et aucune clé n'est envoyée à WPVulnerability. Toutes ces requêtes passent en HTTPS, sans suivre de redirection vers une adresse interne, avec une taille et une durée bornées.

---

## Sécurité de l'extension elle-même

- **Routes d'administration :** chacune exige la capacité `manage_options` et le nonce `wp_rest`. Chaque action `admin-post` vérifie la capacité et un nonce.
- **Requêtes SQL :** toutes sont préparées (`$wpdb->prepare()`) ou construites à partir de constantes.
- **Affichage :** tout contenu externe (avis de vulnérabilité, noms, messages) est échappé, et les liens sont limités à `http(s)`.
- **Code :** aucun `eval`, aucune exécution de commande système, aucune bibliothèque tierce chargée depuis un CDN.
- **Tolérance aux erreurs :** une erreur de journalisation n'empêche jamais une connexion, un commentaire ou l'envoi d'un formulaire.
- **Accès au code :** chaque dossier contient un `index.php` vide.

**Signaler une faille** : merci de le faire **en privé**, via l'onglet *Security* du dépôt (*Report a vulnerability*), et pas dans une Issue publique.

---

## Performances

- **Script de mesure :** sans dépendance, ses envois sont groupés et partent par `sendBeacon`. Il est exclu des retards et regroupements de JavaScript des optimiseurs (WP Rocket, LiteSpeed, Perfmatters, Cloudflare Rocket Loader, Jetpack Boost).
- **Compteurs anti-abus :** atomiques, une seule requête SQL, avec le cache objet s'il est présent.
- **Rapports :** mis en cache 10 minutes, et chargés à l'affichage de leur section.
- **Tâches lourdes :** vulnérabilités, intégrité et SEO tournent en arrière-plan, par lots, avec un verrou et un budget de temps.
- **Requêtes des visiteurs :** aucune requête externe, aucune écriture inutile.

---

## Récupération en cas de blocage

Ajoutez dans `wp-config.php` :

```php
define( 'CU_FIREWALL_DISABLED', true );
```

Connectez-vous, corrigez la liste de blocage (Firewall › Blocages actifs), puis retirez la ligne.

---

## Pour les développeurs

### API JavaScript (site public)

```js
window.cuAnalytics.consent( true );   // accord « statistiques » donné par votre bandeau
window.cuAnalytics.consent( false );  // retrait
window.cuAnalytics.optOut();          // opposition du visiteur
window.cuAnalytics.optIn();
window.cuAnalytics.level();           // "full", "anon" ou null
window.cuAnalyticsConsent = true;     // à définir avant le chargement du script
```

### Filtres utiles (extrait)

| Filtre | Rôle |
|---|---|
| `cu_trusted_proxy_cidrs` | Ajouter des proxys de confiance (IP/CIDR). |
| `cu_ip_source` | Forcer la source de l'adresse IP (`auto`, `remote_addr`, `x_forwarded_for`, `x_real_ip`, `cf_connecting_ip`). |
| `cu_cloudflare_ip_ranges` | Mettre à jour les plages Cloudflare. |
| `cu_trusted_country_headers` | Accepter l'en-tête pays d'un autre CDN (CloudFront, App Engine, Vercel). |
| `cu_geo_local_database_path` | Utiliser votre propre fichier `.mmdb` (GeoLite2, DB-IP…). |
| `cu_geo_local_db_mirror` | Changer le miroir de secours de la base de pays. |
| `cu_analytics_allowed_hosts` | Domaines supplémentaires considérés comme le site. |
| `cu_analytics_allowed_query_params` | Paramètres d'URL conservés (en plus de `utm_*`). |
| `cu_analytics_consent_cookies` | Reconnaître le cookie d'un autre bandeau de consentement. |
| `cu_analytics_honor_gpc` | Respect du signal Global Privacy Control. |
| `cu_analytics_new_sessions_per_hour` | Plafond anti-abus par adresse. |
| `cu_tracker_heartbeat_seconds` | Signal de présence du temps réel. |
| `cu_spam_min_seconds` | Délai minimal avant un envoi « trop rapide ». |
| `cu_show_proxy_notice` | Masquer l'avis proxy / CDN. |
| `cu_waf_skip` | Exempter une requête du WAF. |
| `cu_firewall_server_ips` | Adresses du serveur à ne jamais bloquer. |

### Actions

- `cu_audit_recorded( $id, $event )` : un événement vient d'être ajouté au journal de sécurité.
- `cu_firewall_request_refused` : une requête vient d'être refusée par le pare-feu.

### API REST

Espace de noms `cyberpush-ultra/v1`. Toutes les routes d'administration exigent `manage_options` et le nonce `wp_rest`.

La route de mesure d'audience `POST /collect` (et son équivalent `admin-ajax.php?action=cu_collect`) est la seule ouverte aux visiteurs. Elle est contrôlée par origine et par débit, et n'expose aucune donnée.

Exemples de routes d'administration : `analytics/summary`, `analytics/countries`, `analytics/contacts`, `analytics/outbound`, `analytics/audience`, `firewall/dashboard`, `firewall/vulns`, `firewall/vulns/sync`, `firewall/logins`, `firewall/logins/account`, `firewall/audit/list`, `spam/status`, `seo/overview`, `indexnow/status`, `logs/summary`.

### Ajouter un adaptateur anti-spam

Le module Spam sépare les décisions (`CU_Spam`) des formulaires (adaptateurs). Pour prendre en charge une autre extension de formulaires, inspirez-vous de `includes/class-cu-spam-cf7.php` ou `includes/class-cu-spam-wp.php`, qui :
- affichent le champ piège et le jeton ;
- appellent `spam_reason()` et `email_decision()` ;
- journalisent l'issue avec `finish()`.

---

## Architecture

```
cyberpush-ultra/
├── cyberpush-ultra.php          Amorçage, constantes, chargement des classes
├── uninstall.php                Désinstallation (tables et options, si demandé)
├── admin/                       Menus, vues (dashboard, analytics, firewall, spam, seo…)
├── assets/                      CSS, JavaScript d'administration, polices (OFL)
├── includes/
│   ├── class-cu-geo.php         Résolution IP fiable, Cloudflare, base de pays locale (lecteur MMDB intégré)
│   ├── class-cu-db.php          Schéma (dbDelta), compteurs atomiques, verrous
│   ├── class-cu-spam-cf7.php    Adaptateur anti-spam Contact Form 7
│   ├── class-cu-spam-wp.php     Adaptateur anti-spam des formulaires natifs
│   └── …                        Réglages, cron, REST commun, SEO, IA, journalisation
├── modules/
│   ├── class-cu-analytics.php   Collecte et rapports d'audience
│   ├── class-cu-firewall.php    Pare-feu applicatif
│   ├── class-cu-audit.php       Journal de sécurité et intégrité des fichiers
│   ├── class-cu-vulns.php       Vulnérabilités & versions
│   ├── class-cu-logins.php      Protection des connexions
│   ├── class-cu-spam.php        Décisions anti-spam
│   ├── class-cu-seo.php         Audit SEO
│   └── class-cu-indexnow.php    IndexNow
└── public/                      Script de mesure (sans dépendance) et point de collecte
```

**Tables** (préfixe `wp_cu_`) : `sessions`, `events`, `contacts`, `content_daily`, `firewall_logs`, `firewall_blocks`, `firewall_whitelist`, `firewall_watch`, `firewall_audit`, `fim`, `logins`, `spam_log`, `seo_pages`, `seo_issues`, `seo_meta`, `seo_links`, `seo_history`, `logs`.

**Tâches planifiées** :
- agrégation horaire ;
- enrichissement réseau ;
- maintenance du pare-feu (toutes les 5 minutes), qui inclut le rapprochement des administrateurs ;
- cycle SEO ;
- scan d'intégrité quotidien ;
- vérification des vulnérabilités quotidienne, par lots ;
- mise à jour de la base de pays ;
- nettoyage quotidien.

**Aucune dépendance Composer ni bibliothèque JavaScript externe** : les graphiques, la carte du monde et le lecteur MMDB sont intégrés.

---

## Limites connues

- **Correctifs serveur :** l'extension ne met jamais à jour PHP, WordPress, vos extensions ou vos thèmes. Elle vous informe ; la mise à jour reste votre décision ou celle de votre hébergeur.
- **Connexions hors standard :** une extension de connexion qui n'utilise pas le processus standard de WordPress (certains SSO) n'apparaît dans « Protection des connexions » qu'au premier accès à l'administration.
- **Pays :** il est indicatif, un VPN, un réseau mobile ou un proxy pouvant le fausser.
- **Vulnérabilités non publiées :** une vulnérabilité absente de la base publique n'est pas détectée. « Aucune vulnérabilité connue » n'est pas une garantie.
- **Journaux :** ils aident au diagnostic mais ne sont pas infalsifiables si le serveur ou la base de données sont compromis.
- **Langue :** l'interface est en français (les traductions sont les bienvenues).

---

## FAQ

**Mon site est-il touché par les failles PHP2Shell ?**
Ouvrez Firewall › Vulnérabilités & versions et cliquez sur « Vérifier maintenant ». Si votre version de PHP est antérieure à 8.2.35, 8.3.36, 8.4.27 ou 8.5.12 dans sa branche, l'extension l'indique avec la version corrective. Votre hébergeur peut avoir appliqué un correctif sans changer le numéro : demandez-lui confirmation.

**Mes visites n'apparaissent pas.**
Les administrateurs connectés sont exclus. Testez en navigation privée, puis utilisez « Vérifier le suivi ». Videz le cache de page (et celui de Cloudflare) après l'installation.

**Les pays sont vides ou faux derrière Cloudflare.**
Utilisez la version 1.3.4 ou plus récente, ouvrez une fois l'administration, et approuvez le relais de votre hébergeur si l'avis « configuration proxy / CDN » apparaît.

**Le plugin peut-il bloquer un vrai visiteur ou m'enfermer dehors ?**
Le pare-feu ne bannit jamais les adresses d'infrastructure, les verrous de connexion sont temporaires, et la constante `CU_FIREWALL_DISABLED` rend toujours la main. Les modules « Vulnérabilités » et « Protection des connexions » ne bloquent jamais rien.

**Je reçois trop d'e-mails.**
Les alertes ont un plafond horaire et ne sont jamais envoyées deux fois pour le même événement. L'e-mail pour une nouvelle adresse IP est désactivé par défaut. Vous pouvez couper chaque type d'alerte dans sa section.

**Est-il compatible avec Yoast / Rank Math / WP Rocket / LiteSpeed Cache / WooCommerce ?**
Oui. L'audit SEO n'émet aucune balise, le script de mesure est exclu des optimiseurs courants, et les avis WooCommerce sont protégés comme des commentaires.

**Faut-il une clé d'API ou un compte ?**
Non. Seules les fonctions facultatives (PageSpeed, Serper.dev, IA) utilisent vos propres clés.

**Est-ce vraiment gratuit ?**
Oui. CyberPush Ultra est libre (GPL), sans version payante, sans fonction bridée, sans publicité.

---

## Contribuer

Les signalements, idées et *Pull Requests* sont les bienvenus. Exemples de contributions utiles :
- **Traductions :** l'interface est aujourd'hui en français.
- **Anti-spam :** de nouveaux adaptateurs pour d'autres extensions de formulaires (WPForms, Gravity Forms, Elementor Forms…).
- **Tests :** des essais sur d'autres hébergeurs, configurations de proxy et versions de PHP.
- **Documentation :** des améliorations et des captures d'écran.

**Règles :**
- Respectez les conventions de code WordPress : tabulations, `esc_*` à l'affichage, `$wpdb->prepare()` pour toute requête, capacité et nonce pour toute action d'administration.
- Toute nouvelle requête externe doit être documentée, désactivable, et ne jamais partir pendant la navigation d'un visiteur.
- Toute fonction de sécurité doit privilégier l'information à l'action automatique, pour ne jamais bloquer un utilisateur légitime.
- Vérifiez la syntaxe avec `php -l` (PHP 7.4 à 8.3) avant d'envoyer.

Les failles de sécurité se signalent **en privé** (onglet *Security* du dépôt).

---

## Licence et crédits

CyberPush Ultra est un logiciel libre distribué sous licence **GNU GPL v2 ou ultérieure**. Vous pouvez l'utiliser, l'étudier, le modifier et le redistribuer.

- **Données de pays :** [IP Geolocation by DB-IP](https://db-ip.com), licence CC BY 4.0.
- **Vulnérabilités :** [WPVulnerability](https://www.wpvulnerability.com/), base publique et gratuite.
- **Cycle de vie de PHP :** [php.net](https://www.php.net/supported-versions.php).
- **Fond de carte du monde :** [jsVectorMap](https://github.com/themustafaomar/jsvectormap), licence MIT (données seules, intégrées).
- **Domaines d'adresses jetables :** [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains), licence CC0.
- **Polices Geist et Sora :** SIL Open Font License (fichiers `OFL-*.txt`).

Merci à toutes celles et ceux qui publient et maintiennent ces données ouvertes : elles rendent la sécurité accessible à tous.

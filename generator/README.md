# Génération du catalogue — PHP CLI

Le code s’exécute dans **ce dépôt de données**, sans dépendance Composer. Aucun
secret ni appel OpenAI n’est ajouté à Flutter. Le catalogue actuellement publié
contient les cinq découvertes existantes, conservées sans lot initial supplémentaire.

## Première mise en route dans GitHub

1. **Settings → Secrets and variables → Actions → Secrets** : créer
   `OPENAI_API_KEY` avec une clé du projet API OpenAI. Ne jamais la committer.
   L’abonnement ChatGPT ne finance pas les appels API : le projet API doit disposer
   de crédits ou d’une facturation active.
2. Dans **Variables**, créer `GENERATION_ENABLED` avec la valeur `true`.
   La valeur absente ou `false` arrête les exécutions payantes, manuelles comprises.
3. **Settings → Pages → Build and deployment → Source** : sélectionner
   **GitHub Actions**, pour permettre le déploiement explicite des données.
   L’URL demeure `https://kevinrembert.github.io/echappees-data/data/index.json`.
4. Dans **Actions → Enrichir le catalogue Échappées → Run workflow**, conserver
   `dry_run=true` pour vérifier le plan sans aucun appel payant.
5. Rejouer avec `dry_run=false` pour produire jusqu’à **10 nouveaux articles**.
   Le même rythme s’applique dès le premier jour, sans objectif initial de 50.
   Les passages manuels et planifiés partagent le quota journalier de 10.
   Les cinq démonstrations déjà publiées sont conservées. L’application récupère
   les nouveaux articles par actualisation, sans nouvelle version mobile.

Si une protection de `main` refuse les commits du bot, adapter les règles du dépôt
pour ce workflow ou passer à une publication par PR ; ne pas désactiver aveuglément
les protections. Les autorisations du workflow sont limitées aux écritures du
catalogue et au déploiement Pages ; aucun jeton personnel entre dépôts n’est requis.

## Comprendre une simulation ou un échec avant génération

En simulation (`dry_run=true`), le job `generate` vérifie le code, les tests, les
données et le plan. Les étapes payantes et le job `pages` sont volontairement
ignorés : aucun nouveau contenu n’est à publier. Le résumé du workflow le précise.

Si l’étape « Vérifier la présence du secret sans l’afficher » échoue, le secret
n’est pas accessible à ce workflow. Dans **le dépôt `echappees-data`**, ouvrir
**Settings → Secrets and variables → Actions → Secrets → New repository secret**.
Le nom doit être exactement `OPENAI_API_KEY`, avec la clé API comme valeur.
Une entrée sous **Variables** ne remplace pas un secret. Un secret d’environnement
n’est accessible que si le job utilise cet environnement ; utiliser ici un secret
de dépôt. Ne jamais envoyer la clé dans le chat ou les logs.

Vérifier aussi que la variable Actions `GENERATION_ENABLED` vaut `true`, puis
utiliser **Run workflow**, avec `dry_run=false`. Cette vérification échoue avant
la réservation du budget et avant tout appel OpenAI. Les avertissements de version
Node.js sont distincts et n’expliquent pas une absence de secret.

## Rythme et arrêt

- Planification : tous les jours à **06:17 UTC**, soit 07:17 à Paris en hiver et
  08:17 en été. GitHub peut retarder ou désactiver une planification inactive.
- `generator/config.json` : rythme **10/jour**, 10 maximum par passage,
  plafond de bibliothèque 1 000, 20 appels OpenAI maximum par passage.
- Pour changer l’heure, modifier le cron de `.github/workflows/generation.yml`.
- Pour arrêter : mettre la variable Actions `GENERATION_ENABLED=false` ou
  `enabled=false` dans la configuration. Un processus déjà commencé doit être
  annulé depuis Actions ; le code vérifie le drapeau au démarrage de l’exécution.
- Les contrôles sont prioritaires : on ne complète pas artificiellement un quota.
  Un passage peut publier moins de 10 articles, voire aucun. Les motifs sont
  journalisés sans clé ni réponse brute OpenAI.

La même exécution GitHub (`GITHUB_RUN_ID`) ne rejoue jamais les appels. Pour lancer
un nouveau passage, utiliser **Run workflow**, plutôt que **Re-run jobs**. Les
réservations en cours occupent leurs places ; une fois un passage terminé, seuls
les articles effectivement publiés comptent dans le quota journalier. Un échec
libère ses places sans effacer ses coûts conservateurs. Les anciennes réservations
interrompues sans bilan restent bloquées par prudence.
Tous les passages partagent le plafond quotidien de 10, sans mode d’amorçage.
Les concurrents sont sérialisés et un conflit Git interrompt l’envoi,
sans force-push et sans écraser les changements d’un utilisateur.

## Sources, photos et contrôles

`topics.json` contient 80 pistes thématiques, pas des articles prêts à publier.
Le pipeline récupère l’introduction française de Wikipédia, résout les redirections
et l’identité Wikidata, puis les métadonnées de la photo libre sur Wikimedia Commons.
Après ces pistes, une découverte bornée de pages Wikipédia propose d’autres sujets ;
les sujets inadaptés sont refusés. Les identités de sujets et les titres normalisés
servent à éviter les doublons entre passages. Les cinq sujets du prototype sont
exclus de la sélection initiale.

Le flux institutionnel NASA est essayé pour les actualités. `recent.json` permet
aussi de proposer une source NASA, ESA ou CNRS avec les champs :

```json
[
  {
    "wiki_title": "Titre documentaire Wikipédia associé",
    "kind": "recent",
    "country": "Monde",
    "source_url": "https://www.nasa.gov/chemin-du-communique/",
    "source_published_at": "2026-10-19"
  }
]
```

Cet exemple de chemin n’est pas une actualité réelle. Une date récente ne suffit
pas : elle doit être confirmée par les métadonnées de publication de la source
primaire, ne pas être future ni dater de plus de 30 jours. La rédaction cite la
date du communiqué et ne la présente pas comme date de l’événement. Une photo
Wikimedia générale est expressément légendée comme illustration. Un flux en panne,
une source trop courte, une date non confirmée ou un sujet anxiogène est écarté ;
le mélange intemporel/actualités dépend donc des sources acceptables disponibles.

Les métadonnées photo doivent préciser auteur, crédit, source et licence prise en
charge : CC BY / CC BY-SA 2.0, 2.5, 3.0 ou 4.0, CC0 ou domaine public. Les restrictions
supplémentaires, schémas, dessins et origines générées identifiables sont refusés.
Les photos sont téléchargées sur `upload.wikimedia.org` ou `thumb.wikimedia.org`, décodées et converties
en JPEG progressif, côté maximal 1 280 pixels, qualité 82. Les URL portent une
empreinte SHA-256 et sont immuables ; les crédits signalent la modification.
Une incertitude de licence entraîne un refus, jamais une attribution inventée.

L’API **Responses** OpenAI utilise des sorties JSON structurées strictes et
`store=false`. Une première réponse rédige 100–150 mots français à partir des
extraits ; une seconde passe contrôle les faits explicitement soutenus, le français,
la positivité, les répétitions et la légende documentaire. Les crédits sont issus
des métadonnées, pas du modèle. Ensuite, le validateur PHP vérifie le contrat partagé
avec Flutter. Les archives ne sont pas supprimées. Les images et le lot immuable
sont écrits avant l’index atomique ; le commit Git les publie ensemble.

**Limite éditoriale :** une seconde passe de modèle n’est pas une vérification
humaine indépendante des faits ni des droits. Des erreurs et doublons sémantiques
peuvent subsister. Les audits `generator/state/audit-*.json` conservent sources,
licences et empreintes pour permettre une revue ; ils n’attestent pas une revue
humaine. Examiner les premiers lots et leurs photos avant une diffusion élargie.

## Budget choisi : 5 USD/mois, 1 USD/passage

Le modèle configuré est `gpt-4.1-mini`. Les tarifs de calcul sont explicitement
configurés : 0,40 USD/million de tokens d’entrée et 1,60 USD/million de tokens de
sortie. **Vérifier les tarifs actuels sur https://openai.com/api/pricing/** et mettre
à jour ces valeurs lors d’un changement de modèle ou de prix.

Avant les appels payants, le workflow réserve leur coût maximal estimé dans
`generator/state/budget.json`, puis pousse cette réservation. La limite tient
compte des réservations non terminées du mois UTC. Avec les limites initiales,
10 candidats et 20 appels représentent au maximum estimé environ **0,282 USD**.
Les compteurs d’usage retournés par l’API permettent de libérer la différence.
Une erreur réseau de POST n’est jamais rejouée : son coût maximal reste compté.
Les GET de sources ont au plus trois tentatives et des délais bornés.

Une interruption brutale conserve la réservation déjà poussée. Ne pas supprimer
le registre pour contourner un plafond ; contrôler la consommation dans le tableau
de bord OpenAI avant tout rapprochement manuel. Ces limites locales dépendent des
tarifs renseignés et de l’usage renvoyé par l’API ; elles ne constituent pas un plafond
de facturation garanti par le fournisseur. Configurer aussi les alertes et limites
d’usage du projet OpenAI. Les refus de contenu consomment eux aussi des tokens.

## Commandes locales sans dépense

PHP **8.4+**, extensions `curl`, `mbstring`, `dom`, `simplexml`, `gd` :

```sh
php generator/cli.php validate
php generator/cli.php plan
php generator/cli.php probe # Sources publiques uniquement, aucun appel OpenAI
php generator/tests/run.php
find generator -name '*.php' -print0 | xargs -0 -n1 php -l
```

Le plan ne fait aucun appel réseau et ne crée aucune réservation. Le diagnostic
`probe` vérifie au plus trois sources documentaires publiques ; il ne rédige rien
et n’utilise aucune clé API. Les tests utilisent
un transport factice, un jeton explicitement factice et des dossiers temporaires ;
leurs articles ne sont jamais copiés dans le catalogue public. Un scénario vérifie
le cycle collecte → rédaction → relecture → compression photo → lot → index → audit.
D’autres couvrent les refus, les doublons, les plafonds, les reprises et les dates.
Les commandes `reserve` et `generate` réelles sont réservées à GitHub Actions.

## Workflows

- `validate.yml` : syntaxe, tests PHP et catalogue, sans secret sur push/PR.
- `generation.yml` : simulation, lancement manuel et rythme quotidien paramétrable.
- `pages.yml` : déploiement de `data/` uniquement ; aucune interface Web.

Un push effectué avec `GITHUB_TOKEN` ne déclenche pas un autre workflow `push`.
Le workflow de génération appelle donc explicitement le workflow Pages réutilisable
après une publication de contenu. Le code PHP, les registres et les secrets ne sont
pas ajoutés à l’artefact Pages.


## Refus de collecte et erreurs API

Les vignettes Commons utilisent aussi `thumb.wikimedia.org`, autorisé explicitement
avec `upload.wikimedia.org`. Les liens de licence CC historiques `http://` ou
`//` sont canonisés en HTTPS uniquement si le domaine et le chemin correspondent
exactement à la licence annoncée. Aucun téléchargement en HTTP n’est autorisé.

Le diagnostic d’erreur indique le service concerné. Pour OpenAI HTTP 429 :
`insufficient_quota` signifie crédits API insuffisants ou plafond du projet atteint ;
`rate_limit_exceeded` signifie limite de débit. Vérifier Billing/Usage du projet
API en cas de quota, ou attendre en cas de débit. Un abonnement ChatGPT n’inclut
pas les appels API. Les codes inconnus et les messages bruts ne sont pas affichés ;
si le code est absent, le message reste prudent (« quota ou débit »).
Aucun POST en erreur n’est automatiquement rejoué.

Une génération échouée ne publie aucun lot : ses places quotidiennes sont libérées,
y compris pour les anciens registres `failed`. Les coûts incertains et les registres
sont conservés. Les passages encore `reserved`/`running` gardent leurs places pour
éviter un dépassement en cas d’interruption. Ne pas effacer un registre pour relancer.

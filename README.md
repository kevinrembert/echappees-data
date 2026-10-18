# Données Échappées

Catalogue JSON statique pour l’application Flutter Android/iOS Échappées.

Index public : https://kevinrembert.github.io/echappees-data/data/index.json

## Contenu

Cinq découvertes de démonstration : Ainhoa, Porto, Machu Picchu, récifs coralliens
et glaciers, réparties en trois lots. Les sources, crédits, licences et liens
photographiques sont conservés dans chaque article. Les photographies sont
hébergées ici dans `data/images/`. Le catalogue utilise les versions mobiles
`*.v1.jpg` (côté maximal de 1 280 pixels, JPEG progressif qualité 82).
Les originaux restent disponibles ; les crédits, modifications et licences sont
documentés dans `data/IMAGES.md`, `data/PHOTO_CREDITS.md` et `data/licenses/`.

`data/index.json` référence les fichiers versionnés de `data/batches/`.
Les schémas sont dans `data/schema/`. Aucun générateur, appel OpenAI ou frontend
Web n’est ajouté. La bibliothèque de 50 articles relève d’une phase ultérieure.

## Validation et publication

Depuis le dépôt de l’application :

```sh
dart run tool/validate_catalog.dart /chemin/vers/echappees-data/data/index.json
```

GitHub Pages publie la branche `main` depuis la racine du dépôt.
Pour modifier un lot, créer un nouveau fichier versionné, conserver les archives,
puis actualiser sa version, son chemin et son nombre d’articles dans l’index.
Publier les lots avant l’index. Les nouveaux articles sont récupérés par
actualisation de l’application, sans nouvelle version mobile.

Contrat et guide :
https://github.com/kevinrembert/echappees/blob/main/docs/CATALOGUE.md

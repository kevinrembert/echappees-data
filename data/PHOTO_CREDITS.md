# Photographies et typographies du prototype

Les cinq illustrations sont des **photographies réelles**, embarquées pour que
l’application fonctionne hors connexion. Aucun visuel n’est généré par IA.
Les fichiers embarqués sont repris sans modification ; leur présentation utilise
un cadrage `BoxFit.cover`. Le catalogue distant utilise désormais des versions
mobiles hébergées dans `echappees-data/data/images/*.v1.jpg` : proportions
conservées, côté maximal de 1 280 pixels et compression JPEG progressive qualité 82.
Ces traitements sont signalés dans les crédits des lots version 2. Les originaux,
les licences et les sources sont conservés ; la version mobile d’Ainhoa reste
sous CC BY-SA 2.0. Voir les notices `data/IMAGES.md`, `data/PHOTO_CREDITS.md` et
`data/licenses/` du dépôt de données.

## Ainhoa — patrimoine basque

- Fichier : `assets/images/ainhoa.jpg`.
- Sujet réel : maison labourdine et église à Ainhoa, au Pays basque.
- Photographe indiqué dans la description de la source : **Raj Alaya / Hans Lucas**.
- Compte auteur et source : **Office de Tourisme Pays Basque**.
- Date de prise de vue indiquée : 8 novembre 2023.
- Licence : **Creative Commons Attribution - Partage dans les mêmes conditions
  2.0 (CC BY-SA 2.0)**.
- Licence intégrale : <https://creativecommons.org/licenses/by-sa/2.0/legalcode>.
- Page vérifiée : <https://commons.wikimedia.org/wiki/File:AINHOA_-_PAYS_BASQUE02.jpg>.
- Source originale indiquée par Wikimedia :
  <https://www.flickr.com/photos/196653707@N08/53516720278/>.
- Fichier original téléchargé :
  <https://upload.wikimedia.org/wikipedia/commons/9/9c/AINHOA_-_PAYS_BASQUE02.jpg>.
- Métadonnées de provenance conservées dans `docs/ainhoa-photo-source.json`.

Le crédit, la licence et la source sont visibles dans « Sources et crédits ».
La notice est aussi conservée dans `assets/licenses/Ainhoa-CC-BY-SA-2.0.txt` et
consultable dans l’écran des licences. La photo reste distribuée sous CC BY-SA
2.0 ; cette licence ne s’étend pas au code de l’application ni aux autres images.

## Quatre photographies de la collection Flutter Gallery

Les autres photos proviennent de la distribution `flutter_gallery_assets`
**1.0.2**, publiée par l’équipe Flutter :

- Collection : <https://pub.dev/packages/flutter_gallery_assets/versions/1.0.2>.
- Archive source : <https://pub.dev/api/archives/flutter_gallery_assets-1.0.2.tar.gz>.
- SHA-256 vérifié contre les métadonnées pub.dev :
  `f8fecfeebcfbe80a2fabc00c0834046890abe681f59d3e2c5b1028faf887d94b`.
- Mention de droits fournie par cette distribution : **Copyright 2018 The Chromium
  Authors. All rights reserved.** Licence BSD à 3 clauses.
- Le distributeur ne précise pas les photographes individuels dans cette archive.
  L’application attribue la collection et le titulaire indiqué, sans inventer de
  noms d’auteurs.
- La licence source est conservée dans
  `assets/licenses/Flutter-Gallery-LICENSE.txt` et accessible dans l’application.

| Fichier embarqué | Chemin dans l’archive source | Lieu photographié / usage |
| --- | --- | --- |
| `assets/images/porto.jpg` | `lib/crane/destinations/3.0x/sleep_7.jpg` | Façades à Porto, Portugal. |
| `assets/images/machu-picchu.jpg` | `lib/crane/destinations/3.0x/fly_3.jpg` | Machu Picchu, Pérou. |
| `assets/images/coral-island.jpg` | `lib/crane/destinations/3.0x/fly_4.jpg` | Lagon et bungalows des Maldives, illustration du milieu tropical ; ce n’est pas une vue sous-marine des coraux. |
| `assets/images/khumbu.jpg` | `lib/crane/destinations/3.0x/fly_2.jpg` | Vallée du Khumbu, Népal, illustration du sujet général sur les glaciers ; ce n’est pas une photographie d’un prélèvement de carotte. |

Les lieux associés à ces fichiers sont ceux du catalogue Crane de Flutter Gallery,
révision `d12640d4b08ee6e706fd8c64a83fba0620c9aa8d` :
<https://github.com/flutter/gallery/blob/d12640d4b08ee6e706fd8c64a83fba0620c9aa8d/lib/l10n/intl_en.arb>.
Les légendes distinguent le lieu de l’article et celui de l’illustration.

## Textes et sources documentaires

Les articles sont des textes originaux de démonstration, sur des faits intemporels,
avec des références accessibles via « Sources et crédits ». Ils ne prétendent pas
relater des événements récents. La géographie, le patrimoine et les maisons
labourdines d’Ainhoa ont été recoupés avec la page Wikipédia citée et la photo
Wikimedia. Certaines autres pages citées n’ont pas pu être ouvertes depuis le
réseau de cette instance ; une revue éditoriale reste nécessaire avant publication
de contenus définitifs.

## Polices

Les fichiers Merriweather et Libre Franklin sont extraits de l’archive Flutter
Gallery ci-dessus. Ils sont distribués sous **SIL Open Font License 1.1**. Les
mentions des auteurs et les licences originales sont conservées dans :

- `assets/licenses/Merriweather-OFL.txt` ;
- `assets/licenses/LibreFranklin-OFL.txt`.

Les polices sont locales ; aucun service de polices distant n’est utilisé.

# Présence habituelle — plugin Jeedom

Plugin [Jeedom](https://www.jeedom.com) de **simulation de présence tirée de vos historiques**. Il relève quand vos lumières s'allument
et quand vos volets s'ouvrent, en tire les tendances (au plus tôt, au plus tard, en moyenne), les exceptions et ce qui n'arrive jamais,
puis les rejoue pendant votre absence, pièce par pièce, avec un aléa de chaque jour.

Tout est calculé sur la box : **aucun appel réseau**, aucun service en ligne. La simulation est **à blanc par défaut** : elle décide et
note tout au journal, sans rien envoyer aux équipements, tant que vous n'avez pas coché « Envoyer vraiment aux équipements ».

## Fonctions
- **Guide** en quatre questions : quelles commandes sont des lampes ou des volets, où elles sont, quelles règles suivre (jamais simulée, plages permises ou interdites), ce qui manque encore. Détecteurs de présence de toute marque.
- **Tendances** par lampe ou par pièce, en semaine et le week-end : horaires, durées, scènes (lampes allumées ensemble), exceptions ; pour les volets, heures d'ouverture et de fermeture. Les absences et les périodes de simulation sont exclues.
- **Plan de la maison** (facultatif) : un plan par étage, pièces, passages et escaliers, zones à exclure ; reprise des positions d'un design Jeedom.
- **Simulation** pièce par pièce, d'après ses tendances ou des horaires provisoires de son type de pièce : seulement à la pénombre (capteur de luminosité ou soleil), au plus 3 pièces à la fois, volets, pièce laissée tranquille si quelqu'un y touche. Commandes **Simulation On** / **Off** pour un scénario d'alarme ou d'absence.
- **Calage** (facultatif) : la simulation rejouée en mémoire sur les 28 derniers jours, comparée au réel pièce par pièce et corrigée jusqu'à entrer dans les tolérances ; un contrôle chaque nuit signale les **habitudes changées**.
- **Widget** : état de la simulation, commandes On / Off, Recalculer.

## Installation
Jeedom 4.4 ou plus récent (Debian 11 ou 12). Installer depuis GitHub : activer la source GitHub dans la configuration des mises à jour,
puis Plugins → Gestion des plugins → ＋ → GitHub : `flab75`, `plugin-presencehabituelle`, branche `master` (`beta` pour la version en cours).
Activer le plugin, puis suivre l'onglet **Guide**. Il faut une dizaine de jours de relevés par pièce avant que la simulation suive vos tendances ;
l'historique de Jeedom des 90 derniers jours est repris quand les commandes sont historisées.

## Documentation
- [Notice](docs/fr_FR/index.md) · [Changelog](docs/fr_FR/changelog.md)
- English: [documentation](docs/en_US/index.md) · [changelog](docs/en_US/changelog.md)

## Confidentialité
Les habitudes relevées disent quand la maison est vide : elles restent sur la box (base de Jeedom et `data/`, inaccessibles depuis le Web),
visibles des seuls administrateurs. Le bouton **Tout effacer** supprime les relevés, les tendances et le calage. Voir la [notice](docs/fr_FR/index.md#confidentialité).

## Licence
[MIT](LICENSE).

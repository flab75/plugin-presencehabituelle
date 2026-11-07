# Présence habituelle

Simulation de présence **tirée de vos historiques**. Le plugin relève quand vos lumières s'allument et quand vos volets s'ouvrent, en tire les tendances (au plus tôt, au plus tard, en moyenne), les exceptions et ce qui n'arrive jamais, puis (dans les versions suivantes) les rejoue pendant votre absence, avec des variations réglables.

## Confidentialité

Tout est calculé sur la box. Le plugin ne fait **aucun appel réseau** (le contrôle automatique `tests/selftest.php` le vérifie), n'utilise aucun service en ligne ni aucune « IA ». Les changements d'état sont gardés dans une table de la base de Jeedom (`presencehabituelle_event`) et les tendances calculées dans `data/habitudes.json`, le plan dans `data/plan.json` et ses images dans `data/plan_*.jpg` (inaccessibles depuis le Web). Seuls les administrateurs les voient. Comme le reste de la base, elles font partie des **sauvegardes de Jeedom**, y compris une sauvegarde envoyée dans le cloud si vous en avez réglé une. Le bouton **Tout effacer** (onglet Tendances) supprime les relevés et les tendances (le plan, lui, s'efface en supprimant ses étages) ; elles sont aussi effacées quand le plugin est réinstallé après une désinstallation. **Avant de désinstaller, cliquez Tout effacer** : la désinstallation seule laisse la table en base.

## Guide (quatre questions)

Le plugin ne sait rien de votre maison. L'onglet **Guide** lit vos équipements et leurs historiques, puis vous pose quatre questions ; vos réponses remplissent les « Commandes suivies » et préparent la future simulation.

1. **Est-ce une lampe ou un volet de la maison ?** Pour chaque lumière, volet, prise (une par équipement) ou commande signalée par la découverte : **Oui, à suivre**, **Non** (une prise de frigo, une LED d'état), **Doublon de…** (la même lampe vue par une autre commande : celle choisie est gardée) ou **Groupe de lampes** (une commande qui allume plusieurs lampes déjà listées). **Identifier en allumant** affiche ce qui change pendant que vous allumez ou éteignez une lampe : si plusieurs commandes changent ensemble (à 5 secondes près), gardez celle qui la représente, les autres deviennent ses doublons.
2. **Où sont-elles ?** (facultatif) Les commandes suivies pas encore placées : un clic ouvre l'onglet Plan pour les placer.
3. **Des règles ?** (facultatif) Par lampe ou volet : **Ne jamais la simuler** (ni pendant l'absence), ou des plages : « peut s'allumer » (seulement dans ces plages) ou « jamais allumée » (jamais dans celles-ci), tous les jours, en semaine (lundi-vendredi) ou le week-end. De 23:00 à 07:00 passe minuit ; de 00:00 à 00:00 = toute la journée.
4. **Pas encore assez de données** : les commandes suivies relevées depuis moins de 10 jours (une commande non historisée ne l'est qu'à partir de l'enregistrement). Attendez, ou donnez-leur une plage « peut s'allumer ».

Le **bilan** dit, pour chaque commande suivie : habitudes (10 jours de relevés ou plus), plages données, jamais simulée, ou à compléter. Quand plus rien n'est à compléter : « Simulation proposable » — rien n'est lancé pour l'instant. **Enregistrer** garde ensemble les réponses, les règles et le choix des commandes ; **Revenir au choix d'avant** les reprend aussi.

## Pour l'instant : analyse seule

Le plugin **n'agit encore sur rien** : il analyse vos historiques et montre ce qu'il en tire.

1. **Commandes suivies** : le plugin propose les commandes d'état d'après leur type générique.
   - Lumières (`LIGHT_STATE`) et volets (`FLAP_STATE`) : cochés.
   - Prises et médias : proposés décochés (une prise peut alimenter un frigo).
   - Sondes et autres : cachées, déconseillées (elles ne disent rien de vos gestes).
   - Une commande sans type générique n'apparaît pas d'office : renseignez son type, ou cochez « Afficher les autres commandes ».
2. **Groupes** : une lampe est analysée seule ; donnez le même groupe à plusieurs lampes pour les analyser ensemble (une pièce), ou « Grouper par objet Jeedom ».
3. **Historique** : à l'enregistrement, les changements d'état des 90 derniers jours sont repris de l'historique de Jeedom (si la commande est historisée). Une commande non historisée est relevée à partir de l'enregistrement. Après quelques heures (2 h par défaut, réglage « archivage »), Jeedom remplace les valeurs numériques par une moyenne par heure : on ne sait plus quand la lampe s'est allumée. Pour une telle commande (marquée « historique lissé »), seules les dernières heures sont reprises ; réglez son lissage sur « Aucun » (configuration de la commande, onglet Historique) pour que tout l'historique serve à l'avenir. Une commande binaire reste exacte. L'état au début de la période (lampe déjà allumée, volet déjà fermé) est repris lui aussi.
   Une commande ajoutée plus tard est reprise de la même façon. Une seule commande d'état par équipement et par type est proposée cochée.
4. **Absences** : une commande binaire ou numérique vraie pendant vos absences (un mode « Absent » en texte ne convient pas : passez par un virtuel binaire) et des périodes saisies à la main. Les jours touchés ne sont pas comptés, sans quoi le plugin tirerait les tendances de la maison vide — ou une ancienne simulation. Son historique est repris sur toute la période (90 jours par défaut) ; une commande numérique lissée est refusée (réglez son lissage sur « Aucun » ou passez par un virtuel binaire). Quand on change de commande d'absence, les absences de l'ancienne sont gardées. Les jours où le plugin était désactivé, ou la box arrêtée plus de 3 heures, ne sont pas comptés non plus (rien n'a été relevé).
5. **Découvrir** : le plugin lit les 30 derniers jours d'historique et fait des propositions, cochées ou non, que vous confirmez. Il ne sait rien de votre maison : il s'appuie sur les types génériques, les noms et ce que montrent les historiques.
   - **Doublons** : deux commandes qui basculent ensemble (à 5 secondes près) au moins 9 fois sur 10 sont une même lampe vue deux fois (par exemple par le plugin du module et par celui d'une passerelle), ou deux lampes toujours commandées ensemble. On garde l'historisée ; l'autre est décochée, marquée « doublon de … ».
   - **Groupes** : une commande allumée seulement quand d'autres le sont, et qui bascule avec elles (un groupe Hue, « Tout le salon »), est décochée et marquée « groupe de … » : ses lampes suffisent.
   - **Lumières sans type générique** : une commande binaire dont le nom ou l'équipement évoque une lampe (lampe, applique, plafonnier, light…) est montrée comme lumière, décochée, « type générique à renseigner ? ».
   - **Jours sans aucune lumière** : des absences possibles, proposées décochées (une journée sans lumière peut aussi être une journée d'été) ; cochez celles où la maison était vide pour les ajouter aux périodes à ne pas analyser.
   - **Historique lissé** : une lampe dont l'historique est lissé (Jeedom n'en garde qu'une moyenne horaire au-delà de quelques heures) ne permet aucune comparaison ; mettez son lissage à « Aucun » ou observez-la 3 jours.
   - **Commande d'absence possible** : une alarme activée ou une commande dont le nom parle d'absence, de vacances ou de simulation (binaire ou numérique non lissée), proposée décochée.
   - **Lumières automatiques ?** : une lampe allumée presque chaque jour à la même heure (à 5 minutes près) ou au coucher du soleil (à 10 minutes près, d'après la position de la box) est seulement signalée : décochez-la vous-même si un programme l'allume.
   - **Sans historique** : rien à comparer. **Observer 3 jours** relève leurs changements pendant 3 jours (sans rien analyser) ; relancez ensuite la découverte. Les relevés sont gardés 7 jours après la fin de l'observation ; ensuite, ceux des commandes que vous n'avez pas cochées sont effacés.

   **Appliquer à la liste** reporte les propositions cochées ; rien n'est enregistré avant **Enregistrer**. **Revenir au choix d'avant** recharge dans la liste le choix d'avant le dernier enregistrement (commandes, groupes, commande d'absence, périodes) ; enregistrez pour y revenir. Avant d'enregistrer un choix qui retire des commandes, le plugin demande confirmation : les relevés d'une commande retirée sont effacés et ne reviennent pas avec le choix d'avant ; seul l'historique de Jeedom est repris (90 jours en général, quelques heures si l'historique est lissé).

   Les périodes où le plugin lui-même simulera la présence seront exclues d'office, comme ses arrêts : il ne tirera pas ses tendances de sa propre simulation.

L'onglet **Tendances** montre, pour chaque lampe ou groupe :
- le pourcentage des jours où elle est allumée dans chaque heure, en semaine et le week-end ;
- **ce qui n'arrive jamais** : les heures où elle n'a jamais été allumée ;
- **les exceptions** : les jours où elle l'a été à une heure où elle l'est au plus 10 % des jours ;
- la durée d'un allumage : moyenne, médiane, la moitié des allumages entre deux valeurs, la plus courte et la plus longue, la part des passages de moins de 3 minutes, et les durées inhabituelles ;
- les lampes allumées ensemble.

Une ligne (semaine ou week-end) tirée de moins de 10 jours est marquée « à confirmer ».

Un allumage de plus de 18 heures (réglable) est pris pour une extinction manquée (box arrêtée, pont hors ligne) : il est écarté, et les jours qu'il touche ne comptent pas pour son groupe (ou la lampe seule).

Pour chaque volet, en semaine et le week-end : l'heure d'ouverture du matin et de fermeture du soir (médiane, moyenne, au plus tôt, au plus tard), la part des nuits où il est fermé (« jamais » ou « toujours » le cas échéant) et les exceptions. Une exception est un jour loin de la moitié centrale des valeurs : de plus de 30 minutes et de plus d'une fois et demie l'étendue de cette moitié (le plus grand des deux, règle de Tukey) ; il en faut au moins 4 pour en parler. Une heure tirée de moins de 10 valeurs, ou une part des nuits tirée de moins de 10 nuits, est marquée « à confirmer », séparément en semaine et le week-end. Une ouverture du matin n'est relevée que si le volet était fermé à 04 h : le rouvrir après l'avoir fermé contre la chaleur n'est pas un lever. Une nuit (16 h → 04 h) touchée par une absence n'est pas comptée.

La journée va de 04 h à 04 h, pour les lumières comme pour les volets. Le week-end, ce sont les matins (04 h-16 h) du samedi et du dimanche et les soirs (16 h-04 h) du vendredi et du samedi : une lampe allumée le dimanche à 22 h compte en semaine, comme une heure entre 0 h et 4 h compte pour le soir de la veille.

Le calcul est refait chaque nuit ; les changements de plus de 90 jours sont effacés (réglable dans la configuration du plugin), pour suivre la saison.

## Plan de la maison (facultatif)

L'onglet **Plan** dessine la maison, un plan par étage :

1. **＋ Étage** : nommez-le (« Rez-de-chaussée », « Étage »…) ; les flèches changent l'ordre. **Image…** dépose le plan de l'étage : une photo d'un plan papier, une capture, un export de logiciel (PNG, JPEG…). Elle est réduite à 2 000 pixels et gardée sur la box ; sans image, on dessine sur une grille.
2. **Placer** : cliquez une lumière ou un volet de « Pas sur le plan », puis l'endroit du plan. Glissez une pastille pour la déplacer ; l'outil **Supprimer** la renvoie dans la liste. Si vous avez déjà un design Jeedom où vos lumières sont placées, **Reprendre les positions d'un design** les reprend (centre de chaque widget, en proportion du design : prenez comme image de l'étage le fond de ce design).
3. **Pièce** : cliquez les coins, puis « Terminer la forme », et nommez-la. Les lampes d'une pièce sont analysées ensemble, comme un groupe ; un groupe saisi dans « Commandes suivies » reste prioritaire. Deux pièces de même nom forment un seul groupe, distinct d'un groupe saisi de même nom.
4. **Passage** : cliquez les deux bouts (une porte, un couloir, le haut d'un escalier), puis choisissez les deux pièces reliées et le type ; pour un escalier, la seconde pièce peut être sur un autre étage. Le plugin compte les **enchaînements** : les fois où une lumière de la seconde pièce s'allume moins de 3 minutes après l'extinction d'une lumière de la première (et dans l'autre sens). Ils serviront au parcours de la simulation.
5. **Zone à exclure** : une partie de la maison qu'on ne veut ni analyser ni simuler (une chambre d'amis, un logement loué) : les commandes placées dedans sont ignorées.

**Enregistrer le plan** garde le tout et recalcule les tendances. Chaque pastille montre la tendance à l'heure choisie (« Tendance à », en semaine ou le week-end) : plus elle est pleine, plus la lampe est souvent allumée à cette heure ; pour un volet, pleine s'il est d'habitude ouvert. Survolez-la pour le détail.

## Capteur de luminosité

Le plugin fonctionnera sans capteur de luminosité, d'après la hauteur du soleil. Un capteur extérieur est cependant **un vrai plus** : par jour gris, on allume plus tôt et en pleine journée, ce que la hauteur du soleil ne voit pas. (Prévu dans une prochaine version.)

# Présence habituelle

Simulation de présence **tirée de vos historiques**. Le plugin relève quand vos lumières s'allument et quand vos volets s'ouvrent, en tire les tendances (au plus tôt, au plus tard, en moyenne), les exceptions et ce qui n'arrive jamais, puis (dans les versions suivantes) les rejoue pendant votre absence, avec des variations réglables.

## Confidentialité

Tout est calculé sur la box. Le plugin ne fait **aucun appel réseau** (le contrôle automatique `tests/selftest.php` le vérifie), n'utilise aucun service en ligne ni aucune « IA ». Les changements d'état sont gardés dans une table de la base de Jeedom (`presencehabituelle_event`) et les tendances calculées dans `data/habitudes.json` (inaccessible depuis le Web). Seuls les administrateurs les voient. Comme le reste de la base, elles font partie des **sauvegardes de Jeedom**, y compris une sauvegarde envoyée dans le cloud si vous en avez réglé une. Le bouton **Tout effacer** (onglet Tendances) les supprime ; elles sont aussi effacées quand le plugin est réinstallé après une désinstallation.

## Pour l'instant : analyse seule

Le plugin **n'agit encore sur rien** : il analyse vos historiques et montre ce qu'il en tire.

1. **Commandes suivies** : le plugin propose les commandes d'état d'après leur type générique.
   - Lumières (`LIGHT_STATE`) et volets (`FLAP_STATE`) : cochés.
   - Prises et médias : proposés décochés (une prise peut alimenter un frigo).
   - Sondes et autres : cachées, déconseillées (elles ne disent rien de vos gestes).
   - Une commande sans type générique n'apparaît pas d'office : renseignez son type, ou cochez « Afficher les autres commandes ».
2. **Groupes** : une lampe est analysée seule ; donnez le même groupe à plusieurs lampes pour les analyser ensemble (une pièce), ou « Grouper par objet Jeedom ».
3. **Historique** : à l'enregistrement, les changements d'état des 90 derniers jours sont repris de l'historique de Jeedom (si la commande est historisée). Une commande non historisée est relevée à partir de l'enregistrement. Après quelques heures (2 h par défaut, réglage « archivage »), Jeedom remplace les valeurs numériques par une moyenne par heure : on ne sait plus quand la lampe s'est allumée. Pour une telle commande (marquée « historique lissé »), seules les dernières heures sont reprises ; réglez son lissage sur « Aucun » (configuration de la commande, onglet Historique) pour que tout l'historique serve à l'avenir. Une commande binaire reste exacte.
   Une commande ajoutée plus tard est reprise de la même façon. Une seule commande d'état par équipement et par type est proposée cochée.
4. **Absences** : une commande vraie pendant vos absences (un mode « Absent ») et des périodes saisies à la main. Les jours touchés ne sont pas comptés, sans quoi le plugin tirerait les tendances de la maison vide — ou une ancienne simulation.

L'onglet **Tendances** montre, pour chaque lampe ou groupe :
- le pourcentage des jours où elle est allumée dans chaque heure, en semaine et le week-end ;
- **ce qui n'arrive jamais** : les heures où elle n'a jamais été allumée ;
- **les exceptions** : les jours où elle l'a été à une heure où elle l'est au plus 10 % des jours ;
- la durée d'un allumage : moyenne, médiane, la moitié des allumages entre deux valeurs, la plus courte et la plus longue, la part des passages de moins de 3 minutes, et les durées inhabituelles ;
- les lampes allumées ensemble.

Un allumage de plus de 18 heures (réglable) est pris pour une extinction manquée (box arrêtée, pont hors ligne) : il est écarté, et les jours qu'il touche ne comptent pas pour cette lampe.

Pour chaque volet, en semaine et le week-end : l'heure d'ouverture du matin et de fermeture du soir (médiane, moyenne, au plus tôt, au plus tard), la part des nuits où il est fermé (« jamais » ou « toujours » le cas échéant) et les exceptions. Une exception est un jour loin de la moitié centrale des valeurs : de plus de 30 minutes, ou de plus d'une fois et demie l'étendue de cette moitié (règle de Tukey) ; il en faut au moins 4 pour en parler. Une tendance calculée sur moins de 10 jours est marquée « à confirmer ».

Le calcul est refait chaque nuit ; les changements de plus de 90 jours sont effacés (réglable dans la configuration du plugin), pour suivre la saison.

## Capteur de luminosité

Le plugin fonctionnera sans capteur de luminosité, d'après la hauteur du soleil. Un capteur extérieur est cependant **un vrai plus** : par jour gris, on allume plus tôt et en pleine journée, ce que la hauteur du soleil ne voit pas. (Prévu dans une prochaine version.)

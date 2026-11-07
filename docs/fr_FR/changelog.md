# Changelog

## 0.7
- **Découvrir** : propositions tirées des 30 derniers jours, à confirmer : doublons (même lampe vue par deux commandes), groupes (type groupe Hue), lumières sans type générique d'après leur nom, jours sans aucune lumière (absences possibles), commande d'absence possible, lumières allumées à heure fixe ou au coucher du soleil (signalées seulement) ; « Observer 3 jours » pour les commandes sans historique.
- **Revenir au choix d'avant** : recharge le choix d'avant le dernier enregistrement.
- Les périodes où le plugin simulera la présence seront exclues de l'analyse.

## 0.6
- Onglet **Plan** : un plan par étage (nom, ordre, image gardée sur la box), lumières et volets placés à la souris (ou repris d'un design Jeedom), pièces qui groupent les lampes qu'elles contiennent, passages entre pièces (porte, couloir, escalier, y compris d'un étage à l'autre) avec les enchaînements relevés, zones à exclure de l'analyse ; les pastilles montrent la tendance à l'heure choisie.
- Corrections après la 6e revue : part des passages éclair et des scènes affichée 100 % sans l'être toujours, lissage qui arrondissait 99 % à 100 %, « à confirmer » absent de la notice pour les lumières.
- Corrections après la 7e revue : image de l'étage absente à l'ouverture de l'onglet Plan, pastilles perdues à l'enregistrement du plan avant celui des commandes, image affichée sur le mauvais étage, images d'étages effacées par un plan illisible, chiffres d'un passage pris pour ceux d'un autre, pièce confondue avec un groupe saisi de même nom, pastille reprise d'un design décalée, « zone exclue » sur les pastilles concernées, 200 coins au plus par forme, données du plan hors du dépôt.
- Corrections après la 8e revue : lampe cochée pas encore plaçable sur le plan avant l'enregistrement, pièce et groupe saisi de même nom affichés sous le même titre (« (pièce) » ajouté), « j » non traduit, deux enregistrements simultanés qui se gênaient, centre d'un widget de largeur en % mal calculé, carte restée sur l'ancien étage si l'image ne se charge pas.

## 0.5.1
- Corrections après la 5e revue : volet rouvert après une fermeture contre la chaleur pris pour une ouverture du matin, « à confirmer » compté en jours au lieu des valeurs relevées, deux équipements de même nom dans deux pièces confondus (la pièce les départage), 100 % ou 0 % affichés alors que ce n'est ni toujours ni jamais, notice inexacte sur la nuit avant une absence ; les lumières suivent la même journée (04 h → 04 h) et le même week-end que les volets.

## 0.5
- Corrections après la 4e revue : soir d'un volet compté alors que le lendemain matin était une absence, « toujours » / « jamais fermé » déduits d'un pourcentage arrondi, durée maximale d'un allumage à 0 prise pour 1 h (18 h désormais), règle des exceptions mal décrite, « à confirmer » donné pour toute la ligne au lieu de la semaine ou du week-end, bouton Enregistrer actif avant le chargement de la liste.

## 0.4
- Corrections après la 3e revue : heures des volets fausses d'une heure les jours de changement d'heure, volet sans nuit observée affiché « jamais fermé », heures « jamais allumées » déduites d'un pourcentage arrondi, présélection des commandes réappliquée après un enregistrement vide, état d'avant la période reprise oublié (une lampe allumée la veille n'était pas vue), choix des commandes illisible enregistré comme « rien ».
- Les heures où la box était arrêtée (plus de 3 h) ne sont pas comptées ; les absences de l'ancienne commande d'absence sont gardées quand on en change ; une commande d'absence numérique lissée est refusée.

## 0.3
- Corrections après la 2e revue : dernière nuit d'un volet comptée avant d'être finie (« jamais fermé » à tort), jours où le plugin était désactivé comptés comme une maison éteinte, commande d'absence en texte refusée (elle n'excluait rien) et historique d'absence repris en entier, deux allumages dans la même minute confondus, fenêtre d'historique exact élargie à tort, groupe nommé « #12 » confondu avec la lampe n° 12.

## 0.2
- Corrections après revue : la liste des commandes suivies n'était pas relue correctement (rien n'était analysé), désactiver le plugin ne vide plus la table, historique lissé repris seulement sur ses dernières heures (et signalé), allumages anormalement longs écartés, période saisie illisible refusée, commande d'absence vérifiée, une commande ajoutée plus tard est reprise de l'historique, bouton « Tout effacer », tendances gardées dans un fichier.
- Vocabulaire : le plugin n’« apprend » pas, il tire de vos historiques les tendances. Ajouts : au plus tôt, au plus tard, moyenne, plus courte et plus longue durée, heures où une lampe n'est jamais allumée, volet jamais ou toujours fermé la nuit, exceptions (heures rares, jours loin de la moitié centrale).

## 0.1
- Première version : analyse seule. Choix des lumières et volets suivis (préconisation par type générique), reprise de l'historique de Jeedom, profils heure par heure (semaine / week-end), durées, lampes allumées ensemble, heures des volets, périodes d'absence exclues. Rien n'est envoyé hors de la box ; aucune action sur les équipements.

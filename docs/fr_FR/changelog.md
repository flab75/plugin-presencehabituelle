# Changelog

## 0.19
- Bouton **Rapport de test** : un texte à coller sur le forum pour les essais (versions, sortes de commandes suivies et commandes d'action trouvées, calage), sans nom d'équipement ni heure. Rien n'est envoyé.
- Textes de la page à jour : la simulation existe (à blanc par défaut), le nombre de pièces allumées en même temps est relevé dans vos habitudes (README).

## 0.18
- Durées d'allumage : pour une pièce allumée au moins 20 fois sur les jours du calage, la simulation tire une durée dans toute la distribution relevée sur ces jours (vingt valeurs, longues soirées comprises) au lieu d'un tirage entre le quart et les trois quarts, dont la moyenne tombait près de la médiane : Cuisine et Salons étaient simulés 2 à 3 h/jour trop courts. Au-delà du premier passage du cron (toutes les 5 min), l'attente du passage suivant ne rallonge plus l'allumage, et une durée relevée n'est plus prolongée au hasard en fin de course (le WC restait allumé +40 %). Les lampes oubliées avant les jours du calage ne comptent plus (Entrée RDC : 47 min simulées par allumage, 16 min réelles). Moins de 20 allumages : tirage d'avant.
- Le coefficient de durée du calage s'applique après le tri passage éclair / présence, qui reste celui du réel.
- À la mise à jour, les coefficients de durée appris avec l'ancien tirage sont remis à 1 (une fois) et le calage repart (sa signature change).

## 0.17.2
- Corrections de la 2e revue adverse de la 0.17 : la part des départs suivis d'un trajet ne compte plus que les extinctions de nuit (entre coucher et lever du soleil), comme la simulation qui ne fait de trajet que dans la pénombre (elle était sous-estimée par les extinctions de jour) ; un trajet qui revient dans le groupe de départ n'est plus une suite ; la pièce d'arrivée est vérifiée avant d'allumer les pièces traversées (plus de couloir allumé pour rien) ; le calage ne repart plus quand un groupe gagne ou perd un trajet rare (moins de 5 vus).
- Au déploiement de la 0.17 puis de la 0.17.2, le calage repart une fois (sa signature change), puis seulement quand les trajets d'une pièce apparaissent ou disparaissent.

## 0.17.1
- Corrections de la revue adverse de la 0.17 : une pièce éteinte au même passage n'est plus rallumée aussitôt par un trajet ; un passage lumineux ne prend plus de place parmi les pièces allumées en même temps (la pièce d'arrivée n'est plus coupée) ; part des départs suivis d'un trajet comptée sur tous les trajets ; un groupe réparti sur deux pièces ne se suit plus lui-même ; noms de pièces départagés avant d'être reliés aux trajets ; heure d'un trajet de nuit juste (00:10 n'est plus « vers 12:00 ») ; une arrivée pendant une absence n'est plus apprise.

## 0.17
- Passages : pris dans les deux sens, et un chemin peut en enchaîner jusqu'à 3, à travers des pièces restées éteintes. Les « enchaînements » (pièce voisine directe seulement) deviennent des **trajets** : une pièce s'éteint, une pièce reliée s'allume dans les 3 min ; une pièce allumée moins de 3 min en passant (passage lumineux) prolonge le trajet. Onglet Plan : trajets les plus fréquents, passages comptés dans chaque sens, ordre d'allumage du soir, pièces sans passage signalées.
- Simulation : quand une pièce s'éteint d'elle-même, un trajet relevé est tiré (aussi souvent que dans les habitudes) : pièces traversées en passage lumineux, pièce d'arrivée allumée pour une durée habituelle ; jamais de jour (sauf pièces allumées même de jour), hors des règles du Guide, ni au-delà du nombre de pièces allumées en même temps. Le calage en tient compte : il repart quand une pièce gagne ou perd ses trajets, et son réglage de fréquence retient aussi les arrivées par trajet.

## 0.16
- Horloge ou soleil : pour chaque pièce, l'analyse mesure si l'allumage du soir suit le coucher du soleil ou l'horloge, et si l'extinction du matin suit le lever (une part de 0, l'horloge, à 1, le soleil ; entre les deux, un mélange). Il faut 10 jours au moins et un soleil qui a bougé (pas en juin). La simulation fait glisser ces heures avec la saison selon cette part ; l'heure du coucher et du lever des habitants reste à l'horloge. L'onglet Calage nomme les pièces qui suivent le soleil.

## 0.15
- Pièces allumées en même temps : relevées dans vos historiques (sur les 28 derniers jours comptés, le maximum de chaque jour tenu 3 minutes au moins, sans les pièces aux allumages éclair, dépassé un jour sur dix au plus) au lieu de 3 fixes. Le réglage de la configuration reste possible (vide = relevé). Avec 3, une maison souvent éclairée dans 5 ou 6 pièces le soir ne pouvait pas être calée : salons et cuisine prenaient les 3 places, l'étage restait sous le réel même × 3.
- Calage : les pièces aux allumages éclair (presque tous moins de 3 min : détecteur de mouvement) restent hors calage, avec leurs réglages d'origine, et sont nommées dans l'onglet Calage. Une lampe sur détecteur aux allumages de quelques minutes (WC) reste calée.

## 0.14.1
- « Tout effacer » efface aussi le calage, qui garde pour chaque pièce le profil réel heure par heure (il laissait deviner quand la maison est vide). Message de confirmation complété.

## 0.14
- Notice (2026-11-08) : section **Calage** (ce qui est comparé et corrigé, tolérances, contrôle de chaque nuit, Habitudes changées, onglet Calage), plage « sauf exception » du Guide, commande Habitudes changées du widget.
- Recalage (case « Recalage automatique » de la configuration, décochée par défaut) : chaque nuit, la simulation est rejouée en mémoire sur les 28 derniers jours réels comptés et comparée pièce par pièce (heures allumées, allumages, durée, profil de 24 h). Deux réglages par pièce (fréquence, durée, entre ÷ 3 et × 3) sont corrigés, au plus 5 tours par nuit, jusqu'à entrer dans les tolérances (±15 % des heures, ±25 % des allumages, réglables).
- Une fois calée, plus de rejeu : un contrôle léger compare chaque nuit les 14 derniers jours à la référence du calage. Une pièce hors tolérances 3 nuits de suite : état « Habitudes changées », message Jeedom et commande info « Habitudes changées » du widget. Le calage se relance par un bouton.
- Nouvel onglet Calage : état, tableau réel / simulé par pièce avec écarts et pastilles, réglages, bandes de 24 h, volets ; boutons « Relancer le calage » et « Remettre les réglages d'origine ».
- Le réglage de fréquence ne joue que sur l'allumage : la fin d'une session suit toujours le profil (un profil gonflé gardait le WC allumé 16 h). Contrôle sur les allumages seulement (les heures suivent la saison), sauté quand aucun jour nouveau n'est compté (absence) ; pièces ajoutées, retirées ou changées dans le Guide : calage refait ; arrêt « au mieux » après 20 tours.
- Règles du Guide : une plage « jamais allumée » peut être « sauf exception » (un jour sur 30 environ, tiré au hasard, elle ne compte pas).

## 0.13.2
- Correction : le seuil haut de luminosité (« lux_haut ») n'était plus lu depuis la 0.13.1 ; avec un capteur, la pénombre passait d'un coup de 0 à 1 au seuil bas.

## 0.13.1
- Corrections après la revue adverse de la 0.13 :
  - « Envoyer vraiment » décoché pendant Simulation On : les lampes vraiment allumées sont éteintes, puis la simulation continue à blanc ; coché : elle repart de zéro (l'état de l'essai à blanc n'est plus pris pour la maison) ;
  - l'état est enregistré avant d'envoyer : un journal impossible à écrire (disque plein) ne fait plus renvoyer ni perdre d'ordres ;
  - une pièce sortie de la simulation est éteinte au lieu de rester allumée ; une lampe sans commande pour l'éteindre n'est pas allumée ; un « éteindre » perdu est renvoyé au lieu d'être pris pour un visiteur ; une lampe déjà allumée au moment de On compte comme visiteur ;
  - volets : un volet déjà fermé n'est plus « fermé » puis rouvert, ni un volet sans commande pour l'ouvrir ; ouverture le matin seulement (une plage « peut » ne l'ouvre plus le soir) ; un volet sorti de la simulation qu'elle avait fermé est rouvert le matin ;
  - journée de 04:00 juste aux changements d'heure ; capteur de luminosité au format `#1234#` ; seuil de nuit à 0 lux permis ; « Pièces allumées même de jour » sans tenir compte des majuscules, et pour un nom fait de chiffres ;
  - onglet Simulation : plus de volets inventés sur Off ; widget mis à jour quand la configuration change ; « envoyé » ne s'affiche plus sur les lignes Simulation On / Off ; un passage éclair dure 5 minutes, comme la notice le dit maintenant ;
  - « Tout effacer » efface aussi le journal de la simulation ; périodes de simulation purgées comme les autres ; notice à jour (configuration, confidentialité, volets, visiteur en envoi réel seulement).

## 0.13
- Onglet **Simulation** et commandes **Simulation On / Off** du widget : pièce par pièce, d'après ses tendances quand elle a assez de jours comptés, sinon d'après des horaires provisoires de son type de pièce (cuisine, salon, chambre…) ; seulement à la pénombre (capteur de luminosité ou soleil), décalage et activité tirés chaque jour, scènes, règles du Guide, au plus 3 pièces à la fois, volets ouverts et fermés, pièce changée par quelqu'un laissée 60 min ; Off éteint ce qu'elle a allumé ; une pièce arrivée à une heure où elle n'a jamais été allumée est éteinte, sauf un jour sur 30 environ.
- **À blanc par défaut** : rien n'est envoyé, chaque décision est notée au journal (7 jours) ; « Journée à blanc » rejoue tout de suite les 24 prochaines heures ; « Envoyer vraiment aux équipements » dans la configuration. Une période Simulation On est exclue de l'analyse.

## 0.12.1
- Corrections après la revue générale : deux enregistrements simultanés (deux onglets) passent l'un après l'autre, et tout est vérifié avant le moindre effacement ; données illisibles refusées au lieu d'être prises pour « vide » ; la page reprend le suivi retenu par la box.
- Guide : un doublon dont la commande gardée n'est pas encore choisie ne compte pas ; changer d'avis sur une commande remet en ordre ses doublons ; « Remettre le Guide à zéro » rend aussi leur type aux commandes.
- Tendances : une lampe restée allumée (oubliée, bloquée) est signalée « allumée sans fin » au lieu d'effacer les jours qui suivent ; périodes exclues qui se chevauchent réunies ; un variateur ne note plus chaque changement d'intensité ; lampe allumée chaque soir vers minuit reconnue (23:58 et 00:02).
- Plan : version du plan (un autre onglet resté sur l'ancien plan est refusé), images d'étages jamais enregistrés effacées après un jour, image de 3 Mo au plus, tracé gardé si on annule le nom de la pièce ou le passage, détail d'une pastille d'un clic (tablettes), vieux Safari / iPad (image, grille, noms) ; commande d'absence supprimée signalée ; les noms d'étage à chiffres (« Lampe 1 ») ne disent plus « 1er étage ».
- Selftest : accès administrateur et `.htaccess` vérifiés, « // » dans une chaîne ne masque plus une écriture ; `.htaccess` pour Apache 2.2 et 2.4.

## 0.12
- Boutons **Configuration** (configuration du plugin) et **Widget** en haut de la page : un équipement « Présence habituelle » (état, pièces allumées d'habitude maintenant, date des tendances, commandes suivies, Recalculer), objet et visibilité réglables ; les pièces allumées d'habitude seulement si vous cochez l'option (elles laissent deviner quand la maison est vide).
- Guide : **Activer leur historique…** pour les commandes suivies non historisées ou lissées, sur confirmation, réglage d'avant gardé et **Remettre l'historique comme avant** ; ou la marche à suivre à la main.
- Guide : **Détecteurs de présence** (reliés à Jeedom ou non) et les lampes qu'ils allument : plus proposées comme doublons, marquées « sur détecteur » dans les Tendances, gardées pour être simulées ensemble ; **＋ détecteur de Jeedom…** propose les détecteurs de présence de Jeedom (lampes de la même pièce pré-cochées) ; pour une pièce sur détecteur, « surtout allumée » se lit par rapport à son heure la plus fréquente (sinon « à toute heure »).
- Plan : les détecteurs de présence reliés à Jeedom se placent comme les lampes ; une pastille posée hors de la pièce que disent sa pièce Jeedom ou son nom est signalée (permis).
- Correction : un virtuel qui recopie une lampe reste son doublon, même quand les deux sont sur le même détecteur (il n'est plus pré-coché parmi les lampes du détecteur).
- Guide : bouton **Remettre le Guide à zéro** (réponses, règles, détecteurs et sens des volets effacés, sur confirmation ; le choix des commandes, le plan et les relevés sont gardés ; « Revenir au choix d'avant » les rend).

## 0.11
- **Tendances plus lisibles** : un tableau résumé (une ligne par pièce : heures où elle est surtout allumée en semaine et le week-end, heures où elle ne l'est jamais, durée habituelle, allumages éclair d'une lampe sur détecteur signalés), le détail heure par heure replié sous chaque pièce (clic sur son nom), durées en clair (« 5 min », « 1 h 57 »), chiffres lisibles sur les cases foncées, volets : heure habituelle en gras avec l'écart, exceptions repliées.

## 0.10
- **Plan** : tout se modifie ou se supprime (cliquez une pièce, un passage ou une zone : panneau Renommer / Modifier / Supprimer, coins orange à glisser ; ✎ et 🗑 dans les listes), suppression d'une pièce annonçant les passages qui partent avec elle, bouton **Annuler**.
- Passages et escaliers de plus de deux points (« Terminer la forme »). Un escalier entre deux étages est enregistré une fois et montré sur les deux : sur l'autre étage, d'abord à la même place, en pointillé pâle « à ajuster », jusqu'à ce que vous glissiez ses points. Les escaliers saisis deux fois (une fois de chaque étage) sont fusionnés.
- **Pièces Jeedom** (colonne de droite) : la pièce du plan de chaque pièce Jeedom, trouvée d'après le nom (sans accents, espaces ni majuscules) ou choisie. Bouton **Placer d'après les pièces Jeedom** : les lampes et volets suivis pas encore placés vont dans leur pièce ; les pastilles déjà placées ne bougent pas.
- Ordre des étages du plus bas au plus haut (numérotés ; ↑ = un niveau plus haut ; en 0.9, ↑ remontait dans la liste : vérifiez l'ordre).
- **Guide** : lignes groupées par pièce Jeedom (dans l'ordre du plan), provenance de chaque commande (Virtuel, Zigbee2MQTT…), filtres par type et par provenance ; un virtuel qui recopie une autre commande est proposé « Doublon de » l'originale (gardée) ; « Groupe de lampes » : les lampes qu'il allume se cochent dans une liste, la découverte les pré-remplit (liste gardée pour la simulation, pas encore utilisée par l'analyse).
- Guide : historique de chaque commande (📈 valeurs gardées sur 90 jours, courbe de Jeedom au clic, filtre « historique riche ») et scénarios qui la commandent (⚙, actions notées dans leur journal) pour aider à choisir ; rien n'est coché pour vous.
- Corrections après revue : un virtuel recopie n'efface plus un « Non » ni l'historique d'un virtuel suivi (proposé décoché, « à vérifier »), « Annuler » après l'enregistrement ne ramène plus un étage dont l'image est effacée, la liste des lampes d'un groupe ne propose plus celles répondues Non ou Doublon, un doublon d'un groupe est refusé, « Placer d'après les pièces » ne met plus de pastille hors d'une pièce en L, confirmation avant d'effacer un escalier vu des deux étages, modification du plan pendant l'enregistrement gardée ; puis titre de la fenêtre d'historique protégé, recopie jugée sur des relevés exacts (pas seulement historisée), plages en cours de saisie gardées au chargement des historiques, historique lissé jamais « riche », bouton « Doublon de » aussi strict que la découverte, pastilles d'une pièce pleine gardées à l'intérieur.

## 0.9
- **Volets de toute marque** : états en texte (open / closed, up / down, ouvert / fermé ; stop et my ignorés au lieu d'être pris pour « fermé ») ; sens réglable par volet dans le Guide (ouvert = 0) ; volets sans type générique reconnus à leur nom par la découverte ; volet sans retour d'état (Somfy RTS…) signalé par le Guide après 3 jours sans changement.
- « Identifier en allumant ou en ouvrant » ; plages « peut s'ouvrir » / « jamais ouvert » pour un volet.
- Sens inversé calculé sur l'échelle de la commande (0-1 ou 0-100) ; matin et soir d'un volet rangés en semaine ou week-end à l'heure du mouvement ; « 3 jours sans changement » compté hors absences, même pour un volet jamais historisé ; commandes « auto », « mode », « calibration », « vitesse » d'un volet non proposées ; la découverte ne repropose pas un volet déjà refusé ; un « volet » qui est l'état d'un démon ou d'un plugin (« Démon Volet proportionnel ») est proposé décoché, « à vérifier ».

## 0.8.1
- Guide : le × d'une plage retire la bonne ; doublon d'un doublon refusé ; les doublons suivent la commande gardée quand sa réponse change ; « Enregistrer » garde aussi le plan ; la page reprend ce que la box a retenu ; plage sans heure refusée ; la découverte remplit les réponses ; « Non » rend son type à une prise ; question 4 alignée sur le bilan ; « Identifier en allumant » voit toutes les commandes binaires ou typées.
- **Week-end réglable** (configuration : jour et heure de début et de fin), par défaut du vendredi 16:00 au lundi 04:00 : le dimanche soir compte désormais pour le week-end.
- Revenir à un choix d'avant la 0.8 garde les réponses et les règles actuelles.
- Contrôle ajouté au selftest : le plugin ne modifie aucun équipement, commande, scénario ni historique de la box.

## 0.8
- **Guide** : quatre questions (lampe ou volet de la maison, doublon ou groupe ; place sur le plan ; règles : jamais simulée, plages où elle peut ou ne doit pas s'allumer ; commandes sans assez de données), « Identifier en allumant », et un bilan qui dit quand une simulation est proposable. Rien n'est encore lancé.

## 0.7.1
- Découverte : les « Etat LED » et autres voyants d'une prise ne sont plus pris pour des lampes ; un équipement qui a déjà une lumière typée ne propose pas ses autres binaires ; les bascules en bord de période ne comptent plus dans les doublons et groupes ; chaîne de doublons résolue ; latitude à virgule acceptée.
- Jours sans lumière proposés décochés ; lampes à historique lissé signalées.
- Observation : relevés gardés 7 jours après la fin, plus d'orphelins quand on observe à nouveau.
- Le type choisi sur la page est gardé au rechargement ; une seule découverte à la fois.
- Confirmation avant d'enregistrer un choix qui retire des commandes.

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

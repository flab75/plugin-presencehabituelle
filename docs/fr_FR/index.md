# Présence habituelle

Simulation de présence **apprise de vos habitudes**. Le plugin observe quand vos lumières s'allument et quand vos volets s'ouvrent, puis (dans les versions suivantes) rejoue ces habitudes pendant votre absence, avec des variations réglables.

## Confidentialité

Tout est calculé sur la box. Le plugin ne fait **aucun appel réseau** (le contrôle automatique `tests/selftest.php` le vérifie), n'utilise aucun service en ligne ni aucune « IA ». Les changements d'état sont gardés dans une table de la base de Jeedom (`presencehabituelle_event`), effacée à la désinstallation. Seuls les administrateurs voient les habitudes.

## Version 0.1 : apprentissage seul

La 0.1 **n'agit sur rien** : elle apprend et montre ce qu'elle a appris.

1. **Commandes suivies** : le plugin propose les commandes d'état d'après leur type générique.
   - Lumières (`LIGHT_STATE`) et volets (`FLAP_STATE`) : cochés.
   - Prises et médias : proposés décochés (une prise peut alimenter un frigo).
   - Sondes et autres : cachées, déconseillées (elles ne disent rien de vos gestes).
   - Une commande sans type générique n'apparaît pas d'office : renseignez son type, ou cochez « Afficher les autres commandes ».
2. **Groupes** : une lampe est apprise seule ; donnez le même groupe à plusieurs lampes pour les apprendre ensemble (une pièce), ou « Grouper par objet Jeedom ».
3. **Historique** : à l'enregistrement, les changements d'état des 90 derniers jours sont repris de l'historique de Jeedom (si la commande est historisée). Une commande non historisée est apprise à partir de l'enregistrement. Au-delà de 2 jours, Jeedom garde par défaut une moyenne par heure des valeurs numériques : les heures restent justes, mais la durée d'un allumage ne l'est plus. Une commande binaire, ou en mode d'historique « Aucun », reste exacte.
4. **Absences** : une commande vraie pendant vos absences (un mode « Absent ») et des périodes saisies à la main. Les jours touchés ne sont pas comptés, sans quoi le plugin apprendrait la maison vide — ou une ancienne simulation.

L'onglet **Habitudes** montre, pour chaque lampe ou groupe :
- le pourcentage des jours où elle est allumée dans chaque heure, en semaine et le week-end ;
- la durée d'un allumage (médiane, et la moitié des allumages entre deux valeurs) et la part des passages de moins de 3 minutes ;
- les lampes allumées ensemble.

Pour chaque volet : l'heure d'ouverture du matin, de fermeture du soir, et la part des nuits où il est fermé. Une habitude calculée sur moins de 10 jours est marquée « à confirmer ».

Le calcul est refait chaque nuit ; les changements de plus de 90 jours sont effacés (réglable dans la configuration du plugin), pour suivre la saison.

## Capteur de luminosité

Le plugin fonctionnera sans capteur de luminosité, d'après la hauteur du soleil. Un capteur extérieur est cependant **un vrai plus** : par jour gris, on allume plus tôt et en pleine journée, ce que la hauteur du soleil ne voit pas. (Prévu à partir de la 0.2.)

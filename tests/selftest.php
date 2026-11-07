<?php
/* Contrôles : calcul des habitudes sur données synthétiques, aucun appel réseau dans le code, puis (sur la box) analyse réelle sans rien écrire.
   Usage : sudo -u www-data php tests/selftest.php */
require_once __DIR__ . '/../core/class/presencehabituelle_analyse.class.php';
$ok = function ($v, $m) { if (!$v) { echo "ECHEC : $m\n"; exit(1); } }; // assert() est désactivé en production
$A = 'presencehabituelle_analyse';
date_default_timezone_set('Europe/Paris');
$t = fn($s) => strtotime($s);
$A::weekEnd(5, '16:00', 7, '16:00'); // contrôles écrits avec ce week-end (du ven. 16:00 au dim. 16:00 : dim. soir = semaine) ; le défaut est testé plus bas

// lampe : 2 sessions, la dernière encore allumée
$iv = $A::intervalles([[$t('2026-03-02 19:00'), 1, 1], [$t('2026-03-02 19:30'), 0, 1], [$t('2026-03-03 07:00'), 40, 0], [$t('2026-03-03 07:02'), 0, 1], [$t('2026-03-03 20:00'), 1, 1]], $t('2026-03-03 21:00'));
$ok(count($iv) == 3 && $iv[0][2] && !$iv[1][2] && !$iv[2][2] && $iv[2][1] == $t('2026-03-03 21:00'), 'intervalles');

// groupe : A et B ensemble (scène), C 10 min plus tard dans la même session
$se = $A::sessions(['A' => [[$t('2026-03-02 19:00'), $t('2026-03-02 19:30'), true]], 'B' => [[$t('2026-03-02 19:00:10'), $t('2026-03-02 20:00'), true]],
  'C' => [[$t('2026-03-02 19:10'), $t('2026-03-02 19:20'), true], [$t('2026-03-02 22:00'), $t('2026-03-02 22:01'), true]]]);
$ok(count($se) == 2 && $se[0][1] == $t('2026-03-02 20:00') && $se[0][3] == ['A', 'B'], 'sessions : ' . json_encode($se));

// jours : lun. 2 → ven. 6 mars, mercredi exclu par une période
$j = $A::jours($t('2026-03-01 12:00'), $t('2026-03-07 08:00'), [[$t('2026-03-04 10:00'), $t('2026-03-04 11:00')]]);
$ok(array_column($j, 'd') == ['2026-03-02', '2026-03-03', '2026-03-05', '2026-03-06'], 'jours : ' . json_encode(array_column($j, 'd')));

// profil : 19 h et 22 h allumés le lundi seulement → 33 % des 3 soirs de semaine (le vendredi soir est du week-end)
$p = $A::profil($se, $j);
$ok($p['nS'] == 4 && $p['nW'] == 1 && $p['S'][19] == 33 && $p['S'][22] == 33 && $p['S'][20] == 0 && $p['S'][21] == 0, 'profil : ' . json_encode($p));
$l = $A::lisser($p['S']);
$ok($l[19] == 17 && $l[20] == 0 && $l[22] == 17, 'lissage : ' . json_encode($l));

$ok($p['ex'] == [], 'aucune heure rare : ' . json_encode($p['ex'])); // 33 % > RARE

// découverte : B = doublon de A (3 s de retard), G = groupe de A et C, D seule
$jr = fn($j, $h, $m = 0) => $t('2026-03-02') + 86400 * $j + 3600 * $h + 60 * $m;
$LA = $LB = $LC = $LD = [];
for ($q = 0; $q < 10; $q++) {
  $LA[] = [$jr($q, 19), $jr($q, 20), true]; $LB[] = [$jr($q, 19) + 3, $jr($q, 20) + 3, true];
  $LC[] = [$jr($q, 19, 30), $jr($q, 21), true]; $LD[] = [$jr($q, 7, $q * 7), $jr($q, 8), true];
}
$ok($A::memes($LA, $LB) == 1 && $A::memes($LB, $LA) == 1 && $A::memes($LA, $LC) < .9, 'doublon : ' . $A::memes($LA, $LC));
$LG = $A::union(['A' => $LA, 'C' => $LC]);
$ok(count($LG) == 10 && $LG[0][0] == $jr(0, 19) && $LG[0][1] == $jr(0, 21) && $A::inter($LA, $LG) == $A::duree($LA) && $A::memes($LG, $A::union(['A' => $LA, 'C' => $LC])) == 1, 'groupe');
$ok($A::memes($LA, $LG) < .8, 'une lampe n’est pas le groupe');
// absences : rien d'allumé les 4 et 5 mars (D éteinte aussi), 3 lampes relevées
$cut = fn($iv) => array_values(array_filter($iv, fn($i) => date('d', $i[0]) != '04' && date('d', $i[0]) != '05'));
$ab = $A::absences(['A' => $cut($LA), 'C' => $cut($LC), 'D' => $cut($LD)], ['A' => $jr(0, 0), 'C' => $jr(0, 0), 'D' => $jr(0, 0)], $A::jours($jr(-1, 12), $jr(9, 12), []));
$ok($ab == [['2026-03-04', '2026-03-05']], 'absences : ' . json_encode($ab));
// automatique : A à 19:00 chaque jour ; D décalée de 7 min par jour ; E au coucher du soleil + 2 min
$cou = []; $LE = [];
for ($q = 0; $q < 10; $q++) { $cou[date('Y-m-d', $jr($q, 0))] = $jr($q, 18, 2 * $q); $LE[] = [$jr($q, 18, 2 * $q + 2), $jr($q, 23), true]; }
$ok($A::automatique($LA) == ['heure' => 1140] && $A::automatique($LD) === null && $A::automatique($LE, $cou) == ['soleil' => 2], 'automatique : ' . json_encode([$A::automatique($LD), $A::automatique($LE, $cou)]));
// bascules aux bords d'une fenêtre coupée : pas de vraies bascules (déjà allumée au début, encore allumée à la fin)
$X = [[100, 200, true], [500, 1000, true]]; $Y = [[100, 300, true], [500, 1000, true]];
$ok($A::memes($X, $Y) == .75 && $A::memes($X, $Y, 5, 100, 1000) == .5, 'bornes : ' . $A::memes($X, $Y, 5, 100, 1000));
// lumières sans type d'après le nom ; voyant, disponibilité, capteur de lumière : non
$ok($A::nommee('Lumière', 'PriseLumiereBoite') && $A::nommee('Etat', 'Ruban LED') && $A::nommee('Applique', 'Relais 2')
  && !$A::nommee('Etat LED', 'PriseSerre') && !$A::nommee('Luminosité basse', 'Détecteur') && !$A::nommee('Disponibilité', 'Lampe bureau')
  && !$A::nommee('Etat', 'Prise frigo') && !$A::nommee('Batterie faible', 'Lampe'), 'lumières d’après le nom');
// volets sans type d'après le nom (0.9) ; pile, luminosité : non
$ok($A::voletNomme('Volet', 'Module RTS') && $A::voletNomme('Position', 'Store Salon') && $A::voletNomme('Etat', 'Shutter kitchen') && $A::voletNomme('Rideau', 'Relais')
  && !$A::voletNomme('Batterie', 'Volet Salon') && !$A::voletNomme('Luminosité', 'Store') && !$A::voletNomme('Position', 'Thermostat') && !$A::voletNomme('Restore', 'Box') && !$A::voletNomme('Volet auto', 'Virtuel') && !$A::voletNomme('Mode', 'Volet Salon'), 'volets d’après le nom');
echo "découverte OK\n";
$rp = $A::reponses(['5' => ['r' => 'oui'], '6' => ['r' => 'doublon', 'de' => '5'], '7' => ['r' => 'doublon', 'de' => 7], '8' => ['r' => 'peut-être'], 'x' => ['r' => 'oui']]);
$ok($rp == [5 => ['r' => 'oui'], 6 => ['r' => 'doublon', 'de' => 5], 7 => ['r' => 'doublon']], 'réponses : ' . json_encode($rp));
$rg = $A::regles(['5' => ['jamais' => 1], '6' => ['plages' => [['k' => 'exclue', 'j' => 'tous', 'de' => '23:00', 'a' => '07:00'], ['k' => 'marche', 'j' => 'S', 'de' => '24:00', 'a' => '07:00'], ['k' => 'x', 'j' => 'S', 'de' => '01:00', 'a' => '02:00']]], '7' => ['plages' => []]]);
$ok($rg == [5 => ['jamais' => 1, 'plages' => []], 6 => ['jamais' => 0, 'plages' => [['k' => 'exclue', 'j' => 'tous', 'de' => '23:00', 'a' => '07:00']]]], 'règles : ' . json_encode($rg));
echo "mode guidé OK\n";

$A::weekEnd(5, '16:00', 1, '04:00');
// week-end par défaut du ven. 16:00 au lun. 04:00 : dim. 22 h et lun. 01 h = week-end, lun. 10 h et ven. 10 h = semaine
$ok($A::cle(7, 22) == 'W' && $A::cle(6, 1) == 'W' && $A::cle(1, 1) == 'W' && $A::cle(1, 4) == 'S' && $A::cle(5, 22) == 'W' && $A::cle(5, 10) == 'S' && $A::cle(7, 10) == 'W' && $A::cle(5, 15, 59) == 'S', 'week-end par défaut');
$A::weekEnd(6, '8:00', 7, '20:00'); // réglé : du sam. 08:00 au dim. 20:00 (sans passer par lundi)
$ok($A::cle(6, 7) == 'S' && $A::cle(6, 8) == 'W' && $A::cle(7, 19) == 'W' && $A::cle(7, 20) == 'S' && $A::cle(5, 22) == 'S', 'week-end réglé');
$ok($A::weekEnd(9, '16:00', 1, '04:00') == [5, 960, 1, 240] && $A::weekEnd(5, '25:00', 1, '04:00') == [5, 960, 1, 240], 'week-end illisible = défaut');
$A::weekEnd(5, '16:00', 7, '16:00');
$ok($A::pc(299, 300) == 99 && $A::pc(1, 300) == 1 && $A::pc(300, 300) == 100 && $A::pc(0, 300) == 0 && $A::pc(0, 0) == 0, 'pourcentage borné');
$d = $A::durees($se, $j);
$ok($d['n'] == 2 && $d['med'] == 30.5 && $d['min'] == 1 && $d['max'] == 60 && $d['passages'] == 50, 'durées : ' . json_encode($d));
$sc = $A::scenes($se, $j);
$ok($sc == ['A + B' => 50, 'C' => 50], 'scènes : ' . json_encode($sc));

// volet : ouvert 07:30 en semaine, fermé partiellement l'après-midi (pas une fermeture), fermé 21:00 ; samedi ouvert 09:00, fermé 23:30
$ev = []; foreach (['02', '03', '04', '05', '06'] as $x) array_push($ev, ["2026-03-$x 07:30", 100], ["2026-03-$x 14:00", 30], ["2026-03-$x 21:00", 0]);
array_push($ev, ['2026-03-07 09:00', 100], ['2026-03-07 23:30', 0], ['2026-03-08 00:10', 100], ['2026-03-08 00:20', 0]);
$ev = array_map(fn($e) => [$t($e[0]), $e[1], true], $ev);
$v = $A::volet($ev, $A::jours($t('2026-03-01 12:00'), $t('2026-03-09 08:00'), []));
$ok($v['matin']['S']['med'] == 450 && $v['matin']['S']['n'] == 4 && $v['soir']['S']['med'] == 1260 && $v['soir']['S']['ferme'] == 100, 'volet semaine : ' . json_encode($v));
$ok($v['matin']['W']['med'] == 540 && $v['soir']['W']['n'] == 2 && $v['soir']['W']['med'] == 1360 && $v['soir']['W']['ferme'] == 100, 'volet week-end : ' . json_encode($v));

// nuit pas finie : analyse le dim. 8 à 00:05, le samedi soir ne compte pas encore (ni fermeture, ni % fermé)
$vf = $A::volet($ev, $A::jours($t('2026-03-01 12:00'), $t('2026-03-08 00:05'), []), $t('2026-03-08 00:05'));
$ok($vf['soir']['W']['n'] == 1 && $vf['soir']['W']['ferme'] == 100 && $vf['soir']['S']['n'] == 4, 'volet nuit en cours : ' . json_encode($vf['soir']));

// passage à l'heure d'été (dim. 29 mars) : ouvert 07:30 = 450 min, fermé 00:30 = 1470 min (après minuit ; dim. soir = semaine)
$ete = array_map(fn($e) => [$t($e[0]), $e[1], true], [['2026-03-28 22:00', 0], ['2026-03-29 07:30', 100], ['2026-03-30 00:30', 0]]);
$ve = $A::volet($ete, $A::jours($t('2026-03-28 12:00'), $t('2026-03-30 08:00'), []));
$ok($ve['matin']['W']['med'] == 450 && $ve['soir']['S']['med'] == 1470, 'volet heure d\'été : ' . json_encode($ve));
$ok($A::minutes($t('2026-03-30 00:30'), '2026-03-29') == 1470 && $A::minutes($t('2026-03-29 07:30'), '2026-03-29') == 450, 'minutes');
// aucune nuit de week-end observée : % fermé inconnu (null), pas « jamais fermé »
$vs = $A::volet($ev, $A::jours($t('2026-03-01 12:00'), $t('2026-03-06 08:00'), [])); // lun.-jeu. (le soir du vendredi compte pour le week-end)
$ok($vs['soir']['W']['ferme'] === null && $vs['soir']['S']['ferme'] == 100, 'volet sans nuit : ' . json_encode($vs['soir']));
// soir dont le lendemain matin est une absence (départ le sam. 7 à 02:00) : le soir du vendredi 6 ne compte pas ; comptes bruts des nuits
$vx = $A::volet($ev, $A::jours($t('2026-03-01 12:00'), $t('2026-03-09 08:00'), []), PHP_INT_MAX, [[$t('2026-03-07 02:00'), $t('2026-03-07 03:00')]]);
$ok($vx['soir']['W']['n'] == 1 && $vx['soir']['W']['jours'] == 1 && $vx['soir']['W']['nuits'] == 1 && $vx['soir']['W']['fermees'] == 1 && $vx['matin']['W']['jours'] == 2, 'volet soir avant absence : ' . json_encode($vx['soir']['W']));
// volet ouvert la nuit, fermé contre la chaleur à 13:00, rouvert à 15:30 : pas une ouverture du matin
$ch = []; foreach (range(2, 6) as $d) { $ch[] = [$t("2026-03-0$d 13:00"), 0, 1]; $ch[] = [$t("2026-03-0$d 15:30"), 100, 1]; }
$vc = $A::volet(array_merge([[$t('2026-03-01 12:00'), 100, 1]], $ch), $A::jours($t('2026-03-01 12:00'), $t('2026-03-07 08:00'), []));
$ok($vc['matin']['S']['n'] == 0 && $vc['soir']['S']['ferme'] === 0, 'volet fermé contre la chaleur : ' . json_encode($vc['matin']['S']));
$ok($v['soir']['S']['nuits'] == $v['soir']['S']['fermees'] && $v['soir']['S']['nuits'] > 0 && $v['matin']['S']['jours'] == 5, 'volet comptes bruts : ' . json_encode($v['soir']['S']));
// week-end lu à l'heure de l'événement : réglé du sam. 08:00 au dim. 20:00, le ven. soir compte en semaine, le sam. 09:00 en week-end
$A::weekEnd(6, '8:00', 7, '20:00'); $vw = $A::volet($ev, $A::jours($t('2026-03-01 12:00'), $t('2026-03-09 08:00'), [])); $A::weekEnd(5, '16:00', 7, '16:00');
$ok($vw['soir']['S']['n'] == 5 && $vw['matin']['W']['med'] == 540, 'volet week-end à l’heure de l’événement : ' . json_encode($vw));
// jour sans ouverture rangé à l'heure médiane des ouvertures (07:30) : week-end du sam. 07:45, le samedi resté fermé compte en semaine comme un samedi ouvert à 07:30
$e2 = []; foreach (range(2, 6) as $d) array_push($e2, [$t("2026-03-0$d 07:30"), 100], [$t("2026-03-0$d 21:00"), 0]); $e2[] = [$t('2026-03-08 07:30'), 100];
$A::weekEnd(6, '7:45', 7, '20:00'); $vm = $A::volet(array_merge([[$t('2026-03-01 22:00'), 0]], $e2), $A::jours($t('2026-03-01 12:00'), $t('2026-03-09 08:00'), [])); $A::weekEnd(5, '16:00', 7, '16:00');
$ok($vm['matin']['S']['jours'] == 6 && $vm['matin']['W']['jours'] == 1 && $vm['matin']['W']['n'] == 1, 'volet jour sans geste à l’heure médiane : ' . json_encode($vm['matin']));
$ok($p['cS'][19] == 1 && $p['cS'][20] == 0, 'comptes bruts : ' . json_encode($p['cS']));

// deux allumages dans la même minute : deux durées, passages ≤ 100 %
$d2 = $A::durees([[$t('2026-03-02 19:00:05'), $t('2026-03-02 19:00:20'), true], [$t('2026-03-02 19:00:40'), $t('2026-03-02 19:00:50'), true]], $j);
$ok($d2['n'] == 2 && $d2['passages'] == 100, 'durées même minute : ' . json_encode($d2));

[$g, $x] = $A::longues([[0, 3600, true], [5000, 5000 + 20 * 3600, true]], 18 * 3600);
$ok(count($g) == 1 && $g[0][1] == 3600 && $x == [[5000, 5000 + 20 * 3600]], 'longues');

// heure rare : allumé à 03 h un seul jour de semaine sur 15 (7 %) = exception ; 19 h tous les jours = tendance
$jr = $A::jours($t('2026-03-01 12:00'), $t('2026-03-22 08:00'), []); $ses = [];
foreach ($jr as $x) $ses[] = [$x['a'] + 19 * 3600, $x['a'] + 20 * 3600];
$ses[] = [$t('2026-03-04 03:10'), $t('2026-03-04 03:20')];
$pr = $A::profil($ses, $jr);
$ok($pr['nS'] == 15 && $pr['S'][19] == 100 && $pr['ex'] == [['2026-03-04', 3]], 'heure rare : ' . json_encode($pr['ex']));

// stats : 9 matins vers 07:30, un à 05:00 = exception ; série trop courte = pas d'exception
$st = $A::stats(['a' => 450, 'b' => 455, 'c' => 445, 'd' => 460, 'e' => 450, 'f' => 440, 'g' => 450, 'h' => 470, 'i' => 450, 'x' => 300]);
$ok($st['min'] == 300 && $st['max'] == 470 && $st['med'] == 450 && $st['moy'] == 437 && $st['ex'] == ['x' => 300], 'stats : ' . json_encode($st));
$ok($A::stats([1 => 450, 2 => 900])['ex'] == [] && $A::stats([]) == ['n' => 0], 'stats courtes');

// lissage et parts bornés : 99 % lissé n'affiche pas 100 % ; 299 passages sur 300 = 99 %
$pl = array_fill(0, 24, 100); $pl[5] = 99; $ll = $A::lisser($pl);
$ok($ll[5] == 99 && $ll[0] == 100, 'lissage borné : ' . json_encode($ll));
$sp = []; for ($i = 0; $i < 300; $i++) $sp[] = [$jr[$i % 15]['a'] + 36000 + $i * 60, $jr[$i % 15]['a'] + 36000 + $i * 60 + ($i ? 30 : 3000), true];
$ok($A::durees($sp, $jr)['passages'] == 99, 'passages bornés : ' . json_encode($A::durees($sp, $jr)['passages']));

// plan : point dans une pièce ; enchaînement Salon → Cuisine (éteint 19:30, allumé 19:31), pas l'allumage de 19:40, ni pendant une absence
$ok($A::dans(5, 5, [[0, 0], [10, 0], [10, 10], [0, 10]]) && !$A::dans(15, 5, [[0, 0], [10, 0], [10, 10], [0, 10]]) && $A::dans(2, 8, [[0, 0], [4, 10], [0, 10]]), 'point dans un polygone');
$pp = ['S' => [[$t('2026-03-02 19:00'), $t('2026-03-02 19:30'), true], [$t('2026-03-03 19:00'), $t('2026-03-03 19:30'), true], [$t('2026-03-04 20:00'), $t('2026-03-04 21:00'), false]],
  'C' => [[$t('2026-03-02 19:31'), $t('2026-03-02 19:50'), true], [$t('2026-03-03 19:40'), $t('2026-03-03 19:45'), true], [$t('2026-03-04 21:01'), $t('2026-03-04 21:30'), true]]];
$ok($A::enchainements($pp, [7 => ['S', 'C']]) == [7 => ['S', 'C', 1, 0]], 'enchaînements : ' . json_encode($A::enchainements($pp, [7 => ['S', 'C']])));
$ok($A::enchainements($pp, [['S', 'C']], [[$t('2026-03-02 00:00'), $t('2026-03-03 00:00')]])[0][2] == 0, 'enchaînements hors absences');

$ok($A::actif('On') && $A::actif(1) && !$A::actif('0') && !$A::actif('Absent ?') && !$A::actif(''), 'actif');
// états en texte (0.9) : ouvert / fermé reconnus, stop / my ignorés ; sens inversé (0 = ouvert)
$ok($A::actif('Open') && $A::actif('ouvert') && $A::actif('UP') && !$A::actif('closed') && !$A::actif('fermé') && $A::neutre('Stop') && $A::neutre('my') && !$A::neutre('closed') && !$A::neutre(0), 'états en texte');
$ok($A::inverser([[1, 0], [2, 100, true], [3, 40]]) == [[1, 100], [2, 0, true], [3, 60]] && $A::inverser([]) == [] && $A::inverser([[1, 0], [2, 0]]) == [[1, 1], [2, 1]] && $A::inverser([[1, 0], [2, 80]]) == [[1, 100], [2, 20]] && !$A::neutre('unknown'), 'sens inversé (échelle de la commande)');
$pe = $A::periodes("2026-10-15 15:27 → 2026-10-23 12:42\nn'importe quoi\n2026-11-01 - 2026-11-02");
$ok(count($pe) == 2 && $pe[0][0] == $t('2026-10-15 15:27') && $pe[1][1] == $t('2026-11-02 23:59:59'), 'périodes : ' . json_encode($pe));
echo "calcul OK\n";

// confidentialité : aucun appel réseau dans le code du plugin
$motifs = ['http' . '://', 'https' . '://', 'curl' . '_', 'fsock' . 'open', 'stream' . '_socket', 'com' . '_http', 'file_get_contents(\'h', 'f' . 'open(', 'XMLHttp' . 'Request', '$.' . 'ajax', '$.' . 'get(', '$.' . 'post(', 'new ' . 'Image', 'Soap' . 'Client', 'sock' . 'et_', 'Web' . 'Socket', 'send' . 'Beacon'];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
  if (!preg_match('/\.(php|js)$/', $f) || preg_match('#/(docs|tests)/#', $f)) continue;
  $c = file_get_contents($f);
  foreach ($motifs as $m) $ok(strpos($c, $m) === false, "appel réseau possible ($m) dans " . basename($f));
  preg_match_all('/fetch\(\s*([^,)]*)/', $c, $fm); // seul fetch permis : l'ajax du plugin
  foreach ($fm[1] as $u) $ok(strpos($u, "'plugins/presencehabituelle/") === 0, "fetch hors du plugin dans " . basename($f) . ' : ' . $u);
}
echo "aucun appel réseau OK\n";

// garantie : le plugin n'écrit que chez lui (sa table, sa configuration, son écouteur, ses fichiers) ; il ne modifie aucun équipement, commande, scénario ni historique
foreach ($it as $f) {
  if (!preg_match('/\.php$/', $f) || preg_match('#/(docs|tests)/#', $f)) continue;
  foreach (file($f) as $i => $l) {
    $o = basename($f) . ':' . ($i + 1);
    $l = preg_replace('#^\s*(/?\*).*$|\s//.*$#', '', $l); // commentaires ôtés : « history::archive » cité n'est pas un appel
    $ok(!preg_match('/->(save|remove)\(/', $l) || preg_match('/\$l->(save|remove)\(\)/', $l), "enregistrement ou suppression d'un objet de la box ($o)");
    $ok(!preg_match('/->(set(Configuration|IsEnable|IsVisible|Name|Value|Display|Order|Object_id|EqLogic_id|Logical_id)|event|checkAndUpdateCmd|addHistoryValue)\(/', $l), "modification d'un objet de la box ($o)");
    $ok(!preg_match('/(history|scenario|eqLogic|cmd|object|jeeObject|plan|planHeader|interactDef|cron|message|log)::(remove|save|archive|removes|clean|set)/i', $l), "écriture par le core ($o)");
    $ok(!preg_match('/->execCmd\(/', $l) || strpos($l, "getType() == 'info'") !== false, "commande exécutée sans être une info ($o)");
    $ok(!preg_match('/config::(save|remove)\(/', $l) || preg_match('/__CLASS__\)|, \$P\)/', $l), "configuration hors du plugin ($o)");
    $ok(!preg_match('/[\'"]\s*(DELETE|UPDATE|INSERT|DROP|ALTER|TRUNCATE|REPLACE|CREATE)\b/i', $l) || strpos($l, 'self::TABLE') !== false, "écriture SQL hors de la table du plugin ($o)");
  }
}
echo "aucune modification des objets de la box OK\n";

if (is_file(__DIR__ . '/../../../core/php/core.inc.php')) { // sur la box : analyse réelle, rien n'est écrit sauf data/habitudes.json
  require_once __DIR__ . '/../core/class/presencehabituelle.class.php';
  $r = presencehabituelle::analyser();
  echo 'analyse OK : ' . count($r['groupes']) . ' groupes, ' . count($r['volets']) . " volets\n";
}

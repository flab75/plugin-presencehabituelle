<?php
/* Contrôles : calcul des habitudes sur données synthétiques, aucun appel réseau dans le code, puis (sur la box) analyse réelle sans rien écrire.
   Usage : sudo -u www-data php tests/selftest.php */
require_once __DIR__ . '/../core/class/presencehabituelle_analyse.class.php';
require_once __DIR__ . '/../core/class/presencehabituelle_moteur.class.php';
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
$ok($A::estDetecteur('PRESENCE', 'binary', 'Etat', 'X', []) && $A::estDetecteur('', 'binary', 'occupancy', 'Capteur cuisine', []) && $A::estDetecteur('', 'binary', 'Etat', 'Détecteur de mouvement', [])
  && !$A::estDetecteur('', 'binary', 'motion', 'Porte', ['OPENING']) && !$A::estDetecteur('', 'numeric', 'motion_sensitivity', 'PIR', []) && !$A::estDetecteur('', 'string', 'presence', 'X', []), 'détecteur de présence reconnu');
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
$ok($A::suspect('Démon Volet proportionel', 'Jeedom interne') && $A::voletNomme('Démon Volet proportionel', 'Jeedom interne') && !$A::suspect('Volet', 'Salon'), 'volet à vérifier (état d’un démon), pas écarté');
// lampe restée allumée à la fin (oubliée, bloquée) : retirée sans exclure ses jours ; extinction manquée au milieu : jours exclus
[$g, $x, $bl] = $A::longues([[0, 100, true], [200, 99999, true], [100000, 200000, false]], 1000, 200000);
$ok(count($g) == 1 && $x == [[200, 99999]] && $bl === 100000, 'longues : ' . json_encode([$g, $x, $bl]));
$ok($A::fusion([[50, 60], [0, 10], [5, 20], [20, 30]]) == [[0, 30], [50, 60]], 'fusion des intervalles');
$mn = []; for ($q = 0; $q < 8; $q++) { $d0 = $q % 2 ? $jr($q, 23, 58) : $jr($q, 0, 2); $mn[] = [$d0, $d0 + 1800, true]; } // 23:58 un jour, 00:02 un autre : 4 min d'écart
$au = $A::automatique($mn);
$ok(isset($au['heure']) && in_array($au['heure'], [2, 1438], true), 'automatique autour de minuit : ' . json_encode($au));
echo "découverte OK\n";
$rp = $A::reponses(['5' => ['r' => 'oui'], '6' => ['r' => 'doublon', 'de' => '5'], '7' => ['r' => 'doublon', 'de' => 7], '8' => ['r' => 'peut-être'], 'x' => ['r' => 'oui'], '9' => ['r' => 'groupe', 'm' => ['5', 6, 9, 6, 'z']], '10' => ['r' => 'groupe', 'm' => 'x']]);
$ok($rp == [5 => ['r' => 'oui'], 6 => ['r' => 'doublon', 'de' => 5], 9 => ['r' => 'groupe', 'm' => [5, 6]], 10 => ['r' => 'groupe']], 'réponses (doublon sans commande gardée ignoré) : ' . json_encode($rp));
$dt = $A::detecteurs([['nom' => ' RDJ ', 'cmd' => '12', 'm' => ['5', 6, 6, 0, 'x']], ['nom' => '', 'm' => [5]], ['nom' => 'Vide', 'm' => []], 'x', ['nom' => 'Libre', 'cmd' => -3, 'm' => [7]]]);
$ok($dt == [['nom' => 'RDJ', 'cmd' => 12, 'm' => [5, 6]], ['nom' => 'Libre', 'cmd' => 0, 'm' => [7]]], 'détecteurs : nom, commande facultative, lampes');
$ok($A::memeDetecteur($dt, 6, 5) && !$A::memeDetecteur($dt, 5, 7) && count($A::detecteurs(array_fill(0, 40, ['nom' => 'a', 'm' => [1]]))) == 30, 'détecteurs : même détecteur, 30 au plus');
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

// 0.10 : escalier dessiné sur chaque étage avant la 0.10 (Palier ↔ Salon Rue, deux fois) = un seul, 2e tracé en pts2 ; porte et escalier Tableau ↔ atelier gardés
$pcs = [['id' => 'pal', 'e' => 'p1'], ['id' => 'sr', 'e' => 'p0'], ['id' => 'tab', 'e' => 'p0'], ['id' => 'at', 'e' => 'pm'], ['id' => 'en', 'e' => 'pm']];
$fu = $A::fusionner([['id' => 'x1', 'e' => 'p1', 'a' => 'pal', 'b' => 'sr', 't' => 'escalier', 'pts' => [[1, 1], [2, 2]]],
  ['id' => 'x2', 'e' => 'p0', 'a' => 'sr', 'b' => 'pal', 't' => 'escalier', 'pts' => [[3, 3], [4, 4], [5, 5]]],
  ['id' => 'x3', 'e' => 'p0', 'a' => 'tab', 'b' => 'at', 't' => 'escalier', 'pts' => [[6, 6], [7, 7]]],
  ['id' => 'x4', 'e' => 'pm', 'a' => 'at', 'b' => 'en', 't' => 'couloir', 'pts' => [[8, 8], [9, 9]], 'pts2' => [[1, 1], [1, 2]]],
  ['id' => 'x5', 'e' => 'pm', 'a' => 'at', 'b' => 'en', 't' => 'couloir', 'pts' => [[8, 1], [9, 1]]]], $pcs);
$ok(array_column($fu, 'id') == ['x1', 'x3', 'x4', 'x5'] && $fu[0]['pts2'] == [[3, 3], [4, 4], [5, 5]] && !isset($fu[1]['pts2']) && !isset($fu[2]['pts2'])
  && $A::autreEtage($fu[1], array_column($pcs, 'e', 'id')) === 'pm' && $A::fusionner($fu, $pcs) == $fu, 'escaliers fusionnés : ' . json_encode($fu));
$ok($A::actif('On') && $A::actif(1) && !$A::actif('0') && !$A::actif('Absent ?') && !$A::actif(''), 'actif');
// états en texte (0.9) : ouvert / fermé reconnus, stop / my ignorés ; sens inversé (0 = ouvert)
$ok($A::actif('Open') && $A::actif('ouvert') && $A::actif('UP') && !$A::actif('closed') && !$A::actif('fermé') && $A::neutre('Stop') && $A::neutre('my') && !$A::neutre('closed') && !$A::neutre(0), 'états en texte');
$ok($A::inverser([[1, 0], [2, 100, true], [3, 40]]) == [[1, 100], [2, 0, true], [3, 60]] && $A::inverser([]) == [] && $A::inverser([[1, 0], [2, 0]]) == [[1, 1], [2, 1]] && $A::inverser([[1, 0], [2, 80]]) == [[1, 100], [2, 20]] && !$A::neutre('unknown'), 'sens inversé (échelle de la commande)');
$pe = $A::periodes("2026-10-15 15:27 → 2026-10-23 12:42\nn'importe quoi\n2026-11-01 - 2026-11-02");
$ok(count($pe) == 2 && $pe[0][0] == $t('2026-10-15 15:27') && $pe[1][1] == $t('2026-11-02 23:59:59'), 'périodes : ' . json_encode($pe));
echo "calcul OK\n";

// moteur de la simulation (aléa à graine : rejouable)
$M = 'presencehabituelle_moteur'; mt_srand(42); $r = fn() => mt_rand() / (mt_getrandmax() + 1);
$ok($M::sources([['nom' => 'Palier', 'ids' => [1]]], [], ['Palier' => 'Palier Lampe Chambre 1'], 10)['Palier']['type'] == 'passage' && $M::typePiece('Salle de bains') == 'sdb' && $M::typePiece('Lampe Cuisine') == 'cuisine' && $M::typePiece('Séjour') == 'salon' && $M::typePiece('Grenier') == 'autre', 'type de pièce');
$pv = $M::provisoire('salon', 'S'); $ok($pv[20] == 85 && $pv[18] == 85 && $pv[17] == 0 && $pv[12] == 0, 'profil provisoire : ' . json_encode($pv));
$prof = array_fill(0, 24, 0); for ($i = 19; $i < 23; $i++) $prof[$i] = 90;
$gs = [['nom' => 'Salon', 'ids' => [1, 2], 'jours' => 20, 'nS' => 14, 'nW' => 6, 'lS' => $prof, 'lW' => $prof, 'confirmerW' => 1, 'lampes' => ['A', 'B'], 'lids' => [1, 2], 'scenes' => ['A + B' => 70]],
  ['nom' => 'Cuisine', 'ids' => [3], 'jours' => 0], ['nom' => 'Cave', 'ids' => [4], 'jours' => 20, 'nS' => 14, 'lS' => $prof]];
$rg = [4 => ['jamais' => 1, 'plages' => []], 1 => ['jamais' => 0, 'plages' => [['k' => 'marche', 'j' => 'tous', 'de' => '19:30', 'a' => '22:00']]]];
$src = $M::sources($gs, $rg, [], 10);
$ok($src['Salon']['src'] == ['S' => 'hist', 'W' => 'prov'] && $src['Cuisine']['src']['S'] == 'prov' && $src['Cuisine']['type'] == 'cuisine' && $src['Cave']['src']['S'] == 'jamais' && $src['Salon']['scenes'] == [[[1, 2], 70]], 'sources : ' . json_encode(array_column($src, 'src')));
$ok($M::sources([['nom' => 'X', 'ids' => [5], 'jours' => 20, 'nS' => 9, 'lS' => $prof]], [], [], 10)['X']['src']['S'] == 'prov' && $M::sources([['nom' => 'X', 'ids' => [5], 'jours' => 20, 'nS' => 10, 'lS' => $prof]], [], [], 10)['X']['src']['S'] == 'hist', 'provisoire → historique au seuil');
$ok(!$M::permis([['k' => 'exclue', 'j' => 'S', 'de' => '23:00', 'a' => '06:00']], 'S', 60) && $M::permis([['k' => 'exclue', 'j' => 'S', 'de' => '23:00', 'a' => '06:00']], 'W', 60) && !$M::permis($rg[1]['plages'], 'S', 19 * 60) && $M::permis($rg[1]['plages'], 'S', 20 * 60), 'plages permises');
$sun = date_sun_info($t('2026-03-02 12:00'), 48.85, 2.35);
$ok($M::penombre($t('2026-03-02 13:00'), $sun) == 0 && $M::penombre($t('2026-03-02 23:00'), $sun) == 1 && $M::penombre(0, [], 200) == 1 && $M::penombre(0, [], 1200) == 0, 'pénombre');
$pvo = $M::planVolets([['id' => 9, 'nom' => 'V', 'jours' => 0]], [], 'S', 'S', 400, 1100, $r, 10);
$ok($pvo[9]['o'] >= 430 && $pvo[9]['o'] <= 460 && $pvo[9]['f'] >= 1120 && $pvo[9]['f'] <= 1150 && $M::planVolets([['id' => 9, 'nom' => 'V', 'jours' => 20, 'soir' => ['S' => ['n' => 5, 'q25' => 1200, 'q75' => 1200, 'ferme' => 5]]]], [], 'S', 'S', 400, 1100, $r, 10)[9]['f'] === null, 'volets du jour : ' . json_encode($pvo));
// une semaine de passages : jamais hors des plages, au plus « max » pièces allumées, volet fermé le soir et rouvert le matin, l'arrêt éteint tout
$src = $M::sources([$gs[0], $gs[1], ['nom' => 'Chambre', 'ids' => [6], 'jours' => 0], ['nom' => 'Bureau', 'ids' => [7], 'jours' => 0]], $rg, [], 10);
$E = []; $vu = []; $fv = $ov = 0; $max = 0; $nb = 0;
for ($x = $t('2026-03-02 04:00'); $x < $t('2026-03-09 04:00'); $x += 300) {
  $jd = date('Y-m-d', $x - 4 * 3600);
  if (($E['jour']['d'] ?? '') != $jd) $E['jour'] = ['d' => $jd, 'dec' => 0, 'f' => 1.3, 'volets' => $M::planVolets([['id' => 9, 'nom' => 'V', 'jours' => 0]], [], 'S', 'S', 400, 1100, $r, 10)];
  $m = (int)date('G', $x) * 60 + (int)date('i', $x); $k = $A::cle((int)date('N', $x), (int)date('G', $x), (int)date('i', $x));
  [$E, $d] = $M::pas($E, ['t' => $x, 'm' => $m, 'k' => $k, 'pk' => 1, 'sources' => $src, 'max' => 2, 'sansJour' => [], 'regles' => $rg], $r);
  foreach ($d as $y) {
    if ($y['action'] == 'on') { $nb++; $ok($y['nom'] != 'Salon' || ($m >= 19 * 60 + 30 && $m < 22 * 60), 'Salon allumé hors de sa plage à ' . date('D H:i', $x)); }
    if ($y['action'] == 'off' && $y['nom'] == 'Salon') $ok($y['motif'] != 'plage' || $m == 22 * 60, 'Salon : fin de plage à ' . date('H:i', $x));
    if ($y['action'] == 'fermer') { $fv++; $ok($m == $E['jour']['volets'][9]['f'] - $E['jour']['volets'][9]['f'] % 5 + ($E['jour']['volets'][9]['f'] % 5 ? 5 : 0), 'volet fermé à ' . date('H:i', $x)); }
    if ($y['action'] == 'ouvrir') { $ov++; $ok($m >= 430 && $m <= 465, 'volet ouvert à ' . date('H:i', $x)); }
  }
  $max = max($max, count(array_filter($E['g'], fn($e) => $e['on'])));
}
$ok($nb > 20 && $max <= 2 && $fv == 7 && $ov == 6, "semaine simulée : $nb allumages, au plus $max pièces, volet fermé $fv / ouvert $ov fois");
// longue durée tirée (cuisine allumée des heures) : éteinte à la 1re heure jamais allumée, pas à la fin de la durée
$pc = array_fill(0, 24, 0); for ($i = 17; $i < 24; $i++) $pc[$i] = 80; $pc[0] = 6;
$sc = $M::sources([['nom' => 'Cuisine', 'ids' => [3], 'jours' => 20, 'nS' => 14, 'nW' => 6, 'lS' => $pc, 'lW' => $pc, 'durees' => ['n' => 9, 'q25' => 600, 'q75' => 700, 'passages' => 0]]], [], [], 10);
$Ec = ['jour' => ['d' => 'x', 'dec' => 0, 'f' => 1, 'volets' => []], 'g' => ['Cuisine' => ['on' => 1, 'fin' => $t('2026-03-03 04:00'), 'l' => [3], 'pause' => 0, 'vis' => 0]]]; $hc = null;
for ($x = $t('2026-03-02 23:00'); $x < $t('2026-03-03 04:00') && $hc === null; $x += 300) {
  [$Ec, $d] = $M::pas($Ec, ['t' => $x, 'm' => (int)date('G', $x) * 60 + (int)date('i', $x), 'k' => 'S', 'pk' => 1, 'sources' => $sc, 'max' => 3, 'sansJour' => [], 'regles' => []], $r);
  foreach ($d as $y) if ($y['action'] == 'off') $hc = date('H:i', $x) . ' ' . $y['motif'];
}
$ok($hc === '01:00 jamais', 'cuisine éteinte à la 1re heure jamais allumée : ' . $hc);
$Ec['jour']['ex'] = true; $Ec['g']['Cuisine'] = ['on' => 1, 'fin' => $t('2026-03-03 03:00'), 'l' => [3], 'pause' => 0, 'vis' => 0]; $hx = null;
for ($x = $t('2026-03-02 23:00'); $x < $t('2026-03-03 05:00') && $hx === null; $x += 300) {
  [$Ec, $d] = $M::pas($Ec, ['t' => $x, 'm' => (int)date('G', $x) * 60 + (int)date('i', $x), 'k' => 'S', 'pk' => 1, 'sources' => $sc, 'max' => 3, 'sansJour' => [], 'regles' => []], $r);
  foreach ($d as $y) if ($y['action'] == 'off') $hx = date('H:i', $x);
}
$ok($hx >= '03:00', "jour d'exception : cuisine allumée jusqu'au bout de sa durée ($hx)");
$nx = 0; for ($i = 0; $i < 3000; $i++) $nx += $M::jour($r)['ex'] ? 1 : 0; $ok($nx > 50 && $nx < 150, "jours d'exception : $nx sur 3000");
$E['g']['Chambre'] = ['on' => 1, 'fin' => 0, 'l' => [6], 'pause' => 0, 'vis' => 0];
$ar = $M::arret($E); $ok($ar && !array_filter($ar, fn($y) => $y['action'] != 'off') && count($ar) == count(array_filter($E['g'], fn($e) => $e['on'])), "l'arrêt éteint tout");
[$Ev, $dv] = $M::visiteurs(['g' => ['Salon' => ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => 0, 'vis' => 0]]], $src, [1 => true], 1000, 60);
$ok($Ev['g']['Salon']['vis'] == 1000 + 3600 && !$dv, 'visiteur : pièce allumée par quelqu\'un, laissée 1 h');
[$Ev, $dv] = $M::visiteurs([], $src, [1 => true], 1000, 60);
$ok(($Ev['g']['Salon']['vis'] ?? 0) == 1000 + 3600, 'visiteur : lampe déjà allumée au 1er passage après On');
[$Ev, $dv] = $M::visiteurs(['g' => ['Salon' => ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => 0, 'vis' => 0, 'off' => 800, 'lo' => [1]]]], $src, [1 => true], 1000, 60);
$ok(count($dv) == 1 && $dv[0]['action'] == 'off' && $dv[0]['motif'] == 'renvoi' && $dv[0]['ids'] == [1] && empty($Ev['g']['Salon']['vis']), 'off perdu renvoyé, pas pris pour un visiteur : ' . json_encode($dv));
[$Ev, $dv] = $M::visiteurs(['g' => ['Salon' => ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => 0, 'vis' => 0, 'off' => 100, 'lo' => [1]]]], $src, [1 => true], 1000, 60);
$ok(!$dv && $Ev['g']['Salon']['vis'] == 4600, 'off de plus de 10 min : visiteur');
// pièce sortie des sources : éteinte et oubliée ; passage éclair : éteint au passage suivant
$Ed = ['jour' => ['d' => 'x', 'dec' => 0, 'f' => 1, 'volets' => []], 'g' => ['Partie' => ['on' => 1, 'fin' => 9e9, 'l' => [42], 'pause' => 0, 'vis' => 0], '12' => ['on' => 1, 'fin' => 9e9, 'l' => [43], 'pause' => 0, 'vis' => 0, 'ec' => 1]]];
$s12 = ['12' => ['nom' => '12', 'ids' => [43], 'type' => 'autre', 'src' => ['S' => 'prov', 'W' => 'prov'], 'profil' => ['S' => array_fill(0, 24, 50), 'W' => array_fill(0, 24, 50)], 'plages' => [], 'd' => [5, 5], 'pas' => 0, 'scenes' => []]];
[$Ed, $d] = $M::pas($Ed, ['t' => 1000, 'm' => 1200, 'k' => 'S', 'pk' => 1, 'sources' => $s12, 'max' => 3, 'sansJour' => ['12'], 'regles' => []], $r);
$mo = array_column($d, 'motif', 'nom');
$ok(($mo['Partie'] ?? '') == 'disparu' && !isset($Ed['g']['Partie']) && ($mo[12] ?? '') == 'eclair' && $Ed['g'][12]['lo'] == [43], 'pièce disparue éteinte, passage éclair 5 min : ' . json_encode($d));
// volet : pas rouvert le soir (plage « peut » du soir) ; sorti du plan alors que fermé : rouvert le matin, pas la nuit
$pv = [['k' => 'marche', 'j' => 'tous', 'de' => '17:00', 'a' => '23:00']];
$Ev = ['jour' => ['d' => 'j2', 'dec' => 0, 'f' => 1, 'volets' => [9 => ['nom' => 'V', 'o' => 450, 'f' => 1300, 'src' => 'prov/prov']]], 'g' => [], 'v' => [9 => ['ferme' => 1, 'fj' => 'j1']]];
[$Ev2, $d] = $M::pas($Ev, ['t' => 1000, 'm' => 1100, 'k' => 'S', 'pk' => 0, 'sources' => [], 'max' => 3, 'sansJour' => [], 'regles' => [9 => ['plages' => $pv]]], $r);
$ok(!$d && $Ev2['v'][9]['ferme'] == 1, 'volet : pas ouvert le soir par une plage « peut » : ' . json_encode($d));
$Ev['jour']['volets'] = []; $Ev['v'][9]['nom'] = 'V';
[$Ev2, $d] = $M::pas($Ev, ['t' => 1000, 'm' => 120, 'k' => 'S', 'pk' => 1, 'sources' => [], 'max' => 3, 'sansJour' => [], 'regles' => []], $r);
$ok(!$d && $Ev2['v'][9]['ferme'] == 1, 'volet sorti du plan : pas rouvert la nuit');
[$Ev2, $d] = $M::pas($Ev, ['t' => 1000, 'm' => 480, 'k' => 'S', 'pk' => 1, 'sources' => [], 'max' => 3, 'sansJour' => [], 'regles' => []], $r);
$ok(count($d) == 1 && $d[0]['action'] == 'ouvrir' && $d[0]['motif'] == 'hors plan' && !isset($Ev2['v'][9]), 'volet sorti du plan : rouvert à 08:00 et oublié');
// plage exclue « sauf exception » : permise seulement un jour d'exception ; une plage sans x reste interdite
$px = [['k' => 'exclue', 'j' => 'tous', 'de' => '00:30', 'a' => '06:00', 'x' => 1]];
$ok(!$M::permis($px, 'S', 120) && $M::permis($px, 'S', 120, true) && !$M::permis([['k' => 'exclue', 'j' => 'tous', 'de' => '00:30', 'a' => '06:00']], 'S', 120, true), 'plage sauf exception');
$ok(($A::regles([5 => ['plages' => [$px[0], ['k' => 'marche', 'j' => 'tous', 'de' => '08:00', 'a' => '09:00', 'x' => 1]]]])[5]['plages'] ?? []) == [$px[0], ['k' => 'marche', 'j' => 'tous', 'de' => '08:00', 'a' => '09:00']], 'x gardé sur une plage exclue seulement');
// recalage : bilan, coefficients appliqués, tour d'ajustement
$jb = [['d' => '2026-03-02', 'a' => $t('2026-03-02'), 'b' => $t('2026-03-03')], ['d' => '2026-03-03', 'a' => $t('2026-03-03'), 'b' => $t('2026-03-04')]];
$bl = $A::bilan([[$t('2026-03-02 19:00'), $t('2026-03-02 21:00')], [$t('2026-03-03 23:00'), $t('2026-03-04 01:00')]], $jb);
$ok($bl['h'] == 1.5 && $bl['n'] == 1 && $bl['mn'] == 90 && $bl['p'][19] == 50 && $bl['p'][23] == 50 && $bl['p'][0] == 0, 'bilan : ' . json_encode($bl));
$sc = $M::caler(['X' => ['profil' => ['S' => [0, 40], 'W' => [10, 0]], 'd' => [10, 20]]], ['X' => ['cf' => 2, 'cd' => .5], 'Y' => ['cf' => 3]]);
$ok($sc['X']['profil']['S'] == [0, 40] && $sc['X']['cf'] == 2 && $sc['X']['d'] == [5, 10] && !isset($sc['Y']), 'caler');
[$cf, $v] = $M::ajuster(['A' => ['h' => 4, 'n' => 2], 'B' => ['h' => 2, 'n' => 1], 'C' => ['h' => 1, 'n' => 1]], ['A' => ['h' => 1, 'n' => .5], 'B' => ['h' => 2.1, 'n' => 1.1], 'C' => ['h' => 1, 'n' => 1]], ['C' => ['cf' => 3, 'cd' => 1]], [.15, .25]);
$ok(!$v['A']['ok'] && $cf['A'] == ['cf' => 2.0, 'cd' => 1.0] && $v['B']['ok'] && $cf['B'] == ['cf' => 1, 'cd' => 1] && $v['C']['ok'], 'ajuster : ' . json_encode([$cf, $v]));
[$cf, $v] = $M::ajuster(['A' => ['h' => 9, 'n' => 9]], ['A' => ['h' => 1, 'n' => 1]], ['A' => ['cf' => 3, 'cd' => 3]], [.15, .25]);
$ok(!$v['A']['ok'] && $v['A']['borne'] && $cf['A'] == ['cf' => 3.0, 'cd' => 3.0], 'ajuster : borne');
[$cf, $v] = $M::ajuster(['A' => ['h' => 2, 'n' => 1]], ['A' => ['h' => 0, 'n' => 0]], [], [.15, .25]);
$ok($cf['A']['cf'] == 3 && !$v['A']['borne'], 'ajuster : jamais allumée dans le rejeu');
[$cf, $v] = $M::ajuster(['A' => ['h' => 0, 'n' => 0]], ['A' => ['h' => 1, 'n' => 1]], [], [.15, .25]);
$ok(round($cf['A']['cf'], 3) == .333 && !$v['A']['borne'], 'ajuster : jamais allumée en vrai');
[, $v] = $M::ajuster(['A' => ['h' => 2, 'n' => 1]], ['A' => ['h' => 4, 'n' => 1.1]], [], [INF, .25]);
$ok($v['A']['ok'], 'ajuster : contrôle sur les allumages seulement');
[, $v] = $M::ajuster(['A' => ['h' => 4, 'n' => 4]], ['A' => ['h' => 1, 'n' => 1]], ['A' => ['cf' => 3, 'cd' => 1]], [.15, .25]);
$ok($v['A']['borne'], 'ajuster : cf à sa limite, allumages trop rares');
echo "moteur OK\n";

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
    if (basename($f) != 'presencehabituelle_analyse.class.php' && preg_match_all('/\$(w|wc)\s*=(?!=)\s*([^;]+)/', $l, $af, PREG_SET_ORDER)) foreach ($af as $x) // $w / $wc = l'équipement du plugin ou ses commandes, rien d'autre
      $ok(preg_match('/^(\$P::equipement\(|self::equipement\(|eqLogic::byLogicalId\(\'widget\', __CLASS__\)|new self\(\)|new presencehabituelleCmd\(\)|\$w->getCmd\(null, \'maintenant\'\))/', trim($x[2])), "\$$x[1] n'est pas l'équipement du plugin ($o)");
    if (preg_match('#// ph:(propre|historiser|envoyer)$#', rtrim($l), $mq)) { // écritures permises : le widget du plugin ($w, $wc) ; l'historique à la demande (bouton + sauvegarde)
      $ok(in_array(basename($f), $mq[1] == 'propre' ? ['presencehabituelle.class.php', 'presencehabituelle.ajax.php'] : ['presencehabituelle.class.php']), "marque $mq[1] hors de sa place ($o)");
      if ($mq[1] == 'envoyer') $ok(trim($l) == '$c->execCmd($o); // ph:envoyer', "envoi : seulement la commande d'action trouvée ($o)");
      $marques[$mq[1]] = ($marques[$mq[1]] ?? 0) + 1;
      // seul l'appel permis est ôté ; le reste de la ligne passe les mêmes contrôles
      $l = $mq[1] == 'envoyer' ? '' : ($mq[1] == 'propre' ? preg_replace('/\$wc?->\w+\(/', '(', $l) : preg_replace('/\$c->(setIsHistorized|setConfiguration\(\'historizeMode\'|save)\(?/', '(', $l));
    }
    $b = preg_replace_callback('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/', fn($m) => str_repeat('x', strlen($m[0])), $l); // chaînes masquées : un « // » dans une chaîne n'ouvre pas un commentaire
    if (preg_match('#^\s*/?\*|\s//#', $b, $cm, PREG_OFFSET_CAPTURE)) $l = substr($l, 0, $cm[0][1]); // commentaires ôtés : « history::archive » cité n'est pas un appel
    if (basename($f) != 'presencehabituelle_analyse.class.php') $ok(!preg_match('/\bas\s+(\$\w+\s*=>\s*)?\$wc?\b|\[[^\]]*\$wc?\b[^\]]*\]\s*=(?!=)/', $l), "\$w / \$wc affecté par une boucle ou une déstructuration ($o)");
    $ok(!preg_match('/->(save|remove)\(/', preg_replace('/\$l->(save|remove)\(\)/', '', $l)), "enregistrement ou suppression d'un objet de la box ($o)");
    $ok(!preg_match('/->(set[A-Z]\w*|event|checkAndUpdateCmd|addHistoryValue)\(/', preg_replace('/\$l->set(Class|Function|Option)\(/', '(', $l)), "modification d'un objet de la box ($o)"); // tout setter, sauf ceux de l'écouteur du plugin ($l)
    $ok(!preg_match('/(history|scenario|eqLogic|cmd|object|jeeObject|plan|planHeader|interactDef|cron|message|log)::(remove|save|archive|removes|clean|set)/i', $l), "écriture par le core ($o)");
    $ok(!preg_match('/->execCmd\(/', $l) || strpos($l, "getType() == 'info'") !== false, "commande exécutée sans être une info ($o)");
    $ok(!preg_match('/config::(save|remove)\(/', $l) || preg_match('/__CLASS__\)|, \$P\)/', $l), "configuration hors du plugin ($o)");
    $ok(!preg_match('/[\'"]\s*(DELETE|UPDATE|INSERT|DROP|ALTER|TRUNCATE|REPLACE|CREATE)\b/i', $l) || strpos($l, 'self::TABLE') !== false, "écriture SQL hors de la table du plugin ($o)");
  }
}
$ok(($marques['historiser'] ?? 0) == 6, 'écritures « historiser » : 6 attendues (historiser, remettre)');
$ok(($marques['propre'] ?? 0) == 13, 'écritures « propre » : 13 attendues (equipement, majWidget, ajax widget) : ' . ($marques['propre'] ?? 0));
$ok(($marques['envoyer'] ?? 0) == 1, 'envoi aux équipements : 1 ligne attendue (envoyer)');
$cl = file_get_contents(__DIR__ . '/../core/class/presencehabituelle.class.php');
// bascule de l'envoi réel, état enregistré avant d'agir (ces fonctions écrivent la configuration : lues ici, pas exécutées)
$ok(preg_match('/function effacer\(\) \{[^}]*config::remove\(\'calage\', __CLASS__\);/', $cl) === 1, 'Tout effacer vide aussi le calage (profil réel par pièce)');
$ok(preg_match('/^\s*\$haut = \(float\)config::byKey\(\'lux_haut\'/m', $cl) === 1, 'seuil haut de luminosité lu (pas avalé par un commentaire)');
$ok(strpos($cl, 'public static function preConfig_reel($v)') !== false && strpos($cl, 'if ($avant) self::arretMoteur(); else config::remove(\'moteur\', __CLASS__);') !== false, 'envoi réel décoché : extinction ; coché : nouveau départ');
$ok(preg_match('/config::save\(\'moteur\'[^\n]*\n\s*self::agir\(\$dec, \$reel\);/', $cl) && preg_match('/config::remove\(\'moteur\'[^\n]*\n\s*self::agir\(presencehabituelle_moteur::arret/', $cl), 'état enregistré avant d\'envoyer');
$ok(preg_match('/public static function envoyer\(array \$d\) \{\s*if \(self::blanc\(\)\) return /', $cl) && strpos($cl, "function blanc() { return (string)config::byKey('reel', __CLASS__, '0') !== '1'; }") !== false, 'à blanc par défaut, et rien n\'est envoyé à blanc');
foreach (['core/ajax/presencehabituelle.ajax.php', 'desktop/php/presencehabituelle.php', 'plugin_info/configuration.php'] as $f)
  $ok(preg_match("/if \\(!isConnect\\('admin'\\)\\)/", file_get_contents(__DIR__ . '/../' . $f)), "accès administrateur seulement : $f");
foreach (['data', 'tests', 'plugin_info'] as $d) $ok(preg_match('/Require all denied/', (string)@file_get_contents(__DIR__ . "/../$d/.htaccess")), "$d/.htaccess refuse l'accès (Apache 2.4)");
echo 'aucune modification des objets de la box OK (sauf widget du plugin : ' . ($marques['propre'] ?? 0) . ' lignes ; historique à la demande : ' . ($marques['historiser'] ?? 0) . " lignes)\n";

if (is_file(__DIR__ . '/../../../core/php/core.inc.php')) { // sur la box : analyse réelle, rien n'est écrit sauf data/habitudes.json et les valeurs du widget du plugin
  require_once __DIR__ . '/../core/class/presencehabituelle.class.php';
  $r = presencehabituelle::analyser();
  echo 'analyse OK : ' . count($r['groupes']) . ' groupes, ' . count($r['volets']) . " volets\n";
}

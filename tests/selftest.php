<?php
/* Contrôles : calcul des habitudes sur données synthétiques, aucun appel réseau dans le code, puis (sur la box) analyse réelle sans rien écrire.
   Usage : sudo -u www-data php tests/selftest.php */
require_once __DIR__ . '/../core/class/presencehabituelle_analyse.class.php';
$ok = function ($v, $m) { if (!$v) { echo "ECHEC : $m\n"; exit(1); } }; // assert() est désactivé en production
$A = 'presencehabituelle_analyse';
date_default_timezone_set('Europe/Paris');
$t = fn($s) => strtotime($s);

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

// journée 04:00 → 04:00 : dim. 22 h = semaine, sam. 01 h = soir du vendredi = week-end, lun. 01 h = soir du dimanche = semaine
$ok($A::cle(7, 22) == 'S' && $A::cle(6, 1) == 'W' && $A::cle(1, 1) == 'S' && $A::cle(5, 22) == 'W' && $A::cle(5, 10) == 'S' && $A::cle(7, 10) == 'W', 'journée 04:00');
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

if (is_file(__DIR__ . '/../../../core/php/core.inc.php')) { // sur la box : analyse réelle, rien n'est écrit sauf data/habitudes.json
  require_once __DIR__ . '/../core/class/presencehabituelle.class.php';
  $r = presencehabituelle::analyser();
  echo 'analyse OK : ' . count($r['groupes']) . ' groupes, ' . count($r['volets']) . " volets\n";
}

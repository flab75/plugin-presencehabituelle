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

// profil : 19 h et 22 h allumés le lundi seulement → 25 % des 4 jours de semaine
$p = $A::profil($se, $j);
$ok($p['nS'] == 4 && $p['nW'] == 0 && $p['S'][19] == 25 && $p['S'][22] == 25 && $p['S'][20] == 0 && $p['S'][21] == 0, 'profil : ' . json_encode($p['S']));
$l = $A::lisser($p['S']);
$ok($l[19] == 13 && $l[20] == 0 && $l[22] == 13, 'lissage : ' . json_encode($l));

$d = $A::durees($se, $j);
$ok($d['n'] == 2 && $d['med'] == 30.5 && $d['passages'] == 50, 'durées : ' . json_encode($d));
$sc = $A::scenes($se, $j);
$ok($sc == ['A + B' => 50, 'C' => 50], 'scènes : ' . json_encode($sc));

// volet : ouvert 07:30 en semaine, fermé partiellement l'après-midi (pas une fermeture), fermé 21:00 ; samedi ouvert 09:00, fermé 23:30
$ev = []; foreach (['02', '03', '04', '05', '06'] as $x) array_push($ev, ["2026-03-$x 07:30", 100], ["2026-03-$x 14:00", 30], ["2026-03-$x 21:00", 0]);
array_push($ev, ['2026-03-07 09:00', 100], ['2026-03-07 23:30', 0], ['2026-03-08 00:10', 100], ['2026-03-08 00:20', 0]);
$ev = array_map(fn($e) => [$t($e[0]), $e[1], true], $ev);
$v = $A::volet($ev, $A::jours($t('2026-03-01 12:00'), $t('2026-03-09 08:00'), []));
$ok($v['matin']['S']['med'] == 450 && $v['matin']['S']['n'] == 4 && $v['soir']['S']['med'] == 1260 && $v['soir']['S']['ferme'] == 100, 'volet semaine : ' . json_encode($v));
$ok($v['matin']['W']['med'] == 540 && $v['soir']['W']['n'] == 2 && $v['soir']['W']['med'] == 1360 && $v['soir']['W']['ferme'] == 100, 'volet week-end : ' . json_encode($v));

$ok($A::actif('On') && $A::actif(1) && !$A::actif('0') && !$A::actif('Absent ?') && !$A::actif(''), 'actif');
$pe = $A::periodes("2026-10-15 15:27 → 2026-10-23 12:42\nn'importe quoi\n2026-11-01 - 2026-11-02");
$ok(count($pe) == 2 && $pe[0][0] == $t('2026-10-15 15:27') && $pe[1][1] == $t('2026-11-02 23:59:59'), 'périodes : ' . json_encode($pe));
echo "calcul OK\n";

// confidentialité : aucun appel réseau dans le code du plugin
$motifs = ['http' . '://', 'https' . '://', 'curl' . '_', 'fsock' . 'open', 'stream' . '_socket', 'com' . '_http', 'file_get_contents(\'h', 'fetch(\'h'];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
  if (!preg_match('/\.(php|js)$/', $f) || strpos($f, '/docs/') !== false) continue;
  $c = file_get_contents($f);
  foreach ($motifs as $m) $ok(strpos($c, $m) === false, "appel réseau possible ($m) dans " . basename($f));
}
echo "aucun appel réseau OK\n";

if (is_file(__DIR__ . '/../../../core/php/core.inc.php')) { // sur la box : analyse réelle, rien n'est écrit sauf le résultat
  require_once __DIR__ . '/../core/class/presencehabituelle.class.php';
  $r = presencehabituelle::analyser();
  echo 'analyse OK : ' . count($r['groupes']) . ' groupes, ' . count($r['volets']) . " volets\n";
}

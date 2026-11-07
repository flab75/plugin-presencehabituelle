<?php
/* Calcul des habitudes : fonctions pures (aucun accès à Jeedom), testées par tests/selftest.php.
   Évènement = [horodatage, valeur, exact] ; seuls des changements exacts sont relevés : exact = false seulement pour une session encore en cours (durée inconnue). */
class presencehabituelle_analyse {
  const SCENE = 30;    // s : lampes allumées à moins de SCENE s du début de la session = même scène
  const PASSAGE = 180; // s : session plus courte = passage éclair
  const RARE = 10;     // % : heure allumée au plus ce % des jours = exception
  const ECART = 30;    // min : écart minimal à la moitié centrale pour parler d'exception

  // Lampe : intervalles allumés [début, fin, exact], valeur > 0 = allumée ; la dernière session est coupée à $fin
  public static function intervalles(array $ev, $fin) {
    $r = []; $deb = null;
    foreach ($ev as [$t, $v, $x]) {
      if ($v > 0 && $deb === null) $deb = [$t, $x];
      elseif ($v <= 0 && $deb !== null) { $r[] = [$deb[0], $t, $deb[1] && $x]; $deb = null; }
    }
    if ($deb !== null && $fin > $deb[0]) $r[] = [$deb[0], $fin, false]; // encore allumée : durée inconnue
    return $r;
  }

  /* Sessions trop longues (extinction manquée : box arrêtée, pont Zigbee hors ligne…) : retirées, et renvoyées pour exclure les jours touchés.
     → [intervalles gardés, [[début, fin]] retirés] */
  public static function longues(array $iv, $max) {
    $g = []; $x = [];
    foreach ($iv as $i) if ($i[1] - $i[0] > $max) $x[] = [$i[0], $i[1]]; else $g[] = $i;
    return [$g, $x];
  }

  /* Groupe de lampes : sessions = au moins une lampe allumée.
     $parLampe = [lampe => intervalles] → [[début, fin, exact, [lampes allumées au début]]] */
  public static function sessions(array $parLampe) {
    $tous = [];
    foreach ($parLampe as $l => $iv) foreach ($iv as $i) $tous[] = [$i[0], $i[1], $i[2], (string)$l];
    usort($tous, fn($a, $b) => $a[0] <=> $b[0]);
    $r = [];
    foreach ($tous as [$a, $b, $x, $l]) {
      $n = count($r) - 1;
      if ($n >= 0 && $a <= $r[$n][1]) { // chevauche la session en cours
        $r[$n][1] = max($r[$n][1], $b); $r[$n][2] = $r[$n][2] && $x;
        if ($a - $r[$n][0] <= self::SCENE && !in_array($l, $r[$n][3], true)) $r[$n][3][] = $l;
      } else $r[] = [$a, $b, $x, [$l]];
    }
    return $r;
  }

  /* Jours comptés : du lendemain de $debut à la veille de $fin (jours entiers seulement), sauf ceux touchés par une exclusion.
     → [['d' => 'Y-m-d', 'a' => début, 'b' => fin, 'n' => 1..7 (ISO)]] */
  public static function jours($debut, $fin, array $excl) {
    $r = [];
    for ($a = strtotime('tomorrow', $debut); ($b = strtotime('+1 day', $a)) <= $fin; $a = $b) {
      foreach ($excl as [$x, $y]) if ($x < $b && $y > $a) continue 2;
      $r[] = ['d' => date('Y-m-d', $a), 'a' => $a, 'b' => $b, 'n' => (int)date('N', $a)];
    }
    return $r;
  }

  /* Semaine S ou week-end W de l'heure $h d'un jour ISO $n, comme pour les volets : journée de 04:00 à 04:00,
     matin (04 h-16 h) du sam. et du dim., soir (16 h-04 h) du ven. et du sam. ; 0 h-3 h = fin du soir de la veille */
  public static function cle($n, $h) {
    if ($h < 4) $n = $n == 1 ? 7 : $n - 1;
    return $h >= 4 && $h < 16 ? ($n >= 6 ? 'W' : 'S') : (in_array($n, [5, 6]) ? 'W' : 'S');
  }

  // % de $a sur $b, jamais arrondi à 0 ou 100 quand ce n'est ni jamais ni toujours
  public static function pc($a, $b) {
    return !$b ? 0 : ($a && $a < $b ? max(1, min(99, (int)round(100 * $a / $b))) : ($a ? 100 : 0));
  }

  /* % des jours où le groupe est allumé dans l'heure, 0 h..23 h, en semaine S / week-end W (cle()) ; ex = heures exceptionnelles.
     nS / nW = jours comptés (le plus grand des 24 heures : une heure du soir n'a pas tout à fait les mêmes jours qu'une heure du matin) */
  public static function profil(array $sessions, array $jours) {
    $on = [];
    foreach ($sessions as [$a, $b]) // ponytail: heure par heure, coût = durée totale allumée en heures ; suffisant sur 90 j
      for ($t = $a - ($a % 60); $t < $b; $t = strtotime(date('Y-m-d H:00:00', $t)) + 3600) $on[date('Y-m-d G', $t)] = true;
    $z = array_fill(0, 24, 0); $p = ['S' => $z, 'W' => $z]; $d = ['S' => $z, 'W' => $z];
    foreach ($jours as $j) for ($h = 0; $h < 24; $h++) {
      $k = self::cle($j['n'], $h); $d[$k][$h]++;
      if (isset($on[$j['d'] . ' ' . $h])) $p[$k][$h]++;
    }
    $p['nS'] = max($d['S']); $p['nW'] = max($d['W']);
    $p['cS'] = $p['S']; $p['cW'] = $p['W']; // comptes bruts : « jamais » se lit sur eux
    foreach (['S', 'W'] as $k) foreach ($p[$k] as $h => $v) $p[$k][$h] = self::pc($v, $d[$k][$h]);
    $p['ex'] = []; // exceptions : [jour, heure] allumé à une heure où il l'est au plus RARE % des jours
    foreach ($jours as $j) for ($h = 0; $h < 24; $h++) if (isset($on[$j['d'] . ' ' . $h]) && $p[self::cle($j['n'], $h)][$h] <= self::RARE) $p['ex'][] = [$j['d'], $h];
    return $p;
  }

  // Lissage 1-2-1 sur 24 h (minuit relié à 23 h) ; une heure jamais allumée reste à 0
  public static function lisser(array $p) {
    $r = [];
    for ($h = 0; $h < 24; $h++) $r[$h] = $p[$h] ? (int)round(($p[($h + 23) % 24] + 2 * $p[$h] + $p[($h + 1) % 24]) / 4) : 0;
    return $r;
  }

  /* Tendance d'une série [clé (date) => minutes] : n, plus tôt / court (min), q25, médiane, q75, plus tard / long (max), moyenne,
     ex = [clé => valeur] exceptions, hors de [q25 − e ; q75 + e] avec e = max(1,5 × (q75 − q25), ECART) (moustaches de Tukey, plancher contre une série trop régulière) */
  public static function stats(array $v, $prec = 0) {
    if (!$v) return ['n' => 0];
    $q1 = self::quantile($v, .25); $q3 = self::quantile($v, .75); $e = max(1.5 * ($q3 - $q1), self::ECART);
    $ex = count($v) >= 4 ? array_filter($v, fn($x) => $x < $q1 - $e || $x > $q3 + $e) : []; // ponytail: moins de 4 valeurs, rien n'est « exceptionnel »
    $r = ['min' => min($v), 'q25' => $q1, 'med' => self::quantile($v, .5), 'q75' => $q3, 'max' => max($v), 'moy' => array_sum($v) / count($v)];
    return ['n' => count($v)] + array_map(fn($x) => round($x, $prec), $r) + ['ex' => array_map(fn($x) => round($x, $prec), $ex)];
  }

  public static function quantile(array $v, $q) {
    if (!$v) return null;
    sort($v); $i = ($q * (count($v) - 1)); $f = (int)floor($i);
    return $v[$f] + ($i - $f) * (($v[$f + 1] ?? $v[$f]) - $v[$f]);
  }

  // Durées (min) des sessions exactes comprises dans les jours comptés (stats, exceptions par « date heure » de début) ; passages = % de sessions < PASSAGE
  public static function durees(array $sessions, array $jours) {
    $ok = []; foreach ($jours as $j) $ok[$j['d']] = true;
    $d = []; $pas = 0;
    foreach ($sessions as [$a, $b, $x]) if ($x && isset($ok[date('Y-m-d', $a)])) $d[date('Y-m-d H:i:s', $a)] = ($b - $a) / 60; // à la seconde : deux allumages dans la même minute restent deux
    foreach ($d as $v) if ($v * 60 < self::PASSAGE) $pas++;
    return self::stats($d, 1) + ($d ? ['passages' => (int)round(100 * $pas / count($d))] : []);
  }

  // Scènes : combinaisons de lampes allumées ensemble au début des sessions exactes → [« A + B » => %]
  public static function scenes(array $sessions, array $jours) {
    $ok = []; foreach ($jours as $j) $ok[$j['d']] = true;
    $n = [];
    foreach ($sessions as $s) if ($s[2] && isset($ok[date('Y-m-d', $s[0])])) { $l = $s[3]; sort($l); $k = implode(' + ', $l); $n[$k] = ($n[$k] ?? 0) + 1; }
    arsort($n); $t = array_sum($n);
    return array_map(fn($v) => (int)round(100 * $v / $t), $n);
  }

  /* Volet : journée de 04:00 à 04:00. Matin = 1re ouverture entre 04:00 et 16:00 d'un volet fermé à 04:00 (rouvrir après une fermeture contre la chaleur n'est pas un lever) ; soir = dernière fermeture entre 16:00 et 04:00.
     Fermé = position ≤ 1 % du maximum observé (une position partielle contre le soleil n'est pas une fermeture).
     Semaine / week-end : matins du sam. et dim., soirs du ven. et sam. (on se couche plus tard la veille d'un jour sans travail).
     Une nuit pas encore finie à $fin (le calcul tourne vers minuit), ou qui déborde sur une exclusion (lendemain d'un départ), ne compte pas pour le soir.
     → ['matin' => ['S' => stats, 'W' => stats], 'soir' => [...]] ; stats() en minutes depuis 00:00 (exceptions par date), jours = jours comptés dans la colonne,
       ferme = % des nuits où il est fermé à 04:00 (null sans nuit observée), nuits / fermees = comptes bruts (« toujours » / « jamais » se lisent sur eux) */
  public static function volet(array $ev, array $jours, $fin = PHP_INT_MAX, array $excl = []) {
    $max = 0; foreach ($ev as $e) $max = max($max, $e[1]);
    $seuil = $max * 0.01;
    $m = ['S' => [], 'W' => []]; $s = ['S' => [], 'W' => []]; $nsoir = ['S' => 0, 'W' => 0]; $fsoir = ['S' => 0, 'W' => 0]; $jm = ['S' => 0, 'W' => 0]; $js = ['S' => 0, 'W' => 0];
    foreach ($jours as $j) {
      $a = strtotime('04:00', $j['a']); $mid = strtotime('16:00', $j['a']); $b = strtotime('04:00', $j['b']); // heures d'horloge : justes les jours de changement d'heure
      $ferme = null; $mat = null; $soir = null; $f4 = null; $vu = false;
      foreach ($ev as [$t, $v]) {
        $f = $v <= $seuil;
        if ($t >= $a && !$vu) { $vu = true; $f4 = $ferme; } // état à 04:00
        if ($t >= $a && $t < $b && $ferme !== null && $f != $ferme) {
          if (!$f && $t < $mid && $mat === null && $f4) $mat = $t;
          if ($f && $t >= $mid) $soir = $t;
          if (!$f && $t >= $mid) $soir = null; // rouvert ensuite : la fermeture du soir est la dernière
        }
        if ($t >= $b) break;
        $ferme = $f;
      }
      $km = self::cle($j['n'], 4); $ks = self::cle($j['n'], 16);
      $jm[$km]++; if ($mat !== null) $m[$km][$j['d']] = self::minutes($mat, $j['d']);
      if ($b > $fin) continue; // nuit en cours
      foreach ($excl as [$x, $y]) if ($x < $b && $y > $a) continue 2; // le soir finit le lendemain à 04:00 : absent ce matin-là, la nuit ne dit rien
      $js[$ks]++;
      if ($ferme !== null) { $nsoir[$ks]++; if ($ferme) $fsoir[$ks]++; } // fermé à 04:00, même resté fermé toute la journée
      if ($soir !== null) $s[$ks][$j['d']] = self::minutes($soir, $j['d']);
    }
    $r = ['matin' => [], 'soir' => []];
    foreach (['S', 'W'] as $k) {
      $r['matin'][$k] = self::stats($m[$k]) + ['jours' => $jm[$k]];
      $r['soir'][$k] = self::stats($s[$k]) + ['jours' => $js[$k], 'nuits' => $nsoir[$k], 'fermees' => $fsoir[$k],
        'ferme' => $nsoir[$k] ? self::pc($fsoir[$k], $nsoir[$k]) : null]; // null : aucune nuit observée
    }
    return $r;
  }

  // Minutes d'horloge depuis 00:00 du jour $d (+1440 après minuit) : 07:30 = 450 même un jour de changement d'heure
  public static function minutes($t, $d) {
    return (int)date('G', $t) * 60 + (int)date('i', $t) + (date('Y-m-d', $t) > $d ? 1440 : 0);
  }

  // Valeur « active » d'une commande d'exclusion (On, 1, true, oui…)
  public static function actif($v) {
    return is_numeric($v) ? $v > 0 : in_array(mb_strtolower(trim((string)$v)), ['on', 'true', 'oui', 'yes', 'actif', 'active'], true);
  }

  // Périodes d'exclusion saisies : une par ligne, « AAAA-MM-JJ HH:MM → AAAA-MM-JJ HH:MM » (ou « - », « > », « ; » entre les deux)
  public static function periodes($texte) {
    $r = [];
    foreach (preg_split('/\R/', (string)$texte) as $l)
      if (preg_match('/^\s*(\d{4}-\d\d-\d\d(?:[ T]\d\d?:\d\d)?)\s*(?:→|->|>|;|\s-\s|\s)\s*(\d{4}-\d\d-\d\d(?:[ T]\d\d?:\d\d)?)\s*$/u', $l, $m)
        && ($a = strtotime($m[1])) && ($b = strtotime(strlen($m[2]) == 10 ? $m[2] . ' 23:59:59' : $m[2])) && $b > $a) $r[] = [$a, $b];
    return $r;
  }
}

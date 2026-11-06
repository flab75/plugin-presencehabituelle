<?php
/* Calcul des habitudes : fonctions pures (aucun accès à Jeedom), testées par tests/selftest.php.
   Évènement = [horodatage, valeur, exact] ; exact = 0 si la valeur vient d'un historique moyenné par heure. */
class presencehabituelle_analyse {
  const SCENE = 30;    // s : lampes allumées à moins de SCENE s du début de la session = même scène
  const PASSAGE = 180; // s : session plus courte = passage éclair

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

  // % des jours (semaine S = lun.-ven., week-end W = sam.-dim.) où le groupe est allumé dans l'heure, 0 h..23 h
  public static function profil(array $sessions, array $jours) {
    $on = [];
    foreach ($sessions as [$a, $b]) // ponytail: heure par heure, coût = durée totale allumée en heures ; suffisant sur 90 j
      for ($t = $a - ($a % 60); $t < $b; $t = strtotime(date('Y-m-d H:00:00', $t)) + 3600) $on[date('Y-m-d G', $t)] = true;
    $p = ['S' => array_fill(0, 24, 0), 'W' => array_fill(0, 24, 0), 'nS' => 0, 'nW' => 0];
    foreach ($jours as $j) {
      $k = $j['n'] >= 6 ? 'W' : 'S'; $p['n' . $k]++;
      for ($h = 0; $h < 24; $h++) if (isset($on[$j['d'] . ' ' . $h])) $p[$k][$h]++;
    }
    foreach (['S', 'W'] as $k) foreach ($p[$k] as $h => $v) $p[$k][$h] = $p['n' . $k] ? (int)round(100 * $v / $p['n' . $k]) : 0;
    return $p;
  }

  // Lissage 1-2-1 sur 24 h (minuit relié à 23 h) ; une heure jamais allumée reste à 0
  public static function lisser(array $p) {
    $r = [];
    for ($h = 0; $h < 24; $h++) $r[$h] = $p[$h] ? (int)round(($p[($h + 23) % 24] + 2 * $p[$h] + $p[($h + 1) % 24]) / 4) : 0;
    return $r;
  }

  public static function quantile(array $v, $q) {
    if (!$v) return null;
    sort($v); $i = ($q * (count($v) - 1)); $f = (int)floor($i);
    return $v[$f] + ($i - $f) * (($v[$f + 1] ?? $v[$f]) - $v[$f]);
  }

  // Durées (min) des sessions exactes comprises dans les jours comptés ; passages = % de sessions < PASSAGE
  public static function durees(array $sessions, array $jours) {
    $ok = []; foreach ($jours as $j) $ok[$j['d']] = true;
    $d = []; $pas = 0;
    foreach ($sessions as [$a, $b, $x]) if ($x && isset($ok[date('Y-m-d', $a)])) { $d[] = ($b - $a) / 60; if ($b - $a < self::PASSAGE) $pas++; }
    $r = ['n' => count($d)];
    if ($d) $r += ['q25' => round(self::quantile($d, .25), 1), 'med' => round(self::quantile($d, .5), 1), 'q75' => round(self::quantile($d, .75), 1),
      'passages' => (int)round(100 * $pas / count($d))];
    return $r;
  }

  // Scènes : combinaisons de lampes allumées ensemble au début des sessions exactes → [« A + B » => %]
  public static function scenes(array $sessions, array $jours) {
    $ok = []; foreach ($jours as $j) $ok[$j['d']] = true;
    $n = [];
    foreach ($sessions as $s) if ($s[2] && isset($ok[date('Y-m-d', $s[0])])) { $l = $s[3]; sort($l); $k = implode(' + ', $l); $n[$k] = ($n[$k] ?? 0) + 1; }
    arsort($n); $t = array_sum($n);
    return array_map(fn($v) => (int)round(100 * $v / $t), $n);
  }

  /* Volet : journée de 04:00 à 04:00. Matin = 1re ouverture entre 04:00 et 16:00 ; soir = dernière fermeture entre 16:00 et 04:00.
     Fermé = position ≤ 1 % du maximum observé (une position partielle contre le soleil n'est pas une fermeture).
     Semaine / week-end : matins du sam. et dim., soirs du ven. et sam. (on se couche plus tard la veille d'un jour sans travail).
     → ['matin' => ['S' => stats, 'W' => stats], 'soir' => [...]] ; stats = n, q25, med, q75 en minutes depuis 00:00, ferme = % des nuits où il est fermé à 04:00 */
  public static function volet(array $ev, array $jours) {
    $max = 0; foreach ($ev as $e) $max = max($max, $e[1]);
    $seuil = $max * 0.01;
    $m = ['S' => [], 'W' => []]; $s = ['S' => [], 'W' => []]; $nsoir = ['S' => 0, 'W' => 0]; $fsoir = ['S' => 0, 'W' => 0];
    foreach ($jours as $j) {
      $a = $j['a'] + 4 * 3600; $mid = $j['a'] + 16 * 3600; $b = $j['b'] + 4 * 3600; // ponytail: +4 h fixes, décalés d'1 h les jours de changement d'heure
      $ferme = null; $mat = null; $soir = null;
      foreach ($ev as [$t, $v]) {
        $f = $v <= $seuil;
        if ($t >= $a && $t < $b && $ferme !== null && $f != $ferme) {
          if (!$f && $t < $mid && $mat === null) $mat = $t;
          if ($f && $t >= $mid) $soir = $t;
          if (!$f && $t >= $mid) $soir = null; // rouvert ensuite : la fermeture du soir est la dernière
        }
        if ($t >= $b) break;
        $ferme = $f;
      }
      $km = $j['n'] >= 6 ? 'W' : 'S'; $ks = in_array($j['n'], [5, 6]) ? 'W' : 'S';
      if ($mat !== null) $m[$km][] = ($mat - $j['a']) / 60;
      if ($ferme !== null) { $nsoir[$ks]++; if ($ferme) $fsoir[$ks]++; } // fermé à 04:00, même resté fermé toute la journée
      if ($soir !== null) $s[$ks][] = ($soir - $j['a']) / 60;
    }
    $st = function ($v) { return ['n' => count($v)] + ($v ? ['q25' => (int)round(self::quantile($v, .25)), 'med' => (int)round(self::quantile($v, .5)), 'q75' => (int)round(self::quantile($v, .75))] : []); };
    $r = ['matin' => [], 'soir' => []];
    foreach (['S', 'W'] as $k) {
      $r['matin'][$k] = $st($m[$k]);
      $r['soir'][$k] = $st($s[$k]) + ['ferme' => $nsoir[$k] ? (int)round(100 * $fsoir[$k] / $nsoir[$k]) : 0];
    }
    return $r;
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

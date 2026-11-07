<?php
/* Calcul des habitudes : fonctions pures (aucun accès à Jeedom), testées par tests/selftest.php.
   Évènement = [horodatage, valeur, exact] ; seuls des changements exacts sont relevés : exact = false seulement pour une session encore en cours (durée inconnue). */
class presencehabituelle_analyse {
  const SCENE = 30;    // s : lampes allumées à moins de SCENE s du début de la session = même scène
  const PASSAGE = 180; // s : session plus courte = passage éclair
  const RARE = 10;     // % : heure allumée au plus ce % des jours = exception
  const ECART = 30;    // min : écart minimal à la moitié centrale pour parler d'exception

  /* Mode guidé. Réponses {cmdId: {r: oui|non|doublon|groupe, de: cmdId}} ; règles {cmdId: {jamais: 0|1, plages: [{k: marche|exclue, j: tous|S|W, de: HH:MM, a: HH:MM}]}}.
     Plage : de > a passe minuit, de = a = toute la journée. Ce qui est illisible est écarté. */
  public static function reponses($x) {
    $r = [];
    foreach (is_array($x) ? $x : [] as $id => $v) {
      if (!(int)$id || !is_array($v) || !in_array($v['r'] ?? '', ['oui', 'non', 'doublon', 'groupe'], true)) continue;
      $r[(int)$id] = ['r' => $v['r']];
      if ($v['r'] == 'doublon' && (int)($v['de'] ?? 0) && (int)$v['de'] != (int)$id) $r[(int)$id]['de'] = (int)$v['de'];
    }
    return $r;
  }
  public static function regles($x) {
    $r = []; $h = '/^([01]\d|2[0-3]):[0-5]\d$/';
    foreach (is_array($x) ? $x : [] as $id => $v) {
      if (!(int)$id || !is_array($v)) continue;
      $p = [];
      foreach (array_slice((array)($v['plages'] ?? []), 0, 10) as $q)
        if (is_array($q) && in_array($q['k'] ?? '', ['marche', 'exclue'], true) && in_array($q['j'] ?? '', ['tous', 'S', 'W'], true)
          && preg_match($h, (string)($q['de'] ?? '')) && preg_match($h, (string)($q['a'] ?? '')))
          $p[] = ['k' => $q['k'], 'j' => $q['j'], 'de' => $q['de'], 'a' => $q['a']];
      if (!empty($v['jamais']) || $p) $r[(int)$id] = ['jamais' => empty($v['jamais']) ? 0 : 1, 'plages' => $p];
    }
    return $r;
  }

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

  /* Semaine S ou week-end W de l'heure $h (et minute $mn) d'un jour ISO $n. Week-end = de [jour ISO, minutes] à [jour ISO, minutes],
     réglable (configuration du plugin) ; par défaut du vendredi 16:00 au lundi 04:00 : soirs du ven. au dim., matins du sam. et du dim. */
  public static $WE = [5, 960, 1, 240];
  public static function cle($n, $h, $mn = 0) {
    [$jd, $md, $jf, $mf] = self::$WE;
    $m = ($n - 1) * 1440 + $h * 60 + $mn; $d = ($jd - 1) * 1440 + $md; $f = ($jf - 1) * 1440 + $mf;
    return ($d <= $f ? $m >= $d && $m < $f : $m >= $d || $m < $f) ? 'W' : 'S'; // d = f : pas de week-end
  }
  // [jour, 'HH:MM', jour, 'HH:MM'] lus de la configuration → $WE ; illisible = défaut
  public static function weekEnd($jd, $hd, $jf, $hf) {
    $x = '/^([01]?\d|2[0-3]):([0-5]\d)$/'; $ok = fn($j) => (int)$j >= 1 && (int)$j <= 7;
    if (!$ok($jd) || !$ok($jf) || !preg_match($x, (string)$hd, $a) || !preg_match($x, (string)$hf, $b)) return self::$WE = [5, 960, 1, 240];
    return self::$WE = [(int)$jd, 60 * $a[1] + $a[2], (int)$jf, 60 * $b[1] + $b[2]];
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
    for ($h = 0; $h < 24; $h++) $r[$h] = $p[$h] ? min($p[$h] < 100 ? 99 : 100, (int)round(($p[($h + 23) % 24] + 2 * $p[$h] + $p[($h + 1) % 24]) / 4)) : 0; // 100 seulement si vraiment toujours
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
    return self::stats($d, 1) + ($d ? ['passages' => self::pc($pas, count($d))] : []);
  }

  // Scènes : combinaisons de lampes allumées ensemble au début des sessions exactes → [« A + B » => %]
  public static function scenes(array $sessions, array $jours) {
    $ok = []; foreach ($jours as $j) $ok[$j['d']] = true;
    $n = [];
    foreach ($sessions as $s) if ($s[2] && isset($ok[date('Y-m-d', $s[0])])) { $l = $s[3]; sort($l); $k = implode(' + ', $l); $n[$k] = ($n[$k] ?? 0) + 1; }
    arsort($n); $t = array_sum($n);
    return array_map(fn($v) => self::pc($v, $t), $n);
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
          if (!$f && $t >= $mid) $soir = null; // rouvert ensuite : la fermeture du soir est la dernière (ponytail: rouvert avant 04:00 = nuit « pas fermée », limite de la journée 04:00 → 04:00 ; le plus souvent un départ, déjà exclu)
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

  // Point (x, y) dans le polygone [[x, y], …] (coordonnées en % de l'image ; règle du rayon pair-impair)
  public static function dans($x, $y, array $pts) {
    $in = false; $n = count($pts);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
      [$xi, $yi] = $pts[$i]; [$xj, $yj] = $pts[$j];
      if (($yi > $y) != ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) $in = !$in;
    }
    return $in;
  }

  /* Enchaînements par les passages du plan : une lumière de A s'éteint, une de B s'allume moins de PASSAGE s après.
     $parPiece = [pièce => intervalles allumés (toutes ses lampes)], $passages = [[a, b]] ; hors périodes exclues.
     → [clé du passage => [a, b, ab, ba]] (ab = fois A → B, ba = fois B → A)
     ponytail: un seul passage entre deux pièces compte ; un trajet A → C → B sans lumière en C n'est pas vu */
  public static function enchainements(array $parPiece, array $passages, array $excl = []) {
    $hors = function ($t) use ($excl) { foreach ($excl as [$a, $b]) if ($t >= $a && $t < $b) return false; return true; };
    $fois = function ($de, $vers) use ($parPiece, $hors) {
      $on = array_column($parPiece[$vers] ?? [], 0); sort($on); $n = 0;
      foreach ($parPiece[$de] ?? [] as $i) {
        if (!$i[2] || !$hors($i[1])) continue; // extinction connue seulement
        foreach ($on as $t) if ($t >= $i[1] && $t - $i[1] <= self::PASSAGE) { $n++; break; } elseif ($t > $i[1]) break;
      }
      return $n;
    };
    $r = [];
    foreach ($passages as $k => [$a, $b]) if ($a !== $b) $r[$k] = [$a, $b, $fois($a, $b), $fois($b, $a)]; // clé gardée : celle du passage
    return $r;
  }

  const LAMPE = '/(?<![\p{L}\d])(lampes?|lamps?|lights?|appliques?|plafonniers?|plafonds?|lustres?|spots?|suspensions?|ampoules?|bulbs?|leds?|veilleuses?|guirlandes?|lumi\p{L}*|[ée]clairages?|halog[èe]nes?|lampadaires?|chevets?)(?![\p{L}\d])/iu';
  // Lumière sans type générique, d'après son nom ; commande annexe d'un équipement (voyant, disponibilité, pile, capteur de lumière…) : jamais une lampe
  const ANNEXE = '/(dispo|availab|online|reachab|batter|(?<![\p{L}\d])piles?(?![\p{L}\d])|update|mise [àa] jour|lumin|indicat|voyant|(?<![\p{L}\d])leds?(?![\p{L}\d]))/iu';
  const ETAT = '/^\s*(etat|état|state|statut|status|on|marche)\s*$/iu';
  public static function nommee($cmd, $eq) {
    return !preg_match(self::ANNEXE, $cmd) && (preg_match(self::LAMPE, $cmd) || (preg_match(self::ETAT, $cmd) && preg_match(self::LAMPE, $eq)));
  }

  /* Découverte : intervalles allumés triés sans chevauchement ([début, fin, …], comme ceux d'intervalles()). */
  public static function duree(array $iv) { $d = 0; foreach ($iv as $i) $d += $i[1] - $i[0]; return $d; }
  public static function coupe(array $iv, $a, $b) { // ramenés à [a, b]
    $r = []; foreach ($iv as $i) if ($i[1] > $a && $i[0] < $b) $r[] = [max($a, $i[0]), min($b, $i[1]), true]; return $r;
  }
  public static function inter(array $x, array $y) { // durée allumée en commun
    $d = 0; $j = 0; $n = count($y);
    foreach ($x as $i) {
      while ($j < $n && $y[$j][1] <= $i[0]) $j++;
      for ($k = $j; $k < $n && $y[$k][0] < $i[1]; $k++) $d += min($i[1], $y[$k][1]) - max($i[0], $y[$k][0]);
    }
    return $d;
  }
  public static function union(array $listes) { return array_map(fn($s) => [$s[0], $s[1], true], self::sessions($listes)); }

  /* Part des bascules de $x (allumages, extinctions) que $y fait aussi, dans le même sens, à $tol s près.
     $a, $b : bornes d'une fenêtre coupée par coupe() ; une bascule posée sur la borne n'en est pas une (déjà allumée, encore allumée) */
  public static function memes(array $x, array $y, $tol = 5, $a = null, $b = null) {
    $f = function ($iv) use ($a, $b) { $r = []; foreach ($iv as $i) { if ($a === null || $i[0] != $a) $r[] = [$i[0], 1]; if ($b === null || $i[1] != $b) $r[] = [$i[1], 0]; } return $r; };
    $bx = $f($x); $by = $f($y); $m = count($by); $n = 0; $j = 0;
    if (!$bx) return 0;
    foreach ($bx as [$t, $s]) {
      while ($j < $m && $by[$j][0] < $t - $tol) $j++;
      for ($k = $j; $k < $m && $by[$k][0] <= $t + $tol; $k++) if ($by[$k][1] == $s) { $n++; break; }
    }
    return $n / count($bx);
  }

  /* Jours sans aucune lumière : $iv = [lampe => intervalles], $deb = [lampe => début de ses données], $jours = jours() ;
     un jour compte si au moins 2 lampes étaient déjà relevées. → [[premier jour, dernier jour]] (jours consécutifs regroupés) */
  public static function absences(array $iv, array $deb, array $jours) {
    $r = [];
    foreach ($jours as $j) {
      $n = 0;
      foreach ($iv as $l => $i) if (($deb[$l] ?? PHP_INT_MAX) <= $j['a']) { if (self::coupe($i, $j['a'], $j['b'])) continue 2; $n++; }
      if ($n < 2) continue;
      $k = count($r) - 1;
      if ($k >= 0 && date('Y-m-d', strtotime($r[$k][1] . ' +1 day')) == $j['d']) $r[$k][1] = $j['d']; else $r[] = [$j['d'], $j['d']];
    }
    return $r;
  }

  /* Lampe allumée chaque jour à la même heure (à 5 min près) ou au coucher du soleil (à 10 min près), 80 % des jours au moins, sur 7 jours au moins.
     $couchers = ['Y-m-d' => horodatage du coucher]. → ['heure' => minutes depuis minuit] | ['soleil' => minutes après le coucher] | null
     ponytail: O(n²) sur les allumages de la lampe (≈ 300 sur 30 jours) ; trier puis fenêtre glissante si une box le sent */
  public static function automatique(array $iv, array $couchers = []) {
    $jours = []; foreach ($iv as $i) $jours[date('Y-m-d', $i[0])][] = $i[0];
    if (count($jours) < 7) return null;
    $part = function ($f, $tol) use ($jours) { // valeur partagée par le plus de jours
      $val = array_map(fn($ts) => array_values(array_filter(array_map($f, $ts), fn($w) => $w !== null)), $jours); // une fois par allumage
      $mieux = [null, 0];
      foreach ($val as $ws) foreach ($ws as $c) {
        $n = 0; foreach ($val as $u) foreach ($u as $w) if (abs($w - $c) <= $tol) { $n++; break; }
        if ($n > $mieux[1]) $mieux = [$c, $n];
      }
      return [$mieux[0], $mieux[1] / count($jours)];
    };
    [$m, $p] = $part(fn($t) => (int)date('G', $t) * 60 + (int)date('i', $t), 5);
    if ($p >= .8) return ['heure' => $m];
    [$m, $p] = $part(fn($t) => isset($couchers[$d = date('Y-m-d', $t)]) ? intdiv($t - $couchers[$d], 60) : null, 10);
    return $p >= .8 ? ['soleil' => $m] : null;
  }
}

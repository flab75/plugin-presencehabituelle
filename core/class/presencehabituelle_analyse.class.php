<?php
/* Calcul des habitudes : fonctions pures (aucun accès à Jeedom), testées par tests/selftest.php.
   Évènement = [horodatage, valeur, exact] ; seuls des changements exacts sont relevés : exact = false seulement pour une session encore en cours (durée inconnue). */
class presencehabituelle_analyse {
  const SCENE = 30;    // s : lampes allumées à moins de SCENE s du début de la session = même scène
  const PASSAGE = 180; // s : session plus courte = passage éclair
  const RARE = 10;     // % : heure allumée au plus ce % des jours = exception
  const ECART = 30;    // min : écart minimal à la moitié centrale pour parler d'exception

  /* Mode guidé. Réponses {cmdId: {r: oui|non|doublon|groupe, de: cmdId, m: [cmdId] (lampes du groupe)}} ; règles {cmdId: {jamais: 0|1, plages: [{k: marche|exclue, j: tous|S|W, de: HH:MM, a: HH:MM}]}}.
     Plage : de > a passe minuit, de = a = toute la journée. Ce qui est illisible est écarté. */
  public static function reponses($x) {
    $r = [];
    foreach (is_array($x) ? $x : [] as $id => $v) {
      if (!(int)$id || !is_array($v) || !in_array($v['r'] ?? '', ['oui', 'non', 'doublon', 'groupe'], true)) continue;
      if ($v['r'] == 'doublon' && (!(int)($v['de'] ?? 0) || (int)$v['de'] == (int)$id)) continue; // doublon dont la commande gardée n'est pas choisie : pas encore une réponse
      $r[(int)$id] = ['r' => $v['r']];
      if ($v['r'] == 'doublon' && (int)($v['de'] ?? 0) && (int)$v['de'] != (int)$id) $r[(int)$id]['de'] = (int)$v['de'];
      if ($v['r'] == 'groupe' && is_array($v['m'] ?? null)) { // lampes que le groupe allume
        $m = array_filter(array_map('intval', $v['m']), fn($x) => $x > 0 && $x != (int)$id);
        if ($m = array_slice(array_values(array_unique($m)), 0, 100)) $r[(int)$id]['m'] = $m;
      }
    }
    return $r;
  }
  /* Plan : un escalier entre deux étages est un seul passage, tracé sur l'étage e (pts) et sur l'autre (pts2, facultatif).
     Avant la 0.10, il était dessiné une fois sur chaque étage : les deux deviennent un seul (le 2e tracé passe en pts2). */
  public static function autreEtage(array $o, array $etageDe) { // étage de l'autre bout, ou null (passage sur un seul étage)
    $fa = $etageDe[$o['a']] ?? null; $fb = $etageDe[$o['b']] ?? null;
    if (!$fa || !$fb || $fa === $fb) return null;
    return $fa === $o['e'] ? $fb : ($fb === $o['e'] ? $fa : null);
  }
  public static function fusionner(array $passages, array $pieces) {
    $etageDe = array_column($pieces, 'e', 'id'); $r = []; $vu = [];
    foreach ($passages as $o) {
      $autre = self::autreEtage($o, $etageDe);
      if (!$autre) { unset($o['pts2']); $r[] = $o; continue; }
      $k = min($o['a'], $o['b']) . '|' . max($o['a'], $o['b']) . '|' . $o['t'];
      if (isset($vu[$k]) && !isset($r[$vu[$k]]['pts2']) && $r[$vu[$k]]['e'] === $autre) { $r[$vu[$k]]['pts2'] = $o['pts']; continue; } // même escalier vu de l'autre étage
      $vu[$k] = count($r); $r[] = $o;
    }
    return $r;
  }
  /* Commande d'un détecteur de présence, toute marque : type générique PRESENCE de Jeedom, sinon le nom de la commande ou de l'équipement
     (présence, mouvement, motion, occupancy, PIR, mmWave…) ; binaire ou 0/1 ; pas un réglage (délai, sensibilité, zone…) ni un équipement
     qui est un capteur d'ouverture, de fumée, d'eau ou de gaz (type générique de l'une de ses commandes). ponytail: liste de mots, à compléter au besoin */
  const DET_TYPES = ['OPENING', 'OPENING_WINDOW', 'SMOKE', 'FLOOD', 'GAS', 'SABOTAGE_DOOR', 'LOCK_STATE'];
  public static function estDetecteur($gen, $sub, $nom, $eqNom, array $eqGens) {
    if ($gen == 'PRESENCE') return true;
    if (($sub != 'binary' && $sub != 'numeric') || array_intersect($eqGens, self::DET_TYPES)) return false;
    return (bool)preg_match('/pr[ée]sence|mouvement|motion|occupancy|occupation|occupied|\bpir\b|mmwave|human|personne|person\b|people|bewegung|anwesenheit/iu', $nom . ' ' . $eqNom)
      && !preg_match('/interval|sensib|sensitiv|timeout|d[ée]lai|delay|tamper|sabotage|enable|activ|distance|lux|lumin|illum|target|fading|cooldown|keep|count|zone|batter|temp|humid|calib|qualit|communic|statut|status|linkq|voltage|tension|puissance|power|consom|\bled\b|identify|reset/iu', $nom);
  }
  /* Détecteurs de présence : [{nom, cmd (0 = non connecté à Jeedom), m: [cmdId des lampes qu'il allume]}], 30 au plus ; sans nom ni lampe = écarté */
  public static function detecteurs($x) {
    $r = [];
    foreach (array_slice(is_array($x) ? array_values($x) : [], 0, 30) as $v) {
      if (!is_array($v) || ($nom = mb_substr(trim((string)($v['nom'] ?? '')), 0, 60)) === '') continue;
      $m = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($v['m'] ?? [])), fn($y) => $y > 0))), 0, 100);
      if ($m) $r[] = ['nom' => $nom, 'cmd' => max(0, (int)($v['cmd'] ?? 0)), 'm' => $m];
    }
    return $r;
  }
  public static function memeDetecteur(array $det, $a, $b) { // deux lampes allumées par un même détecteur : pas des doublons
    foreach ($det as $d) if (in_array((int)$a, $d['m'], true) && in_array((int)$b, $d['m'], true)) return true;
    return false;
  }
  public static function regles($x) {
    $r = []; $h = '/^([01]\d|2[0-3]):[0-5]\d$/';
    foreach (is_array($x) ? $x : [] as $id => $v) {
      if (!(int)$id || !is_array($v)) continue;
      $p = [];
      foreach (array_slice((array)($v['plages'] ?? []), 0, 10) as $q)
        if (is_array($q) && in_array($q['k'] ?? '', ['marche', 'exclue'], true) && in_array($q['j'] ?? '', ['tous', 'S', 'W'], true)
          && preg_match($h, (string)($q['de'] ?? '')) && preg_match($h, (string)($q['a'] ?? '')))
          $p[] = ['k' => $q['k'], 'j' => $q['j'], 'de' => $q['de'], 'a' => $q['a']] + ($q['k'] == 'exclue' && !empty($q['x']) ? ['x' => 1] : []); // x : sauf un jour d'exception
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
     Celle encore en cours à $fin (lampe restée allumée, ou bloquée) est retirée sans exclure ses jours : sinon une lampe oubliée effacerait les jours qui suivent.
     → [intervalles gardés, [[début, fin]] retirés, bloquée depuis (horodatage) ou null] */
  public static function longues(array $iv, $max, $fin = null) {
    $g = []; $x = []; $bl = null;
    foreach ($iv as $i) if ($i[1] - $i[0] <= $max) $g[] = $i; elseif ($fin !== null && $i[1] >= $fin && !$i[2]) $bl = $i[0]; else $x[] = [$i[0], $i[1]];
    return [$g, $x, $bl];
  }
  // Intervalles [[a, b]] triés, ceux qui se chevauchent ou se touchent réunis
  public static function fusion(array $iv) {
    usort($iv, fn($x, $y) => $x[0] <=> $y[0]); $r = [];
    foreach ($iv as [$a, $b]) { $n = count($r) - 1; if ($n >= 0 && $a <= $r[$n][1]) $r[$n][1] = max($r[$n][1], $b); else $r[] = [(int)$a, (int)$b]; }
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

  /* Recalage : bilan d'un groupe sur des jours ($jours de jours()) : h = heures allumées par jour, n = allumages par jour,
     mn = durée moyenne (min), p = % des jours allumé dans chaque heure (0..23, semaine et week-end ensemble) */
  public static function bilan(array $sessions, array $jours) {
    $N = count($jours); $tot = 0; $n = 0; $p = array_fill(0, 24, 0);
    if (!$N) return ['h' => 0, 'n' => 0, 'mn' => 0, 'p' => $p, 'j' => 0];
    foreach ($jours as $j) {
      $vu = [];
      foreach ($sessions as $s) {
        $a = max($s[0], $j['a']); $b = min($s[1], $j['b']);
        if ($b <= $a) continue;
        $tot += $b - $a; if ($s[0] >= $j['a']) $n++;
        for ($t = $a - ($a % 3600); $t < $b; $t += 3600) $vu[(int)date('G', $t)] = 1; // ponytail: heure pleine à l'heure UTC ronde, juste en France (décalage d'heures entières)
      }
      foreach ($vu as $h => $x) $p[$h]++;
    }
    return ['h' => round($tot / 3600 / $N, 2), 'n' => round($n / $N, 2), 'mn' => $n ? (int)round($tot / 60 / $n) : 0, 'p' => array_map(fn($x) => (int)round(100 * $x / $N), $p), 'j' => $N];
  }

  /* Pièces allumées en même temps : chaque jour compté, le plus grand nombre de groupes allumés ensemble pendant $tenu secondes au moins
     (un croisement de quelques secondes n'en est pas un) ; → le 9e décile de ces maxima (dépassé un jour sur dix au plus), arrondi au-dessus,
     1 au moins ; null sans jour. $parGroupe = [groupe => sessions()] ; deux sessions d'un même groupe ne se chevauchent pas (sessions() les fusionne) */
  public static function simultanees(array $parGroupe, array $jours, $tenu = self::PASSAGE) {
    $max = [];
    foreach ($jours as $j) {
      $ev = [];
      foreach ($parGroupe as $se) foreach ($se as $s) { $a = max($s[0], $j['a']); $b = min($s[1], $j['b']); if ($b > $a) { $ev[] = [$a, 1]; $ev[] = [$b, -1]; } }
      usort($ev, fn($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]); // au même instant, l'extinction d'abord : un relais n'est pas deux pièces
      $n = 0; $m = 0;
      foreach ($ev as $i => $e) { $n += $e[1]; if (($ev[$i + 1][0] ?? $j['b']) - $e[0] >= $tenu) $m = max($m, $n); } // niveau tenu jusqu'à l'événement suivant
      $max[] = $m;
    }
    return $max ? max(1, (int)ceil(self::quantile($max, .9) - 1e-9)) : null;
  }

  // Lissage 1-2-1 sur 24 h (minuit relié à 23 h) ; une heure jamais allumée reste à 0
  /* Le soir, la 1re lampe allumée (15:00-24:00) suit-elle le coucher du soleil ou l'horloge ? Le matin, la dernière éteinte (04:00-12:00), le lever ?
     Pente b de l'heure relevée (minutes d'horloge) sur l'heure du soleil, jour par jour, gardée si elle dépasse deux fois son erreur type, entre 0 et 1.
     → ['soir' => ['b', 'c' (heure moyenne du soleil, minutes), 'n' (jours)] | absent, 'matin' => idem] ; 10 jours au moins et un soleil qui a bougé
     d'au moins 15 min (écart type) : en juin, rien ne peut se dire. ponytail: régression linéaire simple, une lampe allumée à 15:30 pour lire pèse comme les autres */
  public static function soleil(array $sessions, array $jours, $lat, $lon) {
    $mn = fn($t) => (int)date('G', $t) * 60 + (int)date('i', $t);
    $v = ['soir' => [], 'matin' => []];
    foreach ($jours as $j) {
      $sun = date_sun_info($j['a'] + 43200, $lat, $lon);
      if (!is_int($sun['sunset'] ?? null) || !is_int($sun['sunrise'] ?? null)) continue;
      $soir = null; $matin = null;
      foreach ($sessions as [$a, $b]) {
        if ($a >= $j['a'] + 54000 && $a < $j['b'] && $soir === null) $soir = $a; // sessions triées : la 1re du soir
        if ($b >= $j['a'] + 14400 && $b < $j['a'] + 43200 && $a < $b) $matin = $b; // la dernière éteinte du matin
      }
      if ($soir !== null) $v['soir'][] = [$mn($sun['sunset']), $mn($soir)];
      if ($matin !== null) $v['matin'][] = [$mn($sun['sunrise']), $mn($matin)];
    }
    $r = [];
    foreach ($v as $k => $xy) {
      if (($n = count($xy)) < 10) continue;
      $mx = array_sum(array_column($xy, 0)) / $n; $my = array_sum(array_column($xy, 1)) / $n; $sxx = 0; $sxy = 0;
      foreach ($xy as [$x, $y]) { $sxx += ($x - $mx) ** 2; $sxy += ($x - $mx) * ($y - $my); }
      if (sqrt($sxx / $n) < 15) continue;
      $b = $sxy / $sxx; $res = 0;
      foreach ($xy as [$x, $y]) $res += ($y - $my - $b * ($x - $mx)) ** 2;
      if ($b <= 2 * sqrt($res / ($n - 2) / $sxx)) continue; // pas plus net que le hasard
      $r[$k] = ['b' => round(min(1, $b), 2), 'c' => (int)round($mx), 'n' => $n];
    }
    return $r;
  }

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
     Semaine / week-end : cle() à l'heure du geste (week-end réglable, par défaut du ven. 16:00 au lun. 04:00 : soirs du ven. au dim., matins du sam. et du dim.).
     Une nuit pas encore finie à $fin (le calcul tourne vers minuit), ou qui déborde sur une exclusion (lendemain d'un départ), ne compte pas pour le soir.
     → ['matin' => ['S' => stats, 'W' => stats], 'soir' => [...]] ; stats() en minutes depuis 00:00 (exceptions par date), jours = jours comptés dans la colonne,
       ferme = % des nuits où il est fermé à 04:00 (null sans nuit observée), nuits / fermees = comptes bruts (« toujours » / « jamais » se lisent sur eux) */
  public static function volet(array $ev, array $jours, $fin = PHP_INT_MAX, array $excl = []) {
    $max = 0; foreach ($ev as $e) $max = max($max, $e[1]);
    $seuil = $max * 0.01;
    $R = []; $m = ['S' => [], 'W' => []]; $s = ['S' => [], 'W' => []]; $nsoir = ['S' => 0, 'W' => 0]; $fsoir = ['S' => 0, 'W' => 0]; $jm = ['S' => 0, 'W' => 0]; $js = ['S' => 0, 'W' => 0];
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
      $nuit = $b <= $fin; // nuit en cours : ne compte pas pour le soir
      foreach ($excl as [$x, $y]) if ($x < $b && $y > $a) $nuit = false; // le soir finit le lendemain à 04:00 : absent ce matin-là, la nuit ne dit rien
      $R[] = [$j, $mat === null ? null : self::minutes($mat, $j['d']), $soir === null ? null : self::minutes($soir, $j['d']), $nuit, $ferme];
    }
    // semaine ou week-end à l'heure du geste (week-end réglable) ; un jour sans geste, à l'heure médiane des gestes relevés (sinon 08:00, 21:00)
    $med = function ($i, $def) use ($R) { $x = array_values(array_filter(array_column($R, $i), fn($v) => $v !== null)); sort($x); return $x ? $x[intdiv(count($x), 2)] : $def; };
    $mm = $med(1, 480); $ms = $med(2, 1260);
    $k = fn($j, $mn) => self::cle(($j['n'] - 1 + intdiv($mn, 1440)) % 7 + 1, intdiv($mn % 1440, 60), $mn % 60);
    foreach ($R as [$j, $mat, $soir, $nuit, $ferme]) {
      $km = $k($j, $mat ?? $mm); $jm[$km]++; if ($mat !== null) $m[$km][$j['d']] = $mat;
      if (!$nuit) continue;
      $ks = $k($j, $soir ?? $ms); $js[$ks]++;
      if ($ferme !== null) { $nsoir[$ks]++; if ($ferme) $fsoir[$ks]++; } // fermé à 04:00, même resté fermé toute la journée
      if ($soir !== null) $s[$ks][$j['d']] = $soir;
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
  // Aussi les états en texte d'un volet ou d'une lampe (open, ouvert, up, allumé…) ; tout autre texte = inactif
  public static function actif($v) {
    return is_numeric($v) ? $v > 0 : in_array(mb_strtolower(trim((string)$v)), ['on', 'true', 'oui', 'yes', 'actif', 'active',
      'open', 'opened', 'ouvert', 'ouverte', 'up', 'haut', 'allumé', 'allumée', 'allume'], true);
  }
  // État en texte qui ne dit ni ouvert ni fermé (arrêté en route, position « my », en mouvement) : ignoré, pas pris pour fermé
  public static function neutre($v) {
    return !is_numeric($v) && in_array(mb_strtolower(trim((string)$v)), ['stop', 'stopped', 'my', 'moving', 'opening', 'closing',
      'en mouvement', 'partial', 'partiel', 'intermédiaire', 'intermediate'], true);
  }
  // Volet suivi « ouvert = valeur basse » (0 = ouvert) : remis dans le sens habituel, sur son échelle (binaire 0-1, position 0-100 ou plus)
  public static function inverser(array $ev) {
    $mx = $ev ? max(array_column($ev, 1)) : 0; $mx = $mx > 1 ? max(100, $mx) : 1; // pas la plus haute valeur vue : un volet resté ouvert (0) n'est pas fermé
    return array_map(fn($e) => [$e[0], $mx - $e[1]] + $e, $ev);
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

  /* Réseau des passages du plan : une porte se passe dans les deux sens, et deux portes en série font un chemin.
     $passages = [[a, b]] → [x => [y => chemin [x, …, y]]] des plus courts chemins de PORTES passages au plus (largeur d'abord) */
  const PORTES = 3;
  public static function chemins(array $passages) {
    $adj = [];
    foreach ($passages as [$a, $b]) if ($a !== $b) { $adj[$a][$b] = 1; $adj[$b][$a] = 1; }
    $r = [];
    foreach (array_keys($adj) as $x) {
      $r[$x] = [$x => [$x]]; $file = [$x];
      while ($file) { $u = array_shift($file); if (count($r[$x][$u]) > self::PORTES) continue;
        foreach (array_keys($adj[$u]) as $v) if (!isset($r[$x][$v])) { $r[$x][$v] = array_merge($r[$x][$u], [$v]); $file[] = $v; } }
      unset($r[$x][$x]);
    }
    return $r;
  }

  /* Trajets : une pièce s'éteint (allumée 3 min au moins, extinction connue), une pièce reliée par le plan (PORTES passages au plus, même à travers
     une pièce restée éteinte) s'allume entre 1 min avant et PASSAGE s après ; si celle-ci n'est allumée qu'en passant (moins de PASSAGE s :
     « passage lumineux »), le trajet continue depuis elle (allumage de la suivante pendant qu'elle est allumée ou PASSAGE s après), 5 pièces au plus.
     $parPiece = [pièce => sessions()], $chemins = chemins(), hors périodes exclues.
     → ['trajets' => ['A>B>C' => ['ch' => [A, B, C], 'n', 'h' => heure médiane du départ]], 'departs' => [pièce => départs possibles],
        'portes' => ['x|y' => fois passée de x vers y (chaque passage du chemin, pièces éteintes comprises)]]
     ponytail: le premier allumage qui convient gagne ; deux personnes qui bougent en même temps se mêlent */
  public static function trajets(array $parPiece, array $chemins, array $excl = []) {
    $hors = function ($t) use ($excl) { foreach ($excl as [$a, $b]) if ($t >= $a && $t < $b) return false; return true; };
    $ev = [];
    foreach ($parPiece as $pc => $se) foreach ($se as $x) $ev[] = [$x[0], $x[1], !empty($x[2]), (string)$pc];
    usort($ev, fn($x, $y) => $x[0] <=> $y[0]);
    $deb = array_column($ev, 0); $n = count($ev);
    $suivant = function ($de, $a, $b, array $vu) use ($ev, $deb, $n, $chemins) { // 1re pièce reliée allumée dans [a, b]
      for ($lo = 0, $hi = $n; $lo < $hi;) { $m = ($lo + $hi) >> 1; if ($deb[$m] < $a) $lo = $m + 1; else $hi = $m; }
      for ($i = $lo; $i < $n && $ev[$i][0] <= $b; $i++) if (!in_array($ev[$i][3], $vu, true) && isset($chemins[$de][$ev[$i][3]])) return $ev[$i];
      return null;
    };
    $r = ['trajets' => [], 'departs' => [], 'portes' => []]; $heures = [];
    foreach ($ev as $e) {
      if (!$e[2] || $e[1] - $e[0] < self::PASSAGE || !isset($chemins[$e[3]]) || !$hors($e[1])) continue;
      $r['departs'][$e[3]] = ($r['departs'][$e[3]] ?? 0) + 1;
      $ch = [$e[3]]; $cur = $e; $a = max($e[0], $e[1] - 60); $b = $e[1] + self::PASSAGE;
      while (count($ch) < 5 && ($x = $suivant(end($ch), $a, $b, $ch))) {
        $ch[] = $x[3];
        if (!$x[2] || $x[1] - $x[0] >= self::PASSAGE) break; // arrivé : la pièce reste allumée
        $a = $x[0]; $b = $x[1] + self::PASSAGE; // passage lumineux : on continue
      }
      if (count($ch) < 2) continue;
      $k = implode('>', $ch);
      $r['trajets'][$k] = ['ch' => $ch, 'n' => ($r['trajets'][$k]['n'] ?? 0) + 1]; $heures[$k][] = (int)date('G', $e[1]) + (int)date('i', $e[1]) / 60;
      for ($i = 1; $i < count($ch); $i++) { $c = $chemins[$ch[$i - 1]][$ch[$i]]; for ($j = 1; $j < count($c); $j++) { $kk = $c[$j - 1] . '|' . $c[$j]; $r['portes'][$kk] = ($r['portes'][$kk] ?? 0) + 1; } }
    }
    foreach ($r['trajets'] as $k => $x) $r['trajets'][$k]['h'] = round(self::quantile($heures[$k], .5), 1);
    uasort($r['trajets'], fn($x, $y) => $y['n'] <=> $x['n']);
    return $r;
  }

  /* Ordre d'allumage du soir : chaque jour compté, les pièces rangées par leur 1er allumage tenu (PASSAGE s au moins) après 15:00.
     → [pièce => ['soirs' => jours allumée, 'premiere' => % de ces soirs où elle est la 1re, 'rang' => rang médian (1 = la 1re)]] ; soirs à 2 pièces au moins */
  public static function ordre(array $parPiece, array $jours) {
    $rangs = []; $prem = [];
    foreach ($jours as $j) {
      $t = [];
      foreach ($parPiece as $pc => $se) foreach ($se as $x) if ($x[0] >= $j['a'] + 54000 && $x[0] < $j['b'] && $x[1] - $x[0] >= self::PASSAGE) { $t[(string)$pc] = $x[0]; break; }
      if (count($t) < 2) continue;
      asort($t); $i = 0;
      foreach ($t as $pc => $x) { $rangs[$pc][] = ++$i; if ($i == 1) $prem[$pc] = ($prem[$pc] ?? 0) + 1; }
    }
    $r = [];
    foreach ($rangs as $pc => $v) $r[$pc] = ['soirs' => count($v), 'premiere' => self::pc($prem[$pc] ?? 0, count($v)), 'rang' => round(self::quantile($v, .5), 1)];
    uasort($r, fn($x, $y) => $x['rang'] <=> $y['rang']);
    return $r;
  }

  const LAMPE = '/(?<![\p{L}\d])(lampes?|lamps?|lights?|appliques?|plafonniers?|plafonds?|lustres?|spots?|suspensions?|ampoules?|bulbs?|leds?|veilleuses?|guirlandes?|lumi\p{L}*|[ée]clairages?|halog[èe]nes?|lampadaires?|chevets?)(?![\p{L}\d])/iu';
  // Lumière sans type générique, d'après son nom ; commande annexe d'un équipement (voyant, disponibilité, pile, capteur de lumière…) : jamais une lampe
  const ANNEXE = '/(dispo|availab|online|reachab|batter|(?<![\p{L}\d])piles?(?![\p{L}\d])|update|mise [àa] jour|lumin|indicat|voyant|(?<![\p{L}\d])leds?(?![\p{L}\d]))/iu';
  const ETAT = '/^\s*(etat|état|state|statut|status|on|marche)\s*$/iu';
  // Volet sans type générique, d'après son nom (commande, ou équipement si la commande s'appelle Etat / Position…)
  const VOLET = '/(?<![\p{L}\d])(volets?|stores?|shutters?|rollers?|persiennes?|bso|jalousies?|blinds?|rideaux?|covers?|brise-soleil)(?![\p{L}\d])/iu';
  const POSITION = '/^\s*(etat|état|state|statut|status|position|niveau|level|ouverture)\s*$/iu';
  const VNON = '/(auto|mode|programm|calibr|vitesse|speed)/iu'; // « Volet auto » d'un virtuel : un mode, pas un état
  const SUSPECT = '/(d[ée]mon|daemon|deamon|monitor|moniteur)/iu'; // « Démon Volet proportionnel » de Jeedom interne : état d'un plugin, signalé « à vérifier », pas écarté
  public static function suspect($cmd, $eq) { return (bool)preg_match(self::SUSPECT, $cmd . ' ' . $eq); }
  public static function voletNomme($cmd, $eq) {
    return !preg_match(self::ANNEXE, $cmd) && !preg_match(self::VNON, $cmd) && (preg_match(self::VOLET, $cmd) || (preg_match(self::POSITION, $cmd) && preg_match(self::VOLET, $eq)));
  }
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
        $n = 0; foreach ($val as $u) foreach ($u as $w) if (min(abs($w - $c), 1440 - abs($w - $c)) <= $tol) { $n++; break; } // 23:58 et 00:02 : 4 min d'écart
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

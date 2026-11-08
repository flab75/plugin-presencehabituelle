<?php
/* Moteur de la simulation : tirages purs (aucun accès à Jeedom), testés par tests/selftest.php.
   Chaque groupe (pièce) suit, en semaine et le week-end séparément, l'une de deux sources :
   « hist » = son profil tiré de l'historique (assez de jours comptés), « prov » = horaires provisoires de son type de pièce.
   Heures en minutes d'horloge depuis 00:00 ; l'aléa vient de $r (callable → [0, 1[), pour rejouer une journée. */
class presencehabituelle_moteur {
  const PAS = 5;  // min entre deux passages du cron
  const GAP = 20; // min : fenêtre de prolongation à la fin d'une durée tirée

  // Type de pièce d'après les noms (groupe, pièce Jeedom des lampes) ; le premier trouvé dans cet ordre
  const MOTS = ['sdb' => '/salle d.?eau|salle de bains?|\bsdb\b|\bwc\b|toilette|bathroom/iu', 'cuisine' => '/cuisine|kitchen/iu',
    'salon' => '/salon|s[ée]jour|living|salle [àa] manger/iu', 'chambre' => '/chambre|bedroom|\bch ?\d/iu', 'bureau' => '/bureau|office/iu',
    'passage' => '/entr[ée]e|couloir|palier|escalier|hall|d[ée]gagement|stairs/iu'];
  /* Horaires provisoires : plages [début, fin (heures), % des jours], durée [min, max] (minutes), passages éclair (%).
     ponytail: une maison « moyenne », remplacée pièce par pièce par l'historique dès FIABLE jours */
  const PROV = [
    'cuisine' => ['S' => [[6.5, 8, 60], [12, 13.5, 30], [18.5, 21, 80]], 'W' => [[8, 10, 60], [12, 14, 50], [18.5, 21.5, 80]], 'd' => [20, 60], 'pas' => 10],
    'salon' => ['S' => [[18, 23, 85]], 'W' => [[10, 12, 30], [17.5, 23.5, 85]], 'd' => [60, 180], 'pas' => 0],
    'chambre' => ['S' => [[6.5, 7.5, 50], [21.5, 23, 60]], 'W' => [[8, 9.5, 50], [22, 23.5, 60]], 'd' => [15, 45], 'pas' => 10],
    'sdb' => ['S' => [[8, 21, 10], [6.5, 8, 60], [21, 23, 50]], 'W' => [[10, 21, 10], [8, 10, 60], [21, 23.5, 50]], 'd' => [5, 20], 'pas' => 20],
    'passage' => ['S' => [[18, 23, 10]], 'W' => [[18, 23, 10]], 'd' => [2, 5], 'pas' => 60],
    'bureau' => ['S' => [[9, 12, 40], [14, 18, 40]], 'W' => [], 'd' => [60, 120], 'pas' => 0],
    'autre' => ['S' => [[19, 22, 30]], 'W' => [[19, 22, 30]], 'd' => [15, 60], 'pas' => 10]];

  public static function typePiece($texte) {
    foreach (self::MOTS as $t => $re) if (preg_match($re, (string)$texte)) return $t;
    return 'autre';
  }

  // Profil provisoire (24 h) d'un type : une heure prend le % d'une plage qui la couvre au moins 30 min (le plus grand)
  public static function provisoire($type, $k) {
    $p = array_fill(0, 24, 0);
    foreach (self::PROV[$type][$k] as [$a, $b, $pc]) for ($h = 0; $h < 24; $h++) if (min($b, $h + 1) - max($a, $h) >= .5) $p[$h] = max($p[$h], $pc);
    return $p;
  }

  /* Sources de chaque groupe d'analyser() : $textes[nom] = texte où chercher le type de pièce, $regles = règles du Guide par lampe.
     Une lampe « jamais simulée » sort de son groupe ; ses plages s'appliquent au groupe entier (ponytail: règles par lampe lues pour la pièce).
     → [nom => [nom, ids, type, src[S|W] = hist|prov|jamais, profil[S|W] = 24 %, d = [q25, q75], pas, scenes = [[ids, poids]], plages]] */
  public static function sources(array $groupes, array $regles, array $textes, $fiable) {
    $r = [];
    foreach ($groupes as $g) {
      $ids = array_values(array_filter($g['ids'] ?? [], fn($id) => empty($regles[$id]['jamais'])));
      $type = ($x = self::typePiece($g['nom'])) != 'autre' ? $x : self::typePiece($textes[$g['nom']] ?? ''); // le nom du groupe d'abord : « Palier » dont la lampe est rangée dans « Chambre »
      $pr = self::PROV[$type];
      $s = ['nom' => $g['nom'], 'ids' => $ids, 'type' => $type, 'src' => [], 'profil' => [], 'plages' => []];
      foreach ($ids as $id) foreach ($regles[$id]['plages'] ?? [] as $q) $s['plages'][] = $q;
      foreach (['S', 'W'] as $k) {
        $hist = $ids && ($g['jours'] ?? 0) > 0 && ($g['n' . $k] ?? 0) >= $fiable && empty($g['confirmer' . $k]);
        $s['src'][$k] = !$ids ? 'jamais' : ($hist ? 'hist' : 'prov');
        $s['profil'][$k] = !$ids ? array_fill(0, 24, 0) : ($hist ? array_map('intval', $g['l' . $k]) : self::provisoire($type, $k));
      }
      $du = $g['durees'] ?? [];
      $hd = ($du['n'] ?? 0) >= 4; // ponytail: moins de 4 durées relevées, celles du type de pièce
      $s['d'] = $hd ? [max(1, $du['q25']), max(1, $du['q75'])] : $pr['d'];
      $s['pas'] = $hd ? (int)($du['passages'] ?? 0) : $pr['pas'];
      $s['scenes'] = [];
      $parNom = count($g['lampes'] ?? []) == count($g['lids'] ?? []) ? array_combine($g['lampes'] ?? [], $g['lids'] ?? []) : []; // tendances d'avant la 0.13 : sans lids, pas de scènes
      foreach ($g['scenes'] ?? [] as $sc => $pc) { // « A + B » → ids encore simulées
        $l = array_values(array_intersect(array_map(fn($n) => $parNom[$n] ?? 0, explode(' + ', $sc)), $ids));
        if ($l) $s['scenes'][] = [$l, $pc];
      }
      $r[$g['nom']] = $s;
    }
    return $r;
  }

  // Règles du Guide à la minute $m d'un jour $k : « jamais » d'une plage exclue, et seulement dans les plages « peut » s'il y en a
  public static function permis(array $plages, $k, $m) {
    $mn = fn($x) => 60 * (int)substr($x, 0, 2) + (int)substr($x, 3, 2);
    $dans = function ($q) use ($m, $mn) { $a = $mn($q['de']); $b = $mn($q['a']); return $a == $b || ($a < $b ? $m >= $a && $m < $b : $m >= $a || $m < $b); };
    $marche = null;
    foreach ($plages as $q) {
      if ($q['j'] != 'tous' && $q['j'] != $k) continue;
      if ($q['k'] == 'exclue' && $dans($q)) return false;
      if ($q['k'] == 'marche') $marche = $marche || $dans($q);
    }
    return $marche !== false;
  }

  /* Pénombre k de 0 (plein jour) à 1 (nuit) : capteur de luminosité si lu (entre $bas et $haut lux),
     sinon le soleil ($sun = date_sun_info) : 1 avant l'aube civile (−6°), 0 une fois le soleil aussi haut qu'elle est basse, idem le soir */
  public static function penombre($t, array $sun, $lux = null, $bas = 350, $haut = 1000) {
    if ($lux !== null) return max(0, min(1, ($haut - $lux) / max(1, $haut - $bas)));
    $a = $sun['civil_twilight_begin'] ?? null; $l = $sun['sunrise'] ?? null; $c = $sun['sunset'] ?? null; $z = $sun['civil_twilight_end'] ?? null;
    if (!is_int($a) || !is_int($l) || !is_int($c) || !is_int($z)) return 1; // nuit ou jour polaire : ponytail, la France n'en a pas
    if ($t <= $a || $t >= $z) return 1;
    $m = $l + ($l - $a); $s = $c - ($z - $c);
    if ($t < $m) return ($m - $t) / ($m - $a);
    if ($t > $s) return ($t - $s) / ($z - $s);
    return 0;
  }

  // Aléa du jour : décalage de ±45 min, facteur d'activité de 0,7 à 1,3
  public static function jour(callable $r) { return ['dec' => (int)round(-45 + 90 * $r()), 'f' => round(.7 + .6 * $r(), 2)]; }

  /* Volets de la journée : ouverture et fermeture (minutes ; f null = pas fermé la nuit). $volets = sortie volets d'analyser(),
     $n = jour ISO, $kM / $kS = semaine ou week-end du matin et du soir, $lever / $coucher en minutes.
     Historique : entre q25 et q75 des jours relevés ; provisoire : lever + 30 à 60 min (pas avant 07:00, 08:30 le week-end), coucher + 20 à 50 min (pas après 22:30). */
  public static function planVolets(array $volets, array $regles, $kM, $kS, $lever, $coucher, callable $r, $fiable) {
    $p = [];
    foreach ($volets as $v) {
      if (!empty($regles[$v['id']]['jamais'])) continue;
      $m = $v['matin'][$kM] ?? []; $s = $v['soir'][$kS] ?? [];
      $hm = ($v['jours'] ?? 0) >= $fiable && ($m['n'] ?? 0) && empty($m['confirmer']);
      $hs = ($v['jours'] ?? 0) >= $fiable && ($s['n'] ?? 0) && empty($s['confirmer']);
      $o = $hm ? $m['q25'] + $r() * ($m['q75'] - $m['q25']) : max($lever + 30, $kM == 'W' ? 510 : 420) + 30 * $r(); // provisoire : pas tous à la même minute
      $f = $hs ? $s['q25'] + $r() * ($s['q75'] - $s['q25']) : min($coucher + 20 + 30 * $r(), 1350);
      if (isset($s['ferme']) && empty($s['confirmerF']) && $s['ferme'] < 10) $f = null; // jamais (ou presque) fermé la nuit
      $p[$v['id']] = ['nom' => $v['nom'], 'o' => (int)round($o), 'f' => $f === null ? null : (int)round($f), 'src' => ($hm ? 'hist' : 'prov') . '/' . ($hs ? 'hist' : 'prov')];
    }
    return $p;
  }

  private static function duree(array $s, callable $r) { // minutes
    if ($r() * 100 < $s['pas']) return 1 + 2 * $r(); // passage éclair : éteint au passage suivant
    return max(self::PAS, $s['d'][0] + $r() * ($s['d'][1] - $s['d'][0]));
  }
  private static function lampes(array $s, callable $r) {
    if ($s['scenes']) { // une scène tirée au poids
      $x = $r() * array_sum(array_column($s['scenes'], 1));
      foreach ($s['scenes'] as [$l, $pc]) if (($x -= $pc) < 0) return $l;
      return end($s['scenes'])[0];
    }
    return [$s['ids'][min(count($s['ids']) - 1, (int)floor($r() * count($s['ids'])))]];
  }

  /* Un passage du cron. $E = état {g: {nom: {on, fin, l, pause, vis}}, v: {id: {ferme, fj}}, jour: {d, dec, f, volets}} ;
     $C = {t, m (minute du jour), k (S|W), pk (pénombre), sources, max (présences simultanées), sansJour [noms], regles}.
     → [état, décisions [{g|v, nom, ids, action: on|off|ouvrir|fermer, src, p, motif}]] */
  public static function pas(array $E, array $C, callable $r) {
    $t = $C['t']; $m = $C['m']; $k = $C['k']; $J = $E['jour']; $dec = [];
    $on = 0; foreach ($E['g'] ?? [] as $e) if (!empty($e['on'])) $on++;
    $h = intdiv(((($m - $J['dec']) % 1440) + 1440) % 1440, 60); // heure du profil, décalée du jour
    foreach ($C['sources'] as $nom => $s) {
      $e = ($E['g'][$nom] ?? []) + ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => 0, 'vis' => 0];
      if ($e['vis'] > $t) { $E['g'][$nom] = $e; continue; } // visiteur : quelqu'un l'a changée, laissée tranquille
      $ok = $s['src'][$k] != 'jamais' && self::permis($s['plages'], $k, $m);
      $pk = in_array($nom, $C['sansJour'], true) ? 1 : $C['pk'];
      $p = min(1, $s['profil'][$k][$h] / 100 * $pk * $J['f']);
      $d = ['g' => 1, 'nom' => $nom, 'src' => $s['src'][$k], 'p' => round($p, 3)];
      if ($e['on']) {
        $fin = !$ok ? 'plage' : ($s['profil'][$k][$h] <= 0 ? 'jamais' : ($t >= $e['fin'] && $r() >= 1 - pow(1 - $p, self::GAP / 60) ? 'fin' : null)); // heure où la pièce n'a jamais été allumée : éteinte, même au milieu d'une longue durée tirée (une cuisine allumée toute la journée ne file pas jusqu'à 3 h)
        if ($t >= $e['fin'] && !$fin) $e['fin'] = $t + 60 * self::duree($s, $r); // prolongée
        if ($fin) { $dec[] = $d + ['ids' => $e['l'], 'action' => 'off', 'motif' => $fin]; $e = ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => $t + 60 * (10 + 30 * $r()), 'vis' => 0]; $on--; }
      } elseif ($ok && $p > 0 && $t >= $e['pause'] && $on < $C['max'] && $r() < 1 - pow(1 - $p, self::PAS / 60)) {
        $e = ['on' => 1, 'fin' => $t + 60 * self::duree($s, $r), 'l' => self::lampes($s, $r), 'pause' => 0, 'vis' => 0]; $on++;
        $dec[] = $d + ['ids' => $e['l'], 'action' => 'on', 'motif' => 'tirage'];
      }
      $E['g'][$nom] = $e;
    }
    $mj = ($m - 240 + 1440) % 1440; // minutes depuis 04:00 : la nuit appartient au jour d'avant
    foreach ($J['volets'] as $id => $v) {
      $e = ($E['v'][$id] ?? []) + ['ferme' => 0, 'fj' => ''];
      $d = ['v' => 1, 'nom' => $v['nom'], 'ids' => [(int)$id], 'src' => $v['src'], 'p' => 1];
      $pl = $C['regles'][$id]['plages'] ?? [];
      $fj = $v['f'] === null ? null : ($v['f'] - 240 + 1440) % 1440; $oj = ($v['o'] - 240 + 1440) % 1440;
      if (!$e['ferme'] && $fj !== null && $mj >= $fj && $e['fj'] !== $J['d'] && self::permis($pl, $k, $m)) {
        $e = ['ferme' => 1, 'fj' => $J['d']]; $dec[] = $d + ['action' => 'fermer', 'motif' => 'soir'];
      } elseif ($e['ferme'] && $mj >= $oj && ($fj === null || $mj < $fj) && $e['fj'] !== $J['d'] && self::permis($pl, $k, $m)) {
        $e['ferme'] = 0; $dec[] = $d + ['action' => 'ouvrir', 'motif' => 'matin']; // n'ouvre que ce qu'il a fermé
      }
      $E['v'][$id] = $e;
    }
    return [$E, $dec];
  }

  /* Visiteur (envoi réel seulement) : une lampe d'un groupe changée par quelqu'un d'autre ($reels[id] = allumée ou non) → groupe laissé $min minutes.
     Groupe allumé par le moteur dont toutes les lampes sont éteintes, ou groupe éteint dont une lampe est allumée. */
  public static function visiteurs(array $E, array $sources, array $reels, $t, $min) {
    foreach ($sources as $nom => $s) {
      $e = $E['g'][$nom] ?? null; if (!$e || ($e['vis'] ?? 0) > $t) continue;
      $l = $e['on'] ? $e['l'] : $s['ids']; $vus = array_intersect_key($reels, array_flip($l));
      if (!$vus) continue;
      $allume = in_array(true, $vus, true);
      if ($e['on'] != $allume) $E['g'][$nom] = ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => 0, 'vis' => $t + 60 * $min];
    }
    return $E;
  }

  // Arrêt : tout ce que le moteur a allumé est éteint (les volets restent comme ils sont)
  public static function arret(array $E) {
    $dec = [];
    foreach ($E['g'] ?? [] as $nom => $e) if (!empty($e['on'])) $dec[] = ['g' => 1, 'nom' => $nom, 'ids' => $e['l'], 'action' => 'off', 'src' => '', 'p' => 0, 'motif' => 'arret'];
    return $dec;
  }
}

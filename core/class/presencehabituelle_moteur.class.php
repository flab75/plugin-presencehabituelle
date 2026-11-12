<?php
/* Moteur de la simulation : tirages purs (aucun accès à Jeedom), testés par tests/selftest.php.
   Chaque groupe (pièce) suit, en semaine et le week-end séparément, l'une de deux sources :
   « hist » = son profil tiré de l'historique (assez de jours comptés), « prov » = horaires provisoires de son type de pièce.
   Heures en minutes d'horloge depuis 00:00 ; l'aléa vient de $r (callable → [0, 1[), pour rejouer une journée. */
class presencehabituelle_moteur {
  const PAS = 5;  // min entre deux passages du cron
  const EXCEPTION = 1 / 30; // ponytail: un jour sur 30 environ, une pièce allumée peut aller au bout de sa durée tirée, même à une heure jamais allumée (oubli, soirée tardive) ; réglage si besoin
  const GAP = 20; // min : fenêtre de prolongation à la fin d'une durée tirée (durées provisoires seulement : relevées, la distribution a déjà ses longues sessions)

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
      $s['qs'] = $hd && count($du['qs'] ?? []) > 1 ? $du['qs'] : []; // durées relevées (20 vingtièmes) : tirées telles quelles, passages compris
      $s['scenes'] = [];
      $parNom = count($g['lampes'] ?? []) == count($g['lids'] ?? []) ? array_combine($g['lampes'] ?? [], $g['lids'] ?? []) : []; // tendances d'avant la 0.13 : sans lids, pas de scènes
      foreach ($g['scenes'] ?? [] as $sc => $pc) { // « A + B » → ids encore simulées
        $l = array_values(array_intersect(array_map(fn($n) => $parNom[$n] ?? 0, explode(' + ', $sc)), $ids));
        if ($l) $s['scenes'][] = [$l, $pc];
      }
      $s['soleil'] = $g['soleil'] ?? [];
      $s['suites'] = $g['suites'] ?? []; $s['q'] = (float)($g['q'] ?? 0);
      $r[$g['nom']] = $s;
    }
    return $r;
  }

  // Règles du Guide à la minute $m d'un jour $k : « jamais » d'une plage exclue, et seulement dans les plages « peut » s'il y en a
  // $ex = jour d'exception (jour()) : une plage exclue marquée x (« sauf exception ») ne compte pas ce jour-là
  public static function permis(array $plages, $k, $m, $ex = false) {
    $mn = fn($x) => 60 * (int)substr($x, 0, 2) + (int)substr($x, 3, 2);
    $dans = function ($q) use ($m, $mn) { $a = $mn($q['de']); $b = $mn($q['a']); return $a == $b || ($a < $b ? $m >= $a && $m < $b : $m >= $a || $m < $b); };
    $marche = null;
    foreach ($plages as $q) {
      if ($q['j'] != 'tous' && $q['j'] != $k) continue;
      if ($q['k'] == 'exclue' && $dans($q) && !($ex && !empty($q['x']))) return false;
      if ($q['k'] == 'marche') $marche = $marche || $dans($q);
    }
    return $marche !== false;
  }

  /* Recalage : coefficients par pièce {nom: {cf, cd}} ; cf multiplie la probabilité d'allumer (profil), cd la durée tirée.
     Les heures jamais allumées restent à 0. */
  public static function caler(array $sources, array $coef) {
    foreach ($coef as $nom => $c) if (isset($sources[$nom])) {
      $cf = (float)($c['cf'] ?? 1); $cd = (float)($c['cd'] ?? 1);
      $sources[$nom]['cf'] = $cf; // l'allumage seulement : un profil gonflé empêcherait aussi d'éteindre (WC allumé 16 h, places de présence prises)
      $sources[$nom]['d'] = [$sources[$nom]['d'][0] * $cd, $sources[$nom]['d'][1] * $cd];
      $sources[$nom]['cd'] = $cd; // appliqué aux durées relevées (qs) après le tri éclair / présence, qui reste celui du réel
    }
    return $sources;
  }
  const BORNE = 3; // coefficients entre 1/3 et 3
  /* Un tour de recalage. $R / $P = {nom: bilan()} réel / rejoué ; $tol = [heures, allumages] en fraction (0,15 = ±15 %).
     → [coefficients, {nom: {ok, borne, eh, en}}] ; eh / en = écart relatif des heures et des allumages par jour.
     ponytail: pas amorti (racine du rapport) ; écart sous 6 min par jour toujours accepté (pièce presque jamais allumée) */
  public static function ajuster(array $R, array $P, array $coef, array $tol) {
    $v = []; $b = fn($x) => max(1 / self::BORNE, min(self::BORNE, $x));
    foreach ($R as $nom => $r) {
      if (!isset($P[$nom])) continue;
      $p = $P[$nom]; $c = ($coef[$nom] ?? []) + ['cf' => 1, 'cd' => 1];
      $eh = $r['h'] > 0 ? ($p['h'] - $r['h']) / $r['h'] : ($p['h'] > 0 ? 1 : 0);
      $en = $r['n'] > 0 ? ($p['n'] - $r['n']) / $r['n'] : ($p['n'] > 0 ? 1 : 0);
      $ok = (abs($eh) <= $tol[0] || abs($p['h'] - $r['h']) < .1) && (abs($en) <= $tol[1] || abs($p['n'] - $r['n']) < .1);
      $av = $c;
      if (!$ok && $r['n'] > 0) {
        $c['cf'] = $b($c['cf'] * ($p['n'] > 0 ? sqrt($r['n'] / $p['n']) : self::BORNE));
        if ($p['n'] > 0 && $p['h'] > 0) $c['cd'] = $b($c['cd'] * sqrt(($r['h'] / $r['n']) / ($p['h'] / $p['n'])));
      } elseif (!$ok && $p['n'] > 0) $c['cf'] = $b($c['cf'] / self::BORNE); // jamais allumée en vrai : bien moins souvent
      $c = ['cf' => round($c['cf'], 3), 'cd' => round($c['cd'], 3)];
      $coef[$nom] = $c;
      $sat = abs($en) > $tol[1] && abs($p['n'] - $r['n']) >= .1 && ($en < 0 ? $av['cf'] >= self::BORNE : $av['cf'] <= round(1 / self::BORNE, 3)); // trop peu (ou trop) d'allumages, cf déjà à sa limite
      $v[$nom] = ['ok' => $ok, 'borne' => !$ok && ($sat || $c == ['cf' => round($av['cf'], 3), 'cd' => round($av['cd'], 3)]), 'eh' => round($eh, 3), 'en' => round($en, 3)];
    }
    return [$coef, $v];
  }

  const LUX_AGE = 7200; // s : capteur muet plus longtemps = source suivante (secours, puis soleil) ; 2 h : certains capteurs Zigbee n'envoient qu'un relevé par heure
  const LUX_NUIT = 43200; // s : muet sur une mesure de nuit (≤ bas) : un capteur qui n'envoie qu'aux changements reste valable 12 h
  /* Capteurs de luminosité par ordre de préférence. $m[i] = ['v' => lux ou null, 'age' => s depuis la dernière mesure,
     'lampe' => true si la lampe qui fausse la mesure est allumée, 'bas', 'haut'] ; $L = valeurs lissées du passage d'avant.
     Chaque source est lissée (~40 min, un nuage ne rallume pas la maison) ; lampe allumée : mesure faussée, source suivante
     (le lissage d'avant est gardé pour l'extinction).
     $nuit : le soleil dit nuit ou pénombre ; sinon pas de délai de nuit (un capteur gelé sur 0 lux la nuit forcerait la nuit le matin :
     un capteur qui n'envoie qu'aux changements en envoie forcément un à l'aube).
     Rend [[lux ou null, bas, haut, n° de la source utilisée (0 = soleil)], $L]. */
  public static function lumiere(array $m, array $L, $nuit = true) {
    $r = [null, 350, 1000, 0];
    foreach (array_values($m) as $i => $x) {
      if ($x['v'] === null || $x['age'] > ($x['v'] <= $x['bas'] && $nuit ? self::LUX_NUIT : self::LUX_AGE)) { unset($L[$i]); continue; } // muet : lissage repris de zéro à son retour
      if (!empty($x['lampe'])) continue; // pas de valeur gelée : elle entretiendrait la nuit tant que la simulation laisse la lampe allumée
      $L[$i] = isset($L[$i]) ? .88 * $L[$i] + .12 * (float)$x['v'] : (float)$x['v'];
      if ($r[3] === 0) $r = [$L[$i], $x['bas'], $x['haut'], $i + 1];
    }
    return [$r, $L];
  }

  /* Pénombre k de 0 (plein jour) à 1 (nuit). Soleil ($sun = date_sun_info) : 1 avant l'aube civile (−6°), 0 une fois le soleil aussi haut
     qu'elle est basse, idem le soir. Capteur lu (entre $bas et $haut lux) : le plus sombre des deux. Le capteur ne fait qu'avancer la
     pénombre (jour gris) ; il ne peut pas faire le jour la nuit (capteur éclairé par une lampe, en panne). Un capteur trop sombre
     (à l'ombre l'après-midi) avance toujours la pénombre : seuils plus bas. */
  public static function penombre($t, array $sun, $lux = null, $bas = 350, $haut = 1000) {
    $k = self::penombreSoleil($t, $sun);
    return $lux === null ? $k : max($k, min(1, ($haut - $lux) / max(1, $haut - $bas)));
  }
  private static function penombreSoleil($t, array $sun) {
    $a = $sun['civil_twilight_begin'] ?? null; $l = $sun['sunrise'] ?? null; $c = $sun['sunset'] ?? null; $z = $sun['civil_twilight_end'] ?? null;
    if (!is_int($a) || !is_int($l) || !is_int($c) || !is_int($z)) return 1; // nuit ou jour polaire : ponytail, la France n'en a pas
    if ($t <= $a || $t >= $z) return 1;
    $m = $l + ($l - $a); $s = $c - ($z - $c);
    if ($t < $m) return ($m - $t) / ($m - $a);
    if ($t > $s) return ($t - $s) / ($z - $s);
    return 0;
  }

  // Aléa du jour : décalage de ±45 min, facteur d'activité de 0,7 à 1,3
  public static function jour(callable $r) { return ['dec' => (int)round(-45 + 90 * $r()), 'f' => round(.7 + .6 * $r(), 2), 'ex' => $r() < self::EXCEPTION]; }

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

  /* % du profil à la minute $mb (décalée du jour), à l'horloge, au soleil ou entre les deux selon la pièce (analyse soleil() : part b de 0 à 1).
     Le soir, l'allumage glisse de b × (coucher du jour − coucher moyen des jours analysés), le matin l'extinction de b × (lever du jour − lever moyen).
     Plus tôt : le plus grand du profil et du profil décalé ; plus tard : le plus petit. L'heure du coucher et le lever des habitants restent ceux de l'horloge. */
  public static function profilSoleil(array $s, $k, $mb, array $J) {
    $at = fn($x) => (int)$s['profil'][$k][intdiv((($x % 1440) + 1440) % 1440, 60)];
    $mb = (($mb % 1440) + 1440) % 1440; $v = $at($mb); $h = intdiv($mb, 60);
    $g = fn($x, $d) => $d > 0 ? max($v, $x) : min($v, $x);
    if ($h >= 12 && ($o = $s['soleil']['soir'] ?? null) && isset($J['cm']) && ($d = (int)round($o['b'] * ($o['c'] - $J['cm']))) && $mb + $d < 1440 && $mb + $d >= 720) $v = $g($at($mb + $d), $d);
    if ($h >= 4 && $h < 12 && ($o = $s['soleil']['matin'] ?? null) && isset($J['lm']) && ($d = (int)round($o['b'] * ($J['lm'] - $o['c']))) && $mb - $d >= 240 && $mb - $d < 720) $v = $g($at($mb - $d), $d);
    return $v;
  }
  const PASSAGE = 3; // min : durée tirée plus courte = passage éclair (comme presencehabituelle_analyse::PASSAGE)
  private static function duree(array $s, callable $r) { // minutes
    if ($q = $s['qs'] ?? []) { // durées relevées : tirage dans la distribution entière (longues soirées et visites de 3 min comprises)
      $x = $q[min(count($q) - 1, (int)($r() * count($q)))]; // une des 20 durées, au hasard
      if ($x < self::PASSAGE) return 0; // passage éclair : éteint au passage suivant
      // La lampe s'éteint au premier passage du cron après la fin (toutes les PAS min) : sans correction, chaque durée gagnerait
      // PAS / 2 en moyenne (WC : 6 min relevées, 8 simulées). Fin avancée d'une part tirée de PAS : arrondi au passage juste en moyenne.
      return max(1, $x * ($s['cd'] ?? 1) - self::PAS * $r());
    }
    if ($r() * 100 < $s['pas']) return 0; // passage éclair : éteint au passage suivant (5 min)
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

  /* Un passage du cron. $E = état {g: {nom: {on, fin, l, pause, vis, off, lo}}, v: {id: {ferme, fj, nom}}, jour: {d, dec, f, volets}, reel} ;
     off / lo = heure et lampes du dernier « off » (renvoyé s'il s'est perdu, voir visiteurs()) ;
     $C = {t, m (minute du jour), k (S|W), pk (pénombre), sources, max (présences simultanées), sansJour [noms], regles}.
     → [état, décisions [{g|v, nom, ids, action: on|off|ouvrir|fermer, src, p, motif}]] */
  public static function pas(array $E, array $C, callable $r) {
    $t = $C['t']; $m = $C['m']; $k = $C['k']; $J = $E['jour']; $dec = []; $partis = [];
    $on = 0; foreach ($E['g'] ?? [] as $e) if (!empty($e['on']) && empty($e['ec'])) $on++; // un passage éclair n'est pas une présence (simultanees() ne compte que les allumages tenus 3 min)
    foreach ($C['sources'] as $nom => $s) {
      $e = ($E['g'][$nom] ?? []) + ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => 0, 'vis' => 0];
      if ($e['vis'] > $t) { $E['g'][$nom] = $e; continue; } // visiteur : quelqu'un l'a changée, laissée tranquille
      $ok = $s['src'][$k] != 'jamais' && self::permis($s['plages'], $k, $m, !empty($J['ex']));
      $pk = in_array(mb_strtolower((string)$nom), $C['sansJour'], true) ? 1 : $C['pk']; // (string) : un nom fait de chiffres devient une clé entière
      $v = self::profilSoleil($s, $k, $m - $J['dec'], $J);
      $p = min(1, $v / 100 * $pk * $J['f']);
      $d = ['g' => 1, 'nom' => $nom, 'src' => $s['src'][$k], 'p' => round($p, 3)];
      if ($e['on']) {
        $fin = !$ok ? 'plage' : (!empty($e['ec']) ? 'eclair' : ($v <= 0 && empty($J['ex']) ? 'jamais' : ($t >= $e['fin'] && (!empty($s['qs']) || $r() >= 1 - pow(1 - $p, self::GAP / 60)) ? 'fin' : null))); // heure où la pièce n'a jamais été allumée : éteinte, même au milieu d'une longue durée tirée (une cuisine allumée toute la journée ne file pas jusqu'à 3 h), sauf un jour d'exception
        if ($t >= $e['fin'] && !$fin) $e['fin'] = $t + 60 * self::duree($s, $r); // prolongée
        if ($fin == 'fin' || $fin == 'jamais') $partis[] = $nom; // partie d'elle-même (pas une règle du Guide)
        if ($fin) { $dec[] = $d + ['ids' => $e['l'], 'action' => 'off', 'motif' => $fin]; $on -= empty($e['ec']) ? 1 : 0; $e = ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => $t + 60 * (10 + 30 * $r()), 'vis' => 0, 'off' => $t, 'lo' => $e['l']]; }
      } elseif ($ok && $p > 0 && $t >= $e['pause'] && $on < $C['max'] && $r() < 1 - pow(1 - min(1, $p * ($s['cf'] ?? 1)), self::PAS / 60)) {
        $du = self::duree($s, $r);
        $e = ['on' => 1, 'fin' => $t + 60 * $du, 'l' => self::lampes($s, $r), 'pause' => 0, 'vis' => 0] + ($du ? [] : ['ec' => 1]); if ($du) $on++;
        $dec[] = $d + ['ids' => $e['l'], 'action' => 'on', 'motif' => 'tirage'];
      }
      $E['g'][$nom] = $e;
    }
    foreach ($partis as $de) { // trajet : une pièce éteinte d'elle-même, la suite tirée parmi les trajets relevés (analyse trajets())
      $s = $C['sources'][$de];
      if (empty($s['suites']) || $on >= $C['max'] || $r() >= ($s['q'] ?? 0)) continue; // q : part des départs suivis d'un trajet ; plus de place pour la pièce d'arrivée : pas de trajet
      $x = $r() * array_sum(array_column($s['suites'], 1)); $ch = end($s['suites'])[0];
      foreach ($s['suites'] as [$c, $n]) if (($x -= $n) < 0) { $ch = $c; break; }
      $libre = function ($nom) use ($C, $E, $de, $t, $k, $m, $J) { // la pièce peut s'allumer maintenant
        $sv = $C['sources'][$nom] ?? null; $e = ($E['g'][$nom] ?? []) + ['on' => 0, 'pause' => 0, 'vis' => 0];
        return $sv && (string)$nom !== (string)$de && !$e['on'] && $e['vis'] <= $t && $t >= $e['pause'] && $sv['src'][$k] != 'jamais' && self::permis($sv['plages'], $k, $m, !empty($J['ex']))
          && (in_array(mb_strtolower((string)$nom), $C['sansJour'], true) ? 1 : $C['pk']) > 0; // de jour : pas de trajet
      };
      $fin = end($ch); $sf = $C['sources'][$fin] ?? null;
      if (!$libre($fin) || self::profilSoleil($sf, $k, $m - $J['dec'], $J) <= 0 || $r() >= min(1, $sf['cf'] ?? 1)) continue; // arrivée d'abord : jamais allumée à cette heure, ou retenue par le calage (cf < 1) → personne ne traverse
      foreach ($ch as $i => $nom) {
        if (!$libre($nom)) break; // chemin coupé : on s'arrête là
        $sv = $C['sources'][$nom];
        $der = $i == count($ch) - 1;
        $du = $der ? self::duree($sv, $r) : 0; // en chemin : passage lumineux, éteint au passage suivant
        $E['g'][$nom] = ['on' => 1, 'fin' => $t + 60 * $du, 'l' => self::lampes($sv, $r), 'pause' => 0, 'vis' => 0] + ($du ? [] : ['ec' => 1]); if ($du) $on++;
        $dec[] = ['g' => 1, 'nom' => $nom, 'src' => $sv['src'][$k], 'p' => 1, 'ids' => $E['g'][$nom]['l'], 'action' => 'on', 'motif' => 'trajet'];
      }
    }
    foreach ($E['g'] ?? [] as $nom => $e) if (!isset($C['sources'][$nom])) { // pièce sortie des sources (commande décochée, Guide) : éteinte, oubliée
      if (!empty($e['on'])) $dec[] = ['g' => 1, 'nom' => $nom, 'ids' => $e['l'], 'action' => 'off', 'src' => '', 'p' => 0, 'motif' => 'disparu'];
      unset($E['g'][$nom]);
    }
    $mj = ($m - 240 + 1440) % 1440; // minutes depuis 04:00 : la nuit appartient au jour d'avant
    foreach ($J['volets'] as $id => $v) {
      $e = ($E['v'][$id] ?? []) + ['ferme' => 0, 'fj' => ''];
      $d = ['v' => 1, 'nom' => $v['nom'], 'ids' => [(int)$id], 'src' => $v['src'], 'p' => 1];
      $pl = $C['regles'][$id]['plages'] ?? [];
      $fj = $v['f'] === null ? null : ($v['f'] - 240 + 1440) % 1440; $oj = ($v['o'] - 240 + 1440) % 1440;
      if (!$e['ferme'] && $fj !== null && $mj >= $fj && $e['fj'] !== $J['d'] && self::permis($pl, $k, $m, !empty($J['ex']))) {
        $e = ['ferme' => 1, 'fj' => $J['d'], 'nom' => $v['nom']]; $dec[] = $d + ['action' => 'fermer', 'motif' => 'soir'];
      } elseif ($e['ferme'] && $mj >= $oj && $mj < 720 && ($fj === null || $mj < $fj) && $e['fj'] !== $J['d'] && self::permis($pl, $k, $m, !empty($J['ex']))) {
        $e['ferme'] = 0; $dec[] = $d + ['action' => 'ouvrir', 'motif' => 'matin']; // n'ouvre que ce qu'il a fermé
      }
      $E['v'][$id] = $e;
    }
    foreach ($E['v'] ?? [] as $id => $e) if (!isset($J['volets'][$id])) { // volet sorti du plan (« jamais simulé ») : rouvert le matin s'il l'a fermé, puis oublié
      if (empty($e['ferme'])) unset($E['v'][$id]);
      elseif ($mj >= 240 && $mj < 720) { $dec[] = ['v' => 1, 'nom' => $e['nom'] ?? '', 'ids' => [(int)$id], 'src' => '', 'p' => 1, 'action' => 'ouvrir', 'motif' => 'hors plan']; unset($E['v'][$id]); }
    }
    return [$E, $dec];
  }

  /* Visiteur (envoi réel seulement) : une lampe d'un groupe changée par quelqu'un d'autre ($reels[id] = allumée ou non) → groupe laissé $min minutes.
     Groupe allumé par le moteur dont toutes les lampes sont éteintes, ou groupe éteint dont une lampe est allumée (aussi au 1er passage après On).
     Sauf un « off » du moteur de moins de 10 min dont les lampes sont restées allumées : ordre perdu, renvoyé. → [état, décisions] */
  public static function visiteurs(array $E, array $sources, array $reels, $t, $min) {
    $dec = [];
    foreach ($sources as $nom => $s) {
      $e = ($E['g'][$nom] ?? []) + ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => 0, 'vis' => 0];
      if ($e['vis'] > $t) continue;
      $vus = array_intersect_key($reels, array_flip($e['on'] ? $e['l'] : $s['ids']));
      if (!$vus || (bool)$e['on'] == in_array(true, $vus, true)) continue;
      $lit = array_keys(array_filter($vus));
      if (!$e['on'] && $t - ($e['off'] ?? 0) <= 600 && !array_diff($lit, $e['lo'] ?? [])) { $dec[] = ['g' => 1, 'nom' => $nom, 'ids' => $lit, 'action' => 'off', 'src' => '', 'p' => 0, 'motif' => 'renvoi']; continue; }
      $E['g'][$nom] = ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => 0, 'vis' => $t + 60 * $min];
    }
    return [$E, $dec];
  }

  // Arrêt : tout ce que le moteur a allumé est éteint (les volets restent comme ils sont)
  public static function arret(array $E) {
    $dec = [];
    foreach ($E['g'] ?? [] as $nom => $e) if (!empty($e['on'])) $dec[] = ['g' => 1, 'nom' => $nom, 'ids' => $e['l'], 'action' => 'off', 'src' => '', 'p' => 0, 'motif' => 'arret'];
    return $dec;
  }
}

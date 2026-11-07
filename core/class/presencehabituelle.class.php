<?php
/* Présence habituelle : tire des historiques (lumières, volets) les tendances, les exceptions et ce qui n'arrive jamais, sans rien envoyer hors de la box.
   0.6 : analyse seule (aucune action sur les équipements), plan de la maison par étage. */
require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/presencehabituelle_analyse.class.php';

class presencehabituelle extends eqLogic {
  const TABLE = 'presencehabituelle_event'; // src : 0 listener ou état noté, 1 repris de l'historique (points exacts seulement)
  const FIABLE = 10; // jours comptés sous lesquels une habitude est « à confirmer »
  // préconisation par type générique : [type, cochée d'office]
  const TYPES = ['LIGHT_STATE' => ['lumiere', 1], 'LIGHT_STATE_BOOL' => ['lumiere', 1], 'FLAP_STATE' => ['volet', 1], 'FLAP_BSO_STATE' => ['volet', 1],
    'ENERGY_STATE' => ['prise', 0], 'SWITCH_STATE' => ['prise', 0], 'MEDIA_STATUS' => ['media', 0], 'MEDIA_STATE' => ['media', 0]];

  public static function creerTable() {
    DB::Prepare('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, cmd_id INT NOT NULL,
      ts INT UNSIGNED NOT NULL, valeur DOUBLE NOT NULL, src TINYINT NOT NULL DEFAULT 0, KEY cmd_ts (cmd_id, ts)) ENGINE=InnoDB', [], DB::FETCH_TYPE_ROW);
  }

  /* Installation / réactivation. Jeedom appelle _remove aussi à la simple désactivation : la table y est gardée.
     Une désinstallation efface la configuration (core) : une table restée sans configuration est d'une installation précédente → vidée. */
  public static function demarrer() {
    self::creerTable();
    if (!self::suivi() && !(int)config::byKey('exclure_cmd', __CLASS__, 0)) self::effacer();
    else foreach (self::ids() as $id) self::noter($id); // état pendant la désactivation : une extinction manquée ne fait pas une session sans fin
    if ($a = (int)config::byKey('arret', __CLASS__, 0)) { // rien n'a été relevé pendant la désactivation : ces jours ne comptent pas
      self::exclure($a, time()); config::remove('arret', __CLASS__);
    }
    config::save('vu', time(), __CLASS__);
    self::majListener();
  }

  public static function arreter() {
    if (is_object($l = listener::byClassAndFunction(__CLASS__, 'evenement'))) $l->remove();
    config::save('arret', time(), __CLASS__);
  }

  /* Périodes exclues gardées [[début, fin]] : plugin désactivé, box arrêtée, absences d'une ancienne commande d'absence
     (config::byKey décode déjà le JSON) */
  public static function arrets() {
    $p = config::byKey('arrets', __CLASS__, []);
    if (is_string($p)) $p = json_decode($p, true);
    return is_array($p) ? $p : [];
  }

  public static function exclure($a, $b) {
    $p = self::arrets(); $p[] = [(int)$a, (int)$b]; config::save('arrets', json_encode($p), __CLASS__);
  }

  // Bouton « Tout effacer » : changements d'état et habitudes calculées (le choix des commandes reste)
  public static function effacer() {
    DB::Prepare('DELETE FROM ' . self::TABLE, [], DB::FETCH_TYPE_ROW);
    if (is_file($f = self::fichier())) unlink($f);
  }

  // {cmdId: {g: groupe, t: type}} ; config::byKey décode déjà le JSON (piège JD-54)
  public static function suivi() {
    $s = config::byKey('suivi', __CLASS__, []);
    if (is_string($s)) $s = json_decode($s, true);
    return is_array($s) ? $s : [];
  }

  // Commandes lues : suivies + commande d'absence
  private static function ids() {
    $ids = array_map('intval', array_keys(self::suivi()));
    if ($x = (int)config::byKey('exclure_cmd', __CLASS__, 0)) $ids[] = $x;
    return array_values(array_unique($ids));
  }

  // Valeur d'état → nombre (> 0 = allumé / ouvert / actif)
  public static function valeur($v) {
    return is_numeric($v) ? (float)$v : (presencehabituelle_analyse::actif($v) ? 1.0 : 0.0);
  }

  // Ajoute un changement (src 0) seulement si la valeur diffère de la dernière connue
  private static function ajouter($id, $v, $ts) {
    $d = DB::Prepare('SELECT valeur FROM ' . self::TABLE . ' WHERE cmd_id = ? ORDER BY ts DESC, id DESC LIMIT 1', [$id], DB::FETCH_TYPE_ROW);
    if (is_array($d) && (float)$d['valeur'] == $v) return;
    DB::Prepare('INSERT INTO ' . self::TABLE . ' (cmd_id, ts, valeur, src) VALUES (?, ?, ?, 0)', [$id, $ts, $v], DB::FETCH_TYPE_ROW);
  }

  // Listener synchrone
  public static function evenement($_options) {
    try { // synchrone : une erreur ici ne doit pas remonter au plugin qui a changé la valeur
      $id = (int)($_options['event_id'] ?? 0); if (!$id) return;
      self::ajouter($id, self::valeur($_options['value'] ?? 0), strtotime((string)($_options['datetime'] ?? '')) ?: time());
    } catch (Throwable $e) { log::add(__CLASS__, 'error', 'evenement : ' . $e->getMessage()); }
  }

  // État courant noté maintenant : début d'observation d'une commande non historisée, reprise après une désactivation
  public static function noter($id) {
    if (is_object($c = cmd::byId($id))) self::ajouter($id, self::valeur($c->execCmd()), time());
  }

  public static function majListener() {
    $ids = self::ids();
    $l = listener::byClassAndFunction(__CLASS__, 'evenement');
    if (!$ids) { if (is_object($l)) $l->remove(); return; }
    if (!is_object($l)) { $l = new listener(); $l->setClass(__CLASS__); $l->setFunction('evenement'); }
    $l->setOption('background', false); // ponytail: synchrone = 1 SELECT + 1 INSERT au plus par changement ; passer en background si une box lente le sent
    $l->emptyEvent();
    foreach ($ids as $id) $l->addEvent($id);
    $l->save();
  }

  // Historique lissé : Jeedom remplace les valeurs numériques par une moyenne horaire après historyArchiveTime HEURES
  public static function lisse($cmd) {
    return $cmd->getIsHistorized() && $cmd->getSubType() != 'binary' && $cmd->getConfiguration('historizeMode', 'avg') != 'none';
  }

  /* Reprise d'une commande par son historique Jeedom : changements exacts seulement (binaire, mode « Aucun », ou point pas encore archivé).
     Une moyenne horaire n'est ni un allumage ni une fermeture : elle est ignorée. À appeler AVANT noter() : s'arrête à la 1re ligne connue.
     Historique exact : l'état au début de la fenêtre est repris aussi (absence ou allumage commencés avant). */
  public static function amorcer($cmd, $jours) {
    DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE cmd_id = ? AND src > 0', [$cmd->getId()], DB::FETCH_TYPE_ROW);
    if (!$cmd->getIsHistorized() || $jours <= 0) return 0;
    $exact = time() - 3600 * max(0, (float)config::byKey('historyArchiveTime', 'core', 2)); // en heures (history::archive)
    $debut = self::lisse($cmd) ? max($exact, time() - 86400 * $jours) : time() - 86400 * $jours;
    $prem = DB::Prepare('SELECT MIN(ts) t FROM ' . self::TABLE . ' WHERE cmd_id = ?', [$cmd->getId()], DB::FETCH_TYPE_ROW);
    $fin = ($prem['t'] ?? null) ? (int)$prem['t'] : null; // les lignes déjà notées priment sur l'historique
    $lignes = []; $der = null;
    if (!self::lisse($cmd) && (!$fin || $fin > $debut)) { // dernier point avant la fenêtre, dans history puis historyArch
      $d = date('Y-m-d H:i:s', $debut); $av = null;
      foreach (['history', 'historyArch'] as $t)
        if (!$av && is_array($r = DB::Prepare('SELECT value FROM ' . $t . ' WHERE cmd_id = ? AND datetime < ? ORDER BY datetime DESC LIMIT 1', [$cmd->getId(), $d], DB::FETCH_TYPE_ROW))) $av = $r;
      if ($av) { $der = self::valeur($av['value']); $lignes[] = [$cmd->getId(), $debut, $der]; }
    }
    foreach (history::all($cmd->getId(), date('Y-m-d H:i:s', $debut), $fin ? date('Y-m-d H:i:s', $fin - 1) : null) as $h) {
      $v = self::valeur($h->getValue());
      if ($v === $der) continue;
      $der = $v; $lignes[] = [$cmd->getId(), strtotime($h->getDatetime()), $v];
    }
    foreach (array_chunk($lignes, 500) as $paquet) // une requête par 500 lignes
      DB::Prepare('INSERT INTO ' . self::TABLE . ' (cmd_id, ts, valeur, src) VALUES ' . implode(',', array_fill(0, count($paquet), '(?, ?, ?, 1)')),
        array_merge(...$paquet), DB::FETCH_TYPE_ROW);
    return count($lignes);
  }

  // Commandes info proposées, avec la préconisation (une seule commande d'état cochée par équipement)
  public static function candidats() {
    $eqs = []; foreach (eqLogic::all() as $e) $eqs[$e->getId()] = $e;
    $objs = []; foreach (jeeObject::all() as $o) $objs[$o->getId()] = $o->getName();
    $r = []; $choisi = [];
    foreach (cmd::byTypeSubType('info') as $c) {
      if (!($eq = $eqs[$c->getEqLogic_id()] ?? null) || $eq->getEqType_name() == __CLASS__) continue;
      [$t, $coche] = self::TYPES[$c->getGeneric_type()] ?? ['autre', 0];
      // préférée : binaire ou LIGHT_STATE_BOOL (une luminosité peut ne pas retomber à 0 à l'extinction)
      $rang = $coche ? ($c->getSubType() == 'binary' || $c->getGeneric_type() == 'LIGHT_STATE_BOOL' ? 2 : 1) : 0;
      $r[] = ['id' => $c->getId(), 'nom' => $c->getName(), 'eq' => $eq->getName(), 'objet' => $objs[$eq->getObject_id()] ?? '',
        'type' => $t, 'coche' => $coche, 'gen' => (string)$c->getGeneric_type(), 'hist' => (int)$c->getIsHistorized(), 'lisse' => (int)self::lisse($c),
        'actif' => (int)$eq->getIsEnable()];
      $k = $eq->getId() . '|' . $t;
      if ($rang && (!isset($choisi[$k]) || $rang > $choisi[$k][0])) $choisi[$k] = [$rang, count($r) - 1];
    }
    $garde = array_flip(array_column($choisi, 1));
    foreach ($r as $i => $c) if ($c['coche'] && !isset($garde[$i])) $r[$i]['coche'] = 0;
    return $r;
  }

  /* Enregistre le choix de la page. $suivi = {cmdId: {g, t}} ; renvoie le nombre de changements repris de l'historique. */
  public static function enregistrer(array $suivi, $exclure, $exclusions) {
    $A = 'presencehabituelle_analyse';
    $mal = [];
    foreach (preg_split('/\R/', (string)$exclusions) as $l) if (trim($l) !== '' && !$A::periodes($l)) $mal[] = trim($l);
    if ($mal) throw new Exception(__('Période illisible (attendu AAAA-MM-JJ HH:MM → AAAA-MM-JJ HH:MM)', __FILE__) . ' : ' . implode(' ; ', $mal));
    $propre = []; $avant = self::suivi(); $n = 0; $avantX = (int)config::byKey('exclure_cmd', __CLASS__, 0);
    foreach ($suivi as $id => $s) {
      if (!is_object($c = cmd::byId((int)$id)) || $c->getType() != 'info') continue;
      $propre[(int)$id] = ['g' => mb_substr(trim((string)($s['g'] ?? '')), 0, 60), 't' => in_array($s['t'] ?? '', ['lumiere', 'volet', 'prise', 'media', 'autre'], true) ? $s['t'] : 'autre'];
    }
    $exclure = (int)$exclure;
    if ($exclure && (!is_object($c = cmd::byId($exclure)) || $c->getType() != 'info')) throw new Exception(__('Commande d\'exclusion introuvable', __FILE__));
    if ($exclure && $c->getSubType() == 'string') // « Absent » en texte n'est pas reconnu : rien ne serait exclu, sans le dire
      throw new Exception(__('La commande d\'absence doit être binaire ou numérique (vraie pendant l\'absence) : un mode en texte ne convient pas, passez par un virtuel binaire', __FILE__));
    if ($exclure && self::lisse($c)) // une moyenne horaire (0,5 = absent une demi-heure) n'a ni début ni fin
      throw new Exception(__('Commande d\'absence numérique lissée : réglez son lissage sur « Aucun » (configuration de la commande, onglet Historique) ou passez par un virtuel binaire', __FILE__));
    if ($avantX && $avantX != $exclure) // commande d'absence changée ou retirée : ses absences restent exclues
      foreach (presencehabituelle_analyse::intervalles(self::evs($avantX), time()) as $i) self::exclure($i[0], $i[1]);
    $gardes = array_keys($propre); if ($exclure) $gardes[] = $exclure;
    foreach (array_keys($avant + [$avantX => 1]) as $id) // retirée = ses évènements partent
      if ($id && !in_array((int)$id, $gardes, true)) DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE cmd_id = ?', [(int)$id], DB::FETCH_TYPE_ROW);
    $jours = self::jours('amorcer');
    foreach (array_unique($gardes) as $id) if (!isset($avant[$id]) && $id != $avantX) { $n += self::amorcer(cmd::byId($id), $jours); self::noter($id); }
    config::save('suivi', json_encode($propre), __CLASS__);
    config::save('exclure_cmd', $exclure ?: '', __CLASS__);
    config::save('exclusions', (string)$exclusions, __CLASS__);
    self::majListener();
    return $n;
  }

  private static function evs($id) {
    return array_map(fn($d) => [(int)$d['ts'], (float)$d['valeur'], true],
      DB::Prepare('SELECT ts, valeur FROM ' . self::TABLE . ' WHERE cmd_id = ? ORDER BY ts, id', [$id], DB::FETCH_TYPE_ALL) ?: []);
  }

  // $piece : préfixe « [Objet] », pour départager deux équipements de même nom dans deux pièces
  private static function nom($id, $piece = false) {
    if (!is_object($c = cmd::byId($id)) || !is_object($eq = $c->getEqLogic())) return '#' . $id;
    return ($piece && is_object($o = $eq->getObject()) ? '[' . $o->getName() . '] ' : '') . $eq->getName() . ($c->getName() != 'Etat' && $c->getName() != 'État' ? ' ' . $c->getName() : '');
  }

  // Périodes exclues : commande d'exclusion active + périodes saisies + plugin désactivé
  public static function exclusions() {
    $A = 'presencehabituelle_analyse';
    $p = array_merge($A::periodes(config::byKey('exclusions', __CLASS__, '')), self::arrets());
    if ($x = (int)config::byKey('exclure_cmd', __CLASS__, 0))
      foreach ($A::intervalles(self::evs($x), time()) as $i) $p[] = [$i[0], $i[1]];
    return $p;
  }

  /* Plan de la maison : data/plan.json + une image par étage data/plan_<étage>.jpg (refusés au web par data/.htaccess).
     Coordonnées en % de l'image. {etages: [{id, nom}] (dans l'ordre), marqueurs: {cmdId: {e, x, y}}, pieces: [{id, e, nom, pts}],
     passages: [{id, e, a, b, t, pts}], zones: [{id, e, pts}]} */
  const VIDE = ['etages' => [], 'marqueurs' => [], 'pieces' => [], 'passages' => [], 'zones' => []];
  public static function planFichier() { return __DIR__ . '/../../data/plan.json'; }
  public static function planImage($e) { return __DIR__ . '/../../data/plan_' . $e . '.jpg'; }
  private static function ecrire($f, $d) { // fichier temporaire puis rename : jamais de fichier à moitié écrit
    if (file_put_contents($f . '.tmp', $d) !== strlen($d) || !rename($f . '.tmp', $f)) { @unlink($f . '.tmp'); throw new Exception(__('Écriture impossible sur la box (disque plein ?) : rien n\'a été enregistré', __FILE__)); }
  }
  private static function idPlan($v) { return is_string($v) && preg_match('/^[a-z0-9]{1,16}$/', $v) ? $v : null; }
  public static function plan() {
    $p = is_file($f = self::planFichier()) ? json_decode((string)file_get_contents($f), true) : null;
    $p = (is_array($p) ? $p : []) + self::VIDE;
    $p['marqueurs'] = (object)$p['marqueurs']; // {} et non [] pour le JS
    return $p;
  }

  // Valide tout ce qui vient de la page (jamais pris tel quel) puis enregistre ; les images des étages retirés partent
  public static function planEnregistrer(array $p) {
    $pc = fn($v) => is_numeric($v) ? round(max(0, min(100, (float)$v)), 2) : null;
    $pts = function ($l, $min, $max) use ($pc) {
      if (!is_array($l) || count($l) < $min || count($l) > $max) return null;
      $r = []; foreach ($l as $q) { if (!is_array($q) || ($x = $pc($q[0] ?? null)) === null || ($y = $pc($q[1] ?? null)) === null) return null; $r[] = [$x, $y]; }
      return $r;
    };
    $nom = fn($v) => is_scalar($v) ? mb_substr(trim((string)$v), 0, 40) : '';
    $r = self::VIDE; $et = [];
    foreach (array_slice((array)($p['etages'] ?? []), 0, 20) as $e)
      if (($id = self::idPlan($e['id'] ?? null)) && !isset($et[$id]) && ($n = $nom($e['nom'] ?? '')) !== '') { $et[$id] = 1; $r['etages'][] = ['id' => $id, 'nom' => $n]; }
    $etage = fn($v) => ($e = self::idPlan($v)) && isset($et[$e]) ? $e : null; // après les étages : fn capture $et par valeur
    foreach (array_slice((array)($p['marqueurs'] ?? []), 0, 2000, true) as $id => $m) // commande pas encore enregistrée dans le suivi : gardée quand même
      if (is_object(cmd::byId((int)$id)) && is_array($m) && $etage($m['e'] ?? null) && ($x = $pc($m['x'] ?? null)) !== null && ($y = $pc($m['y'] ?? null)) !== null)
        $r['marqueurs'][(int)$id] = ['e' => $m['e'], 'x' => $x, 'y' => $y];
    $vus = [];
    foreach (['pieces' => [3, 200], 'zones' => [3, 200], 'passages' => [2, 2]] as $k => [$min, $max])
      foreach (array_slice((array)($p[$k] ?? []), 0, 300) as $o) {
        if (!is_array($o) || !($id = self::idPlan($o['id'] ?? null)) || isset($vus[$id]) || !$etage($o['e'] ?? null) || !($l = $pts($o['pts'] ?? null, $min, $max))) continue;
        $x = ['id' => $id, 'e' => $o['e'], 'pts' => $l];
        if ($k == 'pieces') { if (($x['nom'] = $nom($o['nom'] ?? '')) === '') continue; }
        if ($k == 'passages') {
          $x['t'] = in_array($o['t'] ?? '', ['porte', 'couloir', 'escalier'], true) ? $o['t'] : 'porte';
          $x['a'] = self::idPlan($o['a'] ?? null); $x['b'] = self::idPlan($o['b'] ?? null); // pièces vérifiées plus bas (définies après)
        }
        $vus[$id] = 1; $r[$k][] = $x;
      }
    $pieces = array_flip(array_column($r['pieces'], 'id'));
    $r['passages'] = array_values(array_filter($r['passages'], fn($x) => $x['a'] && $x['b'] && isset($pieces[$x['a']], $pieces[$x['b']]) && $x['a'] !== $x['b']));
    $avant = self::plan()['etages'];
    self::ecrire(self::planFichier(), json_encode($r));
    foreach ($avant as $e) if (!isset($et[$e['id']]) && is_file($f = self::planImage($e['id']))) unlink($f); // seulement les étages retirés : un plan illisible n'efface rien
    // ponytail: image déposée puis étage jamais enregistré : le fichier reste (quelques centaines de Ko)
    return self::plan();
  }

  // Image d'un étage, réduite et convertie en JPEG par la page (data:image/jpeg;base64,…) : vérifiée avant d'être gardée
  public static function planImageEnregistrer($e, $data) {
    if (!($e = self::idPlan($e))) throw new Exception(__('Étage inconnu', __FILE__));
    if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string)$data, $m) || ($b = base64_decode($m[1], true)) === false
      || strlen($b) > 6000000 || !($i = @getimagesizefromstring($b)) || $i[2] != IMAGETYPE_JPEG || $i[0] > 4000 || $i[1] > 4000)
      throw new Exception(__('Image refusée : JPEG de 4000 × 4000 pixels au plus', __FILE__));
    self::ecrire(self::planImage($e), $b);
  }
  public static function planImageLire($e) {
    return ($e = self::idPlan($e)) && is_file($f = self::planImage($e)) ? 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($f)) : '';
  }

  // Où est chaque commande placée : {cmdId: {zone: exclue ?, piece: id de pièce ou null}}
  public static function lieux(array $plan) {
    $A = 'presencehabituelle_analyse'; $r = [];
    foreach ((array)$plan['marqueurs'] as $id => $m) {
      $z = false; $pc = null;
      foreach ($plan['zones'] as $o) if ($o['e'] == $m['e'] && $A::dans($m['x'], $m['y'], $o['pts'])) $z = true;
      foreach ($plan['pieces'] as $o) if ($o['e'] == $m['e'] && $A::dans($m['x'], $m['y'], $o['pts'])) { $pc = $o; break; } // ponytail: pièces qui se chevauchent : la première dessinée l'emporte
      $r[(int)$id] = ['zone' => $z, 'piece' => $pc];
    }
    return $r;
  }

  // Designs Jeedom : positions des commandes suivies (centre du widget, en % du design) ; un équipement placé donne ses commandes suivies
  public static function designs() {
    return array_map(fn($h) => ['id' => $h->getId(), 'nom' => $h->getName()], planHeader::all());
  }
  public static function designPositions($hid) {
    if (!is_object($h = planHeader::byId((int)$hid))) throw new Exception(__('Design introuvable', __FILE__));
    $W = max(1, (float)$h->getConfiguration('desktopSizeX', 1280)); $H = max(1, (float)$h->getConfiguration('desktopSizeY', 1000));
    $suivi = self::suivi(); $parEq = [];
    foreach (array_keys($suivi) as $id) if (is_object($c = cmd::byId($id))) $parEq[$c->getEqLogic_id()][] = (int)$id;
    $axe = function ($v, $taille, $dim) { // « 12% » ou des pixels ; + la moitié du widget
      if (is_string($v) && substr(trim($v), -1) == '%') return (float)$v;
      return ((float)$v + (is_scalar($dim) ? (float)$dim / 2 : 0)) / $taille * 100; // « 200px » ou « 200 » : 200 ; absente : 0
    };
    $r = [];
    foreach (plan::byPlanHeaderId($h->getId()) as $q) {
      $ids = $q->getLink_type() == 'cmd' ? (isset($suivi[(int)$q->getLink_id()]) ? [(int)$q->getLink_id()] : []) : ($q->getLink_type() == 'eqLogic' ? $parEq[(int)$q->getLink_id()] ?? [] : []);
      $x = $axe($q->getPosition('left', 0), $W, $q->getDisplay('width')); $y = $axe($q->getPosition('top', 0), $H, $q->getDisplay('height'));
      if ($x < 0 || $x > 100 || $y < 0 || $y > 100) continue; // hors du design
      foreach ($ids as $id) $r[$id] = ['x' => round($x, 2), 'y' => round($y, 2)];
    }
    return (object)$r;
  }

  // Habitudes calculées : fichier sous data/ (refusé au web par data/.htaccess), trop gros pour une clé de configuration
  public static function fichier() { return __DIR__ . '/../../data/habitudes.json'; }
  public static function habitudes() {
    $h = is_file($f = self::fichier()) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($h) ? $h : self::analyser();
  }

  public static function analyser() {
    $A = 'presencehabituelle_analyse'; $fin = time(); $excl = self::exclusions();
    $max = 3600 * (($h = (float)config::byKey('session_max', __CLASS__, 18)) > 0 ? max(1, $h) : 18); // 0 ou illisible : 18 h, comme jours()
    $groupes = []; $volets = []; $plan = self::plan(); $lieux = self::lieux($plan); $piece = [];
    foreach (self::suivi() as $id => $s) {
      $l = $lieux[$id] ?? ['zone' => false, 'piece' => null];
      if ($l['zone']) continue; // zone à exclure du plan : ni analysée ni simulée
      if ($l['piece']) $piece[$id] = $l['piece']['id'];
      if ($s['t'] == 'volet') { $volets[$id] = $s; continue; }
      if ($s['g'] === '' && $l['piece']) $s['g'] = "\1" . $l['piece']['nom']; // pièce du plan = groupe, sauf groupe saisi ; \1 : jamais confondue avec un groupe saisi de même nom
      $groupes[$s['g'] !== '' ? $s['g'] : "\0" . $id][] = (int)$id; // \0 : une lampe seule, jamais confondue avec un groupe saisi « #12 » ; un groupe « 12 » devient la clé entière 12 : (string) plus bas
    }
    $res = ['date' => date('Y-m-d H:i:s'), 'groupes' => [], 'volets' => [], 'passages' => []]; $parPiece = [];
    foreach ($groupes as $g => $ids) {
      $iv = []; $debut = null; $exG = $excl; $noms = [];
      foreach ($ids as $id) {
        if (!$ev = self::evs($id)) continue;
        $debut = min($debut ?? PHP_INT_MAX, $ev[0][0]); // ponytail: avant sa 1re ligne, une lampe ajoutée plus tard compte éteinte ; le groupe garde l'historique des autres
        [$iv[$id], $x] = $A::longues($A::intervalles($ev, $fin), $max);
        if (isset($piece[$id])) $parPiece[$piece[$id]][$id] = $iv[$id];
        foreach ($x as $p) $exG[] = $p; // extinction manquée : jours touchés non comptés pour tout le groupe
        $noms[$id] = self::nom($id);
      }
      $dbl = array_count_values($noms); // deux lampes de même nom : l'id les distingue
      foreach ($noms as $id => $n) if ($dbl[$n] > 1) $noms[$id] = $n . ' #' . $id;
      $g = (string)$g; $seule = $g[0] === "\0" ? (int)substr($g, 1) : 0; $nom = $seule ? self::nom($seule) : ltrim($g, "\1");
      if (!$iv) { $res['groupes'][] = ['nom' => $nom, 'id' => $seule, 'ids' => $ids, 'jours' => 0]; continue; }
      $parNom = []; foreach ($iv as $id => $i) $parNom[$noms[$id]] = $i;
      $j = $A::jours($debut, $fin, $exG); $se = $A::sessions($parNom); $p = $A::profil($se, $j);
      $res['groupes'][] = ['nom' => $nom, 'id' => $seule, 'ids' => $ids, 'lampes' => array_values($noms), 'jours' => count($j), 'nS' => $p['nS'], 'nW' => $p['nW'],
        'S' => $p['S'], 'W' => $p['W'], 'cS' => $p['cS'], 'cW' => $p['cW'], 'lS' => $A::lisser($p['S']), 'lW' => $A::lisser($p['W']),
        'ex' => $p['ex'], 'durees' => $A::durees($se, $j), 'scenes' => count($iv) > 1 ? $A::scenes($se, $j) : [], 'confirmerS' => $p['nS'] < self::FIABLE, 'confirmerW' => $p['nW'] < self::FIABLE];
    }
    foreach ($volets as $id => $s) {
      $ev = self::evs($id); $nom = self::nom($id);
      if (!$ev) { $res['volets'][] = ['nom' => $nom, 'id' => $id, 'jours' => 0]; continue; }
      $j = $A::jours($ev[0][0], $fin, $excl);
      $v = $A::volet($ev, $j, $fin, $excl);
      foreach ($v as $m => $c) foreach ($c as $k => $x) { // par case : 60 jours de semaine ne confirment pas 4 week-ends, 2 ouvertures sur 20 jours non plus
        $v[$m][$k]['confirmer'] = ($x['n'] ?: $x['jours']) < self::FIABLE; // heures : sur les valeurs relevées (aucune : sur les jours)
        if ($m == 'soir') $v[$m][$k]['confirmerF'] = $x['nuits'] < self::FIABLE; // % fermé : sur les nuits observées
      }
      $res['volets'][] = ['nom' => $nom, 'id' => $id, 'groupe' => $s['g'], 'jours' => count($j)] + $v;
    }
    $noms = array_column($plan['pieces'], 'nom', 'id'); // enchaînements par les passages du plan (lumières seulement)
    $se = array_map(fn($p) => $A::sessions($p), $parPiece); // allumée tant qu'une de ses lampes l'est
    $pa = array_map(fn($p) => [$p['a'], $p['b']], $plan['passages']);
    foreach ($A::enchainements($se, $pa, $excl) as $i => [$a, $b, $ab, $ba])
      $res['passages'][] = ['id' => $plan['passages'][$i]['id'], 'a' => $noms[$a], 'b' => $noms[$b], 't' => $plan['passages'][$i]['t'], 'ab' => $ab, 'ba' => $ba];
    foreach (['groupes', 'volets'] as $t) { // même nom dans deux pièces : la pièce les départage (un groupe saisi garde son nom)
      $n = array_count_values(array_column($res[$t], 'nom'));
      foreach ($res[$t] as $i => $x) if ($n[$x['nom']] > 1 && $x['id']) $res[$t][$i]['nom'] = self::nom($x['id'], true);
    }
    file_put_contents(self::fichier(), json_encode($res));
    return $res;
  }

  /* Purge : au-delà de « conserver » jours, seul le dernier état connu reste, ramené à la limite
     (sinon un « On » purgé laisserait un « Off » seul : absence ou allumage perdus). */
  public static function purger($jours) {
    $lim = time() - 86400 * $jours;
    foreach (self::ids() as $id) {
      $d = DB::Prepare('SELECT id FROM ' . self::TABLE . ' WHERE cmd_id = ? AND ts < ? ORDER BY ts DESC, id DESC LIMIT 1', [$id, $lim], DB::FETCH_TYPE_ROW);
      if (!is_array($d)) continue;
      DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE cmd_id = ? AND ts < ? AND id <> ?', [$id, $lim, $d['id']], DB::FETCH_TYPE_ROW);
      DB::Prepare('UPDATE ' . self::TABLE . ' SET ts = ? WHERE id = ?', [$lim, $d['id']], DB::FETCH_TYPE_ROW);
    }
    $p = array_values(array_filter(self::arrets(), fn($a) => $a[1] >= $lim));
    if ($p != self::arrets()) config::save('arrets', json_encode($p), __CLASS__);
  }

  // Jours en réglage : vide, nul ou illisible = 90 (« trois mois » ne doit pas purger à 7 jours)
  public static function jours($cle) {
    return ($j = (int)config::byKey($cle, __CLASS__, 90)) > 0 ? $j : 90;
  }

  /* Box ou Jeedom arrêté : rien n'est relevé, les lampes paraîtraient éteintes. Un trou de plus de 3 h entre deux passages est exclu.
     ponytail: 3 h = deux cronHourly manqués ; un arrêt plus court n'est pas vu */
  public static function cronHourly() {
    try {
      $vu = (int)config::byKey('vu', __CLASS__, 0);
      if ($vu && time() - $vu > 3 * 3600) self::exclure($vu, time());
      config::save('vu', time(), __CLASS__);
    } catch (Throwable $e) { log::add(__CLASS__, 'error', 'cronHourly : ' . $e->getMessage()); }
  }

  public static function cronDaily() {
    try {
      self::purger(max(7, self::jours('conserver')));
      self::analyser();
    } catch (Throwable $e) { log::add(__CLASS__, 'error', 'cronDaily : ' . $e->getMessage()); }
  }
}

class presencehabituelleCmd extends cmd {
  public function execute($_options = []) {
  }
}

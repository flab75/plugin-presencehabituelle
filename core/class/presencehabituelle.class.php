<?php
/* Présence habituelle : apprend les habitudes (lumières, volets) à partir des changements d'état, sans rien envoyer hors de la box.
   0.1 : apprentissage seul (aucune action sur les équipements). */
require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/presencehabituelle_analyse.class.php';

class presencehabituelle extends eqLogic {
  const TABLE = 'presencehabituelle_event';
  const FIABLE = 10; // jours comptés sous lesquels une habitude est « à confirmer »
  // préconisation par type générique : [type, cochée d'office]
  const TYPES = ['LIGHT_STATE' => ['lumiere', 1], 'LIGHT_STATE_BOOL' => ['lumiere', 1], 'FLAP_STATE' => ['volet', 1], 'FLAP_BSO_STATE' => ['volet', 1],
    'ENERGY_STATE' => ['prise', 0], 'SWITCH_STATE' => ['prise', 0], 'MEDIA_STATUS' => ['media', 0], 'MEDIA_STATE' => ['media', 0]];

  public static function creerTable() {
    DB::Prepare('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, cmd_id INT NOT NULL,
      ts INT UNSIGNED NOT NULL, valeur DOUBLE NOT NULL, src TINYINT NOT NULL DEFAULT 0, KEY cmd_ts (cmd_id, ts)) ENGINE=InnoDB', [], DB::FETCH_TYPE_ROW);
  }

  public static function supprimerTout() {
    if (is_object($l = listener::byClassAndFunction(__CLASS__, 'evenement'))) $l->remove();
    DB::Prepare('DROP TABLE IF EXISTS ' . self::TABLE, [], DB::FETCH_TYPE_ROW);
  }

  // {cmdId: {g: groupe, t: type}}
  public static function suivi() {
    $s = json_decode((string)config::byKey('suivi', __CLASS__, '{}'), true);
    return is_array($s) ? $s : [];
  }

  // Valeur d'état → nombre (> 0 = allumé / ouvert / actif)
  public static function valeur($v) {
    return is_numeric($v) ? (float)$v : (presencehabituelle_analyse::actif($v) ? 1.0 : 0.0);
  }

  // Listener synchrone : une ligne seulement si la valeur change
  public static function evenement($_options) {
    $id = (int)($_options['event_id'] ?? 0); if (!$id) return;
    $v = self::valeur($_options['value'] ?? 0);
    $ts = strtotime((string)($_options['datetime'] ?? '')) ?: time();
    $d = DB::Prepare('SELECT valeur FROM ' . self::TABLE . ' WHERE cmd_id = ? ORDER BY ts DESC, id DESC LIMIT 1', [$id], DB::FETCH_TYPE_ROW);
    if (is_array($d) && (float)$d['valeur'] == $v) return;
    DB::Prepare('INSERT INTO ' . self::TABLE . ' (cmd_id, ts, valeur, src) VALUES (?, ?, ?, 0)', [$id, $ts, $v], DB::FETCH_TYPE_ROW);
  }

  public static function majListener() {
    $ids = array_keys(self::suivi());
    if ($x = (int)config::byKey('exclure_cmd', __CLASS__, 0)) $ids[] = $x;
    $l = listener::byClassAndFunction(__CLASS__, 'evenement');
    if (!$ids) { if (is_object($l)) $l->remove(); return; }
    if (!is_object($l)) { $l = new listener(); $l->setClass(__CLASS__); $l->setFunction('evenement'); }
    $l->setOption('background', false); // ponytail: synchrone = 1 SELECT + 1 INSERT au plus par changement ; passer en background si une box lente le sent
    $l->emptyEvent();
    foreach (array_unique($ids) as $id) $l->addEvent($id);
    $l->save();
  }

  /* Amorçage d'une commande par son historique Jeedom : changements seulement.
     Exact (src 1) : binaire (jamais lissé), historizeMode « none », ou point récent pas encore archivé ; sinon moyenne horaire (src 2). */
  public static function amorcer($cmd, $jours) {
    DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE cmd_id = ? AND src > 0', [$cmd->getId()], DB::FETCH_TYPE_ROW);
    if (!$cmd->getIsHistorized() || $jours <= 0) return 0;
    $brut = $cmd->getSubType() == 'binary' || $cmd->getConfiguration('historizeMode', 'avg') == 'none';
    $recent = time() - 86400 * (float)config::byKey('historyArchiveTime', 'core', 2);
    $prem = DB::Prepare('SELECT MIN(ts) t FROM ' . self::TABLE . ' WHERE cmd_id = ?', [$cmd->getId()], DB::FETCH_TYPE_ROW);
    $fin = ($prem['t'] ?? null) ? (int)$prem['t'] : null; // les lignes du listener priment sur l'historique
    $n = 0; $der = null;
    foreach (history::all($cmd->getId(), date('Y-m-d H:i:s', time() - 86400 * $jours), $fin ? date('Y-m-d H:i:s', $fin - 1) : null) as $h) {
      $v = self::valeur($h->getValue()); $t = strtotime($h->getDatetime());
      if ($v === $der) continue;
      $der = $v; $n++;
      DB::Prepare('INSERT INTO ' . self::TABLE . ' (cmd_id, ts, valeur, src) VALUES (?, ?, ?, ?)', [$cmd->getId(), $t, $v, ($brut || $t >= $recent) ? 1 : 2], DB::FETCH_TYPE_ROW);
    }
    return $n;
  }

  // Commandes info proposées, avec la préconisation
  public static function candidats() {
    $r = [];
    foreach (cmd::byTypeSubType('info') as $c) {
      if (!is_object($eq = $c->getEqLogic()) || $eq->getEqType_name() == __CLASS__) continue;
      [$t, $coche] = self::TYPES[$c->getGeneric_type()] ?? ['autre', 0];
      $r[] = ['id' => $c->getId(), 'nom' => $c->getName(), 'eq' => $eq->getName(), 'objet' => is_object($o = $eq->getObject()) ? $o->getName() : '',
        'type' => $t, 'coche' => $coche, 'gen' => (string)$c->getGeneric_type(), 'hist' => (int)$c->getIsHistorized(), 'actif' => (int)$eq->getIsEnable()];
    }
    return $r;
  }

  /* Enregistre le choix de la page. $suivi = {cmdId: {g, t}} ; renvoie le nombre de changements repris de l'historique. */
  public static function enregistrer(array $suivi, $exclure, $exclusions) {
    $propre = []; $avant = self::suivi(); $n = 0; $avantX = (int)config::byKey('exclure_cmd', __CLASS__, 0);
    foreach ($suivi as $id => $s) {
      if (!is_object($c = cmd::byId((int)$id)) || $c->getType() != 'info') continue;
      $propre[(int)$id] = ['g' => mb_substr(trim((string)($s['g'] ?? '')), 0, 60), 't' => in_array($s['t'] ?? '', ['lumiere', 'volet', 'prise', 'media', 'autre'], true) ? $s['t'] : 'autre'];
    }
    $exclure = (int)$exclure;
    if ($exclure && (!is_object($c = cmd::byId($exclure)) || $c->getType() != 'info')) throw new Exception(__('Commande d\'exclusion introuvable', __FILE__));
    $gardes = array_keys($propre); if ($exclure) $gardes[] = $exclure;
    foreach (array_keys($avant + [$avantX => 1]) as $id) // retirée = ses évènements partent
      if ($id && !in_array((int)$id, $gardes, true)) DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE cmd_id = ?', [(int)$id], DB::FETCH_TYPE_ROW);
    $jours = (int)config::byKey('amorcer', __CLASS__, 90);
    foreach ($gardes as $id) if (!isset($avant[$id]) && $id != $avantX) $n += self::amorcer(cmd::byId($id), $jours);
    config::save('suivi', json_encode($propre), __CLASS__);
    config::save('exclure_cmd', $exclure ?: '', __CLASS__);
    config::save('exclusions', (string)$exclusions, __CLASS__);
    self::majListener();
    return $n;
  }

  private static function evs($id) {
    return array_map(fn($d) => [(int)$d['ts'], (float)$d['valeur'], $d['src'] < 2],
      DB::Prepare('SELECT ts, valeur, src FROM ' . self::TABLE . ' WHERE cmd_id = ? ORDER BY ts, id', [$id], DB::FETCH_TYPE_ALL) ?: []);
  }

  private static function nom($id) {
    return is_object($c = cmd::byId($id)) && is_object($eq = $c->getEqLogic()) ? $eq->getName() . ($c->getName() != 'Etat' && $c->getName() != 'État' ? ' ' . $c->getName() : '') : '#' . $id;
  }

  // Périodes exclues : commande d'exclusion active + périodes saisies
  public static function exclusions() {
    $A = 'presencehabituelle_analyse';
    $p = $A::periodes(config::byKey('exclusions', __CLASS__, ''));
    if ($x = (int)config::byKey('exclure_cmd', __CLASS__, 0))
      foreach ($A::intervalles(self::evs($x), time()) as $i) $p[] = [$i[0], $i[1]];
    return $p;
  }

  public static function analyser() {
    $A = 'presencehabituelle_analyse'; $fin = time(); $excl = self::exclusions();
    $groupes = []; $volets = [];
    foreach (self::suivi() as $id => $s) {
      if ($s['t'] == 'volet') { $volets[$id] = $s; continue; }
      $groupes[$s['g'] !== '' ? $s['g'] : '#' . $id][$id] = true;
    }
    $res = ['date' => date('Y-m-d H:i:s'), 'groupes' => [], 'volets' => []];
    foreach ($groupes as $g => $ids) {
      $iv = []; $debut = 0;
      foreach (array_keys($ids) as $id) {
        if (!$ev = self::evs($id)) continue;
        $debut = max($debut, $ev[0][0]); $iv[self::nom($id)] = $A::intervalles($ev, $fin);
      }
      $nom = $g[0] == '#' ? self::nom((int)substr($g, 1)) : $g;
      if (!$iv) { $res['groupes'][] = ['nom' => $nom, 'jours' => 0]; continue; }
      $j = $A::jours($debut, $fin, $excl); $se = $A::sessions($iv); $p = $A::profil($se, $j);
      $res['groupes'][] = ['nom' => $nom, 'lampes' => array_keys($iv), 'jours' => count($j), 'nS' => $p['nS'], 'nW' => $p['nW'],
        'S' => $p['S'], 'W' => $p['W'], 'lS' => $A::lisser($p['S']), 'lW' => $A::lisser($p['W']),
        'durees' => $A::durees($se, $j), 'scenes' => count($iv) > 1 ? $A::scenes($se, $j) : [], 'confirmer' => count($j) < self::FIABLE];
    }
    foreach ($volets as $id => $s) {
      $ev = self::evs($id); $nom = self::nom($id);
      if (!$ev) { $res['volets'][] = ['nom' => $nom, 'jours' => 0]; continue; }
      $j = $A::jours($ev[0][0], $fin, $excl); // ponytail: positions moyennées (src 2) gardées : heures à l'heure près ; les séparer si un volet n'a qu'un historique lissé
      $res['volets'][] = ['nom' => $nom, 'groupe' => $s['g'], 'jours' => count($j), 'confirmer' => count($j) < self::FIABLE] + $A::volet($ev, $j);
    }
    config::save('habitudes', json_encode($res), __CLASS__);
    return $res;
  }

  public static function cronDaily() {
    try {
      $j = max(7, (int)config::byKey('conserver', __CLASS__, 90));
      DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE ts < ?', [time() - 86400 * $j], DB::FETCH_TYPE_ROW);
      self::analyser();
    } catch (Throwable $e) { log::add(__CLASS__, 'error', 'cronDaily : ' . $e->getMessage()); }
  }
}

class presencehabituelleCmd extends cmd {
  public function execute($_options = []) {
  }
}

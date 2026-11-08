<?php
/* Présence habituelle : tire des historiques (lumières, volets) les tendances, les exceptions et ce qui n'arrive jamais, sans rien envoyer hors de la box.
   0.13 : analyse, plan de la maison par étage, découverte des commandes à suivre, Guide, widget ; simulation (à blanc par défaut : rien n'est envoyé). */
require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/presencehabituelle_analyse.class.php';
require_once __DIR__ . '/presencehabituelle_moteur.class.php';

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
    if (!self::suivi() && !(int)config::byKey('exclure_cmd', __CLASS__, 0) && !self::observes()) self::effacer();
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
    if (self::historiserAvant()) log::add(__CLASS__, 'warning', 'Plugin désactivé : l\'historique activé par le plugin reste activé ; « Remettre l\'historique comme avant » (Guide) le rétablit une fois le plugin réactivé');
  }

  /* Périodes exclues gardées [[début, fin]] (config::byKey décode déjà le JSON) :
     'arrets' = plugin désactivé, box arrêtée, absences d'une ancienne commande d'absence ;
     'simulation' = périodes où le plugin lui-même simulait (ponytail: rempli par la future simulation, via exclure($a, $b, 'simulation')) */
  public static function arrets($cle = 'arrets') {
    $p = config::byKey($cle, __CLASS__, []);
    if (is_string($p)) $p = json_decode($p, true);
    return is_array($p) ? $p : [];
  }

  public static function exclure($a, $b, $cle = 'arrets') {
    $p = presencehabituelle_analyse::fusion(array_merge(self::arrets($cle), [[(int)$a, (int)$b]])); // chevauchements fusionnés : la liste ne grossit pas à chaque redémarrage
    config::save($cle, json_encode($p), __CLASS__);
  }

  // Bouton « Tout effacer » : changements d'état et habitudes calculées (le choix des commandes reste)
  public static function effacer() {
    DB::Prepare('DELETE FROM ' . self::TABLE, [], DB::FETCH_TYPE_ROW);
    if (is_file($f = self::fichier())) unlink($f);
    if (is_file($f = self::journalFichier())) unlink($f); // journal de la simulation : heures de la maison aussi
  }

  // {cmdId: {g: groupe, t: type}} ; config::byKey décode déjà le JSON (piège JD-54)
  public static function suivi() {
    $s = config::byKey('suivi', __CLASS__, []);
    if (is_string($s)) $s = json_decode($s, true);
    return is_array($s) ? $s : [];
  }

  // Commandes lues : suivies + commande d'absence + commandes observées pour la découverte
  private static function ids($observees = true) {
    $ids = array_map('intval', array_keys(self::suivi()));
    if ($x = (int)config::byKey('exclure_cmd', __CLASS__, 0)) $ids[] = $x;
    if ($observees) $ids = array_merge($ids, self::observes());
    return array_values(array_unique($ids));
  }

  /* Observation (découverte) : commandes sans historique relevées quelques jours, pour les comparer aux autres.
     config 'observation' = {fin, ids} ; → ids observés tant que la fin n'est pas passée */
  public static function detecteursLus($l = null) { // avec le nom lisible de la commande (h), pour la page
    return array_map(fn($d) => ($d['cmd'] && !is_object(cmd::byId($d['cmd'])) ? ['cmd' => 0, 'h' => '', 'perdu' => 1] : ['h' => $d['cmd'] ? cmd::cmdToHumanReadable('#' . $d['cmd'] . '#') : '']) + $d, // perdu : commande supprimée de Jeedom
      presencehabituelle_analyse::detecteurs($l ?? self::cfg('detecteurs')));
  }
  public static function cfg($cle) { // config JSON (réponses, règles du mode guidé) ; config::byKey décode déjà le JSON
    $v = config::byKey($cle, __CLASS__, []);
    if (is_string($v)) $v = json_decode($v, true);
    return is_array($v) ? $v : [];
  }
  public static function donnees() { // {cmdId: jours depuis le premier relevé} ; ponytail: étendue, pas les jours comptés (arrêts, absences)
    $r = [];
    foreach (DB::Prepare('SELECT cmd_id, MIN(ts) AS d FROM ' . self::TABLE . ' GROUP BY cmd_id', [], DB::FETCH_TYPE_ALL) ?: [] as $l)
      $r[(int)$l['cmd_id']] = round((time() - (int)$l['d']) / 86400, 1);
    return $r;
  }
  /* Volets suivis restés sur une seule valeur (ou sans aucune : « stop », « my » seulement) pendant 3 jours hors absences :
     sans retour d'état (Somfy RTS, radio sans retour) ? Départ : premier relevé, sinon date où il a été suivi (d). */
  /* Historique Jeedom des 90 derniers jours : {cmdId: {n: points, j: jours depuis le premier}} (lecture seule ; ponytail: deux GROUP BY sur les ids demandés) */
  public static function historiques(array $ids) {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0))), 0, 200); // la page les demande par paquets de 200
    if (!$ids) return [];
    $r = []; $d = date('Y-m-d H:i:s', time() - 90 * 86400);
    foreach (['history', 'historyArch'] as $t)
      foreach (DB::Prepare('SELECT cmd_id, COUNT(*) AS n, MIN(datetime) AS d FROM ' . $t . ' WHERE cmd_id IN (' . implode(',', $ids) . ') AND datetime >= ? GROUP BY cmd_id', [$d], DB::FETCH_TYPE_ALL) ?: [] as $l) {
        $id = (int)$l['cmd_id']; $j = round((time() - strtotime($l['d'])) / 86400, 1);
        $r[$id] = ['n' => ($r[$id]['n'] ?? 0) + (int)$l['n'], 'j' => max($r[$id]['j'] ?? 0, $j)];
      }
    return $r;
  }
  public static function scenarios(array $ids) { // {cmdId: [[scénario, nom, actions notées dans son journal, 1re, dernière, actif]]} : scénarios qui lancent une action de son équipement (lecture seule)
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0))), 0, 200); // la page les demande par paquets de 200
    $par = []; $sc = []; $acts = []; $connus = [];
    foreach ($ids as $id) {
      $c = cmd::byId($id); if (!is_object($c)) continue;
      $eq = (int)$c->getEqLogic_id();
      if (!isset($acts[$eq])) { // ponytail: un scénario qui appelle la commande dans un bloc code (cmd::byId) n'est pas vu
        $acts[$eq] = [];
        foreach (cmd::byEqLogicId($eq, 'action') ?: [] as $a)
          foreach (scenarioExpression::searchExpression('#' . $a->getId() . '#', '#' . $a->getId() . '#', false) ?: [] as $e) {
            try { $x = $e->getSubElement(); $x = is_object($x) ? $x->getElement() : null; $x = is_object($x) ? $x->getScenario() : null; }
            catch (Throwable $t) { continue; } // bloc orphelin d'un scénario abîmé : passé
            if (!is_object($x)) continue;
            $acts[$eq][] = [$x->getId(), $a->getHumanName(), (int)$a->getValue()]; $sc[$x->getId()] = $x;
          }
      }
      $l = array_filter($acts[$eq], fn($a) => $a[2] == $id); // équipement à plusieurs sorties : les actions liées à cette info, sinon toutes
      foreach ($l ?: $acts[$eq] as [$sid, $h]) { $par[$id][$sid][$h] = 1; $connus[$sid][$h] = 1; }
    }
    $journal = [];
    foreach ($connus as $sid => $noms) { // « [date][SCENARIO] Exécution de la commande [Pièce][Équipement][Commande] … », libellé dans la langue de la box
      $journal[$sid] = [];
      foreach (@file(log::getPathToLog('scenarioLog/scenario' . (int)$sid . '.log'), FILE_IGNORE_NEW_LINES) ?: [] as $ligne) {
        if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\]\[SCENARIO\] [^\[]*(\[.*)$/u', $ligne, $m)) continue;
        foreach ($noms as $n => $_) if (strpos($m[2], $n) === 0) {
          $t = strtotime($m[1]); $j = &$journal[$sid][$n]; $j = [($j[0] ?? 0) + 1, min($j[1] ?? $t, $t), max($j[2] ?? $t, $t)]; unset($j); break;
        }
      }
    }
    $r = [];
    foreach ($par as $id => $l)
      foreach ($l as $sid => $noms) {
        $n = 0; $d0 = 0; $d1 = 0;
        foreach (array_keys($noms) as $h) if ($j = $journal[$sid][$h] ?? null) { $n += $j[0]; $d0 = $d0 ? min($d0, $j[1]) : $j[1]; $d1 = max($d1, $j[2]); }
        $r[$id][] = [$sid, $sc[$sid]->getName(), $n, $d0, $d1, (int)$sc[$sid]->getIsActive()];
      }
    return $r;
  }
  public static function figes() {
    $v = array_filter(self::suivi(), fn($s) => ($s['t'] ?? '') == 'volet');
    if (!$v) return [];
    $l = DB::Prepare('SELECT cmd_id, MIN(ts) AS d, COUNT(DISTINCT valeur) AS n FROM ' . self::TABLE . ' WHERE cmd_id IN (' . implode(',', array_map('intval', array_keys($v))) . ') GROUP BY cmd_id', [], DB::FETCH_TYPE_ALL) ?: [];
    $l = array_column($l, null, 'cmd_id'); $excl = self::exclusions(); $r = [];
    foreach ($v as $id => $s) {
      if ((int)($l[$id]['n'] ?? 0) >= 2 || !($a = (int)($l[$id]['d'] ?? 0) ?: (int)($s['d'] ?? 0))) continue;
      $duree = time() - $a; foreach ($excl as [$x, $y]) $duree -= max(0, min($y, time()) - max($x, $a));
      if ($duree > 3 * 86400) $r[] = (int)$id;
    }
    return $r;
  }
  public static function observes() {
    $o = config::byKey('observation', __CLASS__, []);
    if (is_string($o)) $o = json_decode($o, true);
    return is_array($o) && (int)($o['fin'] ?? 0) > time() ? array_map('intval', (array)($o['ids'] ?? [])) : [];
  }

  public static function observees() { // observées, y compris celles dont l'observation est finie mais les relevés encore gardés
    $o = config::byKey('observation', __CLASS__, []);
    if (is_string($o)) $o = json_decode($o, true);
    return is_array($o) ? array_map('intval', (array)($o['ids'] ?? [])) : [];
  }

  public static function finObservee() {
    $o = config::byKey('observation', __CLASS__, []);
    if (is_string($o)) $o = json_decode($o, true);
    return is_array($o) ? (int)($o['fin'] ?? 0) : 0;
  }

  public static function observer(array $ids, $jours = 3) {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => is_object($c = cmd::byId($id)) && $c->getType() == 'info'));
    if (!$ids) throw new Exception(__('Aucune commande à observer', __FILE__));
    $o = config::byKey('observation', __CLASS__, []); // observation précédente pas encore nettoyée : ses relevés restent suivis, jamais orphelins
    if (is_string($o)) $o = json_decode($o, true);
    if (is_array($o)) $ids = array_values(array_unique(array_merge($ids, array_map('intval', (array)($o['ids'] ?? [])))));
    $ids = array_slice($ids, 0, 500);
    config::save('observation', json_encode(['fin' => $fin = time() + 86400 * $jours, 'ids' => $ids]), __CLASS__);
    foreach ($ids as $id) self::noter($id);
    self::majListener();
    return $fin;
  }

  /* Fin d'observation : relevés gardés GARDE jours après la fin (le temps de relancer la découverte),
     puis ceux des commandes ni suivies ni d'absence partent */
  const GARDE = 7;
  public static function finObservation() {
    $o = config::byKey('observation', __CLASS__, []);
    if (is_string($o)) $o = json_decode($o, true);
    if (!is_array($o) || !$o || (int)($o['fin'] ?? 0) + 86400 * self::GARDE > time()) return;
    config::remove('observation', __CLASS__);
    $gardes = self::ids(false);
    foreach ((array)($o['ids'] ?? []) as $id) if (!in_array((int)$id, $gardes, true)) DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE cmd_id = ?', [(int)$id], DB::FETCH_TYPE_ROW);
    self::majListener();
  }

  // Valeur d'état → nombre (> 0 = allumé / ouvert / actif) ; null : texte qui ne dit rien (stop, my…), ignoré
  public static function valeur($v) {
    $A = 'presencehabituelle_analyse';
    return is_numeric($v) ? (float)$v : ($A::neutre($v) ? null : ($A::actif($v) ? 1.0 : 0.0));
  }

  // Ajoute un changement (src 0) seulement si la valeur diffère de la dernière connue
  private static function ajouter($id, $v, $ts) {
    if ($v === null) return;
    $d = DB::Prepare('SELECT valeur FROM ' . self::TABLE . ' WHERE cmd_id = ? ORDER BY ts DESC, id DESC LIMIT 1', [$id], DB::FETCH_TYPE_ROW);
    if (is_array($d) && ((float)$d['valeur'] == $v || ((self::suivi()[$id]['t'] ?? '') != 'volet' && ((float)$d['valeur'] > 0) == ($v > 0)))) return; // hors volet, seul allumé / éteint compte : un variateur ne remplit pas la table
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
    if (is_object($c = cmd::byId($id)) && $c->getType() == 'info') self::ajouter($id, self::valeur($c->execCmd()), time()); // une info seulement : execCmd d'une action agirait
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
    return $cmd->getIsHistorized() && $cmd->getSubType() == 'numeric' && $cmd->getConfiguration('historizeMode', 'avg') != 'none'; // un texte n'est jamais moyenné
  }

  /* Reprise d'une commande par son historique Jeedom : changements exacts seulement (binaire, mode « Aucun », ou point pas encore archivé).
     Une moyenne horaire n'est ni un allumage ni une fermeture : elle est ignorée. À appeler AVANT noter() : s'arrête à la 1re ligne connue.
     Historique exact : l'état au début de la fenêtre est repris aussi (absence ou allumage commencés avant). */
  public static function amorcer($cmd, $jours) {
    DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE cmd_id = ? AND src > 0', [$cmd->getId()], DB::FETCH_TYPE_ROW);
    if (!$cmd->getIsHistorized() || $jours <= 0) return 0;
    $prem = DB::Prepare('SELECT MIN(ts) t FROM ' . self::TABLE . ' WHERE cmd_id = ?', [$cmd->getId()], DB::FETCH_TYPE_ROW);
    $fin = ($prem['t'] ?? null) ? (int)$prem['t'] : null; // les lignes déjà notées priment sur l'historique
    $id = $cmd->getId();
    $lignes = array_map(fn($e) => [$id, $e[0], $e[1]], self::historique($cmd, time() - 86400 * $jours, $fin));
    foreach (array_chunk($lignes, 500) as $paquet) // une requête par 500 lignes
      DB::Prepare('INSERT INTO ' . self::TABLE . ' (cmd_id, ts, valeur, src) VALUES ' . implode(',', array_fill(0, count($paquet), '(?, ?, ?, 1)')),
        array_merge(...$paquet), DB::FETCH_TYPE_ROW);
    return count($lignes);
  }

  /* Historique Jeedom exact de $debut à $jusqua (exclu, facultatif) → [[ts, valeur, true]], l'état au début compris (sauf lissé).
     Lissé : points pas encore archivés seulement. */
  private static function historique($cmd, $debut, $jusqua = null) {
    $lisse = self::lisse($cmd);
    if ($lisse) $debut = max($debut, time() - 3600 * max(0, (float)config::byKey('historyArchiveTime', 'core', 2))); // en heures (history::archive)
    $r = []; $der = null;
    if (!$lisse && (!$jusqua || $jusqua > $debut)) { // dernier point avant la fenêtre, dans history puis historyArch
      $d = date('Y-m-d H:i:s', $debut); $av = null;
      foreach (['history', 'historyArch'] as $t)
        if (!$av && is_array($x = DB::Prepare('SELECT value FROM ' . $t . ' WHERE cmd_id = ? AND datetime < ? ORDER BY datetime DESC LIMIT 1', [$cmd->getId(), $d], DB::FETCH_TYPE_ROW))) $av = $x;
      if ($av && ($der = self::valeur($av['value'])) !== null) $r[] = [$debut, $der, true];
    }
    foreach (history::all($cmd->getId(), date('Y-m-d H:i:s', $debut), $jusqua ? date('Y-m-d H:i:s', $jusqua - 1) : null) as $h) {
      $v = self::valeur($h->getValue());
      if ($v === null || $v === $der) continue;
      $der = $v; $r[] = [strtotime($h->getDatetime()), $v, true];
    }
    return $r;
  }

  // Commandes info proposées, avec la préconisation (une seule commande d'état cochée par équipement)
  public static function candidats() {
    $eqs = []; foreach (eqLogic::all() as $e) $eqs[$e->getId()] = $e;
    $objs = []; foreach (jeeObject::all() as $o) $objs[$o->getId()] = $o->getName();
    $r = []; $choisi = []; $suivi = self::suivi(); $src = []; $gens = [];
    $source = function ($t) use (&$src) { // nom du plugin de l'équipement (Virtuel, Zigbee2MQTT…), lu une fois par type
      if (!isset($src[$t])) { try { $src[$t] = $t == 'virtual' ? __('Virtuel', __FILE__) : plugin::byId($t)->getName(); } catch (Throwable $e) { $src[$t] = $t; } }
      return $src[$t];
    };
    foreach (cmd::byTypeSubType('info') as $c) {
      if (!($eq = $eqs[$c->getEqLogic_id()] ?? null) || $eq->getEqType_name() == __CLASS__) continue;
      [$t, $coche] = self::TYPES[$c->getGeneric_type()] ?? ['autre', 0];
      $t = $suivi[$c->getId()]['t'] ?? $t; // type choisi sur la page (ex. lumière sans type générique) : gardé
      // préférée : binaire ou LIGHT_STATE_BOOL (une luminosité peut ne pas retomber à 0 à l'extinction)
      $rang = $coche ? ($c->getSubType() == 'binary' || $c->getGeneric_type() == 'LIGHT_STATE_BOOL' ? 2 : 1) : 0;
      $r[] = ['id' => $c->getId(), 'nom' => $c->getName(), 'eq' => $eq->getName(), 'objet' => $objs[$eq->getObject_id()] ?? '',
        'type' => $t, 'tg' => self::TYPES[$c->getGeneric_type()][0] ?? 'autre', /* type d'après le type générique, sans le choix de la page */ 'coche' => $coche, 'gen' => (string)$c->getGeneric_type(), 'hist' => (int)$c->getIsHistorized(), 'lisse' => (int)self::lisse($c),
        'actif' => (int)$eq->getIsEnable(), 'sub' => (string)$c->getSubType(), 'eqid' => (int)$eq->getId(),
        'objid' => (int)$eq->getObject_id(), 'src' => $source((string)$eq->getEqType_name()), 'copie' => self::copie($c, $eq),
        'det' => presencehabituelle_analyse::estDetecteur((string)$c->getGeneric_type(), (string)$c->getSubType(), $c->getName(), $eq->getName(), $gens[$eq->getId()] ??= array_map(fn($x) => (string)$x->getGeneric_type(), $eq->getCmd('info')))];
      $k = $eq->getId() . '|' . $t;
      if ($rang && (!isset($choisi[$k]) || $rang > $choisi[$k][0])) $choisi[$k] = [$rang, count($r) - 1];
    }
    $garde = array_flip(array_column($choisi, 1));
    foreach ($r as $i => $c) if ($c['coche'] && !isset($garde[$i])) $r[$i]['coche'] = 0;
    return $r;
  }

  // Virtuel qui recopie une seule commande (calcul « #123# », éventuellement dans une formule) : l'id de l'originale, sinon 0
  public static function copie($c, $eq) {
    if ($eq->getEqType_name() != 'virtual' || !preg_match_all('/#(\d+)#/', (string)$c->getConfiguration('calcul'), $m)) return 0;
    $ids = array_values(array_unique(array_map('intval', $m[1])));
    return count($ids) == 1 && $ids[0] != $c->getId() ? $ids[0] : 0;
  }

  /* Enregistre le choix de la page. $suivi = {cmdId: {g, t}} ; renvoie le nombre de changements repris de l'historique. */
  public static function enregistrer(array $suivi, $exclure, $exclusions, $reponses = null, $regles = null, $detecteurs = null) {
    return self::verrou(fn() => self::enregistrer1($suivi, $exclure, $exclusions, $reponses, $regles, $detecteurs)); // deux onglets : l'un après l'autre
  }
  private static function enregistrer1(array $suivi, $exclure, $exclusions, $reponses, $regles, $detecteurs) {
    $A = 'presencehabituelle_analyse';
    $mal = [];
    foreach (preg_split('/\R/', (string)$exclusions) as $l) if (trim($l) !== '' && !$A::periodes($l)) $mal[] = trim($l);
    if ($mal) throw new Exception(__('Période illisible (attendu AAAA-MM-JJ HH:MM → AAAA-MM-JJ HH:MM)', __FILE__) . ' : ' . implode(' ; ', $mal));
    $propre = []; $avant = self::suivi(); $n = 0; $avantX = (int)config::byKey('exclure_cmd', __CLASS__, 0);
    foreach ($suivi as $id => $s) {
      if (!is_object($c = cmd::byId((int)$id)) || $c->getType() != 'info') continue;
      $t = in_array($s['t'] ?? '', ['lumiere', 'volet', 'prise', 'media', 'autre'], true) ? $s['t'] : 'autre';
      $propre[(int)$id] = ['g' => mb_substr(trim((string)($s['g'] ?? '')), 0, 60), 't' => $t] + ($t == 'volet' && !empty($s['i']) ? ['i' => 1] : []) // i : ouvert = valeur basse
        + ['d' => (int)($avant[(int)$id]['d'] ?? time())]; // d : suivie depuis (volet figé)
    }
    $exclure = (int)$exclure;
    if ($exclure && (!is_object($c = cmd::byId($exclure)) || $c->getType() != 'info')) throw new Exception(__('Commande d\'exclusion introuvable', __FILE__));
    if ($exclure && $c->getSubType() == 'string') // « Absent » en texte n'est pas reconnu : rien ne serait exclu, sans le dire
      throw new Exception(__('La commande d\'absence doit être binaire ou numérique (vraie pendant l\'absence) : un mode en texte ne convient pas, passez par un virtuel binaire', __FILE__));
    if ($exclure && self::lisse($c)) // une moyenne horaire (0,5 = absent une demi-heure) n'a ni début ni fin
      throw new Exception(__('Commande d\'absence numérique lissée : réglez son lissage sur « Aucun » (configuration de la commande, onglet Historique) ou passez par un virtuel binaire', __FILE__));
    if ($avantX && $avantX != $exclure) // commande d'absence changée ou retirée : ses absences restent exclues
      foreach (presencehabituelle_analyse::intervalles(self::evs($avantX), time()) as $i) self::exclure($i[0], $i[1]);
    $reponses = $reponses === null ? self::cfg('reponses') : $A::reponses($reponses); // null : page d'avant 0.8, réponses gardées
    $regles = $regles === null ? self::cfg('regles') : $A::regles($regles);
    $info = fn($id) => is_object($c = cmd::byId($id)) && $c->getType() == 'info';
    $detecteurs = $detecteurs === null ? self::cfg('detecteurs') : array_values(array_filter(array_map(fn($d) => ['cmd' => $info($d['cmd']) ? $d['cmd'] : 0, 'm' => array_values(array_filter($d['m'], $info))] + $d,
      $A::detecteurs($detecteurs)), fn($d) => $d['m'])); // commande supprimée : détecteur gardé, non relié ; lampes inconnues ôtées
    $gardes = array_keys($propre); if ($exclure) $gardes[] = $exclure; // tout est validé : les effacements peuvent commencer
    foreach (array_keys($avant + [$avantX => 1]) as $id) // retirée = ses évènements partent
      if ($id && !in_array((int)$id, $gardes, true)) DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE cmd_id = ?', [(int)$id], DB::FETCH_TYPE_ROW);
    $jours = self::jours('amorcer');
    foreach (array_unique($gardes) as $id) if (!isset($avant[$id]) && $id != $avantX) { $n += self::amorcer(cmd::byId($id), $jours); self::noter($id); }
    $vieux = ['suivi' => $avant, 'exclure' => $avantX, 'exclusions' => (string)config::byKey('exclusions', __CLASS__, ''), 'reponses' => self::cfg('reponses'), 'regles' => self::cfg('regles'), 'detecteurs' => self::cfg('detecteurs')];
    $sansD = fn($x) => array_map(fn($e) => array_diff_key($e, ['d' => 0]), $x); // d (suivie depuis) n'est pas un choix : sans lui, un suivi d'avant 0.9 serait vu changé
    if (config::byKey('suivi', __CLASS__, '') !== '' && ['suivi' => $sansD($avant)] + $vieux != ['suivi' => $sansD($propre), 'exclure' => $exclure, 'exclusions' => (string)$exclusions, 'reponses' => $reponses, 'regles' => $regles, 'detecteurs' => $detecteurs]) // « Revenir au choix d'avant »
      config::save('precedent', json_encode($vieux + ['date' => date('Y-m-d H:i')]), __CLASS__);
    config::save('suivi', json_encode($propre), __CLASS__);
    config::save('exclure_cmd', $exclure ?: '', __CLASS__);
    config::save('exclusions', (string)$exclusions, __CLASS__);
    config::save('reponses', json_encode((object)$reponses), __CLASS__);
    config::save('regles', json_encode((object)$regles), __CLASS__);
    config::save('detecteurs', json_encode($detecteurs), __CLASS__);
    self::majListener();
    return $n;
  }

  // Choix d'avant le dernier enregistrement (commande d'absence en clair), ou null
  public static function precedent() {
    $p = config::byKey('precedent', __CLASS__, '');
    if (is_string($p)) $p = json_decode($p, true);
    if (!is_array($p) || !isset($p['suivi'])) return null;
    $p['exclure'] = (int)($p['exclure'] ?? 0) ? cmd::cmdToHumanReadable('#' . (int)$p['exclure'] . '#') : '';
    $p['suivi'] = (object)$p['suivi']; $p['reponses'] = isset($p['reponses']) ? (object)$p['reponses'] : null; $p['regles'] = isset($p['regles']) ? (object)$p['regles'] : null; $p['detecteurs'] = isset($p['detecteurs']) ? self::detecteursLus($p['detecteurs']) : null; // null : choix d'avant la 0.8, la page garde les réponses actuelles
    return $p;
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
    $p = array_merge($A::periodes(config::byKey('exclusions', __CLASS__, '')), self::arrets(), self::arrets('simulation'));
    if ($d = (int)config::byKey('mode_depuis', __CLASS__, 0)) $p[] = [$d, time()]; // simulation en cours
    if ($x = (int)config::byKey('exclure_cmd', __CLASS__, 0))
      foreach ($A::intervalles(self::evs($x), time()) as $i) $p[] = [$i[0], $i[1]];
    return $A::fusion($p); // chevauchements réunis : une heure n'est retirée qu'une fois
  }

  /* Découverte : propositions tirées des 30 derniers jours, à confirmer sur la page (rien n'est enregistré ici).
     Aucune connaissance de la maison : types génériques, noms, et comportements dans l'historique. */
  const ABSENCE = '/(absen|vacanc|simulation|away|holiday|d[ée]part)/iu';
  const DECOUVERTE = 30; // jours lus

  public static function decouverte($det = null) { // $det : détecteurs de la page (pas encore enregistrés), sinon ceux enregistrés
    $A = 'presencehabituelle_analyse'; $fin = time(); $debut = $fin - 86400 * self::DECOUVERTE;
    $suivi = self::suivi(); $lampes = []; $eqNomme = []; $nommees = []; $absence = []; $volets = []; $eqVolet = [];
    $sansX = !(int)config::byKey('exclure_cmd', __CLASS__, 0);
    $cands = self::candidats(); $eqLampe = []; $rep = self::cfg('reponses'); $copies = [];
    $parId = array_column($cands, null, 'id'); // déjà répondue dans le Guide (« Non » compris) : plus reproposée
    foreach ($cands as $c) if (in_array($c['gen'], ['LIGHT_STATE', 'LIGHT_STATE_BOOL'], true)) $eqLampe[$c['eqid']] = 1; // ses autres binaires sont annexes
      elseif (in_array($c['gen'], ['FLAP_STATE', 'FLAP_BSO_STATE'], true)) $eqVolet[$c['eqid']] = 1; // volet déjà typé : sa « Position » n'est pas un 2e volet
    $bon = fn($x) => isset($suivi[$x['id']]) || ($x['hist'] && !$x['lisse']); // relevés exacts : suivie par le plugin, ou historisée sans lissage
    foreach ($cands as $c) {
      if (!$c['actif']) continue;
      $txt = $c['nom'] . ' ' . $c['eq'];
      // commande d'absence possible : alarme activée, ou nom parlant ; pas de texte, pas de lissé (refusés à l'enregistrement)
      if ($sansX && $c['sub'] != 'string' && !$c['lisse'] && ($c['gen'] == 'ALARM_ENABLE_STATE' || preg_match(self::ABSENCE, $txt)))
        $absence[] = ['h' => cmd::cmdToHumanReadable('#' . $c['id'] . '#'), 'nom' => self::nom($c['id'], true),
          'alarme' => $c['gen'] == 'ALARM_ENABLE_STATE'];
      if (isset($rep[$c['id']]) && !isset($suivi[$c['id']])) continue;
      if ($c['copie'] && ($o = $parId[$c['copie']] ?? null) && in_array($o['type'], ['lumiere', 'volet'], true) && $o['actif'] && ($rep[$o['id']]['r'] ?? 'oui') == 'oui') // virtuel qui recopie une lampe ou un volet active, pas refusée : doublon, on garde l'originale
        $copies[$c['id']] = $bon($c) && !$bon($o) ? -$o['id'] : $o['id']; // la copie a de meilleurs relevés que l'originale : proposé décoché
      if ($c['type'] == 'autre' && !isset($suivi[$c['id']]) && in_array($c['gen'], ['', 'GENERIC_INFO'], true) && !isset($eqVolet[$c['eqid']]) && $A::voletNomme($c['nom'], $c['eq'])) {
        $eqVolet[$c['eqid']] = 1; $volets[$c['id']] = $A::suspect($c['nom'], $c['eq']); continue; // une par équipement ; position, binaire ou texte (open / closed)
      }
      if ($c['type'] == 'autre' && in_array($c['gen'], ['', 'GENERIC_INFO'], true) && $c['sub'] == 'binary' && !isset($eqLampe[$c['eqid']])
        && $A::nommee($c['nom'], $c['eq'])) {
        if (isset($eqNomme[$c['eqid']])) continue; // une par équipement
        $eqNomme[$c['eqid']] = 1; $nommees[] = $c['id'];
      } elseif (!($c['type'] == 'lumiere' && ($c['coche'] || isset($suivi[$c['id']])))) continue;
      $lampes[$c['id']] = $c;
    }
    $observees = self::observes(); $releves = array_flip(array_merge(self::ids(false), self::observees())); // relevés du plugin lus seulement pour celles-ci (jamais des restes)
    $iv = []; $deb = []; $sans = []; $lisses = [];
    foreach ($lampes as $id => $c) {
      $ev = isset($releves[$id]) ? self::evs($id) : []; // relevés du plugin (suivie ou observée), sinon historique Jeedom
      if ($ev) {
        $av = null; $e = [];
        foreach ($ev as $x) if ($x[0] < $debut) $av = $x; else $e[] = $x;
        if ($av) array_unshift($e, [$debut, $av[1], true]);
        $ev = $e;
      } elseif (is_object($cmd = cmd::byId($id)) && $cmd->getIsHistorized()) $ev = self::historique($cmd, $debut);
      $lisse = !isset($releves[$id]) && $c['lisse']; // moyenne horaire : seules les 2 dernières heures sont exactes
      if (!$ev || ($lisse && $fin - $ev[0][0] < 86400)) { if (!in_array($id, $observees, true)) { if ($lisse) $lisses[] = $id; else $sans[] = $id; } continue; }
      $deb[$id] = $ev[0][0]; $iv[$id] = $A::intervalles($ev, $fin);
    }
    $ids = array_keys($iv);
    $commun = function (...$l) use ($deb) { return max(array_map(fn($x) => $deb[$x], $l)); };

    // 1. Doublons : mêmes bascules à 5 s près, 90 % des fois dans les deux sens ; on garde l'historisée exacte
    $rang = fn($id) => [isset($suivi[$id]), $lampes[$id]['hist'] && !$lampes[$id]['lisse'], (bool)$lampes[$id]['hist'], $lampes[$id]['sub'] == 'binary', count($iv[$id]), -$id];
    $doublon = []; $det = $A::detecteurs($det ?? self::cfg('detecteurs'));
    foreach ($ids as $i => $a) foreach (array_slice($ids, $i + 1) as $b) {
      if (isset($doublon[$a]) || isset($doublon[$b]) || $A::memeDetecteur($det, $a, $b) || $fin - ($s = $commun($a, $b)) < 86400) continue; // même détecteur : deux lampes qui s'allument ensemble, pas une seule
      $x = $A::coupe($iv[$a], $s, $fin); $y = $A::coupe($iv[$b], $s, $fin);
      if (count($x) < 2 || count($y) < 2 || $A::memes($x, $y, 5, $s, $fin) < .9 || $A::memes($y, $x, 5, $s, $fin) < .9) continue;
      [$g, $p] = $rang($a) >= $rang($b) ? [$a, $b] : [$b, $a];
      $doublon[$p] = $g;
    }
    $verif = [];
    foreach ($copies as $p => $g) { if ($g < 0) $verif[$p] = 1; $g = abs($g); if (($doublon[$g] ?? null) == $p) unset($doublon[$g]); $doublon[$p] = $g; } // l'originale gardée, jamais doublon de sa copie
    foreach ($doublon as $p => $g) { // a doublon de b, puis b doublon de c : a doublon de c
      for ($vu = [$p => 1]; isset($doublon[$g]) && !isset($vu[$g]); ) { $vu[$g] = 1; $g = $doublon[$g]; }
      if (isset($vu[$g])) { log::add(__CLASS__, 'debug', 'Découverte : doublons en boucle autour de #' . $p . ', non proposé'); unset($doublon[$p]); continue; } // a doublon de b doublon de a : aucun des deux retiré
      $doublon[$p] = $g;
    }
    foreach ($doublon as $p => $g) { // par une 3e commande (relais, virtuel) : deux lampes d'un même détecteur jamais fusionnées
      if ($A::memeDetecteur($det, $p, $g) && !isset($copies[$p])) { unset($doublon[$p]); continue; } // une recopie (virtuel) reste un doublon, même sur un détecteur
      foreach ($doublon as $q => $h) if ($q != $p && $h == $g && isset($doublon[$p]) && !isset($copies[$q]) && $A::memeDetecteur($det, $p, $q)) unset($doublon[$q]);
    }
    $canon = array_values(array_diff($ids, array_keys($doublon)));

    // 2. Groupes : allumée seulement quand d'autres le sont, et ses bascules sont celles de leur réunion (2 lampes au moins)
    $groupe = [];
    foreach ($canon as $g) {
      $m = [];
      foreach ($canon as $l) {
        if ($l == $g || $A::memeDetecteur($det, $g, $l) || $fin - ($s = $commun($g, $l)) < 86400) continue; // même détecteur : allumées ensemble, pas l'une le groupe de l'autre
        $x = $A::coupe($iv[$l], $s, $fin);
        if (($d = $A::duree($x)) > 0 && $A::inter($x, $A::coupe($iv[$g], $s, $fin)) >= .9 * $d) $m[] = $l;
      }
      if (count($m) < 2 || $fin - ($s = $commun($g, ...$m)) < 86400) continue;
      $x = $A::coupe($iv[$g], $s, $fin);
      $u = $A::union(array_combine($m, array_map(fn($l) => $A::coupe($iv[$l], $s, $fin), $m)));
      if (count($x) >= 2 && $A::memes($x, $u, 5, $s, $fin) >= .8 && $A::memes($u, $x, 5, $s, $fin) >= .8) $groupe[$g] = $m;
    }
    $seules = array_values(array_diff($canon, array_keys($groupe)));

    // 4. Jours sans aucune lumière (hors périodes déjà exclues)
    $absences = $A::absences(array_intersect_key($iv, array_flip($seules)), $deb, $A::jours($debut, $fin, self::exclusions()));

    // 6. Allumée à heure fixe ou au coucher du soleil : signalée seulement
    $geo = fn($k) => (float)str_replace(',', '.', (string)config::byKey($k, 'core', 0)); // « 48,85 » saisi à la française
    $lat = $geo('info::latitude'); $lon = $geo('info::longitude');
    $couchers = [];
    if ($lat || $lon) for ($t = strtotime('today', $debut); $t <= $fin; $t = strtotime('+1 day', $t))
      if (is_int($c = date_sun_info($t + 43200, $lat, $lon)['sunset'] ?? null)) $couchers[date('Y-m-d', $t)] = $c;
    $auto = [];
    foreach ($seules as $l) if ($a = $A::automatique($A::coupe($iv[$l], $deb[$l], $fin), $couchers)) $auto[] = ['id' => $l, 'nom' => self::nom($l, true)] + $a;

    $n = fn($id) => self::nom($id, true);
    return ['jours' => self::DECOUVERTE,
      'doublons' => array_map(fn($p, $g) => ['id' => $p, 'nom' => $n($p), 'de' => $g, 'nomDe' => $n($g), 'copie' => isset($copies[$p]), 'verif' => isset($verif[$p])], array_keys($doublon), $doublon),
      'groupes' => array_map(function ($g, $m) use ($n, $groupe) { $m = array_values(array_diff($m, array_keys($groupe))) ?: $m;
        return ['id' => $g, 'nom' => $n($g), 'ids' => $m, 'membres' => array_map($n, $m)]; }, array_keys($groupe), $groupe),
      'nommees' => array_map(fn($id) => ['id' => $id, 'nom' => $n($id)], $nommees),
      'volets' => array_map(fn($id, $v) => ['id' => $id, 'nom' => $n($id), 'verif' => $v], array_keys($volets), $volets), // verif : sans doute l'état d'un démon, proposé décoché
      'sans' => array_map(fn($id) => ['id' => $id, 'nom' => $n($id)], $sans),
      'lisses' => array_map(fn($id) => ['id' => $id, 'nom' => $n($id)], $lisses),
      'observation' => $observees ? self::finObservee() : 0,
      'absences' => array_map(fn($p) => $p[0] . ' → ' . $p[1], $absences),
      'automatiques' => $auto, 'absence' => $absence];
  }

  /* Plan de la maison : data/plan.json + une image par étage data/plan_<étage>.jpg (refusés au web par data/.htaccess).
     Coordonnées en % de l'image. {etages: [{id, nom}] (dans l'ordre), marqueurs: {cmdId: {e, x, y}}, pieces: [{id, e, nom, pts}],
     passages: [{id, e, a, b, t, pts, pts2 (tracé sur l'étage de l'autre bout d'un escalier)}], zones: [{id, e, pts}],
     liens: {jeeObjectId: id de pièce, ou '' = aucune}} ; étages dans l'ordre, du plus bas au plus haut */
  const VIDE = ['etages' => [], 'marqueurs' => [], 'pieces' => [], 'passages' => [], 'zones' => [], 'liens' => []];
  public static function planFichier() { return __DIR__ . '/../../data/plan.json'; }
  public static function planImage($e) { return __DIR__ . '/../../data/plan_' . $e . '.jpg'; }
  private static function ecrire($f, $d) { // fichier temporaire puis rename : jamais de fichier à moitié écrit ; $d tableau = JSON
    if (!is_string($d)) $d = json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE); // un nom mal encodé ne vide pas le fichier
    if ($d === false) throw new Exception(__('Données impossibles à enregistrer (encodage) : rien n\'a été enregistré', __FILE__));
    $t = $f . '.' . getmypid() . mt_rand() . '.tmp'; // un temporaire par écriture : deux envois en même temps ne se mélangent pas
    if (@file_put_contents($t, $d) !== strlen($d) || !@rename($t, $f)) { @unlink($t); throw new Exception(__('Écriture impossible sur la box (disque plein ?) : rien n\'a été enregistré', __FILE__)); }
  }
  private static function idPlan($v) { return is_string($v) && preg_match('/^[a-z0-9]{1,16}$/', $v) ? $v : null; }
  public static function plan() {
    $p = is_file($f = self::planFichier()) ? json_decode((string)file_get_contents($f), true) : null;
    $p = (is_array($p) ? $p : []) + self::VIDE;
    $p['passages'] = presencehabituelle_analyse::fusionner($p['passages'], $p['pieces']); // escalier dessiné sur les deux étages avant la 0.10 : un seul
    $p['marqueurs'] = (object)$p['marqueurs']; $p['liens'] = (object)$p['liens']; // {} et non [] pour le JS
    return $p;
  }

  // Valide tout ce qui vient de la page (jamais pris tel quel) puis enregistre ; les images des étages retirés partent
  public static function planEnregistrer(array $p) {
    $date = (int)(self::plan()['date'] ?? 0);
    if ((int)($p['date'] ?? 0) !== $date) throw new Exception(__('Plan modifié ailleurs (autre onglet ?) depuis son ouverture : rechargez la page, rien n\'a été enregistré', __FILE__));
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
    foreach (['pieces' => [3, 200], 'zones' => [3, 200], 'passages' => [2, 200]] as $k => [$min, $max])
      foreach (array_slice((array)($p[$k] ?? []), 0, 300) as $o) {
        if (!is_array($o) || !($id = self::idPlan($o['id'] ?? null)) || isset($vus[$id]) || !$etage($o['e'] ?? null) || !($l = $pts($o['pts'] ?? null, $min, $max))) continue;
        $x = ['id' => $id, 'e' => $o['e'], 'pts' => $l];
        if ($k == 'pieces') { if (($x['nom'] = $nom($o['nom'] ?? '')) === '') continue; }
        if ($k == 'passages') {
          $x['t'] = in_array($o['t'] ?? '', ['porte', 'couloir', 'escalier'], true) ? $o['t'] : 'porte';
          $x['a'] = self::idPlan($o['a'] ?? null); $x['b'] = self::idPlan($o['b'] ?? null); // pièces vérifiées plus bas (définies après)
          if ($l2 = $pts($o['pts2'] ?? null, 2, 200)) $x['pts2'] = $l2; // gardé seulement pour un escalier (fusionner)
        }
        $vus[$id] = 1; $r[$k][] = $x;
      }
    $pieces = array_flip(array_column($r['pieces'], 'id'));
    $r['passages'] = array_values(array_filter($r['passages'], fn($x) => $x['a'] && $x['b'] && isset($pieces[$x['a']], $pieces[$x['b']]) && $x['a'] !== $x['b']));
    $r['passages'] = presencehabituelle_analyse::fusionner($r['passages'], $r['pieces']);
    foreach (array_slice((array)($p['liens'] ?? []), 0, 500, true) as $o => $pc) // pièce Jeedom → pièce du plan ('' : aucune)
      if ((int)$o > 0 && is_object(jeeObject::byId((int)$o)) && is_string($pc) && ($pc === '' || isset($pieces[$pc]))) $r['liens'][(int)$o] = $pc;
    $r['date'] = max(time(), $date + 1); // version : un 2e onglet resté sur l'ancien plan est refusé
    self::ecrire(self::planFichier(), $r);
    foreach (glob(self::planImage('*')) ?: [] as $f) // images des étages retirés, et celles déposées pour un étage jamais enregistré
      if (preg_match('/plan_([a-z0-9]{1,16})\.jpg$/', $f, $m) && !isset($et[$m[1]]) && filemtime($f) < time() - 86400) unlink($f); // ponytail: un jour de grâce pour l'image d'un étage pas encore enregistré
    return self::plan();
  }

  // Image d'un étage, réduite et convertie en JPEG par la page (data:image/jpeg;base64,…) : vérifiée avant d'être gardée
  public static function planImageEnregistrer($e, $data) {
    if (!($e = self::idPlan($e))) throw new Exception(__('Étage inconnu', __FILE__));
    if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string)$data, $m) || ($b = base64_decode($m[1], true)) === false
      || strlen($b) > 3000000 || !($i = @getimagesizefromstring($b)) || $i[2] != IMAGETYPE_JPEG || $i[0] > 4000 || $i[1] > 4000)
      throw new Exception(__('Image refusée : JPEG de 4000 × 4000 pixels et 3 Mo au plus', __FILE__));
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
      $moitie = is_scalar($dim) ? (float)$dim / 2 : 0; // « 200px » ou « 200 » : 100 ; absente : 0 (coin du widget)
      $pc = is_scalar($dim) && substr(trim((string)$dim), -1) == '%'; // largeur « 10% » : en % du design
      if (is_string($v) && substr(trim($v), -1) == '%') return (float)$v + ($pc ? $moitie : $moitie / $taille * 100);
      return $pc ? (float)$v / $taille * 100 + $moitie : ((float)$v + $moitie) / $taille * 100;
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
    self::semaine();
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
      $iv = []; $debut = null; $exG = $excl; $noms = []; $bloq = [];
      foreach ($ids as $id) {
        if (!$ev = self::evs($id)) continue;
        $debut = min($debut ?? PHP_INT_MAX, $ev[0][0]); // ponytail: avant sa 1re ligne, une lampe ajoutée plus tard compte éteinte ; le groupe garde l'historique des autres
        [$iv[$id], $x, $bl] = $A::longues($A::intervalles($ev, $fin), $max, $fin);
        if ($bl) $bloq[] = self::nom($id) . ' (' . date('d/m H:i', $bl) . ')'; // allumée sans fin connue depuis plus de session_max
        if (isset($piece[$id])) $parPiece[$piece[$id]][$id] = $iv[$id];
        foreach ($x as $p) $exG[] = $p; // extinction manquée : jours touchés non comptés pour tout le groupe
        $noms[$id] = self::nom($id);
      }
      $dbl = array_count_values($noms); // deux lampes de même nom : l'id les distingue
      foreach ($noms as $id => $n) if ($dbl[$n] > 1) $noms[$id] = $n . ' #' . $id;
      $g = (string)$g; $seule = $g[0] === "\0" ? (int)substr($g, 1) : 0; $nom = $seule ? self::nom($seule) : ltrim($g, "\1");
      $pi = $g[0] === "\1"; // groupe d'une pièce du plan
      if (!$iv) { $res['groupes'][] = ['nom' => $nom, 'id' => $seule, 'piece' => $pi, 'ids' => $ids, 'jours' => 0]; continue; }
      $parNom = []; foreach ($iv as $id => $i) $parNom[$noms[$id]] = $i;
      $j = $A::jours($debut, $fin, $exG); $se = $A::sessions($parNom); $p = $A::profil($se, $j);
      $res['groupes'][] = ['nom' => $nom, 'id' => $seule, 'piece' => $pi, 'ids' => $ids, 'lampes' => array_values($noms), 'lids' => array_keys($noms), 'jours' => count($j), 'nS' => $p['nS'], 'nW' => $p['nW'],
        'S' => $p['S'], 'W' => $p['W'], 'cS' => $p['cS'], 'cW' => $p['cW'], 'lS' => $A::lisser($p['S']), 'lW' => $A::lisser($p['W']),
        'ex' => $p['ex'], 'durees' => $A::durees($se, $j), 'scenes' => count($iv) > 1 ? $A::scenes($se, $j) : [], 'confirmerS' => $p['nS'] < self::FIABLE, 'confirmerW' => $p['nW'] < self::FIABLE, 'bloquees' => $bloq];
    }
    foreach ($volets as $id => $s) {
      $ev = self::evs($id); $nom = self::nom($id);
      if (!empty($s['i'])) $ev = $A::inverser($ev);
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
      foreach ($res[$t] as $i => $x) if ($n[$x['nom']] > 1) {
        if ($x['id']) $res[$t][$i]['nom'] = self::nom($x['id'], true);
        elseif (!empty($x['piece'])) $res[$t][$i]['nom'] .= ' (' . __('pièce', __FILE__) . ')'; // pièce et groupe saisi de même nom
      }
    }
    self::ecrire(self::fichier(), $res);
    try { self::majWidget($res); } catch (Throwable $e) { log::add(__CLASS__, 'error', 'widget : ' . $e->getMessage()); } // les tendances sont enregistrées quoi qu'il arrive
    return $res;
  }

  // Week-end de la configuration → presencehabituelle_analyse::$WE
  public static function semaine() {
    $c = fn($k, $d) => config::byKey($k, __CLASS__, $d) ?: $d;
    presencehabituelle_analyse::weekEnd($c('we_jour_debut', 5), $c('we_heure_debut', '16:00'), $c('we_jour_fin', 1), $c('we_heure_fin', '04:00'));
  }

  /* Le widget : un équipement du plugin à lui (logicalId « widget »), créé au 1er clic sur « Widget » (supprimé, il ne revient pas seul).
     Ses commandes seules sont écrites ($w, $wc) ; marque « ph:propre » = écriture sur cet équipement-là (vérifiée par le selftest).
     « Allumé d'habitude maintenant » dit quand la maison est vide : rempli seulement si l'option widget_pieces est cochée. */
  const CMDS = ['etat' => ['État', 'info', 'string'], 'maintenant' => ['Allumé d\'habitude maintenant', 'info', 'string'],
    'calcul' => ['Tendances du', 'info', 'string'], 'suivies' => ['Commandes suivies', 'info', 'numeric'], 'recalculer' => ['Recalculer', 'action', 'other'],
    'mode' => ['Simulation', 'info', 'binary'], 'on' => ['Simulation On', 'action', 'other'], 'off' => ['Simulation Off', 'action', 'other']];
  public static function equipement($creer = true) {
    $w = eqLogic::byLogicalId('widget', __CLASS__);
    if (!is_object($w)) {
      if (!$creer) return null;
      $w = new self(); $w->setName(__('Présence habituelle', __FILE__)); // ph:propre
      $w->setEqType_name(__CLASS__); $w->setLogicalId('widget'); $w->setIsEnable(1); $w->setIsVisible(1); // ph:propre
      try { $w->save(); } catch (Exception $e) { $w->setName(__('Présence habituelle (plugin)', __FILE__)); $w->save(); } /* nom déjà pris par un équipement sans objet */ // ph:propre
    }
    $o = 0;
    foreach (self::CMDS as $k => [$nom, $type, $sous]) {
      $o++;
      if (is_object($w->getCmd(null, $k))) continue;
      $wc = new presencehabituelleCmd(); $wc->setEqLogic_id($w->getId()); $wc->setLogicalId($k); $wc->setName(__($nom, __FILE__)); // ph:propre
      $wc->setType($type); $wc->setSubType($sous); $wc->setOrder($o); if ($k == 'maintenant') $wc->setIsVisible(0); $wc->save(); // ph:propre
    }
    return $w;
  }
  // Valeurs du widget : à chaque calcul et chaque heure. « Allumé d'habitude » = pièces allumées au moins un jour sur deux à cette heure (profil lissé).
  public static function majWidget($h = null) {
    if (!is_object($w = self::equipement(false)) || !$w->getIsEnable()) return;
    if ($h === null) $h = is_file($f = self::fichier()) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($h)) return;
    self::semaine();
    $t = time(); $hr = (int)date('G', $t);
    $k = presencehabituelle_analyse::cle((int)date('N', $t), $hr); // jour civil et heure pleine, comme profil() des lumières
    $n = []; foreach ($h['groupes'] ?? [] as $g) if (empty($g['confirmer' . $k]) && ($g['l' . $k][$hr] ?? 0) >= 50) $n[] = $g['nom']; // « à confirmer » : pas assez de jours
    $m = !(int)config::byKey('widget_pieces', __CLASS__, 0) ? '' : ($n ? mb_substr(implode(', ', $n), 0, 250) : __('rien', __FILE__));
    $mode = (int)config::byKey('mode', __CLASS__, 0);
    $w->checkAndUpdateCmd('etat', !$mode ? __('Simulation arrêtée : rien n\'est allumé ni fermé', __FILE__) : (self::blanc() ? __('Simulation à blanc : rien n\'est envoyé', __FILE__) : __('Simulation en marche', __FILE__))); // ph:propre
    $w->checkAndUpdateCmd('mode', $mode); // ph:propre
    $w->checkAndUpdateCmd('maintenant', $m); // ph:propre
    $w->checkAndUpdateCmd('calcul', isset($h['date']) ? date('d/m H:i', strtotime($h['date'])) : ''); // ph:propre
    $w->checkAndUpdateCmd('suivies', count(self::suivi())); // ph:propre
  }

  /* Historique Jeedom des commandes suivies : seule écriture du plugin sur des commandes d'autres équipements, à la demande
     (bouton + confirmation), état d'avant gardé dans data/ pour « Remettre comme avant ». Marque « ph:historiser » (selftest).
     Lissage « Aucun » : sinon Jeedom remplace les valeurs par une moyenne horaire après quelques heures. */
  public static function aHistoriser() {
    $r = [];
    foreach (self::suivi() as $id => $s) {
      if (!is_object($c = cmd::byId($id)) || $c->getType() != 'info') continue;
      if (!$c->getIsHistorized() || self::lisse($c)) $r[] = ['id' => (int)$id, 'nom' => self::nom($id, true), 'hist' => (int)$c->getIsHistorized(), 'lisse' => (int)self::lisse($c)];
    }
    return $r;
  }
  public static function historiserFichier() { return __DIR__ . '/../../data/historiser_avant.json'; }
  public static function historiserAvant() {
    $a = is_file($f = self::historiserFichier()) ? json_decode((string)file_get_contents($f), true) : [];
    return is_array($a) ? $a : [];
  }
  // État d'historique d'une commande, tel que l'affiche le Guide
  public static function etatHisto($c) { return ['hist' => (int)$c->getIsHistorized(), 'lisse' => (int)self::lisse($c)]; }
  // Un appel à la fois (double clic, deux onglets) : le 2e attend la fin du 1er et lit l'état à jour
  public static function verrou(callable $f) {
    $v = new SplFileObject(__DIR__ . '/../../data/historiser.lock', 'c'); // fichier local (pas fopen : le selftest le refuse, appel réseau possible)
    $v->flock(LOCK_EX);
    try { return $f(); } finally { $v->flock(LOCK_UN); }
  }
  /* avant[id] = {h, m} : 1er état d'avant le plugin (jamais écrasé) ; apres = état posé par le plugin (« Remettre » ne touche pas
     une commande réglée autrement depuis). Renvoie [id => état affiché]. */
  public static function historiser(array $ids) {
    return self::verrou(function () use ($ids) {
      $ids = array_flip(array_map('intval', $ids)); $faits = [];
      $avant = self::historiserAvant();
      foreach (self::aHistoriser() as $x) {
        if (!isset($ids[$x['id']]) || !is_object($c = cmd::byId($x['id']))) continue;
        if (!isset($avant[$x['id']])) $avant[$x['id']] = ['h' => (int)$c->getIsHistorized(), 'm' => $c->getConfiguration('historizeMode', null)];
        unset($avant[$x['id']]['apres']); // posé seulement une fois l'enregistrement réussi
        $faits[] = [$c, $x];
      }
      if (!$faits) return [];
      self::ecrire(self::historiserFichier(), $avant); // sauvegarde AVANT toute écriture
      $r = []; $noms = [];
      foreach ($faits as [$c, $x]) {
        $c->setIsHistorized(1); // ph:historiser
        if ($c->getSubType() != 'binary') $c->setConfiguration('historizeMode', 'none'); // ph:historiser
        $c->save(); // ph:historiser
        $avant[$x['id']]['apres'] = [1, $c->getConfiguration('historizeMode', null)]; self::ecrire(self::historiserFichier(), $avant);
        $r[$x['id']] = self::etatHisto($c); $noms[] = $x['nom'];
      }
      log::add(__CLASS__, 'info', 'Historique activé : ' . implode(', ', $noms));
      return $r;
    });
  }
  // Renvoie remis (noms), laisses (noms : réglés autrement depuis, pas touchés), etats [id => état affiché]
  public static function remettre() {
    return self::verrou(function () {
      $r = ['remis' => [], 'laisses' => [], 'etats' => []]; $reste = self::historiserAvant();
      foreach ($reste as $id => $a) {
        if (is_object($c = cmd::byId($id))) {
          if (isset($a['apres']) && [(int)$c->getIsHistorized(), $c->getConfiguration('historizeMode', null)] != $a['apres']) $r['laisses'][] = self::nom($id, true);
          else {
            $c->setIsHistorized((int)$a['h']); // ph:historiser
            $c->setConfiguration('historizeMode', $a['m'] ?? null); // ph:historiser
            $c->save(); // ph:historiser
            $r['remis'][] = self::nom($id, true); $r['etats'][$id] = self::etatHisto($c);
          }
        }
        unset($reste[$id]); self::ecrire(self::historiserFichier(), (object)$reste); // une erreur plus loin : celles-ci ne sont pas retentées
      }
      if (is_file($f = self::historiserFichier())) unlink($f);
      log::add(__CLASS__, 'info', 'Historique remis comme avant : ' . implode(', ', $r['remis']) . ($r['laisses'] ? ' ; laissées (changées depuis) : ' . implode(', ', $r['laisses']) : ''));
      return $r;
    });
  }

  /* Simulation (0.13). Mode On/Off (config 'mode', 'mode_depuis') : une période On est exclue de l'analyse, comme une absence.
     À blanc (case 'reel' de la configuration non cochée, le défaut) : le moteur décide et note au journal, n'envoie rien ;
     « blanc_sans_on » le fait tourner à blanc même sans On, pour l'essayer à la maison (cette période-là n'est pas exclue).
     Le scénario 245 et le Mode simulation 927 de la maison ne sont ni lus ni touchés. */
  public static function blanc() { return (string)config::byKey('reel', __CLASS__, '0') !== '1'; } // case non cochée (ou jamais enregistrée) = à blanc
  public static function tourne() { return (int)config::byKey('mode', __CLASS__, 0) || (self::blanc() && (int)config::byKey('blanc_sans_on', __CLASS__, 0)); }
  public static function simulation($on) {
    self::verrou(function () use ($on) {
      if ((int)$on == (int)config::byKey('mode', __CLASS__, 0)) return;
      if ($on) config::save('mode_depuis', time(), __CLASS__);
      else {
        if ($d = (int)config::byKey('mode_depuis', __CLASS__, 0)) self::exclure($d, time(), 'simulation');
        config::remove('mode_depuis', __CLASS__);
      }
      config::save('mode', $on ? 1 : 0, __CLASS__);
      try { self::journal([['nom' => '', 'ids' => [], 'action' => $on ? 'mode on' : 'mode off', 'src' => '', 'p' => 0, 'motif' => '', 'mode' => self::blanc() ? 'blanc' : 'reel']]); } catch (Throwable $e) { log::add(__CLASS__, 'warning', 'journal : ' . $e->getMessage()); }
      self::arretMoteur(!$on); // On : nouveau départ : ce que l'essai à blanc croyait allumé est oublié ; à l'arrêt, ce qui a été allumé est éteint
      log::add(__CLASS__, 'info', 'Simulation ' . ($on ? 'On' : 'Off') . (self::blanc() ? ' (à blanc)' : ''));
    });
    self::majWidget();
  }
  /* Fin : ce que le moteur a vraiment allumé est éteint (état tenu en envoi réel, et envoi encore permis), son état oublié.
     $fin = false (On) : nouveau départ, rien n'est envoyé. */
  private static function arretMoteur($fin = true) {
    if (!$E = self::cfg('moteur')) return;
    config::remove('moteur', __CLASS__); // avant l'envoi : un échec ne laisse pas un état faux
    self::agir(presencehabituelle_moteur::arret($E), $fin && ($E['reel'] ?? true) && !self::blanc()); // ?? true : état d'avant la 0.13.1, sans « reel »
  }
  /* Case « Envoyer vraiment » changée (appelé par config::save avant l'enregistrement : l'ancienne valeur se lit encore).
     Décochée pendant une simulation : ce qu'elle a vraiment allumé est éteint tant que l'envoi est permis (les volets restent comme ils sont) ;
     cochée : l'état de l'essai à blanc n'est pas la maison, nouveau départ. */
  public static function preConfig_reel($v) {
    $avant = !self::blanc();
    if ($avant != ((string)$v === '1')) self::verrou(function () use ($avant) {
      if ($avant) self::arretMoteur(); else config::remove('moteur', __CLASS__);
      log::add(__CLASS__, 'info', $avant ? 'Envoi réel décoché : lampes allumées par la simulation éteintes, suite à blanc' : 'Envoi réel coché : la simulation repart de zéro');
    });
    return $v;
  }
  public static function postConfig_reel($v) { self::majWidget(); }
  public static function cron5() {
    try { self::verrou(fn() => self::moteur()); } catch (Throwable $e) { log::add(__CLASS__, 'error', 'cron5 : ' . $e->getMessage()); }
  }
  private static function moteur() {
    $M = 'presencehabituelle_moteur';
    if (!self::tourne()) { self::arretMoteur(); return; } // essai à blanc arrêté : rien n'avait été envoyé (état sans « reel »)
    $E = self::cfg('moteur'); $t = time(); $reel = !self::blanc() && (int)config::byKey('mode', __CLASS__, 0);
    if ($E && isset($E['reel']) && (bool)$E['reel'] != $reel) $E = []; // ponytail: bascule déjà traitée par preConfig_reel ; ici un filet, état d'une autre façon d'envoyer oublié
    $C = self::contexte($t, $E); $dec = [];
    if ($reel) {
      $ids = []; foreach ($C['sources'] as $s) $ids = array_merge($ids, $s['ids']);
      [$E, $dec] = $M::visiteurs($E, $C['sources'], self::etats($ids), $t, ($v = (int)config::byKey('visiteur', __CLASS__, 60)) > 0 ? $v : 60);
    }
    [$E, $d] = $M::pas($E, $C, fn() => mt_rand() / (mt_getrandmax() + 1)); $dec = array_merge($dec, $d);
    if ($reel) $dec = self::verifier($E, $dec, $t);
    $E['reel'] = $reel;
    config::save('moteur', json_encode($E, JSON_INVALID_UTF8_SUBSTITUTE), __CLASS__); // avant d'agir : un envoi ou un journal en échec ne renvoie pas les mêmes ordres au passage suivant
    self::agir($dec, $reel);
  }
  /* Envoi réel : rien n'est allumé sans commande pour l'éteindre, rien n'est fermé sans commande pour l'ouvrir,
     ni un volet déjà fermé (par quelqu'un d'autre : le moteur ne le rouvrira pas). Décision gardée au journal, marquée « saute ». */
  private static function verifier(array &$E, array $dec, $t) {
    $S = self::suivi();
    foreach ($dec as $i => $d) {
      if ($d['action'] == 'on') {
        $l = array_values(array_filter($d['ids'], fn($id) => self::action($id, 'off') !== null));
        if ($l) { $dec[$i]['ids'] = $l; $E['g'][$d['nom']]['l'] = $l; continue; }
        $dec[$i]['saute'] = __('pas de commande pour éteindre', __FILE__);
        $E['g'][$d['nom']] = ['on' => 0, 'fin' => 0, 'l' => [], 'pause' => $t + 3600, 'vis' => 0];
      } elseif ($d['action'] == 'fermer') {
        $id = $d['ids'][0]; $inv = !empty($S[$id]['i']);
        if (!self::action($id, 'ouvrir', $inv)) $dec[$i]['saute'] = __('pas de commande pour ouvrir', __FILE__);
        elseif (self::voletFerme($id, $inv)) $dec[$i]['saute'] = __('déjà fermé', __FILE__);
        else continue;
        $E['v'][$id]['ferme'] = 0; // pas fermé par le moteur : ni rouvert ni refermé aujourd'hui
      }
    }
    return $dec;
  }
  // ponytail: fermé = 0 (ou, sens inversé, 1 ou ≥ 99) ; une position intermédiaire compte pour ouvert
  private static function voletFerme($id, $inv) {
    if (!is_object($c = cmd::byId($id)) || !($c->getType() == 'info') || ($v = self::valeur($c->execCmd())) === null) return false;
    return $inv ? ($v == 1 || $v >= 99) : $v <= 0;
  }
  /* Contexte d'un passage à l'instant $t ; tire l'aléa et le plan des volets à chaque nouvelle journée (04:00) dans $E.
     $capteur = false : pas de capteur de luminosité (journée rejouée à l'avance), le soleil seul. */
  public static function contexte($t, array &$E, $capteur = true) {
    $M = 'presencehabituelle_moteur'; $A = 'presencehabituelle_analyse'; $r = fn() => mt_rand() / (mt_getrandmax() + 1);
    static $cache = null; // tendances lues une fois par appel (journée rejouée : 288 passages)
    self::semaine();
    if ($cache === null) {
      $h = self::habitudes(); $regles = $A::regles(self::cfg('regles')); $tx = [];
      foreach ($h['groupes'] ?? [] as $g) $tx[$g['nom']] = $g['nom'] . ' ' . implode(' ', array_map(fn($id) => self::nom($id, true), $g['ids'] ?? [])); // nom et pièce Jeedom : type de pièce
      $cache = ['h' => $h, 'regles' => $regles, 'sources' => $M::sources($h['groupes'] ?? [], $regles, $tx, self::FIABLE)];
    }
    $geo = fn($k) => (float)str_replace(',', '.', (string)config::byKey($k, 'core', 0));
    $lat = $geo('info::latitude'); $lon = $geo('info::longitude');
    if (!$lat && !$lon) { $lat = 46.6; $lon = 2.4; } // ponytail: coordonnées absentes de la configuration de Jeedom, centre de la France
    $jd = (int)date('G', $t) < 4 ? date('Y-m-d', strtotime('yesterday', $t)) : date('Y-m-d', $t); // la nuit appartient au jour d'avant (heures d'horloge : juste au changement d'heure)
    if (($E['jour']['d'] ?? '') !== $jd) {
      $mid = strtotime($jd . ' 12:00'); $n = (int)date('N', $mid); $sj = date_sun_info($mid, $lat, $lon);
      $mn = fn($x) => is_int($x) ? (int)date('G', $x) * 60 + (int)date('i', $x) : null;
      $E['jour'] = ['d' => $jd] + $M::jour($r) + ['volets' => $M::planVolets($cache['h']['volets'] ?? [], $cache['regles'], $A::cle($n, 8), $A::cle($n, 21), $mn($sj['sunrise'] ?? null) ?? 480, $mn($sj['sunset'] ?? null) ?? 1200, $r, self::FIABLE)];
    }
    $lux = null;
    if ($capteur && ($lc = (int)trim((string)config::byKey('lux_cmd', __CLASS__, ''), '# ')) && is_object($c = cmd::byId($lc)) && $c->getType() == 'info' && is_numeric($v = $c->execCmd())) {
      $E['lux'] = isset($E['lux']) ? .88 * $E['lux'] + .12 * (float)$v : (float)$v; $lux = $E['lux']; // lissé : un nuage ne rallume pas la maison
    }
    $bas = is_numeric($x = str_replace(',', '.', trim((string)config::byKey('lux_bas', __CLASS__, '')))) ? max(0, (float)$x) : 350; // 0 permis : nuit noire seulement
    $haut = (float)config::byKey('lux_haut', __CLASS__, 1000) ?: 1000;
    $max = ($x = (int)config::byKey('presences_max', __CLASS__, 3)) > 0 ? $x : 3;
    $sans = array_values(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', (string)config::byKey('sans_jour', __CLASS__, ''))), 'strlen')); // sans tenir compte de la casse
    return ['t' => $t, 'm' => (int)date('G', $t) * 60 + (int)date('i', $t), 'k' => $A::cle((int)date('N', $t), (int)date('G', $t), (int)date('i', $t)),
      'pk' => $M::penombre($t, date_sun_info(strtotime(date('Y-m-d', $t) . ' 12:00'), $lat, $lon), $lux, $bas, max($bas + 1, $haut)),
      'sources' => $cache['sources'], 'max' => $max, 'sansJour' => $sans, 'regles' => $cache['regles']];
  }
  // État réel des lampes (infos seulement) : [id => allumée]
  private static function etats(array $ids) {
    $r = [];
    foreach (array_unique($ids) as $id) if (is_object($c = cmd::byId($id)) && $c->getType() == 'info' && ($v = self::valeur($c->execCmd())) !== null) $r[$id] = $v > 0;
    return $r;
  }
  private static function agir(array $dec, $reel) {
    $j = [];
    foreach ($dec as $d) {
      $d['mode'] = $reel ? 'reel' : 'blanc';
      if (isset($d['saute'])) { $d['envoi'] = $d['saute']; unset($d['saute']); }
      elseif ($reel) { try { $d['envoi'] = self::envoyer($d); } catch (Throwable $e) { $d['envoi'] = $e->getMessage(); } }
      $j[] = $d;
    }
    if ($j) { try { self::journal($j); } catch (Throwable $e) { log::add(__CLASS__, 'warning', 'journal : ' . $e->getMessage()); } } // journal en échec (disque plein) : l'état est déjà enregistré
  }
  /* Seul endroit où le plugin agit sur la maison, jamais à blanc (vérifié par le selftest). Commande d'action du même équipement que l'info suivie :
     liée à elle (sa valeur), sinon la seule de l'équipement à avoir le type générique ou le nom attendu (deux possibles, aucune : rien n'est envoyé).
     → '' ou ce qui n'a pas pu être envoyé */
  const ACTIONS = ['on' => [['LIGHT_ON', 'ENERGY_ON'], '/^(on|allumer?|marche)$/iu', 'max'], 'off' => [['LIGHT_OFF', 'ENERGY_OFF'], '/^(off|[ée]teindre|arr[êe]t)$/iu', 'min'],
    'ouvrir' => [['FLAP_UP', 'FLAP_BSO_UP'], '/^(ouvrir|monter|mont[ée]e|haut|up|open)$/iu', 'max'], 'fermer' => [['FLAP_DOWN', 'FLAP_BSO_DOWN'], '/^(fermer|descendre|descente|bas|down|close)$/iu', 'min']];
  public static function envoyer(array $d) {
    if (self::blanc()) return __('à blanc', __FILE__);
    $S = self::suivi(); $manque = [];
    foreach ($d['ids'] as $id) {
      if (!$a = self::action($id, $d['action'], !empty($S[$id]['i']))) { $manque[] = self::nom($id); continue; }
      [$c, $o] = $a;
      $c->execCmd($o); // ph:envoyer
    }
    return $manque ? __('pas de commande d\'action', __FILE__) . ' : ' . implode(', ', $manque) : '';
  }
  public static function action($id, $action, $inv = false) { // → [commande d'action, options] ou null
    if (!is_object($i = cmd::byId($id)) || !is_object($eq = $i->getEqLogic())) return null;
    [$gen, $re, $bout] = self::ACTIONS[$action];
    if ($inv && in_array($action, ['ouvrir', 'fermer'])) $bout = $bout == 'max' ? 'min' : 'max'; // sens inversé : 0 = ouvert
    $lie = []; $nom = []; $cur = [];
    foreach ($eq->getCmd('action') as $c) {
      if ($c->getType() != 'action') continue;
      $g = (string)$c->getGeneric_type(); $l = (int)trim((string)$c->getValue(), '#') == (int)$id;
      if (in_array($g, $gen, true) || preg_match($re, trim($c->getName()))) { if ($l) $lie[] = [$c, []]; else $nom[] = [$c, []]; }
      elseif ($c->getSubType() == 'slider' && in_array($g, ['LIGHT_SLIDER', 'FLAP_SLIDER'], true)) $cur[] = [$c, ['slider' => $bout == 'max' ? $c->getConfiguration('maxValue', 100) : $c->getConfiguration('minValue', 0)], $l];
    }
    if (count($lie) == 1) return $lie[0];
    if (!$lie && count($nom) == 1) return $nom[0];
    if (!$lie && !$nom) { $cl = array_values(array_filter($cur, fn($x) => $x[2])); $x = count($cl) == 1 ? $cl[0] : (count($cur) == 1 ? $cur[0] : null); return $x ? [$x[0], $x[1]] : null; }
    return null;
  }
  public static function journalFichier() { return __DIR__ . '/../../data/simulation.log.json'; }
  // Journal du moteur, 7 jours ; $l null = lecture
  public static function journal(?array $l = null) {
    $f = self::journalFichier(); $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    if (!is_array($j)) $j = [];
    if ($l === null) return $j;
    $t = time();
    foreach ($l as $x) $j[] = ['t' => $t, 'type' => isset($x['v']) ? 'volet' : 'lumiere'] + array_diff_key($x, ['g' => 0, 'v' => 0]);
    $j = array_values(array_filter($j, fn($x) => ($x['t'] ?? 0) >= $t - 7 * 86400));
    self::ecrire($f, array_slice($j, -5000));
  }
  // Journée à blanc : les 24 prochaines heures rejouées en mémoire (même code, soleil seul), rien n'est envoyé ni gardé
  public static function journee() {
    $E = []; $r = []; $t0 = time() - time() % 300; $M = 'presencehabituelle_moteur';
    for ($t = $t0; $t < $t0 + 86400; $t += 300) {
      $C = self::contexte($t, $E, false);
      [$E, $d] = $M::pas($E, $C, fn() => mt_rand() / (mt_getrandmax() + 1));
      foreach ($d as $x) $r[] = ['t' => $t, 'type' => isset($x['v']) ? 'volet' : 'lumiere'] + array_diff_key($x, ['g' => 0, 'v' => 0]);
    }
    return ['debut' => $t0, 'fin' => $t0 + 86400, 'decisions' => $r, 'sources' => array_values($C['sources'] ?? []), 'volets' => $E['jour']['volets'] ?? []];
  }
  // Onglet Simulation
  public static function etatSimulation() {
    $E0 = self::cfg('moteur'); $E = $E0; $C = self::contexte(time(), $E, false);
    $vol = self::tourne() && ($E0['jour']['d'] ?? '') === $E['jour']['d'] ? $E0['jour']['volets'] : []; // Off, ou nouvelle journée pas encore tirée par le cron : pas de plan inventé ici
    return ['mode' => (int)config::byKey('mode', __CLASS__, 0), 'depuis' => (int)config::byKey('mode_depuis', __CLASS__, 0), 'blanc' => self::blanc() ? 1 : 0,
      'sans_on' => (int)config::byKey('blanc_sans_on', __CLASS__, 0), 'tourne' => self::tourne() ? 1 : 0, 'fiable' => self::FIABLE,
      'sources' => array_values(array_map(fn($s) => ['nom' => $s['nom'], 'type' => $s['type'], 'src' => $s['src']], $C['sources'])),
      'volets' => $vol, 'allumes' => array_map('strval', array_keys(array_filter($E0['g'] ?? [], fn($e) => !empty($e['on'])))),
      'journal' => array_values(array_filter(self::journal(), fn($x) => $x['t'] >= time() - 86400))];
  }

  /* Purge : au-delà de « conserver » jours, seul le dernier état connu reste, ramené à la limite
     (sinon un « On » purgé laisserait un « Off » seul : absence ou allumage perdus). */
  public static function purger($jours) {
    $lim = time() - 86400 * $jours;
    foreach (array_column(DB::Prepare('SELECT DISTINCT cmd_id FROM ' . self::TABLE, [], DB::FETCH_TYPE_ALL) ?: [], 'cmd_id') as $id) { // toutes : aussi les restes d'une observation finie
      $d = DB::Prepare('SELECT id FROM ' . self::TABLE . ' WHERE cmd_id = ? AND ts < ? ORDER BY ts DESC, id DESC LIMIT 1', [$id, $lim], DB::FETCH_TYPE_ROW);
      if (!is_array($d)) continue;
      DB::Prepare('DELETE FROM ' . self::TABLE . ' WHERE cmd_id = ? AND ts < ? AND id <> ?', [$id, $lim, $d['id']], DB::FETCH_TYPE_ROW);
      DB::Prepare('UPDATE ' . self::TABLE . ' SET ts = ? WHERE id = ?', [$lim, $d['id']], DB::FETCH_TYPE_ROW);
    }
    foreach (['arrets', 'simulation'] as $cle) {
      $p = array_values(array_filter(self::arrets($cle), fn($a) => $a[1] >= $lim));
      if ($p != self::arrets($cle)) config::save($cle, json_encode($p), __CLASS__);
    }
  }

  // Jours en réglage : vide, nul ou illisible = 90 (« trois mois » ne doit pas purger à 7 jours)
  public static function jours($cle) {
    return ($j = (int)config::byKey($cle, __CLASS__, 90)) > 0 ? $j : 90;
  }

  /* Box ou Jeedom arrêté : rien n'est relevé, les lampes paraîtraient éteintes. Un trou de plus de 3 h entre deux passages est exclu.
     ponytail: 3 h = deux cronHourly manqués ; un arrêt plus court n'est pas vu */
  public static function cronHourly() {
    try {
      self::finObservation();
      $vu = (int)config::byKey('vu', __CLASS__, 0);
      if ($vu && time() - $vu > 3 * 3600) self::exclure($vu, time());
      config::save('vu', time(), __CLASS__);
      self::majWidget();
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
    if (!in_array($this->getLogicalId(), ['recalculer', 'on', 'off'])) return;
    // administrateur, ou scénario / cron (sans session) ; pas un compte limité ni l'API (habitudes = heures où la maison est vide)
    if (php_sapi_name() != 'cli' && !isConnect('admin')) throw new Exception(__('Réservé aux administrateurs', __FILE__));
    if ($this->getLogicalId() != 'recalculer') return presencehabituelle::simulation($this->getLogicalId() == 'on');
    $f = presencehabituelle::fichier(); clearstatcache(true, $f);
    if (is_file($f) && time() - filemtime($f) < 60) // ponytail: une minute entre deux calculs, contre les clics répétés ; verrou si ça ne suffit pas
      throw new Exception(__('Tendances déjà calculées il y a moins d\'une minute', __FILE__));
    presencehabituelle::analyser(); // met aussi le widget à jour
  }
}

<?php
try {
  require_once __DIR__ . '/../../../../core/php/core.inc.php';
  include_file('core', 'authentification', 'php');
  if (!isConnect('admin')) throw new Exception(__('401 - Accès non autorisé', __FILE__)); // habitudes de la maison : administrateurs seulement
  require_once __DIR__ . '/../class/presencehabituelle.class.php';
  ajax::init();
  $P = 'presencehabituelle';

  if (init('action') == 'liste') {
    $x = (int)config::byKey('exclure_cmd', $P, 0);
    ajax::success(['candidats' => $P::candidats(), 'suivi' => (object)$P::suivi(), 'exclure' => $x ? cmd::cmdToHumanReadable('#' . $x . '#') : '',
      'exclusions' => config::byKey('exclusions', $P, ''),
      'jamais' => config::byKey('suivi', $P, '') === '', // jamais enregistré : la page préconise
      'precedent' => $P::precedent(), 'observation' => $P::observes() ? $P::finObservee() : 0,
      'reponses' => (object)$P::cfg('reponses'), 'regles' => (object)$P::cfg('regles'), 'detecteurs' => $P::detecteursLus(), 'donnees' => (object)$P::donnees(), 'figes' => $P::figes(), 'fiable' => $P::FIABLE]);
  }
  if (init('action') == 'decouverte') ajax::success($P::decouverte(init('detecteurs') === '' ? null : json_decode((string)init('detecteurs'), true)));
  if (init('action') == 'historiques') { $ids = json_decode((string)init('ids'), true); $ids = is_array($ids) ? $ids : []; ajax::success(['h' => (object)$P::historiques($ids), 's' => (object)$P::scenarios($ids)]); }
  if (init('action') == 'observer') {
    $ids = json_decode((string)init('ids'), true);
    ajax::success($P::observer(is_array($ids) ? $ids : []));
  }
  if (init('action') == 'enregistrer') {
    $s = json_decode((string)init('suivi'), true);
    if (!is_array($s)) throw new Exception(__('Choix des commandes illisible : rien n\'a été enregistré', __FILE__)); // sinon tout le suivi serait effacé
    $x = trim((string)init('exclure'));
    if ($x !== '') { // un nom inconnu revient tel quel : ne pas le prendre pour « pas d'exclusion »
      if (!preg_match('/^#(\d+)#$/', trim(cmd::humanReadableToCmd($x)), $m)) throw new Exception(__('Commande d\'absence introuvable', __FILE__) . ' : ' . $x);
      $x = (int)$m[1];
    } else $x = 0;
    ajax::success(['repris' => $P::enregistrer($s, $x, (string)init('exclusions'), init('reponses') === '' ? null : json_decode((string)init('reponses'), true), init('regles') === '' ? null : json_decode((string)init('regles'), true), init('detecteurs') === '' ? null : json_decode((string)init('detecteurs'), true)), 'habitudes' => $P::analyser(), 'precedent' => ($p = $P::precedent()) ? $p['date'] : null,
      'reponses' => (object)$P::cfg('reponses'), 'regles' => (object)$P::cfg('regles'), 'detecteurs' => $P::detecteursLus(), 'donnees' => (object)$P::donnees(), 'figes' => $P::figes()]); // ce que la box a retenu
  }
  if (init('action') == 'habitudes') {
    ajax::success($P::habitudes());
  }
  if (init('action') == 'recalculer') ajax::success($P::analyser());
  if (init('action') == 'plan') ajax::success(['plan' => $P::plan(), 'designs' => $P::designs()]);
  if (init('action') == 'plan_image') ajax::success($P::planImageLire((string)init('etage')));
  if (init('action') == 'plan_image_enregistrer') { $P::planImageEnregistrer((string)init('etage'), (string)init('image')); ajax::success(); }
  if (init('action') == 'plan_enregistrer') {
    $p = json_decode((string)init('plan'), true);
    if (!is_array($p)) throw new Exception(__('Plan illisible : rien n\'a été enregistré', __FILE__));
    ajax::success(['plan' => $P::planEnregistrer($p), 'habitudes' => $P::analyser()]); // pièces et zones changent les groupes
  }
  if (init('action') == 'plan_design') ajax::success($P::designPositions(init('design')));
  if (init('action') == 'widget') {
    $w = $P::equipement(); // recréé s'il a été supprimé
    if (init('enregistrer')) {
      $o = (int)init('objet');
      if ($o && !is_object(jeeObject::byId($o))) throw new Exception(__('Objet introuvable', __FILE__));
      $w->setObject_id($o ?: null); $w->setIsVisible(init('visible') ? 1 : 0); $w->save(); // ph:propre
      config::save('widget_pieces', init('pieces') ? 1 : 0, $P);
      if (is_object($wc = $w->getCmd(null, 'maintenant'))) { $wc->setIsVisible(init('pieces') ? 1 : 0); $wc->save(); } // ph:propre
      $P::majWidget();
    }
    ajax::success(['id' => $w->getId(), 'objet' => (int)$w->getObject_id(), 'visible' => (int)$w->getIsVisible(), 'pieces' => (int)config::byKey('widget_pieces', $P, 0),
      'objets' => array_map(fn($x) => ['id' => $x->getId(), 'nom' => $x->getName()], jeeObject::all())]);
  }
  if (init('action') == 'historiques_manquants') ajax::success(['liste' => $P::aHistoriser(), 'avant' => count($P::historiserAvant())]);
  if (init('action') == 'historiser') { $ids = json_decode((string)init('ids'), true); ajax::success($P::historiser(is_array($ids) ? $ids : [])); }
  if (init('action') == 'remettre') ajax::success($P::remettre());
  if (init('action') == 'effacer') { $P::effacer(); $P::demarrer(); ajax::success($P::analyser()); } // état courant noté à nouveau

  throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Throwable $e) {
  ajax::error($e->getMessage(), $e->getCode());
}

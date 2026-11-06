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
      'jamais' => config::byKey('suivi', $P, '') === '']); // jamais enregistré : la page préconise
  }
  if (init('action') == 'enregistrer') {
    $s = json_decode((string)init('suivi'), true);
    if (!is_array($s)) throw new Exception(__('Choix des commandes illisible : rien n\'a été enregistré', __FILE__)); // sinon tout le suivi serait effacé
    $x = trim((string)init('exclure'));
    if ($x !== '') { // un nom inconnu revient tel quel : ne pas le prendre pour « pas d'exclusion »
      if (!preg_match('/^#(\d+)#$/', trim(cmd::humanReadableToCmd($x)), $m)) throw new Exception(__('Commande d\'absence introuvable', __FILE__) . ' : ' . $x);
      $x = (int)$m[1];
    } else $x = 0;
    ajax::success(['repris' => $P::enregistrer($s, $x, (string)init('exclusions')), 'habitudes' => $P::analyser()]);
  }
  if (init('action') == 'habitudes') {
    ajax::success($P::habitudes());
  }
  if (init('action') == 'recalculer') ajax::success($P::analyser());
  if (init('action') == 'effacer') { $P::effacer(); $P::demarrer(); ajax::success($P::analyser()); } // état courant noté à nouveau

  throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Throwable $e) {
  ajax::error($e->getMessage(), $e->getCode());
}

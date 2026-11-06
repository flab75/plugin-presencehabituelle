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
      'exclusions' => config::byKey('exclusions', $P, '')]);
  }
  if (init('action') == 'enregistrer') {
    $s = json_decode((string)init('suivi'), true);
    $x = trim((string)init('exclure'));
    $x = $x === '' ? 0 : (int)trim(cmd::humanReadableToCmd($x), '#');
    ajax::success(['repris' => $P::enregistrer(is_array($s) ? $s : [], $x, (string)init('exclusions')), 'habitudes' => $P::analyser()]);
  }
  if (init('action') == 'habitudes') {
    $h = json_decode((string)config::byKey('habitudes', $P, ''), true);
    ajax::success(is_array($h) ? $h : $P::analyser());
  }
  if (init('action') == 'recalculer') ajax::success($P::analyser());

  throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Throwable $e) {
  ajax::error($e->getMessage(), $e->getCode());
}

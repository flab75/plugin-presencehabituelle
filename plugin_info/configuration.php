<?php
require_once __DIR__ . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Reprendre l'historique sur}} ({{jours}})
        <sup><i class="fas fa-question-circle tooltips" title="{{À l'ajout d'une commande, ses changements d'état sont repris de l'historique de Jeedom sur ce nombre de jours (si la commande est historisée). Par défaut 90.}}"></i></sup>
      </label>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="amorcer" placeholder="90"></div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Conserver les changements}} ({{jours}})
        <sup><i class="fas fa-question-circle tooltips" title="{{Les changements d'état plus anciens sont effacés chaque nuit : les habitudes suivent la saison. Par défaut 90, minimum 7.}}"></i></sup>
      </label>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="conserver" placeholder="90"></div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Allumage le plus long}} ({{heures}})
        <sup><i class="fas fa-question-circle tooltips" title="{{Un allumage plus long est pris pour une extinction manquée (box arrêtée, pont hors ligne) : il est écarté, et les jours qu'il touche ne sont pas comptés pour cette lampe. Par défaut 18.}}"></i></sup>
      </label>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="session_max" placeholder="18"></div>
    </div>
  </fieldset>
</form>

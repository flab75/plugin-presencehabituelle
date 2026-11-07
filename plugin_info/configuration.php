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
        <sup><i class="fas fa-question-circle tooltips" title="{{Un allumage plus long est pris pour une extinction manquée (box arrêtée, pont hors ligne) : il est écarté, et les jours qu'il touche ne sont pas comptés pour son groupe. Par défaut 18.}}"></i></sup>
      </label>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="session_max" placeholder="18"></div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Le week-end commence}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Habitudes et règles « en semaine » / « le week-end » : par défaut du vendredi 16:00 au lundi 04:00 (soirs du vendredi au dimanche, matins du samedi et du dimanche). Adaptez-le à votre rythme (travail le samedi, retour le dimanche midi…), puis Recalculer les tendances.}}"></i></sup>
      </label>
      <div class="col-md-2"><select class="configKey form-control" data-l1key="we_jour_debut"><option value="">{{vendredi (défaut)}}</option><option value="1">{{lundi}}</option><option value="2">{{mardi}}</option><option value="3">{{mercredi}}</option><option value="4">{{jeudi}}</option><option value="5">{{vendredi}}</option><option value="6">{{samedi}}</option><option value="7">{{dimanche}}</option></select></div>
      <div class="col-md-2"><input type="time" class="configKey form-control" data-l1key="we_heure_debut" placeholder="16:00"></div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Le week-end finit}}</label>
      <div class="col-md-2"><select class="configKey form-control" data-l1key="we_jour_fin"><option value="">{{lundi (défaut)}}</option><option value="1">{{lundi}}</option><option value="2">{{mardi}}</option><option value="3">{{mercredi}}</option><option value="4">{{jeudi}}</option><option value="5">{{vendredi}}</option><option value="6">{{samedi}}</option><option value="7">{{dimanche}}</option></select></div>
      <div class="col-md-2"><input type="time" class="configKey form-control" data-l1key="we_heure_fin" placeholder="04:00"></div>
    </div>
  </fieldset>
</form>

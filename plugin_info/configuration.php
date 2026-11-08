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
    <legend>{{Simulation}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Envoyer vraiment aux équipements}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Non coché (défaut) : la simulation est « à blanc », elle décide et note tout au journal de l'onglet Simulation, mais n'allume, n'éteint, n'ouvre ni ne ferme rien. Cochez-la seulement après avoir lu quelques journées à blanc. Décochée pendant une simulation On : les lampes qu'elle a allumées sont éteintes, puis elle continue à blanc ; cochée : elle repart de zéro.}}"></i></sup>
      </label>
      <div class="col-md-2"><input type="checkbox" class="configKey" data-l1key="reel"></div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{À blanc même sans Simulation On}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Pour essayer à la maison : la simulation à blanc tourne en permanence et remplit le journal, sans rien envoyer. Ignoré si « Envoyer vraiment » est coché.}}"></i></sup>
      </label>
      <div class="col-md-2"><input type="checkbox" class="configKey" data-l1key="blanc_sans_on"></div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Pièces allumées en même temps, au plus}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Vide ou 0 : 3.}}"></i></sup>
      </label>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="presences_max" placeholder="3"></div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Pièce changée par quelqu'un : laissée}} ({{minutes}})
        <sup><i class="fas fa-question-circle tooltips" title="{{Envoi réel seulement. Si une lampe suivie est allumée ou éteinte par quelqu'un d'autre pendant la simulation (visiteur, voisin qui arrose), la pièce n'est plus touchée pendant ce temps. Vide ou 0 : 60.}}"></i></sup>
      </label>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="visiteur" placeholder="60"></div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Capteur de luminosité}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Facultatif. Une commande info de luminosité extérieure (lux) : les lampes ne s'allument qu'à la pénombre, entre les deux seuils. Vide : le lever et le coucher du soleil (coordonnées de la configuration de Jeedom).}}"></i></sup>
      </label>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="lux_cmd" placeholder="{{id de la commande (ou #id#)}}"></div>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="lux_bas" placeholder="{{nuit sous 350 lux}}"></div>
      <div class="col-md-2"><input class="configKey form-control" data-l1key="lux_haut" placeholder="{{jour au-dessus de 1000 lux}}"></div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Pièces allumées même de jour}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Noms des pièces (ou groupes) sans fenêtre, séparés par des virgules : la pénombre n'y compte pas.}}"></i></sup>
      </label>
      <div class="col-md-4"><input class="configKey form-control" data-l1key="sans_jour" placeholder="{{Cave, WC}}"></div>
    </div>
  </fieldset>
</form>

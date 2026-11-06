<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('presencehabituelle');
sendVarToJS('eqType', $plugin->getId());
?>
<div id="ph_page" style="padding:10px">
	<div class="alert alert-info">
		<i class="fas fa-user-shield"></i> {{Tout est calculé sur cette box : les habitudes apprises ne sont envoyées nulle part, et seuls les administrateurs les voient. Version 0.1 : le plugin apprend, il n'allume ni ne ferme encore rien.}}
	</div>
	<ul class="nav nav-tabs" role="tablist">
		<li role="presentation" class="active"><a href="#ph_suivi" role="tab" data-toggle="tab"><i class="fas fa-list-check"></i> {{Commandes suivies}}</a></li>
		<li role="presentation"><a href="#ph_hab" role="tab" data-toggle="tab"><i class="fas fa-chart-bar"></i> {{Habitudes}}</a></li>
	</ul>
	<div class="tab-content">
		<div role="tabpanel" class="tab-pane active" id="ph_suivi" style="padding-top:10px">
			<p>{{Cochez les lumières et les volets à apprendre. Les prises et les médias sont proposés décochés : une prise peut alimenter un frigo ou une box, ce n'est pas une présence. Les sondes ne servent pas : elles ne disent rien de vos gestes.}}</p>
			<p>{{Groupe : les lampes d'un même groupe sont apprises ensemble (une pièce). Vide = la lampe seule.}}</p>
			<div style="margin-bottom:8px">
				<a class="btn btn-sm btn-default" id="ph_parObjet"><i class="fas fa-object-group"></i> {{Grouper par objet Jeedom}}</a>
				<a class="btn btn-sm btn-default" id="ph_seules"><i class="fas fa-lightbulb"></i> {{Une lampe par groupe}}</a>
				<label class="checkbox-inline" style="margin-left:10px"><input type="checkbox" id="ph_autres"> {{Afficher les autres commandes (sondes…), déconseillées}}</label>
				<a class="btn btn-sm btn-success pull-right" id="ph_enregistrer"><i class="fas fa-check-circle"></i> {{Enregistrer}}</a>
			</div>
			<div id="ph_liste"></div>
			<legend style="margin-top:15px"><i class="fas fa-plane-departure"></i> {{Périodes à ne pas apprendre}}</legend>
			<p>{{Vos absences (et toute période où une simulation tournait) faussent les habitudes : ces jours-là ne sont pas comptés.}}</p>
			<div class="form-group">
				<label>{{Commande d'absence}} <sup><i class="fas fa-question-circle tooltips" title="{{Commande info vraie pendant une absence (ex. un mode « Absent » ou « Vacances ») : ses périodes actives sont exclues. Facultatif.}}"></i></sup></label>
				<div class="input-group" style="max-width:600px">
					<input class="form-control" id="ph_exclure" placeholder="#[Maison][Mode][Absent]#">
					<span class="input-group-btn"><a class="btn btn-default" id="ph_choisir"><i class="fas fa-list-alt"></i></a></span>
				</div>
			</div>
			<div class="form-group">
				<label>{{Périodes saisies}} <sup><i class="fas fa-question-circle tooltips" title="{{Une par ligne : AAAA-MM-JJ HH:MM → AAAA-MM-JJ HH:MM (l'heure est facultative). Tout jour touché est exclu en entier.}}"></i></sup></label>
				<textarea class="form-control" id="ph_exclusions" rows="3" style="max-width:600px" placeholder="2026-10-15 15:27 → 2026-10-23 12:42"></textarea>
			</div>
		</div>
		<div role="tabpanel" class="tab-pane" id="ph_hab" style="padding-top:10px">
			<a class="btn btn-sm btn-default" id="ph_recalculer"><i class="fas fa-sync"></i> {{Recalculer}}</a>
			<span id="ph_date" style="margin-left:10px"></span>
			<div id="ph_resultats" style="margin-top:10px"></div>
		</div>
	</div>
</div>
<?php include_file('desktop', 'presencehabituelle', 'js', 'presencehabituelle'); ?>

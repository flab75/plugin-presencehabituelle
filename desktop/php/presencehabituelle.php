<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('presencehabituelle');
sendVarToJS('eqType', $plugin->getId());
?>
<div id="ph_page" style="padding:10px">
	<div class="alert alert-info">
		<i class="fas fa-user-shield"></i> {{Tout est calculé sur cette box : le plugin n'envoie rien à personne, et seuls les administrateurs voient les tendances (elles font partie des sauvegardes de Jeedom, comme le reste de la base). Pour l'instant, le plugin tire de vos historiques les tendances, les exceptions et ce qui n'arrive jamais : il n'allume ni ne ferme encore rien.}}
	</div>
	<ul class="nav nav-tabs" role="tablist">
		<li role="presentation" class="active"><a href="#ph_guide" role="tab" data-toggle="tab"><i class="fas fa-route"></i> {{Guide}}</a></li>
		<li role="presentation"><a href="#ph_suivi" role="tab" data-toggle="tab"><i class="fas fa-list-check"></i> {{Commandes suivies}}</a></li>
		<li role="presentation"><a href="#ph_hab" role="tab" data-toggle="tab"><i class="fas fa-chart-bar"></i> {{Tendances}}</a></li>
		<li role="presentation"><a href="#ph_plan" role="tab" data-toggle="tab"><i class="fas fa-map"></i> {{Plan}}</a></li>
	</ul>
	<div class="tab-content">
		<div role="tabpanel" class="tab-pane active" id="ph_guide" style="padding-top:10px">
			<p>{{Le plugin ne sait rien de votre maison : il lit vos équipements et leurs historiques, puis vous pose quatre questions. Vos réponses servent à la future simulation ; pour l'instant, rien n'est allumé ni fermé.}}</p>
			<div id="ph_gcontenu"></div>
			<p style="margin-top:10px"><a class="btn btn-sm btn-success" id="ph_genregistrer"><i class="fas fa-check-circle"></i> {{Enregistrer}}</a> <small class="text-muted">{{Les réponses, les règles et le choix des commandes (onglet suivant) sont enregistrés ensemble.}}</small></p>
		</div>
		<div role="tabpanel" class="tab-pane" id="ph_suivi" style="padding-top:10px">
			<p>{{Cochez les lumières et les volets à analyser. Les prises et les médias sont proposés décochés : une prise peut alimenter un frigo ou une box, ce n'est pas une présence. Les sondes ne servent pas : elles ne disent rien de vos gestes.}}</p>
			<p>{{Groupe : les lampes d'un même groupe sont analysées ensemble (une pièce). Vide = la lampe seule.}}</p>
			<div style="margin-bottom:8px">
				<a class="btn btn-sm btn-default" id="ph_parObjet"><i class="fas fa-object-group"></i> {{Grouper par objet Jeedom}}</a>
				<a class="btn btn-sm btn-default" id="ph_seules"><i class="fas fa-lightbulb"></i> {{Une lampe par groupe}}</a>
				<label class="checkbox-inline" style="margin-left:10px"><input type="checkbox" id="ph_autres"> {{Afficher les autres commandes (sondes…), déconseillées}}</label>
				<a class="btn btn-sm btn-success pull-right" id="ph_enregistrer"><i class="fas fa-check-circle"></i> {{Enregistrer}}</a>
				<a class="btn btn-sm btn-default pull-right" id="ph_precedent" style="display:none;margin-right:5px" title="{{Recharge dans la liste le choix d'avant le dernier enregistrement : rien n'est enregistré tant que vous ne cliquez pas sur Enregistrer}}"><i class="fas fa-undo"></i> {{Revenir au choix d'avant}}</a>
				<a class="btn btn-sm btn-primary pull-right" id="ph_decouvrir" style="margin-right:5px" title="{{Cherche dans l'historique les doublons, les groupes, les lumières sans type générique, les jours sans personne et les lumières automatiques : des propositions, à confirmer}}"><i class="fas fa-search"></i> {{Découvrir}}</a>
			</div>
			<div id="ph_decouverte"></div>
			<div id="ph_liste"></div>
			<legend style="margin-top:15px"><i class="fas fa-plane-departure"></i> {{Périodes à ne pas analyser}}</legend>
			<p>{{Vos absences (et toute période où une simulation tournait) faussent les tendances : ces jours-là ne sont pas comptés.}}</p>
			<div class="form-group">
				<label>{{Commande d'absence}} <sup><i class="fas fa-question-circle tooltips" title="{{Commande info binaire ou numérique, vraie pendant une absence (ex. un virtuel binaire « Absent » ou « Vacances » ; un mode en texte ne convient pas) : ses périodes actives sont exclues. Facultatif.}}"></i></sup></label>
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
			<a class="btn btn-sm btn-danger pull-right" id="ph_effacer"><i class="fas fa-eraser"></i> {{Tout effacer}}</a>
			<span id="ph_date" style="margin-left:10px"></span>
			<div id="ph_resultats" style="margin-top:10px"></div>
		</div>
		<div role="tabpanel" class="tab-pane" id="ph_plan" style="padding-top:10px">
			<p>{{Facultatif. Un plan par étage : déposez-en l'image, placez-y les lumières et les volets suivis, dessinez les pièces (elles groupent les lampes qu'elles contiennent, sauf groupe saisi), les passages entre pièces (portes, couloirs, escaliers, y compris d'un étage à l'autre) et les zones à exclure (ni analysées ni simulées). L'image reste sur la box, visible des administrateurs seulement.}}</p>
			<div class="form-inline" style="margin-bottom:6px">
				<select class="form-control input-sm" id="ph_etage" style="min-width:160px"></select>
				<a class="btn btn-sm btn-default" id="ph_etage_plus" title="{{Ajouter un étage}}"><i class="fas fa-plus"></i> {{Étage}}</a>
				<a class="btn btn-sm btn-default ph_etage_op" id="ph_etage_nom" title="{{Renommer l'étage}}"><i class="fas fa-pen"></i></a>
				<a class="btn btn-sm btn-default ph_etage_op" id="ph_etage_haut" title="{{Monter dans la liste}}"><i class="fas fa-arrow-up"></i></a>
				<a class="btn btn-sm btn-default ph_etage_op" id="ph_etage_bas" title="{{Descendre dans la liste}}"><i class="fas fa-arrow-down"></i></a>
				<label class="btn btn-sm btn-default ph_etage_op" style="margin:0"><i class="fas fa-image"></i> {{Image…}}<input type="file" id="ph_image" accept="image/*" style="display:none"></label>
				<a class="btn btn-sm btn-danger ph_etage_op" id="ph_etage_moins" title="{{Supprimer l'étage}}"><i class="fas fa-trash"></i></a>
				<select class="form-control input-sm ph_etage_op" id="ph_design" style="max-width:280px"></select>
				<a class="btn btn-sm btn-success pull-right" id="ph_plan_enregistrer"><i class="fas fa-check-circle"></i> {{Enregistrer le plan}}</a>
			</div>
			<div class="form-inline" style="margin-bottom:6px">
				<div class="btn-group" id="ph_outils">
					<a class="btn btn-sm btn-primary" data-outil="deplacer"><i class="fas fa-arrows-alt"></i> {{Déplacer}}</a>
					<a class="btn btn-sm btn-default" data-outil="piece"><i class="fas fa-draw-polygon"></i> {{Pièce}}</a>
					<a class="btn btn-sm btn-default" data-outil="passage"><i class="fas fa-door-open"></i> {{Passage}}</a>
					<a class="btn btn-sm btn-default" data-outil="zone"><i class="fas fa-ban"></i> {{Zone à exclure}}</a>
					<a class="btn btn-sm btn-default" data-outil="effacer"><i class="fas fa-eraser"></i> {{Supprimer}}</a>
				</div>
				<a class="btn btn-sm btn-default" id="ph_finir" style="display:none"><i class="fas fa-check"></i> {{Terminer la forme}}</a>
				<span id="ph_aide" style="margin-left:8px"></span>
				<span class="pull-right">{{Tendance à}} <select class="form-control input-sm" id="ph_heure"></select>
					<select class="form-control input-sm" id="ph_jour"><option value="S">{{en semaine}}</option><option value="W">{{le week-end}}</option></select></span>
			</div>
			<div class="row">
				<div class="col-md-9"><div id="ph_carte" style="position:relative;user-select:none;border:1px solid var(--al-border-color,#ccc)"></div></div>
				<div class="col-md-3">
					<legend style="font-size:1em">{{Pas sur le plan}}</legend><div id="ph_hors"></div>
					<legend style="font-size:1em;margin-top:10px">{{Pièces}}</legend><div id="ph_pieces"></div>
					<legend style="font-size:1em;margin-top:10px">{{Passages}}</legend><div id="ph_passages"></div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php include_file('desktop', 'presencehabituelle', 'js', 'presencehabituelle'); ?>

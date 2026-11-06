/* Présence habituelle : choix des commandes suivies et tableau des habitudes */
(function () {
  var D = null // dernière liste reçue
  function esc(s) { return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (x) { return '&#' + x.charCodeAt(0) + ';' }) }
  function alerte(m, n) { $('#div_alert').showAlert({ message: esc(m), level: n || 'danger' }) }
  function ajax(action, champs, ok) {
    var f = new FormData()
    f.append('action', action); f.append('jeedom_token', window.JEEDOM_AJAX_TOKEN || '')
    Object.keys(champs).forEach(function (k) { f.append(k, champs[k]) })
    fetch('plugins/presencehabituelle/core/ajax/presencehabituelle.ajax.php', { method: 'POST', body: f, credentials: 'same-origin' })
      .then(function (r) { return r.json() })
      .then(function (j) { if (j.state == 'ok') ok(j.result); else alerte(j.result) })
      .catch(function (e) { alerte(e instanceof SyntaxError ? "{{Réponse illisible : session expirée ? Recharger la page.}}" : String(e)) })
  }
  var NOMS = { lumiere: '{{lumière}}', volet: '{{volet}}', prise: '{{prise}}', media: '{{média}}', autre: '{{autre}}' }
  function hm(m) { if (m === undefined) return '–'; m = Math.round(m) % 1440; return ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + m % 60).slice(-2) }

  function liste() {
    var autres = $('#ph_autres').is(':checked'), parObjet = {}
    D.candidats.forEach(function (c) {
      var s = D.suivi[c.id]
      if (c.type == 'autre' && !autres && !s) return
      ;(parObjet[c.objet || '{{Sans objet}}'] = parObjet[c.objet || '{{Sans objet}}'] || []).push(c)
    })
    var h = ''
    Object.keys(parObjet).sort().forEach(function (o) {
      h += '<legend style="font-size:1.1em;margin:10px 0 4px">' + esc(o) + '</legend><table class="table table-condensed" style="margin-bottom:0"><tbody>'
      parObjet[o].forEach(function (c) {
        var s = D.suivi[c.id]
        h += '<tr data-id="' + esc(c.id) + '" data-type="' + esc(c.type) + '" data-objet="' + esc(c.objet) + '"' + (c.actif ? '' : ' style="opacity:.5"') + '>'
          + '<td style="width:30px"><input type="checkbox" class="ph_coche"' + (s ? ' checked' : '') + '></td>'
          + '<td>' + esc(c.eq) + ' <small>' + esc(c.nom) + '</small></td>'
          + '<td style="width:110px">' + esc(NOMS[c.type]) + (c.type == 'autre' ? ' <i class="fas fa-exclamation-triangle text-warning" title="{{Déconseillé : pas un geste de présence}}"></i>' : '') + '</td>'
          + '<td style="width:150px">' + (c.hist ? '<small>{{historisée}}</small>' : '<small class="text-warning" title="{{Rien à reprendre : apprentissage à partir de l\'enregistrement}}">{{non historisée}}</small>') + '</td>'
          + '<td style="width:220px"><input class="form-control input-sm ph_groupe" placeholder="{{lampe seule}}" value="' + esc(s ? s.g : '') + '"></td></tr>'
      })
      h += '</tbody></table>'
    })
    $('#ph_liste').html(h || '<div class="alert alert-warning">{{Aucune lumière ni aucun volet trouvé : renseignez le type générique de leurs commandes d\'état, ou affichez les autres commandes.}}</div>')
  }

  function charger() {
    ajax('liste', {}, function (r) {
      D = r
      if (!Object.keys(D.suivi).length) D.candidats.forEach(function (c) { if (c.coche && c.actif) D.suivi[c.id] = { g: '', t: c.type } }) // première fois : préconisation
      $('#ph_exclure').val(r.exclure); $('#ph_exclusions').val(r.exclusions)
      liste()
    })
  }
  function lire() { // reporte les cases et les groupes de l'écran dans D.suivi
    $('#ph_liste tr[data-id]').each(function () {
      var id = $(this).data('id')
      if ($(this).find('.ph_coche').is(':checked')) D.suivi[id] = { g: $(this).find('.ph_groupe').val().trim(), t: $(this).data('type') }
      else delete D.suivi[id]
    })
  }

  function profil(g, k) {
    var h = '<tr><th style="width:90px">' + (k == 'S' ? '{{Semaine}}' : '{{Week-end}}') + ' <small>(' + esc(g['n' + k]) + ' j)</small></th>'
    g['l' + k].forEach(function (v, i) {
      h += '<td title="' + i + ' h : ' + esc(g[k][i]) + ' % {{des jours}} ({{lissé}} ' + esc(v) + ' %)" style="text-align:center;padding:2px;background:rgba(240,173,78,' + (v / 100) + ')">' + (v ? esc(v) : '') + '</td>'
    })
    return h + '</tr>'
  }
  function habitudes(r) {
    $('#ph_date').text(r.date ? '{{Calculé le}} ' + r.date : '')
    var h = ''
    ;(r.groupes || []).forEach(function (g) {
      h += '<legend style="font-size:1.1em">' + esc(g.nom) + (g.confirmer ? ' <span class="label label-warning">{{à confirmer}}</span>' : '') + '</legend>'
      if (!g.jours) { h += '<p>{{Pas encore assez de données (un jour entier au moins).}}</p>'; return }
      if (g.lampes.length > 1) h += '<p><small>' + esc(g.lampes.join(', ')) + '</small></p>'
      h += '<table class="table table-condensed table-bordered" style="table-layout:fixed;font-size:.85em"><tr><th></th>'
      for (var i = 0; i < 24; i++) h += '<th style="text-align:center;padding:2px">' + i + '</th>'
      h += '</tr>' + profil(g, 'S') + profil(g, 'W') + '</table>'
      var d = g.durees
      h += '<p>' + (d.n ? '{{Durée allumée}} : {{médiane}} ' + esc(d.med) + ' min ({{la moitié entre}} ' + esc(d.q25) + ' {{et}} ' + esc(d.q75) + ' min), '
        + esc(d.passages) + ' % {{de passages de moins de 3 min}} (' + esc(d.n) + ' {{allumages}})' : '{{Durées : pas d\'allumage daté à la seconde (historique moyenné seulement).}}') + '</p>'
      var sc = Object.keys(g.scenes || {})
      if (sc.length) h += '<p>{{Allumées ensemble}} : ' + sc.slice(0, 5).map(function (k) { return esc(k) + ' (' + esc(g.scenes[k]) + ' %)' }).join(' ; ') + '</p>'
    })
    if ((r.volets || []).length) {
      h += '<legend style="font-size:1.1em">{{Volets}}</legend><table class="table table-condensed table-bordered"><tr><th>{{Volet}}</th><th>{{Ouverture semaine}}</th><th>{{Ouverture week-end}}</th><th>{{Fermeture semaine}}</th><th>{{Fermeture week-end}}</th></tr>'
      r.volets.forEach(function (v) {
        function c(x, soir) {
          if (!x || !x.n) return '<td>–</td>'
          return '<td>' + hm(x.med) + ' <small>(' + hm(x.q25) + '–' + hm(x.q75) + ', ' + esc(x.n) + ' j' + (soir ? ', {{fermé}} ' + esc(x.ferme) + ' % {{des soirs}}' : '') + ')</small></td>'
        }
        h += '<tr><td>' + esc(v.nom) + (v.confirmer ? ' <span class="label label-warning">{{à confirmer}}</span>' : '') + '</td>'
          + (v.jours ? c(v.matin.S) + c(v.matin.W) + c(v.soir.S, 1) + c(v.soir.W, 1) : '<td colspan="4">{{Pas encore assez de données.}}</td>') + '</tr>'
      })
      h += '</table><p><small>{{Week-end : matins du samedi et du dimanche, soirs du vendredi et du samedi. Une fermeture partielle (contre le soleil) ne compte pas.}}</small></p>'
    }
    $('#ph_resultats').html(h || '<p>{{Aucune commande suivie.}}</p>')
  }

  $('#ph_autres').on('change', function () { lire(); liste() })
  $('#ph_parObjet').on('click', function () { $('#ph_liste tr[data-id]').each(function () { if ($(this).find('.ph_coche').is(':checked')) $(this).find('.ph_groupe').val($(this).data('objet')) }) })
  $('#ph_seules').on('click', function () { $('#ph_liste .ph_groupe').val('') })
  $('#ph_choisir').on('click', function () { jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (r) { $('#ph_exclure').val(r.human) }) })
  $('#ph_enregistrer').on('click', function () {
    lire()
    ajax('enregistrer', { suivi: JSON.stringify(D.suivi), exclure: $('#ph_exclure').val(), exclusions: $('#ph_exclusions').val() }, function (r) {
      alerte('{{Enregistré}} : ' + r.repris + ' {{changements repris de l\'historique}}', 'success')
      habitudes(r.habitudes)
    })
  })
  $('#ph_recalculer').on('click', function () { ajax('recalculer', {}, habitudes) })
  charger()
  ajax('habitudes', {}, habitudes)
})()

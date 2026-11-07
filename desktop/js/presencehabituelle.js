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
  function jj(d) { return String(d).slice(8, 10) + '/' + String(d).slice(5, 7) + String(d).slice(10, 16) } // AAAA-MM-JJ[ HH:MM[:SS]] → JJ/MM[ HH:MM]
  function plages(hs) { // [3, 4, 5, 9] → « 03 h – 06 h, 09 h – 10 h »
    var r = [], a = null
    for (var h = 0; h <= 24; h++) {
      if (hs.indexOf(h) >= 0) { if (a === null) a = h } else if (a !== null) { r.push(('0' + a).slice(-2) + ' h – ' + ('0' + h % 24).slice(-2) + ' h'); a = null }
    }
    return r.join(', ')
  }
  function exc(ex, f) { var k = Object.keys(ex || {}).sort(); return k.slice(-8).map(function (d) { return esc(jj(d)) + ' (' + f(ex[d]) + ')' }).join(' ; ') + (k.length > 8 ? ' …' : '') }
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
          + '<td style="width:150px">' + (!c.hist ? '<small class="text-warning" title="{{Rien à reprendre : l’analyse part de l’enregistrement}}">{{non historisée}}</small>'
            : c.lisse ? '<small class="text-warning" title="{{Jeedom remplace ses valeurs par une moyenne horaire après quelques heures : seules les dernières heures sont reprises. Réglez son lissage sur « Aucun » (configuration de la commande, onglet Historique).}}">{{historique lissé}}</small>'
            : '<small>{{historisée}}</small>') + '</td>'
          + '<td style="width:220px"><input class="form-control input-sm ph_groupe" placeholder="{{lampe seule}}" value="' + esc(s ? s.g : '') + '"></td></tr>'
      })
      h += '</tbody></table>'
    })
    $('#ph_liste').html(h || '<div class="alert alert-warning">{{Aucune lumière ni aucun volet trouvé : renseignez le type générique de leurs commandes d’état, ou affichez les autres commandes.}}</div>')
  }

  function charger() {
    ajax('liste', {}, function (r) {
      D = r
      if (r.jamais) D.candidats.forEach(function (c) { if (c.coche && c.actif) D.suivi[c.id] = { g: '', t: c.type } }) // première fois : préconisation
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
    var h = '<tr><th style="width:90px">' + (k == 'S' ? '{{Semaine}}' : '{{Week-end}}') + ' <small>(' + esc(g['n' + k]) + ' j)</small>'
      + ((g['confirmer' + k] === undefined ? g.confirmer : g['confirmer' + k]) ? ' <span class="label label-warning">{{à confirmer}}</span>' : '') + '</th>'
    g['l' + k].forEach(function (v, i) {
      h += '<td title="' + i + ' h : ' + esc(g[k][i]) + ' % {{des jours}} ({{lissé}} ' + esc(v) + ' %)" style="text-align:center;padding:2px;background:rgba(240,173,78,' + (v / 100) + ')">' + (v ? esc(v) : '') + '</td>'
    })
    return h + '</tr>'
  }
  function habitudes(r) {
    $('#ph_date').text(r.date ? '{{Calculé le}} ' + r.date : '')
    var h = ''
    ;(r.groupes || []).forEach(function (g) {
      h += '<legend style="font-size:1.1em">' + esc(g.nom) + '</legend>'
      if (!g.jours) { h += '<p>{{Pas encore assez de données (un jour entier au moins).}}</p>'; return }
      if (g.lampes.length > 1) h += '<p><small>' + esc(g.lampes.join(', ')) + '</small></p>'
      h += '<table class="table table-condensed table-bordered" style="table-layout:fixed;font-size:.85em"><tr><th></th>'
      for (var i = 0; i < 24; i++) h += '<th style="text-align:center;padding:2px">' + i + '</th>'
      h += '</tr>' + profil(g, 'S') + profil(g, 'W') + '</table>'
      var jamais = []
      for (var i = 0; i < 24; i++) if (!(g.cS || g.S)[i] && !(g.cW || g.W)[i]) jamais.push(i) // comptes bruts (habitudes.json d’avant 0.4 : %) : un allumage sur 300 jours arrondit à 0 %
      h += '<p>' + (jamais.length ? '<b>{{Jamais allumée}}</b> : ' + esc(plages(jamais)) : '{{Allumée au moins une fois à chaque heure.}}') + '</p>'
      if ((g.ex || []).length) h += '<p><b>{{Exceptions}}</b> <small>({{heures où elle l’est au plus 10 % des jours}})</small> : '
        + g.ex.slice(-8).map(function (e) { return esc(jj(e[0])) + ' ' + esc(e[1]) + ' h' }).join(' ; ') + (g.ex.length > 8 ? ' …' : '') + '</p>'
      var d = g.durees
      h += '<p>' + (d.n ? '<b>{{Durée allumée}}</b> : {{moyenne}} ' + esc(d.moy) + ' min, {{médiane}} ' + esc(d.med) + ' min ({{la moitié entre}} ' + esc(d.q25) + ' {{et}} ' + esc(d.q75) + ' min), '
        + '{{de}} ' + esc(d.min) + ' {{à}} ' + esc(d.max) + ' min ; ' + esc(d.passages) + ' % {{de passages de moins de 3 min}} (' + esc(d.n) + ' {{allumages}})' : '{{Durées : pas encore d’allumage terminé.}}') + '</p>'
      if (d.n && Object.keys(d.ex || {}).length) h += '<p><b>{{Durées inhabituelles}}</b> : ' + exc(d.ex, function (v) { return esc(v) + ' min' }) + '</p>'
      var sc = Object.keys(g.scenes || {})
      if (sc.length) h += '<p>{{Allumées ensemble}} : ' + sc.slice(0, 5).map(function (k) { return esc(k) + ' (' + esc(g.scenes[k]) + ' %)' }).join(' ; ') + '</p>'
    })
    if ((r.volets || []).length) {
      h += '<legend style="font-size:1.1em">{{Volets}}</legend><table class="table table-condensed table-bordered"><tr><th>{{Volet}}</th><th>{{Ouverture semaine}}</th><th>{{Ouverture week-end}}</th><th>{{Fermeture semaine}}</th><th>{{Fermeture week-end}}</th></tr>'
      var ex = []
      r.volets.forEach(function (v) {
        function c(x, soir) {
          var f = !soir || !x || x.ferme === null || x.ferme === undefined ? '' : '<br><small>' + ((x.nuits !== undefined ? x.fermees == x.nuits : x.ferme >= 100) ? '{{toujours fermé la nuit}}' : (x.nuits !== undefined ? !x.fermees : !x.ferme) ? '{{jamais fermé la nuit}}' : '{{fermé}} ' + esc(x.ferme) + ' % {{des nuits}}') + (x.confirmerF ? ' <span class="label label-warning">{{à confirmer}}</span>' : '') + '</small>'
          var cf = x && x.confirmer ? ' <span class="label label-warning">{{à confirmer}}</span>' : ''
          if (!x || !x.n) return '<td>–' + cf + f + '</td>'
          return '<td>' + cf.slice(1) + (cf ? '<br>' : '') + '{{médiane}} ' + hm(x.med) + ', {{moyenne}} ' + hm(x.moy) + '<br><small>{{au plus tôt}} ' + hm(x.min) + ', {{au plus tard}} ' + hm(x.max) + ' (' + esc(x.n) + ' j)</small>' + f + '</td>'
        }
        ;[['matin', '{{ouverture}}'], ['soir', '{{fermeture}}']].forEach(function (m) {
          ;['S', 'W'].forEach(function (k) { var x = v[m[0]] && v[m[0]][k]; if (x && Object.keys(x.ex || {}).length) ex.push(esc(v.nom) + ', ' + m[1] + ' : ' + exc(x.ex, hm)) })
        })
        h += '<tr><td>' + esc(v.nom) + (v.confirmer ? ' <span class="label label-warning">{{à confirmer}}</span>' : '') + '</td>' // v.confirmer : habitudes.json d’avant 0.5
          + (v.jours ? c(v.matin.S) + c(v.matin.W) + c(v.soir.S, 1) + c(v.soir.W, 1) : '<td colspan="4">{{Pas encore assez de données.}}</td>') + '</tr>'
      })
      h += '</table>' + (ex.length ? '<p><b>{{Exceptions}}</b> :<br>' + ex.join('<br>') + '</p>' : '') + '<p><small>{{Exceptions : jours loin de la moitié centrale, de plus de 30 min et de plus de 1,5 fois son étendue (le plus grand des deux).}} {{Ouverture du matin : seulement si le volet était fermé à 04 h. Une fermeture partielle (contre le soleil) ne compte pas.}}</small></p>'
    }
    if (h) h += '<p><small>{{Journée de 04 h à 04 h. Week-end : matins (04 h-16 h) du samedi et du dimanche, soirs (16 h-04 h) du vendredi et du samedi.}}</small></p>'
    $('#ph_resultats').html(h || '<p>{{Aucune commande suivie.}}</p>')
  }

  $('#ph_autres').on('change', function () { lire(); liste() })
  $('#ph_parObjet').on('click', function () { $('#ph_liste tr[data-id]').each(function () { if ($(this).find('.ph_coche').is(':checked')) $(this).find('.ph_groupe').val($(this).attr('data-objet')) }) })
  $('#ph_seules').on('click', function () { $('#ph_liste .ph_groupe').val('') })
  $('#ph_choisir').on('click', function () { jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (r) { $('#ph_exclure').val(r.human) }) })
  $('#ph_enregistrer').on('click', function () {
    lire()
    if (!D) return // liste pas encore chargée (ou en erreur) : rien à enregistrer
    ajax('enregistrer', { suivi: JSON.stringify(D.suivi), exclure: $('#ph_exclure').val(), exclusions: $('#ph_exclusions').val() }, function (r) {
      alerte('{{Enregistré}} : ' + r.repris + ' {{changements repris de l’historique}}', 'success')
      habitudes(r.habitudes)
    })
  })
  $('#ph_recalculer').on('click', function () { ajax('recalculer', {}, habitudes) })
  $('#ph_effacer').on('click', function () {
    bootbox.confirm('{{Effacer les changements d’état relevés et les tendances calculées ? Le choix des commandes est gardé ; l’historique de Jeedom n’est pas repris.}}', function (oui) {
      if (oui) ajax('effacer', {}, function (r) { habitudes(r); alerte('{{Relevés effacés}}', 'success') })
    })
  })
  charger()
  ajax('habitudes', {}, habitudes)
})()

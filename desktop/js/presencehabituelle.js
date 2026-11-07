/* Présence habituelle : choix des commandes suivies et tableau des habitudes */
(function () {
  var D = null // dernière liste reçue
  var SAUVE = {} // commandes suivies sur la box (dernier enregistrement)
  var NOTES = {}, R = null // remarques de la découverte par commande, dernière découverte reçue
  function esc(s) { return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (x) { return '&#' + x.charCodeAt(0) + ';' }) }
  function alerte(m, n) { $('#div_alert').showAlert({ message: esc(m), level: n || 'danger' }) }
  function ajax(action, champs, ok, ko) {
    var f = new FormData()
    f.append('action', action); f.append('jeedom_token', window.JEEDOM_AJAX_TOKEN || '')
    Object.keys(champs).forEach(function (k) { f.append(k, champs[k]) })
    fetch('plugins/presencehabituelle/core/ajax/presencehabituelle.ajax.php', { method: 'POST', body: f, credentials: 'same-origin' })
      .then(function (r) { return r.json() })
      .then(function (j) { if (j.state == 'ok') ok(j.result); else { alerte(j.result); if (ko) ko() } })
      .catch(function (e) { alerte(e instanceof SyntaxError ? "{{Réponse illisible : session expirée ? Recharger la page.}}" : String(e)); if (ko) ko() })
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
      if (c.type == 'autre' && !autres && !s && !NOTES[c.id]) return
      ;(parObjet[c.objet || '{{Sans objet}}'] = parObjet[c.objet || '{{Sans objet}}'] || []).push(c)
    })
    var h = ''
    Object.keys(parObjet).sort().forEach(function (o) {
      h += '<legend style="font-size:1.1em;margin:10px 0 4px">' + esc(o) + '</legend><table class="table table-condensed" style="margin-bottom:0"><tbody>'
      parObjet[o].forEach(function (c) {
        var s = D.suivi[c.id]
        h += '<tr data-id="' + esc(c.id) + '" data-type="' + esc(c.type) + '" data-objet="' + esc(c.objet) + '"' + (c.actif ? '' : ' style="opacity:.5"') + '>'
          + '<td style="width:30px"><input type="checkbox" class="ph_coche"' + (s ? ' checked' : '') + '></td>'
          + '<td>' + esc(c.eq) + ' <small>' + esc(c.nom) + '</small>' + (NOTES[c.id] ? ' <span class="label label-info">' + esc(NOTES[c.id]) + '</span>' : '') + '</td>'
          + '<td style="width:110px">' + esc(NOMS[c.type]) + (c.type == 'autre' ? ' <i class="fas fa-exclamation-triangle text-warning" title="{{Déconseillé : pas un geste de présence}}"></i>' : '') + '</td>'
          + '<td style="width:150px">' + (!c.hist ? '<small class="text-warning" title="{{Rien à reprendre : l’analyse part de l’enregistrement}}">{{non historisée}}</small>'
            : c.lisse ? '<small class="text-warning" title="{{Jeedom remplace ses valeurs par une moyenne horaire après quelques heures : seules les dernières heures sont reprises. Réglez son lissage sur « Aucun » (configuration de la commande, onglet Historique).}}">{{historique lissé}}</small>'
            : '<small>{{historisée}}</small>') + '</td>'
          + '<td style="width:220px"><input class="form-control input-sm ph_groupe" placeholder="{{lampe seule}}" value="' + esc(s ? s.g : '') + '"></td></tr>'
      })
      h += '</tbody></table>'
    })
    $('#ph_liste').html(h || '<div class="alert alert-warning">{{Aucune lumière ni aucun volet trouvé : renseignez le type générique de leurs commandes d’état, ou affichez les autres commandes.}}</div>')
    guide()
  }

  function charger() {
    ajax('liste', {}, function (r) {
      D = r; SAUVE = $.extend({}, r.suivi); REP = $.extend({}, r.reponses); REG = $.extend(true, {}, r.regles)
      D.candidats.forEach(function (c) { c.type0 = c.type }); SENS = {} // rendu sur « Non » après un « Oui »
      if (r.jamais) D.candidats.forEach(function (c) { if (c.coche && c.actif) D.suivi[c.id] = { g: '', t: c.type } }) // première fois : préconisation
      $('#ph_exclure').val(r.exclure); $('#ph_exclusions').val(r.exclusions)
      $('#ph_precedent').toggle(!!r.precedent).attr('data-date', r.precedent ? r.precedent.date : '')
      liste(); dessiner()
    })
  }

  // Découverte : propositions cochées = acceptées ; « Appliquer » les reporte dans la liste, « Enregistrer » confirme
  function decouverte(r) {
    R = r
    var h = ''
    function bloc(titre, aide, l) { return l ? '<p style="margin:8px 0 2px"><b>' + titre + '</b> <small class="text-muted">' + aide + '</small></p><ul class="list-unstyled" style="margin-left:10px">' + l + '</ul>' : '' }
    function cb(k, v, txt, oui) { return '<li><label style="font-weight:normal;margin:0"><input type="checkbox" class="ph_dec" data-k="' + k + '" data-v="' + esc(v) + '"' + (oui ? ' checked' : '') + '> ' + txt + '</label></li>' }
    h += bloc('{{Doublons}}', '{{même lampe vue par deux commandes (ou deux lampes toujours commandées ensemble) : cochée = décochée de la liste}}',
      r.doublons.map(function (d) { return cb('doublon', d.id, esc(d.nom) + ' — {{doublon de}} ' + esc(d.nomDe), true) }).join(''))
    h += bloc('{{Groupes}}', '{{allumé seulement quand ses lampes le sont : cochée = décochée de la liste}}',
      r.groupes.map(function (g) { return cb('groupe', g.id, esc(g.nom) + ' — {{groupe de}} ' + esc(g.membres.join(', ')), true) }).join(''))
    h += bloc('{{Lumières sans type générique}}', '{{d’après leur nom : montrées dans la liste comme lumières, décochées}}',
      r.nommees.map(function (n) { return cb('lumiere', n.id, esc(n.nom) + ' — {{type générique à renseigner ?}}', true) }).join(''))
    h += bloc('{{Volets sans type générique}}', '{{d’après leur nom : montrés dans la liste comme volets, décochés}}',
      (r.volets || []).map(function (n) { return cb('volet', n.id, esc(n.nom) + ' — {{type générique à renseigner ?}}', true) }).join(''))
    h += bloc('{{Jours sans aucune lumière}}', '{{absences possibles (ou journées sans lumière, en été) : à cocher si vous étiez absent ; cochée = ajoutée aux périodes à ne pas analyser}}',
      r.absences.map(function (p) { return cb('absence', p, esc(p), false) }).join(''))
    h += bloc('{{Commande d’absence possible}}', '{{à cocher si elle est vraie pendant vos absences (une alarme activée l’est souvent aussi la nuit)}}',
      r.absence.map(function (a) { return cb('exclure', a.h, esc(a.nom) + (a.alarme ? ' <small>({{alarme activée}})</small>' : ''), false) }).join(''))
    h += bloc('{{Lumières automatiques ?}}', '{{signalées seulement : à décocher vous-même si un programme les allume}}',
      r.automatiques.map(function (a) {
        return '<li>' + esc(a.nom) + ' — ' + (a.heure !== undefined ? '{{s’allume à heure fixe ?}} (' + hm(a.heure) + ')' : '{{s’allume au coucher du soleil ?}} (' + (a.soleil >= 0 ? '+' : '') + a.soleil + ' {{min}})') + '</li>'
      }).join(''))
    h += bloc('{{Historique lissé}}', '{{Jeedom n’en garde qu’une moyenne horaire : rien à comparer. Réglez leur lissage sur « Aucun » (configuration de la commande, onglet Historique), ou observez-les}}',
      r.lisses.map(function (x) { return '<li>' + esc(x.nom) + '</li>' }).join(''))
    if (r.observation) h += '<p class="text-info" style="margin-top:8px"><i class="fas fa-hourglass-half"></i> {{Observation des commandes sans historique jusqu’au}} ' + esc(new Date(r.observation * 1000).toLocaleString()) + ' : {{relancez la découverte ensuite (relevés gardés 7 jours après).}}</p>'
    else if (r.sans.length || r.lisses.length) h += bloc('{{Sans historique}}', '{{rien à comparer : le plugin peut relever leurs changements quelques jours, puis relancez la découverte}}',
      (r.sans.length ? '<li>' + r.sans.map(function (x) { return esc(x.nom) }).join(', ') + '</li>' : '') + '<li style="margin-top:4px"><a class="btn btn-xs btn-default" id="ph_observer"><i class="fas fa-eye"></i> {{Observer 3 jours}}</a></li>')
    $('#ph_decouverte').html('<div class="panel panel-info"><div class="panel-heading"><i class="fas fa-search"></i> {{Découverte}} <small>({{sur les}} ' + esc(r.jours) + ' {{derniers jours}})</small>'
      + '<a class="close" id="ph_dec_fermer" style="margin-top:-2px" aria-label="{{Fermer}}">&times;</a></div><div class="panel-body">'
      + (h || '<p>{{Rien à signaler.}}</p>')
      + '<p style="margin-top:10px"><a class="btn btn-sm btn-primary" id="ph_appliquer"><i class="fas fa-arrow-down"></i> {{Appliquer à la liste}}</a> <small class="text-muted">{{Rien n’est enregistré tant que vous ne cliquez pas sur Enregistrer.}}</small></p></div></div>')
  }
  function appliquer() {
    if (!R || !D) return
    lire()
    var parId = {}; D.candidats.forEach(function (c) { parId[c.id] = c })
    R.automatiques.forEach(function (a) { NOTES[a.id] = a.heure !== undefined ? '{{s’allume à heure fixe ?}}' : '{{s’allume au coucher du soleil ?}}' })
    var exc = $('#ph_exclusions').val()
    $('#ph_decouverte .ph_dec:checked').each(function () {
      var k = $(this).attr('data-k'), v = $(this).attr('data-v')
      if (k == 'doublon') { var d = R.doublons.filter(function (x) { return x.id == v })[0]; if (repondre(v, 'doublon', String(d.de))) NOTES[v] = '{{doublon de}} ' + d.nomDe }
      if (k == 'groupe') { var g = R.groupes.filter(function (x) { return x.id == v })[0]; garder(g.ids, D.suivi[v]); g.ids.forEach(function (i) { if (D.suivi[i] && !REP[i]) REP[i] = { r: 'oui' } }); repondre(v, 'groupe'); NOTES[v] = '{{groupe de}} ' + g.membres.join(', ') }
      if (k == 'lumiere' && parId[v]) { parId[v].type = 'lumiere'; NOTES[v] = '{{type générique à renseigner ?}}' }
      if (k == 'volet' && parId[v]) { parId[v].type = parId[v].type0 = 'volet'; NOTES[v] = '{{type générique à renseigner ?}}' } // type0 : reste un volet sur « Oui »
      if (k == 'absence' && exc.split('\n').map(function (l) { return l.trim() }).indexOf(v) < 0) exc = (exc.trim() ? exc.trim() + '\n' : '') + v
      if (k == 'exclure') $('#ph_exclure').val(v)
    })
    $('#ph_exclusions').val(exc)
    liste(); dessiner()
    alerte('{{Propositions reportées dans la liste : vérifiez, puis cliquez sur Enregistrer.}}', 'info')
  }
  function garder(ids, s) { // la lampe gardée d'un doublon, les lampes d'un groupe : suivies (avec le groupe saisi de celle qu'elles remplacent)
    ids.forEach(function (id) { if (!D.suivi[id] && cand(id)) D.suivi[id] = { g: s ? s.g : '', t: cand(id).type0 == 'volet' ? 'volet' : 'lumiere' } })
  }
  function cand(id) { return D.candidats.find(function (c) { return c.id == id }) }
  function lire() { // reporte les cases et les groupes de l'écran dans D.suivi
    $('#ph_liste tr[data-id]').each(function () {
      var id = $(this).data('id')
      if ($(this).find('.ph_coche').is(':checked')) D.suivi[id] = { g: $(this).find('.ph_groupe').val().trim(), t: $(this).data('type'), i: id in SENS ? SENS[id] : (D.suivi[id] || {}).i } // i : sens du volet, réglé dans le Guide (gardé si décoché puis recoché)
      else delete D.suivi[id]
    })
  }

  /* ---- Guide : quatre questions, les réponses remplissent le suivi ---- */
  var REP = {}, REG = {}, VU = [], SENS = {} // réponses {id: {r, de}}, règles {id: {jamais, plages}}, changements vus en écoutant
  var JOURS = { tous: '{{tous les jours}}', S: '{{en semaine}}', W: '{{le week-end}}' }
  function aDemander() { // lumières, volets, une prise binaire par équipement, et ce qui est déjà suivi, répondu ou signalé
    var prises = {}
    return D.candidats.filter(function (c) {
      if (D.suivi[c.id] || REP[c.id] || NOTES[c.id] || VU.some(function (v) { return v.id == c.id })) return true
      if (!c.actif) return false
      if (c.type == 'prise') { if (c.sub != 'binary' || prises[c.eqid]) return false; return prises[c.eqid] = true }
      return (c.type == 'lumiere' || c.type == 'volet') && c.coche
    })
  }
  function repondre(id, r, de) { // false : refusée (doublon d'un doublon)
    var c = cand(id), s = D.suivi[id]
    if (!r) { delete REP[id]; return true }
    if (r == 'doublon' && de && REP[de] && REP[de].r == 'doublon') { alerte(libelle(de) + ' {{est déjà un doublon : choisissez la commande gardée.}}', 'warning'); return false }
    REP[id] = r == 'doublon' && de ? { r: r, de: de } : { r: r }
    if (c) c.type = r == 'oui' && c.type0 != 'volet' ? 'lumiere' : c.type0
    if (r == 'oui') D.suivi[id] = { g: s ? s.g : '', t: c ? c.type : 'lumiere', i: s && s.i }
    else {
      delete D.suivi[id]
      Object.keys(REP).forEach(function (y) { // ses doublons : vers la commande gardée à sa place, sinon à revoir
        if (REP[y].de != id) return
        if (r == 'doublon' && de && de != y) REP[y].de = de
        else { delete REP[y]; NOTES[y] = '{{à revoir : la commande gardée n’est plus suivie}}' }
      })
    }
    if (r == 'doublon' && de) { garder([de], s); if (!REP[de] || REP[de].r != 'oui') REP[de] = { r: 'oui' } }
    return true
  }
  function jours(id) { return (D.donnees || {})[id] || 0 }
  var HM = /^([01]\d|2[0-3]):[0-5]\d$/
  function plageOk(p) { return HM.test(p.de) && HM.test(p.a) } // la box écarte les autres
  function marche(id) { return ((REG[id] || {}).plages || []).some(function (p) { return p.k == 'marche' && plageOk(p) }) }
  function etat(id) { // [texte, à compléter]
    var g = REG[id] || {}, n = jours(id), f = D.fiable || 10
    if (g.jamais) return ['{{jamais simulée}}', false]
    if (!REP[id]) return ['{{à confirmer (question 1)}}', true]
    if ((D.figes || []).indexOf(+id) >= 0 && !marche(id)) return ['{{aucun changement relevé : volet sans retour d’état (Somfy RTS…) ? Ses habitudes ne peuvent pas être tirées : donnez-lui une plage « peut s’ouvrir » (question 3)}}', true]
    if (n >= f) return ['{{habitudes}} (' + Math.floor(n) + ' {{j de relevés}})', false]
    if (marche(id)) return ['{{plages données}}', false]
    return ['{{en observation}} (' + Math.floor(n) + ' / ' + f + ' {{j}}) : {{attendez, ou donnez une plage « peut s’allumer »}}', true]
  }
  function plageH(id, i, p) {
    var v = (D.suivi[id] || {}).t == 'volet'
    return '<div class="form-inline ph_plage" style="margin:2px 0"><select class="form-control input-sm ph_pk"><option value="marche">' + (v ? '{{peut s’ouvrir}}' : '{{peut s’allumer}}') + '</option><option value="exclue">' + (v ? '{{jamais ouvert}}' : '{{jamais allumée}}') + '</option></select> '
      + '<select class="form-control input-sm ph_pj">' + Object.keys(JOURS).map(function (k) { return '<option value="' + k + '">' + JOURS[k] + '</option>' }).join('') + '</select> '
      + '{{de}} <input type="time" class="form-control input-sm ph_pde" value="' + esc(p.de) + '"> {{à}} <input type="time" class="form-control input-sm ph_pa" value="' + esc(p.a) + '"> '
      + '<a class="btn btn-xs btn-default ph_pmoins" title="{{Retirer}}"><i class="fas fa-times"></i></a></div>'
  }
  function guide() {
    if (!D) return
    var l = aDemander(), suivis = Object.keys(D.suivi), h = ''
    var opts = function (id) { return l.filter(function (c) { return c.id != id }).map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(libelle(c.id)) + '</option>' }).join('') }
    h += '<legend style="font-size:1.1em">1. {{Est-ce une lampe ou un volet de la maison ?}}</legend>'
      + '<p><small>{{Doublon : la même lampe vue par une autre commande (gardez l’autre). Groupe : une commande qui allume plusieurs lampes déjà listées. Non : une prise de frigo, une LED d’état…}}</small></p>'
      + '<p><a class="btn btn-sm btn-default" id="ph_ecoute"><i class="fas fa-' + (ECOUTE ? 'stop' : 'hand-pointer') + '"></i> ' + (ECOUTE ? '{{Arrêter}}' : '{{Identifier en allumant ou en ouvrant}}') + '</a> '
      + '<small class="text-muted">{{allumez ou éteignez une lampe, ouvrez ou fermez un volet : ce qui change s’affiche ici, avec sa valeur}}</small></p><div id="ph_vus"></div>'
      + '<table class="table table-condensed"><tbody>'
    l.forEach(function (c) {
      var r = REP[c.id] || {}, note = NOTES[c.id] || (D.suivi[c.id] ? '{{suivie}}' : c.coche ? '{{proposée}}' : '')
      h += '<tr data-id="' + esc(c.id) + '"' + (c.actif ? '' : ' style="opacity:.5"') + '><td>' + esc(libelle(c.id)) + ' <small class="text-muted">' + esc(c.objet) + ' · ' + esc(NOMS[c.type]) + '</small>'
        + (note ? ' <span class="label label-info">' + esc(note) + '</span>' : '') + '</td><td style="width:340px" class="form-inline"><select class="form-control input-sm ph_grep">'
        + [['', '{{— à répondre —}}'], ['oui', '{{Oui, à suivre}}'], ['non', '{{Non}}'], ['doublon', '{{Doublon de…}}'], ['groupe', '{{Groupe de lampes}}']].map(function (o) { return '<option value="' + o[0] + '"' + ((r.r || '') == o[0] ? ' selected' : '') + '>' + o[1] + '</option>' }).join('')
        + '</select>' + (r.r == 'doublon' ? ' <select class="form-control input-sm ph_gde"><option value="">{{de…}}</option>' + opts(c.id) + '</select>' : '')
        + (c.type == 'volet' && D.suivi[c.id] ? ' <select class="form-control input-sm ph_ginv" title="{{Ouvrez le volet en identifiant : la valeur affichée est celle de l’ouvert}}"><option value="">{{ouvert = valeur haute}}</option><option value="1"' + (D.suivi[c.id].i ? ' selected' : '') + '>{{ouvert = 0 (valeur basse)}}</option></select>' : '') + '</td></tr>'
    })
    h += '</tbody></table>'
    var hors = PL ? suivis.filter(function (id) { return !PL.marqueurs[id] }) : []
    h += '<legend style="font-size:1.1em">2. {{Où sont-elles ?}} <small>({{facultatif}})</small></legend>'
      + (hors.length ? '<p>' + hors.map(function (id) { return '<a class="btn btn-xs btn-default ph_gplacer" data-id="' + esc(id) + '" style="margin:0 3px 3px 0"><i class="fas fa-map-marker-alt"></i> ' + esc(libelle(id)) + '</a>' }).join('') + '</p><p><small>{{Cliquez pour la placer sur le plan (pièces et passages se dessinent dans l’onglet Plan).}}</small></p>'
        : '<p><small>' + (!PL ? '{{Plan pas encore chargé.}}' : suivis.length ? '{{Tout est placé.}}' : '{{Rien à placer.}}') + '</small></p>')
    h += '<legend style="font-size:1.1em">3. {{Des règles ?}} <small>({{facultatif}})</small></legend><p><small>{{« Peut s’allumer » : seulement dans ces plages ; « jamais allumée » : jamais dans celles-ci. De 23:00 à 07:00 passe minuit ; de 00:00 à 00:00 = toute la journée. Semaine et week-end : comme dans la configuration du plugin (par défaut, le week-end va du vendredi 16:00 au lundi 04:00).}}</small></p>'
      + '<table class="table table-condensed"><tbody>'
    suivis.forEach(function (id) {
      var g = REG[id] || { jamais: 0, plages: [] }
      h += '<tr class="ph_rid" data-id="' + esc(id) + '"><td style="width:30%">' + esc(libelle(id)) + '</td><td><label style="font-weight:normal"><input type="checkbox" class="ph_rjamais"' + (g.jamais ? ' checked' : '') + '> {{Ne jamais la simuler (ni pendant l’absence)}}</label>'
        + g.plages.map(function (p, i) { return plageH(id, i, p) }).join('') + '<a class="btn btn-xs btn-default ph_pplus"><i class="fas fa-plus"></i> {{plage}}</a></td></tr>'
    })
    h += '</tbody></table>'
    h += '<legend style="font-size:1.1em">4. {{Pas encore assez de données}}</legend><div id="ph_gq4"></div>'
    h += '<legend style="font-size:1.1em">{{Bilan}}</legend><div id="ph_gbilan"></div>'
    $('#ph_gcontenu').html(h)
    $('#ph_gcontenu tr[data-id]').each(function () { var r = REP[$(this).attr('data-id')]; if (r && r.de) $(this).find('.ph_gde').val(r.de) })
    $('#ph_gcontenu .ph_plage').each(function () { var id = $(this).closest('tr').attr('data-id'), p = REG[id].plages[$(this).index('#ph_gcontenu tr[data-id="' + id + '"] .ph_plage')]; $(this).find('.ph_pk').val(p.k); $(this).find('.ph_pj').val(p.j) })
    vus(); bilan()
  }
  function q4() { // comme etat() : ni « jamais », ni plage « peut s'allumer »
    var peu = Object.keys(D.suivi).filter(function (id) { return jours(id) < (D.fiable || 10) && !(REG[id] || {}).jamais && !marche(id) })
    $('#ph_gq4').html(peu.length ? '<ul>' + peu.map(function (id) {
      var c = cand(id) || {}, n = SAUVE[id] ? '' : !c.hist ? '{{non historisée : les relevés commencent à l’enregistrement}}'
        : c.lisse ? '{{historique lissé : seules les dernières heures sont reprises à l’enregistrement}}' : '{{historisée : son historique est repris à l’enregistrement}}'
      return '<li>' + esc(libelle(id)) + ' : ' + Math.floor(jours(id)) + ' {{j de relevés}}' + (n ? ' <small>(' + n + ')</small>' : '') + '</li>'
    }).join('') + '</ul><p><small>{{Une fois enregistrées, leurs changements sont relevés chaque jour. En attendant, une plage « peut s’allumer » (question 3) suffit pour la simulation.}}</small></p>' : '<p><small>{{Rien.}}</small></p>')
  }
  function bilan() {
    q4()
    var suivis = Object.keys(D.suivi), manque = 0, h = ''
    var nr = aDemander().filter(function (c) { return !REP[c.id] && !D.suivi[c.id] }).length
    suivis.forEach(function (id) { var e = etat(id); if (e[1]) manque++; h += '<li>' + esc(libelle(id)) + ' : ' + (e[1] ? '<span class="text-warning">' + esc(e[0]) + '</span>' : esc(e[0])) + '</li>' })
    $('#ph_gbilan').html((!suivis.length ? '<div class="alert alert-warning">{{Aucune lampe ni aucun volet suivi : répondez « Oui » à la question 1.}}</div>'
      : manque ? '<div class="alert alert-warning">' + manque + ' {{à compléter avant de proposer une simulation.}}</div>'
      : '<div class="alert alert-success">{{Simulation proposable : chaque lampe et chaque volet suivi a des habitudes, des plages ou est écarté. Rien n’est lancé pour l’instant.}}</div>')
      + (nr ? '<p><small>' + nr + ' {{commande(s) sans réponse à la question 1 (non suivies).}}</small></p>' : '') + (h ? '<ul>' + h + '</ul>' : ''))
  }
  function lireRegles() {
    $('#ph_gcontenu tr.ph_rid').each(function () {
      var id = $(this).attr('data-id'), p = []
      $(this).find('.ph_plage').each(function () { p.push({ k: $(this).find('.ph_pk').val(), j: $(this).find('.ph_pj').val(), de: $(this).find('.ph_pde').val(), a: $(this).find('.ph_pa').val() }) })
      var j = $(this).find('.ph_rjamais').is(':checked') ? 1 : 0
      if (j || p.length) REG[id] = { jamais: j, plages: p }; else delete REG[id]
    })
  }
  // Identifier en allumant : le core diffuse les changements de valeur (cmd::update) sur la page
  var ECOUTE = false
  function vus() {
    if (!VU.length) return $('#ph_vus').html(ECOUTE ? '<p class="text-info"><i class="fas fa-spinner fa-spin"></i> {{En attente d’un changement…}}</p>' : '')
    var ens = [], t0 = VU[0].t
    VU.forEach(function (v) { if (t0 - v.t <= 5000 && ens.indexOf(v.id) < 0) ens.push(v.id) }) // ponytail: 5 s comme les doublons de la découverte
    var h = '<ul class="list-unstyled">' + VU.slice(0, 8).map(function (v) { return '<li><small>' + esc(new Date(v.t).toLocaleTimeString()) + '</small> ' + esc(libelle(v.id)) + ' → ' + esc(v.v) + '</li>' }).join('') + '</ul>'
    if (ens.length > 1) h += '<p>' + ens.length + ' {{commandes ont changé ensemble : une seule lampe ? Gardez celle qui la représente, les autres deviennent ses doublons.}}</p><p>'
      + ens.map(function (id) { return '<a class="btn btn-xs btn-primary ph_garder" data-id="' + esc(id) + '" data-ens="' + esc(ens.join(',')) + '" style="margin:0 3px 3px 0">{{garder}} ' + esc(libelle(id)) + '</a>' }).join('') + '</p>'
    $('#ph_vus').html('<div class="well well-sm">' + h + '</div>')
  }
  function ecouter(on) {
    ECOUTE = on; $('body').off('cmd::update.phg')
    if (on) $('body').on('cmd::update.phg', function (e, d) {
      var l = [].concat(d !== undefined ? d : (e.originalEvent && e.originalEvent.detail) || []), ids = {}, n = 0
      D.candidats.forEach(function (c) { if (c.sub == 'binary' || c.type != 'autre') ids[c.id] = 1 }) // aussi les virtuels et 2es commandes : les doublons cherchés
      l.forEach(function (o) { if (o && ids[o.cmd_id]) { VU.unshift({ id: String(o.cmd_id), v: o.value, t: Date.now() }); n++ } })
      if (n) { VU = VU.slice(0, 30); lireRegles(); guide() } // les nouvelles venues deviennent répondables ; plages en cours de saisie gardées
    })
    guide()
  }
  function placer(id) {
    if (!ET) return alerte('{{Ajoutez d’abord un étage.}}', 'warning')
    PLACER = PLACER == id ? null : id; OUTIL = 'deplacer'; TRACE = []
    $('#ph_outils a').removeClass('btn-primary').addClass('btn-default').filter('[data-outil=deplacer]').addClass('btn-primary').removeClass('btn-default')
    dessiner()
  }
  function changeGuide() { liste(); dessiner(); guide() }
  $('#ph_gcontenu').on('change', '.ph_grep', function () { lire(); var id = $(this).closest('tr').attr('data-id'); repondre(id, $(this).val()); changeGuide() })
  $('#ph_gcontenu').on('change', '.ph_ginv', function () { lire(); var id = $(this).closest('tr').attr('data-id'); SENS[id] = $(this).val() ? 1 : 0; if (D.suivi[id]) D.suivi[id].i = SENS[id] || undefined; changeGuide() })
  $('#ph_gcontenu').on('change', '.ph_gde', function () { lire(); var id = $(this).closest('tr').attr('data-id'); repondre(id, 'doublon', $(this).val()); changeGuide() })
  $('#ph_gcontenu').on('click', '#ph_ecoute', function () { VU = []; ecouter(!ECOUTE) })
  $('#ph_gcontenu').on('click', '.ph_garder', function () {
    lire(); var id = $(this).attr('data-id')
    $(this).attr('data-ens').split(',').forEach(function (o) { if (o != id) repondre(o, 'doublon', id) })
    repondre(id, 'oui'); VU = []; changeGuide()
  })
  $('#ph_gcontenu').on('click', '.ph_gplacer', function () {
    var id = String($(this).attr('data-id'))
    $('a[href="#ph_plan"]').tab('show'); if (!ET) return alerte('{{Ajoutez d’abord un étage dans l’onglet Plan.}}', 'warning')
    PLACER = null; placer(id)
  })
  $('#ph_gcontenu').on('change', '.ph_rjamais, .ph_pk, .ph_pj, .ph_pde, .ph_pa', function () { lireRegles(); bilan() })
  $('#ph_gcontenu').on('click', '.ph_pplus', function () { lireRegles(); var id = $(this).closest('tr').attr('data-id'); REG[id] = REG[id] || { jamais: 0, plages: [] }; if (REG[id].plages.length < 10) REG[id].plages.push({ k: 'marche', j: 'tous', de: '18:00', a: '23:00' }); guide() })
  $('#ph_gcontenu').on('click', '.ph_pmoins', function () { var tr = $(this).closest('tr'), i = tr.find('.ph_plage').index($(this).closest('.ph_plage')); lireRegles(); var g = REG[tr.attr('data-id')]; if (g) { g.plages.splice(i, 1); if (!g.jamais && !g.plages.length) delete REG[tr.attr('data-id')] } guide() })
  $('#ph_genregistrer').on('click', function () { lireRegles(); sauver(function () { if (PL) planEnr() }) }) // plan après le suivi : ses tendances sur le suivi neuf

  function profil(g, k) {
    var h = '<tr><th style="width:90px">' + (k == 'S' ? '{{Semaine}}' : '{{Week-end}}') + ' <small>(' + esc(g['n' + k]) + ' {{j}})</small>'
      + ((g['confirmer' + k] === undefined ? g.confirmer : g['confirmer' + k]) ? ' <span class="label label-warning">{{à confirmer}}</span>' : '') + '</th>'
    g['l' + k].forEach(function (v, i) {
      h += '<td title="' + i + ' h : ' + esc(g[k][i]) + ' % {{des jours}} ({{lissé}} ' + esc(v) + ' %)" style="text-align:center;padding:2px;background:rgba(240,173,78,' + (v / 100) + ')">' + (v ? esc(v) : '') + '</td>'
    })
    return h + '</tr>'
  }
  function habitudes(r) {
    H = r; dessiner() // pastilles du plan
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
          return '<td>' + cf.slice(1) + (cf ? '<br>' : '') + '{{médiane}} ' + hm(x.med) + ', {{moyenne}} ' + hm(x.moy) + '<br><small>{{au plus tôt}} ' + hm(x.min) + ', {{au plus tard}} ' + hm(x.max) + ' (' + esc(x.n) + ' {{j}})</small>' + f + '</td>'
        }
        ;[['matin', '{{ouverture}}'], ['soir', '{{fermeture}}']].forEach(function (m) {
          ;['S', 'W'].forEach(function (k) { var x = v[m[0]] && v[m[0]][k]; if (x && Object.keys(x.ex || {}).length) ex.push(esc(v.nom) + ', ' + m[1] + ' : ' + exc(x.ex, hm)) })
        })
        h += '<tr><td>' + esc(v.nom) + (v.confirmer ? ' <span class="label label-warning">{{à confirmer}}</span>' : '') + '</td>' // v.confirmer : habitudes.json d’avant 0.5
          + (v.jours ? c(v.matin.S) + c(v.matin.W) + c(v.soir.S, 1) + c(v.soir.W, 1) : '<td colspan="4">{{Pas encore assez de données.}}</td>') + '</tr>'
      })
      h += '</table>' + (ex.length ? '<p><b>{{Exceptions}}</b> :<br>' + ex.join('<br>') + '</p>' : '') + '<p><small>{{Exceptions : jours loin de la moitié centrale, de plus de 30 min et de plus de 1,5 fois son étendue (le plus grand des deux).}} {{Ouverture du matin : seulement si le volet était fermé à 04 h. Une fermeture partielle (contre le soleil) ne compte pas.}}</small></p>'
    }
    if (h) h += '<p><small>{{Journée de 04 h à 04 h. Week-end : comme dans la configuration du plugin (par défaut du vendredi 16:00 au lundi 04:00).}}</small></p>'
    $('#ph_resultats').html(h || '<p>{{Aucune commande suivie.}}</p>')
  }

  /* ---- Plan : un par étage, coordonnées en % de l'image ---- */
  var PL = null, H = null, ET = null, IMG = {}, OUTIL = 'deplacer', TRACE = [], PLACER = null, DESIGNS = [], GLISSE = false
  var AIDE = {
    deplacer: '{{Glissez une pastille pour la déplacer ; cliquez une commande de « Pas sur le plan », puis le plan, pour la placer ; cliquez une pièce pour la renommer.}}',
    piece: '{{Cliquez les coins de la pièce, puis « Terminer la forme ».}}',
    passage: '{{Cliquez les deux bouts du passage (une porte, un couloir, le haut d’un escalier).}}',
    zone: '{{Cliquez les coins de la zone (ex. une chambre d’amis), puis « Terminer la forme ».}}',
    effacer: '{{Cliquez une pastille (elle retourne dans « Pas sur le plan »), une pièce, un passage ou une zone pour le retirer.}}'
  }
  function uid() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 6) } // 12 caractères (≤ 16 côté box)
  function libelle(id) { var c = D && D.candidats.find(function (x) { return x.id == id }); return c ? c.eq + (c.nom != 'Etat' && c.nom != 'État' ? ' ' + c.nom : '') : '#' + id }
  function etage(id) { return PL.etages.find(function (e) { return e.id == id }) }
  function piece(id) { return PL.pieces.find(function (x) { return x.id == id }) }
  function pieceNom(id) { var p = piece(id), e = p && etage(p.e); return p ? (PL.etages.length > 1 && e ? e.nom + ' – ' : '') + p.nom : '?' }
  function dans(x, y, pts) { // même règle que presencehabituelle_analyse::dans
    var r = false
    for (var i = 0, j = pts.length - 1; i < pts.length; j = i++)
      if ((pts[i][1] > y) != (pts[j][1] > y) && x < (pts[j][0] - pts[i][0]) * (y - pts[i][1]) / (pts[j][1] - pts[i][1]) + pts[i][0]) r = !r
    return r
  }
  function pieceDe(e, x, y) { var p = PL.pieces.find(function (o) { return o.e == e && dans(x, y, o.pts) }); return p ? p.id : '' }
  function pos(ev) {
    var r = $('#ph_carte')[0].getBoundingClientRect(), f = function (v) { return Math.round(Math.max(0, Math.min(100, v)) * 100) / 100 }
    return [f((ev.clientX - r.left) / r.width * 100), f((ev.clientY - r.top) / r.height * 100)]
  }
  function tendance(id) { // [texte, intensité 0..1] à l'heure choisie
    var pm = PL.marqueurs[id]
    if (pm && PL.zones.some(function (o) { return o.e == pm.e && dans(pm.x, pm.y, o.pts) })) return ['{{Zone exclue : ni analysée ni simulée.}}', 0]
    if (!H) return ['', 0]
    var h = +$('#ph_heure').val(), k = $('#ph_jour').val()
    if (D.suivi[id].t == 'volet') {
      var v = (H.volets || []).find(function (x) { return x.id == id }), m = v && v.matin && v.matin[k], s = v && v.soir && v.soir[k]
      if (!v || !v.jours) return ['{{Pas encore assez de données.}}', 0]
      var mn = h * 60 + 30 + (h < 4 ? 1440 : 0), ouvert = m && m.n && s && s.n ? mn >= m.med && mn < s.med : null
      return ['{{Ouverture}} ' + (m && m.n ? hm(m.med) : '–') + ', {{fermeture}} ' + (s && s.n ? hm(s.med) : '–') + ' ({{médianes}})'
        + (ouvert === null ? '' : ' — ' + (ouvert ? '{{d’habitude ouvert à cette heure}}' : '{{d’habitude fermé à cette heure}}')), ouvert ? 1 : 0]
    }
    var g = (H.groupes || []).find(function (x) { return (x.ids || []).indexOf(+id) >= 0 })
    if (!g || !g.jours) return ['{{Pas encore assez de données.}}', 0]
    return [(g.ids.length > 1 ? g.nom + ' : ' : '') + '{{allumée}} ' + g[k][h] + ' % {{des jours vers}} ' + h + ' h', g[k][h] / 100]
  }
  function dessiner() {
    if (!PL || !D) return
    if (ET && !etage(ET)) ET = null
    if (!ET && PL.etages.length) ET = PL.etages[0].id
    $('#ph_etage').html(PL.etages.map(function (e) { return '<option value="' + esc(e.id) + '">' + esc(e.nom) + '</option>' }).join('') || '<option value="">{{Aucun étage}}</option>').val(ET || '')
    $('.ph_etage_op').toggle(!!ET)
    $('#ph_finir').toggle((OUTIL == 'piece' || OUTIL == 'zone') && TRACE.length >= 3)
    $('#ph_aide').text(AIDE[OUTIL] || '')
    var img = ET && IMG[ET]
    var h = img ? '<img src="' + esc(img) + '" style="width:100%;display:block;pointer-events:none" draggable="false">'
      : '<div style="aspect-ratio:3/2;background:repeating-linear-gradient(0deg,transparent 0 39px,rgba(128,128,128,.15) 39px 40px),repeating-linear-gradient(90deg,transparent 0 39px,rgba(128,128,128,.15) 39px 40px)"><p style="padding:20px;opacity:.7">'
        + (ET ? '{{Pas d’image pour cet étage : « Image… » pour en déposer une (photo, capture), ou dessinez sur la grille.}}' : '{{Ajoutez un étage pour commencer.}}') + '</p></div>'
    function pts(l) { return l.map(function (p) { return p[0] + ',' + p[1] }).join(' ') }
    var trait = ' vector-effect="non-scaling-stroke" style="cursor:pointer"'
    h += '<svg viewBox="0 0 100 100" preserveAspectRatio="none" style="position:absolute;left:0;top:0;width:100%;height:100%">'
    PL.pieces.forEach(function (o, i) { if (o.e == ET) h += '<polygon data-k="pieces" data-i="' + i + '" points="' + pts(o.pts) + '" fill="rgba(91,192,222,.12)" stroke="#5bc0de" stroke-width="2"' + trait + '/>' })
    PL.zones.forEach(function (o, i) { if (o.e == ET) h += '<polygon data-k="zones" data-i="' + i + '" points="' + pts(o.pts) + '" fill="rgba(217,83,79,.25)" stroke="#d9534f" stroke-width="2" stroke-dasharray="6 3"' + trait + '/>' })
    PL.passages.forEach(function (o, i) { if (o.e == ET) h += '<polyline data-k="passages" data-i="' + i + '" points="' + pts(o.pts) + '" stroke="#5cb85c" stroke-width="5"' + (o.t == 'escalier' ? ' stroke-dasharray="3 3"' : '') + trait + '/>' })
    if (TRACE.length) h += '<polyline points="' + pts(TRACE) + '" fill="none" stroke="#f0ad4e" stroke-width="2" vector-effect="non-scaling-stroke"/>'
    h += '</svg>'
    PL.pieces.forEach(function (o) { // nom au centre des coins
      if (o.e != ET) return
      var x = 0, y = 0; o.pts.forEach(function (p) { x += p[0] / o.pts.length; y += p[1] / o.pts.length })
      h += '<div style="position:absolute;left:' + x + '%;top:' + y + '%;transform:translate(-50%,-50%);pointer-events:none;font-weight:bold;text-shadow:0 0 3px var(--bg-color,#fff)">' + esc(o.nom) + '</div>'
    })
    Object.keys(PL.marqueurs).forEach(function (id) {
      var m = PL.marqueurs[id]; if (m.e != ET || !D.suivi[id]) return
      var t = tendance(id), vol = D.suivi[id].t == 'volet', c = vol ? '51,122,183' : '240,173,78'
      h += '<div class="ph_m" data-id="' + esc(id) + '" title="' + esc(libelle(id) + (t[0] ? '\n' + t[0] : '')) + '" style="position:absolute;left:' + m.x + '%;top:' + m.y + '%;transform:translate(-50%,-50%);width:26px;height:26px;'
        + 'border-radius:' + (vol ? '4px' : '50%') + ';border:2px solid rgb(' + c + ');background:rgba(' + c + ',' + (0.15 + 0.85 * t[1]) + ');display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;touch-action:none;cursor:'
        + (OUTIL == 'deplacer' ? 'move' : OUTIL == 'effacer' ? 'pointer' : 'crosshair') + '"><i class="fas fa-' + (vol ? 'bars' : 'lightbulb') + '"></i></div>'
    })
    $('#ph_carte').html(h).css('cursor', OUTIL == 'deplacer' && !PLACER ? 'default' : 'crosshair')
    var hors = Object.keys(D.suivi).filter(function (id) { return !PL.marqueurs[id] })
    $('#ph_hors').html(hors.length ? hors.map(function (id) {
      return '<a class="btn btn-xs ' + (PLACER == id ? 'btn-warning' : 'btn-default') + ' ph_placer" data-id="' + esc(id) + '" style="margin:0 3px 3px 0"><i class="fas fa-' + (D.suivi[id].t == 'volet' ? 'bars' : 'lightbulb') + '"></i> ' + esc(libelle(id)) + '</a>'
    }).join('') : '<small>{{Tout est placé.}}</small>')
    $('#ph_pieces').html(PL.pieces.map(function (o, i) {
      if (o.e != ET) return ''
      var n = Object.keys(PL.marqueurs).filter(function (id) { var m = PL.marqueurs[id]; return D.suivi[id] && m.e == ET && dans(m.x, m.y, o.pts) }).length
      return '<div><a class="ph_pnom" data-i="' + i + '" style="cursor:pointer" title="{{Renommer}}"><i class="fas fa-pen"></i></a> ' + esc(o.nom) + ' <small>(' + n + ')</small></div>'
    }).join('') || '<small>{{Aucune pièce sur cet étage.}}</small>')
    $('#ph_passages').html(PL.passages.map(function (o) {
      var a = piece(o.a), b = piece(o.b), r = a && b && (H && H.passages || []).find(function (x) { return x.id == o.id })
      return '<div>' + esc(pieceNom(o.a)) + ' ↔ ' + esc(pieceNom(o.b)) + ' <small>(' + esc({ porte: '{{porte}}', couloir: '{{couloir}}', escalier: '{{escalier}}' }[o.t]) + ')'
        + (r ? '<br>→ ' + esc(r.ab) + ' {{fois}}, ← ' + esc(r.ba) + ' {{fois}}' : '') + '</small></div>'
    }).join('') + (PL.passages.length ? '<p><small>{{Fois où une lumière de la seconde pièce s’allume moins de 3 min après l’extinction d’une lumière de la première.}}</small></p>' : '<small>{{Aucun passage.}}</small>'))
  }
  function image() { // image de l'étage, demandée une fois
    if (!ET && PL && PL.etages.length) ET = PL.etages[0].id // à l'ouverture et après « Supprimer l'étage » : le premier
    var e = ET // la réponse va à l'étage demandé, même si on en a changé entre-temps
    dessiner() // tout de suite : grille en attendant l'image, et la carte suit l'étage même si la demande échoue
    if (e && IMG[e] === undefined) ajax('plan_image', { etage: e }, function (r) { IMG[e] = r; dessiner() })
  }
  function retirer(k, i) {
    if (k == 'pieces') { var id = PL.pieces[i].id; PL.passages = PL.passages.filter(function (o) { return o.a != id && o.b != id }) } // un passage sans sa pièce ne relie plus rien
    PL[k].splice(i, 1)
  }
  function nomPiece(titre, val, ok) {
    bootbox.prompt({ title: titre, value: val || '', callback: function (n) {
      n = n === null ? '' : n.trim().slice(0, 40)
      if (n && PL.pieces.some(function (o) { return o.nom == n && o.nom != val })) alerte('{{Une autre pièce porte ce nom : leurs lampes seront analysées ensemble.}}', 'warning')
      if (n) ok(n); dessiner()
    } })
  }
  function finir() {
    if (TRACE.length < 3) return
    var l = TRACE; TRACE = []
    if (OUTIL == 'zone') { PL.zones.push({ id: uid(), e: ET, pts: l }); return dessiner() }
    nomPiece('{{Nom de la pièce}}', '', function (n) { PL.pieces.push({ id: uid(), e: ET, nom: n, pts: l }) })
  }
  function passage() {
    var a = TRACE[0], b = TRACE[1]; TRACE = []
    if (PL.pieces.length < 2) { alerte('{{Dessinez d’abord au moins deux pièces.}}', 'warning'); return dessiner() }
    var o = PL.pieces.map(function (p) { return '<option value="' + esc(p.id) + '">' + esc(pieceNom(p.id)) + '</option>' }).join('')
    bootbox.dialog({ title: '{{Passage}}', message: '<div class="form-group"><label>{{De}}</label><select class="form-control" id="ph_pa">' + o + '</select></div>'
      + '<div class="form-group"><label>{{Vers}} <small>({{une pièce d’un autre étage pour un escalier}})</small></label><select class="form-control" id="ph_pb">' + o + '</select></div>'
      + '<div class="form-group"><label>{{Type}}</label><select class="form-control" id="ph_pt"><option value="porte">{{porte}}</option><option value="couloir">{{couloir}}</option><option value="escalier">{{escalier}}</option></select></div>',
      buttons: { annuler: { label: '{{Annuler}}', className: 'btn-default', callback: dessiner }, ok: { label: '{{Ajouter}}', className: 'btn-success', callback: function () {
        if ($('#ph_pa').val() == $('#ph_pb').val()) { alerte('{{Un passage relie deux pièces différentes.}}', 'warning'); return false }
        PL.passages.push({ id: uid(), e: ET, a: $('#ph_pa').val(), b: $('#ph_pb').val(), t: $('#ph_pt').val(), pts: [a, b] }); dessiner()
      } } } })
    var pa = pieceDe(ET, a[0], a[1]) || PL.pieces[0].id, pb = pieceDe(ET, b[0], b[1])
    $('#ph_pa').val(pa); $('#ph_pb').val(pb && pb != pa ? pb : PL.pieces.find(function (p) { return p.id != pa }).id)
  }

  $('#ph_carte').on('click', function (ev) {
    if (GLISSE || !ET) return
    var p = pos(ev), $t = $(ev.target), m = $t.closest('.ph_m'), k = $t.attr('data-k')
    if (OUTIL == 'effacer') { if (m.length) delete PL.marqueurs[m.attr('data-id')]; else if (k) retirer(k, +$t.attr('data-i')); return dessiner() }
    if (OUTIL == 'deplacer') {
      if (PLACER) { PL.marqueurs[PLACER] = { e: ET, x: p[0], y: p[1] }; PLACER = null; return dessiner() }
      if (k == 'pieces') { var i = +$t.attr('data-i'); nomPiece('{{Nom de la pièce}}', PL.pieces[i].nom, function (n) { PL.pieces[i].nom = n }) }
      return
    }
    if (TRACE.length >= 200) return alerte('{{200 coins au plus : terminez la forme.}}', 'warning')
    TRACE.push(p)
    if (OUTIL == 'passage' && TRACE.length == 2) return passage()
    dessiner()
  })
  $('#ph_carte').on('pointerdown', '.ph_m', function (ev) {
    if (OUTIL != 'deplacer' || PLACER) return
    var id = $(this).attr('data-id'), el = this, bouge = false
    ev.preventDefault(); this.setPointerCapture(ev.pointerId) // relâché hors de la fenêtre : le pointerup arrive quand même
    $(document).on('pointermove.ph', function (e) { bouge = true; var p = pos(e); PL.marqueurs[id].x = p[0]; PL.marqueurs[id].y = p[1]; el.style.left = p[0] + '%'; el.style.top = p[1] + '%' })
      .on('pointerup.ph pointercancel.ph', function () { $(document).off('.ph'); if (bouge) { GLISSE = true; setTimeout(function () { GLISSE = false; dessiner() }, 0) } }) // le clic qui suit un glissé ne compte pas
  })
  $('#ph_hors').on('click', '.ph_placer', function () { placer(String($(this).attr('data-id'))) })
  $('#ph_pieces').on('click', '.ph_pnom', function () { var i = +$(this).attr('data-i'); nomPiece('{{Nom de la pièce}}', PL.pieces[i].nom, function (n) { PL.pieces[i].nom = n }) })
  $('#ph_outils a').on('click', function () {
    OUTIL = $(this).attr('data-outil'); TRACE = []; PLACER = null
    $('#ph_outils a').removeClass('btn-primary').addClass('btn-default'); $(this).addClass('btn-primary').removeClass('btn-default')
    dessiner()
  })
  $('#ph_finir').on('click', finir)
  for (var i = 0; i < 24; i++) $('#ph_heure').append('<option value="' + i + '">' + i + ' h</option>')
  $('#ph_heure').val(new Date().getHours())
  $('#ph_heure, #ph_jour').on('change', dessiner)
  $('#ph_etage').on('change', function () { ET = $(this).val(); TRACE = []; PLACER = null; image() })
  $('#ph_etage_plus').on('click', function () {
    bootbox.prompt('{{Nom de l’étage (ex. Rez-de-chaussée)}}', function (n) {
      if (!n || !n.trim()) return
      if (PL.etages.length >= 20) return alerte('{{20 étages au plus.}}', 'warning')
      var e = { id: uid(), nom: n.trim().slice(0, 40) }; PL.etages.push(e); ET = e.id; TRACE = []; image()
    })
  })
  $('#ph_etage_nom').on('click', function () { var e = etage(ET); bootbox.prompt({ title: '{{Nom de l’étage}}', value: e.nom, callback: function (n) { if (n && n.trim()) e.nom = n.trim().slice(0, 40); dessiner() } }) })
  $('#ph_etage_haut, #ph_etage_bas').on('click', function () {
    var i = PL.etages.indexOf(etage(ET)), j = i + (this.id == 'ph_etage_haut' ? -1 : 1)
    if (j < 0 || j >= PL.etages.length) return
    var e = PL.etages[i]; PL.etages[i] = PL.etages[j]; PL.etages[j] = e; dessiner()
  })
  $('#ph_etage_moins').on('click', function () {
    bootbox.confirm('{{Supprimer cet étage, son image, ses pièces, ses passages, ses zones et les positions qui y sont ? (effectif à l’enregistrement du plan)}}', function (oui) {
      if (!oui) return
      var e = ET, ids = PL.pieces.filter(function (o) { return o.e == e }).map(function (o) { return o.id })
      PL.pieces = PL.pieces.filter(function (o) { return o.e != e }); PL.zones = PL.zones.filter(function (o) { return o.e != e })
      PL.passages = PL.passages.filter(function (o) { return o.e != e && ids.indexOf(o.a) < 0 && ids.indexOf(o.b) < 0 })
      Object.keys(PL.marqueurs).forEach(function (id) { if (PL.marqueurs[id].e == e) delete PL.marqueurs[id] })
      PL.etages = PL.etages.filter(function (o) { return o.id != e }); ET = null; TRACE = []; image()
    })
  })
  $('#ph_image').on('change', function () { // réduite à 2000 px et passée en JPEG ici : la box ne garde qu'un JPEG vérifié
    var f = this.files[0], e = ET; this.value = ''
    if (!f || !e) return
    createImageBitmap(f).then(function (b) {
      var k = Math.min(1, 2000 / Math.max(b.width, b.height)), c = document.createElement('canvas')
      c.width = Math.round(b.width * k); c.height = Math.round(b.height * k)
      var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(b, 0, 0, c.width, c.height)
      var d = c.toDataURL('image/jpeg', 0.85)
      ajax('plan_image_enregistrer', { etage: e, image: d }, function () { IMG[e] = d; dessiner(); alerte('{{Image enregistrée. Enregistrez le plan pour garder l’étage.}}', 'success') })
    }).catch(function () { alerte('{{Image illisible (prenez une photo ou une capture : PNG, JPEG…)}}') })
  })
  $('#ph_design').on('change', function () {
    var d = $(this).val(); $(this).val('')
    if (!d || !ET) return
    ajax('plan_design', { design: d }, function (r) {
      var n = Object.keys(r).length
      if (!n) return alerte('{{Aucune commande suivie dans ce design.}}', 'warning')
      bootbox.confirm(esc(n) + ' {{commandes suivies trouvées. Les placer sur l’étage}} « ' + esc(etage(ET).nom) + ' » ? {{Leurs positions actuelles sont remplacées. Elles suivent les proportions du design : prenez comme image de l’étage le fond de ce design, ou une image aux mêmes proportions.}}', function (oui) {
        if (oui) { Object.keys(r).forEach(function (id) { PL.marqueurs[id] = { e: ET, x: r[id].x, y: r[id].y } }); dessiner() }
      })
    })
  })
  function planEnr() { ajax('plan_enregistrer', { plan: JSON.stringify(PL) }, function (r) { PL = r.plan; habitudes(r.habitudes); guide(); alerte('{{Plan enregistré}}', 'success') }) }
  $('#ph_plan_enregistrer').on('click', function () { if (PL) planEnr() })
  ajax('plan', {}, function (r) {
    PL = r.plan; DESIGNS = r.designs
    $('#ph_design').html('<option value="">{{Reprendre les positions d’un design…}}</option>' + DESIGNS.map(function (d) { return '<option value="' + esc(d.id) + '">' + esc(d.nom) + '</option>' }).join(''))
    image(); guide()
  })
  $('a[href="#ph_guide"]').on('shown.bs.tab', guide) // pastilles placées entre-temps

  $('#ph_autres').on('change', function () { lire(); liste() })
  $('#ph_parObjet').on('click', function () { $('#ph_liste tr[data-id]').each(function () { if ($(this).find('.ph_coche').is(':checked')) $(this).find('.ph_groupe').val($(this).attr('data-objet')) }) })
  $('#ph_liste').on('change', '.ph_coche, .ph_groupe', function () { lire(); dessiner(); guide() }) // cochée sans enregistrer : déjà plaçable sur le plan
  $('#ph_seules').on('click', function () { $('#ph_liste .ph_groupe').val('') })
  $('#ph_choisir').on('click', function () { jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (r) { $('#ph_exclure').val(r.human) }) })
  $('#ph_enregistrer').on('click', function () { sauver() })
  function sauver(ok) {
    lire()
    if (!D) return // liste pas encore chargée (ou en erreur) : rien à enregistrer
    var mal = Object.keys(REG).filter(function (id) { return REG[id].plages.some(function (p) { return !plageOk(p) }) })
    if (mal.length) return alerte('{{Plage sans heure de début ou de fin (question 3)}} : ' + mal.map(libelle).join(', '), 'warning')
    var partent = Object.keys(SAUVE).filter(function (id) { return !D.suivi[id] })
    if (!partent.length) return enregistrer(ok)
    bootbox.confirm(esc(partent.length) + ' {{commande(s) retirée(s) du suivi}} : ' + esc(partent.map(libelle).join(', ')) + '. {{Leurs relevés seront effacés : si vous les cochez à nouveau, seul l’historique de Jeedom est repris (90 jours, quelques heures pour un historique lissé). Continuer ?}}', function (oui) { if (oui) enregistrer(ok) })
  }
  function enregistrer(ok) {
    ajax('enregistrer', { suivi: JSON.stringify(D.suivi), exclure: $('#ph_exclure').val(), exclusions: $('#ph_exclusions').val(), reponses: JSON.stringify(REP), regles: JSON.stringify(REG) }, function (r) {
      alerte('{{Enregistré}} : ' + r.repris + ' {{changements repris de l’historique}}', 'success')
      $('#ph_precedent').toggle(!!r.precedent).attr('data-date', r.precedent || '')
      SAUVE = $.extend({}, D.suivi); REP = $.extend({}, r.reponses); REG = $.extend(true, {}, r.regles); D.donnees = r.donnees; D.figes = r.figes // ce que la box a retenu
      habitudes(r.habitudes); guide()
      if (ok) ok()
    })
  }
  $('#ph_decouvrir').on('click', function () {
    var b = $(this)
    if (b.hasClass('disabled')) return // une découverte à la fois
    b.addClass('disabled'); $('#ph_decouverte').html('<p><i class="fas fa-spinner fa-spin"></i> {{Lecture des historiques…}}</p>')
    ajax('decouverte', {}, function (r) { b.removeClass('disabled'); decouverte(r) }, function () { b.removeClass('disabled'); $('#ph_decouverte').empty() })
  })
  $('#ph_decouverte').on('click', '#ph_appliquer', appliquer)
  $('#ph_decouverte').on('click', '#ph_dec_fermer', function () { $('#ph_decouverte').empty() })
  $('#ph_decouverte').on('click', '#ph_observer', function () {
    ajax('observer', { ids: JSON.stringify(R.sans.concat(R.lisses).map(function (x) { return x.id })) }, function (fin) { R.observation = fin; R.sans = []; R.lisses = []; decouverte(R) })
  })
  $('#ph_precedent').on('click', function () {
    bootbox.confirm('{{Recharger dans la liste le choix d’avant le dernier enregistrement}} (' + esc($(this).attr('data-date')) + ') ? {{Rien n’est enregistré tant que vous ne cliquez pas sur Enregistrer. Les relevés des commandes retirées entre-temps ne reviennent pas : seul l’historique de Jeedom est repris.}}', function (oui) {
      if (!oui) return
      ajax('liste', {}, function (r) {
        if (!r.precedent) return
        D = r; D.suivi = $.extend({}, r.precedent.suivi); NOTES = {}; SENS = {}
        D.candidats.forEach(function (c) { var s = D.suivi[c.id]; if (s && s.t) c.type = s.t; c.type0 = c.type }) // type du choix d'avant (volet ou lumière reconnus au nom)
        REP = $.extend({}, r.precedent.reponses || r.reponses); REG = $.extend(true, {}, r.precedent.regles || r.regles) // choix d'avant la 0.8 : réponses actuelles gardées
        $('#ph_exclure').val(r.precedent.exclure); $('#ph_exclusions').val(r.precedent.exclusions)
        liste(); dessiner()
        alerte('{{Choix d’avant rechargé : cliquez sur Enregistrer pour y revenir.}}', 'info')
      })
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

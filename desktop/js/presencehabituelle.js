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
      D = r; HIST = null; SAUVE = $.extend({}, r.suivi); REP = $.extend({}, r.reponses); REG = $.extend(true, {}, r.regles); DET = $.extend(true, [], r.detecteurs); if (H) habitudes(H) // tendances arrivées avant : étiquettes « sur détecteur »
      D.candidats.forEach(function (c) { c.type0 = c.type == 'volet' ? 'volet' : c.tg }); SENS = {} // rendu sur « Non » après un « Oui » (une prise prise pour une lampe redevient prise)
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
      r.doublons.map(function (d) { return cb('doublon', d.id, esc(d.nom) + ' — ' + (d.copie ? '{{recopie}} ' : '{{doublon de}} ') + esc(d.nomDe) + (d.verif ? ' <span class="text-warning">({{à vérifier : c’est la recopie qui a l’historique}})</span>' : ''), !d.verif) }).join(''))
    h += bloc('{{Groupes}}', '{{allumé seulement quand ses lampes le sont : cochée = décochée de la liste}}',
      r.groupes.map(function (g) { return cb('groupe', g.id, esc(g.nom) + ' — {{groupe de}} ' + esc(g.membres.join(', ')), true) }).join(''))
    h += bloc('{{Lumières sans type générique}}', '{{d’après leur nom : montrées dans la liste comme lumières, décochées}}',
      r.nommees.map(function (n) { return cb('lumiere', n.id, esc(n.nom) + ' — {{type générique à renseigner ?}}', true) }).join(''))
    h += bloc('{{Volets sans type générique}}', '{{d’après leur nom : montrés dans la liste comme volets, décochés}}',
      (r.volets || []).map(function (n) { return cb('volet', n.id, esc(n.nom) + ' — ' + (n.verif ? '{{à vérifier : état d’un démon ou d’un plugin, sans doute pas un volet}}' : '{{type générique à renseigner ?}}'), !n.verif) }).join(''))
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
      if (k == 'doublon') { var d = R.doublons.filter(function (x) { return x.id == v })[0]; if (repondre(v, 'doublon', String(d.de))) NOTES[v] = (d.copie ? '{{recopie}} ' : '{{doublon de}} ') + d.nomDe }
      if (k == 'groupe') { var g = R.groupes.filter(function (x) { return x.id == v })[0]; garder(g.ids, D.suivi[v]); g.ids.forEach(function (i) { if (D.suivi[i] && !REP[i]) REP[i] = { r: 'oui' } }); repondre(v, 'groupe'); REP[v].m = g.ids.map(Number); NOTES[v] = '{{groupe de}} ' + g.membres.join(', ') }
      if (k == 'lumiere' && parId[v]) { parId[v].type = 'lumiere'; NOTES[v] = '{{type générique à renseigner ?}}' }
      if (k == 'volet' && parId[v]) { parId[v].type = parId[v].type0 = 'volet'; NOTES[v] = (R.volets || []).some(function (x) { return x.id == v && x.verif }) ? '{{à vérifier : état d’un démon ou d’un plugin ?}}' : '{{type générique à renseigner ?}}' } // type0 : reste un volet sur « Oui »
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
  var DET = [], REP = {}, REG = {}, VU = [], SENS = {}, FT = '', FS = '', FH = '', HIST = null // réponses {id: {r, de, m}} (m : lampes d'un groupe), filtres type et provenance, règles {id: {jamais, plages}}, changements vus en écoutant
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
  function memeDet(a, b) { var x = cand(a), y = cand(b); if (x && x.copie && x.copie == b || y && y.copie && y.copie == a) return false // une recopie reste un doublon
    return DET.some(function (d) { return d.m.indexOf(+a) >= 0 && d.m.indexOf(+b) >= 0 }) } // comme memeDetecteur côté box
  function repondre(id, r, de) { // false : refusée (doublon d'un doublon)
    var c = cand(id), s = D.suivi[id]
    if (!r) { delete REP[id]; return true }
    if (r == 'doublon' && de && memeDet(id, de)) { alerte(libelle(id) + ' {{et}} ' + libelle(de) + ' {{sont sur le même détecteur : deux lampes, pas un doublon.}}', 'warning'); return false }
    if (r == 'doublon' && de && REP[de] && REP[de].r == 'doublon') { alerte(libelle(de) + ' {{est déjà un doublon : choisissez la commande gardée.}}', 'warning'); return false }
    if (r == 'doublon' && de && REP[de] && REP[de].r == 'groupe') { alerte(libelle(de) + ' {{est un groupe de lampes : un doublon recopie une seule lampe.}}', 'warning'); return false }
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
    if (!HIST) HIST = { vu: {}, sc: {} } // points des 90 derniers jours et scénarios qui la commandent, demandés une fois par commande
    var neuf = l.filter(function (c) { return !HIST.vu[c.id] }).map(function (c) { return c.id }).slice(0, 2000) // la box n'en lit pas plus à la fois
    if (neuf.length && !HIST.attente) {
      HIST.attente = 1
      ajax('historiques', { ids: JSON.stringify(neuf) }, function (r) { var rh = (r && r.h) || {}, rs = (r && r.s) || {}; neuf.forEach(function (id) { HIST.vu[id] = 1; if (rh[id]) HIST[id] = rh[id]; if (rs[id]) HIST.sc[id] = rs[id] }); HIST.attente = 0; lireDet(); lireRegles(); guide() },
        function () { neuf.forEach(function (id) { HIST.vu[id] = 1 }); HIST.attente = 0 }) // échec : pas de nouvel essai à chaque clic (rechargez la page)
    }
    var riche = function (c) { var x = HIST[c.id]; return !c.lisse && x && x.n >= 30 && x.j >= 30 } // ponytail: seuils fixes (30 points sur 30 j au moins) ; un historique lissé n'est pas riche
    var opts = function (id) { return l.filter(function (c) { return c.id != id }).map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(libelle(c.id)) + '</option>' }).join('') }
    h += '<legend style="font-size:1.1em">1. {{Est-ce une lampe ou un volet de la maison ?}}</legend>'
      + '<p><small>{{Doublon : la même lampe vue par une autre commande (gardez l’autre). Groupe : une commande qui allume plusieurs lampes déjà listées. Non : une prise de frigo, une LED d’état…}}</small></p>'
      + '<p><a class="btn btn-sm btn-default" id="ph_ecoute"><i class="fas fa-' + (ECOUTE ? 'stop' : 'hand-pointer') + '"></i> ' + (ECOUTE ? '{{Arrêter}}' : '{{Identifier en allumant ou en ouvrant}}') + '</a> '
      + '<small class="text-muted">{{allumez ou éteignez une lampe, ouvrez ou fermez un volet : ce qui change s’affiche ici, avec sa valeur}}</small></p><div id="ph_vus"></div>'
      + '<table class="table table-condensed"><tbody>'
    var srcs = {}; l.forEach(function (c) { if (c.src) srcs[c.src] = 1 })
    h = h.replace('<table class="table table-condensed"><tbody>', '<p class="form-inline">{{Montrer}} <select class="form-control input-sm" id="ph_gft"><option value="">{{tous les types}}</option>'
      + Object.keys(NOMS).map(function (k) { return '<option value="' + k + '">' + esc(NOMS[k]) + '</option>' }).join('') + '</select> <select class="form-control input-sm" id="ph_gfs"><option value="">{{toutes les provenances}}</option>'
      + Object.keys(srcs).sort().map(function (k) { return '<option value="' + esc(k) + '">' + esc(k) + '</option>' }).join('') + '</select> <select class="form-control input-sm" id="ph_gfh"><option value="">{{avec ou sans historique}}</option><option value="riche">{{historique riche}}</option><option value="oui">{{avec historique}}</option><option value="non">{{sans historique}}</option></select>'
      + ' <small class="text-muted">{{📈 : valeurs gardées sur 90 jours ; ⚙ : scénario qui la commande (son journal ne montre que ce qu’il fait, pas les gestes à la main). Cliquez pour voir. Rien n’est coché pour vous.}}</small></p><table class="table table-condensed"><tbody>')
    var cle = function (c) { // pièces Jeedom dans l'ordre du plan (étage du bas au haut, puis pièce), les autres par nom, « sans objet » à la fin
      var p = c.objid && lien(c.objid, c.objet) ? piece(lien(c.objid, c.objet)) : null, e = p ? PL.etages.indexOf(etage(p.e)) : 99
      return [c.objid ? 0 : 1, p ? 0 : 1, ('0' + e).slice(-2), p ? p.nom : '', c.objet || ''].join('|')
    }
    var vis = l.filter(function (c) { return (!FT || c.type == FT) && (!FS || c.src == FS) && (!FH || (FH == 'riche' ? riche(c) : FH == 'oui' ? !!HIST[c.id] : HIST.vu[c.id] && !HIST[c.id])) }), obj = null
    vis.map(function (c, n) { return [cle(c) + '|' + ('000' + n).slice(-4), c] }).sort(function (a, b) { return a[0] < b[0] ? -1 : 1 }).forEach(function (x) {
      var c = x[1], r = REP[c.id] || {}, note = NOTES[c.id] || (D.suivi[c.id] ? '{{suivie}}' : c.coche ? '{{proposée}}' : ''), o = c.objid || 0
      if (o !== obj) { // en-tête de la pièce Jeedom (sans data-id : les boucles du Guide ne le voient pas)
        obj = o; var pl = o && lien(o, c.objet)
        h += '<tr class="ph_gobj"><th colspan="2" style="padding-top:10px">' + (o ? esc(c.objet) : '{{Sans objet}}') + (pl ? ' <small class="text-muted">→ {{plan}} : ' + esc(pieceNom(pl)) + '</small>' : '') + '</th></tr>'
      }
      var oc = c.copie && cand(c.copie), cp = oc && oc.actif && (oc.type == 'lumiere' || oc.type == 'volet') && (!REP[c.copie] || REP[c.copie].r == 'oui'), mb = (r.m || []).map(libelle)
      h += '<tr data-id="' + esc(c.id) + '"' + (c.actif ? '' : ' style="opacity:.5"') + '><td>' + esc(libelle(c.id)) + ' <small class="text-muted">' + esc(NOMS[c.type]) + '</small>'
        + (c.src ? ' <span class="label label-default">' + esc(c.src) + '</span>' : '')
        + (!HIST.vu[c.id] ? '' : HIST[c.id] ? ' <a class="label label-' + (riche(c) ? 'success' : 'default') + ' ph_ghist" style="cursor:pointer" title="{{Voir l’historique}}">📈 ' + HIST[c.id].n + ' {{valeurs}} / ' + Math.min(90, Math.round(HIST[c.id].j)) + ' {{j}}</a>' : ' <small class="text-muted">' + (c.hist ? '{{historisée, rien sur 90 j}}' : '{{non historisée}}') + '</small>')
        + (HIST.sc[c.id] || []).slice().sort(function (a, b) { return b[4] - a[4] || b[2] - a[2] }).map(function (x) { return ' <a class="label label-' + (x[2] ? 'primary' : 'default') + ' ph_gsc" data-sc="' + esc(x[0]) + '" style="cursor:pointer' + (x[5] ? '' : ';opacity:.6') + '" title="{{Scénario qui commande cet équipement : journal (seulement ce que fait le scénario, pas les gestes à la main). Journal vide : journal du scénario désactivé, commande renommée depuis, ou pas lancé récemment.}}">⚙ ' + esc(x[1]) + (x[5] ? '' : ' ({{désactivé}})') + (x[2] ? ' : ' + x[2] + ' {{actions}}, {{dernière le}} ' + new Date(x[4] * 1000).toLocaleDateString() : ' : {{rien au journal}}') + '</a>' }).join('')
        + (cp ? ' <span class="label label-warning">{{recopie}} ' + esc(libelle(c.copie)) + '</span>' : '')
        + (note ? ' <span class="label label-info">' + esc(note) + '</span>' : '')
        + (r.r == 'groupe' ? '<br><small>' + (mb.length ? '{{allume}} : ' + esc(mb.join(', ')) : '{{choisissez les lampes qu’il allume}}') + '</small>' : '') + '</td><td style="width:340px" class="form-inline"><select class="form-control input-sm ph_grep">'
        + [['', '{{— à répondre —}}'], ['oui', '{{Oui, à suivre}}'], ['non', '{{Non}}'], ['doublon', '{{Doublon de…}}'], ['groupe', '{{Groupe de lampes}}']].map(function (o) { return '<option value="' + o[0] + '"' + ((r.r || '') == o[0] ? ' selected' : '') + '>' + o[1] + '</option>' }).join('')
        + '</select>' + (r.r == 'doublon' ? ' <select class="form-control input-sm ph_gde"><option value="">{{de…}}</option>' + opts(c.id) + '</select>' : '')
        + (r.r == 'groupe' ? ' <a class="btn btn-xs btn-default ph_gm"><i class="fas fa-object-group"></i> {{Lampes…}}</a>' : '')
        + (cp && !(r.r == 'doublon' && r.de == c.copie) ? ' <a class="btn btn-xs btn-warning ph_gcopie" title="{{On garde l’originale}}">{{Doublon de}} ' + esc(libelle(c.copie)) + '</a>' : '')
        + (c.type == 'volet' && D.suivi[c.id] ? ' <select class="form-control input-sm ph_ginv" title="{{Ouvrez le volet en identifiant : la valeur affichée est celle de l’ouvert}}"><option value="">{{ouvert = valeur haute}}</option><option value="1"' + (D.suivi[c.id].i ? ' selected' : '') + '>{{ouvert = 0 (valeur basse)}}</option></select>' : '') + '</td></tr>'
    })
    if (!vis.length) h += '<tr><td><small>{{Rien avec ces filtres.}}</small></td></tr>'
    h += '</tbody></table>'
    var hors = PL ? placables().filter(function (id) { return !PL.marqueurs[id] }) : []
    h += '<legend style="font-size:1.1em">2. {{Où sont-elles ?}} <small>({{facultatif}})</small></legend>'
      + (hors.length ? '<p>' + hors.map(function (id) { return '<a class="btn btn-xs btn-default ph_gplacer" data-id="' + esc(id) + '" style="margin:0 3px 3px 0"><i class="fas fa-' + (estDet(id) ? 'walking' : 'map-marker-alt') + '"></i> ' + esc(libelle(id)) + '</a>' }).join('') + '</p><p><small>{{Cliquez pour la placer sur le plan (pièces et passages se dessinent dans l’onglet Plan).}}</small></p>'
        : '<p><small>' + (!PL ? '{{Plan pas encore chargé.}}' : placables().length ? '{{Tout est placé.}}' : '{{Rien à placer.}}') + '</small></p>')
    h += '<legend style="font-size:1.1em">3. {{Des règles ?}} <small>({{facultatif}})</small></legend><p><small>{{« Peut s’allumer » : seulement dans ces plages ; « jamais allumée » : jamais dans celles-ci. De 23:00 à 07:00 passe minuit ; de 00:00 à 00:00 = toute la journée. Semaine et week-end : comme dans la configuration du plugin (par défaut, le week-end va du vendredi 16:00 au lundi 04:00).}}</small></p>'
      + '<table class="table table-condensed"><tbody>'
    suivis.forEach(function (id) {
      var g = REG[id] || { jamais: 0, plages: [] }
      h += '<tr class="ph_rid" data-id="' + esc(id) + '"><td style="width:30%">' + esc(libelle(id)) + '</td><td><label style="font-weight:normal"><input type="checkbox" class="ph_rjamais"' + (g.jamais ? ' checked' : '') + '> {{Ne jamais la simuler (ni pendant l’absence)}}</label>'
        + g.plages.map(function (p, i) { return plageH(id, i, p) }).join('') + '<a class="btn btn-xs btn-default ph_pplus"><i class="fas fa-plus"></i> {{plage}}</a></td></tr>'
    })
    h += '</tbody></table>'
    h += '<legend style="font-size:1.1em">{{Détecteurs de présence}} <small>({{facultatif}})</small></legend><p><small>{{Un détecteur qui allume des lampes (relié à Jeedom ou non) : elles s’allument ensemble, brièvement. Déclaré, elles ne sont plus proposées comme doublons ; la liste est gardée pour les simuler ensemble dans une prochaine version.}}</small></p>'
      + '<table class="table table-condensed"><tbody>' + DET.map(function (d, i) {
        return '<tr class="ph_det" data-i="' + i + '"><td style="width:30%"><input class="form-control input-sm ph_dnom" maxlength="60" placeholder="{{nom}}" value="' + esc(d.nom) + '"></td><td>'
          + '<a class="btn btn-xs btn-default ph_dcmd" title="{{Commande Jeedom du détecteur (facultatif)}}"><i class="fas fa-list-alt"></i> ' + (d.cmd ? esc(d.h || '#' + d.cmd) : '{{non relié à Jeedom}}') + '</a>' + (d.perdu ? ' <span class="text-warning">{{sa commande a été supprimée de Jeedom}}</span>' : '') + (d.cmd ? ' <a class="btn btn-xs btn-default ph_dsans" title="{{Délier}}">×</a>' : '')
          + ' <a class="btn btn-xs btn-default ph_dlampes"><i class="fas fa-lightbulb"></i> {{Lampes…}}</a> <small>' + (d.m.length ? esc(d.m.map(libelle).join(', ')) : '<span class="text-warning">{{choisissez les lampes qu’il allume}}</span>') + '</small>'
          + ' <a class="btn btn-xs btn-danger ph_dmoins" title="{{Supprimer}}"><i class="fas fa-trash"></i></a></td></tr>'
      }).join('') + '</tbody></table>' + (DET.length < 30 ? '<p><a class="btn btn-xs btn-default ph_dplus"><i class="fas fa-plus"></i> {{détecteur}}</a> ' + detJeedom() + '</p>' : '')
    h += '<legend style="font-size:1.1em">4. {{Pas encore assez de données}}</legend><div id="ph_gq4"></div><div id="ph_ghisto"></div>'
    h += '<legend style="font-size:1.1em">{{Bilan}}</legend><div id="ph_gbilan"></div>'
    $('#ph_gcontenu').html(h); $('#ph_gft').val(FT); $('#ph_gfs').val(FS); $('#ph_gfh').val(FH)
    $('#ph_gcontenu tr[data-id]').each(function () { var r = REP[$(this).attr('data-id')]; if (r && r.de) $(this).find('.ph_gde').val(r.de) })
    $('#ph_gcontenu .ph_plage').each(function () { var id = $(this).closest('tr').attr('data-id'), p = REG[id].plages[$(this).index('#ph_gcontenu tr[data-id="' + id + '"] .ph_plage')]; $(this).find('.ph_pk').val(p.k); $(this).find('.ph_pj').val(p.j) })
    vus(); bilan(); histoAff()
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
    VU.forEach(function (v) { if (t0 - v.t <= 5000 && ens.indexOf(v.id) < 0 && !DET.some(function (d) { return d.cmd == v.id })) ens.push(v.id) }) // ponytail: 5 s comme les doublons de la découverte ; la commande d'un détecteur n'est pas une lampe
    var h = '<ul class="list-unstyled">' + VU.slice(0, 8).map(function (v) { return '<li><small>' + esc(new Date(v.t).toLocaleTimeString()) + '</small> ' + esc(libelle(v.id)) + ' → ' + esc(v.v) + '</li>' }).join('') + '</ul>'
    var dd = ens.length > 1 && DET.filter(function (d) { return ens.every(function (id) { return d.m.indexOf(+id) >= 0 }) })[0]
    if (dd) h += '<p>{{Ces lampes sont sur le même détecteur}} « ' + esc(dd.nom) + ' » : {{elles s’allument ensemble, rien à garder.}}</p>'
    else if (ens.length > 1) h += '<p>' + ens.length + ' {{commandes ont changé ensemble : une seule lampe ? Gardez celle qui la représente, les autres deviennent ses doublons.}}</p><p>'
      + ens.map(function (id) { return '<a class="btn btn-xs btn-primary ph_garder" data-id="' + esc(id) + '" data-ens="' + esc(ens.join(',')) + '" style="margin:0 3px 3px 0">{{garder}} ' + esc(libelle(id)) + '</a>' }).join('') + '</p>'
    $('#ph_vus').html('<div class="well well-sm">' + h + '</div>')
  }
  function ecouter(on) {
    ECOUTE = on; $('body').off('cmd::update.phg')
    if (on) $('body').on('cmd::update.phg', function (e, d) {
      var l = [].concat(d !== undefined ? d : (e.originalEvent && e.originalEvent.detail) || []), ids = {}, n = 0
      D.candidats.forEach(function (c) { if (c.sub == 'binary' || c.type != 'autre') ids[c.id] = 1 }) // aussi les virtuels et 2es commandes : les doublons cherchés
      l.forEach(function (o) { if (o && ids[o.cmd_id]) { VU.unshift({ id: String(o.cmd_id), v: o.value, t: Date.now() }); n++ } })
      if (n) { VU = VU.slice(0, 30); lireDet(); lireRegles(); guide() } // les nouvelles venues deviennent répondables ; plages en cours de saisie gardées
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
  $('#ph_gcontenu').on('click', '.ph_ghist', function () { // fenêtre d'historique de Jeedom (lecture seule)
    var id = $(this).closest('tr').attr('data-id'), u = 'index.php?v=d&modal=cmd.history&id=' + encodeURIComponent(id), t = '{{Historique}} : ' + libelle(id)
    if (window.jeeDialog) jeeDialog.dialog({ id: 'jee_modal', title: esc(t), contentUrl: u }); else $('#md_modal').dialog({ title: t }).load(u).dialog('open')
  })
  $('#ph_gcontenu').on('click', '.ph_gsc', function () { // journal du scénario (lecture seule)
    var u = 'index.php?v=d&modal=scenario.log.execution&scenario_id=' + encodeURIComponent($(this).attr('data-sc')), t = esc('{{Journal du scénario}} : ' + $(this).text())
    if (window.jeeDialog) jeeDialog.dialog({ id: 'jee_modal', title: t, contentUrl: u }); else $('#md_modal').dialog({ title: $(this).text() }).load(u).dialog('open')
  })
  $('#ph_gcontenu').on('change', '#ph_gft, #ph_gfs, #ph_gfh', function () { FT = $('#ph_gft').val(); FS = $('#ph_gfs').val(); FH = $('#ph_gfh').val(); lireRegles(); guide() })
  $('#ph_gcontenu').on('click', '.ph_gcopie', function () { lire(); var id = $(this).closest('tr').attr('data-id'); repondre(id, 'doublon', String(cand(id).copie)); changeGuide() })
  function lampes(titre, id, m, ok, dbl) { // lampes à cocher (groupe, détecteur) : ni « Non » ni doublon (dbl : doublons aussi, pour un détecteur)
    var l = D.candidats.filter(function (c) { return c.id != id && (c.type == 'lumiere' || (dbl && REP[c.id] && REP[c.id].r == 'doublon')) && (!REP[c.id] || REP[c.id].r == 'oui' || (dbl && REP[c.id].r == 'doublon')) && (c.actif || D.suivi[c.id] || m.indexOf(+c.id) >= 0) })
    bootbox.dialog({ title: esc(titre), message: '<div style="max-height:60vh;overflow:auto">' + l.map(function (c) {
      return '<div class="checkbox"><label><input type="checkbox" class="ph_gmc" value="' + esc(c.id) + '"' + (m.indexOf(+c.id) >= 0 ? ' checked' : '') + '> ' + esc(libelle(c.id)) + ' <small class="text-muted">' + esc(c.objet) + '</small></label></div>'
    }).join('') + '</div>', buttons: { annuler: { label: '{{Annuler}}', className: 'btn-default' }, ok: { label: '{{Valider}}', className: 'btn-success', callback: function () {
      ok($('.ph_gmc:checked').map(function () { return +this.value }).get().slice(0, 100))
    } } } })
  }
  $('#ph_gcontenu').on('click', '.ph_gm', function () { // lampes allumées par un groupe : cochées comme le « de… » d'un doublon
    lire(); var id = $(this).closest('tr').attr('data-id')
    lampes('{{Lampes allumées par}} ' + libelle(id), id, (REP[id] || {}).m || [], function (v) {
      if (!REP[id] || REP[id].r != 'groupe') return
      REP[id].m = v; garder(v.map(String), D.suivi[id]); v.forEach(function (x) { if (D.suivi[x] && !REP[x]) REP[x] = { r: 'oui' } })
      changeGuide()
    })
  })
  function lireDet() { $('#ph_gcontenu tr.ph_det').each(function () { var d = DET[$(this).attr('data-i')]; if (d) d.nom = $(this).find('.ph_dnom').val() }) }
  function det(e) { lireDet(); lireRegles(); return DET[$(e).closest('tr').attr('data-i')] }
  $('#ph_gcontenu').on('change', '.ph_dnom', lireDet)
  $('#ph_gcontenu').on('click', '.ph_dplus', function () { lireDet(); lireRegles(); if (DET.length < 30) DET.push({ nom: '{{Détecteur}} ' + (DET.length + 1), cmd: 0, h: '', m: [] }); guide() })
  function presence(c) { return c.det } // règle côté box : presencehabituelle_analyse::estDetecteur
  function detJeedom() { // détecteurs de présence de Jeedom pas encore déclarés, par pièce Jeedom
    var l = D.candidats.filter(function (c) { return presence(c) && !DET.some(function (d) { return d.cmd == c.id }) })
    return l.length ? '<select class="form-control input-sm ph_djeedom" style="display:inline-block;width:auto"><option value="">{{＋ détecteur de Jeedom…}}</option>'
      + l.map(function (c) { return '<option value="' + c.id + '">' + esc((c.objet ? '[' + c.objet + '] ' : '') + c.eq + ' ' + c.nom) + (c.actif ? '' : ' ({{désactivé}})') + '</option>' }).join('') + '</select>' : ''
  }
  $('#ph_gcontenu').on('change', '.ph_djeedom', function () { // lampes suivies de la même pièce Jeedom pré-cochées, à vérifier avec « Lampes… »
    var c = D.candidats.find(function (x) { return x.id == this.value }, this); if (!c) return; lireDet(); lireRegles(); if (DET.length >= 30) return
    DET.push({ nom: c.eq, cmd: +c.id, h: '#[' + c.objet + '][' + c.eq + '][' + c.nom + ']#', m: Object.keys(D.suivi).filter(function (id) { var l = D.candidats.find(function (x) { return x.id == id }); return D.suivi[id].t == 'lumiere' && l && l.objid && l.objid == c.objid && !(REP[id] && REP[id].r != 'oui') && !(l.copie && D.suivi[l.copie]) }).map(Number) })
    guide()
  })
  function oublier(d) { if (PL && d.cmd && !D.suivi[d.cmd] && DET.filter(function (x) { return x.cmd == d.cmd }).length < 2) delete PL.marqueurs[d.cmd] } // pastille du détecteur retirée avec lui (sinon invisible, impossible à supprimer)
  $('#ph_gcontenu').on('click', '.ph_dmoins', function () { var d = det(this); oublier(d); DET.splice(DET.indexOf(d), 1); guide(); if (PL) dessiner() })
  $('#ph_gcontenu').on('click', '.ph_dsans', function () { var d = det(this); oublier(d); d.cmd = 0; d.h = ''; guide(); if (PL) dessiner() })
  $('#ph_gcontenu').on('click', '.ph_dcmd', function () { var d = det(this); jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (r) { if (DET.some(function (x) { return x != d && x.cmd == r.cmd.id })) return alerte('{{Cette commande est déjà celle d’un autre détecteur.}}', 'warning'); if (d.cmd != r.cmd.id) oublier(d); d.cmd = +r.cmd.id; d.h = r.human; d.perdu = 0; guide(); if (PL) dessiner() }) })
  $('#ph_gcontenu').on('click', '.ph_dlampes', function () { var d = det(this); lampes('{{Lampes allumées par}} ' + d.nom, 0, d.m, function (v) {
    lire(); d.m = v; v.forEach(function (x) { var de = REP[x] && REP[x].r == 'doublon' && REP[x].de; if (de === false || de === undefined || (cand(x) || {}).copie == de) return
      repondre(String(x), 'oui'); delete NOTES[x]; if (D.suivi[x]) D.suivi[x].g = (D.suivi[de] || {}).g || '' }) // une lampe du détecteur n'est le doublon de personne ; elle reprend le groupe de sa jumelle
    changeGuide()
  }, 1) })
  $('#ph_gcontenu').on('click', '#ph_ecoute', function () { VU = []; ecouter(!ECOUTE) })
  $('#ph_gcontenu').on('click', '.ph_garder', function () {
    lire(); var id = $(this).attr('data-id')
    $(this).attr('data-ens').split(',').forEach(function (o) { if (o != id && !memeDet(o, id)) repondre(o, 'doublon', id) })
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
      h += '<td title="' + i + ' h : ' + esc(g[k][i]) + ' % {{des jours}} ({{lissé}} ' + esc(v) + ' %)" style="text-align:center;padding:2px;background:rgba(240,173,78,' + (v / 100) + ')' + (v >= 50 ? ';color:#000' : '') + '">' + (v ? esc(v) : '') + '</td>' // texte noir sur fond clair
    })
    return h + '</tr>'
  }
  function duree(m) { // minutes → « 5 min », « 1 h 57 »
    m = +m
    if (!(m >= 1)) return '{{moins d’1 min}}'
    m = Math.round(m)
    return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + ('0' + m % 60).slice(-2) : '')
  }
  function surtout(l, det) { // heures allumées au moins un jour sur quatre (profil lissé) ; sur détecteur, au moins la moitié des jours de l'heure la plus fréquente (des passages brefs touchent presque toutes les heures)
    var s = det ? Math.max(25, Math.max.apply(null, (l || []).concat(0)) / 2) : 25
    var hs = []; (l || []).forEach(function (v, i) { if (v >= s) hs.push(i) })
    return hs.length == 24 ? '{{à toute heure}}' : hs.length ? plages(hs) : '{{rarement}}'
  }
  function habitudes(r) {
    H = r; dessiner() // pastilles du plan
    $('#ph_date').text(r.date ? '{{Calculé le}} ' + r.date : '')
    var h = '', res = '' // res : une ligne par pièce ; h : le détail, replié
    ;(r.groupes || []).forEach(function (g, n) {
      var d = g.durees || {}, ancre = 'ph_g' + n
      if (!g.jours) { res += '<tr><td>' + esc(g.nom) + '</td><td colspan="5">{{Pas encore assez de données (un jour entier au moins).}}</td></tr>'; return }
      var jamais = []
      for (var i = 0; i < 24; i++) if (!(g.cS || g.S)[i] && !(g.cW || g.W)[i]) jamais.push(i) // comptes bruts (habitudes.json d’avant 0.4 : %) : un allumage sur 300 jours arrondit à 0 %
      var rq = []
      if (g.confirmer || g.confirmerS || g.confirmerW) rq.push('<span class="label label-warning">{{à confirmer}}</span>')
      var sur = DET.filter(function (x) { return x.m.some(function (id) { return (g.ids || []).map(Number).indexOf(+id) >= 0 }) }).map(function (x) { return x.nom })
      if (sur.length) rq.push('<span class="label label-default">{{sur détecteur}} « ' + esc(sur.join(' », « ')) + ' »</span>')
      else if (d.n && d.passages >= 90) rq.push('<span title="{{Presque tous les allumages durent moins de 3 min : sans doute une lampe sur détecteur de mouvement. Déclarez-le dans le Guide (Détecteurs de présence).}}">{{allumages éclair (détecteur ? déclarez-le dans le Guide)}}</span>')
      res += '<tr><td><a href="#' + ancre + '" class="ph_ouvrir">' + esc(g.nom) + '</a></td><td>' + esc(surtout(g.lS, sur.length)) + '</td><td>' + esc(surtout(g.lW, sur.length)) + '</td><td>'
        + (jamais.length ? esc(plages(jamais)) : '–') + '</td><td>' + (d.n ? esc(duree(d.med)) : '–') + '</td><td>' + rq.join(' ') + '</td></tr>'
      h += '<details id="' + ancre + '" style="margin-bottom:6px"><summary style="cursor:pointer;font-size:1.1em">' + esc(g.nom) + '</summary>'
      if (g.lampes.length > 1) h += '<p><small>' + esc(g.lampes.join(', ')) + '</small></p>'
      h += '<table class="table table-condensed table-bordered" style="table-layout:fixed;font-size:.85em"><tr><th></th>'
      for (var i = 0; i < 24; i++) h += '<th style="text-align:center;padding:2px">' + i + '</th>'
      h += '</tr>' + profil(g, 'S') + profil(g, 'W') + '</table><p><small>{{Chiffre = % des jours où une lampe de la pièce est allumée à cette heure.}}</small></p>'
      h += '<p>' + (jamais.length ? '<b>{{Jamais allumée}}</b> : ' + esc(plages(jamais)) : '{{Allumée au moins une fois à chaque heure.}}') + '</p>'
      h += '<p>' + (d.n ? '<b>{{Durée allumée}}</b> : {{en général}} ' + esc(duree(d.med)) + ' ({{la moitié entre}} ' + esc(duree(d.q25)) + ' {{et}} ' + esc(duree(d.q75)) + '), {{au plus}} ' + esc(duree(d.max))
        + ' ; ' + esc(d.passages) + ' % {{de passages de moins de 3 min}} (' + esc(d.n) + ' {{allumages}})' : '{{Durées : pas encore d’allumage terminé.}}') + '</p>'
      var sc = Object.keys(g.scenes || {})
      if (sc.length) h += '<p>{{Allumées ensemble}} : ' + sc.slice(0, 5).map(function (k) { return esc(k) + ' (' + esc(g.scenes[k]) + ' %)' }).join(' ; ') + '</p>'
      if ((g.ex || []).length) h += '<p><small><b>{{Exceptions}}</b> ({{heures où elle l’est au plus 10 % des jours}}) : '
        + g.ex.slice(-8).map(function (e) { return esc(jj(e[0])) + ' ' + esc(e[1]) + ' h' }).join(' ; ') + (g.ex.length > 8 ? ' …' : '') + '</small></p>'
      if (d.n && Object.keys(d.ex || {}).length) h += '<p><small><b>{{Durées inhabituelles}}</b> : ' + exc(d.ex, function (v) { return esc(duree(v)) }) + '</small></p>'
      h += '</details>'
    })
    if (res) h = '<legend style="font-size:1.1em">{{Lumières}}</legend><table class="table table-condensed table-bordered"><tr><th>{{Pièce}}</th><th>{{Allumée surtout en semaine}}</th><th>{{… le week-end}}</th><th>{{Jamais allumée}}</th><th>{{Durée habituelle}}</th><th></th></tr>' + res + '</table>'
      + '<p><small>{{« Surtout » : heures où une lampe est allumée au moins un jour sur quatre. Cliquez une pièce pour son détail heure par heure.}}</small></p>' + h
    if ((r.volets || []).length) {
      h += '<legend style="font-size:1.1em">{{Volets}}</legend><table class="table table-condensed table-bordered"><tr><th>{{Volet}}</th><th>{{Ouverture semaine}}</th><th>{{Ouverture week-end}}</th><th>{{Fermeture semaine}}</th><th>{{Fermeture week-end}}</th></tr>'
      var ex = []
      r.volets.forEach(function (v) {
        function c(x, soir) {
          var f = !soir || !x || x.ferme === null || x.ferme === undefined ? '' : '<br><small>' + ((x.nuits !== undefined ? x.fermees == x.nuits : x.ferme >= 100) ? '{{toujours fermé la nuit}}' : (x.nuits !== undefined ? !x.fermees : !x.ferme) ? '{{jamais fermé la nuit}}' : '{{fermé}} ' + esc(x.ferme) + ' % {{des nuits}}') + (x.confirmerF ? ' <span class="label label-warning">{{à confirmer}}</span>' : '') + '</small>'
          var cf = x && x.confirmer ? ' <span class="label label-warning">{{à confirmer}}</span>' : ''
          if (!x || !x.n) return '<td>–' + cf + f + '</td>'
          return '<td><b>' + hm(x.med) + '</b>' + cf + '<br><small>{{entre}} ' + hm(x.min) + ' {{et}} ' + hm(x.max) + ' (' + esc(x.n) + ' {{j}})</small>' + f + '</td>'
        }
        ;[['matin', '{{ouverture}}'], ['soir', '{{fermeture}}']].forEach(function (m) {
          ;['S', 'W'].forEach(function (k) { var x = v[m[0]] && v[m[0]][k]; if (x && Object.keys(x.ex || {}).length) ex.push(esc(v.nom) + ', ' + m[1] + ' : ' + exc(x.ex, hm)) })
        })
        h += '<tr><td>' + esc(v.nom) + (v.confirmer ? ' <span class="label label-warning">{{à confirmer}}</span>' : '') + '</td>' // v.confirmer : habitudes.json d’avant 0.5
          + (v.jours ? c(v.matin.S) + c(v.matin.W) + c(v.soir.S, 1) + c(v.soir.W, 1) : '<td colspan="4">{{Pas encore assez de données.}}</td>') + '</tr>'
      })
      h += '</table><p><small>{{En gras : l’heure habituelle (médiane).}} {{Ouverture du matin : seulement si le volet était fermé à 04 h. Une fermeture partielle (contre le soleil) ne compte pas.}}</small></p>'
      if (ex.length) h += '<details><summary style="cursor:pointer">{{Exceptions des volets}} (' + ex.length + ')</summary><p><small>' + ex.join('<br>') + '<br>{{Exceptions : jours loin de la moitié centrale, de plus de 30 min et de plus de 1,5 fois son étendue (le plus grand des deux).}}</small></p></details>'
    }
    if (h) h += '<p><small>{{Journée de 04 h à 04 h. Week-end : comme dans la configuration du plugin (par défaut du vendredi 16:00 au lundi 04:00).}}</small></p>'
    $('#ph_resultats').html(h || '<p>{{Aucune commande suivie.}}</p>')
  }
  $('#ph_resultats').on('click', '.ph_ouvrir', function (e) { e.preventDefault(); var d = document.getElementById($(this).attr('href').slice(1)); if (d) { d.open = true; d.scrollIntoView({ behavior: 'smooth' }) } })

  /* ---- Plan : un par étage, coordonnées en % de l'image ---- */
  var PL = null, H = null, ET = null, IMG = {}, OUTIL = 'deplacer', TRACE = [], PLACER = null, DESIGNS = [], GLISSE = false, SEL = null, UNDO = []
  var AIDE = {
    deplacer: '{{Glissez une pastille ou un coin pour le déplacer ; cliquez une pièce, un passage ou une zone pour le modifier ou le supprimer ; cliquez une commande de « Pas sur le plan », puis le plan, pour la placer.}}',
    piece: '{{Cliquez les coins de la pièce, puis « Terminer la forme ».}}',
    passage: '{{Cliquez les points du passage (deux au moins : une porte, un couloir, un escalier qui tourne), puis « Terminer la forme ».}}',
    zone: '{{Cliquez les coins de la zone (ex. une chambre d’amis), puis « Terminer la forme ».}}',
    effacer: '{{Cliquez une pastille (elle retourne dans « Pas sur le plan »), une pièce, un passage ou une zone pour le retirer.}}'
  }
  function uid() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 6) } // 12 caractères (≤ 16 côté box)
  function libelle(id) { var c = D && D.candidats.find(function (x) { return x.id == id }); return c ? c.eq + (c.nom != 'Etat' && c.nom != 'État' ? ' ' + c.nom : '') : '#' + id }
  function etage(id) { return PL.etages.find(function (e) { return e.id == id }) }
  function piece(id) { return PL.pieces.find(function (x) { return x.id == id }) }
  function estDet(id) { return !D.suivi[id] && DET.some(function (d) { return d.cmd == id }) } // pastille d'un détecteur relié à Jeedom (ponytail: un détecteur non relié n'a pas de commande, donc pas de pastille)
  function placables() { var l = Object.keys(D.suivi); DET.forEach(function (d) { if (d.cmd && l.indexOf(String(d.cmd)) < 0) l.push(String(d.cmd)) }); return l }
  function ailleurs(id) { // pastille hors de la pièce que disent sa pièce Jeedom ou son nom : signalé, permis (lampe d'escalier, « SdB 1er » posée au RDC…)
    var m = PL.marqueurs[id], c = D.candidats.find(function (x) { return x.id == id }); if (!m || !c) return ''
    var ici = PL.pieces.find(function (o) { return o.e == m.e && dans(m.x, m.y, o.pts) }), L = mots(c.eq + ' ' + c.nom), j = piece(lien(c.objid, c.objet))
    var meme = function (o) { return ici && norm(o.nom) == norm(ici.nom) } // pièce en L : deux formes de même nom = une pièce
    if (j && !meme(j)) return '{{pièce Jeedom}} « ' + c.objet + ' » → « ' + j.nom + ' »'
    var nomme = PL.pieces.filter(function (o) { return contient(L, mots(o.nom)) })
    nomme = nomme.filter(function (o) { return !nomme.some(function (q) { return norm(q.nom) != norm(o.nom) && contient(mots(q.nom), mots(o.nom)) }) }) // « Chambre 2 » l'emporte sur « Chambre »
    if (nomme.length && !nomme.some(meme)) return '{{son nom dit}} « ' + nomme.map(function (o) { return o.nom }).join(' », « ') + ' »'
    var ici_e = mots((etage(m.e) || {}).nom), propre = function (e) { return mots(e.nom).filter(function (w) { return GENERIQUE.indexOf(w) < 0 }) }
    var autre = PL.etages.filter(function (e) { return e.id != m.e && propre(e).some(function (w) { return L.indexOf(w) >= 0 && ici_e.indexOf(w) < 0 }) })
    return autre.length ? '{{son nom dit}} « ' + autre[0].nom + ' »' : ''
  }
  var GENERIQUE = ['etage', 'niveau', 'level', 'floor', 'de', 'du', 'la', 'le']
  function mots(s) { return String(s || '').replace(/([a-zà-ÿ])([A-ZÀ-Þ][a-zà-ÿ])/g, '$1 $2').split(/[^\p{L}\p{N}]+/u).map(norm).filter(Boolean) } // mots entiers : « étagère » n'est pas « étage »
  function contient(L, W) { for (var i = 0; W.length && i + W.length <= L.length; i++) if (W.every(function (w, k) { return L[i + k] == w })) return true; return false }
  function pieceNom(id) { var p = piece(id), e = p && etage(p.e); return p ? (PL.etages.length > 1 && e ? e.nom + ' – ' : '') + p.nom : '?' }
  function dans(x, y, pts) { // même règle que presencehabituelle_analyse::dans
    var r = false
    for (var i = 0, j = pts.length - 1; i < pts.length; j = i++)
      if ((pts[i][1] > y) != (pts[j][1] > y) && x < (pts[j][0] - pts[i][0]) * (y - pts[i][1]) / (pts[j][1] - pts[i][1]) + pts[i][0]) r = !r
    return r
  }
  function avant() { UNDO.push(JSON.stringify(PL)); if (UNDO.length > 30) UNDO.shift() } // avant chaque changement du plan : « Annuler »
  function autre(o) { // étage de l'autre bout d'un escalier, sinon null (même règle que presencehabituelle_analyse::autreEtage)
    var a = piece(o.a), b = piece(o.b)
    if (!a || !b || a.e == b.e) return null
    return a.e == o.e ? b.e : b.e == o.e ? a.e : null
  }
  function trace(o) { // tracé d'un passage sur l'étage affiché : [points, clé, provisoire], ou null
    if (o.e == ET) return [o.pts, 'pts', false]
    if (autre(o) == ET) return o.pts2 ? [o.pts2, 'pts2', false] : [o.pts, 'pts2', true] // pas encore ajusté : à la même place que sur l'autre étage
    return null
  }
  function selPts() { // élément choisi, s'il est sur cet étage : [points, objet, clé, provisoire]
    var o = SEL && PL[SEL.k][SEL.i]
    if (!o) return null
    if (SEL.k == 'passages') { var t = trace(o); return t ? [t[0], o, t[1], t[2]] : null }
    return o.e == ET ? [o.pts, o, 'pts', false] : null
  }
  function norm(s) { return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '') }
  function auto(nom) { // pièce du plan au même nom (sans accents, espaces ni majuscules), sinon la seule qui contient l'autre nom
    var n = norm(nom), l = PL.pieces.filter(function (p) { return norm(p.nom) == n })
    if (!l.length && n.length >= 3) l = PL.pieces.filter(function (p) { var m = norm(p.nom); return m.length >= 3 && (m.indexOf(n) >= 0 || n.indexOf(m) >= 0) })
    return l.length == 1 ? l[0].id : ''
  }
  function lien(objid, nom) { // pièce du plan d'une pièce Jeedom : choisie dans « Pièces Jeedom », sinon d'après le nom ; '' = aucune
    if (!PL || !objid) return ''
    var v = objid in PL.liens ? PL.liens[objid] : auto(nom)
    return piece(v) ? v : ''
  }
  function objets() { // pièces Jeedom qui ont une lampe ou un volet
    var r = {}
    D.candidats.forEach(function (c) { if (c.objid && (D.suivi[c.id] || c.type == 'lumiere' || c.type == 'volet' || DET.some(function (d) { return d.cmd == c.id }))) r[c.objid] = c.objet })
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
    if (estDet(id)) { var dt = DET.find(function (d) { return d.cmd == id }); return [dt.m.length ? '{{Détecteur : allume}} ' + dt.m.map(libelle).join(', ') : '{{Détecteur : aucune lampe choisie}}', 0.5] }
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
    if (SEL && !selPts()) SEL = null // élément retiré, ou sur un autre étage
    $('#ph_etage').html(PL.etages.map(function (e, i) { return '<option value="' + esc(e.id) + '">' + (i + 1) + '. ' + esc(e.nom) + '</option>' }).join('') || '<option value="">{{Aucun étage}}</option>').val(ET || '')
    $('.ph_etage_op').toggle(!!ET)
    $('#ph_finir').toggle((OUTIL == 'piece' || OUTIL == 'zone') && TRACE.length >= 3 || OUTIL == 'passage' && TRACE.length >= 2)
    $('#ph_annuler').toggleClass('disabled', !UNDO.length)
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
    PL.passages.forEach(function (o, i) { var t = trace(o); if (t) h += '<polyline data-k="passages" data-i="' + i + '" points="' + pts(t[0]) + '" fill="none" stroke="#5cb85c" stroke-width="5"' + (t[2] ? ' stroke-opacity=".45"' : '') + (o.t == 'escalier' ? ' stroke-dasharray="3 3"' : '') + trait + '/>' })
    var sp = selPts()
    if (sp) h += '<' + (SEL.k == 'passages' ? 'polyline' : 'polygon') + ' class="ph_selt" points="' + pts(sp[0]) + '" fill="none" stroke="#f0ad4e" stroke-width="3" vector-effect="non-scaling-stroke" style="pointer-events:none"/>'
    if (TRACE.length) h += '<polyline points="' + pts(TRACE) + '" fill="none" stroke="#f0ad4e" stroke-width="2" vector-effect="non-scaling-stroke"/>'
    h += '</svg>'
    PL.pieces.forEach(function (o) { // nom au centre des coins
      if (o.e != ET) return
      var x = 0, y = 0; o.pts.forEach(function (p) { x += p[0] / o.pts.length; y += p[1] / o.pts.length })
      h += '<div style="position:absolute;left:' + x + '%;top:' + y + '%;transform:translate(-50%,-50%);pointer-events:none;font-weight:bold;text-shadow:0 0 3px var(--bg-color,#fff)">' + esc(o.nom) + '</div>'
    })
    Object.keys(PL.marqueurs).forEach(function (id) {
      var m = PL.marqueurs[id], dt = estDet(id); if (m.e != ET || (!D.suivi[id] && !dt)) return
      var t = tendance(id), vol = !dt && D.suivi[id].t == 'volet', c = dt ? '155,89,182' : vol ? '51,122,183' : '240,173,78', ai = ailleurs(id)
      h += '<div class="ph_m" data-id="' + esc(id) + '" title="' + esc(libelle(id) + (t[0] ? '\n' + t[0] : '') + (ai ? '\n⚠ {{Placé(e) hors de sa pièce}} : ' + ai + ' ({{permis}})' : '')) + '" style="position:absolute;left:' + m.x + '%;top:' + m.y + '%;transform:translate(-50%,-50%);width:26px;height:26px;'
        + 'border-radius:' + (vol ? '4px' : '50%') + ';border:2px solid rgb(' + c + ');background:rgba(' + c + ',' + (0.15 + 0.85 * t[1]) + ');display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;touch-action:none;cursor:'
        + (OUTIL == 'deplacer' ? 'move' : OUTIL == 'effacer' ? 'pointer' : 'crosshair') + ';outline:' + (ai ? '2px dashed #f0ad4e' : 'none') + '"><i class="fas fa-' + (dt ? 'walking' : vol ? 'bars' : 'lightbulb') + '"></i></div>'
    })
    if (sp) sp[0].forEach(function (q, j) { // coins de l'élément choisi : à glisser
      h += '<div class="ph_coin" data-j="' + j + '" title="{{Glissez ce coin}}" style="position:absolute;left:' + q[0] + '%;top:' + q[1] + '%;transform:translate(-50%,-50%);width:12px;height:12px;background:#f0ad4e;border:2px solid #fff;cursor:move;touch-action:none"></div>'
    })
    $('#ph_carte').html(h).css('cursor', OUTIL == 'deplacer' && !PLACER ? 'default' : 'crosshair')
    var hors = placables().filter(function (id) { return !PL.marqueurs[id] })
    $('#ph_hors').html(hors.length ? hors.map(function (id) {
      return '<a class="btn btn-xs ' + (PLACER == id ? 'btn-warning' : 'btn-default') + ' ph_placer" data-id="' + esc(id) + '" style="margin:0 3px 3px 0"><i class="fas fa-' + (estDet(id) ? 'walking' : D.suivi[id].t == 'volet' ? 'bars' : 'lightbulb') + '"></i> ' + esc(libelle(id)) + '</a>'
    }).join('') : '<small>{{Tout est placé.}}</small>')
    var ico = function (k, i, f, t) { return ' <a class="ph_act" data-k="' + k + '" data-i="' + i + '" data-f="' + f + '" style="cursor:pointer" title="' + t + '"><i class="fas fa-' + { choisir: 'mouse-pointer', nom: 'pen', modifier: 'pen', suppr: 'trash' }[f] + '"></i></a>' }
    var o = SEL && PL[SEL.k][SEL.i]
    $('#ph_sel').html(o ? '<div class="well well-sm" style="margin-bottom:6px"><b>' + esc(SEL.k == 'pieces' ? o.nom : SEL.k == 'zones' ? '{{Zone à exclure}}' : pieceNom(o.a) + ' ↔ ' + pieceNom(o.b)) + '</b><br>'
      + (SEL.k == 'pieces' ? '<a class="btn btn-xs btn-default ph_act" data-k="pieces" data-i="' + SEL.i + '" data-f="nom"><i class="fas fa-pen"></i> {{Renommer}}</a> ' : '')
      + (SEL.k == 'passages' ? '<a class="btn btn-xs btn-default ph_act" data-k="passages" data-i="' + SEL.i + '" data-f="modifier"><i class="fas fa-pen"></i> {{Modifier}}</a> ' : '')
      + '<a class="btn btn-xs btn-danger ph_act" data-k="' + SEL.k + '" data-i="' + SEL.i + '" data-f="suppr"><i class="fas fa-trash"></i> {{Supprimer}}</a>'
      + (selPts() && selPts()[3] ? '<br><small>{{Escalier tracé sur l’autre étage : glissez ses points pour le mettre à sa place ici.}}</small>' : '<br><small>{{Glissez les coins orange pour modifier la forme.}}</small>') + '</div>' : '')
    $('#ph_pieces').html(PL.pieces.map(function (o, i) {
      if (o.e != ET) return ''
      var n = Object.keys(PL.marqueurs).filter(function (id) { var m = PL.marqueurs[id]; return D.suivi[id] && m.e == ET && dans(m.x, m.y, o.pts) }).length
      return '<div>' + ico('pieces', i, 'choisir', '{{Choisir}}') + ico('pieces', i, 'nom', '{{Renommer}}') + ico('pieces', i, 'suppr', '{{Supprimer}}') + ' ' + esc(o.nom) + ' <small>(' + n + ')</small></div>'
    }).join('') || '<small>{{Aucune pièce sur cet étage.}}</small>')
    $('#ph_passages').html(PL.passages.map(function (o, i) {
      var r = (H && H.passages || []).find(function (x) { return x.id == o.id }), au = autre(o), t = trace(o)
      return '<div>' + (t ? ico('passages', i, 'choisir', '{{Choisir}}') : '') + ico('passages', i, 'modifier', '{{Modifier}}') + ico('passages', i, 'suppr', '{{Supprimer}}') + ' '
        + esc(pieceNom(o.a)) + ' ↔ ' + esc(pieceNom(o.b)) + ' <small>(' + esc({ porte: '{{porte}}', couloir: '{{couloir}}', escalier: '{{escalier}}' }[o.t]) + ')'
        + (au && !o.pts2 ? ' <span class="text-warning">{{à ajuster sur}} ' + esc((etage(au) || {}).nom) + '</span>' : '')
        + (r ? '<br>→ ' + esc(r.ab) + ' {{fois}}, ← ' + esc(r.ba) + ' {{fois}}' : '') + '</small></div>'
    }).join('') + (PL.passages.length ? '<p><small>{{Fois où une lumière de la seconde pièce s’allume moins de 3 min après l’extinction d’une lumière de la première.}}</small></p>' : '<small>{{Aucun passage.}}</small>'))
    $('#ph_zones').html(PL.zones.map(function (o, i) { return o.e == ET ? '<div>' + ico('zones', i, 'choisir', '{{Choisir}}') + ico('zones', i, 'suppr', '{{Supprimer}}') + ' {{Zone}} ' + (i + 1) + '</div>' : '' }).join('') || '<small>{{Aucune zone sur cet étage.}}</small>')
    var ob = objets(), opt = PL.etages.map(function (e) {
      var l = PL.pieces.filter(function (p) { return p.e == e.id })
      return l.length ? '<optgroup label="' + esc(e.nom) + '">' + l.map(function (p) { return '<option value="' + esc(p.id) + '">' + esc(p.nom) + '</option>' }).join('') + '</optgroup>' : ''
    }).join('')
    $('#ph_liens').html(Object.keys(ob).sort(function (a, b) { return ob[a].localeCompare(ob[b]) }).map(function (o) {
      var a = auto(ob[o])
      return '<div style="margin-bottom:3px"><small>' + esc(ob[o]) + '</small><select class="form-control input-sm ph_lien" data-o="' + esc(o) + '"><option value="">' + (a ? '{{d’après le nom}} : ' + esc(pieceNom(a)) : '{{— à lier —}}') + '</option><option value="-">{{aucune}}</option>' + opt + '</select></div>'
    }).join('') || '<small>{{Aucune pièce Jeedom avec une lampe ou un volet.}}</small>')
    $('#ph_liens .ph_lien').each(function () { var o = $(this).attr('data-o'); $(this).val(o in PL.liens ? (PL.liens[o] === '' ? '-' : PL.liens[o]) : '') })
  }
  function image() { // image de l'étage, demandée une fois
    if (!ET && PL && PL.etages.length) ET = PL.etages[0].id // à l'ouverture et après « Supprimer l'étage » : le premier
    var e = ET // la réponse va à l'étage demandé, même si on en a changé entre-temps
    dessiner() // tout de suite : grille en attendant l'image, et la carte suit l'étage même si la demande échoue
    if (e && IMG[e] === undefined) ajax('plan_image', { etage: e }, function (r) { IMG[e] = r; dessiner() })
  }
  function nettoyer() { Object.keys(PL.liens).forEach(function (o) { if (PL.liens[o] !== '' && !piece(PL.liens[o])) delete PL.liens[o] }) } // lien vers une pièce retirée : revient au nom
  function retirer(k, i) {
    if (k == 'pieces') { var id = PL.pieces[i].id; PL.passages = PL.passages.filter(function (o) { return o.a != id && o.b != id }) } // un passage sans sa pièce ne relie plus rien
    PL[k].splice(i, 1); SEL = null; nettoyer()
  }
  function supprimer(k, i) {
    var o = PL[k][i], l = k == 'pieces' ? PL.passages.filter(function (x) { return x.a == o.id || x.b == o.id }) : []
    var go = function () { avant(); retirer(k, i); dessiner() }
    if (k == 'passages' && autre(o)) return bootbox.confirm('{{Supprimer cet escalier ? Il disparaît des deux étages.}}', function (oui) { if (oui) go() })
    if (!l.length) return go()
    bootbox.confirm('{{Supprimer la pièce}} « ' + esc(o.nom) + ' » {{et ses passages}} : ' + esc(l.map(function (x) { return pieceNom(x.a) + ' ↔ ' + pieceNom(x.b) }).join(', ')) + ' ?', function (oui) { if (oui) go() })
  }
  function nomPiece(titre, val, ok) {
    bootbox.prompt({ title: titre, value: val || '', callback: function (n) {
      n = n === null ? '' : n.trim().slice(0, 40)
      if (n && PL.pieces.some(function (o) { return o.nom == n && o.nom != val })) alerte('{{Une autre pièce porte ce nom : leurs lampes seront analysées ensemble.}}', 'warning')
      if (n && n != val) { avant(); ok(n) }
      dessiner()
    } })
  }
  function finir() {
    if (OUTIL == 'passage') { if (TRACE.length >= 2) passage(TRACE); return }
    if (TRACE.length < 3) return
    var l = TRACE; TRACE = []
    if (OUTIL == 'zone') { avant(); PL.zones.push({ id: uid(), e: ET, pts: l }); return dessiner() }
    nomPiece('{{Nom de la pièce}}', '', function (n) { PL.pieces.push({ id: uid(), e: ET, nom: n, pts: l }) })
  }
  function passage(l, i) { // l : points d'un nouveau passage ; i : passage à modifier
    var o0 = i === undefined ? null : PL.passages[i], e = o0 ? o0.e : ET; TRACE = []
    if (PL.pieces.length < 2) { alerte('{{Dessinez d’abord au moins deux pièces.}}', 'warning'); return dessiner() }
    var o = PL.pieces.map(function (p) { return '<option value="' + esc(p.id) + '">' + esc(pieceNom(p.id)) + '</option>' }).join('')
    bootbox.dialog({ title: o0 ? '{{Modifier le passage}}' : '{{Passage}}', message: '<div class="form-group"><label>{{De}}</label><select class="form-control" id="ph_pa">' + o + '</select></div>'
      + '<div class="form-group"><label>{{Vers}} <small>({{une pièce d’un autre étage pour un escalier}})</small></label><select class="form-control" id="ph_pb">' + o + '</select></div>'
      + '<div class="form-group"><label>{{Type}}</label><select class="form-control" id="ph_pt"><option value="porte">{{porte}}</option><option value="couloir">{{couloir}}</option><option value="escalier">{{escalier}}</option></select></div>',
      buttons: { annuler: { label: '{{Annuler}}', className: 'btn-default', callback: dessiner }, ok: { label: o0 ? '{{Modifier}}' : '{{Ajouter}}', className: 'btn-success', callback: function () {
        var n = { a: $('#ph_pa').val(), b: $('#ph_pb').val(), t: $('#ph_pt').val(), e: e }
        if (n.a == n.b) { alerte('{{Un passage relie deux pièces différentes.}}', 'warning'); return false }
        if (piece(n.a).e != e && piece(n.b).e != e) { alerte('{{L’une des deux pièces doit être sur l’étage où le passage est tracé.}}', 'warning'); return false }
        var au = autre(n)
        if (au && PL.passages.some(function (x, k) { return k !== i && x.t == n.t && (x.a == n.a && x.b == n.b || x.a == n.b && x.b == n.a) })) { alerte('{{Cet escalier existe déjà : il est montré sur les deux étages. Choisissez-le pour l’ajuster.}}', 'warning'); return false }
        avant()
        if (o0) { if (au != autre(o0)) delete o0.pts2; o0.a = n.a; o0.b = n.b; o0.t = n.t } // autre étage changé : son tracé est à refaire
        else PL.passages.push({ id: uid(), e: e, a: n.a, b: n.b, t: n.t, pts: l })
        dessiner()
      } } } })
    if (o0) { $('#ph_pa').val(o0.a); $('#ph_pb').val(o0.b); $('#ph_pt').val(o0.t); return }
    var pa = pieceDe(ET, l[0][0], l[0][1]) || PL.pieces[0].id, pb = pieceDe(ET, l[l.length - 1][0], l[l.length - 1][1])
    $('#ph_pa').val(pa); $('#ph_pb').val(pb && pb != pa ? pb : PL.pieces.find(function (p) { return p.id != pa }).id)
  }
  function placerAuto() { // pastilles pas encore placées → pièce liée à leur pièce Jeedom
    var mis = 0, sans = [], av = JSON.stringify(PL)
    placables().forEach(function (id) {
      if (PL.marqueurs[id]) return // déjà placée : ne bouge pas
      var c = D.candidats.find(function (x) { return x.id == id }), p = c && piece(lien(c.objid, c.objet))
      if (!p) return sans.push(libelle(id))
      var cx = 0, cy = 0, x0 = 100, x1 = 0, y0 = 100, y1 = 0, best = null
      p.pts.forEach(function (q) { cx += q[0] / p.pts.length; cy += q[1] / p.pts.length; x0 = Math.min(x0, q[0]); x1 = Math.max(x1, q[0]); y0 = Math.min(y0, q[1]); y1 = Math.max(y1, q[1]) })
      var pl = placables(), pris = Object.keys(PL.marqueurs).filter(function (k) { return pl.indexOf(k) >= 0 }).map(function (k) { return PL.marqueurs[k] }).filter(function (m) { return m.e == p.e })
      for (var x = x0 + 1.5; x < x1; x += 3) for (var y = y0 + 1.5; y < y1; y += 3) { // ponytail: grille de 3 %, assez pour quelques lampes par pièce
        if (!dans(x, y, p.pts) || pris.some(function (m) { return Math.abs(m.x - x) < 3 && Math.abs(m.y - y) < 3 })) continue
        var d = (x - cx) * (x - cx) + (y - cy) * (y - cy)
        if (!best || d < best[2]) best = [x, y, d]
      }
      if (!best) { // pièce pleine : au plus près du centre, à l'intérieur, en décalant de 1 % des autres
        var essais = [[cx, cy]]
        for (var x = x0 + 0.5; x < x1; x += 1) for (var y = y0 + 0.5; y < y1; y += 1) essais.push([x, y])
        var dedans = essais.filter(function (q) { return dans(q[0], q[1], p.pts) }), libre = dedans.filter(function (q) { return !pris.some(function (m) { return Math.abs(m.x - q[0]) < 1 && Math.abs(m.y - q[1]) < 1 }) })
        var tri = function (a, b) { return (a[0] - cx) * (a[0] - cx) + (a[1] - cy) * (a[1] - cy) - (b[0] - cx) * (b[0] - cx) - (b[1] - cy) * (b[1] - cy) }
        best = (libre.length ? libre : dedans).sort(tri)[0] || [cx, cy]
      }
      PL.marqueurs[id] = { e: p.e, x: Math.round(best[0] * 100) / 100, y: Math.round(best[1] * 100) / 100 }; mis++
    })
    if (mis) { UNDO.push(av); if (UNDO.length > 30) UNDO.shift() }
    dessiner()
    alerte(mis + ' {{placée(s)}}' + (sans.length ? ' ; {{sans pièce liée}} : ' + sans.join(', ') + ' ({{voir « Pièces Jeedom »}})' : '') + (mis ? '. {{Enregistrez le plan pour les garder.}}' : ''), sans.length ? 'warning' : 'success')
  }

  $('#ph_carte').on('click', function (ev) {
    if (GLISSE || !ET) return
    var p = pos(ev), $t = $(ev.target), m = $t.closest('.ph_m'), k = $t.attr('data-k')
    if ($t.hasClass('ph_coin')) return
    if (OUTIL == 'effacer') { if (m.length) { avant(); delete PL.marqueurs[m.attr('data-id')]; return dessiner() } if (k) supprimer(k, +$t.attr('data-i')); return }
    if (OUTIL == 'deplacer') {
      if (PLACER) { avant(); PL.marqueurs[PLACER] = { e: ET, x: p[0], y: p[1] }; PLACER = null; return dessiner() }
      SEL = k && !m.length ? { k: k, i: +$t.attr('data-i') } : null
      return dessiner()
    }
    if (TRACE.length >= 200) return alerte('{{200 coins au plus : terminez la forme.}}', 'warning')
    TRACE.push(p)
    dessiner()
  })
  $('#ph_carte').on('pointerdown', '.ph_m', function (ev) {
    if (OUTIL != 'deplacer' || PLACER) return
    var id = $(this).attr('data-id'), el = this, bouge = false
    ev.preventDefault(); this.setPointerCapture(ev.pointerId) // relâché hors de la fenêtre : le pointerup arrive quand même
    $(document).on('pointermove.ph', function (e) { if (!bouge) avant(); bouge = true; var p = pos(e); PL.marqueurs[id].x = p[0]; PL.marqueurs[id].y = p[1]; el.style.left = p[0] + '%'; el.style.top = p[1] + '%' })
      .on('pointerup.ph pointercancel.ph', function () { $(document).off('.ph'); if (bouge) { GLISSE = true; setTimeout(function () { GLISSE = false; dessiner() }, 0) } }) // le clic qui suit un glissé ne compte pas
  })
  $('#ph_carte').on('pointerdown', '.ph_coin', function (ev) { // coin de l'élément choisi
    var sp = selPts(), j = +$(this).attr('data-j'), el = this, bouge = false
    if (!sp) return
    ev.preventDefault(); ev.stopPropagation(); this.setPointerCapture(ev.pointerId)
    $(document).on('pointermove.ph', function (e) {
      if (!bouge) { avant(); if (sp[3]) sp[1].pts2 = JSON.parse(JSON.stringify(sp[1].pts)) } // escalier pas encore ajusté sur cet étage : son tracé propre part de celui de l'autre étage
      bouge = true
      var p = pos(e), l = sp[1][sp[2]]; l[j] = p; el.style.left = p[0] + '%'; el.style.top = p[1] + '%'
      var v = l.map(function (q) { return q[0] + ',' + q[1] }).join(' ')
      $('#ph_carte .ph_selt').attr('points', v); $('#ph_carte [data-k="' + SEL.k + '"][data-i="' + SEL.i + '"]').attr('points', v)
    }).on('pointerup.ph pointercancel.ph', function () { $(document).off('.ph'); if (bouge) { GLISSE = true; setTimeout(function () { GLISSE = false; dessiner() }, 0) } })
  })
  $('#ph_hors').on('click', '.ph_placer', function () { placer(String($(this).attr('data-id'))) })
  $('#ph_sel, #ph_pieces, #ph_passages, #ph_zones').on('click', '.ph_act', function () {
    var k = $(this).attr('data-k'), i = +$(this).attr('data-i'), f = $(this).attr('data-f')
    if (f == 'choisir') { OUTIL = 'deplacer'; $('#ph_outils a').removeClass('btn-primary').addClass('btn-default').filter('[data-outil="deplacer"]').addClass('btn-primary').removeClass('btn-default'); TRACE = []; PLACER = null; SEL = { k: k, i: i }; return dessiner() }
    if (f == 'nom') return nomPiece('{{Nom de la pièce}}', PL.pieces[i].nom, function (n) { PL.pieces[i].nom = n })
    if (f == 'modifier') return passage(null, i)
    if (f == 'suppr') supprimer(k, i)
  })
  $('#ph_liens').on('change', '.ph_lien', function () {
    var o = $(this).attr('data-o'), v = $(this).val()
    avant()
    if (v === '') delete PL.liens[o]; else PL.liens[o] = v == '-' ? '' : v
    dessiner(); guide()
  })
  $('#ph_annuler').on('click', function () {
    if (!UNDO.length) return
    PL = JSON.parse(UNDO.pop()); SEL = null; TRACE = []; PLACER = null; image(); guide()
  })
  $('#ph_placer_auto').on('click', function () { if (PL && D) placerAuto() })
  $('#ph_outils a').on('click', function () {
    OUTIL = $(this).attr('data-outil'); TRACE = []; PLACER = null; SEL = null
    $('#ph_outils a').removeClass('btn-primary').addClass('btn-default'); $(this).addClass('btn-primary').removeClass('btn-default')
    dessiner()
  })
  $('#ph_finir').on('click', finir)
  for (var i = 0; i < 24; i++) $('#ph_heure').append('<option value="' + i + '">' + i + ' h</option>')
  $('#ph_heure').val(new Date().getHours())
  $('#ph_heure, #ph_jour').on('change', dessiner)
  $('#ph_etage').on('change', function () { ET = $(this).val(); TRACE = []; PLACER = null; image() })
  $('#ph_etage_plus').on('click', function () {
    bootbox.prompt('{{Nom de l’étage (ex. Rez-de-chaussée). Il est ajouté en haut : réglez sa place avec les flèches.}}', function (n) {
      if (!n || !n.trim()) return
      if (PL.etages.length >= 20) return alerte('{{20 étages au plus.}}', 'warning')
      avant(); var e = { id: uid(), nom: n.trim().slice(0, 40) }; PL.etages.push(e); ET = e.id; TRACE = []; image()
    })
  })
  $('#ph_etage_nom').on('click', function () { var e = etage(ET); bootbox.prompt({ title: '{{Nom de l’étage}}', value: e.nom, callback: function (n) { if (n && n.trim() && n.trim() != e.nom) { avant(); e.nom = n.trim().slice(0, 40) } dessiner() } }) })
  $('#ph_etage_haut, #ph_etage_bas').on('click', function () { // ordre : du plus bas au plus haut
    var i = PL.etages.indexOf(etage(ET)), j = i + (this.id == 'ph_etage_haut' ? 1 : -1)
    if (j < 0 || j >= PL.etages.length) return
    avant(); var e = PL.etages[i]; PL.etages[i] = PL.etages[j]; PL.etages[j] = e; dessiner(); guide()
  })
  $('#ph_etage_moins').on('click', function () {
    bootbox.confirm('{{Supprimer cet étage, son image, ses pièces, ses passages, ses zones et les positions qui y sont ? (effectif à l’enregistrement du plan)}}', function (oui) {
      if (!oui) return
      avant()
      var e = ET, ids = PL.pieces.filter(function (o) { return o.e == e }).map(function (o) { return o.id })
      PL.pieces = PL.pieces.filter(function (o) { return o.e != e }); PL.zones = PL.zones.filter(function (o) { return o.e != e })
      PL.passages = PL.passages.filter(function (o) { return o.e != e && ids.indexOf(o.a) < 0 && ids.indexOf(o.b) < 0 })
      Object.keys(PL.marqueurs).forEach(function (id) { if (PL.marqueurs[id].e == e) delete PL.marqueurs[id] })
      PL.etages = PL.etages.filter(function (o) { return o.id != e }); ET = null; TRACE = []; SEL = null; nettoyer(); image()
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
        if (oui) { avant(); Object.keys(r).forEach(function (id) { PL.marqueurs[id] = { e: ET, x: r[id].x, y: r[id].y } }); dessiner() }
      })
    })
  })
  function planEnr() { var env = JSON.stringify(PL); ajax('plan_enregistrer', { plan: env }, function (r) { if (JSON.stringify(PL) != env) return alerte('{{Plan modifié pendant l’enregistrement : enregistrez-le à nouveau.}}', 'warning'); PL = r.plan; SEL = null; UNDO = []; habitudes(r.habitudes); guide(); alerte('{{Plan enregistré}}', 'success') }) }
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
    lire(); lireDet()
    var vide = DET.filter(function (d) { return !d.m.length || !$.trim(d.nom) }).map(function (d) { return d.nom || '?' })
    if (vide.length) return alerte('{{Détecteur sans nom ou sans lampe}} : ' + vide.join(', '), 'warning')
    if (!D) return // liste pas encore chargée (ou en erreur) : rien à enregistrer
    var mal = Object.keys(REG).filter(function (id) { return REG[id].plages.some(function (p) { return !plageOk(p) }) })
    if (mal.length) return alerte('{{Plage sans heure de début ou de fin (question 3)}} : ' + mal.map(libelle).join(', '), 'warning')
    var partent = Object.keys(SAUVE).filter(function (id) { return !D.suivi[id] })
    if (!partent.length) return enregistrer(ok)
    bootbox.confirm(esc(partent.length) + ' {{commande(s) retirée(s) du suivi}} : ' + esc(partent.map(libelle).join(', ')) + '. {{Leurs relevés seront effacés : si vous les cochez à nouveau, seul l’historique de Jeedom est repris (90 jours, quelques heures pour un historique lissé). Continuer ?}}', function (oui) { if (oui) enregistrer(ok) })
  }
  function enregistrer(ok) {
    ajax('enregistrer', { suivi: JSON.stringify(D.suivi), exclure: $('#ph_exclure').val(), exclusions: $('#ph_exclusions').val(), reponses: JSON.stringify(REP), regles: JSON.stringify(REG), detecteurs: JSON.stringify(DET) }, function (r) {
      alerte('{{Enregistré}} : ' + r.repris + ' {{changements repris de l’historique}}', 'success')
      $('#ph_precedent').toggle(!!r.precedent).attr('data-date', r.precedent || '')
      var partis = DET.filter(function (d) { return !r.detecteurs.some(function (e) { return e.nom == $.trim(d.nom) }) }).map(function (d) { return d.nom })
      if (partis.length) alerte('{{Détecteur(s) retiré(s) : leurs lampes n’existent plus dans Jeedom}} : ' + partis.join(', '), 'warning')
      SAUVE = $.extend({}, D.suivi); REP = $.extend({}, r.reponses); REG = $.extend(true, {}, r.regles); DET = $.extend(true, [], r.detecteurs); D.donnees = r.donnees; D.figes = r.figes // ce que la box a retenu
      habitudes(r.habitudes); guide(); histo()
      if (ok) ok()
    })
  }
  $('#ph_decouvrir').on('click', function () {
    var b = $(this)
    if (b.hasClass('disabled')) return // une découverte à la fois
    b.addClass('disabled'); $('#ph_decouverte').html('<p><i class="fas fa-spinner fa-spin"></i> {{Lecture des historiques…}}</p>')
    lireDet(); ajax('decouverte', { detecteurs: JSON.stringify(DET) }, function (r) { b.removeClass('disabled'); decouverte(r) }, function () { b.removeClass('disabled'); $('#ph_decouverte').empty() })
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
        D.candidats.forEach(function (c) { var s = D.suivi[c.id]; c.type = s && s.t ? s.t : c.tg; c.type0 = c.type == 'volet' ? 'volet' : c.tg }) // type du choix d'avant ; absente de ce choix : type d'après le type générique
        REP = $.extend({}, r.precedent.reponses || r.reponses); REG = $.extend(true, {}, r.precedent.regles || r.regles); DET = $.extend(true, [], r.precedent.detecteurs || r.detecteurs) // choix d'avant la 0.8 : réponses actuelles gardées
        $('#ph_exclure').val(r.precedent.exclure); $('#ph_exclusions').val(r.precedent.exclusions)
        liste(); dessiner(); if (H) habitudes(H) // étiquettes « sur détecteur » du choix d'avant
        alerte('{{Choix d’avant rechargé : cliquez sur Enregistrer pour y revenir.}}', 'info')
      })
    })
  })
  $('#ph_graz').on('click', function () {
    if (!D) return
    bootbox.confirm('{{Effacer toutes les réponses du Guide (lampe ou volet, doublons, groupes, sens des volets), ses règles et ses détecteurs ? Le choix des commandes, le plan et les relevés sont gardés. Rien n’est enregistré tant que vous ne cliquez pas sur Enregistrer ; ensuite, « Revenir au choix d’avant » les rend.}}', function (oui) {
      if (!oui) return
      lire(); DET.forEach(oublier); DET = []; REP = {}; REG = {}; NOTES = {}; SENS = {}; VU = []
      Object.keys(D.suivi).forEach(function (id) { delete D.suivi[id].i })
      changeGuide(); alerte('{{Guide remis à zéro : cliquez sur Enregistrer pour le garder.}}', 'info')
    })
  })
  $('#ph_recalculer').on('click', function () { ajax('recalculer', {}, habitudes) })
  $('#ph_effacer').on('click', function () {
    bootbox.confirm('{{Effacer les changements d’état relevés et les tendances calculées ? Le choix des commandes est gardé ; l’historique de Jeedom n’est pas repris.}}', function (oui) {
      if (oui) ajax('effacer', {}, function (r) { habitudes(r); alerte('{{Relevés effacés}}', 'success') })
    })
  })
  /* ---- Historique Jeedom des commandes suivies : proposé, jamais fait sans confirmation ; état d'avant gardé sur la box ---- */
  var HISTO = null
  function histo() { ajax('historiques_manquants', {}, function (r) { HISTO = r; histoAff() }) }
  function histoAff() {
    if (!HISTO || !$('#ph_ghisto').length) return
    var l = HISTO.liste, h = ''
    if (l.length) h += '<div class="well well-sm"><p><b>' + l.length + ' {{commande(s) suivie(s) sans historique utilisable dans Jeedom}}</b> : '
      + l.map(function (x) { return esc(x.nom) + ' <small>(' + (x.hist ? '{{lissé}}' : '{{non historisée}}') + ')</small>' }).join(', ') + '</p>'
      + '<p><small>{{Le plugin relève déjà leurs changements depuis leur enregistrement. L’historique de Jeedom permet en plus de les voir dans Jeedom et de les reprendre après « Tout effacer » ou une réinstallation.}}</small></p>'
      + '<p><a class="btn btn-sm btn-warning" id="ph_historiser"><i class="fas fa-history"></i> {{Activer leur historique…}}</a></p>'
      + '<details><summary style="cursor:pointer">{{Ou le faire à la main}}</summary><ol><li>{{Ouvrez l’équipement (Plugins, puis son plugin), onglet Commandes, roue dentée de la commande.}}</li>'
      + '<li>{{Onglet Configuration, partie Historique : cochez « Historiser ».}}</li>'
      + '<li>{{Mode de lissage : « Aucun » (sinon Jeedom remplace les valeurs par une moyenne horaire après quelques heures ; inutile pour une commande binaire).}}</li>'
      + '<li>{{Enregistrez. Pour plusieurs commandes à la fois : Analyse, Résumé domotique, case « Historiser » de chaque commande.}}</li></ol></details></div>'
    if (HISTO.avant) h += '<p><a class="btn btn-xs btn-default" id="ph_remettre"><i class="fas fa-undo"></i> {{Remettre l’historique comme avant}}</a> <small>(' + HISTO.avant + ' {{commande(s) changée(s) par le plugin}})</small></p>'
    $('#ph_ghisto').html(h)
  }
  $('#ph_gcontenu').on('click', '#ph_historiser', function () {
    var l = HISTO.liste
    bootbox.confirm('{{Le plugin va modifier ces commandes (et seulement celles-ci)}} : <ul>' + l.map(function (x) { return '<li>' + esc(x.nom) + '</li>' }).join('') + '</ul>'
      + '{{« Historiser » coché, lissage « Aucun » pour une commande non binaire. Leur réglage actuel est gardé sur la box : « Remettre l’historique comme avant » le rétablit. L’historique ne commence qu’à partir de maintenant. Continuer ?}}', function (oui) {
      if (!oui) return
      var b = $('#ph_historiser').addClass('disabled') // un seul envoi
      ajax('historiser', { ids: JSON.stringify(l.map(function (x) { return x.id })) }, function (r) {
        alerte('{{Historique activé}} : ' + l.filter(function (x) { return r[x.id] }).map(function (x) { return x.nom }).join(', '), 'success'); etatsHisto(r)
      }, function () { b.removeClass('disabled') })
    })
  })
  $('#ph_gcontenu').on('click', '#ph_remettre', function () {
    bootbox.confirm('{{Remettre le réglage d’historique d’avant le plugin sur les commandes qu’il a changées ? Une commande remise « non historisée » garde l’historique déjà enregistré tant que Jeedom ne le purge pas.}}', function (oui) {
      if (!oui) return
      $('#ph_remettre').addClass('disabled')
      ajax('remettre', {}, function (r) {
        alerte('{{Remis comme avant}} : ' + r.remis.join(', ') + (r.laisses.length ? ' ; {{laissées, réglées autrement depuis}} : ' + r.laisses.join(', ') : ''), r.laisses.length ? 'warning' : 'success'); etatsHisto(r.etats)
      }, function () { $('#ph_remettre').removeClass('disabled') })
    })
  })
  // Après historiser / remettre : état affiché des commandes changées, sans recharger la liste (les réponses pas encore enregistrées restent)
  function etatsHisto(e) {
    Object.keys(e || {}).forEach(function (id) { var c = cand(id); if (c) { c.hist = e[id].hist; c.lisse = e[id].lisse } })
    HISTO = null; liste(); guide(); histo() // HISTO vidé : pas d'ancien bouton « Activer » en attendant la réponse
  }
  /* ---- Widget : l'équipement du plugin ---- */
  var WID = null
  $('#ph_widget_ouvrir').on('click', function () {
    if ($('#ph_widget').is(':visible')) return $('#ph_widget').hide()
    ajax('widget', {}, function (r) {
      WID = r.id
      $('#ph_wobjet').html('<option value="0">{{Aucun}}</option>' + r.objets.map(function (o) { return '<option value="' + esc(o.id) + '">' + esc(o.nom) + '</option>' }).join('')).val(r.objet || 0)
      $('#ph_wvisible').prop('checked', !!r.visible); $('#ph_wpieces').prop('checked', !!r.pieces); $('#ph_widget').show()
    })
  })
  $('#ph_wenregistrer').on('click', function () {
    ajax('widget', { enregistrer: 1, objet: $('#ph_wobjet').val(), visible: $('#ph_wvisible').is(':checked') ? 1 : 0, pieces: $('#ph_wpieces').is(':checked') ? 1 : 0 }, function () { alerte('{{Widget enregistré}}', 'success') })
  })
  $('#ph_wavance').on('click', function () {
    if (!WID) return
    var u = 'index.php?v=d&modal=eqLogic.configure&eqLogic_id=' + WID
    if (window.jeeDialog) jeeDialog.dialog({ id: 'jee_modal', title: '{{Configuration du widget}}', contentUrl: u })
    else $('#md_modal').dialog({ title: '{{Configuration du widget}}' }).load(u).dialog('open')
  })
  charger()
  histo()
  ajax('habitudes', {}, habitudes)
})()

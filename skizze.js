/* Skizzenmodus - visuelle Aenderungswuensche direkt auf der laufenden Seite festhalten.
   Aktivierung: Alt+S, oder ?skizze=1 an die URL haengen.
   Aendert nichts dauerhaft. Neuladen setzt alles zurueck.

   Quelle dieser Datei ist skizze.js; in dashboard.html steht eine inline
   eingebettete Kopie. Nach jeder Aenderung: python3 skizze-einbetten.py */

(function () {
  'use strict';
  if (window.__skizze) return;

  var SPEICHER = 'skizze.stand';
  var FARBE = '#D85A30';
  var WERKZEUGE = [
    { id: 'auswahl',      name: 'Auswählen',   hinweis: 'Element anklicken' },
    { id: 'verschieben',  name: 'Verschieben', hinweis: 'ziehen rastet ein, Alt hält es an' },
    { id: 'abstand',      name: 'Abstand',     hinweis: '← → ↑ ↓ innen · Alt = aussen, auch negativ' },
    { id: 'groesse',      name: 'Grösse',      hinweis: '← → Breite, ↑ ↓ Höhe' },
    { id: 'schrift',      name: 'Schrift',     hinweis: '↑ ↓ Grösse, ← → Stärke' },
    { id: 'text',         name: 'Text',        hinweis: 'anklicken und tippen' },
    { id: 'reihenfolge',  name: 'Reihenfolge', hinweis: '← → tauscht mit Nachbar' },
    { id: 'duplizieren',  name: 'Duplizieren', hinweis: 'Element anklicken' },
    { id: 'ausblenden',   name: 'Ausblenden',  hinweis: 'Element anklicken' },
    { id: 'platzhalter',  name: 'Platzhalter', hinweis: 'Rechteck aufziehen' },
    { id: 'farbe',        name: 'Farbe',       hinweis: 'wählen, dann Farbe klicken' },
    { id: 'messen',       name: 'Messen',      hinweis: 'zwei Elemente anklicken' },
    { id: 'notiz',        name: 'Notiz',       hinweis: 'Element anklicken' }
  ];
  /* Werkzeuge, die auf mehrere Elemente gleichzeitig wirken. */
  var MEHRFACH = /^(verschieben|abstand|groesse|schrift|farbe|ausblenden|duplizieren)$/;

  var aktiv = false;
  var werkzeug = 'auswahl';
  var auswahl = [];
  var messZiel = null;
  var eintraege = [];
  var zaehler = 0;
  var host, wurzel, panel, liste, zielzeile, hover, umrisse, marke, ziehflaeche;
  var zieht = null;
  var hilfsElemente = [];
  var kandidaten = [];
  var linien = [];

  function erstes() { return auswahl[0] || null; }

  /* ---------- Selektor ---------- */

  function istEigen(el) {
    return !!(el && el.closest && el.closest('[data-skizze-eigen]'));
  }

  function teil(el) {
    if (el.id) return '#' + CSS.escape(el.id);
    var t = el.tagName.toLowerCase();
    var attr = ['data-tag', 'data-schicht', 'data-objekt', 'data-id', 'name', 'type'];
    for (var i = 0; i < attr.length; i++) {
      var w = el.getAttribute(attr[i]);
      if (w) return t + '[' + attr[i] + '="' + w + '"]';
    }
    var kl = (el.getAttribute('class') || '').trim().split(/\s+/)
      .filter(function (k) { return k && !/^(is-|js-|ng-|active$|selected$)/.test(k); })
      .slice(0, 2);
    if (kl.length) {
      var s = t + '.' + kl.map(function (k) { return CSS.escape(k); }).join('.');
      try {
        if (el.parentElement && el.parentElement.querySelectorAll(s).length === 1) return s;
      } catch (e) { /* ungueltiger Klassenname */ }
    }
    var gleich = [];
    var kinder = el.parentElement ? el.parentElement.children : [];
    for (var j = 0; j < kinder.length; j++) {
      if (kinder[j].tagName === el.tagName) gleich.push(kinder[j]);
    }
    if (gleich.length > 1) return t + ':nth-of-type(' + (gleich.indexOf(el) + 1) + ')';
    return t;
  }

  /* Ein Pfadteil traegt nur dann Bedeutung, wenn eine id, Klasse oder ein
     Attribut drinsteckt. Reines nth-of-type wird zwar oft eindeutig, sagt aber
     nichts darueber aus, welches Element gemeint war. */
  function hatKennung(pfad) {
    return pfad.some(function (s) { return /[#.\[]/.test(s); });
  }

  function selektor(el) {
    if (!el || el === document.body) return 'body';
    var pfad = [];
    var k = el;
    while (k && k !== document.body && pfad.length < 5) {
      var s = teil(k);
      pfad.unshift(s);
      if (s.charAt(0) === '#') break;
      if (hatKennung(pfad)) {
        try {
          if (document.querySelectorAll(pfad.join(' > ')).length === 1) break;
        } catch (e) { break; }
      }
      k = k.parentElement;
    }
    return pfad.join(' > ');
  }

  function kurz(el) {
    var t = el.tagName.toLowerCase();
    var txt = (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 24);
    return txt ? t + ' „' + txt + '“' : t;
  }

  /* ---------- Auswahl ---------- */

  function setzeAuswahl(els) {
    auswahl = els.filter(function (e) { return e && !istEigen(e); });
    zeichneAuswahl();
    zeigeZiel();
  }

  function schalteAuswahl(el) {
    var i = auswahl.indexOf(el);
    if (i >= 0) auswahl.splice(i, 1);
    else auswahl.push(el);
    zeichneAuswahl();
    zeigeZiel();
  }

  /* Alles auf derselben Ebene: gleiche Eltern, gleiches Tag. Trifft der Filter
     nur das Element selbst, werden alle Geschwister genommen. */
  function ganzeEbene() {
    var el = erstes();
    if (!el || !el.parentElement) return;
    var kinder = Array.prototype.slice.call(el.parentElement.children)
      .filter(function (k) { return !istEigen(k); });
    var gleiche = kinder.filter(function (k) { return k.tagName === el.tagName; });
    setzeAuswahl(gleiche.length > 1 ? gleiche : kinder);
    melde(auswahl.length + ' Elemente auf dieser Ebene gewählt');
  }

  /* ---------- Protokoll ---------- */

  function notiere(els, art, was, vorher, nachher, extra) {
    els = [].concat(els).filter(Boolean);
    zaehler++;
    var e = {
      nr: zaehler,
      art: art,
      was: was,
      vorher: vorher,
      nachher: nachher,
      selektor: els.length ? selektor(els[0]) : null,
      element: els.length ? kurz(els[0]) : null,
      ansicht: (location.hash || '#start').replace('#', '')
    };
    if (els.length > 1) {
      e.anzahl = els.length;
      e.selektoren = els.map(selektor);
    }
    if (extra) Object.keys(extra).forEach(function (k) { e[k] = extra[k]; });
    e._els = els;
    merkeZiel(e);
    eintraege.push(e);
    zeichneListe();
    sichere();
    return e;
  }

  /* Wohin das Element soll, nicht nur um wie viel es sich bewegt hat. Ein
     Verschieben per transform kostet im Layout keinen Platz, im gebauten
     Ergebnis aber schon -- ohne den Zielrahmen ist nicht zu erkennen, ob etwas
     in dieselbe Zeile gehoert oder in eine neue. */
  function rahmen(el) {
    var r = el.getBoundingClientRect();
    return {
      x: Math.round(r.left), y: Math.round(r.top + window.scrollY),
      w: Math.round(r.width), h: Math.round(r.height)
    };
  }

  function merkeZiel(e) {
    var els = (e._els || []).filter(function (el) { return el && el.getBoundingClientRect; });
    if (!els.length) return;
    e.ziel = rahmen(els[0]);
    /* Bei mehreren Elementen zaehlt jeder Rahmen: sonst ist nicht zu erkennen,
       wo die anderen landen sollen. */
    if (els.length > 1) e.ziele = els.map(rahmen);
  }

  function gleicheMenge(a, b) {
    if (a.length !== b.length) return false;
    for (var i = 0; i < a.length; i++) if (a[i] !== b[i]) return false;
    return true;
  }

  /* Wiederholte Aenderungen an derselben Menge buendeln: nur der Endwert zaehlt. */
  function notiereGebuendelt(els, art, was, vorher, nachher) {
    els = [].concat(els).filter(Boolean);
    for (var i = eintraege.length - 1; i >= 0; i--) {
      var e = eintraege[i];
      if (e.art === art && e.was === was && gleicheMenge(e._els, els)) {
        e.nachher = nachher;
        merkeZiel(e);
        zeichneListe();
        sichere();
        return e;
      }
    }
    return notiere(els, art, was, vorher, nachher);
  }

  function sichere() {
    try {
      sessionStorage.setItem(SPEICHER, JSON.stringify(alsDaten()));
    } catch (e) { /* Speicher voll oder gesperrt */ }
  }

  function alsDaten() {
    return eintraege.map(function (e) {
      var d = {
        nr: e.nr, art: e.art, was: e.was,
        selektor: e.selektor, element: e.element, ansicht: e.ansicht
      };
      if (e.anzahl) { d.anzahl = e.anzahl; d.selektoren = e.selektoren; }
      if (e.vorher !== undefined && e.vorher !== null) d.vorher = e.vorher;
      if (e.nachher !== undefined && e.nachher !== null) d.nachher = e.nachher;
      if (e.form) d.form = e.form;
      if (e.text) d.text = e.text;
      if (e.ziel) d.ziel = e.ziel;
      if (e.ziele) d.ziele = e.ziele;
      return d;
    });
  }

  /* ---------- Ruecknahme ---------- */

  var vorherStile = new WeakMap();

  function merkeStil(el, eigenschaft) {
    var m = vorherStile.get(el);
    if (!m) { m = {}; vorherStile.set(el, m); }
    if (!(eigenschaft in m)) m[eigenschaft] = el.style[eigenschaft] || '';
  }

  function machRueckgaengig(e) {
    (e._els || []).forEach(function (el) {
      if (!el) return;
      var m = vorherStile.get(el);
      if (m) Object.keys(m).forEach(function (k) { el.style[k] = m[k]; });
      if (e.art === 'text' && e.vorher != null) el.textContent = e.vorher;
    });
    (e.kopien || []).forEach(function (k) { if (k.parentElement) k.remove(); });
    if (e.knoten && e.knoten.parentElement) e.knoten.remove();
  }

  function stelleHer() {
    eintraege.forEach(machRueckgaengig);
    loeseHilfsstile();
    document.querySelectorAll('[data-skizze-platzhalter]').forEach(function (p) { p.remove(); });
    eintraege = [];
    zaehler = 0;
    setzeAuswahl([]);
    try { sessionStorage.removeItem(SPEICHER); } catch (e) { /* egal */ }
    zeichneListe();
    zeichnePins();
  }

  function nimmZurueck() {
    var e = eintraege.pop();
    if (!e) return;
    machRueckgaengig(e);
    if (!eintraege.length) loeseHilfsstile();
    zeichneListe();
    sichere();
    zeichnePins();
    zeichneAuswahl();
  }

  /* ---------- Ausgabe ---------- */

  function alsText() {
    if (!eintraege.length) return 'Keine Änderungen.';
    var zeilen = ['Skizze ' + new Date().toISOString().slice(0, 10) + ' · ' + location.pathname,
      'Fenster ' + window.innerWidth + ' × ' + window.innerHeight + ' px', ''];
    eintraege.forEach(function (e) {
      var z = e.nr + '. ' + e.art;
      if (e.was) z += ' · ' + e.was;
      if (e.anzahl) z += ' (' + e.anzahl + ' Elemente)';
      zeilen.push(z);
      (e.selektoren || [e.selektor]).forEach(function (s) {
        if (s) zeilen.push('   ' + s);
      });
      if (e.vorher != null && e.nachher != null) zeilen.push('   ' + e.vorher + ' -> ' + e.nachher);
      else if (e.nachher != null) zeilen.push('   ' + e.nachher);
      if (e.form) zeilen.push('   Rechteck ' + e.form.w + ' x ' + e.form.h + ' bei ' + e.form.x + ',' + e.form.y);
      (e.ziele || (e.ziel ? [e.ziel] : [])).forEach(function (z) {
        zeilen.push('   steht danach bei ' + z.x + ',' + z.y + ' und ist ' + z.w + ' x ' + z.h + ' px');
      });
      zeilen.push('');
    });
    return zeilen.join('\n');
  }

  function kopiere() {
    var nutz = alsText() + '\n\n```json\n' + JSON.stringify({
      seite: location.pathname + location.hash,
      fenster: { breite: window.innerWidth, hoehe: window.innerHeight },
      aenderungen: alsDaten()
    }, null, 2) + '\n```';
    navigator.clipboard.writeText(nutz).then(function () {
      melde('In die Zwischenablage kopiert');
    }, function () {
      melde('Kopieren blockiert, nutze Speichern');
    });
  }

  function speichere() {
    var name = 'skizze-' + new Date().toISOString().slice(0, 10) + '-' +
      (location.hash.replace('#', '') || 'start') + '.json';
    var blob = new Blob([JSON.stringify({
      seite: location.pathname + location.hash,
      erstellt: new Date().toISOString(),
      fenster: { breite: window.innerWidth, hoehe: window.innerHeight },
      aenderungen: alsDaten()
    }, null, 2)], { type: 'application/json' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    melde('Gespeichert als ' + name);
  }

  /* ---------- Oberflaeche ---------- */

  var STIL = '' +
    ':host{all:initial}' +
    '*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}' +
    '#panel{position:fixed;top:16px;right:16px;width:300px;max-height:calc(100vh - 32px);' +
    'display:flex;flex-direction:column;background:#1b1b1d;color:#e8e8e6;border:1px solid #3a3a3d;' +
    'border-radius:12px;z-index:2147483000;font-size:13px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.4)}' +
    '#kopf{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid #3a3a3d;cursor:move}' +
    '#kopf b{font-weight:500;font-size:13px}' +
    '#kopf span{font-size:11px;color:#8b8b88}' +
    '#zu{cursor:pointer;color:#8b8b88;padding:2px 6px;border-radius:4px}' +
    '#zu:hover{background:#2a2a2d;color:#e8e8e6}' +
    '#wz{display:grid;grid-template-columns:repeat(3,1fr);gap:4px;padding:10px 12px;border-bottom:1px solid #3a3a3d}' +
    '.wz{padding:6px 4px;border:1px solid #3a3a3d;border-radius:6px;background:transparent;color:#c9c9c6;' +
    'font-size:11px;cursor:pointer;text-align:center;line-height:1.3}' +
    '.wz:hover{background:#2a2a2d}' +
    '.wz.an{background:' + FARBE + ';border-color:' + FARBE + ';color:#fff}' +
    '#hinweis{padding:6px 12px;font-size:11px;color:#8b8b88;border-bottom:1px solid #3a3a3d;min-height:26px}' +
    '#ziel{padding:8px 12px;border-bottom:1px solid #3a3a3d;font-size:11px;color:#8b8b88;word-break:break-all;min-height:34px}' +
    '#ziel b{color:#e8e8e6;font-weight:400;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}' +
    '#ziel .mehr{color:#c9c9c6;display:block;margin-top:3px}' +
    '#farben{display:none;gap:4px;padding:8px 12px;border-bottom:1px solid #3a3a3d;flex-wrap:wrap}' +
    '#farben.an{display:flex}' +
    '.fb{width:22px;height:22px;border-radius:4px;border:1px solid #4a4a4d;cursor:pointer}' +
    '#liste{flex:1;overflow-y:auto;padding:4px 12px 10px}' +
    '.ae{padding:8px 0;border-bottom:1px solid #2a2a2d}' +
    '.ae:last-child{border-bottom:0}' +
    '.ae .k{display:flex;gap:6px;align-items:baseline}' +
    '.ae .n{flex:none;width:16px;height:16px;border-radius:50%;background:' + FARBE + ';color:#fff;' +
    'font-size:10px;line-height:16px;text-align:center}' +
    '.ae .t{font-size:12px;color:#e8e8e6}' +
    '.ae .s{font-size:10px;color:#7a7a78;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;' +
    'margin-top:2px;padding-left:22px;word-break:break-all}' +
    '.ae .w{font-size:11px;color:#a8a8a5;margin-top:2px;padding-left:22px}' +
    '#leer{color:#7a7a78;font-size:12px;padding:14px 0;text-align:center}' +
    '#fuss{display:flex;gap:6px;padding:10px 12px;border-top:1px solid #3a3a3d}' +
    '#fuss button{flex:1;padding:7px 4px;border-radius:6px;border:1px solid #3a3a3d;background:transparent;' +
    'color:#c9c9c6;font-size:11px;cursor:pointer}' +
    '#fuss button:hover{background:#2a2a2d}' +
    '#fuss button.haupt{background:' + FARBE + ';border-color:' + FARBE + ';color:#fff}' +
    '#hoverUmriss{position:fixed;pointer-events:none;border:1px dashed ' + FARBE + ';border-radius:3px;' +
    'z-index:2147482000;display:none}' +
    '.aus{position:fixed;pointer-events:none;border:1.5px solid ' + FARBE + ';border-radius:3px;' +
    'z-index:2147482050;background:rgba(216,90,48,.10)}' +
    '#marke{position:fixed;pointer-events:none;background:' + FARBE + ';color:#fff;font-size:11px;' +
    'padding:2px 6px;border-radius:4px;z-index:2147482100;display:none;white-space:nowrap}' +
    '#zieh{position:fixed;pointer-events:none;border:1.5px dashed ' + FARBE + ';background:rgba(216,90,48,.12);' +
    'border-radius:4px;z-index:2147482000;display:none}' +
    '.linie{position:fixed;pointer-events:none;z-index:2147482400;background:#7F77DD}' +
    '.linie.waag{height:1px}' +
    '.linie.senk{width:1px}' +
    '#meldung{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1b1b1d;color:#e8e8e6;' +
    'border:1px solid #3a3a3d;border-radius:8px;padding:8px 14px;font-size:12px;z-index:2147483100;display:none}';

  function baue() {
    host = document.createElement('div');
    host.setAttribute('data-skizze-eigen', '');
    host.style.cssText = 'all:initial;position:static';
    document.body.appendChild(host);
    wurzel = host.attachShadow({ mode: 'open' });

    var s = document.createElement('style');
    s.textContent = STIL;
    wurzel.appendChild(s);

    panel = document.createElement('div');
    panel.id = 'panel';
    panel.innerHTML =
      '<div id="kopf"><div><b>Skizzenmodus</b><br><span>Alt+S beendet</span></div><div id="zu">✕</div></div>' +
      '<div id="wz"></div>' +
      '<div id="hinweis"></div>' +
      '<div id="ziel">Kein Element gewählt</div>' +
      '<div id="farben"></div>' +
      '<div id="liste"></div>' +
      '<div id="fuss">' +
        '<button id="bZurueck">Zurück</button>' +
        '<button id="bReset">Verwerfen</button>' +
        '<button id="bDatei">Datei</button>' +
        '<button id="bKopie" class="haupt">Kopieren</button>' +
      '</div>';
    wurzel.appendChild(panel);

    hover = element('div', 'hoverUmriss');
    marke = element('div', 'marke');
    ziehflaeche = element('div', 'zieh');
    element('div', 'meldung');
    umrisse = [];

    var wz = wurzel.getElementById('wz');
    WERKZEUGE.forEach(function (w) {
      var b = document.createElement('button');
      b.className = 'wz' + (w.id === werkzeug ? ' an' : '');
      b.dataset.wz = w.id;
      b.textContent = w.name;
      b.onclick = function () { waehleWerkzeug(w.id); };
      wz.appendChild(b);
    });

    var fb = wurzel.getElementById('farben');
    ['#D85A30', '#378ADD', '#1D9E75', '#BA7517', '#E24B4A', '#7F77DD', '#888780', '#ffffff', '#1b1b1d']
      .forEach(function (c) {
        var d = document.createElement('div');
        d.className = 'fb';
        d.style.background = c;
        d.title = c;
        d.onclick = function (ev) { setzeFarbe(c, ev.shiftKey); };
        fb.appendChild(d);
      });

    wurzel.getElementById('zu').onclick = aus;
    wurzel.getElementById('bZurueck').onclick = nimmZurueck;
    wurzel.getElementById('bReset').onclick = function () {
      if (!eintraege.length || confirm('Alle Änderungen verwerfen?')) stelleHer();
    };
    wurzel.getElementById('bDatei').onclick = speichere;
    wurzel.getElementById('bKopie').onclick = kopiere;

    ziehePanel(wurzel.getElementById('kopf'));
    liste = wurzel.getElementById('liste');
    zielzeile = wurzel.getElementById('ziel');
    waehleWerkzeug(werkzeug);
    zeichneListe();
  }

  function element(tag, id) {
    var e = document.createElement(tag);
    e.id = id;
    wurzel.appendChild(e);
    return e;
  }

  function ziehePanel(griff) {
    var start = null;
    griff.addEventListener('mousedown', function (e) {
      if (e.target.id === 'zu') return;
      var r = panel.getBoundingClientRect();
      start = { x: e.clientX, y: e.clientY, l: r.left, t: r.top };
      e.preventDefault();
    });
    window.addEventListener('mousemove', function (e) {
      if (!start) return;
      panel.style.left = (start.l + e.clientX - start.x) + 'px';
      panel.style.top = (start.t + e.clientY - start.y) + 'px';
      panel.style.right = 'auto';
    });
    window.addEventListener('mouseup', function () { start = null; });
  }

  function melde(text) {
    if (!wurzel) return;
    var m = wurzel.getElementById('meldung');
    m.textContent = text;
    m.style.display = 'block';
    clearTimeout(m._t);
    m._t = setTimeout(function () { m.style.display = 'none'; }, 2200);
  }

  function waehleWerkzeug(id) {
    werkzeug = id;
    messZiel = null;
    wurzel.querySelectorAll('.wz').forEach(function (b) {
      b.classList.toggle('an', b.dataset.wz === id);
    });
    var w = WERKZEUGE.filter(function (x) { return x.id === id; })[0];
    var text = w ? w.hinweis : '';
    if (/^(groesse|abstand|verschieben)$/.test(id)) text += ' · Shift = 10 px';
    if (id === 'schrift') text += ' · Shift = 4 px';
    if (MEHRFACH.test(id)) text += ' · mehrere: Shift+Klick, G, H';
    if (id === 'farbe') text = 'Klick = Fläche, Shift+Klick = Schrift · mehrere: G, H';
    wurzel.getElementById('hinweis').textContent = text;
    wurzel.getElementById('farben').classList.toggle('an', id === 'farbe');
    document.body.style.cursor = (id === 'platzhalter' || id === 'messen') ? 'crosshair' : '';
  }

  function zeigeZiel() {
    if (!auswahl.length) { zielzeile.textContent = 'Kein Element gewählt'; return; }
    if (auswahl.length === 1) {
      var r = auswahl[0].getBoundingClientRect();
      zielzeile.innerHTML = '<b>' + esc(selektor(auswahl[0])) + '</b><br>' +
        Math.round(r.width) + ' × ' + Math.round(r.height) + ' px';
      return;
    }
    var namen = auswahl.slice(0, 4).map(function (el) { return esc(selektor(el)); });
    if (auswahl.length > 4) namen.push('… und ' + (auswahl.length - 4) + ' weitere');
    zielzeile.innerHTML = '<b>' + auswahl.length + ' Elemente gewählt</b>' +
      '<span class="mehr">' + namen.join('<br>') + '</span>';
  }

  function zeichneAuswahl() {
    umrisse.forEach(function (u) { u.remove(); });
    umrisse = auswahl.map(function (el) {
      var r = el.getBoundingClientRect();
      var d = document.createElement('div');
      d.className = 'aus';
      d.style.left = r.left + 'px';
      d.style.top = r.top + 'px';
      d.style.width = r.width + 'px';
      d.style.height = r.height + 'px';
      wurzel.appendChild(d);
      return d;
    });
  }

  function zeigeHover(el) {
    if (!el) { hover.style.display = 'none'; marke.style.display = 'none'; return; }
    var r = el.getBoundingClientRect();
    hover.style.display = 'block';
    hover.style.left = r.left + 'px';
    hover.style.top = r.top + 'px';
    hover.style.width = r.width + 'px';
    hover.style.height = r.height + 'px';
    marke.textContent = el.tagName.toLowerCase() + ' · ' + Math.round(r.width) + '×' + Math.round(r.height);
    marke.style.display = 'block';
    marke.style.left = r.left + 'px';
    marke.style.top = Math.max(0, r.top - 20) + 'px';
  }

  function aktualisiereAnzeige() {
    zeichneAuswahl();
    zeigeZiel();
    if (auswahl.length === 1) zeigeHover(auswahl[0]);
  }

  /* Wird ein Flex-Kind auf eine feste Groesse gesetzt, verteilt sich der frei
     werdende Platz auf die Geschwister -- die werden dann selbst breiter,
     obwohl sie niemand angefasst hat. Darum vorher alle auf ihre aktuelle
     Groesse festnageln. Steht nicht im Protokoll: das ist eine technische
     Massnahme, kein Aenderungswunsch. */
  function friereGeschwisterEin(el) {
    var p = el.parentElement;
    if (!p) return;
    var stil = getComputedStyle(p);
    if (!/flex/.test(stil.display)) return;
    var quer = /column/.test(stil.flexDirection);
    Array.prototype.forEach.call(p.children, function (k) {
      if (k === el || istEigen(k) || auswahl.indexOf(k) >= 0) return;
      var r = k.getBoundingClientRect();
      merkeStil(k, 'flexGrow');
      merkeStil(k, 'flexShrink');
      merkeStil(k, 'flexBasis');
      merkeStil(k, 'boxSizing');
      k.style.boxSizing = 'border-box';
      k.style.flexGrow = '0';
      k.style.flexShrink = '0';
      k.style.flexBasis = Math.round(quer ? r.height : r.width) + 'px';
      if (hilfsElemente.indexOf(k) < 0) hilfsElemente.push(k);
    });
  }

  /* Die eingefrorenen Geschwister haengen an keinem Protokolleintrag. Sind alle
     Aenderungen zurueckgenommen, muessen sie trotzdem wieder loslassen. */
  function loeseHilfsstile() {
    hilfsElemente.forEach(function (el) {
      var m = vorherStile.get(el);
      if (m) Object.keys(m).forEach(function (k) { el.style[k] = m[k]; });
    });
    hilfsElemente = [];
  }

  /* ---------- Ausrichtungshilfen ---------- */

  var TOLERANZ = 6;

  /* Einmal beim Anfassen sammeln: alles Sichtbare, was weder zur Auswahl noch
     zu deren Vorfahren oder Kindern gehoert. */
  function sammleKandidaten(ziele) {
    var out = [];
    var alle = document.body.querySelectorAll('*');
    for (var i = 0; i < alle.length; i++) {
      var el = alle[i];
      if (istEigen(el)) continue;
      var verwandt = false;
      for (var j = 0; j < ziele.length; j++) {
        if (el === ziele[j] || el.contains(ziele[j]) || ziele[j].contains(el)) { verwandt = true; break; }
      }
      if (verwandt) continue;
      var r = el.getBoundingClientRect();
      if (r.width < 8 || r.height < 8) continue;
      if (r.bottom < 0 || r.top > window.innerHeight || r.right < 0 || r.left > window.innerWidth) continue;
      out.push({ el: el, r: r });
      if (out.length >= 800) break;
    }
    return out;
  }

  function kantenX(r) { return [r.left, (r.left + r.right) / 2, r.right]; }
  function kantenY(r) { return [r.top, (r.top + r.bottom) / 2, r.bottom]; }

  /* Sucht die naechstliegende Flucht in beide Richtungen. */
  function findeFlucht(r) {
    var bx = null, by = null;
    var zx = kantenX(r), zy = kantenY(r);
    kandidaten.forEach(function (k) {
      kantenX(k.r).forEach(function (kx) {
        zx.forEach(function (x) {
          var d = kx - x;
          if (Math.abs(d) <= TOLERANZ && (!bx || Math.abs(d) < Math.abs(bx.d))) bx = { d: d, pos: kx, k: k };
        });
      });
      kantenY(k.r).forEach(function (ky) {
        zy.forEach(function (y) {
          var d = ky - y;
          if (Math.abs(d) <= TOLERANZ && (!by || Math.abs(d) < Math.abs(by.d))) by = { d: d, pos: ky, k: k };
        });
      });
    });
    return { x: bx, y: by };
  }

  function zeigeLinien(flucht, r) {
    versteckeLinien();
    if (flucht.x) {
      var d = document.createElement('div');
      d.className = 'linie senk';
      d.style.left = Math.round(flucht.x.pos) + 'px';
      d.style.top = Math.round(Math.min(r.top, flucht.x.k.r.top)) + 'px';
      d.style.height = Math.round(Math.max(r.bottom, flucht.x.k.r.bottom) - Math.min(r.top, flucht.x.k.r.top)) + 'px';
      wurzel.appendChild(d);
      linien.push(d);
    }
    if (flucht.y) {
      var h = document.createElement('div');
      h.className = 'linie waag';
      h.style.top = Math.round(flucht.y.pos) + 'px';
      h.style.left = Math.round(Math.min(r.left, flucht.y.k.r.left)) + 'px';
      h.style.width = Math.round(Math.max(r.right, flucht.y.k.r.right) - Math.min(r.left, flucht.y.k.r.left)) + 'px';
      wurzel.appendChild(h);
      linien.push(h);
    }
  }

  function versteckeLinien() {
    linien.forEach(function (l) { l.remove(); });
    linien = [];
  }

  /* Alles, was optisch auf derselben waagrechten Linie sitzt -- auch wenn es im
     HTML in einem anderen Container steht. Von verschachtelten Treffern bleibt
     der innerste, das ist das eigentliche Bedienelement. */
  function gleicheEbene() {
    var el = erstes();
    if (!el) return;
    var r = el.getBoundingClientRect();
    var mitte = (r.top + r.bottom) / 2;
    var treffer = sammleKandidaten([el]).filter(function (k) {
      var km = (k.r.top + k.r.bottom) / 2;
      var fluchtet = Math.abs(km - mitte) <= 4 || Math.abs(k.r.top - r.top) <= 4;
      /* Eine hohe Tabelle kann dieselbe Mittellinie haben wie ein Filterknopf,
         gehoert aber nicht dazu. Nur aehnlich hohe Elemente zaehlen. */
      var passt = Math.abs(k.r.height - r.height) <= Math.max(6, r.height * 0.25);
      return fluchtet && passt;
    }).map(function (k) { return k.el; });
    var innerste = treffer.filter(function (a) {
      return !treffer.some(function (b) { return b !== a && a.contains(b); });
    });
    setzeAuswahl([el].concat(innerste));
    melde(auswahl.length + ' Elemente auf derselben Linie gewählt');
  }

  function zeichneListe() {
    if (!liste) return;
    if (!eintraege.length) {
      liste.innerHTML = '<div id="leer">Noch nichts geändert</div>';
      return;
    }
    liste.innerHTML = eintraege.map(function (e) {
      var wert = '';
      if (e.vorher != null && e.nachher != null) wert = e.vorher + ' → ' + e.nachher;
      else if (e.nachher != null) wert = String(e.nachher);
      var titel = e.art + (e.was ? ' · ' + e.was : '');
      if (e.anzahl) titel += ' · ' + e.anzahl + ' Elemente';
      var sel = e.anzahl
        ? e.selektoren.slice(0, 3).join('<br>') + (e.anzahl > 3 ? '<br>… und ' + (e.anzahl - 3) + ' weitere' : '')
        : esc(e.selektor || '');
      if (e.anzahl) sel = e.selektoren.slice(0, 3).map(esc).join('<br>') +
        (e.anzahl > 3 ? '<br>… und ' + (e.anzahl - 3) + ' weitere' : '');
      return '<div class="ae"><div class="k"><span class="n">' + e.nr + '</span>' +
        '<span class="t">' + esc(titel) + '</span></div>' +
        (sel ? '<div class="s">' + sel + '</div>' : '') +
        (wert ? '<div class="w">' + esc(wert) + '</div>' : '') + '</div>';
    }).join('');
    liste.scrollTop = liste.scrollHeight;
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function zeichnePins() {
    document.querySelectorAll('[data-skizze-pin]').forEach(function (p) { p.remove(); });
    eintraege.forEach(function (e) {
      var el = (e._els || [])[0];
      if (e.art !== 'notiz' || !el || !el.isConnected) return;
      var r = el.getBoundingClientRect();
      var p = document.createElement('div');
      p.setAttribute('data-skizze-pin', '');
      p.setAttribute('data-skizze-eigen', '');
      p.textContent = e.nr;
      p.title = e.nachher || '';
      p.style.cssText = 'position:fixed;left:' + (r.right - 8) + 'px;top:' + (r.top - 8) +
        'px;width:20px;height:20px;border-radius:50%;background:' + FARBE + ';color:#fff;font-size:11px;' +
        'line-height:20px;text-align:center;z-index:2147481000;pointer-events:none;' +
        'font-family:-apple-system,sans-serif';
      document.body.appendChild(p);
    });
  }

  /* ---------- Werkzeuge ---------- */

  /* Haben die gewaehlten Elemente verschiedene Ausgangswerte, waere ein einzelner
     Wert gelogen. Dann die Spanne zeigen. */
  function spanne(werte, einheit) {
    var min = Math.min.apply(null, werte), max = Math.max.apply(null, werte);
    return (min === max ? String(min) : min + '–' + max) + (einheit || '');
  }

  function px(el, eigenschaft) {
    return Math.round(parseFloat(getComputedStyle(el)[eigenschaft]) || 0);
  }

  /* Welche Elemente eine Handlung trifft: die ganze Auswahl, wenn das
     angeklickte Element dazugehoert, sonst nur das angeklickte. */
  function betroffene(el) {
    return auswahl.indexOf(el) >= 0 ? auswahl.slice() : [el];
  }

  function setzeFarbe(c, schrift) {
    if (!auswahl.length) { melde('Erst ein Element wählen'); return; }
    var eig = schrift ? 'color' : 'backgroundColor';
    var alt = getComputedStyle(auswahl[0])[eig];
    auswahl.forEach(function (el) {
      merkeStil(el, eig);
      el.style[eig] = c;
    });
    notiereGebuendelt(auswahl.slice(), 'farbe', schrift ? 'Schrift' : 'Fläche', alt, c);
  }

  function dupliziere(els) {
    var kopien = els.map(function (el) {
      var k = el.cloneNode(true);
      k.setAttribute('data-skizze-kopie', '');
      k.style.outline = '1.5px dashed ' + FARBE;
      el.parentElement.insertBefore(k, el.nextSibling);
      return k;
    });
    var e = notiere(els, 'dupliziert', '', null, 'Kopie direkt dahinter eingefügt');
    e.kopien = kopien;
  }

  function blendeAus(els) {
    els.forEach(function (el) {
      merkeStil(el, 'display');
      el.style.display = 'none';
    });
    notiere(els, 'ausgeblendet', '', null, 'soll hier weg');
    setzeAuswahl([]);
    zeigeHover(null);
  }

  function bearbeiteText(el) {
    var alt = el.textContent;
    el.setAttribute('contenteditable', 'true');
    el.focus();
    var fertig = function () {
      el.removeAttribute('contenteditable');
      el.removeEventListener('blur', fertig);
      var neu = el.textContent;
      if (neu !== alt) notiere([el], 'text', '', alt.trim().slice(0, 60), neu.trim().slice(0, 60));
    };
    el.addEventListener('blur', fertig);
  }

  function setzeNotiz(el) {
    var t = prompt('Was soll hier passieren?');
    if (!t) return;
    notiere([el], 'notiz', '', null, t);
    zeichnePins();
  }

  function messe(el) {
    if (!messZiel) {
      messZiel = el;
      melde('Jetzt das zweite Element anklicken');
      return;
    }
    var a = messZiel.getBoundingClientRect();
    var b = el.getBoundingClientRect();
    var dx = b.left > a.right ? Math.round(b.left - a.right)
           : a.left > b.right ? Math.round(a.left - b.right) : 0;
    var dy = b.top > a.bottom ? Math.round(b.top - a.bottom)
           : a.top > b.bottom ? Math.round(a.top - b.bottom) : 0;
    melde('Abstand: ' + dx + ' px waagrecht, ' + dy + ' px senkrecht');
    notiere([messZiel], 'gemessen', 'Abstand zu ' + kurz(el), null, dx + ' px / ' + dy + ' px');
    messZiel = null;
  }

  function pfeil(e) {
    if (!auswahl.length) return false;
    var schritt = e.shiftKey ? 10 : 1;
    var richtung = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
    if (!richtung) return false;
    var delta = richtung[0] || richtung[1];

    if (werkzeug === 'verschieben') {
      var xs = [], ys = [];
      auswahl.forEach(function (el) {
        merkeStil(el, 'transform');
        var m = /translate\((-?[\d.]+)px,\s*(-?[\d.]+)px\)/.exec(el.style.transform || '');
        var x = (m ? parseFloat(m[1]) : 0) + richtung[0] * schritt;
        var y = (m ? parseFloat(m[2]) : 0) + richtung[1] * schritt;
        el.style.transform = 'translate(' + x + 'px, ' + y + 'px)';
        xs.push(x); ys.push(y);
      });
      notiereGebuendelt(auswahl.slice(), 'verschoben', '', 'ursprüngliche Position',
        (xs[0] >= 0 ? '+' : '') + xs[0] + ' px waagrecht, ' + (ys[0] >= 0 ? '+' : '') + ys[0] + ' px senkrecht');
      aktualisiereAnzeige();
      return true;
    }

    if (werkzeug === 'abstand') {
      var aussen = e.altKey;
      /* Innen wirkt symmetrisch: links und rechts, oder oben und unten.
         Aussen wirkt gerichtet -- der Pfeil zeigt, wohin das Element soll -- und
         darf ins Minus gehen. Sonst laesst sich ein Block, der oben gar keinen
         eigenen Abstand hat, nur nach unten schieben und nie nach oben. */
      var eig = aussen ? (richtung[0] ? 'marginLeft' : 'marginTop')
                       : 'padding' + (richtung[0] ? 'Inline' : 'Block');
      var lese = aussen ? eig : (richtung[0] ? 'paddingLeft' : 'paddingTop');
      var alteA = [], neueA = [];
      auswahl.forEach(function (el) { friereGeschwisterEin(el); });
      auswahl.forEach(function (el) {
        merkeStil(el, eig);
        var vorA = px(el, lese);
        var neuA = vorA + delta * schritt;
        if (!aussen) neuA = Math.max(0, neuA);
        el.style[eig] = neuA + 'px';
        alteA.push(vorA); neueA.push(neuA);
      });
      notiereGebuendelt(auswahl.slice(), aussen ? 'margin' : 'padding',
        aussen ? (richtung[0] ? 'links' : 'oben')
               : (richtung[0] ? 'links und rechts' : 'oben und unten'),
        spanne(alteA, 'px'), spanne(neueA, 'px'));
      aktualisiereAnzeige();
      return true;
    }

    if (werkzeug === 'groesse') {
      var eigG = richtung[0] ? 'width' : 'height';
      var alteG = [], neueG = [];
      auswahl.forEach(function (el) { friereGeschwisterEin(el); });
      auswahl.forEach(function (el) {
        /* Vor jedem Eingriff messen: sobald das Element nicht mehr mitwaechst,
           faellt es sonst auf seine Eigenbreite zurueck und springt. */
        var vor = Math.round(el.getBoundingClientRect()[eigG]);
        merkeStil(el, eigG);
        merkeStil(el, 'boxSizing');
        el.style.boxSizing = 'border-box';
        /* In Flex- und Grid-Layouts bestimmt der Container die Groesse. Ein
           blosses width bleibt dort wirkungslos. */
        var eltern = el.parentElement ? getComputedStyle(el.parentElement).display : '';
        if (/flex|grid/.test(eltern)) {
          if (richtung[0]) {
            merkeStil(el, 'flexGrow');
            merkeStil(el, 'flexShrink');
            merkeStil(el, 'flexBasis');
            el.style.flexGrow = '0';
            el.style.flexShrink = '0';
            /* flex: 1 setzt flex-basis auf 0% -- das schlaegt width, solange es steht. */
            el.style.flexBasis = 'auto';
          } else {
            merkeStil(el, 'alignSelf');
            el.style.alignSelf = 'flex-start';
          }
        }
        var neuG = Math.max(4, vor + delta * schritt);
        el.style[eigG] = neuG + 'px';
        alteG.push(vor); neueG.push(neuG);
        /* In Tabellen ueberstimmt das Auto-Layout ein blosses width. */
        if (/^(TD|TH)$/.test(el.tagName)) {
          var eigMin = richtung[0] ? 'minWidth' : 'minHeight';
          merkeStil(el, eigMin);
          el.style[eigMin] = neuG + 'px';
        }
      });
      notiereGebuendelt(auswahl.slice(), 'grösse', eigG === 'width' ? 'Breite' : 'Höhe',
        spanne(alteG, 'px'), spanne(neueG, 'px'));
      aktualisiereAnzeige();
      return true;
    }

    if (werkzeug === 'schrift') {
      /* Eine groessere Schrift macht das Element groesser -- in einer Flex-Leiste
         wuerden sonst die Nachbarn nachgeben. */
      auswahl.forEach(function (el) { friereGeschwisterEin(el); });
      if (richtung[1]) {
        var schrittS = e.shiftKey ? 4 : 1;
        var alteS = [], neueS = [];
        auswahl.forEach(function (el) {
          merkeStil(el, 'fontSize');
          var vorS = px(el, 'fontSize');
          /* Nach oben groesser: ArrowUp ist -1 auf der Achse. */
          var neuS = Math.max(6, vorS - richtung[1] * schrittS);
          el.style.fontSize = neuS + 'px';
          alteS.push(vorS); neueS.push(neuS);
        });
        notiereGebuendelt(auswahl.slice(), 'schrift', 'Grösse',
          spanne(alteS, 'px'), spanne(neueS, 'px'));
      } else {
        var lies = function (el) {
          var w = parseInt(getComputedStyle(el).fontWeight, 10);
          return isNaN(w) ? 400 : w;
        };
        var alteW = [], neueW = [];
        auswahl.forEach(function (el) {
          merkeStil(el, 'fontWeight');
          var vorW = lies(el);
          var neuW = Math.min(900, Math.max(100, Math.round(vorW / 100) * 100 + richtung[0] * 100));
          el.style.fontWeight = String(neuW);
          alteW.push(vorW); neueW.push(neuW);
        });
        notiereGebuendelt(auswahl.slice(), 'schrift', 'Stärke', spanne(alteW), spanne(neueW));
      }
      aktualisiereAnzeige();
      return true;
    }

    if (werkzeug === 'reihenfolge' && richtung[0]) {
      var el0 = auswahl[0];
      var p = el0.parentElement;
      if (!p) return true;
      var alt = Array.prototype.indexOf.call(p.children, el0);
      if (richtung[0] < 0 && el0.previousElementSibling) {
        p.insertBefore(el0, el0.previousElementSibling);
      } else if (richtung[0] > 0 && el0.nextElementSibling) {
        p.insertBefore(el0.nextElementSibling, el0);
      } else return true;
      var neu = Array.prototype.indexOf.call(p.children, el0);
      notiereGebuendelt([el0], 'umsortiert', 'Position im Container',
        'Stelle ' + (alt + 1), 'Stelle ' + (neu + 1));
      aktualisiereAnzeige();
      return true;
    }

    return false;
  }

  /* ---------- Ereignisse ---------- */

  function unterMaus(e) {
    var el = document.elementFromPoint(e.clientX, e.clientY);
    if (!el || istEigen(el)) el = e.target;
    if (!el || istEigen(el) || el === document.documentElement || el === document) return null;
    return el;
  }

  function beiBewegung(e) {
    if (!aktiv || zieht) return;
    zeigeHover(unterMaus(e));
  }

  function beiKlick(e) {
    if (!aktiv || istEigen(e.target)) return;
    e.preventDefault();
    e.stopPropagation();
    var el = unterMaus(e);
    if (!el) return;

    if (werkzeug === 'duplizieren') { dupliziere(betroffene(el)); return; }
    if (werkzeug === 'ausblenden') { blendeAus(betroffene(el)); return; }
    if (werkzeug === 'notiz') { setzeNotiz(el); return; }
    if (werkzeug === 'messen') { messe(el); return; }

    if ((e.shiftKey || e.metaKey || e.ctrlKey) && MEHRFACH.test(werkzeug)) {
      schalteAuswahl(el);
      return;
    }

    setzeAuswahl([el]);
    if (werkzeug === 'text') bearbeiteText(el);
  }

  function beiRunter(e) {
    if (!aktiv || istEigen(e.target)) return;
    /* Immer abfangen: ein <select> oeffnet sein Menue schon bei mousedown, und
       Eingabefelder wuerden den Fokus greifen. Beides macht die Elemente sonst
       unbearbeitbar. */
    e.preventDefault();
    e.stopPropagation();
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();

    if (werkzeug === 'platzhalter') {
      zieht = { x: e.clientX, y: e.clientY, unter: unterMaus(e) };
      ziehflaeche.style.display = 'block';
      return;
    }
    if (werkzeug === 'verschieben') {
      var el = unterMaus(e);
      if (!el) return;
      if (!(e.shiftKey || e.metaKey || e.ctrlKey) && auswahl.indexOf(el) < 0) setzeAuswahl([el]);
      var start = auswahl.map(function (k) {
        merkeStil(k, 'transform');
        var m = /translate\((-?[\d.]+)px,\s*(-?[\d.]+)px\)/.exec(k.style.transform || '');
        return { el: k, dx: m ? parseFloat(m[1]) : 0, dy: m ? parseFloat(m[2]) : 0 };
      });
      kandidaten = sammleKandidaten(auswahl);
      zieht = { x: e.clientX, y: e.clientY, start: start };
    }
  }

  function beiZug(e) {
    if (!zieht) return;
    if (werkzeug === 'platzhalter') {
      var l = Math.min(zieht.x, e.clientX), t = Math.min(zieht.y, e.clientY);
      ziehflaeche.style.left = l + 'px';
      ziehflaeche.style.top = t + 'px';
      ziehflaeche.style.width = Math.abs(e.clientX - zieht.x) + 'px';
      ziehflaeche.style.height = Math.abs(e.clientY - zieht.y) + 'px';
      return;
    }
    if (zieht.start) {
      var rohX = e.clientX - zieht.x, rohY = e.clientY - zieht.y;
      var setze = function (kx, ky) {
        zieht.start.forEach(function (s) {
          s.el.style.transform = 'translate(' + Math.round(s.dx + kx) + 'px, ' +
            Math.round(s.dy + ky) + 'px)';
        });
      };
      setze(rohX, rohY);
      /* Alt haelt das Einrasten an, wie in den Zeichenprogrammen ueblich. */
      if (!e.altKey && kandidaten.length) {
        var r = zieht.start[0].el.getBoundingClientRect();
        var flucht = findeFlucht(r);
        setze(rohX + (flucht.x ? flucht.x.d : 0), rohY + (flucht.y ? flucht.y.d : 0));
        zeigeLinien(flucht, zieht.start[0].el.getBoundingClientRect());
      } else {
        versteckeLinien();
      }
      zeichneAuswahl();
    }
  }

  function beiHoch(e) {
    if (!zieht) return;
    if (werkzeug === 'platzhalter') {
      var l = Math.min(zieht.x, e.clientX), t = Math.min(zieht.y, e.clientY);
      var w = Math.abs(e.clientX - zieht.x), h = Math.abs(e.clientY - zieht.y);
      ziehflaeche.style.display = 'none';
      if (w > 8 && h > 8) legePlatzhalter(l, t, w, h, zieht.unter);
    } else if (zieht.start) {
      var s0 = zieht.start[0];
      var m = /translate\((-?[\d.]+)px,\s*(-?[\d.]+)px\)/.exec(s0.el.style.transform || '');
      var x = m ? Math.round(parseFloat(m[1])) : 0;
      var y = m ? Math.round(parseFloat(m[2])) : 0;
      /* Ein, zwei Pixel beim Anklicken sind keine Absicht und wuerden das
         Protokoll mit Nichtigkeiten fuellen. */
      if (Math.abs(x - s0.dx) >= 3 || Math.abs(y - s0.dy) >= 3) {
        notiereGebuendelt(zieht.start.map(function (s) { return s.el; }), 'verschoben', '',
          'ursprüngliche Position',
          (x >= 0 ? '+' : '') + x + ' px waagrecht, ' + (y >= 0 ? '+' : '') + y + ' px senkrecht');
      } else {
        zieht.start.forEach(function (s) {
          s.el.style.transform = s.dx || s.dy ? 'translate(' + s.dx + 'px, ' + s.dy + 'px)' : '';
        });
        zeichneAuswahl();
      }
    }
    zieht = null;
    versteckeLinien();
    kandidaten = [];
  }

  function legePlatzhalter(l, t, w, h, unter) {
    var text = prompt('Beschriftung für den Platzhalter:', 'neuer Button');
    if (text === null) return;
    var d = document.createElement('div');
    d.setAttribute('data-skizze-platzhalter', '');
    d.setAttribute('data-skizze-eigen', '');
    d.textContent = text;
    d.style.cssText = 'position:fixed;left:' + l + 'px;top:' + t + 'px;width:' + w + 'px;height:' + h +
      'px;border:1.5px dashed ' + FARBE + ';background:rgba(216,90,48,.12);color:' + FARBE +
      ';border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;' +
      'z-index:2147481500;pointer-events:none;font-family:-apple-system,sans-serif;text-align:center;padding:2px';
    document.body.appendChild(d);
    var e = notiere([unter || document.body], 'platzhalter', text, null,
      Math.round(w) + ' × ' + Math.round(h) + ' px');
    e.form = { x: Math.round(l), y: Math.round(t), w: Math.round(w), h: Math.round(h) };
    e.text = text;
    e.knoten = d;
    zeichneListe();
  }

  function beiTaste(e) {
    if (e.altKey && (e.key === 's' || e.key === 'S' || e.code === 'KeyS')) {
      e.preventDefault();
      umschalten();
      return;
    }
    if (!aktiv) return;
    if (e.target && e.target.getAttribute && e.target.getAttribute('contenteditable')) return;

    if (e.key === 'Escape') { aus(); return; }
    if ((e.metaKey || e.ctrlKey) && e.key === 'z') { e.preventDefault(); nimmZurueck(); return; }

    if (!e.metaKey && !e.ctrlKey && (e.key === 'g' || e.key === 'G')) {
      e.preventDefault();
      ganzeEbene();
      return;
    }

    if (!e.metaKey && !e.ctrlKey && (e.key === 'h' || e.key === 'H')) {
      e.preventDefault();
      gleicheEbene();
      return;
    }

    var nr = parseInt(e.key, 10);
    if (!e.metaKey && !e.ctrlKey && nr >= 1 && nr <= 9 && WERKZEUGE[nr - 1]) {
      waehleWerkzeug(WERKZEUGE[nr - 1].id);
      return;
    }
    if (pfeil(e)) e.preventDefault();
  }

  function beiScroll() {
    if (!aktiv) return;
    zeichneAuswahl();
    zeichnePins();
  }

  /* ---------- An und aus ---------- */

  function an() {
    if (aktiv) return;
    aktiv = true;
    if (!host) baue();
    host.style.display = '';
    document.addEventListener('mousemove', beiBewegung, true);
    document.addEventListener('click', beiKlick, true);
    document.addEventListener('mousedown', beiRunter, true);
    document.addEventListener('mousemove', beiZug, true);
    document.addEventListener('mouseup', beiHoch, true);
    window.addEventListener('scroll', beiScroll, true);
    window.addEventListener('resize', beiScroll);
    melde('Skizzenmodus an · Zahlen 1–9 wählen Werkzeuge, G und H wählen mehrere');
  }

  function aus() {
    if (!aktiv) return;
    aktiv = false;
    document.removeEventListener('mousemove', beiBewegung, true);
    document.removeEventListener('click', beiKlick, true);
    document.removeEventListener('mousedown', beiRunter, true);
    document.removeEventListener('mousemove', beiZug, true);
    document.removeEventListener('mouseup', beiHoch, true);
    window.removeEventListener('scroll', beiScroll, true);
    window.removeEventListener('resize', beiScroll);
    document.body.style.cursor = '';
    zeigeHover(null);
    if (host) host.style.display = 'none';
  }

  function umschalten() { aktiv ? aus() : an(); }

  document.addEventListener('keydown', beiTaste, true);

  window.__skizze = {
    an: an, aus: aus, umschalten: umschalten,
    text: alsText, daten: alsDaten, kopieren: kopiere
  };

  if (/[?&]skizze=1/.test(location.search)) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', an);
    } else an();
  }
})();

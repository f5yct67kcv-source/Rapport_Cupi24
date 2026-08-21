/* Skizzenmodus - visuelle Aenderungswuensche direkt auf der laufenden Seite festhalten.
   Aktivierung: Alt+S, oder ?skizze=1 an die URL haengen.
   Aendert nichts dauerhaft. Neuladen setzt alles zurueck. */

(function () {
  'use strict';
  if (window.__skizze) return;

  var SPEICHER = 'skizze.stand';
  var FARBE = '#D85A30';
  var WERKZEUGE = [
    { id: 'auswahl',      name: 'Auswählen',   hinweis: 'Element anklicken' },
    { id: 'verschieben',  name: 'Verschieben', hinweis: 'anklicken, dann ziehen oder ← → ↑ ↓' },
    { id: 'abstand',      name: 'Abstand',     hinweis: 'anklicken, dann ← → ↑ ↓ · Alt = aussen' },
    { id: 'groesse',      name: 'Grösse',      hinweis: 'anklicken, dann ← → Breite, ↑ ↓ Höhe' },
    { id: 'text',         name: 'Text',        hinweis: 'anklicken und tippen' },
    { id: 'reihenfolge',  name: 'Reihenfolge', hinweis: 'anklicken, dann ← → tauscht mit Nachbar' },
    { id: 'duplizieren',  name: 'Duplizieren', hinweis: 'Element anklicken' },
    { id: 'ausblenden',   name: 'Ausblenden',  hinweis: 'Element anklicken' },
    { id: 'platzhalter',  name: 'Platzhalter', hinweis: 'Rechteck aufziehen' },
    { id: 'farbe',        name: 'Farbe',       hinweis: 'Element wählen, dann Farbe' },
    { id: 'messen',       name: 'Messen',      hinweis: 'zwei Elemente anklicken' },
    { id: 'notiz',        name: 'Notiz',       hinweis: 'Element anklicken' }
  ];

  var aktiv = false;
  var werkzeug = 'auswahl';
  var ziel = null;
  var messZiel = null;
  var eintraege = [];
  var zaehler = 0;
  var host, wurzel, panel, liste, zielzeile, umriss, marke, messlinie, ziehflaeche;
  var zieht = null;

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

  /* ---------- Protokoll ---------- */

  function notiere(el, art, was, vorher, nachher, extra) {
    zaehler++;
    var e = {
      nr: zaehler,
      art: art,
      was: was,
      vorher: vorher,
      nachher: nachher,
      selektor: el ? selektor(el) : null,
      element: el ? kurz(el) : null,
      ansicht: (location.hash || '#start').replace('#', '')
    };
    if (extra) Object.keys(extra).forEach(function (k) { e[k] = extra[k]; });
    e._el = el;
    eintraege.push(e);
    zeichneListe();
    sichere();
    return e;
  }

  function letzterFuer(el, was) {
    for (var i = eintraege.length - 1; i >= 0; i--) {
      if (eintraege[i]._el === el && eintraege[i].was === was) return eintraege[i];
    }
    return null;
  }

  /* Wiederholte Aenderungen am selben Element buendeln: nur der Endwert zaehlt. */
  function notiereGebuendelt(el, art, was, vorher, nachher) {
    var vor = letzterFuer(el, was);
    if (vor && vor.art === art) {
      vor.nachher = nachher;
      zeichneListe();
      sichere();
      return vor;
    }
    return notiere(el, art, was, vorher, nachher);
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
      if (e.vorher !== undefined && e.vorher !== null) d.vorher = e.vorher;
      if (e.nachher !== undefined && e.nachher !== null) d.nachher = e.nachher;
      if (e.form) d.form = e.form;
      if (e.text) d.text = e.text;
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

  function stelleHer() {
    eintraege.forEach(function (e) {
      var el = e._el;
      if (!el) return;
      var m = vorherStile.get(el);
      if (m) Object.keys(m).forEach(function (k) { el.style[k] = m[k]; });
      if (e.art === 'text' && e.vorher != null) el.textContent = e.vorher;
      if (e.art === 'dupliziert' && e.kopie && e.kopie.parentElement) e.kopie.remove();
      el.removeAttribute('data-skizze-markiert');
    });
    document.querySelectorAll('[data-skizze-platzhalter]').forEach(function (p) { p.remove(); });
    eintraege = [];
    zaehler = 0;
    ziel = null;
    try { sessionStorage.removeItem(SPEICHER); } catch (e) { /* egal */ }
    zeichneListe();
    zeigeZiel();
    setzeUmriss(null);
  }

  function nimmZurueck() {
    var e = eintraege.pop();
    if (!e) return;
    var el = e._el;
    if (el) {
      var m = vorherStile.get(el);
      if (m) Object.keys(m).forEach(function (k) { el.style[k] = m[k]; });
      if (e.art === 'text' && e.vorher != null) el.textContent = e.vorher;
      if (e.art === 'dupliziert' && e.kopie && e.kopie.parentElement) e.kopie.remove();
      el.removeAttribute('data-skizze-markiert');
    }
    if (e.art === 'platzhalter' && e.knoten && e.knoten.parentElement) e.knoten.remove();
    zeichneListe();
    sichere();
    zeichnePins();
  }

  /* ---------- Ausgabe ---------- */

  function alsText() {
    if (!eintraege.length) return 'Keine Änderungen.';
    var zeilen = ['Skizze ' + new Date().toISOString().slice(0, 10) + ' · ' + location.pathname, ''];
    eintraege.forEach(function (e) {
      var z = e.nr + '. ' + e.art;
      if (e.was) z += ' · ' + e.was;
      zeilen.push(z);
      if (e.selektor) zeilen.push('   ' + e.selektor);
      if (e.vorher != null && e.nachher != null) zeilen.push('   ' + e.vorher + ' -> ' + e.nachher);
      else if (e.nachher != null) zeilen.push('   ' + e.nachher);
      if (e.form) zeilen.push('   Rechteck ' + e.form.w + ' x ' + e.form.h + ' bei ' + e.form.x + ',' + e.form.y);
      zeilen.push('');
    });
    return zeilen.join('\n');
  }

  function kopiere() {
    var nutz = alsText() + '\n\n```json\n' + JSON.stringify(alsDaten(), null, 2) + '\n```';
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
    '#umriss{position:fixed;pointer-events:none;border:1.5px solid ' + FARBE + ';border-radius:3px;' +
    'z-index:2147482000;display:none}' +
    '#marke{position:fixed;pointer-events:none;background:' + FARBE + ';color:#fff;font-size:11px;' +
    'padding:2px 6px;border-radius:4px;z-index:2147482100;display:none;white-space:nowrap}' +
    '#zieh{position:fixed;pointer-events:none;border:1.5px dashed ' + FARBE + ';background:rgba(216,90,48,.12);' +
    'border-radius:4px;z-index:2147482000;display:none}' +
    '#mess{position:fixed;pointer-events:none;z-index:2147482100;display:none;font-size:11px}' +
    '#meldung{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1b1b1d;color:#e8e8e6;' +
    'border:1px solid #3a3a3d;border-radius:8px;padding:8px 14px;font-size:12px;z-index:2147483100;display:none}' +
    '.pin{position:absolute;width:20px;height:20px;border-radius:50%;background:' + FARBE + ';color:#fff;' +
    'font-size:11px;line-height:20px;text-align:center;z-index:2147481000;pointer-events:none}';

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

    umriss = element('div', 'umriss');
    marke = element('div', 'marke');
    ziehflaeche = element('div', 'zieh');
    messlinie = element('div', 'mess');
    element('div', 'meldung');

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
    var m = wurzel.getElementById('meldung');
    m.textContent = text;
    m.style.display = 'block';
    clearTimeout(m._t);
    m._t = setTimeout(function () { m.style.display = 'none'; }, 2200);
  }

  function waehleWerkzeug(id) {
    werkzeug = id;
    messZiel = null;
    messlinie.style.display = 'none';
    wurzel.querySelectorAll('.wz').forEach(function (b) {
      b.classList.toggle('an', b.dataset.wz === id);
    });
    var w = WERKZEUGE.filter(function (x) { return x.id === id; })[0];
    var text = w ? w.hinweis : '';
    if (id === 'groesse' || id === 'abstand' || id === 'verschieben') text += ' · Shift = 10 px';
    wurzel.getElementById('hinweis').textContent = text;
    wurzel.getElementById('farben').classList.toggle('an', id === 'farbe');
    if (id === 'farbe') {
      wurzel.getElementById('hinweis').textContent = 'Klick = Fläche, Shift+Klick = Schrift';
    }
    document.body.style.cursor = (id === 'platzhalter' || id === 'messen') ? 'crosshair' : '';
  }

  function zeigeZiel() {
    if (!ziel) { zielzeile.textContent = 'Kein Element gewählt'; return; }
    var r = ziel.getBoundingClientRect();
    zielzeile.innerHTML = '<b>' + selektor(ziel).replace(/</g, '&lt;') + '</b><br>' +
      Math.round(r.width) + ' × ' + Math.round(r.height) + ' px';
  }

  function setzeUmriss(el) {
    if (!el) { umriss.style.display = 'none'; marke.style.display = 'none'; return; }
    var r = el.getBoundingClientRect();
    umriss.style.cssText += ';display:block;left:' + r.left + 'px;top:' + r.top + 'px;width:' +
      r.width + 'px;height:' + r.height + 'px';
    marke.textContent = el.tagName.toLowerCase() + ' · ' + Math.round(r.width) + '×' + Math.round(r.height);
    marke.style.display = 'block';
    marke.style.left = r.left + 'px';
    marke.style.top = Math.max(0, r.top - 20) + 'px';
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
      return '<div class="ae"><div class="k"><span class="n">' + e.nr + '</span>' +
        '<span class="t">' + esc(e.art + (e.was ? ' · ' + e.was : '')) + '</span></div>' +
        (e.selektor ? '<div class="s">' + esc(e.selektor) + '</div>' : '') +
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
      if (e.art !== 'notiz' || !e._el || !e._el.isConnected) return;
      var r = e._el.getBoundingClientRect();
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

  function px(el, eigenschaft) {
    return Math.round(parseFloat(getComputedStyle(el)[eigenschaft]) || 0);
  }

  function setzeFarbe(c, schrift) {
    if (!ziel) { melde('Erst ein Element wählen'); return; }
    var eig = schrift ? 'color' : 'backgroundColor';
    merkeStil(ziel, eig);
    var alt = getComputedStyle(ziel)[eig];
    ziel.style[eig] = c;
    notiereGebuendelt(ziel, 'farbe', schrift ? 'Schrift' : 'Fläche', alt, c);
  }

  function dupliziere(el) {
    var kopie = el.cloneNode(true);
    kopie.setAttribute('data-skizze-kopie', '');
    kopie.style.outline = '1.5px dashed ' + FARBE;
    el.parentElement.insertBefore(kopie, el.nextSibling);
    var e = notiere(el, 'dupliziert', '', null, 'Kopie direkt dahinter eingefügt');
    e.kopie = kopie;
  }

  function blendeAus(el) {
    merkeStil(el, 'display');
    el.style.display = 'none';
    notiere(el, 'ausgeblendet', '', null, 'soll hier weg');
    setzeUmriss(null);
  }

  function bearbeiteText(el) {
    var alt = el.textContent;
    el.setAttribute('contenteditable', 'true');
    el.focus();
    var fertig = function () {
      el.removeAttribute('contenteditable');
      el.removeEventListener('blur', fertig);
      var neu = el.textContent;
      if (neu !== alt) notiere(el, 'text', '', alt.trim().slice(0, 60), neu.trim().slice(0, 60));
    };
    el.addEventListener('blur', fertig);
  }

  function setzeNotiz(el) {
    var t = prompt('Was soll hier passieren?');
    if (!t) return;
    notiere(el, 'notiz', '', null, t);
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
    notiere(messZiel, 'gemessen', 'Abstand zu ' + kurz(el), null, dx + ' px / ' + dy + ' px');
    messZiel = null;
  }

  function pfeil(e) {
    if (!ziel) return false;
    var schritt = e.shiftKey ? 10 : 1;
    var richtung = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
    if (!richtung) return false;

    if (werkzeug === 'verschieben') {
      merkeStil(ziel, 'transform');
      var m = /translate\((-?[\d.]+)px,\s*(-?[\d.]+)px\)/.exec(ziel.style.transform || '');
      var x = (m ? parseFloat(m[1]) : 0) + richtung[0] * schritt;
      var y = (m ? parseFloat(m[2]) : 0) + richtung[1] * schritt;
      ziel.style.transform = 'translate(' + x + 'px, ' + y + 'px)';
      notiereGebuendelt(ziel, 'verschoben', '', 'ursprüngliche Position',
        (x >= 0 ? '+' : '') + x + ' px waagrecht, ' + (y >= 0 ? '+' : '') + y + ' px senkrecht');
      return true;
    }

    if (werkzeug === 'abstand') {
      var aussen = e.altKey;
      var seite = richtung[0] ? 'Inline' : 'Block';
      var eig = (aussen ? 'margin' : 'padding') + seite;
      merkeStil(ziel, eig);
      var jetzt = px(ziel, richtung[0] ? (aussen ? 'marginLeft' : 'paddingLeft')
                                       : (aussen ? 'marginTop' : 'paddingTop'));
      var neu = Math.max(0, jetzt + (richtung[0] || richtung[1]) * schritt);
      ziel.style[eig] = neu + 'px';
      notiereGebuendelt(ziel, aussen ? 'margin' : 'padding',
        richtung[0] ? 'links und rechts' : 'oben und unten', jetzt + 'px', neu + 'px');
      return true;
    }

    if (werkzeug === 'groesse') {
      var eigG = richtung[0] ? 'width' : 'height';
      /* Vor jedem Eingriff messen: sobald das Element nicht mehr mitwaechst,
         faellt es sonst auf seine Eigenbreite zurueck und springt. */
      var altG = Math.round(ziel.getBoundingClientRect()[eigG]);
      merkeStil(ziel, eigG);
      /* Ohne border-box zaehlt width nur den Inhalt, der protokollierte Wert
         wuerde dann von der sichtbaren Breite abweichen. */
      merkeStil(ziel, 'boxSizing');
      ziel.style.boxSizing = 'border-box';
      /* In Flex- und Grid-Layouts bestimmt der Container die Groesse. Ein
         blosses width bleibt dort wirkungslos, solange das Element noch
         mitwaechst oder auf volle Hoehe gestreckt wird. */
      var eltern = ziel.parentElement ? getComputedStyle(ziel.parentElement).display : '';
      if (/flex|grid/.test(eltern)) {
        if (richtung[0]) {
          merkeStil(ziel, 'flexGrow');
          merkeStil(ziel, 'flexShrink');
          merkeStil(ziel, 'flexBasis');
          ziel.style.flexGrow = '0';
          ziel.style.flexShrink = '0';
          /* flex: 1 setzt flex-basis auf 0% -- das schlaegt width, solange es steht. */
          ziel.style.flexBasis = 'auto';
        } else {
          merkeStil(ziel, 'alignSelf');
          ziel.style.alignSelf = 'flex-start';
        }
      }
      var neuG = Math.max(4, altG + (richtung[0] || richtung[1]) * schritt);
      ziel.style[eigG] = neuG + 'px';
      /* In Tabellen ueberstimmt das Auto-Layout ein blosses width. */
      if (/^(TD|TH)$/.test(ziel.tagName)) {
        var eigMin = richtung[0] ? 'minWidth' : 'minHeight';
        merkeStil(ziel, eigMin);
        ziel.style[eigMin] = neuG + 'px';
      }
      notiereGebuendelt(ziel, 'grösse', eigG === 'width' ? 'Breite' : 'Höhe', altG + 'px', neuG + 'px');
      setzeUmriss(ziel);
      return true;
    }

    if (werkzeug === 'reihenfolge' && richtung[0]) {
      var p = ziel.parentElement;
      if (!p) return true;
      var alt = Array.prototype.indexOf.call(p.children, ziel);
      if (richtung[0] < 0 && ziel.previousElementSibling) {
        p.insertBefore(ziel, ziel.previousElementSibling);
      } else if (richtung[0] > 0 && ziel.nextElementSibling) {
        p.insertBefore(ziel.nextElementSibling, ziel);
      } else return true;
      var neu = Array.prototype.indexOf.call(p.children, ziel);
      notiereGebuendelt(ziel, 'umsortiert', 'Position im Container',
        'Stelle ' + (alt + 1), 'Stelle ' + (neu + 1));
      setzeUmriss(ziel);
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
    var el = unterMaus(e);
    if (el) setzeUmriss(el);
  }

  function beiKlick(e) {
    if (!aktiv || istEigen(e.target)) return;
    e.preventDefault();
    e.stopPropagation();
    var el = unterMaus(e);
    if (!el) return;

    if (werkzeug === 'duplizieren') { dupliziere(el); return; }
    if (werkzeug === 'ausblenden') { blendeAus(el); return; }
    if (werkzeug === 'notiz') { setzeNotiz(el); return; }
    if (werkzeug === 'messen') { messe(el); return; }

    ziel = el;
    zeigeZiel();
    setzeUmriss(el);
    if (werkzeug === 'text') bearbeiteText(el);
  }

  function beiRunter(e) {
    if (!aktiv || istEigen(e.target)) return;
    if (werkzeug === 'platzhalter') {
      zieht = { x: e.clientX, y: e.clientY, unter: unterMaus(e) };
      ziehflaeche.style.display = 'block';
      e.preventDefault();
      return;
    }
    if (werkzeug === 'verschieben') {
      var el = unterMaus(e);
      if (!el) return;
      ziel = el;
      zeigeZiel();
      merkeStil(el, 'transform');
      var m = /translate\((-?[\d.]+)px,\s*(-?[\d.]+)px\)/.exec(el.style.transform || '');
      zieht = { x: e.clientX, y: e.clientY, el: el, dx: m ? parseFloat(m[1]) : 0, dy: m ? parseFloat(m[2]) : 0 };
      e.preventDefault();
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
    if (zieht.el) {
      var x = zieht.dx + e.clientX - zieht.x;
      var y = zieht.dy + e.clientY - zieht.y;
      zieht.el.style.transform = 'translate(' + Math.round(x) + 'px, ' + Math.round(y) + 'px)';
      setzeUmriss(zieht.el);
    }
  }

  function beiHoch(e) {
    if (!zieht) return;
    if (werkzeug === 'platzhalter') {
      var l = Math.min(zieht.x, e.clientX), t = Math.min(zieht.y, e.clientY);
      var w = Math.abs(e.clientX - zieht.x), h = Math.abs(e.clientY - zieht.y);
      ziehflaeche.style.display = 'none';
      if (w > 8 && h > 8) legePlatzhalter(l, t, w, h, zieht.unter);
    } else if (zieht.el) {
      var m = /translate\((-?[\d.]+)px,\s*(-?[\d.]+)px\)/.exec(zieht.el.style.transform || '');
      var x = m ? Math.round(parseFloat(m[1])) : 0;
      var y = m ? Math.round(parseFloat(m[2])) : 0;
      if (x !== zieht.dx || y !== zieht.dy) {
        notiereGebuendelt(zieht.el, 'verschoben', '', 'ursprüngliche Position',
          (x >= 0 ? '+' : '') + x + ' px waagrecht, ' + (y >= 0 ? '+' : '') + y + ' px senkrecht');
      }
    }
    zieht = null;
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
    var e = notiere(unter || document.body, 'platzhalter', text, null,
      Math.round(w) + ' × ' + Math.round(h) + ' px');
    e.form = { x: Math.round(l), y: Math.round(t), w: Math.round(w), h: Math.round(h) };
    e.text = text;
    e.knoten = d;
    e.art = 'platzhalter';
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

    var nr = parseInt(e.key, 10);
    if (!e.metaKey && !e.ctrlKey && nr >= 1 && nr <= 9 && WERKZEUGE[nr - 1]) {
      waehleWerkzeug(WERKZEUGE[nr - 1].id);
      return;
    }
    if (pfeil(e)) e.preventDefault();
  }

  function beiScroll() {
    if (!aktiv) return;
    if (ziel) setzeUmriss(ziel);
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
    melde('Skizzenmodus an · Zahlen 1–9 wählen Werkzeuge');
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
    setzeUmriss(null);
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

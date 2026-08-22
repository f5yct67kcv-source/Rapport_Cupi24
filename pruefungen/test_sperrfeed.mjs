// Ereignis-Kachel "Neue Sperrtage" auf der Übersicht (ENT-030).
import { WURZEL, HIER, OUT, browserPfad } from './pfade.mjs';
import { chromium } from 'playwright';

const EXE = browserPfad();
const iso = d => new Date(d.getTime() - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 10);
const tag = n => iso(new Date(Date.now() + n * 864e5));
const ok = [], bad = [];
const check = (n, c) => (c ? ok : bad).push(n);

const jetztMinus = m => new Date(Date.now() - m * 60000).toISOString().slice(0, 19).replace('T', ' ');
const FEED = [
  { id: 41, mitarbeiter_id: 2, name: 'valbon', vorname: 'Valbon', nachname: 'Redjepi',
    datum: tag(4), bemerkung: 'Wochenendurlaub', erfasst_am: jetztMinus(5) },
  { id: 42, mitarbeiter_id: 3, name: 'daniele.ciardo', vorname: 'Daniele', nachname: 'Ciardo',
    datum: tag(9), bemerkung: null, erfasst_am: jetztMinus(190) },
];
const STATS = { status: 'ok', kpi: { rapporte_monat: 1, rapporte_vormonat: 0, stunden_monat: 8, stunden_vormonat: 0,
  mitarbeiter: 2, kunden: 1, rapporte_total: 1 },
  verlauf: Array.from({ length: 8 }, (_, i) => ({ kw: 26 + i, stunden: 10, anzahl: 1 })),
  angemeldet: [], pro_mitarbeiter: [], letzte_rapporte: [], sperr_ereignisse: FEED };

const rufe = [];
const browser = await chromium.launch({ executablePath: EXE });
const page = await browser.newPage({ viewport: { width: 1500, height: 950 } });
page.on('pageerror', e => bad.push('JS-Fehler: ' + e.message));
await page.route('**/api/**', route => {
  const req = route.request(), u = req.url(), p = u.split('/api/')[1].split('?')[0];
  let body = null;
  try { body = req.postData() ? JSON.parse(req.postData()) : null; } catch {}
  rufe.push({ p, body });
  const send = b => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(b) });
  if (p.includes('login')) return send({ status: 'ok', token: 't', name: 'a', ist_admin: true });
  if (p.includes('dashboard_stats')) return send(STATS);
  if (p.includes('sperr_erledigt')) return send({ status: 'ok', id: body?.id });
  if (p.includes('planung_einrichten')) return send({ status: 'ok', message: 'Alles ist eingerichtet.', getan: [], unveraendert: [], ausstehend: 0 });
  return send({ status: 'ok', einsaetze: [], kunden: [], rapporte: [], objekte: [], mitarbeiter: [],
    feiertage: [], gepflegt: {}, sperren: [] });
});
await page.goto(`file://${WURZEL}/dashboard.html`);
await page.fill('#gName', 'a'); await page.fill('#gPass', 'x'); await page.click('#gBtn');
await page.waitForSelector('#shell.on'); await page.waitForTimeout(400);

// ══════════ KACHEL DA
check('Die Kachel „Neue Sperrtage“ ist da', (await page.textContent('.card-hd h2').catch(() => '')) !== null);
check('Titel „Neue Sperrtage“ vorhanden',
  await page.evaluate(() => [...document.querySelectorAll('.card-hd h2')].some(h => h.textContent === 'Neue Sperrtage')));
const feed = await page.textContent('#sperrFeed');
check('Erste Person steht drin', feed.includes('Valbon Redjepi'));
check('Zweite Person steht drin', feed.includes('Daniele Ciardo'));
check('Datum wird angezeigt', feed.includes(tag(4).split('-').reverse().join('.')));
check('Notiz wird angezeigt', feed.includes('Wochenendurlaub'));
check('Ohne Notiz kein Gedankenstrich ins Leere',
  !feed.includes('Ciardo hat sich') || !feed.match(/Ciardo[^<]*—\s*<\/span>/));
check('Relative Zeit „vor 5 Min.“', feed.includes('vor 5 Min.'));
check('Relative Zeit „vor 3 Std.“', feed.includes('vor 3 Std.'));
check('Reihenfolge: neuestes zuerst',
  feed.indexOf('Valbon Redjepi') < feed.indexOf('Daniele Ciardo'));
await page.screenshot({ path: OUT + '/70-sperrfeed.png' });

// ══════════ KLICK FÜHRT ZUM TAGESPLAN
await page.click('#sperrFeed .rank:first-child');
await page.waitForTimeout(400);
check('Springt in die Planung', await page.evaluate(() => document.getElementById('view-planung').classList.contains('on')));
check('Springt auf den Tagesplan', await page.evaluate(() => document.getElementById('pv-tag').classList.contains('on')));
check('Das richtige Datum ist gesetzt', (await page.inputValue('#tgD')) === tag(4));

// ══════════ ALS ERLEDIGT MARKIEREN (ENT-033)
await page.evaluate(() => go('uebersicht'));
await page.waitForTimeout(200);
check('Zwei Zeilen vor dem Markieren', (await page.$$('#sperrFeed .rank')).length === 2);
await page.click('#sperrFeed .rank:first-child .rank-erledigt');
await page.waitForTimeout(300);
check('Die erledigte Zeile verschwindet sofort, ohne auf den Server zu warten',
  (await page.$$('#sperrFeed .rank')).length === 1);
check('Klick auf den Erledigt-Knopf springt nicht in die Planung',
  await page.evaluate(() => document.getElementById('view-uebersicht').classList.contains('on')));
check('Der Aufruf geht an sperr_erledigt.php', rufe.some(r => r.p.includes('sperr_erledigt')));
check('Die richtige id wird geschickt',
  rufe.find(r => r.p.includes('sperr_erledigt'))?.body?.id === 41);
check('Die verbleibende Person ist Daniele', (await page.textContent('#sperrFeed')).includes('Daniele Ciardo'));
await page.click('#sperrFeed .rank:first-child .rank-erledigt');
await page.waitForTimeout(300);
check('Nach dem letzten Eintrag erscheint der leere Zustand',
  (await page.textContent('#sperrFeed')).includes('Keine neuen Sperrtage'));

// ══════════ LEERER ZUSTAND
STATS.sperr_ereignisse = [];
await page.evaluate(() => loadStats());
await page.waitForTimeout(400);
check('Leerer Zustand erklärt sich', (await page.textContent('#sperrFeed')).includes('Keine neuen Sperrtage'));

// ══════════ FEHLENDE TABELLE (OP-29 noch offen) BRICHT DIE ÜBERSICHT NICHT
STATS.sperr_ereignisse = undefined;   // genau das liefert der Server bei fehlender Tabelle
await page.evaluate(() => loadStats());
await page.waitForTimeout(400);
check('Ohne Tabelle bleibt die Kachel ruhig leer statt zu brechen',
  (await page.textContent('#sperrFeed')).includes('Keine neuen Sperrtage'));
check('Die übrigen Kacheln laden trotzdem',
  (await page.textContent('#kpiGrid')).includes('1'));

await browser.close();
console.log(`\n${ok.length} bestanden, ${bad.length} nicht bestanden\n`);
if (bad.length) { bad.forEach(b => console.log('  ✗ ' + b)); process.exit(1); }
console.log('Alle Pruefungen bestanden.');

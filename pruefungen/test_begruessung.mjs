// Begrüssungs-Container mit Diktat-Router und Bild-Erfassung (ENT-032).
import { WURZEL, HIER, OUT, browserPfad } from './pfade.mjs';
import { chromium } from 'playwright';

const EXE = browserPfad();
// Ein winziges Testbild, im Lauf erzeugt statt als Binaerdatei im
// Repository. Es muss nur ein gueltiges PNG sein -- was darauf zu sehen
// ist, spielt keine Rolle; geprueft wird der Weg vom Auswaehlen bis zur
// Anfrage, nicht der Inhalt.
const BILD = OUT + '/testbild.png';
{
  const { writeFileSync, existsSync } = await import('fs');
  if (!existsSync(BILD)) {
    // 1x1 Pixel, PNG, transparent.
    writeFileSync(BILD, Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
      + 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64'));
  }
}
const iso = d => new Date(d.getTime() - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 10);
const tag = n => iso(new Date(Date.now() + n * 864e5));
const ok = [], bad = [];
const check = (n, c) => (c ? ok : bad).push(n);

const jetzt = new Date();
const hm = d => String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':00';
const NAECHSTES = { status: 'ok',
  naechster_einsatz: { id: 41, kunde_name: 'Borner AG', titel: 'Schliessrunde', ort: '4601 Olten',
    datum: tag(0), von: hm(new Date(jetzt.getTime() + 3600e3)), bis: '23:00:00', bedarf: 2, zugeteilt: 1,
    objekt_name: 'Gerolag Center' },
  offene_zusagen: 3, neue_sperrtage: 2 };

const MA = [{ id: 1, name: 'adrianvonarb', vorname: 'Adrian', nachname: 'von Arb', aktiv: 1, ist_admin: 1 }];
const KU = [{ id: 1, name: 'Borner AG', strasse: 'Bahnhofstrasse 1', ort: '4600 Olten', telefon: null, email: null }];

const rufe = [];
let routerAntwort = null, bildAntwort = null;

const browser = await chromium.launch({ executablePath: EXE });
const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });
page.on('pageerror', e => bad.push('JS-Fehler: ' + e.message));
await page.route('**/api/**', route => {
  const req = route.request(), u = req.url(), p = u.split('/api/')[1].split('?')[0];
  let body = null;
  try { body = req.postData() ? JSON.parse(req.postData()) : null; } catch (e) {}
  rufe.push({ p, body });
  const send = (b, s) => route.fulfill({ status: s || 200, contentType: 'application/json', body: JSON.stringify(b) });
  if (p.includes('login')) return send({ status: 'ok', token: 't', name: 'adrianvonarb', ist_admin: true });
  if (p.includes('naechstes')) return send(NAECHSTES);
  if (p.includes('ki_router_parse')) return routerAntwort ? send(routerAntwort[0], routerAntwort[1])
    : send({ status: 'error', message: 'kein Mock' }, 502);
  if (p.includes('ki_einsatz_bild')) return bildAntwort ? send(bildAntwort[0], bildAntwort[1])
    : send({ status: 'error', message: 'kein Mock' }, 502);
  if (p.includes('mitarbeiter_list')) return send({ status: 'ok', mitarbeiter: MA });
  if (p.includes('kunden_list')) return send({ status: 'ok', kunden: KU });
  if (p.includes('dashboard_stats')) return send({ status: 'ok', kpi: { rapporte_monat: 0, rapporte_vormonat: 0,
    stunden_monat: 0, stunden_vormonat: 0, mitarbeiter: 1, kunden: 1, rapporte_total: 0 },
    verlauf: [], angemeldet: [], pro_mitarbeiter: [], letzte_rapporte: [], sperr_ereignisse: [] });
  return send({ status: 'ok', einsaetze: [], rapporte: [], objekte: [], feiertage: [], gepflegt: {}, sperren: [] });
});
await page.goto(`file://${WURZEL}/dashboard.html`);
await page.fill('#gName', 'adrianvonarb'); await page.fill('#gPass', 'x'); await page.click('#gBtn');
await page.waitForSelector('#shell.on'); await page.waitForTimeout(500);

// ══════════ BEGRÜSSUNG
check('Der Container ist als erster da',
  await page.evaluate(() => document.querySelector('.dash-item').dataset.widget === 'begruessung'));
check('Die Begrüssung nennt den Vornamen', (await page.textContent('#begrGruss')).includes('Adrian'));
check('„Was steht an" wird geladen', rufe.some(r => r.p.includes('naechstes')));
const naechstesTxt = await page.textContent('#begrNaechstes');
check('Der nächste Einsatz wird genannt', naechstesTxt.includes('Gerolag Center'));
check('Heute wird als „heute" erkannt', /heute/i.test(naechstesTxt));
// Seit ENT-058 heisst es Schichten, nicht Stellen.
check('Offene Schichten werden genannt', naechstesTxt.includes('1 Schicht offen'));
check('KRITISCH: die alte Benennung taucht nicht wieder auf', !/Stelle/.test(naechstesTxt));
check('Sperrtage werden genannt', naechstesTxt.includes('2 neue Sperrtage'));
check('Offene Rückmeldungen werden genannt', naechstesTxt.includes('3 offene Rückmeldungen'));
await page.screenshot({ path: OUT + '/80-begruessung.png' });

// Wechselnde Formulierung: neu laden, Text darf sich unterscheiden koennen
const grussTexte = new Set();
for (let i = 0; i < 12; i++) {
  await page.evaluate(() => renderBegruessung());
  grussTexte.add(await page.textContent('#begrGruss'));
}
check('Die Begrüssung variiert über mehrere Aufrufe', grussTexte.size > 1);

// ══════════ DER GRUSS RICHTET SICH NACH DER UHRZEIT
await page.evaluate(() => { window.__zeitFest = 9; Date.prototype.getHours = function () { return window.__zeitFest; }; renderBegruessung(); });
check('Morgens ein anderer Gruss als abends',
  (await page.textContent('#begrGruss')).match(/Morgen|Start/));
await page.evaluate(() => { window.__zeitFest = 20; renderBegruessung(); });
check('Abends ein Abend-Gruss', ['Guten Abend', 'Noch spät unterwegs?'].includes(await page.textContent('#begrGruss').then(t => t.split(',')[0].trim())));
// Nicht auf /Abend/ pruefen: eine der drei Abend-Formen lautet "Noch spät
// unterwegs?" und enthaelt das Wort gar nicht. Die alte Fassung dieser Zeile
// fiel darum in einem von drei Laeufen durch -- ein Fehler im Test, nicht im
// Produkt. Geprueft wird stattdessen, dass es kein Tag-/Morgengruss ist.
check('Abends kein Morgen- oder Taggruss',
  !(await page.textContent('#begrGruss')).match(/Morgen|Guten Tag|Willkommen zurück|Start/));

// ══════════ MIKROFON IST WIEDERVERWENDET
check('Der Sprechen-Knopf ist im Router da', await page.isVisible('#rtMik'));
check('Die Pegelanzeige ist da', await page.evaluate(() => document.querySelectorAll('#rtViz i').length === 22));

// ══════════ ROUTER: LEERE EINGABE
await page.click('#rtBtn');
await page.waitForTimeout(200);
check('Leere Eingabe wird abgefangen', await page.isVisible('#rtErr'));
check('Leere Eingabe geht nicht ans Modell', !rufe.some(r => r.p.includes('ki_router_parse')));

// ══════════ ROUTER: MITARBEITER
routerAntwort = [{ status: 'ok', bereich: 'mitarbeiter',
  felder: { vorname: 'Hans', nachname: 'Meier', mobil: '079 111 22 33' } }, 200];
await page.fill('#rtText', 'Neuer Mitarbeiter Hans Meier, Mobil 079 111 22 33');
await page.click('#rtBtn');
await page.waitForTimeout(500);
// Seit ENT-072 fuehrt der Router auf die volle Anlegen-Flaeche statt in
// einen kurzen Dialog -- dieselbe, auf der auch bearbeitet wird.
check('Anlegen-Flaeche geht auf', await page.isVisible('#mv-bearbeiten.on'));
check('Vorname ist vorbefüllt', (await page.inputValue('#mb_vorname')) === 'Hans');
check('Diktat-Herkunft ist markiert',
  await page.evaluate(() => document.getElementById('mb_vorname').classList.contains('ki')));
check('Das Eingabefeld ist danach leer', (await page.inputValue('#rtText')) === '');
// Die Flaeche ist eine Seite, kein Ueberlagerungsdialog: Der Router wechselt
// dafuer den Bereich. Zurueck auf die Uebersicht, wo das Diktatfeld steht.
await page.evaluate(() => { mbAbbrechen(); go('uebersicht'); });
await page.waitForTimeout(300);
routerAntwort = null;

// ══════════ ROUTER: KUNDE
routerAntwort = [{ status: 'ok', bereich: 'kunde', felder: { name: 'Studer Immobilien AG', ort: '4632 Trimbach' } }, 200];
await page.fill('#rtText', 'Neuer Kunde Studer Immobilien AG in Trimbach');
await page.click('#rtBtn');
await page.waitForTimeout(500);
check('Kunden-Dialog geht auf', await page.isVisible('#dlgKunde.on'));
check('Firmenname ist vorbefüllt', (await page.inputValue('#ku_name')) === 'Studer Immobilien AG');
await page.evaluate(() => closeDlg('dlgKunde'));
routerAntwort = null;

// ══════════ ROUTER: EINSATZ
routerAntwort = [{ status: 'ok', bereich: 'einsatz',
  felder: { kunde_name: 'Borner AG', datum: tag(1), von: '07:00', bis: '16:00', bedarf: 1 },
  mitarbeiter_login_namen: ['adrianvonarb'] }, 200];
await page.fill('#rtText', 'Neuer Einsatz für die Borner AG morgen 7 bis 16 Uhr');
await page.click('#rtBtn');
await page.waitForTimeout(500);
check('Einsatz-Dialog geht auf', await page.isVisible('#dlgEnNeu.on'));
check('Kunde ist vorbefüllt', (await page.inputValue('#enNKunde_name')) === 'Borner AG');
check('Zugeteilte Person ist angehakt',
  await page.evaluate(() => document.querySelector('#enNMa input[value="1"]').checked));
await page.screenshot({ path: OUT + '/81-router-einsatz.png' });
await page.evaluate(() => closeDlg('dlgEnNeu'));
routerAntwort = null;

// ══════════ ROUTER: FEHLER DES MODELLS
routerAntwort = [{ status: 'error', message: 'Konnte keinem Bereich zugeordnet werden -- bitte im jeweiligen Bereich direkt diktieren.' }, 422];
await page.fill('#rtText', 'irgendwas Unklares');
await page.click('#rtBtn');
await page.waitForTimeout(400);
check('Fehler des Modells wird gezeigt', (await page.textContent('#rtErr')).includes('keinem Bereich'));
check('Kein Dialog öffnet sich dabei',
  !(await page.isVisible('#mv-bearbeiten.on')) && !(await page.isVisible('#dlgKunde.on')) && !(await page.isVisible('#dlgEnNeu.on')));
check('Der Text bleibt für eine Korrektur stehen', (await page.inputValue('#rtText')) === 'irgendwas Unklares');
routerAntwort = null;
await page.fill('#rtText', '');

// ══════════ BILD: AUSWAHL ÜBER DEN DATEIDIALOG
await page.setInputFiles('#rtDatei', BILD);
await page.waitForTimeout(400);
check('Die Vorschau erscheint', await page.isVisible('#rtBildVorschau'));
check('Ein Vorschaubild ist gesetzt',
  (await page.getAttribute('#rtBildImg', 'src') || '').startsWith('data:image/jpeg'));
await page.screenshot({ path: OUT + '/82-bild-vorschau.png' });

bildAntwort = [{ status: 'ok', felder: { kunde_name: 'Borner AG', titel: 'Baustelle Kreisel',
  datum: tag(2), von: '08:00', bis: '17:00', bedarf: 1 }, mitarbeiter_login_namen: [], unsicher: false }, 200];
await page.click('#rtBtn');
await page.waitForTimeout(500);
const bildRuf = rufe.filter(r => r.p.includes('ki_einsatz_bild'));
check('Bild wird statt Text gesendet, wenn beides da wäre', bildRuf.length === 1);
check('Der Bild-Rumpf enthält base64 und Mime',
  typeof bildRuf[0].body.bild === 'string' && bildRuf[0].body.bild.length > 0 && bildRuf[0].body.mime === 'image/jpeg');
check('Einsatz-Dialog geht nach Bild-Erkennung auf', await page.isVisible('#dlgEnNeu.on'));
check('Titel aus dem Bild ist vorbefüllt', (await page.inputValue('#enNTitel')) === 'Baustelle Kreisel');
check('Die Bildvorschau ist danach wieder leer', !(await page.isVisible('#rtBildVorschau')));
await page.evaluate(() => closeDlg('dlgEnNeu'));
bildAntwort = null;

// ══════════ BILD: ENTFERNEN VOR DEM SENDEN
await page.setInputFiles('#rtDatei', BILD);
await page.waitForTimeout(300);
await page.click('#rtBildVorschau button');
await page.waitForTimeout(150);
check('Entfernen blendet die Vorschau aus', !(await page.isVisible('#rtBildVorschau')));
const vorSenden = rufe.filter(r => r.p.includes('ki_einsatz_bild')).length;
await page.click('#rtBtn');
await page.waitForTimeout(200);
check('Ohne Bild und ohne Text passiert nichts', rufe.filter(r => r.p.includes('ki_einsatz_bild')).length === vorSenden);
check('Stattdessen erscheint die Text-Fehlermeldung', await page.isVisible('#rtErr'));

// ══════════ BILD: FEHLER "KEIN AUFTRAG ERKENNBAR"
await page.setInputFiles('#rtDatei', BILD);
await page.waitForTimeout(300);
bildAntwort = [{ status: 'error', message: 'Im Bild liess sich kein Auftrag erkennen. Bitte die Felder von Hand eintragen.' }, 422];
await page.click('#rtBtn');
await page.waitForTimeout(400);
check('Erklärung bei unlesbarem Bild', (await page.textContent('#rtErr')).includes('kein Auftrag erkennen'));
check('Kein Dialog bei Fehler', !(await page.isVisible('#dlgEnNeu.on')));
bildAntwort = null;
await page.evaluate(() => rtBildEntfernen());

// ══════════ EINFÜGEN AUS DER ZWISCHENABLAGE
await page.evaluate(async (b64) => {
  const bin = atob(b64);
  const arr = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
  const file = new File([arr], 'einfuegung.png', { type: 'image/png' });
  const dt = new DataTransfer(); dt.items.add(file);
  const ev = new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true });
  document.getElementById('rtText').dispatchEvent(ev);
}, (await (await import('fs')).promises.readFile(BILD)).toString('base64'));
await page.waitForTimeout(400);
check('Ein eingefügtes Bild erzeugt ebenfalls eine Vorschau', await page.isVisible('#rtBildVorschau'));
await page.evaluate(() => rtBildEntfernen());

// ══════════ ZIEHEN UND FALLENLASSEN
await page.evaluate(async (b64) => {
  const bin = atob(b64);
  const arr = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
  const file = new File([arr], 'gezogen.png', { type: 'image/png' });
  const dt = new DataTransfer(); dt.items.add(file);
  const zone = document.getElementById('begrDropzone');
  zone.dispatchEvent(new DragEvent('dragover', { dataTransfer: dt, bubbles: true, cancelable: true }));
  zone.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
}, (await (await import('fs')).promises.readFile(BILD)).toString('base64'));
await page.waitForTimeout(400);
check('Ein gezogenes Bild erzeugt ebenfalls eine Vorschau', await page.isVisible('#rtBildVorschau'));
await page.evaluate(() => rtBildEntfernen());

// ══════════ DER CONTAINER GEHÖRT ZUM KONFIGURIERBAREN DASHBOARD (ENT-031)
await page.click('#btnDashBearbeiten');
await page.waitForTimeout(200);
check('Der Container hat ebenfalls ein Werkzeug',
  await page.evaluate(() => !!document.querySelector('.dash-item[data-widget="begruessung"] .dash-werk')));
await page.click('.dash-item[data-widget="begruessung"] .dash-auge');
await page.waitForTimeout(150);
check('Auch die Begrüssung lässt sich ausblenden',
  await page.evaluate(() => document.querySelector('.dash-item[data-widget="begruessung"]').classList.contains('versteckt')));
await page.click('#dashEditleiste button:has-text("Speichern")');
await page.waitForTimeout(200);
const gespeichert = JSON.parse(await page.evaluate(() => localStorage.getItem('rv3_dash_layout')));
check('Der Zustand wird mitgespeichert wie jeder andere Container',
  gespeichert.find(x => x.id === 'begruessung').sichtbar === false);

console.log(`\n${ok.length} bestanden, ${bad.length} nicht bestanden\n`);
if (bad.length) { bad.forEach(b => console.log('  ✗ ' + b)); process.exit(1); }
console.log('Alle Pruefungen bestanden.');
await browser.close();

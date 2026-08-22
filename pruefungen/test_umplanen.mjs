// Umplanung statt Doppelbelegung (ENT-060) -- die serverseitigen Zusicherungen.
// Diese Reihe liest die PHP-Quellen: Der Browser kann die Regel nicht
// garantieren, und ein Aufruf daran vorbei wuerde sie sonst umgehen.
import { WURZEL, HIER, OUT, browserPfad } from './pfade.mjs';
import { readFileSync } from 'fs';
const ok = [], bad = [];
const check = (n, c) => (c ? ok : bad).push(n);

const pl = readFileSync(`${WURZEL}/backend/planung.php`, 'utf8');
const save = readFileSync(`${WURZEL}/backend/api/einsatz_save.php`, 'utf8');
const zut = readFileSync(`${WURZEL}/backend/api/einsatz_zuteilen.php`, 'utf8');
const dash = readFileSync(`${WURZEL}/dashboard.html`, 'utf8');

check('Es gibt eine Umplanungsfunktion', /function umplanen\(/.test(pl));
check('KRITISCH: sie ENTFERNT aus der alten Schicht, statt nur hinzuzufuegen',
  /DELETE FROM einsatz_zuteilung WHERE einsatz_id = \? AND mitarbeiter_id = \?/.test(pl));
check('KRITISCH: eine abgeglichene Schicht wird nicht angefasst',
  /function umplanen[\s\S]{0,900}einsatz_abgeglichen\(\$pdo, \$esId\)/.test(pl));
check('Solche Faelle werden gemeldet, nicht still uebergangen',
  /function umplanen[\s\S]{0,900}\$blockiert\[\] = \$k/.test(pl));
check('KRITISCH: nur ausdruecklich genannte Personen werden umgeplant',
  /\$erlaubt = array_flip[\s\S]{0,400}isset\(\$erlaubt\[\$maId\]\)/.test(pl));

for (const [name, q] of [['einsatz_save.php', save], ['einsatz_zuteilen.php', zut]]) {
  // Frueher stand hier /\$input\['umplanen'\]/ -- also genau die Schreibweise,
  // die in einsatz_zuteilen.php falsch war. Der Test hat den Fehler damit
  // BEHAUPTET statt ihn zu finden: Er war gruen, solange der Fehler drin war,
  // und wurde rot, als er behoben wurde.
  //
  // Geprueft wird jetzt die Absicht: Die Umplanungsliste muss aus DERSELBEN
  // Variablen gelesen werden, in die diese Datei ihre Eingabe legt.
  const eingabe = (q.match(/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*json_decode\(file_get_contents/) || [])[1];
  check(`${name}: liest seine Eingabe in eine benannte Variable`, !!eingabe);
  check(`KRITISCH ${name}: liest die Umplanungsliste aus genau dieser Variablen`,
    !!eingabe && new RegExp('\\$' + eingabe + "\\['umplanen'\\]").test(q));
  check(`${name}: laeuft in einer Transaktion`, /beginTransaction\(\)[\s\S]{0,300}umplanen\(/.test(q));
  check(`KRITISCH ${name}: prueft NACH dem Umplanen erneut auf Doppelbelegung`,
    /umplanen\([\s\S]{0,1400}\$doppelt = doppelbelegungen\(/.test(q));
  check(`KRITISCH ${name}: die Sperre gegen Doppelbelegung bleibt bestehen`,
    /'message' => 'Doppelbelegung: '/.test(q));
  check(`${name}: eine gesperrte alte Schicht wird als Grund genannt`,
    /bereits abgeglichen/.test(q));
}

// Oberflaeche
check('Die Oberflaeche fragt vor dem Umplanen', /function pickWahl/.test(dash) && /confirm\(/.test(dash));
check('KRITISCH: die Frage sagt, dass die Person entfernt wird',
  /aus dieser Schicht entfernt/.test(dash));
check('Sie benennt die Folge fuer die alte Schicht', /unterbesetzt/.test(dash));
check('KRITISCH: nur Bestaetigte landen auf der Umplanungsliste',
  /if \(!ok\) \{ el\.checked = false; menge\.delete\(id\)/.test(dash));
check('Die Liste geht mit dem Speichern raus', /umplanen: pickUmplanenListe\(prefix\)/.test(dash));
check('Verfuegbare werden gruen markiert', /label\.frei/.test(dash) && /frei-marke/.test(dash));

// Einsatzart
// Seit ENT-062 wieder MIT "Reinigung", auf Wunsch des Projektinhabers. Damit
// sagen zwei Felder dasselbe -- deshalb die Klammer: artSparteKoppeln() zieht
// die Sparte mit, spartenKonflikt() macht einen Widerspruch sichtbar.
// Massgeblich fuer die GAV-Regeln bleibt die Sparte (ENT-061).
check('Die vier Einsatzarten sind hinterlegt',
  /'Verkehrsdienst', 'Revierdienst', 'Sicherheitsdienst', 'Reinigung'/.test(dash));
check('KRITISCH: die Kopplung Einsatzart -> Sparte existiert',
  /function artSparteKoppeln/.test(dash));
check('KRITISCH: ein Widerspruch wird gemeldet, statt still zu bleiben',
  /function spartenKonflikt/.test(dash));
check('KRITISCH: ein abweichender Bestandswert wird ergaenzt, nicht ueberschrieben',
  /if \(w && !liste\.includes\(w\)\) \{ liste\.push\(w\); \}/.test(dash));
check('Die Bezeichnung bleibt als verstecktes Feld erhalten',
  /<input type="hidden" id="enNTitel"/.test(dash) && /id="enETitel"/.test(dash));

console.log(`\n${ok.length} bestanden, ${bad.length} nicht bestanden\n`);
if (bad.length) { bad.forEach(b => console.log('  ✗ ' + b)); process.exit(1); }
console.log('Alle Pruefungen bestanden.');

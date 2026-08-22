// Alle Pruefungen in einem Lauf.
//
//     node pruefungen/alle.mjs            alles
//     node pruefungen/alle.mjs rollen     nur Suiten, deren Name "rollen" enthaelt
//
// Ergebnis: 0 = alles gruen, 1 = mindestens eine Suite rot.
//
// Wozu: Vor jedem Push ausfuehren. Es arbeiten mehrere Beteiligte parallel am
// selben Repository; wer nicht prueft, was er aendert, bricht einen fremden
// Bereich, ohne es zu merken -- und der Deploy geht bei jedem Push sofort
// live.
import { execFileSync } from 'child_process';
import { readdirSync } from 'fs';
import { HIER } from './pfade.mjs';

const filter = process.argv[2] || '';
const suiten = readdirSync(HIER)
  .filter(f => f.startsWith('test_') && f.endsWith('.mjs'))
  .filter(f => !filter || f.includes(filter))
  .sort();

if (!suiten.length) {
  console.log(filter ? `Keine Suite passt auf "${filter}".` : 'Keine Suiten gefunden.');
  process.exit(1);
}

// Erst nachsehen, ob Playwright ueberhaupt da ist. Ohne die Abhaengigkeit
// scheitert JEDE Suite mit demselben Fehler -- 37 rote Zeilen, die alle
// dasselbe sagen und die eigentliche Ursache verdecken.
try {
  await import('playwright');
} catch {
  console.log('Playwright fehlt.\n\n    cd pruefungen && npm install\n\n'
    + 'Danach laeuft "node pruefungen/alle.mjs". Einmal pro Rechner noetig.');
  process.exit(2);
}

const start = Date.now();
const rot = [];
let gruen = 0;

for (const s of suiten) {
  process.stdout.write(s.padEnd(30));
  let aus = '', code = 0;
  try {
    aus = execFileSync('node', [HIER + '/' + s], { encoding: 'utf8' });
  } catch (e) {
    aus = String(e.stdout || '') + String(e.stderr || '');
    code = e.status === undefined ? 1 : e.status;
  }
  // Die Suiten melden unterschiedlich; beide Formen zaehlen.
  const zahl = (aus.match(/(\d+) bestanden/) || [])[1] || '?';
  const schlecht = code !== 0 || /nicht bestanden|FEHLGESCHLAGEN/.test(aus.replace(/0 nicht bestanden/g, ''));
  if (schlecht) {
    rot.push(s);
    console.log(`ROT   (${zahl} bestanden)`);
    aus.split('\n').filter(z => /✗|FEHLGESCHLAGEN|^\s+- /.test(z)).slice(0, 8)
      .forEach(z => console.log('        ' + z.trim()));
  } else {
    gruen++;
    console.log(`gruen (${zahl})`);
  }
}

const sek = Math.round((Date.now() - start) / 1000);
console.log(`\n${gruen} von ${suiten.length} Suiten gruen, ${sek}s.`);
if (rot.length) {
  console.log('ROT: ' + rot.join(', '));
  console.log('\nNicht schieben, solange etwas rot ist -- der Deploy geht sofort live.');
  process.exit(1);
}

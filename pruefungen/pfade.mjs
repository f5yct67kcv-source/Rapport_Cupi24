// Wo liegt was? EINE Stelle, damit die Pruefungen auf jedem Rechner laufen.
//
// Bis zum 22.08.2026 standen in jeder Suite absolute Pfade eines einzigen
// Arbeitsplatzes. Damit liefen sie genau dort und sonst nirgends -- und wer
// am Projekt arbeitete, ohne diesen Arbeitsplatz zu haben, konnte nicht
// pruefen, was er aendert. Genau so ist eine Luecke eine Nacht lang
// produktiv geblieben.
import { existsSync, readdirSync, mkdirSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

// Die Projektwurzel liegt ein Verzeichnis ueber dieser Datei.
export const WURZEL = dirname(dirname(fileURLToPath(import.meta.url)));
export const HIER   = join(WURZEL, 'pruefungen');

// Bildschirmfotos der Pruefungen. Nicht versioniert (.gitignore) -- sie
// entstehen bei jedem Lauf neu.
export const OUT = join(HIER, 'bilder');
if (!existsSync(OUT)) { mkdirSync(OUT, { recursive: true }); }

// Der Browser. Playwright bringt normalerweise seinen eigenen mit; in
// vorbereiteten Umgebungen liegt er woanders. Beides wird versucht, und
// wenn nichts passt, entscheidet Playwright selbst (executablePath
// undefined = eigener Browser).
export function browserPfad() {
  if (process.env.PLAYWRIGHT_CHROMIUM) { return process.env.PLAYWRIGHT_CHROMIUM; }
  const stamm = process.env.PLAYWRIGHT_BROWSERS_PATH || '/opt/pw-browsers';
  if (existsSync(stamm)) {
    for (const eintrag of readdirSync(stamm)) {
      if (!eintrag.startsWith('chromium')) { continue; }
      for (const kandidat of [
        join(stamm, eintrag, 'chrome-linux', 'chrome'),
        join(stamm, eintrag, 'chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'),
        join(stamm, eintrag, 'chrome-win', 'chrome.exe'),
      ]) {
        if (existsSync(kandidat)) { return kandidat; }
      }
    }
  }
  return undefined;   // Playwright nimmt seinen eigenen
}

# Prüfungen

Vor jedem Push ausführen:

```
node pruefungen/alle.mjs
```

Nur ein Teil, wenn du weisst, was du angefasst hast:

```
node pruefungen/alle.mjs rollen      # alle Suiten mit "rollen" im Namen
node pruefungen/test_planung.mjs     # eine einzelne
```

Ergebnis: `0` = alles grün, `1` = mindestens eine Suite rot.
**Nicht schieben, solange etwas rot ist** — der Deploy geht bei jedem Push
auf `main` sofort live.

## Einmal einrichten

```
cd pruefungen && npm install
```

Playwright bringt seinen eigenen Browser mit. Liegt auf dem Rechner schon
einer (z. B. unter `/opt/pw-browsers`), wird er genommen; sonst der von
Playwright. Nichts ist auf einen bestimmten Arbeitsplatz festgelegt —
`pfade.mjs` findet Projektwurzel und Browser selbst.

## Warum es das gibt

Am Projekt arbeiten mehrere Beteiligte parallel am selben Repository. Bis zum
22.08.2026 lagen diese Prüfungen im Arbeitsverzeichnis eines einzelnen
Arbeitsplatzes: Wer dort nicht sass, konnte nicht prüfen, was er ändert. In
einer Nacht sind so drei Suiten rot geworden, ohne dass es jemandem auffiel —
und eine Lücke bei den festgeschriebenen Schichten blieb produktiv wirksam.

## Was hier geprüft wird

- **Browser-Suiten** (`test_*.mjs`) — sie bedienen die Oberfläche mit
  vorgetäuschter Serverantwort und **messen den gerenderten Zustand**:
  Grössen, Positionen, Reihenfolge. Nicht im Quelltext nachlesen, ob eine
  Regel dasteht — eine CSS-Regel kann wirkungslos bleiben, ohne dass etwas
  kaputtgeht.
- **PHP-Prüfungen** (`pruef_*.php`) — sie führen den echten Quelltext aus,
  teils gegen eine wirkliche Datenbank (SQLite im Arbeitsspeicher). Die
  Browser-Suiten täuschen den Server vor und kämen an einer Rechteregel nie
  vorbei. Sie laufen über `test_php.mjs` mit.
- **`test_php.mjs`** prüft ausserdem Regeln, die über Dateien hinweg gelten:
  dass jeder Endpunkt eine Rechteprüfung hat, dass jeder Schreibweg an einer
  Schicht die Festschreibung beachtet, dass `skizze.js` und die eingebettete
  Fassung gleich sind.
- **`test_datumsfest.mjs`** verhindert Prüfungen, die am Kalender hängen.

## Zwei Regeln beim Ergänzen

1. **Nicht den Quelltext abschreiben.** Eine Prüfung, die nachsieht, ob ein
   Wort im Code steht, bleibt grün, wenn die Formulierung sich ändert und die
   Sache verschwindet. Geprüft wird die Aussage, nicht der Wortlaut.
2. **Gegenprobe machen.** Den behobenen Fehler absichtlich wieder einbauen und
   nachsehen, ob die Prüfung rot wird. Eine Prüfung, die nie angeschlagen hat,
   ist eine Behauptung.

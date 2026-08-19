# Rapport-Tool CUPI 24

Internes Werkzeug der CUPI 24 GmbH zur Erfassung von Einsatzstunden.
Entwickelt unter der Ausnahme aus ENT-008/ENT-012 (rein interne Nutzung,
kein Verkauf) — siehe Entscheidungsprotokoll im Projekt-Repository.

## Adressen

| | Adresse | Für wen |
|---|---|---|
| Erfassung | https://rapport.itufeden.myhostpoint.ch | alle Mitarbeitenden, mobil |
| Dashboard | https://rapport.itufeden.myhostpoint.ch/dashboard.html | nur Admin, Desktop |

Beide Seiten teilen sich Anmeldung und Backend — wer angemeldet ist, bleibt es
beim Wechsel. Nicht-Admins werden vom Dashboard abgewiesen.

## Aufbau

```
index.html         Erfassung (mobil, PWA-installierbar)
dashboard.html     Verwaltungsoberflaeche (Desktop, admin-only)
manifest.json      PWA-Manifest
sw.js              Service Worker (nur fuer die Installierbarkeit)
icons/             App-Symbole

backend/
  db.php              PDO-Verbindung + require_session()
  ai.php              Anthropic-Anbindung (Diktat, Kundenrecherche, Planung)
  schema.sql          Grundschema, einmalig in phpMyAdmin ausfuehren
  schema_planung.sql  Nachtrag fuer die Einsatzplanung, ebenfalls einmalig
  api/*.php           Endpunkte, alle ueber X-Auth-Token abgesichert
```

## Einmaliger Schritt nach dem Deploy der Planung

`backend/schema_planung.sql` muss einmal im Hostpoint-Datenbank-Tool
(phpMyAdmin) ausgefuehrt werden — der Deploy legt keine Tabellen an. Solange
das nicht geschehen ist, zeigt der Bereich „Planung" einen entsprechenden
Hinweis; alle uebrigen Bereiche arbeiten unveraendert weiter.

**Die Datei enthaelt zwei Teile — genau einen davon ausfuehren:**

- **Teil A**, wenn `schema_planung.sql` noch nie gelaufen ist: der ganze
  obere Block (objekte, masterschichten, feiertage, einsaetze,
  einsatz_zuteilung).
- **Teil B**, wenn die erste Fassung vom 17.08. bereits lief (also
  `einsaetze` und `einsatz_zuteilung` schon bestehen): die drei neuen
  Tabellen anlegen und danach die auskommentierten ALTER-Befehle am Dateiende
  ausfuehren.

Danach im Dashboard unter **Planung → Übersicht → Feiertage** einmal pro Jahr
„Jahr eintragen" druecken. Der Kalender ist Kanton Solothurn, Quelle steht in
der Liste. Er markiert Tage — ueber Zuschlaege oder Entschaedigung sagt er
ausdruecklich nichts aus (siehe GAV-AUS-003 und GAV-AUS-006 im
Projekt-Repository).

Der Produktname ist **GuardControl** (loest den Arbeitstitel „Cockpit" ab,
siehe OP-18). Im Dashboard haengt er an der Konstante `APP_NAME` am Anfang des
Skriptblocks in `dashboard.html`; dieselbe Schreibweise steht als statischer
Rueckfalltext im `<title>` und in den beiden Wasserzeichen, damit vor dem
Start des Skripts nicht kurz ein anderer Name aufblitzt. In `app.html` steht
er im `<title>` und im Wasserzeichen der Anmeldung.

## Deploy

Jeder Push auf `main` loest den Workflow `.github/workflows/deploy-hostpoint.yml`
aus: Platzhalter (`__DB_HOST__`, `__ANTHROPIC_API_KEY__` usw.) werden aus
GitHub Secrets ersetzt, danach FTPS-Upload zu Hostpoint.

**Im Quellcode stehen nie echte Zugangsdaten** — nur Platzhalter. Wer die Dateien
lokal oeffnet, sieht keine Geheimnisse.

`setup.php`/`setup.html` werden bewusst **nicht** mit ausgeliefert: die
Ersteinrichtung war ein einmaliger manueller Upload und ist erledigt (OP-17).

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
dashboard.html     Verwaltungsoberflaeche (Desktop, admin-only, derzeit rein lesend)
manifest.json      PWA-Manifest
sw.js              Service Worker (nur fuer die Installierbarkeit)
icons/             App-Symbole

backend/
  db.php           PDO-Verbindung + require_session()
  ai.php           Anthropic-Anbindung (Feldextraktion aus Diktat)
  schema.sql       Datenbankschema, einmalig in phpMyAdmin ausfuehren
  api/*.php        Endpunkte, alle ueber X-Auth-Token abgesichert
```

Der Produktname des Dashboards steht noch nicht fest (Arbeitstitel „Cockpit",
siehe OP-18). Er haengt an der Konstante `APP_NAME` am Anfang des Skriptblocks
in `dashboard.html` — eine Zeile aendern genuegt.

## Deploy

Jeder Push auf `main` loest den Workflow `.github/workflows/deploy-hostpoint.yml`
aus: Platzhalter (`__DB_HOST__`, `__ANTHROPIC_API_KEY__` usw.) werden aus
GitHub Secrets ersetzt, danach FTPS-Upload zu Hostpoint.

**Im Quellcode stehen nie echte Zugangsdaten** — nur Platzhalter. Wer die Dateien
lokal oeffnet, sieht keine Geheimnisse.

`setup.php`/`setup.html` werden bewusst **nicht** mit ausgeliefert: die
Ersteinrichtung war ein einmaliger manueller Upload und ist erledigt (OP-17).

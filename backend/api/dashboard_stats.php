<?php
// Aggregierte Kennzahlen fuer das Dashboard. Reine Leseoperation.
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}

$monatStart    = date('Y-m-01');
$vormonatStart = date('Y-m-01', strtotime('first day of last month'));

// ── Kennzahlen laufender Monat vs. Vormonat
$stmt = db()->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN datum >= ? THEN 1 ELSE 0 END), 0)        AS rapporte_monat,
        COALESCE(SUM(CASE WHEN datum >= ? THEN netto_h ELSE 0 END), 0)  AS stunden_monat,
        COALESCE(SUM(CASE WHEN datum >= ? AND datum < ? THEN 1 ELSE 0 END), 0)       AS rapporte_vormonat,
        COALESCE(SUM(CASE WHEN datum >= ? AND datum < ? THEN netto_h ELSE 0 END), 0) AS stunden_vormonat
     FROM rapporte'
);
$stmt->execute([$monatStart, $monatStart, $vormonatStart, $monatStart, $vormonatStart, $monatStart]);
$kpi = $stmt->fetch() ?: [];

$counts = db()->query(
    'SELECT (SELECT COUNT(*) FROM mitarbeiter WHERE aktiv = 1) AS mitarbeiter,
            (SELECT COUNT(*) FROM kunden) AS kunden,
            (SELECT COUNT(*) FROM rapporte) AS rapporte_total'
)->fetch() ?: [];

// ── Stundenverlauf der letzten 8 Kalenderwochen (Luecken bewusst als 0 auffuellen)
$rows = db()->query(
    "SELECT DATE_FORMAT(datum, '%x-%v') AS kw, SUM(netto_h) AS stunden, COUNT(*) AS anzahl
     FROM rapporte
     WHERE datum >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
     GROUP BY kw"
)->fetchAll();
$byKw = [];
foreach ($rows as $r) {
    $byKw[$r['kw']] = $r;
}
$verlauf = [];
for ($i = 7; $i >= 0; $i--) {
    $ts  = strtotime("monday this week -{$i} week");
    $key = date('o-W', $ts);
    $verlauf[] = [
        'kw'      => (int)date('W', $ts),
        'von'     => date('Y-m-d', $ts),
        'stunden' => isset($byKw[$key]) ? (float)$byKw[$key]['stunden'] : 0.0,
        'anzahl'  => isset($byKw[$key]) ? (int)$byKw[$key]['anzahl'] : 0,
    ];
}

// ── Offene Sitzungen (Sessions laufen in diesem Modell nicht automatisch ab)
$angemeldet = db()->query(
    'SELECT m.name, m.vorname, m.nachname, MAX(s.erstellt_am) AS letzte_anmeldung, COUNT(*) AS sitzungen
     FROM sessions s JOIN mitarbeiter m ON m.id = s.mitarbeiter_id
     WHERE m.aktiv = 1
     GROUP BY m.id, m.name, m.vorname, m.nachname
     ORDER BY letzte_anmeldung DESC'
)->fetchAll();

// ── Stunden je Mitarbeitende im laufenden Monat
$stmt = db()->prepare(
    'SELECT m.name, m.vorname, m.nachname,
            COALESCE(SUM(r.netto_h), 0) AS stunden, COUNT(r.id) AS anzahl
     FROM mitarbeiter m
     LEFT JOIN rapporte r ON r.mitarbeiter_id = m.id AND r.datum >= ?
     WHERE m.aktiv = 1
     GROUP BY m.id, m.name, m.vorname, m.nachname
     ORDER BY stunden DESC, m.name'
);
$stmt->execute([$monatStart]);
$proMitarbeiter = $stmt->fetchAll();

// ── Neueste Sperrtage (ENT-030). Nur kuenftige/heutige Tage: eine Sperre fuer
// einen bereits vergangenen Tag ist kein aktuelles Ereignis mehr.
// Die Tabelle kann fehlen, solange OP-29 nicht erledigt ist -- dann bleibt
// die Liste leer, statt das ganze Dashboard mitzureissen (ENT-024-Prinzip:
// lieber ehrlich leer als ein Fehler, der alles blockiert).
$sperrEreignisse = [];
try {
    $sperrEreignisse = db()->query(
        "SELECT v.id, v.mitarbeiter_id, m.name, m.vorname, m.nachname, v.datum, v.bemerkung, v.erfasst_am
         FROM verfuegbarkeiten v
         JOIN mitarbeiter m ON m.id = v.mitarbeiter_id
         WHERE v.datum >= CURDATE() AND v.gesehen_am IS NULL
         ORDER BY v.erfasst_am DESC
         LIMIT 8"
    )->fetchAll();
} catch (Throwable $e) {
    $sperrEreignisse = [];
}

// ── Letzte Rapporte
$letzte = db()->query(
    'SELECT r.id, r.datum, m.name AS mitarbeiter, r.kunde, r.ort, r.einsatzart, r.netto_h
     FROM rapporte r JOIN mitarbeiter m ON m.id = r.mitarbeiter_id
     ORDER BY r.datum DESC, r.id DESC
     LIMIT 8'
)->fetchAll();

// Wieviele vergangene Schichten noch auf den Abgleich warten (ENT-045).
// Bewusst hier und nicht aus der Einsatzliste gerechnet: die Zahl soll schon
// beim Anmelden stehen, ohne dass dafuer saemtliche Einsaetze geladen werden.
// Abgesagte zaehlen nicht mit -- die Absage ist bereits die Feststellung.
//
// Der Griff ist abgesichert: Solange die Tabelle oder die Spalte fehlt (vor
// dem Einrichten-Lauf), bleibt die Zahl 0, statt die ganze Uebersicht mit
// einem Datenbankfehler mitzureissen.
$abgleichOffen = 0;
try {
    $abgleichOffen = (int)db()->query(
        "SELECT COUNT(*) FROM einsaetze
         WHERE datum <= CURDATE() AND status <> 'abgesagt' AND ist_status = 'offen'"
    )->fetchColumn();
} catch (Throwable $e) {
    $abgleichOffen = 0;
}

json_response([
    'status' => 'ok',
    'stand'  => date('c'),
    'abgleich_offen' => $abgleichOffen,
    'kpi' => [
        'rapporte_monat'    => (int)($kpi['rapporte_monat'] ?? 0),
        'rapporte_vormonat' => (int)($kpi['rapporte_vormonat'] ?? 0),
        'stunden_monat'     => (float)($kpi['stunden_monat'] ?? 0),
        'stunden_vormonat'  => (float)($kpi['stunden_vormonat'] ?? 0),
        'mitarbeiter'       => (int)($counts['mitarbeiter'] ?? 0),
        'kunden'            => (int)($counts['kunden'] ?? 0),
        'rapporte_total'    => (int)($counts['rapporte_total'] ?? 0),
    ],
    'verlauf'         => $verlauf,
    'angemeldet'      => $angemeldet,
    'pro_mitarbeiter' => $proMitarbeiter,
    'letzte_rapporte' => $letzte,
    'sperr_ereignisse' => $sperrEreignisse,
]);

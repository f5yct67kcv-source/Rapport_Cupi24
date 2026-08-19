<?php
// Alle geplanten Einsaetze samt Zuteilung (ENT-020, erweitert in ENT-021).
// Optional auf einen Zeitraum eingegrenzt -- bei taeglich wiederkehrenden
// Objektschichten waechst die Gesamtmenge sonst schnell.
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}

$von = trim((string)($_GET['von'] ?? ''));
$bis = trim((string)($_GET['bis'] ?? ''));
$eingegrenzt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $von) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis);

$sql = 'SELECT id, kunde_id, kunde_name, objekt_id, masterschicht_id, titel, strasse, ort,
               einsatzart, sparte, datum, von, bis, bedarf, status, bemerkung, erstellt_am
        FROM einsaetze';
$args = [];
if ($eingegrenzt) {
    $sql .= ' WHERE datum BETWEEN ? AND ?';
    $args = [$von, $bis];
}
$sql .= ' ORDER BY datum DESC, von ASC, id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($args);
$einsaetze = $stmt->fetchAll();

// Zuteilungen in einem Zug holen und zuordnen -- eine Abfrage je Einsatz waere
// bei einem Monatsplan schnell dreistellig.
$zsql = 'SELECT z.einsatz_id, z.mitarbeiter_id, z.zusage, m.name, m.vorname, m.nachname
         FROM einsatz_zuteilung z
         JOIN mitarbeiter m ON m.id = z.mitarbeiter_id';
if ($eingegrenzt) {
    $zsql .= ' JOIN einsaetze e ON e.id = z.einsatz_id AND e.datum BETWEEN ? AND ?';
}
$zsql .= ' ORDER BY m.name';
$zstmt = db()->prepare($zsql);
$zstmt->execute($args);

$proEinsatz = [];
foreach ($zstmt->fetchAll() as $z) {
    $proEinsatz[(int)$z['einsatz_id']][] = [
        'id' => (int)$z['mitarbeiter_id'],
        'name' => $z['name'],
        'vorname' => $z['vorname'],
        'nachname' => $z['nachname'],
        'zusage' => $z['zusage'],
    ];
}

$einsaetze = array_map(function ($e) use ($proEinsatz) {
    $e['id'] = (int)$e['id'];
    $e['kunde_id'] = $e['kunde_id'] === null ? null : (int)$e['kunde_id'];
    $e['objekt_id'] = $e['objekt_id'] === null ? null : (int)$e['objekt_id'];
    $e['masterschicht_id'] = $e['masterschicht_id'] === null ? null : (int)$e['masterschicht_id'];
    $e['bedarf'] = (int)$e['bedarf'];
    $e['mitarbeiter'] = $proEinsatz[$e['id']] ?? [];
    return $e;
}, $einsaetze);

json_response(['status' => 'ok', 'einsaetze' => $einsaetze, 'eingegrenzt' => (bool)$eingegrenzt]);

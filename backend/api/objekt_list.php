<?php
// Objekte (Dauerauftraege) samt Zahl der aktuell gueltigen Masterschichten (ENT-021).
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}

$heute = date('Y-m-d');

$objekte = db()->query(
    'SELECT id, kunde_id, kunde_name, name, strasse, ort, kanton, einsatzart, sparte, aktiv, bemerkung, erstellt_am
     FROM objekte ORDER BY aktiv DESC, name'
)->fetchAll();

// Nur heute gueltige Masterschichten zaehlen -- abgelaufene Fassungen sind
// Geschichte, keine offene Planung.
$stmt = db()->prepare(
    'SELECT objekt_id, COUNT(*) AS anzahl, COALESCE(SUM(arbeitszeit_h), 0) AS stunden
     FROM masterschichten
     WHERE gueltig_ab <= ? AND (gueltig_bis IS NULL OR gueltig_bis >= ?)
     GROUP BY objekt_id'
);
$stmt->execute([$heute, $heute]);
$proObjekt = [];
foreach ($stmt->fetchAll() as $r) {
    $proObjekt[(int)$r['objekt_id']] = ['anzahl' => (int)$r['anzahl'], 'stunden' => (float)$r['stunden']];
}

$objekte = array_map(function ($o) use ($proObjekt) {
    $o['id'] = (int)$o['id'];
    $o['kunde_id'] = $o['kunde_id'] === null ? null : (int)$o['kunde_id'];
    $o['aktiv'] = (int)$o['aktiv'];
    $z = $proObjekt[$o['id']] ?? ['anzahl' => 0, 'stunden' => 0];
    $o['masterschichten'] = $z['anzahl'];
    $o['stunden_je_einsatz'] = $z['stunden'];
    return $o;
}, $objekte);

json_response(['status' => 'ok', 'objekte' => $objekte]);

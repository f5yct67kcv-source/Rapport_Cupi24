<?php
// Alle geplanten Einsaetze samt Zuteilung (ENT-020).
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}

$einsaetze = db()->query(
    'SELECT id, kunde_id, kunde_name, titel, strasse, ort, einsatzart,
            datum, von, bis, bedarf, status, bemerkung, erstellt_am
     FROM einsaetze ORDER BY datum DESC, von ASC, id DESC'
)->fetchAll();

// Zuteilungen in einem Zug holen und zuordnen -- eine Abfrage je Einsatz waere
// bei einem Monatsplan schnell dreistellig.
$zut = db()->query(
    'SELECT z.einsatz_id, z.mitarbeiter_id, m.name, m.vorname, m.nachname
     FROM einsatz_zuteilung z
     JOIN mitarbeiter m ON m.id = z.mitarbeiter_id
     ORDER BY m.name'
)->fetchAll();

$proEinsatz = [];
foreach ($zut as $z) {
    $proEinsatz[(int)$z['einsatz_id']][] = [
        'id' => (int)$z['mitarbeiter_id'],
        'name' => $z['name'],
        'vorname' => $z['vorname'],
        'nachname' => $z['nachname'],
    ];
}

$einsaetze = array_map(function ($e) use ($proEinsatz) {
    $e['id'] = (int)$e['id'];
    $e['kunde_id'] = $e['kunde_id'] === null ? null : (int)$e['kunde_id'];
    $e['bedarf'] = (int)$e['bedarf'];
    $e['mitarbeiter'] = $proEinsatz[$e['id']] ?? [];
    return $e;
}, $einsaetze);

json_response(['status' => 'ok', 'einsaetze' => $einsaetze]);

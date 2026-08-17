<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}

$rows = db()->query(
    'SELECT id, name, ist_admin, erstellt_am, personalnummer, anrede, vorname, nachname, geburtsdatum,
            strasse, ort, telefon, mobil, email
     FROM mitarbeiter WHERE aktiv = 1 ORDER BY name'
)->fetchAll();
$rows = array_map(fn($r) => [
    // id wird fuer die Zuteilung in der Einsatzplanung gebraucht (ENT-020).
    'id' => (int)$r['id'],
    'name' => $r['name'], 'ist_admin' => (bool)$r['ist_admin'], 'erstellt_am' => $r['erstellt_am'],
    'personalnummer' => $r['personalnummer'], 'anrede' => $r['anrede'], 'vorname' => $r['vorname'], 'nachname' => $r['nachname'],
    'geburtsdatum' => $r['geburtsdatum'], 'strasse' => $r['strasse'], 'ort' => $r['ort'],
    'telefon' => $r['telefon'], 'mobil' => $r['mobil'], 'email' => $r['email'],
], $rows);
json_response(['status' => 'ok', 'mitarbeiter' => $rows]);

<?php
// Die eigenen Sperrtage lesen und setzen (ENT-028).
//
// Nicht admin-only, aber strikt auf die eigene Person begrenzt -- niemand
// sieht oder aendert hier die Angaben anderer.
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
$ich = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $von = trim((string)($_GET['von'] ?? ''));
    $bis = trim((string)($_GET['bis'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) { $von = date('Y-m-d'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) { $bis = date('Y-m-d', strtotime('+120 days')); }

    $s = db()->prepare(
        'SELECT datum, art, bemerkung FROM verfuegbarkeiten
         WHERE mitarbeiter_id = ? AND datum BETWEEN ? AND ? ORDER BY datum'
    );
    $s->execute([$ich, $von, $bis]);
    json_response(['status' => 'ok', 'von' => $von, 'bis' => $bis, 'tage' => $s->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur GET oder POST'], 405);
}

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$datum = trim((string)($in['datum'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    json_response(['status' => 'error', 'message' => 'Datum im Format JJJJ-MM-TT erforderlich'], 400);
}
// Die Vergangenheit laesst sich nicht mehr sperren -- das waere eine Aussage
// ueber bereits geleistete oder verpasste Arbeit und gehoert nicht hierher.
if ($datum < date('Y-m-d')) {
    json_response(['status' => 'error', 'message' => 'Vergangene Tage lassen sich nicht mehr sperren'], 400);
}
if ($datum > date('Y-m-d', strtotime('+2 years'))) {
    json_response(['status' => 'error', 'message' => 'So weit im Voraus geht es nicht'], 400);
}

$gesperrt = !empty($in['gesperrt']);
$bemerkung = trim((string)($in['bemerkung'] ?? ''));
if (mb_strlen($bemerkung) > 200) {
    $bemerkung = mb_substr($bemerkung, 0, 200);
}

if (!$gesperrt) {
    db()->prepare('DELETE FROM verfuegbarkeiten WHERE mitarbeiter_id = ? AND datum = ?')
        ->execute([$ich, $datum]);
    json_response(['status' => 'ok', 'datum' => $datum, 'gesperrt' => false]);
}

$s = db()->prepare(
    'INSERT INTO verfuegbarkeiten (mitarbeiter_id, datum, art, bemerkung)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE bemerkung = VALUES(bemerkung), art = VALUES(art)'
);
$s->execute([$ich, $datum, 'gesperrt', $bemerkung !== '' ? $bemerkung : null]);

json_response(['status' => 'ok', 'datum' => $datum, 'gesperrt' => true, 'bemerkung' => $bemerkung ?: null]);

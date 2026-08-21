<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../rechte.php';
require_once __DIR__ . '/../logbuch.php';

$user = require_session();
require_recht($user, 'personal_schreiben');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string)($input['name'] ?? ''));
if ($name === '') {
    json_response(['status' => 'error', 'message' => 'Name erforderlich'], 400);
}

$zielId = (int)db()->query('SELECT id FROM mitarbeiter WHERE name = '
    . db()->quote($name))->fetchColumn();

db()->prepare('UPDATE mitarbeiter SET aktiv = 0 WHERE name = ?')->execute([$name]);
db()->prepare('DELETE FROM sessions WHERE mitarbeiter_id = (SELECT id FROM mitarbeiter WHERE name = ?)')->execute([$name]);

if ($zielId > 0) {
    logbuch_schreiben(db(), $user, 'mitarbeiter', $zielId, 'aktiv', '1', '0');
}

json_response(['status' => 'ok']);

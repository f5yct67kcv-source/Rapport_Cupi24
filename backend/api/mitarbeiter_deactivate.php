<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string)($input['name'] ?? ''));
if ($name === '') {
    json_response(['status' => 'error', 'message' => 'Name erforderlich'], 400);
}

db()->prepare('UPDATE mitarbeiter SET aktiv = 0 WHERE name = ?')->execute([$name]);
db()->prepare('DELETE FROM sessions WHERE mitarbeiter_id = (SELECT id FROM mitarbeiter WHERE name = ?)')->execute([$name]);

json_response(['status' => 'ok']);

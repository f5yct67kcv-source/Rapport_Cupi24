<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../anmeldung.php';   // passwort_pruefen (ENT-075)

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string)($input['name'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($name === '') {
    json_response(['status' => 'error', 'message' => 'Name erforderlich'], 400);
}
$pwFehler = passwort_pruefen($password, $name);
if ($pwFehler !== null) {
    json_response(['status' => 'error', 'message' => $pwFehler], 400);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
require_once __DIR__ . '/../mitarbeiter.php';
$stmt = db()->prepare('UPDATE mitarbeiter SET password_hash = ? WHERE name = ?');
$stmt->execute([$hash, $name]);

if ($stmt->rowCount() === 0) {
    json_response(['status' => 'error', 'message' => 'Mitarbeiter nicht gefunden'], 404);
}

// Bestehende Sitzungen dieses Mitarbeiters beenden -- altes Passwort war
// evtl. kompromittiert oder auf mehreren Geraeten eingeloggt.
db()->prepare('DELETE FROM sessions WHERE mitarbeiter_id = (SELECT id FROM mitarbeiter WHERE name = ?)')->execute([$name]);

ma_stempel(db(), 'passwort_geaendert_am', 'name', $name);

json_response(['status' => 'ok']);

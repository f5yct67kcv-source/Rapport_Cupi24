<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

// Einmaliges Bootstrap: legt den ersten Admin-Account an. Sperrt sich danach
// selbst -- sobald ein Mitarbeiter existiert, tut dieses Skript nichts mehr.
// Kann danach auf dem Server bleiben, ist aber empfehlenswert, es via FTP
// zu loeschen, sobald der erste Admin-Account erstellt ist.

$count = (int)db()->query('SELECT COUNT(*) AS c FROM mitarbeiter')->fetch()['c'];
if ($count > 0) {
    json_response(['status' => 'error', 'message' => 'bereits eingerichtet -- diese Datei kann geloescht werden'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST. Aufruf: {"name":"...", "password":"..."}'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string)($input['name'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($name === '' || strlen($password) < 6) {
    json_response(['status' => 'error', 'message' => 'Name erforderlich, Passwort mindestens 6 Zeichen'], 400);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = db()->prepare('INSERT INTO mitarbeiter (name, password_hash, ist_admin) VALUES (?, ?, 1)');
$stmt->execute([$name, $hash]);

json_response(['status' => 'ok', 'message' => 'Admin-Account erstellt. Diese Datei jetzt loeschen.']);

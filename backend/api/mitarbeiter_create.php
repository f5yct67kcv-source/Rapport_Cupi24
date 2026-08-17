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
$password = (string)($input['password'] ?? '');
$istAdmin = !empty($input['ist_admin']) ? 1 : 0;

if ($name === '' || strlen($password) < 6) {
    json_response(['status' => 'error', 'message' => 'Name erforderlich, Passwort mindestens 6 Zeichen'], 400);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = db()->prepare('INSERT INTO mitarbeiter (name, password_hash, ist_admin) VALUES (?, ?, ?)');
$stmt->execute([$name, $hash, $istAdmin]);

json_response(['status' => 'ok']);

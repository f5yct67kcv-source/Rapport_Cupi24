<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string)($input['name'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($name === '' || $password === '') {
    json_response(['status' => 'error', 'message' => 'Name und Passwort erforderlich'], 400);
}

$stmt = db()->prepare('SELECT id, password_hash, ist_admin FROM mitarbeiter WHERE name = ? AND aktiv = 1');
$stmt->execute([$name]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_response(['status' => 'error', 'message' => 'Name oder Passwort falsch'], 401);
}

$token = bin2hex(random_bytes(32));
$stmt = db()->prepare('INSERT INTO sessions (token, mitarbeiter_id) VALUES (?, ?)');
$stmt->execute([$token, $user['id']]);

json_response(['status' => 'ok', 'token' => $token, 'name' => $name, 'ist_admin' => (bool)$user['ist_admin']]);

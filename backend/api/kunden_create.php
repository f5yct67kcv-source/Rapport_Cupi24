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
$strasse = trim((string)($input['strasse'] ?? ''));
$ort = trim((string)($input['ort'] ?? ''));
$telefon = trim((string)($input['telefon'] ?? ''));
$email = trim((string)($input['email'] ?? '')) ?: null;

if ($name === '' || $strasse === '' || $ort === '' || $telefon === '') {
    json_response(['status' => 'error', 'message' => 'Name, Strasse, Ort und Telefon erforderlich'], 400);
}

$stmt = db()->prepare('INSERT INTO kunden (name, strasse, ort, telefon, email) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$name, $strasse, $ort, $telefon, $email]);

json_response(['status' => 'ok', 'id' => (int)db()->lastInsertId()]);

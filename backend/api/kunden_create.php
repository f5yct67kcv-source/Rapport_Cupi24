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

if ($name === '' || $strasse === '' || $ort === '') {
    json_response(['status' => 'error', 'message' => 'Name, Strasse und Ort erforderlich'], 400);
}

$stmt = db()->prepare('INSERT INTO kunden (name, strasse, ort) VALUES (?, ?, ?)');
$stmt->execute([$name, $strasse, $ort]);

json_response(['status' => 'ok', 'id' => (int)db()->lastInsertId()]);

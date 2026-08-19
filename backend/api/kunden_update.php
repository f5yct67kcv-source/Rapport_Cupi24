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
$id = (int)($input['id'] ?? 0);
$name = trim((string)($input['name'] ?? ''));
$strasse = trim((string)($input['strasse'] ?? ''));
$ort = trim((string)($input['ort'] ?? ''));
$telefon = trim((string)($input['telefon'] ?? ''));
$kontaktperson = trim((string)($input['kontaktperson'] ?? '')) ?: null;
$email = trim((string)($input['email'] ?? '')) ?: null;
$notiz = trim((string)($input['notiz'] ?? '')) ?: null;

if ($id <= 0 || $name === '' || $strasse === '' || $ort === '' || $telefon === '') {
    json_response(['status' => 'error', 'message' => 'Name, Strasse, Ort und Telefon erforderlich'], 400);
}

// Die Kundennummer ist bewusst nicht Teil dieses Aufrufs -- sie wird einmalig
// bei Anlage vergeben und bleibt danach unveraendert (ENT-040).
$stmt = db()->prepare(
    'UPDATE kunden SET name = ?, strasse = ?, ort = ?, telefon = ?, kontaktperson = ?, email = ?, notiz = ? WHERE id = ?'
);
$stmt->execute([$name, $strasse, $ort, $telefon, $kontaktperson, $email, $notiz, $id]);

json_response(['status' => 'ok']);

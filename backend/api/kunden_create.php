<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../kunden.php';

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
$kontaktperson = trim((string)($input['kontaktperson'] ?? '')) ?: null;
$email = trim((string)($input['email'] ?? '')) ?: null;
$notiz = trim((string)($input['notiz'] ?? '')) ?: null;

if ($name === '' || $strasse === '' || $ort === '' || $telefon === '') {
    json_response(['status' => 'error', 'message' => 'Name, Strasse, Ort und Telefon erforderlich'], 400);
}

// Die Kundennummer vergibt ausschliesslich das System, fortlaufend und
// danach unveraenderlich (ENT-040) -- kein Eingabefeld dafuer im Dialog.
$pdo = db();
$kundennummer = naechste_kundennummer($pdo);
$stmt = $pdo->prepare(
    'INSERT INTO kunden (kundennummer, name, strasse, ort, telefon, kontaktperson, email, notiz, aktiv)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
);
$stmt->execute([$kundennummer, $name, $strasse, $ort, $telefon, $kontaktperson, $email, $notiz]);

json_response(['status' => 'ok', 'id' => (int)$pdo->lastInsertId(), 'kundennummer' => $kundennummer]);

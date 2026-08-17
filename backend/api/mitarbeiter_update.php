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

$fields = [
    'personalnummer' => trim((string)($input['personalnummer'] ?? '')) ?: null,
    'anrede' => trim((string)($input['anrede'] ?? '')) ?: null,
    'vorname' => trim((string)($input['vorname'] ?? '')) ?: null,
    'nachname' => trim((string)($input['nachname'] ?? '')) ?: null,
    'geburtsdatum' => trim((string)($input['geburtsdatum'] ?? '')) ?: null,
    'strasse' => trim((string)($input['strasse'] ?? '')) ?: null,
    'ort' => trim((string)($input['ort'] ?? '')) ?: null,
    'telefon' => trim((string)($input['telefon'] ?? '')) ?: null,
    'mobil' => trim((string)($input['mobil'] ?? '')) ?: null,
    'email' => trim((string)($input['email'] ?? '')) ?: null,
];

$stmt = db()->prepare(
    'UPDATE mitarbeiter SET personalnummer = ?, anrede = ?, vorname = ?, nachname = ?, geburtsdatum = ?,
            strasse = ?, ort = ?, telefon = ?, mobil = ?, email = ?
     WHERE name = ?'
);
$stmt->execute([
    $fields['personalnummer'], $fields['anrede'], $fields['vorname'], $fields['nachname'], $fields['geburtsdatum'],
    $fields['strasse'], $fields['ort'], $fields['telefon'], $fields['mobil'], $fields['email'],
    $name,
]);

json_response(['status' => 'ok']);

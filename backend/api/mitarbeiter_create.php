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

$check = db()->prepare('SELECT COUNT(*) AS c FROM mitarbeiter WHERE name = ?');
$check->execute([$name]);
if ((int)$check->fetch()['c'] > 0) {
    json_response(['status' => 'error', 'message' => "Login-Name \"$name\" ist bereits vergeben"], 409);
}

// Optionale Detailfelder (ENT-014) -- koennen direkt beim Anlegen mitgegeben
// werden (z.B. vom KI-Diktat-Piloten, ENT-015), statt sie in einem
// separaten Schritt nachzutragen.
$fields = [
    'personalnummer' => trim((string)($input['personalnummer'] ?? '')) ?: null,
    'anrede' => trim((string)($input['anrede'] ?? '')) ?: null,
    'anstellungskategorie' => kategorie_pruefen($input['anstellungskategorie'] ?? null),
    'pensum_stunden' => pensum_pruefen($input['pensum_stunden'] ?? null),
    'eintritt' => trim((string)($input['eintritt'] ?? '')) ?: null,
    'vorname' => trim((string)($input['vorname'] ?? '')) ?: null,
    'nachname' => trim((string)($input['nachname'] ?? '')) ?: null,
    'geburtsdatum' => trim((string)($input['geburtsdatum'] ?? '')) ?: null,
    'strasse' => trim((string)($input['strasse'] ?? '')) ?: null,
    'ort' => trim((string)($input['ort'] ?? '')) ?: null,
    'telefon' => trim((string)($input['telefon'] ?? '')) ?: null,
    'mobil' => trim((string)($input['mobil'] ?? '')) ?: null,
    'email' => trim((string)($input['email'] ?? '')) ?: null,
];

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = db()->prepare(
    'INSERT INTO mitarbeiter (name, password_hash, ist_admin, personalnummer, anrede, vorname, nachname, geburtsdatum, strasse, ort, telefon, mobil, email, anstellungskategorie, pensum_stunden, eintritt)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $name, $hash, $istAdmin,
    $fields['personalnummer'], $fields['anrede'], $fields['vorname'], $fields['nachname'], $fields['geburtsdatum'],
    $fields['strasse'], $fields['ort'], $fields['telefon'], $fields['mobil'], $fields['email'],
    $fields['anstellungskategorie'], $fields['pensum_stunden'], $fields['eintritt'],
]);

json_response(['status' => 'ok']);

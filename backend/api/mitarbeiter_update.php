<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../mitarbeiter.php';

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

// Bestand laden, damit nicht mitgeschickte Felder ihren Wert behalten. Ein
// Formular, das nur einen Abschnitt sendet, darf den Rest nicht leeren.
$vorher = db()->prepare('SELECT * FROM mitarbeiter WHERE name = ?');
$vorher->execute([$name]);
$bestand = $vorher->fetch(PDO::FETCH_ASSOC);
if (!$bestand) {
    json_response(['status' => 'error', 'message' => 'Mitarbeitende(r) nicht gefunden'], 404);
}

$gelesen = ma_eingabe_lesen($input, $bestand, db());
if ($gelesen['fehler']) {
    json_response(['status' => 'error', 'message' => implode('; ', $gelesen['fehler'])], 400);
}
$s = $gelesen['spalten'];
if (!$s) {
    json_response(['status' => 'ok', 'geaendert' => 0]);
}

// Auch hier aus der Feldliste gebaut statt von Hand -- siehe
// mitarbeiter_create.php.
$sql = 'UPDATE mitarbeiter SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($s)))
     . ' WHERE name = ?';
db()->prepare($sql)->execute(array_merge(array_values($s), [$name]));

json_response(['status' => 'ok', 'geaendert' => count($s)]);

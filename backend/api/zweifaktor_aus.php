<?php
declare(strict_types=1);
// Zwei-Faktor-Anmeldung abschalten. Verlangt das eigene PASSWORT, nicht nur
// eine offene Sitzung: Wer an einem unbeaufsichtigten Rechner sitzt, soll
// den Schutz nicht mit einem Klick entfernen koennen.
require __DIR__ . '/../db.php';
require __DIR__ . '/../zweifaktor.php';

$user = require_session();
$pdo = db();
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$passwort = (string)($in['passwort'] ?? '');

$s = $pdo->prepare('SELECT password_hash FROM mitarbeiter WHERE id = ?');
$s->execute([(int)$user['id']]);
$hash = (string)$s->fetchColumn();
if ($hash === '' || !password_verify($passwort, $hash)) {
    json_response(['status' => 'error', 'message' => 'Passwort falsch'], 401);
}

zf_abschalten($pdo, (int)$user['id']);
json_response(['status' => 'ok']);

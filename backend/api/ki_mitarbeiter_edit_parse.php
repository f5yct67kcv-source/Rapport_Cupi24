<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../ai.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$text = trim((string)($input['text'] ?? ''));
if ($text === '') {
    json_response(['status' => 'error', 'message' => 'Text erforderlich'], 400);
}

$mitarbeiter = db()->query('SELECT name, vorname, nachname FROM mitarbeiter WHERE aktiv = 1')->fetchAll();
if (!$mitarbeiter) {
    json_response(['status' => 'error', 'message' => 'keine Mitarbeiter vorhanden'], 400);
}

$ergebnis = anthropic_extract_mitarbeiter_edit($text, $mitarbeiter);
if ($ergebnis === null || empty($ergebnis['mitarbeiter_login_name'])) {
    json_response(['status' => 'error', 'message' => 'KI-Erkennung nicht verfuegbar, fehlgeschlagen oder kein Mitarbeiter erkannt'], 502);
}

// Erkannten Login-Namen gegen die tatsaechliche Liste verifizieren -- die KI
// soll nur zuordnen, nie einen neuen/falschen Namen erfinden.
$namen = array_column($mitarbeiter, 'name');
if (!in_array($ergebnis['mitarbeiter_login_name'], $namen, true)) {
    json_response(['status' => 'error', 'message' => 'Erkannter Mitarbeiter nicht in der Liste gefunden'], 502);
}

json_response(['status' => 'ok', 'mitarbeiter_login_name' => $ergebnis['mitarbeiter_login_name'], 'aenderungen' => $ergebnis['aenderungen'] ?? []]);

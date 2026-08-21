<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../anmeldung.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string)($input['name'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($name === '' || $password === '') {
    json_response(['status' => 'error', 'message' => 'Name und Passwort erforderlich'], 400);
}

// Bremse gegen Passwort-Raten (ENT-075). Die Pruefung steht VOR dem
// Datenbankzugriff auf das Konto -- ein gesperrter Versuch soll gar nicht
// erst rechnen.
$adresse = anmeld_adresse();
[$fehlerName, $fehlerAdresse] = anmeld_zaehlen(db(), $name, $adresse);
$sperre = anmeld_sperre($fehlerName, $fehlerAdresse);
if ($sperre > 0) {
    // Dieselbe Grundaussage wie bei falschem Passwort: Ob es den Namen
    // gibt, wird auch hier nicht verraten -- die Sperre greift fuer einen
    // erfundenen Namen genauso.
    json_response(['status' => 'error',
        'message' => "Zu viele Fehlversuche. Bitte $sperre Minuten warten."], 429);
}

$stmt = db()->prepare('SELECT id, password_hash, ist_admin FROM mitarbeiter WHERE name = ? AND aktiv = 1');
$stmt->execute([$name]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    anmeld_fehlversuch(db(), $name, $adresse);
    json_response(['status' => 'error', 'message' => 'Name oder Passwort falsch'], 401);
}

// Geschafft -- die Fehlversuche dieses Namens sind erledigt.
anmeld_zuruecksetzen(db(), $name);

$token = bin2hex(random_bytes(32));
// Wann war diese Person zuletzt da? Die Angabe steht im Mitarbeiterbereich
// (ENT-072) und ist die einzige Spur, ob ein Zugang ueberhaupt genutzt wird.
require_once __DIR__ . '/../mitarbeiter.php';
ma_stempel(db(), 'letzter_zugriff', 'id', (int)$user['id']);

// letzte_nutzung gleich mitsetzen (ENT-075): Eine frische Sitzung darf nicht
// im selben Moment als untaetig gelten, in dem sie entsteht.
if (hat_spalte(db(), 'sessions', 'letzte_nutzung')) {
    $stmt = db()->prepare('INSERT INTO sessions (token, mitarbeiter_id, letzte_nutzung) VALUES (?, ?, NOW())');
} else {
    $stmt = db()->prepare('INSERT INTO sessions (token, mitarbeiter_id) VALUES (?, ?)');
}
$stmt->execute([$token, $user['id']]);

json_response(['status' => 'ok', 'token' => $token, 'name' => $name, 'ist_admin' => (bool)$user['ist_admin']]);

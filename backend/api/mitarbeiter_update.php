<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../rechte.php';
require __DIR__ . '/../mitarbeiter.php';
require_once __DIR__ . '/../logbuch.php';

$user = require_session();
require_recht($user, 'personal_schreiben');
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

// Wer die vertraulichen Angaben nicht sehen darf, darf sie auch nicht
// aendern (ENT-077). Ohne diese Sperre koennte die Planung die AHV-Nummer
// ueberschreiben, ohne sie je gesehen zu haben -- und das Logbuch haette
// als alten Wert nichts stehen, weil ihr das Feld nie ausgeliefert wurde.
if (!darf($user, 'personal_vertraulich')) {
    $verboten = array_intersect(array_keys($s), ma_vertrauliche_felder());
    foreach ($verboten as $feld) { unset($s[$feld]); }
    if (!$s) {
        json_response(['status' => 'error',
            'message' => 'Dafür fehlt dir die Berechtigung.'], 403);
    }
}

// Auch hier aus der Feldliste gebaut statt von Hand -- siehe
// mitarbeiter_create.php.
$sql = 'UPDATE mitarbeiter SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($s)))
     . ' WHERE name = ?';
db()->prepare($sql)->execute(array_merge(array_values($s), [$name]));

// Ins Logbuch, WER wann WAS geaendert hat (ENT-077). Erst nach dem
// Speichern: Ein Eintrag ueber eine Aenderung, die gar nicht stattgefunden
// hat, waere schlimmer als kein Eintrag. Verglichen wird gegen den zuvor
// geladenen Bestand -- nur echte Unterschiede kommen ins Buch.
logbuch_vergleichen(db(), $user, 'mitarbeiter', (int)$bestand['id'],
    $bestand, $s, ma_vertrauliche_felder());

// Rollen, falls mitgeschickt und falls der Bedienende sie vergeben darf.
$rollenFehler = null;
if (array_key_exists('rollen', $input) && is_array($input['rollen'])) {
    if (!darf($user, 'rechte')) {
        json_response(['status' => 'error',
            'message' => 'Rollen darf nur die Verwaltung vergeben.'], 403);
    }
    $rollenFehler = rechte_setzen(db(), (int)$bestand['id'],
        array_map('strval', $input['rollen']), $user);
}

json_response(['status' => $rollenFehler ? 'error' : 'ok',
    'geaendert' => count($s),
    'message'   => $rollenFehler]);

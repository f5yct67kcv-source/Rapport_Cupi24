<?php
declare(strict_types=1);
// Einrichtung abschliessen: Erst wenn ein gueltiger Code ankommt, wird die
// Zwei-Faktor-Anmeldung scharf. Sonst sperrt sich aus, wer den Schluessel
// falsch abgetippt hat.
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../rechte.php';
require __DIR__ . '/../zweifaktor.php';

$user = require_session();
require_verwaltung($user);
$pdo = db();
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$code = (string)($in['code'] ?? '');

$stand = zf_stand($pdo, (int)$user['id']);
if ($stand === null) {
    json_response(['status' => 'error', 'message' => 'Zuerst die Einrichtung starten.'], 409);
}
if ((bool)$stand['aktiv']) {
    json_response(['status' => 'error', 'message' => 'Ist bereits eingeschaltet.'], 409);
}
if (!zf_code_einloesen($pdo, (int)$user['id'], $code, time())) {
    json_response(['status' => 'error',
        'message' => 'Der Code stimmt nicht. Stimmt die Uhrzeit auf dem Handy?'], 400);
}

zf_bestaetigen($pdo, (int)$user['id']);
// Die Notfallcodes gibt es GENAU HIER einmal im Klartext -- danach stehen
// sie nur noch gehasht in der Datenbank und lassen sich nicht wieder
// hervorholen, nur neu erzeugen.
json_response([
    'status' => 'ok',
    'notfallcodes' => zf_notfallcodes_neu($pdo, (int)$user['id']),
]);

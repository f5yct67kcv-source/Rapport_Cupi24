<?php
declare(strict_types=1);
// Einrichtung beginnen: Geheimnis erzeugen und zum Abtippen zurueckgeben.
// Noch NICHT scharf -- das wird es erst mit zweifaktor_bestaetigen.php.
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../rechte.php';
require __DIR__ . '/../zweifaktor.php';

$user = require_session();
require_verwaltung($user);
$pdo = db();
if (!zf_tabellen_da($pdo)) {
    json_response(['status' => 'error',
        'message' => 'Die Tabellen fehlen noch — einmal „Einrichtung" ausführen.'], 409);
}
// Ein BESTAETIGTES Geheimnis wird nicht ueberschrieben: Wer einen Zugang
// uebernommen hat, koennte die Zwei-Faktor-Anmeldung sonst einfach auf sein
// eigenes Handy neu einrichten.
if (zf_ist_an($pdo, (int)$user['id'])) {
    json_response(['status' => 'error',
        'message' => 'Die Zwei-Faktor-Anmeldung ist bereits eingeschaltet. '
                   . 'Zum Wechseln zuerst abschalten.'], 409);
}

$geheim = zf_einrichten($pdo, (int)$user['id']);
json_response([
    'status' => 'ok',
    'geheimnis' => zf_lesbar($geheim),
    'adresse' => zf_adresse((string)$user['name'], $geheim),
]);

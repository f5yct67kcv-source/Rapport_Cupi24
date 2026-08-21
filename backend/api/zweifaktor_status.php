<?php
declare(strict_types=1);
// Stand der eigenen Zwei-Faktor-Anmeldung (ENT-076).
// Immer nur die EIGENE: Die Person kommt aus der Sitzung, nie aus der
// Anfrage. Sonst liesse sich ausspaehen, wer sie eingeschaltet hat.
require __DIR__ . '/../db.php';
require __DIR__ . '/../zweifaktor.php';

$user = require_session();
$pdo = db();
$stand = zf_stand($pdo, (int)$user['id']);

json_response([
    'status' => 'ok',
    'eingerichtet' => zf_tabellen_da($pdo),
    // Nur fuer Verwaltungszugaenge (Entscheid des Projektinhabers): In der
    // Mitarbeiter-App sieht jemand nur die eigenen Schichten.
    'moeglich' => (bool)$user['ist_admin'],
    'an' => $stand !== null && (bool)$stand['aktiv'],
    'angefangen' => $stand !== null && !$stand['aktiv'],
    'notfallcodes_offen' => zf_notfallcodes_offen($pdo, (int)$user['id']),
    'geraete' => zf_geraete_liste($pdo, (int)$user['id']),
    'geraet_tage' => ZF_GERAET_TAGE,
]);

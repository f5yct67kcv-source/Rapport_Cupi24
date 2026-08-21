<?php
// Wer bin ich, und was darf ich? Liefert ausschliesslich Angaben ueber die
// anfragende Person selbst -- darum ohne Rechtepruefung.
//
// Die Rechteliste geht bewusst mit an den Browser (ENT-077): Die Oberflaeche
// blendet damit aus, was die Rolle nicht darf. Das ist eine BEQUEMLICHKEIT,
// keine Absicherung -- entschieden wird jede Anfrage auf dem Server. Ein
// ausgeblendeter Knopf haelt niemanden auf, der die Anfrage von Hand stellt.
declare(strict_types=1);
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../rechte.php';

$user = require_session();
json_response([
    'status'    => 'ok',
    'name'      => $user['name'],
    'ist_admin' => (bool)$user['ist_admin'],
    'rollen'    => $user['rollen'] ?? [],
    'rechte'    => $user['rechte'] ?? [],
]);

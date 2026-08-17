<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

require_session(); // jeder eingeloggte Nutzer sieht die Uebersicht (wie bisher)

$rows = db()->query(
    'SELECT r.id, r.datum, m.name AS mitarbeiter, r.kunde, r.strasse, r.ort, r.auftrag_nr,
            r.einsatzart, r.von, r.bis, r.pause_min, r.netto_h, r.unterzeichner, r.bemerkung, r.erfasst_am
     FROM rapporte r JOIN mitarbeiter m ON m.id = r.mitarbeiter_id
     ORDER BY r.datum DESC, r.id DESC'
)->fetchAll();

json_response(['status' => 'ok', 'rapporte' => $rows]);

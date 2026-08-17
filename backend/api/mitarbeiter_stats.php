<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}

$rows = db()->query(
    'SELECT m.name, COUNT(r.id) AS anzahl, COALESCE(SUM(r.netto_h),0) AS stunden, MAX(r.datum) AS letzter
     FROM mitarbeiter m LEFT JOIN rapporte r ON r.mitarbeiter_id = m.id
     WHERE m.aktiv = 1
     GROUP BY m.id, m.name
     ORDER BY stunden DESC'
)->fetchAll();

json_response(['status' => 'ok', 'stats' => $rows]);

<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}

$rows = db()->query('SELECT name, ist_admin, erstellt_am FROM mitarbeiter WHERE aktiv = 1 ORDER BY name')->fetchAll();
$rows = array_map(fn($r) => ['name' => $r['name'], 'ist_admin' => (bool)$r['ist_admin'], 'erstellt_am' => $r['erstellt_am']], $rows);
json_response(['status' => 'ok', 'mitarbeiter' => $rows]);

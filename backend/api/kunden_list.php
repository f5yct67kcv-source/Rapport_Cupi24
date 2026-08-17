<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

require_session(); // jeder eingeloggte Nutzer braucht die Liste zum Ausfuellen des Rapports

$rows = db()->query('SELECT id, name, strasse, ort, telefon, email FROM kunden ORDER BY name')->fetchAll();
json_response(['status' => 'ok', 'kunden' => $rows]);

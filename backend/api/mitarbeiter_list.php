<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

require_session(); // jeder eingeloggte Nutzer darf die Namensliste sehen

$rows = db()->query('SELECT name FROM mitarbeiter WHERE aktiv = 1 ORDER BY name')->fetchAll();
json_response(['status' => 'ok', 'mitarbeiter' => array_column($rows, 'name')]);

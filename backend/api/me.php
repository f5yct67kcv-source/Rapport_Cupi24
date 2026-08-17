<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
json_response(['status' => 'ok', 'name' => $user['name'], 'ist_admin' => (bool)$user['ist_admin']]);

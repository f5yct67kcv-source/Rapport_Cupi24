<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../rechte.php';

$user = require_session();
require_recht($user, 'abgleich');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($input['id'] ?? 0);
if ($id <= 0) {
    json_response(['status' => 'error', 'message' => 'ungueltige id'], 400);
}

db()->prepare('DELETE FROM rapporte WHERE id = ?')->execute([$id]);

json_response(['status' => 'ok']);

<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

$token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($_POST['token'] ?? '');
if ($token) {
    $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
    $stmt->execute([$token]);
}
json_response(['status' => 'ok']);

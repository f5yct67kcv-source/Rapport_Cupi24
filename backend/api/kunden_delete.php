<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($input['id'] ?? 0);
if ($id <= 0) {
    json_response(['status' => 'error', 'message' => 'ungueltige id'], 400);
}

// Kein Fremdschluessel von rapporte auf kunden (Rapporte speichern Kundendaten
// als eigenen Snapshot) -- ein Loeschen hier hat keine Auswirkung auf
// bestehende Rapporte.
db()->prepare('DELETE FROM kunden WHERE id = ?')->execute([$id]);

json_response(['status' => 'ok']);

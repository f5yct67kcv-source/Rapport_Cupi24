<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../ai.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$text = trim((string)($input['text'] ?? ''));
if ($text === '') {
    json_response(['status' => 'error', 'message' => 'Text erforderlich'], 400);
}

$felder = anthropic_extract_mitarbeiter($text);
if ($felder === null) {
    json_response(['status' => 'error', 'message' => 'KI-Erkennung nicht verfuegbar oder fehlgeschlagen'], 502);
}

json_response(['status' => 'ok', 'felder' => $felder]);

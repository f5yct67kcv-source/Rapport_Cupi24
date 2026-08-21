<?php
declare(strict_types=1);
// Ein gemerktes Geraet vergessen -- etwa nach einem Geraetewechsel oder
// wenn ein Rechner abhandengekommen ist.
require __DIR__ . '/../db.php';
require __DIR__ . '/../zweifaktor.php';

$user = require_session();
$pdo = db();
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($in['id'] ?? 0);

if (!zf_tabellen_da($pdo)) {
    json_response(['status' => 'error', 'message' => 'nicht eingerichtet'], 409);
}
if ($id === 0) {
    // Alle auf einmal -- der Weg nach einem Verdacht.
    $pdo->prepare('DELETE FROM zwei_faktor_geraete WHERE mitarbeiter_id = ?')
        ->execute([(int)$user['id']]);
    json_response(['status' => 'ok', 'alle' => true]);
}
// mitarbeiter_id kommt aus der Sitzung: So laesst sich kein fremdes Geraet
// loeschen, auch nicht mit geratener id.
$pdo->prepare('DELETE FROM zwei_faktor_geraete WHERE id = ? AND mitarbeiter_id = ?')
    ->execute([$id, (int)$user['id']]);
json_response(['status' => 'ok']);

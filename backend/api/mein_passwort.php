<?php
// Eigenes Passwort aendern (ENT-023).
//
// Anders als mitarbeiter_reset_password.php (Admin) wird hier das bisherige
// Passwort verlangt -- sonst koennte ein offenes Geraet zum Kontodiebstahl
// werden.
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$alt = (string)($in['alt'] ?? '');
$neu = (string)($in['neu'] ?? '');

if (strlen($neu) < 6) {
    json_response(['status' => 'error', 'message' => 'Das neue Passwort braucht mindestens 6 Zeichen'], 400);
}

$stmt = db()->prepare('SELECT password_hash FROM mitarbeiter WHERE id = ?');
$stmt->execute([(int)$user['id']]);
$row = $stmt->fetch();
if (!$row || !password_verify($alt, $row['password_hash'])) {
    json_response(['status' => 'error', 'message' => 'Das bisherige Passwort stimmt nicht'], 401);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE mitarbeiter SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($neu, PASSWORD_DEFAULT), (int)$user['id']]);
    // Alle anderen Sitzungen beenden -- die aktuelle bleibt bestehen.
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    $pdo->prepare('DELETE FROM sessions WHERE mitarbeiter_id = ? AND token <> ?')
        ->execute([(int)$user['id'], $token]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response(['status' => 'ok']);

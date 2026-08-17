<?php
// Datenbank-Verbindung. Platzhalter werden beim Deploy durch GitHub Actions
// aus GitHub Secrets ersetzt -- diese Datei enthaelt nie echte Zugangsdaten.
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = '__DB_HOST__';
        $name = '__DB_NAME__';
        $user = '__DB_USER__';
        $pass = '__DB_PASS__';
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function require_session(): array {
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($_GET['token'] ?? ($_POST['token'] ?? ''));
    if (!$token) {
        json_response(['status' => 'error', 'message' => 'kein Token'], 401);
    }
    $stmt = db()->prepare(
        'SELECT m.id, m.name, m.ist_admin FROM sessions s
         JOIN mitarbeiter m ON m.id = s.mitarbeiter_id
         WHERE s.token = ? AND m.aktiv = 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['status' => 'error', 'message' => 'ungueltige oder abgelaufene Sitzung'], 401);
    }
    return $row;
}

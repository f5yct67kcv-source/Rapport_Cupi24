<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../mitarbeiter.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string)($input['name'] ?? ''));
$password = (string)($input['password'] ?? '');
$istAdmin = !empty($input['ist_admin']) ? 1 : 0;

if ($name === '' || strlen($password) < 6) {
    json_response(['status' => 'error', 'message' => 'Name erforderlich, Passwort mindestens 6 Zeichen'], 400);
}

$check = db()->prepare('SELECT COUNT(*) AS c FROM mitarbeiter WHERE name = ?');
$check->execute([$name]);
if ((int)$check->fetch()['c'] > 0) {
    json_response(['status' => 'error', 'message' => "Login-Name \"$name\" ist bereits vergeben"], 409);
}

// Alle uebrigen Angaben laufen durch dieselbe Fachlogik wie das Bearbeiten
// (ENT-072). Nicht mitgeschickte Felder fehlen einfach -- beim Anlegen gibt
// es keinen Bestand, aus dem sie kommen koennten.
$gelesen = ma_eingabe_lesen($input, [], db());
if ($gelesen['fehler']) {
    json_response(['status' => 'error', 'message' => implode('; ', $gelesen['fehler'])], 400);
}
$s = $gelesen['spalten'];

// Das SQL wird aus der Feldliste gebaut und nicht von Hand geschrieben:
// Spaltenzahl, Platzhalterzahl und Wertezahl koennen so nicht mehr
// auseinanderlaufen. Genau dieser Fehler ist beim Kundenstamm zweimal
// passiert und war beide Male nur durch Nachzaehlen zu finden.
$felder = array_keys($s);
// passwort_geaendert_am gehoert nicht in die Feldliste -- es wird vom System
// gesetzt, nicht vom Formular. Es kommt nur mit, wenn die Spalte schon da ist.
$fest = ['name' => $name, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'ist_admin' => $istAdmin];
$jetzt = ma_spalte_da(db(), 'passwort_geaendert_am');
$sql = 'INSERT INTO mitarbeiter (' . implode(', ', array_keys($fest))
     . ($jetzt ? ', passwort_geaendert_am' : '')
     . ($felder ? ', ' . implode(', ', $felder) : '')
     . ') VALUES (' . rtrim(str_repeat('?, ', count($fest)), ', ')
     . ($jetzt ? ', NOW()' : '')
     . str_repeat(', ?', count($felder)) . ')';

$werte = array_merge(array_values($fest), array_values($s));
db()->prepare($sql)->execute($werte);

json_response(['status' => 'ok']);

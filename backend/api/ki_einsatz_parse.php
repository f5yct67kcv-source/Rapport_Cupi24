<?php
// Zerlegt einen diktierten Planungsbefehl in Einsatzfelder (ENT-020).
// Schreibt nichts in die Datenbank -- das Ergebnis fuellt nur das Formular vor.
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

// Das heutige Datum kommt vom Geraet des Admins, weil "morgen" sich nach dessen
// Zeitzone richtet und nicht nach der des Servers. Format wird geprueft, sonst
// faellt es auf die Serverzeit zurueck.
$heute = trim((string)($input['heute'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $heute)) {
    $heute = date('Y-m-d');
}

$kunden = db()->query('SELECT name FROM kunden ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$mitarbeiter = db()->query(
    'SELECT name, vorname, nachname FROM mitarbeiter WHERE aktiv = 1 ORDER BY name'
)->fetchAll();

$ergebnis = anthropic_extract_einsatz($text, $kunden, $mitarbeiter, $heute);
if ($ergebnis === null) {
    json_response(['status' => 'error', 'message' => 'Erkennung nicht verfuegbar'], 502);
}

$felder = [];
foreach (['kunde_name', 'titel', 'strasse', 'ort', 'datum', 'von', 'bis', 'einsatzart', 'bemerkung'] as $f) {
    $wert = trim((string)($ergebnis[$f] ?? ''));
    if ($wert !== '') {
        $felder[$f] = $wert;
    }
}
if (isset($ergebnis['bedarf']) && (int)$ergebnis['bedarf'] > 0) {
    $felder['bedarf'] = min(99, (int)$ergebnis['bedarf']);
}

// Nur Login-Namen durchlassen, die es wirklich gibt -- ein erfundener Name
// darf nicht als Zuteilung in der Oberflaeche landen.
$bekannt = array_column($mitarbeiter, 'name');
$maNamen = array_values(array_intersect(
    array_map('strval', (array)($ergebnis['mitarbeiter_login_namen'] ?? [])),
    $bekannt
));

json_response([
    'status' => 'ok',
    'felder' => $felder,
    'mitarbeiter_login_namen' => $maNamen,
]);

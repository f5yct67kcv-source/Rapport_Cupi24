<?php
// Die eigenen Schichten eines Mitarbeitenden (ENT-023).
//
// Anders als alle uebrigen Planungs-Endpunkte NICHT admin-only -- aber strikt
// auf die eigene Person gefiltert. Niemand sieht hier die Einteilung anderer.
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();

$heute = date('Y-m-d');
$von = trim((string)($_GET['von'] ?? ''));
$bis = trim((string)($_GET['bis'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) {
    // Der Vortag muss mit: eine Nachtschicht von gestern laeuft heute noch.
    $von = date('Y-m-d', strtotime('-1 day'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) {
    $bis = date('Y-m-d', strtotime('+90 days'));
}

$stmt = db()->prepare(
    'SELECT e.id, e.kunde_name, e.titel, e.strasse, e.ort, e.einsatzart,
            e.datum, e.von, e.bis, e.status, e.bemerkung,
            z.zusage, o.name AS objekt_name
     FROM einsatz_zuteilung z
     JOIN einsaetze e ON e.id = z.einsatz_id
     LEFT JOIN objekte o ON o.id = e.objekt_id
     WHERE z.mitarbeiter_id = ? AND e.datum BETWEEN ? AND ?
     ORDER BY e.datum, e.von'
);
$stmt->execute([(int)$user['id'], $von, $bis]);

$schichten = array_map(function ($e) {
    $e['id'] = (int)$e['id'];
    return $e;
}, $stmt->fetchAll());

// Wie viele Kolleginnen und Kollegen sind sonst noch auf derselben Schicht?
// Nur die Anzahl, keine Namen -- das ist Planungsinformation, keine
// Personalauskunft.
$anzahl = [];
if ($schichten) {
    $ids = array_column($schichten, 'id');
    $marken = implode(',', array_fill(0, count($ids), '?'));
    $z = db()->prepare("SELECT einsatz_id, COUNT(*) AS n FROM einsatz_zuteilung
                        WHERE einsatz_id IN ($marken) GROUP BY einsatz_id");
    $z->execute($ids);
    foreach ($z->fetchAll() as $r) {
        $anzahl[(int)$r['einsatz_id']] = (int)$r['n'];
    }
}
foreach ($schichten as &$s) {
    $s['im_team'] = $anzahl[$s['id']] ?? 1;
}
unset($s);

json_response(['status' => 'ok', 'schichten' => $schichten, 'von' => $von, 'bis' => $bis]);

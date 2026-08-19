<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

require_session(); // jeder eingeloggte Nutzer braucht die Liste zum Ausfuellen des Rapports

// Aktive und archivierte Kunden kommen in einem Zug (ENT-040) -- wie schon
// bei objekte/einsaetze filtert das Dashboard selbst nach aktiv, statt einen
// zweiten Aufruf zu brauchen.
$rows = db()->query(
    'SELECT id, kundennummer, name, strasse, ort, telefon, kontaktperson, email, notiz, aktiv
     FROM kunden ORDER BY name'
)->fetchAll();
json_response(['status' => 'ok', 'kunden' => $rows]);

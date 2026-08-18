<?php
// Legt die Tabellen der Einsatzplanung an (ENT-020, ENT-021).
//
// Ersetzt das Kopieren von schema_planung.sql in phpMyAdmin. Der Endpunkt
// prueft selbst, was bereits vorhanden ist, und ergaenzt nur das Fehlende --
// er laesst sich also gefahrlos mehrfach aufrufen und deckt beide Faelle ab:
// vollstaendige Neuanlage und Nachtrag zur ersten Fassung.
//
// Er legt ausschliesslich an. Es wird nichts geloescht und nichts geleert.
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$pdo = db();

function hat_tabelle(PDO $pdo, string $tabelle): bool {
    $s = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $s->execute([$tabelle]);
    return (bool)$s->fetchColumn();
}
function hat_spalte(PDO $pdo, string $tabelle, string $spalte): bool {
    $s = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $s->execute([$tabelle, $spalte]);
    return (bool)$s->fetchColumn();
}
function hat_fremdschluessel(PDO $pdo, string $tabelle, string $spalte): bool {
    $s = $pdo->prepare(
        'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
           AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $s->execute([$tabelle, $spalte]);
    return (bool)$s->fetchColumn();
}

$getan = [];
$schon = [];

// ── 1. Tabellen. Reihenfolge zaehlt: worauf verwiesen wird, muss zuerst da sein.
$tabellen = [

'objekte' => "
CREATE TABLE IF NOT EXISTS objekte (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kunde_id INT NULL,
  kunde_name VARCHAR(200) NOT NULL,
  name VARCHAR(200) NOT NULL,
  strasse VARCHAR(200),
  ort VARCHAR(200) NOT NULL,
  kanton CHAR(2) NOT NULL DEFAULT 'SO',
  einsatzart VARCHAR(100) NOT NULL DEFAULT 'Revierdienst',
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  bemerkung TEXT,
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aktiv (aktiv),
  FOREIGN KEY (kunde_id) REFERENCES kunden(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'masterschichten' => "
CREATE TABLE IF NOT EXISTS masterschichten (
  id INT AUTO_INCREMENT PRIMARY KEY,
  objekt_id INT NOT NULL,
  name VARCHAR(200) NOT NULL,
  kuerzel VARCHAR(10),
  art VARCHAR(20) NOT NULL DEFAULT 'arbeit',
  von TIME NOT NULL,
  bis TIME NOT NULL,
  pause_von TIME NULL,
  pause_bis TIME NULL,
  pause_min INT NOT NULL DEFAULT 0,
  arbeitszeit_h DECIMAL(5,2) NOT NULL DEFAULT 0,
  farbe VARCHAR(7),
  auf_abruf TINYINT(1) NOT NULL DEFAULT 0,
  rhythmus VARCHAR(20) NOT NULL DEFAULT 'woche',
  bedarf_mo INT NOT NULL DEFAULT 0,
  bedarf_di INT NOT NULL DEFAULT 0,
  bedarf_mi INT NOT NULL DEFAULT 0,
  bedarf_do INT NOT NULL DEFAULT 0,
  bedarf_fr INT NOT NULL DEFAULT 0,
  bedarf_sa INT NOT NULL DEFAULT 0,
  bedarf_so INT NOT NULL DEFAULT 0,
  bedarf_feiertag INT NOT NULL DEFAULT 0,
  intervall_tage INT NULL,
  intervall_start DATE NULL,
  bedarf_intervall INT NOT NULL DEFAULT 1,
  gueltig_ab DATE NOT NULL,
  gueltig_bis DATE NULL,
  ersetzt_id INT NULL,
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_objekt (objekt_id),
  FOREIGN KEY (objekt_id) REFERENCES objekte(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'feiertage' => "
CREATE TABLE IF NOT EXISTS feiertage (
  id INT AUTO_INCREMENT PRIMARY KEY,
  datum DATE NOT NULL,
  kanton CHAR(2) NOT NULL,
  name VARCHAR(100) NOT NULL,
  halbtags TINYINT(1) NOT NULL DEFAULT 0,
  ab_zeit TIME NULL,
  quelle VARCHAR(255),
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tag (datum, kanton, name),
  KEY idx_kanton_datum (kanton, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'einsaetze' => "
CREATE TABLE IF NOT EXISTS einsaetze (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kunde_id INT NULL,
  kunde_name VARCHAR(200) NOT NULL,
  objekt_id INT NULL,
  masterschicht_id INT NULL,
  titel VARCHAR(200),
  strasse VARCHAR(200),
  ort VARCHAR(200) NOT NULL,
  einsatzart VARCHAR(100) NOT NULL DEFAULT 'Verkehrsdienst',
  datum DATE NOT NULL,
  von TIME NOT NULL,
  bis TIME NOT NULL,
  bedarf INT NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'geplant',
  bemerkung TEXT,
  erstellt_von INT NULL,
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_datum (datum),
  KEY idx_objekt (objekt_id, datum),
  FOREIGN KEY (kunde_id) REFERENCES kunden(id) ON DELETE SET NULL,
  FOREIGN KEY (objekt_id) REFERENCES objekte(id) ON DELETE SET NULL,
  FOREIGN KEY (masterschicht_id) REFERENCES masterschichten(id) ON DELETE SET NULL,
  FOREIGN KEY (erstellt_von) REFERENCES mitarbeiter(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'einsatz_zuteilung' => "
CREATE TABLE IF NOT EXISTS einsatz_zuteilung (
  einsatz_id INT NOT NULL,
  mitarbeiter_id INT NOT NULL,
  zusage VARCHAR(20) NOT NULL DEFAULT 'offen',
  zugeteilt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (einsatz_id, mitarbeiter_id),
  KEY idx_ma (mitarbeiter_id),
  FOREIGN KEY (einsatz_id) REFERENCES einsaetze(id) ON DELETE CASCADE,
  FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

foreach ($tabellen as $name => $sql) {
    if (hat_tabelle($pdo, $name)) {
        $schon[] = "Tabelle $name war bereits vorhanden";
        continue;
    }
    $pdo->exec($sql);
    $getan[] = "Tabelle $name angelegt";
}

// ── 2. Spalten nachtragen, falls die erste Fassung schon lief
$spalten = [
    ['einsaetze', 'objekt_id',        'ALTER TABLE einsaetze ADD COLUMN objekt_id INT NULL AFTER kunde_name'],
    ['einsaetze', 'masterschicht_id', 'ALTER TABLE einsaetze ADD COLUMN masterschicht_id INT NULL AFTER objekt_id'],
    ['einsatz_zuteilung', 'zusage',   "ALTER TABLE einsatz_zuteilung ADD COLUMN zusage VARCHAR(20) NOT NULL DEFAULT 'offen' AFTER mitarbeiter_id"],
    ['objekte', 'einsatzart',         "ALTER TABLE objekte ADD COLUMN einsatzart VARCHAR(100) NOT NULL DEFAULT 'Revierdienst' AFTER kanton"],
];
foreach ($spalten as [$tabelle, $spalte, $sql]) {
    if (!hat_tabelle($pdo, $tabelle) || hat_spalte($pdo, $tabelle, $spalte)) {
        continue;
    }
    $pdo->exec($sql);
    $getan[] = "Spalte $tabelle.$spalte ergaenzt";
}

// ── 3. Verweise und Index nachtragen, wenn die Spalten neu dazugekommen sind
$verweise = [
    ['einsaetze', 'objekt_id',        'ALTER TABLE einsaetze ADD FOREIGN KEY (objekt_id) REFERENCES objekte(id) ON DELETE SET NULL'],
    ['einsaetze', 'masterschicht_id', 'ALTER TABLE einsaetze ADD FOREIGN KEY (masterschicht_id) REFERENCES masterschichten(id) ON DELETE SET NULL'],
];
foreach ($verweise as [$tabelle, $spalte, $sql]) {
    if (!hat_spalte($pdo, $tabelle, $spalte) || hat_fremdschluessel($pdo, $tabelle, $spalte)) {
        continue;
    }
    $pdo->exec($sql);
    $getan[] = "Verweis $tabelle.$spalte ergaenzt";
}

// ── 4. Ergebnis. Fehlt am Schluss etwas, wird das gesagt statt verschwiegen.
$fehlt = [];
foreach (array_keys($tabellen) as $name) {
    if (!hat_tabelle($pdo, $name)) {
        $fehlt[] = $name;
    }
}

json_response([
    'status' => $fehlt ? 'error' : 'ok',
    'message' => $fehlt
        ? 'Diese Tabellen fehlen weiterhin: ' . implode(', ', $fehlt)
        : ($getan ? 'Einrichtung abgeschlossen.' : 'Alles war bereits eingerichtet.'),
    'getan' => $getan,
    'unveraendert' => $schon,
]);

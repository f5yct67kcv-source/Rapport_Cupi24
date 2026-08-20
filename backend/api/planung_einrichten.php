<?php
// Legt die Tabellen der Einsatzplanung an (ENT-020, ENT-021).
//
// Ersetzt das Kopieren von schema_planung.sql in phpMyAdmin. Der Endpunkt
// prueft selbst, was bereits vorhanden ist, und ergaenzt nur das Fehlende --
// er laesst sich also gefahrlos mehrfach aufrufen und deckt beide Faelle ab:
// vollstaendige Neuanlage und Nachtrag zur ersten Fassung.
//
// Er legt ausschliesslich an. Es wird nichts geloescht und nichts geleert.
//
// GET ist ein reiner Pruefmodus (kein exec) -- das Dashboard nutzt ihn, um
// den bestehenden Einrichten-Knopf farblich hervorzuheben, wenn seit dem
// letzten Aufruf neue Tabellen/Spalten hinzugekommen sind (ENT-033). Es
// entsteht dadurch kein zweiter Mechanismus: dieselbe Liste, derselbe Knopf.
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../kunden.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
$methode = $_SERVER['REQUEST_METHOD'];
if ($methode !== 'GET' && $methode !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur GET oder POST'], 405);
}
$nurPruefen = $methode === 'GET';

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

// Vertraglich vereinbarte Anstellungsorte nach Art. 18 Ziff. 2 (ENT-054).
// Der GAV erlaubt HOECHSTENS ZWEI, und wenn es zwei sind, muss der eine
// als Hauptanstellungsort (HAO) und der andere als Nebenanstellungsort
// (NAO) klar bezeichnet sein -- es gibt keine zwei HAO. Gemessen wird
// immer ab HAO; der NAO erzeugt nur das vorrangige Nebenanstellungsgebiet.
//
// Der PAKO-Kommentar verlangt eine genaue Adresse mit Strasse und Nummer
// ('ein Parkplatz ohne Adresse ist als vertraglich definierter
// Anstellungsort nicht zulaessig') -- darum ist strasse hier NOT NULL,
// anders als bei den Objekten.
'anstellungsorte' => "
CREATE TABLE IF NOT EXISTS anstellungsorte (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bezeichnung VARCHAR(200) NOT NULL,
  rolle VARCHAR(10) NOT NULL DEFAULT 'hao',
  strasse VARCHAR(200) NOT NULL,
  plz VARCHAR(10),
  ort VARCHAR(200) NOT NULL,
  km_zum_anderen DECIMAL(7,2) NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  bemerkung TEXT,
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rolle (rolle, aktiv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Wegstrecke Anstellungsort -> Objekt (ENT-054). Eine Zeile je Paar.
//
// Warum am Objekt und nicht am Einsatz: Gemessen wird HAO -> Einsatzort,
// und der Einsatzort ist das Objekt. Bei einem HAO und N Objekten gibt es
// also N Distanzen, nicht eine je Schicht. Das ist der Grund, warum die
// Sache ueberhaupt bezahlbar bleibt.
//
// quelle/ermittelt_am/bestaetigt_von halten fest, WOHER die Zahl stammt.
// An der 10-km-Grenze entscheidet sie ueber Geld -- da darf man spaeter
// nicht raten muessen, ob jemand sie eingetippt oder ein Dienst geliefert
// hat.
'objekt_distanz' => "
CREATE TABLE IF NOT EXISTS objekt_distanz (
  objekt_id INT NOT NULL,
  anstellungsort_id INT NOT NULL,
  km DECIMAL(7,2) NOT NULL,
  quelle VARCHAR(50) NOT NULL DEFAULT 'manuell',
  ermittelt_am DATE NULL,
  bestaetigt_von VARCHAR(100) NULL,
  bemerkung TEXT,
  geaendert_am DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (objekt_id, anstellungsort_id),
  KEY idx_ort (anstellungsort_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

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
  -- Sparte des Betriebs (ENT-037): 'sicherheit' oder 'reinigung'. Bewusst
  -- VARCHAR und nicht ENUM -- eine dritte Sparte braucht dann keine
  -- Tabellenaenderung. Am Objekt ist sie die Vorgabe, verbindlich ist die
  -- Sparte am einzelnen Einsatz.
  sparte VARCHAR(20) NOT NULL DEFAULT 'sicherheit',
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
  -- Eigene Sparte je Vorlage: dasselbe Objekt kann eine Sicherheits- und eine
  -- Reinigungsvorlage tragen, auch gleichzeitig (Baustelle, ENT-037).
  sparte VARCHAR(20) NOT NULL DEFAULT 'sicherheit',
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
  -- Hier ist die Sparte verbindlich: nach ihr wird gefiltert und getrennt.
  sparte VARCHAR(20) NOT NULL DEFAULT 'sicherheit',
  datum DATE NOT NULL,
  von TIME NOT NULL,
  bis TIME NOT NULL,
  bedarf INT NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'geplant',
  -- Das Ist neben dem Plan (ENT-045). Bis dahin wusste das System nur, was
  -- geplant war; abgerechnet und ausgewertet wird aber, was geleistet wurde.
  ist_status VARCHAR(20) NOT NULL DEFAULT 'offen',
  ist_von TIME NULL,
  ist_bis TIME NULL,
  ist_pause_von TIME NULL,
  ist_pause_min INT NULL,
  -- NULL heisst ausdruecklich 'noch nicht entschieden' und ist NICHT dasselbe
  -- wie 0/nein (GAV-AUS-004, ENT-046). Eine Vorbelegung waere eine
  -- stillschweigende GAV-Auslegung.
  ist_pause_bezahlt_ma TINYINT NULL,
  ist_pause_bezahlt_kunde TINYINT NULL,
  ist_bemerkung TEXT NULL,
  abgeglichen_von INT NULL,
  abgeglichen_am DATETIME NULL,
  bemerkung TEXT,
  erstellt_von INT NULL,
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_datum (datum),
  KEY idx_objekt (objekt_id, datum),
  FOREIGN KEY (kunde_id) REFERENCES kunden(id) ON DELETE SET NULL,
  FOREIGN KEY (objekt_id) REFERENCES objekte(id) ON DELETE SET NULL,
  FOREIGN KEY (masterschicht_id) REFERENCES masterschichten(id) ON DELETE SET NULL,
  FOREIGN KEY (erstellt_von) REFERENCES mitarbeiter(id) ON DELETE SET NULL,
  FOREIGN KEY (abgeglichen_von) REFERENCES mitarbeiter(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'einsatz_zuteilung' => "
CREATE TABLE IF NOT EXISTS einsatz_zuteilung (
  einsatz_id INT NOT NULL,
  mitarbeiter_id INT NOT NULL,
  zusage VARCHAR(20) NOT NULL DEFAULT 'offen',
  -- Der Abgleich laeuft je Person (ENT-045): eigene Ist-Zeiten und eigener
  -- Status je zugeteilter Person, weil dieselbe Person am selben Tag auf zwei
  -- Objekten unterschiedlich lang gearbeitet haben kann.
  ist_status VARCHAR(20) NOT NULL DEFAULT 'offen',
  ist_von TIME NULL,
  ist_bis TIME NULL,
  ist_pause_von TIME NULL,
  ist_pause_min INT NULL,
  ist_pause_bezahlt_ma TINYINT NULL,
  ist_pause_bezahlt_kunde TINYINT NULL,
  ist_bemerkung TEXT NULL,
  abgeglichen_von INT NULL,
  abgeglichen_am DATETIME NULL,
  zugeteilt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (einsatz_id, mitarbeiter_id),
  KEY idx_ma (mitarbeiter_id),
  FOREIGN KEY (einsatz_id) REFERENCES einsaetze(id) ON DELETE CASCADE,
  FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Sperrtage der Mitarbeitenden (ENT-028). Eine Sperre ist eine Mitteilung,
// kein technisches Verbot -- die Planung warnt, verbietet aber nicht.
'verfuegbarkeiten' => "
CREATE TABLE IF NOT EXISTS verfuegbarkeiten (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mitarbeiter_id INT NOT NULL,
  datum DATE NOT NULL,
  art VARCHAR(16) NOT NULL DEFAULT 'gesperrt',
  bemerkung VARCHAR(200) NULL,
  erfasst_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_person_tag (mitarbeiter_id, datum),
  KEY idx_datum (datum),
  FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Ansprechpersonen eines Kunden (ENT-044). Eigene Tabelle statt eines
// Textfelds, weil ein Kunde mehrere haben kann -- das bisherige Feld
// kunden.kontaktperson bleibt als Kurzfassung der ersten Person bestehen,
// damit Liste, Suche und CSV unveraendert weiterlaufen.
'kunden_person' => "
CREATE TABLE IF NOT EXISTS kunden_person (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kunde_id INT NOT NULL,
  anrede VARCHAR(20) NULL,
  vorname VARCHAR(100) NULL,
  nachname VARCHAR(100) NULL,
  sortierung INT NOT NULL DEFAULT 0,
  KEY idx_kunde (kunde_id, sortierung),
  FOREIGN KEY (kunde_id) REFERENCES kunden(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Kommunikationswege -- wahlweise der Firma selbst (person_id NULL) oder
// einer ihrer Ansprechpersonen. Eine Tabelle fuer beides, weil Aufbau und
// Bedienung identisch sind; zwei fast gleiche Tabellen waeren doppelte Logik.
'kunden_kontaktweg' => "
CREATE TABLE IF NOT EXISTS kunden_kontaktweg (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kunde_id INT NOT NULL,
  person_id INT NULL,
  art VARCHAR(20) NOT NULL,
  wert VARCHAR(255) NOT NULL,
  sortierung INT NOT NULL DEFAULT 0,
  KEY idx_kunde (kunde_id, sortierung),
  KEY idx_person (person_id),
  FOREIGN KEY (kunde_id) REFERENCES kunden(id) ON DELETE CASCADE,
  FOREIGN KEY (person_id) REFERENCES kunden_person(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

foreach ($tabellen as $name => $sql) {
    if (hat_tabelle($pdo, $name)) {
        $schon[] = "Tabelle $name war bereits vorhanden";
        continue;
    }
    if ($nurPruefen) { $getan[] = "Tabelle $name fehlt noch"; continue; }
    $pdo->exec($sql);
    $getan[] = "Tabelle $name angelegt";
}

// ── 2. Spalten nachtragen, falls die erste Fassung schon lief
$spalten = [
    ['einsaetze', 'objekt_id',        'ALTER TABLE einsaetze ADD COLUMN objekt_id INT NULL AFTER kunde_name'],
    ['einsaetze', 'masterschicht_id', 'ALTER TABLE einsaetze ADD COLUMN masterschicht_id INT NULL AFTER objekt_id'],
    ['einsatz_zuteilung', 'zusage',   "ALTER TABLE einsatz_zuteilung ADD COLUMN zusage VARCHAR(20) NOT NULL DEFAULT 'offen' AFTER mitarbeiter_id"],
    ['objekte', 'einsatzart',         "ALTER TABLE objekte ADD COLUMN einsatzart VARCHAR(100) NOT NULL DEFAULT 'Revierdienst' AFTER kanton"],
    ['verfuegbarkeiten', 'gesehen_am', 'ALTER TABLE verfuegbarkeiten ADD COLUMN gesehen_am DATETIME NULL AFTER erfasst_am'],
    // PLZ am Objekt (ENT-054). Fuer die Wegstrecke nach Art. 18 braucht es
    // eine eindeutige Adresse; Strasse plus Ort allein ist in der Schweiz
    // nicht immer eindeutig.
    ['objekte', 'plz', "ALTER TABLE objekte ADD COLUMN plz VARCHAR(10) NULL AFTER strasse"],
    // Sparte (ENT-037). Der Bestand ist ausnahmslos Sicherheit -- die Reinigung
    // kommt erst mit diesem Schritt dazu. Die Vorgabe traegt die Altdaten also
    // richtig, ohne dass etwas von Hand nachgetragen werden muss.
    ['objekte',         'sparte', "ALTER TABLE objekte ADD COLUMN sparte VARCHAR(20) NOT NULL DEFAULT 'sicherheit' AFTER einsatzart"],
    ['masterschichten', 'sparte', "ALTER TABLE masterschichten ADD COLUMN sparte VARCHAR(20) NOT NULL DEFAULT 'sicherheit' AFTER art"],
    ['einsaetze',       'sparte', "ALTER TABLE einsaetze ADD COLUMN sparte VARCHAR(20) NOT NULL DEFAULT 'sicherheit' AFTER einsatzart"],
    // Kundenuebersicht (ENT-040): eigene Nummer, Ansprechperson und Notiz als
    // durchsuchbare Zusatzfelder, sowie Archivierung statt endgueltigem
    // Loeschen -- gleiches Vorgehen wie objekte.aktiv.
    ['kunden', 'kundennummer',  'ALTER TABLE kunden ADD COLUMN kundennummer VARCHAR(10) NULL AFTER id, ADD UNIQUE KEY uniq_kundennummer (kundennummer)'],
    ['kunden', 'kontaktperson', 'ALTER TABLE kunden ADD COLUMN kontaktperson VARCHAR(200) NULL AFTER telefon'],
    ['kunden', 'notiz',         'ALTER TABLE kunden ADD COLUMN notiz TEXT NULL AFTER email'],
    ['kunden', 'aktiv',         'ALTER TABLE kunden ADD COLUMN aktiv TINYINT(1) NOT NULL DEFAULT 1 AFTER notiz'],
    // Ausbau des Kundenstamms (ENT-044). Der Bestand ist ausnahmslos
    // Unternehmen -- die Vorgabe traegt die Altdaten damit richtig, ohne dass
    // etwas von Hand nachzutragen waere. Alle uebrigen Felder sind freiwillig.
    ['kunden', 'art',         "ALTER TABLE kunden ADD COLUMN art VARCHAR(20) NOT NULL DEFAULT 'unternehmen' AFTER kundennummer"],
    ['kunden', 'anrede',      'ALTER TABLE kunden ADD COLUMN anrede VARCHAR(20) NULL AFTER art'],
    ['kunden', 'vorname',     'ALTER TABLE kunden ADD COLUMN vorname VARCHAR(100) NULL AFTER anrede'],
    ['kunden', 'nachname',    'ALTER TABLE kunden ADD COLUMN nachname VARCHAR(100) NULL AFTER vorname'],
    ['kunden', 'zusatzfeld',  'ALTER TABLE kunden ADD COLUMN zusatzfeld VARCHAR(200) NULL AFTER name'],
    ['kunden', 'hausnummer',  'ALTER TABLE kunden ADD COLUMN hausnummer VARCHAR(20) NULL AFTER strasse'],
    ['kunden', 'adresszusatz','ALTER TABLE kunden ADD COLUMN adresszusatz VARCHAR(200) NULL AFTER hausnummer'],
    ['kunden', 'plz',         'ALTER TABLE kunden ADD COLUMN plz VARCHAR(10) NULL AFTER adresszusatz'],
    ['kunden', 'uid',         'ALTER TABLE kunden ADD COLUMN uid VARCHAR(20) NULL AFTER ort'],
    ['kunden', 'mwst_nr',     'ALTER TABLE kunden ADD COLUMN mwst_nr VARCHAR(20) NULL AFTER uid'],
    // Schichtabgleich (ENT-045): das Ist neben dem Plan. Vorgabe 'offen', damit
    // der Bestand sichtbar unabgeglichen bleibt, statt faelschlich als
    // bestaetigt zu gelten -- was nie geprueft wurde, darf nicht so aussehen,
    // als waere es geprueft worden.
    ['einsaetze', 'ist_status',      "ALTER TABLE einsaetze ADD COLUMN ist_status VARCHAR(20) NOT NULL DEFAULT 'offen' AFTER status"],
    ['einsaetze', 'ist_von',         'ALTER TABLE einsaetze ADD COLUMN ist_von TIME NULL AFTER ist_status'],
    ['einsaetze', 'ist_bis',         'ALTER TABLE einsaetze ADD COLUMN ist_bis TIME NULL AFTER ist_von'],
    // Pause je Zeile (ENT-046): Beginn plus Dauer, wie in der Referenzloesung.
    // Das Ende ergibt sich rechnerisch und wird nicht gespeichert.
    ['einsaetze', 'ist_pause_von',   'ALTER TABLE einsaetze ADD COLUMN ist_pause_von TIME NULL AFTER ist_bis'],
    ['einsaetze', 'ist_pause_min',   'ALTER TABLE einsaetze ADD COLUMN ist_pause_min INT NULL AFTER ist_pause_von'],
    // TINYINT NULL, nicht NOT NULL DEFAULT 0: NULL heisst 'noch nicht
    // entschieden', 0 heisst 'geprueft und nein'. Der Unterschied ist bei
    // GAV-AUS-004 wesentlich -- eine Vorbelegung waere eine Auslegung.
    ['einsaetze', 'ist_pause_bezahlt_ma',     'ALTER TABLE einsaetze ADD COLUMN ist_pause_bezahlt_ma TINYINT NULL AFTER ist_pause_min'],
    ['einsaetze', 'ist_pause_bezahlt_kunde',  'ALTER TABLE einsaetze ADD COLUMN ist_pause_bezahlt_kunde TINYINT NULL AFTER ist_pause_bezahlt_ma'],
    ['einsaetze', 'ist_bemerkung',   'ALTER TABLE einsaetze ADD COLUMN ist_bemerkung TEXT NULL AFTER ist_pause_bezahlt_kunde'],
    ['einsaetze', 'abgeglichen_von', 'ALTER TABLE einsaetze ADD COLUMN abgeglichen_von INT NULL AFTER ist_bemerkung'],
    ['einsaetze', 'abgeglichen_am',  'ALTER TABLE einsaetze ADD COLUMN abgeglichen_am DATETIME NULL AFTER abgeglichen_von'],
    // Der Abgleich laeuft je Person, nicht je Schicht: eine Zeile ist eine
    // zugeteilte Person, mit eigenen Ist-Zeiten und eigenem Status. Dieselbe
    // Person kann am selben Tag auf zwei Objekten unterschiedlich lang
    // gearbeitet haben -- eine gemeinsame Zeit an der Schicht kann das nicht
    // abbilden. Die Felder an einsaetze oben bleiben fuer den Fall, dass gar
    // niemand zugeteilt war.
    ['einsatz_zuteilung', 'ist_status',      "ALTER TABLE einsatz_zuteilung ADD COLUMN ist_status VARCHAR(20) NOT NULL DEFAULT 'offen' AFTER zusage"],
    ['einsatz_zuteilung', 'ist_von',         'ALTER TABLE einsatz_zuteilung ADD COLUMN ist_von TIME NULL AFTER ist_status'],
    ['einsatz_zuteilung', 'ist_bis',         'ALTER TABLE einsatz_zuteilung ADD COLUMN ist_bis TIME NULL AFTER ist_von'],
    ['einsatz_zuteilung', 'ist_pause_von',   'ALTER TABLE einsatz_zuteilung ADD COLUMN ist_pause_von TIME NULL AFTER ist_bis'],
    ['einsatz_zuteilung', 'ist_pause_min',   'ALTER TABLE einsatz_zuteilung ADD COLUMN ist_pause_min INT NULL AFTER ist_pause_von'],
    ['einsatz_zuteilung', 'ist_pause_bezahlt_ma',    'ALTER TABLE einsatz_zuteilung ADD COLUMN ist_pause_bezahlt_ma TINYINT NULL AFTER ist_pause_min'],
    ['einsatz_zuteilung', 'ist_pause_bezahlt_kunde', 'ALTER TABLE einsatz_zuteilung ADD COLUMN ist_pause_bezahlt_kunde TINYINT NULL AFTER ist_pause_bezahlt_ma'],
    ['einsatz_zuteilung', 'ist_bemerkung',   'ALTER TABLE einsatz_zuteilung ADD COLUMN ist_bemerkung TEXT NULL AFTER ist_pause_bezahlt_kunde'],
    ['einsatz_zuteilung', 'abgeglichen_von', 'ALTER TABLE einsatz_zuteilung ADD COLUMN abgeglichen_von INT NULL AFTER ist_bemerkung'],
    ['einsatz_zuteilung', 'abgeglichen_am',  'ALTER TABLE einsatz_zuteilung ADD COLUMN abgeglichen_am DATETIME NULL AFTER abgeglichen_von'],
];
foreach ($spalten as [$tabelle, $spalte, $sql]) {
    if (!hat_tabelle($pdo, $tabelle) || hat_spalte($pdo, $tabelle, $spalte)) {
        continue;
    }
    if ($nurPruefen) { $getan[] = "Spalte $tabelle.$spalte fehlt noch"; continue; }
    $pdo->exec($sql);
    $getan[] = "Spalte $tabelle.$spalte ergaenzt";
}

// ── 2b. Kundennummern nachtragen, wenn Kunden ohne eigene Nummer bestehen --
// entweder aus der Zeit vor ENT-040 oder weil die Spalte gerade erst oben
// dazukam. Reihenfolge nach id, damit die Vergabe nachvollziehbar bleibt.
if (hat_spalte($pdo, 'kunden', 'kundennummer')) {
    $ohneNummer = $pdo->query(
        'SELECT id FROM kunden WHERE kundennummer IS NULL ORDER BY id'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($ohneNummer) {
        if ($nurPruefen) {
            $getan[] = count($ohneNummer) . ' Kunde(n) ohne Kundennummer';
        } else {
            foreach ($ohneNummer as $kid) {
                $nr = naechste_kundennummer($pdo);
                $pdo->prepare('UPDATE kunden SET kundennummer = ? WHERE id = ?')->execute([$nr, $kid]);
            }
            $getan[] = count($ohneNummer) . ' Kundennummer(n) vergeben';
        }
    }
}

// ── 2b2. Die beiden Anstellungsorte einmalig hinterlegen (ENT-055).
// Auf ausdrueckliche Bitte des Projektinhabers, damit er sie nicht von Hand
// erfassen muss. Laeuft NUR, wenn die Tabelle leer ist -- wer die Orte
// spaeter ueber die Oberflaeche aendert, bekommt sie nicht wieder
// ueberschrieben.
//
// Betriebsdaten im Code sind an sich unschoen. Hier vertretbar, weil das
// Werkzeug rein intern eingesetzt wird (ENT-008) und die Adressen auf
// cupi24.ch oeffentlich stehen. Wuerde daraus je ein Produkt fuer Dritte,
// muss dieser Block als Erstes verschwinden.
//
// Die 19 km stammen aus der Angabe des Projektinhabers, nicht aus einer
// eigenen Messung. Sie entscheiden nach Art. 18: unter 40 km ist im
// Nebenanstellungsgebiet nichts geschuldet (Ziff. 3.2.5).
if (hat_tabelle($pdo, 'anstellungsorte')) {
    $anzahl = (int)$pdo->query('SELECT COUNT(*) FROM anstellungsorte')->fetchColumn();
    if ($anzahl === 0) {
        if ($nurPruefen) {
            $getan[] = 'Anstellungsorte Trimbach (HAO) und Gelterkinden (NAO) hinterlegen';
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO anstellungsorte (bezeichnung, rolle, strasse, plz, ort, km_zum_anderen, aktiv, bemerkung)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
            );
            $ins->execute(['Hauptsitz Trimbach', 'hao', 'Baslerstrasse 67', '4632', 'Trimbach', 19.0,
                'Hauptanstellungsort nach Art. 18 Ziff. 2 GAV (ENT-055). Von hier wird gemessen.']);
            $ins->execute(['Standort Gelterkinden', 'nao', 'Rünenbergerstrasse 44', '4460', 'Gelterkinden', 19.0,
                'Nebenanstellungsort nach Art. 18 Ziff. 2 GAV (ENT-055). Erzeugt das Nebenanstellungsgebiet.']);
            $getan[] = 'Anstellungsorte Trimbach (HAO) und Gelterkinden (NAO) hinterlegt, 19 km auseinander';
        }
    }
}

// ── 2c. PLZ aus dem bisherigen Ort-Feld herausloesen (ENT-044). Bis hierher
// stand beides zusammen in einer Spalte ("4632 Trimbach"). Getrennt wird nur,
// wo das Muster eindeutig ist: vier Ziffern, Leerzeichen, Rest. Passt es
// nicht, bleibt der Wert unveraendert stehen -- lieber ungetrennt als falsch
// zerlegt. Laeuft nur ueber Kunden, deren plz noch leer ist, und ist damit
// beliebig oft wiederholbar.
if (hat_spalte($pdo, 'kunden', 'plz')) {
    $ungetrennt = $pdo->query(
        "SELECT id, ort FROM kunden
         WHERE (plz IS NULL OR plz = '') AND ort REGEXP '^[0-9]{4}[[:space:]]' ORDER BY id"
    )->fetchAll();
    if ($ungetrennt) {
        if ($nurPruefen) {
            $getan[] = count($ungetrennt) . ' Kunde(n) mit PLZ und Ort in einem Feld';
        } else {
            $s = $pdo->prepare('UPDATE kunden SET plz = ?, ort = ? WHERE id = ?');
            foreach ($ungetrennt as $k) {
                [$plz, $ort] = plz_ort_trennen((string)$k['ort']);
                $s->execute([$plz, $ort, (int)$k['id']]);
            }
            $getan[] = count($ungetrennt) . ' Adresse(n) in PLZ und Ort getrennt';
        }
    }
}

// ── 3. Verweise und Index nachtragen, wenn die Spalten neu dazugekommen sind
$verweise = [
    ['einsaetze', 'objekt_id',        'ALTER TABLE einsaetze ADD FOREIGN KEY (objekt_id) REFERENCES objekte(id) ON DELETE SET NULL'],
    ['einsaetze', 'masterschicht_id', 'ALTER TABLE einsaetze ADD FOREIGN KEY (masterschicht_id) REFERENCES masterschichten(id) ON DELETE SET NULL'],
    ['einsaetze', 'abgeglichen_von',  'ALTER TABLE einsaetze ADD FOREIGN KEY (abgeglichen_von) REFERENCES mitarbeiter(id) ON DELETE SET NULL'],
];
foreach ($verweise as [$tabelle, $spalte, $sql]) {
    if (!hat_spalte($pdo, $tabelle, $spalte) || hat_fremdschluessel($pdo, $tabelle, $spalte)) {
        continue;
    }
    if ($nurPruefen) { $getan[] = "Verweis $tabelle.$spalte fehlt noch"; continue; }
    $pdo->exec($sql);
    $getan[] = "Verweis $tabelle.$spalte ergaenzt";
}

// ── 4. Ergebnis. Fehlt am Schluss etwas, wird das gesagt statt verschwiegen.
// Im Pruefmodus (GET) heisst "fehlt" nur "noch nicht eingerichtet", kein Fehler --
// das Dashboard liest dafuer 'ausstehend', nicht 'status'.
$fehlt = [];
foreach (array_keys($tabellen) as $name) {
    if (!hat_tabelle($pdo, $name)) {
        $fehlt[] = $name;
    }
}

json_response([
    'status' => (!$nurPruefen && $fehlt) ? 'error' : 'ok',
    'message' => $nurPruefen
        ? ($getan ? count($getan) . ' Punkt(e) stehen noch aus.' : 'Alles ist eingerichtet.')
        : ($fehlt
            ? 'Diese Tabellen fehlen weiterhin: ' . implode(', ', $fehlt)
            : ($getan ? 'Einrichtung abgeschlossen.' : 'Alles war bereits eingerichtet.')),
    'getan' => $getan,
    'unveraendert' => $schon,
    'ausstehend' => count($getan),
]);

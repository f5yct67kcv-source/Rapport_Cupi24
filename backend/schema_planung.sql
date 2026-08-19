-- Nachtrag zum Schema: Einsatzplanung (ENT-020, ENT-021)
-- Einmalig im Hostpoint-Datenbank-Tool (phpMyAdmin) ausfuehren.
-- Ergaenzt das bestehende Schema, veraendert keine vorhandene Tabelle.
--
-- ════════════════════════════════════════════════════════════════════
--  WICHTIG -- genau EINEN der beiden Teile ausfuehren:
--
--  TEIL A  wenn diese Datei noch NIE ausgefuehrt wurde  -> alles unten
--  TEIL B  wenn die Fassung vom 17.08. (nur einsaetze + einsatz_zuteilung)
--          bereits gelaufen ist  -> nur der Block ganz am Schluss
-- ════════════════════════════════════════════════════════════════════


-- ══════════════════════════════════════════════════════════════════════
-- TEIL A -- vollstaendige Neuanlage
-- ══════════════════════════════════════════════════════════════════════

-- Der feste Einsatzort eines Dauerauftrags. Ein Kunde kann mehrere Objekte
-- haben. Der Kanton haengt hier, weil daran die Feiertage haengen.
CREATE TABLE objekte (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kunde_id INT NULL,
  kunde_name VARCHAR(200) NOT NULL,
  name VARCHAR(200) NOT NULL,
  strasse VARCHAR(200),
  ort VARCHAR(200) NOT NULL,
  kanton CHAR(2) NOT NULL DEFAULT 'SO',
  -- Einsatzart der daraus entstehenden Schichten. Eigenschaft des
  -- Dauerauftrags, damit sie nicht je Schicht geraten werden muss.
  einsatzart VARCHAR(100) NOT NULL DEFAULT 'Revierdienst',
  -- Sparte (ENT-037): 'sicherheit' oder 'reinigung'. Am Objekt die Vorgabe,
  -- verbindlich ist die Sparte am einzelnen Einsatz. Bewusst VARCHAR statt
  -- ENUM -- eine dritte Sparte braucht dann keine Tabellenaenderung.
  sparte VARCHAR(20) NOT NULL DEFAULT 'sicherheit',
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  bemerkung TEXT,
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aktiv (aktiv),
  FOREIGN KEY (kunde_id) REFERENCES kunden(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vorlage fuer wiederkehrende Schichten an einem Objekt.
--
-- Aenderungen erzeugen eine NEUE Zeile mit gueltig_ab; die alte bekommt ein
-- gueltig_bis. Bereits erzeugte Schichten sind Kopien und bleiben unberuehrt.
-- Damit veraendert keine spaetere Bearbeitung die Vergangenheit (ENT-021).
CREATE TABLE masterschichten (
  id INT AUTO_INCREMENT PRIMARY KEY,
  objekt_id INT NOT NULL,
  name VARCHAR(200) NOT NULL,
  kuerzel VARCHAR(10),

  -- Arbeit oder Fahrtzeit. Getrennt gefuehrt, weil die Frage, ob Fahrtzeit
  -- bezahlte Arbeitszeit ist, eine GAV-Frage ist -- sie wird hier NICHT
  -- beantwortet, nur nachvollziehbar gehalten.
  art VARCHAR(20) NOT NULL DEFAULT 'arbeit',
  -- Eigene Sparte je Vorlage: dasselbe Objekt kann eine Sicherheits- und
  -- eine Reinigungsvorlage tragen, auch gleichzeitig (Baustelle, ENT-037).
  sparte VARCHAR(20) NOT NULL DEFAULT 'sicherheit',

  von TIME NOT NULL,
  -- Liegt "bis" vor "von", laeuft die Schicht ueber Mitternacht.
  bis TIME NOT NULL,

  -- Pause getrennt gespeichert, nicht in die Arbeitszeit hineingerechnet
  -- (GAV-AUS-004 ist offen).
  pause_von TIME NULL,
  pause_bis TIME NULL,
  pause_min INT NOT NULL DEFAULT 0,

  -- Effektive Arbeitszeit, wie sie der Verwaltung angezeigt wird.
  -- Vorgeschlagen aus von/bis/pause, bei Bedarf uebersteuerbar.
  arbeitszeit_h DECIMAL(5,2) NOT NULL DEFAULT 0,

  farbe VARCHAR(7),

  -- "auf Abruf": erzeugte Schichten entstehen als provisorisch, weil die
  -- Durchfuehrung von einer nicht planbaren Bedingung abhaengt (Wetter).
  auf_abruf TINYINT(1) NOT NULL DEFAULT 0,

  -- Zwei Rhythmusarten, kein allgemeines Regelwerk:
  --   woche     -> Anzahl je Wochentag, siehe bedarf_*
  --   intervall -> jeden n-ten Tag ab intervall_start, siehe bedarf_intervall
  rhythmus VARCHAR(20) NOT NULL DEFAULT 'woche',
  bedarf_mo INT NOT NULL DEFAULT 0,
  bedarf_di INT NOT NULL DEFAULT 0,
  bedarf_mi INT NOT NULL DEFAULT 0,
  bedarf_do INT NOT NULL DEFAULT 0,
  bedarf_fr INT NOT NULL DEFAULT 0,
  bedarf_sa INT NOT NULL DEFAULT 0,
  bedarf_so INT NOT NULL DEFAULT 0,
  -- Bedarf an einem Feiertag. Bewusst eigener Wert: an Feiertagen laeuft der
  -- Betrieb oft anders. Das ist eine Bedarfsangabe, KEINE Lohnbewertung.
  bedarf_feiertag INT NOT NULL DEFAULT 0,
  intervall_tage INT NULL,
  intervall_start DATE NULL,
  bedarf_intervall INT NOT NULL DEFAULT 1,

  -- Gueltigkeitszeitraum. Deckt zugleich saisonale Fenster ab
  -- (z.B. nur April bis Oktober) -- dafuer braucht es keine eigene Art.
  gueltig_ab DATE NOT NULL,
  gueltig_bis DATE NULL,
  -- Verweis auf die Vorgaengerfassung, wenn diese Zeile durch eine Aenderung
  -- mit Stichtag entstanden ist.
  ersetzt_id INT NULL,

  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_objekt (objekt_id),
  FOREIGN KEY (objekt_id) REFERENCES objekte(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Feiertagskalender. Gepflegte Tatsachen mit Quelle -- ausdruecklich KEINE
-- Lohnbewertung. Ob ein Feiertag einen Zeitbonus oder eine Entschaedigung
-- ausloest, ist offen (GAV-AUS-003, GAV-AUS-006) und wird hier nicht
-- entschieden.
CREATE TABLE feiertage (
  id INT AUTO_INCREMENT PRIMARY KEY,
  datum DATE NOT NULL,
  kanton CHAR(2) NOT NULL,
  name VARCHAR(100) NOT NULL,
  -- Halbe Feiertage gibt es wirklich: der 1. Mai gilt in Solothurn nur
  -- nachmittags.
  halbtags TINYINT(1) NOT NULL DEFAULT 0,
  ab_zeit TIME NULL,
  quelle VARCHAR(255),
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tag (datum, kanton, name),
  KEY idx_kanton_datum (kanton, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ein geplanter Einsatz. Bewusst getrennt vom Rapport: geplant wird vorher,
-- rapportiert wird nachher und von Hand durch die Mitarbeitenden. Aus einem
-- Einsatz entsteht KEIN Rapport automatisch.
CREATE TABLE einsaetze (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Kunde: Verweis auf die Kundendatei, zusaetzlich der Name als Kopie.
  -- Wird der Kunde spaeter entfernt, bleibt der geplante Einsatz lesbar.
  kunde_id INT NULL,
  kunde_name VARCHAR(200) NOT NULL,

  -- Gesetzt, wenn die Schicht aus einer Masterschicht entstanden ist.
  -- Die Werte sind KOPIEN -- eine spaetere Aenderung der Vorlage veraendert
  -- diesen Datensatz nicht.
  objekt_id INT NULL,
  masterschicht_id INT NULL,

  -- Bezeichnung des Einsatzes, z.B. "Fasnachtsumzug" oder "Baustelle Kreisel".
  titel VARCHAR(200),

  -- ARBEITSORT, nicht der Firmensitz des Kunden. Im Verkehrsdienst ist der
  -- Einsatzort praktisch immer eine andere Adresse als die Rechnungsadresse.
  strasse VARCHAR(200),
  ort VARCHAR(200) NOT NULL,

  einsatzart VARCHAR(100) NOT NULL DEFAULT 'Verkehrsdienst',
  -- Hier ist die Sparte verbindlich: danach wird gefiltert und getrennt.
  sparte VARCHAR(20) NOT NULL DEFAULT 'sicherheit',

  datum DATE NOT NULL,
  von TIME NOT NULL,
  -- Liegt "bis" vor "von", laeuft der Einsatz ueber Mitternacht in den Folgetag.
  bis TIME NOT NULL,

  -- Wie viele Mitarbeitende der Einsatz braucht. Die Zuteilung kann darunter
  -- oder darueber liegen -- die Oberflaeche zeigt die Differenz an.
  bedarf INT NOT NULL DEFAULT 1,

  -- geplant | bestaetigt | abgesagt | provisorisch
  -- provisorisch = aus einer Masterschicht "auf Abruf"; zaehlt nicht als
  -- offene Stelle, bis kurzfristig bestaetigt wird.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wer ist welchem Einsatz zugeteilt. Eigene Tabelle, weil ein Einsatz mehrere
-- Mitarbeitende hat und eine Person mehrere Einsaetze.
CREATE TABLE einsatz_zuteilung (
  einsatz_id INT NOT NULL,
  mitarbeiter_id INT NOT NULL,
  -- Rueckmeldung aus der spaeteren Mobil-App: offen | zugesagt | abgelehnt.
  -- Eine Information, KEINE Voraussetzung fuer den Einsatz (ENT-021).
  zusage VARCHAR(20) NOT NULL DEFAULT 'offen',
  zugeteilt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (einsatz_id, mitarbeiter_id),
  KEY idx_ma (mitarbeiter_id),
  FOREIGN KEY (einsatz_id) REFERENCES einsaetze(id) ON DELETE CASCADE,
  FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ══════════════════════════════════════════════════════════════════════
-- TEIL B -- nur ausfuehren, wenn die Fassung vom 17.08. schon lief
--           (also einsaetze und einsatz_zuteilung bereits bestehen).
--           Dann TEIL A oben NICHT ausfuehren, sondern nur den Block hier:
-- ══════════════════════════════════════════════════════════════════════
--
-- ... zuerst die drei CREATE TABLE aus TEIL A fuer objekte, masterschichten
--     und feiertage ausfuehren, danach:
--
-- ALTER TABLE einsaetze
--   ADD COLUMN objekt_id INT NULL AFTER kunde_name,
--   ADD COLUMN masterschicht_id INT NULL AFTER objekt_id,
--   ADD KEY idx_objekt (objekt_id, datum),
--   ADD FOREIGN KEY (objekt_id) REFERENCES objekte(id) ON DELETE SET NULL,
--   ADD FOREIGN KEY (masterschicht_id) REFERENCES masterschichten(id) ON DELETE SET NULL;
--
-- ALTER TABLE einsatz_zuteilung
--   ADD COLUMN zusage VARCHAR(20) NOT NULL DEFAULT 'offen' AFTER mitarbeiter_id;

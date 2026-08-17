-- Schema fuer das gemeinsame Backend (ENT-010)
-- Ausfuehren im Hostpoint-Datenbank-Tool (phpMyAdmin), einmalig.
-- Ersetzt die vorherige Version (Sessions-Tabelle ergaenzt).

CREATE TABLE mitarbeiter (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  ist_admin TINYINT(1) NOT NULL DEFAULT 0,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sessions (
  token VARCHAR(64) PRIMARY KEY,
  mitarbeiter_id INT NOT NULL,
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rapporte (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mitarbeiter_id INT NOT NULL,
  datum DATE NOT NULL,
  kunde VARCHAR(200) NOT NULL,
  strasse VARCHAR(200) NOT NULL,
  ort VARCHAR(200) NOT NULL,
  auftrag_nr VARCHAR(100),
  einsatzart VARCHAR(100) NOT NULL DEFAULT 'Verkehrsdienst',
  von TIME NOT NULL,
  bis TIME NOT NULL,
  pause_min INT NOT NULL DEFAULT 0,
  netto_h DECIMAL(5,2) NOT NULL,
  unterzeichner VARCHAR(200),
  unterschrift MEDIUMTEXT,
  bemerkung TEXT,
  erfasst_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nachtrag zum Schema: Einsatzplanung (ENT-020)
-- Einmalig im Hostpoint-Datenbank-Tool (phpMyAdmin) ausfuehren.
-- Ergaenzt das bestehende Schema, veraendert keine vorhandene Tabelle.

-- Ein geplanter Einsatz. Bewusst getrennt vom Rapport: geplant wird vorher,
-- rapportiert wird nachher und von Hand durch die Mitarbeitenden. Aus einem
-- Einsatz entsteht KEIN Rapport automatisch.
CREATE TABLE einsaetze (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Kunde: Verweis auf die Kundendatei, zusaetzlich der Name als Kopie.
  -- Wird der Kunde spaeter entfernt, bleibt der geplante Einsatz lesbar.
  kunde_id INT NULL,
  kunde_name VARCHAR(200) NOT NULL,

  -- Bezeichnung des Einsatzes, z.B. "Fasnachtsumzug" oder "Baustelle Kreisel".
  titel VARCHAR(200),

  -- ARBEITSORT, nicht der Firmensitz des Kunden. Im Verkehrsdienst ist der
  -- Einsatzort praktisch immer eine andere Adresse als die Rechnungsadresse.
  strasse VARCHAR(200),
  ort VARCHAR(200) NOT NULL,

  einsatzart VARCHAR(100) NOT NULL DEFAULT 'Verkehrsdienst',

  datum DATE NOT NULL,
  von TIME NOT NULL,
  -- Liegt "bis" vor "von", laeuft der Einsatz ueber Mitternacht in den Folgetag.
  bis TIME NOT NULL,

  -- Wie viele Mitarbeitende der Einsatz braucht. Die Zuteilung kann darunter
  -- oder darueber liegen -- die Oberflaeche zeigt die Differenz an.
  bedarf INT NOT NULL DEFAULT 1,

  status VARCHAR(20) NOT NULL DEFAULT 'geplant',
  bemerkung TEXT,

  erstellt_von INT NULL,
  erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,

  KEY idx_datum (datum),
  FOREIGN KEY (kunde_id) REFERENCES kunden(id) ON DELETE SET NULL,
  FOREIGN KEY (erstellt_von) REFERENCES mitarbeiter(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wer ist welchem Einsatz zugeteilt. Eigene Tabelle, weil ein Einsatz mehrere
-- Mitarbeitende hat und eine Person mehrere Einsaetze.
CREATE TABLE einsatz_zuteilung (
  einsatz_id INT NOT NULL,
  mitarbeiter_id INT NOT NULL,
  zugeteilt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (einsatz_id, mitarbeiter_id),
  KEY idx_ma (mitarbeiter_id),
  FOREIGN KEY (einsatz_id) REFERENCES einsaetze(id) ON DELETE CASCADE,
  FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

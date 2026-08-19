-- ══════════════════════════════════════════════════════════════════════
-- Verfuegbarkeit (ENT-028) -- Stufe 2 aus ENT-017
--
-- Mitarbeitende sperren sich einzelne Tage, an denen sie nicht koennen.
-- Eine Sperre ist eine MITTEILUNG, keine Sperre im technischen Sinn: die
-- Planung warnt deutlich, verbietet das Einteilen aber nicht (ENT-028).
--
-- Einmalig im Hostpoint-Datenbank-Tool ausfuehren.
-- ══════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS verfuegbarkeiten (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mitarbeiter_id INT NOT NULL,
  datum DATE NOT NULL,
  -- Bewusst VARCHAR und nicht ENUM: ein spaeterer Wert wie "wunsch"
  -- (moechte an diesem Tag arbeiten) braucht dann keine Tabellenaenderung.
  art VARCHAR(16) NOT NULL DEFAULT 'gesperrt',
  -- Freitext der Person, z.B. "erst ab 18 Uhr" oder "Arzttermin".
  -- Ganze Tage sind die kleinste Einheit; wer es genauer braucht, schreibt
  -- es hierhin, und die Planung sieht es.
  bemerkung VARCHAR(200) NULL,
  erfasst_am DATETIME DEFAULT CURRENT_TIMESTAMP,
  -- Wann ein Admin die Sperre auf der Uebersicht als erledigt markiert hat
  -- (ENT-033). NULL = noch offen, taucht im Ereignis-Feed auf.
  gesehen_am DATETIME NULL,
  UNIQUE KEY uq_person_tag (mitarbeiter_id, datum),
  KEY idx_datum (datum),
  FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

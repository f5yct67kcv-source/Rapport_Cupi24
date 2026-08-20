/* ══════════════════════════════════════════════════════════════════════════
   GAV-REGELN — gemeinsam für Verwaltung (dashboard.html) und
   Mitarbeiter-App (app.html).

   WARUM EINE DATEI: Dieselbe Schicht muss in beiden Oberflächen dieselbe
   Zahl ergeben. Sieht die Verwaltung andere Stunden als die betroffene
   Person selbst, ist der Streit vorprogrammiert — und im Zweifel steht die
   Person schlechter da, weil sie die Abweichung erklären müsste. Zwei
   Kopien einer Lohnregel driften früher oder später auseinander; die eine
   Kopie hier kann es nicht.

   WAS HIER NICHT PASSIERT: Es entsteht kein Lohn, keine Rechnung, kein
   Zeitzuschlag nach Art. 14 Ziff. 3 und kein Ferienstand. Die Funktionen
   liefern Zeiten, die angezeigt und von einem Menschen beurteilt werden.

   Bezugsversion: GAV private Sicherheitsdienstleistungen, Ausgabe 2026.
   Offene Auslegungsfragen: siehe `90-gav/auslegungsregister.md`
   (GAV-AUS-001 bis GAV-AUS-008 im Dokumentations-Repository).
   ══════════════════════════════════════════════════════════════════════════ */

/* Versioniertes Regelwerk mit Gültigkeitszeitraum, wie CLAUDE.md Teil B es
   verlangt: eine spätere GAV-Revision darf zurückliegende Monate nicht
   rückwirkend verändern. Fällt ein Datum in keinen Zeitraum, wird NICHT
   gerechnet — lieber keine Zahl als eine auf abgelaufener Grundlage.

   Beim Fortschreiben: neuen Eintrag ANHÄNGEN, den alten stehen lassen. */
const GAV_REGELWERK = [
  {
    quelle: 'GAV private Sicherheitsdienstleistungen, Ausgabe 2026 (AVE vom 11.12.2025)',
    ab: '2026-01-01', bis: '2026-12-31',
    satz: 0.10,                              // 6 Minuten pro Stunde
    nachtAb: 23 * 60, nachtBis: 6 * 60,      // 23:00–06:00, über Mitternacht
    sonntagAb: 6 * 60, sonntagBis: 23 * 60,  // 06:00–23:00 an Sonntagen
  },
];
const gavRegel = datum => GAV_REGELWERK.find(r => datum >= r.ab && datum <= r.bis) || null;

/* Rohzeit in Minuten: bis minus von, über Mitternacht hinweg. OHNE
   Pausenabzug — die Pausenpflicht bemisst sich an der Zeit vor dem Abzug,
   sonst wäre die Rechnung zirkulär (die Pause kürzte ihre eigene Pflicht). */
function gavRohMin(von, bis) {
  if (!von || !bis) { return null; }
  const min = t => Number(String(t).slice(0, 2)) * 60 + Number(String(t).slice(3, 5));
  let d = min(bis) - min(von);
  if (d < 0) { d += 1440; }
  return d;
}

/* Nettozeit = Rohzeit minus UNBEZAHLTER Pause (ENT-047).
   Art. 13 Ziff. 2 rechnet eine bezahlte Pause ausdrücklich zur Arbeitszeit.

   Nur die MA-Kennzeichnung wirkt. "Bezahlte Pause Kunde" ist eine
   Verrechnungsfrage gegenüber dem Auftraggeber und hat mit dem Lohn nichts
   zu tun — die beiden dürfen nie zusammengeworfen werden.

   Ist die Kennzeichnung noch nicht gesetzt (null), wird abgezogen: das ist
   die bisherige Rechnung und bleibt der Ausgangszustand, solange die
   Feststellung nach Art. 13 Ziff. 2 niemand getroffen hat. */
function gavNetto(von, bis, pauseMin, pauseBezahltMa) {
  let d = gavRohMin(von, bis);
  if (d === null) { return ''; }
  if (Number(pauseBezahltMa) !== 1) { d -= Number(pauseMin || 0); }
  if (d < 0) { return ''; }
  return `${String(Math.floor(d / 60)).padStart(2, '0')}:${String(d % 60).padStart(2, '0')}`;
}

/* Mindestpause nach Art. 13 Ziff. 1 — WÖRTLICH aus dem Vertrag:
   15 Min. bei mehr als 5½ Std., 30 Min. bei mehr als 7, 60 Min. bei mehr
   als 9. Die Zahlen sind eindeutig; offen ist nur, WORAUF sie angewendet
   werden (GAV-AUS-007). Hier: Rohzeit der einzelnen Schicht. */
const GAV_PAUSE_REGEL = [
  { abMin: 9 * 60, pause: 60, text: 'mehr als 9 Std.' },
  { abMin: 7 * 60, pause: 30, text: 'mehr als 7 Std.' },
  { abMin: 5.5 * 60, pause: 15, text: 'mehr als 5½ Std.' },
];
function gavPauseSoll(von, bis) {
  const roh = gavRohMin(von, bis);
  if (roh === null) { return null; }
  const treffer = GAV_PAUSE_REGEL.find(r => roh > r.abMin);
  return treffer ? { min: treffer.pause, weil: treffer.text } : { min: 0, weil: null };
}

/* Zeitbonus nach Art. 12 Ziff. 2: 6 Minuten (10 %) pro Stunde, die in ein
   Bonusfenster fällt — Nachtarbeit 23:00–06:00 oder Sonntagsarbeit
   06:00–23:00, jeweils inklusive Pause.

   Minutenweise über den echten Kalenderverlauf, weil eine Schicht über
   Mitternacht in einen Sonntag hineinlaufen kann.

   Die beiden Fenster sind KOMPLEMENTÄR — dieselbe Minute kann nie in beide
   fallen. Die vom PaKo bestätigte Regel "nur einmal, nicht doppelt" ist
   damit bauartbedingt eingehalten (GAV-AUS-001, geklärt).

   ANTEILIG statt nur volle Stunden: der Vertrag nennt 6 Minuten UND 10 %
   im selben Atemzug, und ein Prozentsatz ist seiner Natur nach anteilig.
   Für 15-Minuten-Runden ist das entscheidend — GAV-AUS-008, vorläufige
   Annahme, noch nicht von der PaKo bestätigt.

   FEIERTAGE FEHLEN: welche Liste gilt, ist offen (GAV-AUS-006). Ein
   Feiertag, der kein Sonntag ist, bekommt hier keinen Bonus; die Summe ist
   dann ZU TIEF. Aufrufer müssen das kenntlich machen — dafür gibt es
   gavFeiertagLuecke().

   Rückgabe null heisst "kein Regelwerk für dieses Datum". */
function gavBonusMin(datum, von, bis) {
  const regel = gavRegel(datum);
  if (!regel || !von || !bis) { return null; }
  const min = t => Number(String(t).slice(0, 2)) * 60 + Number(String(t).slice(3, 5));
  const start = min(von);
  let ende = min(bis);
  if (ende <= start) { ende += 1440; }
  const tag0 = new Date(datum + 'T12:00:00');
  let imFenster = 0;
  for (let m = start; m < ende; m++) {
    const tagesMin = ((m % 1440) + 1440) % 1440;
    const d = new Date(tag0.getTime() + Math.floor(m / 1440) * 864e5);
    const inNacht = tagesMin >= regel.nachtAb || tagesMin < regel.nachtBis;
    const inSonntag = d.getDay() === 0 && tagesMin >= regel.sonntagAb && tagesMin < regel.sonntagBis;
    if (inNacht || inSonntag) { imFenster++; }
  }
  return imFenster * regel.satz;
}

/* Fällt eine Schicht auf einen Feiertag, der kein Sonntag ist? Dann fehlt
   in der Summe ein Bonus, den der GAV vorsieht — das muss man sehen,
   sonst hält man eine zu tiefe Zahl für vollständig.
   `karte` ist eine Zuordnung Datum -> Feiertag (beliebiger Wahrheitswert). */
function gavFeiertagLuecke(datum, von, bis, karte) {
  const tage = [datum];
  if (von && bis && bis <= von) {
    const d = new Date(datum + 'T12:00:00');
    d.setDate(d.getDate() + 1);
    tage.push(new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10));
  }
  return tage.some(t => (karte || {})[t] && new Date(t + 'T12:00:00').getDay() !== 0);
}

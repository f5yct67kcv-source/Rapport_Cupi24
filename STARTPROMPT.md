# Startprompts

Die Arbeitsregeln stehen in `CLAUDE.md` und werden von jeder Sitzung
**automatisch** gelesen. Der Startprompt muss sie darum nicht wiederholen —
er sagt nur, **welcher Bereich** dieser Sitzung gehört.

Kurz ist hier besser: Ein langer Prompt, den man jedes Mal einfügt, veraltet
und wird nicht gepflegt. Die Datei wird gepflegt.

---

## Der allgemeine Startprompt

> Du arbeitest am Repository `rapport_cupi24`. Lies zuerst `CLAUDE.md` — dort
> stehen die Arbeitsregeln, sie gelten vollständig.
>
> **Mein Bereich in dieser Sitzung: _<BEREICH>_.**
> Ausserhalb davon liest du, aber änderst nichts. Fällt dir ausserhalb etwas
> auf, meldest du es mir, statt es zu beheben — es arbeitet gleichzeitig eine
> andere Sitzung daran.
>
> Beginne mit `git fetch origin main` und sag mir, ob etwas Neues da ist.

`<BEREICH>` durch einen der folgenden ersetzen.

---

## Die Bereiche

| Bereich | Was dazugehört | Ansichten und Dateien |
|---|---|---|
| **Planung** | Einsätze, Objekte, Masterschichten, Objektplanung, Tagesplan, Feiertage, Zuteilung, Einsatzplan | `view-planung`, `view-einsatzplan`, `pv-*`, `backend/api/einsatz_*`, `objekt_*`, `masterschicht_*`, `schichten_*`, `zuteilung_*`, `backend/planung.php` |
| **Kunden** | Kundenstamm, Import, KI-Recherche | `view-kunden`, `backend/api/kunden_*`, `ki_kunden_*`, `backend/kunden.php` |
| **Personal** | Mitarbeitende, Personalakte, Personaldossier, Verlauf | `view-mitarbeiter`, `mv-*`, `md-*`, `backend/api/mitarbeiter_*`, `backend/mitarbeiter.php` |
| **Abgleich** | Ist-Zeiten, Rapporte, Pensen, Ruhezeit | `view-abgleich`, `view-pensen`, `backend/api/einsatz_abgleich.php`, `rapport_*` |
| **App** | Mobile Mitarbeiter-Ansicht, Erfassung | `app.html`, `index.html`, `backend/api/mein*`, `meine_*` |
| **Betrieb** | Anstellungsorte, Listen, Zwei-Faktor, Rollen, Einrichtung | `view-betrieb`, `backend/rechte.php`, `logbuch.php`, `zweifaktor.php`, `anmeldung.php`, `planung_einrichten.php` |

---

## Querliegendes läuft allein

**Nicht** parallel zu Bereichsarbeit — es berührt jeden Bereich:

- Rechte und Rollen, Sitzungen, Anmeldung, Zwei-Faktor
- Datenmodell und Einrichtung (`planung_einrichten.php`)
- Deploy-Workflow, `.htaccess`
- Alles, was `db.php`, `rechte.php` oder die Navigation anfasst

Startprompt dafür:

> Du arbeitest am Repository `rapport_cupi24`. Lies zuerst `CLAUDE.md`.
>
> **Diese Sitzung ist querliegend: _<THEMA>_.** Sie darf jeden Bereich
> anfassen. Sag mir zu Beginn, dass ich in dieser Zeit **keine zweite
> Sitzung** am Code laufen lassen soll — und erinnere mich daran, wenn ich
> es doch tue.
>
> Beginne mit `git fetch origin main`.

---

## Skizzenmodus

Wenn du ein Skizzen-Protokoll übergibst, gehört das zum Bereich, in dem die
Skizze entstanden ist. Ergänzung zum Startprompt:

> Ich arbeite mit dem Skizzenmodus (Alt+S). Wenn ich dir ein Protokoll gebe:
> lies Selektor und Alt-Neu-Wert, finde die Stelle im echten Code und ändere
> sie **dort** — nicht als Inline-Stil. Nimm die Pixel als Absicht, nicht als
> Vorschrift, und ordne das Element ins bestehende Raster ein. Miss das
> Ergebnis am gerenderten Zustand, mobil und am Desktop.

---

## Wenn eine Sitzung endet

Am Ende einer Sitzung, bevor du sie schliesst:

> Fasse zusammen, was du geändert hast, was noch offen ist und was ich
> selbst tun muss. Prüfe, ob alles gepusht ist und `node pruefungen/alle.mjs`
> grün war.

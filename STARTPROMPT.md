# Startprompts

Die Arbeitsregeln stehen in `CLAUDE.md` und werden von jeder Sitzung
**automatisch** gelesen. Der Startprompt wiederholt sie nicht — er sagt nur,
**welche Aufgabe** dieser Sitzung gehört.

Kurz ist hier besser: Ein langer Prompt, den man jedes Mal einfügt, veraltet
und wird nicht gepflegt. Die Datei wird gepflegt.

---

## Die Grundform: ein Chat, eine Aufgabe

Ein Chat wird für **eine** Aufgabe geöffnet und nach Abschluss geschlossen.
Nicht ein Chat je Bereich auf Dauer — der hat nie einen Moment, an dem er
sauber übergibt.

> Du arbeitest am Repository `rapport_cupi24`. Lies zuerst `CLAUDE.md` — dort
> stehen die Arbeitsregeln, sie gelten vollständig.
>
> **Bereich: _<BEREICH>_ — Aufgabe: _<AUFGABE>_.**
> Ausserhalb dieser Aufgabe liest du, aber änderst nichts. Fällt dir
> ausserhalb etwas auf, meldest du es mir, statt es zu beheben — es arbeitet
> gleichzeitig eine andere Sitzung daran.
>
> Bevor du etwas baust: frag nach, was sich später nur mit Umbau ändern
> lässt. Danach: `git fetch origin main` und sag mir, ob etwas Neues da ist.

Beispiel: *Bereich: Planung — Aufgabe: Schublade beim Klick auf eine Schicht
umfassender strukturieren.*

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

## Gestaltung gehört in die Aufgabe, nicht in einen eigenen Chat

**Kein dauerhafter Design-Chat.** In diesem Projekt ist Gestaltung nicht von
Funktion trennbar: `dashboard.html` trägt Aufbau, Verhalten und CSS in einer
Datei. Wer dort „nur das Layout" ändert, ändert das, was die Prüfungen des
Bereichs messen.

Am 21.08.2026 belegt: Ein Umbau „nach Skizze" verschob einen Knopf aus der
Werkzeugleiste (die Prüfung meldete Funktionsverlust), ersetzte die
Bearbeitungs-Schublade durch eine Vollbildansicht — und diese Ansicht kannte
die Regel für festgeschriebene Schichten nicht. Drei Prüfungen wurden rot,
eine Lücke blieb eine Nacht lang produktiv.

Also: Das Skizzen-Protokoll geht an den **Aufgaben-Chat des Bereichs**.
Ergänzung zu dessen Startprompt:

> Ich arbeite mit dem Skizzenmodus (Alt+S). Wenn ich dir ein Protokoll gebe:
> lies Selektor und Alt-Neu-Wert, finde die Stelle im echten Code und ändere
> sie **dort** — nicht als Inline-Stil. Nimm die Pixel als Absicht, nicht als
> Vorschrift, und ordne das Element ins bestehende Raster ein. Miss das
> Ergebnis am gerenderten Zustand, mobil und am Desktop.
>
> Sag mir ausdrücklich, wenn eine Gestaltungsänderung zugleich das Verhalten
> ändert — ein verschobener Knopf, ein anderer Weg zu einer Funktion.

---

## Querliegendes läuft allein

**Nicht** parallel zu anderer Arbeit — es berührt jeden Bereich:

- Rechte und Rollen, Sitzungen, Anmeldung, Zwei-Faktor
- Datenmodell und Einrichtung (`planung_einrichten.php`)
- Deploy-Workflow, `.htaccess`
- Alles, was `db.php`, `rechte.php` oder die Navigation anfasst
- **Querliegende Gestaltung:** Farbwerte, Abstandsraster, Typografie,
  gemeinsame Bausteine — das ist der einzige Fall, in dem ein reiner
  Gestaltungs-Chat sinnvoll ist

> Du arbeitest am Repository `rapport_cupi24`. Lies zuerst `CLAUDE.md`.
>
> **Diese Sitzung ist querliegend: _<THEMA>_.** Sie darf jeden Bereich
> anfassen. Sag mir zu Beginn, dass ich in dieser Zeit **keine zweite
> Sitzung** am Code laufen lassen soll — und erinnere mich daran, wenn ich es
> doch tue.
>
> Beginne mit `git fetch origin main`.

---

## Bevor du einen Chat schliesst

> Fasse zusammen: was geändert, was noch offen, was muss ich selbst tun.
> Prüfe, ob alles gepusht ist und `node pruefungen/alle.mjs` grün war.
> Gehört etwas ins Entscheidungsprotokoll oder in die offenen Punkte?

Erst danach schliessen. Ein Chat, der ohne diesen Schritt endet, hinterlässt
Wissen, das niemand mehr hat.

---

## Was dieser Schnitt NICHT löst

`dashboard.html` ist eine Datei mit über 9000 Zeilen und trägt fast alle
Bereiche. Der Aufgaben-Schnitt macht Überschneidungen unwahrscheinlich, nicht
unmöglich. Die Prüfungen bleiben das Netz:

```
node pruefungen/alle.mjs
```

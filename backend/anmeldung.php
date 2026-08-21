<?php
declare(strict_types=1);
// Bremse gegen Passwort-Raten (ENT-075).
//
// Bis hierher durfte jemand unbegrenzt oft und beliebig schnell raten.
// Zusammen mit einer Mindestlaenge von sechs Zeichen war das die
// gefaehrlichste Kombination der Sicherheitspruefung.
//
// ZWEI GRENZEN, weil ein Angreifer sonst einfach ausweicht:
//   - je Login-Name: wer EIN Konto knacken will, wird gebremst.
//   - je Absender-Adresse: wer viele Namen durchprobiert, auch.
//
// DIE BREMSE DARF NICHT SELBST ZUR WAFFE WERDEN. Wer einen Login-Namen
// kennt, koennte ihn sonst dauerhaft aussperren. Darum: zeitlich begrenzt,
// nie dauerhaft, und die Sperre laeuft von selbst ab.
//
// Und sie darf nicht verraten, ob es den Namen gibt: Die Meldung ist
// dieselbe wie bei falschem Passwort, nur um den Hinweis auf die Wartezeit
// ergaenzt -- und die gibt es fuer einen erfundenen Namen genauso.

const ANMELD_FENSTER_MIN  = 15;   // Zeitraum, in dem Fehlversuche zaehlen
const ANMELD_MAX_NAME     = 5;    // Fehlversuche je Login-Name im Fenster
const ANMELD_MAX_ADRESSE  = 20;   // Fehlversuche je Absender-Adresse im Fenster
const ANMELD_SPERRE_MIN   = 15;   // wie lange danach gesperrt wird

// Absender-Adresse. Hostpoint liefert sie in REMOTE_ADDR; die weitergegebenen
// Kopfzeilen (X-Forwarded-For) werden BEWUSST nicht benutzt -- die kann ein
// Angreifer frei setzen und damit die Bremse je Adresse umgehen.
function anmeld_adresse(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unbekannt'), 0, 45);
}

function hat_tabelle_anmeldung(PDO $pdo): bool
{
    static $da = null;
    if ($da === null) {
        $da = (bool)$pdo->query("SHOW TABLES LIKE 'anmeldeversuche'")->fetchColumn();
    }
    return $da;
}

// Die Entscheidung als eigene Funktion, damit sie sich OHNE Datenbank
// ausfuehren laesst -- die Browser-Pruefungen kaemen hier nie vorbei.
// Gibt die Sperrdauer in Minuten zurueck, 0 = frei.
function anmeld_sperre(int $fehlerName, int $fehlerAdresse): int
{
    if ($fehlerName >= ANMELD_MAX_NAME || $fehlerAdresse >= ANMELD_MAX_ADRESSE) {
        return ANMELD_SPERRE_MIN;
    }
    return 0;
}

// Wie viele Fehlversuche liegen im Fenster? Gibt [jeName, jeAdresse] zurueck.
function anmeld_zaehlen(PDO $pdo, string $name, string $adresse): array
{
    if (!hat_tabelle_anmeldung($pdo)) { return [0, 0]; }
    $s = $pdo->prepare(
        'SELECT
            SUM(login_name = ?) AS je_name,
            SUM(adresse = ?)    AS je_adresse
         FROM anmeldeversuche
         WHERE zeitpunkt > DATE_SUB(NOW(), INTERVAL ' . ANMELD_FENSTER_MIN . ' MINUTE)'
    );
    $s->execute([$name, $adresse]);
    $r = $s->fetch();
    return [(int)($r['je_name'] ?? 0), (int)($r['je_adresse'] ?? 0)];
}

function anmeld_fehlversuch(PDO $pdo, string $name, string $adresse): void
{
    if (!hat_tabelle_anmeldung($pdo)) { return; }
    $pdo->prepare('INSERT INTO anmeldeversuche (login_name, adresse) VALUES (?, ?)')
        ->execute([substr($name, 0, 100), $adresse]);
    // Gelegentlich aufraeumen: Die Tabelle ist ein Kurzzeitgedaechtnis und
    // soll keine Sammlung werden, wer wann von wo aus etwas versucht hat.
    if (random_int(1, 20) === 1) {
        $pdo->exec('DELETE FROM anmeldeversuche
                    WHERE zeitpunkt < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}

// ── Passwortregeln (ENT-075) ──────────────────────────────────────────
//
// Bis hierher genuegten sechs Zeichen. Sechs Zeichen sind in Minuten
// durchprobiert -- zusammen mit der fehlenden Bremse war das die
// gefaehrlichste Kombination der Sicherheitspruefung.
//
// LAENGE VOR ZEICHENSALAT. Kein Zwang zu Sonderzeichen und Grossbuchstaben:
// Das erzeugt "Passwort1!" und Zettel am Bildschirm. Ein langes, merkbares
// Passwort ist schwerer zu raten als ein kurzes mit Sonderzeichen -- so
// steht es auch in den Empfehlungen des Bundes.
//
// DIE REGEL GILT BEIM SETZEN, NICHT BEIM ANMELDEN. Wer ein aelteres,
// kuerzeres Passwort hat, kommt weiterhin rein; er wird nur beim naechsten
// Wechsel auf die neue Laenge verpflichtet. Sonst waeren mit dem Deploy
// schlagartig alle Konten ausgesperrt.
const PASSWORT_MIN = 12;

// Was offensichtlich zu schwach ist, auch wenn es lang genug waere.
// Kurze Liste mit Absicht: Sie soll die naheliegenden Faelle fangen, nicht
// eine Passwortpruefung ersetzen.
const PASSWORT_VERBOTEN = [
    'passwort', 'password', 'geheim', '123456', 'qwertz', 'qwerty',
    'cupi24', 'sicherheit', 'admin', 'willkommen', 'schweiz',
];

// Prueft ein NEUES Passwort. Gibt null zurueck, wenn es taugt, sonst den
// Grund im Klartext -- der Grund geht an die Oberflaeche und muss ohne
// Nachschlagen verstaendlich sein.
function passwort_pruefen(string $passwort, string $loginName = ''): ?string
{
    if (mb_strlen($passwort) < PASSWORT_MIN) {
        return 'Passwort mindestens ' . PASSWORT_MIN . ' Zeichen. '
             . 'Lieber eine merkbare Wortfolge als ein kurzes mit Sonderzeichen.';
    }
    $klein = mb_strtolower($passwort);
    // Der eigene Login-Name im Passwort ist das Erste, was jemand probiert.
    if ($loginName !== '' && mb_strlen($loginName) >= 3
        && str_contains($klein, mb_strtolower($loginName))) {
        return 'Das Passwort darf den Login-Namen nicht enthalten.';
    }
    foreach (PASSWORT_VERBOTEN as $wort) {
        if (str_contains($klein, $wort)) {
            return 'Das Passwort enthält ein zu naheliegendes Wort ("' . $wort . '").';
        }
    }
    // Ein einziges wiederholtes Zeichen ist lang, aber nicht schwer.
    if (count(array_unique(mb_str_split($klein))) < 5) {
        return 'Das Passwort besteht aus zu wenigen verschiedenen Zeichen.';
    }
    return null;
}

// Nach erfolgreicher Anmeldung: die Fehlversuche dieses Namens loeschen.
// Sonst schleppt jemand, der sich einmal vertippt hat, das noch Minuten mit.
function anmeld_zuruecksetzen(PDO $pdo, string $name): void
{
    if (!hat_tabelle_anmeldung($pdo)) { return; }
    $pdo->prepare('DELETE FROM anmeldeversuche WHERE login_name = ?')->execute([$name]);
}

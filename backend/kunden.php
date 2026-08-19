<?php
// Fachlogik rund um Kunden: Vergabe der Kundennummer (ENT-040).
declare(strict_types=1);

// Naechste freie Kundennummer, Format K0001 aufwaerts. Wird aus dem
// bestehenden Hoechststand abgeleitet statt aus einem eigenen Zaehler --
// so bleibt die Vergabe luecken- und kollisionsfrei, auch wenn zwischendurch
// Kunden geloescht wurden oder die Nummer aus einem Nachtrag stammt.
function naechste_kundennummer(PDO $pdo): string
{
    $s = $pdo->query(
        "SELECT kundennummer FROM kunden WHERE kundennummer REGEXP '^K[0-9]{4}$'
         ORDER BY CAST(SUBSTRING(kundennummer, 2) AS UNSIGNED) DESC LIMIT 1"
    );
    $letzte = $s->fetchColumn();
    $n = $letzte ? ((int)substr((string)$letzte, 1)) + 1 : 1;
    return 'K' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

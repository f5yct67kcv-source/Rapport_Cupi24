<?php
declare(strict_types=1);

// KI-Sprachbefehl-Pilot (ENT-015). Nimmt bereits transkribierten Text
// entgegen (Sprach-zu-Text laeuft ueber die native Tastaturdiktierfunktion
// des Geraets, nicht hier) und zerlegt ihn per Anthropic-API in
// strukturierte Mitarbeiter-Felder. Schreibt nie selbst in die Datenbank --
// nur Extraktion, das Speichern bleibt beim Admin.

function anthropic_tool_call(array $tool, string $userContent): ?array {
    $apiKey = '__ANTHROPIC_API_KEY__';
    if ($apiKey === '' || str_contains($apiKey, '__ANTHROPIC_API_KEY')) {
        return null; // nicht konfiguriert
    }

    $payload = [
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 512,
        'tools' => [$tool],
        'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
        'messages' => [
            ['role' => 'user', 'content' => $userContent],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $tool['name']) {
            return $block['input'] ?? [];
        }
    }
    return null;
}

function anthropic_extract_mitarbeiter(string $text): ?array {
    $tool = [
        'name' => 'extract_mitarbeiter',
        'description' => 'Extrahiert Mitarbeiter-Personaldaten aus einem deutschen Satz.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'vorname' => ['type' => 'string'],
                'nachname' => ['type' => 'string'],
                'personalnummer' => ['type' => 'string'],
                'anrede' => ['type' => 'string', 'enum' => ['Herr', 'Frau', 'Divers']],
                'geburtsdatum' => ['type' => 'string', 'description' => 'Format JJJJ-MM-TT'],
                'strasse' => ['type' => 'string'],
                'ort' => ['type' => 'string', 'description' => 'PLZ und Ort zusammen, z.B. "3011 Bern"'],
                'telefon' => ['type' => 'string'],
                'mobil' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
        ],
    ];
    return anthropic_tool_call($tool, $text);
}

// Identifiziert den gemeinten (bestehenden) Mitarbeiter aus einer Liste
// (auch bei Tippfehlern/Umschreibungen) und die zu aendernden Felder.
function anthropic_extract_mitarbeiter_edit(string $text, array $mitarbeiterListe): ?array {
    $tool = [
        'name' => 'extract_mitarbeiter_edit',
        'description' => 'Identifiziert den gemeinten Mitarbeiter aus der gegebenen Liste und die zu aendernden Felder samt neuen Werten.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'mitarbeiter_login_name' => [
                    'type' => 'string',
                    'description' => 'Der Login-Name des gemeinten Mitarbeiters -- exakt wie in der Liste angegeben, auch wenn der Befehl einen Tippfehler oder eine Umschreibung enthaelt.',
                ],
                'aenderungen' => [
                    'type' => 'object',
                    'description' => 'Nur die tatsaechlich im Befehl genannten Felder eintragen, alle anderen weglassen.',
                    'properties' => [
                        'personalnummer' => ['type' => 'string'],
                        'anrede' => ['type' => 'string', 'enum' => ['Herr', 'Frau', 'Divers']],
                        'vorname' => ['type' => 'string'],
                        'nachname' => ['type' => 'string'],
                        'geburtsdatum' => ['type' => 'string', 'description' => 'Format JJJJ-MM-TT'],
                        'strasse' => ['type' => 'string'],
                        'ort' => ['type' => 'string', 'description' => 'PLZ und Ort zusammen'],
                        'telefon' => ['type' => 'string'],
                        'mobil' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['mitarbeiter_login_name', 'aenderungen'],
        ],
    ];

    $listeText = implode("\n", array_map(
        fn($m) => "- {$m['name']}: " . trim(($m['vorname'] ?? '') . ' ' . ($m['nachname'] ?? '')),
        $mitarbeiterListe
    ));
    $userContent = "Bestehende Mitarbeiter (Login-Name: Vorname Nachname):\n{$listeText}\n\nBefehl: {$text}";

    return anthropic_tool_call($tool, $userContent);
}

// Ausweitung des Piloten auf das Kundenformular (ENT-018). Gleiche Regel wie
// oben: nur Extraktion, gespeichert wird erst nach Pruefung durch den Admin.
function anthropic_extract_kunde(string $text): ?array
{
    $tool = [
        'name' => 'extract_kunde',
        'description' => 'Extrahiert Kunden-Stammdaten aus einem deutschen Satz.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Firmen- oder Kundenname, inkl. Rechtsform wie GmbH oder AG'],
                'strasse' => ['type' => 'string', 'description' => 'Strasse mit Hausnummer, ohne Ort'],
                'ort' => ['type' => 'string', 'description' => 'Postleitzahl und Ort zusammen, z.B. "4632 Trimbach"'],
                'telefon' => ['type' => 'string', 'description' => 'Telefonnummer in der genannten Schreibweise'],
                'email' => ['type' => 'string', 'description' => 'E-Mail-Adresse'],
            ],
        ],
    ];

    return anthropic_tool_call($tool, $text);
}

// Einsatzplanung per Diktat (ENT-020). Wieder reine Extraktion: das Modell
// ordnet zu, gespeichert wird erst nach Pruefung im Formular.
//
// Bewusst OHNE Internetrecherche. Kunde und Mitarbeitende stehen bereits in
// der eigenen Datei -- was fehlt, wird nicht erraten, sondern leer gelassen.
function anthropic_extract_einsatz(string $text, array $kunden, array $mitarbeiter, string $heute): ?array
{
    $tool = [
        'name' => 'extract_einsatz',
        'description' => 'Extrahiert einen geplanten Einsatz aus einem deutschen Satz.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'kunde_name' => [
                    'type' => 'string',
                    'description' => 'Der Kunde. Steht er in der Kundenliste, exakt so schreiben wie dort -- '
                        . 'auch bei Tippfehlern oder Abkuerzungen im Befehl. Sonst so, wie genannt.',
                ],
                'titel' => [
                    'type' => 'string',
                    'description' => 'Kurze Bezeichnung des Einsatzes, z.B. "Fasnachtsumzug" oder '
                        . '"Baustelle Kreisel". Nur eintragen, wenn im Befehl genannt.',
                ],
                'strasse' => [
                    'type' => 'string',
                    'description' => 'Strasse und Hausnummer des ARBEITSORTES, ohne Ort. Nicht der Firmensitz des Kunden.',
                ],
                'ort' => [
                    'type' => 'string',
                    'description' => 'PLZ und Ort des ARBEITSORTES, z.B. "4632 Trimbach". Nicht der Firmensitz des Kunden.',
                ],
                'datum' => ['type' => 'string', 'description' => 'Format JJJJ-MM-TT'],
                'von'   => ['type' => 'string', 'description' => 'Startzeit im Format HH:MM'],
                'bis'   => ['type' => 'string', 'description' => 'Endzeit im Format HH:MM'],
                'bedarf' => [
                    'type' => 'integer',
                    'description' => 'Wie viele Mitarbeitende der Einsatz braucht. Werden Personen namentlich '
                        . 'genannt, ohne dass eine Anzahl gesagt wurde, ist das deren Anzahl.',
                ],
                'einsatzart' => ['type' => 'string', 'description' => 'z.B. Verkehrsdienst. Nur wenn genannt.'],
                'mitarbeiter_login_namen' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Login-Namen der namentlich genannten Mitarbeitenden -- exakt wie in der '
                        . 'Personalliste. Wird niemand namentlich genannt, leer lassen.',
                ],
                'bemerkung' => ['type' => 'string', 'description' => 'Zusatzangaben, die in kein anderes Feld passen.'],
            ],
        ],
    ];

    $kundenText = $kunden
        ? implode("\n", array_map(fn($k) => '- ' . $k, $kunden))
        : '(keine Kunden erfasst)';
    $maText = $mitarbeiter
        ? implode("\n", array_map(
            fn($m) => "- {$m['name']}: " . trim(($m['vorname'] ?? '') . ' ' . ($m['nachname'] ?? '')),
            $mitarbeiter))
        : '(keine Mitarbeitenden erfasst)';

    $userContent =
        "Heutiges Datum: {$heute}. Relative Angaben wie \"morgen\" oder \"naechsten Freitag\" darauf beziehen.\n\n"
        . "Bekannte Kunden:\n{$kundenText}\n\n"
        . "Bekannte Mitarbeitende (Login-Name: Vorname Nachname):\n{$maText}\n\n"
        . "Regeln: Erfinde nichts. Ein Feld, das im Befehl nicht vorkommt, laesst du weg. "
        . "Der Ort ist der Arbeitsort des Einsatzes, nicht die Rechnungsadresse des Kunden.\n\n"
        . "Befehl: {$text}";

    return anthropic_tool_call($tool, $userContent);
}

// Kunden-Recherche (ENT-019). Anders als die Funktionen oben: hier darf das
// Modell zuerst im Internet suchen und uebergibt erst danach die Felder.
// Deshalb kein erzwungenes tool_choice (das wuerde die Suche blockieren) und
// eine Schleife statt eines Einzelaufrufs.
//
// Gesucht wird der statutarische Sitz aus dem Handelsregister -- das ist die
// Rechnungsadresse. Der Arbeitsort eines Einsatzes ist etwas anderes und wird
// hier bewusst nicht ermittelt.
function anthropic_recherche_kunde(string $text): ?array
{
    $apiKey = '__ANTHROPIC_API_KEY__';
    if ($apiKey === '' || str_contains($apiKey, '__ANTHROPIC_API_KEY')) {
        return null;
    }

    $felder = ['name', 'strasse', 'ort', 'telefon', 'email'];

    $uebernehmen = [
        'name' => 'kunde_uebernehmen',
        'description' => 'Uebergibt die ermittelten Kundendaten an die Eingabemaske. Genau einmal aufrufen, am Ende.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'name'     => ['type' => 'string', 'description' => 'Offizieller Firmenname inkl. Rechtsform, z.B. "Borner AG"'],
                'strasse'  => ['type' => 'string', 'description' => 'Strasse und Hausnummer des Firmensitzes, ohne Ort'],
                'ort'      => ['type' => 'string', 'description' => 'Postleitzahl und Ort zusammen, z.B. "4652 Winznau"'],
                'telefon'  => ['type' => 'string', 'description' => 'Allgemeine Telefonnummer der Firma'],
                'email'    => ['type' => 'string', 'description' => 'Allgemeine E-Mail-Adresse der Firma'],
                'recherchiert' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $felder],
                    'description' => 'Feldnamen, deren Wert aus dem Internet stammt und nicht vom Benutzer genannt wurde.',
                ],
                'quellen' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Die URLs, auf die sich die recherchierten Werte stuetzen. Hoechstens drei.',
                ],
            ],
            'required' => ['name'],
        ],
    ];

    $system =
        "Du ermittelst Stammdaten Schweizer Firmen fuer eine Kundendatei. Die Adresse ist immer der "
        . "statutarische Sitz aus dem Handelsregister (Zefix) -- das ist die Rechnungsadresse.\n\n"
        . "Regeln:\n"
        . "- Erfinde nichts. Ein Feld, das du nicht belegen kannst, laesst du weg. Eine Luecke ist "
        . "richtig, eine plausible Erfindung ist ein Schaden.\n"
        . "- Suche zuerst im Internet, rufe danach kunde_uebernehmen genau einmal auf.\n"
        . "- Uebernimm Angaben, die der Benutzer bereits genannt hat, unveraendert und fuehre sie "
        . "NICHT in 'recherchiert'.\n"
        . "- Findest du mehrere Firmen mit aehnlichem Namen, nimm die, die zum genannten Ort passt. "
        . "Passt keine eindeutig, uebergib nur den Namen und lass den Rest leer.";

    $messages = [['role' => 'user', 'content' => $text]];

    // Hoechstens vier Runden: die Suche laeuft serverseitig, aber lange Laeufe
    // brechen mit stop_reason "pause_turn" ab und muessen erneut angestossen
    // werden.
    for ($runde = 0; $runde < 4; $runde++) {
        $payload = [
            'model' => 'claude-sonnet-5',
            'max_tokens' => 8000,
            'system' => $system,
            'tools' => [
                ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 6],
                $uebernehmen,
            ],
            'messages' => $messages,
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);

        // Sicherheitsklassifikatoren koennen ablehnen -- das kommt als HTTP 200
        // zurueck, nicht als Fehler.
        if (($data['stop_reason'] ?? '') === 'refusal') {
            return null;
        }

        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'kunde_uebernehmen') {
                return $block['input'] ?? [];
            }
        }

        // Lange Suchlaeufe pausieren -- unveraendert erneut anstossen.
        if (($data['stop_reason'] ?? '') === 'pause_turn') {
            $messages[] = ['role' => 'assistant', 'content' => $data['content'] ?? []];
            continue;
        }

        break;
    }

    return null;
}

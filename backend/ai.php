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

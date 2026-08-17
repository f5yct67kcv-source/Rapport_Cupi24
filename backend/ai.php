<?php
declare(strict_types=1);

// KI-Sprachbefehl-Pilot (ENT-015). Nimmt bereits transkribierten Text
// entgegen (Sprach-zu-Text laeuft ueber die native Tastaturdiktierfunktion
// des Geraets, nicht hier) und zerlegt ihn per Anthropic-API in
// strukturierte Mitarbeiter-Felder. Schreibt nie selbst in die Datenbank --
// nur Extraktion, das Speichern bleibt beim Admin.

function anthropic_extract_mitarbeiter(string $text): ?array {
    $apiKey = '__ANTHROPIC_API_KEY__';
    if ($apiKey === '' || str_contains($apiKey, '__ANTHROPIC_API_KEY')) {
        return null; // nicht konfiguriert
    }

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

    $payload = [
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 512,
        'tools' => [$tool],
        'tool_choice' => ['type' => 'tool', 'name' => 'extract_mitarbeiter'],
        'messages' => [
            ['role' => 'user', 'content' => $text],
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
        if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'extract_mitarbeiter') {
            return $block['input'] ?? [];
        }
    }
    return null;
}

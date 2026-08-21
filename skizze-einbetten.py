#!/usr/bin/env python3
"""Kopiert skizze.js inline nach dashboard.html.

Der Deploy-Workflow kopiert nur namentlich gelistete Dateien, und ihn zu
aendern braucht das Recht "workflow", das der GitHub-Login nicht hat. Darum
steht der Skizzenmodus inline in dashboard.html. skizze.js bleibt die Quelle,
dieses Skript haelt beide Stellen gleich.

    python3 skizze-einbetten.py
"""
from pathlib import Path
import sys

START = '<!-- skizze:start -->'
ENDE = '<!-- skizze:ende -->'

quelle = Path('skizze.js')
ziel = Path('dashboard.html')

if not quelle.exists() or not ziel.exists():
    sys.exit('skizze.js oder dashboard.html fehlt -- im Repo-Wurzelverzeichnis ausfuehren.')

code = quelle.read_text(encoding='utf-8').rstrip('\n')
s = ziel.read_text(encoding='utf-8')

block = (START + '\n'
         '<!-- Notizebene fuer Aenderungswuensche, Alt+S. Nicht von Hand aendern:\n'
         '     Quelle ist skizze.js, eingebettet mit skizze-einbetten.py. -->\n'
         '<script>\n' + code + '\n</script>\n' + ENDE + '\n')

if START in s and ENDE in s:
    a = s.index(START)
    e = s.index(ENDE) + len(ENDE) + 1
    s = s[:a] + block + s[e:]
    wie = 'ersetzt'
else:
    # Erster Lauf: alten Block ohne Marker abraeumen, sonst haengt er doppelt drin.
    alt = s.find('<!-- Skizzenmodus:')
    if alt != -1:
        s = s[:alt] + s[s.index('</body>', alt):]
    i = s.rindex('</body>')
    s = s[:i] + block + s[i:]
    wie = 'eingefuegt'

ziel.write_text(s, encoding='utf-8')
print(f'{wie}: {len(code)} Zeichen aus skizze.js in dashboard.html')

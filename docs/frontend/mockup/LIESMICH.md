# Mockup (Design-Referenz)

Exportiert aus dem claude.ai-Mockup „Mockup Feuerwehr-Geräteverwaltung“ am 27.10.2026.

Die `*.dc.html`-Dateien sind **Design-Referenzen, kein lauffähiger Code**: Sie stammen aus einem
Design-Werkzeug (Platzhalter wie `{{accent}}`, `<sc-for>`, `<sc-if>`, ein `DCLogic`-Skript) und
lassen sich im Browser nicht direkt öffnen. Maßgeblich sind Aufbau, Texte, Farben, Abstände,
Schriftgrößen und die CSS-Regeln darin. Inhalte (Namen, Zahlen, Geräte) sind Beispieldaten.

| Datei | Ansicht |
|---|---|
| `Main.dc.html` | Admin – Dashboard (PC) |
| `Geraete.dc.html` | Admin – Geräteliste (PC, Filter und Sortierung) |
| `SuperAdmin.dc.html` | Super-Admin – Dashboard und neue Stadt |
| `Einstellungen.dc.html` | Einstellungen (PC) mit Hell-/Dunkel-Farben und Kontrastprüfung der Stadtfarbe |
| `UserStart.dc.html` | User – Start (Handy) |
| `UserUmbuchen.dc.html` | User – Umbuchen (Handy) |
| `UserStandortwahl.dc.html` | User – Standortauswahl (Handy) |
| `AdminHandy.dc.html` | Admin – Geräteliste (Handy) |
| `canvas.json` | Übersicht der Ansichten mit Größen (Handy 390 × 844, PC 1440 × 900) |

Gemeinsame Gestaltung (aus dem Mockup abgeleitet, als Design-Variablen umsetzen):
- Schrift: Source Sans 3 (400, 600, 700), Ersatz `system-ui, sans-serif`.
- Hell: Hintergrund `#F3F4F6`, Fläche `#FFFFFF`, Rand `#E1E5EA`, Text `#1B2430`, gedämpft `#5B6675`.
- Dunkel: Hintergrund `#12161C`, Fläche `#1B222B`, Rand `#2C3644`, Text `#E7ECF2`, gedämpft `#A9B4C1`.
- Statusfarben: bereit `#1B6330` auf `#E3F2E7`, Wartung `#7A4A00` auf `#FFF1DB`, defekt `#9B1C1C` auf `#FCE4E2`.
- Standard-Akzentfarbe: **`#b71c1c` (Feuerwehrrot, wie `AccentColor::DEFAULT` im Backend)**, je Stadt
  einstellbar. Das Dunkelblau `#1D3557` im Mockup ist nur ein Beispiel und gilt nicht als Standard.

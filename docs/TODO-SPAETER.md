# Später umsetzen (erst, wenn die App vollständig funktioniert)

Wünsche aus Live-Tests. Nicht vorziehen; erst nach Abschluss der Hauptabschnitte
(User am Handy, Offline-Modus, Admin-Konsole, Super-Admin) gemeinsam planen.

| Nr. | Seite | Wunsch | Aufgenommen |
|---|---|---|---|
| T1 | Suchen | „Zuletzt benutzt“ nach dem Zeitpunkt der letzten Änderung des Geräts sortieren (neueste Änderung oben) statt nach der eigenen Nutzung. | 29.10.2026 |
| T2 | Umbuchen | Unter „zuletzt“ nur noch EINEN zuletzt benutzten Standort anzeigen (zusätzlich zum aktuellen), statt bisher zwei. | 29.10.2026 |
| T3 | Standort wählen | Beim Aufklappen einer Gruppe (z. B. HLF 20) so scrollen, dass die aufgeklappten Fächer sichtbar werden, wenn sie unten aus dem Bild laufen; der Kopf der Gruppe bleibt dabei oben sichtbar. | 29.10.2026 |
| T4 | Umbuchen | „Speichern“ nur antippbar, wenn sich gegenüber dem jetzigen Stand etwas geändert hat (Standort, Status oder Kommentar). | 29.10.2026 |
| T5 | Profil | Einstellung „Große Darstellung“: größere Schrift, größere Schaltflächen und Kacheln, damit ältere Kameraden die App leichter bedienen können; gespeichert je Gerät wie der Anzeigemodus. | 29.10.2026 |
| T6 | Log-Mails | ~~Link in den Log-Mails direkt auf die Geräteseite statt auf die Startseite der Stadt (AppUrls). Zusammen mit der Admin-Konsole umsetzen.~~ Erledigt am 30.10.2026 (Admin-Konsole, Schritt 5; KONZEPT.md Entscheidung 36). | 29.10.2026 |
| T7 | Scan-Code (Suchen, Scanner, Import) | GS1-Codes mit und ohne überflüssiges GS nach einem Element fester Länge (z. B. nach 01 = GTIN) gleich behandeln: Ein GS dort ändert die Bedeutung nicht, entsteht aber je nach Drucker bzw. Etiketten-Programm, bei Eingabe von Hand oder per CSV. Gespeichert wird weiter genau, was eingegeben wurde; nur der Vergleich (`scan_code_key`, `findByCode`) nutzt eine einheitliche Form ohne solche GS, und nur, wenn der Code gültig mit `01` + 14 Ziffern mit richtiger Prüfziffer beginnt (sonst genauer Vergleich wie bisher). Braucht eine Migration und gemeinsame Testfälle für PHP und Frontend (wie bei `identifierKey`). Ein fehlendes GS nach einem Element variabler Länge (z. B. Charge) lässt sich nicht ergänzen; solche Codes bleiben verschieden. Ergänzung (30.10.2026): USB-Handscanner im Tastatur-Modus übertragen das GS-Trennzeichen je nach Gerät und Browser gar nicht oder anders (z. B. lässt Firefox es beim Eintippen weg). Bei der Umsetzung berücksichtigen, damit ein am PC per Handscanner zugeordneter Code auch beim Kamera-Scan passt; ggf. Hinweis im Fenster „Code zuordnen“. | 30.10.2026 |
| T8 | Auswahllisten (WebKit, CSP) | Bei neuer Playwright-Version prüfen, ob natives `<select>` in WebKit noch eine CSP-Meldung auslöst; ggf. am Handy natives `<select>` verwenden (siehe CLAUDE.md, "Frontend ausliefern", CSP). | 30.10.2026 |
| T9 | Geräteliste (Admin) | Klick auf die ganze Zeile bzw. Karte öffnet das Gerätedetail, nicht nur die Kennung (der Name allein ist als Aktion zu unscheinbar); Hover der ganzen Zeile wie im Konzept. | 30.10.2026 |

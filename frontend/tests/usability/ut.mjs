/* eslint-disable no-console -- Kommandozeilen-Werkzeug des Benutzertests */
// Steuerung des Browsers einer Persona: node ut.mjs <port> <befehl> [argumente …]
//   look                    Seite beschreiben (Adresse, Text, nummerierte Bedienelemente im sichtbaren Bereich)
//   shot [name] [full]      Bildschirmfoto speichern (Pfad wird ausgegeben; mit dem Bildbetrachter ansehen)
//   click <nr>              Element anklicken (Nummer aus „look“)      type <nr> <text>   in ein Feld schreiben
//   tap <x> <y>             Fenster-Koordinate antippen                press <Taste>      z. B. Enter, Tab, Escape
//   scroll [down|up] [px]   scrollen                                   back               Zurück-Knopf des Browsers
//   goto <pfad>             Adresse der App öffnen (nur im Notfall, wie Eintippen)
//   offline on|off          Netz aus/an                                device <iphone|pixel|tablet|laptop|desktop>
//   task <name>             neue Aufgabe beginnen (Zähler auf 0)       result <ja|umweg|nein> <1-7> <Text>
//   note <text>             Beobachtung festhalten                     problems  Konsolenfehler der App   quit
const [port, cmd, ...rest] = process.argv.slice(2);
const res = await fetch(`http://127.0.0.1:${port}/`, {
    method: 'POST',
    body: JSON.stringify({ cmd, rest }),
});
console.log(await res.text());

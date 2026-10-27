<?php
// Vorlage für die Zugangsdaten. Kopie anlegen als:
//   config.local.php – Zugang der Anwendung (auf dem Server: Produktiv-DB)
//   config.test.php  – Zugang nur für Tests, nur lokal; Datenbankname muss auf _test enden
// Beide Kopien werden nie committet.
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'DATENBANKNAME',
        'user' => 'BENUTZER',
        'pass' => 'PASSWORT',
    ],
    // Optional, nur lokal: weitere Origins für ändernde API-Anfragen (https://www.prodad.de ist immer
    // erlaubt). Auf dem Server weglassen.
    // 'http' => [
    //     'allowed_origins' => ['http://localhost:8000'],
    // ],
    // Optional: Adresse der Anwendung für Links in Mails (ohne Pfad, ohne / am Ende).
    // Standard https://www.prodad.de; auf dem Server weglassen.
    // 'app' => [
    //     'base_url' => 'http://localhost:8000',
    // ],
    // Optional: Zeitlimits der Offline-Buchungen (je 1 bis 365 Tage, Standard je 7). max_age_days: so
    // lange darf das Handy nach der letzten Synchronisierung offline buchen; queue_max_age_days: ab
    // diesem Alter einer Buchung fragt das Handy vor dem Senden nach.
    // 'offline' => [
    //     'max_age_days'       => 7,
    //     'queue_max_age_days' => 7,
    // ],
    // Optional: Versand von Mails. Standard 'sendmail' (mail(), ohne DKIM).
    // Lokal 'file': jede Mail als .eml-Datei in file_dir (relativ zum Projektordner oder absolut,
    // Standard var/mail; steht in .gitignore).
    // 'mail' => [
    //     'transport' => 'file',
    //     'file_dir'  => 'var/mail',
    // ],
    // 'smtp': über das STRATO-Postfach (STRATO signiert mit DKIM). username muss genau
    // feuerwehr-app@prodad.de sein; username und password sind Pflicht, der Rest optional
    // (Standardwerte wie unten). encryption: 'ssl' (implizites TLS, Port 465) oder 'starttls'
    // (Port 587). Das Zertifikat des Servers wird immer geprüft. Die Gruppe smtp wird auch bei
    // einem anderen transport geprüft, aber nur bei 'smtp' verwendet.
    // 'mail' => [
    //     'transport' => 'smtp',
    //     'smtp' => [
    //         'username'   => 'feuerwehr-app@prodad.de',
    //         'password'   => 'PASSWORT-DES-POSTFACHS',
    //         'host'       => 'smtp.strato.de',
    //         'port'       => 465,
    //         'encryption' => 'ssl',
    //         'timeout'    => 10,
    //     ],
    // ],
];

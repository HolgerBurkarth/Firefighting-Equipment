<?php

declare(strict_types=1);

// Fake-SMTP-Server für Tests (gestartet von FakeSmtpServer, eigener Prozess, da Windows kein fork kennt).
// Aufruf: php fake-smtp-server.php SZENARIO.json PROTOKOLL [ZERTIFIKAT.pem]
// Lauscht auf 127.0.0.1 (freier Port, erste Zeile auf stdout: "PORT n"), nimmt genau eine
// Verbindung an und arbeitet die Schritte des Szenarios ab:
//   {"send": "220 bereit"}   Zeile(n) senden (mehrere durch \n getrennt, je mit CRLF)
//   {"read": true}           eine Zeile lesen und protokollieren
//   {"data": true}           Text bis zur Zeile "." lesen und als Ganzes protokollieren
//   {"tls": true}            TLS als Server einschalten (Zertifikat aus Argument 3)
//   {"sleep": 2.5}           warten (Zeitüberschreitung testen)
//   {"close": true}          Verbindung sofort schließen und enden
// Endet die Verbindung vorzeitig, endet der Server. Das Protokoll enthält je Eintrag eine JSON-Zeile,
// am Schluss immer END_MARKER: FakeSmtpServer beendet den Prozess dann sofort (unter Windows wartet
// PHP beim Schließen eines lauschenden Sockets sonst 500 ms).

[, $scenarioFile, $logFile] = $argv;
$certFile = $argv[3] ?? null;
$steps = json_decode((string) file_get_contents($scenarioFile), true, 512, JSON_THROW_ON_ERROR);

$log = static function (string $entry) use ($logFile): void {
    file_put_contents($logFile, json_encode($entry, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
};
register_shutdown_function(static fn () => $log('<ende>'));

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Server nicht gestartet: $errstr\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, 'PORT ' . substr($name, strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$conn = @stream_socket_accept($server, 20);
if ($conn === false) {
    exit(1);
}

foreach ($steps as $step) {
    if (isset($step['send'])) {
        foreach (explode("\n", $step['send']) as $line) {
            if (@fwrite($conn, $line . "\r\n") === false) {
                exit(0);
            }
        }
    } elseif (isset($step['read'])) {
        $line = fgets($conn);
        if ($line === false) {
            $log('<geschlossen>');
            exit(0);
        }
        $log(rtrim($line, "\r\n"));
    } elseif (isset($step['data'])) {
        $data = '';
        while (($line = fgets($conn)) !== false && $line !== ".\r\n") {
            $data .= $line;
        }
        $log($data);
    } elseif (isset($step['tls'])) {
        stream_context_set_option($conn, 'ssl', 'local_cert', $certFile);
        if (@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
            $log('<tls fehlgeschlagen>');
            exit(0);
        }
        $log('<tls>');
    } elseif (isset($step['close'])) {
        fclose($conn);
        exit(0);
    } elseif (isset($step['sleep'])) {
        usleep((int) ($step['sleep'] * 1e6));
    }
}

// Warten, bis der Client die Verbindung schließt (sonst könnte er das Ende als Fehler sehen).
stream_set_timeout($conn, 5);
while (!feof($conn) && fgets($conn) !== false) {
}
fclose($conn);

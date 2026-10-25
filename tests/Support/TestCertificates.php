<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

/**
 * Erzeugt zur Laufzeit eine Test-CA und davon signierte Serverzertifikate (EC P-256, 1 Tag gültig)
 * für TLS-Tests. Nichts davon wird committet. Bringt eine eigene minimale openssl.cnf mit, da PHP
 * unter Windows oft keine findet.
 */
final readonly class TestCertificates
{
    private const string OPENSSL_CNF = <<<'CNF'
        [req]
        distinguished_name = dn
        [dn]
        [v3_ca]
        basicConstraints = critical,CA:TRUE
        keyUsage = critical,keyCertSign,cRLSign
        [v3_server]
        basicConstraints = CA:FALSE
        keyUsage = critical,digitalSignature
        extendedKeyUsage = serverAuth
        subjectAltName = %s

        CNF;

    /** Datei mit dem Zertifikat der Test-CA (für cafile). */
    public string $caFile;

    private \OpenSSLAsymmetricKey $caKey;

    private \OpenSSLCertificate $caCert;

    public function __construct(private TempDirectory $dir, string $caName = 'FF Test CA')
    {
        $config = $this->config('DNS:unused.invalid');
        $this->caKey = self::newKey($config);
        $csr = openssl_csr_new(['commonName' => $caName], $this->caKey, $config);
        $cert = $csr instanceof \OpenSSLCertificateSigningRequest
            ? openssl_csr_sign($csr, null, $this->caKey, 1, $config + ['x509_extensions' => 'v3_ca'], random_int(1, PHP_INT_MAX))
            : false;
        if ($cert === false) {
            throw new \RuntimeException('Test-CA konnte nicht erzeugt werden: ' . self::errors());
        }
        $this->caCert = $cert;
        openssl_x509_export($cert, $pem);
        $this->caFile = $dir->file(bin2hex(random_bytes(4)) . '-ca.pem');
        file_put_contents($this->caFile, $pem);
    }

    /**
     * Serverzertifikat mit Schlüssel in einer PEM-Datei (für local_cert).
     *
     * @param string $subjectAltName z. B. "IP:127.0.0.1" oder "DNS:mail.example.org"
     */
    public function server(string $subjectAltName): string
    {
        $config = $this->config($subjectAltName);
        $key = self::newKey($config);
        $csr = openssl_csr_new(['commonName' => 'FF Test Server'], $key, $config);
        $cert = $csr instanceof \OpenSSLCertificateSigningRequest
            ? openssl_csr_sign($csr, $this->caCert, $this->caKey, 1, $config + ['x509_extensions' => 'v3_server'], random_int(1, PHP_INT_MAX))
            : false;
        if ($cert === false) {
            throw new \RuntimeException('Serverzertifikat konnte nicht erzeugt werden: ' . self::errors());
        }
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, $config);
        $file = $this->dir->file(bin2hex(random_bytes(4)) . '-server.pem');
        file_put_contents($file, $certPem . $keyPem);

        return $file;
    }

    /**
     * Optionen für openssl_* mit eigener openssl.cnf (subjectAltName steht fest in der Datei).
     *
     * @return array<string, mixed>
     */
    private function config(string $subjectAltName): array
    {
        $file = $this->dir->file(bin2hex(random_bytes(4)) . '-openssl.cnf');
        file_put_contents($file, sprintf(self::OPENSSL_CNF, $subjectAltName));

        return [
            'config' => $file,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            'digest_alg' => 'sha256',
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function newKey(array $options): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_new($options) ?: throw new \RuntimeException('Schlüssel konnte nicht erzeugt werden: ' . self::errors());
    }

    private static function errors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return implode('; ', $errors);
    }
}

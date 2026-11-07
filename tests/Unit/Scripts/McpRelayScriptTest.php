<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Scripts;

use FirefightingEquipment\Http\AllowedOrigins;
use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\OAuth\OAuthUrls;
use FirefightingEquipment\Tests\Support\Bash;
use FirefightingEquipment\Tests\Support\Perl;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vermittler scripts/cgi-bin/ff-mcp.pl (CLAUDE.md "Vermittler für Claude (cgi-bin)") in einer
 * nachgebauten CGI-Umgebung (übersprungen ohne Perl). curl ist ein Ersatz (FF_MCP_CURL_BIN), der
 * Befehlszeile, Konfiguration und Umgebung neben sich ablegt und eine vorgegebene Antwort ausgibt;
 * unter Linux zusätzlich das echte curl gegen einen lokalen PHP-Server, der Header und Body
 * zurückgibt.
 */
#[CoversNothing]
final class McpRelayScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../scripts/cgi-bin/ff-mcp.pl';
    private const string HTACCESS = __DIR__ . '/../../../scripts/cgi-bin/.htaccess';
    private const string TOKEN = 'ffa_geheim-Token_123';
    private const string HEAD = "\r\nCache-Control: no-store\r\nX-Content-Type-Options: nosniff\r\n\r\n";

    private const string FAKE_CURL = <<<'PERL'
        #!/usr/bin/perl
        use strict;
        use warnings;
        use File::Basename qw(dirname);
        my $dir = dirname($0);
        binmode STDIN;
        binmode STDOUT;
        my ($config_file) = map { $ARGV[$_ + 1] } grep { $ARGV[$_] eq '--config' } 0 .. $#ARGV - 1;
        open(my $cfg, '<', $config_file) or die;
        binmode $cfg;
        my $config = do { local $/; <$cfg> } // '';
        close $cfg;
        my $body = do { local $/; <STDIN> } // '';
        sub save { my ($name, $content) = @_; open(my $fh, '>', "$dir/$name") or die; binmode $fh; print {$fh} $content; close $fh; }
        save('curl-args', join("\n", @ARGV) . "\n");
        save('curl-config', $config);
        save('curl-body', $body);
        save('curl-env', join("\n", sort keys %ENV) . "\n");
        my $output = '';
        if (open(my $fh, '<', "$dir/curl-output")) { binmode $fh; local $/; $output = <$fh>; close $fh; }
        print $output;
        my $exit = 0;
        if (open(my $fh, '<', "$dir/curl-exit")) { $exit = <$fh>; close $fh; }
        exit($exit + 0);

        PERL;

    /** Für das echte curl: Ziel auf den lokalen Server umschreiben (http statt https). */
    private const string CURL_WRAPPER = <<<'PERL'
        #!/usr/bin/perl
        use strict;
        use warnings;
        use File::Basename qw(dirname);
        my $dir = dirname($0);
        open(my $fh, '<', "$dir/target-origin") or die;
        my $origin = <$fh>;
        close $fh;
        my @args = map { s{\Ahttps://www\.prodad\.de/}{$origin/}r =~ s/\A=https\z/=http/r } @ARGV;
        exec { 'curl' } 'curl', @args;

        PERL;

    private const string ECHO_SERVER = <<<'PHP'
        <?php
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') || $key === 'CONTENT_TYPE') {
                $headers[strtolower(str_replace('_', '-', str_starts_with($key, 'HTTP_') ? substr($key, 5) : $key))] = $value;
            }
        }
        ksort($headers);
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('WWW-Authenticate: Bearer error="invalid_token", scope="read write"');
        header('X-Intern: nicht weiter');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri' => $_SERVER['REQUEST_URI'],
            'headers' => $headers,
            'body' => base64_encode((string) file_get_contents('php://input')),
        ]);

        PHP;

    private TempDirectory $dir;

    /** @var resource|null php -S des Echo-Servers */
    private $server = null;

    protected function setUp(): void
    {
        if (Perl::path() === null) {
            self::markTestSkipped('Perl nicht gefunden.');
        }
        $this->dir = new TempDirectory();
        $this->writeScript('curl', self::FAKE_CURL);
        $this->respondWith("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{}");
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if (isset($this->dir)) {
            $this->dir->remove();
        }
    }

    /** Ohne Warnungen beim Übersetzen (sie kämen vor der Umleitung von STDERR ins Protokoll). */
    public function testSyntaxIsValidWithoutWarnings(): void
    {
        [$exitCode, , $stderr] = $this->perl(['-c', Bash::toPath(self::SCRIPT)]);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame(Bash::toPath(self::SCRIPT) . " syntax OK\n", str_replace("\r\n", "\n", $stderr));
    }

    public function testScriptAndHtaccessAreReadyForTheServer(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);
        self::assertStringStartsWith("#!/usr/bin/perl\n", $script);
        self::assertStringNotContainsString("\r", $script, 'LF wie auf dem Server (.gitattributes)');
        // Gleich mit der App: Ziel, Adresse des Vermittlers (Ressource) und Header des Tokens.
        self::assertStringContainsString("my \$TARGET = '" . AllowedOrigins::DEFAULT . AppPath::PREFIX . "/%s/mcp';", $script);
        self::assertSame('/cgi-bin/' . basename(self::SCRIPT), OAuthUrls::RELAY_PATH);
        self::assertStringContainsString("['HTTP_AUTHORIZATION', '" . OAuthUrls::RELAY_AUTHORIZATION_HEADER . "'],", $script);

        self::assertSame(['CGIPassAuth On'], $this->directives((string) file_get_contents(self::HTACCESS)));
    }

    public function testForwardsOnlyToMcpOfTheCityWithTokenOutsideTheCommandLine(): void
    {
        $this->respondWith("HTTP/1.1 401 Unauthorized\r\n"
            . "Content-Type: application/json; charset=utf-8\r\n"
            . "WWW-Authenticate: Bearer error=\"invalid_token\", resource_metadata=\"https://x/m\", scope=\"read write\"\r\n"
            . "Set-Cookie: ff_session=x\r\n"
            . "X-Api-Version: 2\r\n"
            . "\r\n"
            . '{"error":"not_authenticated"}');
        $body = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

        [$exitCode, $stdout] = $this->relay([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
            'HTTP_MCP_SESSION_ID' => 'sitzung-1',
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
            'HTTP_X_FF_AUTHORIZATION' => 'Bearer selbst-gesendet',
            'HTTP_COOKIE' => 'ff_session=abc',
            'HTTP_ORIGIN' => 'https://evil.example',
            'QUERY_STRING' => 'ziel=https://evil.example',
        ], $body);

        self::assertSame(0, $exitCode);
        self::assertSame("Status: 401 Unauthorized\r\n"
            . "Content-Type: application/json; charset=utf-8\r\n"
            . "WWW-Authenticate: Bearer error=\"invalid_token\", resource_metadata=\"https://x/m\", scope=\"read write\""
            . self::HEAD
            . '{"error":"not_authenticated"}', $stdout);
        self::assertSame([
            '--disable', '--stderr', '/dev/null', '--silent', '--include', '--http1.1',
            '--proto', '=https', '--noproxy', '*',
            '--connect-timeout', '5', '--max-time', '25', '--max-filesize', '4194304',
            '--config', '/dev/fd/3', '--data-binary', '@-',
            '--url', 'https://www.prodad.de/firefighting/test/mcp',
        ], $this->curlArgs());
        self::assertSame(
            "header = \"Content-Type: application/json\"\n"
            . "header = \"Accept: application/json, text/event-stream\"\n"
            . "header = \"Mcp-Session-Id: sitzung-1\"\n"
            . "header = \"MCP-Protocol-Version: 2025-06-18\"\n"
            . 'header = "X-FF-Authorization: Bearer ' . self::TOKEN . "\"\n"
            . "header = \"Expect:\"\n"
            . "header = \"User-Agent: ff-mcp-relay\"\n",
            $this->read('curl-config'),
        );
        self::assertSame($body, $this->read('curl-body'));
        // Cygwin (Perl von Git for Windows) ergänzt SYSTEMROOT und WINDIR selbst.
        $env = array_diff(explode("\n", rtrim($this->read('curl-env'))), ['SYSTEMROOT', 'WINDIR']);
        self::assertSame(['PATH'], array_values($env), 'curl erbt weder Token noch Anfrage');
    }

    public function testBodyIsPassedUnchanged(): void
    {
        $body = "@/etc/passwd \"a\\b\"\tx\ny\rz\x0B\0ü€\x01";

        $this->relay([], $body);

        self::assertSame($body, $this->read('curl-body'));
    }

    public function testHeaderValuesAreEscapedForTheCurlConfiguration(): void
    {
        $this->relay(['CONTENT_TYPE' => 'application/json; x="a\\b"'], '{}');

        self::assertStringStartsWith("header = \"Content-Type: application/json; x=\\\"a\\\\b\\\"\"\n", $this->read('curl-config'));
    }

    public function testWithoutHeadersCurlAddsNoOwnContentTypeOrAccept(): void
    {
        $this->relay(['HTTP_AUTHORIZATION' => "Bearer x\ny", 'HTTP_ACCEPT' => str_repeat('a', 4097)], '');

        self::assertSame(
            "header = \"Content-Type:\"\nheader = \"Accept:\"\nheader = \"Expect:\"\n"
            . "header = \"User-Agent: ff-mcp-relay\"\n",
            $this->read('curl-config'),
        );
        self::assertSame('', $this->read('curl-body'));
    }

    public function testGetAndDeleteAreForwarded(): void
    {
        $this->relay(['REQUEST_METHOD' => 'GET'], '');
        self::assertSame(['--config', '/dev/fd/3', '--url'], array_slice($this->curlArgs(), -4, 3));

        $this->relay(['REQUEST_METHOD' => 'DELETE'], '');
        self::assertSame(['/dev/fd/3', '--request', 'DELETE', '--url'], array_slice($this->curlArgs(), -5, 4));

        $this->relay(['REQUEST_METHOD' => 'DELETE'], '{}');
        self::assertSame(['--data-binary', '@-', '--request', 'DELETE', '--url'], array_slice($this->curlArgs(), -6, 5));
        self::assertSame('{}', $this->read('curl-body'));
    }

    public function testSkipsInformationalResponsesAndKeepsTheBody(): void
    {
        $this->respondWith("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 202 Accepted\r\nAllow: POST\r\nX-Other: 1\r\n\r\nA\r\n\r\nB");

        [, $stdout] = $this->relay([], '{}');

        self::assertSame("Status: 202 Accepted\r\nAllow: POST" . self::HEAD . "A\r\n\r\nB", $stdout);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'ohne' => [null];
        yield 'leer' => [''];
        yield 'nur Schrägstrich' => ['/'];
        yield 'Großbuchstaben' => ['/Test'];
        yield 'Schrägstrich am Ende' => ['/test/'];
        yield 'zweites Segment' => ['/test/mcp'];
        yield 'anderes Ziel' => ['/../../evil.example'];
        yield 'Bindestrich am Anfang' => ['/-test'];
        yield 'doppelter Bindestrich' => ['/te--st'];
        yield 'Leerzeichen' => ['/te st'];
        yield 'Punkt' => ['/www.evil.example'];
        yield 'reserviert' => ['/service'];
        yield 'zu lang' => ['/' . str_repeat('a', 51)];
    }

    #[DataProvider('invalidPaths')]
    public function testOtherPathsAre404WithoutCallingCurl(?string $path): void
    {
        [$exitCode, $stdout] = $this->relay(['PATH_INFO' => $path], '{}');

        self::assertSame(0, $exitCode);
        self::assertSame("Status: 404 Not Found\r\nContent-Type: application/json; charset=utf-8" . self::HEAD
            . '{"error":"not_found","message":"Die Adresse wurde nicht gefunden."}', $stdout);
        self::assertFileDoesNotExist($this->dir->file('curl-args'));
    }

    public function testLongestSlugIsForwarded(): void
    {
        $slug = str_repeat('a', 50);

        $this->relay(['PATH_INFO' => '/' . $slug], '{}');

        self::assertSame('https://www.prodad.de/firefighting/' . $slug . '/mcp', array_slice($this->curlArgs(), -1)[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherMethods(): iterable
    {
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
        yield 'klein' => ['post'];
    }

    #[DataProvider('otherMethods')]
    public function testOtherMethodsAre405(string $method): void
    {
        [, $stdout] = $this->relay(['REQUEST_METHOD' => $method], '{}');

        self::assertStringStartsWith("Status: 405 Method Not Allowed\r\nContent-Type: application/json; charset=utf-8\r\nAllow: POST\r\n", $stdout);
        self::assertStringContainsString('"error":"method_not_allowed"', $stdout);
        self::assertFileDoesNotExist($this->dir->file('curl-args'));
    }

    public function testBodyUpToOneMebibyte(): void
    {
        $body = str_repeat('x', 1_048_576);

        $this->relay([], $body);

        self::assertTrue($this->read('curl-body') === $body, 'ganzer Body');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'zu groß' => ['1048577', '', 'Die Anfrage ist zu groß (höchstens 1 MiB).'];
        yield 'Länge keine Zahl' => ['12a', '', 'Die Länge der Anfrage ist ungültig.'];
        yield 'unvollständig' => ['10', '{}', 'Die Anfrage ist unvollständig.'];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAre400(string $length, string $body, string $message): void
    {
        [, $stdout] = $this->relay(['CONTENT_LENGTH' => $length], $body);

        self::assertStringStartsWith("Status: 400 Bad Request\r\n", $stdout);
        self::assertStringEndsWith('{"error":"invalid_request","message":"' . $message . '"}', $stdout);
        self::assertFileDoesNotExist($this->dir->file('curl-args'));
    }

    /**
     * @return iterable<string, array{int, string, int, string}>
     */
    public static function curlFailures(): iterable
    {
        yield 'Zeitgrenze' => [28, '', 504, 'gateway_timeout'];
        yield 'keine Verbindung' => [7, '', 502, 'bad_gateway'];
        yield 'Antwort zu groß' => [63, '', 502, 'bad_gateway'];
        yield 'ohne Header' => [0, '{}', 502, 'bad_gateway'];
        yield 'keine Statuszeile' => [0, "Hallo\r\n\r\n{}", 502, 'bad_gateway'];
        yield 'nur 100' => [0, "HTTP/1.1 100 Continue\r\n\r\n", 502, 'bad_gateway'];
    }

    #[DataProvider('curlFailures')]
    public function testFailuresOfCurlAreGatewayErrors(int $exit, string $output, int $status, string $error): void
    {
        $this->respondWith($output, $exit);

        [, $stdout] = $this->relay([], '{}');

        self::assertStringStartsWith("Status: $status ", $stdout);
        self::assertStringContainsString('"error":"' . $error . '"', $stdout);
    }

    public function testMissingCurlIsBadGateway(): void
    {
        [, $stdout] = $this->relay(['FF_MCP_CURL_BIN' => Bash::toPath($this->dir->file('fehlt'))], '{}');

        self::assertStringStartsWith("Status: 502 Bad Gateway\r\n", $stdout);
    }

    /** Echtes curl liest die Konfiguration: Header und Body kommen genau so an. */
    public function testRealCurlDeliversHeadersAndBodyExactly(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !is_executable('/usr/bin/curl')) {
            self::markTestSkipped('Nur unter Linux mit /usr/bin/curl (unter Windows: WSL).');
        }
        $origin = $this->startEchoServer();
        $this->writeScript('curl', self::CURL_WRAPPER);
        $this->dir->write('target-origin', $origin);
        // Höchstgröße mit allen Sonderfällen (curl liest Zeilen einer Konfiguration nur bis rund
        // 100 KiB, daher kommt der Body über die Standardeingabe).
        $body = substr(str_repeat("@/etc/passwd {\"a\":\"x\\\"y\\\\z\",\"t\":\"\t\"}\n\r\x0B\0äöü€\x01", 30_000), 0, 1_048_576);

        [$exitCode, $stdout] = $this->relay([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25',
            'HTTP_COOKIE' => 'ff_session=abc',
        ], $body);

        self::assertSame(0, $exitCode);
        [$head, $content] = explode("\r\n\r\n", $stdout, 2);
        self::assertSame("Status: 401 Unauthorized\r\n"
            . "Content-Type: application/json; charset=utf-8\r\n"
            . "WWW-Authenticate: Bearer error=\"invalid_token\", scope=\"read write\"\r\n"
            . "Cache-Control: no-store\r\nX-Content-Type-Options: nosniff", $head);
        $echo = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($echo);
        self::assertSame('POST', $echo['method']);
        self::assertSame('/firefighting/test/mcp', $echo['uri']);
        self::assertSame($body, base64_decode((string) $echo['body'], true));
        $headers = $echo['headers'];
        self::assertIsArray($headers);
        unset($headers['host'], $headers['content-length']);
        self::assertSame([
            'content-type' => 'application/json',
            'mcp-protocol-version' => '2025-11-25',
            'user-agent' => 'ff-mcp-relay',
            'x-ff-authorization' => 'Bearer ' . self::TOKEN,
        ], $headers);
    }

    /**
     * @param array<string, string|null> $env CGI-Umgebung (null = Variable weglassen)
     *
     * @return array{int, string, string}
     */
    private function relay(array $env, string $body): array
    {
        foreach (['curl-args', 'curl-config', 'curl-body', 'curl-env'] as $file) {
            if (is_file($this->dir->file($file))) {
                unlink($this->dir->file($file));
            }
        }
        $env += [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REQUEST_METHOD' => 'POST',
            'PATH_INFO' => '/test',
            'CONTENT_LENGTH' => (string) strlen($body),
            'FF_MCP_CURL_BIN' => Bash::toPath($this->dir->file('curl')),
        ];

        return Perl::run(self::SCRIPT, [], array_filter($env, static fn (?string $value): bool => $value !== null), $body);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function perl(array $args): array
    {
        $process = proc_open([(string) Perl::path(), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function read(string $name): string
    {
        $content = file_get_contents($this->dir->file($name));
        self::assertIsString($content);

        return $content;
    }

    /** @return list<string> */
    private function curlArgs(): array
    {
        return explode("\n", rtrim($this->read('curl-args'), "\n"));
    }

    private function respondWith(string $output, int $exit = 0): void
    {
        $this->dir->write('curl-output', $output);
        $this->dir->write('curl-exit', (string) $exit);
    }

    private function writeScript(string $name, string $content): void
    {
        $this->dir->write($name, str_replace("\r\n", "\n", $content));
        chmod($this->dir->file($name), 0700);
    }

    /** @return list<string> */
    private function directives(string $content): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", str_replace("\r\n", "\n", $content))),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
    }

    /** Startet php -S mit dem Echo-Server auf einem freien Port; Rückgabe http://127.0.0.1:<port>. */
    private function startEchoServer(): string
    {
        $this->dir->write('echo.php', self::ECHO_SERVER);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $process = proc_open([PHP_BINARY, '-q', '-S', '127.0.0.1:' . $port, $this->dir->file('echo.php')], [
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        $this->server = $process;
        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return 'http://127.0.0.1:' . $port;
            }
            usleep(50_000);
        }
        self::fail('Echo-Server startet nicht.');
    }
}

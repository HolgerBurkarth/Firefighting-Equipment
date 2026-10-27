<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Scripts;

use FirefightingEquipment\Tests\Support\Bash;
use FirefightingEquipment\Tests\Support\Git;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Startet scripts/deploy.sh in einer nachgebauten Server-Umgebung (übersprungen ohne bash):
 * Remote mit den Branches main und deploy (erzeugt mit scripts/publish-deploy-branch.sh), ein
 * Klon als Projektordner unter apps/, apps/_backup mit .htaccess. php85 (Composer, Migrationen)
 * und curl (Health-Check, App-Seite) sind Platzhalter in einem eigenen bin-Ordner vorn im PATH.
 */
#[CoversNothing]
final class DeployScriptTest extends TestCase
{
    private const string SCRIPTS = __DIR__ . '/../../../scripts';

    private const string FAKE_PHP = <<<'BASH'
        #!/usr/bin/env bash
        echo "php85 $*" >> "$FAKE_LOG"
        if [ "$1" = "bin/migrate.php" ] && [ "$2" = "check" ]; then
            exit "${FAKE_MIGRATE_CHECK:-0}"
        fi
        exit 0

        BASH;

    private const string FAKE_CURL = <<<'BASH'
        #!/usr/bin/env bash
        url="${!#}"
        echo "curl $url" >> "$FAKE_LOG"
        case "$url" in
            */firefighting/service/api/v1/health)
                printf '%s' "${FAKE_HEALTH_STATUS:-200}"
                ;;
            */firefighting/service/)
                printf '%s' "${FAKE_APP_BODY-<head><base href=\"/firefighting/service/\" /></head>}"
                printf '\n%s' "${FAKE_APP_STATUS:-200 text/html; charset=utf-8}"
                ;;
            *)
                exit 7
                ;;
        esac

        BASH;

    /** Setzt den PATH erst in bash (Git Bash stellt sonst /mingw64/bin mit dem echten curl davor). */
    private const string WRAPPER = <<<'BASH'
        #!/usr/bin/env bash
        PATH="$(cd "$FAKE_BIN" && pwd):$PATH"
        exec bash "$@"

        BASH;

    private TempDirectory $dir;
    private Git $git;
    private string $work;
    private string $remote;
    private string $server;
    private string $dist;

    protected function setUp(): void
    {
        if (Bash::path() === null) {
            self::markTestSkipped('bash nicht gefunden.');
        }
        $this->dir = new TempDirectory();
        $this->git = new Git($this->dir->file('gitconfig'));
        $this->work = $this->dir->file('work');
        $this->remote = $this->dir->file('remote.git');
        $this->dist = $this->dir->file('dist');
        $apps = $this->dir->file('apps');
        $this->server = $apps . DIRECTORY_SEPARATOR . 'firefighting-equipment';

        mkdir($this->remote);
        $this->git->run($this->remote, 'init', '--quiet', '--bare');
        mkdir($this->work . '/scripts', 0700, true);
        $this->git->run($this->work, 'init', '--quiet', '--initial-branch=main');
        $this->git->run($this->work, 'remote', 'add', 'origin', Bash::toPath($this->remote));
        foreach (['deploy.sh', 'prune-deploy-backups.sh', 'publish-deploy-branch.sh'] as $script) {
            copy(self::SCRIPTS . '/' . $script, $this->work . '/scripts/' . $script);
        }
        file_put_contents($this->work . '/README.md', "Projekt\n");
        $this->git->run($this->work, 'add', '.');
        $this->git->run($this->work, 'commit', '--quiet', '-m', 'Erster Stand');
        $this->git->run($this->work, 'push', '--quiet', 'origin', 'main');
        $this->publish();

        mkdir($apps . '/_backup', 0700, true);
        file_put_contents($apps . '/_backup/.htaccess', "Require all denied\n");
        $this->git->run($apps, 'clone', '--quiet', '--branch', 'deploy', Bash::toPath($this->remote), 'firefighting-equipment');

        mkdir($this->dir->file('bin'));
        file_put_contents($this->dir->file('bin') . '/php85', str_replace("\r\n", "\n", self::FAKE_PHP));
        file_put_contents($this->dir->file('bin') . '/curl', str_replace("\r\n", "\n", self::FAKE_CURL));
        chmod($this->dir->file('bin') . '/php85', 0700);
        chmod($this->dir->file('bin') . '/curl', 0700);
        $this->dir->write('composer', '');
        $this->dir->write('wrapper.sh', str_replace("
", "
", self::WRAPPER));
        $this->dir->write('fake.log', '');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->dir->remove();
        }
    }

    public function testDeploysNewStateFromDeployBranch(): void
    {
        $old = $this->serverHead();
        $new = $this->newVersion();

        [$exitCode, $stdout, $stderr] = $this->deploy();

        self::assertSame(0, $exitCode, $stdout . $stderr);
        self::assertSame($new, $this->serverHead());
        self::assertFileExists($this->server . '/frontend/dist/index.html');
        self::assertStringContainsString("Alter Commit: $old", $stdout);
        self::assertStringContainsString("Neuer Commit: $new", $stdout);
        self::assertStringContainsString('Health-Check OK (HTTP 200).', $stdout);
        self::assertStringContainsString('App-Seite:       https://example.test/firefighting/service/', $stdout);
        self::assertStringContainsString('App-Seite OK (HTTP 200, Basispfad gesetzt).', $stdout);
        self::assertStringContainsString('Deployment erfolgreich.', $stdout);
        self::assertCount(1, glob($this->dir->file('apps') . '/_backup/ff-deploy-*.tar.gz') ?: []);
        $log = $this->log();
        self::assertStringContainsString(' install --no-dev --optimize-autoloader', $log);
        self::assertStringContainsString('php85 bin/migrate.php check', $log);
    }

    public function testWithoutNewCommitsStillChecksEverything(): void
    {
        [$exitCode, $stdout, $stderr] = $this->deploy();

        self::assertSame(0, $exitCode, $stdout . $stderr);
        self::assertStringContainsString('(keine neuen Commits)', $stdout);
        self::assertStringContainsString('App-Seite OK', $stdout);
    }

    public function testRefusesMainBranchWithHintToSwitch(): void
    {
        $this->git->run($this->server, 'checkout', '--quiet', '-b', 'main', 'origin/main');

        [$exitCode, , $stderr] = $this->deploy();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString("Aktueller Branch ist 'main', erwartet 'deploy'.", $stderr);
        self::assertStringContainsString('Umstieg des Servers auf den Branch deploy', $stderr);
        self::assertSame('', $this->log());
    }

    public function testMissingFrontendBuildStopsBeforeComposer(): void
    {
        $old = $this->serverHead();
        // Kaputter Stand in deploy (dürfte nur durch einen Fehler außerhalb des Workflows entstehen).
        $this->git->run($this->work, 'fetch', '--quiet', 'origin');
        $this->git->run($this->work, 'checkout', '--quiet', '-b', 'deploy', 'origin/deploy');
        $this->git->run($this->work, 'rm', '--quiet', 'frontend/dist/index.html');
        $this->git->run($this->work, 'commit', '--quiet', '-m', 'Build kaputt');
        $this->git->run($this->work, 'push', '--quiet', 'origin', 'deploy');

        [$exitCode, , $stderr] = $this->deploy();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('frontend/dist/index.html fehlt im neuen Stand', $stderr);
        self::assertStringContainsString('DEPLOYMENT FEHLGESCHLAGEN', $stderr);
        self::assertStringContainsString("git reset --hard $old", $stderr);
        self::assertStringNotContainsString('composer', $this->log());
    }

    public function testFailingAppPageStops(): void
    {
        $this->newVersion();

        [$exitCode, $stdout, $stderr] = $this->deploy(['FAKE_APP_STATUS' => '503 application/json; charset=utf-8']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString("App-Seite lieferte '503 application/json; charset=utf-8'", $stderr);
        self::assertStringContainsString('DEPLOYMENT FEHLGESCHLAGEN', $stderr);
        self::assertStringNotContainsString('Deployment erfolgreich.', $stdout);
    }

    public function testAppPageWithoutBasePathStops(): void
    {
        [$exitCode, , $stderr] = $this->deploy(['FAKE_APP_BODY' => '<html><base href="/__FF_BASE__/" /></html>']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('<base href="/firefighting/service/" />', $stderr);
    }

    public function testFailingHealthCheckStopsBeforeAppPage(): void
    {
        [$exitCode, , $stderr] = $this->deploy(['FAKE_HEALTH_STATUS' => '503']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Health-Check lieferte HTTP 503 statt 200.', $stderr);
        self::assertStringNotContainsString("curl https://example.test/firefighting/service/\n", $this->log());
    }

    private function newVersion(): string
    {
        file_put_contents($this->work . '/src.php', "<?php\n");
        $this->git->run($this->work, 'add', 'src.php');
        $this->git->run($this->work, 'commit', '--quiet', '-m', 'Neue Funktion');
        $this->git->run($this->work, 'push', '--quiet', 'origin', 'main');
        file_put_contents($this->dist . '/assets/index-neu.js', "console.log(2);\n");
        $this->publish();

        return $this->git->run($this->remote, 'rev-parse', 'refs/heads/deploy');
    }

    private function publish(): void
    {
        if (!is_dir($this->dist . '/assets')) {
            mkdir($this->dist . '/assets', 0700, true);
            file_put_contents($this->dist . '/index.html', '<base href="/__FF_BASE__/" />');
            file_put_contents($this->dist . '/sw.js', "self.addEventListener('fetch', () => {});\n");
        }
        [$exitCode, , $stderr] = Bash::run(
            self::SCRIPTS . '/publish-deploy-branch.sh',
            [$this->git->run($this->work, 'rev-parse', 'main'), Bash::toPath($this->dist)],
            $this->work,
            $this->git->env,
        );
        self::assertSame(0, $exitCode, $stderr);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function deploy(array $env = []): array
    {
        return Bash::run($this->dir->file('wrapper.sh'), [Bash::toPath($this->server . '/scripts/deploy.sh')], $this->server, [
            ...$this->git->env,
            'FAKE_BIN' => Bash::toPath($this->dir->file('bin')),
            'FF_DEPLOY_COMPOSER_BIN' => Bash::toPath($this->dir->file('composer')),
            'FF_DEPLOY_BASE_URL' => 'https://example.test',
            'FAKE_LOG' => Bash::toPath($this->dir->file('fake.log')),
            ...$env,
        ]);
    }

    private function serverHead(): string
    {
        return $this->git->run($this->server, 'rev-parse', 'HEAD');
    }

    private function log(): string
    {
        return (string) file_get_contents($this->dir->file('fake.log'));
    }
}

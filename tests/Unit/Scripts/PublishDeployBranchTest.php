<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Scripts;

use FirefightingEquipment\Tests\Support\Bash;
use FirefightingEquipment\Tests\Support\Git;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Startet scripts/publish-deploy-branch.sh mit bash gegen ein temporäres Repository und ein
 * leeres Remote (übersprungen, wenn bash oder git fehlen).
 */
#[CoversNothing]
final class PublishDeployBranchTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../scripts/publish-deploy-branch.sh';

    private TempDirectory $dir;
    private string $repo;
    private string $remote;
    private string $dist;
    private Git $gitRunner;

    protected function setUp(): void
    {
        if (Bash::path() === null) {
            self::markTestSkipped('bash nicht gefunden.');
        }
        $this->dir = new TempDirectory();
        $this->repo = $this->dir->file('repo');
        $this->remote = $this->dir->file('remote.git');
        $this->dist = $this->dir->file('dist');
        $this->gitRunner = new Git($this->dir->file('gitconfig'));

        mkdir($this->repo);
        mkdir($this->remote);
        $this->git($this->remote, 'init', '--quiet', '--bare');
        $this->git($this->repo, 'init', '--quiet', '--initial-branch=main');
        $this->git($this->repo, 'remote', 'add', 'origin', Bash::toPath($this->remote));
        $this->commit('README.md', "Projekt\n", 'Erster Stand');
        $this->build('<base href="/__FF_BASE__/" /> v1');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->dir->remove();
        }
    }

    public function testFirstPublishCreatesDeployWithCodeAndBuild(): void
    {
        $main = $this->head();

        [$exitCode, $stdout, $stderr] = $this->publish($main);

        self::assertSame(0, $exitCode, $stderr);
        $deploy = $this->remoteDeploy();
        self::assertSame("Branch deploy: $deploy\n", $stdout);
        self::assertSame([$main], $this->parents($deploy));
        self::assertSame(
            ['README.md', 'frontend/dist/assets/index-abc.js', 'frontend/dist/index.html', 'frontend/dist/sw.js'],
            $this->files($deploy),
        );
        self::assertSame('<base href="/__FF_BASE__/" /> v1', $this->git($this->repo, 'show', "$deploy:frontend/dist/index.html"));
        self::assertStringStartsWith('Deploy ' . substr($main, 0, 7) . ': Erster Stand', $this->git($this->repo, 'log', '-1', '--format=%B', $deploy));
        // Arbeitsordner und Index bleiben unverändert.
        self::assertSame('', $this->git($this->repo, 'status', '--porcelain'));
    }

    public function testNextPublishDescendsFromPreviousDeployAndMain(): void
    {
        $first = $this->head();
        $this->publish($first);
        $previous = $this->remoteDeploy();
        $second = $this->commit('src.php', "<?php\n", 'Neue Funktion');
        $this->build('<base href="/__FF_BASE__/" /> v2');

        [$exitCode, , $stderr] = $this->publish($second);

        self::assertSame(0, $exitCode, $stderr);
        $deploy = $this->remoteDeploy();
        self::assertSame([$previous, $second], $this->parents($deploy));
        self::assertContains('src.php', $this->files($deploy));
        self::assertSame('<base href="/__FF_BASE__/" /> v2', $this->git($this->repo, 'show', "$deploy:frontend/dist/index.html"));
        // Server auf main (Umstieg) oder auf dem vorigen deploy-Stand: Fast-Forward möglich.
        $this->assertAncestor($first, $deploy);
        $this->assertAncestor($previous, $deploy);
    }

    public function testRepeatedPublishWithSameStateCreatesNoCommit(): void
    {
        $this->publish($this->head());
        $deploy = $this->remoteDeploy();

        [$exitCode, $stdout, $stderr] = $this->publish($this->head());

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame("Branch deploy ist bereits auf diesem Stand ($deploy).\n", $stdout);
        self::assertSame($deploy, $this->remoteDeploy());
    }

    public function testChangedBuildAloneCreatesCommit(): void
    {
        $main = $this->head();
        $this->publish($main);
        $previous = $this->remoteDeploy();
        $this->build('<base href="/__FF_BASE__/" /> neu gebaut');

        [$exitCode] = $this->publish($main);

        self::assertSame(0, $exitCode);
        self::assertSame([$previous, $main], $this->parents($this->remoteDeploy()));
    }

    public function testMissingBuildIsRejected(): void
    {
        unlink($this->dist . '/index.html');

        [$exitCode, , $stderr] = $this->publish($this->head());

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('index.html fehlt', $stderr);
        self::assertSame('', $this->git($this->remote, 'branch', '--list', 'deploy'));
    }

    public function testBuildWithoutPlaceholderIsRejected(): void
    {
        $this->build('<base href="/firefighting/test/" />');

        [$exitCode, , $stderr] = $this->publish($this->head());

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Platzhalter', $stderr);
    }

    public function testVersionedBuildOnMainIsRejected(): void
    {
        $main = $this->commit('frontend/dist/index.html', 'versehentlich', 'Build eingecheckt');

        [$exitCode, , $stderr] = $this->publish($main);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('ist in', $stderr);
    }

    public function testWrongUsageIsExitCode2(): void
    {
        [$exitCode] = Bash::run(self::SCRIPT, [$this->head()], $this->repo, $this->gitRunner->env);

        self::assertSame(2, $exitCode);
    }

    /**
     * @return array{int, string, string}
     */
    private function publish(string $commit): array
    {
        return Bash::run(self::SCRIPT, [$commit, Bash::toPath($this->dist)], $this->repo, $this->gitRunner->env);
    }

    private function build(string $indexHtml): void
    {
        if (!is_dir($this->dist . '/assets')) {
            mkdir($this->dist . '/assets', 0700, true);
        }
        file_put_contents($this->dist . '/index.html', $indexHtml);
        file_put_contents($this->dist . '/sw.js', "self.addEventListener('fetch', () => {});\n");
        file_put_contents($this->dist . '/assets/index-abc.js', "console.log(1);\n");
    }

    private function commit(string $file, string $content, string $message): string
    {
        $path = $this->repo . '/' . $file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $content);
        $this->git($this->repo, 'add', '--force', $file);
        $this->git($this->repo, 'commit', '--quiet', '-m', $message);

        return $this->head();
    }

    private function head(): string
    {
        return $this->git($this->repo, 'rev-parse', 'HEAD');
    }

    private function remoteDeploy(): string
    {
        return $this->git($this->remote, 'rev-parse', 'refs/heads/deploy');
    }

    /**
     * @return list<string>
     */
    private function parents(string $commit): array
    {
        return array_slice(explode(' ', $this->git($this->remote, 'rev-list', '--parents', '-n', '1', $commit)), 1);
    }

    /**
     * @return list<string>
     */
    private function files(string $commit): array
    {
        return explode("\n", $this->git($this->remote, 'ls-tree', '-r', '--name-only', $commit));
    }

    private function assertAncestor(string $ancestor, string $commit): void
    {
        $this->git($this->remote, 'merge-base', '--is-ancestor', $ancestor, $commit);
        $this->addToAssertionCount(1);
    }

    private function git(string $cwd, string ...$args): string
    {
        return $this->gitRunner->run($cwd, ...$args);
    }
}

import { spawn, type ChildProcess } from 'node:child_process';
import { connect } from 'node:net';
import { fileURLToPath } from 'node:url';

/** Projektordner (php -S läuft dort wie auf dem Server mit public/index.php). */
const ROOT = fileURLToPath(new URL('../../../../', import.meta.url));

/** Erster Port; je Playwright-Worker zwei (App-Server, Update-Testserver). */
export const FIRST_PORT = 4173;

const START_TIMEOUT_MS = 60_000;
const STARTED_LINE = /Development Server \(\S+\) started$/;
const CONNECT_TIMEOUT_MS = 2000;
const STOP_TIMEOUT_MS = 10_000;

/**
 * `php -S` beantwortet Anfragen nacheinander in einem Prozess. Unter Linux (CI) laufen deshalb
 * mehrere PHP-Prozesse je Server (PHP_CLI_SERVER_WORKERS); unter Windows gibt es das nicht.
 */
const PHP_SERVER_WORKERS = 4;

/** Ports des Workers mit dem Index `parallelIndex` (0 … Anzahl Worker − 1). */
export function workerPorts(parallelIndex: number): { app: number; update: number } {
    const app = FIRST_PORT + 2 * parallelIndex;
    return { app, update: app + 1 };
}

/** true, wenn auf dem Port schon ein Server Verbindungen annimmt (IPv4 oder IPv6). */
async function isPortUsed(port: number): Promise<boolean> {
    const accepts = (host: string): Promise<boolean> =>
        new Promise((resolve) => {
            const socket = connect({ host, port });
            socket.once('connect', () => {
                socket.destroy();
                resolve(true);
            });
            socket.once('error', () => {
                resolve(false);
            });
            // Unter hoher Last nie hängen bleiben: keine Antwort zählt als „nicht belegt“.
            socket.setTimeout(CONNECT_TIMEOUT_MS, () => {
                socket.destroy();
                resolve(false);
            });
        });
    const results = await Promise.all([accepts('127.0.0.1'), accepts('::1')]);
    return results.includes(true);
}

function delay(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Laufender `php -S`; `stop()` beendet ihn. */
export interface PhpServer {
    readonly origin: string;
    stop(): Promise<void>;
}

/**
 * Startet `php -q -S localhost:<port> <script>` im Projektordner und wartet, bis er Verbindungen
 * annimmt. Ist der Port schon belegt (z. B. ein hängender Server eines abgebrochenen Laufs), bricht
 * der Start ab: Fremde Server werden nie mitbenutzt.
 */
export async function startPhpServer(port: number, script: string): Promise<PhpServer> {
    const origin = `http://localhost:${String(port)}`;
    if (await isPortUsed(port)) {
        throw new Error(`${origin} is already used (Server eines anderen Laufs?)`);
    }
    const child: ChildProcess = spawn('php', ['-q', '-S', `localhost:${String(port)}`, script], {
        cwd: ROOT,
        env:
            process.platform === 'win32'
                ? process.env
                : { ...process.env, PHP_CLI_SERVER_WORKERS: String(PHP_SERVER_WORKERS) },
        stdio: ['ignore', 'ignore', 'pipe'],
    });
    const exit = new Promise<void>((resolve) => {
        child.once('exit', () => {
            resolve();
        });
    });
    const exited = (): boolean => child.exitCode !== null || child.signalCode !== null;
    // Nur Meldungen (z. B. PHP-Fehler); die Startzeile käme je Worker und Neustart.
    child.stderr?.on('data', (chunk: Buffer) => {
        for (const line of chunk.toString().split(/\r?\n/)) {
            if (line !== '' && !STARTED_LINE.test(line)) {
                process.stderr.write(`[php ${String(port)}] ${line}\n`);
            }
        }
    });
    const killOnExit = (): void => {
        child.kill();
    };
    process.once('exit', killOnExit);

    const deadline = Date.now() + START_TIMEOUT_MS;
    while (!(await isPortUsed(port))) {
        if (exited() || Date.now() > deadline) {
            child.kill();
            throw new Error(`php -S auf ${origin} ist nicht gestartet.`);
        }
        await delay(50);
    }
    return {
        origin,
        stop: async () => {
            process.off('exit', killOnExit);
            if (!exited()) {
                child.kill();
                // Endet der Prozess nicht, scheitert das Fixture; der nächste Start meldet den
                // belegten Port.
                await Promise.race([
                    exit,
                    new Promise<never>((_, reject) => {
                        setTimeout(() => {
                            reject(new Error(`php -S auf ${origin} lässt sich nicht beenden.`));
                        }, STOP_TIMEOUT_MS).unref();
                    }),
                ]);
            }
        },
    };
}

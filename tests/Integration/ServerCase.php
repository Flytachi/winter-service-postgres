<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Postgres\Tests\Integration;

use Flytachi\Winter\Service\Postgres\Postgres;
use PHPUnit\Framework\TestCase;

/**
 * Runs the start script the way the application's supervisor does — the component's
 * command, in a process of its own — against a data directory of the test's own, and
 * stops it as the supervisor does, with the component's stop signal.
 *
 * Needs the PostgreSQL servers installed: run through docker/test.sh.
 */
abstract class ServerCase extends TestCase
{
    protected string $dir = '';
    /** @var resource|null */
    private mixed $process = null;
    private string $output = '';

    protected function setUp(): void
    {
        if (!is_executable('/usr/libexec/postgresql18/postgres')) {
            self::markTestSkipped('PostgreSQL is not installed here: run sh docker/test.sh.');
        }
        $this->dir = sys_get_temp_dir() . '/wsp-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/sock', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->stop();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    protected function postgres(mixed ...$parameters): Postgres
    {
        return new Postgres(...[
            'data' => $this->dir . '/data',
            'socket' => $this->dir . '/sock',
            ...$parameters,
        ]);
    }

    /** Starts it and waits until it accepts connections, or until it has exited. */
    protected function start(Postgres $postgres): bool
    {
        $this->output = '';
        $this->process = proc_open($postgres->component()->command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ([1, 2] as $fd) {
            stream_set_blocking($pipes[$fd], false);
        }
        $this->pipes = $pipes;
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            $this->drain();
            if (!proc_get_status($this->process)['running']) {
                return false;
            }
            exec(implode(' ', array_map('escapeshellarg', $postgres->isReady())), $_, $code);
            if ($code === 0) {
                return true;
            }
            usleep(200_000);
        }
        return false;
    }

    /** @var array<int, resource> */
    private array $pipes = [];

    /** Stops it with the component's signal — a fast shutdown by default — and waits. */
    protected function stop(int $signal = SIGINT): ?int
    {
        if ($this->process === null) {
            return null;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            posix_kill($status['pid'], $signal);
        }
        $deadline = microtime(true) + 30;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            $this->drain();
            usleep(100_000);
        }
        $this->drain();
        $code = proc_get_status($this->process)['exitcode'];
        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($this->process);
        $this->process = null;
        return $code;
    }

    /** What the script and the server wrote. */
    protected function serverOutput(): string
    {
        $this->drain();
        return $this->output;
    }

    /** Waits for a start that fails, and returns its exit code. */
    protected function failedStart(Postgres $postgres): int
    {
        self::assertFalse($this->start($postgres), 'it was not supposed to start');
        return (int) $this->stop();
    }

    protected function socket(Postgres $postgres, ?string $password = null): \PDO
    {
        return new \PDO($postgres->dsn(), $postgres->user(), $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    protected function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        return $port;
    }

    private function drain(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                $this->output .= (string) stream_get_contents($pipe);
            }
        }
    }
}

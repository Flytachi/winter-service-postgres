<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Postgres\Tests\Unit;

use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Service\Postgres\Postgres;
use Flytachi\Winter\Service\Postgres\PostgresOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PostgreSQL's lines and the start script's, as the application's log reads them. */
final class PostgresOutputTest extends TestCase
{
    private function read(string $line): OutputLine
    {
        return (new PostgresOutput())->line($line);
    }

    /** @return iterable<string, array{string, string}> */
    public static function severities(): iterable
    {
        yield 'LOG' => ['LOG', 'info'];
        yield 'INFO' => ['INFO', 'info'];
        yield 'DEBUG1' => ['DEBUG1', 'debug'];
        yield 'NOTICE' => ['NOTICE', 'notice'];
        yield 'WARNING' => ['WARNING', 'warning'];
        yield 'ERROR' => ['ERROR', 'error'];
        yield 'FATAL' => ['FATAL', 'critical'];
        yield 'PANIC' => ['PANIC', 'emergency'];
    }

    #[DataProvider('severities')]
    public function test_a_severity_is_a_level(string $severity, string $level): void
    {
        $line = $this->read("2026-11-15 02:10:00.412 UTC [57] {$severity}:  something happened");

        self::assertSame([$level, 'something happened', 57], [$line->level, $line->message, $line->pid]);
    }

    public function test_the_details_of_an_entry_go_under_their_keys(): void
    {
        foreach (['DETAIL' => 'detail', 'HINT' => 'hint', 'STATEMENT' => 'statement', 'CONTEXT' => 'context'] as $tag => $key) {
            $line = $this->read("2026-11-15 02:11:23.004 UTC [88] {$tag}:  SELECT * FROM usres");
            self::assertNull($line->level, $tag);
            self::assertSame([$key, 'SELECT * FROM usres'], [$line->key, $line->message]);
        }
    }

    public function test_a_prefix_without_the_time_is_read_too(): void
    {
        $line = $this->read('[57] LOG:  database system is ready to accept connections');

        self::assertSame(['info', 'database system is ready to accept connections', 57], [$line->level, $line->message, $line->pid]);
    }

    public function test_the_next_line_of_a_statement_continues_it(): void
    {
        $line = $this->read("\t  FROM usres");

        self::assertNull($line->level);
        self::assertNull($line->key, 'it goes where the line before went');
        self::assertSame('  FROM usres', $line->message, 'one tab off, the indentation kept');
    }

    public function test_the_start_scripts_lines(): void
    {
        $info = $this->read('winter-postgres: created role winter.');
        $warning = $this->read('winter-postgres: WARNING: winter is a superuser: …');
        $error = $this->read('winter-postgres: ERROR: initdb failed for /var/lib/postgresql/data.');

        self::assertSame(['info', 'created role winter.'], [$info->level, $info->message]);
        self::assertSame(['warning', 'winter is a superuser: …'], [$warning->level, $warning->message]);
        self::assertSame(['error', 'initdb failed for /var/lib/postgresql/data.'], [$error->level, $error->message]);
        self::assertNull($info->pid, 'the program\'s own');
    }

    public function test_an_empty_line_is_skipped_and_an_unknown_one_is_an_entry_as_it_is(): void
    {
        self::assertTrue($this->read('   ')->skip);
        $unknown = $this->read('02:10:00 pid=57 LOG: a prefix of your own');
        self::assertSame(['info', '02:10:00 pid=57 LOG: a prefix of your own'], [$unknown->level, $unknown->message]);
    }

    public function test_the_service_parses_its_log_unless_told_not_to(): void
    {
        self::assertInstanceOf(PostgresOutput::class, (new Postgres())->component()->output);
        self::assertNull((new Postgres(logs: false))->component()->output);
    }
}

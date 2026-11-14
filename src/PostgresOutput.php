<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Postgres;

use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Kernel\App\ProgramOutput;

/**
 * PostgreSQL's log, read into the application's: each entry at its level, with the PID
 * of the backend that wrote it, and its DETAIL, HINT, STATEMENT… lines in its context.
 *
 * Reads the default `log_line_prefix` (`%m [%p] `), and the start script's own lines
 * (`winter-postgres: …`). A tab-led line continues the entry before — PostgreSQL starts the
 * next line of a long message or statement so; any other line it does not know — a
 * `log_line_prefix` of your own — is an entry of its own, at info, as it is.
 *
 * | PostgreSQL | the log |
 * | --- | --- |
 * | DEBUG1–5 | debug |
 * | LOG, INFO | info |
 * | NOTICE | notice |
 * | WARNING | warning |
 * | ERROR | error |
 * | FATAL | critical |
 * | PANIC | emergency |
 */
final readonly class PostgresOutput implements ProgramOutput
{
    /** `2026-11-15 02:10:00.412 UTC [57] LOG:  message` — the time is the log's own. */
    private const string LINE = '/^(?:\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d+)? \S+ )?'
        . '\[(\d+)\] ([A-Z][A-Z0-9]*):  ?(.*)$/s';

    private const string SCRIPT = 'winter-postgres: ';

    private const array LEVELS = [
        'DEBUG1' => 'debug', 'DEBUG2' => 'debug', 'DEBUG3' => 'debug', 'DEBUG4' => 'debug', 'DEBUG5' => 'debug',
        'DEBUG' => 'debug',
        'LOG' => 'info',
        'INFO' => 'info',
        'NOTICE' => 'notice',
        'WARNING' => 'warning',
        'ERROR' => 'error',
        'FATAL' => 'critical',
        'PANIC' => 'emergency',
    ];

    /** The lines of an entry that say more about it: the key they go under. */
    private const array DETAILS = [
        'DETAIL' => 'detail',
        'HINT' => 'hint',
        'QUERY' => 'query',
        'CONTEXT' => 'context',
        'STATEMENT' => 'statement',
        'LOCATION' => 'location',
    ];

    public function line(string $line): OutputLine
    {
        if (str_starts_with($line, self::SCRIPT)) {
            $said = substr($line, strlen(self::SCRIPT));
            foreach (['WARNING' => 'warning', 'ERROR' => 'error'] as $tag => $level) {
                if (str_starts_with($said, "{$tag}: ")) {
                    return OutputLine::entry($level, substr($said, strlen($tag) + 2));
                }
            }
            return OutputLine::entry('info', $said);
        }
        if (preg_match(self::LINE, $line, $m) === 1) {
            [, $pid, $severity, $text] = $m;
            if (isset(self::LEVELS[$severity])) {
                return OutputLine::entry(self::LEVELS[$severity], $text, pid: (int) $pid);
            }
            if (isset(self::DETAILS[$severity])) {
                return OutputLine::more($text, self::DETAILS[$severity]);
            }
        }
        if (trim($line) === '') {
            return OutputLine::skip();
        }
        // The next line of a long message or statement: PostgreSQL starts it with a tab.
        if (str_starts_with($line, "\t")) {
            return OutputLine::more(substr($line, 1));
        }
        return OutputLine::entry('info', $line);
    }
}

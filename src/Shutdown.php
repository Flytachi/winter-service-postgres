<?php

declare(strict_types=1);

namespace Flytachi\Winter\Service\Postgres;

/**
 * How PostgreSQL is asked to stop — its three shutdown modes, each a signal to the
 * postmaster.
 *
 * @link https://www.postgresql.org/docs/current/server-shutdown.html Shutting down the server
 */
enum Shutdown
{
    /**
     * Waits for every session to end on its own, then stops. A client that stays
     * connected — an administrator's psql — holds the stop until the application's grace
     * runs out and SIGKILL follows.
     */
    case Smart;

    /**
     * Ends the sessions, rolls their transactions back, writes a checkpoint and stops:
     * clean, and quick. The default.
     */
    case Fast;

    /**
     * Stops at once, without a checkpoint: the next start recovers as after a crash.
     * For when even Fast takes too long.
     */
    case Immediate;

    /** The signal that asks for this mode. */
    public function signal(): int
    {
        return match ($this) {
            self::Smart => SIGTERM,
            self::Fast => SIGINT,
            self::Immediate => SIGQUIT,
        };
    }
}

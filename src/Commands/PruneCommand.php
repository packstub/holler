<?php

namespace Packstub\Calls\Commands;

use Illuminate\Console\Command;
use Packstub\Calls\Calls;
use Packstub\Calls\Models\Call;
use Packstub\Calls\Models\CallParticipant;
use Packstub\Calls\Models\CallSignal;

/**
 * Housekeeping for a schedule: ends the calls everybody walked away from (nobody
 * polls them any more, so nothing else would) and deletes ended calls older than
 * `prune_after_days`, their participants and signals with them.
 */
class PruneCommand extends Command
{
    protected $signature = 'calls:prune {--days= : Delete ended calls older than this many days (default: the prune_after_days config)}';

    protected $description = 'End abandoned calls and delete old ended ones';

    public function handle(Calls $calls): int
    {
        $stale = 0;

        Call::query()->with('participants')->where('status', '!=', Call::ENDED)
            ->where('updated_at', '<', now()->subSeconds((int) config('packstub-calls.reconnect_timeout', 15)))
            ->chunkById(200, function ($open) use ($calls, &$stale): void {
                $calls->expireCalls($open);
                $stale += $open->filter(fn (Call $call) => $call->isEnded())->count();
            });

        $days = $this->option('days') ?? config('packstub-calls.prune_after_days');
        $deleted = 0;

        if ($days !== null && (int) $days > 0) {
            // Participants and signals by hand too: SQLite cascades only with foreign keys switched on.
            do {
                $ids = Call::query()->where('status', Call::ENDED)->where('ended_at', '<', now()->subDays((int) $days))->limit(500)->pluck('id');

                if ($ids->isNotEmpty()) {
                    CallSignal::query()->whereIn('call_id', $ids)->delete();
                    CallParticipant::query()->whereIn('call_id', $ids)->delete();
                    $deleted += Call::query()->whereKey($ids)->delete();
                }
            } while ($ids->isNotEmpty());
        }

        $this->components->info("Ended {$stale} abandoned call(s), deleted {$deleted} old one(s).");

        return self::SUCCESS;
    }
}

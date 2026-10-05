<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EffectivenessSnapshotCycle;
use App\Services\EffectivenessService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SnapshotEffectiveness extends Command
{
    protected $signature = 'effectiveness:snapshot
        {--period= : Calendar month to snapshot (YYYY-MM; defaults to the previous month)}';

    protected $description = 'Generate auditable effectiveness snapshots for the active tenant';

    public function handle(EffectivenessService $effectiveness): int
    {
        $period = $this->option('period');
        if ($period && ! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error('The --period option must use YYYY-MM.');
            return self::FAILURE;
        }

        $start = $period ? Carbon::createFromFormat('Y-m', $period)->startOfMonth() : now()->subMonthNoOverflow()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $cycle = EffectivenessSnapshotCycle::firstOrCreate(
            ['period_start' => $start->toDateString(), 'period_end' => $end->toDateString()],
            ['status' => 'draft', 'policy_version' => EffectivenessService::POLICY_VERSION],
        );
        if ($cycle->status !== 'draft') {
            $this->error('Only a draft period may be regenerated. Reviewed and finalized periods are protected.');
            return self::FAILURE;
        }
        $count = 0;

        Employee::query()->with('user:id,role')->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('role', '!=', 'super_admin'))
            ->orderBy('id')->each(function (Employee $employee) use ($effectiveness, $start, $end, &$count): void {
                $effectiveness->snapshot($employee, $start, $end);
                $count++;
            });

        $this->info("Generated {$count} draft effectiveness snapshot(s) for {$start->format('F Y')}." );
        return self::SUCCESS;
    }
}

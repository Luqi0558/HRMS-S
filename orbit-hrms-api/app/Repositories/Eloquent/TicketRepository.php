<?php

namespace App\Repositories\Eloquent;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketTimeLog;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TicketRepository implements TicketRepositoryInterface
{
    public function __construct(private readonly Ticket $model)
    {
    }

    public function paginateScoped(
        ?array $visibleProjectIds,
        array $filters = [],
        int $perPage = 50
    ): LengthAwarePaginator {
        $query = $this->model->newQuery()
            ->with(['assignee:id,name', 'module:id,name,slug', 'project:id,name,slug']);

        if ($visibleProjectIds !== null) {
            $query->whereIn('project_id', $visibleProjectIds);
        }

        foreach (['project_id', 'project_module_id', 'status', 'priority', 'assignee_id'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        // Overdue is a computed state, not a column: open work whose deadline
        // has passed. A delivered ticket is never late.
        if (($filters['overdue'] ?? false)) {
            $query->where('status', '!=', 'done')
                ->whereNotNull('deadline')
                ->whereDate('deadline', '<', now());
        }

        if (filled($filters['search'] ?? null)) {
            $query->where('title', 'like', '%'.$filters['search'].'%');
        }

        // Deadlines first so the board surfaces what is due; nulls last, since
        // an undated ticket is not more urgent than a dated one.
        return $query
            ->orderByRaw('deadline IS NULL, deadline ASC')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findById(int $id): ?Ticket
    {
        return $this->model->newQuery()->find($id);
    }

    public function create(array $attributes): Ticket
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function update(Ticket $ticket, array $attributes): Ticket
    {
        // completed_at is derived from status rather than sent by the client,
        // so the two can never disagree.
        if (array_key_exists('status', $attributes)) {
            $attributes['completed_at'] = $attributes['status'] === 'done'
                ? ($ticket->completed_at ?? now())
                : null;
        }

        $ticket->fill($attributes)->save();

        return $ticket->refresh();
    }

    public function addComment(Ticket $ticket, int $authorId, string $body): void
    {
        TicketComment::create([
            'ticket_id' => $ticket->id,
            'author_id' => $authorId,
            'body' => $body,
        ]);
    }

    public function runningTimerFor(int $employeeId): ?object
    {
        return DB::table('ticket_time_logs')
            ->where('employee_id', $employeeId)
            ->whereNull('ended_at')
            ->first();
    }

    public function startTimer(Ticket $ticket, int $employeeId): void
    {
        TicketTimeLog::create([
            'ticket_id' => $ticket->id,
            'employee_id' => $employeeId,
            'started_at' => now(),
        ]);
    }

    public function stopTimer(int $employeeId): void
    {
        $running = TicketTimeLog::where('employee_id', $employeeId)
            ->whereNull('ended_at')
            ->get();

        foreach ($running as $log) {
            $endedAt = now();
            $log->update([
                'ended_at' => $endedAt,
                // Stored rather than computed on read, so timesheet reports can
                // SUM() over a date range instead of differencing every row.
                'duration_seconds' => $endedAt->diffInSeconds($log->started_at),
            ]);
        }
    }

    public function loadDetail(Ticket $ticket): Ticket
    {
        return $ticket->load([
            'assignee:id,name,email',
            'module:id,name,slug,project_id',
            'project:id,name,slug',
            'comments.author:id,name',
            'timeLogs' => fn ($q) => $q->latest('started_at')->limit(50),
        ]);
    }

    public function statsByModule(array $moduleIds): Collection
    {
        if ($moduleIds === []) {
            return collect();
        }

        return DB::table('tickets')
            ->selectRaw('project_module_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status != 'done') as open")
            ->selectRaw("SUM(status = 'done') as done")
            ->selectRaw("SUM(status != 'done' AND deadline IS NOT NULL AND deadline < CURDATE()) as overdue")
            ->whereIn('project_module_id', $moduleIds)
            ->whereNull('deleted_at')
            ->groupBy('project_module_id')
            ->get()
            ->keyBy('project_module_id');
    }
}

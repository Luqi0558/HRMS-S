<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The audit trail.
 *
 * Write-often, read-rarely: every module records decisions here, and only an
 * administrator reads them back. That shape is why the table is indexed for
 * newest-first reads filtered by module or actor, and why nothing in this
 * service updates or deletes an entry — an audit trail that can be edited is
 * not an audit trail.
 *
 * No permission check on record(): the caller has already done the thing being
 * logged. Refusing to write the log because of a permission would lose the
 * record of an action that happened anyway.
 */
class ActivityLogService
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * Records one action.
     *
     * The actor's name and role are copied in, not just referenced: an entry
     * has to stay readable after the employee record is deleted, which is
     * precisely when an audit trail matters most.
     */
    public function record(?User $user, array $attributes): ActivityLog
    {
        $employee = $user ? Employee::where('user_id', $user->id)->first() : null;

        return ActivityLog::create([
            'actor_id' => $employee?->id,
            'actor_name' => $employee?->name ?? $user?->name ?? 'System',
            'actor_role' => $user?->role ?? 'system',
            'module' => $attributes['module'],
            'action' => $attributes['action'],
            'details' => $attributes['details'] ?? null,
            'target_id' => $attributes['targetId'] ?? null,
            'target_type' => $attributes['targetType'] ?? null,
        ]);
    }

    /**
     * The trail, newest first.
     *
     * Paginated rather than returned whole: this table grows with every action
     * anyone takes, so it is the one collection guaranteed to outgrow a single
     * response.
     */
    public function list(User $user, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $this->assertMayRead($user);

        return ActivityLog::query()
            ->with('actor:id,name')
            ->when(filled($filters['module'] ?? null), fn ($q) => $q->where('module', $filters['module']))
            ->when(filled($filters['actor_id'] ?? null), fn ($q) => $q->where('actor_id', $filters['actor_id']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** The distinct modules present, so the filter offers only real options. */
    public function modules(User $user): array
    {
        $this->assertMayRead($user);

        return ActivityLog::query()
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->all();
    }

    /**
     * Reading the trail is administrative. No grid row names 'Activity Log',
     * so only super admin reaches it through the blanket grant — which is the
     * right default: it records what everyone in the company has done.
     */
    private function assertMayRead(User $user): void
    {
        if (! $this->permissions->can($user, 'Activity Log', 'view')) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }
    }
}

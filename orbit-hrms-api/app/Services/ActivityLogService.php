<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ActivityLogService
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

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

    private function assertMayRead(User $user): void
    {
        if (! $this->permissions->can($user, 'Activity Log', 'view')) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }
    }
}
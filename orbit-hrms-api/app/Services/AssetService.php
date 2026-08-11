<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The asset register.
 *
 * The rule that shapes this service: an asset is held by at most one person at
 * a time, and every handover leaves a record. Assigning something already out
 * is refused rather than silently reassigned — the previous holder would
 * otherwise disappear from the register while still physically having it.
 *
 * `status` and `assigned_to` on the asset are denormalised from the open
 * assignment. They are only ever written inside the same transaction that
 * opens or closes one, so they cannot drift.
 */
class AssetService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly ActivityLogService $activity,
    ) {
    }

    public function list(User $user, array $filters = []): array
    {
        $row = $this->assetRow($user);

        $query = Asset::with(['holder:id,name', 'openAssignment']);

        // An employee sees what they hold, not the whole register.
        $visibleIds = $this->scopes->visibleEmployeeIds($user, $row['scope']);
        if ($visibleIds !== null) {
            $query->whereIn('assigned_to', $visibleIds);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q
                ->where('name', 'like', $term)
                ->orWhere('serial_number', 'like', $term));
        }

        return $query->orderBy('name')->get()->all();
    }

    public function create(User $user, array $attributes): Asset
    {
        $this->assertMayManage($user);

        $asset = Asset::create([
            'name' => $attributes['name'],
            'category' => $attributes['category'],
            'serial_number' => $attributes['serialNumber'] ?? null,
            'condition' => $attributes['condition'] ?? 'Good',
            'notes' => $attributes['notes'] ?? null,
            'status' => 'available',
        ]);

        $this->activity->record($user, [
            'module' => 'assets',
            'action' => 'asset added',
            'details' => sprintf('%s (%s) was added to the register.', $asset->name, $asset->category),
            'targetId' => $asset->id,
            'targetType' => 'asset',
        ]);

        return $asset;
    }

    public function update(User $user, int $assetId, array $attributes): Asset
    {
        $this->assertMayManage($user);

        $asset = $this->find($assetId);

        $asset->update(array_filter([
            'name' => $attributes['name'] ?? null,
            'category' => $attributes['category'] ?? null,
            'serial_number' => $attributes['serialNumber'] ?? null,
            'condition' => $attributes['condition'] ?? null,
            'notes' => $attributes['notes'] ?? null,
        ], fn ($value) => $value !== null));

        return $asset->refresh()->load('holder:id,name');
    }

    /**
     * Issues an asset to someone.
     *
     * Refused if it is already out: reassigning silently would drop the
     * current holder from the register while they still have the item.
     */
    public function assign(User $user, int $assetId, array $attributes): Asset
    {
        $this->assertMayManage($user);

        $asset = $this->find($assetId);

        if ($asset->status === 'retired') {
            throw new ConflictHttpException('A retired asset cannot be issued.');
        }

        if ($asset->status === 'assigned') {
            throw new ConflictHttpException(sprintf(
                'That asset is already issued to %s. Record its return first.',
                $asset->holder?->name ?? 'someone',
            ));
        }

        $employee = Employee::find($attributes['employeeId']);

        if (! $employee) {
            throw new NotFoundHttpException('Employee not found.');
        }

        $issuer = $this->employeeFor($user);

        return DB::transaction(function () use ($asset, $employee, $issuer, $attributes) {
            AssetAssignment::create([
                'asset_id' => $asset->id,
                'employee_id' => $employee->id,
                // Copied so a returned asset still records who held it after
                // that employee is offboarded.
                'employee_name' => $employee->name,
                'issued_on' => $attributes['issuedOn'] ?? now()->toDateString(),
                'return_due' => $attributes['returnDue'] ?? null,
                'condition_out' => $attributes['condition'] ?? $asset->condition,
                'notes' => $attributes['notes'] ?? null,
                'issued_by' => $issuer?->id,
            ]);

            // Written in the same transaction as the assignment, so the
            // denormalised columns cannot drift from the history.
            $asset->update([
                'status' => 'assigned',
                'assigned_to' => $employee->id,
            ]);

            return $asset->refresh()->load(['holder:id,name', 'openAssignment']);
        });
    }

    /** Records an asset coming back, closing its open assignment. */
    public function returnAsset(User $user, int $assetId, array $attributes = []): Asset
    {
        $this->assertMayManage($user);

        $asset = $this->find($assetId);
        $open = $asset->openAssignment;

        if (! $open) {
            throw new ConflictHttpException('That asset is not currently issued.');
        }

        return DB::transaction(function () use ($asset, $open, $attributes, $user) {
            $condition = $attributes['condition'] ?? $asset->condition;

            $open->update([
                'returned_on' => $attributes['returnedOn'] ?? now()->toDateString(),
                'condition_in' => $condition,
                'notes' => $attributes['notes'] ?? $open->notes,
            ]);

            $asset->update([
                'status' => 'available',
                'assigned_to' => null,
                // The condition it came back in becomes the asset's condition:
                // the next person is issued what actually exists.
                'condition' => $condition,
            ]);

            $this->activity->record($user, [
                'module' => 'assets',
                'action' => 'asset returned',
                'details' => sprintf('%s was returned by %s in %s condition.',
                    $asset->name, $open->employee_name ?? 'someone', strtolower($condition)),
                'targetId' => $asset->id,
                'targetType' => 'asset',
            ]);

            return $asset->refresh()->load('holder:id,name');
        });
    }

    /**
     * Retires an asset.
     *
     * Kept rather than deleted: the assignment history is the point, and it
     * would be meaningless without the asset it refers to.
     */
    public function retire(User $user, int $assetId): Asset
    {
        $this->assertMayManage($user);

        $asset = $this->find($assetId);

        if ($asset->status === 'assigned') {
            throw new ConflictHttpException('Record the asset\'s return before retiring it.');
        }

        $asset->update(['status' => 'retired', 'assigned_to' => null]);

        $this->activity->record($user, [
            'module' => 'assets',
            'action' => 'asset retired',
            'details' => sprintf('%s was retired.', $asset->name),
            'targetId' => $asset->id,
            'targetType' => 'asset',
        ]);

        return $asset->refresh();
    }

    /** Everyone who has held this asset, newest first. */
    public function history(User $user, int $assetId): array
    {
        $this->assetRow($user);

        return AssetAssignment::with(['employee:id,name', 'issuer:id,name'])
            ->where('asset_id', $assetId)
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    private function find(int $assetId): Asset
    {
        $asset = Asset::with(['holder:id,name', 'openAssignment'])->find($assetId);

        if (! $asset) {
            throw new NotFoundHttpException('Asset not found.');
        }

        return $asset;
    }

    private function assetRow(User $user): array
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Assets');

        if (! $row || ! ($row['view'] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        return $row;
    }

    private function assertMayManage(User $user): void
    {
        if (! $this->permissions->can($user, 'Assets', 'create')) {
            throw new AccessDeniedHttpException('You may not manage assets.');
        }
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}

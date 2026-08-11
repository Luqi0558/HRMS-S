<?php

namespace App\Services;

use App\Models\Designation;
use App\Models\Employee;
use App\Models\Stack;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The job titles and technical stacks the dropdowns offer.
 *
 * These tables have existed and been seeded since onboarding was built, but
 * nothing served them — the SPA carried its own hardcoded copies, so adding a
 * designation in Settings appeared to work and vanished on refresh, while
 * onboarding went on offering the original list.
 *
 * Removal deactivates rather than deletes. A designation is recorded on every
 * employee who holds it; deleting the row would either orphan those records or
 * force a rename nobody asked for. Deactivating takes it out of the dropdowns
 * and leaves history alone.
 */
class SettingsListService
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * Both lists.
     *
     * Readable by anyone signed in: onboarding and the invite form need them,
     * and they are not sensitive — they are the options on a dropdown.
     *
     * @return array{designations: array<int, string>, stacks: array<int, string>}
     */
    public function all(): array
    {
        return [
            'designations' => Designation::where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->pluck('name')->all(),
            'stacks' => Stack::where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->pluck('name')->all(),
        ];
    }

    public function add(User $user, string $type, string $name): array
    {
        $this->assertMayManage($user);

        $model = $this->modelFor($type);
        $name = trim($name);

        $existing = $model::where('name', $name)->first();

        if ($existing) {
            // Re-adding something previously removed reactivates it rather
            // than failing on the unique index — which is what someone
            // retyping a name they removed by mistake actually wants.
            if ($existing->is_active) {
                throw new ConflictHttpException('That option already exists.');
            }

            $existing->update(['is_active' => true]);

            return $this->all();
        }

        $model::create([
            'name' => $name,
            'is_active' => true,
            'sort_order' => (int) $model::max('sort_order') + 1,
        ]);

        return $this->all();
    }

    /**
     * Takes an option out of the dropdowns.
     *
     * Returns how many employees still hold it, so the UI can say so rather
     * than implying nothing was affected.
     */
    public function remove(User $user, string $type, string $name): array
    {
        $this->assertMayManage($user);

        $model = $this->modelFor($type);
        $record = $model::where('name', $name)->first();

        if (! $record) {
            throw new NotFoundHttpException('That option does not exist.');
        }

        $record->update(['is_active' => false]);

        return [
            'lists' => $this->all(),
            // Only meaningful for designations: an employee's department is
            // the designation they hold.
            'stillAssigned' => $type === 'designations'
                ? Employee::where('department', $name)->count()
                : 0,
        ];
    }

    private function modelFor(string $type): string
    {
        return match ($type) {
            'designations' => Designation::class,
            'stacks' => Stack::class,
            default => throw new NotFoundHttpException('Unknown list.'),
        };
    }

    private function assertMayManage(User $user): void
    {
        if (! $this->permissions->can($user, 'Settings', 'create')) {
            throw new AccessDeniedHttpException('You may not change company settings.');
        }
    }
}

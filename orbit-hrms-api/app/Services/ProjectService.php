<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Employee;
use App\Models\User;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProjectService
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly SettingsService $settings,
        private readonly MessageService $messages,
        private readonly ActivityLogService $activity,
    ) {
    }

    public function list(User $user, array $filters = []): Collection
    {
        $scope = $this->requireScope($user, 'view');

        return $this->projects->listScoped(
            $this->scopes->visibleProjectIds($user, $scope),
            $filters,
        );
    }

    public function findBySlug(User $user, string $slug): Project
    {
        $project = $this->projects->findBySlug($slug);

        if (! $project || ! $this->canSee($user, $project)) {
            // 404 for both cases: distinguishing them would confirm a project
            // exists to someone with no access to it.
            throw new NotFoundHttpException('Project not found.');
        }

        return $this->projects->loadDetail($project);
    }

    public function create(User $user, array $attributes): Project
    {
        $this->requireScope($user, 'create');

        $employee = $this->employeeFor($user);

        return $this->projects->create([
            'name' => $attributes['name'],
            'slug' => $this->uniqueSlug($attributes['name']),
            'description' => $attributes['description'] ?? null,
            // Defaults to the creator when unspecified: a project with no
            // manager is invisible to every 'owned' scope, including theirs.
            'manager_id' => $attributes['managerId'] ?? $employee?->id,
            'status' => 'active',
        ]);
    }

    public function update(User $user, string $slug, array $attributes): Project
    {
        $project = $this->projects->findBySlug($slug);

        if (! $project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $scope = $this->requireScope($user, 'create');
        $this->assertWithinScope($user, $project, $scope);

        $payload = array_filter([
            'name' => $attributes['name'] ?? null,
            'description' => $attributes['description'] ?? null,
            'manager_id' => $attributes['managerId'] ?? null,
            'status' => $attributes['status'] ?? null,
        ], fn ($value) => $value !== null);

        return $this->projects->update($project, $payload);
    }

    /**
     * Replaces the member list. Used by the "Add employee to project" modal,
     * which sends the complete set rather than a delta.
     */
    public function syncMembers(User $user, string $slug, array $employeeIds): Project
    {
        $project = $this->projects->findBySlug($slug);

        if (! $project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $scope = $this->requireScope($user, 'create');
        $this->assertWithinScope($user, $project, $scope);

        // A project is the parent staffing record. A contributor who is on a
        // module must never disappear from its parent project when a caller
        // syncs the project roster.
        $moduleMemberIds = $project->modules()
            ->with('members:id')
            ->get()
            ->flatMap(fn (ProjectModule $module) => $module->members->pluck('id'));

        $before = $project->members()->pluck('employees.id')->map(fn ($id) => (int) $id)->all();

        $this->projects->syncMembers(
            $project,
            collect($employeeIds)->merge($moduleMemberIds)->map(fn ($id) => (int) $id)->unique()->values()->all(),
        );

        // Only the people who are new. The client sends the whole roster, so
        // without the diff every save would re-tell everyone already on it.
        $this->messages->notifyOthers(
            $user,
            array_diff(array_map('intval', $employeeIds), $before),
            [
                'title' => 'Added to project',
                'body' => sprintf('You were added to %s.', $project->name),
                'type' => 'project',
                'routeName' => 'project-detail',
                'routeParams' => ['projectSlug' => $project->slug],
            ],
        );

        return $this->projects->loadDetail($project->refresh());
    }

    /**
     * Marks a module delivered, or reopens it.
     *
     * Gated on `approve` rather than `create`: signing off a deliverable is a
     * sign-off, and it is what closes the module to new tickets. Mirrors
     * can('Projects', 'approve', project) in the frontend.
     */
    /**
     * Creates a module inside a project.
     *
     * There was no endpoint for this at all: the client built a module with
     * `id: Date.now()`, pushed it into local state and logged an activity, so
     * every module anyone "created" disappeared on refresh — for super admins
     * as much as for anybody else.
     */
    public function createModule(User $user, string $projectSlug, array $attributes): ProjectModule
    {
        $project = $this->projects->findBySlug($projectSlug);

        if (! $project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $scope = $this->requireScope($user, 'create');
        $this->assertWithinScope($user, $project, $scope);

        $commission = $this->commissionPolicy();

        // Only the Finance right sets money. Anyone else creating a module gets
        // the company defaults, whatever they send.
        if (! $this->permissions->can($user, 'Finance', 'finance')) {
            unset($attributes['budget'], $attributes['commissionType'], $attributes['commissionValue']);
        }

        return $this->projects->createModule([
            'project_id' => $project->id,
            'name' => $attributes['name'],
            'slug' => $this->uniqueModuleSlug($project, $attributes['name']),
            'description' => $attributes['description'] ?? null,
            // Defaults to the creator, so a lead setting up training work is
            // the lead of it and their squad resolves without a second step.
            'lead_id' => $attributes['leadId'] ?? $this->employeeFor($user)?->id,
            'status' => 'active',
            'budget' => $attributes['budget'] ?? 0,
            'commission_type' => $attributes['commissionType'] ?? $commission['type'],
            'commission_value' => $attributes['commissionValue'] ?? $commission['value'],
            'commission_status' => $commission['requiresApproval'] ? 'pending' : 'approved',
        ]);
    }

    public function updateModule(User $user, int $moduleId, array $attributes): ProjectModule
    {
        $module = $this->projects->findModule($moduleId);

        if (! $module) {
            throw new NotFoundHttpException('Module not found.');
        }

        $scope = $this->requireScope($user, 'create');
        $this->assertWithinScope($user, $module->project, $scope);

        // A lead may edit a module's name and scope but not its money: they
        // no longer receive it, and must not be able to change it blind.
        if (! $this->permissions->can($user, 'Finance', 'finance')) {
            unset($attributes['budget'], $attributes['commissionType'], $attributes['commissionValue']);
        }

        $commissionTermsChanged = (
            array_key_exists('commissionType', $attributes)
            && $attributes['commissionType'] !== $module->commission_type
        ) || (
            array_key_exists('commissionValue', $attributes)
            && (float) $attributes['commissionValue'] !== (float) $module->commission_value
        );

        $payload = array_filter([
            'name' => $attributes['name'] ?? null,
            'description' => $attributes['description'] ?? null,
            'lead_id' => $attributes['leadId'] ?? null,
            'budget' => $attributes['budget'] ?? null,
            'commission_type' => $attributes['commissionType'] ?? null,
            'commission_value' => $attributes['commissionValue'] ?? null,
        ], fn ($value) => $value !== null);

        // A changed commission is a new financial agreement. It re-enters the
        // configured approval flow instead of retaining an approval/payout for
        // terms that no longer exist.
        if ($commissionTermsChanged) {
            $payload['commission_status'] = $this->commissionPolicy()['requiresApproval']
                ? 'pending'
                : 'approved';
        }

        // The slug follows a rename, since it is what the board's URL uses.
        if (filled($attributes['name'] ?? null) && $attributes['name'] !== $module->name) {
            $payload['slug'] = $this->uniqueModuleSlug($module->project, $attributes['name'], $module->id);
        }

        return $this->projects->updateModule($module, $payload);
    }

    /** @return array{type: string, value: float, requiresApproval: bool} */
    private function commissionPolicy(): array
    {
        $finance = $this->settings->group('finance');
        $type = (string) ($finance['defaultCommissionType'] ?? 'percent');
        $approval = filter_var(
            $finance['superAdminApprovalRequired'] ?? true,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );

        return [
            'type' => in_array($type, ['percent', 'fixed'], true) ? $type : 'percent',
            'value' => max(0, (float) ($finance['defaultCommissionValue'] ?? 0)),
            'requiresApproval' => $approval ?? true,
        ];
    }

    /**
     * Replaces a module's member list.
     */
    public function syncModuleMembers(User $user, int $moduleId, array $employeeIds, ?int $leadId = null): ProjectModule
    {
        $module = $this->projects->findModule($moduleId);

        if (! $module) {
            throw new NotFoundHttpException('Module not found.');
        }

        $scope = $this->requireScope($user, 'create');
        $this->assertWithinScope($user, $module->project, $scope);

        $before = $module->members()->pluck('employees.id')->map(fn ($id) => (int) $id)->all();

        $updated = DB::transaction(function () use ($module, $employeeIds, $leadId) {
            $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

            if ($leadId !== null) {
                if (! in_array($leadId, $ids, true)) {
                    throw ValidationException::withMessages([
                        'leadId' => 'The module lead must also be assigned to the module.',
                    ]);
                }

                $lead = Employee::query()->with('user:id,role')->find($leadId);
                if (! $lead || $lead->user?->role !== 'team_lead') {
                    throw ValidationException::withMessages([
                        'leadId' => 'Select an active Team Lead as the module lead.',
                    ]);
                }

                $module->update(['lead_id' => $leadId]);
            }

            $updated = $this->projects->syncModuleMembers($module, $ids);

            // Module assignment is also project assignment. Without this the
            // staffing screen became a dead end: a new manager had no people
            // in their owned roster to add to the next module.
            $project = $module->project()->with('members:id')->firstOrFail();
            $this->projects->syncMembers(
                $project,
                $project->members->pluck('id')->merge($ids)->unique()->values()->all(),
            );

            return $updated;
        });

        $project = $module->project;

        $this->messages->notifyOthers(
            $user,
            array_diff(array_map('intval', $employeeIds), $before),
            [
                'title' => 'Added to module',
                'body' => sprintf('You were added to %s in %s.', $module->name, $project?->name ?? 'a project'),
                'type' => 'project',
                ...($project?->slug && $module->slug
                    ? [
                        'routeName' => 'project-module-detail',
                        'routeParams' => ['projectSlug' => $project->slug, 'moduleSlug' => $module->slug],
                    ]
                    : ['routeName' => 'projects']),
            ],
        );

        return $updated;
    }

    /**
     * Active delivery staff a project owner may assign. This is deliberately
     * separate from Employees.index: a manager's normal employee view is
     * limited to their existing team, whereas staffing is how that team is
     * formed in the first place.
     */
    public function staffingCandidates(User $user, string $slug): Collection
    {
        $project = $this->projects->findBySlug($slug);

        if (! $project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $scope = $this->requireScope($user, 'create');
        $this->assertWithinScope($user, $project, $scope);

        return Employee::query()
            ->select(['id', 'user_id', 'name', 'email', 'department', 'designation'])
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query
                ->where('status', 'active')
                ->whereIn('role', ['employee', 'team_lead', 'qa', 'intern']))
            ->with('user:id,role')
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'department' => $employee->department,
                'designation' => $employee->designation,
                'role' => $employee->user?->role,
            ]);
    }

    /**
     * Unique within its project, not globally: two projects may each have an
     * "Attendance System" and the URL carries the project slug as well.
     */
    private function uniqueModuleSlug(Project $project, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'module';
        $slug = $base;
        $suffix = 2;

        while ($this->projects->moduleSlugExists($project->id, $slug, $ignoreId)) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function setModuleDelivered(User $user, int $moduleId, bool $delivered): ProjectModule
    {
        $module = $this->projects->findModule($moduleId);

        if (! $module) {
            throw new NotFoundHttpException('Module not found.');
        }

        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Projects');

        if (! $row || ! ($row['approve'] ?? false)) {
            throw new AccessDeniedHttpException('You may not change delivery status.');
        }

        // Scope still applies: holding `approve` does not grant it over a
        // project the user has no relationship to.
        $visible = $this->scopes->visibleProjectIds($user, $row['scope']);
        if ($visible !== null && ! in_array($module->project_id, $visible, true)) {
            throw new AccessDeniedHttpException('You may not change this module.');
        }

        return $this->projects->updateModule($module, [
            'status' => $delivered ? 'completed' : 'active',
            // Cleared on reopen so a reopened module never reports a delivery
            // date it no longer has.
            'delivered_at' => $delivered ? now()->toDateString() : null,
        ]);
    }

    /** Approve, then settle, a manager commission through Finance only. */
    public function decideModuleCommission(User $user, int $moduleId, string $status): ProjectModule
    {
        // This is intentionally role-bound. Super Admin's immutable "All
        // modules" grant covers finance controls even when a tenant's legacy
        // permission grid does not contain a separate Finance row.
        if ($user->role !== 'super_admin') {
            throw new AccessDeniedHttpException('You may not approve commissions.');
        }

        $module = $this->projects->findModule($moduleId);

        if (! $module) {
            throw new NotFoundHttpException('Module not found.');
        }

        if ($status === 'approved' && $module->commission_status !== 'pending') {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                'Only pending commissions can be approved.'
            );
        }

        if ($status === 'paid' && $module->commission_status !== 'approved') {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                'A commission must be approved before it can be paid.'
            );
        }

        $updated = $this->projects->updateModule($module, ['commission_status' => $status]);
        $project = $module->project;
        $label = sprintf('%s / %s', $project?->name ?? 'Project', $module->name);

        $this->activity->record($user, [
            'module' => 'finance',
            'action' => "commission {$status}",
            'details' => sprintf('Commission on %s was marked %s.', $label, $status),
            'targetId' => $module->id,
            'targetType' => 'module',
        ]);

        // The manager is the one being paid; this is their money moving.
        $this->messages->notifyOthers($user, [$project?->manager_id], [
            'title' => $status === 'paid' ? 'Commission paid' : 'Commission approved',
            'body' => $status === 'paid'
                ? sprintf('Your commission on %s has been paid.', $label)
                : sprintf('Your commission on %s was approved for payment.', $label),
            'type' => 'finance',
            'routeName' => 'finance',
        ]);

        return $updated;
    }

    private function canSee(User $user, Project $project): bool
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Projects');
        if (! $row || ! ($row['view'] ?? false)) {
            return false;
        }

        $visible = $this->scopes->visibleProjectIds($user, $row['scope']);

        return $visible === null || in_array($project->id, $visible, true);
    }

    private function assertWithinScope(User $user, Project $project, string $scope): void
    {
        $visible = $this->scopes->visibleProjectIds($user, $scope);

        if ($visible !== null && ! in_array($project->id, $visible, true)) {
            throw new AccessDeniedHttpException('You may not change this project.');
        }
    }

    private function requireScope(User $user, string $action): string
    {
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Projects');

        if (! $row || ! ($row[$action] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        return $row['scope'];
    }

    /**
     * Slugs are unique and appear in URLs, so a duplicate name gets a suffix
     * rather than failing the request.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while ($this->projects->findBySlug($slug) !== null) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function employeeFor(User $user): ?\App\Models\Employee
    {
        return \App\Models\Employee::where('user_id', $user->id)->first();
    }
}

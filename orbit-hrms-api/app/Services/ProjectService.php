<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\User;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProjectService
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
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

        $this->projects->syncMembers($project, $employeeIds);

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
            'commission_type' => $attributes['commissionType'] ?? 'percent',
            'commission_value' => $attributes['commissionValue'] ?? 0,
            'commission_status' => 'pending',
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

        $payload = array_filter([
            'name' => $attributes['name'] ?? null,
            'description' => $attributes['description'] ?? null,
            'lead_id' => $attributes['leadId'] ?? null,
            'budget' => $attributes['budget'] ?? null,
            'commission_type' => $attributes['commissionType'] ?? null,
            'commission_value' => $attributes['commissionValue'] ?? null,
        ], fn ($value) => $value !== null);

        // The slug follows a rename, since it is what the board's URL uses.
        if (filled($attributes['name'] ?? null) && $attributes['name'] !== $module->name) {
            $payload['slug'] = $this->uniqueModuleSlug($module->project, $attributes['name'], $module->id);
        }

        return $this->projects->updateModule($module, $payload);
    }

    /**
     * Replaces a module's member list.
     */
    public function syncModuleMembers(User $user, int $moduleId, array $employeeIds): ProjectModule
    {
        $module = $this->projects->findModule($moduleId);

        if (! $module) {
            throw new NotFoundHttpException('Module not found.');
        }

        $scope = $this->requireScope($user, 'create');
        $this->assertWithinScope($user, $module->project, $scope);

        return $this->projects->syncModuleMembers($module, array_map('intval', $employeeIds));
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

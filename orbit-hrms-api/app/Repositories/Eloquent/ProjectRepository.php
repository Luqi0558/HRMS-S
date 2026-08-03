<?php

namespace App\Repositories\Eloquent;

use App\Models\Project;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Support\Collection;

class ProjectRepository implements ProjectRepositoryInterface
{
    public function __construct(private readonly Project $model)
    {
    }

    public function listScoped(?array $visibleIds, array $filters = []): Collection
    {
        $query = $this->model->newQuery()
            ->with(['manager:id,name', 'modules:id,project_id,name,slug,status,lead_id'])
            // Counted in SQL rather than loaded and counted in PHP: the list
            // shows totals per project and the rows themselves are not needed.
            ->withCount([
                'modules',
                'tickets',
                'tickets as open_tickets_count' => fn ($q) => $q->where('status', '!=', 'done'),
                'tickets as overdue_tickets_count' => fn ($q) => $q
                    ->where('status', '!=', 'done')
                    ->whereNotNull('deadline')
                    ->whereDate('deadline', '<', now()),
            ]);

        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['search'] ?? null)) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        return $query->orderBy('name')->get();
    }

    public function findBySlug(string $slug): ?Project
    {
        return $this->model->newQuery()->where('slug', $slug)->first();
    }

    public function findById(int $id): ?Project
    {
        return $this->model->newQuery()->find($id);
    }

    public function create(array $attributes): Project
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function update(Project $project, array $attributes): Project
    {
        $project->fill($attributes)->save();

        return $project->refresh();
    }

    public function syncMembers(Project $project, array $employeeIds): void
    {
        $project->members()->sync($employeeIds);
    }

    public function loadDetail(Project $project): Project
    {
        return $project->load([
            'manager:id,name,email',
            'members:id,name,email,designation',
            'modules' => fn ($q) => $q->withCount([
                'tickets',
                'tickets as open_tickets_count' => fn ($t) => $t->where('status', '!=', 'done'),
                'tickets as overdue_tickets_count' => fn ($t) => $t
                    ->where('status', '!=', 'done')
                    ->whereNotNull('deadline')
                    ->whereDate('deadline', '<', now()),
            ]),
            'modules.lead:id,name',
            'modules.members:id,name,email',
        ]);
    }
}

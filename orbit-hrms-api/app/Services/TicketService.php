<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TicketService
{
    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
    ) {
    }

    public function list(User $user, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $scope = $this->requireScope($user, 'view');

        return $this->tickets->paginateScoped(
            $this->visibleProjects($user, $scope),
            $filters,
            $perPage,
        );
    }

    public function find(User $user, int $id): Ticket
    {
        $ticket = $this->tickets->findById($id);

        if (! $ticket || ! $this->canSee($user, $ticket)) {
            throw new NotFoundHttpException('Ticket not found.');
        }

        return $this->tickets->loadDetail($ticket);
    }

    public function create(User $user, array $attributes): Ticket
    {
        $scope = $this->requireScope($user, 'create');

        $module = ProjectModule::find($attributes['moduleId']);
        if (! $module) {
            throw new NotFoundHttpException('Module not found.');
        }

        // A ticket cannot be created in a project the user cannot see, or the
        // module picker becomes a way to write into someone else's project.
        $visible = $this->visibleProjects($user, $scope);
        if ($visible !== null && ! in_array($module->project_id, $visible, true)) {
            throw new AccessDeniedHttpException('You may not add work to this module.');
        }

        // Delivered modules are closed to new work; reopening is an explicit
        // action rather than a side effect of creating a ticket.
        if ($module->status === 'completed') {
            throw new AccessDeniedHttpException('This module is delivered. Reopen it before adding tickets.');
        }

        $employee = $this->employeeFor($user);

        return $this->tickets->create([
            'project_id' => $module->project_id,
            'project_module_id' => $module->id,
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'todo',
            'priority' => $attributes['priority'] ?? 'medium',
            'assignee_id' => $attributes['assigneeId'] ?? null,
            'created_by' => $employee?->id,
            'deadline' => $attributes['deadline'] ?? null,
        ]);
    }

    public function update(User $user, int $id, array $attributes): Ticket
    {
        $ticket = $this->tickets->findById($id);

        if (! $ticket || ! $this->canSee($user, $ticket)) {
            throw new NotFoundHttpException('Ticket not found.');
        }

        $employee = $this->employeeFor($user);
        $row = $this->permissionRow($user);

        // An assignee may move their own ticket through the board even without
        // a wider create grant — that is the core of doing the work. Editing
        // anyone else's requires the module-level permission.
        $isAssignee = $employee && $ticket->assignee_id === $employee->id;

        if (! $isAssignee && ! ($row['create'] ?? false)) {
            throw new AccessDeniedHttpException('You may not change this ticket.');
        }

        $payload = [];
        foreach ([
            'title' => 'title',
            'description' => 'description',
            'status' => 'status',
            'priority' => 'priority',
            'deadline' => 'deadline',
            'assigneeId' => 'assignee_id',
        ] as $input => $column) {
            if (array_key_exists($input, $attributes)) {
                $payload[$column] = $attributes[$input];
            }
        }

        // Reassigning is a supervisory act, and scope is what distinguishes a
        // supervisor here. Every role holds create on Tickets (employees raise
        // their own), and only team_lead holds approve — so neither boolean
        // separates the two. What does: an employee's scope is 'team' (they
        // work alongside these tickets) while a manager's or lead's is 'owned'
        // (they run them).
        $maySupervise = in_array($row['scope'] ?? '', ['owned', 'all'], true)
            || ($row['approve'] ?? false);

        if (array_key_exists('assignee_id', $payload) && ! $maySupervise) {
            unset($payload['assignee_id']);
        }

        return $this->tickets->update($ticket, $payload);
    }

    public function comment(User $user, int $id, string $body): Ticket
    {
        $ticket = $this->tickets->findById($id);

        if (! $ticket || ! $this->canSee($user, $ticket)) {
            throw new NotFoundHttpException('Ticket not found.');
        }

        $employee = $this->employeeFor($user);
        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can comment.');
        }

        $this->tickets->addComment($ticket, $employee->id, $body);

        return $this->tickets->loadDetail($ticket->refresh());
    }

    /**
     * Starts a timer, stopping any already running one first.
     *
     * One timer per person is enforced here rather than by a constraint: the
     * rule is "starting a new timer stops the old one", which a unique index
     * would turn into an error instead.
     */
    public function startTimer(User $user, int $id): Ticket
    {
        $ticket = $this->tickets->findById($id);

        if (! $ticket || ! $this->canSee($user, $ticket)) {
            throw new NotFoundHttpException('Ticket not found.');
        }

        $employee = $this->employeeFor($user);
        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can log time.');
        }

        DB::transaction(function () use ($ticket, $employee) {
            $this->tickets->stopTimer($employee->id);
            $this->tickets->startTimer($ticket, $employee->id);
        });

        return $this->tickets->loadDetail($ticket->refresh());
    }

    public function stopTimer(User $user): void
    {
        $employee = $this->employeeFor($user);
        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can log time.');
        }

        $this->tickets->stopTimer($employee->id);
    }

    private function canSee(User $user, Ticket $ticket): bool
    {
        $row = $this->permissionRow($user);
        if (! $row || ! ($row['view'] ?? false)) {
            return false;
        }

        $employee = $this->employeeFor($user);
        // Your own ticket is always visible, even if you have since been
        // removed from the project.
        if ($employee && $ticket->assignee_id === $employee->id) {
            return true;
        }

        $visible = $this->visibleProjects($user, $row['scope']);

        return $visible === null || in_array($ticket->project_id, $visible, true);
    }

    private function visibleProjects(User $user, string $scope): ?array
    {
        return $this->scopes->visibleProjectIds($user, $scope);
    }

    private function permissionRow(User $user): ?array
    {
        return collect($this->permissions->forUser($user))->firstWhere('module', 'Tickets');
    }

    private function requireScope(User $user, string $action): string
    {
        $row = $this->permissionRow($user);

        if (! $row || ! ($row[$action] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        return $row['scope'];
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}

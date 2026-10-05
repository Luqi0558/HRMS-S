<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TicketService
{
    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
        private readonly TicketReferenceGenerator $references,
        private readonly SettingsService $settings,
        private readonly MessageService $messages,
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

        $ticket = $this->tickets->create([
            'project_id' => $module->project_id,
            'project_module_id' => $module->id,
            'title' => $attributes['title'],
            // Derived from the company, project and module rather than typed:
            // see TicketReferenceGenerator.
            'reference' => $this->references->for($module),
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'todo',
            'priority' => $attributes['priority'] ?? 'medium',
            'assignee_id' => $attributes['assigneeId'] ?? null,
            'created_by' => $employee?->id,
            'deadline' => $attributes['deadline'] ?? null,
        ]);

        $this->notifyWatchers($user, $ticket, 'New ticket', sprintf(
            '%s created "%s" in %s.',
            $employee?->name ?? 'Someone',
            $ticket->title,
            $module->name,
        ));

        return $ticket;
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
            // reference is deliberately absent: it is assigned once at creation
            // and quoted elsewhere, so it must not drift.
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

        // Signing work off is the same kind of act as reassigning it, so it
        // takes the same test. Without this the QA gate was decorative: every
        // role holds create on Tickets — that is how an employee raises their
        // own — so anyone on the board could mark anything done, QA included or
        // not.
        //
        // 'approve' rather than scope alone is what admits QA, whose scope is
        // 'team'; managers and leads qualify on scope. An assignee moves work
        // to 'review' and stops there, which is the handover.
        if (($payload['status'] ?? null) === 'done' && ! $maySupervise) {
            throw new AccessDeniedHttpException(
                'Only a reviewer can mark work done. Move it to review instead.'
            );
        }

        $previousStatus = $ticket->status;
        $previousAssignee = $ticket->assignee_id ? (int) $ticket->assignee_id : null;

        // Reloaded with its relations: reassigning changes who the ticket belongs
        // to, and the client merges this response into what it already holds.
        // Returning the bare row left the previous assignee's name on screen
        // until the next full load.
        $updated = $this->tickets->loadDetail($this->tickets->update($ticket, $payload));

        $this->notifyUpdate($user, $employee, $updated, $previousStatus, $previousAssignee);

        return $updated;
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

        $this->notifyWatchers($user, $ticket, 'New ticket comment', sprintf(
            '%s commented on "%s".',
            $employee->name,
            $ticket->title,
        ));

        return $this->tickets->loadDetail($ticket->refresh());
    }

    /**
     * Attaches a file to a ticket. Anyone who can see the ticket may attach,
     * as with comments, and the people on it are told.
     */
    public function attach(User $user, int $id, UploadedFile $file): Ticket
    {
        $ticket = $this->tickets->findById($id);

        if (! $ticket || ! $this->canSee($user, $ticket)) {
            throw new NotFoundHttpException('Ticket not found.');
        }

        $employee = $this->employeeFor($user);

        $ticket->attachments()->create([
            'uploaded_by' => $employee?->id,
            'path' => $file->store("tickets/{$ticket->id}", 'local'),
            'original_name' => $file->getClientOriginalName(),
            'size_bytes' => $file->getSize(),
            'mime_type' => $file->getClientMimeType(),
        ]);

        $this->notifyWatchers($user, $ticket, 'New ticket attachment', sprintf(
            '%s attached %s to "%s".',
            $employee?->name ?? 'Someone',
            $file->getClientOriginalName(),
            $ticket->title,
        ));

        return $this->tickets->loadDetail($ticket->refresh());
    }

    /** An attachment on a ticket this user can see; 404 otherwise. */
    public function findAttachment(User $user, int $id, int $attachmentId): TicketAttachment
    {
        $ticket = $this->tickets->findById($id);

        if (! $ticket || ! $this->canSee($user, $ticket)) {
            throw new NotFoundHttpException('Attachment not found.');
        }

        $attachment = $ticket->attachments()->whereKey($attachmentId)->first();

        if (! $attachment) {
            throw new NotFoundHttpException('Attachment not found.');
        }

        return $attachment;
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

        // Time logged against a ticket is working time, not simply elapsed
        // browser time. Keep this check on the server so a direct API call
        // cannot bypass the same rule the interface presents to staff.
        if (! $this->isCheckedIn($employee)) {
            throw new ConflictHttpException('Check in from Attendance before starting a ticket timer.');
        }

        DB::transaction(function () use ($ticket, $employee) {
            $this->tickets->stopTimer($employee->id);
            $this->tickets->startTimer($ticket, $employee->id);
        });

        return $this->tickets->loadDetail($ticket->refresh());
    }

    /**
     * Returns the ticket whose timer was stopped, so the caller can send the
     * refreshed totals back. Null when nothing was running — stopping an
     * already-stopped timer is not an error.
     */
    public function stopTimer(User $user): ?Ticket
    {
        $employee = $this->employeeFor($user);
        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can log time.');
        }

        $ticketIds = $this->tickets->stopTimer($employee->id);

        if ($ticketIds === []) {
            return null;
        }

        // At most one timer runs per employee — startTimer stops any other
        // first — so the first id is the one that matters.
        $ticket = $this->tickets->findById($ticketIds[0]);

        return $ticket ? $this->tickets->loadDetail($ticket) : null;
    }

    /**
     * Picks the one notification an edit deserves.
     *
     * A review verdict is the most specific thing that can happen to a ticket,
     * then a status move, then anything else. One notification per save: a
     * reviewer who sends work back with a note should not land three rows in
     * the assignee's bell.
     */
    private function notifyUpdate(User $user, ?Employee $actor, Ticket $ticket, string $previousStatus, ?int $previousAssignee): void
    {
        $name = $actor?->name ?? 'Someone';
        $assignee = $ticket->assignee_id ? (int) $ticket->assignee_id : null;
        $reassigned = $assignee !== $previousAssignee;

        // Someone handed this ticket gets told directly, in words that say so,
        // rather than finding out from a generic "updated".
        if ($assignee && $reassigned) {
            $this->messages->notifyOthers($user, [$assignee], [
                'title' => 'Ticket assigned to you',
                'body' => sprintf('%s assigned "%s" to you.', $name, $ticket->title),
                'type' => 'ticket',
                ...$this->ticketRoute($ticket),
            ]);
        }

        if ($ticket->status !== $previousStatus) {
            [$title, $body] = match (true) {
                $previousStatus === 'review' && $ticket->status === 'done' => [
                    'Ticket passed review',
                    sprintf('"%s" was approved by %s.', $ticket->title, $name),
                ],
                $previousStatus === 'review' => [
                    'Ticket sent back',
                    sprintf('%s sent "%s" back to %s.', $name, $ticket->title, $this->statusLabel($ticket->status)),
                ],
                default => [
                    'Ticket status changed',
                    sprintf('%s moved "%s" to %s.', $name, $ticket->title, $this->statusLabel($ticket->status)),
                ],
            };
        } else {
            [$title, $body] = ['Ticket updated', sprintf('%s updated "%s".', $name, $ticket->title)];
        }

        // The new assignee has just had their own notification above.
        $this->notifyWatchers($user, $ticket, $title, $body, except: $reassigned ? $assignee : null);
    }

    /**
     * Tells the people a ticket belongs to: whoever is doing it, whoever
     * raised it, and the manager accountable for the project.
     */
    private function notifyWatchers(User $user, Ticket $ticket, string $title, string $body, ?int $except = null): void
    {
        $ticket->loadMissing(['project:id,slug,manager_id', 'module:id,slug']);

        $recipients = array_filter(
            [$ticket->assignee_id, $ticket->created_by, $ticket->project?->manager_id],
            fn ($id) => $id && (int) $id !== $except,
        );

        $this->messages->notifyOthers($user, $recipients, [
            'title' => $title,
            'body' => $body,
            'type' => 'ticket',
            ...$this->ticketRoute($ticket),
        ]);
    }

    /** Links the bell straight to the module board the ticket lives on. */
    private function ticketRoute(Ticket $ticket): array
    {
        $ticket->loadMissing(['project:id,slug,manager_id', 'module:id,slug']);

        if (! $ticket->project?->slug || ! $ticket->module?->slug) {
            return ['routeName' => 'projects'];
        }

        return [
            'routeName' => 'project-module-detail',
            'routeParams' => [
                'projectSlug' => $ticket->project->slug,
                'moduleSlug' => $ticket->module->slug,
            ],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'todo' => 'To do',
            'in_progress' => 'In progress',
            'review' => 'Review',
            'done' => 'Done',
            'blocked' => 'Blocked',
            default => $status,
        };
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

    private function isCheckedIn(Employee $employee): bool
    {
        $today = now()->setTimezone(
            (string) $this->settings->get('company', 'timezone', config('app.timezone'))
        )->toDateString();

        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $today)
            ->whereHas('sessions', fn ($sessions) => $sessions->whereNull('check_out'))
            ->exists();
    }
}

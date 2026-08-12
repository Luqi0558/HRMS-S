<?php

namespace App\Services;

use App\Jobs\AnnouncePostToTeams;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Announcements and policies.
 *
 * Everyone reads, only HR writes — the grid says so, and this enforces it.
 *
 * Acknowledgements are per policy VERSION rather than per policy. Reissuing a
 * policy at 2.0 makes every prior acknowledgement stale, which is the whole
 * point of asking for one: agreeing to the old text is not agreeing to the
 * new. "Who has not acknowledged this" is only answerable because of that.
 */
class CompanyHubService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly MessageService $messages,
        private readonly ActivityLogService $activity,
    ) {
    }

    // --- announcements ---------------------------------------------------

    public function announcements(User $user): array
    {
        $this->assertMayRead($user);

        return Announcement::with('author:id,name')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->all();
    }

    public function publish(User $user, array $attributes): Announcement
    {
        $author = $this->assertMayWrite($user);

        $announcement = Announcement::create([
            'title' => $attributes['title'],
            'body' => $attributes['body'],
            'audience' => $attributes['audience'] ?? 'All company',
            'priority' => $attributes['priority'] ?? 'normal',
            'author_id' => $author?->id,
            // Kept so it still names its author after they leave.
            'author_name' => $author?->name,
            'published_at' => now(),
        ]);

        // An announcement nobody is told about is a page nobody visits.
        $this->messages->notify(
            $this->messages->employeeIdsForRoles(['employee', 'manager', 'team_lead', 'hr', 'super_admin']),
            [
                'title' => $announcement->priority === 'high' ? 'Important announcement' : 'New announcement',
                'body' => $announcement->title,
                'type' => 'announcement',
                'routeName' => 'company-hub',
            ],
        );

        $this->activity->record($user, [
            'module' => 'announcements',
            'action' => 'announcement published',
            'details' => sprintf('%s was published to %s.', $announcement->title, $announcement->audience),
            'targetId' => $announcement->id,
            'targetType' => 'announcement',
        ]);

        // Mirrored into the Teams announcements channel, when one is configured.
        // Dispatched last so a Teams problem cannot prevent the announcement
        // being recorded or the in-app notifications going out.
        AnnouncePostToTeams::dispatch($announcement->id);

        return $announcement->load('author:id,name');
    }

    // --- policies --------------------------------------------------------

    /**
     * Policies, each carrying whether THIS user has acknowledged the current
     * version — the one thing the badge needs and could not previously know.
     */
    public function policies(User $user): array
    {
        $this->assertMayRead($user);

        $employee = $this->employeeFor($user);

        $policies = Policy::with('owner:id,name')->orderBy('title')->get();

        // One query for every acknowledgement this person has, rather than one
        // per policy.
        $mine = $employee
            ? PolicyAcknowledgement::where('employee_id', $employee->id)
                ->pluck('version', 'policy_id')
            : collect();

        return $policies->map(function (Policy $policy) use ($mine) {
            // Acknowledged only if it was THIS version.
            $policy->setAttribute(
                'acknowledged_by_me',
                ($mine[$policy->id] ?? null) === $policy->version,
            );

            return $policy;
        })->all();
    }

    public function savePolicy(User $user, array $attributes, ?int $policyId = null): Policy
    {
        $owner = $this->assertMayWrite($user);

        $data = [
            'title' => $attributes['title'],
            'category' => $attributes['category'] ?? 'HR',
            'version' => $attributes['version'] ?? '1.0',
            'summary' => $attributes['summary'],
            'body' => $attributes['body'] ?? null,
            'acknowledgment_required' => (bool) ($attributes['acknowledgmentRequired'] ?? false),
            'owner_id' => $owner?->id,
            'owner_name' => $owner?->name,
        ];

        if ($policyId) {
            $policy = Policy::find($policyId);

            if (! $policy) {
                throw new NotFoundHttpException('Policy not found.');
            }

            $policy->update($data);
        } else {
            $policy = Policy::create($data);
        }

        $this->activity->record($user, [
            'module' => 'policies',
            'action' => $policyId ? 'policy updated' : 'policy added',
            'details' => sprintf('%s (v%s) was %s.', $policy->title, $policy->version, $policyId ? 'updated' : 'added'),
            'targetId' => $policy->id,
            'targetType' => 'policy',
        ]);

        return $policy->refresh()->load('owner:id,name');
    }

    /** Records that this person has read and agreed to the current version. */
    public function acknowledge(User $user, int $policyId): PolicyAcknowledgement
    {
        $employee = $this->requireEmployee($user);
        $policy = Policy::find($policyId);

        if (! $policy) {
            throw new NotFoundHttpException('Policy not found.');
        }

        $existing = PolicyAcknowledgement::where('policy_id', $policy->id)
            ->where('employee_id', $employee->id)
            ->where('version', $policy->version)
            ->first();

        if ($existing) {
            throw new ConflictHttpException('You have already acknowledged this version.');
        }

        $acknowledgement = PolicyAcknowledgement::create([
            'policy_id' => $policy->id,
            'employee_id' => $employee->id,
            // Stamped with the version as it stands now: reissuing at 2.0 must
            // not silently inherit an agreement to 1.0.
            'version' => $policy->version,
            'acknowledged_at' => now(),
        ]);

        $this->activity->record($user, [
            'module' => 'policies',
            'action' => 'policy acknowledged',
            'details' => sprintf('%s acknowledged %s v%s.', $employee->name, $policy->title, $policy->version),
            'targetId' => $policy->id,
            'targetType' => 'policy',
        ]);

        return $acknowledgement;
    }

    /**
     * Who has and has not acknowledged the current version.
     *
     * The compliance question, and the reason acknowledgements are versioned.
     */
    public function acknowledgementStatus(User $user, int $policyId): array
    {
        $this->assertMayWrite($user);

        $policy = Policy::find($policyId);

        if (! $policy) {
            throw new NotFoundHttpException('Policy not found.');
        }

        $acknowledged = PolicyAcknowledgement::where('policy_id', $policy->id)
            ->where('version', $policy->version)
            ->pluck('employee_id')
            ->all();

        $everyone = Employee::where('status', 'active')->get(['id', 'name']);

        return [
            'version' => $policy->version,
            'acknowledged' => $everyone->whereIn('id', $acknowledged)->values()->all(),
            'outstanding' => $everyone->whereNotIn('id', $acknowledged)->values()->all(),
        ];
    }

    // --- internals -------------------------------------------------------

    private function assertMayRead(User $user): void
    {
        if (! $this->permissions->can($user, 'Company Hub', 'view')) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }
    }

    private function assertMayWrite(User $user): ?Employee
    {
        if (! $this->permissions->can($user, 'Company Hub', 'create')) {
            throw new AccessDeniedHttpException('You may not publish to the company hub.');
        }

        return $this->employeeFor($user);
    }

    private function requireEmployee(User $user): Employee
    {
        $employee = $this->employeeFor($user);

        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can acknowledge a policy.');
        }

        return $employee;
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}

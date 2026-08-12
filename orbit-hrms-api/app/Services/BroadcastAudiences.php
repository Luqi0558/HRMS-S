<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The single definition of who a broadcast audience means.
 *
 * This used to live in three places — which roles may broadcast, which
 * audiences they may pick, and which roles each audience resolves to — spread
 * across Messages.vue and MessageService. Adding the qa and intern roles
 * required an edit in all three; one was missed, so "All employees" quietly
 * stopped reaching two people. The announcement still sent, still reported
 * success, and nobody could tell.
 *
 * So the grouping lives here and nowhere else, and unassignedRoles() makes the
 * drift loud: a role that exists in the permission grid but belongs to no
 * audience is a role that would be silently skipped, and a test fails on it.
 */
class BroadcastAudiences
{
    /**
     * Audience id => label and the roles it covers.
     *
     * A null role list means "everyone the sender can reach", which is not the
     * same as listing every role: it is bounded by the sender's own scope.
     */
    private const AUDIENCES = [
        'all_company' => ['label' => 'All company', 'roles' => null],
        // Non-management staff. QA and interns are employees for the purposes
        // of an announcement, which is the judgement this constant records.
        'employees' => ['label' => 'All employees', 'roles' => ['employee', 'qa', 'intern']],
        'managers' => ['label' => 'All managers', 'roles' => ['manager', 'team_lead']],
        'hr' => ['label' => 'HR team', 'roles' => ['hr']],
    ];

    /**
     * Roles that are deliberately not part of any audience.
     *
     * super_admin administers the company rather than being announced to, and
     * would otherwise be listed as unassigned by the drift check below.
     */
    private const NOT_AN_AUDIENCE_MEMBER = ['super_admin'];

    /** @return array<int, string> */
    public function ids(): array
    {
        return array_keys(self::AUDIENCES);
    }

    public function label(string $audience): string
    {
        return self::AUDIENCES[$audience]['label'] ?? $audience;
    }

    public function exists(string $audience): bool
    {
        return array_key_exists($audience, self::AUDIENCES);
    }

    /**
     * The roles an audience covers, or null for "everyone reachable".
     *
     * @return array<int, string>|null
     */
    public function rolesFor(string $audience): ?array
    {
        return self::AUDIENCES[$audience]['roles'] ?? null;
    }

    /**
     * Roles present in the permission grid that no audience covers.
     *
     * Empty is the healthy state. Anything here is a role whose holders would
     * be skipped by every broadcast without a word — which is exactly what
     * happened to qa and intern, and what the test guarding this prevents.
     *
     * @return array<int, string>
     */
    public function unassignedRoles(): array
    {
        $covered = collect(self::AUDIENCES)
            ->pluck('roles')
            ->filter()
            ->flatten()
            ->merge(self::NOT_AN_AUDIENCE_MEMBER)
            ->unique();

        // DB::table, not a model: role_permissions is a lookup with no
        // behaviour, which is how PermissionRepository reads it too.
        return DB::table('role_permissions')
            ->distinct()
            ->pluck('role')
            ->reject(fn (string $role) => $covered->contains($role))
            ->values()
            ->all();
    }
}

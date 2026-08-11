<?php

use App\Models\Employee;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| This is the security boundary for the socket. Without it, subscribing to
| another employee's feed would be a matter of editing a string in the
| browser — Pusher asks this application whether a subscription is allowed,
| and whatever these callbacks return is the answer.
|
*/

/**
 * One employee's feed.
 *
 * The tenant is part of the channel name because two companies in this
 * installation both have an employee 1, and they share one Pusher app. The
 * check confirms the subscriber belongs to the tenant resolved from the
 * request: tenancy is initialised from the X-Tenant header before this
 * callback runs, so a token issued for company A cannot subscribe to company
 * B's channel even when the employee ids match.
 */
Broadcast::channel('tenant.{tenantId}.employee.{employeeId}', function ($user, string $tenantId, string $employeeId) {
    if (! tenant('id') || (string) tenant('id') !== $tenantId) {
        return false;
    }

    $employee = Employee::where('user_id', $user->id)->first();

    return $employee && (string) $employee->id === $employeeId;
});

/**
 * Who is currently online, company-wide.
 *
 * A presence channel rather than a stored flag: Pusher already knows which
 * browsers are subscribed and pushes member_added / member_removed to everyone
 * else. A last_seen column would need a job to expire it and would still be
 * wrong the moment somebody shut a laptop lid without signing out.
 *
 * Returning an array (rather than true) is what makes this a presence channel —
 * the data becomes that member's entry in the roster every other subscriber
 * sees, so it must contain nothing that is not already public within the
 * company.
 */
Broadcast::channel('tenant.{tenantId}.presence', function ($user, string $tenantId) {
    if (! tenant('id') || (string) tenant('id') !== $tenantId) {
        return false;
    }

    $employee = Employee::where('user_id', $user->id)->first();

    if (! $employee || $employee->status !== 'active') {
        return false;
    }

    return [
        'id' => $employee->id,
        'name' => $employee->name,
        'status' => $employee->presence_status ?? 'available',
        'message' => $employee->presence_message,
    ];
});

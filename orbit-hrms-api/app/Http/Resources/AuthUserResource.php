<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in identity, and nothing more.
 *
 * Login previously returned the full nested employee record — designation,
 * department, birthday, joining date, probation status — none of which the
 * SPA reads at sign-in (Login.vue uses id, name, role and email only).
 * Two reasons that was wrong:
 *
 *  1. Login is the app's one unauthenticated endpoint. Everything it returns
 *     is disclosed on a successful guess, so it should carry the minimum
 *     needed to establish a session.
 *  2. `name` and `email` exist on both `users` and `employees`, so nesting the
 *     employee made them appear twice with no indication of which one wins.
 *
 * The employment record is available from GET /api/employees/me when a screen
 * actually needs it.
 */
class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            // Included because the UI may want to show "last seen"; it is the
            // user's own data and discloses nothing about anyone else.
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
            // The employment record's id only, so a client can fetch the rest
            // without a second lookup by email.
            'employeeId' => $this->whenLoaded(
                'employee',
                fn () => $this->employee?->id
            ),
        ];
    }
}

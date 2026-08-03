<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\InviteEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeInvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations)
    {
    }

    /**
     * Creates the employment record and its first invitation.
     *
     * The raw token is returned in the response for now, because no mailer is
     * configured — HR copies the link manually. Once mail is wired this should
     * send the invitation and stop returning the token, since a token in an
     * API response ends up in logs and browser history.
     */
    public function store(InviteEmployeeRequest $request): JsonResponse
    {
        $result = $this->invitations->inviteEmployee(
            $request->validated(),
            $request->user()->id,
        );

        return response()->json([
            'message' => "Invitation created for {$result['employee']->name}.",
            'employee' => new EmployeeResource($result['employee']),
            'invite' => [
                'token' => $result['token'],
                'expiresAt' => $result['invitation']->expires_at->toIso8601String(),
                'path' => "/accept-invite?token={$result['token']}",
            ],
        ], 201);
    }

    /**
     * Issues a replacement link, invalidating any still outstanding so a
     * forwarded copy of the old email stops working.
     */
    public function resend(Request $request, Employee $employee): JsonResponse
    {
        if ($employee->status !== 'invited') {
            return response()->json([
                'message' => 'This employee has already completed onboarding.',
                'error' => 'already_onboarded',
            ], 422);
        }

        $result = $this->invitations->resendInvitation($employee, $request->user()->id);

        return response()->json([
            'message' => "New invitation created for {$employee->name}.",
            'invite' => [
                'token' => $result['token'],
                'expiresAt' => $result['invitation']->expires_at->toIso8601String(),
                'path' => "/accept-invite?token={$result['token']}",
            ],
        ]);
    }
}

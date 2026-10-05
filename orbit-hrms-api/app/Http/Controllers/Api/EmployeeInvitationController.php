<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\InviteEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Mail\EmployeeInvitationMail;
use App\Models\Employee;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
        $emailQueued = $this->queueInvitationEmail($result);
        $emailDeliveryEnabled = $this->hasDeliverableMailTransport();

        return response()->json([
            'message' => !$emailDeliveryEnabled
                ? "Invitation created for {$result['employee']->name}, but email delivery is not configured for this environment."
                : ($emailQueued
                ? "Invitation created and email queued for {$result['employee']->name}."
                : "Invitation created for {$result['employee']->name}, but the email could not be queued."),
            'employee' => new EmployeeResource($result['employee']),
            'emailQueued' => $emailQueued,
            'emailDeliveryEnabled' => $emailDeliveryEnabled,
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

        // A resend revokes the still-valid token. Do not strand an invitee by
        // replacing their only working link when this environment cannot send
        // any email (the local `log` and `array` transports are mail sinks).
        if (!$this->hasDeliverableMailTransport()) {
            return response()->json([
                'message' => 'Email delivery is not configured for this environment. Configure SMTP before resending the invitation.',
                'error' => 'mail_delivery_not_configured',
            ], 409);
        }

        $result = $this->invitations->resendInvitation($employee, $request->user()->id);
        $emailQueued = $this->queueInvitationEmail([...$result, 'employee' => $employee]);
        $emailDeliveryEnabled = $this->hasDeliverableMailTransport();

        return response()->json([
            'message' => !$emailDeliveryEnabled
                ? "New invitation created for {$employee->name}, but email delivery is not configured for this environment."
                : ($emailQueued
                ? "New invitation created and email queued for {$employee->name}."
                : "New invitation created for {$employee->name}, but the email could not be queued."),
            'emailQueued' => $emailQueued,
            'emailDeliveryEnabled' => $emailDeliveryEnabled,
            'invite' => [
                'token' => $result['token'],
                'expiresAt' => $result['invitation']->expires_at->toIso8601String(),
                'path' => "/accept-invite?token={$result['token']}",
            ],
        ]);
    }

    /**
     * The employee record and invitation are already durable at this point.
     * A mail transport outage must be visible to HR but must not undo either.
     */
    private function queueInvitationEmail(array $result): bool
    {
        $employee = $result['employee'];
        $inviteUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/accept-invite?token='.rawurlencode($result['token']);

        try {
            Mail::to($employee->email)->queue(new EmployeeInvitationMail(
                employeeName: $employee->name,
                inviteUrl: $inviteUrl,
                expiresAt: $result['invitation']->expires_at->toDayDateTimeString(),
            ));

            return true;
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /** Log/array transports are intentional local-development sinks, not inbox delivery. */
    private function hasDeliverableMailTransport(): bool
    {
        return !in_array((string) config('mail.default'), ['array', 'log'], true);
    }
}

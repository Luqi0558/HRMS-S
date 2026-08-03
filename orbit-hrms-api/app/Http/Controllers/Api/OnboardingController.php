<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CompleteOnboardingRequest;
use App\Http\Requests\Onboarding\ResolveInvitationRequest;
use App\Http\Resources\EmployeeResource;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    /**
     * Prefills the wizard from an invitation link. Public — the token is the
     * credential. Returns only what the form needs to greet the invitee; the
     * full employee record is not exposed to an unauthenticated caller.
     */
    public function resolve(ResolveInvitationRequest $request): JsonResponse
    {
        $invitation = $this->onboarding->resolveInvitation(
            $request->string('token')->toString()
        );

        return response()->json([
            'invitation' => [
                'email' => $invitation->email,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ],
            'employee' => [
                'name' => $invitation->employee->name,
                'designation' => $invitation->employee->designation,
                'department' => $invitation->employee->department,
            ],
        ]);
    }

    /**
     * Completes onboarding and activates the login. Everything inside runs in
     * one transaction, so a failure part-way leaves no half-onboarded account.
     */
    public function complete(CompleteOnboardingRequest $request): JsonResponse
    {
        $employee = $this->onboarding->completeOnboarding(
            $request->string('token')->toString(),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Onboarding complete. You can now sign in.',
            'employee' => new EmployeeResource($employee),
        ], 201);
    }
}

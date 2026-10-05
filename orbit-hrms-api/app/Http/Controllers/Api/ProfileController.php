<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\EmployeeResource;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in employee's own record.
 *
 * Deliberately not part of EmployeeController: every route there is gated on
 * the Employees module, which ordinary employees do not hold — so they could
 * neither read nor edit their own profile through it. These two routes take
 * their subject from the bearer token, never from the URL, so there is nothing
 * to authorise beyond being signed in.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profiles)
    {
    }

    /**
     * The full own-profile record, including the onboarding detail the Profile
     * screen edits. Employees cannot call /employees to find themselves in the
     * roster, so without this their own phone number and bank details are
     * simply unreachable.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'employee' => new EmployeeResource(
                $this->profiles->findOwn($request->user())
            ),
        ]);
    }

    /**
     * Changes the signed-in user's own password.
     *
     * `confirmed` pairs newPassword with newPassword_confirmation, so a typo
     * cannot lock somebody out of their own account.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $this->profiles->changePassword(
            $request->user(),
            $validated['currentPassword'],
            $validated['newPassword'],
        );

        return response()->json([
            'message' => 'Password updated. Other devices have been signed out.',
        ]);
    }

    /**
     * Uploads or replaces the signed-in user's own documents. Same file rules
     * as onboarding, plus the four the Profile screen adds.
     */
    public function uploadDocuments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.photo' => ['nullable', 'file', 'image', 'max:5120'],
            'documents.cv' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'documents.cnicFront' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents.cnicBack' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents.education' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents.experience' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents.offerLetter' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'documents.contract' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'documents.certificates' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents.experienceLetters' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);

        return response()->json([
            'message' => 'Documents uploaded. HR will review them.',
            'employee' => new EmployeeResource(
                $this->profiles->uploadOwnDocuments($request->user(), $validated['documents'])
            ),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Profile updated.',
            'employee' => new EmployeeResource(
                $this->profiles->updateOwn($request->user(), $request->validated())
            ),
        ]);
    }
}

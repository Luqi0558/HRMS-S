<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What an employee may change about themselves.
 *
 * The omissions are the point of this class. Anything an employee could use to
 * promote themselves or rewrite their employment record is absent, so it cannot
 * arrive through this endpoint at all:
 *
 *   email             the login identity — changing it changes who can sign in,
 *                     which needs a verification flow, not a text box
 *   department        who they report through, and what ScopeResolver hands
 *                     them; self-service here would widen their own access
 *   joiningDate       drives leave accrual and probation
 *   designation       a job title HR grants, not one you award yourself
 *   employmentType    contractual
 *   probationStatus   nobody confirms their own probation
 *   status            self-reactivation would defeat offboarding
 *
 * Key names stay camelCase to match the frontend; translation to columns
 * happens in OnboardingRepository, as it does for the invite wizard.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any signed-in employee may edit their own record. There is no module
        // permission check because this endpoint never touches anyone else's —
        // ProfileService resolves the employee from the token, not from input.
        return true;
    }

    public function rules(): array
    {
        return [
            // Correcting the spelling of your own name is reasonable
            // self-service. It is not blank-able, since the roster and every
            // message sender label reads from it.
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'birthday' => ['sometimes', 'nullable', 'date'],

            'personal' => ['sometimes', 'array'],
            'personal.phone' => ['nullable', 'string', 'max:40'],
            'personal.cnic' => ['nullable', 'string', 'max:40'],
            'personal.address' => ['nullable', 'string', 'max:1000'],
            'personal.emergencyContact' => ['nullable', 'string', 'max:255'],
            'personal.bankAccount' => ['nullable', 'string', 'max:64'],
            'personal.taxNumber' => ['nullable', 'string', 'max:64'],

            'technical' => ['sometimes', 'array'],
            'technical.primaryStack' => ['nullable', 'string', 'max:255'],
            // Numeric string from the form; 60 is a sanity ceiling rather than
            // a business rule.
            'technical.experienceYears' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'technical.githubUrl' => ['nullable', 'url', 'max:255'],
            'technical.portfolioUrl' => ['nullable', 'url', 'max:255'],
            'technical.linkedinUrl' => ['nullable', 'url', 'max:255'],

            // `nullable` on the elements so a stray empty entry from a form
            // serialiser is ignored rather than failing the whole submission —
            // the repository already filters blanks before inserting.
            'technical.stacks' => ['sometimes', 'array', 'max:50'],
            'technical.stacks.*' => ['nullable', 'string', 'max:255'],

            'technical.skills' => ['sometimes', 'array', 'max:100'],
            'technical.skills.*' => ['nullable', 'string', 'max:255'],

            'technical.skillLevels' => ['sometimes', 'array'],
            'technical.skillLevels.*' => ['nullable', 'string', 'max:40'],

            'technical.currentlyLearning' => ['sometimes', 'array', 'max:100'],
            'technical.currentlyLearning.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}

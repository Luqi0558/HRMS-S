<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates the payload the AcceptInvite wizard submits. Key names are
 * camelCase to match the frontend exactly — translation to column names happens
 * in OnboardingRepository, not here.
 */
class CompleteOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The invitation token is the credential; OnboardingService verifies it.
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],

            // The wizard enforces 8 characters and a confirmation match, but
            // that is a client-side convenience — an API caller can skip it, so
            // the real rule lives here.
            'password' => ['required', 'confirmed', Password::min(8)],

            'personal' => ['required', 'array'],
            'personal.phone' => ['nullable', 'string', 'max:40'],
            'personal.cnic' => ['nullable', 'string', 'max:40'],
            'personal.address' => ['nullable', 'string', 'max:1000'],
            'personal.emergencyContact' => ['nullable', 'string', 'max:255'],
            'personal.bankAccount' => ['nullable', 'string', 'max:64'],
            'personal.taxNumber' => ['nullable', 'string', 'max:64'],

            'technical' => ['required', 'array'],
            'technical.designation' => ['nullable', 'string', 'max:255'],
            'technical.primaryStack' => ['nullable', 'string', 'max:255'],
            // Numeric string from the form; 60 is a sanity ceiling rather than
            // a business rule.
            'technical.experienceYears' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'technical.employmentType' => [
                'nullable',
                Rule::in(['Full-time', 'Part-time', 'Contract', 'Internship']),
            ],
            'technical.probationStatus' => [
                'nullable',
                Rule::in(['Probation', 'Confirmed']),
            ],
            'technical.githubUrl' => ['nullable', 'url', 'max:255'],
            'technical.portfolioUrl' => ['nullable', 'url', 'max:255'],
            'technical.linkedinUrl' => ['nullable', 'url', 'max:255'],

            'technical.stacks' => ['sometimes', 'array', 'max:50'],
            'technical.stacks.*' => ['string', 'max:255'],

            'technical.skills' => ['sometimes', 'array', 'max:100'],
            'technical.skills.*' => ['string', 'max:255'],

            // skill => level. Levels are optional: the wizard collects skills
            // without them, and the column is nullable to match.
            'technical.skillLevels' => ['sometimes', 'array'],
            'technical.skillLevels.*' => [
                Rule::in(['Beginner', 'Intermediate', 'Advanced', 'Expert']),
            ],

            'technical.currentlyLearning' => ['sometimes', 'array', 'max:50'],
            'technical.currentlyLearning.*' => ['string', 'max:255'],

            // Files are optional at this stage — HR chases missing paperwork
            // afterwards via employee_documents.status.
            'documents' => ['sometimes', 'array'],
            'documents.photo' => ['nullable', 'file', 'image', 'max:5120'],
            'documents.cv' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'documents.cnicFront' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents.cnicBack' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents.education' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents.experience' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'The two passwords do not match.',
            'token.size' => 'This invitation link is not valid.',
        ];
    }
}

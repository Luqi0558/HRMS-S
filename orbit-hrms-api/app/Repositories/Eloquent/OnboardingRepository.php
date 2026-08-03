<?php

namespace App\Repositories\Eloquent;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Repositories\Contracts\OnboardingRepositoryInterface;

class OnboardingRepository implements OnboardingRepositoryInterface
{
    public function savePersonal(Employee $employee, array $personal): void
    {
        // updateOrCreate keyed on employee_id: the wizard and the Profile
        // screen both write here, and the second must update rather than
        // violate the one-to-one unique index.
        $employee->profile()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'phone' => $personal['phone'] ?? null,
                'cnic' => $personal['cnic'] ?? null,
                'address' => $personal['address'] ?? null,
                'emergency_contact' => $personal['emergencyContact'] ?? null,
                'bank_account' => $personal['bankAccount'] ?? null,
                'tax_number' => $personal['taxNumber'] ?? null,
            ]
        );
    }

    public function saveTechnical(Employee $employee, array $technical): void
    {
        $employee->technicalProfile()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'designation' => $technical['designation'] ?? null,
                'primary_stack' => $technical['primaryStack'] ?? null,
                // '' from an untouched form input must become NULL, not 0.
                'experience_years' => ($technical['experienceYears'] ?? '') === ''
                    ? null
                    : $technical['experienceYears'],
                'employment_type' => $technical['employmentType'] ?? null,
                'probation_status' => $technical['probationStatus'] ?? null,
                'github_url' => $technical['githubUrl'] ?? null,
                'portfolio_url' => $technical['portfolioUrl'] ?? null,
                'linkedin_url' => $technical['linkedinUrl'] ?? null,
                'submitted_at' => $technical['submittedAt'] ?? null,
            ]
        );
    }

    public function syncStacks(Employee $employee, array $stacks): void
    {
        // Delete-then-insert: the frontend sends the complete set every save,
        // so a diff would be more code for the same result. Both statements
        // run inside the caller's transaction.
        $employee->stacks()->delete();

        $rows = collect($stacks)
            ->filter(fn ($stack) => filled($stack))
            ->unique()
            ->map(fn ($stack) => [
                'employee_id' => $employee->id,
                'stack' => $stack,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows !== []) {
            $employee->stacks()->insert($rows);
        }
    }

    public function syncSkills(Employee $employee, array $skills, array $levels = [], array $currentlyLearning = []): void
    {
        $employee->skills()->delete();

        // The frontend keeps skills, skillLevels and currentlyLearning as three
        // parallel structures; this collapses them into one row per skill.
        // Union so a "currently learning" entry missing from `skills` still
        // gets a row rather than being dropped.
        $all = collect($skills)
            ->merge($currentlyLearning)
            ->filter(fn ($skill) => filled($skill))
            ->unique();

        $learning = collect($currentlyLearning)->flip();

        $rows = $all->map(fn ($skill) => [
            'employee_id' => $employee->id,
            'skill' => $skill,
            // Null when the wizard supplied no level — never defaulted, so we
            // do not fabricate a proficiency the employee did not claim.
            'level' => $levels[$skill] ?? null,
            'is_learning' => $learning->has($skill),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            $employee->skills()->insert($rows);
        }
    }

    public function attachDocument(Employee $employee, string $type, ?int $mediaId, string $status = 'submitted'): void
    {
        // Keyed on (employee_id, type) to match the unique index: re-uploading
        // a CV replaces the file on the existing row instead of adding a second
        // row that would make "the current CV" ambiguous.
        $employee->documents()->updateOrCreate(
            ['employee_id' => $employee->id, 'type' => $type],
            [
                'media_id' => $mediaId,
                'status' => $status,
                // A replacement resets any previous verification: HR has not
                // seen this file yet.
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ]
        );
    }

    public function saveDocuments(Employee $employee, array $files): void
    {
        foreach ($files as $key => $file) {
            // Ignore unknown keys rather than trusting request input to name a
            // column value — `type` is an enum and a bad value would throw.
            $type = EmployeeDocument::TYPE_MAP[$key] ?? null;
            if (! $type || ! $file) {
                continue;
            }

            // Stored under the employee's id with a generated name: the
            // original filename is attacker-controlled and is kept only as a
            // display label.
            $path = $file->store("employees/{$employee->id}/documents", 'local');

            $employee->documents()->updateOrCreate(
                ['employee_id' => $employee->id, 'type' => $type],
                [
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size_bytes' => $file->getSize(),
                    'mime_type' => $file->getClientMimeType(),
                    'status' => 'submitted',
                    // A replacement resets verification — HR has not seen this
                    // file yet.
                    'verified_by' => null,
                    'verified_at' => null,
                    'rejection_reason' => null,
                ]
            );
        }
    }

    /** @return array<string, string> Frontend upload key => document type. */
    public function documentTypeMap(): array
    {
        return EmployeeDocument::TYPE_MAP;
    }
}

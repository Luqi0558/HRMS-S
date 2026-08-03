<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rebuilds the frontend's `onboarding.technical` shape from the normalised
 * tables: employee_stacks and employee_skills collapse back into the three
 * parallel structures the SPA expects (stacks, skills, skillLevels,
 * currentlyLearning).
 */
class EmployeeTechnicalProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Stacks and skills belong to the employee rather than this row, and
        // EmployeeResource eager-loads them there. relationLoaded() guards
        // against firing a query per field if a caller forgets.
        $stacks = $this->relationLoaded('stacks') ? $this->stacks : collect();
        $skills = $this->relationLoaded('skills') ? $this->skills : collect();

        return [
            'designation' => $this->designation,
            'primaryStack' => $this->primary_stack,
            // Cast to string: the frontend binds this to a text input, and a
            // numeric 0 would render where an empty field is expected.
            'experienceYears' => $this->experience_years !== null
                ? (string) $this->experience_years
                : '',
            'employmentType' => $this->employment_type,
            'probationStatus' => $this->probation_status,
            'githubUrl' => $this->github_url,
            'portfolioUrl' => $this->portfolio_url,
            'linkedinUrl' => $this->linkedin_url,
            'submittedAt' => $this->submitted_at?->toIso8601String(),

            'stacks' => $stacks->pluck('stack')->values(),
            'skills' => $skills->pluck('skill')->values(),
            // skill => level, omitting skills with no level recorded.
            'skillLevels' => $skills->whereNotNull('level')
                ->pluck('level', 'skill'),
            'currentlyLearning' => $skills->where('is_learning', true)
                ->pluck('skill')->values(),
        ];
    }
}

<?php

namespace App\Repositories\Contracts;

use App\Models\Employee;

interface OnboardingRepositoryInterface
{
    /** Mirrors the frontend's onboarding.personal object. */
    public function savePersonal(Employee $employee, array $personal): void;

    /** Mirrors onboarding.technical, including the stacks/skills arrays. */
    public function saveTechnical(Employee $employee, array $technical): void;

    /**
     * Replaces the employee's stacks with exactly this set.
     * @param array<int, string> $stacks
     */
    public function syncStacks(Employee $employee, array $stacks): void;

    /**
     * @param array<int, string>  $skills
     * @param array<string, string> $levels        skill => level
     * @param array<int, string>  $currentlyLearning
     */
    public function syncSkills(Employee $employee, array $skills, array $levels = [], array $currentlyLearning = []): void;

    /** Upserts one document slot; re-uploading replaces the file on the same row. */
    public function attachDocument(Employee $employee, string $type, ?int $mediaId, string $status = 'submitted'): void;

    /**
     * Stores uploaded files against their slots.
     *
     * @param array<string, \Illuminate\Http\UploadedFile> $files keyed by the
     *        frontend's camelCase upload name (cv, cnicFront, ...)
     */
    public function saveDocuments(Employee $employee, array $files): void;
}

<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

class EmployeeDocumentReviewTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function hrToken(): string
    {
        $user = new User();
        $user->fill([
            'name' => 'HR Reviewer',
            'email' => 'reviewer@test.com',
            'password' => 'password123',
        ]);
        $user->role = 'hr';
        $user->status = 'active';
        $user->save();

        return $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ], $this->tenantHeaders())->json('token');
    }

    public function test_hr_document_verification_is_persisted(): void
    {
        $employee = Employee::create([
            'employee_code' => 'ORB-0001',
            'name' => 'Ayesha Khan',
            'email' => 'ayesha@test.com',
            'status' => 'active',
        ]);
        $document = EmployeeDocument::create([
            'employee_id' => $employee->id,
            'type' => 'cv',
            'status' => 'submitted',
        ]);

        $this->postJson("/api/employees/{$employee->id}/document-review", [
            'action' => 'verified',
        ], $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->hrToken(),
        ]))
            ->assertOk()
            ->assertJsonPath('employee.onboardingReview.status', 'verified');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'onboarding_review_status' => 'verified',
        ]);
        $this->assertDatabaseHas('employee_documents', [
            'id' => $document->id,
            'status' => 'verified',
        ]);
    }

    public function test_correction_request_is_persisted_with_its_reason(): void
    {
        $employee = Employee::create([
            'employee_code' => 'ORB-0001',
            'name' => 'Ayesha Khan',
            'email' => 'ayesha@test.com',
            'status' => 'active',
        ]);

        $this->postJson("/api/employees/{$employee->id}/document-review", [
            'action' => 'correction_requested',
            'note' => 'Please upload a clearer CNIC copy.',
        ], $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->hrToken(),
        ]))
            ->assertOk()
            ->assertJsonPath('employee.onboardingReview.status', 'correction_requested')
            ->assertJsonPath('employee.onboardingReview.correctionNote', 'Please upload a clearer CNIC copy.');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'onboarding_review_status' => 'correction_requested',
            'onboarding_correction_note' => 'Please upload a clearer CNIC copy.',
        ]);
    }
}

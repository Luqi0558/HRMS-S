<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeTechnicalProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

/**
 * Self-service profile editing.
 *
 * The point of these routes is that they work for someone holding no Employees
 * permission at all, so an ordinary employee is the subject of nearly every
 * test here — using HR would pass for the wrong reason.
 */
class ProfileTest extends TenantTestCase
{
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->employee = $this->makeEmployee('worker@test.com', 'employee');
    }

    private function makeEmployee(string $email, string $role): Employee
    {
        $user = new User();
        $user->fill(['name' => $email, 'email' => $email, 'password' => 'password123']);
        $user->role = $role;
        $user->status = 'active';
        $user->save();

        return Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'ORB-'.substr(md5($email), 0, 6),
            'name' => 'Original Name',
            'email' => $email,
            'department' => 'Engineering',
            'designation' => 'Junior Developer',
            'status' => 'active',
        ]);
    }

    private function tokenFor(string $email): string
    {
        return $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'password123',
        ], $this->tenantHeaders())->json('token');
    }

    private function authHeaders(string $email): array
    {
        return $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor($email),
        ]);
    }

    public function test_an_employee_can_read_their_own_profile(): void
    {
        // The same person gets 403 from /api/employees, which is exactly why
        // this route exists.
        $this->getJson('/api/employees', $this->authHeaders('worker@test.com'))
            ->assertForbidden();

        $this->getJson('/api/profile', $this->authHeaders('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('employee.id', $this->employee->id)
            ->assertJsonPath('employee.email', 'worker@test.com');
    }

    public function test_an_employee_can_update_their_own_personal_details(): void
    {
        $this->patchJson('/api/profile', [
            'name' => 'Corrected Name',
            'personal' => [
                'phone' => '0300-1234567',
                'address' => '12 Mall Road, Lahore',
                'bankAccount' => 'PK00-1111-2222',
            ],
        ], $this->authHeaders('worker@test.com'))->assertOk();

        $this->employee->refresh();
        $this->assertSame('Corrected Name', $this->employee->name);

        $profile = $this->employee->profile;
        $this->assertSame('0300-1234567', $profile->phone);
        $this->assertSame('12 Mall Road, Lahore', $profile->address);
        // Encrypted at rest, so this also proves the cast round-trips.
        $this->assertSame('PK00-1111-2222', $profile->bank_account);
    }

    /**
     * The fields an employee must not be able to award themselves. They are
     * absent from UpdateProfileRequest, so validation drops them silently
     * rather than erroring — what matters is that the stored values are
     * unchanged.
     */
    public function test_hr_controlled_fields_cannot_be_set_through_the_profile_route(): void
    {
        $this->patchJson('/api/profile', [
            'email' => 'attacker@test.com',
            'department' => 'Finance',
            'status' => 'terminated',
            'technical' => [
                'designation' => 'Chief Executive Officer',
                'probationStatus' => 'Confirmed',
                'employmentType' => 'Full-time',
                // A legitimate edit in the same payload, so the request is not
                // rejected outright and the omissions are genuinely tested.
                'primaryStack' => 'Laravel',
            ],
        ], $this->authHeaders('worker@test.com'))->assertOk();

        $this->employee->refresh();
        $this->assertSame('worker@test.com', $this->employee->email);
        $this->assertSame('Engineering', $this->employee->department);
        $this->assertSame('active', $this->employee->status);
        $this->assertSame('Junior Developer', $this->employee->designation);

        $technical = $this->employee->technicalProfile;
        $this->assertSame('Laravel', $technical->primary_stack);
        $this->assertNull($technical->designation);
        $this->assertNull($technical->probation_status);
    }

    /**
     * The trap in reusing OnboardingRepository::saveTechnical(): it writes every
     * column with `?? null`, which is right for the invite wizard (whole form,
     * one submit) and destructive here (self-editable subset only). Without the
     * merge in ProfileService, editing a GitHub URL would blank the designation
     * and probation status HR had set.
     */
    public function test_editing_one_technical_field_preserves_hr_set_values(): void
    {
        EmployeeTechnicalProfile::create([
            'employee_id' => $this->employee->id,
            'designation' => 'Senior Developer',
            'employment_type' => 'Full-time',
            'probation_status' => 'Confirmed',
            'primary_stack' => 'Vue',
        ]);

        $this->patchJson('/api/profile', [
            'technical' => ['githubUrl' => 'https://github.com/someone'],
        ], $this->authHeaders('worker@test.com'))->assertOk();

        $technical = $this->employee->technicalProfile()->first();
        $this->assertSame('https://github.com/someone', $technical->github_url);
        $this->assertSame('Senior Developer', $technical->designation);
        $this->assertSame('Full-time', $technical->employment_type);
        $this->assertSame('Confirmed', $technical->probation_status);
    }

    public function test_the_profile_routes_reject_an_unauthenticated_caller(): void
    {
        $this->getJson('/api/profile', $this->tenantHeaders())->assertUnauthorized();
        $this->patchJson('/api/profile', ['name' => 'Nobody'], $this->tenantHeaders())
            ->assertUnauthorized();
    }

    public function test_a_blank_name_is_rejected(): void
    {
        $this->patchJson('/api/profile', [
            'name' => '   ',
        ], $this->authHeaders('worker@test.com'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }
}

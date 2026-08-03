<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class OnboardingTest extends TenantTestCase
{
    /** @return array{0: Employee, 1: string} */
    private function invite(string $email = 'hina@test.com'): array
    {
        $result = app(InvitationService::class)->inviteEmployee([
            'name' => 'Hina Malik',
            'email' => $email,
            'designation' => 'QA Engineer',
            'department' => 'QA',
        ], null);

        return [$result['employee'], $result['token']];
    }

    private function validPayload(string $token): array
    {
        return [
            'token' => $token,
            'password' => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
            'personal' => [
                'phone' => '0300-1234567',
                'cnic' => '42101-1234567-1',
                'emergencyContact' => 'Sara 0311-7654321',
            ],
            'technical' => [
                'designation' => 'QA Engineer',
                'experienceYears' => '2.5',
                'stacks' => ['QA Automation'],
                'skills' => ['Selenium', 'SQL'],
                'skillLevels' => ['Selenium' => 'Advanced'],
                'currentlyLearning' => ['Docker'],
            ],
        ];
    }

    public function test_an_invitation_stores_only_the_token_hash(): void
    {
        [, $token] = $this->invite();

        $this->assertSame(64, strlen($token));
        // The raw token must not be recoverable from the database.
        $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);
        $this->assertDatabaseHas('invitations', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_a_valid_token_resolves_to_its_invitee(): void
    {
        [$employee, $token] = $this->invite();

        $this->postJson('/api/onboarding/resolve', ['token' => $token], $this->tenantHeaders())
            ->assertOk()
            ->assertJsonPath('employee.name', $employee->name)
            ->assertJsonPath('invitation.email', $employee->email);
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->postJson('/api/onboarding/resolve', [
            'token' => str_repeat('a', 64),
        ], $this->tenantHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_invitation');
    }

    public function test_an_expired_token_is_rejected(): void
    {
        [$employee, $token] = $this->invite();

        Invitation::where('employee_id', $employee->id)
            ->update(['expires_at' => now()->subDay()]);

        $this->postJson('/api/onboarding/resolve', ['token' => $token], $this->tenantHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_invitation');
    }

    public function test_completing_onboarding_activates_the_account(): void
    {
        [$employee, $token] = $this->invite();

        $this->postJson('/api/onboarding/complete', $this->validPayload($token), $this->tenantHeaders())
            ->assertCreated()
            ->assertJsonPath('employee.status', 'active');

        $employee->refresh();
        $this->assertSame('active', $employee->status);
        $this->assertNotNull($employee->user_id);
        $this->assertSame('active', $employee->user->status);
        $this->assertNotNull($employee->user->password);
    }

    public function test_the_new_account_can_immediately_sign_in(): void
    {
        [, $token] = $this->invite();

        $this->postJson('/api/onboarding/complete', $this->validPayload($token), $this->tenantHeaders())
            ->assertCreated();

        $this->postJson('/api/login', [
            'email' => 'hina@test.com',
            'password' => 'sup3rSecret!',
        ], $this->tenantHeaders())->assertOk();
    }

    /** A forwarded or resent link must not let a second person onboard. */
    public function test_an_invitation_cannot_be_used_twice(): void
    {
        [, $token] = $this->invite();

        $this->postJson('/api/onboarding/complete', $this->validPayload($token), $this->tenantHeaders())
            ->assertCreated();

        $this->postJson('/api/onboarding/complete', $this->validPayload($token), $this->tenantHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_invitation');
    }

    public function test_the_three_parallel_skill_structures_are_flattened_correctly(): void
    {
        [$employee, $token] = $this->invite();

        $this->postJson('/api/onboarding/complete', $this->validPayload($token), $this->tenantHeaders())
            ->assertCreated();

        $skills = $employee->skills()->get()->keyBy('skill');

        // A level supplied by the form is stored...
        $this->assertSame('Advanced', $skills['Selenium']->level);
        // ...and one that was not stays null rather than being defaulted, so we
        // never claim a proficiency the employee did not state.
        $this->assertNull($skills['SQL']->level);
        // currentlyLearning entries become rows even though they are not in
        // the `skills` array.
        $this->assertTrue((bool) $skills['Docker']->is_learning);
        $this->assertFalse((bool) $skills['Selenium']->is_learning);

        $this->assertSame(['QA Automation'], $employee->stacks()->pluck('stack')->all());
    }

    public function test_sensitive_personal_data_is_encrypted_at_rest(): void
    {
        [$employee, $token] = $this->invite();

        $this->postJson('/api/onboarding/complete', $this->validPayload($token), $this->tenantHeaders())
            ->assertCreated();

        // Readable through the model...
        $this->assertSame('42101-1234567-1', $employee->profile->cnic);
        // ...but not in the column.
        $raw = DB::table('employee_profiles')->where('employee_id', $employee->id)->value('cnic');
        $this->assertNotSame('42101-1234567-1', $raw);
        $this->assertStringNotContainsString('42101', $raw);
    }

    public function test_a_weak_or_unconfirmed_password_is_rejected(): void
    {
        [, $token] = $this->invite();

        $payload = $this->validPayload($token);
        $payload['password'] = 'short';
        $payload['password_confirmation'] = 'different';

        $this->postJson('/api/onboarding/complete', $payload, $this->tenantHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * Onboarding writes across five tables. A failure part-way must leave none
     * of it behind — an invitation consumed with no profile, or a password set
     * with no employee record, are both worse than a clean failure.
     */
    public function test_a_failure_rolls_back_every_write(): void
    {
        [$employee, $token] = $this->invite();

        $payload = $this->validPayload($token);
        // Longer than the column allows, so the insert fails mid-transaction.
        $payload['technical']['stacks'] = [str_repeat('x', 300)];

        try {
            $this->postJson('/api/onboarding/complete', $payload, $this->tenantHeaders());
        } catch (\Throwable) {
            // The exception itself is not the point; the state afterwards is.
        }

        $employee->refresh();
        $this->assertSame('invited', $employee->status, 'employee should not be activated');
        $this->assertDatabaseCount('employee_profiles', 0);
        $this->assertDatabaseCount('employee_stacks', 0);
        $this->assertNull(
            Invitation::where('employee_id', $employee->id)->value('accepted_at'),
            'the invitation must remain usable after a failed attempt'
        );
    }

    public function test_each_employee_gets_a_distinct_code(): void
    {
        [$first] = $this->invite('one@test.com');
        [$second] = $this->invite('two@test.com');

        $this->assertNotSame($first->employee_code, $second->employee_code);
        $this->assertSame('ORB-0001', $first->employee_code);
        $this->assertSame('ORB-0002', $second->employee_code);
    }

    public function test_resending_an_invitation_invalidates_the_previous_link(): void
    {
        [$employee, $firstToken] = $this->invite();

        app(\App\Services\InvitationService::class)->resendInvitation($employee, null);

        $this->postJson('/api/onboarding/resolve', ['token' => $firstToken], $this->tenantHeaders())
            ->assertStatus(422);
    }
}

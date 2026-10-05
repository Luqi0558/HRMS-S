<?php

namespace Tests\Feature;

use App\Mail\EmployeeInvitationMail;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

class EmployeeInvitationTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
    }

    private function signIn(string $role): string
    {
        $user = new User();
        $user->fill([
            'name' => 'Test '.$role,
            'email' => $role.'@test.com',
            'password' => 'password123',
        ]);
        $user->role = $role;
        $user->status = 'active';
        $user->save();

        return $this->postJson('/api/login', [
            'email' => $role.'@test.com',
            'password' => 'password123',
        ], $this->tenantHeaders())->json('token');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Hina Malik',
            'email' => 'hina@test.com',
            'designation' => 'Software Engineer',
            'department' => 'Engineering',
        ], $overrides);
    }

    public function test_inviting_requires_authentication(): void
    {
        $this->postJson('/api/employees/invite', $this->payload(), $this->tenantHeaders())
            ->assertStatus(401);
    }

    /**
     * The browser-side router guard is UX only. This is the check that
     * actually stops an employee from inviting staff.
     */
    public function test_an_employee_may_not_invite_staff(): void
    {
        $this->postJson('/api/employees/invite', $this->payload(), $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->signIn('employee'),
        ]))
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_hr_can_invite_an_employee(): void
    {
        $response = $this->postJson('/api/employees/invite', $this->payload(), $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->signIn('hr'),
        ]));

        $response->assertCreated()
            ->assertJsonPath('employee.email', 'hina@test.com')
            ->assertJsonPath('employee.status', 'invited')
            ->assertJsonPath('emailQueued', true)
            ->assertJsonStructure(['invite' => ['token', 'expiresAt', 'path']]);

        Mail::assertQueued(EmployeeInvitationMail::class, function (EmployeeInvitationMail $mail): bool {
            return $mail->hasTo('hina@test.com')
                && str_contains($mail->inviteUrl, '/accept-invite?token=');
        });

        $this->assertDatabaseHas('employees', [
            'email' => 'hina@test.com',
            'status' => 'invited',
        ]);

        // Only the hash is persisted; the raw token exists in the response and
        // nowhere else.
        $token = $response->json('invite.token');
        $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);
        $this->assertDatabaseHas('invitations', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_the_issued_token_completes_onboarding(): void
    {
        $token = $this->postJson('/api/employees/invite', $this->payload(), $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->signIn('hr'),
        ]))->json('invite.token');

        // No auth header: the invitee is not signed in.
        $this->postJson('/api/onboarding/resolve', ['token' => $token], $this->tenantHeaders())
            ->assertOk()
            ->assertJsonPath('employee.name', 'Hina Malik');
    }

    public function test_the_inviter_is_recorded(): void
    {
        $token = $this->signIn('hr');
        $hrId = User::where('email', 'hr@test.com')->value('id');

        $this->postJson('/api/employees/invite', $this->payload(), $this->tenantHeaders([
            'Authorization' => "Bearer {$token}",
        ]))->assertCreated();

        $this->assertDatabaseHas('invitations', ['invited_by' => $hrId]);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        $auth = $this->tenantHeaders(['Authorization' => 'Bearer '.$this->signIn('hr')]);

        Employee::create([
            'employee_code' => 'ORB-0001',
            'name' => 'Existing',
            'email' => 'hina@test.com',
            'status' => 'active',
        ]);

        $this->postJson('/api/employees/invite', $this->payload(), $auth)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_invite_validation_requires_a_name_and_email(): void
    {
        $this->postJson('/api/employees/invite', [], $this->tenantHeaders([
            'Authorization' => 'Bearer '.$this->signIn('hr'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    }

    /**
     * With no mailer that can actually deliver, a resend is refused rather
     * than revoking the invitee's only working link for one nobody receives.
     */
    public function test_a_resend_is_refused_when_email_cannot_be_delivered(): void
    {
        config(['mail.default' => 'array']);
        $auth = $this->tenantHeaders(['Authorization' => 'Bearer '.$this->signIn('hr')]);

        $first = $this->postJson('/api/employees/invite', $this->payload(), $auth);

        $this->postJson("/api/employees/{$first->json('employee.id')}/resend-invite", [], $auth)
            ->assertStatus(409)
            ->assertJsonPath('error', 'mail_delivery_not_configured');

        // The original link still works.
        $this->postJson('/api/onboarding/resolve', ['token' => $first->json('invite.token')], $this->tenantHeaders())
            ->assertOk();
    }

    public function test_resending_replaces_the_previous_link(): void
    {
        // A real transport, so the resend goes ahead. Mail::fake() in setUp
        // still keeps anything from leaving the test.
        config(['mail.default' => 'smtp']);
        $auth = $this->tenantHeaders(['Authorization' => 'Bearer '.$this->signIn('hr')]);

        $first = $this->postJson('/api/employees/invite', $this->payload(), $auth);
        $firstToken = $first->json('invite.token');
        $employeeId = $first->json('employee.id');

        $secondToken = $this->postJson("/api/employees/{$employeeId}/resend-invite", [], $auth)
            ->assertOk()
            ->json('invite.token');

        $this->assertNotSame($firstToken, $secondToken);

        // The superseded link must stop working, or a forwarded copy of the
        // first email stays usable.
        $this->postJson('/api/onboarding/resolve', ['token' => $firstToken], $this->tenantHeaders())
            ->assertStatus(422);

        $this->postJson('/api/onboarding/resolve', ['token' => $secondToken], $this->tenantHeaders())
            ->assertOk();
    }

    public function test_resending_to_an_onboarded_employee_is_refused(): void
    {
        $auth = $this->tenantHeaders(['Authorization' => 'Bearer '.$this->signIn('hr')]);

        $employee = Employee::create([
            'employee_code' => 'ORB-0001',
            'name' => 'Already Here',
            'email' => 'done@test.com',
            'status' => 'active',
        ]);

        $this->postJson("/api/employees/{$employee->id}/resend-invite", [], $auth)
            ->assertStatus(422)
            ->assertJsonPath('error', 'already_onboarded');
    }
}

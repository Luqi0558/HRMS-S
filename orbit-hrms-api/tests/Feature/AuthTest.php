<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Tests\TenantTestCase;

class AuthTest extends TenantTestCase
{
    private function makeUser(array $overrides = []): User
    {
        $user = new User();
        $user->fill([
            'name' => $overrides['name'] ?? 'Ayesha Khan',
            'email' => $overrides['email'] ?? 'ayesha@test.com',
            'password' => $overrides['password'] ?? 'password123',
        ]);
        $user->role = $overrides['role'] ?? 'employee';
        $user->status = $overrides['status'] ?? 'active';
        $user->save();

        return $user;
    }

    public function test_a_user_with_valid_credentials_receives_a_token(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/login', [
            'email' => 'ayesha@test.com',
            'password' => 'password123',
        ], $this->tenantHeaders());

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'status']])
            ->assertJsonPath('user.email', 'ayesha@test.com');

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_records_the_sign_in_time(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->last_login_at);

        $this->postJson('/api/login', [
            'email' => 'ayesha@test.com',
            'password' => 'password123',
        ], $this->tenantHeaders())->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->makeUser();

        $this->postJson('/api/login', [
            'email' => 'ayesha@test.com',
            'password' => 'not-the-password',
        ], $this->tenantHeaders())
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_credentials');
    }

    /**
     * The two failures must be indistinguishable. If an unknown email produced
     * a different message or status, the login form would confirm which
     * addresses have accounts.
     */
    public function test_an_unknown_email_fails_identically_to_a_wrong_password(): void
    {
        $this->makeUser();

        $wrongPassword = $this->postJson('/api/login', [
            'email' => 'ayesha@test.com',
            'password' => 'not-the-password',
        ], $this->tenantHeaders());

        $unknownEmail = $this->postJson('/api/login', [
            'email' => 'nobody@test.com',
            'password' => 'not-the-password',
        ], $this->tenantHeaders());

        $this->assertSame($wrongPassword->status(), $unknownEmail->status());
        $this->assertSame($wrongPassword->json('message'), $unknownEmail->json('message'));
        $this->assertSame($wrongPassword->json('error'), $unknownEmail->json('error'));
    }

    public function test_a_blocked_account_cannot_sign_in(): void
    {
        $this->makeUser(['status' => 'blocked']);

        $this->postJson('/api/login', [
            'email' => 'ayesha@test.com',
            'password' => 'password123',
        ], $this->tenantHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error', 'account_not_active');
    }

    /**
     * An invited user exists with no password set. Sign-in must be refused on
     * `status`, never on password presence — otherwise a null password could
     * be treated as "no check required".
     */
    public function test_an_invited_account_without_a_password_cannot_sign_in(): void
    {
        $user = new User();
        $user->fill(['name' => 'Hina', 'email' => 'hina@test.com']);
        $user->role = 'employee';
        $user->status = 'invited';
        $user->save();

        $this->assertNull($user->password);

        $this->postJson('/api/login', [
            'email' => 'hina@test.com',
            'password' => '',
        ], $this->tenantHeaders())->assertStatus(422);

        $this->postJson('/api/login', [
            'email' => 'hina@test.com',
            'password' => 'anything',
        ], $this->tenantHeaders())->assertStatus(401);
    }

    public function test_login_requires_an_email_and_password(): void
    {
        $this->postJson('/api/login', [], $this->tenantHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_the_me_endpoint_requires_a_token(): void
    {
        $this->getJson('/api/me', $this->tenantHeaders())
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }

    public function test_the_me_endpoint_returns_the_signed_in_user_with_their_employee_record(): void
    {
        $user = $this->makeUser();
        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'ORB-0001',
            'name' => $user->name,
            'email' => $user->email,
            'status' => 'active',
        ]);

        $token = $this->postJson('/api/login', [
            'email' => 'ayesha@test.com',
            'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        $this->getJson('/api/me', $this->tenantHeaders([
            'Authorization' => "Bearer {$token}",
        ]))
            ->assertOk()
            ->assertJsonPath('user.email', 'ayesha@test.com')
            ->assertJsonPath('user.employee.employeeCode', 'ORB-0001');
    }

    public function test_logging_out_revokes_only_the_current_token(): void
    {
        $this->makeUser();

        $headers = $this->tenantHeaders();
        $first = $this->postJson('/api/login', [
            'email' => 'ayesha@test.com', 'password' => 'password123',
        ], $headers)->json('token');
        $second = $this->postJson('/api/login', [
            'email' => 'ayesha@test.com', 'password' => 'password123',
        ], $headers)->json('token');

        $this->postJson('/api/logout', [], $this->tenantHeaders([
            'Authorization' => "Bearer {$first}",
        ]))->assertOk();

        // Asserted against the database rather than a follow-up request: within
        // one test process Laravel keeps the already-resolved user on the auth
        // guard, so a second call with the dead token still succeeds even
        // though the token row is gone. Over HTTP (verified manually) it 401s.
        $firstId = (int) explode('|', $first)[0];
        $secondId = (int) explode('|', $second)[0];

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstId]);
        // Signing out on one device must not sign the user out on others.
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $secondId]);
    }

    public function test_an_unknown_tenant_is_rejected(): void
    {
        $this->postJson('/api/login', [
            'email' => 'ayesha@test.com',
            'password' => 'password123',
        ], ['Accept' => 'application/json', 'X-Tenant' => 'no-such-tenant'])
            ->assertStatus(404)
            ->assertJsonPath('error', 'tenant_not_identified');
    }
}

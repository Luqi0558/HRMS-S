<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ModulePayment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\ProjectService;
use Database\Seeders\RolePermissionSeeder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TenantTestCase;

/**
 * Client payments and commissions.
 *
 * Payments had a table and a model but no way to write a row: the Finance
 * page pushed them into browser state, so they — and the commission each one
 * earned — disappeared on refresh. These pin the API that replaced that.
 */
class FinanceTest extends TenantTestCase
{
    private Employee $admin;
    private Employee $manager;
    private Employee $otherManager;
    private Employee $developer;
    private ProjectModule $module;
    private ProjectModule $otherModule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->makeEmployee('admin@test.com', 'super_admin');
        $this->manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->otherManager = $this->makeEmployee('other@test.com', 'manager');
        $this->developer = $this->makeEmployee('dev@test.com', 'employee');

        $this->module = $this->moduleManagedBy($this->manager, 'delivery');
        $this->otherModule = $this->moduleManagedBy($this->otherManager, 'internal');
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
            'name' => $email,
            'email' => $email,
            'status' => 'active',
        ]);
    }

    private function moduleManagedBy(Employee $manager, string $slug): ProjectModule
    {
        $project = Project::create(['name' => ucfirst($slug), 'slug' => $slug, 'manager_id' => $manager->id]);

        return ProjectModule::create([
            'project_id' => $project->id,
            'name' => 'Payments',
            'slug' => "{$slug}-payments",
            'budget' => 100000,
            'commission_type' => 'percent',
            'commission_value' => 10,
            'commission_status' => 'pending',
        ]);
    }

    private function userOf(Employee $employee): User
    {
        return User::findOrFail($employee->user_id);
    }

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email, 'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        // See AssetTest: the guard otherwise keeps acting as the first user.
        $this->app['auth']->forgetGuards();

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function payment(array $overrides = []): array
    {
        return array_merge([
            'amount' => 25000,
            'date' => now()->toDateString(),
            'method' => 'Bank transfer',
            'payer' => 'Acme Ltd',
            'reference' => 'INV-001',
        ], $overrides);
    }

    // --- logging ---------------------------------------------------------

    public function test_a_manager_logs_a_payment_on_their_own_module(): void
    {
        $response = $this->postJson(
            "/api/modules/{$this->module->id}/payments",
            $this->payment(),
            $this->auth('manager@test.com'),
        )->assertCreated();

        $this->assertSame('25000.00', $response->json('payment.amount'));
        $this->assertSame($this->module->id, $response->json('payment.moduleId'));
        $this->assertSame('manager@test.com', $response->json('payment.loggedByName'));
        $this->assertDatabaseHas('module_payments', [
            'project_module_id' => $this->module->id,
            'logged_by' => $this->manager->id,
        ]);
    }

    /** 404, not 403: a 403 would confirm the module exists. */
    public function test_a_manager_cannot_log_against_another_managers_module(): void
    {
        $this->postJson(
            "/api/modules/{$this->otherModule->id}/payments",
            $this->payment(),
            $this->auth('manager@test.com'),
        )->assertNotFound();

        $this->assertSame(0, ModulePayment::count());
    }

    public function test_an_employee_has_no_access_to_payments(): void
    {
        $headers = $this->auth('dev@test.com');

        $this->getJson('/api/finance/payments', $headers)->assertForbidden();
        $this->postJson("/api/modules/{$this->module->id}/payments", $this->payment(), $headers)->assertForbidden();
    }

    public function test_a_payment_must_be_positive_dated_and_use_a_known_method(): void
    {
        $this->postJson(
            "/api/modules/{$this->module->id}/payments",
            $this->payment(['amount' => 0, 'date' => now()->addDay()->toDateString(), 'method' => 'Bitcoin']),
            $this->auth('manager@test.com'),
        )->assertUnprocessable()->assertJsonValidationErrors(['amount', 'date', 'method']);
    }

    public function test_logging_a_payment_is_audited_and_tells_the_super_admin(): void
    {
        app(FinanceService::class)->logPayment($this->userOf($this->manager), $this->module->id, $this->payment());

        $this->assertSame(
            ['Client payment logged'],
            Notification::where('employee_id', $this->admin->id)->pluck('title')->all(),
        );
        // The manager logged it themselves, so they are not told.
        $this->assertSame(0, Notification::where('employee_id', $this->manager->id)->count());
        $this->assertTrue(ActivityLog::where('action', 'payment logged')->exists());
    }

    // --- reading ---------------------------------------------------------

    public function test_a_manager_sees_only_payments_on_their_projects(): void
    {
        $finance = app(FinanceService::class);
        $finance->logPayment($this->userOf($this->admin), $this->module->id, $this->payment());
        $finance->logPayment($this->userOf($this->admin), $this->otherModule->id, $this->payment());

        $rows = $this->getJson('/api/finance/payments', $this->auth('manager@test.com'))
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->module->id, $rows[0]['moduleId']);
    }

    public function test_the_super_admin_sees_every_payment(): void
    {
        $finance = app(FinanceService::class);
        $finance->logPayment($this->userOf($this->manager), $this->module->id, $this->payment());
        $finance->logPayment($this->userOf($this->otherManager), $this->otherModule->id, $this->payment());

        $this->assertCount(2, $finance->payments($this->userOf($this->admin)));
    }

    // --- removing --------------------------------------------------------

    /**
     * A manager's commission is calculated from these payments, so they must
     * not be able to delete one. The row is soft-deleted, kept for audit.
     */
    public function test_only_the_super_admin_can_remove_a_payment(): void
    {
        $finance = app(FinanceService::class);
        $payment = $finance->logPayment($this->userOf($this->manager), $this->module->id, $this->payment());

        try {
            $finance->deletePayment($this->userOf($this->manager), $payment->id);
            $this->fail('A manager removed a payment.');
        } catch (AccessDeniedHttpException) {
            // expected
        }

        $finance->deletePayment($this->userOf($this->admin), $payment->id);

        $this->assertSoftDeleted('module_payments', ['id' => $payment->id]);
        $this->assertCount(0, $finance->payments($this->userOf($this->admin)));
        $this->assertTrue(ActivityLog::where('action', 'payment removed')->exists());
    }

    public function test_removing_a_missing_payment_is_a_404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        app(FinanceService::class)->deletePayment($this->userOf($this->admin), 999);
    }

    // --- commission ------------------------------------------------------

    public function test_approving_and_paying_a_commission_tells_the_manager(): void
    {
        $projects = app(ProjectService::class);

        $projects->decideModuleCommission($this->userOf($this->admin), $this->module->id, 'approved');
        $projects->decideModuleCommission($this->userOf($this->admin), $this->module->id, 'paid');

        $this->assertSame('paid', $this->module->fresh()->commission_status);
        $this->assertSame(
            ['Commission approved', 'Commission paid'],
            Notification::where('employee_id', $this->manager->id)->orderBy('id')->pluck('title')->all(),
        );
        $this->assertTrue(ActivityLog::where('action', 'commission paid')->exists());
    }
}

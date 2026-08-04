<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PettyCashExpense;
use App\Models\PettyCashTopUp;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

class PettyCashTest extends TenantTestCase
{
    private Employee $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->hr = $this->makeEmployee('hr@test.com', 'hr');
        $this->makeEmployee('worker@test.com', 'employee');
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

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email, 'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    /** Approved float, so there is money to spend against. */
    private function float(float $amount = 50000): PettyCashTopUp
    {
        return PettyCashTopUp::create([
            'amount' => $amount,
            'date' => now()->toDateString(),
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    // --- balance ---------------------------------------------------------

    public function test_the_balance_is_float_minus_spend(): void
    {
        $this->float(50000);
        PettyCashExpense::create([
            'amount' => 12000, 'date' => now()->toDateString(),
            'category' => 'Repairs', 'status' => 'recorded',
        ]);

        $this->getJson('/api/petty-cash', $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('summary.float', 50000)
            ->assertJsonPath('summary.spent', 12000)
            ->assertJsonPath('summary.balance', 38000);
    }

    /** A pending top-up is a request for money, not money in the tin. */
    public function test_a_pending_top_up_does_not_count_toward_the_float(): void
    {
        $this->float(50000);
        PettyCashTopUp::create([
            'amount' => 20000, 'date' => now()->toDateString(), 'status' => 'pending',
        ]);

        $this->getJson('/api/petty-cash', $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('summary.float', 50000);
    }

    /**
     * A rejected expense is the only one that does not count: refusing it
     * means the money is not owed, so it never left the tin. A pending one
     * still counts — the cash is gone whether or not paperwork has caught up.
     */
    public function test_only_rejected_spend_is_excluded_from_the_balance(): void
    {
        $this->float(50000);

        foreach ([['recorded', 1000], ['pending', 2000], ['approved', 3000], ['rejected', 9000]] as [$status, $amount]) {
            PettyCashExpense::create([
                'amount' => $amount, 'date' => now()->toDateString(),
                'category' => 'Office supplies', 'status' => $status,
            ]);
        }

        $this->getJson('/api/petty-cash', $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('summary.spent', 6000)
            ->assertJsonPath('summary.balance', 44000);
    }

    public function test_a_low_balance_is_flagged(): void
    {
        // lowBalanceThreshold defaults to 10000.
        $this->float(12000);
        PettyCashExpense::create([
            'amount' => 4000, 'date' => now()->toDateString(),
            'category' => 'Courier', 'status' => 'recorded',
        ]);

        $this->getJson('/api/petty-cash', $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('summary.balance', 8000)
            ->assertJsonPath('summary.isLow', true);
    }

    // --- expenses --------------------------------------------------------

    /** At or below the threshold, spend is simply recorded. */
    public function test_small_spend_is_recorded_without_approval(): void
    {
        $this->float();

        $this->postJson('/api/petty-cash/expenses', [
            'amount' => 2400,
            'category' => 'Office supplies',
            'payee' => 'Metro Stationers',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('expense.status', 'recorded');
    }

    /**
     * HR holds create but not approve, so its large spend waits for a super
     * admin — the separation the tin exists to provide.
     */
    public function test_spend_above_the_threshold_waits_for_sign_off(): void
    {
        $this->float();

        $this->postJson('/api/petty-cash/expenses', [
            'amount' => 12500,
            'category' => 'Repairs',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('expense.status', 'pending');
    }

    /** The threshold comes from settings, so changing it changes the rule. */
    public function test_raising_the_threshold_changes_what_needs_approval(): void
    {
        $this->float();
        app(SettingsService::class)->put('pettyCash', 'approvalThreshold', 20000);

        $this->postJson('/api/petty-cash/expenses', [
            'amount' => 12500,
            'category' => 'Repairs',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('expense.status', 'recorded');
    }

    /**
     * Spending more than the tin holds means the ledger no longer matches
     * reality.
     */
    public function test_spending_beyond_the_balance_is_refused(): void
    {
        $this->float(5000);

        $this->postJson('/api/petty-cash/expenses', [
            'amount' => 8000,
            'category' => 'Repairs',
        ], $this->auth('hr@test.com'))->assertStatus(409);

        $this->assertSame(0, PettyCashExpense::count());
    }

    public function test_an_employee_may_not_record_spend(): void
    {
        $this->float();

        $this->postJson('/api/petty-cash/expenses', [
            'amount' => 1000,
            'category' => 'Courier',
        ], $this->auth('worker@test.com'))->assertStatus(403);
    }

    public function test_hr_may_not_approve_its_own_large_spend(): void
    {
        $this->float();
        $expense = PettyCashExpense::create([
            'amount' => 12500, 'date' => now()->toDateString(),
            'category' => 'Repairs', 'status' => 'pending',
        ]);

        $this->postJson("/api/petty-cash/expenses/{$expense->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('hr@test.com'))->assertStatus(403);

        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_super_admin_approves_pending_spend(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $this->float();
        $expense = PettyCashExpense::create([
            'amount' => 12500, 'date' => now()->toDateString(),
            'category' => 'Repairs', 'status' => 'pending',
        ]);

        $this->postJson("/api/petty-cash/expenses/{$expense->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('boss@test.com'))
            ->assertOk()
            ->assertJsonPath('expense.status', 'approved');
    }

    /** Rejecting returns the money to the balance. */
    public function test_rejecting_spend_restores_the_balance(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $this->float(50000);
        $expense = PettyCashExpense::create([
            'amount' => 12500, 'date' => now()->toDateString(),
            'category' => 'Repairs', 'status' => 'pending',
        ]);

        $this->postJson("/api/petty-cash/expenses/{$expense->id}/decision", [
            'decision' => 'rejected',
            'comment' => 'Not a company cost.',
        ], $this->auth('boss@test.com'))->assertOk();

        $this->getJson('/api/petty-cash', $this->auth('boss@test.com'))
            ->assertJsonPath('summary.balance', 50000);
    }

    public function test_an_expense_cannot_be_decided_twice(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $this->float();
        $expense = PettyCashExpense::create([
            'amount' => 12500, 'date' => now()->toDateString(),
            'category' => 'Repairs', 'status' => 'pending',
        ]);

        $headers = $this->auth('boss@test.com');
        $this->postJson("/api/petty-cash/expenses/{$expense->id}/decision", ['decision' => 'approved'], $headers)
            ->assertOk();
        $this->postJson("/api/petty-cash/expenses/{$expense->id}/decision", ['decision' => 'rejected'], $headers)
            ->assertStatus(409);
    }

    public function test_a_receipt_is_stored_with_the_expense(): void
    {
        Storage::fake('local');
        $this->float();

        $this->postJson('/api/petty-cash/expenses', [
            'amount' => 2400,
            'category' => 'Office supplies',
            'receipt' => UploadedFile::fake()->image('bill.png'),
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('expense.hasReceipt', true);

        Storage::disk('local')->assertExists(PettyCashExpense::first()->receipt_path);
    }

    // --- top-ups ---------------------------------------------------------

    /** HR cannot approve, so its top-up is a request. */
    public function test_hr_top_up_waits_for_approval(): void
    {
        $this->postJson('/api/petty-cash/top-ups', [
            'amount' => 20000,
            'reference' => 'Mid-month top-up',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('topUp.status', 'pending');

        // Not yet money in the tin.
        $this->getJson('/api/petty-cash', $this->auth('hr@test.com'))
            ->assertJsonPath('summary.float', 0);
    }

    /** Someone who can approve is the sign-off, so it lands approved. */
    public function test_a_super_admin_top_up_is_immediately_approved(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');

        $this->postJson('/api/petty-cash/top-ups', [
            'amount' => 30000,
        ], $this->auth('boss@test.com'))
            ->assertCreated()
            ->assertJsonPath('topUp.status', 'approved');

        $this->getJson('/api/petty-cash', $this->auth('boss@test.com'))
            ->assertJsonPath('summary.float', 30000);
    }

    public function test_approving_a_top_up_adds_it_to_the_float(): void
    {
        $this->makeEmployee('boss@test.com', 'super_admin');
        $topUp = PettyCashTopUp::create([
            'amount' => 20000, 'date' => now()->toDateString(), 'status' => 'pending',
        ]);

        $this->postJson("/api/petty-cash/top-ups/{$topUp->id}/decision", [
            'decision' => 'approved',
        ], $this->auth('boss@test.com'))->assertOk();

        $this->getJson('/api/petty-cash', $this->auth('boss@test.com'))
            ->assertJsonPath('summary.float', 20000);
    }

    // --- reconciliation --------------------------------------------------

    public function test_a_count_records_its_variance(): void
    {
        $this->float(50000);
        PettyCashExpense::create([
            'amount' => 12000, 'date' => now()->toDateString(),
            'category' => 'Repairs', 'status' => 'recorded',
        ]);

        // Book says 38000; 37500 was counted, so 500 is missing.
        $this->postJson('/api/petty-cash/reconciliations', [
            'countedAmount' => 37500,
            'note' => 'July close.',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('reconciliation.bookBalance', '38000.00')
            ->assertJsonPath('reconciliation.variance', '-500.00');
    }

    /**
     * The book balance is captured at the moment of counting. A backdated
     * expense entered later must not rewrite the variance already found.
     */
    public function test_a_later_expense_does_not_alter_a_past_count(): void
    {
        $this->float(50000);

        $this->postJson('/api/petty-cash/reconciliations', [
            'countedAmount' => 50000,
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('reconciliation.variance', '0.00');

        PettyCashExpense::create([
            'amount' => 7000, 'date' => now()->subWeek()->toDateString(),
            'category' => 'Travel', 'status' => 'recorded',
        ]);

        $reconciliation = \App\Models\PettyCashReconciliation::first();
        $this->assertSame('50000.00', (string) $reconciliation->book_balance);
        $this->assertSame('0.00', (string) $reconciliation->variance);
    }

    public function test_an_employee_cannot_read_the_tin(): void
    {
        $this->getJson('/api/petty-cash', $this->auth('worker@test.com'))
            ->assertStatus(403);
    }
}

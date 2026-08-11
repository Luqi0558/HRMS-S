<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Services\AssetService;
use Database\Seeders\RolePermissionSeeder;
use Tests\TenantTestCase;

class AssetTest extends TenantTestCase
{
    private Employee $hr;
    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->hr = $this->makeEmployee('hr@test.com', 'hr');
        $this->worker = $this->makeEmployee('worker@test.com', 'employee');
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

    private function anAsset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'name' => 'MacBook Pro 14',
            'category' => 'Laptop',
            'serial_number' => 'ORB-MBP-'.random_int(1000, 9999),
            'condition' => 'Good',
            'status' => 'available',
        ], $overrides));
    }

    // --- the register ----------------------------------------------------

    public function test_hr_can_add_an_asset(): void
    {
        $this->postJson('/api/assets', [
            'name' => 'Dell Latitude 7440',
            'category' => 'Laptop',
            'serialNumber' => 'ORB-DLL-022',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('asset.status', 'available');
    }

    /**
     * Two rows with one serial means the register has lost track of a physical
     * item, which is the one thing it exists to prevent.
     */
    public function test_a_duplicate_serial_is_refused(): void
    {
        $this->anAsset(['serial_number' => 'ORB-DUP-001']);

        $this->postJson('/api/assets', [
            'name' => 'Another laptop',
            'category' => 'Laptop',
            'serialNumber' => 'ORB-DUP-001',
        ], $this->auth('hr@test.com'))->assertStatus(422);
    }

    public function test_an_employee_may_not_add_an_asset(): void
    {
        $this->postJson('/api/assets', [
            'name' => 'Sneaky laptop',
            'category' => 'Laptop',
        ], $this->auth('worker@test.com'))->assertStatus(403);
    }

    // --- issuing and returning -------------------------------------------

    public function test_issuing_an_asset_records_who_holds_it(): void
    {
        $asset = $this->anAsset();

        $this->postJson("/api/assets/{$asset->id}/assign", [
            'employeeId' => $this->worker->id,
            'returnDue' => now()->addYear()->toDateString(),
        ], $this->auth('hr@test.com'))
            ->assertOk()
            ->assertJsonPath('asset.status', 'assigned')
            ->assertJsonPath('asset.assignedTo', $this->worker->id);

        $this->assertDatabaseHas('asset_assignments', [
            'asset_id' => $asset->id,
            'employee_id' => $this->worker->id,
            'returned_on' => null,
        ]);
    }

    /**
     * Reassigning silently would drop the current holder from the register
     * while they still physically have the item.
     */
    public function test_an_asset_already_out_cannot_be_reissued(): void
    {
        $asset = $this->anAsset();
        $second = $this->makeEmployee('second@test.com', 'employee');
        $headers = $this->auth('hr@test.com');

        $this->postJson("/api/assets/{$asset->id}/assign", [
            'employeeId' => $this->worker->id,
        ], $headers)->assertOk();

        $this->postJson("/api/assets/{$asset->id}/assign", [
            'employeeId' => $second->id,
        ], $headers)->assertStatus(409);

        // Still with the first person.
        $this->assertSame($this->worker->id, $asset->fresh()->assigned_to);
        $this->assertSame(1, AssetAssignment::where('asset_id', $asset->id)->count());
    }

    public function test_returning_an_asset_frees_it_and_closes_the_assignment(): void
    {
        $asset = $this->anAsset();
        $headers = $this->auth('hr@test.com');

        $this->postJson("/api/assets/{$asset->id}/assign", [
            'employeeId' => $this->worker->id,
        ], $headers)->assertOk();

        $this->postJson("/api/assets/{$asset->id}/return", [
            'condition' => 'Fair',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('asset.status', 'available')
            ->assertJsonPath('asset.assignedTo', null);

        $assignment = AssetAssignment::firstOrFail();
        $this->assertNotNull($assignment->returned_on);
        $this->assertSame('Fair', $assignment->condition_in);
        // The condition it came back in becomes the asset's, so the next
        // person is issued what actually exists.
        $this->assertSame('Fair', $asset->fresh()->condition);
    }

    public function test_returning_an_asset_that_is_not_out_is_refused(): void
    {
        $asset = $this->anAsset();

        $this->postJson("/api/assets/{$asset->id}/return", [], $this->auth('hr@test.com'))
            ->assertStatus(409);
    }

    // --- history ---------------------------------------------------------

    /** Every holder is kept, not just the current one. */
    public function test_the_history_keeps_every_holder(): void
    {
        $asset = $this->anAsset();
        $second = $this->makeEmployee('second@test.com', 'employee');
        $headers = $this->auth('hr@test.com');

        $this->postJson("/api/assets/{$asset->id}/assign", ['employeeId' => $this->worker->id], $headers);
        $this->postJson("/api/assets/{$asset->id}/return", [], $headers);
        $this->postJson("/api/assets/{$asset->id}/assign", ['employeeId' => $second->id], $headers);

        $history = $this->getJson("/api/assets/{$asset->id}/history", $headers)
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $history);
        $names = array_column($history, 'employeeName');
        $this->assertContains('worker@test.com', $names);
        $this->assertContains('second@test.com', $names);
    }

    /**
     * The holder's name is copied onto the assignment, so a past holder is
     * still named after being offboarded — which is when the history is
     * actually consulted.
     */
    public function test_history_still_names_a_holder_who_has_left(): void
    {
        $asset = $this->anAsset();
        $headers = $this->auth('hr@test.com');

        $this->postJson("/api/assets/{$asset->id}/assign", ['employeeId' => $this->worker->id], $headers);
        $this->postJson("/api/assets/{$asset->id}/return", [], $headers);

        $this->worker->forceDelete();

        $history = $this->getJson("/api/assets/{$asset->id}/history", $headers)->json('data');

        $this->assertNull($history[0]['employeeId']);
        $this->assertSame('worker@test.com', $history[0]['employeeName']);
    }

    // --- retiring --------------------------------------------------------

    public function test_an_asset_in_someones_hands_cannot_be_retired(): void
    {
        $asset = $this->anAsset();
        $headers = $this->auth('hr@test.com');

        $this->postJson("/api/assets/{$asset->id}/assign", ['employeeId' => $this->worker->id], $headers);

        $this->postJson("/api/assets/{$asset->id}/retire", [], $headers)->assertStatus(409);
        $this->assertSame('assigned', $asset->fresh()->status);
    }

    public function test_a_retired_asset_cannot_be_issued(): void
    {
        $asset = $this->anAsset(['status' => 'retired']);

        $this->postJson("/api/assets/{$asset->id}/assign", [
            'employeeId' => $this->worker->id,
        ], $this->auth('hr@test.com'))->assertStatus(409);
    }

    /** Asset movements are the kind of thing an audit trail is for. */
    public function test_asset_changes_are_recorded_in_the_audit_trail(): void
    {
        app(AssetService::class)->create(
            User::where('email', 'hr@test.com')->firstOrFail(),
            ['name' => 'Logitech MX', 'category' => 'Accessory'],
        );

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'assets',
            'action' => 'asset added',
        ]);
    }
}

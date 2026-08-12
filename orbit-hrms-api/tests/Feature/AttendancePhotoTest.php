<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * The camera check-in.
 *
 * The photo is a picture of a member of staff, so where it is stored and who
 * may fetch it matter as much as whether the upload works.
 */
class AttendancePhotoTest extends TenantTestCase
{
    private Employee $worker;
    private Employee $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->worker = $this->makeEmployee('worker@test.com', 'employee');
        $this->other = $this->makeEmployee('other@test.com', 'employee');
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

    public function test_a_camera_check_in_stores_the_photo(): void
    {
        $this->post('/api/attendance/check-in', [
            'device' => 'camera',
            'photo' => UploadedFile::fake()->image('check-in.jpg'),
        ], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('attendance.device', 'camera')
            // The flag travels, the path never does.
            ->assertJsonPath('attendance.hasCheckInPhoto', true)
            ->assertJsonMissingPath('attendance.photoPath');

        $row = Attendance::where('employee_id', $this->worker->id)->firstOrFail();

        $this->assertNotNull($row->photo_path);
        Storage::disk('local')->assertExists($row->photo_path);
        // Filed per user, as claim receipts are.
        $this->assertStringStartsWith('attendance/', $row->photo_path);
    }

    public function test_a_check_in_without_a_photo_still_works(): void
    {
        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('attendance.hasCheckInPhoto', false);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $this->post('/api/attendance/check-in', [
            'device' => 'camera',
            'photo' => UploadedFile::fake()->create('payload.php', 8, 'text/x-php'),
        ], $this->auth('worker@test.com'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        $this->assertDatabaseCount('attendance', 0);
    }

    public function test_an_employee_can_fetch_their_own_check_in_photo(): void
    {
        $this->post('/api/attendance/check-in', [
            'device' => 'camera',
            'photo' => UploadedFile::fake()->image('check-in.jpg'),
        ], $this->auth('worker@test.com'))->assertCreated();

        $row = Attendance::where('employee_id', $this->worker->id)->firstOrFail();

        $this->get("/api/attendance/{$row->id}/photo", $this->auth('worker@test.com'))
            ->assertOk();
    }

    /**
     * The one that matters. An employee holds Attendance at 'self' scope, so a
     * colleague's photo must be unreachable even with a valid id in the URL.
     */
    public function test_an_employee_cannot_fetch_someone_elses_check_in_photo(): void
    {
        $row = Attendance::create([
            'employee_id' => $this->other->id,
            'date' => now()->toDateString(),
            'check_in' => '09:00',
            'status' => 'present',
            'device' => 'camera',
            'photo_path' => 'attendance/999/private.jpg',
        ]);

        // 404 rather than 403: whether a given person photographed themselves
        // this morning is itself information.
        $this->get("/api/attendance/{$row->id}/photo", $this->auth('worker@test.com'))
            ->assertNotFound();
    }

    /**
     * The reported bug: punched out, then wanted to punch back in — refused.
     */
    public function test_an_employee_can_check_in_again_after_checking_out(): void
    {
        $headers = $this->auth('worker@test.com');

        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $headers)
            ->assertCreated();
        $this->postJson('/api/attendance/check-out', [], $headers)->assertOk();

        // Used to be a 409 "You have already checked in today."
        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $headers)
            ->assertCreated()
            ->assertJsonPath('attendance.isCheckedIn', true);

        $row = Attendance::with('sessions')->where('employee_id', $this->worker->id)->firstOrFail();
        $this->assertCount(2, $row->sessions);
    }

    public function test_checking_in_twice_without_checking_out_is_still_refused(): void
    {
        $headers = $this->auth('worker@test.com');

        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $headers)
            ->assertCreated();

        // A double-tap must not open a second session or overwrite the first.
        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $headers)
            ->assertStatus(409);

        $this->assertCount(1, Attendance::with('sessions')->firstOrFail()->sessions);
    }

    /**
     * The point of sessions: the gap between them is not work.
     */
    public function test_worked_time_excludes_the_gap_between_sessions(): void
    {
        $row = Attendance::create([
            'employee_id' => $this->worker->id,
            'date' => now()->toDateString(),
            'check_in' => '09:00',
            'check_out' => '18:00',
            'status' => 'present',
        ]);
        $row->sessions()->create(['check_in' => '09:00', 'check_out' => '13:00']);
        $row->sessions()->create(['check_in' => '14:00', 'check_out' => '18:00']);

        $row->load('sessions');

        // 8h worked across a 9h day, with an hour out.
        $this->assertSame(8 * 3600, $row->workedSeconds());
        $this->assertSame(3600, $row->breakSeconds());
    }

    /**
     * A later check-in must not relabel a punctual morning.
     */
    public function test_returning_after_the_grace_period_does_not_make_the_day_late(): void
    {
        $headers = $this->auth('worker@test.com');

        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $headers)->assertCreated();
        $row = Attendance::where('employee_id', $this->worker->id)->firstOrFail();
        $firstCheckIn = $row->check_in;
        $originalStatus = $row->status;

        $this->postJson('/api/attendance/check-out', [], $headers)->assertOk();
        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $headers)->assertCreated();

        $row->refresh();
        $this->assertSame($firstCheckIn, $row->check_in, 'The day keeps its first arrival time.');
        $this->assertSame($originalStatus, $row->status, 'Status is stamped once, from the first session.');
    }

    /**
     * The punch is stamped in the company's timezone, not the server's.
     *
     * app.timezone is UTC while the office runs on Asia/Karachi (UTC+5), and
     * settings.attendance.startTime is a local wall clock. Stamping from now()
     * wrote UTC and compared it against that local start, so somebody arriving
     * at 14:00 was filed as "present" — 14:00 local is 09:00 UTC, which falls
     * before the 09:30 start.
     */
    public function test_the_punch_is_stamped_in_the_company_timezone(): void
    {
        $settings = app(SettingsService::class);
        $settings->putGroup('company', ['timezone' => 'Asia/Karachi']);
        $settings->putGroup('attendance', ['startTime' => '09:30', 'graceMinutes' => 10]);

        // 09:00 UTC is 14:00 in Karachi — unambiguously late.
        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00', 'UTC'));

        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('attendance.checkIn', '14:00')
            ->assertJsonPath('attendance.status', 'late');

        Carbon::setTestNow();
    }

    /**
     * The ledger shows the punch that just happened, not the first of the day.
     */
    public function test_checking_in_again_replaces_the_photo_the_ledger_shows(): void
    {
        $headers = $this->auth('worker@test.com');

        $this->post('/api/attendance/check-in', [
            'device' => 'camera',
            'photo' => UploadedFile::fake()->image('morning.jpg'),
        ], $headers)->assertCreated();

        $row = Attendance::where('employee_id', $this->worker->id)->firstOrFail();
        $morning = $row->photo_path;

        $this->postJson('/api/attendance/check-out', [], $headers)->assertOk();

        $this->post('/api/attendance/check-in', [
            'device' => 'camera',
            'photo' => UploadedFile::fake()->image('afternoon.jpg'),
        ], $headers)->assertCreated();

        $row->refresh();
        $this->assertNotSame($morning, $row->photo_path, 'The ledger must follow the latest check-in.');

        // The superseded shot is removed from disk, not merely hidden: the
        // ledger only ever shows the latest, so keeping older files would grow
        // the disk with images nothing displays.
        Storage::disk('local')->assertMissing($morning);
        Storage::disk('local')->assertExists($row->photo_path);

        // And nothing in the database still points at the deleted file.
        $this->assertSame(0, $row->sessions()->where('photo_path', $morning)->count());
    }

    /**
     * Camera off for the second punch must not erase what was already captured.
     */
    public function test_a_photoless_check_in_does_not_clear_an_existing_photo(): void
    {
        $headers = $this->auth('worker@test.com');

        $this->post('/api/attendance/check-in', [
            'device' => 'camera',
            'photo' => UploadedFile::fake()->image('morning.jpg'),
        ], $headers)->assertCreated();

        $row = Attendance::where('employee_id', $this->worker->id)->firstOrFail();
        $morning = $row->photo_path;

        $this->postJson('/api/attendance/check-out', [], $headers)->assertOk();
        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $headers)->assertCreated();

        $row->refresh();
        $this->assertSame($morning, $row->photo_path, 'A photoless punch leaves the last photo in place.');
        Storage::disk('local')->assertExists($morning);
    }

    public function test_fetching_a_photo_that_does_not_exist_is_a_404(): void
    {
        $this->postJson('/api/attendance/check-in', ['device' => 'laptop'], $this->auth('worker@test.com'))
            ->assertCreated();

        $row = Attendance::where('employee_id', $this->worker->id)->firstOrFail();

        $this->get("/api/attendance/{$row->id}/photo", $this->auth('worker@test.com'))
            ->assertNotFound();
    }
}

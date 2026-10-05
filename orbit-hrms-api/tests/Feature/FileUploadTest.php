<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\ProfileService;
use App\Services\TicketService;
use App\Services\EmployeeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TenantTestCase;

/**
 * Files that the screens collected but never sent.
 *
 * The profile's document inputs, the leave form's supporting document and
 * the ticket modal's attachments all held a file (or just a typed name) in
 * the browser and posted nothing — HR never saw a CV or a sick note, and a
 * ticket attachment vanished on the next refresh.
 */
class FileUploadTest extends TenantTestCase
{
    private Employee $hr;
    private Employee $worker;
    private Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->hr = $this->makeEmployee('hr@test.com', 'hr');
        $this->worker = $this->makeEmployee('worker@test.com', 'employee');
        $this->outsider = $this->makeEmployee('outsider@test.com', 'employee');
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

    private function userOf(Employee $employee): User
    {
        return User::findOrFail($employee->user_id);
    }

    private function auth(string $email): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $email, 'password' => 'password123',
        ], $this->tenantHeaders())->json('token');

        $this->app['auth']->forgetGuards();

        return $this->tenantHeaders(['Authorization' => "Bearer {$token}"]);
    }

    // --- profile documents ----------------------------------------------

    public function test_an_employee_uploads_their_own_cv_from_the_profile(): void
    {
        $response = $this->post('/api/profile/documents', [
            'documents' => ['cv' => UploadedFile::fake()->create('my-cv.pdf', 120, 'application/pdf')],
        ], $this->auth('worker@test.com'))->assertOk();

        $cv = collect($response->json('employee.onboarding.documents'))->firstWhere('key', 'cv');
        $this->assertSame('my-cv.pdf', $cv['name']);
        $this->assertSame('submitted', $cv['status']);

        $stored = $this->worker->documents()->where('type', 'cv')->firstOrFail();
        Storage::disk('local')->assertExists($stored->path);
    }

    /** A file HR has not seen sends the person back to HR's review queue. */
    public function test_a_new_upload_returns_the_review_to_pending(): void
    {
        $this->worker->update(['onboarding_review_status' => 'verified', 'onboarding_reviewed_at' => now()]);

        app(ProfileService::class)->uploadOwnDocuments($this->userOf($this->worker), [
            'contract' => UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'),
        ]);

        $this->assertSame('pending', $this->worker->fresh()->onboarding_review_status);
    }

    public function test_hr_and_the_owner_can_download_a_document_but_a_colleague_cannot(): void
    {
        app(ProfileService::class)->uploadOwnDocuments($this->userOf($this->worker), [
            'cv' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
        ]);
        $employees = app(EmployeeService::class);

        $this->assertSame('cv', $employees->documentForDownload($this->userOf($this->hr), $this->worker->id, 'cv')->type);
        $this->assertSame('cv', $employees->documentForDownload($this->userOf($this->worker), $this->worker->id, 'cv')->type);

        $this->expectException(NotFoundHttpException::class);
        $employees->documentForDownload($this->userOf($this->outsider), $this->worker->id, 'cv');
    }

    public function test_a_document_download_streams_the_real_file(): void
    {
        app(ProfileService::class)->uploadOwnDocuments($this->userOf($this->worker), [
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', 'real cv bytes'),
        ]);

        $response = $this->get("/api/employees/{$this->worker->id}/documents/cv", $this->auth('hr@test.com'))
            ->assertOk();

        $this->assertSame('real cv bytes', $response->streamedContent());
    }

    // --- leave attachments ----------------------------------------------

    public function test_leave_is_requested_with_a_contact_and_a_sick_note(): void
    {
        $response = $this->post('/api/leave-requests', [
            'type' => 'Sick leave',
            'fromDate' => now()->addDay()->toDateString(),
            'toDate' => now()->addDay()->toDateString(),
            'days' => 1,
            'contact' => '0300-1234567',
            'attachment' => UploadedFile::fake()->create('sick-note.pdf', 40, 'application/pdf'),
        ], $this->auth('worker@test.com'))->assertCreated();

        $response->assertJsonPath('request.contact', '0300-1234567')
            ->assertJsonPath('request.hasAttachment', true)
            ->assertJsonPath('request.attachmentName', 'sick-note.pdf');

        Storage::disk('local')->assertExists(LeaveRequest::firstOrFail()->attachment_path);
    }

    public function test_a_sick_note_is_readable_by_hr_and_the_owner_only(): void
    {
        $request = LeaveRequest::create([
            'employee_id' => $this->worker->id,
            'type' => 'Sick leave',
            'from_date' => now()->addDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'days' => 1,
            'status' => 'pending',
            'attachment_path' => UploadedFile::fake()->create('note.pdf', 5)->store('leave', 'local'),
            'attachment_name' => 'note.pdf',
        ]);
        $leave = app(LeaveService::class);

        $this->assertSame($request->id, $leave->findForAttachment($this->userOf($this->hr), $request->id)->id);
        $this->assertSame($request->id, $leave->findForAttachment($this->userOf($this->worker), $request->id)->id);

        $this->expectException(NotFoundHttpException::class);
        $leave->findForAttachment($this->userOf($this->outsider), $request->id);
    }

    // --- ticket attachments ---------------------------------------------

    private function ticketForWorker(Employee $manager): Ticket
    {
        $project = Project::create(['name' => 'Delivery', 'slug' => 'delivery', 'manager_id' => $manager->id]);
        $project->members()->sync([$this->worker->id]);
        $module = ProjectModule::create(['project_id' => $project->id, 'name' => 'API', 'slug' => 'api']);

        return Ticket::create([
            'project_id' => $project->id,
            'project_module_id' => $module->id,
            'title' => 'Export bug',
            'reference' => 'DEL-API-1',
            'status' => 'in_progress',
            'priority' => 'medium',
            'assignee_id' => $this->worker->id,
            'created_by' => $manager->id,
        ]);
    }

    public function test_a_ticket_attachment_is_stored_listed_and_announced(): void
    {
        $manager = $this->makeEmployee('manager@test.com', 'manager');
        $ticket = $this->ticketForWorker($manager);

        $updated = app(TicketService::class)->attach(
            $this->userOf($this->worker),
            $ticket->id,
            UploadedFile::fake()->create('stack-trace.txt', 3, 'text/plain'),
        );

        $this->assertCount(1, $updated->attachments);
        $this->assertSame('stack-trace.txt', $updated->attachments->first()->original_name);
        Storage::disk('local')->assertExists($updated->attachments->first()->path);
        $this->assertSame(
            ['New ticket attachment'],
            Notification::where('employee_id', $manager->id)->pluck('title')->all(),
        );
    }

    public function test_someone_off_the_project_cannot_download_a_ticket_attachment(): void
    {
        $manager = $this->makeEmployee('manager@test.com', 'manager');
        $ticket = $this->ticketForWorker($manager);
        $tickets = app(TicketService::class);
        $attachment = $tickets->attach(
            $this->userOf($this->worker),
            $ticket->id,
            UploadedFile::fake()->create('design.png', 3, 'image/png'),
        )->attachments->first();

        $this->assertSame($attachment->id, $tickets->findAttachment($this->userOf($manager), $ticket->id, $attachment->id)->id);

        $this->expectException(NotFoundHttpException::class);
        $tickets->findAttachment($this->userOf($this->outsider), $ticket->id, $attachment->id);
    }

    public function test_an_executable_cannot_be_attached_to_a_ticket(): void
    {
        $manager = $this->makeEmployee('manager@test.com', 'manager');
        $ticket = $this->ticketForWorker($manager);

        $this->post("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->create('payload.html', 1, 'text/html'),
        ], $this->auth('worker@test.com'))->assertStatus(422);
    }
}

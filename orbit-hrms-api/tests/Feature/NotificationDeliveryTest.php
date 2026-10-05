<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AssetService;
use App\Services\CompanyHubService;
use App\Services\LeaveService;
use App\Services\MessageService;
use App\Services\PettyCashService;
use App\Services\ProjectService;
use App\Services\SettingsService;
use App\Services\TicketService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Notifications raised by the server for events that used to be announced
 * only in the acting user's own browser.
 *
 * Each of these was a pushNotification() call in a Vue view: it put a row in
 * the sender's local state and nowhere else, so the person it was addressed
 * to never saw it. These tests pin that the API now raises them, to the right
 * people, and never to the person who acted.
 *
 * Driven through the services rather than HTTP: most cases need two users to
 * act in turn, and within one test process the auth guard caches whoever
 * signed in first.
 */
class NotificationDeliveryTest extends TenantTestCase
{
    private Employee $manager;
    private Employee $developer;
    private Employee $reviewer;
    private Employee $hr;
    private Project $project;
    private ProjectModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->developer = $this->makeEmployee('dev@test.com', 'employee');
        $this->reviewer = $this->makeEmployee('qa@test.com', 'qa');
        $this->hr = $this->makeEmployee('hr@test.com', 'hr');

        $this->project = Project::create([
            'name' => 'Delivery',
            'slug' => 'delivery',
            'manager_id' => $this->manager->id,
        ]);
        $this->project->members()->sync([$this->developer->id, $this->reviewer->id]);

        $this->module = ProjectModule::create([
            'project_id' => $this->project->id,
            'name' => 'Payments',
            'slug' => 'payments',
        ]);
        $this->module->members()->sync([$this->developer->id, $this->reviewer->id]);
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

    /** @return array<int, string> titles this employee has been sent, oldest first */
    private function titlesFor(Employee $employee): array
    {
        return Notification::where('employee_id', $employee->id)->orderBy('id')->pluck('title')->all();
    }

    private function ticketInReview(): Ticket
    {
        return Ticket::create([
            'project_id' => $this->project->id,
            'project_module_id' => $this->module->id,
            'title' => 'Refund flow',
            'reference' => 'DEL-PAY-1',
            'status' => 'review',
            'priority' => 'medium',
            'assignee_id' => $this->developer->id,
            'created_by' => $this->manager->id,
        ]);
    }

    // --- tickets ---------------------------------------------------------

    public function test_creating_a_ticket_tells_the_assignee_but_not_the_creator(): void
    {
        app(TicketService::class)->create($this->userOf($this->manager), [
            'moduleId' => $this->module->id,
            'title' => 'Webhook retries',
            'assigneeId' => $this->developer->id,
        ]);

        $this->assertSame(['New ticket'], $this->titlesFor($this->developer));
        $this->assertSame([], $this->titlesFor($this->manager));
    }

    public function test_a_ticket_notification_links_to_its_module_board(): void
    {
        app(TicketService::class)->create($this->userOf($this->manager), [
            'moduleId' => $this->module->id,
            'title' => 'Webhook retries',
            'assigneeId' => $this->developer->id,
        ]);

        $row = Notification::where('employee_id', $this->developer->id)->firstOrFail();

        $this->assertSame('project-module-detail', $row->route_name);
        $this->assertSame(
            ['projectSlug' => 'delivery', 'moduleSlug' => 'payments'],
            json_decode($row->getRawOriginal('route_params'), true),
        );
    }

    public function test_passing_review_tells_the_assignee_and_the_manager(): void
    {
        $ticket = $this->ticketInReview();

        app(TicketService::class)->update($this->userOf($this->reviewer), $ticket->id, ['status' => 'done']);

        $this->assertSame(['Ticket passed review'], $this->titlesFor($this->developer));
        $this->assertSame(['Ticket passed review'], $this->titlesFor($this->manager));
        $this->assertSame([], $this->titlesFor($this->reviewer));
    }

    public function test_sending_work_back_says_so(): void
    {
        $ticket = $this->ticketInReview();

        app(TicketService::class)->update($this->userOf($this->reviewer), $ticket->id, ['status' => 'in_progress']);

        $this->assertSame(['Ticket sent back'], $this->titlesFor($this->developer));
    }

    /**
     * The new assignee hears it once, in words that say it is theirs — not
     * a second, generic "updated" row alongside.
     */
    public function test_a_reassignment_tells_the_new_assignee_exactly_once(): void
    {
        $ticket = $this->ticketInReview();

        app(TicketService::class)->update($this->userOf($this->manager), $ticket->id, [
            'assigneeId' => $this->reviewer->id,
        ]);

        $this->assertSame(['Ticket assigned to you'], $this->titlesFor($this->reviewer));
    }

    public function test_a_comment_reaches_everyone_on_the_ticket_except_its_author(): void
    {
        $ticket = $this->ticketInReview();

        app(TicketService::class)->comment($this->userOf($this->developer), $ticket->id, 'Ready again');

        $this->assertSame(['New ticket comment'], $this->titlesFor($this->manager));
        $this->assertSame([], $this->titlesFor($this->developer));
    }

    // --- projects --------------------------------------------------------

    /** The client sends the whole roster; only the newcomer is told. */
    public function test_adding_to_a_project_tells_only_the_new_member(): void
    {
        $newcomer = $this->makeEmployee('new@test.com', 'employee');

        app(ProjectService::class)->syncMembers(
            $this->userOf($this->manager),
            'delivery',
            [$this->developer->id, $this->reviewer->id, $newcomer->id],
        );

        $this->assertSame(['Added to project'], $this->titlesFor($newcomer));
        $this->assertSame([], $this->titlesFor($this->developer));
    }

    public function test_adding_to_a_module_tells_only_the_new_member(): void
    {
        $newcomer = $this->makeEmployee('new@test.com', 'employee');

        app(ProjectService::class)->syncModuleMembers(
            $this->userOf($this->manager),
            $this->module->id,
            [$this->developer->id, $this->reviewer->id, $newcomer->id],
        );

        $this->assertSame(['Added to module'], $this->titlesFor($newcomer));
        $this->assertSame([], $this->titlesFor($this->reviewer));
    }

    // --- assets ----------------------------------------------------------

    public function test_issuing_and_returning_an_asset_tells_the_holder(): void
    {
        $asset = Asset::create(['name' => 'ThinkPad', 'category' => 'Laptop', 'status' => 'available']);
        $assets = app(AssetService::class);

        $assets->assign($this->userOf($this->hr), $asset->id, ['employeeId' => $this->developer->id]);
        $assets->returnAsset($this->userOf($this->hr), $asset->id);

        $this->assertSame(['Asset assigned', 'Asset return recorded'], $this->titlesFor($this->developer));
    }

    // --- leave -----------------------------------------------------------

    /**
     * Under two-step approval HR cannot act until the lead has signed, and
     * nothing used to tell them that moment had come.
     */
    public function test_a_lead_approval_tells_hr_the_request_is_ready(): void
    {
        app(SettingsService::class)->put('leave', 'twoStepApproval', true);

        $lead = $this->makeEmployee('lead@test.com', 'team_lead');
        $this->module->update(['lead_id' => $lead->id]);

        $request = LeaveRequest::create([
            'employee_id' => $this->developer->id,
            'type' => 'Annual leave',
            'from_date' => now()->addWeek()->toDateString(),
            'to_date' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'status' => 'pending',
        ]);

        app(LeaveService::class)->decide($this->userOf($lead), $request->id, 'approved');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertContains('Leave ready for HR review', $this->titlesFor($this->hr));
        $this->assertNotContains('Leave ready for HR review', $this->titlesFor($lead));
    }

    // --- petty cash ------------------------------------------------------

    public function test_a_pending_top_up_and_a_variance_reach_the_super_admin(): void
    {
        $admin = $this->makeEmployee('admin@test.com', 'super_admin');
        $pettyCash = app(PettyCashService::class);

        $pettyCash->requestTopUp($this->userOf($this->hr), ['amount' => 5000]);
        $pettyCash->reconcile($this->userOf($this->hr), ['countedAmount' => 120]);

        $this->assertSame(
            ['Petty cash top-up requested', 'Petty cash variance found'],
            $this->titlesFor($admin),
        );
    }

    public function test_a_count_that_matches_the_ledger_raises_nothing(): void
    {
        $admin = $this->makeEmployee('admin@test.com', 'super_admin');

        app(PettyCashService::class)->reconcile($this->userOf($this->hr), ['countedAmount' => 0]);

        $this->assertSame([], $this->titlesFor($admin));
    }

    // --- company hub -----------------------------------------------------

    /**
     * The role list this replaced left out qa and intern, so those two roles
     * never heard a single announcement.
     */
    public function test_an_announcement_reaches_qa_and_interns_but_not_its_author(): void
    {
        $intern = $this->makeEmployee('intern@test.com', 'intern');

        app(CompanyHubService::class)->publish($this->userOf($this->hr), [
            'title' => 'Office closed Friday',
            'body' => 'Maintenance.',
        ]);

        $this->assertSame(['New announcement'], $this->titlesFor($this->reviewer));
        $this->assertSame(['New announcement'], $this->titlesFor($intern));
        $this->assertSame([], $this->titlesFor($this->hr));
    }

    public function test_a_policy_needing_acknowledgement_asks_for_it(): void
    {
        app(CompanyHubService::class)->savePolicy($this->userOf($this->hr), [
            'title' => 'Remote work',
            'summary' => 'Who may work remotely.',
            'acknowledgmentRequired' => true,
        ]);

        $this->assertSame(['Policy needs your acknowledgement'], $this->titlesFor($this->developer));
    }

    // --- sync ------------------------------------------------------------

    /**
     * A first poll replays the newest hundred. It used to replay the oldest,
     * so anyone with a long history opened the bell on last year.
     */
    public function test_a_first_poll_replays_the_newest_notifications(): void
    {
        $now = now();
        DB::table('notifications')->insert(array_map(fn (int $n) => [
            'employee_id' => $this->developer->id,
            'title' => "Notice {$n}",
            'type' => 'general',
            'created_at' => $now,
            'updated_at' => $now,
        ], range(1, 105)));

        $result = app(MessageService::class)->sync($this->userOf($this->developer));
        $titles = collect($result['notifications'])->pluck('title');

        $this->assertCount(100, $titles);
        $this->assertSame('Notice 6', $titles->first());
        $this->assertSame('Notice 105', $titles->last());
        $this->assertSame(
            (int) Notification::where('employee_id', $this->developer->id)->max('id'),
            $result['cursors']['notification'],
        );
        $this->assertSame(105, $result['unread']['notifications']);
    }
}

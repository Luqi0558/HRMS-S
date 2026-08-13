<?php

namespace Tests\Feature;

use App\Events\FeedUpdated;
use App\Events\PresenceChanged;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Notification;
use App\Models\User;
use App\Services\MessageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TenantTestCase;

class MessagingTest extends TenantTestCase
{
    private Employee $worker;
    private Employee $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->worker = $this->makeEmployee('worker@test.com', 'employee');
        $this->hr = $this->makeEmployee('hr@test.com', 'hr');
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

    private function deliver(Employee $to, string $body = 'Hello'): Message
    {
        $message = Message::create([
            'sender_id' => $this->hr->id,
            'audience' => 'Direct message',
            'body' => $body,
        ]);

        MessageRecipient::create([
            'message_id' => $message->id,
            'employee_id' => $to->id,
        ]);

        return $message;
    }

    // --- the poll --------------------------------------------------------

    /**
     * The common case: nothing new. A quiet office polls constantly, so this
     * must cost an empty response rather than a document restating what the
     * client already has.
     */
    public function test_a_poll_with_nothing_new_returns_204(): void
    {
        $this->getJson('/api/sync?sinceMessage=0&sinceNotification=0', $this->auth('worker@test.com'))
            ->assertNoContent();
    }

    /** The first poll always answers in full — it carries the cursors. */
    public function test_the_first_poll_returns_cursors_and_counts(): void
    {
        $this->getJson('/api/sync', $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('unread.messages', 0)
            ->assertJsonStructure(['cursors' => ['message', 'notification']]);
    }

    /**
     * A first poll establishes cursors; it does not replay history.
     *
     * This endpoint only ever sees messages addressed TO someone — a sender is
     * not among their own recipients — so replaying here delivered half a
     * thread and then raced the Messages page's own load to render it. The
     * user saw the other person's messages appear instantly and their own
     * arrive a beat later.
     */
    public function test_a_first_poll_does_not_replay_message_history(): void
    {
        $this->deliver($this->worker, 'Old message');

        $response = $this->getJson('/api/sync', $this->auth('worker@test.com'))->assertOk();

        // No bodies replayed...
        $this->assertSame([], $response->json('messages'));
        // ...but the cursor is set past them, so the next poll resumes
        // correctly rather than delivering them a moment later.
        $this->assertGreaterThan(0, $response->json('cursors.message'));
        // And the badge is still right.
        $this->assertSame(1, $response->json('unread.messages'));
    }

    /** Notifications DO replay: they have no page of their own to load from. */
    public function test_a_first_poll_still_returns_notifications(): void
    {
        app(MessageService::class)->notify([$this->worker->id], [
            'title' => 'Leave approved',
            'type' => 'leave',
        ]);

        $this->getJson('/api/sync', $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('notifications.0.title', 'Leave approved');
    }

    public function test_a_new_message_arrives_on_the_next_poll(): void
    {
        $this->deliver($this->worker, 'Standup at 10');

        $this->getJson('/api/sync?sinceMessage=0&sinceNotification=0', $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'Standup at 10')
            ->assertJsonPath('unread.messages', 1);
    }

    /**
     * The cursor is what keeps a poll cheap: having seen a message, the next
     * tick must not receive it again.
     */
    public function test_the_cursor_advances_so_a_message_is_not_replayed(): void
    {
        $message = $this->deliver($this->worker);
        $headers = $this->auth('worker@test.com');

        $first = $this->getJson('/api/sync?sinceMessage=0&sinceNotification=0', $headers)->assertOk();
        $cursor = $first->json('cursors.message');

        $this->assertSame($message->id, $cursor);

        $this->getJson("/api/sync?sinceMessage={$cursor}&sinceNotification=0", $headers)
            ->assertNoContent();
    }

    /** A client that has been away gets a bounded slice, not everything. */
    public function test_a_long_absence_returns_a_bounded_batch(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->deliver($this->worker, "Message {$i}");
        }

        $response = $this->getJson('/api/sync?sinceMessage=0&sinceNotification=0', $this->auth('worker@test.com'))
            ->assertOk();

        $this->assertCount(100, $response->json('messages'));
        // The cursor stops at the batch, so the next poll picks up the rest.
        $this->assertLessThan(
            Message::max('id'),
            $response->json('cursors.message'),
        );
    }

    public function test_a_poll_never_returns_someone_elses_message(): void
    {
        $this->deliver($this->hr, 'For HR only');

        $this->getJson('/api/sync?sinceMessage=0&sinceNotification=0', $this->auth('worker@test.com'))
            ->assertNoContent();
    }

    // --- sending ---------------------------------------------------------

    public function test_a_direct_message_reaches_its_recipient(): void
    {
        $this->postJson('/api/messages', [
            'body' => 'Can you review the ticket?',
            'audience' => 'Direct message',
            'recipientIds' => [$this->worker->id],
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('sent.recipientCount', 1);

        $this->assertDatabaseHas('message_recipients', [
            'employee_id' => $this->worker->id,
        ]);
    }

    /**
     * The recipient list is a request, not a decision: an employee whose
     * Messages scope is 'self' cannot reach the whole company by posting a
     * longer list of ids.
     */
    public function test_the_recipient_list_is_bounded_by_the_senders_scope(): void
    {
        $outsider = $this->makeEmployee('outsider@test.com', 'employee');

        $service = app(MessageService::class);
        $worker = User::where('email', 'worker@test.com')->firstOrFail();

        // An employee shares no project with the outsider, so they are not
        // reachable — the send is refused rather than silently delivered.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $service->send($worker, [
            'body' => 'Hello everyone',
            'audience' => 'Direct message',
            'recipientIds' => [$outsider->id, $this->hr->id + 999],
        ]);
    }

    /**
     * An employee must be able to message their own manager.
     *
     * Messaging scope means something different from every other module's:
     * 'self' applied literally would let an employee write to nobody at all.
     * It means "the people you report to" — which is what Messages.vue has
     * always offered, while the API refused it.
     */
    public function test_an_employee_can_message_their_manager(): void
    {
        $manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->putOnProjectTogether($manager, $this->worker);

        $this->postJson('/api/messages', [
            'body' => 'Running ten minutes late.',
            'audience' => 'Direct message',
            'recipientIds' => [$manager->id],
        ], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('sent.recipientCount', 1);

        $this->assertDatabaseHas('message_recipients', ['employee_id' => $manager->id]);
    }

    /** Everyone can reach HR, project membership or not. */
    public function test_an_employee_can_always_message_hr(): void
    {
        $this->postJson('/api/messages', [
            'body' => 'Question about my leave balance.',
            'audience' => 'Direct message',
            'recipientIds' => [$this->hr->id],
        ], $this->auth('worker@test.com'))
            ->assertCreated()
            ->assertJsonPath('sent.recipientCount', 1);
    }

    /** A module lead is a supervisor even without the parent project. */
    public function test_an_employee_can_message_the_lead_of_their_module(): void
    {
        $lead = $this->makeEmployee('lead@test.com', 'team_lead');

        $project = \App\Models\Project::create([
            'name' => 'Delivery', 'slug' => 'delivery-msg',
            'manager_id' => $this->hr->id, 'status' => 'active',
        ]);
        $module = \App\Models\ProjectModule::create([
            'project_id' => $project->id, 'name' => 'API', 'slug' => 'api-msg',
            'lead_id' => $lead->id, 'status' => 'active',
        ]);
        DB::table('project_module_members')->insert([
            'project_module_id' => $module->id,
            'employee_id' => $this->worker->id,
        ]);

        $this->postJson('/api/messages', [
            'body' => 'Ticket is ready for review.',
            'audience' => 'Direct message',
            'recipientIds' => [$lead->id],
        ], $this->auth('worker@test.com'))->assertCreated();

        $this->assertDatabaseHas('message_recipients', ['employee_id' => $lead->id]);
    }

    /**
     * Sideways is allowed now, but only to people you actually work with: this
     * colleague shares no project or module, so there is still no route.
     */
    public function test_an_employee_still_cannot_message_an_unrelated_colleague(): void
    {
        $colleague = $this->makeEmployee('colleague@test.com', 'employee');

        $this->postJson('/api/messages', [
            'body' => 'Hello',
            'audience' => 'Direct message',
            'recipientIds' => [$colleague->id],
        ], $this->auth('worker@test.com'))->assertStatus(403);
    }

    /**
     * QA owns the Review column, so "this is failing for me" is the single most
     * common thing they need to say. QA and the developer both hold scope
     * 'self' and neither supervises the other, so this was refused outright.
     *
     * The two directions are separate tests on purpose: once either side has
     * written, correspondentIds() opens a reply channel and would make the
     * second direction pass regardless of whether the rule works.
     */
    public function test_qa_can_message_a_developer_on_their_module(): void
    {
        $qa = $this->makeEmployee('qa@test.com', 'qa');
        $this->putOnModuleTogether('qa-to-dev', $this->worker, $qa);

        $this->postJson('/api/messages', [
            'body' => 'This fails on an empty payload.',
            'audience' => 'Direct message',
            'recipientIds' => [$this->worker->id],
        ], $this->auth('qa@test.com'))->assertCreated();

        $this->assertDatabaseHas('message_recipients', ['employee_id' => $this->worker->id]);
    }

    public function test_a_developer_can_message_the_qa_on_their_module(): void
    {
        $qa = $this->makeEmployee('qa@test.com', 'qa');
        $this->putOnModuleTogether('dev-to-qa', $this->worker, $qa);

        $this->postJson('/api/messages', [
            'body' => 'Fixed — ready for retest.',
            'audience' => 'Direct message',
            'recipientIds' => [$qa->id],
        ], $this->auth('worker@test.com'))->assertCreated();

        $this->assertDatabaseHas('message_recipients', ['employee_id' => $qa->id]);
    }

    /** The same rule, and the reason it is about shared work rather than role. */
    public function test_two_developers_on_the_same_module_can_message_each_other(): void
    {
        $peer = $this->makeEmployee('peer@test.com', 'employee');
        $this->putOnModuleTogether('two-devs', $this->worker, $peer);

        $this->postJson('/api/messages', [
            'body' => 'Are you on this one?',
            'audience' => 'Direct message',
            'recipientIds' => [$peer->id],
        ], $this->auth('worker@test.com'))->assertCreated();
    }

    /** QA on a different project is not a teammate, so the rule still bites. */
    public function test_qa_cannot_message_a_developer_they_share_nothing_with(): void
    {
        $qa = $this->makeEmployee('qa@test.com', 'qa');

        $this->postJson('/api/messages', [
            'body' => 'Hello',
            'audience' => 'Direct message',
            'recipientIds' => [$this->worker->id],
        ], $this->auth('qa@test.com'))->assertStatus(403);
    }

    /**
     * The composer must offer the teammate too. These are the same call, so a
     * dropdown that omitted them would make the feature invisible even though
     * the API accepts it.
     */
    public function test_the_contact_list_offers_a_teammate(): void
    {
        $qa = $this->makeEmployee('qa@test.com', 'qa');
        $this->putOnModuleTogether('contacts', $this->worker, $qa);

        $contacts = $this->getJson('/api/messages', $this->auth('qa@test.com'))
            ->assertOk()
            ->json('contacts');

        $this->assertContains(
            $this->worker->id,
            array_column($contacts, 'id'),
            'the developer should appear in QA\'s composer',
        );
    }

    /**
     * Search exists because the inbox is paged: filtering in the browser would
     * miss anything older than the newest page and report it as "not found".
     */
    public function test_search_finds_a_message_by_its_text(): void
    {
        $this->sendTo($this->hr, $this->worker, 'The payroll run is late again');
        $this->sendTo($this->hr, $this->worker, 'Standup moved to ten');

        $bodies = $this->searchAs('worker@test.com', 'payroll');

        $this->assertSame(['The payroll run is late again'], $bodies);
    }

    /** "My conversation with X" has to work from X's name, in both directions. */
    public function test_search_finds_a_conversation_by_the_other_persons_name(): void
    {
        $this->sendTo($this->hr, $this->worker, 'Anything from them at all');

        $this->assertSame(
            ['Anything from them at all'],
            $this->searchAs('worker@test.com', 'hr@test.com'),
        );
    }

    /**
     * The half a sender-name-only search would miss: this message is FROM the
     * searcher, so it carries their name, not the person they wrote to.
     */
    public function test_search_finds_messages_the_searcher_sent_to_that_person(): void
    {
        $this->sendTo($this->worker, $this->hr, 'Sent outward, not received');

        $this->assertSame(
            ['Sent outward, not received'],
            $this->searchAs('worker@test.com', 'hr@test.com'),
        );
    }

    /** A LIKE wildcard typed by a user is a character, not "match everything". */
    public function test_a_percent_sign_does_not_match_every_message(): void
    {
        $this->sendTo($this->hr, $this->worker, 'Bonus is 10% this year');
        $this->sendTo($this->hr, $this->worker, 'Unrelated message');

        $this->assertSame(['Bonus is 10% this year'], $this->searchAs('worker@test.com', '10%'));
    }

    /** Underscore is the quieter wildcard: it matches any single character. */
    public function test_an_underscore_is_matched_literally(): void
    {
        $this->sendTo($this->hr, $this->worker, 'Use the code build_id here');
        $this->sendTo($this->hr, $this->worker, 'Use the code buildxid here');

        $this->assertSame(['Use the code build_id here'], $this->searchAs('worker@test.com', 'build_id'));
    }

    /** Search must not become a way to read someone else's inbox. */
    public function test_search_never_reaches_another_persons_messages(): void
    {
        $other = $this->makeEmployee('other@test.com', 'employee');
        $this->sendTo($this->hr, $other, 'Confidential to somebody else');

        $this->assertSame([], $this->searchAs('worker@test.com', 'Confidential'));
    }

    public function test_an_empty_search_returns_the_normal_inbox(): void
    {
        $this->sendTo($this->hr, $this->worker, 'First');
        $this->sendTo($this->hr, $this->worker, 'Second');

        $this->assertCount(2, $this->searchAs('worker@test.com', '   '));
    }

    /** Writes a message straight to the tables, bypassing scope rules. */
    private function sendTo(Employee $from, Employee $to, string $body): Message
    {
        $message = Message::create([
            'sender_id' => $from->id,
            'audience' => 'Direct message',
            'body' => $body,
        ]);

        MessageRecipient::create([
            'message_id' => $message->id,
            'employee_id' => $to->id,
        ]);

        return $message;
    }

    /** @return array<int, string> matching bodies, newest first */
    private function searchAs(string $email, string $term): array
    {
        return $this->getJson(
            '/api/messages?q='.urlencode($term),
            $this->auth($email),
        )->assertOk()->json('data.*.body');
    }

    /**
     * Puts everyone given on one module of a fresh project, which is what
     * makes them teammates. The slug is a parameter because several tests do
     * this and the column is unique.
     */
    private function putOnModuleTogether(string $slug, Employee ...$members): void
    {
        $project = \App\Models\Project::create([
            'name' => 'Shared '.$slug, 'slug' => 'shared-'.$slug,
            'manager_id' => $this->hr->id, 'status' => 'active',
        ]);
        $module = \App\Models\ProjectModule::create([
            'project_id' => $project->id, 'name' => 'Module '.$slug, 'slug' => 'module-'.$slug,
            'lead_id' => $this->hr->id, 'status' => 'active',
        ]);

        foreach ($members as $member) {
            DB::table('project_module_members')->insert([
                'project_module_id' => $module->id,
                'employee_id' => $member->id,
            ]);
        }
    }

    private function putOnProjectTogether(Employee $manager, Employee $member): void
    {
        $project = \App\Models\Project::create([
            'name' => 'Shared', 'slug' => 'shared-'.$member->id,
            'manager_id' => $manager->id, 'status' => 'active',
        ]);

        DB::table('project_members')->insert([
            'project_id' => $project->id,
            'employee_id' => $member->id,
        ]);
    }

    /** HR has scope 'all', so a broadcast fans out to everyone but itself. */
    public function test_a_broadcast_fans_out_at_send_time(): void
    {
        $this->makeEmployee('second@test.com', 'employee');

        $this->postJson('/api/messages', [
            'body' => 'Town hall on Friday.',
            'subject' => 'Town hall',
            'audience' => 'all_company',
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            // worker + second + outsiderless roster, excluding HR itself.
            ->assertJsonPath('sent.recipientCount', 2);
    }

    /**
     * A broadcast names a group, and the group decides who receives it — not
     * the recipient list the client sent. Without this a "broadcast to HR"
     * could quietly deliver to anyone the sender chose to enumerate.
     */
    public function test_a_broadcast_reaches_only_its_named_audience(): void
    {
        $manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->makeEmployee('second@test.com', 'employee');

        $this->postJson('/api/messages', [
            'body' => 'For managers only.',
            'audience' => 'managers',
            // A deliberately over-broad list: it must be ignored in favour of
            // the audience.
            'recipientIds' => [$this->worker->id, $manager->id],
        ], $this->auth('hr@test.com'))
            ->assertCreated()
            ->assertJsonPath('sent.recipientCount', 1);

        $this->assertDatabaseHas('message_recipients', ['employee_id' => $manager->id]);
        $this->assertDatabaseMissing('message_recipients', ['employee_id' => $this->worker->id]);
    }

    /**
     * Fan-out at write time is what keeps the poll a single indexed lookup —
     * the delivery rows exist before anyone asks for them.
     */
    public function test_a_broadcast_is_already_delivered_before_any_poll(): void
    {
        $this->postJson('/api/messages', [
            'body' => 'Office closed Monday.',
            'audience' => 'all_company',
        ], $this->auth('hr@test.com'))->assertCreated();

        $this->assertDatabaseHas('message_recipients', [
            'employee_id' => $this->worker->id,
            'read_at' => null,
        ]);
    }

    // --- the inbox -------------------------------------------------------

    /**
     * A sender is not among their own recipients, so an inbox built only from
     * delivery rows shows a conversation containing every reply and none of
     * your own — and a message vanishes the moment the page is reloaded.
     */
    public function test_the_inbox_includes_messages_you_sent(): void
    {
        $this->postJson('/api/messages', [
            'body' => 'Running ten minutes late.',
            'audience' => 'Direct message',
            'recipientIds' => [$this->hr->id],
        ], $this->auth('worker@test.com'))->assertCreated();

        $inbox = $this->getJson('/api/messages', $this->auth('worker@test.com'))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $inbox);
        $this->assertSame('Running ten minutes late.', $inbox[0]['body']);
        $this->assertSame($this->worker->id, $inbox[0]['senderId']);
        // Your own message is not something you need to read.
        $this->assertTrue($inbox[0]['isRead']);
    }

    /** Both halves of a conversation come back, in order. */
    public function test_the_inbox_returns_both_sides_of_a_conversation(): void
    {
        $this->deliver($this->worker, 'Can you take a look?');

        $this->postJson('/api/messages', [
            'body' => 'On it.',
            'audience' => 'Direct message',
            'recipientIds' => [$this->hr->id],
        ], $this->auth('worker@test.com'))->assertCreated();

        $inbox = $this->getJson('/api/messages', $this->auth('worker@test.com'))
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $inbox);
        $bodies = array_column($inbox, 'body');
        $this->assertContains('Can you take a look?', $bodies);
        $this->assertContains('On it.', $bodies);
    }

    /** The view groups conversations by who else is on them. */
    public function test_messages_carry_their_recipient_ids(): void
    {
        $this->deliver($this->worker);

        $inbox = $this->getJson('/api/messages', $this->auth('worker@test.com'))
            ->assertOk()
            ->json('data');

        $this->assertSame([$this->worker->id], $inbox[0]['recipientIds']);
    }

    /**
     * The compose dropdown gets its contacts from here, not /employees — an
     * employee holds no Employees.view permission, so that request 403s for
     * the most common role and returns the whole company for everyone else.
     */
    public function test_the_inbox_carries_the_contacts_you_may_write_to(): void
    {
        $manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->putOnProjectTogether($manager, $this->worker);
        $this->makeEmployee('stranger@test.com', 'employee');

        $contacts = $this->getJson('/api/messages', $this->auth('worker@test.com'))
            ->assertOk()
            ->json('contacts');

        $names = array_column($contacts, 'name');

        // Their manager and HR, but not an unrelated colleague.
        $this->assertContains('manager@test.com', $names);
        $this->assertContains('hr@test.com', $names);
        $this->assertNotContains('stranger@test.com', $names);
        // Never themselves.
        $this->assertNotContains('worker@test.com', $names);
    }

    /** The dropdown cannot offer someone the send endpoint would refuse. */
    public function test_contacts_match_who_the_api_will_actually_deliver_to(): void
    {
        $manager = $this->makeEmployee('manager@test.com', 'manager');
        $this->putOnProjectTogether($manager, $this->worker);

        $contacts = $this->getJson('/api/messages', $this->auth('worker@test.com'))
            ->json('contacts');

        foreach ($contacts as $contact) {
            $this->postJson('/api/messages', [
                'body' => 'Test',
                'audience' => 'Direct message',
                'recipientIds' => [$contact['id']],
            ], $this->auth('worker@test.com'))->assertCreated();
        }
    }

    // --- read state ------------------------------------------------------

    public function test_marking_a_message_read_clears_it_from_the_unread_count(): void
    {
        $message = $this->deliver($this->worker);
        $headers = $this->auth('worker@test.com');

        $this->postJson("/api/messages/{$message->id}/read", [], $headers)->assertOk();

        $this->getJson('/api/sync', $headers)
            ->assertOk()
            ->assertJsonPath('unread.messages', 0);
    }

    public function test_a_message_not_addressed_to_you_cannot_be_marked_read(): void
    {
        $message = $this->deliver($this->hr);

        // 404, not 403 — a message that is not theirs should not be confirmed
        // to exist.
        $this->postJson("/api/messages/{$message->id}/read", [], $this->auth('worker@test.com'))
            ->assertStatus(404);
    }

    public function test_mark_all_read_clears_every_unread_message(): void
    {
        $this->deliver($this->worker, 'One');
        $this->deliver($this->worker, 'Two');

        $headers = $this->auth('worker@test.com');

        $this->postJson('/api/messages/read-all', [], $headers)
            ->assertOk()
            ->assertJsonPath('marked', 2);

        $this->assertSame(0, MessageRecipient::where('employee_id', $this->worker->id)
            ->whereNull('read_at')->count());
    }

    // --- notifications ---------------------------------------------------

    public function test_notifications_ride_the_same_poll_as_messages(): void
    {
        app(MessageService::class)->notify([$this->worker->id], [
            'title' => 'Leave approved',
            'body' => 'Your annual leave was approved.',
            'type' => 'leave',
            'routeName' => 'leave-requests',
        ]);

        $this->getJson('/api/sync?sinceMessage=0&sinceNotification=0', $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('notifications.0.title', 'Leave approved')
            ->assertJsonPath('notifications.0.route.name', 'leave-requests')
            ->assertJsonPath('unread.notifications', 1);
    }

    /**
     * The two cursors advance independently — a shared one would replay
     * whichever feed happened to be behind.
     */
    public function test_the_two_cursors_are_independent(): void
    {
        $this->deliver($this->worker);
        $headers = $this->auth('worker@test.com');

        $first = $this->getJson('/api/sync?sinceMessage=0&sinceNotification=0', $headers)->assertOk();
        $messageCursor = $first->json('cursors.message');

        app(MessageService::class)->notify([$this->worker->id], ['title' => 'Something happened']);

        // The message is not repeated, but the notification arrives.
        $second = $this->getJson("/api/sync?sinceMessage={$messageCursor}&sinceNotification=0", $headers)
            ->assertOk();

        $this->assertCount(0, $second->json('messages'));
        $this->assertCount(1, $second->json('notifications'));
    }

    public function test_marking_a_notification_read_clears_the_badge(): void
    {
        app(MessageService::class)->notify([$this->worker->id], ['title' => 'Payroll released']);

        $notification = Notification::firstOrFail();
        $headers = $this->auth('worker@test.com');

        $this->postJson("/api/notifications/{$notification->id}/read", [], $headers)->assertOk();

        $this->getJson('/api/sync', $headers)
            ->assertOk()
            ->assertJsonPath('unread.notifications', 0);
    }

    // --- notifications raised by other modules ---------------------------

    /**
     * The bell is only useful if the rest of the application feeds it. These
     * assert the wiring exists at all — without them the transport works and
     * nothing ever arrives.
     */
    public function test_a_leave_decision_notifies_the_requester(): void
    {
        $request = \App\Models\LeaveRequest::create([
            'employee_id' => $this->worker->id,
            'type' => 'Annual leave',
            'from_date' => now()->addWeek()->toDateString(),
            'to_date' => now()->addWeek()->addDay()->toDateString(),
            'days' => 2,
            'status' => 'pending',
        ]);

        app(\App\Services\LeaveService::class)->decide(
            User::where('email', 'hr@test.com')->firstOrFail(),
            $request->id,
            'approved',
        );

        $this->assertDatabaseHas('notifications', [
            'employee_id' => $this->worker->id,
            'type' => 'leave',
        ]);
    }

    /** Filing a request tells the people who can act on it. */
    public function test_filing_leave_notifies_the_approvers(): void
    {
        app(\App\Services\LeaveService::class)->create(
            User::where('email', 'worker@test.com')->firstOrFail(),
            [
                'type' => 'Annual leave',
                'fromDate' => now()->addWeek()->toDateString(),
                'toDate' => now()->addWeek()->addDay()->toDateString(),
                'days' => 2,
            ],
        );

        $this->assertDatabaseHas('notifications', [
            'employee_id' => $this->hr->id,
            'type' => 'leave',
        ]);
    }

    public function test_a_claim_decision_notifies_the_claimant(): void
    {
        $claim = \App\Models\MedicalClaim::create([
            'employee_id' => $this->worker->id,
            'type' => 'Consultation',
            'amount' => 2000,
            'incurred_on' => now()->toDateString(),
            'status' => 'pending',
        ]);

        app(\App\Services\MedicalClaimService::class)->decide(
            User::where('email', 'hr@test.com')->firstOrFail(),
            $claim->id,
            'approved',
            1500,
        );

        // A partial approval says so rather than a bare "approved".
        $notification = Notification::where('employee_id', $this->worker->id)->firstOrFail();
        $this->assertSame('medical', $notification->type);
        $this->assertStringContainsString('1,500', $notification->body);
    }

    /** Notifications carry a route so the bell links straight to the record. */
    public function test_a_notification_links_to_its_module(): void
    {
        app(MessageService::class)->notify([$this->worker->id], [
            'title' => 'Salary paid',
            'type' => 'salary',
            'routeName' => 'payroll',
        ]);

        $this->getJson('/api/sync?sinceMessage=0&sinceNotification=0', $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('notifications.0.route.name', 'payroll');
    }

    // --- read receipts ---------------------------------------------------

    /**
     * The sender's tick must turn double when the other side opens the thread.
     *
     * Their own poll reads rows addressed to them, and a sender is not among
     * their own recipients — so without receipts riding along, a delivery tick
     * stayed single until the page was reloaded.
     */
    public function test_a_read_reaches_the_sender_through_their_own_poll(): void
    {
        $sent = $this->postJson('/api/messages', [
            'body' => 'Did you see this?',
            'audience' => 'Direct message',
            'recipientIds' => [$this->hr->id],
        ], $this->auth('worker@test.com'))->assertCreated()->json('sent');

        $service = app(MessageService::class);
        $worker = User::where('email', 'worker@test.com')->firstOrFail();

        // Nothing read yet.
        $before = $service->sync($worker, $sent['id'], 999, 0);
        $this->assertSame([], $before['receipts']);

        $service->markMessageRead(
            User::where('email', 'hr@test.com')->firstOrFail(),
            $sent['id'],
        );

        $after = $service->sync($worker, $sent['id'], 999, 0);

        $this->assertCount(1, $after['receipts']);
        $this->assertSame($sent['id'], $after['receipts'][0]['id']);
        $this->assertSame(1, $after['receipts'][0]['read']);
        $this->assertSame(1, $after['receipts'][0]['total']);
    }

    /**
     * The cursor is a delivery-row id, not a timestamp. Comparing an
     * app-generated time against a database-written column silently returned
     * nothing whenever the two clocks differed.
     */
    public function test_the_receipt_cursor_stops_a_read_being_replayed(): void
    {
        $sent = $this->postJson('/api/messages', [
            'body' => 'Once only',
            'audience' => 'Direct message',
            'recipientIds' => [$this->hr->id],
        ], $this->auth('worker@test.com'))->assertCreated()->json('sent');

        $service = app(MessageService::class);
        $worker = User::where('email', 'worker@test.com')->firstOrFail();

        $service->markMessageRead(
            User::where('email', 'hr@test.com')->firstOrFail(),
            $sent['id'],
        );

        $first = $service->sync($worker, $sent['id'], 999, 0);
        $this->assertCount(1, $first['receipts']);

        // Polling again with the returned cursor reports nothing new.
        $second = $service->sync($worker, $sent['id'], 999, $first['cursors']['receipt']);
        $this->assertSame([], $second['receipts']);
    }

    /** A broadcast is only "read" once every recipient has opened it. */
    public function test_a_broadcast_reports_partial_read_counts(): void
    {
        $second = $this->makeEmployee('second@test.com', 'employee');

        $sent = $this->postJson('/api/messages', [
            'body' => 'Everyone please read',
            'audience' => 'all_company',
        ], $this->auth('hr@test.com'))->assertCreated()->json('sent');

        $service = app(MessageService::class);
        $service->markMessageRead(
            User::where('email', 'worker@test.com')->firstOrFail(),
            $sent['id'],
        );

        $feed = $service->sync(User::where('email', 'hr@test.com')->firstOrFail(), 999, 999, 0);

        $receipt = collect($feed['receipts'])->firstWhere('id', $sent['id']);
        $this->assertSame(1, $receipt['read']);
        $this->assertSame(2, $receipt['total']);
        $this->assertNotNull($second);
    }

    // --- presence --------------------------------------------------------

    public function test_a_chosen_status_persists(): void
    {
        $this->putJson('/api/presence', [
            'status' => 'away',
            'message' => 'In a meeting until 3',
        ], $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('presence.status', 'away');

        $this->assertSame('away', $this->worker->fresh()->presence_status);
    }

    /**
     * Clearing the status must clear the note with it — a stale "In a meeting
     * until 3" hours later is worse than no note at all.
     */
    public function test_clearing_the_status_clears_its_note(): void
    {
        $headers = $this->auth('worker@test.com');

        $this->putJson('/api/presence', ['status' => 'away', 'message' => 'Back at 3'], $headers)->assertOk();
        $this->putJson('/api/presence', ['status' => null], $headers)->assertOk();

        $employee = $this->worker->fresh();
        $this->assertNull($employee->presence_status);
        $this->assertNull($employee->presence_message);
    }

    /**
     * Online/offline is never stored — it comes from the presence channel. Only
     * chosen statuses are returned, so a colleague who has set nothing simply
     * does not appear.
     */
    public function test_only_chosen_statuses_are_listed(): void
    {
        app(MessageService::class)->setPresence(
            User::where('email', 'hr@test.com')->firstOrFail(),
            'away',
        );

        $presence = $this->getJson('/api/presence', $this->auth('worker@test.com'))
            ->assertOk()
            ->json('presence');

        $this->assertCount(1, $presence);
        $this->assertSame($this->hr->id, $presence[0]['id']);
        $this->assertSame('away', $presence[0]['status']);
    }

    /** A status change reaches everyone on the shared presence channel. */
    public function test_setting_a_status_announces_it(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Event::fake([PresenceChanged::class]);

        app(MessageService::class)->setPresence(
            User::where('email', 'worker@test.com')->firstOrFail(),
            'away',
            'Heads down',
        );

        Event::assertDispatched(
            PresenceChanged::class,
            fn (PresenceChanged $event) => $event->employeeId === $this->worker->id
                && $event->status === 'away'
                && $event->message === 'Heads down',
        );
    }

    /** Only two states exist now; busy and invisible were removed. */
    public function test_an_invalid_status_is_refused(): void
    {
        $headers = $this->auth('worker@test.com');

        $this->putJson('/api/presence', ['status' => 'on-holiday'], $headers)->assertStatus(422);
        $this->putJson('/api/presence', ['status' => 'busy'], $headers)->assertStatus(422);
        $this->putJson('/api/presence', ['status' => 'invisible'], $headers)->assertStatus(422);
    }

    // --- delivery hygiene ------------------------------------------------

    /**
     * An invited employee who has never signed in still has a row. Delivering
     * to them creates a recipient record nobody can ever read, so the sender's
     * tick could never reach "everyone has read this" and stayed single
     * forever.
     */
    public function test_a_broadcast_skips_employees_who_are_not_active(): void
    {
        $invited = $this->makeEmployee('invited@test.com', 'employee');
        $invited->update(['status' => 'invited']);

        $this->postJson('/api/messages', [
            'body' => 'Welcome aboard',
            'audience' => 'all_company',
        ], $this->auth('hr@test.com'))->assertCreated();

        $this->assertDatabaseHas('message_recipients', ['employee_id' => $this->worker->id]);
        $this->assertDatabaseMissing('message_recipients', ['employee_id' => $invited->id]);
    }

    /** And so the read count can actually reach the total. */
    public function test_a_broadcast_can_reach_fully_read(): void
    {
        $invited = $this->makeEmployee('invited@test.com', 'employee');
        $invited->update(['status' => 'invited']);

        $sent = $this->postJson('/api/messages', [
            'body' => 'Please confirm',
            'audience' => 'all_company',
        ], $this->auth('hr@test.com'))->assertCreated()->json('sent');

        $service = app(MessageService::class);
        $service->markMessageRead(User::where('email', 'worker@test.com')->firstOrFail(), $sent['id']);

        $feed = $service->sync(User::where('email', 'hr@test.com')->firstOrFail(), 999, 999, 0);
        $receipt = collect($feed['receipts'])->firstWhere('id', $sent['id']);

        // Everyone it went to has read it.
        $this->assertSame($receipt['total'], $receipt['read']);
    }

    // --- presence expiry -------------------------------------------------

    /**
     * Nobody means "away" to outlive the day they set it. presence_updated_at
     * was written and never read, so an away set weeks ago stayed forever.
     */
    public function test_a_stale_presence_override_is_ignored(): void
    {
        $service = app(MessageService::class);
        $worker = User::where('email', 'worker@test.com')->firstOrFail();

        $service->setPresence($worker, 'away', 'Back Monday');

        // Fresh: reported.
        $this->assertCount(1, $service->presenceStatuses($worker));

        // Backdated beyond the TTL.
        $this->worker->fresh()->update(['presence_updated_at' => now()->subDay()]);

        $this->assertSame([], $service->presenceStatuses($worker));
    }

    // --- broadcasting ----------------------------------------------------

    /** With Pusher configured, each recipient gets a ping on their own channel. */
    public function test_sending_announces_on_each_recipients_channel(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Event::fake([FeedUpdated::class]);

        $this->postJson('/api/messages', [
            'body' => 'Ping test',
            'recipientIds' => [$this->worker->id],
        ], $this->auth('hr@test.com'))->assertCreated();

        Event::assertDispatched(
            FeedUpdated::class,
            fn (FeedUpdated $event) => in_array($this->worker->id, $event->employeeIds, true)
                && $event->type === 'message',
        );
    }

    /**
     * The ping carries no message content — only its type. Bodies stay on
     * this server; the client fetches them through /api/sync.
     */
    public function test_the_broadcast_payload_carries_no_message_content(): void
    {
        $event = new FeedUpdated([$this->worker->id], 'orbit', 'message');

        $this->assertSame(['type' => 'message'], $event->broadcastWith());
        $this->assertStringContainsString(
            "tenant.orbit.employee.{$this->worker->id}",
            $event->broadcastOn()[0]->name,
        );
    }

    /**
     * One event for the whole audience, not one per person. Dispatching per
     * recipient turned a company broadcast into dozens of sequential HTTP
     * round trips on the request the sender was waiting for.
     */
    public function test_a_broadcast_pings_everyone_in_one_event(): void
    {
        config(['broadcasting.default' => 'pusher']);
        $this->makeEmployee('second@test.com', 'employee');
        $this->makeEmployee('third@test.com', 'employee');
        Event::fake([FeedUpdated::class]);

        $this->postJson('/api/messages', [
            'body' => 'Everyone please read',
            'audience' => 'all_company',
        ], $this->auth('hr@test.com'))->assertCreated();

        Event::assertDispatchedTimes(FeedUpdated::class, 1);
        Event::assertDispatched(
            FeedUpdated::class,
            fn (FeedUpdated $event) => count($event->employeeIds) === 3
                && count($event->broadcastOn()) === 3,
        );
    }

    /**
     * The guarantee the whole fallback rests on: broadcasting is an
     * accelerator, not the delivery mechanism. A Pusher outage, a wrong key,
     * or the office internet dropping must not turn a successful send into a
     * failure — the recipient rows are already committed and the message
     * arrives on the next poll.
     */
    public function test_a_broadcasting_failure_does_not_fail_the_send(): void
    {
        config(['broadcasting.default' => 'pusher']);

        Event::listen(FeedUpdated::class, function () {
            throw new \RuntimeException('Pusher is unreachable');
        });

        $this->postJson('/api/messages', [
            'body' => 'Still delivered',
            'recipientIds' => [$this->worker->id],
        ], $this->auth('hr@test.com'))->assertCreated();

        // Delivered despite the broadcast blowing up.
        $this->assertDatabaseHas('message_recipients', [
            'employee_id' => $this->worker->id,
        ]);

        // Read back through the service rather than a second HTTP call: within
        // one test process the auth guard caches the first resolved user, so
        // polling with the worker's token would still answer as HR.
        $feed = app(MessageService::class)->sync(
            User::where('email', 'worker@test.com')->firstOrFail(), 0, 0
        );

        $this->assertCount(1, $feed['messages']);
        $this->assertSame('Still delivered', $feed['messages'][0]->message->body);
    }

    /**
     * Channel authorisation is the socket's only security boundary —
     * subscribing to another feed is otherwise a matter of editing a string in
     * the browser. Asserted against the callback in routes/channels.php
     * directly, because resolving it through the broadcast manager depends on
     * which driver was active at boot.
     */
    public function test_channel_authorisation_allows_only_your_own_feed(): void
    {
        $worker = User::where('email', 'worker@test.com')->firstOrFail();
        $tenantId = tenant('id');

        // Own feed.
        $this->assertTrue($this->authorises($worker, "tenant.{$tenantId}.employee.{$this->worker->id}"));
        // Someone else's feed, same company.
        $this->assertFalse($this->authorises($worker, "tenant.{$tenantId}.employee.{$this->hr->id}"));
        // Their own employee id, but a different company.
        $this->assertFalse($this->authorises($worker, "tenant.other-co.employee.{$this->worker->id}"));
    }

    /**
     * Runs a channel name through the real broadcast manager, which applies
     * the callback registered in routes/channels.php.
     *
     * The driver is forced to a real one for the duration: the null
     * broadcaster authorises every channel, so leaving it in place would make
     * this assert nothing at all.
     */
    private function authorises(User $user, string $channel): bool
    {
        config(['broadcasting.default' => 'pusher']);

        $request = Request::create('/api/broadcasting/auth', 'POST', [
            'channel_name' => 'private-'.$channel,
            'socket_id' => '1.1',
        ]);
        $request->setUserResolver(fn () => $user);

        try {
            app(BroadcastManager::class)->auth($request);

            return true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }

    /** A user with no employment record polls harmlessly rather than erroring. */
    public function test_a_user_without_an_employee_record_gets_an_empty_feed(): void
    {
        $user = new User();
        $user->fill(['name' => 'ghost', 'email' => 'ghost@test.com', 'password' => 'password123']);
        $user->role = 'employee';
        $user->status = 'active';
        $user->save();

        $this->getJson('/api/sync', $this->auth('ghost@test.com'))
            ->assertOk()
            ->assertJsonPath('unread.messages', 0);
    }

    /**
     * The guard that would have caught the qa/intern gap.
     *
     * A role in the permission grid that belongs to no audience is a role whose
     * holders every broadcast silently skips — the announcement still sends and
     * still reports success. This fails instead.
     */
    public function test_every_role_belongs_to_a_broadcast_audience(): void
    {
        $unassigned = app(\App\Services\BroadcastAudiences::class)->unassignedRoles();

        $this->assertSame(
            [],
            $unassigned,
            'These roles receive no broadcast: '.implode(', ', $unassigned)
                .'. Add them to an audience in BroadcastAudiences.'
        );
    }

    public function test_the_audience_list_is_served_with_recipient_counts(): void
    {
        // worker@test.com (employee) already exists from setUp.
        $this->makeEmployee('boss@test.com', 'super_admin');
        $this->makeEmployee('tester@test.com', 'qa');

        $audiences = $this->getJson('/api/messages/audiences', $this->auth('boss@test.com'))
            ->assertOk()
            ->json('audiences');

        $byId = collect($audiences)->keyBy('id');

        // QA counts as an employee for announcements, which is the whole point
        // of the grouping living in one place.
        $this->assertSame(2, $byId['employees']['reaches']);
        $this->assertSame('All employees', $byId['employees']['label']);
    }

    /**
     * Broadcasting is reaching the whole company, which is what scope 'all'
     * means — so anyone narrower is offered nothing rather than a list that
     * would be refused on send.
     */
    public function test_someone_who_may_not_broadcast_is_offered_no_audiences(): void
    {
        $this->getJson('/api/messages/audiences', $this->auth('worker@test.com'))
            ->assertOk()
            ->assertJsonPath('audiences', []);
    }

    /**
     * Being messaged is permission to answer.
     *
     * An employee's reachable set is supervisors plus HR, so a broadcast from
     * the super admin — neither of those — could be read but not replied to.
     * The reply came back "You may not message those people", which makes an
     * announcement a dead end.
     *
     * Driven through the service rather than two authenticated requests: the
     * guard caches the first user it resolves, so a second HTTP call with a
     * different token still answers as the first.
     */
    public function test_an_employee_may_reply_to_someone_who_messaged_them(): void
    {
        $admin = $this->makeEmployee('boss@test.com', 'super_admin');
        $service = app(MessageService::class);
        $adminUser = User::where('email', 'boss@test.com')->firstOrFail();
        $workerUser = User::where('email', 'worker@test.com')->firstOrFail();

        // Before the broadcast the admin is unreachable, as designed: this
        // opens a reply channel, it does not let anyone start a conversation.
        try {
            $service->send($workerUser, [
                'body' => 'Unsolicited',
                'audience' => 'Direct message',
                'recipientIds' => [$admin->id],
            ]);
            $this->fail('An employee should not be able to open a conversation with the admin.');
        } catch (AccessDeniedHttpException) {
            // expected
        }

        $service->send($adminUser, [
            'body' => 'Company announcement',
            'audience' => 'employees',
            'recipientIds' => [],
        ]);

        // Having received it, the employee can now answer.
        $sent = $service->send($workerUser, [
            'body' => 'Understood',
            'audience' => 'Direct message',
            'recipientIds' => [$admin->id],
        ]);

        // send() returns the Message, so the delivery rows are what to check.
        $this->assertSame(
            [$admin->id],
            $sent->recipients()->pluck('employee_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    /**
     * And the composer can name them: contacts is what the reply hint reads,
     * and an unreachable sender rendered as "Unknown user".
     */
    public function test_a_broadcast_sender_appears_in_the_recipients_contacts(): void
    {
        $admin = $this->makeEmployee('boss@test.com', 'super_admin');
        $service = app(MessageService::class);

        $service->send(User::where('email', 'boss@test.com')->firstOrFail(), [
            'body' => 'Company announcement',
            'audience' => 'employees',
            'recipientIds' => [],
        ]);

        $contacts = $this->getJson('/api/messages', $this->auth('worker@test.com'))
            ->assertOk()
            ->json('contacts');

        $this->assertContains($admin->id, array_column($contacts, 'id'));
    }
}

<?php

namespace App\Services;

use App\Events\FeedUpdated;
use App\Events\PresenceChanged;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Messages and notifications.
 *
 * The sync() method is called every few seconds by every signed-in client, so
 * it is written to be the cheapest query in the application: two indexed
 * lookups by (employee, id) and nothing else. No joins that scan, no counts
 * over the whole table, no permission resolution — the recipient rows already
 * encode who may see what, because delivery is decided at send time.
 *
 * That last point is the important one. A broadcast fans out to concrete
 * recipient rows when it is sent, rather than being matched against an
 * audience on every poll. Resolving "who counts as All employees" thousands
 * of times a day to answer a question whose result never changes would be the
 * one thing guaranteed to make polling expensive.
 */
class MessageService
{
    /**
     * Broadcast audiences, keyed by the id Messages.vue sends.
     *
     * Resolved here rather than taken from the client's recipient list: a
     * broadcast names a group, and the group is what decides who receives it.
     * 'all_company' is absent deliberately — it means "everyone in scope",
     * which is the fallback.
     */
    /**
     * How long a chosen presence lasts.
     *
     * Long enough to cover a working day, short enough that an "away" set on
     * Friday is gone by Monday. Online/offline is unaffected — that comes from
     * the socket and needs no expiry.
     */
    private const PRESENCE_TTL_HOURS = 12;


    public function __construct(
        private readonly BroadcastAudiences $audiences,
        private readonly PermissionService $permissions,
        private readonly ScopeResolver $scopes,
    ) {
    }

    /**
     * Everything new for this user since their cursor.
     *
     * The cursors are separate because messages and notifications advance
     * independently — a shared one would replay whichever feed was behind.
     *
     * @return array{messages: array, notifications: array, cursors: array, unread: array}
     */
    public function sync(
        User $user,
        ?int $sinceMessage = null,
        ?int $sinceNotification = null,
        ?int $sinceReceipt = null
    ): array {
        $employee = $this->employeeFor($user);

        if (! $employee) {
            // No employment record means nothing is addressed to them. An
            // empty result rather than an error: the client polls this
            // constantly and a 4xx would look like a broken connection.
            return [
                'messages' => [],
                'notifications' => [],
                'receipts' => [],
                'cursors' => ['message' => 0, 'notification' => 0, 'receipt' => 0],
                'unread' => ['messages' => 0, 'notifications' => 0],
            ];
        }

        // A first poll (no cursor) establishes where to resume from; it does
        // not replay history. The Messages page loads that from /messages,
        // which returns both sides of a conversation — this endpoint only ever
        // sees messages addressed TO this person, so replaying here would
        // deliver half a thread and race the page's own load to render it.
        $isFirstPoll = $sinceMessage === null && $sinceNotification === null;

        $messages = $isFirstPoll
            ? collect()
            : MessageRecipient::query()
                // Only the ids, not whole rows. The view needs recipientIds to
                // group a conversation; hydrating every delivery row meant a
                // company-wide broadcast pulled one model per employee into
                // memory on every poll, for a list the client only counts.
                ->with([
                    'message.sender:id,name',
                    'message.recipients:id,message_id,employee_id',
                ])
                ->where('employee_id', $employee->id)
                ->where('message_id', '>', $sinceMessage)
                ->orderBy('message_id')
                // A client that has been offline for a long time gets the most
                // recent slice rather than the entire history in one response.
                ->limit(100)
                ->get();

        // Notifications DO replay on a first poll: unlike messages they have
        // no page of their own to load from, so the bell would be empty until
        // something new happened.
        $notifications = Notification::query()
            ->where('employee_id', $employee->id)
            ->when($sinceNotification, fn ($query) => $query->where('id', '>', $sinceNotification))
            ->orderBy('id')
            ->limit(100)
            ->get();

        // Read state on messages this person SENT. Their own poll would never
        // surface these — it reads rows addressed to them, and a sender is not
        // among their own recipients — so without this a delivery tick would
        // stay single until the page was reloaded.
        ['receipts' => $receipts, 'cursor' => $receiptCursor] =
            $this->readReceiptsFor($employee, $sinceReceipt);

        $hasNews = $messages->isNotEmpty()
            || $notifications->isNotEmpty()
            || $receipts !== [];

        return [
            'messages' => $messages->all(),
            'notifications' => $notifications->all(),
            'receipts' => $receipts,
            // The highest id seen, so the next poll resumes exactly here. Sent
            // back even when nothing arrived, so a client that has just signed
            // in does not replay everything on its second tick.
            'cursors' => [
                'message' => (int) ($messages->max('message_id')
                    ?: $sinceMessage
                    ?: ($isFirstPoll ? $this->latestMessageId($employee) : 0)),
                'notification' => (int) ($notifications->max('id')
                    ?: $sinceNotification
                    ?: ($isFirstPoll ? $this->latestNotificationId($employee) : 0)),
                // Highest delivery row seen, so the next poll asks only for
                // reads that happened after it.
                'receipt' => $receiptCursor,
            ],
            // Counted only when there is something to report. The steady state
            // is a poll that finds nothing and is discarded as a 204, so two
            // COUNT queries on every tick would be the most expensive part of
            // the cheapest request in the application.
            'unread' => ($hasNews || $isFirstPoll)
                ? [
                    'messages' => MessageRecipient::where('employee_id', $employee->id)
                        ->whereNull('read_at')->count(),
                    'notifications' => Notification::where('employee_id', $employee->id)
                        ->whereNull('read_at')->count(),
                ]
                : ['messages' => 0, 'notifications' => 0],
        ];
    }

    /**
     * Set by inbox(): whether older messages exist beyond the page returned,
     * and the oldest id in it. Held here rather than returned so the method's
     * shape stays a plain list — the controller reads them straight after.
     */
    private bool $lastInboxHasMore = false;

    private int $lastInboxOldestId = 0;

    public function inboxHasMore(): bool
    {
        return $this->lastInboxHasMore;
    }

    public function inboxOldestId(): int
    {
        return $this->lastInboxOldestId;
    }

    /**
     * The conversation history for the Messages page.
     *
     * Both sides: messages addressed to this person, and the ones they sent.
     * A sender is not among their own recipients — delivery rows exist for the
     * people being written to — so reading only message_recipients would show
     * a conversation with every reply and none of your own replies in it.
     *
     * Returns messages rather than delivery rows, with the recipient ids the
     * view needs to group a conversation and decide which side to render.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inbox(User $user, int $limit = 100, ?int $before = null): array
    {
        $employee = $this->requireEmployee($user);

        $messages = Message::query()
            ->with(['sender:id,name', 'recipients:id,message_id,employee_id,read_at'])
            ->where(fn ($query) => $query
                ->where('sender_id', $employee->id)
                ->orWhereHas('recipients', fn ($r) => $r->where('employee_id', $employee->id)))
            // Older than what the client already holds. Keyset paging on id
            // rather than an offset: an offset shifts under you the moment a
            // new message arrives, which in a live conversation is constantly.
            ->when($before, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            // One extra row, purely to answer "is there more?" without a
            // second COUNT over the whole conversation.
            ->limit($limit + 1)
            ->get();

        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit);

        $this->lastInboxHasMore = $hasMore;
        $this->lastInboxOldestId = (int) ($messages->min('id') ?? 0);

        return $messages->map(fn (Message $message) => [
            'message' => $message,
            // Read state belongs to the recipient row, so a message this
            // person sent has none — it is theirs and counts as read.
            'readAt' => $message->recipients
                ->firstWhere('employee_id', $employee->id)?->read_at,
            'isMine' => $message->sender_id === $employee->id,
        ])->all();
    }

    /**
     * Sends a message and fans it out to its recipients.
     *
     * Who may be addressed is decided by the sender's Messages scope, not by
     * the ids the client sends — an employee whose scope is 'self' cannot
     * message the whole company by posting a longer list.
     */
    public function send(User $user, array $attributes): Message
    {
        $sender = $this->requireEmployee($user);

        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Messages');

        if (! $row || ! ($row['create'] ?? false)) {
            throw new AccessDeniedHttpException('You may not send messages.');
        }

        $audience = $attributes['audience'] ?? 'Direct message';
        $recipientIds = $this->resolveRecipients($user, $sender, $row['scope'], $audience, $attributes['recipientIds'] ?? []);

        if ($recipientIds === []) {
            throw new AccessDeniedHttpException('You may not message those people.');
        }

        $message = DB::transaction(function () use ($sender, $audience, $attributes, $recipientIds) {
            $message = Message::create([
                'sender_id' => $sender->id,
                'audience' => $audience,
                'subject' => $attributes['subject'] ?? null,
                'body' => $attributes['body'],
            ]);

            // One insert for the whole fan-out rather than a row at a time —
            // a company-wide broadcast is a single statement.
            $now = now();
            MessageRecipient::insert(array_map(fn (int $id) => [
                'message_id' => $message->id,
                'employee_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $recipientIds));

            return $message->load('sender:id,name', 'recipients');
        });

        // After the commit, never inside it: the message is delivered the
        // moment its recipient rows exist, and a ping is only an accelerator.
        $this->announce($recipientIds, 'message');

        return $message;
    }

    public function markMessageRead(User $user, int $messageId): MessageRecipient
    {
        $employee = $this->requireEmployee($user);

        $recipient = MessageRecipient::where('employee_id', $employee->id)
            ->where('message_id', $messageId)
            ->first();

        if (! $recipient) {
            // 404 rather than 403: a message not addressed to them should not
            // be confirmed to exist.
            throw new NotFoundHttpException('Message not found.');
        }

        if (! $recipient->read_at) {
            $recipient->update(['read_at' => now()]);

            // Tell the sender so their tick turns double without waiting for
            // a page reload. Only on the transition — re-opening a thread
            // must not ping them again for every message in it.
            $senderId = Message::whereKey($messageId)->value('sender_id');
            if ($senderId) {
                $this->announce([$senderId], 'message');
            }
        }

        return $recipient;
    }

    /** Marks every unread message read — the "mark all" action. */
    public function markAllMessagesRead(User $user): int
    {
        $employee = $this->requireEmployee($user);

        return MessageRecipient::where('employee_id', $employee->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markNotificationRead(User $user, int $notificationId): Notification
    {
        $employee = $this->requireEmployee($user);

        $notification = Notification::where('employee_id', $employee->id)
            ->where('id', $notificationId)
            ->first();

        if (! $notification) {
            throw new NotFoundHttpException('Notification not found.');
        }

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return $notification;
    }

    public function markAllNotificationsRead(User $user): int
    {
        $employee = $this->requireEmployee($user);

        return Notification::where('employee_id', $employee->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Raises a notification for a set of employees.
     *
     * Called by the other services when something happens that someone should
     * hear about — a leave decision, a claim approval, a payroll release.
     *
     * @param  array<int, int>  $employeeIds
     */
    public function notify(array $employeeIds, array $attributes): int
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds)));

        if ($employeeIds === []) {
            return 0;
        }

        $now = now();

        Notification::insert(array_map(fn (int $id) => [
            'employee_id' => $id,
            'title' => $attributes['title'],
            'body' => $attributes['body'] ?? null,
            'type' => $attributes['type'] ?? 'general',
            'route_name' => $attributes['routeName'] ?? null,
            'route_params' => isset($attributes['routeParams'])
                ? json_encode($attributes['routeParams'])
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $employeeIds));

        $this->announce($employeeIds, 'notification');

        return count($employeeIds);
    }

    /**
     * Pings each recipient over Pusher so their client fetches immediately
     * instead of waiting for its next poll.
     *
     * Wrapped in a try/catch on purpose. Broadcasting is an accelerator, not
     * the delivery mechanism — the recipient rows are already committed, so a
     * Pusher outage, a bad key, or the office internet dropping must not turn
     * a successful send into a 500. The message still arrives on the next
     * 3-second tick; it just arrives less quickly.
     *
     * @param  array<int, int>  $employeeIds
     */
    private function announce(array $employeeIds, string $type): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        $tenantId = tenant('id');

        if (! $tenantId) {
            return;
        }

        // Chunked at 100 because that is Pusher's per-trigger channel limit.
        // One call per chunk rather than one per recipient: a 40-person
        // broadcast was 40 sequential round trips to Mumbai, all of them on
        // the request the sender was waiting for.
        foreach (array_chunk(array_map('intval', $employeeIds), 100) as $chunk) {
            try {
                FeedUpdated::dispatch($chunk, (string) $tenantId, $type);
            } catch (\Throwable $e) {
                // Logged rather than surfaced: the caller's write succeeded,
                // and the recipients get it on their next poll regardless.
                report($e);
            }
        }
    }

    /**
     * The people this user may start a conversation with.
     *
     * Exists so the compose dropdown does not have to read the full employee
     * roster: an employee holds no Employees.view permission, so that request
     * is a 403 for the most common role — and it would be the wrong list
     * anyway, since it is everyone rather than the few people they may
     * actually write to.
     *
     * @return array<int, array{id: int, name: string, role: string}>
     */
    public function contacts(User $user): array
    {
        $employee = $this->employeeFor($user);

        if (! $employee) {
            return [];
        }

        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Messages');

        if (! $row || ! ($row['view'] ?? false)) {
            throw new AccessDeniedHttpException('You do not have permission to do that.');
        }

        $ids = $this->reachableEmployeeIds($user, $employee, $row['scope']);

        return Employee::with('user:id,role')
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'role' => $contact->user?->role ?? 'employee',
            ])
            ->all();
    }

    /**
     * Sets this person's chosen status.
     *
     * Only the manual half lives here — online and offline come from whether a
     * browser is subscribed to the presence channel. Passing null clears the
     * override, so presence falls back to whatever the connection says.
     */
    public function setPresence(User $user, ?string $status, ?string $message = null): Employee
    {
        $employee = $this->requireEmployee($user);

        $employee->update([
            'presence_status' => $status,
            // A stale "In a meeting until 3" is worse than none, so the note
            // is cleared whenever the status is.
            'presence_message' => $status ? $message : null,
            'presence_updated_at' => now(),
        ]);

        // Everyone else's roster entry for this person is now out of date, and
        // the presence channel only pushes join and leave — not a change of
        // status while someone stays connected.
        $this->announcePresence($employee);

        return $employee->refresh();
    }

    /**
     * The chosen statuses of everyone in the company.
     *
     * Who is *connected* comes from the presence channel in the browser; this
     * supplies the half Pusher cannot know. Merged client-side: a person can
     * be online and busy at the same time, and those are two different facts
     * from two different sources.
     *
     * @return array<int, array{id: int, status: string, message: ?string}>
     */
    public function presenceStatuses(User $user): array
    {
        $this->requireEmployee($user);

        return Employee::query()
            ->whereNotNull('presence_status')
            ->where('status', 'active')
            // Stale overrides are ignored rather than returned. Nobody means
            // "away" to outlive the day they set it, and presence_updated_at
            // was being written and never read — so someone who marked
            // themselves away weeks ago stayed away forever.
            ->where('presence_updated_at', '>', now()->subHours(self::PRESENCE_TTL_HOURS))
            ->get(['id', 'presence_status', 'presence_message'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'status' => $employee->presence_status,
                'message' => $employee->presence_message,
            ])
            ->all();
    }

    /**
     * Tells everyone that one person's chosen status changed.
     *
     * Broadcast on the shared presence channel rather than per-employee: this
     * is company-wide information, and one event beats a fan-out to every
     * subscriber individually.
     */
    private function announcePresence(Employee $employee): void
    {
        if (config('broadcasting.default') === 'null' || ! tenant('id')) {
            return;
        }

        try {
            PresenceChanged::dispatch(
                $employee->id,
                (string) tenant('id'),
                $employee->presence_status ?? 'available',
                $employee->presence_message,
            );
        } catch (\Throwable $e) {
            // The status is saved either way; the socket is only how others
            // hear about it sooner than their next page load.
            report($e);
        }
    }

    /** Employees this user is allowed to notify by role, for broadcasts. */
    public function employeeIdsForRoles(array $roles): array
    {
        return Employee::query()
            // Active only. An invited employee who has never signed in still
            // has a row, and delivering to them creates a recipient record
            // nobody can ever read — so the sender's tick could never reach
            // "everyone has read this" and stayed single forever.
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->whereIn('role', $roles))
            ->pluck('id')
            ->all();
    }

    // --- internals -------------------------------------------------------

    /**
     * The concrete delivery list for a send.
     *
     * A broadcast expands to everyone the sender's scope covers; a direct
     * message is intersected with it. Either way the result is bounded by
     * what the sender may reach — the client's list is a request, not the
     * decision.
     *
     * @return array<int, int>
     */
    private function resolveRecipients(
        User $user,
        Employee $sender,
        string $scope,
        string $audience,
        array $requested
    ): array {
        $reachable = $this->reachableEmployeeIds($user, $sender, $scope);

        if ($audience === 'Direct message') {
            $requested = array_map('intval', $requested);

            return array_values(array_intersect($reachable, $requested));
        }

        // A broadcast names a group, and the group decides the recipients —
        // not the id list the client sent. Trusting that list would let a
        // "broadcast to HR" quietly deliver to anyone the sender chose to
        // enumerate.
        $roles = $this->audiences->rolesFor($audience);

        if ($roles !== null) {
            return array_values(array_intersect($reachable, $this->employeeIdsForRoles($roles)));
        }

        // 'all_company' and anything unrecognised fall back to everyone the
        // sender may reach, which their scope has already bounded.
        return $reachable;
    }

    /**
     * The audiences the signed-in user may broadcast to, each with how many
     * people it currently reaches.
     *
     * Served rather than hardcoded in the client, which is what let the two
     * definitions drift. The count is the safeguard: a wrong audience is
     * visible before sending rather than after nobody replies.
     *
     * @return array<int, array{id: string, label: string, reaches: int}>
     */
    public function audiencesFor(User $user): array
    {
        $sender = $this->employeeFor($user);
        $row = collect($this->permissions->forUser($user))->firstWhere('module', 'Messages');

        // Broadcasting is reaching the whole company, which is what scope 'all'
        // already means. The client used to test the role name instead — a
        // second, weaker answer to a question the grid answers.
        if (! $sender || ($row['scope'] ?? null) !== 'all' || ! ($row['create'] ?? false)) {
            return [];
        }

        $reachable = $this->reachableEmployeeIds($user, $sender, $row['scope']);

        return collect($this->audiences->ids())
            ->map(function (string $id) use ($reachable) {
                $roles = $this->audiences->rolesFor($id);

                $recipients = $roles === null
                    ? $reachable
                    : array_intersect($reachable, $this->employeeIdsForRoles($roles));

                return [
                    'id' => $id,
                    'label' => $this->audiences->label($id),
                    'reaches' => count($recipients),
                ];
            })
            // An audience nobody is in is noise in the picker.
            ->filter(fn (array $audience) => $audience['reaches'] > 0)
            ->values()
            ->all();
    }

    /**
     * Who this person may message.
     *
     * Messaging scope does not mean the same thing as it does everywhere else.
     * For leave or payroll, 'self' means "your own records" — a sensible
     * restriction. Applied literally to messages it would mean an employee
     * could write to nobody but themselves, which is not a permission model,
     * it is a broken feature.
     *
     * So 'self' is read as "the people you report to": the managers of
     * projects you are on, the leads of modules you are in, plus HR — who
     * everyone must be able to reach regardless of project. This mirrors
     * directRecipients() in Messages.vue, which has always offered exactly
     * these contacts; the API was refusing what the UI presented.
     *
     * @return array<int, int>
     */
    private function reachableEmployeeIds(User $user, Employee $sender, string $scope): array
    {
        if ($scope === 'self') {
            $ids = collect($this->scopes->supervisorIds($sender))
                ->merge($this->employeeIdsForRoles(['hr']))
                ->merge($this->correspondentIds($sender));

            return array_values(array_diff($ids->unique()->all(), [$sender->id]));
        }

        $allowed = $this->scopes->visibleEmployeeIds($user, $scope);

        // null means 'all' — no constraint beyond excluding the sender.
        $reachable = $allowed === null
            ? Employee::where('status', 'active')->pluck('id')->all()
            : $allowed;

        // Anyone below 'all' still needs a route upward: a lead with scope
        // 'owned' sees their squad but must be able to reach HR too.
        if ($allowed !== null) {
            $reachable = array_merge(
                $reachable,
                $this->employeeIdsForRoles(['hr']),
                $this->correspondentIds($sender)
            );
        }

        $reachable = array_values(array_diff(array_unique($reachable), [$sender->id]));

        // ScopeResolver works from project membership and does not know about
        // employment status, so a scoped sender could still address someone
        // who has left or never arrived.
        return Employee::whereIn('id', $reachable)
            ->where('status', 'active')
            ->pluck('id')
            ->all();
    }

    /**
     * People who have written to this employee.
     *
     * Being messaged is itself permission to answer. Without this an employee's
     * reachable set is supervisors plus HR, so a company announcement from the
     * super admin — who is neither — could be read but not replied to: the
     * reply was refused with "You may not message those people", and the
     * composer could not even name the person it was addressing.
     *
     * Deliberately one-directional. It opens a reply channel to someone who
     * started a conversation; it does not let anyone start one.
     *
     * @return array<int, int>
     */
    private function correspondentIds(Employee $employee): array
    {
        return DB::table('message_recipients')
            ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
            ->where('message_recipients.employee_id', $employee->id)
            ->distinct()
            ->pluck('messages.sender_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Delivery counts for messages this person sent, for their ticks.
     *
     * Bounded by the highest delivery-row id already seen rather than by a
     * timestamp. An id is monotonic and unambiguous; comparing times meant
     * comparing an app-generated value against a column the database writes,
     * and any clock or timezone difference between them silently returned
     * nothing.
     *
     * @return array{receipts: array<int, array{id: int, total: int, read: int}>, cursor: int}
     */
    private function readReceiptsFor(Employee $employee, ?int $sinceReceiptId): array
    {
        $readRows = MessageRecipient::query()
            ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
            ->where('messages.sender_id', $employee->id)
            ->whereNotNull('message_recipients.read_at')
            ->when(
                $sinceReceiptId,
                fn ($query) => $query->where('message_recipients.id', '>', $sinceReceiptId),
            )
            ->orderBy('message_recipients.id')
            ->limit(100)
            ->get(['message_recipients.id', 'message_recipients.message_id']);

        $cursor = (int) ($readRows->max('id') ?: $sinceReceiptId);

        if ($readRows->isEmpty()) {
            return ['receipts' => [], 'cursor' => $cursor];
        }

        $receipts = MessageRecipient::query()
            ->selectRaw('message_id, COUNT(*) as total, COUNT(read_at) as read_count')
            ->whereIn('message_id', $readRows->pluck('message_id')->unique())
            ->groupBy('message_id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->message_id,
                'total' => (int) $row->total,
                'read' => (int) $row->read_count,
            ])
            ->all();

        return ['receipts' => $receipts, 'cursor' => $cursor];
    }

    private function latestMessageId(Employee $employee): int
    {
        return (int) MessageRecipient::where('employee_id', $employee->id)->max('message_id');
    }

    private function latestNotificationId(Employee $employee): int
    {
        return (int) Notification::where('employee_id', $employee->id)->max('id');
    }

    private function requireEmployee(User $user): Employee
    {
        $employee = $this->employeeFor($user);

        if (! $employee) {
            throw new AccessDeniedHttpException('Only employees can use messages.');
        }

        return $employee;
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::where('user_id', $user->id)->first();
    }
}

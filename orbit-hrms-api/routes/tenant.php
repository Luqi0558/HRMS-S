<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyHubController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeInvitationController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\MedicalClaimController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PettyCashController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

/*
|--------------------------------------------------------------------------
| Tenant routes
|--------------------------------------------------------------------------
|
| Every route here runs against one company's database. Tenancy is resolved
| from the X-Tenant header rather than the domain, because the SPA is served
| from its own origin (localhost:5174) and does not share a hostname with the
| API — domain resolution would have nothing to match on.
|
| InitializeTenancyByRequestData must come first: without an active tenant the
| queries below would hit the central connection, where `users` does not exist.
|
| PreventAccessFromCentralDomains is deliberately NOT applied. It exists to stop
| tenant routes resolving on a central hostname, which only makes sense for
| domain-based tenancy — with header-based resolution the API and the central
| app share a hostname, so it 404s every request before tenancy is even checked.
| The X-Tenant header is what scopes these routes.
|
*/

Route::middleware([
    'api',
    InitializeTenancyByRequestData::class,
])->prefix('api')->group(function () {

    Route::middleware('throttle:6,1')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->name('api.login');

        Route::post('/onboarding/resolve', [OnboardingController::class, 'resolve'])
            ->name('api.onboarding.resolve');

        Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])
            ->name('api.onboarding.complete');
    });

    /*
     * Authenticated.
     */
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])
            ->name('api.logout.all');

        Route::get('/me/permissions', [PermissionController::class, 'mine'])
            ->name('api.me.permissions');

        /*
         * Own profile. No module guard: the subject is the bearer token, not a
         * route parameter, so there is no other record these can reach. The
         * Employees routes below cannot serve this — they are gated on a module
         * ordinary employees do not hold, which would 403 exactly the people
         * who need to edit their own details.
         */
        Route::get('/profile', [ProfileController::class, 'show'])
            ->name('api.profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])
            ->name('api.profile.update');

        // Throttled harder than the rest: this endpoint verifies a password, so
        // it is worth guessing against.
        Route::post('/profile/password', [ProfileController::class, 'changePassword'])
            ->middleware('throttle:6,1')
            ->name('api.profile.password');

        Route::get('/employees', [EmployeeController::class, 'index'])
            ->middleware('can.module:Employees,view')
            ->name('api.employees.index');

        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])
            ->whereNumber('employee')
            ->name('api.employees.show');

        Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])
            ->whereNumber('employee')
            ->middleware('can.module:Employees,create')
            ->name('api.employees.update');


        Route::get('/projects', [ProjectController::class, 'index'])
            ->middleware('can.module:Projects,view')
            ->name('api.projects.index');

        Route::post('/projects', [ProjectController::class, 'store'])
            ->middleware('can.module:Projects,create')
            ->name('api.projects.store');

        Route::get('/projects/{project}', [ProjectController::class, 'show'])
            ->middleware('can.module:Projects,view')
            ->name('api.projects.show');

        Route::patch('/projects/{project}', [ProjectController::class, 'update'])
            ->middleware('can.module:Projects,create')
            ->name('api.projects.update');

        Route::put('/projects/{project}/members', [ProjectController::class, 'syncMembers'])
            ->middleware('can.module:Projects,create')
            ->name('api.projects.members');


        /*
         * Modules. These did not exist: the client created and edited modules
         * in local state only, so every one of them vanished on refresh.
         */
        Route::post('/projects/{project}/modules', [ProjectController::class, 'storeModule'])
            ->middleware('can.module:Projects,create')
            ->name('api.modules.store');

        Route::patch('/modules/{module}', [ProjectController::class, 'updateModule'])
            ->whereNumber('module')
            ->middleware('can.module:Projects,create')
            ->name('api.modules.update');

        Route::put('/modules/{module}/members', [ProjectController::class, 'syncModuleMembers'])
            ->whereNumber('module')
            ->middleware('can.module:Projects,create')
            ->name('api.modules.members');

        Route::patch('/modules/{module}/delivery', [ProjectController::class, 'setModuleDelivered'])
            ->whereNumber('module')
            ->middleware('can.module:Projects,approve')
            ->name('api.modules.delivery');

        /*
         * Tickets. `update` is deliberately gated only on view: an assignee may
         * move their own ticket through the board without holding create,
         * which TicketService enforces per record.
         */
        Route::get('/tickets', [TicketController::class, 'index'])
            ->middleware('can.module:Tickets,view')
            ->name('api.tickets.index');

        Route::post('/tickets', [TicketController::class, 'store'])
            ->middleware('can.module:Tickets,create')
            ->name('api.tickets.store');
            
        Route::post('/tickets/stop-timer', [TicketController::class, 'stopTimer'])
            ->middleware('can.module:Tickets,view')
            ->name('api.tickets.stop-timer');

        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
            ->whereNumber('ticket')
            ->middleware('can.module:Tickets,view')
            ->name('api.tickets.show');

        Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])
            ->whereNumber('ticket')
            ->middleware('can.module:Tickets,view')
            ->name('api.tickets.update');

        Route::post('/tickets/{ticket}/comments', [TicketController::class, 'comment'])
            ->whereNumber('ticket')
            ->middleware('can.module:Tickets,view')
            ->name('api.tickets.comment');

        Route::post('/tickets/{ticket}/start-timer', [TicketController::class, 'startTimer'])
            ->whereNumber('ticket')
            ->middleware('can.module:Tickets,view')
            ->name('api.tickets.start-timer');

        /*
         * Company policy. Readable by anyone signed in — the client needs the
         * working day and leave allowances to render correctly — but only
         * writable with Settings.create.
         */
        Route::get('/settings', [SettingsController::class, 'index'])
            ->name('api.settings.index');
        Route::get('/settings/lists', [SettingsController::class, 'lists'])
            ->name('api.settings.lists');
        Route::post('/settings/lists/{type}', [SettingsController::class, 'addListItem'])
            ->whereIn('type', ['designations', 'stacks'])
            ->middleware('can.module:Settings,create')
            ->name('api.settings.lists.add');
        Route::delete('/settings/lists/{type}', [SettingsController::class, 'removeListItem'])
            ->whereIn('type', ['designations', 'stacks'])
            ->middleware('can.module:Settings,create')
            ->name('api.settings.lists.remove');

        // Above the {group} route so "integrations" is not swallowed by it.
        Route::get('/settings/integrations', [SettingsController::class, 'integrations'])
            ->middleware('can.module:Settings,create')
            ->name('api.settings.integrations');

        Route::put('/settings/{group}', [SettingsController::class, 'update'])
            ->middleware('can.module:Settings,create')
            ->name('api.settings.update');

        /*
         * Attendance. `index` is ungated: an employee holds no Attendance row
         * but must see their own punch history, which the service enforces.
         */
        Route::get('/attendance', [AttendanceController::class, 'index'])
            ->name('api.attendance.index');
        Route::get('/attendance/today', [AttendanceController::class, 'today'])
            ->name('api.attendance.today');
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn'])
            ->name('api.attendance.check-in');
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut'])
            ->name('api.attendance.check-out');
        // Below check-in/check-out so those literal segments are never mistaken
        // for an {attendance} id.
        Route::get('/attendance/{attendance}/photo', [AttendanceController::class, 'photo'])
            ->whereNumber('attendance')
            ->name('api.attendance.photo');

        /*
         * Leave. Fixed segments precede the wildcard so /leave-requests/balance
         * is not parsed as an id.
         */
        Route::get('/leave-requests', [LeaveRequestController::class, 'index'])
            ->middleware('can.module:Leave Request,view')
            ->name('api.leave.index');
        Route::get('/leave-requests/balance', [LeaveRequestController::class, 'balance'])
            ->name('api.leave.balance');
        Route::post('/leave-requests', [LeaveRequestController::class, 'store'])
            ->middleware('can.module:Leave Request,create')
            ->name('api.leave.store');
        Route::post('/leave-requests/{leaveRequest}/decision', [LeaveRequestController::class, 'decide'])
            ->whereNumber('leaveRequest')
            ->name('api.leave.decide');
        Route::post('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])
            ->whereNumber('leaveRequest')
            ->name('api.leave.cancel');

        /*
         * Medical claims.
         */
        Route::get('/medical-claims', [MedicalClaimController::class, 'index'])
            ->middleware('can.module:Medical Request,view')
            ->name('api.claims.index');
        Route::get('/medical-claims/usage', [MedicalClaimController::class, 'usage'])
            ->name('api.claims.usage');
        Route::post('/medical-claims', [MedicalClaimController::class, 'store'])
            ->middleware('can.module:Medical Request,create')
            ->name('api.claims.store');

        Route::get('/medical-claims/{claim}/receipt', [MedicalClaimController::class, 'receipt'])
            ->whereNumber('claim')
            ->name('api.claims.receipt');
        Route::post('/medical-claims/{claim}/decision', [MedicalClaimController::class, 'decide'])
            ->whereNumber('claim')
            ->middleware('can.module:Medical Request,approve')
            ->name('api.claims.decide');

        /*
         * Payroll. Every route is ungated at the middleware: Payroll scope is
         * 'self' for all roles but HR, so an employee holds the row but must
         * only ever reach their own pay. PayrollService applies that scope per
         * record, and answers 404 rather than 403 outside it — confirming a
         * salary exists is itself a disclosure.
         *
         * Fixed segments precede the wildcards so /salary-slips/cost is not
         * parsed as an id.
         */
        Route::get('/salary-slip-requests', [PayrollController::class, 'requests'])
            ->name('api.payroll.requests');
        Route::post('/salary-slip-requests', [PayrollController::class, 'storeRequest'])
            ->name('api.payroll.requests.store');
        Route::post('/salary-slip-requests/{slipRequest}/decision', [PayrollController::class, 'decideRequest'])
            ->whereNumber('slipRequest')
            ->name('api.payroll.requests.decide');

        Route::get('/salary-slips/cost', [PayrollController::class, 'cost'])
            ->name('api.payroll.cost');
        Route::get('/salary-slips', [PayrollController::class, 'slips'])
            ->name('api.payroll.slips');
        Route::get('/salary-slips/{slip}', [PayrollController::class, 'showSlip'])
            ->whereNumber('slip')
            ->name('api.payroll.slips.show');

        Route::post('/salary-slips/{slip}/approve', [PayrollController::class, 'approveSlip'])
            ->whereNumber('slip')
            ->name('api.payroll.slips.approve');
        Route::post('/salary-slips/{slip}/payment', [PayrollController::class, 'markPaid'])
            ->whereNumber('slip')
            ->name('api.payroll.slips.payment');
        Route::post('/salary-slips/{slip}/reopen', [PayrollController::class, 'reopenSlip'])
            ->whereNumber('slip')
            ->name('api.payroll.slips.reopen');

        Route::get('/employees/{employee}/salaries', [PayrollController::class, 'salaryHistory'])
            ->whereNumber('employee')
            ->name('api.payroll.salaries');
        Route::post('/employees/{employee}/salaries', [PayrollController::class, 'setSalary'])
            ->whereNumber('employee')
            ->name('api.payroll.salaries.store');

        /*
         * Petty cash. Gated on the module's own rights: view to read, create
         * to record, approve to sign off spend above the threshold. HR holds
         * create but not approve, so its large spend waits for a super admin —
         * which is the separation the tin exists to provide.
         */
        Route::get('/petty-cash', [PettyCashController::class, 'index'])
            ->middleware('can.module:Petty Cash,view')
            ->name('api.petty-cash.index');

        Route::post('/petty-cash/top-ups', [PettyCashController::class, 'storeTopUp'])
            ->middleware('can.module:Petty Cash,create')
            ->name('api.petty-cash.top-ups.store');
        Route::post('/petty-cash/top-ups/{topUp}/decision', [PettyCashController::class, 'decideTopUp'])
            ->whereNumber('topUp')
            ->middleware('can.module:Petty Cash,approve')
            ->name('api.petty-cash.top-ups.decide');

        Route::post('/petty-cash/expenses', [PettyCashController::class, 'storeExpense'])
            ->middleware('can.module:Petty Cash,create')
            ->name('api.petty-cash.expenses.store');
        Route::post('/petty-cash/expenses/{expense}/decision', [PettyCashController::class, 'decideExpense'])
            ->whereNumber('expense')
            ->middleware('can.module:Petty Cash,approve')
            ->name('api.petty-cash.expenses.decide');

        Route::post('/petty-cash/reconciliations', [PettyCashController::class, 'reconcile'])
            ->middleware('can.module:Petty Cash,create')
            ->name('api.petty-cash.reconcile');

        /*
         * Messages and notifications.
         *
         * /sync is polled every few seconds by every signed-in client, so it
         * carries a deliberately higher throttle than the rest of the API and
         * is gated on nothing: the recipient rows already decide what a user
         * can see, and a permission lookup on every tick would be the most
         * expensive part of the cheapest query here.
         */
        Route::get('/sync', [MessageController::class, 'sync'])
            ->middleware('throttle:120,1')
            ->name('api.sync');

        /*
         * Pusher channel authorisation.
         *
         * Registered here rather than through Broadcast::routes() so it runs
         * inside the tenant group: the default route sits outside tenancy and
         * would resolve `users` against the central database, where that table
         * does not exist. routes/channels.php holds the actual check.
         */
        Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request))
            ->name('api.broadcasting.auth');

        // Above /messages/{...} routes so "audiences" is not read as an id.
        Route::get('/messages/audiences', [MessageController::class, 'audiences'])
            ->middleware('can.module:Messages,view')
            ->name('api.messages.audiences');

        Route::get('/messages', [MessageController::class, 'index'])
            ->middleware('can.module:Messages,view')
            ->name('api.messages.index');
       
        Route::post('/messages', [MessageController::class, 'store'])
            ->middleware(['can.module:Messages,create', 'throttle:30,1'])
            ->name('api.messages.store');
        Route::post('/messages/read-all', [MessageController::class, 'markAllRead'])
            ->name('api.messages.read-all');
        Route::post('/messages/{message}/read', [MessageController::class, 'markRead'])
            ->whereNumber('message')
            ->name('api.messages.read');

        /*
         * Presence. Ungated: everyone signed in may see who is available, and
         * the status a person sets is their own. Online/offline is not here at
         * all — that comes from the Pusher presence channel.
         */
        Route::get('/presence', [MessageController::class, 'presence'])
            ->name('api.presence.index');
        Route::put('/presence', [MessageController::class, 'setPresence'])
            ->name('api.presence.update');

        Route::post('/notifications/read-all', [MessageController::class, 'markAllNotificationsRead'])
            ->name('api.notifications.read-all');
        Route::post('/notifications/{notification}/read', [MessageController::class, 'markNotificationRead'])
            ->whereNumber('notification')
            ->name('api.notifications.read');

        /*
         * Assets. Reading is gated on Assets.view — an employee holds it with
         * scope self, so they see what they hold and not the whole register.
         * Every write needs Assets.create, which only HR and super admin have.
         */
        Route::get('/assets', [AssetController::class, 'index'])
            ->name('api.assets.index');
        Route::post('/assets', [AssetController::class, 'store'])
            ->middleware('can.module:Assets,create')
            ->name('api.assets.store');
        Route::patch('/assets/{asset}', [AssetController::class, 'update'])
            ->whereNumber('asset')
            ->middleware('can.module:Assets,create')
            ->name('api.assets.update');
        Route::get('/assets/{asset}/history', [AssetController::class, 'history'])
            ->whereNumber('asset')
            ->name('api.assets.history');
        Route::post('/assets/{asset}/assign', [AssetController::class, 'assign'])
            ->whereNumber('asset')
            ->middleware('can.module:Assets,create')
            ->name('api.assets.assign');
        Route::post('/assets/{asset}/return', [AssetController::class, 'returnAsset'])
            ->whereNumber('asset')
            ->middleware('can.module:Assets,create')
            ->name('api.assets.return');
        Route::post('/assets/{asset}/retire', [AssetController::class, 'retire'])
            ->whereNumber('asset')
            ->middleware('can.module:Assets,create')
            ->name('api.assets.retire');

        Route::get('/company-hub', [CompanyHubController::class, 'index'])
            ->name('api.company-hub.index');
        Route::post('/company-hub/announcements', [CompanyHubController::class, 'publish'])
            ->middleware('can.module:Company Hub,create')
            ->name('api.company-hub.announce');
        Route::post('/company-hub/policies', [CompanyHubController::class, 'savePolicy'])
            ->middleware('can.module:Company Hub,create')
            ->name('api.company-hub.policies.store');
        Route::patch('/company-hub/policies/{policy}', [CompanyHubController::class, 'savePolicy'])
            ->whereNumber('policy')
            ->middleware('can.module:Company Hub,create')
            ->name('api.company-hub.policies.update');
        Route::post('/company-hub/policies/{policy}/acknowledge', [CompanyHubController::class, 'acknowledge'])
            ->whereNumber('policy')
            ->name('api.company-hub.policies.acknowledge');
        Route::get('/company-hub/policies/{policy}/acknowledgements', [CompanyHubController::class, 'acknowledgements'])
            ->whereNumber('policy')
            ->middleware('can.module:Company Hub,create')
            ->name('api.company-hub.policies.acknowledgements');

        Route::get('/activity-logs', [ActivityLogController::class, 'index'])
            ->name('api.activity-logs.index');
        Route::post('/activity-logs', [ActivityLogController::class, 'store'])
            ->middleware('throttle:120,1')
            ->name('api.activity-logs.store');

        Route::post('/employees/invite', [EmployeeInvitationController::class, 'store'])
            ->middleware('can.module:Employees,create')
            ->name('api.employees.invite');

        Route::post('/employees/{employee}/resend-invite', [EmployeeInvitationController::class, 'resend'])
            ->middleware('can.module:Employees,create')
            ->name('api.employees.resend-invite');
    });
});
# Orbit HRMS — Laravel Backend Setup

Everything needed to stand up the API for this frontend. Package choices are driven by features
the Vue app already has, noted per package. Nothing here is speculative.

> **Deployment target: Windows RDP + IIS.** This shapes several choices. IIS cannot supervise
> long-running processes — app-pool recycling (idle timeout, memory limits, the default 29-hour
> recycle) kills any daemon tied to it, and there is no `supervisord` equivalent. So **nothing in
> this guide requires an always-on process.** See §9 for the IIS specifics.

**Prerequisites:** PHP >= 8.1, Composer 2.x, MySQL 8 / MariaDB 10.4+, Node 18+ (this machine has
Node v24, fine).

```bash
php -v && composer -V && mysql --version
```

---

## 1. Create the project

Laravel 10 needs an explicit version — `laravel/laravel` alone installs 11/12.

```bash
composer create-project laravel/laravel:^10.0 orbit-hrms-api
cd orbit-hrms-api
php artisan --version    # expect 10.x
```

---

## 2. Required packages

```bash
# Auth for the SPA — replaces the fake role-dropdown login in Login.vue.
# NOTE: `php artisan install:api` is Laravel 11+ only. On Laravel 10, install manually:
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# Roles/permissions — mirrors the frontend rolePermissions table
composer require spatie/laravel-permission

# Activity log — backs logActivity() with immutable server-side records
composer require spatie/laravel-activitylog

# Multi-tenancy — see §7. Install BEFORE the second customer exists.
composer require stancl/tenancy

# File uploads: leave attachments, medical receipts, onboarding docs, CNIC scans
# (used by AcceptInvite, LeaveRequests, MedicalClaims, Profile) + attendance photos
composer require spatie/laravel-medialibrary

# Payslip PDFs — replaces the hand-rolled createSimplePdf() in SalarySlips.vue
composer require barryvdh/laravel-dompdf

# Report exports — replaces the client-side exportCsv() in Reports.vue
composer require maatwebsite/excel
```

**Messaging needs no package.** `Messages.vue` (threads, unread counts, direct + broadcast) is
served by ordinary REST endpoints plus browser polling — see §6. No WebSocket server, so nothing
extra to keep alive on IIS.

### Dev-only

```bash
composer require --dev laravel/pint            # code style
composer require --dev barryvdh/laravel-ide-helper
composer require --dev pestphp/pest --with-all-dependencies   # or use built-in PHPUnit
```

### Deliberately NOT included

- **laravel/breeze / jetstream** — they scaffold Blade/Inertia views. This is an API for an
  existing SPA; Sanctum alone is the right scope.
- **laravel/passport** — full OAuth2 server, overkill for a first-party SPA.
- **laravel/reverb** — Laravel's own WebSocket server, and the usual recommendation for realtime.
  **Do not use it here.** It is a long-running `php artisan reverb:start` process: IIS will not
  supervise it, app-pool recycling kills it, and a persistent PHP daemon holding open connections
  on Windows is the most likely way to reproduce the CPU/service-stop problems this box already
  has. §6 covers the alternative.
- **laravel/horizon** — needs a long-running worker + Redis. Same problem on IIS.
- **pusher/pusher-php-server** — *would* work on IIS (Pusher hosts the connections, so no local
  daemon), and it is the common tutorial choice. Held back deliberately, not overlooked: every
  employee DM would transit a third party, which is a question buyers will ask, and it adds
  credentials plus an external dependency whose outage becomes yours. See §6 for how to switch to
  it later if the polling delay ever becomes a real complaint.

---

## 3. Publish & migrate

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"

# Cache table, plus the queue table so switching off 'sync' later needs no migration
php artisan queue:table
php artisan cache:table

php artisan migrate
php artisan storage:link          # serves uploaded receipts/photos
```

### Wire up Sanctum (Laravel 10 — not automatic)

**1.** `app/Http/Kernel.php` — add to the `api` middleware group so cookie auth works from the SPA:

```php
'api' => [
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
],
```

**2.** `app/Models/User.php` — add the traits:

```php
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;
}
```

**3.** Protect routes in `routes/api.php`:

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $r) => $r->user());
    // employees, projects, leave, payroll, messages...
});
```

Skipping step 1 is the usual cause of a 401 on every authenticated request despite a valid login.

---

## 4. `.env`

```env
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_DATABASE=orbit_hrms
DB_USERNAME=root
DB_PASSWORD=

# Vite dev server — required for cookie-based Sanctum auth
SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
FRONTEND_URL=http://localhost:5173

# 42 pushNotification() calls in the frontend become real mail/notifications.
# Use log driver until SMTP is ready — mails land in storage/logs/laravel.log
MAIL_MAILER=log

# IIS: 'sync' runs jobs inline during the request — no worker process to keep alive.
# Switch to 'database' ONLY alongside the Task Scheduler setup in §9.
QUEUE_CONNECTION=sync

# Messaging + notifications are polled, not pushed. No broadcast driver needed.
BROADCAST_DRIVER=null
```

In `config/cors.php` set `'supports_credentials' => true` and add the frontend origin, or the
browser silently drops auth cookies.

---

## 5. Frontend side

```bash
cd ../orbit-hrms
npm install axios
```

Add to `vite.config.js` so the SPA and API share an origin in dev (avoids CORS entirely):

```js
server: { proxy: { '/api': 'http://localhost:8000' } }
```

Then replace the mock arrays in `src/store/index.js` with axios calls. The store is already
centralised, so this is one file — components read from `state` and won't change.

---

## 6. Messaging (`Messages.vue`)

### Normalise the arrays first

The mock shape does not translate to SQL:

```js
{ recipientIds: [1, 2, 4], readBy: [3] }   // fine in memory, wrong in a DB
```

`readBy: [3]` cannot answer "has user 7 read this?" without loading and scanning the row, and a
broadcast to 200 staff becomes a 200-element JSON column. Split it:

```
messages            id, sender_id, subject, body, audience, created_at
message_recipients  message_id, user_id, read_at   ← unread = WHERE read_at IS NULL
```

Unread counts then become one indexed `COUNT`, and "mark as read" is a single-row update. Decide
this **before** writing the migration.

### Delivery: polling, not WebSockets

The browser asks for new messages on a timer. No persistent connection, so no daemon — the same
IIS worker that serves every other request serves this, and an app-pool recycle is invisible to it.

```js
// Messages.vue — same pattern Topbar.vue already uses for its clock
let pollTimer = null
onMounted(() => { pollTimer = setInterval(fetchMessages, 10000) })
onBeforeUnmount(() => { clearInterval(pollTimer) })   // must clear, or timers accumulate
```

Endpoint returns only what is new, so the common case is a cheap query with an empty result:

```
GET /api/messages?since=2026-07-30T14:22:00Z
```

Index `messages.created_at` and `message_recipients.(user_id, read_at)`. That indexing is what
keeps polling negligible rather than a CPU cost — it matters on this box.

**Tradeoff:** up to ~10s before a message appears. Imperceptible for workplace messaging; this is
not Slack.

### If the delay ever becomes a real complaint

Laravel broadcasting is driver-based, so switching is config, not a rewrite:

1. `composer require pusher/pusher-php-server`
2. `BROADCAST_DRIVER=pusher` + keys in `.env`
3. `npm install laravel-echo pusher-js`, replace the `setInterval` with an Echo listener

The schema, endpoints, and Vue components stay as they are. Deferring costs nothing.

---

## 7. Order of work (matters)

1. **`tenant_id` on every table, in the FIRST migration.** Free now, expensive to backfill after
   the first customer. Add a global scope so no query can forget it. `rolePermissions` must be
   per-tenant too — Company A's "Team Lead" may differ from Company B's.
2. **Auth** — Sanctum login/logout/refresh. Role must come from the server, never the client.
3. **Mirror the permission table as Laravel policies.** The frontend `can(module, action, resource)`
   maps directly onto `Gate::allows()`. **The UI checks are UX only — every rule must be
   re-enforced server-side.** See `ENHANCEMENTS.md` §2.
4. **`unique` DB constraints** on `employees.email` and `assets.serial_number`. The frontend already
   validates both, but only a DB constraint holds under concurrent requests.
5. Endpoints, then move `logActivity()` to activitylog, then persist settings + permissions.

---

## 8. Local dev

```bash
php artisan serve      # :8000
npm run dev            # separate terminal — :5173
```

No queue worker needed with `QUEUE_CONNECTION=sync`.

---

## 9. Deploying to Windows RDP + IIS

### Prerequisites

- **PHP for Windows** (non-thread-safe build) + **IIS FastCGI** — configure via IIS Manager →
  Handler Mappings, or install PHP through the Web Platform Installer.
- Point the IIS site's physical path at **`public/`**, never the project root. Pointing it at the
  root exposes `.env`.

### URL rewriting

Laravel needs all traffic funnelled to `index.php`. Install the **IIS URL Rewrite** module, then
put this in `public/web.config`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <rewrite>
      <rules>
        <rule name="Laravel" stopProcessing="true">
          <match url="^(.*)$" ignoreCase="false" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.php" />
        </rule>
      </rules>
    </rewrite>
  </system.webServer>
</configuration>
```

### Permissions

`IIS_IUSRS` needs **Modify** on `storage/` and `bootstrap/cache/`. Missing this is the most common
cause of a blank 500 on a fresh Windows deploy — check `storage/logs/laravel.log` first.

`php artisan storage:link` creates a symlink; on Windows run the terminal **as Administrator** or it
fails silently. If symlinks are blocked by policy, serve uploads through a controller instead.

### Queues and the scheduler — use Task Scheduler, never a daemon

Both `queue:work` and `schedule:work` are long-running and will not survive on IIS. Windows
equivalent: short-lived processes that exit cleanly.

| Task | Trigger | Action |
|---|---|---|
| Scheduler | Every 1 min | `php artisan schedule:run` |
| Queue (only if not `sync`) | Every 1 min | `php artisan queue:work --stop-when-empty --max-time=55` |

`--stop-when-empty` is the key flag: the process drains the queue and exits, so there is no daemon
to supervise. `--max-time=55` guarantees it ends before the next trigger, preventing overlap.

Set each task to **"Run whether user is logged on or not"** — otherwise it stops when you close RDP.

**Start with `QUEUE_CONNECTION=sync`.** Jobs run inline: emails make a request slightly slower, but
nothing can stop. Move to `database` + the scheduled task only if request latency becomes a problem.

### App-pool settings worth changing

Given the CPU/service-stop history on this box:

- **Idle Time-out: 0** — stops IIS unloading the app after inactivity (first request after idle is
  otherwise very slow).
- **Regular Time Interval: 0** — disables the default 29-hour recycle.
- **Start Mode: AlwaysRunning** — keeps PHP warm.

These reduce cold starts but do **not** make daemons viable — the constraint stands.

### Worth evaluating before committing

If the box can run **Docker Desktop or WSL2**, running Laravel there with IIS reverse-proxying to it
removes every constraint in this section: standard `supervisord`, real queue workers, and Reverb all
become available. Native IIS + PHP works, but you inherit these limits permanently.

Separately: **diagnose the existing CPU spikes and service stops before deploying.** Adding load to
a box that already drops services will surface the same fault. Check Event Viewer for app-pool
recycle events (WAS category) and whether memory limits are being hit — better found now.

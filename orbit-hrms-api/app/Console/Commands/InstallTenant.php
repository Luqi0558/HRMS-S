<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Stands up a company on a fresh install, and gives it someone who can log in.
 *
 * Without this a production deploy is stranded. Creating the tenant migrates
 * its database but seeds nothing (the SeedDatabase job is commented out of the
 * TenantCreated pipeline), so there is no permission grid — every can() check
 * denies and a signed-in user reaches no page at all. And the only code that
 * has ever created a user is DemoUserSeeder, which DatabaseSeeder refuses to
 * run outside local/testing because every account it makes shares one
 * password. Invitations cannot fill the gap either: issuing one requires an
 * already-authenticated HR account, which is the thing that does not exist yet.
 *
 * So this does the three steps in order: create the tenant, seed the grid and
 * company settings into it, then create one super admin.
 */
class InstallTenant extends Command
{
    protected $signature = 'tenant:install
                            {id : Tenant id, used for the database name (tenant<id>)}
                            {--name= : Company name}
                            {--admin-name= : Full name of the first administrator}
                            {--admin-email= : Their email, which is also their login}
                            {--admin-password= : Leave unset to be prompted, or to have one generated}';

    protected $description = 'Create a company and its first administrator';

    public function handle(): int
    {
        $id = (string) $this->argument('id');

        if (Tenant::find($id)) {
            $this->error("A tenant '{$id}' already exists. Nothing was changed.");

            return self::FAILURE;
        }

        $company = (string) ($this->option('name') ?: $this->ask('Company name'));
        $adminName = (string) ($this->option('admin-name') ?: $this->ask('Administrator full name'));
        $adminEmail = (string) ($this->option('admin-email') ?: $this->ask('Administrator email'));

        // Generated rather than defaulted: a fixed fallback password is how
        // every install ends up sharing one.
        $generated = false;
        $adminPassword = (string) $this->option('admin-password');

        if ($adminPassword === '') {
            $adminPassword = $this->secret('Administrator password (blank to generate one)') ?? '';
        }

        if ($adminPassword === '') {
            $adminPassword = Str::password(16);
            $generated = true;
        }

        $validator = Validator::make(
            ['name' => $company, 'adminName' => $adminName, 'email' => $adminEmail, 'password' => $adminPassword],
            [
                'name' => ['required', 'string', 'max:255'],
                'adminName' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', Password::min(8)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->line("Creating tenant '{$id}' and migrating its database...");

        $tenant = null;
        try {
            // Creating the tenant fires TenantCreated, whose pipeline creates
            // and migrates its database. Keep this inside the rollback guard:
            // a migration failure happens during create(), before the previous
            // implementation had a chance to clean up the partial tenant.
            $tenant = Tenant::create([
                'id' => $id,
                'name' => $company,
                'slug' => Str::slug($company) ?: $id,
            ]);

            tenancy()->initialize($tenant);

            $this->line('Seeding the permission grid and company settings...');
            // DatabaseSeeder skips the demo fixtures outside local/testing, so
            // in production this is the grid, the settings lists and the
            // attendance/leave/claim policy — and nothing else.
            $this->callSilent('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

            $this->line('Creating the administrator...');
            $this->createAdministrator($adminName, $adminEmail, $adminPassword);

            tenancy()->end();
        } catch (Throwable $e) {
            tenancy()->end();

            // A migration can fail during Tenant::create(), before its return
            // value is assigned. Resolve it again so the central record and
            // its database are removed in either failure path.
            $tenant ??= Tenant::find($id);

            // A half-built tenant is worse than none: it would occupy the id,
            // so a corrected re-run would be refused by the guard above.
            $this->error('Install failed, rolling the tenant back: '.$e->getMessage());
            $tenant?->delete();

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Company '{$company}' is ready.");
        $this->line("  tenant id ... {$id}");
        $this->line("  database .... {$tenant->database()->getName()}");
        $this->line("  sign in as .. {$adminEmail}");

        if ($generated) {
            $this->newLine();
            $this->warn('Generated password (shown once, store it now):');
            $this->line('  '.$adminPassword);
        }

        $this->newLine();
        $this->line('Send the X-Tenant header with this id from the frontend (VITE_TENANT).');

        return self::SUCCESS;
    }

    /**
     * The administrator, as both a login and an employee row.
     *
     * Both are needed. Everything in the app resolves the signed-in user to an
     * employee — attendance, messages, tickets all reference employees.id — so
     * a user without one can authenticate and then hit a 404 on their own
     * profile.
     */
    private function createAdministrator(string $name, string $email, string $password): void
    {
        DB::transaction(function () use ($name, $email, $password) {
            $user = new User();
            $user->fill(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->role = 'super_admin';
            $user->status = 'active';
            $user->save();

            Employee::create([
                'user_id' => $user->id,
                'employee_code' => 'ORB-0001',
                'name' => $name,
                'email' => $email,
                'department' => 'Administration',
                'designation' => 'Administrator',
                'status' => 'active',
                'joining_date' => now()->toDateString(),
            ]);
        });
    }
}

<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Seeder;


class DemoUserSeeder extends Seeder
{
    public const PASSWORD = 'password123';

    private const PEOPLE = [
        ['Ayesha Khan',  'ayesha.khan@company.com',  'employee',    'Engineering',     'active',  '1998-07-22', '2024-08-05'],
        ['Bilal Raza',   'bilal.raza@company.com',   'employee',    'Design',          'active',  '1997-07-19', '2023-07-24'],
        ['Zara Nadeem',  'zara.nadeem@company.com',  'manager',     'Engineering',     'active',  '1995-08-03', '2022-07-18'],
        ['Usman Tariq',  'usman.tariq@company.com',  'employee',    'QA',              'active',  '1999-07-28', '2025-01-13'],
        ['Hina Malik',   'hina.malik@company.com',   'employee',    'Engineering',     'invited', '2000-08-09', null],
        ['Sara Ahmed',   'sara.ahmed@company.com',   'hr',          'Human Resources', 'active',  '1996-07-31', '2023-09-01'],
        ['Imran Shah',   'imran.shah@company.com',   'team_lead',   'Team Lead',       'active',  '1994-05-12', '2022-03-14'],
        ['Admin User',   'admin@company.com',        'super_admin', 'Administration',  'active',  null,         '2021-01-04'],
        ['Fatima Iqbal', 'fatima.iqbal@company.com', 'qa',          'QA',              'active',  '1997-03-11', '2023-11-06'],
        ['Hamza Sheikh', 'hamza.sheikh@company.com', 'intern',      'Engineering',     'active',  '2003-09-27', '2026-06-01'],
    ];

    public function run(): void
    {
        foreach (self::PEOPLE as $index => [$name, $email, $role, $department, $status, $birthday, $joiningDate]) {
            $user = User::withTrashed()->firstOrNew(['email' => $email]);
            $user->name = $name;
            $user->role = $role;
            $user->status = $status;
            $user->password = $status === 'invited' ? null : self::PASSWORD;
            $user->save();

            $employee = Employee::withTrashed()->firstOrNew(['email' => $email]);
            $employee->fill([
                'user_id' => $user->id,
                'employee_code' => sprintf('ORB-%04d', $index + 1),
                'name' => $name,
                'department' => $department,
                'designation' => $department,
                'birthday' => $birthday,
                'joining_date' => $joiningDate,
                'status' => $status,
            ]);
            $employee->save();

            if ($status === 'invited') {
                $this->issueInvitation($employee);
            }
        }
    }

    /**
     * Prints the raw token, because it is not recoverable afterwards — only its
     * hash is stored. Without this there is no way to exercise the
     * accept-invite screen locally.
     */
    private function issueInvitation(Employee $employee): void
    {
        [$raw, $hash] = Invitation::issueToken();

        Invitation::updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'email' => $employee->email,
                'token_hash' => $hash,
                'expires_at' => now()->addDays(7),
                'invited_by' => null,
            ]
        );

        $this->command?->newLine();
        $this->command?->info("Invitation for {$employee->name}:");
        $this->command?->line("  /accept-invite?token={$raw}");
    }
}

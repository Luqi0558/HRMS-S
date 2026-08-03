<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The designation and stack option lists, matching the defaults the Vue store
 * shipped with (state.settings.designations / .stacks).
 *
 * Unlike the demo users, these are real starting data rather than a fixture —
 * a new company needs a usable list on day one, and can edit it from Settings.
 */
class SettingsListSeeder extends Seeder
{
    private const DESIGNATIONS = [
        'Junior Software Engineer',
        'Associate Software Engineer',
        'Software Engineer',
        'Senior Software Engineer',
        'QA Engineer',
        'Senior QA Engineer',
        'Project Manager',
        'Team Lead',
        'Bidder',
        'Intern',
        'UI/UX Designer',
        'HR Executive',
        'DevOps Engineer',
    ];

    private const STACKS = [
        'MERN', 'MEAN', '.NET', 'Angular', 'React', 'Vue',
        'Laravel', 'Node.js', 'QA Automation', 'DevOps', 'UI/UX',
    ];

    public function run(): void
    {
        $now = now();

        // sort_order follows array position so the pickers list them in the
        // deliberate order above rather than alphabetically.
        DB::table('designations')->upsert(
            collect(self::DESIGNATIONS)->map(fn ($name, $i) => [
                'name' => $name,
                'is_active' => true,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['name'],
            ['is_active', 'sort_order', 'updated_at']
        );

        DB::table('stacks')->upsert(
            collect(self::STACKS)->map(fn ($name, $i) => [
                'name' => $name,
                'is_active' => true,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['name'],
            ['is_active', 'sort_order', 'updated_at']
        );
    }
}

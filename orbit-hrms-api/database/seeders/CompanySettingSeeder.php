<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use Illuminate\Database\Seeder;

/**
 * Starting policy for a new company, matching the defaults the Vue store
 * shipped with. SettingsService falls back to the same values, so this exists
 * mainly so the Settings screen has real rows to edit rather than blanks.
 */
class CompanySettingSeeder extends Seeder
{
    private const SETTINGS = [
        'attendance' => [
            'startTime' => '09:30',
            'endTime' => '18:00',
            'graceMinutes' => 10,
            'cameraCheckinRequired' => true,
        ],
        'leave' => [
            'annualDays' => 18,
            'sickDays' => 10,
            'casualDays' => 8,
            'halfDayEnabled' => true,
            'twoStepApproval' => true,
        ],
        'claims' => [
            'annualMedicalLimit' => 100000,
            'receiptRequiredAbove' => 5000,
            'approvalFlow' => 'HR + Super Admin',
        ],
        // Petty cash spend above approvalThreshold waits for sign-off;
        // lowBalanceThreshold warns that a top-up is due.
        'pettyCash' => [
            'approvalThreshold' => 5000,
            'lowBalanceThreshold' => 10000,
        ],
    ];

    public function run(): void
    {
        foreach (self::SETTINGS as $group => $values) {
            foreach ($values as $key => $value) {
                CompanySetting::updateOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $value],
                );
            }
        }
    }
}

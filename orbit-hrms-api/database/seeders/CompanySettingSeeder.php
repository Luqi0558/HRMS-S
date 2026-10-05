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
        // Locale drives every currency and date format in the app, so these
        // are not cosmetic: changing the currency here changes what every
        // payroll and petty-cash figure reads as.
        'company' => [
            'name' => 'Orbit HRMS',
            'timezone' => 'Asia/Karachi',
            'workWeek' => 'Monday - Friday',
            'locale' => 'en-US',
            'currency' => 'PKR',
            // 'code' renders "PKR 1,200"; 'symbol' renders the currency glyph.
            'currencyDisplay' => 'code',
        ],
        // Outbound integrations. The Teams URL comes from the channel's
        // Workflows menu ("Post to a channel when a webhook request is
        // received"); leaving it blank switches the announcements off.
        'integrations' => [
            // One URL per Teams channel: a workflow is bound to the channel it
            // was created in, so the destination cannot be chosen per message.
            'teamsAttendanceWebhookUrl' => '',
            'teamsAnnouncementsWebhookUrl' => '',
        ],
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
        'finance' => [
            'defaultCommissionType' => 'percent',
            'defaultCommissionValue' => 8,
            'superAdminApprovalRequired' => true,
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

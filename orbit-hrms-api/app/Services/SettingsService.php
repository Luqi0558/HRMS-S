<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'company_settings';
    private const DEFAULTS = [
        'company' => [
            'name' => 'Orbit HRMS',
            'timezone' => 'Asia/Karachi',
            'workWeek' => 'Monday - Friday',
            'locale' => 'en-US',
            'currency' => 'PKR',
            'currencyDisplay' => 'code',
        ],
        'integrations' => [
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
            // Applied to a newly created module unless its editor deliberately
            // supplies different commission terms.
            'defaultCommissionType' => 'percent',
            'defaultCommissionValue' => 8,
            // When disabled, a new or materially changed commission is ready
            // for settlement without a separate Super Admin approval step.
            'superAdminApprovalRequired' => true,
        ],
        'pettyCash' => [
            'approvalThreshold' => 5000,
            'lowBalanceThreshold' => 10000,
        ],
    ];

    /** @return array<string, mixed> */
    public function group(string $group): array
    {
        return array_merge(
            self::DEFAULTS[$group] ?? [],
            $this->all()[$group] ?? []
        );
    }

    public function get(string $group, string $key, mixed $fallback = null): mixed
    {
        return $this->group($group)[$key] ?? $fallback;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return Cache::driver('array')->remember(self::CACHE_KEY, 60, function () {
            return CompanySetting::all()
                ->groupBy('group')
                ->map(fn ($rows) => $rows->pluck('value', 'key')->all())
                ->all();
        });
    }

    public function put(string $group, string $key, mixed $value): void
    {
        CompanySetting::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value],
        );

        $this->flush();
    }

    /** @param array<string, mixed> $values */
    public function putGroup(string $group, array $values): void
    {
        foreach ($values as $key => $value) {
            CompanySetting::updateOrCreate(
                ['group' => $group, 'key' => $key],
                ['value' => $value],
            );
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::driver('array')->forget(self::CACHE_KEY);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingsListService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SettingsListService $lists,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => [
                'company' => $this->settings->group('company'),
                'attendance' => $this->settings->group('attendance'),
                'leave' => $this->settings->group('leave'),
                'claims' => $this->settings->group('claims'),
                'finance' => $this->settings->group('finance'),
                'pettyCash' => $this->settings->group('pettyCash'),
            ],
        ]);
    }

    public function lists(): JsonResponse
    {
        return response()->json(['lists' => $this->lists->all()]);
    }

    public function addListItem(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        return response()->json([
            'lists' => $this->lists->add($request->user(), $type, $validated['name']),
        ], 201);
    }

    public function removeListItem(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $result = $this->lists->remove($request->user(), $type, $validated['name']);

        return response()->json([
            'lists' => $result['lists'],
            'stillAssigned' => $result['stillAssigned'],
        ]);
    }

    public function integrations(): JsonResponse
    {
        return response()->json([
            'integrations' => $this->settings->group('integrations'),
        ]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        abort_unless(
            in_array($group, ['company', 'attendance', 'leave', 'claims', 'finance', 'pettyCash', 'integrations'], true),
            404,
            'Unknown settings group.',
        );

        $values = $request->validate([
            'values' => ['required', 'array'],
        ])['values'];

        // These defaults become financial terms on newly created modules, so
        // validate them at the API boundary rather than trusting the Settings
        // page's client-side checks.
        if ($group === 'finance') {
            $values = $request->validate([
                'values' => ['required', 'array'],
                'values.defaultCommissionType' => ['required', 'in:percent,fixed'],
                'values.defaultCommissionValue' => ['required', 'numeric', 'min:0', 'max:100000000'],
                'values.superAdminApprovalRequired' => ['required', 'boolean'],
            ])['values'];

            if ($values['defaultCommissionType'] === 'percent' && (float) $values['defaultCommissionValue'] > 100) {
                abort(422, 'Default commission percentage cannot exceed 100.');
            }
        }

        $this->settings->putGroup($group, $values);

        return response()->json([
            'message' => 'Settings saved.',
            'settings' => [$group => $this->settings->group($group)],
        ]);
    }
}

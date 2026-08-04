<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Readable by any signed-in user: the client needs the working day to
     * render attendance, and leave allowances to show a balance. None of it
     * is sensitive.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => [
                'attendance' => $this->settings->group('attendance'),
                'leave' => $this->settings->group('leave'),
                'claims' => $this->settings->group('claims'),
                'pettyCash' => $this->settings->group('pettyCash'),
            ],
        ]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        abort_unless(
            in_array($group, ['attendance', 'leave', 'claims', 'pettyCash'], true),
            404,
            'Unknown settings group.',
        );

        $values = $request->validate([
            'values' => ['required', 'array'],
        ])['values'];

        $this->settings->putGroup($group, $values);

        return response()->json([
            'message' => 'Settings saved.',
            'settings' => [$group => $this->settings->group($group)],
        ]);
    }
}

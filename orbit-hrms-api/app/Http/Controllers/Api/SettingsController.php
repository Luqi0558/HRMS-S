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

    /**
     * Readable by any signed-in user: the client needs the working day to
     * render attendance, and leave allowances to show a balance. None of it
     * is sensitive.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => [
                'company' => $this->settings->group('company'),
                'attendance' => $this->settings->group('attendance'),
                'leave' => $this->settings->group('leave'),
                'claims' => $this->settings->group('claims'),
                'pettyCash' => $this->settings->group('pettyCash'),
            ],
        ]);
    }

    /**
     * The dropdown options: job titles and technical stacks.
     *
     * Readable by anyone signed in — onboarding and the invite form need them,
     * and they are not sensitive.
     */
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
            // So the UI can say how many people still hold it rather than
            // implying nothing was affected.
            'stillAssigned' => $result['stillAssigned'],
        ]);
    }

    /**
     * Outbound integration credentials.
     *
     * Deliberately not part of index(), which any signed-in user may read. The
     * Teams webhook URL is a credential in its own right — anyone holding it
     * can post into the channel — so it is served only to whoever may edit
     * Settings, behind the same guard as the write.
     */
    public function integrations(): JsonResponse
    {
        return response()->json([
            'integrations' => $this->settings->group('integrations'),
        ]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        abort_unless(
            in_array($group, ['company', 'attendance', 'leave', 'claims', 'pettyCash', 'integrations'], true),
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

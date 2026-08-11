<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\PolicyResource;
use App\Services\CompanyHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyHubController extends Controller
{
    public function __construct(private readonly CompanyHubService $hub)
    {
    }

    /** The whole page in one call — both lists are always rendered together. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'announcements' => AnnouncementResource::collection($this->hub->announcements($user)),
            'policies' => PolicyResource::collection($this->hub->policies($user)),
        ]);
    }

    public function publish(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['nullable', 'string', 'max:80'],
            'priority' => ['nullable', Rule::in(['normal', 'high'])],
        ]);

        return response()->json([
            'message' => 'Announcement published.',
            'announcement' => new AnnouncementResource($this->hub->publish($request->user(), $validated)),
        ], 201);
    }

    public function savePolicy(Request $request, ?int $policy = null): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
            'version' => ['nullable', 'string', 'max:20'],
            'summary' => ['required', 'string', 'max:2000'],
            'body' => ['nullable', 'string', 'max:20000'],
            'acknowledgmentRequired' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'message' => 'Policy saved.',
            'policy' => new PolicyResource($this->hub->savePolicy($request->user(), $validated, $policy)),
        ], $policy ? 200 : 201);
    }

    public function acknowledge(Request $request, int $policy): JsonResponse
    {
        $this->hub->acknowledge($request->user(), $policy);

        return response()->json(['message' => 'Acknowledged.']);
    }

    /** Who has and has not acknowledged the current version. */
    public function acknowledgements(Request $request, int $policy): JsonResponse
    {
        return response()->json($this->hub->acknowledgementStatus($request->user(), $policy));
    }
}

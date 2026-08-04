<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\SyncMembersRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectModuleResource;
use App\Http\Resources\ProjectResource;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ProjectResource::collection(
                $this->projects->list($request->user(), $request->only(['status', 'search']))
            ),
        ]);
    }

    /** Resolved by slug — that is what the frontend has in its URLs. */
    public function show(Request $request, string $project): JsonResponse
    {
        return response()->json([
            'project' => new ProjectResource(
                $this->projects->findBySlug($request->user(), $project)
            ),
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Project created.',
            'project' => new ProjectResource(
                $this->projects->create($request->user(), $request->validated())
            ),
        ], 201);
    }

    public function update(UpdateProjectRequest $request, string $project): JsonResponse
    {
        return response()->json([
            'message' => 'Project updated.',
            'project' => new ProjectResource(
                $this->projects->update($request->user(), $project, $request->validated())
            ),
        ]);
    }

    /**
     * Mark a module delivered, or reopen it. Separate from update() because it
     * is gated on `approve` rather than `create` — signing off a deliverable
     * is a different act from editing one.
     */
    public function setModuleDelivered(Request $request, int $module): JsonResponse
    {
        $validated = $request->validate([
            'delivered' => ['required', 'boolean'],
        ]);

        $updated = $this->projects->setModuleDelivered(
            $request->user(),
            $module,
            $validated['delivered'],
        );

        return response()->json([
            'message' => $validated['delivered'] ? 'Module marked delivered.' : 'Module reopened.',
            'module' => new ProjectModuleResource($updated),
        ]);
    }

    public function syncMembers(SyncMembersRequest $request, string $project): JsonResponse
    {
        return response()->json([
            'message' => 'Team updated.',
            'project' => new ProjectResource(
                $this->projects->syncMembers(
                    $request->user(),
                    $project,
                    $request->validated()['employeeIds'],
                )
            ),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetAssignmentResource;
use App\Http\Resources\AssetResource;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function __construct(private readonly AssetService $assets)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['available', 'assigned', 'retired'])],
            'category' => ['nullable', 'string', 'max:60'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => AssetResource::collection($this->assets->list($request->user(), $filters)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:60'],
            // Unique where present: two rows with one serial means the
            // register has lost track of a physical item.
            'serialNumber' => ['nullable', 'string', 'max:255', 'unique:assets,serial_number'],
            'condition' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Asset added.',
            'asset' => new AssetResource($this->assets->create($request->user(), $validated)),
        ], 201);
    }

    public function update(Request $request, int $asset): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
            'serialNumber' => ['nullable', 'string', 'max:255', Rule::unique('assets', 'serial_number')->ignore($asset)],
            'condition' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'asset' => new AssetResource($this->assets->update($request->user(), $asset, $validated)),
        ]);
    }

    /** Issues an asset. Refused if it is already out — see AssetService. */
    public function assign(Request $request, int $asset): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'integer'],
            'issuedOn' => ['nullable', 'date'],
            'returnDue' => ['nullable', 'date', 'after_or_equal:issuedOn'],
            'condition' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Asset issued.',
            'asset' => new AssetResource($this->assets->assign($request->user(), $asset, $validated)),
        ]);
    }

    public function returnAsset(Request $request, int $asset): JsonResponse
    {
        $validated = $request->validate([
            'returnedOn' => ['nullable', 'date'],
            'condition' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Return recorded.',
            'asset' => new AssetResource($this->assets->returnAsset($request->user(), $asset, $validated)),
        ]);
    }

    public function retire(Request $request, int $asset): JsonResponse
    {
        return response()->json([
            'message' => 'Asset retired.',
            'asset' => new AssetResource($this->assets->retire($request->user(), $asset)),
        ]);
    }

    /** Everyone who has held this asset. */
    public function history(Request $request, int $asset): JsonResponse
    {
        return response()->json([
            'data' => AssetAssignmentResource::collection(
                $this->assets->history($request->user(), $asset)
            ),
        ]);
    }
}

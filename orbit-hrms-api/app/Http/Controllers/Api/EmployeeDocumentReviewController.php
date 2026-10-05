<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\ReviewEmployeeDocumentsRequest;
use App\Http\Resources\EmployeeResource;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentReviewController extends Controller
{
    public function __construct(private readonly EmployeeService $employees)
    {
    }

    /** Streams one of an employee's uploaded documents. Access is checked in the service. */
    public function download(Request $request, int $employee, string $key): StreamedResponse
    {
        $document = $this->employees->documentForDownload($request->user(), $employee, $key);

        return Storage::disk($document->disk ?: 'local')->download(
            $document->path,
            $document->original_name ?: $key.'.'.pathinfo($document->path, PATHINFO_EXTENSION),
        );
    }

    public function store(ReviewEmployeeDocumentsRequest $request, int $employee): JsonResponse
    {
        $updated = $this->employees->reviewOnboardingDocuments(
            $request->user(),
            $employee,
            $request->string('action')->toString(),
            $request->input('note'),
        );

        return response()->json([
            'message' => $updated->onboarding_review_status === 'verified'
                ? 'Onboarding documents marked verified.'
                : 'Document correction request saved.',
            'employee' => new EmployeeResource($updated),
        ]);
    }
}

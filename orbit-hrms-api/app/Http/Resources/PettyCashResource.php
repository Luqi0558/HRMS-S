<?php

namespace App\Http\Resources;

use App\Models\PettyCashExpense;
use App\Models\PettyCashReconciliation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One resource for all three petty cash records — they share a shape (amount,
 * date, who, status) and the view renders them in adjacent tables, so keeping
 * the field naming identical is what makes those tables consistent.
 */
class PettyCashResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $common = [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
        ];

        // A reconciliation has no single `amount` — it has a counted figure
        // and a book balance — so it does not take the shared amount field.
        if ($this->resource instanceof PettyCashReconciliation) {
            return $common + [
                'countedAmount' => (string) $this->counted_amount,
                'bookBalance' => (string) $this->book_balance,
                // Negative: cash is missing. Positive: more in the tin than
                // the ledger accounts for.
                'variance' => (string) $this->variance,
                'note' => $this->note,
                'byName' => $this->whenLoaded('counter', fn () => $this->counter?->name),
            ];
        }

        if ($this->resource instanceof PettyCashExpense) {
            return $common + [
                'amount' => (string) $this->amount,
                'category' => $this->category,
                'payee' => $this->payee,
                'description' => $this->description,
                'hasReceipt' => filled($this->receipt_path),
                'status' => $this->status,
                'reviewComment' => $this->review_comment,
                'approvedAt' => $this->approved_at?->toIso8601String(),
                'loggedByName' => $this->whenLoaded('logger', fn () => $this->logger?->name),
                'approvedByName' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            ];
        }

        return $common + [
            'amount' => (string) $this->amount,
            'reference' => $this->reference,
            'note' => $this->note,
            'status' => $this->status,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'requestedByName' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'approvedByName' => $this->whenLoaded('approver', fn () => $this->approver?->name),
        ];
    }
}

<?php

namespace App\Repositories\Contracts;

use App\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface TicketRepositoryInterface
{
    /**
     * @param array<int, int>|null $visibleProjectIds null = no restriction
     */
    public function paginateScoped(
        ?array $visibleProjectIds,
        array $filters = [],
        int $perPage = 50
    ): LengthAwarePaginator;

    public function findById(int $id): ?Ticket;

    public function create(array $attributes): Ticket;

    public function update(Ticket $ticket, array $attributes): Ticket;

    public function addComment(Ticket $ticket, int $authorId, string $body): void;

    /** The employee's currently running timer, if any. */
    public function runningTimerFor(int $employeeId): ?object;

    public function startTimer(Ticket $ticket, int $employeeId): void;

    /** @return array<int> ids of the tickets whose timers were stopped */
    public function stopTimer(int $employeeId): array;

    public function loadDetail(Ticket $ticket): Ticket;

    /** Open/done/overdue counts keyed by module id, for board headers. */
    public function statsByModule(array $moduleIds): Collection;
}

<?php

use App\Models\ProjectModule;
use App\Models\Ticket;
use App\Services\TicketReferenceGenerator;
use Illuminate\Database\Migrations\Migration;

/**
 * Gives every existing ticket the reference it would have been created with.
 *
 * Numbered oldest first within each module, so the ticket raised first holds
 * -1. Anything already carrying a reference is left alone — the generator reads
 * the highest number in use, so hand-entered values are respected rather than
 * renumbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        $generator = app(TicketReferenceGenerator::class);

        ProjectModule::with('project')->get()->each(function (ProjectModule $module) use ($generator) {
            Ticket::withTrashed()
                ->where('project_module_id', $module->id)
                ->whereNull('reference')
                // Oldest first: the reference should read in the order the work
                // was raised, not in whatever order the rows come back.
                ->orderBy('id')
                ->get()
                ->each(function (Ticket $ticket) use ($module, $generator) {
                    // Regenerated per ticket rather than incremented in a loop,
                    // so the number always comes from what is actually stored.
                    $ticket->forceFill(['reference' => $generator->for($module)])->saveQuietly();
                });
        });
    }

    public function down(): void
    {
        // Left in place. Clearing the column would strip references that have
        // since been quoted in Teams messages and conversations.
    }
};

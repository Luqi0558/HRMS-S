<?php

namespace App\Services;

use App\Models\ProjectModule;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Builds a ticket's human reference: LPS-Internal-AS-1.
 *
 *   LPS        initials of the company name from settings
 *   Internal   the project name
 *   AS         initials of the module name — "Attendance System"
 *   1          a counter within that module
 *
 * Generated once, at creation, and then left alone. Renaming a project or
 * module deliberately does NOT rewrite existing references: they are quoted in
 * Teams messages, commit messages and conversations, and an identifier that
 * changes retrospectively is worse than one that no longer matches its module's
 * current name.
 */
class TicketReferenceGenerator
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function for(ProjectModule $module): string
    {
        $module->loadMissing('project');

        $segments = array_filter([
            $this->initials((string) $this->settings->get('company', 'name', '')),
            $this->condense((string) ($module->project?->name ?? '')),
            $this->initials((string) $module->name),
        ]);

        // The counter is per module, which is why two modules can both hold a
        // "-1". Scoped any wider and the numbers would run into the hundreds
        // for no gain in meaning.
        $segments[] = $this->nextNumber($module);

        return implode('-', $segments);
    }

    /**
     * "Logic Powered Solutions" → LPS. "Attendance System" → AS.
     *
     * Split on spaces and punctuation but NOT on hyphens: "Front-end / QA" is
     * two things, not three, so it yields FQ rather than FEQ.
     */
    private function initials(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // A token may still begin with a stray hyphen ("- QA"), which would
        // otherwise contribute "-" as its initial.
        $words = array_filter(array_map(fn (string $word) => ltrim($word, '-'), $words));

        return strtoupper(implode('', array_map(
            fn (string $word) => mb_substr($word, 0, 1),
            $words
        )));
    }

    /**
     * The project name with separators removed: "Internal" stays as it is,
     * "Client Portal" becomes ClientPortal. Kept whole rather than reduced to
     * initials because it is the segment a reader scans for.
     */
    private function condense(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '';
    }

    /**
     * One past the highest number already used in this module.
     *
     * Derived from the stored references rather than from a row count: deleting
     * a ticket must not hand its number to the next one, which would leave two
     * different tickets sharing a reference in whatever was written down.
     */
    private function nextNumber(ProjectModule $module): int
    {
        $existing = Ticket::withTrashed()
            ->where('project_module_id', $module->id)
            ->whereNotNull('reference')
            ->pluck('reference');

        $highest = $existing
            ->map(fn (string $reference) => (int) (preg_match('/-(\d+)$/', $reference, $m) ? $m[1] : 0))
            ->max();

        return ((int) $highest) + 1;
    }
}

<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Services\TeamsNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Mirrors a published company announcement into the Teams announcements
 * channel.
 *
 * Queued for the same reason as the check-out announcement: publishing must not
 * wait on Microsoft, and must not fail because Microsoft did. Carries an id so
 * nothing tenant-specific — and no webhook URL — sits in the jobs table.
 */
class AnnouncePostToTeams implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One retry. A stale announcement is noise; the record is already saved. */
    public int $tries = 2;

    public function __construct(private readonly int $announcementId)
    {
    }

    public function handle(TeamsNotifier $teams): void
    {
        if (! $teams->isConfigured(TeamsNotifier::ANNOUNCEMENTS)) {
            return;
        }

        $announcement = Announcement::find($this->announcementId);

        // Deleted between publishing and this running — posting it to a channel
        // afterwards would put back what somebody just withdrew.
        if (! $announcement) {
            return;
        }

        $teams->announcePost(
            $announcement->title,
            $announcement->body,
            $announcement->author_name ?? 'Someone',
            $announcement->audience,
            $announcement->priority === 'high',
        );
    }
}

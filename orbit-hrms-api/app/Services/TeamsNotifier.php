<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts to a Microsoft Teams channel through a Power Automate workflow.
 *
 * The URL comes from Teams: channel ⋯ → Workflows → "Post to a channel when a
 * webhook request is received". That endpoint expects an Adaptive Card wrapped
 * in an attachment envelope, which is what buildCard() produces. (The older
 * Office 365 connector took a flatter MessageCard; Microsoft has retired it, so
 * only the workflow shape is supported here.)
 *
 * Nothing in this class is allowed to break a punch. Teams being slow, down, or
 * misconfigured must not stop somebody checking out, so failures are logged and
 * swallowed — see send().
 */
class TeamsNotifier
{
    /**
     * Short on purpose. This runs on a queue, but with QUEUE_CONNECTION=sync it
     * runs inside the request, and nobody should wait on Microsoft to be told
     * they have checked out.
     */
    private const TIMEOUT_SECONDS = 8;

    /**
     * Each destination is a separate workflow URL, because a Teams workflow is
     * bound to one channel when it is created — there is no way to choose the
     * channel per message. Punches and announcements therefore go to whichever
     * channel each URL was made in.
     */
    public const ATTENDANCE = 'teamsAttendanceWebhookUrl';
    public const ANNOUNCEMENTS = 'teamsAnnouncementsWebhookUrl';

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function isConfigured(string $channel = self::ATTENDANCE): bool
    {
        return filled($this->webhookUrl($channel));
    }

    private function webhookUrl(string $channel): ?string
    {
        $url = $this->settings->get('integrations', $channel);

        return filled($url) ? (string) $url : null;
    }

    /**
     * @param array<int, array{reference: string, seconds: int}> $tickets
     */
    public function announceCheckOut(
        string $employeeName,
        string $checkedOutAt,
        int $workedSeconds,
        int $effectedSeconds,
        array $tickets,
    ): void {
        $lines = [
            '**'.$employeeName.'** has Checked Out at '.$checkedOutAt,
            'Work Duration : '.AttendanceSummary::humanise($workedSeconds),
            'Effected Hours : '.AttendanceSummary::humanise($effectedSeconds),
        ];

        foreach ($tickets as $ticket) {
            $lines[] = $ticket['reference'].' — '.AttendanceSummary::humanise($ticket['seconds']);
        }

        if ($tickets === []) {
            $lines[] = '_No ticket time logged._';
        }

        $this->send(self::ATTENDANCE, $lines);
    }

    /**
     * A published company announcement, posted to the announcements channel.
     */
    public function announcePost(string $title, string $body, string $author, string $audience, bool $important): void
    {
        $this->send(self::ANNOUNCEMENTS, array_filter([
            ($important ? '**Important announcement** — ' : '**Announcement** — ').$title,
            $body,
            // Only worth a line when it is not the default; "All company" adds
            // nothing to a message posted in a company-wide channel.
            $audience !== 'All company' ? '_For: '.$audience.'_' : null,
            '_Posted by '.$author.'_',
        ]));
    }

    /**
     * @param array<int, string> $lines
     */
    private function send(string $channel, array $lines): void
    {
        $url = $this->webhookUrl($channel);

        if (! $url) {
            return;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post($url, $this->buildCard($lines));

            if ($response->failed()) {
                Log::warning('Teams announcement rejected.', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Teams announcement failed to send.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<int, string> $lines
     * @return array<string, mixed>
     */
    private function buildCard(array $lines): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                // Null content-url is required by the envelope even though the
                // card is inline.
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    // 1.4 is what the Teams workflow renderer supports.
                    'version' => '1.4',
                    'body' => array_map(fn (string $line) => [
                        'type' => 'TextBlock',
                        'text' => $line,
                        // Without this a long ticket list is truncated to one
                        // line per block.
                        'wrap' => true,
                    ], $lines),
                ],
            ]],
        ];
    }
}

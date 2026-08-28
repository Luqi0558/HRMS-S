<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TeamsNotifier
{

    private const TIMEOUT_SECONDS = 8;
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
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => array_map(fn (string $line) => [
                        'type' => 'TextBlock',
                        'text' => $line,
                        'wrap' => true,
                    ], $lines),
                ],
            ]],
        ];
    }
}
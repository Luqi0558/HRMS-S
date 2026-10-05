<?php

namespace Tests;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Pusher\Pusher;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keepBroadcastsOffTheNetwork();
    }

    /**
     * Resolves the user afresh from each request's bearer token.
     *
     * The auth guard caches the first user it resolves for the life of the
     * test, so a test that signed in as HR and then as an employee kept acting
     * as HR — "the employee cannot read X" passed or failed for reasons that
     * had nothing to do with the employee. Only token requests are reset; a
     * request made after actingAs() carries no header and keeps that user.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (isset($server['HTTP_AUTHORIZATION'])) {
            $this->app['auth']->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Swaps the Pusher client for one that never sends.
     *
     * The suite runs on the pusher driver on purpose — the null driver
     * authorises every channel, which would leave the channel-auth tests
     * asserting nothing. But every message, notification and payment the
     * tests create then broadcast for real, using whatever keys .env held: the
     * production Pusher app, which spent quota and slowed every run.
     *
     * Channel authorisation is local signing and is untouched; only the calls
     * that would reach Pusher's API are replaced.
     *
     * The client is swapped inside the broadcaster rather than the broadcaster
     * replaced: routes/channels.php registers its channel callbacks on the
     * broadcaster while the app boots, so a fresh one would have none.
     */
    private function keepBroadcastsOffTheNetwork(): void
    {
        $config = config('broadcasting.connections.pusher');
        $broadcaster = $this->app->make(BroadcastManager::class)->connection('pusher');

        if (! $broadcaster instanceof PusherBroadcaster) {
            return;
        }

        $broadcaster->setPusher(new class(
            (string) ($config['key'] ?? 'test-key'),
            (string) ($config['secret'] ?? 'test-secret'),
            (string) ($config['app_id'] ?? 'test-app'),
            $config['options'] ?? [],
        ) extends Pusher {
            public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
            {
                return new \stdClass();
            }

            public function triggerBatch(array $batch = [], bool $already_encoded = false): object
            {
                return new \stdClass();
            }
        });
    }
}

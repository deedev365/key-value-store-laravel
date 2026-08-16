<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Per-IP request throttling. Laravel 11+ leaves API routes unthrottled unless
 * a limiter is named, so this pins both that the limit exists and what a
 * caller is told when they hit it.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function getFrom(string $ip, string $uri = '/object/get_all_records'): TestResponse
    {
        return $this->call('GET', $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ]);
    }

    /**
     * Spends $count of the quota and returns the last response.
     */
    private function exhaust(string $ip, int $count): TestResponse
    {
        $response = null;

        for ($i = 0; $i < $count; $i++) {
            $response = $this->getFrom($ip);
        }

        return $response;
    }

    public function test_requests_up_to_the_limit_are_allowed(): void
    {
        $limit = (int) config('kvstore.max_requests_per_minute');

        $this->exhaust('10.0.0.1', $limit)->assertOk();
    }

    public function test_the_request_past_the_limit_is_refused_with_429(): void
    {
        $limit = (int) config('kvstore.max_requests_per_minute');
        $this->exhaust('10.0.0.2', $limit);

        $this->getFrom('10.0.0.2')->assertStatus(429);
    }

    public function test_the_refusal_says_how_long_to_wait(): void
    {
        $limit = (int) config('kvstore.max_requests_per_minute');
        $this->exhaust('10.0.0.3', $limit);

        $response = $this->getFrom('10.0.0.3');

        $response->assertStatus(429);

        $seconds = $response->json('retry_after');
        $this->assertIsInt($seconds);
        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual(60, $seconds);

        $this->assertSame(
            "Too many requests. Try again in {$seconds} seconds.",
            $response->json('message')
        );
    }

    public function test_the_refusal_carries_retry_after_and_rate_limit_headers(): void
    {
        $limit = (int) config('kvstore.max_requests_per_minute');
        $this->exhaust('10.0.0.4', $limit);

        $response = $this->getFrom('10.0.0.4');

        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', $limit)
            ->assertHeader('X-RateLimit-Remaining', 0)
            ->assertHeader('X-RateLimit-Reset');

        $this->assertSame(
            (int) $response->headers->get('Retry-After'),
            $response->json('retry_after')
        );
    }

    public function test_allowed_responses_advertise_the_remaining_quota(): void
    {
        $limit = (int) config('kvstore.max_requests_per_minute');

        $this->getFrom('10.0.0.5')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', $limit)
            ->assertHeader('X-RateLimit-Remaining', $limit - 1);

        $this->getFrom('10.0.0.5')
            ->assertHeader('X-RateLimit-Remaining', $limit - 2);
    }

    public function test_the_refusal_is_json_and_carries_the_security_headers(): void
    {
        $limit = (int) config('kvstore.max_requests_per_minute');
        $this->exhaust('10.0.0.6', $limit);

        $this->getFrom('10.0.0.6')
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_quota_is_per_ip(): void
    {
        $limit = (int) config('kvstore.max_requests_per_minute');
        $this->exhaust('10.0.0.7', $limit);

        $this->getFrom('10.0.0.7')->assertStatus(429);
        $this->getFrom('10.0.0.8')->assertOk();
    }

    public function test_reads_and_writes_share_one_quota(): void
    {
        $limit = (int) config('kvstore.max_requests_per_minute');
        $this->exhaust('10.0.0.9', $limit);

        $this->call('POST', '/object', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.0.0.9',
        ], '{"mykey":"value"}')->assertStatus(429);

        $this->assertSame(0, KvEntry::count());
    }

    public function test_a_throttled_write_never_reaches_the_database(): void
    {
        $limit = (int) config('kvstore.max_requests_per_minute');
        $this->exhaust('10.0.0.10', $limit);

        for ($i = 0; $i < 5; $i++) {
            $this->call('POST', '/object', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '10.0.0.10',
            ], '{"flood":"value"}')->assertStatus(429);
        }

        $this->assertSame(0, KvEntry::count());
    }

    public function test_the_limit_is_configurable(): void
    {
        config(['kvstore.max_requests_per_minute' => 3]);

        $this->exhaust('10.0.0.11', 3)->assertOk();
        $this->getFrom('10.0.0.11')->assertStatus(429);
    }

    public function test_the_frontend_is_not_throttled(): void
    {
        // Only the API routes are throttled; the page itself must keep loading.
        config(['kvstore.max_requests_per_minute' => 2]);
        $this->exhaust('10.0.0.12', 2);
        $this->getFrom('10.0.0.12')->assertStatus(429);

        $this->call('GET', '/', [], [], [], ['REMOTE_ADDR' => '10.0.0.12'])->assertOk();
    }
}

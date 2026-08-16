<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Size and nesting limits on a write. Every accepted body becomes a row that
 * is never reclaimed — history is append-only — so these are the only thing
 * bounding how fast the store can grow.
 */
class RequestLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function postRaw(string $body, array $headers = []): TestResponse
    {
        return $this->call('POST', '/object', [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $headers), $body);
    }

    /**
     * A body of exactly $bytes total, padding the value to fit.
     */
    private function bodyOfSize(int $bytes): string
    {
        $envelope = strlen('{"k":""}');

        return '{"k":"'.str_repeat('a', $bytes - $envelope).'"}';
    }

    // ---------------------------------------------------------------
    // Body size
    // ---------------------------------------------------------------

    public function test_a_body_at_the_size_limit_is_accepted(): void
    {
        $max = (int) config('kvstore.max_body_bytes');
        $body = $this->bodyOfSize($max);

        $this->assertSame($max, strlen($body));
        $this->postRaw($body)->assertCreated();
    }

    public function test_a_body_one_byte_over_the_limit_is_rejected(): void
    {
        $max = (int) config('kvstore.max_body_bytes');

        $this->postRaw($this->bodyOfSize($max + 1))
            ->assertStatus(413)
            ->assertJsonPath('message', "Request body must not exceed {$max} bytes.");

        $this->assertSame(0, KvEntry::count());
    }

    public function test_a_multi_megabyte_body_is_rejected(): void
    {
        $this->postRaw('{"bigkey":"'.str_repeat('a', 2 * 1024 * 1024).'"}')
            ->assertStatus(413);

        $this->assertSame(0, KvEntry::count());
    }

    public function test_an_understated_content_length_does_not_bypass_the_limit(): void
    {
        // Content-Length is client-supplied; the real body length is what
        // decides.
        $this->postRaw(
            '{"bigkey":"'.str_repeat('a', 2 * 1024 * 1024).'"}',
            ['CONTENT_LENGTH' => '10']
        )->assertStatus(413);

        $this->assertSame(0, KvEntry::count());
    }

    public function test_an_overstated_content_length_is_rejected_on_the_header_alone(): void
    {
        $this->postRaw('{"k":"v"}', ['CONTENT_LENGTH' => (string) (10 * 1024 * 1024)])
            ->assertStatus(413);
    }

    public function test_the_size_limit_is_configurable(): void
    {
        config(['kvstore.max_body_bytes' => 32]);

        $this->postRaw('{"k":"v"}')->assertCreated();
        $this->postRaw('{"k":"'.str_repeat('a', 64).'"}')->assertStatus(413);
    }

    public function test_reads_are_not_affected_by_the_body_limit(): void
    {
        $this->postRaw('{"k":"v"}')->assertCreated();

        $this->getJson('/object/k')->assertOk();
        $this->getJson('/object/k/history')->assertOk();
        $this->getJson('/object/get_all_records')->assertOk();
        $this->deleteJson('/object/k')->assertNoContent();
    }

    public function test_an_oversized_body_still_carries_the_security_headers(): void
    {
        $this->postRaw($this->bodyOfSize(200000))
            ->assertStatus(413)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/json');
    }

    // ---------------------------------------------------------------
    // Nesting depth
    // ---------------------------------------------------------------

    /**
     * A value nested exactly $levels deep, e.g. 3 => [[["x"]]].
     */
    private function nestedValue(int $levels): string
    {
        return str_repeat('[', $levels).'"x"'.str_repeat(']', $levels);
    }

    public function test_a_value_at_the_depth_limit_is_accepted(): void
    {
        $max = (int) config('kvstore.max_value_depth');

        $this->postRaw('{"deepkey":'.$this->nestedValue($max).'}')->assertCreated();
    }

    public function test_a_value_one_level_past_the_depth_limit_is_rejected(): void
    {
        $max = (int) config('kvstore.max_value_depth');

        $this->postRaw('{"deepkey":'.$this->nestedValue($max + 1).'}')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);

        $this->assertSame(0, KvEntry::count());
    }

    public function test_deep_nesting_of_objects_is_limited_too(): void
    {
        $max = (int) config('kvstore.max_value_depth');
        $deep = str_repeat('{"a":', $max + 1).'1'.str_repeat('}', $max + 1);

        $this->postRaw('{"deepkey":'.$deep.'}')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    public function test_the_depth_limit_is_configurable(): void
    {
        config(['kvstore.max_value_depth' => 3]);

        $this->postRaw('{"k":'.$this->nestedValue(3).'}')->assertCreated();
        $this->postRaw('{"k":'.$this->nestedValue(4).'}')->assertStatus(422);
    }

    public function test_a_depth_violation_is_reported_distinctly_from_malformed_json(): void
    {
        $max = (int) config('kvstore.max_value_depth');

        $this->postRaw('{"k":'.$this->nestedValue($max + 1).'}')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);

        // Malformed JSON is a body error, not a value error — the two must not
        // be conflated, or a client cannot tell what to fix.
        $this->postRaw('{"k": ')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_a_flat_value_is_unaffected_by_the_depth_limit(): void
    {
        $this->postRaw('{"k":{"a":1,"b":[1,2,3],"c":{"d":"e"}}}')->assertCreated();
    }
}

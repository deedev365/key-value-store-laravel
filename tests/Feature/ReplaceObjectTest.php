<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PUT /object/{key}
 */
class ReplaceObjectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return TestResponse<Response>
     */
    private function putRaw(string $uri, string $body): TestResponse
    {
        return $this->call('PUT', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    // ---------------------------------------------------------------
    // Correcting the current version
    // ---------------------------------------------------------------

    public function test_it_replaces_the_current_version_and_returns_it(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 1000]);

        $response = $this->putJson('/object/mykey', ['mykey' => 'corrected']);

        $response->assertOk()->assertJson([
            'key' => 'mykey',
            'value' => 'corrected',
        ]);
        $response->assertJsonStructure(['key', 'value', 'timestamp']);

        $this->getJson('/object/mykey')->assertOk()->assertJson(['value' => 'corrected']);
    }

    public function test_it_answers_200_rather_than_201(): void
    {
        // The distinction is the contract: nothing new is addressable, the
        // resource this URL names simply has a new representation.
        KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 1000]);

        $this->putJson('/object/mykey', ['mykey' => 'corrected'])->assertStatus(200);
    }

    public function test_the_replaced_version_is_gone_and_the_others_survive(): void
    {
        $keep = KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);
        $replaced = KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 2000]);

        $this->putJson('/object/mykey', ['mykey' => 'corrected'])->assertOk();

        $this->assertDatabaseMissing('kv_entries', ['id' => $replaced->id]);
        $this->assertDatabaseHas('kv_entries', ['id' => $keep->id]);
        $this->assertSame(2, KvEntry::where('key', 'mykey')->count());

        $this->assertSame(
            ['value1', 'corrected'],
            $this->getJson('/object/mykey/history')->json('*.value')
        );
    }

    public function test_the_correction_is_stamped_with_the_moment_it_was_written(): void
    {
        // recorded_at means "when this version was written" everywhere else, so
        // a correction must not be back-dated to the version it replaces.
        Carbon::setTestNow(Carbon::createFromTimestamp(5000));
        KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 1000]);

        $this->putJson('/object/mykey', ['mykey' => 'corrected'])
            ->assertOk()
            ->assertJson(['timestamp' => 5000]);
    }

    #[DataProvider('valueProvider')]
    public function test_the_new_value_is_stored_verbatim(mixed $value): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 1000]);

        $response = $this->putJson('/object/mykey', ['mykey' => $value]);

        $response->assertOk();
        $this->assertSame($value, $response->json('value'));
        $this->assertSame($value, $this->getJson('/object/mykey')->json('value'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function valueProvider(): array
    {
        return [
            'null' => [null],
            'false' => [false],
            'zero' => [0],
            'empty string' => [''],
            'object' => [['message' => 'Sale extended']],
        ];
    }

    // ---------------------------------------------------------------
    // Correcting the version named by a timestamp
    // ---------------------------------------------------------------

    public function test_it_replaces_the_version_that_was_current_at_a_timestamp(): void
    {
        $older = KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 2000]);
        $newer = KvEntry::create(['key' => 'mykey', 'value' => 'value3', 'recorded_at' => 3000]);

        $this->putJson('/object/mykey?timestamp=2500', ['mykey' => 'corrected'])->assertOk();

        $this->assertDatabaseMissing('kv_entries', ['id' => $older->id]);
        $this->assertDatabaseHas('kv_entries', ['id' => $newer->id]);
    }

    public function test_correcting_an_older_version_makes_the_correction_current(): void
    {
        // The documented consequence of appending: there is no way to write a
        // version that is not the newest one. Asserted rather than left to be
        // discovered.
        KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 2000]);
        KvEntry::create(['key' => 'mykey', 'value' => 'value3', 'recorded_at' => 3000]);

        $this->putJson('/object/mykey?timestamp=2500', ['mykey' => 'corrected'])->assertOk();

        $this->getJson('/object/mykey')->assertOk()->assertJson(['value' => 'corrected']);
    }

    // ---------------------------------------------------------------
    // Scheduling
    // ---------------------------------------------------------------

    public function test_the_schedule_of_the_replaced_version_is_carried_over_when_none_is_given(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(5000));
        KvEntry::create([
            'key' => 'mykey',
            'value' => 'typo',
            'recorded_at' => 1000,
            'publish_time' => 2000,
        ]);

        $this->putJson('/object/mykey', ['mykey' => 'corrected'])
            ->assertOk()
            ->assertJson(['publish_time' => 2000]);
    }

    public function test_an_unscheduled_version_stays_unscheduled(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 1000]);

        $this->putJson('/object/mykey', ['mykey' => 'corrected'])
            ->assertOk()
            ->assertJsonMissingPath('publish_time');
    }

    public function test_a_version_that_is_not_published_yet_cannot_be_replaced(): void
    {
        // The publish filter guards writes as well as reads: an editor cannot
        // reach a queued campaign through the edit endpoint either.
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));
        $queued = KvEntry::create([
            'key' => 'mykey',
            'value' => 'queued',
            'recorded_at' => 900,
            'publish_time' => 5000,
        ]);

        $this->putJson('/object/mykey', ['mykey' => 'sneaky'])->assertNotFound();

        $this->assertDatabaseHas('kv_entries', ['id' => $queued->id]);
        $this->assertSame(1, KvEntry::where('key', 'mykey')->count());
    }

    public function test_a_correction_can_be_given_a_schedule_of_its_own(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(5000));
        KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 1000]);

        $this->putJson('/object/mykey?publish_time=4000', ['mykey' => 'corrected'])
            ->assertOk()
            ->assertJson(['publish_time' => 4000]);

        $this->getJson('/object/mykey')->assertOk()->assertJson(['value' => 'corrected']);
    }

    public function test_a_given_schedule_overrides_the_one_carried_over(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(5000));
        KvEntry::create([
            'key' => 'mykey',
            'value' => 'typo',
            'recorded_at' => 1000,
            'publish_time' => 2000,
        ]);

        $this->putJson('/object/mykey?publish_time=3000', ['mykey' => 'corrected'])
            ->assertOk()
            ->assertJson(['publish_time' => 3000]);
    }

    public function test_a_correction_scheduled_for_the_future_is_not_visible_yet(): void
    {
        // The honest consequence of scheduling an edit: the version it replaces
        // is gone, and the correction is not readable until its time comes. The
        // page says so before the save; the API simply obeys.
        Carbon::setTestNow(Carbon::createFromTimestamp(5000));
        KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 1000]);

        $this->putJson('/object/mykey?publish_time=9000', ['mykey' => 'corrected'])
            ->assertOk()
            ->assertJson(['publish_time' => 9000]);

        $this->getJson('/object/mykey')->assertNotFound();

        Carbon::setTestNow(Carbon::createFromTimestamp(9001));
        $this->getJson('/object/mykey')->assertOk()->assertJson(['value' => 'corrected']);
    }

    public function test_a_non_integer_publish_time_is_refused(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'typo', 'recorded_at' => 1000]);

        $this->putJson('/object/mykey?publish_time=soon', ['mykey' => 'corrected'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('publish_time');
    }

    // ---------------------------------------------------------------
    // Misses
    // ---------------------------------------------------------------

    public function test_replacing_an_unknown_key_returns_404_and_writes_nothing(): void
    {
        $this->putJson('/object/does-not-exist', ['does-not-exist' => 'value1'])
            ->assertNotFound()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseCount('kv_entries', 0);
    }

    public function test_replacing_before_the_first_version_returns_404(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);

        $this->putJson('/object/mykey?timestamp=500', ['mykey' => 'corrected'])
            ->assertNotFound()
            ->assertJsonStructure(['message']);

        $this->assertSame(1, KvEntry::where('key', 'mykey')->count());
    }

    // ---------------------------------------------------------------
    // Refusals
    // ---------------------------------------------------------------

    public function test_a_body_key_that_differs_from_the_path_key_is_refused(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);

        $this->putJson('/object/mykey', ['otherkey' => 'corrected'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');

        $this->assertSame(1, KvEntry::where('key', 'mykey')->count());
        $this->assertSame(0, KvEntry::where('key', 'otherkey')->count());
    }

    #[DataProvider('invalidBodyProvider')]
    public function test_a_body_that_is_not_a_single_pair_is_refused(string $body, string $field): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);

        $this->putRaw('/object/mykey', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame(1, KvEntry::where('key', 'mykey')->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidBodyProvider(): array
    {
        return [
            'empty body' => ['', 'body'],
            'not json' => ['not json', 'body'],
            'a list' => ['[]', 'body'],
            'no properties' => ['{}', 'body'],
            'two properties' => ['{"mykey":"a","other":"b"}', 'body'],
        ];
    }

    public function test_a_too_deeply_nested_value_is_refused(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);

        $depth = (int) config('kvstore.max_value_depth') + 5;
        $value = str_repeat('[', $depth).'1'.str_repeat(']', $depth);

        $this->putRaw('/object/mykey', '{"mykey":'.$value.'}')
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    public function test_a_non_integer_timestamp_is_refused(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);

        $this->putJson('/object/mykey?timestamp=abc', ['mykey' => 'corrected'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timestamp');
    }

    public function test_a_key_the_store_would_never_accept_does_not_match_the_route(): void
    {
        // The route pattern is the first guard on the new verb too.
        $this->putJson('/object/bad key!', ['bad key!' => 'v'])->assertNotFound();
    }

    public function test_the_listings_own_segment_cannot_be_edited(): void
    {
        // 405 rather than 404: /object/get_all_records is the listing's path and
        // it answers GET only, so the edit route never sees it. The reserved key
        // is unreachable either way — Key::RESERVED would refuse the body too.
        $this->putJson('/object/get_all_records', ['get_all_records' => 'v'])
            ->assertStatus(405);
    }
}

<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /object/get_all_records/{page?}
 */
class GetAllRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_the_latest_version_of_every_key(): void
    {
        KvEntry::create(['key' => 'a', 'value' => 'a1', 'recorded_at' => 1000]);
        KvEntry::create(['key' => 'a', 'value' => 'a2', 'recorded_at' => 2000]);
        KvEntry::create(['key' => 'b', 'value' => 'b1', 'recorded_at' => 1500]);

        $response = $this->getJson('/object/get_all_records');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['key' => 'a', 'value' => 'a2']);
        $response->assertJsonFragment(['key' => 'b', 'value' => 'b1']);
        $response->assertJsonMissing(['value' => 'a1']);
    }

    public function test_get_all_records_returns_an_empty_array_when_the_store_is_empty(): void
    {
        $response = $this->getJson('/object/get_all_records');

        $response->assertOk()->assertExactJson([]);
    }

    // ---------------------------------------------------------------
    // Scheduled versions stay out of the listing until their time
    // ---------------------------------------------------------------

    public function test_it_omits_a_key_whose_only_version_is_still_scheduled(): void
    {
        KvEntry::create([
            'key' => 'route.bangkok-chiang-mai.banner',
            'value' => 'campaign',
            'recorded_at' => now()->timestamp,
            'publish_time' => now()->timestamp + 3600,
        ]);

        $this->getJson('/object/get_all_records')->assertOk()->assertExactJson([]);
    }

    public function test_it_lists_the_live_version_while_a_newer_one_is_scheduled(): void
    {
        KvEntry::create([
            'key' => 'route.bangkok-chiang-mai.banner',
            'value' => 'current banner',
            'recorded_at' => now()->timestamp - 100,
            'publish_time' => null,
        ]);

        KvEntry::create([
            'key' => 'route.bangkok-chiang-mai.banner',
            'value' => 'campaign banner',
            'recorded_at' => now()->timestamp,
            'publish_time' => now()->timestamp + 3600,
        ]);

        $this->getJson('/object/get_all_records')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['value' => 'current banner'])
            ->assertJsonMissing(['value' => 'campaign banner']);
    }

    public function test_the_scheduled_version_appears_once_the_clock_passes_its_publish_time(): void
    {
        // No worker runs in between: the same request answers differently
        // because the listing asks the clock, not because anything was
        // promoted in the background.
        $publishAt = now()->timestamp + 3600;

        KvEntry::create([
            'key' => 'route.bangkok-chiang-mai.banner',
            'value' => 'campaign banner',
            'recorded_at' => now()->timestamp,
            'publish_time' => $publishAt,
        ]);

        $this->getJson('/object/get_all_records')->assertOk()->assertExactJson([]);

        // Past the publish time, not onto it: the rule is publish_time < now.
        $this->travelTo(now()->addSeconds(3601));

        $this->getJson('/object/get_all_records')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['value' => 'campaign banner']);
    }

    public function test_a_version_written_before_the_column_existed_is_still_listed(): void
    {
        // Backwards compatibility: publish_time is null on every pre-existing
        // row, and null must not read as "never publish".
        KvEntry::create([
            'key' => 'legacy',
            'value' => 'still here',
            'recorded_at' => 1000,
            'publish_time' => null,
        ]);

        $this->getJson('/object/get_all_records')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['value' => 'still here']);
    }
}

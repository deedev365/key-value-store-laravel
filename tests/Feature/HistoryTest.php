<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /object/{key}/history
 */
class HistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_full_history_of_a_key_oldest_first(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);
        KvEntry::create(['key' => 'mykey', 'value' => 'value2', 'recorded_at' => 2000]);
        KvEntry::create(['key' => 'mykey', 'value' => 'value3', 'recorded_at' => 3000]);
        KvEntry::create(['key' => 'otherkey', 'value' => 'ignored', 'recorded_at' => 1500]);

        $response = $this->getJson('/object/mykey/history');

        $response->assertOk();
        $response->assertJsonCount(3);
        $response->assertJsonPath('0.value', 'value1');
        $response->assertJsonPath('1.value', 'value2');
        $response->assertJsonPath('2.value', 'value3');
    }

    public function test_history_is_an_empty_array_for_an_unknown_key(): void
    {
        $this->getJson('/object/does-not-exist/history')
            ->assertOk()
            ->assertExactJson([]);
    }

    // ---------------------------------------------------------------
    // publish_time
    // ---------------------------------------------------------------

    public function test_history_omits_a_version_that_is_still_scheduled(): void
    {
        // The log is public, so a queued campaign listed here would be
        // announced before its time.
        $key = 'route.bangkok-chiang-mai.banner';

        KvEntry::create(['key' => $key, 'value' => 'current banner', 'recorded_at' => now()->timestamp - 100]);
        KvEntry::create([
            'key' => $key,
            'value' => 'campaign banner',
            'recorded_at' => now()->timestamp,
            'publish_time' => now()->timestamp + 3600,
        ]);

        $this->getJson('/object/'.$key.'/history')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['value' => 'current banner'])
            ->assertJsonMissing(['value' => 'campaign banner']);
    }

    public function test_history_includes_the_version_once_its_time_has_passed(): void
    {
        $key = 'route.bangkok-chiang-mai.banner';

        KvEntry::create(['key' => $key, 'value' => 'current banner', 'recorded_at' => now()->timestamp - 100]);
        KvEntry::create([
            'key' => $key,
            'value' => 'campaign banner',
            'recorded_at' => now()->timestamp,
            'publish_time' => now()->timestamp + 3600,
        ]);

        $this->travelTo(now()->addSeconds(3601));

        $this->getJson('/object/'.$key.'/history')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['value' => 'campaign banner']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /object/{key}
 * GET /object/{key}?timestamp=<unix timestamp>
 */
class ShowObjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_latest_value_for_a_key(): void
    {
        $this->postJson('/object', ['mykey' => 'value1'])->assertCreated();
        $this->postJson('/object', ['mykey' => 'value2'])->assertCreated();

        $response = $this->getJson('/object/mykey');

        $response->assertOk()->assertJson([
            'key' => 'mykey',
            'value' => 'value2',
        ]);
    }

    public function test_it_returns_404_for_an_unknown_key(): void
    {
        $this->getJson('/object/does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_it_returns_the_value_that_was_current_at_a_given_timestamp(): void
    {
        $sixPm = 1440568800; // 2015-08-26 18:00:00 UTC
        $sixOhFivePm = 1440569100; // 18:05:00 UTC

        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => $sixPm]);
        KvEntry::create(['key' => 'mykey', 'value' => 'value2', 'recorded_at' => $sixOhFivePm]);

        // 18:03pm — after the first write, before the second.
        $response = $this->getJson('/object/mykey?timestamp=1440568980');

        $response->assertOk()->assertJson([
            'key' => 'mykey',
            'value' => 'value1',
        ]);
    }

    /**
     * Reproduces the worked example from the exercise brief verbatim:
     * POST mykey=value1 at 6pm, POST mykey=value2 at 6.05pm, then GET at
     * timestamp=1440568980 [6.03pm] must return value1.
     */
    public function test_it_matches_the_brief_worked_example_exactly(): void
    {
        $this->postJson('/object', ['mykey' => 'value1']);

        KvEntry::where('key', 'mykey')->update(['recorded_at' => 1440568800]); // 6pm

        $this->postJson('/object', ['mykey' => 'value2']);

        KvEntry::where('key', 'mykey')->orderByDesc('id')->limit(1)
            ->update(['recorded_at' => 1440569100]); // 6.05pm

        $this->getJson('/object/mykey')
            ->assertOk()
            ->assertJson(['key' => 'mykey', 'value' => 'value2']);

        $this->getJson('/object/mykey?timestamp=1440568980')
            ->assertOk()
            ->assertJson(['key' => 'mykey', 'value' => 'value1']);
    }

    public function test_it_returns_the_exact_value_when_timestamp_matches_a_write_exactly(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);
        KvEntry::create(['key' => 'mykey', 'value' => 'value2', 'recorded_at' => 2000]);

        $this->getJson('/object/mykey?timestamp=2000')
            ->assertOk()
            ->assertJson(['value' => 'value2']);
    }

    public function test_it_returns_404_when_no_version_existed_yet_at_the_given_timestamp(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 2000]);

        $this->getJson('/object/mykey?timestamp=1000')->assertNotFound();
    }

    public function test_it_rejects_a_non_integer_timestamp(): void
    {
        KvEntry::create(['key' => 'mykey', 'value' => 'value1', 'recorded_at' => 1000]);

        $this->getJson('/object/mykey?timestamp=not-a-number')
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // publish_time
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function schedule(array $attributes): KvEntry
    {
        return KvEntry::create($attributes + ['recorded_at' => now()->timestamp]);
    }

    public function test_it_serves_the_version_with_the_greatest_arrived_publish_time(): void
    {
        // Two versions of one key, both already due at different times: the
        // later slot is the one on air.
        $key = 'route.bangkok-chiang-mai.banner';

        $this->schedule(['key' => $key, 'value' => 'morning banner', 'publish_time' => now()->timestamp - 7200]);
        $this->schedule(['key' => $key, 'value' => 'afternoon banner', 'publish_time' => now()->timestamp - 60]);

        $this->getJson('/object/'.$key)
            ->assertOk()
            ->assertJson(['key' => $key, 'value' => 'afternoon banner']);
    }

    public function test_it_withholds_a_version_whose_publish_time_has_not_arrived(): void
    {
        $key = 'route.bangkok-chiang-mai.banner';

        $this->schedule(['key' => $key, 'value' => 'current banner', 'publish_time' => null]);
        $this->schedule(['key' => $key, 'value' => 'campaign banner', 'publish_time' => now()->timestamp + 3600]);

        $this->getJson('/object/'.$key)
            ->assertOk()
            ->assertJson(['value' => 'current banner']);
    }

    public function test_the_scheduled_version_takes_over_when_its_time_arrives(): void
    {
        $key = 'route.bangkok-chiang-mai.banner';

        $this->schedule(['key' => $key, 'value' => 'current banner', 'publish_time' => null]);
        $this->schedule(['key' => $key, 'value' => 'campaign banner', 'publish_time' => now()->timestamp + 3600]);

        $this->getJson('/object/'.$key)->assertOk()->assertJson(['value' => 'current banner']);

        // Past the publish time, not onto it: the rule is publish_time < now.
        $this->travelTo(now()->addSeconds(3601));

        $this->getJson('/object/'.$key)->assertOk()->assertJson(['value' => 'campaign banner']);
    }

    public function test_a_key_with_nothing_published_yet_is_404(): void
    {
        // Indistinguishable from an unknown key on purpose: a distinct message
        // would confirm that embargoed content exists under this name.
        $key = 'route.bangkok-chiang-mai.banner';

        $this->schedule(['key' => $key, 'value' => 'campaign banner', 'publish_time' => now()->timestamp + 3600]);

        $this->getJson('/object/'.$key)
            ->assertNotFound()
            ->assertJson(['message' => "No value found for key '{$key}'."]);
    }

    public function test_a_future_timestamp_does_not_reveal_a_scheduled_version(): void
    {
        // ?timestamp= travels through recorded_at, but publish_time is still
        // compared against the real clock — so this is not a way in.
        $key = 'route.bangkok-chiang-mai.banner';

        $this->schedule(['key' => $key, 'value' => 'current banner', 'publish_time' => null]);
        $this->schedule(['key' => $key, 'value' => 'campaign banner', 'publish_time' => now()->timestamp + 3600]);

        $this->getJson('/object/'.$key.'?timestamp=9999999999')
            ->assertOk()
            ->assertJson(['value' => 'current banner']);
    }

    public function test_an_unscheduled_version_is_ranked_by_when_it_was_written(): void
    {
        $key = 'operator.srt.booking_notice';

        KvEntry::create([
            'key' => $key,
            'value' => 'scheduled notice',
            'recorded_at' => 1000,
            'publish_time' => 3000,
        ]);

        KvEntry::create([
            'key' => $key,
            'value' => 'notice written later',
            'recorded_at' => 4000,
            'publish_time' => null,
        ]);

        $this->getJson('/object/'.$key)
            ->assertOk()
            ->assertJson(['value' => 'notice written later']);
    }
}

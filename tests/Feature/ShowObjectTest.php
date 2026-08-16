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
}

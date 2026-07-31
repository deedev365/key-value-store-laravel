<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/object/{key}/history
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

        $response = $this->getJson('/api/object/mykey/history');

        $response->assertOk();
        $response->assertJsonCount(3);
        $response->assertJsonPath('0.value', 'value1');
        $response->assertJsonPath('1.value', 'value2');
        $response->assertJsonPath('2.value', 'value3');
    }

    public function test_history_is_an_empty_array_for_an_unknown_key(): void
    {
        $this->getJson('/api/object/does-not-exist/history')
            ->assertOk()
            ->assertExactJson([]);
    }
}

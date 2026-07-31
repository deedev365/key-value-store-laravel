<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/object/get_all_records/{page?}
 */
class GetAllRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_the_latest_version_of_every_key(): void
    {
        KvEntry::create(['key' => 'a', 'value' => 'a1', 'recorded_at' => 1000]);
        KvEntry::create(['key' => 'a', 'value' => 'a2', 'recorded_at' => 2000]);
        KvEntry::create(['key' => 'b', 'value' => 'b1', 'recorded_at' => 1500]);

        $response = $this->getJson('/api/object/get_all_records');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['key' => 'a', 'value' => 'a2']);
        $response->assertJsonFragment(['key' => 'b', 'value' => 'b1']);
        $response->assertJsonMissing(['value' => 'a1']);
    }

    public function test_get_all_records_returns_an_empty_array_when_the_store_is_empty(): void
    {
        $response = $this->getJson('/api/object/get_all_records');

        $response->assertOk()->assertExactJson([]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/object
 */
class StoreObjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_key_and_returns_the_created_record(): void
    {
        $response = $this->postJson('/api/object', ['mykey' => 'value1']);

        $response->assertCreated()->assertJson([
            'key' => 'mykey',
            'value' => 'value1',
        ]);
        $response->assertJsonStructure(['key', 'value', 'timestamp']);

        $this->assertDatabaseHas('kv_entries', [
            'key' => 'mykey',
        ]);
    }

    public function test_it_accepts_a_json_object_as_the_value(): void
    {
        $payload = ['nested' => ['a' => 1, 'b' => [true, false, null]]];

        $response = $this->postJson('/api/object', ['mykey' => $payload]);

        $response->assertCreated()->assertJson([
            'key' => 'mykey',
            'value' => $payload,
        ]);
    }

    public function test_it_handles_a_numeric_looking_key_as_a_string(): void
    {
        // json_decode(..., true) turns a numeric object property like "123"
        // into a PHP integer array key; the key must still round-trip as
        // the string "123".
        $response = $this->postJson('/api/object', ['123' => 'value1']);

        $response->assertCreated()->assertJson(['key' => '123', 'value' => 'value1']);
        $this->getJson('/api/object/123')->assertOk()->assertJson(['key' => '123']);
    }

    public function test_it_rejects_a_body_with_more_than_one_key(): void
    {
        $this->postJson('/api/object', ['a' => 1, 'b' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_it_rejects_an_empty_body(): void
    {
        $this->postJson('/api/object', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_it_rejects_a_json_array_body(): void
    {
        $this->postJson('/api/object', [1, 2, 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_it_rejects_an_empty_string_key(): void
    {
        $this->postJson('/api/object', ['' => 'value1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_it_rejects_a_key_with_special_characters(): void
    {
        $this->postJson('/api/object', ['bad key!' => 'value1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_it_stores_a_new_version_rather_than_overwriting(): void
    {
        $this->postJson('/api/object', ['mykey' => 'value1']);
        $this->postJson('/api/object', ['mykey' => 'value2']);

        $this->assertSame(2, KvEntry::where('key', 'mykey')->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * POST /object
 */
class StoreObjectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return TestResponse<Response>
     */
    private function postRaw(string $body): TestResponse
    {
        return $this->call('POST', '/object', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_it_stores_a_key_and_returns_the_created_record(): void
    {
        $response = $this->postJson('/object', ['mykey' => 'value1']);

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

        $response = $this->postJson('/object', ['mykey' => $payload]);

        $response->assertCreated()->assertJson([
            'key' => 'mykey',
            'value' => $payload,
        ]);
    }

    #[DataProvider('falsyValueProvider')]
    public function test_it_stores_and_returns_falsy_values(mixed $value): void
    {
        // null in particular reaches the column unencoded by the json cast,
        // so it exercises the value column's nullability.
        $response = $this->postJson('/object', ['mykey' => $value]);

        $response->assertCreated();
        $this->assertSame($value, $response->json('value'));

        $this->getJson('/object/mykey')
            ->assertOk()
            ->assertExactJson([
                'key' => 'mykey',
                'value' => $value,
                'timestamp' => $response->json('timestamp'),
            ]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function falsyValueProvider(): array
    {
        return [
            'null' => [null],
            'false' => [false],
            'zero' => [0],
            'empty string' => [''],
            'empty array' => [[]],
        ];
    }

    public function test_it_stores_string_values_verbatim(): void
    {
        // Laravel trims strings and converts empty ones to null globally;
        // that must not apply to stored values (see bootstrap/app.php).
        $response = $this->postJson('/object', ['mykey' => '  spaced  ']);

        $response->assertCreated();
        $this->assertSame('  spaced  ', $response->json('value'));
        $this->assertSame('  spaced  ', $this->getJson('/object/mykey')->json('value'));
    }

    public function test_it_keeps_a_null_value_distinct_from_a_missing_key(): void
    {
        $this->postJson('/object', ['nullkey' => null])->assertCreated();

        $this->getJson('/object/nullkey')
            ->assertOk()
            ->assertJson(['key' => 'nullkey', 'value' => null]);

        $this->getJson('/object/neverwritten')->assertNotFound();
    }

    public function test_it_handles_a_numeric_looking_key_as_a_string(): void
    {
        // json_decode(..., true) turns a numeric object property like "123"
        // into a PHP integer array key; the key must still round-trip as
        // the string "123".
        $response = $this->postJson('/object', ['123' => 'value1']);

        $response->assertCreated()->assertJson(['key' => '123', 'value' => 'value1']);
        $this->getJson('/object/123')->assertOk()->assertJson(['key' => '123']);
    }

    public function test_it_rejects_a_body_with_more_than_one_key(): void
    {
        $this->postJson('/object', ['a' => 1, 'b' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    /**
     * A raw body, because postJson([]) encodes to `[]` — an empty JSON *array*,
     * which is the array-body case below rather than this one. The empty object
     * fails on the "exactly one property" rule instead, and that branch has no
     * other cover.
     */
    public function test_it_rejects_an_empty_json_object(): void
    {
        $this->postRaw('{}')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_it_rejects_a_json_array_body(): void
    {
        $this->postJson('/object', [1, 2, 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_it_rejects_an_empty_string_key(): void
    {
        $this->postJson('/object', ['' => 'value1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_it_rejects_a_key_with_special_characters(): void
    {
        $this->postJson('/object', ['bad key!' => 'value1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    /**
     * '/object/get_all_records' is claimed by the listing route, which is
     * registered before the {key} wildcard. Without this guard the write
     * succeeded with a 201 and the record could never be read back through
     * its own URL — the listing answered instead, with an array where the
     * caller expected the single object it had just written.
     */
    public function test_it_rejects_a_key_reserved_by_a_literal_route(): void
    {
        $this->postJson('/object', ['get_all_records' => 'value1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);

        $this->assertSame(0, KvEntry::where('key', 'get_all_records')->count());
    }

    public function test_the_reserved_key_message_names_the_key(): void
    {
        $this->postJson('/object', ['get_all_records' => 'value1'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.key.0',
                "Key 'get_all_records' is reserved by the API."
            );
    }

    /**
     * Only the exact segment collides — a key that merely starts with it
     * routes to the {key} wildcard like any other and must stay writable.
     */
    public function test_it_accepts_a_key_that_only_resembles_the_reserved_one(): void
    {
        $this->postJson('/object', ['get_all_records_2' => 'value1'])
            ->assertCreated();

        $this->getJson('/object/get_all_records_2')
            ->assertOk()
            ->assertJson(['key' => 'get_all_records_2', 'value' => 'value1']);
    }

    public function test_the_listing_route_still_answers_on_that_path(): void
    {
        $this->postJson('/object', ['mykey' => 'value1']);

        $this->getJson('/object/get_all_records')
            ->assertOk()
            ->assertJson([['key' => 'mykey', 'value' => 'value1']]);
    }

    public function test_it_stores_a_new_version_rather_than_overwriting(): void
    {
        $this->postJson('/object', ['mykey' => 'value1']);
        $this->postJson('/object', ['mykey' => 'value2']);

        $this->assertSame(2, KvEntry::where('key', 'mykey')->count());
    }
}

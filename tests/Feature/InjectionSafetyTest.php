<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use App\ValueObjects\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Hostile-input coverage for every entry point the API exposes:
 * the POST body (key and value), the {key} path segment, the ?timestamp
 * query parameter and the {page} path segment.
 *
 * Two distinct guarantees are asserted throughout:
 *
 *  - A KEY is a constrained identifier. It reaches the URL path, the route
 *    regex and error messages, so anything outside [A-Za-z0-9_.-] must be
 *    refused (422 on write, 404 on read — the route never matches).
 *  - A VALUE is opaque data. It must be accepted no matter what it spells,
 *    stored byte-for-byte and returned unchanged — never parsed, evaluated,
 *    concatenated into SQL or emitted as markup.
 */
class InjectionSafetyTest extends TestCase
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

    /**
     * Asserts a value survives a full write/read round trip untouched.
     */
    private function assertValueRoundTrips(string $key, mixed $value): void
    {
        $write = $this->postJson('/object', [$key => $value]);
        $write->assertCreated();
        $this->assertSame($value, $write->json('value'), 'value changed on write');

        $read = $this->getJson("/object/{$key}");
        $read->assertOk();
        $this->assertSame($value, $read->json('value'), 'value changed on read');
    }

    // ---------------------------------------------------------------
    // SQL injection
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function sqlPayloads(): array
    {
        return [
            'tautology' => ["' OR '1'='1"],
            'comment out' => ["admin'--"],
            'stacked drop' => ["'; DROP TABLE kv_entries; --"],
            'stacked delete' => ["1); DELETE FROM kv_entries WHERE ('1'='1"],
            'union select' => ["' UNION SELECT name, sql FROM sqlite_master --"],
            'quote escape' => ["\\' OR 1=1 --"],
            'double quoted' => ['" OR ""="'],
            'blind boolean' => ["' AND (SELECT COUNT(*) FROM kv_entries) > 0 --"],
            'sqlite pragma' => ['; PRAGMA writable_schema = 1; --'],
            'hex literal' => ['0x27 OR 1=1'],
        ];
    }

    #[DataProvider('sqlPayloads')]
    public function test_sql_payloads_in_a_value_are_stored_as_inert_data(string $payload): void
    {
        $this->assertValueRoundTrips('sqlkey', $payload);

        // The payload landed in the column as text, not as executed SQL.
        $this->assertTrue(Schema::hasTable('kv_entries'));
        $this->assertSame(1, KvEntry::count());
    }

    #[DataProvider('sqlPayloads')]
    public function test_sql_payloads_in_a_key_are_rejected(string $payload): void
    {
        $this->postJson('/object', [$payload => 'value'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);

        $this->assertSame(0, KvEntry::count());
        $this->assertTrue(Schema::hasTable('kv_entries'));
    }

    #[DataProvider('sqlPayloads')]
    public function test_sql_payloads_in_the_timestamp_parameter_are_rejected(string $payload): void
    {
        $this->postJson('/object', ['mykey' => 'value']);

        $this->getJson('/object/mykey?timestamp='.urlencode($payload))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timestamp']);
    }

    #[DataProvider('sqlPayloads')]
    public function test_sql_payloads_in_the_page_segment_do_not_match_the_route(string $payload): void
    {
        // {page} is constrained to \d+, so a payload never reaches the
        // controller at all.
        $this->getJson('/object/get_all_records/'.urlencode($payload))
            ->assertNotFound();
    }

    public function test_a_drop_table_payload_leaves_existing_rows_intact(): void
    {
        $this->postJson('/object', ['keep_me' => 'still here']);

        $this->postJson('/object', ['other' => "'; DROP TABLE kv_entries; --"])
            ->assertCreated();

        $this->assertTrue(Schema::hasTable('kv_entries'));
        $this->getJson('/object/keep_me')
            ->assertOk()
            ->assertJson(['value' => 'still here']);
    }

    public function test_a_stacked_delete_payload_does_not_remove_other_keys(): void
    {
        $this->postJson('/object', ['victim' => 'intact']);
        $this->postJson('/object', ['attacker' => '1); DELETE FROM kv_entries; --']);

        $this->assertSame(2, KvEntry::count());
        $this->getJson('/object/victim')->assertOk()->assertJson(['value' => 'intact']);
    }

    public function test_a_key_lookup_matching_a_sql_wildcard_is_an_exact_match(): void
    {
        // where('key', $key) is an equality comparison, not LIKE — '%' and
        // '_' must not behave as wildcards.
        $this->postJson('/object', ['secret' => 'value']);

        $this->getJson('/object/_______')->assertNotFound();
        $this->getJson('/object/history')->assertNotFound();
    }

    // ---------------------------------------------------------------
    // PHP: code execution, object injection, command injection
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function phpPayloads(): array
    {
        return [
            'open tag' => ['<?php system("whoami"); ?>'],
            'short echo tag' => ['<?= `id` ?>'],
            'serialized object' => ['O:8:"stdClass":1:{s:4:"prop";s:3:"bad";}'],
            'serialized array' => ['a:1:{s:3:"key";s:5:"value";}'],
            'phar path' => ['phar://evil.phar/payload.txt'],
            'data wrapper' => ['data://text/plain;base64,PD9waHAgcGhwaW5mbygpOw=='],
            'file wrapper' => ['php://filter/convert.base64-encode/resource=.env'],
            'template braces' => ['{{ config("app.key") }}'],
            'blade raw' => ['{!! system("id") !!}'],
            'dollar brace' => ['${@print(md5(1))}'],
        ];
    }

    #[DataProvider('phpPayloads')]
    public function test_php_payloads_in_a_value_are_never_evaluated(string $payload): void
    {
        $this->assertValueRoundTrips('phpkey', $payload);

        // The value came back as the literal string, not as the result of
        // evaluating, unserialising or resolving a stream wrapper.
        $returned = $this->getJson('/object/phpkey')->json('value');
        $this->assertIsString($returned);
        $this->assertSame($payload, $returned);
    }

    public function test_a_serialized_payload_is_not_turned_into_an_object(): void
    {
        $payload = 'O:8:"stdClass":1:{s:4:"prop";s:3:"bad";}';
        $this->postJson('/object', ['ser' => $payload]);

        // Read straight from the model to prove the storage layer, not just
        // the JSON response, keeps it as a scalar string.
        $entry = KvEntry::where('key', 'ser')->first();
        $this->assertIsString($entry->value);
        $this->assertSame($payload, $entry->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shellPayloads(): array
    {
        return [
            'semicolon' => ['; rm -rf /tmp/x'],
            'pipe' => ['| cat /etc/passwd'],
            'ampersand' => ['&& whoami'],
            'backticks' => ['`whoami`'],
            'substitution' => ['$(whoami)'],
            'newline' => ["value\nwhoami"],
            'windows chain' => ['& dir C:\\'],
        ];
    }

    #[DataProvider('shellPayloads')]
    public function test_shell_metacharacters_in_a_value_are_stored_verbatim(string $payload): void
    {
        $this->assertValueRoundTrips('shellkey', $payload);
    }

    #[DataProvider('shellPayloads')]
    public function test_shell_metacharacters_in_a_key_are_rejected(string $payload): void
    {
        $this->postJson('/object', [$payload => 'value'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    // ---------------------------------------------------------------
    // Path traversal / local file inclusion
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function traversalKeys(): array
    {
        return [
            'unix relative' => ['../../etc/passwd'],
            'windows relative' => ['..\\..\\windows\\win.ini'],
            'absolute unix' => ['/etc/passwd'],
            'absolute windows' => ['C:\\windows\\win.ini'],
            'dot segment' => ['./.env'],
            'env file' => ['../.env'],
        ];
    }

    #[DataProvider('traversalKeys')]
    public function test_traversal_keys_are_rejected_on_write(string $key): void
    {
        $this->postJson('/object', [$key => 'value'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function encodedTraversalPaths(): array
    {
        return [
            'encoded slash' => ['..%2f..%2fetc%2fpasswd'],
            'double encoded' => ['..%252f..%252fetc%252fpasswd'],
            'encoded backslash' => ['..%5c..%5cwindows%5cwin.ini'],
            'null byte' => ['mykey%00.txt'],
            'encoded null' => ['%00'],
            'encoded dot' => ['%2e%2e%2f%2e%2e%2fetc%2fpasswd'],
            'unicode overlong' => ['%c0%ae%c0%ae%2fetc%2fpasswd'],
        ];
    }

    #[DataProvider('encodedTraversalPaths')]
    public function test_encoded_traversal_paths_never_reach_the_controller(string $path): void
    {
        // '%' is outside the key charset, so the route regex rejects the
        // encoded form before any decoding could reconstruct a traversal.
        // Malformed encodings (overlong UTF-8) are refused a step earlier
        // still, as a 400 — either way the request is dead before routing.
        foreach ([$this->getJson('/object/'.$path), $this->deleteJson('/object/'.$path)] as $response) {
            $this->assertContains(
                $response->status(),
                [400, 404],
                "path {$path} produced an unexpected status"
            );
        }

        $this->assertSame(0, KvEntry::count());
    }

    public function test_a_null_byte_in_a_key_is_rejected_on_write(): void
    {
        $this->postRaw('{"my\u0000key": "value"}')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    // ---------------------------------------------------------------
    // HTML / JavaScript
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function htmlPayloads(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'svg onload' => ['<svg/onload=alert(1)>'],
            'closing tag breakout' => ['</pre><script>alert(1)</script><pre>'],
            'javascript uri' => ['javascript:alert(document.cookie)'],
            'event handler' => ['" onmouseover="alert(1)'],
            'style expression' => ['<style>@import"//evil"</style>'],
            'iframe' => ['<iframe src="//evil"></iframe>'],
            'entity encoded' => ['&lt;script&gt;alert(1)&lt;/script&gt;'],
        ];
    }

    #[DataProvider('htmlPayloads')]
    public function test_html_payloads_in_a_value_round_trip_unchanged(string $payload): void
    {
        $this->assertValueRoundTrips('htmlkey', $payload);
    }

    #[DataProvider('htmlPayloads')]
    public function test_html_payloads_in_a_key_are_rejected(string $payload): void
    {
        $this->postJson('/object', [$payload => 'value'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_markup_in_a_value_is_escaped_in_the_raw_response_body(): void
    {
        $this->postJson('/object', ['htmlkey' => '<script>alert(1)</script>']);

        $body = $this->getJson('/object/htmlkey')->getContent();

        // SecurityHeaders re-encodes JSON with JSON_HEX_TAG and friends, so a
        // raw '<' never appears in the body. Laravel's default encoding does
        // NOT do this — without that middleware the payload is echoed literally.
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringNotContainsString('<', $body);
        $this->assertStringContainsString('\u003C', $body);
    }

    #[DataProvider('htmlPayloads')]
    public function test_no_stored_payload_can_emit_raw_markup_on_any_endpoint(string $payload): void
    {
        $this->postJson('/object', ['htmlkey' => $payload]);

        foreach ([
            '/object/htmlkey',
            '/object/htmlkey/history',
            '/object/get_all_records',
        ] as $uri) {
            $body = $this->getJson($uri)->getContent();

            $this->assertStringNotContainsString('<', $body, $uri);
            $this->assertStringNotContainsString('>', $body, $uri);
            $this->assertStringNotContainsString('&', $body, $uri);
        }
    }

    public function test_responses_are_served_as_json_and_may_not_be_sniffed(): void
    {
        $this->postJson('/object', ['htmlkey' => '<script>alert(1)</script>']);

        foreach ([
            '/object/htmlkey',
            '/object/htmlkey/history',
            '/object/get_all_records',
        ] as $uri) {
            $this->getJson($uri)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/json')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }
    }

    public function test_security_headers_are_present_on_error_responses_too(): void
    {
        $this->getJson('/object/never_written')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->postJson('/object', ['bad key!' => 'value'])
            ->assertStatus(422)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_delete_confirmation_cannot_carry_markup_from_the_key(): void
    {
        // The success body names the key, so it needs the guarantee the 404
        // body already has: the route charset is what keeps markup out, and
        // the response is still marked unsniffable.
        $this->postJson('/object', ['mykey' => 'value']);

        $body = $this->deleteJson('/object/mykey')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->getContent();

        $this->assertStringNotContainsString('<', $body);
    }

    public function test_a_404_message_cannot_carry_markup_from_the_key(): void
    {
        // The 404 body interpolates the key; the route charset is what keeps
        // that safe, so pin the behaviour.
        $this->getJson('/object/<script>alert(1)</script>')->assertNotFound();

        $body = $this->getJson('/object/plain_key')->getContent();
        $this->assertStringNotContainsString('<', $body);
    }

    // ---------------------------------------------------------------
    // Header / log injection
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function crlfKeys(): array
    {
        return [
            'crlf header' => ["mykey\r\nX-Injected: 1"],
            'lf only' => ["mykey\nX-Injected: 1"],
            'cr only' => ["mykey\rX-Injected: 1"],
            'log forge' => ["mykey\n[critical] forged log line"],
            'tab' => ["my\tkey"],
        ];
    }

    #[DataProvider('crlfKeys')]
    public function test_crlf_in_a_key_is_rejected(string $key): void
    {
        $this->postJson('/object', [$key => 'value'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_crlf_in_a_value_does_not_reach_the_response_headers(): void
    {
        $payload = "line1\r\nX-Injected: yes";
        $this->assertValueRoundTrips('crlfvalue', $payload);

        $response = $this->getJson('/object/crlfvalue');
        $this->assertNull($response->headers->get('X-Injected'));
    }

    // ---------------------------------------------------------------
    // JSON structure abuse
    // ---------------------------------------------------------------

    public function test_a_prototype_polluting_key_is_stored_as_an_ordinary_key(): void
    {
        // __proto__ matches the key charset, so it is accepted. It is inert
        // server-side; this test pins that it behaves like any other key and
        // does not corrupt the get_all_records payload.
        $this->postJson('/object', ['__proto__' => ['polluted' => true]])
            ->assertCreated()
            ->assertJson(['key' => '__proto__']);

        $all = $this->getJson('/object/get_all_records');
        $all->assertOk();
        $this->assertIsArray($all->json());
        $this->assertSame('__proto__', $all->json('0.key'));
    }

    public function test_a_constructor_key_is_stored_as_an_ordinary_key(): void
    {
        $this->postJson('/object', ['constructor' => 'value'])
            ->assertCreated()
            ->assertJson(['key' => 'constructor', 'value' => 'value']);
    }

    public function test_duplicate_json_properties_do_not_bypass_the_single_pair_rule(): void
    {
        // json_decode keeps the last occurrence, so this is one pair, not two.
        $this->postRaw('{"dupe": "first", "dupe": "second"}')
            ->assertCreated()
            ->assertJson(['key' => 'dupe', 'value' => 'second']);

        $this->assertSame(1, KvEntry::count());
    }

    public function test_a_nested_object_cannot_smuggle_a_second_key(): void
    {
        $this->postJson('/object', ['outer' => ['inner' => 'value']])
            ->assertCreated()
            ->assertJson(['key' => 'outer']);

        $this->assertNull(KvEntry::where('key', 'inner')->first());
    }

    public function test_a_method_override_field_is_treated_as_an_ordinary_key(): void
    {
        $this->postJson('/object', ['mykey' => 'value']);

        // Laravel copies the decoded JSON body into the request bag Symfony
        // reads _method from, so this property used to rewrite the verb.
        $this->postJson('/object', ['_method' => 'DELETE'])
            ->assertCreated()
            ->assertJson(['key' => '_method', 'value' => 'DELETE']);

        // The existing key was not deleted by a smuggled method override.
        $this->getJson('/object/mykey')->assertOk();
        $this->assertSame(2, KvEntry::count());
    }

    public function test_a_method_override_query_parameter_cannot_rewrite_the_verb(): void
    {
        // Symfony also consults the query string for _method.
        $this->postJson('/object?_method=DELETE', ['mykey' => 'value'])
            ->assertCreated();

        $this->getJson('/object/mykey')->assertOk();
    }

    public function test_a_method_override_header_cannot_turn_a_read_into_a_delete(): void
    {
        $this->postJson('/object', ['mykey' => 'value']);

        $this->call('GET', '/object/mykey', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE',
        ]);

        $this->getJson('/object/mykey')->assertOk();
        $this->assertSame(1, KvEntry::count());
    }

    public function test_model_columns_cannot_be_mass_assigned_through_the_body(): void
    {
        // 'id', 'created_at' and friends are just key names here — they must
        // not bind to model attributes.
        foreach (['id', 'created_at', 'recorded_at'] as $name) {
            $this->postJson('/object', [$name => 'injected'])
                ->assertCreated()
                ->assertJson(['key' => $name, 'value' => 'injected']);
        }

        $this->assertSame(3, KvEntry::count());
        $this->assertNotSame('injected', KvEntry::first()->id);
    }

    public function test_numeric_looking_keys_stay_distinct(): void
    {
        // Raw bodies on purpose: PHP casts the array key '0' to an integer, so
        // json_encode(['0' => 'zero']) would emit the array ["zero"] and the
        // case under test would never leave the client.
        //
        // Server-side, json_decode(..., true) turns {"0":"a"} into [0 => 'a'],
        // which is indistinguishable from ["a"] — the key "0" used to be
        // rejected as an array body because of that collision.
        $this->postRaw('{"0": "zero"}')->assertCreated();
        $this->postRaw('{"00": "double zero"}')->assertCreated();
        $this->postRaw('{"0e123": "exponent"}')->assertCreated();

        $this->assertSame(3, KvEntry::count());
        $this->getJson('/object/0')->assertOk()->assertJson(['value' => 'zero']);
        $this->getJson('/object/00')->assertOk()->assertJson(['value' => 'double zero']);
        $this->getJson('/object/0e123')->assertOk()->assertJson(['value' => 'exponent']);
    }

    public function test_a_json_object_with_list_like_properties_stays_an_object(): void
    {
        // An associative decode would collapse {"0":"a","1":"b"} into a PHP
        // list and re-encode it as ["a","b"], silently changing the value's
        // JSON type on both the write and the read path.
        $this->postRaw('{"shapekey": {"0":"a","1":"b"}}')->assertCreated();

        $body = $this->getJson('/object/shapekey')->getContent();

        $this->assertStringContainsString('"value":{"0":"a","1":"b"}', $body);
        $this->assertStringNotContainsString('["a","b"]', $body);
    }

    public function test_a_genuine_json_array_value_stays_an_array(): void
    {
        $this->postRaw('{"arraykey": ["a","b"]}')->assertCreated();

        $body = $this->getJson('/object/arraykey')->getContent();

        $this->assertStringContainsString('"value":["a","b"]', $body);
    }

    public function test_an_empty_object_value_is_not_confused_with_an_empty_array(): void
    {
        $this->postRaw('{"objkey": {}}')->assertCreated();
        $this->postRaw('{"arrkey": []}')->assertCreated();

        $this->assertStringContainsString('"value":{}', $this->getJson('/object/objkey')->getContent());
        $this->assertStringContainsString('"value":[]', $this->getJson('/object/arrkey')->getContent());
    }

    // ---------------------------------------------------------------
    // Encoding and parser limits
    // ---------------------------------------------------------------

    public function test_malformed_json_is_rejected(): void
    {
        $this->postRaw('{"mykey": ')->assertStatus(422);
        $this->postRaw('not json at all')->assertStatus(422);
        $this->postRaw('')->assertStatus(422);
        $this->postRaw('null')->assertStatus(422);
        $this->postRaw('"just a string"')->assertStatus(422);
        $this->postRaw('[{"mykey": "value"}]')->assertStatus(422);
    }

    public function test_a_lone_surrogate_is_rejected(): void
    {
        $this->postRaw('{"mykey": "\ud800"}')->assertStatus(422);
    }

    public function test_json_nested_beyond_the_parser_depth_limit_is_rejected(): void
    {
        // json_decode's default depth is 512; a body past that fails to parse
        // and is refused rather than crashing the request.
        $deep = str_repeat('[', 600).'1'.str_repeat(']', 600);

        $this->postRaw('{"deepkey": '.$deep.'}')->assertStatus(422);
    }

    public function test_a_content_type_mismatch_does_not_bypass_validation(): void
    {
        // The request is parsed from the raw body, so a lying Content-Type
        // must not change the outcome.
        $response = $this->call('POST', '/object', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"bad key!": "value"}');

        $response->assertStatus(422);
    }

    public function test_form_encoded_bodies_are_rejected(): void
    {
        $this->call('POST', '/object', ['mykey' => 'value'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(422)->assertJsonValidationErrors(['body']);

        $this->assertSame(0, KvEntry::count());
    }

    // ---------------------------------------------------------------
    // Boundaries
    // ---------------------------------------------------------------

    public function test_key_length_boundary(): void
    {
        $this->postJson('/object', [str_repeat('a', 255) => 'value'])->assertCreated();

        $this->postJson('/object', [str_repeat('a', 256) => 'value'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTimestamps(): array
    {
        return [
            'negative' => ['-1'],
            'float' => ['1.5'],
            'exponent' => ['1e10'],
            'hex' => ['0x10'],
            'word' => ['abc'],
            'past int max' => ['9223372036854775808'],
            'array' => ['[]=1'],
        ];
    }

    #[DataProvider('invalidTimestamps')]
    public function test_invalid_timestamps_are_rejected(string $timestamp): void
    {
        $this->postJson('/object', ['mykey' => 'value']);

        $query = $timestamp === '[]=1' ? 'timestamp[]=1' : 'timestamp='.urlencode($timestamp);

        $this->getJson('/object/mykey?'.$query)->assertStatus(422);
    }

    public function test_timestamp_zero_is_accepted(): void
    {
        $this->postJson('/object', ['mykey' => 'value']);

        $this->getJson('/object/mykey?timestamp=0')->assertNotFound();
    }

    public function test_non_numeric_page_segments_do_not_match_the_route(): void
    {
        foreach (['-1', '1.5', 'abc', '1%20OR%201'] as $page) {
            $this->getJson('/object/get_all_records/'.$page)->assertNotFound();
        }
    }

    // ---------------------------------------------------------------
    // Front-end contract
    // ---------------------------------------------------------------

    public function test_the_frontend_key_pattern_matches_the_backend_rule(): void
    {
        // The page validates keys client-side before calling the API. If the
        // two charsets drift apart, the UI starts producing requests the API
        // rejects (or worse, stops mirroring what the API accepts).
        //
        // The script is a static file under public/, which the test kernel
        // does not serve, so it is read from disk.
        // Asserted against the Key value object rather than against a literal:
        // pinning app.js to a hard-coded regex here would let the backend rule
        // change while this test kept happily checking the old one.
        $script = file_get_contents(public_path('js/app.js'));

        $this->assertStringContainsString(Key::REGEX, $script);
        $this->assertStringContainsString('key.length <= '.Key::MAX_LENGTH, $script);
        $this->assertStringContainsString(
            'maxlength="'.Key::MAX_LENGTH.'"',
            $this->get('/')->assertOk()->getContent()
        );
    }
}

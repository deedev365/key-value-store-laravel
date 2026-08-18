<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GET /object/get_all_records/keys — the key names the page's selector offers.
 */
class GetAllKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_every_key_alphabetically(): void
    {
        foreach (['charlie', 'alpha', 'bravo'] as $key) {
            KvEntry::create(['key' => $key, 'value' => 'v', 'recorded_at' => 1000]);
        }

        $this->getJson('/object/get_all_records/keys')
            ->assertOk()
            ->assertExactJson(['alpha', 'bravo', 'charlie']);
    }

    public function test_a_key_with_several_versions_is_listed_once(): void
    {
        // The selector offers keys; the versions of one are a second question,
        // answered by its history.
        KvEntry::create(['key' => 'mykey', 'value' => 'v1', 'recorded_at' => 1000]);
        KvEntry::create(['key' => 'mykey', 'value' => 'v2', 'recorded_at' => 2000]);

        $this->getJson('/object/get_all_records/keys')
            ->assertOk()
            ->assertExactJson(['mykey']);
    }

    public function test_an_empty_store_answers_an_empty_array(): void
    {
        $this->getJson('/object/get_all_records/keys')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_a_key_with_nothing_published_yet_is_not_offered(): void
    {
        // Same rule every other read follows: choosing a key from the selector
        // must never be a way to reach a campaign before it goes live.
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));
        KvEntry::create(['key' => 'live', 'value' => 'v', 'recorded_at' => 900]);
        KvEntry::create([
            'key' => 'queued',
            'value' => 'v',
            'recorded_at' => 900,
            'publish_time' => 5000,
        ]);

        $this->getJson('/object/get_all_records/keys')
            ->assertOk()
            ->assertExactJson(['live']);
    }

    public function test_a_key_whose_older_version_is_published_is_still_offered(): void
    {
        // One scheduled version must not hide the key that is live behind it.
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));
        KvEntry::create(['key' => 'mykey', 'value' => 'live', 'recorded_at' => 900]);
        KvEntry::create([
            'key' => 'mykey',
            'value' => 'queued',
            'recorded_at' => 950,
            'publish_time' => 5000,
        ]);

        $this->getJson('/object/get_all_records/keys')
            ->assertOk()
            ->assertExactJson(['mykey']);
    }

    public function test_the_listing_is_capped(): void
    {
        // Not an unbounded read: the query carries a LIMIT like every other
        // listing here.
        config(['kvstore.max_keys_listed' => 2]);

        foreach (['a', 'b', 'c'] as $key) {
            KvEntry::create(['key' => $key, 'value' => 'v', 'recorded_at' => 1000]);
        }

        $this->getJson('/object/get_all_records/keys')
            ->assertOk()
            ->assertExactJson(['a', 'b']);
    }

    public function test_the_paged_listing_still_answers_on_its_own_path(): void
    {
        // 'keys' is a sub-path of the listing, so neither route may swallow the
        // other — {page} is digits-only, which is what keeps them apart.
        KvEntry::create(['key' => 'mykey', 'value' => 'v', 'recorded_at' => 1000]);

        $this->getJson('/object/get_all_records')->assertOk()->assertJsonCount(1);
        $this->getJson('/object/get_all_records/1')->assertOk()->assertJsonCount(1);
    }

    public function test_a_key_literally_named_keys_is_unreachable_through_this_path(): void
    {
        // It can be stored and read at /object/keys; the reserved segment in
        // front is what keeps this route free.
        $this->postJson('/object', ['keys' => 'v'])->assertCreated();

        $this->getJson('/object/keys')->assertOk()->assertJson(['value' => 'v']);
        $this->getJson('/object/get_all_records/keys')->assertOk()->assertExactJson(['keys']);
    }
}

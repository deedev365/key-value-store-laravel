<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /object/get_all_records/{page?}
 *
 * The page size lives in config and is consumed in two places — the controller
 * and the front end's "Next" button. They drifted apart once already, which is
 * what the last test here exists to prevent.
 */
class RecordsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function pageSize(): int
    {
        return (int) config('kvstore.records_per_page');
    }

    /**
     * Creates $count keys named key_001, key_002, ... so that alphabetical
     * order (which is what the repository pages by) matches creation order.
     */
    private function seedKeys(int $count): void
    {
        foreach (range(1, $count) as $i) {
            KvEntry::create([
                'key' => sprintf('key_%03d', $i),
                'value' => $i,
                'recorded_at' => 1000 + $i,
            ]);
        }
    }

    private function keysOnPage(?int $page = null): array
    {
        $uri = $page === null
            ? '/object/get_all_records'
            : "/object/get_all_records/{$page}";

        return array_column($this->getJson($uri)->assertOk()->json(), 'key');
    }

    public function test_the_default_page_size_is_ten(): void
    {
        $this->assertSame(10, $this->pageSize());
    }

    public function test_a_full_page_returns_exactly_ten_records(): void
    {
        $this->seedKeys(25);

        $this->getJson('/object/get_all_records')
            ->assertOk()
            ->assertJsonCount(10);
    }

    public function test_fewer_records_than_a_page_returns_them_all(): void
    {
        $this->seedKeys(4);

        $this->getJson('/object/get_all_records')
            ->assertOk()
            ->assertJsonCount(4);
    }

    public function test_exactly_one_page_of_records_does_not_spill(): void
    {
        $this->seedKeys(10);

        $this->assertCount(10, $this->keysOnPage(1));
        $this->assertSame([], $this->keysOnPage(2));
    }

    public function test_the_second_page_continues_where_the_first_stopped(): void
    {
        $this->seedKeys(25);

        $this->assertSame('key_001', $this->keysOnPage(1)[0]);
        $this->assertSame('key_010', $this->keysOnPage(1)[9]);
        $this->assertSame('key_011', $this->keysOnPage(2)[0]);
        $this->assertSame('key_020', $this->keysOnPage(2)[9]);
    }

    public function test_the_last_page_holds_the_remainder(): void
    {
        $this->seedKeys(25);

        $this->getJson('/object/get_all_records/3')
            ->assertOk()
            ->assertJsonCount(5);
    }

    public function test_paging_covers_every_key_exactly_once(): void
    {
        $this->seedKeys(25);

        $seen = array_merge($this->keysOnPage(1), $this->keysOnPage(2), $this->keysOnPage(3));

        $this->assertCount(25, $seen);
        $this->assertSame($seen, array_unique($seen), 'a key appeared on two pages');
        $this->assertSame('key_001', $seen[0]);
        $this->assertSame('key_025', $seen[24]);
    }

    public function test_a_page_past_the_end_is_an_empty_array(): void
    {
        $this->seedKeys(25);

        $this->getJson('/object/get_all_records/4')->assertOk()->assertExactJson([]);
        $this->getJson('/object/get_all_records/9999')->assertOk()->assertExactJson([]);
    }

    public function test_page_zero_and_no_page_both_mean_the_first_page(): void
    {
        $this->seedKeys(25);

        $this->assertSame($this->keysOnPage(1), $this->keysOnPage());
        $this->assertSame($this->keysOnPage(1), $this->keysOnPage(0));
    }

    public function test_an_empty_store_returns_an_empty_array_on_any_page(): void
    {
        $this->getJson('/object/get_all_records')->assertOk()->assertExactJson([]);
        $this->getJson('/object/get_all_records/3')->assertOk()->assertExactJson([]);
    }

    public function test_pages_hold_keys_not_versions(): void
    {
        // 30 writes across 12 keys is 12 records, so page 2 has 2 of them —
        // paging must count keys, not rows.
        foreach (range(1, 12) as $i) {
            foreach (range(1, 3) as $version) {
                KvEntry::create([
                    'key' => sprintf('key_%03d', $i),
                    'value' => "v{$version}",
                    'recorded_at' => 1000 + $version,
                ]);
            }
        }

        $this->assertCount(10, $this->keysOnPage(1));
        $this->assertCount(2, $this->keysOnPage(2));

        $this->getJson('/object/get_all_records/2')
            ->assertOk()
            ->assertJsonFragment(['key' => 'key_012', 'value' => 'v3'])
            ->assertJsonMissing(['value' => 'v1']);
    }

    public function test_the_page_size_is_configurable(): void
    {
        config(['kvstore.records_per_page' => 3]);
        $this->seedKeys(7);

        $this->assertCount(3, $this->keysOnPage(1));
        $this->assertSame('key_004', $this->keysOnPage(2)[0]);
        $this->assertCount(1, $this->keysOnPage(3));
    }

    public function test_the_whole_table_is_not_loaded_to_serve_one_page(): void
    {
        // The page must be cut in SQL. If it were sliced in PHP, the query
        // would come back with every row in the table.
        $this->seedKeys(25);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/object/get_all_records')->assertOk();
        $log = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $select = collect($log)->firstWhere(fn ($q) => str_contains($q['query'], 'kv_entries'));

        $this->assertNotNull($select, 'no query against kv_entries was recorded');
        $this->assertStringContainsString('limit', strtolower($select['query']));
    }

    public function test_the_frontend_page_size_matches_the_server(): void
    {
        // These drifted once: the server was cut to 5 while the page still
        // expected 10, which left "Next" permanently disabled because the
        // script only enables it when a full page comes back.
        $script = file_get_contents(public_path('js/app.js'));

        preg_match('/const PAGE_SIZE = (\d+);/', $script, $m);

        $this->assertNotEmpty($m, 'PAGE_SIZE was not found in public/js/app.js');
        $this->assertSame(
            $this->pageSize(),
            (int) $m[1],
            "the front end pages by {$m[1]} but the API returns {$this->pageSize()} per page"
        );
    }
}

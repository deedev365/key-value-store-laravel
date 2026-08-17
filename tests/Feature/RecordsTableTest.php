<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "All records" table. The rendering happens in the browser, but the
 * markup and the script that fills it are separate files, so the contract
 * between them is pinned here.
 */
class RecordsTableTest extends TestCase
{
    use RefreshDatabase;

    private function script(): string
    {
        return file_get_contents(public_path('js/app.js'));
    }

    private function headerRow(): string
    {
        preg_match('/<table id="records-table">.*?<\/thead>/s', $this->get('/')->assertOk()->getContent(), $m);

        return $m[0] ?? '';
    }

    public function test_the_table_has_a_readable_time_column(): void
    {
        $this->assertStringContainsString('<th>Time (UTC)</th>', $this->headerRow());
    }

    public function test_the_time_column_sits_next_to_the_raw_timestamp(): void
    {
        // The raw value stays: the readable form is an addition, not a
        // replacement, so the exact stored number is still on screen.
        $header = $this->headerRow();

        $this->assertStringContainsString('<th>Timestamp</th><th>Time (UTC)</th>', $header);
    }

    public function test_the_header_count_matches_the_cells_the_script_builds(): void
    {
        preg_match_all('/<th>/', $this->headerRow(), $headers);

        preg_match('/const cells = \[(.*?)\];/s', $this->script(), $cells);
        $this->assertNotEmpty($cells, 'the row builder was not found');

        // + 1 for the actions column, which is appended separately.
        $this->assertSame(
            count($headers[0]),
            substr_count($cells[1], '{ text:') + 1,
            'the table head and the row builder disagree on the column count'
        );
    }

    public function test_the_listing_retries_itself_on_the_configured_interval(): void
    {
        // A rate-limited table would otherwise sit empty until someone pressed
        // Refresh, which reads as "the store is broken" rather than "wait".
        $script = $this->script();

        preg_match('/const RECORDS_RETRY_SECONDS = (\d+);/', $script, $m);

        $this->assertNotEmpty($m, 'RECORDS_RETRY_SECONDS was not found in public/js/app.js');
        $this->assertSame(
            (int) config('kvstore.records_retry_seconds'),
            (int) $m[1],
            'the script and kvstore.records_retry_seconds disagree on the retry interval'
        );

        $this->assertStringContainsString('recordsRetryTimer = setTimeout(', $script);
    }

    public function test_only_a_429_schedules_a_retry(): void
    {
        // Any other failure is not going to fix itself, so retrying it would
        // just repeat a broken request forever.
        $this->assertStringContainsString('if (res.status === 429) {', $this->script());
    }

    public function test_a_refused_write_is_never_retried_automatically(): void
    {
        // Writes append, so a retried POST would store a second version of the
        // same value. Only the listing — a read — may ask again by itself.
        $this->assertSame(
            1,
            preg_match_all('/setTimeout\(/', $this->script()),
            'something other than the listing retry schedules a timer'
        );
    }

    public function test_the_time_is_formatted_in_utc(): void
    {
        // Stored timestamps are UNIX seconds in UTC. Reading them with the
        // local getters would show a different hour than the number in the
        // column beside it, and a different one per viewer.
        $script = $this->script();

        $this->assertStringContainsString('getUTCHours()', $script);
        $this->assertStringContainsString('getUTCMinutes()', $script);

        $this->assertSame(
            0,
            preg_match('/\.get(Hours|Minutes|Date|Month|FullYear)\(\)/', $script),
            'the script reads a date in the local timezone'
        );
    }

    public function test_midnight_and_noon_are_not_rendered_as_zero(): void
    {
        // The 12-hour clock's awkward case: 0 and 12 both display as 12.
        $this->assertStringContainsString('hours % 12 === 0 ? 12 : hours % 12', $this->script());
    }

    public function test_the_full_instant_is_kept_as_a_tooltip(): void
    {
        // A bare clock time cannot tell yesterday's 6pm from today's.
        $script = $this->script();

        $this->assertStringContainsString('function formatFullUtc(', $script);
        $this->assertStringContainsString('title: formatFullUtc(rec.timestamp)', $script);
    }

    public function test_the_api_still_returns_the_raw_timestamp(): void
    {
        // The column is presentation only — the API contract is unchanged.
        $write = $this->postJson('/object', ['mykey' => 'value1'])->assertCreated();

        $this->assertIsInt($write->json('timestamp'));

        $this->getJson('/object/get_all_records')
            ->assertOk()
            ->assertJsonStructure([['key', 'value', 'timestamp']]);
    }
}

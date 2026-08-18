<?php

namespace Tests\Feature;

use App\Models\KvEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Save changes" affordance in the "Look up by key" block, which corrects
 * the version a lookup just resolved through PUT /object/{key}.
 *
 * Two contracts, pinned the way the rest of the page is: the markup/script pair
 * that decides *which* version an edit lands on — the part a well-meaning
 * refactor can silently break — and the endpoint's willingness to take what the
 * box sends back.
 */
class EditValueFormTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    private function script(): string
    {
        return file_get_contents(public_path('js/app.js'));
    }

    // ---------------------------------------------------------------
    // The markup
    // ---------------------------------------------------------------

    public function test_the_lookup_block_has_an_editable_value_and_a_save_button(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('id="lookup-value"', $page);
        $this->assertMatchesRegularExpression('/<button[^>]*id="save-btn"[^>]*>Save changes<\/button>/i', $page);
    }

    public function test_the_edit_box_and_the_save_button_start_disabled(): void
    {
        // Nothing has said which version an edit would replace yet, so neither
        // may be used: a Save with no resolved target must not be clickable.
        $page = $this->page();

        $this->assertMatchesRegularExpression('/<input[^>]*id="lookup-value"[^>]*\bdisabled\b/i', $page);
        $this->assertMatchesRegularExpression('/<button[^>]*id="save-btn"[^>]*\bdisabled\b/i', $page);
    }

    public function test_the_edit_box_stays_optional(): void
    {
        // "Get value", "Full history" and "Delete key" never read it, so marking
        // it required would block three buttons for the sake of a fourth.
        $this->assertSame(
            0,
            preg_match('/<input[^>]*id="lookup-value"[^>]*\brequired\b/i', $this->page()),
            'the edit box was marked required'
        );
    }

    // ---------------------------------------------------------------
    // Which version an edit lands on
    // ---------------------------------------------------------------

    public function test_the_save_button_replaces_the_version_the_lookup_resolved(): void
    {
        // The timestamp box is read when the lookup happens and remembered, not
        // read again on click: typing a different timestamp after looking up
        // would otherwise send the edit to a version other than the one shown.
        $script = $this->script();

        $this->assertStringContainsString('let editTarget = null;', $script);
        $this->assertStringContainsString('editTarget = { key, timestamp, schedule };', $script);
        $this->assertStringContainsString('const { key, timestamp, schedule } = editTarget;', $script);

        $this->assertSame(
            1,
            preg_match_all('/lookupTimestampEl\.value/', $script),
            'the timestamp box is read somewhere other than the lookup handler'
        );
    }

    public function test_only_a_successful_lookup_arms_the_save_button(): void
    {
        // A 404 or a rate-limited lookup leaves nothing to correct.
        $script = $this->script();

        $this->assertStringContainsString("if (res.ok && data && typeof data === 'object') {", $script);
        $this->assertStringContainsString('armEditTarget(key, timestamp, data);', $script);
    }

    public function test_the_target_is_dropped_whenever_it_stops_being_what_is_on_screen(): void
    {
        // Editing either lookup box, listing the history, deleting the key and
        // finishing a save all invalidate it.
        $this->assertGreaterThanOrEqual(
            5,
            preg_match_all('/clearEditTarget\(\)/', $this->script()),
            'the edit target outlives the lookup that produced it'
        );
    }

    public function test_a_replacement_is_confirmed_first(): void
    {
        // The old version is removed, so this is as irreversible as a delete and
        // asks the same way.
        $script = $this->script();

        $this->assertSame(2, preg_match_all('/confirm\(/', $script));
        $this->assertStringContainsString('The old version is removed and cannot be recovered.', $script);
    }

    public function test_the_edit_is_sent_as_a_real_put(): void
    {
        // Method override is disabled server-side, so a spoofed verb would be
        // read as the POST it arrived as — an append instead of a correction.
        $script = $this->script();

        $this->assertStringContainsString("method: 'PUT'", $script);
        $this->assertStringNotContainsString('_method', $script);
    }

    public function test_the_edited_value_round_trips_as_json(): void
    {
        // The box holds JSON, so an object survives being edited. Printing the
        // value raw instead would turn {"message":"hi"} into [object Object].
        $script = $this->script();

        $this->assertStringContainsString('JSON.stringify(record.value)', $script);
        $this->assertStringContainsString('parseValue(lookupValueEl.value)', $script);
    }

    // ---------------------------------------------------------------
    // Choosing the key and the version
    // ---------------------------------------------------------------

    public function test_the_key_and_the_version_are_chosen_not_typed(): void
    {
        // Both are exact identifiers that have to match something stored, so a
        // free-text box could only ever produce a 404 or a silent miss.
        $page = $this->page();

        $this->assertMatchesRegularExpression('/<select[^>]*id="lookup-key"/i', $page);
        $this->assertMatchesRegularExpression('/<select[^>]*id="lookup-timestamp"/i', $page);
    }

    public function test_the_empty_option_of_each_selector_says_what_it_means(): void
    {
        // Nothing chosen is a state with a meaning in both boxes, so it is
        // spelled out rather than left blank.
        $page = $this->page();

        $this->assertMatchesRegularExpression('/<option value="">Choose a key/i', $page);
        $this->assertMatchesRegularExpression('/<option value="">Current value<\/option>/i', $page);
    }

    public function test_the_version_selector_starts_hidden(): void
    {
        // It has nothing to offer until a key with several versions is chosen.
        $this->assertMatchesRegularExpression(
            '/<div[^>]*id="lookup-timestamp-field"[^>]*\bhidden\b/i',
            $this->page()
        );
    }

    public function test_the_keys_come_from_the_store(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("fetch(api('/get_all_records/keys'))", $script);
        $this->assertStringContainsString('function loadKeyOptions(', $script);
    }

    public function test_a_refused_key_listing_leaves_the_options_alone(): void
    {
        // Emptying the selector on a 429 would read as "the store has no keys"
        // and take the current choice away with it.
        $this->assertStringContainsString('if (!res.ok || !Array.isArray(data)) {', $this->script());
    }

    public function test_the_versions_come_from_the_keys_own_history(): void
    {
        // The history endpoint already answers "every published version of this
        // key", which is exactly the list to choose from — no second listing.
        $script = $this->script();

        $this->assertStringContainsString('function loadVersionOptions(', $script);
        $this->assertStringContainsString('fetch(api(`/${key}/history`))', $script);
    }

    public function test_the_version_selector_appears_only_for_several_versions(): void
    {
        // One version is not a choice, and "current value" already means it.
        $this->assertStringContainsString('versions.length < 2', $this->script());
    }

    public function test_the_versions_are_offered_newest_first(): void
    {
        // History is oldest-first; the recent versions are the ones an editor
        // is looking for, and they sit next to "current value".
        $this->assertStringContainsString('[...versions].reverse()', $this->script());
    }

    public function test_a_version_is_labelled_by_its_instant_as_well_as_its_number(): void
    {
        // The timestamp is what the API is asked for, but a bare unix number
        // cannot be told from another by eye.
        $this->assertStringContainsString(
            '`${formatFullUtc(version.timestamp)} — ${version.timestamp}`',
            $this->script()
        );
    }

    public function test_choosing_another_key_reloads_the_versions(): void
    {
        // A version list belongs to one key, so it cannot outlive the choice
        // that produced it — and neither can anything armed for editing.
        $script = $this->script();

        $this->assertStringContainsString("lookupKeyEl.addEventListener('change', () => {", $script);
        $this->assertStringContainsString("lookupTimestampEl.addEventListener('change', clearEditTarget);", $script);
    }

    public function test_the_key_list_follows_every_change_to_the_store(): void
    {
        // A write can add a key and a delete can remove one, so the selector is
        // refreshed wherever the store changes — not only at load.
        $this->assertGreaterThanOrEqual(
            6,
            preg_match_all('/loadKeyOptions\(\)/', $this->script()),
            'the key selector can go stale after a write or a delete'
        );
    }

    // ---------------------------------------------------------------
    // Scheduling the correction
    // ---------------------------------------------------------------

    public function test_the_schedule_is_picked_not_typed(): void
    {
        // Native controls, the same pair the content form schedules with: a
        // calendar and a clock instead of a unix number nobody can read. The
        // moment to *read* at stays a raw timestamp — it is a number copied
        // from a record, not a wall clock anyone picks.
        $page = $this->page();

        $this->assertMatchesRegularExpression('/<input[^>]*id="publish-date"[^>]*type="date"/i', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*id="publish-time"[^>]*type="time"/i', $page);
        $this->assertStringContainsString('id="lookup-timestamp"', $page);
    }

    public function test_the_clock_is_stepped_to_the_minute(): void
    {
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="publish-time"[^>]*step="60"/i',
            $this->page()
        );
    }

    public function test_the_pickers_start_disabled_with_the_box_they_belong_to(): void
    {
        // They schedule the correction, so they mean nothing until a version to
        // correct has been resolved.
        $page = $this->page();

        foreach (['publish-date', 'publish-time'] as $id) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*id="'.preg_quote($id, '/').'"[^>]*\bdisabled\b/i',
                $page,
                "#{$id} does not start disabled"
            );
        }
    }

    public function test_both_labels_say_which_zone_they_are_read_in(): void
    {
        // The boxes show a bare wall clock, and it is read as UTC — the zone the
        // store and every other reading on this page use.
        $page = $this->page();

        foreach (['publish-date', 'publish-time'] as $id) {
            $this->assertMatchesRegularExpression(
                '/<label for="'.preg_quote($id, '/').'">[^<]*\(UTC\)[^<]*<\/label>/i',
                $page,
                "the label for #{$id} does not say UTC"
            );
        }
    }

    public function test_the_pickers_are_read_as_utc_through_the_shared_helper(): void
    {
        // The same function the content form uses, so a moment picked in one
        // block cannot mean a different instant than the same moment picked in
        // the other.
        $script = $this->script();

        $this->assertStringContainsString('function utcTimestampFrom(', $script);

        // The declaration plus its three callers: the content form's preview and
        // its save handler, and this block's editPublishTime().
        $this->assertSame(
            4,
            preg_match_all('/utcTimestampFrom\(/', $script),
            'the shared UTC reader has gained or lost a caller'
        );
    }

    public function test_a_lookup_fills_the_pickers_with_the_schedule_it_found(): void
    {
        // So the correction keeps the version's own publish_time unless the
        // editor changes it, and the schedule is visible while editing.
        $script = $this->script();

        $this->assertStringContainsString('function utcPickerValues(', $script);
        $this->assertStringContainsString("typeof record.publish_time === 'number'", $script);
    }

    public function test_the_pickers_are_read_as_utc_rather_than_locally(): void
    {
        // toISOString, not the local getters, for the reason the labels give.
        $this->assertStringContainsString(
            'const iso = new Date(unixSeconds * MS_PER_SECOND).toISOString();',
            $this->script()
        );
    }

    public function test_an_empty_pair_of_pickers_keeps_the_replaced_schedule(): void
    {
        // Absent publish_time means "carry the old one over" at the API, so
        // clearing the boxes cannot silently drop a schedule.
        $this->assertStringContainsString("return { publishTime: '' };", $this->script());
    }

    public function test_half_a_moment_is_refused_rather_than_completed(): void
    {
        // Defaulting the box that was left empty would schedule an instant the
        // editor never picked.
        $script = $this->script();

        $this->assertStringContainsString('Pick the date the correction becomes active', $script);
        $this->assertStringContainsString('Pick the time the correction becomes active', $script);
    }

    public function test_the_preview_starts_hidden(): void
    {
        // It describes a schedule that has not been picked yet.
        $this->assertMatchesRegularExpression(
            '/<p[^>]*id="publish-time-preview"[^>]*\bhidden\b/i',
            $this->page()
        );
    }

    public function test_the_preview_warns_when_a_correction_is_scheduled_forward(): void
    {
        // A future publish_time removes the version people can see and appends
        // one nobody can read until then, so the page says so before the save
        // rather than leaving it to be discovered.
        $this->assertStringContainsString(
            'that is in the future, so the correction stays hidden until then.',
            $this->script()
        );
    }

    public function test_the_schedule_rides_in_the_query_string(): void
    {
        // Alongside the timestamp naming the version, and independent of it.
        $script = $this->script();

        $this->assertStringContainsString("query.set('publish_time', publish.publishTime);", $script);
        $this->assertStringContainsString("query.set('timestamp', timestamp);", $script);
    }

    public function test_an_untouched_schedule_is_not_resent(): void
    {
        // The pickers stop at the minute while a stored publish_time carries
        // seconds, so echoing back what was loaded would round 22:13:20 down to
        // 22:13:00 — an edit to a schedule nobody touched. Leaving the
        // parameter off lets the API carry the exact value over instead.
        $script = $this->script();

        $this->assertStringContainsString('editTarget = { key, timestamp, schedule };', $script);
        $this->assertStringContainsString(
            'const rescheduled = publishDateEl.value !== schedule.date || publishTimeEl.value !== schedule.time;',
            $script
        );
        $this->assertStringContainsString('if (rescheduled && publish.publishTime) {', $script);
    }

    // ---------------------------------------------------------------
    // What the endpoint does with it
    // ---------------------------------------------------------------

    public function test_the_shape_the_box_sends_back_corrects_the_version(): void
    {
        // The whole round trip the page performs: read a message, send the
        // corrected object back under the same key.
        $key = 'route.bangkok-chiang-mai.banner';
        $this->postJson('/object', [$key => ['message' => 'Sale ends Friday']])->assertCreated();

        $found = $this->getJson('/object/'.$key)->assertOk()->json('value');
        $this->assertSame(['message' => 'Sale ends Friday'], $found);

        $this->putJson('/object/'.$key, [$key => ['message' => 'Sale extended to Sunday']])
            ->assertOk()
            ->assertJson(['value' => ['message' => 'Sale extended to Sunday']]);

        $this->assertSame(1, KvEntry::where('key', $key)->count());
    }
}

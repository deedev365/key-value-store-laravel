<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Add a content item" form, which writes the fixed {"message": "..."}
 * shape the travel site reads, scheduled through ?publish_time.
 *
 * The form is a front end over the existing POST /object — the API has no
 * notion of this shape and needs none. So there are two contracts to hold:
 * the markup/script pair that builds the object, pinned the way the rest of
 * the page is, and the API's willingness to store and return it unchanged.
 */
class ContentItemFormTest extends TestCase
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
    // The three fields
    // ---------------------------------------------------------------

    public function test_the_form_has_a_key_a_content_and_a_date_and_time_field(): void
    {
        $page = $this->page();

        foreach (['content-key', 'content-body', 'content-date', 'content-time'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $page, "#{$id} is missing");
        }
    }

    public function test_the_activation_moment_is_picked_not_typed(): void
    {
        // Native controls, so the editor gets a calendar and a clock and cannot
        // enter a shape the script has to guess at.
        $page = $this->page();

        $this->assertMatchesRegularExpression('/<input[^>]*id="content-date"[^>]*type="date"/i', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*id="content-time"[^>]*type="time"/i', $page);
    }

    public function test_all_four_fields_are_required(): void
    {
        $page = $this->page();

        // Unlike the lookup timestamp, which means "now" when left blank, an
        // absent activation moment here has no sensible default — the record's
        // whole purpose is to carry one.
        foreach (['content-key', 'content-body', 'content-date', 'content-time'] as $id) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*id="'.preg_quote($id, '/').'"[^>]*\brequired\b/i',
                $page,
                "#{$id} is not marked required"
            );
        }
    }

    public function test_the_key_field_carries_the_same_charset_pattern_as_the_other_key_boxes(): void
    {
        $this->assertMatchesRegularExpression(
            '/id="content-key"[^>]*pattern="\[A-Za-z0-9_\.\\\\-\]\+"/i',
            $this->page()
        );
    }

    public function test_the_typed_fields_have_a_placeholder(): void
    {
        // Only the free-text boxes: a date or time input renders its own format
        // hint, and a placeholder on one is ignored by the browser anyway.
        $page = $this->page();

        foreach (['content-key', 'content-body'] as $id) {
            $this->assertMatchesRegularExpression(
                '/id="'.preg_quote($id, '/').'"[^>]*placeholder="/i',
                $page,
                "#{$id} has no placeholder"
            );
        }
    }

    public function test_both_pickers_are_labelled_utc(): void
    {
        // The pickers show a bare wall clock, so the zone it is read in has to
        // be said out loud — the number sent is computed as UTC.
        $page = $this->page();

        foreach (['content-date', 'content-time'] as $id) {
            $this->assertMatchesRegularExpression(
                '/<label for="'.preg_quote($id, '/').'">[^<]*\(UTC\)[^<]*<\/label>/i',
                $page,
                "the label for #{$id} does not say UTC"
            );
        }
    }

    // ---------------------------------------------------------------
    // What the script sends
    // ---------------------------------------------------------------

    public function test_the_script_posts_the_message_shape(): void
    {
        $this->assertStringContainsString(
            '{ [key]: { message: content } }',
            $this->script(),
            'the content form no longer builds the {message} value'
        );
    }

    public function test_whatever_is_typed_in_content_becomes_the_message_verbatim(): void
    {
        // The free-form box above tries JSON.parse on what was typed, so
        // {"a":1} there is stored as an object. This form must not: everything
        // entered is the message, so typing {"a":1} stores that text as the
        // message rather than nesting an object under it.
        $script = $this->script();

        $this->assertSame(
            2,
            preg_match_all('/parseValue\(/', $script),
            'parseValue() is called outside the free-form write handler'
        );

        $this->assertStringContainsString('{ [key]: { message: content } }', $script);
        $this->assertStringNotContainsString('message: parseValue(', $script);
    }

    public function test_the_content_box_asks_for_a_message(): void
    {
        $this->assertMatchesRegularExpression(
            '/id="content-body"[^>]*placeholder="Put your message"/i',
            $this->page()
        );
    }

    public function test_the_activation_moment_is_not_copied_into_the_value(): void
    {
        // publish_time is a column now, so a copy inside the value would be the
        // same moment stored twice with nothing keeping the two in step. The
        // label under the heading promises {"message": "..."} and nothing else.
        $this->assertStringNotContainsString('time: time.unix', $this->script());

        $this->assertStringContainsString(
            '<code>{"message": "..."}</code>',
            $this->page(),
            'the form no longer says which shape it stores'
        );
    }

    public function test_the_activation_moment_is_sent_as_a_number_of_seconds(): void
    {
        // The stored `time` and the publish_time parameter are both numbers;
        // sending the picker's string would store a string.
        $script = $this->script();

        $this->assertStringContainsString('Math.floor(ms / MS_PER_SECOND)', $script);
    }

    public function test_the_form_schedules_through_the_publish_time_parameter(): void
    {
        // The picked moment is what the API schedules on, so it has to reach
        // the query string — storing it only inside the value would leave the
        // record live immediately.
        $this->assertStringContainsString(
            '?publish_time=${encodeURIComponent(time.unix)}',
            $this->script(),
            'the content form no longer sends publish_time'
        );
    }

    public function test_the_content_check_does_not_trim(): void
    {
        // Same rule as the free-form value box: whitespace is content.
        $script = $this->script();

        $this->assertStringContainsString("if (content === '')", $script);
        $this->assertStringNotContainsString("content.trim() === ''", $script);
    }

    // ---------------------------------------------------------------
    // The activation time must be unambiguous
    // ---------------------------------------------------------------

    public function test_the_picked_moment_is_converted_as_utc(): void
    {
        // Date.UTC, not the Date constructor. Handing "2026-08-17T16:15" to the
        // constructor applies the browser's own offset, so the same form filled
        // in from Bangkok and from London would schedule different instants.
        $script = $this->script();

        $this->assertStringContainsString('Date.UTC(', $script);
        $this->assertSame(
            0,
            preg_match('/Date\.parse\(|new Date\(\s*`|new Date\(\s*\$\{/', $script),
            'the script parses a date string instead of building it as UTC'
        );
    }

    public function test_an_impossible_date_is_refused_rather_than_rolled_forward(): void
    {
        // Date.UTC turns the 31st of February into March, so the components are
        // read back to catch a value the picker would never have produced.
        $script = $this->script();

        $this->assertStringContainsString('getUTCFullYear()', $script);
        $this->assertStringContainsString('getUTCMonth()', $script);
        $this->assertStringContainsString('getUTCDate()', $script);
    }

    public function test_the_form_does_not_use_a_datetime_local_control(): void
    {
        // datetime-local reports a local wall clock with no zone attached; a
        // separate date and time read as UTC is what avoids that ambiguity.
        $this->assertSame(
            0,
            preg_match('/id="content-(date|time)"[^>]*type="datetime-local"/i', $this->page())
        );
    }

    public function test_the_time_picker_is_stepped_to_the_minute(): void
    {
        // step="60" is what keeps the control showing hours and minutes only,
        // with no seconds box to fill in.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="content-time"[^>]*step="60"/i',
            $this->page()
        );
    }

    public function test_the_time_value_is_read_as_hours_and_minutes_only(): void
    {
        // No optional seconds group: a value carrying seconds did not come from
        // the stepped picker, so it is refused rather than quietly rounded.
        $this->assertStringContainsString(
            'const TIME_VALUE = /^(\\d{2}):(\\d{2})$/;',
            $this->script(),
            'the time pattern accepts something other than HH:MM'
        );
    }

    public function test_the_preview_echoes_the_instant_to_the_minute(): void
    {
        // The pickers offer nothing finer than a minute, so showing a ":00" the
        // editor cannot change would claim a precision the form lacks.
        $script = $this->script();

        $this->assertStringContainsString('function formatUtcToMinute(', $script);
        $this->assertStringContainsString('formatUtcToMinute(parsed.unix)', $script);
    }

    public function test_the_pickers_are_read_with_their_specified_wire_formats(): void
    {
        // <input type="date"> and <input type="time"> always yield YYYY-MM-DD
        // and HH:MM regardless of how the picker displays them, so the values
        // are taken apart exactly rather than parsed loosely.
        $script = $this->script();

        $this->assertStringContainsString('const DATE_VALUE =', $script);
        $this->assertStringContainsString('const TIME_VALUE =', $script);
    }

    public function test_the_preview_element_starts_hidden(): void
    {
        // It describes a time that has not been typed yet.
        $this->assertMatchesRegularExpression(
            '/<p[^>]*id="content-time-preview"[^>]*\bhidden\b/i',
            $this->page()
        );
    }

    // ---------------------------------------------------------------
    // The API stores the shape unchanged
    // ---------------------------------------------------------------

    public function test_the_api_stores_and_returns_the_message_shape_unchanged(): void
    {
        $value = ['message' => 'Songkran timetable now available'];

        $this->postJson('/object', ['route.bangkok-chiang-mai.banner' => $value])
            ->assertCreated()
            ->assertJson(['key' => 'route.bangkok-chiang-mai.banner', 'value' => $value]);

        $this->getJson('/object/route.bangkok-chiang-mai.banner')
            ->assertOk()
            ->assertJson(['value' => $value]);
    }

    public function test_the_message_survives_as_a_string(): void
    {
        // The value column is cast to 'object', so a one-property object must
        // come back as an object rather than being flattened to its value.
        $this->postJson('/object', [
            'route.bangkok-chiang-mai.banner' => ['message' => 'Normal service'],
        ])->assertCreated();

        $this->assertIsString(
            $this->getJson('/object/route.bangkok-chiang-mai.banner')->json('value.message')
        );
    }

    public function test_a_dotted_hyphenated_content_key_is_a_legal_key(): void
    {
        // The real keys are namespaced with dots and carry hyphenated route
        // names — both are in the allowed charset, and the routes must match
        // them back on the way out.
        foreach ([
            'route.bangkok-chiang-mai.banner',
            'operator.srt.booking_notice',
            'country.th.payment_message',
        ] as $key) {
            $this->postJson('/object', [$key => ['message' => 'Normal service']])
                ->assertCreated()
                ->assertJson(['key' => $key]);

            $this->getJson('/object/'.$key)->assertOk()->assertJson(['key' => $key]);
        }
    }

    public function test_a_content_record_appends_a_version_rather_than_replacing_one(): void
    {
        // Saving the form twice for one key is a correction, and the store is
        // append-only: the earlier copy stays readable.
        $key = 'route.bangkok-chiang-mai.banner';

        $this->postJson('/object', [$key => ['message' => 'first']])->assertCreated();
        $this->postJson('/object', [$key => ['message' => 'second']])->assertCreated();

        $history = $this->getJson('/object/'.$key)->assertOk();
        $this->assertSame('second', $history->json('value.message'));

        $this->getJson('/object/'.$key.'/history')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.value.message', 'first')
            ->assertJsonPath('1.value.message', 'second');
    }
}

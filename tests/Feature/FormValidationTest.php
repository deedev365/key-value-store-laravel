<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Markup/script contract for the page's two forms. The behaviour itself lives
 * in the browser, but the script and the markup are separate files now and
 * nothing else ties them together, so the pieces the guards depend on are
 * pinned here.
 */
class FormValidationTest extends TestCase
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

    private function assertInputIsRequired(string $id, string $page): void
    {
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="'.preg_quote($id, '/').'"[^>]*\brequired\b/i',
            $page,
            "#{$id} is not marked required"
        );
    }

    // ---------------------------------------------------------------
    // Which fields are required
    // ---------------------------------------------------------------

    public function test_both_write_fields_are_marked_required(): void
    {
        $page = $this->page();

        $this->assertInputIsRequired('write-key', $page);
        $this->assertInputIsRequired('write-value', $page);
    }

    public function test_the_lookup_key_is_marked_required(): void
    {
        $this->assertInputIsRequired('lookup-key', $this->page());
    }

    public function test_the_timestamp_field_stays_optional(): void
    {
        // An absent timestamp means "current value" — requiring it would break
        // the common case.
        $this->assertSame(
            0,
            preg_match('/<input[^>]*id="lookup-timestamp"[^>]*\brequired\b/i', $this->page()),
            'the optional timestamp field was marked required'
        );
    }

    public function test_every_field_keeps_its_placeholder(): void
    {
        $page = $this->page();

        $this->assertMatchesRegularExpression('/id="write-key"[^>]*placeholder="mykey"/i', $page);
        $this->assertMatchesRegularExpression('/id="write-value"[^>]*placeholder="/i', $page);
        $this->assertMatchesRegularExpression('/id="lookup-key"[^>]*placeholder="mykey"/i', $page);
        $this->assertMatchesRegularExpression('/id="lookup-timestamp"[^>]*placeholder="/i', $page);
    }

    // ---------------------------------------------------------------
    // What the script does with them
    // ---------------------------------------------------------------

    public function test_the_script_refuses_an_empty_key_and_an_empty_value(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("'Key is required.'", $script);
        $this->assertStringContainsString('Value is required.', $script);
    }

    public function test_the_empty_key_message_is_distinct_from_the_charset_message(): void
    {
        // An empty key used to fall through to "may only contain letters,
        // digits, ..." which does not describe the problem.
        $script = $this->script();

        $this->assertStringContainsString("if (key === '')", $script);
        $this->assertStringContainsString('if (!isValidKey(key))', $script);
    }

    public function test_the_value_check_does_not_trim(): void
    {
        // "   " is a legitimate value and the API stores it verbatim, so the
        // form must not treat whitespace as empty.
        $script = $this->script();

        $this->assertStringContainsString("if (rawValue === '')", $script);
        $this->assertStringNotContainsString("rawValue.trim() === ''", $script);
    }

    public function test_all_three_lookup_buttons_share_one_key_guard(): void
    {
        // Get value, Full history and Delete key read the same field. If any
        // of them validated separately, the three could drift apart — and the
        // one that matters most is the destructive one.
        $script = $this->script();

        $this->assertStringContainsString('function validatedLookupKey()', $script);
        $this->assertSame(
            3,
            preg_match_all('/validatedLookupKey\(\)/', $script) - 1,
            'expected exactly three callers of validatedLookupKey()'
        );
    }

    public function test_the_error_style_is_applied_by_class_not_by_pseudo_selector(): void
    {
        // :invalid would paint every required box red on first paint, before
        // the user has typed anything.
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString('input.field-error', $css);

        // Comments are stripped first — the rule above explains why :invalid
        // is avoided, and mentioning it is not the same as using it.
        $rules = preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertSame(
            0,
            preg_match('/:invalid\s*[,{]/', $rules),
            'the stylesheet selects on :invalid'
        );
    }

    public function test_the_highlight_is_cleared_when_the_user_types(): void
    {
        $this->assertStringContainsString(
            "field.addEventListener('input', () => field.classList.remove('field-error'))",
            $this->script()
        );
    }

    // ---------------------------------------------------------------
    // How results are presented
    // ---------------------------------------------------------------

    public function test_messages_and_data_are_rendered_differently(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('function showMessage(', $script);
        $this->assertStringContainsString('function showData(', $script);

        // Only showData serialises; a message must never be stringified into
        // an object literal on its way to the user.
        $this->assertSame(
            1,
            preg_match_all('/JSON\.stringify\(data, null, 2\)/', $script),
            'JSON.stringify is used outside showData()'
        );
    }

    public function test_an_error_body_is_reduced_to_its_sentence(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('function messageFrom(', $script);
        $this->assertStringContainsString('messageFrom(data)', $script);
    }

    public function test_a_failed_response_never_falls_through_without_a_message(): void
    {
        $this->assertStringContainsString('Request failed (HTTP ${res.status}).', $this->script());
    }

    public function test_the_api_error_bodies_the_script_unwraps_are_shaped_as_expected(): void
    {
        // messageFrom() reads `message` and `errors`; if the API stopped
        // sending those, the page would silently fall back to the generic
        // "Request failed" line.
        $this->getJson('/object/never_written')
            ->assertNotFound()
            ->assertJsonStructure(['message']);

        $this->postJson('/object', ['bad key!' => 'value'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    // ---------------------------------------------------------------
    // The rules are the form's, not the API's
    // ---------------------------------------------------------------

    public function test_the_api_still_accepts_an_empty_string_value(): void
    {
        $this->postJson('/object', ['emptyish' => ''])
            ->assertCreated()
            ->assertJson(['key' => 'emptyish', 'value' => '']);
    }

    public function test_the_api_still_accepts_a_whitespace_only_value(): void
    {
        $this->postJson('/object', ['spaced' => '   '])
            ->assertCreated()
            ->assertJson(['key' => 'spaced', 'value' => '   ']);
    }
}

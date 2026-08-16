<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The CSP is only worth anything while the page stays free of inline code:
 * one inline <script>, or one style="..." attribute, and the policy either
 * breaks the page or has to be loosened to 'unsafe-inline', at which point it
 * stops defending against injected markup. These tests guard that property,
 * not just the presence of the header.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(string $uri = '/'): string
    {
        return $this->get($uri)->assertOk()->headers->get('Content-Security-Policy');
    }

    public function test_the_page_sends_a_content_security_policy(): void
    {
        $this->assertNotEmpty($this->policy());
    }

    public function test_the_policy_denies_everything_by_default(): void
    {
        $this->assertStringContainsString("default-src 'none'", $this->policy());
    }

    /**
     * @return string[][]
     */
    public static function requiredDirectives(): array
    {
        return [
            ["script-src 'self'"],
            ["style-src 'self'"],
            ["connect-src 'self'"],
            ["img-src 'self'"],
            ["base-uri 'none'"],
            ["form-action 'none'"],
            ["frame-ancestors 'none'"],
        ];
    }

    #[DataProvider('requiredDirectives')]
    public function test_the_policy_contains_directive(string $directive): void
    {
        $this->assertStringContainsString($directive, $this->policy());
    }

    public function test_the_policy_never_allows_inline_or_eval(): void
    {
        $policy = $this->policy();

        $this->assertStringNotContainsString('unsafe-inline', $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
    }

    public function test_the_policy_is_sent_on_api_responses_too(): void
    {
        $this->postJson('/object', ['mykey' => 'value']);

        foreach (['/object/mykey', '/object/get_all_records'] as $uri) {
            $this->assertStringContainsString("default-src 'none'", $this->policy($uri));
        }
    }

    // ---------------------------------------------------------------
    // The page must stay compatible with the policy
    // ---------------------------------------------------------------

    public function test_the_page_has_no_inline_script(): void
    {
        $page = $this->get('/')->assertOk()->getContent();

        // A <script> tag is fine; a <script> tag with a body is not.
        $this->assertSame(
            0,
            preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $page),
            'the page contains an inline <script> block, which the CSP forbids'
        );
    }

    public function test_the_page_has_no_inline_style(): void
    {
        $page = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<style', $page);
        $this->assertSame(
            0,
            preg_match('/\sstyle\s*=\s*"/i', $page),
            'the page contains a style="..." attribute, which style-src forbids'
        );
    }

    public function test_the_page_references_the_external_assets(): void
    {
        $page = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="/css/app.css"', $page);
        $this->assertStringContainsString('src="/js/app.js"', $page);
    }

    public function test_the_asset_files_exist_and_are_not_empty(): void
    {
        foreach (['css/app.css', 'js/app.js'] as $asset) {
            $path = public_path($asset);

            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path), "{$asset} is empty");
        }
    }

    public function test_the_script_is_deferred_so_the_dom_is_ready(): void
    {
        // The script moved into <head>; without defer it would run before the
        // elements it binds listeners to exist.
        $this->assertMatchesRegularExpression(
            '/<script[^>]*src="\/js\/app\.js"[^>]*\bdefer\b/i',
            $this->get('/')->assertOk()->getContent()
        );
    }

    public function test_every_element_the_script_binds_to_exists_on_the_page(): void
    {
        // Extraction moved the script away from the markup it drives, so pin
        // the contract between them.
        $page = $this->get('/')->assertOk()->getContent();
        $script = file_get_contents(public_path('js/app.js'));

        preg_match_all("/getElementById\('([^']+)'\)/", $script, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $id) {
            $this->assertStringContainsString(
                'id="'.$id.'"',
                $page,
                "the script binds to #{$id}, which is not on the page"
            );
        }
    }
}

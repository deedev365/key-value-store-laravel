<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GetAllRecordsController;
use App\Http\Controllers\Api\ShowObjectController;
use App\ValueObjects\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Laravel answers with the first route that matches, so a wildcard declared
 * above a literal route quietly swallows it. Key::routePattern() removes the
 * overlap instead of relying on declaration order, and these tests pin that
 * down: the patterns are registered here in the order that used to break, and
 * each path still resolves to the endpoint that owns it.
 */
class RouteOrderIndependenceTest extends TestCase
{
    /**
     * The real pair of routes, deliberately declared the wrong way round.
     */
    private function registerReversed(): void
    {
        Route::get('/reversed/{key}', ShowObjectController::class)
            ->where('key', Key::routePattern());

        Route::get('/reversed/{key}/history', ShowObjectController::class)
            ->where('key', Key::routePattern());

        Route::get('/reversed/get_all_records/{page?}', GetAllRecordsController::class)
            ->where('page', '\d+');
    }

    private function controllerFor(string $uri): string
    {
        $this->registerReversed();

        return Route::getRoutes()->match(Request::create($uri, 'GET'))->getActionName();
    }

    public function test_the_listing_wins_the_path_it_owns(): void
    {
        $this->assertStringContainsString(
            GetAllRecordsController::class,
            $this->controllerFor('/reversed/get_all_records')
        );
    }

    public function test_the_listing_wins_the_path_it_owns_with_a_page(): void
    {
        $this->assertStringContainsString(
            GetAllRecordsController::class,
            $this->controllerFor('/reversed/get_all_records/2')
        );
    }

    /**
     * The exclusion is a whole segment, not a prefix: a key that merely starts
     * with the reserved name is an ordinary key and still reaches the wildcard.
     */
    public function test_a_key_that_only_resembles_the_reserved_one_still_matches(): void
    {
        $this->assertStringContainsString(
            ShowObjectController::class,
            $this->controllerFor('/reversed/get_all_records_2')
        );
    }

    public function test_an_ordinary_key_still_matches(): void
    {
        $this->assertStringContainsString(
            ShowObjectController::class,
            $this->controllerFor('/reversed/mykey')
        );
    }

    /**
     * Every reserved name has to be excluded, not just the one that exists
     * today, or adding to the list would reintroduce the collision.
     */
    public function test_the_pattern_excludes_every_reserved_name(): void
    {
        $pattern = '{^'.Key::routePattern().'$}';

        foreach (Key::RESERVED as $reserved) {
            $this->assertSame(
                0,
                preg_match($pattern, $reserved),
                "the route pattern still matches the reserved name '{$reserved}'"
            );

            $this->assertSame(
                1,
                preg_match($pattern, $reserved.'_2'),
                "the route pattern rejects '{$reserved}_2', which is an ordinary key"
            );
        }
    }
}

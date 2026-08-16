<?php

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Behat\Config\TesterOptions;
use Tests\Behat\ApiContext;

/**
 * Behat 4 configures itself from PHP rather than YAML.
 *
 * Strict result interpretation is on so that an undefined or pending step
 * fails the run: the suite is a contract, and a scenario that silently does
 * nothing is worse than one that fails.
 */
return (new Config)->withProfile(
    (new Profile('default'))
        ->withSuite(
            (new Suite('api'))
                ->withPaths(__DIR__.'/features')
                ->withContexts(ApiContext::class)
        )
        ->withTesterOptions(
            (new TesterOptions)->withStrictResultInterpretation()
        )
);

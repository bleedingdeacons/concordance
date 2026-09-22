<?php

declare(strict_types=1);

// Pest configuration.
//
// Two kinds of test live under tests/Unit. The ones that reach WordPress —
// the admin screens, the API client and cache, the CLI command, the REST
// manager, the plugin bootstrap and the User-Agent (home_url()) — run on
// wp-mocks' TestCase, which owns Brain Monkey's lifecycle, the Mockery
// integration and the WpState reset between tests. The pure-PHP tests — the
// PSR-11 container, the models and Encryption — need none of it and stay on
// Pest's default, plain PHPUnit.
//
// So this list is load-bearing: a test that reaches Brain Monkey from a file
// not named here finds none of its functions defined. A new WordPress-coupled
// test file belongs in one of these directories, or has to be named here.
//
// One test stays a PHPUnit class rather than a Pest closure: the one that
// defines WP_CLI, which cannot be undone once defined, so it must run in a
// separate process — and Pest refuses process isolation outright. See
// Unit/PluginWpCliTest.php.

use BleedingDeacons\WpMocks\TestCase;

pest()->extend(TestCase::class)->in(
    'Unit/Admin',
    'Unit/Api',
    'Unit/Cli',
    'Unit/Managers',
    'Unit/PluginTest.php',
    'Unit/Common/UserAgentTest.php',
);

/**
 * Runs $render inside an output buffer and returns what it printed.
 *
 * The admin screen and the dashboard widget echo their markup, so this is how
 * their tests read it. The buffer is closed in a finally, so a render that
 * throws — wp_die() is a WpDieException under the shared stubs — cannot leave
 * it open and have PHPUnit flag the test as risky.
 */
function captureOutput(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}

<?php

declare(strict_types=1);

/*
 * A dependency-free test runner: php tests/run.php [filter]
 *
 * Every public method named test* on a class in tests/*Test.php runs on a
 * fresh instance, with setUp()/tearDown() around it. Tests that need a mail
 * server talk to the one tests/dovecot/start.sh runs -- MAIL_SIMPLY_TEST_IMAP,
 * MAIL_SIMPLY_TEST_SMTP and MAIL_SIMPLY_TEST_MAILPIT say where -- and are
 * skipped when those are not set. They empty the test mailboxes as they go,
 * so never point them at a server holding mail you care about.
 */

namespace MailSimply\Tests;

use Throwable;

// Output is held back until the end: the session tests need PHP to believe no
// headers have been sent yet.
ob_start();

require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/TestCase.php';

$filter = $argv[1] ?? null;
$results = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$failures = [];
$started = microtime(true);

foreach (glob(__DIR__.'/*Test.php') ?: [] as $file) {
    require_once $file;
    $class = __NAMESPACE__.'\\'.basename($file, '.php');

    foreach (get_class_methods($class) as $method) {
        if (! str_starts_with($method, 'test')) {
            continue;
        }

        $name = basename($file, '.php').'::'.$method;

        if ($filter !== null && ! str_contains(strtolower($name), strtolower($filter))) {
            continue;
        }

        $test = new $class;

        try {
            $test->setUp();
            $test->{$method}();
            $results['pass']++;
        } catch (Skipped $e) {
            $results['skip']++;
        } catch (Throwable $e) {
            $results['fail']++;
            $failures[] = sprintf("✗ %s\n  %s: %s\n  at %s:%d", $name, $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        } finally {
            try {
                $test->tearDown();
            } catch (Throwable) {
                // A failing teardown must not hide the test's own result.
            }
        }
    }
}

ob_end_clean();

foreach ($failures as $failure) {
    echo $failure, "\n\n";
}

printf(
    "%d passed, %d failed, %d skipped (%.2fs)%s\n",
    $results['pass'],
    $results['fail'],
    $results['skip'],
    microtime(true) - $started,
    $results['skip'] > 0 && getenv('MAIL_SIMPLY_TEST_IMAP') === false
        ? ' - start tests/dovecot/start.sh and set MAIL_SIMPLY_TEST_IMAP to run the mail server tests'
        : '',
);

exit($results['fail'] > 0 ? 1 : 0);

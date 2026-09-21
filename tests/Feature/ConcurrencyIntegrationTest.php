<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Tests\Feature;

use PDO;
use PDOException;
use Raedev\LaravelPaymentReconciliation\Services\PaymentService;
use Raedev\LaravelPaymentReconciliation\Tests\TestCase;

/**
 * Proves that the row-level locking PaymentService::transitionTo() and
 * markPaid() depend on actually blocks a genuinely concurrent second
 * connection - not just a sequential retry on the same connection,
 * which is all every other "duplicate"/"concurrent" test in this suite
 * can exercise on their own.
 *
 * Only meaningful against a real database with row-level locks. Two
 * separate connections to SQLite's ":memory:" are two entirely
 * separate, empty databases (there is nothing to contend over), and
 * Illuminate's SQLite grammar doesn't even emit a FOR UPDATE clause -
 * lockForUpdate() is a no-op there at the SQL level. So this test is
 * skipped unless DB_CONNECTION points at a real server - see
 * tests/TestCase.php and the "Tests against MySQL" CI job, which runs
 * this for real on every push.
 */
class ConcurrencyIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('DB_CONNECTION', 'sqlite') === 'sqlite') {
            $this->markTestSkipped('Requires a real database with row-level locking (set DB_CONNECTION=mysql).');
        }
    }

    public function test_a_second_connection_is_blocked_while_the_first_holds_the_row_lock(): void
    {
        $payment = app(PaymentService::class)->create([
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '3306'),
            env('DB_DATABASE', 'testing'),
        );
        $username = (string) env('DB_USERNAME', 'root');
        $password = (string) env('DB_PASSWORD', '');

        $connectionA = new PDO($dsn, $username, $password);
        $connectionB = new PDO($dsn, $username, $password);

        $connectionA->beginTransaction();
        $connectionA->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE')->execute([$payment->id]);

        // Fail fast instead of waiting out MySQL's much longer default lock wait timeout.
        $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = false;

        try {
            $connectionB->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE')->execute([$payment->id]);
        } catch (PDOException $e) {
            $blocked = true;
            $this->assertStringContainsStringIgnoringCase('lock wait timeout', $e->getMessage());
        } finally {
            $connectionA->commit();
        }

        $this->assertTrue(
            $blocked,
            'Expected the second connection to be blocked by the first connection\'s row lock, but it was not.'
        );
    }
}

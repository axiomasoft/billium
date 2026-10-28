<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Tests\Inbox\InboxTestCase;
use Billium\Platform\Tests\Inbox\JournalHandler;
use PHPUnit\Framework\Attributes\Group;

/** The source namespace is compound: authority, lineage, seller, environment, channel and partition ref (INV-08, INV-14, D60). */
#[Group('INV-08')]
#[Group('INV-14')]
final class InboxSourceIsolationTest extends InboxTestCase
{
    private const string OTHER = 'remote-billium';

    public function test_two_authorities_cannot_share_or_hijack_a_partition_ref(): void
    {
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', [self::AUTHORITY, self::OTHER]));
        $handler = new JournalHandler;

        $this->custody($this->envelope(1, event: 'evt-1', partition: 'shared'), $handler);
        // The same event id and partition ref from another authority is another identity in another partition.
        $other = $this->custody($this->envelope(1, event: 'evt-1', partition: 'shared', authority: self::OTHER), $handler);
        self::assertSame(CustodyDisposition::Applied, $other->disposition);
        self::assertSame(2, $this->rows('billium_feed_partition'));
        self::assertSame(2, $this->rows('billium_inbox_message', "status = 'applied'"));

        // The other authority at sequence 3 waits in its own namespace; the first authority's frontier is untouched.
        $this->custody($this->envelope(3, event: 'evt-3', partition: 'shared', authority: self::OTHER), $handler);
        $mine = $this->row('SELECT accounted_sequence, custody_sequence FROM billium_feed_partition WHERE source_authority = ?', [self::AUTHORITY]);
        $theirs = $this->row('SELECT accounted_sequence, custody_sequence FROM billium_feed_partition WHERE source_authority = ?', [self::OTHER]);
        self::assertSame([1, 1], [$mine['accounted_sequence'], $mine['custody_sequence']]);
        self::assertSame([1, 1], [$theirs['accounted_sequence'], $theirs['custody_sequence']]);
        self::assertSame('parked_gap', $this->inbox('evt-3')['status']);
        self::assertSame(2, $handler->calls);
    }

    public function test_seller_environment_and_channel_are_part_of_the_namespace(): void
    {
        $handler = new JournalHandler;
        $this->custody($this->envelope(1, event: 'evt-1'), $handler);

        $this->callers->set(new Caller('seller-b', 'test', self::INSTALLATION, 'actor-2', [self::AUTHORITY]));
        $this->custody($this->envelope(1, event: 'evt-1', seller: 'seller-b'), $handler);

        $this->callers->set(new Caller('seller-a', 'live', self::INSTALLATION, 'actor-1', [self::AUTHORITY]));
        $this->custody($this->envelope(1, event: 'evt-1', environment: 'live'), $handler);

        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', [self::AUTHORITY]));
        $channel = $this->root(fn (TransactionContext $context) => $this->feed()->process($context, 'payment.callback', $this->envelope(1, event: 'evt-1'), $handler));
        self::assertNotNull($channel);

        self::assertSame(4, $this->rows('billium_feed_partition'));
        self::assertSame(4, $this->rows('billium_inbox_message', "status = 'applied'"));
        self::assertSame(4, $handler->calls);
    }

    public function test_an_unauthenticated_scope_is_denied_before_any_custody(): void
    {
        $handler = new JournalHandler;
        $denied = [
            'foreign seller' => $this->envelope(1, seller: 'seller-b'),
            'foreign environment' => $this->envelope(1, environment: 'live'),
            'ungranted authority' => $this->envelope(1, authority: self::OTHER),
        ];
        foreach ($denied as $case => $envelope) {
            try {
                $this->custody($envelope, $handler);
                self::fail("{$case} must be denied");
            } catch (ProblemException $refusal) {
                self::assertSame(ProblemCode::AuthDenied, $refusal->problem, $case);
            }
        }
        $foreignInstallation = $this->envelope(1);
        $foreignInstallation->installationRef = 'inst-other';
        try {
            $this->custody($foreignInstallation, $handler);
            self::fail('A foreign installation must be denied');
        } catch (ProblemException $refusal) {
            self::assertSame(ProblemCode::AuthDenied, $refusal->problem);
        }
        // The source authority can never be omitted from namespace lookup.
        $omitted = $this->envelope(1);
        unset($omitted->sourceAuthority);
        try {
            $this->custody($omitted, $handler);
            self::fail('An envelope without a source authority is invalid');
        } catch (ProblemException $refusal) {
            self::assertSame(ProblemCode::ValidationInvalid, $refusal->problem);
        }

        self::assertSame(0, $this->rows('billium_inbox_message'));
        self::assertSame(0, $this->rows('billium_feed_partition'));
        self::assertSame(0, $handler->calls);
    }

    public function test_the_database_keeps_one_live_partition_and_one_live_delivery_per_source_slot(): void
    {
        $this->seedPartition(5);
        $owner = TestEnvironment::pdo(owner: true);

        // A second live partition of the namespace (even another lineage) is the hijack the live key forbids.
        self::assertSame('23505', self::sqlState($owner, <<<'SQL'
            INSERT INTO billium_feed_partition (channel, source_authority, source_lineage, seller_ref, environment, partition_ref)
            VALUES ('payment.observation', 'embedded-commerz', 'L9', 'seller-a', 'test', 'part-1')
            SQL));
        // Once retired, a successor generation may take the namespace.
        $owner->exec("UPDATE billium_feed_partition SET state = 'retired'");
        self::assertNull(self::sqlState($owner, <<<'SQL'
            INSERT INTO billium_feed_partition (channel, source_authority, source_lineage, seller_ref, environment, partition_ref, generation)
            VALUES ('payment.observation', 'embedded-commerz', 'L1', 'seller-a', 'test', 'part-1', 2)
            SQL));

        $this->custody($this->envelope(6), new JournalHandler); // the successor generation is at 0: sequence 6 waits in custody
        self::assertSame('23505', self::sqlState($owner, <<<'SQL'
            INSERT INTO billium_inbox_message (channel, source_authority, source_lineage, seller_ref, environment, external_event_id, content_digest, payload, partition_ref, source_sequence, operation_ref, operation_revision, status)
            VALUES ('payment.observation', 'embedded-commerz', 'L1', 'seller-a', 'test', 'evt-forged', 'sha256:
            SQL.str_repeat('1', 64)."', '{}', 'part-1', 6, 'op-9', 1, 'parked_gap')"));
    }
}

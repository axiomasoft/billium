<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\FeedBootstrapRequest;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Data\Runtime\Caller;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Internal\Installation\Rows;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Data\AttributionSnapshot;
use Billium\Usage\Tests\Support\UsageFixtures;
use Billium\Usage\Tests\UsageTestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class SellerScopedRlsTest extends UsageTestCase
{
    public function test_optional_seller_scoped_rls_isolates_all_table_classes_without_tenant_id(): void
    {
        $owner = self::connection('billium_owner');
        $owner->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS billium_feed_command_lease_seller_scope ON public.billium_feed_command_lease;
            ALTER TABLE public.billium_feed_command_lease ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.billium_feed_command_lease FORCE ROW LEVEL SECURITY;
            CREATE POLICY billium_feed_command_lease_seller_scope ON public.billium_feed_command_lease
                USING (
                    installation_ref = nullif(current_setting('billium.installation_ref', true), '')
                    AND seller_ref   = nullif(current_setting('billium.seller_ref', true), '')
                    AND environment  = nullif(current_setting('billium.environment', true), '')
                )
                WITH CHECK (
                    installation_ref = nullif(current_setting('billium.installation_ref', true), '')
                    AND seller_ref   = nullif(current_setting('billium.seller_ref', true), '')
                    AND environment  = nullif(current_setting('billium.environment', true), '')
                );

            DROP POLICY IF EXISTS billium_usage_metric_seller_scope ON public.billium_usage_metric;
            ALTER TABLE public.billium_usage_metric ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.billium_usage_metric FORCE ROW LEVEL SECURITY;
            CREATE POLICY billium_usage_metric_seller_scope ON public.billium_usage_metric
                USING (
                    seller_ref      = nullif(current_setting('billium.seller_ref', true), '')
                    AND environment = nullif(current_setting('billium.environment', true), '')
                )
                WITH CHECK (
                    seller_ref      = nullif(current_setting('billium.seller_ref', true), '')
                    AND environment = nullif(current_setting('billium.environment', true), '')
                );

            DROP POLICY IF EXISTS billium_usage_fact_seller_scope ON public.billium_usage_fact;
            ALTER TABLE public.billium_usage_fact ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.billium_usage_fact FORCE ROW LEVEL SECURITY;
            CREATE POLICY billium_usage_fact_seller_scope ON public.billium_usage_fact
                USING (
                    seller_ref      = nullif(current_setting('billium.seller_ref', true), '')
                    AND environment = nullif(current_setting('billium.environment', true), '')
                )
                WITH CHECK (
                    seller_ref      = nullif(current_setting('billium.seller_ref', true), '')
                    AND environment = nullif(current_setting('billium.environment', true), '')
                );

            DROP POLICY IF EXISTS billium_idempotency_receipt_seller_scope ON public.billium_idempotency_receipt;
            ALTER TABLE public.billium_idempotency_receipt ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.billium_idempotency_receipt FORCE ROW LEVEL SECURITY;
            CREATE POLICY billium_idempotency_receipt_seller_scope ON public.billium_idempotency_receipt
                USING (
                    seller_ref      = nullif(current_setting('billium.seller_ref', true), '')
                    AND environment = nullif(current_setting('billium.environment', true), '')
                )
                WITH CHECK (
                    seller_ref      = nullif(current_setting('billium.seller_ref', true), '')
                    AND environment = nullif(current_setting('billium.environment', true), '')
                );

            DROP POLICY IF EXISTS billium_installation_state_scope ON public.billium_installation_state;
            ALTER TABLE public.billium_installation_state ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.billium_installation_state FORCE ROW LEVEL SECURITY;
            CREATE POLICY billium_installation_state_scope ON public.billium_installation_state
                USING (
                    installation_ref = nullif(current_setting('billium.installation_ref', true), '')
                )
                WITH CHECK (
                    installation_ref = nullif(current_setting('billium.installation_ref', true), '')
                );

            DROP POLICY IF EXISTS billium_feed_subscription_seller_scope ON public.billium_feed_subscription;
            ALTER TABLE public.billium_feed_subscription ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.billium_feed_subscription FORCE ROW LEVEL SECURITY;
            CREATE POLICY billium_feed_subscription_seller_scope ON public.billium_feed_subscription
                USING (
                    (source_scope::jsonb->>'installationRef') = nullif(current_setting('billium.installation_ref', true), '')
                    AND (source_scope::jsonb->>'sellerRef')   = nullif(current_setting('billium.seller_ref', true), '')
                    AND (source_scope::jsonb->>'environment') = nullif(current_setting('billium.environment', true), '')
                )
                WITH CHECK (
                    (source_scope::jsonb->>'installationRef') = nullif(current_setting('billium.installation_ref', true), '')
                    AND (source_scope::jsonb->>'sellerRef')   = nullif(current_setting('billium.seller_ref', true), '')
                    AND (source_scope::jsonb->>'environment') = nullif(current_setting('billium.environment', true), '')
                );

            DROP POLICY IF EXISTS billium_feed_bootstrap_lease_seller_scope ON public.billium_feed_bootstrap_lease;
            ALTER TABLE public.billium_feed_bootstrap_lease ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.billium_feed_bootstrap_lease FORCE ROW LEVEL SECURITY;
            CREATE POLICY billium_feed_bootstrap_lease_seller_scope ON public.billium_feed_bootstrap_lease
                USING (
                    (source_scope::jsonb->>'installationRef') = nullif(current_setting('billium.installation_ref', true), '')
                    AND (source_scope::jsonb->>'sellerRef')   = nullif(current_setting('billium.seller_ref', true), '')
                    AND (source_scope::jsonb->>'environment') = nullif(current_setting('billium.environment', true), '')
                )
                WITH CHECK (
                    (source_scope::jsonb->>'installationRef') = nullif(current_setting('billium.installation_ref', true), '')
                    AND (source_scope::jsonb->>'sellerRef')   = nullif(current_setting('billium.seller_ref', true), '')
                    AND (source_scope::jsonb->>'environment') = nullif(current_setting('billium.environment', true), '')
                );
            SQL);
        try {

            config([
                'billium.feed.signing_key' => str_repeat('k', 32),
            ]);
            $this->application()->forgetInstance(BilliumOptions::class);

            // 1. Usage submission + replay under RLS when live attribution is disabled
            $fact = UsageFixtures::fact();
            $first = $this->usage()->submitUsageFact($fact);
            $this->application()->instance(UsageAttribution::class, new class implements UsageAttribution
            {
                public function resolve(Caller $caller, UsageFactV2 $fact): ?AttributionSnapshot
                {
                    return null;
                }
            });
            $replay = $this->usage()->submitUsageFact($fact);
            self::assertSame($first->receiptRef, $replay->receiptRef);

            // 2. EventFeedReader::openFeed + bootstrapSelectedFeed + read + bootstrap work under RLS
            /** @var EventFeedReader $feed */
            $feed = $this->application()->make(EventFeed::class);
            $selection = $feed->openFeed(
                new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'),
                'events-2026-10-v1',
                ['billium.event.v1'],
                ['obligation.created.v1'],
            );
            $codec = new Codec;
            $bootstrapReq = $codec->decode($codec->canonicalize([
                'schema' => FeedBootstrapRequest::SCHEMA,
                'commandKey' => (string) Str::uuid(),
                'installationRef' => self::INSTALLATION,
                'sellerRef' => 'seller-a',
                'environment' => 'test',
                'subscriptionRef' => $selection->subscriptionRef,
                'selectionDigest' => $selection->selectionDigest,
            ]));
            self::assertInstanceOf(FeedBootstrapRequest::class, $bootstrapReq);
            $wireBootstrap = $feed->bootstrapSelectedFeed($bootstrapReq);
            self::assertSame($selection->subscriptionRef, $wireBootstrap->subscriptionRef);
            $directBootstrap = $feed->bootstrap($selection);
            self::assertSame($selection->subscriptionRef, $directBootstrap->selection->subscriptionRef);
            $page = $feed->read($selection);
            self::assertSame($selection->subscriptionRef, $page->subscriptionRef);

            $runtime = self::connection('pgsql');

            // 3. Raw SELECT without set_config returns 0 rows
            self::assertSame(0, Rows::int($runtime->scalar('SELECT count(*) FROM public.billium_usage_fact')));
            self::assertSame(0, Rows::int($runtime->scalar('SELECT count(*) FROM public.billium_feed_subscription')));
            self::assertSame(0, Rows::int($runtime->scalar('SELECT count(*) FROM public.billium_feed_bootstrap_lease')));

            // 4. Class D isolation: seller-a sees its subscription/pin; seller-b sees 0 rows
            $sellerASubs = $runtime->transaction(static function () use ($runtime): int {
                $runtime->selectOne("SELECT set_config('billium.installation_ref', ?, true), set_config('billium.seller_ref', 'seller-a', true), set_config('billium.environment', 'test', true)", [self::INSTALLATION]);

                return Rows::int($runtime->scalar('SELECT count(*) FROM public.billium_feed_subscription'));
            });
            $sellerBSubs = $runtime->transaction(static function () use ($runtime): int {
                $runtime->selectOne("SELECT set_config('billium.installation_ref', ?, true), set_config('billium.seller_ref', 'seller-b', true), set_config('billium.environment', 'test', true)", [self::INSTALLATION]);

                return Rows::int($runtime->scalar('SELECT count(*) FROM public.billium_feed_subscription'));
            });
            $sellerBPins = $runtime->transaction(static function () use ($runtime): int {
                $runtime->selectOne("SELECT set_config('billium.installation_ref', ?, true), set_config('billium.seller_ref', 'seller-b', true), set_config('billium.environment', 'test', true)", [self::INSTALLATION]);

                return Rows::int($runtime->scalar('SELECT count(*) FROM public.billium_feed_bootstrap_lease'));
            });
            self::assertSame(1, $sellerASubs);
            self::assertSame(0, $sellerBSubs);
            self::assertSame(0, $sellerBPins);

            // 5. INSERT with foreign seller_ref is blocked by WITH CHECK (42501)
            try {
                $runtime->transaction(static function () use ($runtime): void {
                    $runtime->selectOne("SELECT set_config('billium.installation_ref', ?, true), set_config('billium.seller_ref', 'seller-a', true), set_config('billium.environment', 'test', true)", [self::INSTALLATION]);
                    $runtime->insert(
                        "INSERT INTO public.billium_feed_command_lease(lease_key,installation_ref,seller_ref,environment,lease_token,lease_epoch,owner_ref,lease_until) VALUES (?,'inst','seller-b','test',?,1,'x',clock_timestamp() + interval '5 seconds')",
                        ['sha256:'.str_repeat('c', 64), str_repeat('a', 48)],
                    );
                });
                self::fail('Expected 42501 on foreign seller_ref INSERT under WITH CHECK');
            } catch (QueryException $e) {
                self::assertSame('42501', $e->getCode());
            }
        } finally {
            $owner->unprepared(<<<'SQL'
                DROP POLICY IF EXISTS billium_feed_command_lease_seller_scope ON public.billium_feed_command_lease;
                ALTER TABLE public.billium_feed_command_lease DISABLE ROW LEVEL SECURITY;
                DROP POLICY IF EXISTS billium_usage_metric_seller_scope ON public.billium_usage_metric;
                ALTER TABLE public.billium_usage_metric DISABLE ROW LEVEL SECURITY;
                DROP POLICY IF EXISTS billium_usage_fact_seller_scope ON public.billium_usage_fact;
                ALTER TABLE public.billium_usage_fact DISABLE ROW LEVEL SECURITY;
                DROP POLICY IF EXISTS billium_idempotency_receipt_seller_scope ON public.billium_idempotency_receipt;
                ALTER TABLE public.billium_idempotency_receipt DISABLE ROW LEVEL SECURITY;
                DROP POLICY IF EXISTS billium_installation_state_scope ON public.billium_installation_state;
                ALTER TABLE public.billium_installation_state DISABLE ROW LEVEL SECURITY;
                DROP POLICY IF EXISTS billium_feed_subscription_seller_scope ON public.billium_feed_subscription;
                ALTER TABLE public.billium_feed_subscription DISABLE ROW LEVEL SECURITY;
                DROP POLICY IF EXISTS billium_feed_bootstrap_lease_seller_scope ON public.billium_feed_bootstrap_lease;
                ALTER TABLE public.billium_feed_bootstrap_lease DISABLE ROW LEVEL SECURITY;
                SQL);
        }
    }
}

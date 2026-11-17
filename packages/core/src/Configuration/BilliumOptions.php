<?php

declare(strict_types=1);

namespace Billium\Core\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable closed-schema compiled configuration snapshot for `axiomasoft/billium-core`.
 */
#[Api(since: '1.0')]
final readonly class BilliumOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'connection',
        'installation_ref',
        'runner',
        'financial_session',
        'idempotency',
        'outbox',
        'feed',
        'inbox',
        'runtime_role',
        'ledger',
        'modules',
        'usage',
        'rating',
        'schedule',
    ];

    public function __construct(
        public ?string $connection = null,
        public ?string $installationRef = null,
        public RunnerOptions $runner = new RunnerOptions,
        public FinancialSessionOptions $financialSession = new FinancialSessionOptions,
        public IdempotencyOptions $idempotency = new IdempotencyOptions,
        public OutboxOptions $outbox = new OutboxOptions,
        public FeedOptions $feed = new FeedOptions,
        public InboxOptions $inbox = new InboxOptions,
        public RuntimeRoleOptions $runtimeRole = new RuntimeRoleOptions,
        public LedgerOptions $ledger = new LedgerOptions,
        public ModulesOptions $modules = new ModulesOptions,
        public OptionalModuleOptions $usage = new OptionalModuleOptions,
        public OptionalModuleOptions $rating = new OptionalModuleOptions,
        public OptionalModuleOptions $schedule = new OptionalModuleOptions,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'billium'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $connection = self::optionalNonEmptyString($config['connection'] ?? null, $path.'.connection', $validator);
        $installationRef = self::optionalNonEmptyString($config['installation_ref'] ?? null, $path.'.installation_ref', $validator, treatEmptyAsNull: true);

        $runner = RunnerOptions::fromSection($config['runner'] ?? [], $path.'.runner', $validator);
        $financialSession = FinancialSessionOptions::fromSection($config['financial_session'] ?? [], $path.'.financial_session', $validator);
        $idempotency = IdempotencyOptions::fromSection($config['idempotency'] ?? [], $path.'.idempotency', $validator);
        $outbox = OutboxOptions::fromSection($config['outbox'] ?? [], $path.'.outbox', $validator);
        $feed = FeedOptions::fromSection($config['feed'] ?? [], $path.'.feed', $validator);
        $inbox = InboxOptions::fromSection($config['inbox'] ?? [], $path.'.inbox', $validator);
        $runtimeRole = RuntimeRoleOptions::fromSection($config['runtime_role'] ?? [], $path.'.runtime_role', $validator);
        $ledger = LedgerOptions::fromSection($config['ledger'] ?? [], $path.'.ledger', $validator);
        $modules = ModulesOptions::fromSection($config['modules'] ?? [], $path.'.modules', $validator);
        $usage = OptionalModuleOptions::fromSection($config['usage'] ?? [], $path.'.usage', $validator);
        $rating = OptionalModuleOptions::fromSection($config['rating'] ?? [], $path.'.rating', $validator);
        $schedule = OptionalModuleOptions::fromSection($config['schedule'] ?? [], $path.'.schedule', $validator);

        $validator->failIfAny();

        return new self(
            connection: $connection,
            installationRef: $installationRef,
            runner: $runner,
            financialSession: $financialSession,
            idempotency: $idempotency,
            outbox: $outbox,
            feed: $feed,
            inbox: $inbox,
            runtimeRole: $runtimeRole,
            ledger: $ledger,
            modules: $modules,
            usage: $usage,
            rating: $rating,
            schedule: $schedule,
        );
    }

    public function connectionName(string $defaultConnection): string
    {
        return $this->connection ?? $defaultConnection;
    }

    private static function optionalNonEmptyString(
        mixed $value,
        string $path,
        ConfigurationValidator $validator,
        bool $treatEmptyAsNull = false,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $validator->add($path, 'must be a string or null');

            return null;
        }

        if ($treatEmptyAsNull && trim($value) === '') {
            return null;
        }

        if (trim($value) === '') {
            $validator->add($path, 'must be a non-empty string or null');

            return null;
        }

        return $value;
    }
}

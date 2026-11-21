<?php

declare(strict_types=1);

namespace BilliumMinimalHost;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Billium\Server\Contracts\Authenticator;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Contracts\InstallationContextResolver;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Contracts\SellerEligibility;
use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\InstallationContext;
use Billium\Server\Data\RequestMeta;
use Billium\Server\Data\ResourceScope;
use Billium\Server\Data\SellerAdmission;
use Billium\Server\Data\SellerScope;
use Billium\Server\Data\SourceAuthorityGrant;
use Billium\Server\Enums\Verdict;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Symfony\Component\HttpFoundation\Response;

/** Throwaway test host. The fixture policy issues no process or deployment authority. */
final class Host implements Authenticator, AuthorizationVerdict, FinancialAuthorityEvidence, FinancialSessionAdmission, SellerContextResolver, SellerEligibility
{
    public const string SELLER = 'seller-acme';

    public const string INSTALLATION = 'inst-eu-1';

    public function __construct(private readonly string $token) {}

    /**
     * @param  list<string>  $modules
     * @param  list<class-string<ServiceProvider>>  $providers  Empty to use package discovery.
     */
    public function application(string $basePath, string $database, string $profile = 'ledger-only', array $modules = ['platform', 'ledger'], array $providers = []): Application
    {
        $app = Application::configure(basePath: $basePath)->withExceptions()->create();
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($database, $profile, $modules, $providers): void {
            $config = $app->make(Repository::class);
            $config->set('app.providers', array_merge(ServiceProvider::defaultProviders()->toArray(), $providers));
            $config->set('app.key', str_repeat('fixture-key-', 4));
            $config->set('database.default', 'unused_default');
            $config->set('database.connections.unused_default', Database::connection('unused_default_test'));
            $config->set('database.connections.server_runtime', Database::connection($database));
            $config->set('database.connections.server_owner', Database::connection($database, owner: true));
            $config->set('billium.connection', 'server_runtime');
            $config->set('billium.installation_ref', self::INSTALLATION);
            $config->set('billium.modules.composition', $profile);
            $config->set('billium.modules.enabled', $modules);
            foreach (['usage', 'rating', 'schedule', 'documents', 'collection'] as $module) {
                $config->set("billium.{$module}.enabled", in_array($module, $modules, true));
            }
        });

        foreach ([Authenticator::class, SellerContextResolver::class, SellerEligibility::class, AuthorizationVerdict::class,
            FinancialSessionAdmission::class, FinancialAuthorityEvidence::class] as $contract) {
            $app->instance($contract, $this);
        }
        $app->instance(InstallationContextResolver::class, new class implements InstallationContextResolver
        {
            public function resolve(AuthenticatedPrincipal $principal): InstallationContext
            {
                return new InstallationContext(Host::INSTALLATION, 'test');
            }
        });

        return $app;
    }

    /** @param list<string> $modules */
    public static function migrate(Application $app, array $modules): void
    {
        $sets = array_map(static fn (string $module): string => 'axiomasoft/billium-core:billium-'.$module, $modules);
        $app->make(SchemaMigrator::class)->migrateSets($sets, 'server_owner');
    }

    public function request(Application $app, string $path, ?string $json = null, ?string $token = null): Response
    {
        $request = Request::create($path, $json === null ? 'GET' : 'POST', server: [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token),
        ], content: $json);
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    public function authenticate(array $headers, RequestMeta $requestMeta): AuthenticatedPrincipal
    {
        $bearer = $headers['authorization'][0] ?? '';
        if (! hash_equals('Bearer '.$this->token, $bearer)) {
            throw new ProblemException(ProblemCode::AuthUnauthenticated);
        }

        return new AuthenticatedPrincipal('host-fixture');
    }

    public function resolve(AuthenticatedPrincipal $principal, string $routeSeller): ExecutionContext
    {
        if ($routeSeller !== self::SELLER) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }

        return new ExecutionContext(self::INSTALLATION, 'test', self::SELLER, $principal->principalRef,
            capabilities: ['payment.observation.v1', 'billium.query.v1', 'payment.execute.v1'],
            sourceAuthorities: [
                new SourceAuthorityGrant('payment.observation', 'embedded-commerz', self::INSTALLATION, 'test', self::SELLER, 'fixture-lineage', ['fixture-partition']),
                new SourceAuthorityGrant('payment.observation', 'remote-billium', self::INSTALLATION, 'test', self::SELLER, 'fixture-lineage', ['fixture-partition']),
            ],
            intentOwnerNamespace: 'fixture-intents',
            executionAuthorities: ['remote-billium']);
    }

    public function admission(SellerScope $scope): ?SellerAdmission
    {
        return $scope->matches(new SellerScope(self::INSTALLATION, 'test', self::SELLER)) ? new SellerAdmission($scope, true) : null;
    }

    public function authorize(AuthenticatedPrincipal $principal, string $action, ResourceScope $resourceScope, string $payloadDigest): Verdict
    {
        return in_array($action, ['ledger.query', 'payment.observe'], true) ? Verdict::Allow : Verdict::Deny;
    }

    public function currentToken(string $installationRef): ?FinancialAdmissionToken
    {
        return null;
    }

    public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
    {
        return null;
    }

    public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
    {
        return null;
    }
}

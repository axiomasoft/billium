<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Contracts\SpecRevision;
use Billium\Contracts\Data\Envelopes\Capabilities;
use Billium\Contracts\Data\Envelopes\CapabilitiesV2;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runtime\ContractCapabilities;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CapabilitiesController
{
    public function __construct(
        private Application $app,
        private Router $router,
        private RequestAccess $access,
        private EnvelopeResponse $response,
        private HttpLimits $limits,
        private BilliumOptions $options,
        private ModuleRegistry $modules,
        private ContractCapabilities $capabilities,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->buildCapabilities($request, v2: false);
    }

    public function showV2(Request $request): JsonResponse
    {
        return $this->buildCapabilities($request, v2: true);
    }

    private function buildCapabilities(Request $request, bool $v2): JsonResponse
    {
        try {
            $installation = $this->access->installation($request);
        } catch (ProblemException $refusal) {
            return ProblemResponse::from($refusal->problem);
        }

        $routes = array_filter(HttpContract::ROUTES, function (string $name) use ($v2): bool {
            if (! $v2 && in_array($name, ['billium.server.capabilities.v2', 'billium.server.usage.accept.v2'], true)) {
                return false;
            }
            $port = ModuleCommandsController::HOST_INPUTS[$name] ?? null;

            return $this->router->getRoutes()->hasNamedRoute($name) && ($port === null || $this->app->bound($port));
        }, ARRAY_FILTER_USE_KEY);
        $commands = [];
        $responses = [];
        foreach ($routes as $route) {
            if ($route['request'] !== null) {
                $commands[] = $route['request'];
            }
            $responses = [...$responses, ...$route['responses']];
        }
        $sale = isset($routes['billium.server.execution.execute']);

        $modules = [];
        $versions = [];
        $catalogs = [];
        foreach ($this->modules->manifest()->modules as $module) {
            if (! $this->options->moduleEnabled($module->moduleId)) {
                continue;
            }
            $modules[] = ['module' => $module->moduleId, 'apiVersion' => $module->moduleApiVersion];
            $versions[$module->moduleId] = $module->moduleApiVersion;
            foreach ($module->publicEvents as $event) {
                $key = $event->eventContractRevision.':'.$event->envelopeSchema;
                $catalogs[$key] ??= ['eventContractRevision' => $event->eventContractRevision, 'envelopeSchema' => $event->envelopeSchema, 'eventTypes' => []];
                $catalogs[$key]['eventTypes'][] = $event->eventType;
            }
        }

        $body = [
            'schema' => $v2 ? CapabilitiesV2::SCHEMA : Capabilities::SCHEMA,
            'installationRef' => $installation->installationRef,
            'environment' => $installation->environment,
            'specRevision' => $v2 ? SpecRevision::CURRENT : '2026-10',
            'specHash' => $v2 ? SpecRevision::HASHES[SpecRevision::CURRENT] : HttpContract::SPEC_HASH,
            'serverApiVersion' => '1',
            'acceptedCommandSchemas' => array_values(array_unique($commands)),
            'producedResponseSchemas' => array_values(array_unique($responses)),
            'eventSchemas' => array_values(array_unique(array_column($catalogs, 'envelopeSchema'))),
            'enabledModules' => $modules,
            'moduleApiVersions' => (object) $versions,
            'features' => [
                'charge' => $sale,
                'refund' => isset($routes['billium.server.execution.refund']),
                'find' => isset($routes['billium.server.execution.find']),
                'receipt' => isset($routes['billium.server.receipts.find']),
                'document' => false,
                'execution' => $sale,
            ],
            'effects' => [
                'sale' => $sale,
                'authorize' => isset($routes['billium.server.execution.authorize']),
                'capture' => isset($routes['billium.server.execution.capture']),
                'voidAuthorization' => isset($routes['billium.server.execution.void-authorization']),
                'cancel' => isset($routes['billium.server.execution.cancel']),
            ],
            'limits' => ['maxBodyBytes' => $this->limits->maxBodyBytes, 'maxDepth' => $this->limits->maxDepth],
            'lookupCompletenessPolicyRef' => 'BC-ENV-16',
            'catalogs' => array_values($catalogs),
            'selectionMode' => 'explicit',
        ];
        if ($v2) {
            $body['supportedSpecRevisions'] = array_map(
                static fn (string $rev): array => ['revision' => $rev, 'specHash' => SpecRevision::HASHES[$rev]],
                SpecRevision::SUPPORTED,
            );
            $body['supportedUnits'] = $this->capabilities->supportedUnits();
            $body['usageIngressSchemas'] = isset($routes['billium.server.usage.accept.v2']) ? ['usage.fact.v2'] : [];
            $strategies = ['fixed@1', 'per_unit@1', 'graduated@1', 'volume@1'];
            if ($this->capabilities->supportsRatingStrategy('per_unit', 2)) {
                $strategies[] = 'per_unit@2';
            }
            $body['ratingStrategyVersions'] = $this->capabilities->isModuleEnabled('rating') ? $strategies : [];
        }

        return $this->response->fromBody($body, digest: true);
    }
}

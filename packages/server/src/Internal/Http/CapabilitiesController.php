<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Http;

use Billium\Contracts\Contracts\SpecRevision;
use Billium\Contracts\Data\Envelopes\Capabilities;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
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
    ) {}

    public function show(Request $request): JsonResponse
    {
        try {
            $installation = $this->access->installation($request);
        } catch (ProblemException $refusal) {
            return ProblemResponse::from($refusal->problem);
        }

        $routes = array_filter(HttpContract::ROUTES, function (string $name): bool {
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

        return $this->response->fromBody([
            'schema' => Capabilities::SCHEMA,
            'installationRef' => $installation->installationRef,
            'environment' => $installation->environment,
            'specRevision' => SpecRevision::CURRENT,
            'specHash' => HttpContract::SPEC_HASH,
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
        ], digest: true);
    }
}

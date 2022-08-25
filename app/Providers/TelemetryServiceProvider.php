<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\OpenTelemetry\JaegerPropagator;
use Event;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Contrib\OtlpGrpc\Exporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

class TelemetryServiceProvider extends ServiceProvider
{
    /**
     * Keeping a reference to the TracerProvider prevents from causing the ShutdownHandler
     * to immediately call ShutdownHandler::__destruct.
     * It will call `ShutdownHandler::__destruct` if there is no reference to the TracerProvider.
     *
     * @var \OpenTelemetry\SDK\Trace\TracerProvider
     */
    private TracerProvider $tracer_provider;

    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap services.
     *
     * @return void
     * @throws \Safe\Exceptions\InfoException
     */
    public function boot(): void
    {
        $sampler = new AlwaysOnSampler();

        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=otel-collector:4317');
        $exporter = new Exporter();

        $resource_info = ResourceInfo::create(Attributes::create([
            'env' => $this->app->environment(),
            ResourceAttributes::SERVICE_NAME => 'test-demo',
        ]));

        $processor = new SimpleSpanProcessor($exporter);
        $this->tracer_provider = new TracerProvider($processor, $sampler, $resource_info);
        $tracer = $this->tracer_provider->getTracer('io.opentelemetry.contrib.php');

        // Manually register `register_shutdown_function`
        ShutdownHandler::register([$this->tracer_provider, 'shutdown']);

        $this->app->instance('opentelmetry.tracer', $tracer);
        $trace_context = $this->extractTraceContext(request()->headers->all());
        $root_span = $tracer->spanBuilder('root span')
            ->setParent($trace_context)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $root_span->activate();

        // Listen for the request handled event and set more attributes for the trace
        Event::listen(RequestHandled::class, function (RequestHandled $event) use ($root_span): void {
            $route = $event->request->route();
            $route_name = 'unknown route';

            if ($route !== null && $route->getName() !== null) {
                $root_span->updateName($route->getName());
                $route_name = $route->getName();
            }

            $attributes = [
                'http.method' => $event->request->method(),
                'http.route' => $route_name,
                'http.target' => $event->request->getRequestUri(),
                'http.host' => $event->request->getHost(),
                'http.user_agent' => $event->request->userAgent(),
                'http.scheme' => $event->request->getScheme(),
                'http.status_code' => $event->response->getStatusCode(),
                'http.client_ip' => $event->request->ip(),
                // non-standard attributes
                'http.referer' => $event->request->headers->get('referer'),
            ];

            foreach ($attributes as $key => $val) {
                if ($val !== null) {
                    $root_span->setAttribute($key, $val);
                }
            }
        });

        $root_span->setStatus(StatusCode::STATUS_OK);

        app()->terminating(function () use ($root_span): void {
            $root_span->end();
        });
    }

    /**
     * Extract trace-context from the jaeger or the W3C Trace Context(OTLP) that is a propagator
     *
     * @param array<int|string,array<int,string|null>|string|null> $header
     *
     * @throws \Safe\Exceptions\StringsException
     *
     * @return \OpenTelemetry\Context\Context
     */
    protected function extractTraceContext(array $header): Context
    {
        $array_access = ArrayAccessGetterSetter::getInstance();
        $trace_id     = $array_access->get($header, JaegerPropagator::TRACE_ID_KEY);

        if ($trace_id !== null) {
            return (new JaegerPropagator())->extract([JaegerPropagator::TRACE_ID_KEY => $trace_id], $array_access);
        }

        return TraceContextPropagator::getInstance()->extract($header, $array_access);
    }
}

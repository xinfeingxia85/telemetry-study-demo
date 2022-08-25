<?php

declare(strict_types=1);

namespace App\Support\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\Span;

/**
 * The propagator supports the jaeger format and convert this format to the W3C Trace context.
 * (https://www.jaegertracing.io/docs/1.25/client-libraries/#propagation-format)
 */
class JaegerPropagator implements TextMapPropagatorInterface
{
    public const TRACE_ID_KEY = 'uber-trace-id';

    /**
     *{@inheritdoc}
     *
     * @return string[]
     */
    public function fields(): array
    {
        return [self::TRACE_ID_KEY];
    }

    /**
     *{@inheritdoc}
     */
    public function inject(&$carrier, ?PropagationSetterInterface $setter = null, ?Context $context = null): void
    {
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $carrier
     */
    public function extract($carrier, ?PropagationGetterInterface $getter = null, ?Context $context = null): Context
    {
        $getter  ??= ArrayAccessGetterSetter::getInstance();
        $context ??= Context::getCurrent();

        $header = $getter->get($carrier, self::TRACE_ID_KEY);

        if ($header === null) {
            return $context;
        }

        // Jaeger propagator format: {trace-id}:{span-id}:{parent-span-id}:{flags}
        $pieces = explode(':', $header);

        // Unable to extract propagator for jaeger format. Expected 4 values
        if (count($pieces) !== 4) {
            return $context;
        }

        $trace_id = $pieces[0];
        $span_id  = $pieces[1];

        return $context->withContextValue(Span::wrap(SpanContext::createFromRemoteParent($trace_id, $span_id)));
    }
}

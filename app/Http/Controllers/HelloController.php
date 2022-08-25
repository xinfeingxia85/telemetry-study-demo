<?php

namespace App\Http\Controllers;

use App\Support\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;

class HelloController extends Controller
{
    public function index(): string
    {
        $span = Tracer::spanBuilder("span1")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $scope = $span->activate();
        sleep(10);
        $span->end();
        $scope->detach();

        return 'Hello';
    }
}

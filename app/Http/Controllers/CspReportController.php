<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class CspReportController extends Controller
{
    /**
     * Sink for Content-Security-Policy violation reports (`report-uri`).
     * Browsers POST a JSON body (Content-Type: application/csp-report)
     * whenever a directive blocks something. F13 flipped the policy from
     * Report-Only to enforcing; this endpoint keeps logging violations post-flip
     * so regressions are caught. Public + CSRF-exempt (the browser sends
     * no token) and throttled at the route to bound log volume.
     */
    public function store(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $report = is_array($payload) ? ($payload['csp-report'] ?? $payload) : null;

        Log::warning('CSP violation report', [
            'report' => $report,
            'ua' => $request->userAgent(),
        ]);

        return response()->noContent();
    }
}

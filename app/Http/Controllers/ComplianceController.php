<?php

namespace App\Http\Controllers;

use App\Services\ComplianceQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Org-wide compliance roll-ups, pivoted by training or requirement. Manager+
 * gated (mirrors the dashboard widgets). The Inertia shell ships no data; each
 * tab streams a paginated {data, meta} page from its JSON endpoint via the
 * compliance Pinia store + useServerTable. All aggregation lives in
 * ComplianceQuery (the single seam over the materialized status).
 */
class ComplianceController extends Controller
{
    private const MANAGER_PLUS_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    public function __construct(private readonly ComplianceQuery $compliance) {}

    public function index(Request $request): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('compliance/Index');
    }

    public function byTraining(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        return response()->json(
            $this->compliance->byTraining($request->user()->organization, $this->opts($request)),
        );
    }

    public function byRequirement(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        return response()->json(
            $this->compliance->byRequirement($request->user()->organization, $this->opts($request)),
        );
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(self::MANAGER_PLUS_ROLES),
            403,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function opts(Request $request): array
    {
        return [
            'q' => $request->query('q'),
            'sort' => $request->query('sort'),
            'dir' => $request->query('dir'),
            'page' => $request->query('page'),
            'per_page' => $request->query('per_page'),
        ];
    }
}

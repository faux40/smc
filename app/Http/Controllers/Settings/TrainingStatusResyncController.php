<?php

namespace App\Http\Controllers\Settings;

use App\Actions\RecalculateTrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingStatusResyncController extends Controller
{
    private const ADMIN_PLUS_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    public function __invoke(Request $request, RecalculateTrainingStatus $action): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(self::ADMIN_PLUS_ROLES),
            403,
        );

        $org = Organization::findOrFail($request->user()->org_id);

        return response()->json($action->handleAll($org->id));
    }
}

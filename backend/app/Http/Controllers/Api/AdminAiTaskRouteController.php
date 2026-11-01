<?php

namespace App\Http\Controllers\Api;

use App\AI\MobileDiagnosisAdmission;
use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ADMIN-04/05: token-authenticated API counterpart to the real web model
 * registry and diagnosis route editor. Never runs an inference or exposes
 * provider endpoints/credentials/remaining-credit guesses.
 */
final class AdminAiTaskRouteController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        abort_unless($user?->canAccessAdmin()
            && $token instanceof PersonalAccessToken
            && $token->can('admin'), 403);
    }

    private function onlyFields(Request $request, array $fields): void
    {
        if (array_diff(array_keys($request->all()), $fields) !== []) {
            throw ValidationException::withMessages([
                'configuration' => 'Unsupported model or task configuration fields.',
            ]);
        }
    }

    private static function modelData(AiModel $model): array
    {
        return [
            'id' => $model->id,
            'provider_id' => $model->provider_id,
            'provider_display_name' => $model->provider?->display_name,
            'provider_model_id' => $model->provider_model_id,
            'enabled' => $model->enabled,
            'capabilities' => $model->capabilities_json ?? [],
            'cost_currency' => $model->cost_policy_json['currency'] ?? null,
        ];
    }

    private static function routeData(?AiRoute $route): ?array
    {
        if ($route === null) {
            return null;
        }
        return [
            'id' => $route->id,
            'task_type' => $route->task_type,
            'primary_model_id' => $route->primary_model_id,
            'fallback_model_ids' => $route->fallback_model_ids_json ?? [],
            'budget' => $route->budget_json ?? [],
            'rate_limits' => $route->rate_limits_json ?? [],
        ];
    }

    private function configResponse(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'configuration_only' => true,
                'no_live_eligibility_or_remaining_budget_claim' => true,
            ],
        ], $status)->header('Cache-Control', 'no-store');
    }

    public function models(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'provider_id' => ['sometimes', 'uuid'],
        ]);
        $query = AiModel::query()->whereHas('provider')
            ->with('provider:id,display_name')
            ->when(isset($input['provider_id']), static fn ($q) => $q
                ->where('provider_id', $input['provider_id']))
            ->orderBy('provider_id')->orderBy('provider_model_id')->orderBy('id');
        $page = $query->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(self::modelData(...))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'configuration_only' => true,
                'no_live_eligibility_or_remaining_budget_claim' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function createModel(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->onlyFields($request, [
            'provider_id', 'provider_model_id', 'enabled', 'supports_text',
        ]);
        $input = $request->validate([
            'provider_id' => ['required', 'uuid',
                Rule::exists('ai_providers', 'id')->whereNull('deleted_at')],
            'provider_model_id' => ['required', 'string',
                'regex:/\A[A-Za-z0-9_.:\-]{1,90}\z/D'],
            'enabled' => ['required', 'boolean'],
            'supports_text' => ['required', 'boolean'],
        ]);
        $provider = AiProvider::query()->findOrFail($input['provider_id']);
        $request->validate(['provider_model_id' => [
            Rule::unique('ai_models', 'provider_model_id')
                ->where('provider_id', $provider->id),
        ]]);

        $model = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => $input['provider_model_id'],
            'enabled' => (bool) $input['enabled'],
            'capabilities_json' => $input['supports_text'] ? ['text'] : [],
            'cost_policy_json' => $provider->provider_type === 'openai-compatible'
                ? ['currency' => $provider->currency] : [],
        ]);
        $model->setRelation('provider', $provider);

        return $this->configResponse(self::modelData($model), 201);
    }

    public function updateModel(Request $request, AiModel $model): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->onlyFields($request, ['enabled']);
        $input = $request->validate(['enabled' => ['required', 'boolean']]);
        abort_if($model->provider === null, 404);
        $model->forceFill(['enabled' => (bool) $input['enabled']])->save();
        return $this->configResponse(self::modelData($model));
    }

    public function diagnosisRoute(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $route = AiRoute::query()
            ->where('task_type', MobileDiagnosisAdmission::TASK_TYPE)->first();
        $selected = app(MobileDiagnosisAdmission::class)->select();
        return $this->configResponse([
            'route' => self::routeData($route),
            'eligible_model' => $selected === null ? null : [
                'model_id' => $selected['model']->id,
                'provider_id' => $selected['provider']->id,
                'provider_model_id' => $selected['model']->provider_model_id,
                'reserved_budget_minor' => $selected['reserve_minor'],
            ],
            'eligibility_is_only_a_recorded_state_preview' => true,
        ]);
    }

    public function saveDiagnosisRoute(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->onlyFields($request, [
            'primary_model_id', 'fallback_model_ids', 'currency',
            'reservation_minor', 'per_minute', 'per_day',
        ]);
        $input = $request->validate([
            'primary_model_id' => ['required', 'uuid'],
            'fallback_model_ids' => ['sometimes', 'array', 'max:8'],
            'fallback_model_ids.*' => ['required', 'uuid', 'distinct'],
            'currency' => ['required', 'in:USD,KWD,EUR'],
            'reservation_minor' => ['required', 'integer', 'between:0,100000000'],
            'per_minute' => ['required', 'integer', 'between:1,60'],
            'per_day' => ['required', 'integer', 'between:1,10000'],
        ]);
        if ($input['per_day'] < $input['per_minute']) {
            throw ValidationException::withMessages([
                'per_day' => 'The daily limit must be at least the minute limit.',
            ]);
        }
        $fallbacks = $input['fallback_model_ids'] ?? [];
        $ids = [$input['primary_model_id'], ...$fallbacks];
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'fallback_model_ids' => 'Primary and fallback models must be distinct.',
            ]);
        }
        $models = AiModel::query()->with('provider')
            ->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $model = $models->get($id);
            $provider = $model?->provider;
            if ($model === null || $provider === null
                || ! $model->enabled
                || ! in_array('text', $model->capabilities_json ?? [], true)) {
                throw ValidationException::withMessages([
                    'primary_model_id' => 'Use existing enabled text-capable models with active providers.',
                ]);
            }
            if ($provider->provider_type === 'openai-compatible'
                && ($input['reservation_minor'] < 1
                    || $input['currency'] !== $provider->currency
                    || ($model->cost_policy_json['currency'] ?? null) !== $provider->currency)) {
                throw ValidationException::withMessages([
                    'currency' => 'Paid model, provider and task currency must match with a positive reservation.',
                ]);
            }
        }

        $route = DB::transaction(static function () use ($input, $fallbacks): AiRoute {
            $route = AiRoute::query()
                ->where('task_type', MobileDiagnosisAdmission::TASK_TYPE)
                ->lockForUpdate()->first() ?? new AiRoute([
                    'task_type' => MobileDiagnosisAdmission::TASK_TYPE,
                ]);
            $route->fill([
                'primary_model_id' => $input['primary_model_id'],
                'fallback_model_ids_json' => array_values($fallbacks),
                'budget_json' => [
                    'currency' => $input['currency'],
                    'reservation_minor' => (int) $input['reservation_minor'],
                ],
                'rate_limits_json' => [
                    'per_minute' => (int) $input['per_minute'],
                    'per_day' => (int) $input['per_day'],
                ],
            ])->save();
            return $route;
        }, 3);

        return $this->configResponse([
            'route' => self::routeData($route),
            'eligible_model' => null,
            'eligibility_is_only_a_recorded_state_preview' => true,
        ]);
    }
}

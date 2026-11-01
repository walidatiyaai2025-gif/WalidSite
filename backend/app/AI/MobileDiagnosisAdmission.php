<?php

namespace App\AI;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MobileDiagnosisAdmission
{
    public const TASK_TYPE = 'mobile-ai-diagnose';

    /**
     * @return array{route:AiRoute,model:AiModel,provider:AiProvider,reserve_minor:int,per_minute:int,per_day:int}|null
     */
    public function select(): ?array
    {
        $route = AiRoute::query()->where('task_type', self::TASK_TYPE)->first();
        if ($route === null) {
            return null;
        }

        $budget = $route->budget_json;
        $limits = $route->rate_limits_json;
        if (! is_array($budget) || ! is_array($limits)) {
            return null;
        }
        $configuredReserve = $budget['reservation_minor'] ?? null;
        $perMinute = $limits['per_minute'] ?? null;
        $perDay = $limits['per_day'] ?? null;
        if (! is_int($configuredReserve) || $configuredReserve < 0 || $configuredReserve > 100000000
            || ! is_int($perMinute) || $perMinute < 1 || $perMinute > 60
            || ! is_int($perDay) || $perDay < $perMinute || $perDay > 10000) {
            return null;
        }

        $fallbacks = $route->fallback_model_ids_json;
        if (! is_array($fallbacks) || count($fallbacks) > 8) {
            return null;
        }
        $ids = [$route->primary_model_id];
        foreach ($fallbacks as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                return null;
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));

        foreach ($ids as $id) {
            $model = AiModel::query()->with('provider')->find($id);
            if ($model === null || ! $model->enabled
                || ! is_array($model->capabilities_json)
                || ! in_array('text', $model->capabilities_json, true)) {
                continue;
            }
            $provider = $model->provider;
            if ($provider === null || ! $provider->enabled || $provider->health_status !== 'healthy'
                || ! in_array($provider->provider_type, ['ollama-compatible', 'openai-compatible'], true)
                || $provider->concurrent_limit < 1) {
                continue;
            }
            if ($provider->provider_type === 'openai-compatible'
                && (! is_string($provider->secret_ciphertext) || trim($provider->secret_ciphertext) === '')) {
                continue;
            }

            $circuit = DB::table('ai_provider_circuits')
                ->where('provider_id', $provider->id)->first();
            if ($circuit?->opened_until
                && \Illuminate\Support\Carbon::parse($circuit->opened_until)->isFuture()) {
                continue;
            }

            $running = DB::table('ai_jobs')->where('provider_id', $provider->id)
                ->where('status', 'running')->count();
            if ($running >= $provider->concurrent_limit) {
                continue;
            }

            $reserve = $provider->provider_type === 'openai-compatible' ? $configuredReserve : 0;
            if ($provider->provider_type === 'openai-compatible' && $reserve < 1) {
                continue;
            }
            $spent = (int) DB::table('ai_usage')->where('provider_id', $provider->id)
                ->where('created_at', '>=', now()->startOfDay())->sum('cost_minor');
            $outstanding = 0;
            $runningJobs = DB::table('ai_jobs')->where('provider_id', $provider->id)
                ->where('status', 'running')->get(['id', 'reserved_budget_minor']);
            foreach ($runningJobs as $job) {
                $recorded = (int) DB::table('ai_usage')->where('job_id', $job->id)->sum('cost_minor');
                $outstanding += max(0, (int) $job->reserved_budget_minor - $recorded);
            }
            if ($spent + $outstanding + $reserve > $provider->daily_budget_minor) {
                continue;
            }

            return [
                'route' => $route,
                'model' => $model,
                'provider' => $provider,
                'reserve_minor' => $reserve,
                'per_minute' => $perMinute,
                'per_day' => $perDay,
            ];
        }

        return null;
    }

    public function available(): bool
    {
        return $this->select() !== null;
    }
}

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
            if ($provider->provider_type === 'openai-compatible') {
                // A reserved amount is in the provider's configured minor
                // units. Model pricing and explicit route currency must agree
                // before the job is admitted; do not silently convert FX or
                // use missing price policy as a zero-cost paid model.
                $costPolicy = $model->cost_policy_json;
                $modelCurrency = is_array($costPolicy) ? ($costPolicy['currency'] ?? null) : null;
                $routeCurrency = $budget['currency'] ?? null;
                if (! is_string($modelCurrency) || $modelCurrency !== $provider->currency
                    || ($routeCurrency !== null && $routeCurrency !== $provider->currency)
                    || ! is_string($provider->secret_ciphertext)
                    || trim($provider->secret_ciphertext) === '') {
                    continue;
                }
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
            // Minor units cannot be added across currencies. Legacy or
            // externally imported receipts may predate recordUsage() currency
            // validation. Never admit paid work on a mixed-currency ledger.
            $todayReceipts = DB::table('ai_usage')->where('provider_id', $provider->id)
                ->where('created_at', '>=', now('UTC')->startOfDay());
            if ((clone $todayReceipts)->where('cost_currency', '!=', $provider->currency)->exists()) {
                continue;
            }
            $spent = (int) $todayReceipts->sum('cost_minor');
            // A queued or retrying job already holds a real budget commitment.
            // Counting only running jobs admits more paid requests than the
            // provider can afford and strands the accepted queue indefinitely.
            // Terminal jobs release unspent reservations; recorded UTC-day
            // usage remains charged above. Prior-day receipts for a still
            // pending job reduce its *lifetime* outstanding liability only.
            $pendingStatuses = ['queued', 'retry', 'running'];
            $foreignPendingReceipt = DB::table('ai_usage as receipt')
                ->join('ai_jobs as pending', 'pending.id', '=', 'receipt.job_id')
                ->where('pending.provider_id', $provider->id)
                ->whereIn('pending.status', $pendingStatuses)
                ->where('receipt.cost_currency', '!=', $provider->currency)
                ->exists();
            if ($foreignPendingReceipt) {
                continue;
            }

            // One grouped SQL aggregate instead of an unbounded per-job
            // query loop; CASE works in MariaDB and SQLite test databases.
            $recordedByJob = DB::table('ai_usage')
                ->select('job_id')
                ->selectRaw('SUM(cost_minor) AS recorded_minor')
                ->groupBy('job_id');
            $outstanding = (int) DB::table('ai_jobs as pending')
                ->leftJoinSub($recordedByJob, 'ledger', static function ($join): void {
                    $join->on('ledger.job_id', '=', 'pending.id');
                })
                ->where('pending.provider_id', $provider->id)
                ->whereIn('pending.status', $pendingStatuses)
                ->selectRaw('COALESCE(SUM(CASE WHEN pending.reserved_budget_minor > COALESCE(ledger.recorded_minor, 0) THEN pending.reserved_budget_minor - COALESCE(ledger.recorded_minor, 0) ELSE 0 END), 0) AS outstanding_minor')
                ->value('outstanding_minor');
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

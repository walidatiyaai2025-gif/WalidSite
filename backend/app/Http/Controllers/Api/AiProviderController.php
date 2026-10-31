<?php

namespace App\Http\Controllers\Api;

use App\AI\Adapters\OllamaAdapter;
use App\AI\Adapters\OpenAiCompatibleAdapter;
use App\AI\ProviderEndpoint;
use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

class AiProviderController extends Controller
{
    /** API requires admin token; only named CSRF-protected web forms may use the admin session. */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $apiToken = $token instanceof PersonalAccessToken && $token->can('admin');
        $webForm = $request->routeIs(
            'admin.providers.create', 'admin.providers.update', 'admin.providers.test'
        ) && $request->hasSession() && \Illuminate\Support\Facades\Auth::guard('web')->check();

        abort_unless($user?->canAccessAdmin() && ($apiToken || $webForm), 403);
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        return [
            'display_name' => [$required, 'string', 'min:2', 'max:160'],
            'provider_type' => [$required, Rule::in(['ollama-compatible', 'openai-compatible'])],
            'base_url' => [$required, 'string', 'max:2048'],
            'secret' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'enabled' => [$required, 'boolean'],
            'concurrent_limit' => ['sometimes', 'integer', 'between:1,20'],
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,30'],
            'daily_budget_minor' => ['sometimes', 'integer', 'between:0,1000000000'],
            'currency' => ['sometimes', Rule::in(['USD', 'KWD', 'EUR'])],
            'routing_priority' => ['sometimes', 'integer', 'between:1,999'],
        ];
    }

    private function verifiedInput(Request $request, bool $creating): array
    {
        $fields = $request->validate($this->rules($creating));
        if (isset($fields['base_url'])) {
            try {
                $fields['base_url'] = ProviderEndpoint::normalize($fields['base_url']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['base_url' => 'A valid HTTPS or loopback provider URL is required.']);
            }
        }
        if (array_key_exists('secret', $fields)) {
            $fields['secret_ciphertext'] = $fields['secret'] === '' ? null : $fields['secret'];
            unset($fields['secret']);
        }
        // Any configuration change invalidates previously observed health.
        $fields['health_status'] = 'unverified';
        $fields['last_checked_at'] = null;
        return $fields;
    }

    private function audit(AiProvider $provider, Request $request, string $action, array $fields): void
    {
        $changed = array_values(array_filter(array_keys($fields), static fn (string $field): bool =>
            ! in_array($field, ['secret_ciphertext', 'health_status', 'last_checked_at'], true)
        ));
        if (array_key_exists('secret_ciphertext', $fields)) {
            $changed[] = 'secret_rotated';
        }
        DB::table('ai_provider_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'provider_id' => $provider->id,
            'actor_id' => $request->user()->id,
            'action' => $action,
            'changed_fields_json' => json_encode($changed, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $page = AiProvider::query()->orderBy('display_name')->paginate(20);

        return response()->json(['data' => $page->getCollection()
            ->map(fn (AiProvider $p): array => $p->toPublicArray())->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, AiProvider $provider): JsonResponse
    {
        $this->authorizeAdmin($request);
        return response()->json(['data' => $provider->toPublicArray()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $fields = $this->verifiedInput($request, true);
        if (AiProvider::query()->where('display_name', $fields['display_name'])->exists()) {
            throw ValidationException::withMessages(['display_name' => 'A provider with this name already exists.']);
        }
        $provider = DB::transaction(function () use ($fields, $request): AiProvider {
            $provider = AiProvider::create($fields);
            $this->audit($provider, $request, 'created', $fields);
            return $provider;
        });
        return response()->json(['data' => $provider->toPublicArray()], 201);
    }

    public function update(Request $request, AiProvider $provider): JsonResponse
    {
        $this->authorizeAdmin($request);
        $fields = $this->verifiedInput($request, false);
        if ($fields === ['health_status' => 'unverified', 'last_checked_at' => null]) {
            throw ValidationException::withMessages(['provider' => 'At least one setting must change.']);
        }
        if (isset($fields['display_name']) && AiProvider::query()->where('display_name', $fields['display_name'])
            ->whereKeyNot($provider->id)->exists()) {
            throw ValidationException::withMessages(['display_name' => 'A provider with this name already exists.']);
        }
        DB::transaction(function () use ($provider, $fields, $request): void {
            $provider->update($fields);
            $this->audit($provider, $request, 'updated', $fields);
        });
        return response()->json(['data' => $provider->fresh()->toPublicArray()]);
    }

    public function destroy(Request $request, AiProvider $provider): JsonResponse
    {
        $this->authorizeAdmin($request);

        DB::transaction(function () use ($provider, $request): void {
            // Revoke provider access and erase credentials before retaining an
            // audit-preserving tombstone. Never persist the submitted secret.
            $provider->forceFill([
                'enabled' => false,
                'secret_ciphertext' => null,
                'health_status' => 'unverified',
                'last_checked_at' => null,
            ])->save();

            $this->audit($provider, $request, 'deleted', [
                'enabled' => false,
                'secret_removed' => true,
            ]);

            $provider->delete();
        });

        return response()->json(null, 204);
    }

    public function test(Request $request, AiProvider $provider): JsonResponse
    {
        $this->authorizeAdmin($request);
        // Explicit action only. No connectivity during create/update/page load.
        $adapter = $provider->provider_type === 'ollama-compatible'
            ? new OllamaAdapter() : new OpenAiCompatibleAdapter();
        $healthy = $adapter->checkHealth(
            $provider->base_url,
            $provider->secret_ciphertext,
            $provider->timeout_seconds
        );
        DB::transaction(function () use ($provider, $request, $healthy): void {
            $provider->update(['health_status' => $healthy ? 'healthy' : 'unhealthy', 'last_checked_at' => now()]);
            $this->audit($provider, $request, 'tested', []);
        });
        return response()->json(['data' => $provider->fresh()->toPublicArray()]);
    }
}

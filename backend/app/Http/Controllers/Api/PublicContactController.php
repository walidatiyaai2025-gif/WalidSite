<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * WEB-14 JSON contact submission. The website has a separate CSRF-protected
 * HTML form; this API follows ContactInput and requires explicit consent.
 */
final class PublicContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $recipient = config('mail.contact_recipient');
        $transport = config('mail.default');

        // An array/log/failover mailer can claim success without a real,
        // verified delivery path. Never accept contact data on that basis.
        abort_unless(
            is_string($recipient)
                && filter_var($recipient, FILTER_VALIDATE_EMAIL)
                && in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend'], true),
            503,
            'Contact delivery is not configured.'
        );

        // Language belongs to the URL, never the ContactInput body. Reject
        // all other query keys so PII and consent cannot be smuggled through
        // request merging or used as substitutes for required JSON fields.
        $query = $request->query();
        if (array_diff(array_keys($query), ['lang']) !== []
            || (array_key_exists('lang', $query)
                && (! is_string($query['lang'])
                    || ! in_array($query['lang'], ['en', 'ar'], true)))) {
            throw ValidationException::withMessages([
                'query' => 'Unsupported contact query parameters.',
            ]);
        }

        // Laravel's Request::all() merges URL query and JSON input. Inspect
        // and validate only the actual submitted body to preserve the closed
        // ContactInput schema while accepting /contact?lang=en|ar.
        $payload = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();
        if (array_diff(array_keys($payload), [
            'name', 'email', 'message', 'privacy_consent',
        ]) !== []) {
            throw ValidationException::withMessages([
                'payload' => 'Unexpected contact fields.',
            ]);
        }

        $data = Validator::make($payload, [
            'name' => ['required', 'string', 'min:2', 'max:100',
                'not_regex:/[\x00-\x1F\x7F]/u'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'message' => ['required', 'string', 'min:10', 'max:3000',
                'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'privacy_consent' => ['required', 'boolean', 'accepted'],
        ])->validate();

        // No message database, attachment, queue fallback or unreviewed
        // recipient override. Reply-To is the validated address, never input
        // placed into a mail header via string concatenation.
        $body = "Name: {$data['name']}\nEmail: {$data['email']}\n\n{$data['message']}";
        try {
            Mail::to($recipient)->send(
                (new Mailable)
                    ->text('mail.contact-notification')
                    ->with(['messageBody' => $body])
                    ->replyTo($data['email'])
                    ->subject('WalidSite contact inquiry')
            );
        } catch (Throwable $exception) {
            // Mail exceptions may include transport credentials or user PII.
            Log::warning('public.contact.delivery_failed', ['type' => $exception::class]);
            abort(503, 'Contact delivery is temporarily unavailable.');
        }

        // This means the configured transport accepted the message, NOT
        // proof that the external recipient opened or received it.
        return response()->json([
            'ok' => true,
            'data' => ['accepted_by_transport' => true],
            'timestamp' => now('UTC')->toIso8601String(),
            'request_id' => (string) Str::uuid(),
        ])->header('Cache-Control', 'no-store');
    }
}

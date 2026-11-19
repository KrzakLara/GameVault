<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout accepts exactly one input: the idempotency key. The game comes from the URL and the
 * price from the database; anything else in the payload (price, status, user id ...) is ignored
 * because only validated() fields ever reach the action.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // A UUID from the browser, or any client-generated token of the same strength.
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /** API clients may use the standard Idempotency-Key header instead of the body field. */
    protected function prepareForValidation(): void
    {
        $header = $this->header('Idempotency-Key');

        if ($header !== null && ! $this->filled('idempotency_key')) {
            $this->merge(['idempotency_key' => $header]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $header = $this->header('Idempotency-Key');

            if ($header !== null && $header !== $this->input('idempotency_key')) {
                $validator->errors()->add('idempotency_key', 'The Idempotency-Key header and the idempotency_key field differ.');
            }
        });
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}

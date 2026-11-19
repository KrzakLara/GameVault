<?php

namespace App\Http\Requests\Admin;

use App\Support\KeyFingerprint;

class ImportGameKeysRequest extends AdminRequest
{
    public const MAX_KEYS = 500;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'codes' => ['required', 'array', 'min:1', 'max:'.self::MAX_KEYS],
            'codes.*' => ['required', 'string', 'distinct', 'regex:/^[A-Z0-9]{3,10}(-[A-Z0-9]{3,10}){1,5}$/'],
        ];
    }

    /** Normalise before validating so "abcde-12345" and "ABCDE-12345 " are the same key. */
    protected function prepareForValidation(): void
    {
        $codes = $this->input('codes');

        if (is_array($codes)) {
            $this->merge(['codes' => array_map(fn ($c) => is_string($c) ? KeyFingerprint::normalize($c) : $c, $codes)]);
        }
    }

    /**
     * Submitted codes are never flashed back as old input: "codes" is in the exception
     * handler's dontFlash list (bootstrap/app.php).
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values($this->validated('codes'));
    }
}

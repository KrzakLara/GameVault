<?php

namespace App\Http\Requests\Admin;

class RetryOrderRequest extends AdminRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}

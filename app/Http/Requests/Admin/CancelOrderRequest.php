<?php

namespace App\Http\Requests\Admin;

class CancelOrderRequest extends AdminRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Cancelling is irreversible: a written, auditable reason is mandatory.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}

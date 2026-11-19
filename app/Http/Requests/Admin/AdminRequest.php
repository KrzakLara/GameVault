<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base for administrative operations. Authorization is checked here as well as by the route's
 * can:admin middleware, so an admin action can never be exposed by a routing mistake alone.
 */
abstract class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('admin');
    }
}

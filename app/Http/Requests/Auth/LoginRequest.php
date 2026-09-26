<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * One message for a wrong password, an unknown address and a disabled
     * account. Anything more specific is user enumeration.
     */
    public const INVALID_CREDENTIALS = 'These credentials do not match our records.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1000'],
        ];
    }
}

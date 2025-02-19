<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'email'],
            'username' => ['nullable', 'string', 'alpha_dash:ascii', 'max:20'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $email = $this->input('email');
        $username = $this->input('username');
        $password = $this->input('password');
        $remember = $this->boolean('remember');
        
        $credentials = [];
        
        $isEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
        
        if ($isEmail) {
            $credentials = ['email' => $email, 'password' => $password];
        } else {
            $credentials = ['username' => $username, 'password' => $password];
        }
        
        if (!Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($this->throttleKey());
        
            $failedField = $isEmail ? 'email' : 'username';
        
            throw ValidationException::withMessages([
                $failedField => trans('auth.failed'),
            ]);
        }
        RateLimiter::clear($this->throttleKey());   
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->input('email')).'|'.$this->ip());
    }
}
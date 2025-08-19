<?php

namespace App\Filament\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Models\User;

class CustomLogin extends BaseLogin
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ])
            ->statePath('data');
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::pages/auth/login.form.email.label'))
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::pages/auth/login.form.password.label'))
            ->password()
            ->required()
            ->extraInputAttributes(['tabindex' => 2]);
    }

    protected function getRememberFormComponent(): Component
    {
        return \Filament\Forms\Components\Checkbox::make('remember')
            ->label(__('filament-panels::pages/auth/login.form.remember.label'));
    }

    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $email = $data['email'];
        $password = $data['password'];
        $remember = $data['remember'] ?? false;

        // Rate limiting key
        $key = Str::transliterate(Str::lower($email) . '|' . request()->ip());

        // Check rate limiting (5 attempts per minute)
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in ' . 
                          RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        // Find user
        $user = User::where('email', $email)->first();

        if (!$user) {
            RateLimiter::hit($key, 60); // 1 minute lockout
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // Check if user is active
        if (!$user->is_active) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'email' => 'Your account has been deactivated. Please contact an administrator.',
            ]);
        }

        // Check if account is locked
        if ($user->isLocked()) {
            RateLimiter::hit($key, 60);
            $minutesLeft = $user->locked_until->diffInMinutes(now());
            throw ValidationException::withMessages([
                'email' => "Your account is locked for {$minutesLeft} more minutes due to multiple failed login attempts.",
            ]);
        }

        // Attempt authentication
        $credentials = [
            'email' => $email,
            'password' => $password,
        ];

        if (!auth()->attempt($credentials, $remember)) {
            // Increment failed attempts
            $user->incrementFailedAttempts();
            RateLimiter::hit($key, 60);

            // Log failed attempt
            activity('auth')
                ->causedBy($user)
                ->withProperties([
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'failed_attempts' => $user->failed_login_attempts,
                ])
                ->log('Failed login attempt');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // Clear rate limiting on successful login
        RateLimiter::clear($key);

        // Reset failed attempts and update last login
        $user->resetFailedAttempts();

        // Log successful login
        activity('auth')
            ->causedBy($user)
            ->withProperties([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->log('Successful login');

        // Return the parent's authentication response
        return parent::authenticate();
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }

    public function mount(): void
    {
        parent::mount();

        // If user is already authenticated and can access panel, redirect to dashboard
        if (auth()->check() && auth()->user()->canAccessPanel($this->getPanel())) {
            redirect()->intended($this->getPanel()->getUrl());
        }

        // Clear any existing authentication
        if (auth()->check()) {
            auth()->logout();
        }
    }

    public function getHeading(): string
    {
        return 'ISP Mail Manager';
    }

    public function getSubheading(): ?string
    {
        return 'Sign in to your account to manage mail backups and monitoring';
    }

    public function hasFullWidthFormActions(): bool
    {
        return true;
    }
}
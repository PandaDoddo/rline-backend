<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
            'password_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function getAuthProviderAttribute($value): ?string
    {
        $context = app(TenantContext::class);

        return $context->id() ? $context->membership($this->id)?->auth_provider : $value;
    }

    public function authenticationStamp(): string
    {
        $membership = app(TenantContext::class)->membership($this->id);

        return hash('sha256', implode('|', [$this->getRawOriginal('password'), $membership?->id, $membership?->auth_provider, $membership?->auth_subject]));
    }
}

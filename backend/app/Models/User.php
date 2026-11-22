<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasLocalePreference, MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, HasUlids, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'password', 'locale', 'country_code', 'marketing_opt_in'];

    protected $hidden = ['password', 'remember_token'];

    /** Mirror DB defaults so freshly created models behave like loaded ones. */
    protected $attributes = ['status' => 'active', 'locale' => 'ar', 'is_staff' => false, 'marketing_opt_in' => false];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_staff' => 'boolean',
            'marketing_opt_in' => 'boolean',
            'contributor_applied_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_staff && $this->status === 'active';
    }

    /** Emails (verification, reset, receipts) go out in the customer's language. */
    public function preferredLocale(): ?string
    {
        return $this->locale ?: config('lugha.default_locale');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function activeEntitlements(): HasMany
    {
        return $this->entitlements()->where('status', 'active');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(TranslationFeedback::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PackageReview::class);
    }

    /** Approved members of the contributor program may suggest wording for packages they own. */
    public function isContributor(): bool
    {
        return $this->contributor_status === 'approved';
    }

    /** Name shown on public credits: the chosen display name, else the first name. */
    public function publicName(): string
    {
        return $this->contributor_display_name ?: (explode(' ', trim((string) $this->name))[0] ?: __('Player'));
    }

    public function owns(LocalizationPackage|int $package): bool
    {
        $id = $package instanceof LocalizationPackage ? $package->id : $package;

        return $this->activeEntitlements()->where('package_id', $id)->exists();
    }
}

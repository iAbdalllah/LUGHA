<?php

namespace App\Domain\Entitlements;

use App\Enums\EntitlementSource;
use App\Enums\UpdatePolicy;
use App\Models\Entitlement;
use App\Models\LocalizationPackage;
use App\Models\OrderItem;
use App\Models\User;

final class EntitlementService
{
    /**
     * Grant a package (and, for a bundle, each included package) to a user.
     * Existing active grants are kept as they are — a user never loses update terms by buying a bundle.
     */
    public function grant(User $user, LocalizationPackage $package, EntitlementSource $source, ?OrderItem $item = null, ?Entitlement $parent = null): ?Entitlement
    {
        $existing = Entitlement::where('user_id', $user->id)->where('package_id', $package->id)->where('status', 'active')->first();
        if ($existing) {
            return $parent ? null : $existing;
        }

        [$major, $until] = match ($package->update_policy) {
            UpdatePolicy::VersionLine => [$package->current_major, null],
            UpdatePolicy::Duration => [null, now()->addMonths((int) ($package->update_months ?: 12))],
            UpdatePolicy::Lifetime => [null, null],
        };

        $grant = Entitlement::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'source' => $source,
            'order_item_id' => $item?->id,
            'parent_entitlement_id' => $parent?->id,
            'update_major' => $major,
            'updates_until' => $until,
            'status' => 'active',
            'granted_at' => now(),
        ]);

        if ($package->is_bundle) {
            foreach ($package->inclusions as $child) {
                $this->grant($user, $child, EntitlementSource::Bundle, $item, $grant);
            }
        }

        return $grant;
    }

    /** Revoke a grant and the bundle children it created. */
    public function revoke(Entitlement $entitlement, string $reason): void
    {
        foreach ($entitlement->children()->where('status', 'active')->get() as $child) {
            $this->revoke($child, $reason);
        }
        $entitlement->update(['status' => 'revoked', 'revoked_reason' => $reason, 'revoked_at' => now()]);
    }
}

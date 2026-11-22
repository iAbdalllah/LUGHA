<?php

namespace App\Domain\Commerce;

use App\Domain\Entitlements\EntitlementService;
use App\Enums\EntitlementSource;
use App\Models\Entitlement;
use App\Models\GiftCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RedeemGiftCode
{
    public function __construct(private EntitlementService $entitlements) {}

    public function handle(User $user, string $code): Entitlement
    {
        return DB::transaction(function () use ($user, $code) {
            $gift = GiftCode::where('code_hash', GiftCode::hashCode($code))->lockForUpdate()->first();

            if (! $gift || $gift->revoked_at || ($gift->expires_at && $gift->expires_at->isPast())) {
                throw new CheckoutException(__('gift.invalid'));
            }
            if ($gift->redeemed_by) {
                throw new CheckoutException(__('gift.already_redeemed'));
            }
            if ($user->owns($gift->package_id)) {
                throw new CheckoutException(__('gift.already_owned'));
            }

            $gift->update(['redeemed_by' => $user->id, 'redeemed_at' => now()]);

            return $this->entitlements->grant($user, $gift->package()->with('inclusions')->first(), EntitlementSource::Gift);
        });
    }
}

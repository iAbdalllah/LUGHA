<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();              // stored upper-case
            $table->string('kind', 8);                          // percent | fixed
            $table->unsignedInteger('value');                   // percent (1–100) or minor units
            $table->unsignedInteger('min_order_amount')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedSmallInteger('per_user_limit')->default(1);
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->jsonb('game_ids')->nullable();               // scope: null = all
            $table->jsonb('package_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->boolean('as_gift')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'package_id', 'as_gift']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 24)->nullable()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // pending | awaiting_payment | paid | failed | cancelled | partially_refunded | refunded
            $table->char('currency', 3)->default('SAR');
            $table->unsignedInteger('subtotal_amount');
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('vat_amount');
            $table->unsignedInteger('total_amount');
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('billing')->nullable();
            $table->string('invoice_number', 40)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('localization_packages')->restrictOnDelete();
            $table->string('kind', 10)->default('purchase'); // purchase | upgrade | gift
            $table->foreignId('upgrade_from_package_id')->nullable()->constrained('localization_packages')->nullOnDelete();
            $table->unsignedInteger('list_amount');           // price before upgrade credit / coupon
            $table->unsignedInteger('unit_amount');           // charged, VAT-inclusive, after line discount
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('vat_amount');
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->jsonb('snapshot');
            $table->timestamps();
            $table->index('package_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 20);
            $table->string('gateway_ref', 120)->nullable();
            $table->ulid('idempotency_key')->unique();
            $table->string('method', 20)->nullable();
            $table->string('status', 20)->default('initiated'); // initiated | pending | paid | failed | refunded | partially_refunded | voided
            $table->unsignedInteger('amount');
            $table->char('currency', 3);
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->string('checkout_url', 500)->nullable();
            $table->jsonb('raw_last_status')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'gateway_ref']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->text('reason');
            $table->string('status', 16)->default('requested'); // requested | processed | rejected | failed
            $table->string('gateway_ref', 120)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('event_id', 160);
            $table->jsonb('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'event_id']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['coupon_id', 'order_id']);
        });

        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->constrained('localization_packages')->restrictOnDelete();
            $table->string('source', 12); // purchase | upgrade | bundle | gift | admin | promo
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_entitlement_id')->nullable()->constrained('entitlements')->nullOnDelete();
            $table->unsignedInteger('update_major')->nullable();
            $table->timestamp('updates_until')->nullable();
            $table->string('status', 10)->default('active'); // active | revoked
            $table->string('revoked_reason', 40)->nullable();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->index(['user_id', 'status']);
        });
        // One active grant per user+package. PostgreSQL and SQLite have partial indexes; MySQL/MariaDB don't, so there a
        // generated column is 1 while active and NULL otherwise, and a unique index over it allows any number of
        // revoked rows (NULLs never collide) but only one active one.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('entitlements', function (Blueprint $table) {
                $table->unsignedTinyInteger('active_key')->nullable()->storedAs("CASE WHEN status = 'active' THEN 1 END");
                $table->unique(['user_id', 'package_id', 'active_key'], 'entitlements_one_active_idx');
            });
        } else {
            DB::statement("CREATE UNIQUE INDEX entitlements_one_active_idx ON entitlements (user_id, package_id) WHERE status = 'active'");
        }

        Schema::create('gift_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->string('code_hint', 8);                    // last 4 chars, for support lookups
            $table->text('code_encrypted');                   // app-key encrypted so the purchaser can view it again
            $table->foreignId('package_id')->constrained('localization_packages')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchaser_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_codes');
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('coupons');
    }
};

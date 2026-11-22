<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('localization_packages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->string('slug', 100)->unique();
            $table->jsonb('name');
            $table->jsonb('summary')->nullable();
            $table->unsignedSmallInteger('tier_rank')->default(1);
            $table->string('locale', 10)->default('ar-SA');
            $table->foreignId('style_id')->constrained('localization_styles')->restrictOnDelete();
            $table->string('update_policy', 16)->default('version_line'); // version_line | duration | lifetime
            $table->unsignedSmallInteger('update_months')->nullable();
            $table->unsignedInteger('current_major')->default(1);
            $table->string('status', 16)->default('draft'); // draft | published | unpublished | archived
            $table->boolean('is_bundle')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['game_id', 'status', 'sort_order']);
        });

        Schema::create('package_category', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('translation_categories')->restrictOnDelete();
            $table->primary(['package_id', 'category_id']);
        });

        Schema::create('package_inclusions', function (Blueprint $table) {
            $table->foreignId('bundle_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->foreignId('included_id')->constrained('localization_packages')->restrictOnDelete();
            $table->primary(['bundle_id', 'included_id']);
        });

        Schema::create('package_platform', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->primary(['package_id', 'platform_id']);
        });

        Schema::create('package_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->char('currency', 3)->default('SAR');
            $table->unsignedInteger('base_amount');            // VAT-inclusive minor units (halalas)
            $table->unsignedInteger('sale_amount')->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->timestamps();
            $table->unique(['package_id', 'currency']);
        });

        Schema::create('package_upgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_package_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->foreignId('to_package_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->char('currency', 3)->default('SAR');
            $table->unsignedInteger('amount');
            $table->timestamps();
            $table->unique(['from_package_id', 'to_package_id', 'currency']);
        });

        Schema::create('package_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->string('version', 32);
            $table->unsignedInteger('major');
            $table->foreignId('game_version_min_id')->nullable()->constrained('game_versions')->nullOnDelete();
            $table->foreignId('game_version_max_id')->nullable()->constrained('game_versions')->nullOnDelete();
            $table->string('min_app_version', 32)->default('1.0.0');
            $table->jsonb('release_notes')->nullable();
            $table->unsignedInteger('entry_count')->nullable();
            $table->jsonb('coverage')->nullable();
            $table->string('build_status', 16)->default('pending'); // pending | building | validated | failed | released | revoked
            $table->text('build_log')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['package_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_versions');
        Schema::dropIfExists('package_upgrades');
        Schema::dropIfExists('package_prices');
        Schema::dropIfExists('package_platform');
        Schema::dropIfExists('package_inclusions');
        Schema::dropIfExists('package_category');
        Schema::dropIfExists('localization_packages');
    }
};

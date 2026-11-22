<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);                 // macos | windows | android | ios | android_tv
            $table->string('name', 120);
            $table->string('model', 120)->nullable();
            $table->string('os_version', 40)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->char('public_key', 44);             // base64 X25519 — licenses wrap content keys to it
            $table->char('key_fingerprint', 64)->unique();
            $table->string('status', 10)->default('active'); // active | removed
            $table->unsignedBigInteger('token_id')->nullable(); // the Sanctum token bound to this device
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'kind']);
        });

        Schema::create('device_codes', function (Blueprint $table) {
            $table->id();
            $table->char('device_code_hash', 64)->unique();
            $table->string('user_code', 9)->unique();  // "7K4-Q9P"
            $table->string('client_name', 120);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // set on approval
            $table->string('status', 10)->default('pending'); // pending | approved | denied | used
            $table->timestamp('expires_at');
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('package_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_version_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);                  // full | delta
            $table->foreignId('base_version_id')->nullable()->constrained('package_versions')->cascadeOnDelete();
            $table->string('disk', 20);
            $table->string('path');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->unique(['package_version_id', 'kind', 'base_version_id']);
        });

        Schema::table('package_versions', function (Blueprint $table) {
            $table->text('content_key_encrypted')->nullable(); // app-key encrypted (KMS in production)
            $table->string('signing_key_id', 40)->nullable();
            $table->jsonb('build_report')->nullable();
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('entitlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_version_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'package_version_id']);
        });

        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_file_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('authorized'); // authorized | completed
            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('installations', function (Blueprint $table) {
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->foreignId('package_version_id')->constrained()->cascadeOnDelete();
            $table->timestamp('reported_at');
            $table->primary(['device_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
        Schema::dropIfExists('downloads');
        Schema::dropIfExists('licenses');
        Schema::table('package_versions', fn (Blueprint $t) => $t->dropColumn(['content_key_encrypted', 'signing_key_id', 'build_report']));
        Schema::dropIfExists('package_files');
        Schema::dropIfExists('device_codes');
        Schema::dropIfExists('devices');
    }
};

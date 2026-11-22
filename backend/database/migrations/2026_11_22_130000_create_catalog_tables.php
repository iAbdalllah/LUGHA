<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->jsonb('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('slug', 80)->unique();
            $table->jsonb('title');
            $table->jsonb('description')->nullable();
            $table->string('developer', 160)->nullable();
            $table->string('publisher', 160)->nullable();
            $table->date('release_date')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('hero_path')->nullable();
            $table->string('rights_status', 16)->default('pending'); // pending | licensed | permitted | rejected
            $table->text('rights_notes')->nullable();
            $table->string('status', 16)->default('draft');          // draft | coming_soon | published | archived
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'is_featured']);
        });

        Schema::create('game_platform', function (Blueprint $table) {
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->primary(['game_id', 'platform_id']);
        });

        Schema::create('game_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('version', 40);
            $table->date('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'version']);
        });

        Schema::create('game_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('screenshot'); // screenshot | subtitle_preview | video
            $table->string('path');
            $table->jsonb('caption')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_media');
        Schema::dropIfExists('game_versions');
        Schema::dropIfExists('game_platform');
        Schema::dropIfExists('games');
        Schema::dropIfExists('platforms');
    }
};

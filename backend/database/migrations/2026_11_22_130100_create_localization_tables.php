<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->jsonb('name');
            $table->foreignId('parent_id')->nullable()->constrained('translation_categories')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('localization_styles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->jsonb('name');
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('translation_categories')->restrictOnDelete();
            $table->foreignId('style_id')->constrained('localization_styles')->restrictOnDelete();
            $table->string('locale', 10)->default('ar-SA');
            $table->string('string_key', 160)->nullable();
            $table->text('source_text');
            $table->char('source_hash', 64);
            $table->text('target_text')->nullable();
            $table->string('speaker', 120)->nullable();
            $table->text('context')->nullable();
            $table->unsignedInteger('sequence_no')->nullable();
            $table->string('scene_ref', 120)->nullable();
            $table->foreignId('min_game_version_id')->nullable()->constrained('game_versions')->nullOnDelete();
            $table->foreignId('max_game_version_id')->nullable()->constrained('game_versions')->nullOnDelete();
            $table->string('status', 16)->default('draft'); // draft | in_review | approved | published | rejected
            $table->foreignId('translator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['game_id', 'locale', 'style_id', 'source_hash', 'category_id'], 'translations_identity_unique');
            $table->index(['game_id', 'status', 'category_id']);
        });

        Schema::create('translation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->text('target_text')->nullable();
            $table->string('status', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['translation_id', 'revision']);
        });

        Schema::create('translation_glossaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->nullable()->constrained()->cascadeOnDelete(); // null = global
            $table->string('locale', 10)->default('ar-SA');
            $table->string('term', 200);
            $table->string('translation', 200)->nullable();
            $table->boolean('do_not_translate')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'locale', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_glossaries');
        Schema::dropIfExists('translation_revisions');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('localization_styles');
        Schema::dropIfExists('translation_categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5: community contributions, line reports, public reviews, QA scores, first-party analytics. */
return new class extends Migration
{
    public function up(): void
    {
        // Contributor program: players apply, staff approve. Approved contributors may suggest wording for owned packages.
        Schema::table('users', function (Blueprint $table) {
            $table->string('contributor_status', 16)->nullable()->after('locale'); // applied | approved | suspended
            $table->timestamp('contributor_applied_at')->nullable()->after('contributor_status');
            $table->string('contributor_display_name', 60)->nullable()->after('contributor_applied_at');
        });

        // Reports (any owner, usually from an app: "this line is wrong") and suggestions (contributors: better wording).
        // Both are resolved by a reviewer; accepted wording goes through the normal translation workflow.
        Schema::create('translation_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('localization_packages')->nullOnDelete();
            $table->foreignId('package_version_id')->nullable()->constrained('package_versions')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);              // report | suggestion
            $table->string('reason', 20);            // wrong_meaning | typo | awkward | offensive | wrong_speaker | other
            $table->text('suggested_text')->nullable();
            $table->text('note')->nullable();
            $table->string('source', 12)->default('store'); // store | macos | windows | android | ios | tv
            $table->string('status', 12)->default('open');  // open | accepted | rejected | duplicate
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->text('applied_text')->nullable(); // the wording the reviewer actually applied, if any
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['translation_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('package_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('localization_packages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');   // 1..5
            $table->string('title', 120)->nullable();
            $table->text('body')->nullable();
            $table->string('locale', 5)->default('ar');
            $table->string('package_version', 32)->nullable(); // version the reviewer had installed/latest when writing
            $table->string('status', 12)->default('published'); // published | hidden
            $table->string('hidden_reason', 200)->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['package_id', 'user_id']);
            $table->index(['package_id', 'status', 'created_at']);
        });

        Schema::table('localization_packages', function (Blueprint $table) {
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedTinyInteger('qa_score')->nullable(); // 0..100
            $table->jsonb('qa_breakdown')->nullable();
            $table->timestamp('qa_computed_at')->nullable();
        });

        // First-party, cookie-less product analytics. No IP, no user agent: visitor is a daily-rotating hash.
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32);              // package_view | cart_add (checkouts and purchases come from orders)
            $table->foreignId('package_id')->nullable()->constrained('localization_packages')->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('visitor', 16);
            $table->string('locale', 5)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['name', 'created_at']);
            $table->index(['package_id', 'name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::table('localization_packages', fn (Blueprint $t) => $t->dropColumn(['rating_avg', 'rating_count', 'qa_score', 'qa_breakdown', 'qa_computed_at']));
        Schema::dropIfExists('package_reviews');
        Schema::dropIfExists('translation_feedback');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['contributor_status', 'contributor_applied_at', 'contributor_display_name']));
    }
};

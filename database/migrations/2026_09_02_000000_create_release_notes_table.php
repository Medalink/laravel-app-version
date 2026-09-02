<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('version', 32)->unique();
            $table->string('previous_version', 32)->nullable();
            $table->string('source_commit', 64)->nullable();
            $table->string('source_range')->nullable();
            $table->string('headline');
            $table->text('summary');
            $table->json('sections');
            $table->json('summary_sections')->nullable();
            $table->json('feature_groups')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('published_at')->nullable()->index();
            $table->string('generation_mode', 20)->default('parsed')->index();
            $table->json('generation_warnings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_notes');
    }
};

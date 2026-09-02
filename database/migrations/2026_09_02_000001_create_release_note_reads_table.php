<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_note_reads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Stored as text so integer and ULID user keys both fit; add a
            // foreign key in a published copy when the host's key type is known.
            $table->string('user_id', 64)->unique();
            $table->string('last_seen_version', 32)->nullable();
            $table->string('last_prompted_version', 32)->nullable();
            $table->timestamp('bootstrapped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_note_reads');
    }
};

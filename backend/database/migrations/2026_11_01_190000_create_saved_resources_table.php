<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saved_resources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('resource_type', 30);
            $table->uuid('resource_id');
            $table->timestamp('saved_at');
            $table->unique(['user_id', 'resource_type', 'resource_id'], 'saved_resources_owner_type_id_unique');
            $table->index(['user_id', 'saved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_resources');
    }
};

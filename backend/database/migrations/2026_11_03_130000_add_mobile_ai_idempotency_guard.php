<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_diagnosis_requests', function (Blueprint $table): void {
            // A global UNIQUE opaque key digest denies cross-user key reuse
            // as well as duplicate paid admissions by the same user. NULL
            // preserves old client and historical row compatibility.
            $table->string('idempotency_key_hash', 64)->nullable()->unique();
            $table->string('request_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_diagnosis_requests', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key_hash']);
            $table->dropColumn(['idempotency_key_hash', 'request_fingerprint']);
        });
    }
};

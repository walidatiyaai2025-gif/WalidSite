<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recover a deterministic legacy order before enforcing future enqueue order.
 * Older steps did not persist insertion positions, so they are stabilized by
 * creation time and primary key; new jobs preserve the submitted step order.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_job_steps', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position')->default(0);
        });

        $previousJob = null;
        $position = 0;
        DB::table('ai_job_steps')->select('id', 'job_id')
            ->orderBy('job_id')->orderBy('created_at')->orderBy('id')
            ->chunk(200, static function ($rows) use (&$previousJob, &$position): void {
                foreach ($rows as $row) {
                    if ($previousJob !== $row->job_id) {
                        $previousJob = $row->job_id;
                        $position = 0;
                    }
                    DB::table('ai_job_steps')->where('id', $row->id)
                        ->update(['position' => $position++]);
                }
            });

        Schema::table('ai_job_steps', function (Blueprint $table): void {
            $table->unique(['job_id', 'position'], 'ai_job_steps_order_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ai_job_steps', function (Blueprint $table): void {
            $table->dropUnique('ai_job_steps_order_unique');
            $table->dropColumn('position');
        });
    }
};

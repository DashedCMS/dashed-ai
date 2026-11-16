<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dashed__ai_logs')) {
            return;
        }

        if (! Schema::hasColumn('dashed__ai_logs', 'subject_type')) {
            Schema::table('dashed__ai_logs', function (Blueprint $table) {
                $table->string('subject_type')->nullable()->after('caller');
            });
        }

        if (! Schema::hasColumn('dashed__ai_logs', 'subject_id')) {
            Schema::table('dashed__ai_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
                $table->index(['subject_type', 'subject_id'], 'dashed__ai_logs_subject_index');
            });
        }

        if (! Schema::hasColumn('dashed__ai_logs', 'batch')) {
            Schema::table('dashed__ai_logs', function (Blueprint $table) {
                $table->string('batch', 64)->nullable()->after('subject_id')->index();
            });
        }

        if (! Schema::hasColumn('dashed__ai_logs', 'estimated')) {
            Schema::table('dashed__ai_logs', function (Blueprint $table) {
                $table->boolean('estimated')->default(false)->after('cost_usd');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashed__ai_logs')) {
            return;
        }

        if (Schema::hasColumn('dashed__ai_logs', 'subject_id')) {
            Schema::table('dashed__ai_logs', function (Blueprint $table) {
                $table->dropIndex('dashed__ai_logs_subject_index');
            });
        }

        foreach (['estimated', 'batch', 'subject_id', 'subject_type'] as $column) {
            if (Schema::hasColumn('dashed__ai_logs', $column)) {
                Schema::table('dashed__ai_logs', function (Blueprint $table) use ($column) {
                    if ($column === 'batch') {
                        $table->dropIndex(['batch']);
                    }
                    $table->dropColumn($column);
                });
            }
        }
    }
};

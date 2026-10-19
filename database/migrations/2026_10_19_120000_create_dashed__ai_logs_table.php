<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dashed__ai_logs')) {
            return;
        }

        Schema::create('dashed__ai_logs', function (Blueprint $table) {
            $table->id();
            $table->string('site_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('provider', 40)->index();
            $table->string('model')->nullable();
            $table->string('kind', 40)->index();
            $table->string('purpose')->nullable()->index();
            $table->string('caller')->nullable();
            $table->string('status', 20)->default('success')->index();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('units', 14, 4)->nullable();
            $table->string('unit_type', 30)->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('prompt')->nullable();
            $table->longText('response')->nullable();
            $table->json('options')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashed__ai_logs');
    }
};

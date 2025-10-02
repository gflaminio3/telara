<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('telara_files', function (Blueprint $table) {
            $table->boolean('is_chunked')->default(false)->index()->after('metadata');
            $table->boolean('is_encrypted')->default(false)->after('is_chunked');
        });
    }

    public function down(): void
    {
        Schema::table('telara_files', function (Blueprint $table) {
            $table->dropColumn(['is_chunked', 'is_encrypted']);
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTelaraTrackedFilesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * This method creates the 'telara_tracked_files' table, which stores
     * metadata for Telegram files uploaded via the TelegramStorageDriver.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('telara_files', function (Blueprint $table) {
            $table->id();
            $table->string('file_id')->comment('The Telegram file_id returned by sendDocument');
            $table->string('path')->unique()->comment('Local path or identifier used to track the file');
            $table->string('caption')->nullable()->comment('Optional caption provided during upload');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * This method drops the 'telara_tracked_files' table if it exists.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('telara_tracked_files');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cctv_cameras', function (Blueprint $table) {
            // Convert the camera's H.265 video to H.264 on the gateway so every browser can play it.
            $table->boolean('transcode')->default(true)->after('rtsp_path');
        });
    }

    public function down(): void
    {
        Schema::table('cctv_cameras', function (Blueprint $table) {
            $table->dropColumn('transcode');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->boolean('can_view_cctv')->default(false)->after('is_admin');
        });

        // Everyone who already had an account is an owner of this workspace.
        DB::table('users')->update(['is_admin' => true]);

        Schema::create('cctv_cameras', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('location', 120)->nullable();
            $table->string('stream_path', 64)->unique(); // path name on the MediaMTX gateway
            // Only the camera address goes here; the RTSP password stays on the office PC.
            $table->string('camera_ip', 64)->nullable();
            $table->string('rtsp_path', 120)->default('/h264/ch1/main/av_stream');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('last_online_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cctv_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cctv_camera_id')->nullable()->constrained()->nullOnDelete();
            $table->string('camera_name', 80); // kept even if the camera is removed later
            $table->string('action', 20)->default('view');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cctv_access_logs');
        Schema::dropIfExists('cctv_cameras');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'can_view_cctv']);
        });
    }
};

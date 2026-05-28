<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->unique();
            $table->string('qr_signature')->nullable()->after('uuid');
            $table->boolean('is_qr_enabled')->default(true)->after('qr_signature');
        });

        $appKey = config('app.key') ?: env('APP_KEY', 'restaurant-local-key');

        DB::table('mesas')->orderBy('id')->get()->each(function ($mesa) use ($appKey) {
            $uuid = (string) Str::uuid();
            DB::table('mesas')
                ->where('id', $mesa->id)
                ->update([
                    'uuid' => $uuid,
                    'qr_signature' => hash_hmac('sha256', $uuid, $appKey),
                ]);
        });

    }

    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'qr_signature', 'is_qr_enabled']);
        });
    }
};

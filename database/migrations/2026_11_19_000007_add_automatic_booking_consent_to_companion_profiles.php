<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companion_profiles', function (Blueprint $table): void {
            $table->boolean('accepts_automatic_bookings')->default(false)->after('publication_status');
            $table->timestamp('automatic_booking_consent_at')->nullable()->after('publication_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('companion_profiles', function (Blueprint $table): void {
            $table->dropColumn(['accepts_automatic_bookings', 'automatic_booking_consent_at']);
        });
    }
};

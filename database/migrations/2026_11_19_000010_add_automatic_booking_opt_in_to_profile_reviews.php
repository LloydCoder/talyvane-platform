<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_publication_reviews', function (Blueprint $table): void {
            $table->boolean('automatic_booking_opt_in')->default(false)->after('consent_version');
        });
    }

    public function down(): void
    {
        Schema::table('profile_publication_reviews', function (Blueprint $table): void {
            $table->dropColumn('automatic_booking_opt_in');
        });
    }
};

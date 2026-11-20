<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safeguarding_case_contents', function (Blueprint $table): void {
            $table->string('key_provider', 16)->default('local')->after('case_id');
            $table->string('key_reference', 120)->nullable()->after('key_provider');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('safeguarding_case_contents', 'key_provider')
            && \Illuminate\Support\Facades\DB::table('safeguarding_case_contents')->where('key_provider', 'vault')->exists()) {
            throw new RuntimeException('Safeguarding content still depends on external Vault keys; migration rollback is unsafe.');
        }

        Schema::table('safeguarding_case_contents', function (Blueprint $table): void {
            $table->dropColumn(['key_provider', 'key_reference']);
        });
    }
};

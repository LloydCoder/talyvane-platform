<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('safeguarding_case_contents')
            || ! Schema::hasColumn('safeguarding_case_contents', 'key_reference')) {
            throw new RuntimeException('Safeguarding external key reference schema is unavailable.');
        }

        $duplicateReference = DB::table('safeguarding_case_contents')
            ->select('key_reference')
            ->whereNotNull('key_reference')
            ->groupBy('key_reference')
            ->havingRaw('COUNT(*) > 1')
            ->value('key_reference');

        if ($duplicateReference !== null) {
            throw new RuntimeException('Duplicate safeguarding external key references must be investigated before adding the unique constraint.');
        }

        Schema::table('safeguarding_case_contents', function (Blueprint $table): void {
            $table->unique('key_reference', 'safeguarding_case_contents_key_reference_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('safeguarding_case_contents')) {
            Schema::table('safeguarding_case_contents', function (Blueprint $table): void {
                $table->dropUnique('safeguarding_case_contents_key_reference_unique');
            });
        }
    }
};

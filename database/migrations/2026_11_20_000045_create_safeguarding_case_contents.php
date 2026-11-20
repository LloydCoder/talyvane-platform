<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('safeguarding_case_contents')) {
            Schema::create('safeguarding_case_contents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('case_id')->unique()->constrained('safeguarding_cases')->restrictOnDelete();
                $table->longText('key_ciphertext')->nullable();
                $table->longText('summary_ciphertext')->nullable();
                $table->longText('details_ciphertext')->nullable();
                $table->longText('subject_reference_ciphertext')->nullable();
                $table->longText('legal_hold_reason_ciphertext')->nullable();
                $table->longText('legal_hold_release_reason_ciphertext')->nullable();
                $table->timestamp('retention_erased_at')->nullable();
                $table->foreignId('retention_erased_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('retention_policy_version', 80)->nullable();
                $table->timestamps();
            });
        }

        DB::transaction(function (): void {
            DB::table('safeguarding_cases')->orderBy('id')->chunkById(100, function ($cases): void {
                foreach ($cases as $case) {
                    if (DB::table('safeguarding_case_contents')->where('case_id', $case->id)->exists()) {
                        continue;
                    }

                    $dataKey = random_bytes(32);
                    $encrypter = new Encrypter($dataKey, (string) config('app.cipher'));
                    $ciphertext = fn (?string $value): ?string => $value === null
                        ? null
                        : $encrypter->encryptString((string) Crypt::decryptString($value));

                    DB::table('safeguarding_case_contents')->insert([
                        'case_id' => $case->id,
                        'key_ciphertext' => Crypt::encryptString(base64_encode($dataKey)),
                        'summary_ciphertext' => $ciphertext($case->summary),
                        'details_ciphertext' => $ciphertext($case->details),
                        'subject_reference_ciphertext' => $ciphertext($case->subject_reference),
                        'legal_hold_reason_ciphertext' => $ciphertext($case->legal_hold_reason),
                        'legal_hold_release_reason_ciphertext' => $ciphertext($case->legal_hold_release_reason),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('safeguarding_case_events')
                        ->where('case_id', $case->id)
                        ->whereNotNull('note')
                        ->orderBy('id')
                        ->get(['id', 'note'])
                        ->each(function ($event) use ($encrypter): void {
                            DB::table('safeguarding_case_events')
                                ->where('id', $event->id)
                                ->update(['note' => $encrypter->encryptString((string) Crypt::decryptString($event->note))]);
                        });

                    DB::table('safeguarding_cases')->where('id', $case->id)->update([
                        'summary' => null,
                        'details' => null,
                        'subject_reference' => null,
                        'legal_hold_reason' => null,
                        'legal_hold_release_reason' => null,
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        if (DB::table('safeguarding_case_contents')->whereNotNull('retention_erased_at')->exists()) {
            throw new RuntimeException('Safeguarding content has passed retention erasure and cannot be restored by rolling back this migration.');
        }

        DB::transaction(function (): void {
            DB::table('safeguarding_cases')->orderBy('id')->chunkById(100, function ($cases): void {
                foreach ($cases as $case) {
                    $content = DB::table('safeguarding_case_contents')->where('case_id', $case->id)->first();
                    if (! $content || ! $content->key_ciphertext) {
                        throw new RuntimeException('A safeguarding case content key is unavailable; migration rollback is unsafe.');
                    }

                    $dataKey = base64_decode(Crypt::decryptString($content->key_ciphertext), true);
                    if (! is_string($dataKey) || strlen($dataKey) !== 32) {
                        throw new RuntimeException('A safeguarding case content key is invalid; migration rollback is unsafe.');
                    }

                    $encrypter = new Encrypter($dataKey, (string) config('app.cipher'));
                    $plain = fn (?string $value): ?string => $value === null
                        ? null
                        : Crypt::encryptString($encrypter->decryptString($value));

                    DB::table('safeguarding_cases')->where('id', $case->id)->update([
                        'summary' => $plain($content->summary_ciphertext),
                        'details' => $plain($content->details_ciphertext),
                        'subject_reference' => $plain($content->subject_reference_ciphertext),
                        'legal_hold_reason' => $plain($content->legal_hold_reason_ciphertext),
                        'legal_hold_release_reason' => $plain($content->legal_hold_release_reason_ciphertext),
                    ]);

                    DB::table('safeguarding_case_events')
                        ->where('case_id', $case->id)
                        ->whereNotNull('note')
                        ->orderBy('id')
                        ->get(['id', 'note'])
                        ->each(function ($event) use ($encrypter): void {
                            DB::table('safeguarding_case_events')
                                ->where('id', $event->id)
                                ->update(['note' => Crypt::encryptString($encrypter->decryptString($event->note))]);
                        });
                }
            });
        });

        Schema::dropIfExists('safeguarding_case_contents');
    }
};

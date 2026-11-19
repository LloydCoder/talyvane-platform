<?php

namespace App\Console\Commands;

use App\Services\ModelAgencyApplicationRetentionService;
use Illuminate\Console\Command;

class ApplyModelAgencyApplicationRetention extends Command
{
    protected $signature = 'talyvane:model-agency:apply-retention';

    protected $description = 'Anonymize model-agency applications that have reached their approved retention period';

    public function handle(ModelAgencyApplicationRetentionService $retention): int
    {
        try {
            $count = $retention->apply();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Anonymized '.$count.' expired model-agency application record(s).');

        return self::SUCCESS;
    }
}

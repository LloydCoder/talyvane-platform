<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('talyvane:about', function () {
    $this->comment('Talyvane platform foundation');
})->purpose('Display Talyvane application information');

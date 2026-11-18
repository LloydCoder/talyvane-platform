<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

Artisan::command('talyvane:about', function () {
    $this->comment('Talyvane platform foundation');
})->purpose('Display Talyvane application information');

Artisan::command('talyvane:admin:create {email}', function () {
    $email = strtolower(trim((string) $this->argument('email')));

    if (User::query()->where('email', $email)->exists()) {
        $this->error('An account with that email already exists.');

        return;
    }

    $name = trim((string) $this->ask('Administrator name'));
    $password = (string) $this->secret('Password (minimum 14 characters)');
    $confirmation = (string) $this->secret('Confirm password');

    if ($name === '' || strlen($password) < 14 || ! hash_equals($password, $confirmation)) {
        $this->error('Invalid name, password length, or password confirmation.');

        return;
    }

    User::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'role' => 'owner',
        'is_active' => true,
    ]);

    $this->info('Initial platform owner created. Store the credentials securely and enable MFA before remote administration.');
})->purpose('Create the first platform owner from a trusted command line');

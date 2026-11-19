<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('talyvane:about', function () {
    $this->comment('Talyvane platform foundation');
})->purpose('Display Talyvane application information');


Artisan::command('talyvane:super-admin:create {email}', function () {
    $email = strtolower(trim((string) $this->argument('email')));

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('A valid email address is required.');

        return 1;
    }

    if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
        $this->error('An account with that email already exists.');

        return 1;
    }

    $control = DB::table('platform_control')->where('id', 1)->first();

    if (! $control) {
        $this->error('Run the database migrations before provisioning the deployment Super Admin.');

        return 1;
    }

    if ($control->super_admin_user_id !== null || User::query()->where('role', 'super_admin')->exists()) {
        $this->error('A Super Admin is already provisioned for this deployment. Use the documented recovery process.');

        return 1;
    }

    $name = trim((string) $this->ask('Super Admin name'));
    $password = (string) $this->secret('Password (minimum 14 characters)');
    $confirmation = (string) $this->secret('Confirm password');

    $strongPassword = strlen($password) >= 14
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);

    if ($name === '' || ! $strongPassword || ! hash_equals($password, $confirmation)) {
        $this->error('A name, a strong 14-character password, and matching confirmation are required.');

        return 1;
    }

    $superAdmin = DB::transaction(function () use ($name, $email, $password): ?User {
        $control = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();

        if (! $control || $control->super_admin_user_id !== null || User::query()->where('role', 'super_admin')->exists()) {
            return null;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $user->id,
            'updated_at' => now(),
        ]);

        return $user;
    });

    if (! $superAdmin) {
        $this->error('A Super Admin is already provisioned for this deployment. Use the documented recovery process.');

        return 1;
    }

    $this->info('Deployment Super Admin created. Sign in and complete mandatory MFA setup before administration.');

    return 0;
})->purpose('Provision the single deployment Super Admin from a trusted command line');

Artisan::command('talyvane:admin:create {email}', function () {
    $email = strtolower(trim((string) $this->argument('email')));

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('A valid email address is required.');

        return;
    }

    if (User::query()->where('email', $email)->exists()) {
        $this->error('An account with that email already exists.');

        return;
    }

    $name = trim((string) $this->ask('Administrator name'));
    $password = (string) $this->secret('Password (minimum 14 characters)');
    $confirmation = (string) $this->secret('Confirm password');

    if ($name === '' || strlen($password) < 14 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/[0-9]/', $password) || ! hash_equals($password, $confirmation)) {
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

Artisan::command('talyvane:companion:create {email}', function () {
    $email = strtolower(trim((string) $this->argument('email')));

    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::query()->where('email', $email)->exists()) {
        $this->error('A valid, unused email address is required.');

        return;
    }

    $name = trim((string) $this->ask('Companion account name'));
    $password = (string) $this->secret('Temporary password (minimum 14 characters)');
    $confirmation = (string) $this->secret('Confirm temporary password');

    if ($name === '' || strlen($password) < 14 || ! hash_equals($password, $confirmation)) {
        $this->error('Invalid name, password length, or password confirmation.');

        return;
    }

    $user = User::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'role' => 'companion',
        'is_active' => true,
    ]);

    event(new Registered($user));

    $this->info('Companion account created. Deliver the temporary password through a separate secure channel. The companion must verify their email before using the portal.');
})->purpose('Provision a companion account from a trusted command line');

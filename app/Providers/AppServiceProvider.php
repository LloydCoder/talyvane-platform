<?php

namespace App\Providers;

use App\Install\EnvironmentWriter;
use App\Install\InstallationDirectoryRemover;
use App\Install\InstallerAccessToken;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EnvironmentWriter::class, fn () => new EnvironmentWriter(base_path()));
        $this->app->singleton(InstallationDirectoryRemover::class, fn () => new InstallationDirectoryRemover(base_path()));
        $this->app->singleton(InstallerAccessToken::class, fn () => new InstallerAccessToken(base_path()));
    }

    public function boot(): void
    {
        //
    }
}

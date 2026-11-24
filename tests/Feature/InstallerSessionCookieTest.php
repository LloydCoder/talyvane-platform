<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallerSessionCookieTest extends TestCase
{
    public function test_session_cookie_name_is_independent_of_the_customer_deployment_name(): void
    {
        $originalAppName = getenv('APP_NAME');
        $originalSessionCookie = getenv('SESSION_COOKIE');
        $originalEnvAppName = $_ENV['APP_NAME'] ?? null;
        $originalServerAppName = $_SERVER['APP_NAME'] ?? null;
        $originalEnvSessionCookie = $_ENV['SESSION_COOKIE'] ?? null;
        $originalServerSessionCookie = $_SERVER['SESSION_COOKIE'] ?? null;

        try {
            putenv('SESSION_COOKIE');
            unset($_ENV['SESSION_COOKIE'], $_SERVER['SESSION_COOKIE']);

            putenv('APP_NAME=Talyvane');
            $_ENV['APP_NAME'] = 'Talyvane';
            $defaultName = require config_path('session.php');

            putenv('APP_NAME=Talyvane E2E');
            $_ENV['APP_NAME'] = 'Talyvane E2E';
            $customerName = require config_path('session.php');

            $this->assertSame('talyvane-session', $defaultName['cookie']);
            $this->assertSame('talyvane-session', $customerName['cookie']);
        } finally {
            $originalAppName === false ? putenv('APP_NAME') : putenv('APP_NAME='.$originalAppName);
            $originalSessionCookie === false ? putenv('SESSION_COOKIE') : putenv('SESSION_COOKIE='.$originalSessionCookie);

            if ($originalEnvAppName === null) {
                unset($_ENV['APP_NAME']);
            } else {
                $_ENV['APP_NAME'] = $originalEnvAppName;
            }

            if ($originalServerAppName === null) {
                unset($_SERVER['APP_NAME']);
            } else {
                $_SERVER['APP_NAME'] = $originalServerAppName;
            }

            if ($originalEnvSessionCookie === null) {
                unset($_ENV['SESSION_COOKIE']);
            } else {
                $_ENV['SESSION_COOKIE'] = $originalEnvSessionCookie;
            }

            if ($originalServerSessionCookie === null) {
                unset($_SERVER['SESSION_COOKIE']);
            } else {
                $_SERVER['SESSION_COOKIE'] = $originalServerSessionCookie;
            }
        }
    }
}

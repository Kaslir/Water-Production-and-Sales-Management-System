<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BootstrapAdministrator extends Command
{
    protected $signature = 'app:bootstrap-administrator';

    protected $description = 'Create the first administrator in an empty installation';

    public function handle(AuditTrail $auditTrail): int
    {
        if (User::query()->exists()) {
            $this->error('Bootstrap is available only when no users exist.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Full name'));
        $email = Str::lower(trim((string) $this->ask('Email address')));
        $phone = trim((string) $this->ask('Phone number (0712345678)'));
        $password = (string) $this->secret('Password (at least 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');
        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! preg_match('/^0[17]\d{8}$/', $phone) || mb_strlen($password) < 12 || $password !== $confirmation) {
            $this->error('Invalid details or passwords did not match. No account was created.');

            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'phone_number' => $phone, 'password' => $password, 'role' => 'administrator', 'is_active' => true]);
        $auditTrail->record('administrator.bootstrapped', $user);
        $this->info('Initial administrator created.');

        return self::SUCCESS;
    }
}

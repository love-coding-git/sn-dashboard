<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Create or update the dashboard's one login account from .env (DASHBOARD_ADMIN_NAME / _EMAIL / _PASSWORD). The
 * password is stored hashed in the users table, as the app's login (Fortify) expects; it is never printed.
 */
class DashboardAdmin extends Command
{
    protected $signature = 'dashboard:admin';

    protected $description = 'Create or update the dashboard login account from .env (DASHBOARD_ADMIN_*)';

    public function handle(): int
    {
        $admin = config('dashboard.admin');
        if ($admin['email'] === '' || $admin['password'] === '') {
            $this->error('Set DASHBOARD_ADMIN_EMAIL and DASHBOARD_ADMIN_PASSWORD in .env first.');

            return self::FAILURE;
        }
        if (strlen($admin['password']) < 12) {
            $this->error('DASHBOARD_ADMIN_PASSWORD must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $admin['email']]);
        $created = ! $user->exists;
        $user->name = $admin['name'] ?: 'admin';
        if ($created || ! Hash::check($admin['password'], $user->password)) {
            $user->password = Hash::make($admin['password']);
        }
        $user->email_verified_at ??= now();
        $user->save();
        $this->info(($created ? 'Created' : 'Updated').' the login account '.$user->email.'.');

        return self::SUCCESS;
    }
}

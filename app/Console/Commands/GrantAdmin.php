<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The only way to grant or revoke administrator rights. is_admin is never mass-assignable, so no
 * HTTP request can change it; this command requires shell access and is audited.
 */
class GrantAdmin extends Command
{
    protected $signature = 'gamevault:admin {email} {--revoke : Remove administrator rights instead}';

    protected $description = 'Grant (or revoke) administrator rights for a user';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that e-mail address.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_admin' => $grant])->save();
        AuditLog::record($grant ? 'user.admin_granted' : 'user.admin_revoked', null, $user);

        $this->info(($grant ? 'Granted' : 'Revoked')." administrator rights for {$user->email}.");

        return self::SUCCESS;
    }
}

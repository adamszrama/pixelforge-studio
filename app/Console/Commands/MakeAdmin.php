<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

final class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin {email}';

    protected $description = 'Grant administrator access to an existing user';

    public function handle(): int
    {
        User::where('email', $this->argument('email'))
            ->firstOrFail()
            ->forceFill(['is_admin' => true])
            ->save();

        $this->info('Administrator access granted.');

        return self::SUCCESS;
    }
}

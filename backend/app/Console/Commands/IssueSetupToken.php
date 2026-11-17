<?php

namespace App\Console\Commands;

use App\Exceptions\AuthFlowException;
use App\Services\AdministratorSetup;
use Illuminate\Console\Command;

class IssueSetupToken extends Command
{
    protected $signature = 'app:setup-token {--raw : Print only the token}';

    protected $description = 'Issue or rotate the one-time first-administrator token (valid for one hour)';

    public function handle(AdministratorSetup $setup): int
    {
        try {
            $token = $setup->issueToken();
        } catch (AuthFlowException) {
            $this->error('Administrator setup is closed. Existing users must be managed by an administrator.');

            return self::FAILURE;
        }

        if (! $this->option('raw')) {
            $this->info('One-time administrator setup token (expires in one hour):');
        }

        $this->line($token);

        return self::SUCCESS;
    }
}

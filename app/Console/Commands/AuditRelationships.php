<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RelationshipCheckService;

class AuditRelationships extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monica:audit-relationships
                            {--fix : Create missing reciprocal relationships instead of just reporting them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find (and optionally fix) relationships missing their reciprocal counterpart';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $service = new RelationshipCheckService;
        $missing = $service->getMissingReciprocals();

        if (empty($missing)) {
            $this->info('All relationships have their reciprocal. Nothing to do.');

            return self::SUCCESS;
        }

        $headers = ['From', 'Type', 'To', 'Missing reciprocal type'];
        $rows    = array_map(fn ($m) => [
            $m['from_name'],
            $m['type_name'],
            $m['to_name'],
            $m['rev_type_name'],
        ], $missing);

        $this->table($headers, $rows);
        $this->line('');
        $this->warn(count($missing).' missing reciprocal(s) found.');

        if (! $this->option('fix')) {
            $this->line('Run with --fix to create the missing reciprocals.');

            return self::SUCCESS;
        }

        $created = $service->fixMissingReciprocals();
        $this->line('');
        $this->info("{$created} reciprocal(s) created.");

        return self::SUCCESS;
    }
}

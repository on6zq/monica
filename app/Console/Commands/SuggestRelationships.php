<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RelationshipCheckService;

class SuggestRelationships extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monica:suggest-relationships
                            {--fix : Create the suggested relationships instead of just reporting them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Infer missing relationships from existing ones (siblings, grandparents, uncles/nephews)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $service     = new RelationshipCheckService;
        $suggestions = $service->getSuggestedRelationships();

        if (empty($suggestions)) {
            $this->info('No relationship suggestions found.');

            return self::SUCCESS;
        }

        $headers = ['From', 'Suggested type', 'To', 'Reason'];
        $rows    = array_map(fn ($s) => [
            $s['from_name'],
            $s['type_name'],
            $s['to_name'],
            $s['reason'],
        ], $suggestions);

        $this->table($headers, $rows);
        $this->line('');
        $this->warn(count($suggestions).' suggestion(s) found.');

        if (! $this->option('fix')) {
            $this->line('Run with --fix to create these relationships.');

            return self::SUCCESS;
        }

        $created = $service->applySuggestions();
        $this->line('');
        $this->info("{$created} relationship(s) created (reciprocals included automatically).");

        return self::SUCCESS;
    }
}

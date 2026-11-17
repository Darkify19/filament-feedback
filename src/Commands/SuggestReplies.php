<?php

namespace CarlJanzell\FilamentFeedback\Commands;

use CarlJanzell\FilamentFeedback\Actions\ImportSuggestions;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Loads a suggestions file into the inbox. Writes drafts only; a triager sends them.
 */
class SuggestReplies extends Command
{
    protected $signature = 'feedback:suggest
        {file : The suggestions JSON (see ImportSuggestions)}
        {--dry-run : Check the file and report, without saving}';

    protected $description = 'Import suggested replies into the Feedback inbox for a triager to send';

    public function handle(ImportSuggestions $import): int
    {
        $file = (string) $this->argument('file');
        $payload = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($payload)) {
            $this->error(is_readable($file) ? 'Not valid JSON: '.json_last_error_msg() : "Can't read {$file}");

            return self::FAILURE;
        }

        try {
            $report = $import->execute($payload, $dryRun = (bool) $this->option('dry-run'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $skipped = count($report['skipped']);
        $this->info($dryRun
            ? "Dry run, nothing saved: would create {$report['created']}, supersede {$report['superseded']}, skip {$skipped}."
            : "Created {$report['created']}, superseded {$report['superseded']}, skipped {$skipped}.");

        if ($skipped > 0) {
            $this->table(['Post', 'Skipped because'], array_map(
                fn (array $skip): array => [is_scalar($skip['post_id']) ? $skip['post_id'] : json_encode($skip['post_id']), $skip['reason']],
                $report['skipped'],
            ));
        }

        return self::SUCCESS;
    }
}

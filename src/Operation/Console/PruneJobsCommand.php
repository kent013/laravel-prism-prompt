<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Kent013\PrismPrompt\Operation\Models\PromptJob;

class PruneJobsCommand extends Command
{
    protected $signature = 'prism:prompt-jobs:prune
        {--completed-older-than= : completed job retention (e.g. 30d)}
        {--failed-older-than= : failed job retention (e.g. 90d)}
        {--cancelled-older-than= : cancelled job retention (e.g. 90d)}
        {--dry-run : Show counts only}';

    protected $description = 'Prune old prism_prompt_jobs rows by retention policy';

    public function handle(): int
    {
        $config = (array) config('prism-prompt.jobs.retention', []);
        $completedDays = $this->resolveDays(
            $this->option('completed-older-than'),
            (int) ($config['completed_days'] ?? 30),
        );
        $failedDays = $this->resolveDays(
            $this->option('failed-older-than'),
            (int) ($config['failed_days'] ?? 90),
        );
        $cancelledDays = $this->resolveDays(
            $this->option('cancelled-older-than'),
            (int) ($config['cancelled_days'] ?? 90),
        );
        $dryRun = (bool) $this->option('dry-run');

        $now = CarbonImmutable::now();

        $stats = [
            'completed' => $this->cleanByStatus('completed', $now->subDays($completedDays), $dryRun),
            'failed' => $this->cleanByStatus('failed', $now->subDays($failedDays), $dryRun),
            'cancelled' => $this->cleanByStatus('cancelled', $now->subDays($cancelledDays), $dryRun),
        ];

        foreach ($stats as $kind => $count) {
            $this->info(sprintf('%s: %d row(s) %s', $kind, $count, $dryRun ? '(dry-run)' : 'deleted'));
        }

        return self::SUCCESS;
    }

    private function cleanByStatus(string $status, CarbonImmutable $cutoff, bool $dryRun): int
    {
        $query = PromptJob::query()
            ->where('status', $status);
        if ($status === 'completed') {
            $query->where('completed_at', '<', $cutoff);
        } elseif ($status === 'cancelled') {
            $query->where('cancelled_at', '<', $cutoff);
        } else {
            $query->where('updated_at', '<', $cutoff);
        }

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }

    private function resolveDays(mixed $option, int $default): int
    {
        if (! is_string($option) || $option === '') {
            return $default;
        }
        if (preg_match('/^(\d+)d$/', $option, $m)) {
            return (int) $m[1];
        }

        return (int) $option;
    }
}

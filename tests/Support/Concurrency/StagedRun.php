<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Workers that are fully booted and parked at the barrier, waiting for go().
 *
 * Lets a test arrange database state (e.g. hold a row lock, or let a lease expire) AFTER the
 * workers have paid their process start-up cost, so start-up time can never fall inside a race
 * window. Under load, booting a PHP process can take several seconds.
 */
final class StagedRun
{
    private bool $released = false;

    /** @param  array<int, Process>  $processes */
    public function __construct(private readonly string $dir, private readonly array $processes) {}

    /** Release every parked worker at the same instant. */
    public function go(): self
    {
        touch($this->dir.'/go');
        $this->released = true;

        return $this;
    }

    /** @return array<int, Process> */
    public function processes(): array
    {
        return $this->processes;
    }

    /**
     * Wait for every worker and collect its result.
     *
     * @return list<WorkerResult>
     */
    public function results(): array
    {
        if (! $this->released) {
            $this->go();
        }

        try {
            $results = [];
            foreach ($this->processes as $i => $process) {
                $process->wait();
                $results[$i] = WorkerResult::fromProcess($i, $process);
            }

            return $results;
        } finally {
            $this->cleanUp();
        }
    }

    public function cleanUp(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        File::deleteDirectory($this->dir);
    }
}

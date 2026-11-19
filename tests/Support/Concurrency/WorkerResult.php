<?php

namespace Tests\Support\Concurrency;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Structured outcome of one worker process.
 */
final readonly class WorkerResult
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public int $index,
        public bool $ok,
        public array $result,
        public float $startedAt,
        public float $finishedAt,
        public int $transactionsBegun,
        public int $transactionsRolledBack,
        public ?float $lastCommitAt,
        public int $exitCode,
        public string $stderr,
    ) {}

    public static function fromProcess(int $index, Process $process): self
    {
        $output = $process->getOutput();
        $marker = strrpos($output, '@@RESULT@@');

        if ($marker === false) {
            throw new RuntimeException(sprintf(
                "Worker %d produced no result (exit %s).\nSTDOUT: %s\nSTDERR: %s",
                $index, var_export($process->getExitCode(), true), $output, $process->getErrorOutput(),
            ));
        }

        $payload = json_decode(trim(substr($output, $marker + strlen('@@RESULT@@'))), true, flags: JSON_THROW_ON_ERROR);

        return new self(
            index: $index,
            ok: (bool) $payload['ok'],
            result: (array) $payload['result'],
            startedAt: (float) $payload['started_at'],
            finishedAt: (float) $payload['finished_at'],
            transactionsBegun: (int) $payload['transactions_begun'],
            transactionsRolledBack: (int) $payload['transactions_rolled_back'],
            lastCommitAt: isset($payload['last_commit_at']) ? (float) $payload['last_commit_at'] : null,
            exitCode: (int) $process->getExitCode(),
            stderr: $process->getErrorOutput(),
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->result[$key] ?? $default;
    }

    public function describe(): string
    {
        return sprintf('#%d ok=%s %s', $this->index, $this->ok ? 'yes' : 'no', json_encode($this->result));
    }
}

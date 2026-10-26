<?php

declare(strict_types=1);

namespace Rector\Parallel\Application;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use Nette\Utils\Random;
use PHPStan\Turbo\ForkedChild;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\TcpServer;
use Rector\Autoloading\BootstrapFilesIncluder;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\Console\Command\ProcessCommand;
use Rector\Console\Command\WorkerCommand;
use Rector\Parallel\Command\WorkerCommandLineFactory;
use Rector\Parallel\Enum\Action;
use Rector\Parallel\Enum\Content;
use Rector\Parallel\Enum\ReactCommand;
use Rector\Parallel\Enum\ReactEvent;
use Rector\Parallel\Enum\StreamFormat;
use Rector\Parallel\ForkSupport;
use Rector\Parallel\ValueObject\Bridge;
use Rector\Parallel\ValueObject\ParallelProcess;
use Rector\Parallel\ValueObject\ProcessPool;
use Rector\Parallel\ValueObject\Schedule;
use Rector\ValueObject\Error\SystemError;
use Rector\ValueObject\ProcessResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

/**
 * Workers keep their caches for the whole run and process their own balanced bucket first.
 * Idle workers take pending chunks from the largest remaining queue.
 * Each chunk advances progress and re-arms the per-job timeout.
 */
final class ParallelFileProcessor
{
    private const int SYSTEM_ERROR_LIMIT = 50;

    /**
     * @var int[]
     */
    private const array FATAL_ERROR_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    private ProcessPool $processPool;

    private WorkerCommand $workerCommand;

    public function __construct(
        private readonly WorkerCommandLineFactory $workerCommandLineFactory,
        private readonly BootstrapFilesIncluder $bootstrapFilesIncluder,
    ) {
    }

    /**
     * The worker command depends on this class, so it cannot be a constructor dependency
     */
    public function autowire(WorkerCommand $workerCommand): void
    {
        $this->workerCommand = $workerCommand;
    }

    /**
     * @param callable(int $stepCount): void $postFileCallback Used for progress bar jump
     */
    public function process(
        Schedule $schedule,
        string $mainScript,
        callable $postFileCallback,
        InputInterface $input
    ): ProcessResult {
        // reversed, so that array_pop() hands out the biggest files first
        $jobsPerWorker = array_map(
            array_reverse(...),
            $schedule->getJobsPerWorker()
        );

        /** @var array<string, int> $bucketKeyByIdentifier */
        $bucketKeyByIdentifier = [];

        $streamSelectLoop = new StreamSelectLoop();

        $parallelResultCollector = new ParallelResultCollector();

        $tcpServer = new TcpServer('127.0.0.1:0', $streamSelectLoop);
        $this->processPool = new ProcessPool($tcpServer);

        $tcpServer->on(ReactEvent::CONNECTION, function (ConnectionInterface $connection) use (
            &$jobsPerWorker,
            &$bucketKeyByIdentifier
        ): void {
            $inDecoder = new Decoder($connection, true, StreamFormat::DEPTH, 0, StreamFormat::MAX_LENGTH);
            $outEncoder = new Encoder($connection);

            $inDecoder->on(ReactEvent::DATA, function (array $data) use (
                &$jobsPerWorker,
                &$bucketKeyByIdentifier,
                $inDecoder,
                $outEncoder
            ): void {
                $action = $data[ReactCommand::ACTION];
                if ($action !== Action::HELLO) {
                    return;
                }

                $processIdentifier = $data[Option::PARALLEL_IDENTIFIER];
                $parallelProcess = $this->processPool->getProcess($processIdentifier);
                $parallelProcess->bindConnection($inDecoder, $outEncoder);

                $jobsChunk = $this->popJobChunk($processIdentifier, $jobsPerWorker, $bucketKeyByIdentifier);
                if ($jobsChunk === null) {
                    $this->processPool->quitProcess($processIdentifier);
                    return;
                }

                $parallelProcess->request([
                    ReactCommand::ACTION => Action::MAIN,
                    Content::FILES => $jobsChunk,
                ]);
            });
        });

        /** @var string $serverAddress */
        $serverAddress = $tcpServer->getAddress();

        /** @var int $serverPort */
        $serverPort = parse_url($serverAddress, PHP_URL_PORT);

        $scheduledFilesCount = 0;
        foreach ($jobsPerWorker as $jobs) {
            $scheduledFilesCount += array_sum(array_map(count(...), $jobs));
        }

        $processedFilesCount = 0;
        $systemErrorsCount = 0;
        $reachedSystemErrorsCountLimit = false;

        $handleErrorCallable = function (Throwable $throwable) use (
            $parallelResultCollector,
            &$systemErrorsCount,
            &$reachedSystemErrorsCountLimit
        ): void {
            $parallelResultCollector->collectSystemError(new SystemError(
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine(),
            ));

            ++$systemErrorsCount;
            $reachedSystemErrorsCountLimit = $systemErrorsCount >= self::SYSTEM_ERROR_LIMIT;
            $this->processPool->quitAll();

            // give the child processes time to actually die, they can still write to cache meanwhile
            // @see https://github.com/rectorphp/rector-src/pull/3834/files#r1231696531
            sleep(1);
        };

        $timeoutInSeconds = SimpleParameterProvider::provideIntParameter(Option::PARALLEL_JOB_TIMEOUT_IN_SECONDS);

        $isForking = ForkSupport::isAvailable();

        $processSpawner = function (int $bucketKey) use (
            $parallelResultCollector,
            &$jobsPerWorker,
            &$bucketKeyByIdentifier,
            $postFileCallback,
            &$systemErrorsCount,
            &$reachedSystemErrorsCountLimit,
            $mainScript,
            $input,
            $serverPort,
            $streamSelectLoop,
            $timeoutInSeconds,
            $handleErrorCallable,
            &$processedFilesCount,
            $isForking,
            $tcpServer
        ): void {
            $processIdentifier = Random::generate();
            $bucketKeyByIdentifier[$processIdentifier] = $bucketKey;

            $workerCommandLine = $this->workerCommandLineFactory->create(
                $mainScript,
                ProcessCommand::class,
                'worker',
                $input,
                $processIdentifier,
                $serverPort,
            );

            $forkedWorker = $isForking ? $this->createForkedWorker(
                $tcpServer,
                $this->workerCommandLineFactory->createWorkerArguments(
                    ProcessCommand::class,
                    'worker',
                    $input,
                    $processIdentifier,
                    $serverPort,
                )
            ) : null;
            $parallelProcess = new ParallelProcess(
                $workerCommandLine,
                $streamSelectLoop,
                $timeoutInSeconds,
                $forkedWorker
            );

            $parallelProcess->start(
                // 1. callable on data
                function (array $json) use (
                    $parallelProcess,
                    $parallelResultCollector,
                    &$jobsPerWorker,
                    &$bucketKeyByIdentifier,
                    $postFileCallback,
                    &$systemErrorsCount,
                    &$reachedSystemErrorsCountLimit,
                    $processIdentifier,
                    &$processedFilesCount
                ): void {
                    /** @var array{
                     *      total_changed: int,
                     *      system_errors: mixed[],
                     *      file_diffs: array<string, mixed>,
                     *      files_count: int,
                     *      system_errors_count: int,
                     *      used_skips: array<string, string[]>
                     * } $json */
                    $filesCount = $parallelResultCollector->collectWorkerResult($json);
                    $postFileCallback($filesCount);
                    $processedFilesCount += $filesCount;

                    $systemErrorsCount += $json[Bridge::SYSTEM_ERRORS_COUNT];
                    if ($systemErrorsCount >= self::SYSTEM_ERROR_LIMIT) {
                        $reachedSystemErrorsCountLimit = true;
                        $this->processPool->quitAll();
                        return;
                    }

                    $jobsChunk = $this->popJobChunk($processIdentifier, $jobsPerWorker, $bucketKeyByIdentifier);
                    if ($jobsChunk === null) {
                        // bucket done, this worker has nothing left to do
                        $this->processPool->quitProcess($processIdentifier);
                        return;
                    }

                    $parallelProcess->request([
                        ReactCommand::ACTION => Action::MAIN,
                        Content::FILES => $jobsChunk,
                    ]);
                },

                // 2. callable on error
                $handleErrorCallable,

                // 3. callable on exit
                function ($exitCode, string $stdErr) use ($parallelResultCollector, $processIdentifier): void {
                    $this->processPool->tryQuitProcess($processIdentifier);
                    if ($exitCode === Command::SUCCESS) {
                        return;
                    }

                    if ($exitCode === null) {
                        return;
                    }

                    $parallelResultCollector->collectSystemError(new SystemError(
                        'Child process error: ' . $stdErr
                    ));
                }
            );

            $this->processPool->attachProcess($processIdentifier, $parallelProcess);
        };

        foreach (array_keys($jobsPerWorker) as $bucketKey) {
            ($processSpawner)($bucketKey);
        }

        // every worker is forked by now, so the bootstrap files can no longer leak into them
        $this->bootstrapFilesIncluder->includeDeferredBootstrapFiles();

        $streamSelectLoop->run();

        if ($reachedSystemErrorsCountLimit) {
            $parallelResultCollector->collectSystemError(new SystemError(sprintf(
                'Reached system errors count limit of %d, exiting...',
                self::SYSTEM_ERROR_LIMIT
            )));
        }

        // Workers may exit without reporting their assigned files.
        if ($processedFilesCount < $scheduledFilesCount) {
            $parallelResultCollector->collectSystemError(new SystemError(sprintf(
                'Some parallel jobs have not finished, results for %d of %d files are missing',
                $scheduledFilesCount - $processedFilesCount,
                $scheduledFilesCount
            )));
        }

        return $parallelResultCollector->createProcessResult();
    }

    /**
     * Mirrors @see https://github.com/phpstan/phpstan-src/blob/2.3.x/src/Parallel/ForkedProcess.php
     *
     * @return callable(resource $stdErr): never
     */
    private function createForkedWorker(TcpServer $tcpServer, string $workerArguments): callable
    {
        return function ($stdErr) use ($tcpServer, $workerArguments): never {
            // the worker connects to the main process like a spawned one, and accepts no connections itself
            $tcpServer->close();

            // stay off the shared OPcache: siblings that fill it at the same time crash each other
            ini_set('opcache.enable', '0');

            // report the worker's own memory peak, not the one of the main process
            memory_reset_peak_usage();

            $this->reportFatalErrorsTo($stdErr);

            // the last shutdown function: skip the destructors and the extension shutdown inherited from the main process
            register_shutdown_function(static function (): void {
                ForkedChild::exitImmediately();
            });

            $this->bootstrapFilesIncluder->includeDeferredBootstrapFiles();

            exit($this->workerCommand->run(new StringInput($workerArguments), new StreamOutput($stdErr)));
        };
    }

    /**
     * A forked worker shares the console of the main process. Without this, a fatal error is printed there,
     * and the main process gets an empty error message.
     *
     * @param resource $stdErr
     */
    private function reportFatalErrorsTo($stdErr): void
    {
        ini_set('display_errors', '0');
        if (in_array(ini_get('error_log'), ['', false], true)) {
            ini_set('log_errors', '0');
        }

        // unbuffered, so the report needs no memory when the worker ran out of it
        stream_set_write_buffer($stdErr, 0);

        register_shutdown_function(static function () use ($stdErr): void {
            $error = error_get_last();
            if ($error === null || ! in_array($error['type'], self::FATAL_ERROR_TYPES, true)) {
                return;
            }

            fwrite(
                $stdErr,
                sprintf("PHP Fatal error:  %s in %s on line %d\n", $error['message'], $error['file'], $error['line'])
            );
        });
    }

    /**
     * @param array<int, array<int, array<string>>> $jobsPerWorker
     * @param array<string, int> $bucketKeyByIdentifier
     * @return array<string>|null
     */
    private function popJobChunk(
        string $processIdentifier,
        array &$jobsPerWorker,
        array $bucketKeyByIdentifier
    ): ?array {
        $bucketKey = $bucketKeyByIdentifier[$processIdentifier] ?? null;

        if ($bucketKey !== null && ($jobsPerWorker[$bucketKey] ?? []) !== []) {
            return array_pop($jobsPerWorker[$bucketKey]);
        }

        return $this->stealJobChunk($jobsPerWorker);
    }

    /**
     * Own bucket drained while others still have work. Byte size only estimates the cost of a file, and
     * not every core is equally fast - on a big.LITTLE CPU (Apple silicon, recent Intel) a bucket handed
     * to an efficiency core takes several times longer. Take over a chunk from whoever has the most left,
     * so the run is not held up by one straggler.
     *
     * @param array<int, array<int, array<string>>> $jobsPerWorker
     * @return array<string>|null
     */
    private function stealJobChunk(array &$jobsPerWorker): ?array
    {
        $fattestBucketKey = null;
        $fattestJobCount = 0;

        foreach ($jobsPerWorker as $currentBucketKey => $jobs) {
            $jobCount = count($jobs);
            if ($jobCount <= $fattestJobCount) {
                continue;
            }

            $fattestJobCount = $jobCount;
            $fattestBucketKey = $currentBucketKey;
        }

        if ($fattestBucketKey === null) {
            return null;
        }

        return array_pop($jobsPerWorker[$fattestBucketKey]);
    }
}

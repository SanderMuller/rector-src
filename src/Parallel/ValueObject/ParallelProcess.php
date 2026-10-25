<?php

declare(strict_types=1);

namespace Rector\Parallel\ValueObject;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use Exception;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Rector\Parallel\Enum\Action;
use Rector\Parallel\Enum\Content;
use Rector\Parallel\Enum\ReactCommand;
use Rector\Parallel\Enum\ReactEvent;
use Rector\Parallel\Exception\ParallelShouldNotHappenException;
use Throwable;

/**
 * Inspired at @see https://raw.githubusercontent.com/phpstan/phpstan-src/master/src/Parallel/Process.php
 * @see \Rector\Tests\Parallel\ValueObject\ParallelProcessTest
 */
final class ParallelProcess
{
    private const float WAITPID_INTERVAL_IN_SECONDS = 0.01;

    private Process $process;

    private Encoder $encoder;

    /**
     * @var resource|null
     */
    private $stdErr;

    /**
     * @var callable(mixed[]) : void
     */
    private $onData;

    /**
     * @var callable(Throwable): void
     */
    private $onError;

    private ?TimerInterface $timer = null;

    private ?int $forkedPid = null;

    private ?TimerInterface $waitTimer = null;

    /**
     * Runs the worker in a forked child, instead of the command
     *
     * @var (callable(resource): never)|null
     */
    private $forkedWorker;

    /**
     * @param (callable(resource $stdErr): never)|null $forkedWorker
     */
    public function __construct(
        private readonly string $command,
        private readonly LoopInterface $loop,
        private readonly int $timetoutInSeconds,
        ?callable $forkedWorker = null
    ) {
        $this->forkedWorker = $forkedWorker;
    }

    /**
     * @param callable(mixed[] $onData) : void $onData
     * @param callable(Throwable $onError) : void $onError
     * @param callable(?int $onExit, string $output) : void $onExit
     */
    public function start(callable $onData, callable $onError, callable $onExit): void
    {
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new ParallelShouldNotHappenException('Failed creating temp file.');
        }

        $this->stdErr = $tmp;

        $this->onData = $onData;
        $this->onError = $onError;

        $forkedWorker = $this->forkedWorker;
        if ($forkedWorker !== null) {
            $this->startForked($forkedWorker, $tmp, $onExit);
            return;
        }

        // on Unix, the command runs in a wrapping shell; exec replaces the shell with the worker,
        // so terminating the process stops the worker itself, not only the shell
        $command = DIRECTORY_SEPARATOR === '\\' ? $this->command : 'exec ' . $this->command;

        $this->process = new Process($command, null, null, [
            2 => $this->stdErr,
            // todo is it fine to not have 0 and 1 FD?
        ]);
        $this->process->start($this->loop);

        $this->process->on(ReactEvent::EXIT, function ($exitCode) use ($onExit): void {
            $this->cancelTimer();
            $onExit($exitCode, $this->readStdErr());
        });
    }

    /**
     * @param mixed[] $data
     */
    public function request(array $data): void
    {
        $this->cancelTimer();
        $this->encoder->write($data);
        $this->timer = $this->loop->addTimer($this->timetoutInSeconds, function (): void {
            // a worker that does not answer in time cannot be asked to stop either
            $this->terminate();

            $onError = $this->onError;

            $errorMessage = sprintf('Child process timed out after %d seconds', $this->timetoutInSeconds);
            $onError(new Exception($errorMessage));
        });
    }

    public function quit(): void
    {
        if ($this->forkedPid !== null) {
            $this->quitForked();
            return;
        }

        if (! $this->process->isRunning()) {
            return;
        }

        foreach ($this->process->pipes as $pipe) {
            $pipe->close();
        }

        // the process can be quit before its connection is bound, e.g. on quitAll() after an error;
        // such a worker cannot be asked to stop
        if (! isset($this->encoder)) {
            $this->process->terminate();
            return;
        }

        // a busy worker keeps its timeout, so it is still terminated when it never finishes its job
        $this->encoder->end();
    }

    public function bindConnection(Decoder $decoder, Encoder $encoder): void
    {
        $decoder->on(ReactEvent::DATA, function (array $json): void {
            $this->cancelTimer();
            if ($json[ReactCommand::ACTION] !== Action::RESULT) {
                return;
            }

            $onData = $this->onData;
            $onData($json[Content::RESULT]);
        });
        $this->encoder = $encoder;

        $decoder->on(ReactEvent::ERROR, function (Throwable $throwable): void {
            $onError = $this->onError;
            $onError($throwable);
        });

        $encoder->on(ReactEvent::ERROR, function (Throwable $throwable): void {
            $onError = $this->onError;
            $onError($throwable);
        });
    }

    /**
     * @param callable(resource $stdErr): never $forkedWorker
     * @param resource $stdErr
     * @param callable(?int $onExit, string $output) : void $onExit
     */
    private function startForked(callable $forkedWorker, $stdErr, callable $onExit): void
    {
        // memory the main process freed but still holds would otherwise be copied into every worker
        gc_mem_caches();

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new ParallelShouldNotHappenException('Forking a parallel worker failed.');
        }

        if ($pid === 0) {
            $forkedWorker($stdErr);
        }

        $this->forkedPid = $pid;
        $this->waitTimer = $this->loop->addPeriodicTimer(self::WAITPID_INTERVAL_IN_SECONDS, function () use (
            $pid,
            $onExit
        ): void {
            $status = 0;
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === 0) {
                return;
            }

            $this->cancelWaitTimer();
            $this->cancelTimer();

            // a worker killed by a signal has no exit code, like a spawned one
            $exitCode = null;
            if ($result > 0 && pcntl_wifexited($status)) {
                $exitStatus = pcntl_wexitstatus($status);
                $exitCode = $exitStatus === false ? null : $exitStatus;
            }

            $onExit($exitCode, $this->readStdErr());
        });
    }

    private function quitForked(): void
    {
        // the worker is already gone
        if (! $this->waitTimer instanceof TimerInterface) {
            return;
        }

        // the process can be quit before its connection is bound, e.g. on quitAll() after an error
        if (! isset($this->encoder)) {
            $this->terminate();
            return;
        }

        // ending the connection stops the worker's loop, and the wait timer reaps it
        $this->encoder->end();
    }

    private function terminate(): void
    {
        if ($this->forkedPid !== null) {
            posix_kill($this->forkedPid, SIGTERM);
            return;
        }

        $this->process->terminate();
    }

    private function readStdErr(): string
    {
        $stdErr = $this->stdErr;
        if ($stdErr === null) {
            throw new ParallelShouldNotHappenException();
        }

        rewind($stdErr);

        /** @var string $streamContents */
        $streamContents = stream_get_contents($stdErr);
        fclose($stdErr);
        $this->stdErr = null;

        return $streamContents;
    }

    private function cancelWaitTimer(): void
    {
        if (! $this->waitTimer instanceof TimerInterface) {
            return;
        }

        $this->loop->cancelTimer($this->waitTimer);
        $this->waitTimer = null;
    }

    private function cancelTimer(): void
    {
        if (! $this->timer instanceof TimerInterface) {
            return;
        }

        $this->loop->cancelTimer($this->timer);
        $this->timer = null;
    }
}

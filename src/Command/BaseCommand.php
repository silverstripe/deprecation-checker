<?php

namespace Silverstripe\DeprecationChangelogGenerator\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Process\Process;

abstract class BaseCommand extends Command
{
    protected InputInterface $input;

    protected SymfonyStyle $output;

    private ?ProgressBar $progressBar = null;

    private bool $progressBarDisplayed = false;

    /**
     * Callable to pass into Process::run() to advance a progress bar when a long process
     * has some output
     */
    public function handleProcessOutput($type, $data): void
    {
        $this->advanceProgressBar($data);
    }

    protected function setIO(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = new SymfonyStyle($input, $output);
    }

    /**
     * Run a CLI command and return the process that ran it.
     *
     * @param int|null $timeout The timeout in seconds or null to disable
     */
    protected function runCliCommand(string|array $command, ?int $timeout = null): Process
    {
        if (is_array($command)) {
            $command = implode(' ', $command);
        }
        $this->output->writeln("Running <info>$command</info>");
        /** @var ProcessHelper $processHelper */
        $processHelper = $this->getHelper('process');
        $process = Process::fromShellCommandline($command, timeout: $timeout);

        // Handle output with a progress bar unless we're running with `-vvv` aka debug
        // Debug output will explicitly include all output from the process directly to the terminal.
        $callback = null;
        if (!$this->output->isDebug()) {
            $callback = [$this, 'handleProcessOutput'];
        }

        $result = $processHelper->run($this->output, $process, callback: $callback);
        $this->endProgressBar();
        return $result;
    }

    /**
     * Advances the current progress bar, starting a new one if necessary.
     */
    protected function advanceProgressBar(?string $message = null): void
    {
        $barWidth = 15;
        $timeWidth = 20;
        if ($this->progressBar === null) {
            $this->progressBar = $this->output->createProgressBar();
            $this->progressBar->setFormat("%elapsed:10s% %bar% %message%");
            $this->progressBar->setBarWidth($barWidth);
            $this->progressBar->setMessage('');
        }
        if (!$this->progressBarDisplayed) {
            $this->progressBar->display();
            $this->progressBarDisplayed = true;
        }

        if ($message !== null) {
            // Make sure messages can't span multiple lines - truncate if necessary
            $terminal = new Terminal();
            $threshold = $terminal->getWidth() - $barWidth - $timeWidth - 5;
            $message = trim(Helper::removeDecoration($this->output->getFormatter(), str_replace("\n", ' ', $message)));
            if (strlen($message) > $threshold) {
                $message = substr($message, 0, $threshold - 3) . '...';
            }
            $this->progressBar->setMessage($message);
        }

        $this->progressBar->advance();
    }

    /**
     * Clears the current progress bar (if any) from the console.
     *
     * Useful if we need to output a warning while a progress bar may be running.
     */
    protected function clearProgressBar(): void
    {
        if ($this->progressBarDisplayed) {
            $this->progressBar?->clear();
            $this->progressBarDisplayed = false;
        }
    }

    /**
     * Clears and unsets the progressbar if there is one.
     */
    protected function endProgressBar(): void
    {
        $this->progressBar?->finish();
        $this->clearProgressBar();
        $this->progressBar = null;
    }
}

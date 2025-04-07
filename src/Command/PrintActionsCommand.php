<?php

namespace SilverStripe\DeprecationChecker\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

#[AsCommand('print-actions', 'Print the list of actions required to the terminal in markdown format.')]
class PrintActionsCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setIO($input, $output);
        // Get the data dir and convert it an absolute path
        $dataDir = $this->input->getOption('dir');
        $dataDir = Path::canonicalize($dataDir);
        if (!Path::isAbsolute($dataDir)) {
            $dataDir = Path::makeAbsolute($dataDir, getcwd());
        }

        $actionsFilePath = Path::join($dataDir, GenerateCommand::DIR_OUTPUT, GenerateCommand::FILE_ACTIONS);
        if (!file_exists($actionsFilePath)) {
            $this->output->error('You haven\'t run the `generate` command.');
            return BaseCommand::FAILURE;
        }
        $actionsJson = $this->getJsonFromFile($actionsFilePath);

        if (empty($actionsJson)) {
            $this->output->success('No actions required.');
            return BaseCommand::SUCCESS;
        }

        // Prepare sort order and get nice API references.
        ksort($actionsJson);
        foreach ($actionsJson as $module => $moduleActions) {
            foreach ($moduleActions as $typeActions) {
                foreach ($typeActions as $apiType => $apiActions) {
                    foreach ($apiActions as $actionData) {
                        $actions[$actionData['message']][$module][] = $this->getApiReference($apiType, $actionData);
                    }
                }
            }
        }

        // Actually output the stuff
        $this->output->writeln(['## Actions', '']);
        foreach ($actions as $message => $moduleActions) {
            $this->output->writeln(['### ' . $message, '']);
            foreach ($moduleActions as $module => $apiRefs) {
                $this->output->writeln(['#### ' . $module, '']);
                foreach ($apiRefs as $api) {
                    $this->output->writeln('- ' . $api);
                }
                $this->output->writeln(['']);
            }
        }

        return BaseCommand::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addOption(
            'dir',
            'd',
            InputOption::VALUE_REQUIRED,
            'Directory the clone command output its content into. There should be an <info>output/</info> dir in there.',
            './'
        );
    }

    /**
     * Get a GitHub issue markdown friendly string to reference a specific piece of API
     */
    private function getApiReference(string $apiType, array $apiData = []): string
    {
        $apiTypeFriendly = $apiData['apiType'];
        $apiName = $apiData['name'];
        if ($apiType === 'class') {
            return "`{$apiName}` $apiTypeFriendly";
        }
        if ($apiType === 'method') {
            $className = $apiData['class'];
            return "`{$className}::{$apiName}()` $apiTypeFriendly";
        }
        if ($apiType === 'property') {
            $className = $apiData['class'];
            return "`{$className}->{$apiName}` $apiTypeFriendly";
        }
        if ($apiType === 'config') {
            $className = $apiData['class'];
            return "`{$className}.{$apiName}` $apiTypeFriendly";
        }
        if ($apiType === 'const') {
            $className = $apiData['class'];
            return "`{$className}::{$apiName}` $apiTypeFriendly";
        }
        if ($apiType === 'function') {
            return "`{$apiName}()` $apiTypeFriendly";
        }
        if ($apiType === 'param') {
            $function = $apiData['function'];
            if ($function) {
                $parent = $this->getApiReference('function', ['name' => $function]);
            } else {
                $parent = $this->getApiReference('method', ['name' => $apiData['method'], 'class' => $apiData['class']]);
            }
            return "`\$$apiName` $apiTypeFriendly in $parent";
        }
        throw new InvalidArgumentException("Unexpected API type $apiType");
    }
}

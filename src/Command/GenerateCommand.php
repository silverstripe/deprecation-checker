<?php

namespace SilverStripe\DeprecationChecker\Command;

use Composer\Semver\VersionParser;
use Doctum\Message;
use Doctum\Parser\ParseError;
use Doctum\Parser\Transaction;
use Doctum\Project;
use Doctum\Reflection\ClassReflection;
use Doctum\Version\Version;
use RuntimeException;
use SilverStripe\DeprecationChecker\Compare\BreakingChangesComparer;
use SilverStripe\DeprecationChecker\Parse\ParserFactory;
use SilverStripe\DeprecationChecker\Render\Renderer;
use SilverStripe\SupportedModules\BranchLogic;
use SilverStripe\SupportedModules\MetaData;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsCommand('generate', 'Generate the deprecation section of a changelog and additional data files')]
class GenerateCommand extends BaseCommand
{
    public const string DIR_OUTPUT = 'output';

    public const string FILE_CHANGELOG = 'changelog.md';

    public const string FILE_ACTIONS = 'actions-required.json';

    public const string FILE_CHANGES = 'breaking-changes.json';

    public const string FILE_PARSE_ERRORS = 'parse-errors.json';

    /**
     * We don't care about missing `@param` tags for our purposes.
     */
    private const string IGNORE_PARSE_ERROR_REGEX = '/is missing a @param tag/';

    /**
     * Some information about the "from" version.
     * Includes:
     * - recipe (e.g. 'silverstripe/recipe-kitchen-sink')
     * - constraint (e.g. '5.4.x-dev')
     * - branch (e.g. '5.4')
     * - path (absolute path to the cloned/from dir)
     */
    private array $metaDataFrom;

    /**
     * Some information about the "to" version.
     * Includes:
     * - recipe (e.g. 'silverstripe/recipe-kitchen-sink')
     * - constraint (e.g. '6.0.x-dev')
     * - branch (e.g. '6.0')
     * - path (absolute path to the cloned/to dir)
     */
    private array $metaDataTo;

    /**
     * Associative array of supported modules metadata
     */
    private array $supportedModules;

    /**
     * Any errors that occurred during the parsing step
     * @var ParseError[]
     */
    private array $parseErrors = [];

    /**
     * Any steps that developers should take to tidy up
     * e.g. deprecate API which has been removed
     */
    private array $actionsToTake = [];

    /**
     * The full set of identified breaking API changes
     */
    private array $breakingApiChanges = [];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setIO($input, $output);
        $warnings = [];

        // Get the data dir and convert it an absolute path
        $dataDir = $this->input->getOption('dir');
        $dataDir = Path::canonicalize($dataDir);
        if (!Path::isAbsolute($dataDir)) {
            $dataDir = Path::makeAbsolute($dataDir, getcwd());
        }

        $parseErrorFilePath = Path::join($dataDir, GenerateCommand::DIR_OUTPUT, GenerateCommand::FILE_PARSE_ERRORS);
        $changelogPath = Path::join($dataDir, GenerateCommand::DIR_OUTPUT, GenerateCommand::FILE_CHANGELOG);
        $actionsFilePath = Path::join($dataDir, GenerateCommand::DIR_OUTPUT, GenerateCommand::FILE_ACTIONS);
        $breakingChangesFilePath = Path::join($dataDir, GenerateCommand::DIR_OUTPUT, GenerateCommand::FILE_CHANGES);

        // Remove files from previous run
        $filesystem = new Filesystem();
        $filesystem->remove($parseErrorFilePath);
        $filesystem->remove($changelogPath);
        $filesystem->remove($actionsFilePath);
        $filesystem->remove($breakingChangesFilePath);

        if ($this->input->getOption('flush')) {
            $filesystem->remove(Path::join($dataDir, 'cache'));
        }

        // Get metadata so we know what we need to parse
        $this->fetchMetaData($dataDir);
        $this->findSupportedModules();

        // Parse PHP files in all relevant repositories
        $project = $this->parseModules($dataDir);
        $parseWarning = $this->handleParseErrors($parseErrorFilePath);
        if ($parseWarning) {
            $warnings[] = $parseWarning;
        }

        // Compare versions to find breaking changes and actions needed
        $this->findBreakingChanges($project, $dataDir);
        if (!empty($this->actionsToTake)) {
            $numActions = $this->getActionsCount();
            $actionsFile = Path::join($dataDir, GenerateCommand::DIR_OUTPUT, GenerateCommand::FILE_ACTIONS);
            $warnings[] = "$numActions actions to take. See '{$actionsFile}' or run the `print-actions` command for details.";
        }

        if (count($this->breakingApiChanges) < 1) {
            // Output any warnings and then return - nothing more to do.
            foreach ($warnings as $message) {
                $this->output->warning($message);
            }
            $this->output->success('No API breaking changes to add to the changelog.');
            return BaseCommand::SUCCESS;
        }

        // Render changelog chunk
        $this->output->writeln('Rendering...');
        $renderer = new Renderer($this->metaDataFrom, $this->metaDataTo, $project);
        $renderer->render($this->breakingApiChanges, $dataDir, $changelogPath);
        $this->output->writeln('Rendering complete.');

        // Output any warnings
        foreach ($warnings as $message) {
            $this->output->warning($message);
        }

        // output a message including path to the file(s) to check.
        $this->output->success("Changelog chunk generated successfully. See '$changelogPath'");
        return BaseCommand::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addOption(
            'dir',
            'd',
            InputOption::VALUE_REQUIRED,
            'Directory the clone command output its content into.'
            . ' Additional content will be added here including the changelog chunk.',
            './'
        );
        $this->addOption(
            'flush',
            'f',
            InputOption::VALUE_NONE,
            'Flushes parser and twig cache. Useful when developing this tool or after running <info>clone</info> again.'
        );
        $this->setHelp('Also generates useful data e.g. actions that need to be taken to clean up existing deprecations.');
    }

    /**
     * Gathers metadata about both versions of the recipe
     */
    private function fetchMetaData(string $dataDir): void
    {
        $this->output->writeln('Collating metadata about the recipe in its two branches.');
        // check for presence of the clone dirs
        if (
            !is_dir(Path::join($dataDir, CloneCommand::DIR_CLONE, BreakingChangesComparer::FROM))
            || !is_dir(Path::join($dataDir, CloneCommand::DIR_CLONE, BreakingChangesComparer::TO))
        ) {
            throw new InvalidOptionException(
                "'$dataDir' is missing one or both of the cloned directories. Run the clone command."
            );
        }

        $fromFile = Path::join($dataDir, CloneCommand::DIR_CLONE, BreakingChangesComparer::FROM, CloneCommand::META_FILE);
        $toFile = Path::join($dataDir, CloneCommand::DIR_CLONE, BreakingChangesComparer::TO, CloneCommand::META_FILE);

        $this->metaDataFrom = $this->getJsonFromFile($fromFile);
        $this->metaDataTo = $this->getJsonFromFile($toFile);
        $this->metaDataFrom['branch'] = $this->guessBranchFromConstraint($this->metaDataFrom['constraint']);
        $this->metaDataTo['branch'] = $this->guessBranchFromConstraint($this->metaDataTo['constraint']);
    }

    /**
     * Identifies supported modules which are relevant for our purposes
     */
    private function findSupportedModules(): void
    {
        $this->output->writeln('Finding supported modules across both branches.');
        $supportedModules = MetaData::getAllRepositoryMetaData(true)[MetaData::CATEGORY_SUPPORTED];
        $cmsMajorTo = $this->getCmsMajor($this->metaDataTo);
        // Make sure we only have supported modules that are in the "to" version.
        // Don't also restrict to the "from" version because we need to know about new modules for API links.
        $supportedModules = MetaData::removeReposNotInCmsMajor($supportedModules, $cmsMajorTo, true);
        $this->supportedModules = $supportedModules;
    }

    /**
     * Determine which major release line of Silverstripe CMS this metadata applies to
     */
    private function getCmsMajor(array $metaData): string
    {
        $composerJson = $this->getJsonFromFile(Path::join($metaData['path'], 'composer.json'), false);
        // Note that while the branch will usually directly tell us what major it is since our recipes
        // tend to be lockstepped, we have an API to explicitly fetch it so we may as well for future proofing.
        return BranchLogic::getCmsMajor(
            MetaData::getMetaDataByPackagistName($metaData['recipe']),
            $metaData['branch'],
            $composerJson
        );
    }

    /**
     * Parse the PHP code in the supported modules.
     * If there are errors, they're stored in $this->parseErrors.
     * The parsed result is represented by the returned project.
     */
    private function parseModules(string $dataDir): Project
    {
        $this->output->writeln('Parsing modules...');

        $factory = new ParserFactory($this->supportedModules, $dataDir);
        $project = $factory->buildProject();

        $project->parse(function (string $messageType, mixed $data) {
            switch ($messageType) {
                case Message::SWITCH_VERSION:
                    /** @var Version $data */
                    $this->output->writeln("Swapping to '{$data->getName()}'", OutputInterface::VERBOSITY_VERBOSE);
                    break;
                case Message::PARSE_CLASS:
                    /**
                     * @var int $step
                     * @var int $steps
                     * @var ClassReflection $class
                     */
                    list($step, $steps, $class) = $data;
                    $this->output->writeln("Step {$step}/{$steps} - parsing '{$class->getName()}'", OutputInterface::VERBOSITY_VERBOSE);
                    break;
                case Message::PARSE_ERROR:
                    // Note the error for later
                    /** @var ParseError[] $data */
                    $this->parseErrors = array_merge($this->parseErrors, $data);
                    // If in debug mode, output the message now.
                    $errorMessages = [];
                    foreach ($data as $error) {
                        $errorIsRelevant = !preg_match(GenerateCommand::IGNORE_PARSE_ERROR_REGEX, $error->getMessage());
                        if ($errorIsRelevant && !$error->canBeIgnored()) {
                            $errorMessages[] = "<fg=black;bg=yellow>Parse error in {$error->getFile()}:{$error->getLine()} - {$error->getMessage()}</>";
                        }
                    }
                    $this->output->writeln($errorMessages, OutputInterface::VERBOSITY_DEBUG);
                    break;
                case Message::PARSE_VERSION_FINISHED:
                    /** @var Transaction $data */
                    $this->output->writeln('Finished parsing that version.', OutputInterface::VERBOSITY_VERBOSE);
                    break;
            }
        });
        $this->parseErrors = array_merge($this->parseErrors, $factory->getFinder()->getProblems());

        $this->output->writeln('Parsing complete.');
        return $project;
    }

    /**
     * Store the relevant parse errors in a file, and return a message about them if there are any.
     */
    private function handleParseErrors(string $parseErrorFile): string
    {
        if (empty($this->parseErrors)) {
            return '';
        }
        $parseErrors = [];
        foreach ($this->parseErrors as $error) {
            if (preg_match(GenerateCommand::IGNORE_PARSE_ERROR_REGEX, $error->getMessage())) {
                continue;
            }
            $parseErrors[] = [
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'tip' => $error->getTip(),
            ];
        }
        if (!empty($parseErrors)) {
            $filesystem = new Filesystem();
            $filesystem->dumpFile($parseErrorFile, $this->jsonEncode($parseErrors));
            $countParseErrors = count($parseErrors);
            return "$countParseErrors parsing errors found. See '{$parseErrorFile}' for details.";
        }
        return '';
    }

    /**
     * Find all breaking changes, as well as any actions needed to improve output accuracy.
     */
    private function findBreakingChanges(Project $parsedProject, string $dataDir): void
    {
        $this->output->writeln('Comparing API between versions...');
        $outputDir = Path::join($dataDir, GenerateCommand::DIR_OUTPUT);
        $comparer = new BreakingChangesComparer($this->output);
        $comparer->compare($parsedProject);
        $this->actionsToTake = $comparer->getActionsToTake();
        $this->breakingApiChanges = $comparer->getBreakingChanges();

        $filesystem = new Filesystem();
        $filesystem->mkdir($outputDir);

        // Dump findings into files so we can check them at any time
        $filesystem->dumpFile(
            Path::join($outputDir, GenerateCommand::FILE_ACTIONS),
            $this->jsonEncode($this->actionsToTake)
        );
        $filesystem->dumpFile(
            Path::join($outputDir, GenerateCommand::FILE_CHANGES),
            $this->jsonEncode($this->breakingApiChanges)
        );
        $this->output->writeln('Comparison complete.');
    }

    /**
     * Try to figure out what specific branch was used to install the recipe based on the constraint used.
     * The installed recipe's composer.lock doesn't include itself, it's composer.json doesn't mark a version,
     * and the vendor/composer/installed.php data doesn't say the specific branch it installed from.
     */
    private function guessBranchFromConstraint(string $constraint): string
    {
        $branchRegex = '/^([0-9]+(?:\.[0-9]+))/';
        // Naively try to turn a constraint like "^5.4.x-dev" into a branch like "5.4".
        $candidate = ltrim(str_replace('.x-dev', '', $constraint), '<>=');
        if (preg_match($branchRegex, $candidate, $match)) {
            return $match[1];
        }
        // Let Composer's Semver API take a crack at it - will be close enough if it works.
        $versionParser = new VersionParser();
        $candidate = $versionParser->parseConstraints($constraint)->getLowerBound()->getVersion();
        if (preg_match($branchRegex, $candidate, $match)) {
            return $match[1];
        }
        throw new RuntimeException("Constraint '$constraint' does not dissolve easily into a branch number.");
    }

    /**
     * Get the number of actions that need to be taken.
     */
    private function getActionsCount(): int
    {
        $count = 0;
        foreach ($this->actionsToTake as $moduleActions) {
            foreach ($moduleActions as $typeActions) {
                foreach ($typeActions as $apiActions) {
                    $count += count($apiActions);
                }
            }
        }
        return $count;
    }
}

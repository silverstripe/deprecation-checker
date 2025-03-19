<?php

namespace Silverstripe\DeprecationChangelogGenerator\Command;

use Composer\Semver\VersionParser;
use Doctum\Message;
use Doctum\Parser\ClassVisitor\InheritdocClassVisitor;
use Doctum\Parser\ClassVisitor\MethodClassVisitor;
use Doctum\Parser\ClassVisitor\PropertyClassVisitor;
use Doctum\Parser\CodeParser;
use Doctum\Parser\NodeVisitor;
use Doctum\Parser\ParseError;
use Doctum\Parser\Parser;
use Doctum\Parser\ParserContext;
use Doctum\Parser\ProjectTraverser;
use Doctum\Parser\Transaction;
use Doctum\Project;
use Doctum\Reflection\ClassReflection;
use Doctum\Store\JsonStore;
use Doctum\Version\Version;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use RuntimeException;
use Silverstripe\DeprecationChangelogGenerator\Compare\CodeComparer;
use Silverstripe\DeprecationChangelogGenerator\Parse\DocBlockParser;
use Silverstripe\DeprecationChangelogGenerator\Parse\IncludeConfigFilter;
use Silverstripe\DeprecationChangelogGenerator\Parse\RecipeFinder;
use Silverstripe\DeprecationChangelogGenerator\Parse\RecipeVersionCollection;
use Silverstripe\DeprecationChangelogGenerator\Render\Renderer;
use SilverStripe\SupportedModules\BranchLogic;
use SilverStripe\SupportedModules\MetaData;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsCommand('generate', 'Generate the deprecation section of a changelog')]
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

        $parseErrorFile = Path::join($dataDir, GenerateCommand::DIR_OUTPUT, GenerateCommand::FILE_PARSE_ERRORS);

        if ($this->input->getOption('flush')) {
            $filesystem = new Filesystem();
            if ($filesystem->exists($parseErrorFile)) {
                $filesystem->remove($parseErrorFile);
            }
            $filesystem->remove(Path::join($dataDir, 'cache'));
        }

        // Get metadata so we know what we need to parse
        $this->fetchMetaData($dataDir);
        $this->findSupportedModules();

        // Parse PHP files in all relevant repositories
        $parsed = $this->parseModules($dataDir);
        $parseWarning = $this->handleParseErrors($parseErrorFile);
        if ($parseWarning) {
            $warnings[] = $parseWarning;
        }

        // Compare versions to find breaking changes and actions needed
        $this->findInfoAboutDeprecations($parsed, $dataDir);
        if (!empty($this->actionsToTake)) {
            $numActions = $this->getActionsCount();
            $actionsFile = Path::join($dataDir, GenerateCommand::DIR_OUTPUT, GenerateCommand::FILE_ACTIONS);
            $warnings[] = "$numActions actions to take. See '{$actionsFile}' for details.";
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
        $changelogPath = Path::join($dataDir, GenerateCommand::DIR_OUTPUT, GenerateCommand::FILE_CHANGELOG);
        $renderer = new Renderer($this->metaDataFrom, $this->metaDataTo, $parsed);
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

    private function fetchMetaData(string $dataDir): void
    {
        $this->output->writeln('Collating metadata about the recipe in its two branches.');
        // check for presence of the clone dirs
        if (
            !is_dir(Path::join($dataDir, CloneCommand::DIR_CLONE, CodeComparer::FROM))
            || !is_dir(Path::join($dataDir, CloneCommand::DIR_CLONE, CodeComparer::TO))
        ) {
            throw new InvalidOptionException(
                "'$dataDir' is missing one or both of the cloned directories. Run the clone command."
            );
        }

        $fromFile = Path::join($dataDir, CloneCommand::DIR_CLONE, CodeComparer::FROM, CloneCommand::META_FILE);
        $toFile = Path::join($dataDir, CloneCommand::DIR_CLONE, CodeComparer::TO, CloneCommand::META_FILE);

        $this->metaDataFrom = $this->getJsonFromFile($fromFile);
        $this->metaDataTo = $this->getJsonFromFile($toFile);
        $this->metaDataFrom['branch'] = $this->guessBranchFromConstraint($this->metaDataFrom['constraint']);
        $this->metaDataTo['branch'] = $this->guessBranchFromConstraint($this->metaDataTo['constraint']);
    }

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

    private function parseModules(string $dataDir): Project
    {
        $this->output->writeln('Parsing modules...');

        $collection = new RecipeVersionCollection($this->supportedModules, $dataDir);
        $store = new JsonStore();
        $project = new Project(
            $store,
            $collection,
            [
                // build_dir shouldn't anything in it - but I'm setting it so that if it DOES output something we'll know.
                'build_dir' => Path::join($dataDir, 'doctum-output/%version%'),
                'cache_dir' => Path::join($dataDir, 'cache/parser/%version%'),
                'include_parent_data' => true,
            ]
        );
        $iterator = new RecipeFinder($collection);
        $iterator
            ->files()
            ->name('*.php')
            ->exclude('thirdparty')
            ->exclude('tests');

        $parserContext = new ParserContext(new IncludeConfigFilter(), new DocBlockParser(), new PrettyPrinter());
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new NodeVisitor($parserContext));

        // NOTE currently the version of the dependency we're using doesn't have PHP 8 as a version, but does correctly parse PHP 8 code.
        $phpParser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $codeParser = new CodeParser($parserContext, $phpParser, $traverser);

        $visitors = [
            new InheritdocClassVisitor(),
            new MethodClassVisitor(),
            new PropertyClassVisitor($parserContext),
        ];
        $projectTraverser = new ProjectTraverser($visitors);
        $parser = new Parser($iterator, $store, $codeParser, $projectTraverser);
        $project->setParser($parser);
        // @TODO can use callback arg to output current step
        $project->parse(function (string $messageType, mixed $data) {
            // @TODO Probably use a progress bar when not in verbose mode.
            //       Also check out Doctum\Console\Command\Command::messageCallback()
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
                    // @TODO can DEFINITELY use this for a progress bar - we even know how many steps there are.
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
                    // @TODO Would be a good point to STOP the progress bar maybe
                    // @TODO can say modified x, removed y, visited z
                    $this->output->writeln('Finished parsing that version.', OutputInterface::VERBOSITY_VERBOSE);
                    break;
            }
        });
        $this->parseErrors = array_merge($this->parseErrors, $iterator->getProblems());

        $this->output->writeln('Parsing complete.');
        return $project;
    }

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

    private function findInfoAboutDeprecations(Project $parsedProject, string $dataDir): void
    {
        $this->output->writeln('Comparing API between versions...');
        $outputDir = Path::join($dataDir, GenerateCommand::DIR_OUTPUT);
        $comparer = new CodeComparer($this->output);
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

    private function getJsonFromFile(string $filePath, bool $associative = true): array|stdClass
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("'$filePath' does not exist or is not a file.");
        }

        $fileContents = file_get_contents($filePath);
        $json = json_decode($fileContents, $associative);

        if ($json === null) {
            $error = json_last_error_msg();
            throw new RuntimeException("$filePath has invalid JSON: $error");
        }

        return $json;
    }

    private function jsonEncode(mixed $content): string
    {
        return json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function getActionsCount(): int
    {
        $count = 0;
        foreach ($this->actionsToTake as $module => $moduleActions) {
            foreach ($moduleActions as $actionType => $typeActions) {
                foreach ($typeActions as $apiType => $apiActions) {
                    $count += count($apiActions);
                }
            }
        }
        return $count;
    }
}

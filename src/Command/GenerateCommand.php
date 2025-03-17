<?php

namespace Silverstripe\DeprecationChangelogGenerator\Command;

use Composer\Semver\VersionParser;
use Doctum\Message;
use Doctum\Parser\ClassVisitor\InheritdocClassVisitor;
use Doctum\Parser\ClassVisitor\MethodClassVisitor;
use Doctum\Parser\ClassVisitor\PropertyClassVisitor;
use Doctum\Parser\CodeParser;
use Doctum\Parser\DocBlockParser;
use Doctum\Parser\Filter\DefaultFilter;
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
use Silverstripe\DeprecationChangelogGenerator\Data\CodeComparer;
use Silverstripe\DeprecationChangelogGenerator\Data\RecipeFinder;
use Silverstripe\DeprecationChangelogGenerator\Data\RecipeVersionCollection;
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
    private const array SRC_DIRS = [
        'code',
        'src',
    ];

    public const string DIR_OUTPUT = 'output';

    public const string FILE_ACTIONS = 'actions-required.json';

    public const string FILE_CHANGES = 'breaking-changes.json';

    private array $metaDataFrom;

    private array $metaDataTo;

    private array $supportedModules;

    /**
     * @var ParseError[]
     */
    private array $parseErrors = [];

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

        $this->fetchMetaData($dataDir);
        $this->findSupportedModules();
        $parsed = $this->parseModules($dataDir);

        if (!empty($this->parseErrors)) {
            // @TODO dump these to a file somewhere too
            $parseErrorFile = 'TODO: Create a file';
            $countParseErrors = count($this->parseErrors);
            $continue = $this->output->confirm(
                "Found $countParseErrors errors during parsing. Do you want to continue anyway?"
            );
            $parseErrorMsg = "$countParseErrors parsing errors found. See '{$parseErrorFile}' for details.";
            $warnings[] = $parseErrorMsg;
            if (!$continue) {
                $this->output->error($parseErrorMsg);
                return BaseCommand::FAILURE;
            }
        }

        $this->findInfoAboutDeprecations($parsed, $dataDir);
        // @TODO separate method for generating the changelog chunk

        // Output any
        if (!empty($warnings)) {
            foreach ($warnings as $message) {
                $this->output->warning($message);
            }
        }

        // @TODO if there is anything logged that needs actioning e.g. stuff that needs to be deprecated,

        // output a message including path to the file(s) to check.
        $this->output->success("Changelog chunk generated successfully.");
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
        // @TODO "--only" like module standardiser
        // @TODO "--exclude" like module standardiser
        // @TODO maybe "--analyse-only"? Which would check what needs to be done without building the changelog output
        // @TODO do we need to indicate which end we care about?
        //       i.e. are we making a changelog for what was DEPRECATED in 5, or what is REMOVED in 6?
        //       For now assume the latter.
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
        $cmsMajorFrom = $this->getCmsMajor($this->metaDataFrom);
        $cmsMajorTo = $this->getCmsMajor($this->metaDataTo);
        // Make sure we only have supported modules that are in BOTH major releases
        $supportedModules = MetaData::removeReposNotInCmsMajor($supportedModules, $cmsMajorFrom, true);
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
            // ->exclude('examples') // @TODO I don't think that dir exists anywhere.
            ->exclude('tests');

        $parserContext = new ParserContext(new DefaultFilter(), new DocBlockParser(), new PrettyPrinter()); // @TODO api.silverstripe.org replaces filter with our own which manages public API definition
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new NodeVisitor($parserContext));

        // @TODO find the lowest common PHP version between versions
        $phpParser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $codeParser = new CodeParser($parserContext, $phpParser, $traverser); // @TODO use PHP version detection per recipe version

        $visitors = [
            new InheritdocClassVisitor(),
            new MethodClassVisitor(),
            new PropertyClassVisitor($parserContext),
            // @TODO api.silverstripe.org has a node visitor for collecting configs
        ];
        $projectTraverser = new ProjectTraverser($visitors);
        $parser = new Parser($iterator, $store, $codeParser, $projectTraverser);
        $project->setParser($parser);
        // @TODO can use callback arg to output current step
        // @TODO check what the $force bool does and if we need that
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
                        if (!$error->canBeIgnored()) {
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

        $this->output->writeln('Parsing complete.');
        return $project;
    }

    private function findInfoAboutDeprecations(Project $parsedProject, string $dataDir): void
    {
        $this->output->writeln('Comparing API between versions...');
        $outputDir = Path::join($dataDir, GenerateCommand::DIR_OUTPUT);
        $comparer = new CodeComparer($parsedProject, $this->output);
        $comparer->compare();
        $actions = $comparer->getActionsToTake();
        $changes = $comparer->getBreakingChanges();

        $filesystem = new Filesystem();
        $filesystem->mkdir($outputDir);
        // @TODO ask before overriding existing data

        // @TODO maybe this comes as its own step? Or only one of these written like this, and the other written only as a real changelog blob?
        $filesystem->dumpFile(Path::join($outputDir, GenerateCommand::FILE_ACTIONS), json_encode($actions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $filesystem->dumpFile(Path::join($outputDir, GenerateCommand::FILE_CHANGES), json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
}

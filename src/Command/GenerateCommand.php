<?php

namespace Silverstripe\DeprecationChangelogGenerator\Command;

use Composer\Semver\VersionParser;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use RuntimeException;
use Silverstripe\DeprecationChangelogGenerator\PhpParser\DeprecationNodeVisitor;
use SilverStripe\SupportedModules\BranchLogic;
use SilverStripe\SupportedModules\MetaData;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

#[AsCommand('generate', 'Generate the deprecation section of a changelog')]
class GenerateCommand extends BaseCommand
{
    private const array SRC_DIRS = [
        'code',
        'src',
    ];

    public const string DIR_OUTPUT = 'output';

    private array $metaDataFrom;

    private array $metaDataTo;

    private array $supportedModules;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setIO($input, $output);

        // Get the data dir and convert it an absolute path
        $dataDir = $this->input->getOption('dir');
        $dataDir = Path::canonicalize($dataDir);
        if (!Path::isAbsolute($dataDir)) {
            $dataDir = Path::makeAbsolute($dataDir, getcwd());
        }

        $this->fetchMetaData($dataDir);
        $this->findSupportedModules();
        $this->findInfoAboutDeprecations();
        // @TODO separate method for generating the changelog chunk

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
            !is_dir(Path::join($dataDir, CloneCommand::DIR_FROM))
            || !is_dir(Path::join($dataDir, CloneCommand::DIR_TO))
        ) {
            throw new InvalidOptionException(
                "'$dataDir' is missing one or both of the cloned directories. Run the clone command."
            );
        }

        $fromFile = Path::join($dataDir, CloneCommand::DIR_FROM, CloneCommand::META_FILE);
        $toFile = Path::join($dataDir, CloneCommand::DIR_TO, CloneCommand::META_FILE);

        $this->metaDataFrom = $this->getJsonFromFile($fromFile);
        $this->metaDataTo = $this->getJsonFromFile($toFile);
        $this->metaDataFrom['branch'] = $this->guessBranchFromConstraint($this->metaDataFrom['constraint']);
        $this->metaDataTo['branch'] = $this->guessBranchFromConstraint($this->metaDataTo['constraint']);
        $this->metaDataFrom['phpVersion'] = $this->getPhpVersion($this->metaDataFrom['path']);
        $this->metaDataTo['phpVersion'] = $this->getPhpVersion($this->metaDataTo['path']);
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

    private function getPhpVersion(string $recipePath)
    {
        $composerJson = $this->getJsonFromFile(Path::join($recipePath, 'composer.json'), true);
        $phpConstraint = $composerJson['require']['php'] ?? '';
        if (!$phpConstraint) {
            $this->output->warning('PHP version not listed for , using host version instead.');
            return substr(phpversion(), 0, 3);
        }
        $versionParser = new VersionParser();
        $phpConstraint = $versionParser->parseConstraints($phpConstraint);
        $phpVersion = $phpConstraint->getLowerBound()->getVersion();
        return substr($phpVersion, 0, 3);
    }

    private function findInfoAboutDeprecations(): void
    {
        $this->output->writeln('Gathering data');

        foreach ($this->supportedModules as $repoData) {
            $this->output->writeln("Gathering data for {$repoData['packagist']}");
            $moduleFromDir = Path::join($this->metaDataFrom['path'], 'vendor', $repoData['packagist']);
            $moduleToDir = Path::join($this->metaDataTo['path'], 'vendor', $repoData['packagist']);
            foreach (GenerateCommand::SRC_DIRS as $srcDir) {
                $srcDirPath = Path::join($moduleToDir, $srcDir);
                if (!is_dir($srcDirPath)) {
                    $this->output->writeln("$srcDirPath doesn't exist. Skipping.", OutputInterface::VERBOSITY_VERBOSE);
                    continue;
                }
                $this->output->writeln("Gathering data from $srcDirPath", OutputInterface::VERBOSITY_VERBOSE);

                // Recursively iterates over the directory and subdirectories only returning files ending with ".php"
                $iterator = new RegexIterator(
                    new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator(
                            $srcDirPath,
                            // Note that RecursiveDirectoryIterator::CURRENT_AS_PATHNAME guves an array
                            // rather than a string, so we use KEY_AS_PATHNAME to key the string we want.
                            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::KEY_AS_PATHNAME
                        )
                    ),
                    '/^.+\.php$/i',
                    RecursiveRegexIterator::ALL_MATCHES
                );
                foreach ($iterator as $filePath => $finfo) {
                    $fromCandidateFile = $this->findCandidateSrcFile(
                        str_replace($this->metaDataFrom['path'], $this->metaDataTo['path'], $filePath),
                        $srcDir
                    );
                    // $this->parseFile($filePath, $this->metaDataTo['phpVersion']);
                }
                return;
            }
        }
    }

    private function findCandidateSrcFile(string $dir, string $origSrcDir): string
    {
        if (is_file($dir)) {
            return $dir;
        }
        foreach (GenerateCommand::SRC_DIRS as $srcDir) {
            if ($srcDir === $origSrcDir) {
                continue;
            }
            $candidate = str_replace($origSrcDir, $srcDir, $dir);
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
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

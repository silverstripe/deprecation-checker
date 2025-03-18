<?php


// This was in GenerateCommand - it was finding all PHP files so we can parse them.
/*
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
*/

// This was finding the PHP version for a recipe
/*
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
*/


// This was gonna use nikic/php-parser to parse files. The idea was to use node visitors to collect the information we need.
/*
class SourceFileParser
{
    private string $phpVersionFrom;

    private string $phpVersionTo;

    public function __construct(string $phpVersionFrom, string $phpVersionTo)
    {
        $this->phpVersionFrom = $phpVersionFrom;
        $this->phpVersionTo = $phpVersionTo;
    }

    public function parse(string $filePath, string $altPath)
    {
        $astFrom = [];
        if ($altPath) {
            list($parserFrom, $traverserFrom) = $this->getParserAndTraverser($this->phpVersionFrom, null);
            $astFrom = $parserFrom->parse(file_get_contents($altPath));
            $astFrom = $traverserFrom->traverse($astFrom);
        }

        list($parserTo, $traverserTo) = $this->getParserAndTraverser($this->phpVersionTo, $astFrom);
        $astTo = $parserTo->parse(file_get_contents($filePath));
        $astTo = $traverserTo->traverse($astTo);
    }

    private function getParserAndTraverser(string $phpVersion, ?array $astFrom): array
    {
        $factory = new ParserFactory();
        $parser = $factory->createForVersion(PhpVersion::fromString($phpVersion));
        $visitors = [
            // Provides FQCN
            new NameResolver(),
            // Ensures we know where things come from (e.g. this method belongs to this class)
            new ParentConnectingVisitor(),
        ];
        // @TODO actually, DON'T do that with visitors!!!! Instead, just loop over the ASTs.
        if ($astFrom !== null) {
            // Our visitor that gets all the deprecation information for us
            // @TODO need to visit the "from" set too, to check for anything deprecated but not removed.
            new DeprecationNodeVisitor($astFrom);
        }
        $traverser = new NodeTraverser(...$visitors);
        return [$parser, $traverser];
    }
}
*/

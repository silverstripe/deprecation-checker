<?php

namespace Silverstripe\DeprecationChangelogGenerator\PhpParser;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

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
            /** @var NodeTraverser $traverserFrom */
            list($parserFrom, $traverserFrom) = $this->getParserAndTraverser($this->phpVersionFrom, null);
            $astFrom = $parserFrom->parse(file_get_contents($altPath));
            $astFrom = $traverserFrom->traverse($astFrom);
        }

        /** @var NodeTraverser $traverserTo */
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

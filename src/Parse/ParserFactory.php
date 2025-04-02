<?php

namespace SilverStripe\DeprecationChecker\Parse;

use Doctum\Parser\ClassVisitor\InheritdocClassVisitor;
use Doctum\Parser\ClassVisitor\MethodClassVisitor;
use Doctum\Parser\ClassVisitor\PropertyClassVisitor;
use Doctum\Parser\CodeParser;
use Doctum\Parser\NodeVisitor;
use Doctum\Parser\Parser;
use Doctum\Parser\ParserContext;
use Doctum\Parser\ProjectTraverser;
use Doctum\Project;
use Doctum\Store\JsonStore;
use Doctum\Store\StoreInterface;
use Doctum\Version\VersionCollection;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory as PhpParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use Symfony\Component\Filesystem\Path;

/**
 * Factory which builds a project and a parser for comparing two versions of a codebase
 */
class ParserFactory
{
    private string $dir;

    private StoreInterface $store;

    private VersionCollection $collection;

    private RecipeFinder $finder;

    public function __construct(array $supportedModules, string $dir)
    {
        $this->dir = $dir;
        $this->store = new JsonStore();
        $this->collection =  new RecipeVersionCollection($supportedModules, $this->dir);
    }

    /**
     * Build a project that will hold contextual parse data for multiple versions of the codebase.
     * Note you should also call buildParser() and pass it into $project->setParser().
     */
    public function buildProject(): Project
    {
        $project = new Project(
            $this->store,
            $this->collection,
            [
                // build_dir shouldn't end up with anything in it - but we're setting it so that if it DOES output something we'll know.
                'build_dir' => Path::join($this->dir, 'doctum-output/%version%'),
                'cache_dir' => Path::join($this->dir, 'cache/parser/%version%'),
                'include_parent_data' => true,
            ]
        );
        $project->setParser($this->buildParser());
        return $project;
    }

    /**
     * Get the file finder which is used to find PHP files to parse.
     */
    public function getFinder(): RecipeFinder
    {
        return $this->finder;
    }

    /**
     * Build a parser which will search through and parse multiple versions of the codebase
     */
    private function buildParser(): Parser
    {
        $this->finder = new RecipeFinder($this->collection);
        $this->finder
            ->files()
            ->name('*.php')
            ->exclude('thirdparty')
            ->exclude('tests');

        $parserContext = new ParserContext(new IncludeConfigFilter(), new DocBlockParser(), new PrettyPrinter());
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new NodeVisitor($parserContext));

        // NOTE currently the version of the dependency we're using doesn't have PHP 8 as a version, but does seem to correctly parse PHP 8 code.
        $phpParser = (new PhpParserFactory())->create(PhpParserFactory::PREFER_PHP7);
        $codeParser = new CodeParser($parserContext, $phpParser, $traverser);

        $visitors = [
            new InheritdocClassVisitor(),
            new MethodClassVisitor(),
            new PropertyClassVisitor($parserContext),
        ];
        $projectTraverser = new ProjectTraverser($visitors);
        return new Parser($this->finder, $this->store, $codeParser, $projectTraverser);
    }
}

<?php

namespace Silverstripe\DeprecationChangelogGenerator\Parse;

use Doctum\Parser\ParseError;
use Iterator;
use ReflectionProperty;
use Symfony\Component\Finder\Finder;

/**
 * Stolen from api.silverstripe.org - rework this to use supported modules API.
 */
class RecipeFinder extends Finder
{
    private RecipeVersionCollection $collection;

    private array $problems = [];

    public function __construct(RecipeVersionCollection $collection)
    {
        parent::__construct();
        $this->collection = $collection;
    }

    public function getIterator(): Iterator
    {
        // Ensure we start looking from scratch, in case the version changed since last iteration.
        $this->resetDirs();

        // Look through all supported modules we're aware of for this version
        $packages = $this->collection->getPackageNames();
        foreach ($packages as $package) {
            $path = $this->collection->getPackagePath($package);
            if (!$path) {
                $this->problems[] = new ParseError("No path for $package in '{$this->collection->getVersion()->getName()}'", null, null);
                continue;
            }
            $this->in($path);
        }

        return parent::getIterator();
    }

    /**
     * @return ParseError[]
     */
    public function getProblems(): array
    {
        return $this->problems;
    }

    /**
     * Reset the dirs property
     */
    protected function resetDirs()
    {
        // Reset $this->dirs to the list of package paths
        // I read http://fabien.potencier.org/pragmatism-over-theory-protected-vs-private.html and I feel depressed
        // @TODO the clean way would be to just not re-use the same RecipeFinder, if we can do that.
        $dirsProp = new ReflectionProperty(Finder::class, 'dirs');
        $dirsProp->setAccessible(true);
        $dirsProp->setValue($this, []);
    }
}

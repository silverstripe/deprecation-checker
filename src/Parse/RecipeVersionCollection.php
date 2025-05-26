<?php

namespace SilverStripe\DeprecationChecker\Parse;

use Doctum\Version\Version;
use Doctum\Version\VersionCollection;
use SilverStripe\DeprecationChecker\Command\CloneCommand;
use SilverStripe\DeprecationChecker\Compare\BreakingChangesComparer;
use Symfony\Component\Filesystem\Path;

/**
 * Provides a per-recipe view of all versions in a multi-repo checkout.
 */
class RecipeVersionCollection extends VersionCollection
{
    private ?Version $version = null;

    private array $supportedModules = [];

    private string $basePath;

    public function __construct(array $supportedModules, string $basePath)
    {
        parent::__construct([BreakingChangesComparer::FROM, BreakingChangesComparer::TO]);
        $this->version = $this->versions[0];
        $this->basePath = $basePath;
        foreach ($supportedModules as $moduleData) {
            // Themes have no PHP and any PHP in recipes gets pulled into the project anyway.
            if ($moduleData['type'] === 'theme' || $moduleData['type'] === 'recipe') {
                continue;
            }
            $this->supportedModules[$moduleData['packagist']] = $moduleData;
        }
    }

    /**
     * List of all package names
     */
    public function getPackageNames(): array
    {
        return ['app', ...array_keys($this->supportedModules)];
    }

    /**
     * Get path to the given package root
     */
    public function getPackagePath(string $package): string
    {
        if ($package === 'app') {
            return Path::join($this->basePath, CloneCommand::DIR_CLONE, $this->version, 'app');
        }
        return realpath(Path::join($this->basePath, CloneCommand::DIR_CLONE, $this->version, '/vendor/', $package));
    }

    /**
     * Will be one of "from" or "to"
     */
    public function getVersion(): ?Version
    {
        return $this->version;
    }

    /**
     * Switch to a specified version to collect from
     */
    protected function switchVersion(Version $version): void
    {
        $this->version = $version;
    }
}

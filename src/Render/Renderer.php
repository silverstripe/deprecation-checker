<?php

namespace Silverstripe\DeprecationChangelogGenerator\Render;

use Doctum\Project;
use Doctum\Version\Version;
use InvalidArgumentException;
use LogicException;
use Silverstripe\DeprecationChangelogGenerator\Compare\BreakingChangesComparer;
use Symfony\Component\Filesystem\Path;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderer that takes API breaking changes and renders a twig template.
 */
class Renderer
{
    private array $metaDataFrom;

    private array $metaDataTo;

    private Project $parsedProject;

    public function __construct(array $metaDataFrom, array $metaDataTo, Project $parsedProject)
    {
        $this->metaDataFrom = $metaDataFrom;
        $this->metaDataTo = $metaDataTo;
        $this->parsedProject = $parsedProject;
    }

    /**
     * Render the changelog chunk for these API breaking changes
     */
    public function render(array $breakingApiChanges, string $baseDir, string $filePath): void
    {
        $this->parsedProject->switchVersion(new Version(BreakingChangesComparer::TO));
        $data = [
            'fromVersion' => $this->metaDataFrom['branch'],
            'toVersion' => $this->metaDataTo['branch'],
            'apiChanges' => $this->getFormattedApiChanges($breakingApiChanges),
        ];
        $loader = new FilesystemLoader(Path::join(__DIR__, '../../templates'));
        $cacheDir = Path::join($baseDir, 'cache/twig');
        $twig = new Environment($loader, ['cache' => $cacheDir, 'auto_reload' => true, 'autoescape' => false]);
        $content = $twig->render('changelog.md.twig', $data);
        file_put_contents($filePath, $content);
    }

    /**
     * Get the API breaking changes in a format that's ready to be used in the template.
     * This includes flattening the list, creating human-readable messages, and sorting the list.
     */
    private function getFormattedApiChanges(array $breakingApiChanges): array
    {
        $sortedChanges = [];
        // Set the order for different API types to display
        $apiTypeOrder = [
            'class' => [],
            'method' => [],
            'config' => [],
            'property' => [],
            'const' => [],
            'function' => [],
            'param' => [],
        ];
        foreach ($breakingApiChanges as $module => $moduleChanges) {
            // Set the order for different change types to display
            $sortedChanges[$module] = [
                'removed' => $apiTypeOrder,
                'internal' => $apiTypeOrder,
                'visibility' => $apiTypeOrder,
                'returnType' => $apiTypeOrder,
                'type' => $apiTypeOrder,
                'renamed' => $apiTypeOrder,
                'new' => $apiTypeOrder, // params only
                'abstract' => $apiTypeOrder,
                'final' => $apiTypeOrder,
                'returnByRef' => $apiTypeOrder,
                'passByRef' => $apiTypeOrder,
                'readonly' => $apiTypeOrder,
                'variadic' => $apiTypeOrder,
                'default' => $apiTypeOrder,
            ];
            foreach ($moduleChanges as $changeType => $typeChanges) {
                foreach ($typeChanges as $apiType => $apiChanges) {
                    foreach ($apiChanges as $apiName => $apiData) {
                        $message = $this->getMessageForChange($changeType, $apiType, $apiName, $apiData);
                        $sortedChanges[$module][$changeType][$apiType][$apiName] = $message;
                    }
                    // asort over ksort because we want the FQCN of the API to be the sort order, not just e.g. the method name on its own.
                    asort($sortedChanges[$module][$changeType][$apiType]);
                }
            }
        }
        ksort($sortedChanges);
        // Now that everything's in the right order, flatted down so we have one array of messages per module.
        $formattedChanges = [];
        foreach ($sortedChanges as $module => $moduleChanges) {
            foreach ($moduleChanges as $changeType => $typeChanges) {
                foreach ($typeChanges as $apiType => $apiChanges) {
                    foreach ($apiChanges as $apiName => $message) {
                        $formattedChanges[$module][] = $message;
                    }
                }
            }
        }
        return $formattedChanges;
    }

    /**
     * For a given change, get the message that will be displayed in the changelog.
     */
    private function getMessageForChange(string $changeType, string $apiType, string $apiName, array $apiData): string
    {
        $apiTypeForMessage = $apiData['apiType'];
        $apiReference = $this->getApiReference($apiType, $apiName, $apiData, $changeType);
        $deprecationMessage = $apiData['message'] ?? null;
        $from = $this->normaliseChangedValue(
            $apiData[BreakingChangesComparer::FROM] ?? null,
            $apiData[BreakingChangesComparer::FROM . 'Orig'] ?? null,
            $changeType,
            $apiType
        );
        $to = $this->normaliseChangedValue(
            $apiData[BreakingChangesComparer::TO] ?? null,
            $apiData[BreakingChangesComparer::TO . 'Orig'] ?? null,
            $changeType,
            $apiType
        );
        $overriddenOrSubclassed = $apiType === 'class' ? 'subclassed' : 'overridden';

        // Make the message
        $message = match ($changeType) {
            'abstract' => ucfirst($apiTypeForMessage) . " $apiReference is now abstract",
            'internal' => ucfirst($apiTypeForMessage) . " $apiReference is now internal and should not be used",
            'default' => "Changed default value for $apiTypeForMessage $apiReference from $from to $to",
            'final' => ucfirst($apiTypeForMessage) . " $apiReference is now final and cannot be $overriddenOrSubclassed",
            'new' => "Added new $apiTypeForMessage $apiReference",
            'passByRef' => ucfirst($apiTypeForMessage) . " $apiReference is " . ($apiData['isNow'] ? 'now' : 'no longer') . ' passed by reference',
            'readonly' => ucfirst($apiTypeForMessage) . " $apiReference is " . ($apiData['isNow'] ? 'now' : 'no longer') . ' read-only',
            'renamed' => "Renamed $apiTypeForMessage $apiReference to $to",
            'removed' => "Removed deprecated $apiTypeForMessage $apiReference",
            'returnByRef' => ucfirst($apiTypeForMessage) . " $apiReference " . ($apiData['isNow'] ? 'now' : 'no longer') . ' returns its value by reference',
            'returnType' => "Changed return type for $apiTypeForMessage $apiReference from $from to $to",
            'type' => "Changed type of $apiTypeForMessage $apiReference from $from to $to",
            'variadic' => ucfirst($apiTypeForMessage) . " $apiReference is " . ($apiData['isNow'] ? 'now' : 'no longer') . ' variadic',
            'visibility' => "Changed visibility for $apiTypeForMessage $apiReference from $from to $to",
        };

        // Add deprecation message if appropriate and available
        if (($changeType === 'removed' || $changeType === 'internal') && $deprecationMessage) {
            // Tidy it up first and replace FQCN with API links
            $deprecationMessage = lcfirst(trim(preg_replace('/^(will be)/i', '', $deprecationMessage)));
            $deprecationMessage = $this->replaceClassWithApiLink($deprecationMessage);
            $message .= " - $deprecationMessage";
        }

        return $message;
    }

    /**
     * Given a message, convert all FQCN references into API links in the format used by docs.silverstripe.org.
     * For FQCN that aren't in the "to" version, surround with backticks if $backTickAsFallback is true.
     */
    private function replaceClassWithApiLink(string $message, bool $backTickAsFallback = true): string
    {
        // Regex uses example from https://www.php.net/manual/en/language.oop5.basic.php#language.oop5.basic.class
        // Captures any FQCN-looking string, plus optional const/method/property/config tacked on the end.
        // Note the quadrupal \\\\ is a regex match for a single \
        $apiRegex = '/(?<class>[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\\\\[^\s`:."\'&|-]+)(?<rest>(?:\.|::|->)[a-zA-Z0-9_-]+(?:\(\))?)?/';
        return preg_replace_callback(
            $apiRegex,
            function(array $match) use ($backTickAsFallback) {
                $class = $match['class'];
                $classReflection = $this->parsedProject->getClass($class);
                // If that class doesn't exist (e.g. symfony class or removed in new version) just backtick the full reference.
                if (!$classReflection || $classReflection->getFile() === null) {
                    return $backTickAsFallback ? "`{$match[0]}`" : $match[0];
                }
                // Get the API reference
                $rest = $match['rest'] ?? null;
                if ($rest) {
                    if (preg_match('/^::(.*)\(\)$/', $rest, $restMatch)) {
                        return $this->getApiReference('method', $restMatch[1], ['class' => $class]);
                    } elseif (preg_match('/^::(.*)$/', $rest, $restMatch)) {
                        return $this->getApiReference('const', $restMatch[1], ['class' => $class]);
                    } elseif (preg_match('/^.(.*)/', $rest, $restMatch)) {
                        return $this->getApiReference('config', $restMatch[1], ['class' => $class]);
                    } elseif (preg_match('/^->(.*)/', $rest, $restMatch)) {
                        return $this->getApiReference('property', $restMatch[1], ['class' => $class]);
                    } else {
                        throw new LogicException("Unexpected API reference in deprecation notice: '{$match[0]}'");
                    }
                }
                return $this->getApiReference('class', $class);
            },
            $message
        );
    }

    /**
     * Get a documentation markdown friendly string to reference a specific piece of API
     */
    private function getApiReference(string $apiType, string $apiName, array $apiData = [], string $changeType = ''): string
    {
        if ($apiType === 'class') {
            if ($changeType === 'removed') {
                return "`{$apiName}`";
            }
            $shortName = $this->getShortClassName($apiName);
            return "[`{$shortName}`](api:{$apiName})";
        }
        if ($apiType === 'method') {
            $className = $apiData['class'];
            if ($changeType === 'removed') {
                return "`{$className}::{$apiName}()`";
            }
            $shortName = $this->getShortClassName($className);
            return "[`{$shortName}::{$apiName}()`](api:{$className}::{$apiName}())";
        }
        if ($apiType === 'property') {
            $className = $apiData['class'];
            if ($changeType === 'removed') {
                return "`{$className}->{$apiName}`";
            }
            $shortName = $this->getShortClassName($className);
            return "[`{$shortName}->{$apiName}`](api:{$className}->{$apiName})";
        }
        if ($apiType === 'config') {
            $className = $apiData['class'];
            if ($changeType === 'removed') {
                return "`{$className}.{$apiName}`";
            }
            $shortName = $this->getShortClassName($className);
            return "[`{$shortName}.{$apiName}`](api:{$className}->{$apiName})";
        }
        if ($apiType === 'const') {
            $className = $apiData['class'];
            if ($changeType === 'removed') {
                return "`{$className}::{$apiName}`";
            }
            $shortName = $this->getShortClassName($className);
            return "[`{$shortName}::{$apiName}`](api:{$className}::{$apiName})";
        }
        if ($apiType === 'function') {
            if ($changeType === 'removed') {
                return "`{$apiName}()`";
            }
            return "[`{$apiName}()`](api:{$apiName}())";
        }
        if ($apiType === 'param') {
            $function = $apiData['function'];
            if ($function) {
                $parent = $this->getApiReference('function', $function);
            } else {
                $method = $apiData['method'];
                $class = $apiData['class'];
                $parent = $this->getApiReference('method', $method, ['class' => $class]);
            }
            return "`\$$apiName` in $parent";
        }
        throw new InvalidArgumentException("Unexpected API type $apiType");
    }

    /**
     * Get the short (un-namespaced) name for a class.
     */
    private function getShortClassName(string $className): string
    {
        $parts = explode('\\', $className);
        return array_pop($parts);
    }

    /**
     * Normalise a value into a consistent representation for the changelog.
     */
    private function normaliseChangedValue(?string $value, ?string $origValue, string $changeType, string $apiType): string
    {
        if ($value && $changeType === 'renamed' && $apiType === 'param') {
            return "`\$$value`";
        }

        if ($value === null || $value === '') {
            // Depending on the type of change, the reference is different.
            return match ($changeType) {
                'type' => 'dynamic',
                'returnType' => 'dynamic',
                'visibility' => 'undefined',
                'default' => 'none',
                default => '',
            };
        }
        $valueWithLinks = $this->replaceClassWithApiLink($value, false);

        // If any class wasn't resolved into an API link, assume $value is malformed and fall back to $origValue.
        // See https://github.com/code-lts/doctum/issues/76 for why this is necessary.
        $apiLinkRegex = '/\[`.+`\]\(api:.+\)/';
        if (!preg_match($apiLinkRegex, $valueWithLinks) && str_contains($valueWithLinks, '\\')) {
            $valueWithLinks = $origValue;
        }

        // For now BreakingChangesComparer provides only one of `|` or `&` so we don't need to worry about weird
        // union/intersection combinations
        $parts = explode('&', $valueWithLinks);
        $separator = '&';
        if (count($parts) === 1) {
            $parts = explode('|', $valueWithLinks);
            $separator = '|';
        }
        $normalised = '';
        $prevWasApiLink = false;
        // Wrap backticks around all parts that aren't API links
        foreach ($parts as $i => $type) {
            $isFirst = $i === 0;
            if (preg_match($apiLinkRegex, $type)) {
                // $type is an API link
                if (!$isFirst) {
                    $normalised .= $separator . '`';
                }
                $normalised .= $type;
                $prevWasApiLink = true;
            } else {
                if ($prevWasApiLink) {
                    $normalised .= '`';
                }
                $normalised .= ($isFirst ? '`' : $separator) . $type;
                $prevWasApiLink = false;
            }
        }
        if (!$prevWasApiLink) {
            $normalised .= '`';
        }
        return $normalised;
    }
}

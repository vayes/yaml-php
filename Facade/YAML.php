<?php

namespace Vayes\YamlPhp\Facade;

use Vayes\Facade\Facade;
use Vayes\YamlPhp\Provider\YamlProvider;

/**
 * @method static read($file): array
 * @method static getCachedFilename($file, ?string $customSegment = null, ?string $cachePath = null): string
 * @method static getYamlAsArray(array $yamlFiles): array
 * @method static getMode(): string
 * @method static setMode(string $mode): self
 * @method static getIgnoreKeys(): array
 * @method static setIgnoreKeys(array $ignoreKeys): self
 * @method static isIncludeMainKey(): bool
 * @method static setIncludeMainKey(bool $includeMainKey): self
 * @method static flatten(array &$items, array $subNode = null, $path = null)
 */
class YAML extends Facade
{
    const FLATTEN = 'flatten';
    const STRUCTURED = 'structured';

    /**
     * @inheritDoc
     */
    protected static function getFacadeAccessor(): string
    {
        return YamlProvider::class;
    }
}

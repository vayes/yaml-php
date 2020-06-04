<?php

namespace Vayes\YamlPhp\Provider;

use Symfony\Component\Yaml\Yaml;
use Vayes\Exception\CacheWriteFailException;
use Vayes\Exception\FileNotFoundException;
use Vayes\Exception\InvalidArgumentException;
use function Vayes\Str\str_starts;

class YamlProvider
{
    const FLATTEN = 'flatten';
    const STRUCTURED = 'structured';

    /** @var string */
    protected $mode = self::FLATTEN;

    /** @var array */
    protected $ignoreKeys = [];

    /** @var bool */
    protected $includeMainKey = true;

    /**
     * Reads given YAML file(s) and returns as array
     *
     * @param string|array $file
     * @return array
     */
    public function read($file): array
    {
        $file = (array) $file;

        $yamlFiles = $this->getYamlFilesPathInfo($file);

        return $this->getYamlAsArray($yamlFiles);
    }

    /**
     * Returns compiled php file name. If not compiled yet, it compiles first.
     *
     * @param mixed         $file
     * @param string|null   $customSegment
     * @param string|null   $cachePath
     * @return string
     */
    public function getCachedFilename(
        $file,
        ?string $customSegment = null,
        ?string $cachePath = null
    ): string {
        $file = (array) $file;

        $yamlFiles = $this->getYamlFilesPathInfo($file);

        $cacheSignature = $this->getUniqueSignature($yamlFiles) . '.php';

        // Ensure a trailing slash
        $cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $cachedFilePath = $this->isCachedBefore($cacheSignature, $cachePath);

        if (null === $cachedFilePath) {
            $yamlArrayMeta = $this->getYamlAsArray($yamlFiles);
            $phpStringMeta = $this->convertArrayPhpString($yamlArrayMeta, $customSegment);

            $this->writeToFile($phpStringMeta, $cachePath . $cacheSignature);
            $cachedFilePath = $cachePath . $cacheSignature;
        }

        $this->setMode(self::FLATTEN);
        $this->setIgnoreKeys([]);
        $this->setIncludeMainKey(true);

        return pathinfo($cachedFilePath, PATHINFO_FILENAME);
    }

    /**
     * Returns an array with path info of yaml files
     *
     * @param string|array  $file
     * @return array
     */
    private function getYamlFilesPathInfo($file): array
    {
        $file  = (array) $file;
        $yamlFiles = [];

        $iterator = 0;
        $hashed = (string) null;

        foreach ($file as $_file) {
            $_fileName = pathinfo($_file, PATHINFO_BASENAME);

            $_filePath = rtrim(
                pathinfo($_file, PATHINFO_DIRNAME),
                DIRECTORY_SEPARATOR
            ) . DIRECTORY_SEPARATOR;

            if (false === stripos($_fileName, '.yaml')) {
                $_fileName .= '.yaml';
            }

            $yamlFiles[$iterator]['name'] = $_fileName;
            $yamlFiles[$iterator]['path'] = $_filePath;

            if (false === file_exists($_filePath . $_fileName)) {

                if (false === file_exists($_filePath . 'd.' . $_fileName)) {
                    throw new FileNotFoundException(
                        null,
                        0,
                        null,
                        $_filePath.$_fileName
                    );
                } else {
                    $yamlFiles[$iterator]['name'] = 'd.' . $_fileName;
                }
            }

            $iterator++;
        }

        return $yamlFiles;
    }

    /**
     * Returns unique signature for given YAML file(s)
     *
     * @param array $yamlFiles
     * @return string
     */
    private function getUniqueSignature(array $yamlFiles): string
    {
        $signature = (string) null;
        foreach ($yamlFiles as $yamlFile) {
            $signature .= $yamlFile['path'].$yamlFile['name'] . '+';
        }

        return 'yaml.' . md5($signature);
    }

    /**
     * Check if cached/compiled file is already created.
     * If exists, return full path. If not, returns null.
     *
     * @param string $cacheSignature
     * @param string $cachePath
     * @return string|null
     */
    private function isCachedBefore(string $cacheSignature, string $cachePath): ?string
    {
        if (file_exists($cachePath.$cacheSignature)) {
            return $cachePath.$cacheSignature;
        }

        return null;
    }

    /**
     * Returns an array with 'content', 'docblock', 'files' keys.
     *
     * @param array $yamlFiles
     * @return array
     */
    public function getYamlAsArray(array $yamlFiles): array
    {
        $contentArray = [];
        $pathForDocBlock = [];

        foreach ($yamlFiles as $yamlFile) {
            $arr = Yaml::parseFile($yamlFile['path'] . $yamlFile['name']);
            $contentArray = array_replace_recursive($contentArray, $arr);
            $pathForDocBlock[] = ' * - ' . str_replace(
                ROOTPATH,
                null,
                $yamlFile['path'] . $yamlFile['name']
            );
        }

        $pathForDocBlock = implode(",\n", $pathForDocBlock);
        $docBlock = "/**\n * This file is compiled from the following YAML file(s):\n$pathForDocBlock\n */\n";

        if (false === empty($this->getIgnoreKeys())) {
            foreach ($contentArray as $key => $value) {
                if (true === in_array($key, $this->getIgnoreKeys())) {
                    unset($contentArray[$key]);
                }
            }
        }

        if (false === $this->isIncludeMainKey()) {
            $firstKey = array_key_first($contentArray);
            $contentArray = $contentArray[$firstKey];
        }


        return [
            'content'  => $contentArray,
            'docblock' => $docBlock,
            'files'    => $yamlFiles
        ];
    }

    /**
     * Returns an array with 'writeData', 'docblock', 'files' keys.
     *
     * @param array       $yamlData
     * @param string|null $customSegment
     * @return array
     */
    private function convertArrayPhpString(array $yamlData, ?string $customSegment = null): array
    {
        $contentString = (string) null;

        switch ($this->getMode()) {
            case self::STRUCTURED:
                $contentString = $this->processorStructured($yamlData['content']);
                break;
            case self::FLATTEN:
            default:
                if (null === $customSegment) {
                    throw new InvalidArgumentException('$customSegment is not provided.');
                }
                $contentString = $this->processorFlatten($yamlData['content'], $customSegment);
                break;
        }

        return [
            'writeData' => $contentString,
            'docblock'  => $yamlData['docblock'],
            'files'     => $yamlData['files']
        ];
    }

    /**
     * Writes yaml array to targetFile as php.
     *
     * @param $contentData
     * @param $targetFile
     * @return bool
     */
    private function writeToFile($contentData, $targetFile): bool
    {
        $data = "<?php\n\n";

        if (false === empty($contentData['docblock'])) {
            $data .= $contentData['docblock'] . "\n";
        }

        if (false === empty($contentData['writeData'])) {
            $data .= rtrim($contentData['writeData'], "\n") . "\n";
        }

        if (false === file_put_contents($targetFile, $data)) {
            throw new CacheWriteFailException(
                null,
                0,
                null,
                $targetFile
            );
        }

        if (defined('CI_VERSION')) {
            $batch = (string) rand(1000, 10000);
            foreach ($contentData['files'] as $yamlFile) {
                log_message('debug', sprintf(
                    '[YAML] %s is compiled from YAML and cached to %s in batch #%s',
                    str_replace(ROOTPATH, null, $yamlFile['path'].$yamlFile['name']),
                    str_replace(ROOTPATH, null, pathinfo($targetFile, PATHINFO_DIRNAME)),
                    $batch
                ));
            }
        }

        return true;
    }

    /**
     * @param $array
     * @param $customSegment
     * @return string
     */
    protected function processorFlatten(&$array, $customSegment): string
    {
        $this->flatten($array);

        $segment = null;

        preg_match('/\[\'(.*?)\'\]/i', $customSegment, $matches);

        if (false === empty($matches)) {
            $segment = $matches[1];
        }

        $content = (string) null;
        foreach ($array as $k => $v) {
            if (false === is_null($segment)) {
                $k = str_replace("{$segment}.", null, $k);
            }
            // Check the sign to enable to use php functions in keys
            if (str_starts('≈', $k)) {
                $k = str_replace('≈', null, $k);
                $content .= "\${$customSegment}[{$k}] = ";
            } else {
                $content .= "\${$customSegment}['{$k}'] = ";
            }

            if (str_starts('≈', $v)) {
                $v = str_replace('≈', null, $v);
                $content .= "{$v};\n";
            } else {
                $content .= "'{$v}';\n";
            }
        }

        return $content;
    }

    /**
     * @param array  $items
     * @param string $currentLine
     * @param array  $result
     * @return string
     */
    protected function processorStructured(array $items, $currentLine = '', &$result = array()): string
    {
        foreach ($items as $key => $val) {
            $newLine = empty($currentLine) ? "\$config['{$key}']" : $currentLine . "['{$key}']";

            // Overwrite to enable to use php functions in keys
            if (false === empty($currentLine)) {
                if (str_starts('≈', $key)) {
                    $key = str_replace('≈', null, $key);
                    $newLine = $currentLine . "[" . $key . "]";
                }
            }

            if (false === is_array($val)) {
                $line = $newLine . " = '{$val}'";
                $result[] = $line;
            } else {
                $this->processorStructured($val, $newLine, $result);
            }
        }

        return implode(";\n", $result) . ";\n";
    }

    /**
     * Flattens an nested array.
     *
     * The scheme used is:
     *   'key' => array('key2' => array('key3' => 'value'))
     * Becomes:
     *   'key.key2.key3' => 'value'
     *
     * This function takes an array by reference and will modify it
     *
     * @param array  &$items The array that will be flattened
     * @param array  $subNode   Current subNode being parsed, used internally for recursive calls
     * @param string $path      Current path being parsed, used internally for recursive calls
     */
    public function flatten(array &$items, array $subNode = null, $path = null)
    {
        if (null === $subNode) {
            $subNode = &$items;
        }
        foreach ($subNode as $key => $value) {
            if (is_array($value)) {
                $nodePath = $path ? $path.'.'.$key : $key;
                $this->flatten($items, $value, $nodePath);
                if (null === $path) {
                    unset($items[$key]);
                }
            } elseif (null !== $path) {
                $items[$path.'.'.$key] = $value;
            }
        }
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * @param string $mode
     * @return $this
     */
    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        return $this;
    }

    public function getIgnoreKeys(): array
    {
        return $this->ignoreKeys;
    }

    /**
     * @param array $ignoreKeys
     * @return $this
     */
    public function setIgnoreKeys(array $ignoreKeys): self
    {
        $this->ignoreKeys = $ignoreKeys;
        return $this;
    }

    public function isIncludeMainKey(): bool
    {
        return $this->includeMainKey;
    }

    /**
     * @param bool $includeMainKey
     * @return $this
     */
    public function setIncludeMainKey(bool $includeMainKey): self
    {
        $this->includeMainKey = $includeMainKey;
        return $this;
    }
}

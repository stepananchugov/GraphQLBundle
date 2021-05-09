<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Generator;

use Composer\Autoload\ClassLoader;
use Overblog\GraphQLBundle\Config\Processor;
use Overblog\GraphQLBundle\Event\SchemaCompiledEvent;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use function array_merge;
use function file_exists;
use function file_put_contents;
use function str_replace;
use function var_export;

/**
 * @final
 */
class TypeGenerator
{
    public const MODE_DRY_RUN = 1;
    public const MODE_MAPPING_ONLY = 2;
    public const MODE_WRITE = 4;
    public const MODE_OVERRIDE = 8;
    public const GRAPHQL_SERVICES = 'services';

    private static bool $classMapLoaded = false;
    public TypeGeneratorOptions $options;
    private TypeBuilder $typeBuilder;
    private EventDispatcherInterface $dispatcher;

    public function __construct(TypeBuilder $typeBuilder, EventDispatcherInterface $dispatcher, TypeGeneratorOptions $options)
    {
        $this->typeBuilder = $typeBuilder;
        $this->dispatcher = $dispatcher;
        $this->options = $options;
    }

    public function compile(int $mode): array
    {
        $cacheDir = $this->options->getCacheDirOrDefault();
        $writeMode = $mode & self::MODE_WRITE;

        // Configure write mode
        if ($writeMode && file_exists($cacheDir)) {
            $fs = new Filesystem();
            $fs->remove($cacheDir);
        }

        // Process configs
        $types = Processor::process($this->options->types);

        // Generate classes
        $classes = [];
        foreach ($types as $name => $config) {
            $config['config']['name'] ??= $name;
            $config['config']['class_name'] = $config['class_name'];
            $classMap = $this->generateClass($config, $cacheDir, $mode);
            $classes = array_merge($classes, $classMap);
        }

        // Create class map file
        if ($writeMode && $this->options->useClassMap && count($classes) > 0) {
            $content = "<?php\nreturn ".var_export($classes, true).';';

            // replaced hard-coded absolute paths by __DIR__
            // (see https://github.com/overblog/GraphQLBundle/issues/167)
            $content = str_replace(" => '$cacheDir", " => __DIR__ . '", $content);

            file_put_contents($this->getClassesMap(), $content);

            $this->loadClasses(true);
        }

        $this->dispatcher->dispatch(new SchemaCompiledEvent());

        return $classes;
    }

    public function generateClass(array $config, ?string $outputDirectory, int $mode = self::MODE_WRITE): array
    {
        $className = $config['config']['class_name'];
        $path = "$outputDirectory/$className.php";

        if (!($mode & self::MODE_MAPPING_ONLY)) {
            $phpFile = $this->typeBuilder->build($config['config'], $config['type']);

            if ($mode & self::MODE_WRITE) {
                if (($mode & self::MODE_OVERRIDE) || !file_exists($path)) {
                    $phpFile->save($path);
                }
            }
        }

        $namespace = $this->options->namespace;

        return ["$namespace\\$className" => $path];
    }

    public function loadClasses(bool $forceReload = false): void
    {
        if ($this->options->useClassMap && (!self::$classMapLoaded || $forceReload)) {
            $classMapFile = $this->getClassesMap();
            $classes = file_exists($classMapFile) ? require $classMapFile : [];

            /** @var ClassLoader $mapClassLoader */
            static $mapClassLoader = null;

            if (null === $mapClassLoader) {
                $mapClassLoader = new ClassLoader();
                $mapClassLoader->setClassMapAuthoritative(true);
            } else {
                $mapClassLoader->unregister();
            }

            $mapClassLoader->addClassMap($classes);
            $mapClassLoader->register();

            self::$classMapLoaded = true;
        }
    }

    private function getClassesMap(): string
    {
        return $this->options->getCacheDirOrDefault().'/__classes.map';
    }
}

<?php

declare(strict_types=1);

namespace NunoMaduro\Larastan;

use const DIRECTORY_SEPARATOR;
use Illuminate\Contracts\Foundation\Application;
use function in_array;
use NunoMaduro\Larastan\Support\ClassMapGenerator;
use Orchestra\Testbench\Concerns\CreatesApplication;

/**
 * @internal
 */
final class ApplicationResolver
{
    use CreatesApplication;

    /** @var mixed */
    public static $composer;

    /** @var bool */
    protected $enablesPackageDiscoveries = true;

    /**
     * Creates an application and registers service providers found.
     *
     * @throws \ReflectionException
     */
    public static function resolve(): Application
    {
        $app = (new self)->createApplication();

        $composerFile = getcwd().DIRECTORY_SEPARATOR.'composer.json';

        if (file_exists($composerFile)) {
            self::$composer = json_decode((string) file_get_contents($composerFile), true);
            $namespace = (string) key(self::$composer['autoload']['psr-4']);
            $vendorDir = self::$composer['config']['vendor-dir'] ?? dirname($composerFile).DIRECTORY_SEPARATOR.'vendor';
            $serviceProviders = array_values(array_filter(self::getProjectClasses($namespace, $vendorDir), static function ($class) use (
                $namespace
            ) {
                /** @var class-string $class */
                return str_starts_with($class, $namespace) && self::isServiceProvider($class);
            }));

            foreach ($serviceProviders as $serviceProvider) {
                $app->register($serviceProvider);
            }
        }

        return $app;
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // ..
    }

    /**
     * @phpstan-param class-string $class
     *
     *
     * @throws \ReflectionException
     */
    private static function isServiceProvider(string $class): bool
    {
        $classParents = class_parents($class);

        if (! $classParents) {
            return false;
        }

        return in_array(\Illuminate\Support\ServiceProvider::class, $classParents, true)
            && ! (new \ReflectionClass($class))->isAbstract();
    }

    /**
     * @return string[]
     * @throws \ReflectionException
     */
    private static function getProjectClasses(string $namespace, string $vendorDir): array
    {
        $projectDirs = self::getProjectSearchDirs($namespace, $vendorDir);
        /** @var array<string, string> $maps */
        $maps = [];
        // Use composer's ClassMapGenerator to pull the class list out of each project search directory
        foreach ($projectDirs as $dir) {
            $maps = array_merge($maps, ClassMapGenerator::createMap($dir));
        }

        // Create array of dev classes from Composer configuration.
        $devClasses = [];
        $autoloadDev = self::$composer['autoload-dev'] ?? [];
        $autoloadDevPsr4 = $autoloadDev['psr-4'] ?? [];
        foreach ($autoloadDevPsr4 as $paths) {
            $paths = is_array($paths) ? $paths : [$paths];

            foreach ($paths as $path) {
                $devClasses = array_merge($devClasses, array_keys(ClassMapGenerator::createMap($path)));
            }
        }

        // now class list of maps are assembled, use class_exists calls to explicitly autoload them,
        // while not running them
        /**
         * @var string $class
         * @var string $file
         */
        foreach ($maps as $class => $file) {
            if (! in_array($class, $devClasses, true)) {
                class_exists($class, true);
            }
        }

        return get_declared_classes();
    }

    /**
     * @return string[]
     *
     * @throws \ReflectionException
     */
    private static function getProjectSearchDirs(string $namespace, string $vendorDir): array
    {
        $composerDir = $vendorDir.DIRECTORY_SEPARATOR.'composer';

        $file = $composerDir.DIRECTORY_SEPARATOR.'autoload_psr4.php';
        $raw = include $file;

        return $raw[$namespace];
    }
}

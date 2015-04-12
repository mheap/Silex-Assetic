<?php

namespace SilexAssetic;

use SilexAssetic\Assetic\Dumper;
use SilexAssetic\Assetic\Router;

use Pimple\Container;
use Pimple\ServiceProviderInterface;

use Silex\Application;
use Silex\Api\BootableProviderInterface;

use Assetic\AssetManager;
use Assetic\FilterManager;
use Assetic\AssetWriter;
use Assetic\Asset\AssetCache;
use Assetic\Factory\AssetFactory;
use Assetic\Factory\LazyAssetManager;
use Assetic\Cache\FilesystemCache;
use Assetic\Extension\Twig\TwigFormulaLoader;
use Assetic\Extension\Twig\AsseticExtension;

class AsseticServiceProvider implements ServiceProviderInterface, BootableProviderInterface
{
    public function register(Container $app)
    {
        $app['assetic.options'] = array();

        /**
         * Asset Factory configuration happens here
         */
        $app['assetic'] = function () use ($app) {
            $app['assetic.options'] = array_replace(array(
                'debug'              => isset($app['debug']) ? $app['debug'] : false,
                'formulae_cache_dir' => null,
                'auto_dump_assets'   => isset($app['debug']) ? !$app['debug'] : true,
                'route_assets'       => false,
            ), $app['assetic.options']);

            // initializing lazy asset manager
            if (isset($app['assetic.formulae']) &&
               !is_array($app['assetic.formulae']) &&
               !empty($app['assetic.formulae'])
            ) {
                $app['assetic.lazy_asset_manager'];
            }

            return $app['assetic.factory'];
        };

        /**
         * Factory
         *
         * @return Assetic\Factory\AssetFactory
         */
        $app['assetic.factory'] = function () use ($app) {
            $root = isset($app['assetic.path_to_source']) ? $app['assetic.path_to_source'] : $app['assetic.path_to_web'];
            $factory = new AssetFactory($root, $app['assetic.options']['debug']);
            $factory->setAssetManager($app['assetic.asset_manager']);
            $factory->setFilterManager($app['assetic.filter_manager']);

            return $factory;
        };

        /**
         * Asset writer, writes to the 'assetic.path_to_web' folder
         *
         * @return Assetic\AssetWriter
         */
        $app['assetic.asset_writer'] = function () use ($app) {
            return new AssetWriter($app['assetic.path_to_web']);
        };

        /**
         * Asset manager
         *
         * @return Assetic\AssetManager
         */
        $app['assetic.asset_manager'] = function () use ($app) {
            return new AssetManager();
        };

        /**
         * Filter manager
         *
         * @return Assetic\FilterManager
         */
        $app['assetic.filter_manager'] = function () use ($app) {
            return new FilterManager();
        };

        /**
         * Lazy asset manager for loading assets from $app['assetic.formulae']
         * (will be later maybe removed)
         */
        $app['assetic.lazy_asset_manager'] = function () use ($app) {
            $formulae = isset($app['assetic.formulae']) ? $app['assetic.formulae'] : array();
            $options  = $app['assetic.options'];
            $lazy     = new LazyAssetmanager($app['assetic.factory']);

            if (empty($formulae)) {
                return $lazy;
            }

            foreach ($formulae as $name => $formula) {
                $lazy->setFormula($name, $formula);
            }

            if ($options['formulae_cache_dir'] !== null && $options['debug'] !== true) {
                foreach ($lazy->getNames() as $name) {
                    $lazy->set($name, new AssetCache(
                        $lazy->get($name),
                        new FilesystemCache($options['formulae_cache_dir'])
                    ));
                }
            }

            return $lazy;
        };

        $app['assetic.dumper'] = function () use ($app) {
            return new Dumper(
                $app['assetic.asset_manager'],
                $app['assetic.lazy_asset_manager'],
                $app['assetic.asset_writer']
            );
        };

        $app['assetic.router'] = function() use ($app) {
            return new Router($app);
        };

        if (isset($app['twig'])) {
            $app->extend('twig', function ($twig, $app) {
                $twig->addExtension(new AsseticExtension($app['assetic']));

                return $twig;
            });

            $app->extend('assetic.lazy_asset_manager', function ($am, $app) {
                $am->setLoader('twig', new TwigFormulaLoader($app['twig']));

                return $am;
            });

            $app->extend('assetic.dumper', function ($helper, $app) {
                $helper->setTwig($app['twig'], $app['twig.loader.filesystem']);

                return $helper;
            });
        }
    }

    /**
     * Bootstraps the application.
     *
     * @param \Silex\Application $app The application
     */
    public function boot(Application $app)
    {

        // Register our filters to use
        if (isset($app['assetic.filters']) && is_callable($app['assetic.filters'])) {
            $app['assetic.filters']($app['assetic.filter_manager']);
        }

        /**
         * Writes down all lazy asset manager and asset managers assets
         */
        $app->after(function () use ($app) {
            // Boot assetic
            $assetic = $app['assetic'];
            if (isset($app['assetic.options']['auto_dump_assets']) && $app['assetic.options']['auto_dump_assets']) {
                $helper = $app['assetic.dumper'];
                if (isset($app['twig'])) {
                    $helper->addTwigAssets();
                }
                $helper->dumpAssets();
            } elseif (isset($app['assetic.options']['route_assets']) && $app['assetic.options']['route_assets']) {
                /** @var Dumper $dumper */
                $dumper = $app['assetic.dumper'];
                if (isset($app['twig'])) {
                    $dumper->addTwigAssets();
                }

                /** @var Router $router */
                $router = $app['assetic.router'];
                $router->routeLazyAssetManager($app['assetic.lazy_asset_manager']);
            }
        });
    }
}

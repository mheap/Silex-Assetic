<?php

namespace SilexAssetic\Assetic;

use Silex\Application;
use Assetic\Asset\BaseAsset;
use Assetic\Factory\LazyAssetManager;

class Router
{
    /**
     * @var \Silex\Application
     */
    protected $app;

    /**
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Create routes for lazy manager assets.
     *
     * @param LazyAssetManager $lazyAssetManager
     */
    public function routeLazyAssetManager(LazyAssetManager $lazyAssetManager)
    {
        foreach ($lazyAssetManager->getNames() as $name) {
            $asset = $lazyAssetManager->get($name);

            /** @var $leaf BaseAsset */
            foreach ($asset as $leaf) {
                $this->app->get($leaf->getTargetPath(), function(Application $app) use ($leaf) {
                    return $app->sendFile($leaf->getSourceRoot() . DIRECTORY_SEPARATOR . $leaf->getSourcePath());
                });
            }
        }
    }


}

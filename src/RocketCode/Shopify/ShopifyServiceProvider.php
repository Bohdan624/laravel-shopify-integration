<?php namespace RocketCode\Shopify;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Validator;
use Illuminate\Console\Scheduling\Schedule;

class ShopifyServiceProvider extends ServiceProvider
{

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('ShopifyAPI', function ($app, $config = false) {
            return new API($config);
        });
    }

    public function boot()
    {
        AliasLoader::getInstance()->alias('ShopifyAPI', 'RocketCode\Shopify\API');
        Validator::extend('shopify_domain', function ($attribute, $value, $parameters, $validator) {
            // from https://stackoverflow.com/questions/1755144/how-to-validate-domain-name-in-php
            return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*\.myshopify\.com$/i", $value) //valid chars check
                && preg_match("/^.{1,253}$/", $value) //overall length check
                && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $value)); //length of each label
        });
        // incorporating the Routes, Views, Migrations to the package
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadViewsFrom(__DIR__.'/views', 'shopify');

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->call(function () {
                $api = new API;
                $api->emailAndProcess();
            })->everyMinute();
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['ShopifyAPI', 'RocketCode\Shopify\API'];
    }
}

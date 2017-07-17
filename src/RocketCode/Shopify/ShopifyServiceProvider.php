<?php namespace RocketCode\Shopify;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Validator;

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
		$this->app->bind('ShopifyAPI', function($app, $config = FALSE)
		{
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

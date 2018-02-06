<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('shopify::welcome');
});
Route::get('/install', 'RocketCode\Shopify\ShopifyController@install');
Route::post('/install', 'RocketCode\Shopify\ShopifyController@getInstallUrl');
Route::get('/success', 'RocketCode\Shopify\ShopifyController@success');
Route::get('/get', 'RocketCode\Shopify\ShopifyController@get');
Route::get('/delete', 'RocketCode\Shopify\ShopifyController@delete');
Route::get('webhook/delete/{id}', 'RocketCode\Shopify\ShopifyController@deleteWebhook');
Route::get('webhook/get', 'RocketCode\Shopify\ShopifyController@getWebhooks');
Route::get('/queue/reset', 'RocketCode\Shopify\ShopifyQueueController@resetQueue');

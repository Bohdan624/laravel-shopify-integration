<?php

namespace RocketCode\Shopify;

use Illuminate\Support\Facades\App;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use Log;

// use App\Shop;

class ShopifyController extends Controller
{
    private $myshopify_domain = null;
    public $sh = null;
    
    public function __construct()
    {
        $this->sh = App::make('ShopifyAPI');
    }

    /**
     * switches to the newly installed shop and
     * gets the first shop with the matching domain
     * or creates a record if it doesn't exist
     * and updates the name and token
     * @param object $request
     */
    public function success(Request $request)
    {
        $code = $request->get('code');
        $data = array();

        $this->shopSwitch($request->get('shop'), true);

        try {
            $accessToken = $this->sh->getAccessToken($code);

            $data['shopify_token'] = $accessToken;
            $data['name'] = $request->get('shop');
            $data['myshopify_domain'] = $request->get('shop');
            
            $shop = Shop::firstOrNew(['myshopify_domain' => $data['myshopify_domain']]);
            $shop->name = $data['name'];
            $shop->shopify_token = $data['shopify_token'];

            $shop->save();
            
            return view('shopify::success', compact('data'));
        } catch (Exception $e) {
            echo '<pre>Error: ' . $e->getMessage() . '</pre>';
        }
    }

    public function install(Request $request)
    {
        return view('shopify::install');
    }

    /**
     * Switch or Install the registered shopify_domain and
     * redirect to /success
     * @param object $request
     */
    public function getInstallUrl(Request $request)
    {
        $myshopify_domain = $request->get('myshopify_domain');
        $this->shopSwitch($myshopify_domain, true);
        $redirect = $this->sh->installURL(['permissions' => array('write_orders', 'write_products', 'write_content', 'read_content', 'write_customers', 'read_customers', 'write_draft_orders', 'read_draft_orders', 'read_checkouts', 'write_checkouts', 'read_shipping', 'write_shipping', 'read_reports', 'write_reports'), 'redirect' => secure_url('/success')]);
        return redirect($redirect);
    }
    
    /**
     * Switches to the given shop domain.
     * Creates the shop entry in the shopify_shops table if it doesn't exist
     * @param String $shop
     * @param bool $install
     */
    public function shopSwitch($shop, $install = false)
    {
        if ($this->myshopify_domain != $shop) {
            $this->myshopify_domain = $shop;
            $shop = \RocketCode\Shopify\Shop::where('myshopify_domain', '=', $this->myshopify_domain)->first();
            $setupArray = [
                'API_KEY' => env('SHOPIFY_APP_ID'),
                'API_SECRET' => env('SHOPIFY_APP_SECRET'),
            ];

            if ($shop) {
                $setupArray['SHOP_DOMAIN'] = $shop->myshopify_domain;
                $setupArray['ACCESS_TOKEN'] = $shop->shopify_token;
            } elseif ($install) {
                $setupArray['SHOP_DOMAIN'] = $this->myshopify_domain;
            }

            $this->sh->setup($setupArray);
        }
    }

    /**
     * Clones the webhooks from the given $shopify_domain while excluding the shops from $excluded_shops
     * @param String $shopify_domain
     * @param Array $excluded_shops
     */
    public function cloneWebhooks($shopify_domain, $excluded_shops = array())
    {
        // Switch to the given $shopify_domain
        $this->shopSwitch($shopify_domain);
        // Get all the webhooks for the current $shopify_domain
        $webhooks = $this->sh->getWebhooks();
        // format the webhooks to match the setupwebhooks function e.g. ['products/create' => 'http://address']
        foreach ($webhooks as $webhook) {
            $formatted_webhooks[$webhook->topic] = $webhook->address;
        }
        // Get all the shops while excluding the shops from $excluded_shops
        $shops = Shop::whereNotIn('myshopify_domain', $excluded_shops)->get();
        foreach ($shops as $shop) {
            $this->shopSwitch($shop->myshopify_domain);
            // passing in the webhooks to clone
            $this->sh->setupWebhooks($formatted_webhooks);
        }
    }

    /**
     * Returns a list of active webhooks for all the shops
     */
    public function getWebhooks()
    {
        return $this->sh->getWebhooks();
    }
}

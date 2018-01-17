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
            
            return view('success', compact('data'));
        } catch (Exception $e) {
            echo '<pre>Error: ' . $e->getMessage() . '</pre>';
        }
    }

    public function install(Request $request)
    {
        return view('install');
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
     * Creates the domain if it doesn't exist
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
}

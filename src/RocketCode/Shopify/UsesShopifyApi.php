<?php

namespace RocketCode\Shopify;

/**
 * This trait should be used to implement the interface ShopifyApiUser
 */
trait UsesShopifyApi {
    protected $api;

    /**
     * Get the Shopify API object to query Shopify
     *
     * @return \RocketCode\Shopify\API
     */
    public function getShopifyApi() {
        if (!($this->api instanceof API)) {
            $this->api = new API([
                'API_KEY' => env('SHOPIFY_APP_ID'),
                'API_SECRET' => env('SHOPIFY_APP_SECRET'),
                'SHOP_DOMAIN' => $this->getDomain(),
                'ACCESS_TOKEN' => $this->getAccessToken(),
            ]);
        }
        return $this->api;
    }

    /**
     * Returns the domain for the shop queried by the API
     *
     * @return string
     */
    abstract protected function getDomain();

    /**
     * Returns the access token for the shop queried by the API
     *
     * @return string
     */
    abstract protected function getAccessToken();
}
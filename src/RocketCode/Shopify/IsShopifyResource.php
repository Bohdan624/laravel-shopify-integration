<?php

namespace RocketCode\Shopify;

trait IsShopifyResource {
    /**
     * The resource's shopify data object
     *
     * @var stdClass
     */
    protected $shopifyData;

    /**
     * Checks if the last json function returned an error, and if so throw an exception
     *
     * @throws Exception
     */
    protected static function checkJsonError() {
        if (json_last_error()) {
            throw new Exception('Invalid JSON data: ' . json_last_error_msg());
        }
    }

    /**
     * Like json_encode() but throws an exception in case of error
     *
     * @param $data
     * @return string
     */
    protected static function jsonEncode($data) {
        $json = json_encode($data);
        self::checkJsonError();
        return $json;
    }

    /**
     * Like json_decode() but throws an exception in case of error
     *
     * @param $json
     * @return mixed
     */
    protected static function jsonDecode($json) {
        $data = json_decode($json);
        self::checkJsonError();
        return $data;
    }

    /**
     * Set the resource's data object according to the data type passed as $value
     * (and make sure it's valid JSON if it's a string)
     *
     * @param mixed $value
     * @return void
     */
    public function setShopifyData($value) {
        if (is_string($value)) {
            $data = self::jsonDecode($value);
        } else {
            $data = $value;
        }
        if (is_object($data) || is_array($data)) {
            $this->shopifyData = $data;
        } else {
            throw new Exception('The Shopify data received was neither an object nor an array');
        }
    }

    /**
     * Get the resource's data as JSON
     *
     * @return string
     */
    public function getShopifyJson() {
        return self::jsonEncode($this->shopifyData);
    }

    /**
     * Get the Shopify string id of this resource, if it exists
     *
     * @return mixed
     */
    public function getShopifyId() {
        if (isset($this->shopifyData->id)) {
            return (string) $this->shopifyData->id;
        }
    }

    /**
     * This function must return a ShopifyApiUser (i.e. a Shop) that owns this resource
     * This function is used to get the right Shopify API parameters when needed
     * (e.g. when creating or updating the resource)
     *
     * @return ShopifyApiUser
     */
    abstract protected function getShopifyApiUser();

    /**
     * Get the Shopify API object for the shop associated to this resource
     */
    protected function shopifyApi() {
        static $apiUser; /** @var ShopifyApiUser $apiUser */
        if (!isset($apiUser)) {
            $apiUser = $this->getShopifyApiUser();
            if (!($apiUser instanceof ShopifyApiUser)) {
                throw new Exception(__CLASS__ . '::' . __METHOD__ . '() must return type ' . ShopifyApiUser::class);
            }
        }
        return $apiUser->ShopifyAPI();
    }

    /**
     * Calls the Shopify API to create this resource
     */
    protected function createShopifyResource() {
        // TODO: actually do something
    }

    /**
     * Calls the Shopify API to update this resource
     */
    protected function updateShopifyResource() {
        // TODO: actually do something
    }

    /**
     * Save this Shopify resource on the Shopify online database using their API
     */
    public function saveShopifyResource() {
        if (is_null($this->getShopifyId())) { // if there is no id...
            $this->createShopifyResource(); // create a new resource
        } else { // if there is an id...
            $this->updateShopifyResource(); // update the resource
        }
    }
}
<?php

namespace RocketCode\Shopify;

abstract class ShopifyResource implements ShopifyApiUser {
    /**
     * The resource's shopify data object
     *
     * @var stdClass
     */
    protected $shopifyData;

    /**
     * The resource's shopify "API user" (i.e. object that knows the domain and access token)
     *
     * @var ShopifyApiUser
     */
    protected $parent;

    /**
     * ShopifyResource constructor.
     * @param ShopifyApiUser $parent Whatever owns this resource must have access to the API
     */
    public function __construct(ShopifyApiUser $parent) {
        $this->shopifyData = [];
        $this->parent = $parent;
    }

    /**
     * This method must return the singular name for this type of resource
     * E.g. "product", "image"
     * @return string
     */
    abstract public static function getResourceSingularName();

    /**
     * This method must return the plural name for this type of resource
     * E.g. "products", "images"
     * @return string
     */
    abstract public static function getResourcePluralName();

    /**
     * Checks if the last PHP json function call returned an error, and if so throw an exception
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
            $data = (array) $data;
            if (isset($data[static::getResourceSingularName()])) {
                /*
                 * If we received data in the form:
                 * {
                 *   "product": {
                 *     ...
                 *   }
                 * }
                 * we'll extract the resource object (in this example, product)
                 */
                $this->shopifyData = $data[static::getResourceSingularName()];
            } else {
                // otherwise let's assume we got the resource object directly
                $this->shopifyData = $data;
            }
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
     * Return a property by name from this Shopify resource
     *
     * @param string $propertyName The name of the Shopify resource property to return
     * @param mixed $default The default value to return in case the property is missing (default null)
     * @return mixed
     */
    public function getShopifyProperty($propertyName, $default = null) {
        return isset($this->shopifyData[$propertyName]) ? $this->shopifyData[$propertyName] : $default;
    }

    protected function setShopifyProperty($propertyName, $value) {
        $this->shopifyData[$propertyName] = $value;
        // Should we automatically update to Shopify here? Probably not.
    }

    /**
     * Get the Shopify string id of this resource, if it exists
     *
     * @return mixed
     */
    public function getShopifyId() {
        return $this->getShopifyProperty('id');
    }

    public function getGenericPath() {
        return $this->parent->getSpecificPath() . '/' . static::getResourcePluralName();
    }

    public function getSpecificPath() {
        return $this->parent->getSpecificPath() . '/' . static::getResourcePluralName() . '/' . $this->getShopifyId();
    }


    /**
     * This method returns the path to the API URL to handle a single resource
     * E.g. /admin/products/#{id}.json
     *
     * @return string
     */
    public function getApiPathSingleResource() {
        return static::getSpecificPath() . '.json';
    }

    /**
     * This returns the path to the API URL to handle a multiple resource
     * E.g. /admin/products.json
     *
     * @return string
     */
    public function getApiPathMultipleResource() {
        return static::getGenericPath() . '.json';
    }

    /**
     * This method returns the path to the API URL to the resource counter
     * E.g. /admin/products/count.json
     *
     * @return string
     */
    protected function getApiPathCountResource() {
        return static::getGenericPath() . '/count.json';
    }

    /**
     * Get the Shopify API object for the shop associated to this resource
     */
    public function shopifyApi() {
        return $this->parent->ShopifyAPI();
    }

    /**
     * Calls the Shopify API to create this resource
     */
    public function createShopifyResource() {
        $this->shopifyApi()->call([

            'URL' => $this->getApiPathMultipleResource(),
            'METHOD' => 'POST',
            'DATA' => [
                /*
                 * Using static:: instead of self:: because static:: binds at runtime
                 * If we use self this would not work because it would
                 * always call ShopifyResource::getResourceSingularName()
                 */
                static::getResourceSingularName() => $this->shopifyData
            ]
        ]);
    }

    /**
     * Calls the Shopify API to update this resource
     */
    public function updateShopifyResource() {
        $this->shopifyApi()->call([
            'URL' => $this->getApiPathSingleResource(),
            'METHOD' => 'PUT',
            'DATA' => [
                static::getResourceSingularName() => $this->shopifyData
            ]
        ]);
    }

    /**
     * Calls the Shopify API to delete this resource
     */
    public function deleteShopifyResource() {
        $this->shopifyApi()->call([
            'URL' => $this->getApiPathSingleResource(),
            'METHOD' => 'DELETE',
        ]);
    }

    /**
     * Calls updateShopifyResource() if we already have an id, otherwise createShopifyResource()
     */
    public function saveShopifyResource() {
        if (is_null($this->getShopifyId())) { // if there is no id...
            $this->createShopifyResource(); // create a new resource
        } else { // if there is an id...
            $this->updateShopifyResource(); // update the resource
        }
    }
}
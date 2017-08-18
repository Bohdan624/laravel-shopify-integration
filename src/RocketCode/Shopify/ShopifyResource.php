<?php

namespace RocketCode\Shopify;
use \stdClass;
use \Exception;

abstract class ShopifyResource implements ShopifyApiUser {
    const CHILDREN = [];

    /**
     * The resource's shopify data object
     *
     * @var stdClass
     */
    protected $shopifyData;

    /**
     * The resource's parent (it's an "API user" i.e. object that knows the domain and access token)
     *
     * @var ShopifyApiUser
     */
    protected $parent;

    /**
     * The resource's children
     *
     * @var ShopifyApiUser
     */
    protected $children;

    public function addChild(ShopifyResource $child) {
        $this->children[] = $child;
    }

    /**
     * ShopifyResource constructor.
     * @param ShopifyApiUser $parent Whatever owns this resource must have access to the API
     * @param stdClass $shopifyData
     */
    public function __construct(ShopifyApiUser $parent, stdClass $shopifyData) {
        $this->children = [];
        $this->parent = $parent;
        if ($this->parent instanceof self) {
            $this->parent->addChild($this);
        }
        if (!$shopifyData) {
            $shopifyData = new stdClass();
        }
        $this->setShopifyData($shopifyData);
    }

    protected static function getFactory()
    {
        return ShopifyFactory::class;
    }

    /**
     * @param ShopifyApiUser $parent
     * @param $type
     * @param stdClass $data
     * @return ShopifyResource
     */
    protected function newShopifyResource($type, stdClass $data)
    {
        $temp = (static::getFactory())::newShopifyResource($this, $type, $data);
        return $temp;
    }

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
    public static function jsonEncode($data) {
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
    public static function jsonDecode($json) {
        $data = json_decode($json);
        self::checkJsonError();
        return $data;
    }

    protected function populateChildrenGroup($groupName)
    {
        if (isset($this->shopifyData->$groupName) && is_array($this->shopifyData->$groupName)) {
            foreach ($this->shopifyData->$groupName as $childData) {
                $this->newShopifyResource($groupName, $childData);
            }
        }
    }

    protected function populateChildren()
    {
        foreach (static::CHILDREN as $groupName) {
            $this->populateChildrenGroup($groupName);
        }
    }

    /**
     * Set the resource's data object
     *
     * @param stdClass $data
     * @return void
     */
    public function setShopifyData(stdClass $data) {
        if (isset($data->{static::getResourceSingularName()})) {
            /*
             * If we received data in the form:
             * {
             *   "product": {
             *     ...
             *   }
             * }
             * we'll extract the resource object (in this example, product)
             */
            $this->shopifyData = $data->{static::getResourceSingularName()};
        } else {
            // otherwise let's assume we got the resource object directly
            $this->shopifyData = $data;
        }
        $this->populateChildren();
    }

    public function setShopifyDataFromJson($jsonData) {
        $this->setShopifyData(self::jsonDecode($jsonData));
    }

    /**
     * Get the resource's data as JSON
     *
     * @return string
     */
    public function getJsonData() {
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
        return isset($this->shopifyData->{$propertyName}) ? $this->shopifyData->{$propertyName} : $default;
    }

    protected function setShopifyProperty($propertyName, $value) {
        $this->shopifyData->{$propertyName} = $value;
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
        return $this->getSpecificPath() . '.json';
    }

    /**
     * This returns the path to the API URL to handle a multiple resource
     * E.g. /admin/products.json
     *
     * @return string
     */
    public function getApiPathMultipleResource() {
        return $this->getGenericPath() . '.json';
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
    public function getShopifyApi() {
        return $this->parent->getShopifyApi();
    }

    /**
     * Event method that gets called before committing data to Shopify
     */
    protected function saving() {
        // This will get reimplemented by children when necessary
    }

    /**
     * Calls the Shopify API to create this resource
     */
    public function createShopifyResource() {
        $this->saving();
        $this->getShopifyApi()->call([
            'URL' => API::PREFIX . $this->getApiPathMultipleResource(),
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
        $this->saving();
        $this->getShopifyApi()->call([
            'URL' => API::PREFIX . $this->getApiPathSingleResource(),
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
        $this->getShopifyApi()->call([
            'URL' => API::PREFIX . $this->getApiPathSingleResource(),
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

    /**
     * Get an array of the resources of this particular type belonging to $parent
     * @param ShopifyApiUser $parent
     * @return array
     */
    public static function listShopifyResources(ShopifyApiUser $parent) {
        return $parent->getShopifyApi()->call([
            'URL' => API::PREFIX . $parent->getSpecificPath() . '/' . static::getResourcePluralName() . '.json',
            'METHOD' => 'GET',
        ]);
    }
}
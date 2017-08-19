<?php

namespace RocketCode\Shopify;


abstract class ShopifyResourceWithMetafields extends ShopifyResource {
    protected $metafields;

    /**
     * @return ShopifyMetafield[]
     */
    protected function getMetafields() {
        if (!isset($this->metafields)) {
            $this->metafields = [];
            foreach (ShopifyMetafield::newShopifyResourceList($this) as $metafield) {
                $this->metafields[$metafield->getFullKey()] = $metafield;
            }
        }
        return $this->metafields;
    }

    /**
     * @param string $namespace
     * @param string $key
     * @return ShopifyMetafield
     */
    protected function getMetafield($namespace, $key) {
        $full_key = ShopifyMetafield::buildFullKey($namespace, $key);
        if (array_key_exists($full_key, $this->getMetafields())) {
            return $this->getMetafields()[$full_key];
        }
    }

    /**
     * @param string $namespace
     * @param string $key
     * @return mixed
     */
    public function getMetafieldValue($namespace, $key) {
        if ($metafield = $this->getMetafield($namespace, $key)) {
            return $metafield->getValue();
        }
    }

    /**
     * Sets a metafield value (but doesn't save it right away to the Shopify API)
     *
     * @param string $namespace
     * @param string $key
     * @param $value
     */
    public function setMetafieldValue($namespace, $key, $value) {
        $metafield = $this->getMetafield($namespace, $key);
        if ($metafield) { // if we have found that metafield resource
            $metafield->setValue($value);
        } else { // we have not found that metafield resource
            $metafield = new ShopifyMetafield($this, new \stdClass(), $namespace, $key, $value);
            $this->metafields[ShopifyMetafield::buildFullKey($namespace, $key)] = $metafield;
        }
    }

    /**
     * @param string $namespace
     * @param string $key
     */
    public function deleteMetafieldValue($namespace, $key) {
        if ($metafield = $this->getMetafield($namespace, $key)) {
            unset($this->metafields[$metafield->getFullKey()]);
            $metafield->deleteShopifyResource();
        }
    }

    /**
     *  Event method that gets called before committing data to Shopify
     */
    protected function saving()
    {
        /*
         * Compile the "metafields" property within the data object so that all the metafields
         * retrieved so far will get saved together with their parent object (i.e. $this)
         */
        $this->shopifyData->metafields = [];
        foreach ($this->getMetafields() as $metafield) {
            $this->shopifyData->metafields[] = $metafield->getShopifyData();
        }
    }
}
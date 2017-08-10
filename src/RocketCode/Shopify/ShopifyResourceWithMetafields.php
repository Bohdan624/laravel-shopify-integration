<?php

namespace RocketCode\Shopify;


abstract class ShopifyResourceWithMetafields extends ShopifyResource {
    protected $metafields;

    /**
     * @return array
     */
    protected function getMetafields() {
        if (!isset($this->metafields)) {
            $this->metafields = [];
            foreach (ShopifyMetafield::listShopifyResources($this)->metafields as $metafield_data) {
                $metafield = new ShopifyMetafield($this);
                $metafield->setShopifyData($metafield_data);
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
     * @param string $namespace
     * @param string $key
     * @param $value
     */
    public function setMetafieldValue($namespace, $key, $value) {
        $metafield = $this->getMetafield($namespace, $key);
        if ($metafield) { // if we have found that metafield resource
            $metafield->setValue($value);
        } else { // we have not found that metafield resource
            $metafield = new ShopifyMetafield($this, $namespace, $key, $value);
            $this->metafields[ShopifyMetafield::buildFullKey($namespace, $key)] = $metafield;
        }
        $metafield->saveShopifyResource(); // save right away to Shopify API
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
}
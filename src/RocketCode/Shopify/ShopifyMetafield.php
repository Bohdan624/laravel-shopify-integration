<?php

namespace RocketCode\Shopify;

use \stdClass;

class ShopifyMetafield extends ShopifyResource {
    use HasNames;

    const SINGULAR_NAME = 'metafield';
    const PLURAL_NAME = 'metafields';

    public function __construct(ShopifyApiUser $parent, $shopifyData, $namespace = null, $key = null, $value = '')
    {
        parent::__construct($parent, $shopifyData);
        if ($namespace && $key) {
            $this->setShopifyProperty('namespace', $namespace);
            $this->setShopifyProperty('key', $key);
            $this->setValue($value);
        }
    }

    public function getNamespace() {
        return $this->getShopifyProperty('namespace');
    }

    public function getKey() {
        return $this->getShopifyProperty('key');
    }

    public static function buildFullKey($namespace, $key) {
        return $namespace . '.' . $key;
    }

    public function getFullKey() {
        return self::buildFullKey($this->getNamespace(), $this->getKey());
    }

    public function getValueType() {
        return $this->getShopifyProperty('value_type');
    }

    public function getValue() {
        return $this->getShopifyProperty('value');
    }

    public function setValue($value) {
        if (is_integer(($value))) {
            $this->setShopifyProperty('value_type', 'integer');
        } else {
            $this->setShopifyProperty('value_type', 'string');
        }
        return $this->setShopifyProperty('value', $value);
    }

    /***
     * @return stdClass
     */
    public function getShopifyData() {
        return $this->shopifyData;
    }
}
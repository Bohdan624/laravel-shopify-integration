<?php

namespace RocketCode\Shopify;


class ShopifyMetafield extends ShopifyResource {
    public function __construct(ShopifyApiUser $parent, $namespace = null, $key = null, $value = '')
    {
        parent::__construct($parent);
        if ($namespace && $key) {
            $this->setShopifyProperty('namespace', $namespace);
            $this->setShopifyProperty('key', $key);
            $this->setValue($value);
        }
    }

    public static function getResourceSingularName()
    {
        return 'metafield';
    }

    public static function getResourcePluralName()
    {
        return 'metafields';
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
}
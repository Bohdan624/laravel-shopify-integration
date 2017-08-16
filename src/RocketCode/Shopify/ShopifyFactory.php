<?php

namespace RocketCode\Shopify;

use Exception;
use stdClass;

class ShopifyFactory
{
    const PRODUCT = ShopifyProduct::class;
    const VARIANT = ShopifyVariant::class;
    const IMAGE = ShopifyImage::class;

    /**
     * @param string $name
     * @return string
     * @throws Exception
     */
    protected static function getShopifyResourceClass($name)
    {
        switch (strtolower($name)) {
            case ShopifyProduct::SINGULAR_NAME:
            case ShopifyProduct::PLURAL_NAME:
                return static::PRODUCT;
            case ShopifyVariant::SINGULAR_NAME:
            case ShopifyVariant::PLURAL_NAME:
                return static::VARIANT;
            case ShopifyImage::SINGULAR_NAME:
            case ShopifyImage::PLURAL_NAME:
                return static::IMAGE;
            default:
                throw new Exception('Unknown shopify resource type: "' . $name . '"');
        }
    }

    /**
     * @param ShopifyApiUser $parent
     * @param $type
     * @param stdClass $data
     * @return ShopifyResource
     */
    public static function newShopifyResource(ShopifyApiUser $parent, $type, stdClass $data)
    {
        $class = self::getShopifyResourceClass($type);
        return new $class($parent, $data);
    }
}
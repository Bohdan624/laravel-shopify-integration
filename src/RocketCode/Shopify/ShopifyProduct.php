<?php

namespace RocketCode\Shopify;

class ShopifyProduct extends ShopifyResource {

    public static function getResourceSingularName()
    {
        return 'product';
    }

    public static function getResourcePluralName()
    {
        return 'products';
    }
}
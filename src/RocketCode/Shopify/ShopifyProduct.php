<?php

namespace RocketCode\Shopify;

class ShopifyProduct extends ShopifyResourceWithMetafields {

    public static function getResourceSingularName()
    {
        return 'product';
    }

    public static function getResourcePluralName()
    {
        return 'products';
    }
}
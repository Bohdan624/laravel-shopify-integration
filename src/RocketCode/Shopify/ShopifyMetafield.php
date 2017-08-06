<?php

namespace RocketCode\Shopify;


class ShopifyMetafield extends ShopifyResource {
    protected static function getResourceSingularName()
    {
        return 'metafield';
    }

    protected static function getResourcePluralName()
    {
        return 'metafields';
    }
}
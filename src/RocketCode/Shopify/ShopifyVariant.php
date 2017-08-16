<?php

namespace RocketCode\Shopify;

class ShopifyVariant extends ShopifyResourceWithMetafields
{
    use HasNames;

    const SINGULAR_NAME = 'variant';
    const PLURAL_NAME = 'variants';
}
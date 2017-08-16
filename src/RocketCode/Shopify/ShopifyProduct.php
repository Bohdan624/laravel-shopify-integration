<?php

namespace RocketCode\Shopify;

class ShopifyProduct extends ShopifyResourceWithMetafields
{
    use HasNames;

    const SINGULAR_NAME = 'product';
    const PLURAL_NAME = 'products';

    const CHILDREN = ['variants', 'images'];
}
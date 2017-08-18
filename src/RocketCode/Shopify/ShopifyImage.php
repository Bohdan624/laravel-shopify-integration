<?php

namespace RocketCode\Shopify;

class ShopifyImage extends ShopifyResourceWithMetafields
{
    use HasNames;

    const SINGULAR_NAME = 'image';
    const PLURAL_NAME = 'images';

    public function getSrc() {
        return $this->getShopifyProperty('src');
    }
}
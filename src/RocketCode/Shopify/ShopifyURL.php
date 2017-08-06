<?php

namespace RocketCode\Shopify;

/**
 * Interface ShopifyURL represents an entity that has URLs
 * Its methods are called when something needs to construct an URL for the API
 */
interface ShopifyURL extends ShopifyNamed {
    /**
     * This method must return the path to access a "generic something"
     * (as opposed to something specific i.e. with an id)
     * E.g. for products: /admin/products; for variants: /admin/products/#{id}/variants
     * @return string
     */
    public function getGenericPath();

    /**
     * This method must return the path to access a "specific something (i.e. something with an id)
     * E.g. for products: /admin/products/#id; for variants: /admin/products/#{id}/variants/#id
     * @return string
     */
    public function getSpecificPath();
}
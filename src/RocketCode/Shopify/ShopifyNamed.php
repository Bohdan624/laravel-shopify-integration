<?php

namespace RocketCode\Shopify;

/**
 * Interface ShopifyNamed represents a "named" entity on Shopify
 * Its methods are called when something needs to inquire about its name
 * E.g. to construct correct URLs for the API
 */
interface ShopifyNamed {
    /**
     * This method must return the singular name for this type of resource
     * E.g. "product", "image"
     * @return string
     */
    public static function getResourceSingularName();

    /**
     * This method must return the plural name for this type of resource
     * E.g. "products", "images"
     * @return string
     */
    public static function getResourcePluralName();
}
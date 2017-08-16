<?php

namespace RocketCode\Shopify;

trait HasNames
{
    public static function getResourceSingularName()
    {
        return self::SINGULAR_NAME;
    }

    public static function getResourcePluralName()
    {
        return self::PLURAL_NAME;
    }
}
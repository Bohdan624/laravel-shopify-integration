<?php

namespace RocketCode\Shopify;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $table = 'shopify_shops';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name', 'shopify_id', 'myshopify_domain', 'shopify_token', 'shopify_webhook_signature'
    ];
}

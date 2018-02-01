<?php

namespace RocketCode\Shopify;

use Illuminate\Database\Eloquent\Model;

class ShopQueueLog extends Model
{
    protected $table = 'shop_queue_logs';
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'shop_id', 'since_id', 'controller', 'function', 'created_at', 'updated_at', 'processed', 'counter', 'message', 'expires_at'];

    public function shop()
    {
        return $this->belongsToOne('App\Shop')->withTimestamps();
    }
}

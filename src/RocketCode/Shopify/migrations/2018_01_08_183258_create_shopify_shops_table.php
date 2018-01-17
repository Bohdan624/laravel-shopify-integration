<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateShopifyShopsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('shopify_shops', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 255);
            $table->bigInteger('shopify_id');
            $table->string('myshopify_domain', 255);            
            $table->timestamps();
            $table->string('shopify_token', 100);
            $table->string('shopify_webhook_signature', 100);        
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shopify_shops');
    }
}

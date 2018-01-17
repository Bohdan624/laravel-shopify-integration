<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateShopQueueLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('shop_queue_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('shop_id')->unsigned()->nullable();
            $table->bigInteger('since_id');
            $table->integer('type');
            $table->timestamps();
            $table->tinyInteger('processed');
            $table->integer('counter');
            $table->foreign('shop_id')->references('id')->on('shopify_shops');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shop_queue_logs');
    }
}

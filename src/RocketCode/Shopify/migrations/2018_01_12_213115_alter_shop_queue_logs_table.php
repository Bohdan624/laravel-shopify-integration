<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterShopQueueLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shop_queue_logs', function ($table) {
            $table->dropColumn('type');
            $table->string('controller', 255);
            $table->string('function', 255);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shop_queue_logs', function ($table) {
            $table->int('type');
            $table->dropColumn('controller');
            $table->dropColumn('function');
        });
    }
}

<?php

namespace RocketCode\Shopify;

use Illuminate\Support\Facades\App;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Log;
use App\Shop;
use App\ShopQueueLog;
use Mail;
use RocketCode\Shopify\SystemNotice;

/**
 * The class iterates through all the shops
 * and runs the specified function from the specified controller for each of the resource items
 * unless it reached $max_records (which is also specified)
 */

class ShopifyQueueController extends ShopifyController
{
    private $controller = null;
    private $controller_function = null;
    /**
     * The controller->function are run on each result record from the API.
     * @controller object
     * @controller_function string
     */
    public function start($controller, $controller_function, $max_records)
    {
        $this->controller = $controller;
        $this->controller_function = $controller_function;
        $resource = $controller->resource;
        Log::info('Shopify Queue Log Started: ' . get_class($this->controller) . ' : ' . $controller_function);

        $shops = Shop::all();

        foreach ($shops as $shop) {
            $this->shopSwitch($shop->myshopify_domain);
            echo $shop->myshopify_domain . '<br>';

            /**
             * Shop Queue Log
             * - If a record with the same controller, function, shop_id, and processed = true is
             * found, skip this shop as its already been processed completely.
             */
            $where = array();
            $where[] = array('shop_id', '=', $shop->id);
            $where[] = array('controller', '=', get_class($this->controller));
            $where[] = array('function', '=', $controller_function);
            $where[] = array('processed', '=', 1);
            $shopQueueLog = ShopQueueLog::where($where)->first();

            $shopQueueLog = $this->validateQueue($shopQueueLog, $shop);

            /**
             * If $shopQueueLog was found its passed to validateQueue to send out email notices
             * and then continues to the next record. If since_id is greater than 0 set current value
             * of since_id found in the shopQueueLog to be able to track the starting record to get from the API.
             */
            if ($shopQueueLog == 'continue') {
                continue;
            } elseif ($shopQueueLog->since_id > 0) {
                $since_id = $shopQueueLog->since_id;
            }

            $time_start = microtime(true);
            $force_stop = false; // forces the while to stop when true
            $counter = 0;

            while ($force_stop <> true && $counter <= $max_records) {
                if (isset($shopQueueLog->since_id)) {
                    $shop->fresh(); // resets the object after each iteration to ensure its "fresh"
                    $since_id = $shopQueueLog->since_id;
                    $this->sh->addData('since_id', $since_id);
                } else {
                    $this->sh->addData('order', 'created_at%20asc');
                }

                $api_results = $controller->sh->listShopifyResources();

                if (sizeof($api_results->$resource) == 0) {
                    $force_stop = true;
                    continue;
                }

                foreach ($api_results->$resource as $result) {
                    $this->controller->$controller_function($result);
                    $since_id = $result->id;

                    if ($counter >= $max_records) {
                        $force_stop = true;
                        break;
                    }

                    $shopQueueLog->since_id = (string) $since_id;

                    $shopQueueLog->increment('counter', 1);
                    $counter++;
                    $shopQueueLog->save();
                }
            }
            $time_end = microtime(true);
            $time = $time_end - $time_start;

            // If $force_stop is true we mark the queue as processed and save to the database.
            if ($force_stop) {
                $shopQueueLog->processed = 1;
                $shopQueueLog->save();
                $this->validateQueue($shopQueueLog, $shop, true);
            }
        }
        Log::info('Shopify Queue Log Stopped: ' . get_class($this->controller) . ' : ' . $controller_function);
    }

    public function validateQueue($shopQueueLog, $shop, $done = false)
    {
        if ($shopQueueLog) {
            $mailto = 'it@cottonbabies.com';

            $message = new \stdClass();
            $message->created_at = $shopQueueLog->created_at;
            $message->updated_at = $shopQueueLog->updated_at;
            $message->processed = $shopQueueLog->processed;
            $message->shop = $shop;
            
            if ($done == true) {
                $message->done = true;
                Mail::to($mailto)->send(new SystemNotice($message));
            }
            return 'continue';
        } else {
            $mailto = 'it@cottonbabies.com';

            $shopQueueLogData = array(
                'shop_id' => $shop->id,
                'controller' => get_class($this->controller),
                'function' => $this->controller_function
            );

            $shopQueueLog = ShopQueueLog::updateOrCreate($shopQueueLogData);

            $message = new \stdClass();
            $message->created_at = $shopQueueLog->created_at;
            $message->updated_at = $shopQueueLog->updated_at;
            $message->processed = $shopQueueLog->processed;
            $message->shop = $shop;

            if ($shopQueueLog->wasRecentlyCreated) {
                Mail::to($mailto)->send(new SystemNotice($message));
            }

            return $shopQueueLog;
        }
    }
}

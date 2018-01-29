<?php

namespace RocketCode\Shopify;

use Illuminate\Support\Facades\App;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Log;
use RocketCode\Shopify\Shop;
use RocketCode\Shopify\ShopQueueLog;
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

        $shopifyData = $controller->sh->getShopifyData();
        $resource = $shopifyData['resource'];

        // check if the resource is a smart/custom collection
        $collection_resource = (strpos($resource, 'collection') !== false);

        Log::info('Shopify Queue Log Started: ' . get_class($this->controller) . ' : ' . $controller_function);

        $shops = Shop::all();

        foreach ($shops as $shop) {
            $controller->shopSwitch($shop->myshopify_domain);
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
            } elseif ($shopQueueLog->page > 0) {
                $page = $shopQueueLog->page;
            }

            $force_stop = false; // forces the while to stop when true
            $page_counter = 1;
            $record_counter = 0;

            // if resource is any collection we count by page
            if ($collection_resource) {
                $counter_variable = $page_counter;
                $counter_type = 'page';
            } else {
                $counter_variable = $record_counter;
                $counter_type = 'since_id';
            }

            while ($force_stop <> true && $counter_variable <= $max_records) {
                // setting the original callData/Data to this->sh because they may get reset
                $controller->sh->setShopifyData($shopifyData);
                // when running after first time
                if (isset($shopQueueLog->since_id) || isset($shopQueueLog->page)) {
                    $shop->fresh(); // resets the object after each iteration to ensure its "fresh"
                    // setting counter_type and counter_value
                    if ($collection_resource) {
                        $page = $shopQueueLog->page;
                        $counter_value = $page;
                    } else {
                        $since_id = $shopQueueLog->since_id;
                        $counter_value = $since_id;
                    }
                    $controller->sh->addData($counter_type, $counter_value);
                }
                // if running first time - when there's no record in database
                else {
                    // if smart or custom_collection
                    if ($collection_resource) {
                        // start at the first page
                        $controller->sh->addData($counter_type, $page_counter);
                    } else {
                        $controller->sh->addData($counter_type, 0);
                    }
                }

                $api_results = $controller->sh->listShopifyResources(false);

                if (sizeof($api_results->$resource) == 0) {
                    $force_stop = true;
                    $processed = true;
                    break;
                }

                foreach ($api_results->$resource as $result) {
                    $this->controller->$controller_function($resource, $result);
                    $since_id = $result->id;

                    // if not smart or custom_collection e.g. products
                    if (!$collection_resource) {
                        if ($record_counter >= $max_records) {
                            $force_stop = true;
                            $processed = false;
                            continue;
                        }
                        $shopQueueLog->since_id = (string) $since_id;
                    }

                    $shopQueueLog->increment('counter', 1);
                    $record_counter++;
                    $shopQueueLog->save();
                    // save the record_counter value to the counter_variable value so it doesn't reset to 0
                    if (!$collection_resource) {
                        $counter_variable = $record_counter;
                    }
                } // foreach end
                if ($collection_resource) {
                    // increment page and save it to the database
                    $page_counter++;
                    if (isset($shopQueueLog->page)) {
                        $shopQueueLog->increment('page', 1);
                    } else {
                        // when running first time, increment by 2 so it starts from page 2 next time
                        $shopQueueLog->increment('page', $page_counter);
                    }
                    $shopQueueLog->save();
                    // save the page_counter value to the counter_variable value so it doesn't reset to 1
                    $counter_variable = $page_counter;
                }
            } // while end

            // If $force_stop is true we mark the queue as processed and save to the database.
            if ($force_stop) {
                if ($processed) {
                    $shopQueueLog->processed = 1;
                }
                $shopQueueLog->save();
                $this->validateQueue($shopQueueLog, $shop, true);
            }
        }
        Log::info('Shopify Queue Log Stopped: ' . get_class($this->controller) . ' : ' . $controller_function);
    }

    public function validateQueue($shopQueueLog, $shop, $done = false)
    {
        if ($shopQueueLog) {
            $mailto = env('SHOPIFY_EMAIL_NOTICE');

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
            $mailto = env('SHOPIFY_EMAIL_NOTICE');

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

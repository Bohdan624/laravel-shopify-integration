<?php namespace RocketCode\Shopify;

/**
 * Class API
 * @package RocketCode\Shopify
 */

use Illuminate\Http\File;
use Storage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Mail;
use RocketCode\Shopify\ShopifyWebhookNotice;
use RocketCode\Shopify\ExceptionNotice;

class API
{
    private $_API = array();
    private static $_KEYS = array('API_KEY', 'API_SECRET', 'ACCESS_TOKEN', 'SHOP_DOMAIN');
        
    private $shopifyData = array();
    private $childData = array();
    
    private $last_response_headers = array();

    // setting default directories
    private $webhooks_dir = 'shopify_webhooks/';
    private $error_log_dir = 'shopify_webhooks/logs';
    private $archive_dir = 'shopify_webhooks/logs/archives';

    // amount of days for the archive files to expire
    private $expired_days = 30;
    // Carbon class object
    private $carbon;
    // unique name for the webhook/log files
    private $unique_name = '';

    const PREFIX = '/admin';

    /**
     * Checks for presence of setup $data array and loads
     * @param bool $data
     */
    public function __construct($data = false)
    {
        if (is_array($data)) {
            $this->setup($data);
        }
        $this->carbon = new Carbon;
        // e.g. 2018_01_15_12_32_58_12345678
        $this->unique_name = date('Y_m_d_h_i_s', time()) . '_' . rand(0, 100000000);
    }

    /**
     * Verifies data returned by OAuth call
     * https://help.shopify.com/api/getting-started/authentication/oauth#confirming-installation
     *
     * @param array|string $data
     * @return bool
     * @throws \Exception
     */
    public function verifyRequest($data = null, $bypassTimeCheck = false)
    {
        $da = array();
        if (is_string($data)) {
            $each = explode('&', $data);
            foreach ($each as $e) {
                list($key, $val) = explode('=', $e);
                $da[$key] = $val;
            }
        } elseif (is_array($data)) {
            $da = $data;
        } else {
            throw new \Exception('Data passed to verifyRequest() needs to be an array or URL-encoded string of key/value pairs.');
        }

        // Timestamp check; 1 hour tolerance
        if (!$bypassTimeCheck) {
            if (($da['timestamp'] - time() > 3600)) {
                throw new \Exception('Timestamp is greater than 1 hour old. To bypass this check, pass TRUE as the second argument to verifyRequest().');
            }
        }

        //Ensure the provided nonce is the same one that your application provided to Shopify during the Step 2: Asking for permission.
        if (!\Cache::has($cache_key = $da['shop'].'_oauth_state') || \Cache::get($cache_key) != $da['state']) {
            throw new \Exception('Invalid nonce.');
        }

        //Ensure the provided hostname parameter is a valid hostname, ends with myshopify.com, and does not contain characters other than letters (a-z), numbers (0-9), dots, and hyphens.
        if (array_key_exists('shop', $da)) {
            $validHostnameRegex = '/^(([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])\.)*([A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9\-]*[A-Za-z0-9])$/';
            if (! preg_match($validHostnameRegex, $da['shop'])) {
                throw new \Exception('Shop parameter is not a valid hostname');
            }

            if (substr($da['shop'], -13) != 'myshopify.com') {
                throw new \Exception('Shop parameter does not end in myshopify.com');
            }

            $validShopifyNames = '/^[a-zA-Z0-9.-]+$/';
            if (! preg_match($validShopifyNames, $da['shop'])) {
                throw new \Exception('Shop parameter is not a valid shopify shop name.');
            }
        } else {
            throw new \Exception('Shop parameter missing');
        }

        //Ensure the provided hmac is valid. The hmac is signed by Shopify as explained below, in the Verification section.
        if (array_key_exists('hmac', $da)) {
            // HMAC Validation
            $queryString = http_build_query(array('code' => $da['code'], 'shop' => $da['shop'], 'state' => $da['state'], 'timestamp' => $da['timestamp']));
            $match = $da['hmac'];
            $calculated = hash_hmac('sha256', $queryString, $this->_API['API_SECRET']);
        } else {
            // MD5 Validation, to be removed June 1st, 2015
            $queryString = http_build_query(array('code' => $da['code'], 'shop' => $da['shop'], 'timestamp' => $da['timestamp']), null, '');
            $match = $da['signature'];
            $calculated = md5($this->_API['API_SECRET'] . $queryString);
        }

        return $calculated === $match;
    }

    /**
     * Calls API and returns OAuth Access Token, which will be needed for all future requests
     * @param string $code
     * @return mixed
     * @throws \Exception
     */
    public function getAccessToken($code = '')
    {
        $dta = array('client_id' => $this->_API['API_KEY'], 'client_secret' => $this->_API['API_SECRET'], 'code' => $code);

        $data = $this->call(
            [
                'METHOD' => 'POST',
                'URL' => 'https://' . $this->_API['SHOP_DOMAIN'] . self::PREFIX . '/oauth/access_token',
                'DATA' => $dta
            ],
            false
        );

        return $data->access_token;
    }

    /**
     * Returns a string of the install URL for the app
     * and optionally stores in the cache some extra data about this store
     * that will expire together with the nonce
     * @param array $data
     * @param mixed $extraData
     * @return string
     */
    public function installURL($data = array(), $extraData = null)
    {
        // https://{shop}.myshopify.com/admin/oauth/authorize?client_id={api_key}&scope={scopes}&redirect_uri={redirect_uri}
        $state = str_random(32);
        \Cache::put($this->_API['SHOP_DOMAIN'] . '_oauth_state', $state, 60);
        if (!is_null($extraData)) {
            \Cache::put($this->_API['SHOP_DOMAIN'] . '_extra_data', $extraData, 60);
        }
        return 'https://' . $this->_API['SHOP_DOMAIN'] . self::PREFIX . '/oauth/authorize?client_id=' . $this->_API['API_KEY'] . '&state=' . urlencode($state) . '&scope=' . urlencode(implode(',', $data['permissions'])) . (!empty($data['redirect']) ? '&redirect_uri=' . urlencode($data['redirect']) : '') . (!empty($data['grant_options']) ? '&grant_options[]=' . urlencode($data['grant_options']) : '');
    }

    /**
     * Returns the optional extra data stored by installURL()
     * @return mixed
     */
    public function getExtraData()
    {
        return \Cache::get($this->_API['SHOP_DOMAIN'] . '_extra_data');
    }

    /**
     * Loops over each of self::$_KEYS, filters provided data, and loads into $this->_API
     * @param array $data
     */
    public function setup($data = array())
    {
        foreach (self::$_KEYS as $k) {
            if (array_key_exists($k, $data)) {
                $this->_API[$k] = self::verifySetup($k, $data[$k]);
            }
        }
    }

    /**
     * Checks that data provided is in proper format
     * @example Removes http(s):// from SHOP_DOMAIN
     * @param string $key
     * @param string $value
     * @return string
     */
    private static function verifySetup($key = '', $value = '')
    {
        $value = trim($value);

        switch ($key) {
            case 'SHOP_DOMAIN':
                preg_match('/(https?:\/\/)?(([a-zA-Z0-9\-\.])+)/', $value, $matched);
                return $matched[2];
                break;

            default:
                return $value;
        }
    }

    /**
     * Checks that data provided is in proper format
     * @example Checks for presence of /admin/ in URL
     * @param array $userData
     * @return array
     */
    private function setupUserData($userData = array())
    {
        $returnable = array();

        foreach ($userData as $key => $value) {
            switch ($key) {
                case 'URL':
                    // Remove shop domain
                    $url = str_replace($this->_API['SHOP_DOMAIN'], '', $value);

                    // Verify it contains /admin/
                    if (strpos($url, '/admin/') !== 0) {
                        $url = str_replace('//', '/', '/admin/' . preg_replace('/\/?admin\/?/', '', $url));
                    }
                    $returnable[$key] = $url;
                    break;

                default:
                    $returnable[$key] = $value;

            }
        }

        return $returnable;
    }


    /**
     * Executes the actual cURL call based on $userData
     * @param array $userData
     * @return mixed
     * @throws \Exception
     */

    public function call($userData = array(), $verifyData = true)
    {
        if ($verifyData) {
            foreach (self::$_KEYS as $k) {
                if ((!array_key_exists($k, $this->_API)) || (empty($this->_API[$k]))) {
                    throw new \Exception($k . ' must be set.');
                }
            }
        }

        $defaults = array(
            'CHARSET'       => 'UTF-8',
            'METHOD'        => 'GET',
            'URL'           => '/',
            'HEADERS'       => array(),
            'DATA'          => array(),
            'FAILONERROR'   => true,
            'RETURNARRAY'   => false,
            'ALLDATA'       => true
        );
            
        // unset the DATA array from $userData if the METHOD is get
        if (isset($userData['DATA']) && $userData['METHOD'] == 'GET') {
            unset($userData['DATA']);
        }

        if ($verifyData) {
            $request = $this->setupUserData(array_merge($defaults, $userData));
        } else {
            $request = array_merge($defaults, $userData);
        }


        // Send & accept JSON data
        $defaultHeaders = array();
        $defaultHeaders[] = 'Content-Type: application/json; charset=' . $request['CHARSET'];
        $defaultHeaders[] = 'Accept: application/json';
        if (array_key_exists('ACCESS_TOKEN', $this->_API)) {
            $defaultHeaders[] = 'X-Shopify-Access-Token: ' . $this->_API['ACCESS_TOKEN'];
        }

        $headers = array_merge($defaultHeaders, $request['HEADERS']);


        if ($verifyData) {
            $url = 'https://' . $this->_API['API_KEY'] . ':' . $this->_API['ACCESS_TOKEN'] . '@' . $this->_API['SHOP_DOMAIN'] . $request['URL'];
        } else {
            $url = $request['URL'];
        }

        // cURL setup
        $ch = curl_init();
        $options = array(
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_URL             => $url,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_CUSTOMREQUEST   => strtoupper($request['METHOD']),
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => 'RocketCode Shopify API Wrapper',
            CURLOPT_FAILONERROR     => $request['FAILONERROR'],
            CURLOPT_VERBOSE         => $request['ALLDATA'],
            CURLOPT_HEADER          => 1
        );
        
        // Checks if DATA is being sent
        if (!empty($request['DATA'])) {
            if (is_array($request['DATA'])) {
                $options[CURLOPT_POSTFIELDS] = json_encode($request['DATA']);
            } else {
                // Detect if already a JSON object
                json_decode($request['DATA']);
                if (json_last_error() == JSON_ERROR_NONE) {
                    $options[CURLOPT_POSTFIELDS] = $request['DATA'];
                } else {
                    throw new \Exception('DATA malformed.');
                }
            }
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        // Data returned
        $result = json_decode(substr($response, $headerSize), $request['RETURNARRAY']);


        // Headers
        $info = array_filter(array_map('trim', explode("\n", substr($response, 0, $headerSize))));

        foreach ($info as $k => $header) {
            if (strpos($header, 'HTTP/') > -1) {
                $_INFO['HTTP_CODE'] = $header;
                continue;
            }

            list($key, $val) = explode(':', $header);
            $_INFO[trim($key)] = trim($val);
        }


        $message = [
            'number' => curl_errno($ch),
            'message' => curl_error($ch),
            'data' => print_r($request, true),
        ];
        // cURL Errors
        $_ERROR = array('NUMBER' => curl_errno($ch), 'MESSAGE' => $message);

        curl_close($ch);

        if ($_ERROR['NUMBER']) {
            // throw new \Exception('ERROR #' . $_ERROR['NUMBER'] . ': ' . $_ERROR['MESSAGE']);
            $this->exceptionNotice($_ERROR['MESSAGE']);
        }


        // Send back in format that user requested
        if ($request['ALLDATA']) {
            if ($request['RETURNARRAY']) {
                $result['_ERROR'] = $_ERROR;
                $result['_INFO'] = $_INFO;
            } else {
                if (!is_object($result)) {
                    $result = new \stdClass();
                }
                $result->_ERROR = $_ERROR;
                $result->_INFO = $_INFO;
                $this->last_response_headers = $_INFO;
                $this->throttleCalls(10);
            }
            return $result;
        } else {
            return $result;
        }
    }

    /**
     * Sends out an email with the Exception Notice
     * @param String $exception
     */
    public function exceptionNotice($exception)
    {
        $mailto = env('SHOPIFY_EMAIL_NOTICE');
        
        Mail::to($mailto)->send(new ExceptionNotice($exception));
    }

    /**
     * Sends out an email with the Webhook Notice
     * @param String $message
     */
    public function webhookNotice($message)
    {
        $mailto = env('SHOPIFY_EMAIL_NOTICE');
        $message = new \stdClass;
        $message->error = $message;
        Mail::to($mailto)->send(new ShopifyWebhookNotice($message));
    }

    /**
     * Creates a webhook with the given topic and address
     * @param String $topic - the topic that is passed e.g. products/create
     * @param String $address - a url to be called when the webhook triggers
     */
    public function createWebhook($topic, $address)
    {
        $address = secure_url($address);
        // check if the webhook exists
        $webhooks = $this->getWebhooks([
            'topic' => $topic,
            'address' => $address,
        ]);

        // create if it doesn't exist
        if (empty($webhooks) && isset($webhooks)) {
            $this->call([
                'URL' => self::PREFIX . '/webhooks.json',
                'METHOD' => 'POST',
                'DATA' => [
                    'webhook' => [
                        'topic' => $topic,
                        'address' => $address,
                        'format' => 'json',
                    ]
                ]
            ]);
        }
    }

    /**
     * Checks and updates an existing webhook
     * @param int $id - id of the webhook
     * @param string $address - url to update to
     */
    public function updateWebhook($id, $address)
    {
        $address = secure_url($address);
        // get all the webhooks
        $webhooks = $this->getWebhooks();
        // check if the webhooks array has an item with the same id.
        if (in_array(intval($id), array_column($webhooks, 'id'))) {
            // update the webhook
            $this->call([
                'URL' => self::PREFIX . '/webhooks/' . intval($id) . '.json',
                'METHOD' => 'PUT',
                'DATA' => [
                    'webhook' => [
                        'id' => intval($id),
                        'address' => $address,
                    ]
                ]
            ]);
        }
    }

    /**
     * Checks and deletes an existing webhook
     * @param int $id - the id of a webhook
     */
    public function deleteWebhook($id)
    {
        // get all the webhooks
        $webhooks = $this->getWebhooks();
        // check if the webhooks array has an item with the same id.
        if (in_array(intval($id), array_column($webhooks, 'id'))) {
            // delete if webhook exists
            $this->call([
                'URL' => self::PREFIX . '/webhooks/' . intval($id) . '.json',
                'METHOD' => 'DELETE',
            ]);
        }
    }

    /**
     * Make sure the passed webhooks (and only those) are up
     * @param array $targetWebhooks Array of webhooks in the form [topic => url, ...]
     *
     */
    public function setupWebhooks(array $targetWebhooks)
    {
        $webhooks = $this->getWebhooks();
        foreach ($webhooks as $webhook) {
            if (!array_key_exists($webhook->topic, $targetWebhooks)) {
                $this->deleteWebhook($webhook->id);
            } else {
                if ($webhook->address != $targetWebhooks[$webhook->topic]) {
                    $this->updateWebhook($webhook->id, $targetWebhooks[$webhook->topic]);
                }
                unset($targetWebhooks[$webhook->topic]); // let's remove from $target this webhook that we already processed
            }
        }
        foreach ($targetWebhooks as $topic => $address) {
            $this->createWebhook($topic, $address);
        }
    }

    /**
     * Gets all the webhooks for the current $this->_API['SHOP_DOMAIN']
     * @param Array $data_properties - the properties to be added to the 'DATA' array e.g. ['address' => 'http://address']
     * optional $data_properties should be structured like so: e.g. ['topic' => 'products/create']
     */
    public function getWebhooks($data_properties = array())
    {
        $callData = [
            'URL' => self::PREFIX . '/webhooks.json',
            'METHOD' => 'GET',
        ];
        if (!empty($data_properties)) {
            // initialising the DATA array to prevent error
            $callData['DATA'] = [];
            // adding each property to the URL
            foreach ($data_properties as $property => $value) {
                // if first property, add ?    e.g. https://domain.com?first_property=test
                if (strpos($callData['URL'], "?") === false) {
                    $callData['URL'] .= '?' . $property . '=' . $value;
                } else {
                    // if not first property add &    e.g. https://domain.com?first_property=test&second_property=test2
                    $callData['URL'] .= '&' . $property . '=' . $value;
                }
            }
        }
        $call = $this->call($callData);
        return $call->webhooks;
    }
    
    public function callsMade()
    {
        return $this->shopApiCallLimitParam(0);
    }

    public function callLimit()
    {
        return $this->shopApiCallLimitParam(1);
    }

    /**
     * if API call limit is reached sleep. see: https://help.shopify.com/api/getting-started/api-call-limit
     * @param type $time
     */
    public function throttleCalls($time)
    {
        if ($this->callsLeft() <= 10) {
            // echo 'Sleep!' . $this->callsLeft() . '<br>';
            sleep($time);
        }
    }

    public function callsLeft()
    {
        return $this->callLimit() - $this->callsMade();
        // return 20;
    }

    public function shopApiCallLimitParam($index)
    {
        if (array_key_exists('HTTP_X_SHOPIFY_SHOP_API_CALL_LIMIT', $this->last_response_headers)) {
            $params = explode('/', $this->last_response_headers['HTTP_X_SHOPIFY_SHOP_API_CALL_LIMIT']);
        }

        if (!isset($params)) {
            return 12;
        }

        return (int) $params[$index];
    }
    
    /**
     * Returns a list of the given resource
     * @param boolean $resetData -  whether to reset the data or not
     */
    public function listShopifyResources($resetData = true)
    {
        try {
            // Checks if the DATA array is set, if it isn't, do not pass it when calling
            if (isset($this->shopifyData['DATA'])) {
                $result = $this->call($this->shopifyData, $this->shopifyData['DATA']);
            } else {
                $result = $this->call($this->shopifyData);
            }
            
            if ($resetData) {
                $this->resetData();
            }
            
            return $result;
        } catch (Exception $e) {
        }
    }

    /**
     * Returns the url filters in an array e.g. ['limit' => 250, 'fields' => 'title']
     */
    public function getUrlFilters()
    {
        $filters_index = strpos($this->shopifyData['URL'], '?');
        $filters_str = substr($this->shopifyData['URL'], $filters_index + 1);
        $filters = explode("&", $filters_str);
        foreach ($filters as $filter) {
            list($k, $v) = explode("=", $filter);
            $retVal[$k] = $v;
        }
        return $retVal;
    }

    public function pagination($resource)
    {
        $continue = true;
        $merged_array = array();
        $page = 1;
        while ($continue) {
            $this->addUrlFilter('page', $page);
            if (isset($this->shopifyData['DATA'])) {
                $resource_array = $this->call($this->shopifyData, $this->shopifyData['DATA']);
            } else {
                $resource_array = $this->call($this->shopifyData);
            }
            // break out of the loop
            if (empty($resource_array->$resource)) {
                $continue = false;
                break;
            }
            $merged_array = array_merge($merged_array, $resource_array->$resource);
            $page++;
        }
        $this->resetData();
        return $merged_array;
    }
    
    /**
     * Gets the total count of the resource
     * @param string $resource
     */
    public function getTotalCount($resource)
    {
        $currentShopifyData = $this->shopifyData;
        $currentShopifyData['URL'] = $this->shopifyData['PLURAL_NAME'] . '/count.json';
        $result = $this->call($currentShopifyData);
        return $result->count;
    }

    /**
     * Appends to the end of the url a filter/endpoint e.g. https://test.json?limit=250
     */
    public function addUrlFilter($key, $value)
    {
        if (isset($this->shopifyData['URL'])) {
            if (strpos($this->shopifyData['URL'], "?") === false) {
                $this->shopifyData['URL'] .= '?' . $key . '=' . $value;
            } else {
                $this->shopifyData['URL'] .= '&' . $key . '=' . $value;
            }
        }
    }
    
    /**
     * Adds properties to the DATA array
     * e.g. addData('title', 'Title') would result [ DATA: [ title: 'Title' ] ]
     */
    public function addData($key, $value)
    {
        $this->shopifyData['DATA'][$key] = $value;
    }
    
    /**
     * Adds a property to the call
     * e.g. addCallData('METHOD', 'GET') would result [ METHOD: 'GET' ]
     * CallData is the immediate property of the call array e.g. METHOD or URL
     * @param string $key - the property's name
     * @param string $value - the property's value
     */
    public function addCallData($key, $value)
    {
        // $key is the property's name
        if ($key == 'resource') {
            $this->setSingularAndPluralName($value);
        }
        if ($key == 'URL') {
            $value .= '.json';
        }
        $this->shopifyData[$key] = $value;
    }

    /**
     * Builds a property or child_resource to be committed to a parent_resource
     * e.g.
     * addCallData('resource', "products")
     * buildChildData("url", "https://image", "images") would result
     * [ DATA: [ product: { images:{ 0:{ url: "https://image" } } } ] ]
     * @param string $key The key value of the property to be added
     * @param string $value The value of the property to be added
     * @param string $child_resource the resource name to nest the key value pair into
     */
    public function buildChildData($key, $value, $child_resource = null)
    {
        if ($child_resource == null) {
            $this->childData[$this->shopifyData['SINGULAR_NAME']][$key] = $value;
        } else {
            $this->childData[$child_resource][$key] = $value;
        }
    }

    /**
     * Commits the childData to the shopifyData
     * @param string $child_resource The resource name to nest the array into
     */
    public function commitChildData($child_resource = null)
    {
        $resource = $this->shopifyData['SINGULAR_NAME'];
        if ($child_resource == null) {
            $this->shopifyData['DATA'] = $this->childData;
        } elseif (is_array($this->childData[$child_resource])) {
            $this->shopifyData['DATA'][$resource][$child_resource][] = $this->childData[$child_resource];
        } else {
            $this->shopifyData['DATA'][$resource][$child_resource] = $this->childData[$child_resource];
        }
        unset($this->childData);
    }

    /**
     * Resets the shopifyData
     */
    public function resetData()
    {
        unset($this->shopifyData);
    }

    public function getSingularAndPluralName($resource)
    {
        $retVal = [
            'PLURAL_NAME' => '',
            'SINGULAR_NAME' => ''
        ];
        switch ($resource) {
            case 'products':
                $retVal['PLURAL_NAME'] = $resource;
                $retVal['SINGULAR_NAME'] = 'product';
                break;
            case 'variants':
                $retVal['PLURAL_NAME'] = $resource;
                $retVal['SINGULAR_NAME'] = 'variant';
                break;
            case 'custom_collections':
                $retVal['PLURAL_NAME'] = 'custom_collections';
                $retVal['SINGULAR_NAME'] = 'custom_collection';
                break;
            case 'smart_collections':
                $retVal['PLURAL_NAME'] = 'smart_collections';
                $retVal['SINGULAR_NAME'] = 'smart_collection';
                break;
            case 'collects':
                $retVal['PLURAL_NAME'] = 'collects';
                $retVal['SINGULAR_NAME'] = 'collect';
                break;
            case 'collections':
                $retVal['PLURAL_NAME'] = 'collections';
                $retVal['SINGULAR_NAME'] = 'collection';
                break;
            case 'metafields':
                $retVal['PLURAL_NAME'] = 'metafields';
                $retVal['SINGULAR_NAME'] = 'metafield';
                break;
            case 'customers':
                $retVal['PLURAL_NAME'] = 'customers';
                $retVal['SINGULAR_NAME'] = 'customer';
                break;
            case 'orders':
                $retVal['PLURAL_NAME'] = 'orders';
                $retVal['SINGULAR_NAME'] = 'order';
                break;
            case 'inventory_levels':
                $retVal['PLURAL_NAME'] = 'inventory_levels';
                $retVal['SINGULAR_NAME'] = 'inventory_level';
                break;
            case 'risks':
                $retVal['PLURAL_NAME'] = 'risks';
                $retVal['SINGULAR_NAME'] = 'risk';
                break;
            case 'transactions':
                $retVal['PLURAL_NAME'] = 'transactions';
                $retVal['SINGULAR_NAME'] = 'transaction';
                break;
        }
        return $retVal;
    }

    /**
     * Sets the singular and plural names for the resource
     * @param string $resource The resource name
     */
    public function setSingularAndPluralName($resource)
    {
        $names = $this->getSingularAndPluralName($resource);
        $this->shopifyData['PLURAL_NAME'] = $names['PLURAL_NAME'];
        $this->shopifyData['SINGULAR_NAME'] = $names['SINGULAR_NAME'];
    }
    
    /**
     * Returns shopifyData or specified shopifyData's property
     * Goes only 1 level deep, so it will only return callData property not 'DATA' property
     * @param String $property - default null - the property to get
     */
    public function getShopifyData($property = null)
    {
        if (!isset($this->shopifyData)) {
            return false;
        }
        if (isset($property)) {
            return $this->shopifyData[$property];
        }
        return $this->shopifyData;
    }

    /**
     * Sets the given $data as the shopifyData
     * @param Array $data - the new shopifyData
     */
    public function setShopifyData($data = array())
    {
        return $this->shopifyData = $data;
    }

    /**
     *  Checks if a record with the property value exists and creates or updates a record depending.
     *  @param string $compare_property property to compare
     *  @param bool $update whether to update a record if it exists
    */
    public function createOrUpdate($compare_property, $update = false)
    {
        $resource = $this->shopifyData['resource'];
        $resource_singular = $this->shopifyData['SINGULAR_NAME'];
        $compare_property_value = $this->shopifyData['DATA'][$resource_singular][$compare_property];

        $currentShopifyData = $this->shopifyData;
        $currentShopifyData['METHOD'] = 'GET';

        if ($compare_property == 'id') {
            $compare_property_valid_name = 'ids';
        } else {
            $compare_property_valid_name = $compare_property;
        }

        $currentShopifyData['URL'] .= '?' . $compare_property_valid_name . '=' . urlencode($compare_property_value);

        $result = $this->call($currentShopifyData, $currentShopifyData['DATA']);

        // If one record is returned optionally update, otherwise create. If more than one record is returned throw an error
        if (count($result->$resource) == 1 && $update == true) {
            // Update the record
            $result = $this->updateRecord($result->$resource[0]->id, $compare_property);
        } elseif (count($result->$resource) == 0) {
            // Create
            $result = $this->createRecord();
        } elseif (count($result->$resource) > 1) {
            // more than one record exists.
            $this->exceptionNotice("Error: More than one record exists");
        }
        $this->resetData();
        return $result;
    }

    /**
     * Gets a record with the given $id e.g. products/$id.json
     * @param int $id
     * @param String $child_resource - e.g. when getting a product's variants
     */
    public function getRecord($id, $child_resource = false)
    {
        $resource = $this->shopifyData['resource'];
        // save the current shopifyData so we don't overwrite it
        $currentShopifyData = $this->shopifyData;
        $currentShopifyData['METHOD'] = 'GET';
        if ($child_resource) {
            $currentShopifyData['URL'] = self::PREFIX . '/' . $resource . '/' . $id . '/' . $child_resource . '.json';
        } else {
            $currentShopifyData['URL'] = self::PREFIX . '/' . $resource . '/' . $id . '.json';
        }
        // Checks if the DATA array is set, if it isn't, do not pass it when calling
        if (isset($this->shopifyData['DATA'])) {
            $result = $this->call($currentShopifyData, $currentShopifyData['DATA']);
        } else {
            $result = $this->call($currentShopifyData);
        }
        $this->resetData();
        return $result;
    }

    /**
     * Updates a record's $property with $proprety_value with the given $id
     * @param int $id The id of the record
     * @param string $property the property name
    **/
    public function updateRecord($id)
    {
        // Check if the record exists
        $resource = $this->shopifyData['resource'];
        $resource_singular = $this->shopifyData['SINGULAR_NAME'];
        $compare_property_value = $id;

        $tempShopifyData = $this->shopifyData;
        $tempShopifyData['METHOD'] = 'GET';
        switch ($this->shopifyData['resource']) {
            case 'collects':
            case 'variants':
                $compare_property_name = 'id';
                $tempShopifyData['URL'] = 'admin/' . $resource . '/' . $id . '.json';
                break;
            case 'metafields':
                // metafields requires a resource id and metafield id, therefore, we're setting the URL from where it's being called
                $tempShopifyData['URL'] = $tempShopifyData['URL'];
                break;
            case 'inventory_levels':
                $compare_property_name = 'inventory_item_ids';
                $tempShopifyData['URL'] .= '?' . $compare_property_name . '=' . urlencode($compare_property_value);
                break;
            default:
                $compare_property_name = 'ids';
                $tempShopifyData['URL'] .= '?' . $compare_property_name . '=' . urlencode($compare_property_value);
        }
        
        $result = $this->call($tempShopifyData);
        if ((isset($result->$resource) && count($result->$resource) == 1) || property_exists($result, $resource_singular)) {
            // update the record
            $resource = $this->shopifyData['resource'];
            $currentShopifyData = $this->shopifyData;
            $currentShopifyData['METHOD'] = 'PUT';

            switch ($resource) {
                case 'smart_collections':
                    // if smart_collections, determine whether to use order.json or #id.json
                    if (isset($currentShopifyData['DATA']) && array_has($currentShopifyData['DATA'], 'products')) {
                        $currentShopifyData['URL'] = str_replace(".json", '/' . $id . '/order.json', $currentShopifyData['URL']);
                    }
                    break;
                case 'inventory_levels':
                    $currentShopifyData['URL'] = str_replace(".json", '/set.json', $currentShopifyData['URL']);
                    break;
                default:
                    $currentShopifyData['URL'] = self::PREFIX . '/' . $resource . '/' . $id . '.json';
            }


            if (isset($currentShopifyData['DATA'])) {
                $result = $this->call($currentShopifyData, $currentShopifyData['DATA']);
            } else {
                $result = $this->call($currentShopifyData);
            }
            $this->resetData();
            return $result;
        }
    }

    /**
     * Creates a record
     */
    public function createRecord()
    {
        // setting method to post as it's creating
        $this->shopifyData['METHOD'] = 'POST';
        $result = $this->call($this->shopifyData, $this->shopifyData['DATA']);
        $this->resetData();
        return $result;
    }

    /**
     * Deletes a record
     * @param int $id - the id of the given resource
     * @param int $parent_id - the id of the parent resource e.g. when deleting a variant, we need to pass in the product_id as well which is the parent_id
     */
    public function deleteRecord($id, $parent_id = false)
    {
        // Check if the record exists
        $resource = $this->shopifyData['resource'];
        $resource_singular = $this->shopifyData['SINGULAR_NAME'];
        $compare_property_value = $id;

        $currentShopifyData = $this->shopifyData;
        $currentShopifyData['METHOD'] = 'GET';
        switch ($this->shopifyData['resource']) {
            case 'collects':
            case 'variants':
                $compare_property_name = 'id';
                $currentShopifyData['URL'] = 'admin/' . $resource . '/' . $id . '.json';
                break;
            case 'metafields':
                // metafields requires a resource id and metafield id, therefore, we're setting the URL from where it's being called
                $currentShopifyData['URL'] = $currentShopifyData['URL'];
                break;
            default:
                $compare_property_name = 'ids';
                $currentShopifyData['URL'] .= '?' . $compare_property_name . '=' . urlencode($compare_property_value);
        }
        
        $result = $this->call($currentShopifyData);
        // delete the record if it exists
        if ((isset($result->$resource) && count($result->$resource) == 1) || $result->$resource_singular) {
            $this->shopifyData['METHOD'] = 'DELETE';
            switch ($this->shopifyData['resource']) {
                case 'variants':
                    $this->shopifyData['URL'] = 'admin/products/' . $parent_id . '/variants/' . $id . '.json';
                    break;
                default:
                    $this->shopifyData['URL'] = 'admin/' . $resource . '/' . $id . '.json';
            }

            $result = $this->call($this->shopifyData);

            $this->resetData();
            return $result;
        } elseif (isset($result->$resource) && count($result->$resource) < 1) {
            $error = "Error: record doesn't exist";
            exceptionNotice($error);
        }
    }

    /**
     * Creates a resource with the given $resource_data
     * @param String $resource
     * @param Array $resource_data - e.g. ['title' => 'TITLE', 'rules' => ['column' => 'vendor', 'relation' => 'equals', 'condition' => 'Cottonbabies']]
     */
    public function createResource($resource, $resource_data)
    {
        $this->addCallData('resource', $resource);
        $this->addCallData('URL', 'admin/' . $resource);
        foreach ($resource_data as $property => $data) {
            $this->buildChildData($property, $data);
        }
        $this->commitChildData();
        return $this->createRecord();
    }

    /**
     * Updates a resource with the given $resource_data['id']
     * @param String $resource
     * @param Array $resource_data - e.g. ['title' => 'TITLE', 'body_html' => 'htmlstuff']
     */
    public function updateResource($resource, $resource_id, $resource_data)
    {
        $this->addCallData('resource', $resource);
        $this->addCallData('URL', 'admin/' . $resource);
        $this->addCallData('METHOD', 'PUT');
        foreach ($resource_data as $property => $data) {
            $this->buildChildData($property, $data);
        }
        $this->commitChildData();
        return $this->updateRecord($resource_id);
    }

    /**
     * Gets resource from the given $resource and applies the given $url_filters to the call. The type of call is determined by $function
     * @param String $resource
     * @param Array $url_filters  - e.g. ['limit' => 250]
     * @param String $function - .e.g 'paginate'
     * @param boolean/int $single - can be int when using 'get' $function e.g. getResource('products', [], 'get', 234987);
     * @param String $child_resource - can be a string when using 'get' $function - e.g. getResource('products', [], 'get', 234234, 'variants')
     */
    public function getResource($resource, $url_filters, $function, $single = false, $child_resource = false)
    {
        $retVal = false;
        $this->addCallData('resource', $resource);
        $this->addCallData('URL', 'admin/' . $resource);
        foreach ($url_filters as $filter => $value) {
            $this->addUrlFilter($filter, $value);
        }
        switch ($function) {
            case 'paginate':
                $result = $this->pagination($resource);
                break;
            case 'list':
                $result = $this->listShopifyResources();
                break;
            case 'get':
                if ($child_resource) {
                    $result = $this->getRecord($single, $child_resource);
                } else {
                    $result = $this->getRecord($single);
                }
                break;
        }
        // if returning a single result
        if ($single) {
            switch ($function) {
                case 'list':
                    // resetting twice as it's in a 2 layer nesting. e.g. $result = ["PRODUCT" => [ 0 => Object{..} ], "ERRORS" => {..}]
                    $result = reset($result);
                    $result = reset($result);
                    if ($result) {
                        $retVal = $result;
                    }
                    break;
                case 'get':
                    $retVal = reset($result);
                    break;
                default:
                    $retVal = reset($result);
            }
        } else {
            $retVal = $result;
        }
        return $retVal;
    }

    /**
     * Verifies request and Saves the webhooks in designated directory e.g. shopify_webhooks/domain/topic
     * @param object $request - the Request object
     */
    public function saveWebhooks(Request $request)
    {
        if ($request->header('x-shopify-hmac-sha256')) {
            $hmac = $request->header('x-shopify-hmac-sha256');
        }
        $data = file_get_contents("php://input");
        $verify = $this->verify_webhook($data, $hmac);
        // if verification passed
        if ($verify) {
            // replaces "/" with "_" to create valid topic directories
            $topic_dir = $request->header('x-shopify-topic');
            $storage_dir = $this->webhooks_dir . $request->header('x-shopify-shop-domain') . '/' . $topic_dir;
            $dir = Storage::directories($storage_dir);
            if (empty($dir)) {
                Storage::makeDirectory($storage_dir);
            }
            $file_name = $this->unique_name . '.json';

            return Storage::put($storage_dir . '/' . $file_name, $data);
        }
        // if verification failed
        $error_log_name = $this->unique_name . '_' . 'webhooks.log';
        // check if logs directory exists under shopify_webhooks directory
        $error_log_dirs = Storage::directories($this->error_log_dir);
        if (empty($error_log_dirs)) {
            Storage::makeDirectory($this->error_log_dir);
        }
        // Create the error log file
        Storage::put($this->error_log_dir . '/' . $error_log_name, $data);
    }

    /**
     * Moves the error log file to the archive directory,
     * then sends an email of the error log
     */
    public function emailAndProcess()
    {
        // Archive the error log file
        // check if archives directory exists
        $archive_dirs = Storage::directories($this->archive_dir);
        if (empty($archive_dirs)) {
            Storage::makeDirectory($this->archive_dir);
        }
        // updating the filename to use now() again for more updated date
        $updated_name = $this->unique_name . '_' . 'webhooks.log';
        // get all the error logs in the logs directory
        $error_logs =  Storage::files($this->error_log_dir);
        foreach ($error_logs as $error_log) {
            // get the log content
            $data = Storage::get($error_log);
            // move the log file to archives directory
            $src_file = $error_log;
            $dest_file = $this->archive_dir . '/' . $updated_name;
            Storage::move($src_file, $dest_file);
            // send an email of the error log
            $this->webhookNotice($data);
        }
    }

    /**
     * Returns a list of all the files in the webhooks directory
     * @param String $topic - the topic e.g products/create
     * @return Array
     * use with the logs.default view e.g.
     *  $data = $this->sh->readWebhooks('products/create');
     *  return view('logs.default', compact('data'));
     */
    public function readWebhooks($topic)
    {
        $all_webhooks = Storage::allFiles($this->webhooks_dir . $this->_API['SHOP_DOMAIN'] . '/' . $topic);
        $processed_webhooks = Storage::allFiles($this->webhooks_dir . $this->_API['SHOP_DOMAIN'] . '/' . $topic . '/processed');
        // return the webhooks that aren't processed yet
        return array_diff($all_webhooks, $processed_webhooks);
    }

    /**
     * Moves the existing webhook file to a sub-directory under the topic directory called "processed"
     * @param String $topic - the topic e.g. products/create
     * @param String $file - the file name e.g. shopify_webhooks/domain/topic/file_name
     */
    public function processWebhooks($topic, $file)
    {
        $webhooks_dir = $this->webhooks_dir . $this->_API['SHOP_DOMAIN'] . '/' . $topic;
        $processed_dir = $this->webhooks_dir . $this->_API['SHOP_DOMAIN'] . '/' . $topic . '/processed';
        // check if processed directory exists
        $processed_dirs = Storage::directories($processed_dir);
        if (empty($processed_dirs)) {
            Storage::makeDirectory($processed_dir);
        }
        // get the file name from the path
        $file_name = explode('/', $file);
        // get the last element which is the file name
        $file_name = end($file_name);
        // updating the modified date
        touch(storage_path() . '/app/' . $webhooks_dir . '/' . $file_name);

        // move the log file to processed directory
        $src_file = $webhooks_dir . '/' . $file_name;
        $dest_file = $processed_dir . '/' . $file_name;
        Storage::move($src_file, $dest_file);
    }

    /**
     * Checks if the files in the processed and archived folders are older than 30 days and delete them if they are
     * @param bool $logs - determines whether to clean the error logs or the webhook files
     */
    public function cleanWebhooks($logs = false)
    {
        if ($logs) {
            $archived_files = Storage::allFiles($this->archive_dir);
            foreach ($archived_files as $file) {
                // Setting the file's last modified timestamp to the class' carbon object
                $this->carbon->timestamp = Storage::lastModified($file);
                // Get the difference between file modification date and now. Then compare it to the expired days variable
                if ($this->carbon->diffInDays($this->carbon->now()) >= $this->expired_days) {
                    Storage::delete($file);
                }
            }
        } elseif ($logs == false) {
            $shop_domain_dir = $this->webhooks_dir . $this->_API['SHOP_DOMAIN'];
            $topic_directories = Storage::directories($shop_domain_dir);
            foreach ($topic_directories as $topic_dir) {
                $processed_files = Storage::allFiles($topic_dir);
                foreach ($processed_files as $file) {
                    // Setting the file's last modified timestamp to the class' carbon object
                    $this->carbon->timestamp = Storage::lastModified($file);
                    // Get the difference between file modification date and now. Then compare it to the expired days variable
                    if ($this->carbon->diffInDays($this->carbon->now()) >= $this->expired_days) {
                        Storage::delete($file);
                    }
                }
            }
        }
    }

    public function verify_webhook($data, $hmac_header)
    {
        $calculated_hmac = base64_encode(hash_hmac('sha256', $data, env('SHOPIFY_APP_SECRET'), true));
        return ($hmac_header == $calculated_hmac);
    }

    /**
     * Adds or deletes tags from the given $tags string
     * @param String $tags a string of all the tags e.g. 'cb_feed_facebook, cb_feed_google'
     * @param String $tag the new tag to add and check against
     * @param String $action the action e.g. add or delete
     */
    public function manageTag($tags, $new_tags, $action, $prefix_tag_name = false)
    {
        $retVal = [];
        $tags = explode(", ", $tags);
        
        // iterate through the new tags
        foreach ($new_tags as $tag) {
            if ($prefix_tag_name) {
                $match = preg_grep("/^" . $prefix_tag_name . ".*/", $tags);
                if (!empty($match)) {
                    foreach ($match as $key => $value) {
                        unset($tags[$key]);
                    }
                }
            }
            if ($action == 'add') {
                if (!in_array($tag, $tags)) {
                    $retVal[] = $tag;
                }
            } elseif ($action == 'delete') {
                if (in_array($tag, $tags)) {
                    // find the key to unset from the array
                    $key = array_search($tag, $tags);
                    unset($tags[$key]);
                }
            }
        }
        $retVal = array_merge($retVal, $tags);
        $retVal = array_filter($retVal);
        $retVal = implode(", ", $retVal);
        return $retVal;
    }

    /**
     * Adds and deletes the given $tags_delete/$tags_add arrays from the product's tags
     * @param String $tags        - the tags to delete from and add to  e.g. $product->tags
     * @param Array $tags_delete  - the tags to delete e.g. ['cb_soldout','cb_test']
     * @param Array $tags_add     - the tags to add e.g. ['cb_new_tag']
     * @param String $filter_tag  - a tag to filter by e.g. when changing tags for only products with tag 'cb_soldout'
     */
    public function changeTags($tags, $tags_delete = array(), $tags_add = array(), $filter_tag = null)
    {
        if (!empty($tags)) {
            if (isset($filter_tag) && $this->hasTag($product->tags, $filter_tag)) {
                $product_data = [];
                $updated_tags = $this->manageTag($product->tags, ['cb_soldout'], 'delete');
                $updated_tags = $this->manageTag($updated_tags, ['cb_auto_soldout'], 'add');
                return $updated_tags;
            }
        }
    }

    /**
     * Gets all the $resource with the given $tag
     * @param String $resource
     * @param String $tag
     */
    public function getResourceWithTag($resource, $tag)
    {
        $retVal = [];
        $records = $this->getResource($resource, ['fields' => 'tags'], 'paginate');
        foreach ($records as $key => $record) {
            if (stripos($record->tags, $tag)) {
                $retVal[] = $record;
            }
        }
        return $retVal;
    }

    /**
     * Checks if the given $tags string contains $tag
     * @param String $tags the tags string e.g. 'cb_feed_facebook, cb_feed_google'
     * @param String $tag the tag to search in the tags
     */
    public function hasTag($tags, $tag)
    {
        $retVal = false;
        if (stripos($tags, $tag) !== false) {
            $retVal = true;
        }
        return $retVal;
    }

    /**
     * Gets a specific metafield by it's $id
     * @param int $id
     */
    public function getMetafield($id)
    {
        $resource = $this->shopifyData['resource'];
        // save the current shopifyData so we don't overwrite it
        $currentShopifyData = $this->shopifyData;
        $currentShopifyData['METHOD'] = 'GET';
        $currentShopifyData['URL'] = self::PREFIX . '/' . $resource . '/' . $id . '/metafields.json';
        // Checks if the DATA array is set, if it isn't, do not pass it when calling
        if (isset($this->shopifyData['DATA'])) {
            $result = $this->call($currentShopifyData, $currentShopifyData['DATA']);
        } else {
            $result = $this->call($currentShopifyData);
        }
        $this->resetData();
        return reset($result);
    }

    /**
     * Checks if a metafield with the given $namespace and $key exists
     * @Returns the found metafield object. Defaults to false
     * @param Array of metafield objects
     * @param String $namespace
     * @param String $key
     */
    public function metafieldExists($metafields, $namespace, $key, $partial = false)
    {
        $retVal = false;
        foreach ($metafields as $metafield) {
            if ($partial) {
                if (stripos($metafield->namespace, $namespace) !== false && stripos($metafield->key, $key) !== false) {
                    $retVal = $metafield;
                }
            } else {
                if ($metafield->namespace == $namespace && $metafield->key == $key) {
                    $retVal = $metafield;
                }
            }
        }
        return $retVal;
    }


    /**
     * Creates a metafield for the given $resource with the given $resource_id and $value
     * @param String $resource
     * @param int $resource_id
     * @param String $value
     */
    public function createMetafield($resource, $resource_id, $metafield_data)
    {
        $this->addCallData('resource', 'metafields');
        $this->addCallData('URL', 'admin/' . $resource . '/' . $resource_id . '/metafields');
        // $this->buildChildData('namespace', 'cottonbabies');
        $this->buildChildData('namespace', $metafield_data['namespace']);
        // $this->buildChildData('key', 'orig_sort_order');
        $this->buildChildData('key', $metafield_data['key']);
        // $this->buildChildData('value', $value);
        $this->buildChildData('value', $metafield_data['value']);
        $this->buildChildData('value_type', 'string');
        $this->commitChildData();
        $this->createRecord();
    }

    /**
     * Deletes a metafield from the given $resource (e.g. collections) by the $metafield_id
     * @param String $resource
     * @param Object $resource_id
     * @param int $metafield_id
     */
    public function deleteMetafield($resource, $resource_id, $metafield_id)
    {
        $this->addCallData('resource', 'metafields');
        $this->addCallData('URL', 'admin/' . $resource . '/' . $resource_id . '/metafields/' . $metafield_id);
        $this->deleteRecord($metafield_id);
    }

    /**
     * Updates the tags of a resource to the given $tags
     * @param int $resource_id
     * @param String $resource
     * @param String $tags
     */
    public function updateTags($resource_id, $resource, $tags)
    {
        $this->addCallData('resource', $resource);
        $this->addCallData('URL', 'admin/' . $resource);
        $this->buildChildData('tags', $tags);
        $this->commitChildData();
        $this->updateRecord($resource_id);
    }

    /**
     * Iterates through all the shops and runs the given functions from the given controller
     * @parameter Object $controller - the controller object
     * @parameter String/Array $function_name - an array of function names or just a function name
     */
    public function iterateShops($controller, $function_name)
    {
        $shops = Shop::all();
        // foreach of shops
        foreach ($shops as $shop) {
            $this->shopSwitch($shop->myshopify_domain, true);
            // if $function_name is an Array
            if (is_array($function_name)) {
                foreach ($function_name as $function) {
                    $controller->$function();
                }
            }
            // if $function_name is a String
            else {
                $controller->$function_name();
            }
        }
    }

    /**
     * Returns the current store domain
     */
    public function getShopDomain()
    {
        return $this->_API['SHOP_DOMAIN'];
    }

    /**
     * Updates the a variant's quantity by getting the location id first, then updating it using the new API
     * @param $inventory_item_id
     */
    public function updateVariantQuantity($inventory_item_id, $quantity)
    {
        // get the location_id
        $result = false;
        $resource = 'inventory_levels';
        $url_filters = ['ids', $inventory_item_id];
        $this->addCallData('resource', $resource);
        $this->addCallData('URL', 'admin/' . $resource);
        foreach ($url_filters as $filter => $value) {
            $this->addUrlFilter($filter, $value);
        }
        $result = $this->listShopifyResources();
        $inventory_item = reset($result->inventory_levels);
        // set the new quantity
        $this->addCallData('resource', $resource);
        $this->shopifyData['URL'] = 'admin/' . $resource . '/set.json';
        $this->addData('inventory_item_id', $inventory_item_id);
        $this->addData('location_id', $inventory_item->location_id);
        $this->addData('available', $quantity);
        $result = $this->updateRecord($inventory_item_id);
        return $result;
    }

    public function getResourceComponent($resource, $component, $resourceId, $urlFilters)
    {
        $this->addCallData('resource', $resource);
        $this->addCallData('URL', self::PREFIX . '/' . $resource . '/' . $resourceId . '/' . $component);
        foreach ($urlFilters as $filter => $value) {
            $this->addUrlFilter($filter, $value);
        }
        $transactions = $this->listShopifyResources();
        $transactions = reset($transactions);
        return $transactions;
    }

    public function createResourceComponent($resource, $component, $resourceId, $newComponentData)
    {
        $this->addCallData('resource', $resource);
        $this->addCallData('URL', self::PREFIX . '/' . $resource . '/' . $resourceId . '/' . $component);
        $componentNames = $this->getSingularAndPluralName($component);
        foreach ($newComponentData as $property => $data) {
            $this->buildChildData($property, $data, $componentNames['SINGULAR_NAME']);
        }
        $this->commitChildData();
        return $this->createRecord();
    }

    public function resourceExists($resource, $resourceId)
    {
        $resourceSingle = $this->getResource($resource, ['ids' => $resourceId], 'list');
        return !empty($resourceSingle->$resource);
    }

} // End of API class

<?php

namespace RocketCode\Shopify;
use Closure;


class VerifyShopifyWebhook
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (self::verify_webhook($request->getContent(), $request->header('HTTP_X_SHOPIFY_HMAC_SHA256'))) {
            return $next($request);
        } else {
            abort(403, 'Shopify webhook verification failed');
        }
    }

    /**
     * Verifies the HMAC
     *
     * @param  string $data
     * @param  string $hmac_header
     * @return bool
     */
    private static function verify_webhook($data, $hmac_header)
    {
        $calculated_hmac = base64_encode(hash_hmac('sha256', $data, env('SHOPIFY_APP_SECRET'), true));
        return ($hmac_header == $calculated_hmac);
    }
}
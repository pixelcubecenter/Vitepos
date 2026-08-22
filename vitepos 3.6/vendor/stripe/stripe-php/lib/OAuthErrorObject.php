<?php

namespace Stripe;

/**
 * Class OAuthErrorObject.
 *
 * @property string $error
 * @property string $error_description
 */
class OAuthErrorObject extends StripeObject
{
    /**
     * Refreshes this object using the provided values.
     *
     * @param array $values
     * @param null|array|string|Util\RequestOptions $opts
     * @param bool $partial defaults to false
     */
    public function refreshFrom($values, $opts, $partial = false)
    {
        
        
        
        $values = \array_merge([
            'error' => null,
            'error_description' => null,
        ], $values);
        parent::refreshFrom($values, $opts, $partial);
    }
}

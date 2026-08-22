<?php

namespace Stripe;

/**
 * Class SingletonApiResource.
 */
abstract class SingletonApiResource extends ApiResource
{
    /**
     * @return string the endpoint associated with this singleton class
     */
    public static function classUrl()
    {
        
        

        /** @phpstan-ignore-next-line */
        $base = \str_replace('.', '/', static::OBJECT_NAME);

        return "/v1/{$base}";
    }

    /**
     * @return string the endpoint associated with this singleton API resource
     */
    public function instanceUrl()
    {
        return static::classUrl();
    }
}

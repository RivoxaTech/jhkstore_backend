<?php

namespace App\Http\Resources;

/** The short order row of "My orders" (needs `items` and `channel` loaded). */
class StoreOrderListResource extends StoreOrderResource
{
    public function __construct($resource)
    {
        parent::__construct($resource, false);
    }
}

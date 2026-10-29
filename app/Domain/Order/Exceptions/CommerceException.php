<?php

namespace App\Domain\Order\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A business-rule failure of the cart / checkout / order flow. Rendered as JSON that a frontend can show directly:
 * { message, code, errors: { field: [message] }, ...extra } with status 422 (409 for "the data changed under you").
 * `code` is stable (e.g. out_of_stock, insufficient_stock, cod_not_allowed, cart_empty); `message` is human text.
 */
class CommerceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $field = 'cart',
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => [$this->field => [$this->getMessage()]],
        ] + $this->extra, $this->status);
    }
}

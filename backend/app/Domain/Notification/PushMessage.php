<?php

namespace App\Domain\Notification;

use App\Domain\Order\Models\Order;
use App\Domain\Support\Settings;
use App\Domain\Wholesale\Models\WholesaleRequest;

/**
 * The payloads of the admin push messages (the service worker `public/admin-sw.js` shows them): a short title, a body with the order number and
 * total, the store logo as `icon`, a `tag` per order (a newer message about the same order replaces the older one) and the panel path to open on click.
 */
class PushMessage
{
    public static function newOrder(Order $order, Settings $settings): array
    {
        return self::make($settings, 'New order', $order->order_number.' · '.self::total($order, $settings), '/admin/orders/'.$order->id, 'order-'.$order->id);
    }

    public static function proofUploaded(Order $order, Settings $settings): array
    {
        return self::make($settings, 'Payment proof to check', $order->order_number.' · '.self::total($order, $settings), '/admin/orders/'.$order->id, 'proof-'.$order->id);
    }

    public static function wholesale(WholesaleRequest $request, Settings $settings): array
    {
        return self::make($settings, 'New wholesale request', $request->reference.' · '.$request->quantity.' × '.$request->product_name, '/admin/wholesale', 'wholesale-'.$request->id);
    }

    public static function test(Settings $settings): array
    {
        return self::make($settings, 'Test notification', 'Push notifications work on this device.', '/admin/', 'test');
    }

    private static function make(Settings $settings, string $title, string $body, string $url, string $tag): array
    {
        $logo = $settings->logoUrl();

        return ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag] + ($logo ? ['icon' => $logo] : []);
    }

    private static function total(Order $order, Settings $settings): string
    {
        return $settings->currencySymbol().number_format((float) $order->grand_total, $settings->decimals());
    }
}

<?php

namespace App\Mail;

use App\Domain\Order\Models\Order;
use App\Domain\Support\Settings;
use App\Http\Resources\OrderPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An e-mail about one order to the customer (one subclass per event). Queued after the order's transaction commits, so a slow or broken mail
 * server never delays or fails an order. Plain Blade templates in resources/views/mail: no images, no tracking pixels, no external links
 * except the store's own tracking page (the setting `storefront_track_url`, `?order=NUMBER`; the customer is asked for the phone number there).
 */
abstract class OrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(public Order $order)
    {
        $this->afterCommit();   // queued only once the order's transaction has committed
    }

    /** The Blade view under resources/views/mail. */
    abstract protected function template(): string;

    /** Subject line; the order number is appended. */
    abstract protected function title(): string;

    /** Extra data for the template. */
    protected function extra(): array
    {
        return [];
    }

    public function envelope(): Envelope
    {
        $settings = app(Settings::class);
        $name = trim((string) $settings->get('mail_from_name')) ?: (string) $settings->get('store_name');
        $replyTo = trim((string) $settings->get('mail_reply_to')) ?: trim((string) $settings->get('store_email'));

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $replyTo !== '' ? [new Address($replyTo, $name)] : [],
            subject: $this->title().' · '.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        $o = $this->order->loadMissing(['items', 'payment']);
        $settings = app(Settings::class);
        $track = trim((string) $settings->get('storefront_track_url'));
        $bank = $o->payment_method === 'bank_transfer' && $o->payment_status->value !== 'paid';

        return new Content(view: 'mail.'.$this->template(), with: $this->extra() + [
            'order' => $o,
            'store' => ['name' => (string) $settings->get('store_name'), 'email' => (string) $settings->get('store_email'), 'phone' => (string) $settings->get('store_phone')],
            'items' => $o->items,
            'totals' => OrderPresenter::totals($o),
            'money' => fn ($v) => $v === null ? '-' : $settings->currencySymbol().number_format((float) $v, $settings->decimals()),
            'payLabel' => OrderPresenter::paymentMethodLabel($o->payment_method),
            'bank' => $bank ? ['accounts' => $o->payment?->bank_snapshot['accounts'] ?? [], 'instructions' => (string) ($o->payment?->bank_snapshot['instructions'] ?? '')] : null,
            'trackUrl' => $track !== '' ? $track.(str_contains($track, '?') ? '&' : '?').'order='.urlencode($o->order_number) : null,
            'estimate' => $o->estimated_delivery_date?->toFormattedDateString(),
        ]);
    }
}

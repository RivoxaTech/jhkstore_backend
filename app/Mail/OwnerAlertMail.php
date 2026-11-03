<?php

namespace App\Mail;

use App\Domain\Order\Models\Order;
use App\Domain\Support\Settings;
use App\Domain\Wholesale\Models\WholesaleRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A short alert for the store owner (the address in the setting `notify_admin_email`, NOT an admin account): a new order, a payment proof
 * to check, or a new wholesale request. Plain text content, no images, no tracking.
 */
class OwnerAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $tries = 3;

    public const NEW_ORDER = 'new_order';

    public const PAYMENT_PROOF = 'payment_proof';

    public const WHOLESALE = 'wholesale';

    public function __construct(public string $kind, public Order|WholesaleRequest $about)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $settings = app(Settings::class);
        $ref = $this->about instanceof Order ? $this->about->order_number : $this->about->reference;
        $title = match ($this->kind) {
            self::NEW_ORDER => 'New order',
            self::PAYMENT_PROOF => 'Payment proof to check',
            default => 'New wholesale request',
        };

        return new Envelope(from: new Address((string) config('mail.from.address'), (string) $settings->get('store_name')), subject: "$title · $ref");
    }

    public function content(): Content
    {
        $settings = app(Settings::class);

        return new Content(view: 'mail.owner-alert', with: [
            'kind' => $this->kind,
            'about' => $this->about,
            'store' => (string) $settings->get('store_name'),
            'money' => fn ($v) => $settings->currencySymbol().number_format((float) $v, $settings->decimals()),
        ]);
    }
}

{{-- The order block every customer e-mail shows: number, items, totals, payment, delivery estimate and the tracking link. --}}
<p style="margin:0 0 12px;"><strong>Order {{ $order->order_number }}</strong><br>
<span style="color:#6b7280;">Placed {{ $order->placed_at?->format('j M Y, H:i') }}</span></p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 12px;">
@foreach ($items as $item)
<tr>
<td style="padding:6px 0;border-bottom:1px solid #f1f1f1;">{{ $item->name }}@if ($item->option_name && ! str_contains($item->option_name, $item->name)) <span style="color:#6b7280;">({{ $item->option_name }})</span>@endif<br><span style="color:#6b7280;">{{ $item->qty }} × {{ $money($item->unit_price) }}</span></td>
<td align="right" style="padding:6px 0;border-bottom:1px solid #f1f1f1;white-space:nowrap;">{{ $money($item->line_total) }}</td>
</tr>
@endforeach
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
<tr><td style="padding:2px 0;">Items</td><td align="right">{{ $money($totals['subtotal']) }}</td></tr>
@if ($totals['coupon_discount'] > 0)
<tr><td style="padding:2px 0;">Coupon {{ $totals['coupon_code'] }}</td><td align="right">−{{ $money($totals['coupon_discount']) }}</td></tr>
@endif
<tr><td style="padding:2px 0;">Delivery</td><td align="right">@if ($totals['delivery_fee_pending']) to be confirmed @elseif ((float) $totals['delivery_fee'] === 0.0) Free @else {{ $money($totals['delivery_fee']) }} @endif</td></tr>
<tr><td style="padding:6px 0 2px;font-weight:700;">Total</td><td align="right" style="padding:6px 0 2px;font-weight:700;">{{ $money($totals['grand_total']) }}</td></tr>
@if ($totals['tax_total'] > 0)
<tr><td colspan="2" style="color:#6b7280;font-size:13px;">{{ $totals['tax_inclusive'] ? 'Includes' : 'Plus' }} {{ $totals['tax_label'] }} {{ $money($totals['tax_total']) }}</td></tr>
@endif
</table>

<p style="margin:0 0 8px;"><strong>Payment:</strong> {{ $payLabel }}@if ($order->payment_status->value === 'paid') (paid)@endif</p>
@if (! empty($bank))
<div style="margin:0 0 12px;padding:12px;background:#f9fafb;border-radius:6px;">
<strong>Please pay into one of these accounts and upload a screenshot of the transfer on the order page.</strong>
@foreach ($bank['accounts'] as $a)
<p style="margin:8px 0 0;">{{ $a['bank'] }}@if ($a['title']) · {{ $a['title'] }}@endif<br>@if ($a['number']) Account: {{ $a['number'] }}<br>@endif @if ($a['iban']) IBAN: {{ $a['iban'] }} @endif</p>
@endforeach
@if ($bank['instructions'])<p style="margin:8px 0 0;">{{ $bank['instructions'] }}</p>@endif
</div>
@endif
@if ($estimate && ! in_array($order->status->value, ['delivered', 'cancelled', 'rejected'], true))
<p style="margin:0 0 8px;"><strong>Estimated delivery:</strong> {{ $estimate }}</p>
@endif
@php($address = $order->addresses->firstWhere('type', 'shipping'))
@if ($address)
<p style="margin:0 0 12px;"><strong>Delivery address:</strong> {{ collect([$address->line1, $address->area, $address->city, $address->postcode])->filter()->implode(', ') }}</p>
@endif
@if ($trackUrl)
<p style="margin:16px 0 0;">Follow your order: <a href="{{ $trackUrl }}" style="color:#2563eb;">{{ $trackUrl }}</a><br><span style="color:#6b7280;font-size:13px;">You will be asked for the phone number you ordered with.</span></p>
@else
<p style="margin:16px 0 0;color:#6b7280;">Keep your order number to follow your order.</p>
@endif

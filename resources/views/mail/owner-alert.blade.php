@extends('mail.layout')
@section('title', 'Alert')
@section('body')
@if ($kind === 'wholesale')
<p style="margin:0 0 12px;"><strong>New wholesale request {{ $about->reference }}</strong></p>
<p style="margin:0 0 12px;">{{ $about->quantity }} × {{ $about->product_name }}<br>
{{ $about->name }}@if ($about->business_name) · {{ $about->business_name }}@endif @if ($about->brn) · BRN {{ $about->brn }}@endif<br>
{{ $about->phone }}@if ($about->email) · {{ $about->email }}@endif</p>
@if ($about->message)<p style="margin:0 0 12px;padding:12px;background:#f9fafb;border-radius:6px;">{{ $about->message }}</p>@endif
<p style="margin:0;color:#6b7280;">Open the Wholesale screen of your admin panel to answer.</p>
@elseif ($kind === 'payment_proof')
<p style="margin:0 0 12px;"><strong>Payment proof to check: {{ $about->order_number }}</strong></p>
<p style="margin:0 0 12px;">{{ $about->customer_name }} · {{ $about->customer_phone }}<br>Total {{ $money($about->grand_total) }}</p>
<p style="margin:0;color:#6b7280;">Open the order in your admin panel to verify or reject the payment.</p>
@else
<p style="margin:0 0 12px;"><strong>New order {{ $about->order_number }}</strong></p>
<p style="margin:0 0 12px;">{{ $about->customer_name }} · {{ $about->customer_phone }}<br>{{ $about->items_count }} item(s) · Total {{ $money($about->grand_total) }} · {{ $about->payment_method === 'bank_transfer' ? 'Bank transfer' : 'Cash on delivery' }}</p>
<p style="margin:0;color:#6b7280;">Open the order in your admin panel to confirm it.</p>
@endif
@endsection
@section('footer')
Sent to the owner address of {{ $store }}. Change it in Settings → Notifications.
@endsection

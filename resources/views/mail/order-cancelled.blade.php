@extends('mail.layout')
@section('title', $rejected ? 'Order not accepted' : 'Order cancelled')
@section('body')
<p style="margin:0 0 16px;">@if ($rejected) We are sorry, we could not accept your order. @else Your order was cancelled. @endif @if (! empty($reason))<br><strong>Reason:</strong> {{ $reason }}@endif</p>
@if ($order->payment_status->value === 'paid')
<p style="margin:0 0 16px;">You already paid for this order: we will contact you about your refund.</p>
@endif
@include('mail.partials.order')
@endsection
@section('footer')
@include('mail.partials.footer')
@endsection

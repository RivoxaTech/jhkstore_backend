@extends('mail.layout')
@section('title', 'Order shipped')
@section('body')
<p style="margin:0 0 16px;">Your order is on its way.</p>
@if ($shipment)
<p style="margin:0 0 16px;padding:12px;background:#f9fafb;border-radius:6px;"><strong>Courier:</strong> {{ $shipment->carrier }}<br><strong>Tracking number:</strong> {{ $shipment->awb_number }}@if ($shipment->tracking_url)<br><a href="{{ $shipment->tracking_url }}" style="color:#2563eb;">Track the parcel</a>@endif</p>
@endif
@include('mail.partials.order')
@endsection
@section('footer')
@include('mail.partials.footer')
@endsection

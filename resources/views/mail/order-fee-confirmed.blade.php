@extends('mail.layout')
@section('title', 'Delivery fee confirmed')
@section('body')
<p style="margin:0 0 16px;">We confirmed the delivery fee of your order. Here is your final total.</p>
@include('mail.partials.order')
@endsection
@section('footer')
@include('mail.partials.footer')
@endsection

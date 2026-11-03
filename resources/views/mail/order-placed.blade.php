@extends('mail.layout')
@section('title', 'Order received')
@section('body')
<p style="margin:0 0 16px;">Thank you for your order. We received it and will confirm it shortly.</p>
@include('mail.partials.order')
@endsection
@section('footer')
@include('mail.partials.footer')
@endsection

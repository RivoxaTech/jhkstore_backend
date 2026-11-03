@extends('mail.layout')
@section('title', 'Order delivered')
@section('body')
<p style="margin:0 0 16px;">Your order was delivered. We hope you are happy with it. Thank you for shopping with us.</p>
@include('mail.partials.order')
@endsection
@section('footer')
@include('mail.partials.footer')
@endsection

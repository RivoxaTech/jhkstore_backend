@extends('mail.layout')
@section('title', 'Order confirmed')
@section('body')
<p style="margin:0 0 16px;">Good news: your order is confirmed and is being prepared.</p>
@include('mail.partials.order')
@endsection
@section('footer')
@include('mail.partials.footer')
@endsection

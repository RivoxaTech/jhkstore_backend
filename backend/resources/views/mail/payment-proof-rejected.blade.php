@extends('mail.layout')
@section('title', 'Payment proof not accepted')
@section('body')
<p style="margin:0 0 16px;">We could not accept the screenshot of your bank transfer@if (! empty($reason)): <strong>{{ $reason }}</strong>@else.@endif Please upload a new screenshot on your order page.</p>
@include('mail.partials.order')
@endsection
@section('footer')
@include('mail.partials.footer')
@endsection

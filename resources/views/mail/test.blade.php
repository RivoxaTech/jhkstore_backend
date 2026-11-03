@extends('mail.layout')
@section('title', 'Test e-mail')
@section('body')
<p style="margin:0 0 12px;"><strong>This is a test e-mail.</strong></p>
<p style="margin:0;">If you can read this, the mail settings of {{ $store }} work. Order e-mails to your customers use the same setup.</p>
@endsection
@section('footer')
Sent from Settings → Notifications.
@endsection

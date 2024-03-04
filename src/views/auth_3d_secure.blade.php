@extends('layouts.app')
@section('title', 'test')
@section('title_header', 'test')
@section('content')
    <h1>Authenticating 3D Secure</h1>
    <p>Redirecting...</p>
    <form id="3dsecure-form" action="{{ route('handle.3dsecure') }}" method="POST">
        @csrf
        <input type="hidden" name="payment_intent_id" value="{{ $paymentIntentId }}">
        <!-- Add any necessary fields for 3D Secure authentication -->
        <!-- For example, you might include a field for the one-time password -->
        <button type="submit">Complete Authentication</button>
    </form>
@endsection

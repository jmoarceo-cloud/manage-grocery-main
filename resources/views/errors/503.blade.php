@extends('layouts.base')
@section('title','Temporarily unavailable · GroceryOne')
@section('body')
<main id="main" class="challenge-page"><section class="challenge-card"><x-brand/><span class="eyebrow">PLEASE TRY AGAIN SHORTLY</span><h1>Taking a short pause.</h1><p>We cannot reach the database right now. Product availability and staff transactions will return when the connection is restored.</p><a class="button" href="{{ url()->current() }}">Try again</a></section></main>
@endsection

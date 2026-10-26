@extends('layouts.base')
@section('body')
<div class="utility-bar"><span>Your neighborhood essentials, thoughtfully brought together.</span><a href="{{ route('login') }}">Staff workspace &#8599;</a></div>
<header class="public-header"><x-brand/><nav aria-label="Main navigation"><a href="{{ route('home') }}" @class(['active'=>request()->routeIs('home')])>Home</a><a href="{{ route('catalog') }}" @class(['active'=>request()->routeIs('catalog','product')])>Our catalog</a><a href="{{ route('compare') }}" @class(['active'=>request()->routeIs('compare')])>Compare</a><a href="/manage-grocery-main/project/HRMS/apply">Careers</a></nav><a class="button button-small" href="{{ route('login') }}">Staff sign in <span>&#8594;</span></a></header>
<main id="main">@yield('content')</main>
<footer class="public-footer"><x-brand/><p>Everyday essentials. A little more care.</p><div><a href="{{ route('catalog') }}">Catalog</a><a href="/manage-grocery-main/project/HRMS/privacy_request">Privacy requests</a><a href="{{ route('login') }}">Staff sign in</a></div><small>&copy; {{ date('Y') }} HCI GroceryOne</small></footer>
@endsection

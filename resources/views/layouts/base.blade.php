<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#142d40"><meta name="description" content="GroceryOne product catalog and staff workspace."><title>@yield('title','GroceryOne')</title>@vite(['resources/css/app.css','resources/js/app.js'])</head><body>
<a href="#main" class="skip-link">Skip to content</a>
@yield('body')
</body></html>

@extends('layouts.workspace')
@section('title','Recovery codes')
@section('content')
<section class="panel form-panel"><span class="eyebrow">KEEP THESE PRIVATE</span><h1>Your sign-in safety net.</h1><p>Save these recovery codes somewhere secure. Each code works once, and this page shows them only after setup.</p><ul class="recovery-codes">@foreach($codes as $code)<li><code>{{ $code }}</code></li>@endforeach</ul><p class="muted">Your administrator will never ask you to share a code.</p><a class="button" href="{{ route('dashboard') }}">I have saved my codes &#8594;</a></section>
@endsection

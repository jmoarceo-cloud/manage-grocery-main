@extends('layouts.workspace')
@section('title','My schedule')
@section('content')
<div class="page-heading"><div><span class="eyebrow">YOUR WEEK, AT A GLANCE</span><h1>My schedule.</h1><p>Your recurring shifts and approved covering assignments. All times are in Asia/Manila.</p></div></div>
@if(!$schedule)
<section class="panel"><div class="empty-state"><h2>Your employee record needs to be linked.</h2><p>Ask HR or your administrator to link your employee record to this account.</p></div></section>
@else
@php($today=$schedule['upcoming'][0])
<div class="schedule-summary"><section class="panel schedule-today"><span class="eyebrow">TODAY &middot; {{ $today['label'] }}</span><h2>{{ $today['name'] }}</h2>@if($today['time'])<p class="schedule-time">{{ $today['time'] }}</p>@endif
@if($today['cover'])<span class="badge">Approved cover</span>@endif
@if($today['overnight'])<p class="schedule-note">Ends the following day.</p>@endif
@if($today['needs_review']||$today['inactive'])<p class="schedule-warning">{{ $today['inactive']?'This shift is inactive.':'Shift times need review.' }} Confirm your schedule with HR.</p>@endif
@if(!$schedule['configured']&&!$today['assigned'])<p class="schedule-note">A weekly schedule has not been set up yet. Check with HR before planning your shift.</p>@endif
</section><aside class="schedule-guide"><h2>Keep your plans in sync.</h2><p>Approved covers appear in the seven-day preview when you have no recurring shift that day. Contact HR to correct or change an assignment.</p><p>Leave, holidays and attendance records are managed separately. Use the attendance workflow to check whether you can clock in.</p></aside></div>
<section class="panel schedule-week"><div class="panel-heading"><div><h2>Recurring week</h2><p>Regular assignments, Monday through Sunday.</p></div></div>
@if(!$schedule['configured'])<p class="schedule-empty" role="status">No weekly schedule has been set up.</p>@endif
<div class="schedule-days" role="list" aria-label="Recurring weekly shifts">@foreach($schedule['weekly'] as $day)<article @class(['schedule-day','schedule-day-today'=>$day['today']]) role="listitem"><div class="schedule-day-label">{{ $day['day'] }}@if($day['today'])<span>Today</span>@endif</div><h3>{{ $day['name'] }}</h3>@if($day['time'])<p>{{ $day['time'] }}</p>@endif @if($day['overnight'])<small>Ends next day</small>@endif @if($day['needs_review']||$day['inactive'])<small class="schedule-warning">Confirm with HR</small>@endif</article>@endforeach</div></section>
<section class="panel schedule-upcoming"><div class="panel-heading"><div><h2>Next seven days</h2><p>Recurring shifts with approved covering assignments.</p></div></div><ol class="schedule-list" aria-label="Next seven days">@foreach($schedule['upcoming'] as $day)<li><div class="schedule-date"><time datetime="{{ $day['date'] }}">{{ $day['label'] }}</time>@if($day['today'])<small>Today</small>@endif</div><div class="schedule-assignment"><strong>{{ $day['name'] }}</strong><span>{{ $day['time'] ?? ($day['assigned']?'Times need confirmation':'') }}</span>@if($day['overnight'])<small>Ends the following day</small>@endif @if($day['inactive'])<small class="schedule-warning">Inactive shift &middot; confirm with HR</small>@endif</div>@if($day['cover'])<span class="badge">Approved cover</span>@elseif($day['assigned'])<span class="schedule-kind">Recurring</span>@endif</li>@endforeach</ol></section>
@endif
@endsection

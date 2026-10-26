@extends('layouts.workspace')
@section('title','Personal information')
@section('content')
<div class="page-heading"><div><span class="eyebrow">YOUR DETAILS, KEPT CURRENT</span><h1>Personal information.</h1><p>Review your contact details and the personal information linked to your staff account.</p></div></div>
@if(!$employee)
<section class="panel"><div class="empty-state"><h2>Your employee profile needs to be linked.</h2><p>Ask HR or your administrator to link your employee record to this account.</p><a class="button button-outline" href="{{ route('account.security') }}">Account security</a></div></section>
@else
<div class="profile-layout"><aside class="panel profile-employment"><div class="panel-heading"><h2>Your employee record</h2></div><dl class="profile-record"><dt>Name</dt><dd>{{ trim($employee->first_name.' '.($employee->middle_name??'').' '.$employee->last_name) }}</dd><dt>Employee code</dt><dd>{{ $employee->employee_code }}</dd><dt>Department</dt><dd>{{ $employee->department ?: 'Not recorded' }}</dd><dt>Position</dt><dd>{{ $employee->position ?: 'Not recorded' }}</dd><dt>Date hired</dt><dd>{{ $employee->date_hired }}</dd></dl><p class="profile-note">Contact HR to correct your employment details.</p></aside>
<section class="panel"><div class="panel-heading"><h2>Contact &amp; personal details</h2></div><form method="post" action="{{ route('profile.update') }}" class="form-panel profile-form" data-busy>
    @csrf
    <input type="hidden" name="revision" value="{{ $revision }}">
    <div class="form-grid"><label>Workplace contact email<input type="email" name="email" value="{{ $employee->email }}" maxlength="100" autocomplete="email" required></label><label>Repeat contact email<input type="email" name="email_confirmation" maxlength="100" autocomplete="off" required></label></div>
    <p class="form-note">Used for workplace correspondence; sign-in verification uses its enrolled address.</p>
    <div class="form-grid"><label>Contact country<select name="contact_number_country" data-profile-country aria-label="Contact country">@if($phoneCountry==='KEEP')<option value="KEEP" selected>Keep saved number</option>@endif @foreach(config('profile.countries') as $iso=>$country)<option value="{{ $iso }}" data-digits="{{ $country['length'] }}" @selected($phoneCountry===$iso)>+{{ $country['dial'] }} {{ $country['name'] }}</option>@endforeach</select></label><label>Contact number<input type="tel" name="contact_number" value="{{ $phoneNumber }}" maxlength="40" autocomplete="tel-national" inputmode="tel" aria-describedby="profile-phone-help"></label></div>
    <p id="profile-phone-help" class="form-note">Enter the number without its country code. <span data-profile-phone-length>Saved numbers are kept intact until you change them.</span></p>
    <div class="form-grid"><label>Date of birth<input type="date" name="date_of_birth" value="{{ $employee->birthdate }}" max="{{ now()->toDateString() }}" autocomplete="bday"></label><label>Place of birth<input name="place_of_birth" value="{{ $employee->place_of_birth }}" maxlength="150"></label>
    @foreach(['sex'=>['label'=>'Sex / gender','options'=>'sex'],'civil_status'=>['label'=>'Civil status','options'=>'civil_status'],'highest_educational_attainment'=>['label'=>'Highest educational attainment','options'=>'education']] as $field=>$setting)
        <label>{{ $setting['label'] }}<select name="{{ $field }}" aria-label="{{ $setting['label'] }}"><option value="">Not provided</option>@if($employee->$field&&!in_array($employee->$field,config('profile.'.$setting['options']),true))<option value="{{ $employee->$field }}" selected>{{ $employee->$field }} (saved value)</option>@endif @foreach(config('profile.'.$setting['options']) as $option)<option value="{{ $option }}" @selected($employee->$field===$option)>{{ $option }}</option>@endforeach</select></label>
    @endforeach
    <label>Year graduated<input name="year_graduated" value="{{ $employee->year_graduated }}" maxlength="4" inputmode="numeric" pattern="[0-9]{4}" placeholder="{{ now()->year }}"></label></div>
    <label>Home address<textarea name="home_address" rows="3" maxlength="1000" autocomplete="street-address">{{ $employee->address }}</textarea></label>
    <div class="profile-confirmation"><h3>Confirm this update</h3><label>Current password<input type="password" name="current_password" autocomplete="current-password" maxlength="1024" required></label><p class="form-note">If the update cannot be saved, the form reloads your saved details.</p><button class="button">Save personal information</button></div>
</form></section></div>
@endif
@endsection

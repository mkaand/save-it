@extends('admin.layout')
@section('title','Recover password')
@section('content')<main class="panel narrow"><h1>Recover password</h1><p class="muted">Enter the administrator email address. The response is identical whether or not it exists.</p><form method="post" action="{{ route('admin.password.email') }}">@csrf<label>Email</label><input name="email" type="email" required><div class="actions"><button class="button button-primary">Send reset link</button><a class="button" href="{{ route('admin.login') }}">Back</a></div></form></main>@endsection

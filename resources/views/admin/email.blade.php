@extends('admin.layout')
@section('title','Email')
@section('content')
<main class="panel">
    <h1>Email</h1>
    <p class="muted">Status: {{ $configured ? 'Configured' : 'Not configured' }}. Empty password keeps the stored secret.</p>
    <form method="post" action="{{ route('admin.email.update') }}">
        @csrf @method('PUT')
        <div class="grid">
            <div><label for="mail-host">SMTP host</label><input id="mail-host" name="host" value="{{ old('host', $mail['mail.host']) }}" required></div>
            <div><label for="mail-port">Port</label><input id="mail-port" name="port" type="number" min="1" max="65535" value="{{ old('port', $mail['mail.port'] ?: 587) }}" required></div>
            <div>
                <label for="mail-security">Connection security</label>
                <select id="mail-security" name="encryption" aria-describedby="mail-security-help">
                    @php($security = \App\Services\Settings\SmtpSecurity::mode(old('encryption', $mail['mail.encryption'])))
                    <option value="starttls" @selected($security === 'starttls')>STARTTLS (recommended, port 587)</option>
                    <option value="smtps" @selected($security === 'smtps')>Implicit TLS / SMTPS (port 465)</option>
                    <option value="plain" @selected($security === 'plain')>Plain SMTP (unencrypted)</option>
                    @if($security === 'legacy_auto')
                        <option value="legacy_auto" selected>Automatic TLS (legacy setting)</option>
                    @endif
                </select>
                <span id="mail-security-help" class="secret-note">STARTTLS requires a secure connection and verifies the server certificate. Plain SMTP disables TLS. Legacy automatic mode uses TLS when the server offers it.</span>
            </div>
            <div><label for="mail-username">Username</label><input id="mail-username" name="username" value="{{ old('username', $mail['mail.username']) }}"></div>
            <div><label for="mail-password">Password</label><input id="mail-password" name="password" type="password" autocomplete="new-password"><span class="secret-note">Stored encrypted and never displayed.</span></div>
            <div><label for="mail-from">From address</label><input id="mail-from" name="from_address" type="email" value="{{ old('from_address', $mail['mail.from_address']) }}" required></div>
            <div><label for="mail-name">From name</label><input id="mail-name" name="from_name" value="{{ old('from_name', $mail['mail.from_name']) }}" required></div>
        </div>
        <div class="action-grid"><button class="button button-primary">Save email</button></div>
    </form>
    <div class="action-grid"><form method="post" action="{{ route('admin.email.test') }}">@csrf<button class="button" type="submit">Send test email</button></form></div>
</main>
@endsection

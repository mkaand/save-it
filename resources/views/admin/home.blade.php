@extends('admin.layout')
@section('title','Dashboard')
@section('content')
<main class="panel">
    <p class="muted">Operations dashboard</p><h1>Save It administration</h1>
    <p class="muted">Signed in as {{ $admin->username }}. Aggregate metrics begin at deployment and never store raw visitor IP addresses or submitted URLs.</p>
    <div class="grid section">
        <div class="card"><h2>Analyses today</h2><p class="metric">{{ $metrics['analysesToday'] }}</p></div>
        <div class="card"><h2>Analyses 7 days</h2><p class="metric">{{ $metrics['analysesSevenDays'] }}</p></div>
        <div class="card"><h2>Analyses 30 days</h2><p class="metric">{{ $metrics['analysesMonth'] }}</p></div>
        <div class="card"><h2>Success rate</h2><p class="metric">{{ $metrics['month']['total'] ? round($metrics['month']['success'] * 100 / $metrics['month']['total']) : 0 }}%</p></div>
        <div class="card"><h2>Failed analyses</h2><p class="metric">{{ $metrics['failedAnalyses'] }}</p></div>
        <div class="card"><h2>Downloads</h2><p class="metric">{{ $metrics['downloads'] }}</p></div>
        <div class="card"><h2>Share preparations</h2><p class="metric">{{ $metrics['shares'] }}</p></div>
        <div class="card"><h2>Rate-limit hits</h2><p class="metric">{{ $metrics['rateLimits'] }}</p></div>
        <div class="card"><h2>Queue depth</h2><p class="metric">{{ $operations['queue']['queue_depth'] }}</p></div>
        <div class="card"><h2>Failed jobs</h2><p class="metric">{{ $operations['queue']['failed_jobs'] }}</p></div>
        <div class="card"><h2>Runtime storage</h2><p class="metric">{{ number_format(collect($operations['storage'])->sum('size') / 1048576, 1) }} MiB</p></div>
    </div>
    <section class="section"><h2>Provider status</h2><div class="grid">
        @foreach($providerStatus as $provider=>$status)
            <div class="card"><h3>{{ $provider === 'x' ? 'X' : ucfirst($provider) }}</h3><p class="muted">{{ $status['enabled'] ? 'Enabled' : 'Disabled' }} · {{ $status['success'] }} successes · {{ $status['failed'] }} errors</p><p class="muted">Rate: {{ $status['success_rate'] === null ? 'No activity yet' : $status['success_rate'].'%' }}@if($status['last_error']) · Last error: {{ $status['last_error'] }}@endif</p></div>
        @endforeach
    </div></section>
    <div class="grid section">
        <a class="card" href="{{ route('admin.analytics') }}"><h2>Analytics</h2><span class="muted">Usage, provider, error and country aggregates</span></a>
        <a class="card" href="{{ route('admin.operations') }}"><h2>Operations</h2><span class="muted">Runtime storage and safe cleanup</span></a>
        <a class="card" href="{{ route('admin.reports') }}"><h2>Reports</h2><span class="muted">Scheduled operational email reports</span></a>
        <a class="card" href="{{ route('admin.geoip') }}"><h2>GeoIP</h2><span class="muted">Privacy-preserving country source</span></a>
    </div>
</main>
@endsection

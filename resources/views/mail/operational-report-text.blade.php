Save It Operational Report
{{ $report['period'] }} · {{ $report['status'] }}
{{ $report['period_start'] }} – {{ $report['period_end'] }} ({{ $report['timezone'] }}, hourly aggregate boundaries)
Generated {{ $report['generated'] }}

Recorded operations: {{ $report['total'] }}
Successes: {{ $report['success'] }} | Errors: {{ $report['errors_count'] }} | Success rate: {{ $report['success_rate'] }}
Analyses: {{ $report['analyses'] }} | Downloads: {{ $report['downloads'] }}
Share preparations: {{ $report['share_preparations'] }} | Rate-limit hits: {{ $report['rate_limits'] }}
Operations are not unique visitors or total HTTP requests. Controlled errors are not an uptime measurement.

Providers:
@forelse($report['providers'] as $item)
{{ $item['label'] }}: {{ $item['count'] }} ({{ $item['success'] }} successful, {{ $item['errors'] }} errors, {{ $item['rate'] }} success)
@empty
No aggregate activity recorded.
@endforelse

Top errors:
@forelse($report['errors'] as $item)
{{ $item['label'] }}: {{ $item['count'] }}
@empty
No errors recorded in this period.
@endforelse

Top countries:
@forelse($report['countries'] as $item)
{{ $item['label'] }}: {{ $item['count'] }}
@empty
No aggregate country data recorded.
@endforelse

Current operations snapshot:
Queue depth: {{ $report['queue_depth'] }} | Failed jobs: {{ $report['failed_jobs'] }}
@foreach($report['storage'] as $item)
{{ $item['label'] }}: {{ $item['size'] }}, {{ $item['count'] }} files
@endforeach
Total runtime storage: {{ $report['total_storage'] }}, {{ $report['total_files'] }} files

Aggregate metrics only. Analytics do not retain raw visitor IP addresses, submitted URLs, media URLs or tokens.

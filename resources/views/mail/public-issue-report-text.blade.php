Save It issue report

Reported by: {{ $report['email'] }}

Message:
{{ $report['message'] }}
@if(filled($report['submitted_url'] ?? null))

Submitted URL: {{ $report['submitted_url'] }}
@endif
@if(filled($report['provider'] ?? null))
Provider: {{ $report['provider'] }}
@endif
@if(filled($report['error_code'] ?? null))
Public error code: {{ $report['error_code'] }}
@endif
@if(filled($report['request_id'] ?? null))
Correlation ID: {{ $report['request_id'] }}
@endif

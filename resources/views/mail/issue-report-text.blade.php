Save It issue report

Email: {{ $report['email'] }}
Message:
{{ $report['message'] }}

@if(isset($report['submitted_url']))
Request context
Submitted URL: {{ $report['submitted_url'] }}
@if(isset($report['provider']))
Provider: {{ $report['provider'] }}
@endif
@if(isset($report['error_code']))
Error code: {{ $report['error_code'] }}
@endif
@if(isset($report['request_id']))
Request ID: {{ $report['request_id'] }}
@endif
@endif
Submitted: {{ $report['submitted_at'] }}

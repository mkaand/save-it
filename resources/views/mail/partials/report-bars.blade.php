@forelse($items as $item)
<tr><td style="padding:10px 0; border-bottom:1px solid #edf1f5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; table-layout:fixed;">
        <tr><td style="font-size:14px; color:#223047; overflow-wrap:anywhere; word-break:break-word;">{{ $item['label'] }}</td><td width="72" align="right" style="font-size:14px; font-weight:bold; color:#10213c;">{{ number_format($item['count']) }}</td></tr>
        @if(isset($item['success']))
        <tr><td colspan="2" style="padding-top:4px; font-size:12px; color:#68758a;">{{ number_format($item['success']) }} successful · {{ number_format($item['errors']) }} errors · {{ $item['rate'] }} success</td></tr>
        @endif
        <tr><td colspan="2" style="padding-top:8px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#edf1f5" style="width:100%; background:#edf1f5; border-radius:4px;">
                <tr>
                    @if($item['percent'] > 0)
                    <td width="{{ $item['percent'] }}%" height="6" bgcolor="{{ $barColor ?? '#4f6fe8' }}" style="font-size:0; line-height:6px; border-radius:4px;">&nbsp;</td>
                    @endif
                    @if($item['percent'] < 100)
                    <td height="6" style="font-size:0; line-height:6px;">&nbsp;</td>
                    @endif
                </tr>
            </table>
        </td></tr>
    </table>
</td></tr>
@empty
<tr><td style="padding:12px 0; color:#68758a; font-size:14px;">{{ $empty ?? 'No aggregate activity recorded for this period.' }}</td></tr>
@endforelse

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Completion</title>
</head>
<body style="margin:0;padding:24px;background:#f5f7fb;font-family:Arial,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;padding:24px;">
                    <tr>
                        <td>
                            <h2 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;">
                                @if($auditRun->status === 'success')
                                    Your audit is complete
                                @else
                                    Your audit has finished
                                @endif
                            </h2>

                            <p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;">
                                Business: <strong>{{ $auditRun->business_name ?: 'N/A' }}</strong>
                            </p>

                            <p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;">
                                Status: <strong>{{ strtoupper($auditRun->status) }}</strong>
                            </p>

                            @if($auditRun->status === 'success' && $auditRun->reputation_score !== null)
                                <p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;">
                                    Reputation Score: <strong>{{ $auditRun->reputation_score }}</strong>
                                </p>
                            @endif

                            @if($auditRun->status !== 'success' && $auditRun->error_message)
                                <p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;">
                                    Details: {{ $auditRun->error_message }}
                                </p>
                            @endif

                            @if(!empty($frontendUrl))
                                <p style="margin:20px 0 0 0;">
                                    <a href="{{ $frontendUrl }}/audit-history" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:8px;font-size:14px;">
                                        Open Audit History
                                    </a>
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

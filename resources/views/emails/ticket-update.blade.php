{{--
    ONE reusable email template for every ticket event (sent by App\Mail\TicketUpdateMail).
    Inline styles are used because many email clients ignore <style> blocks.
    Only basic ticket information is included; details require logging in.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $eventTitle }}</title>
</head>
<body style="margin:0; padding:0; background:#f5f6fa; font-family: 'Segoe UI', Arial, sans-serif; color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6fa; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%; background:#ffffff; border:1px solid #e5e7eb; border-radius:12px;">
                <tr>
                    <td style="padding:20px 28px; border-bottom:1px solid #e5e7eb;">
                        <span style="display:inline-block; background:#4f46e5; color:#ffffff; font-weight:bold; border-radius:6px; padding:4px 9px;">RD</span>
                        <span style="font-size:18px; font-weight:bold; margin-left:8px;">ResolveDesk</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <p style="margin:0 0 16px;">Hello {{ $recipientName }},</p>
                        <h1 style="font-size:20px; margin:0 0 8px;">{{ $eventTitle }}</h1>
                        <p style="margin:0 0 20px; color:#4b5563;">{{ $eventMessage }}</p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
                            <tr>
                                <td style="padding:10px 14px; color:#6b7280; width:120px;">Ticket</td>
                                <td style="padding:10px 14px; font-weight:bold;">{{ $ticketNumber }}</td>
                            </tr>
                            <tr>
                                <td style="padding:10px 14px; color:#6b7280;">Subject</td>
                                <td style="padding:10px 14px;">{{ $ticketSubject }}</td>
                            </tr>
                            <tr>
                                <td style="padding:10px 14px; color:#6b7280;">Status</td>
                                <td style="padding:10px 14px;">{{ $statusLabel }}</td>
                            </tr>
                        </table>

                        <p style="margin:24px 0 0;">
                            <a href="{{ $ticketUrl }}" style="display:inline-block; background:#4f46e5; color:#ffffff; text-decoration:none; padding:10px 18px; border-radius:6px; font-weight:bold;">View ticket</a>
                        </p>
                        <p style="margin:16px 0 0; font-size:12px; color:#9ca3af;">You need to sign in to view the ticket details.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 28px; border-top:1px solid #e5e7eb; font-size:12px; color:#9ca3af;">
                        This is an automated message from ResolveDesk. Please do not reply to this email.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

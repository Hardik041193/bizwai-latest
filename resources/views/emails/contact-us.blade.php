<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 24px; }
        .header { background: #1d4ed8; color: #fff; padding: 16px 24px; border-radius: 8px 8px 0 0; }
        .body { background: #f9fafb; padding: 24px; border: 1px solid #e5e7eb; border-radius: 0 0 8px 8px; }
        .field { margin-bottom: 16px; }
        .label { font-size: 12px; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
        .value { font-size: 15px; margin-top: 4px; }
        .message-box { background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; white-space: pre-wrap; }
        .footer { font-size: 12px; color: #9ca3af; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0">New Contact Form Submission</h2>
        </div>
        <div class="body">
            <div class="field">
                <div class="label">Name</div>
                <div class="value">{{ $data['name'] }}</div>
            </div>
            <div class="field">
                <div class="label">Email</div>
                <div class="value"><a href="mailto:{{ $data['email'] }}">{{ $data['email'] }}</a></div>
            </div>
            @if(!empty($data['user_id']))
            <div class="field">
                <div class="label">User ID</div>
                <div class="value">#{{ $data['user_id'] }}</div>
            </div>
            @endif
            <div class="field">
                <div class="label">Subject</div>
                <div class="value">{{ $data['subject'] }}</div>
            </div>
            <div class="field">
                <div class="label">Message</div>
                <div class="message-box">{{ $data['message'] }}</div>
            </div>
            <div class="footer">
                Submitted at {{ now()->format('D, d M Y H:i:s T') }}<br>
                Reply directly to this email to respond to {{ $data['name'] }}.
            </div>
        </div>
    </div>
</body>
</html>
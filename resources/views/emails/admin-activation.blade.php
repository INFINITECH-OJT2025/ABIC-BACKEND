<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate Your Admin Account</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #5f0c18;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #5f0c18;
            margin-bottom: 10px;
        }
        .title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }
        .content {
            margin-bottom: 30px;
        }
        .button {
            display: inline-block;
            background-color: #7a0f1f;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            margin: 20px 0;
        }
        .button:hover {
            background-color: #5f0c18;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">ABIC Accounting System</div>
            <h1 class="title">Admin Account Activation</h1>
        </div>

        <div class="content">
            <p>Hello <strong>{{ $adminName }}</strong>,</p>

            <p>Your admin account has been created in the ABIC Accounting System. To activate your account and set your password, please click the button below:</p>

            <div style="text-align: center;">
                <a href="{{ $activationUrl }}" class="button">Activate Your Account</a>
            </div>

            <div class="warning">
                <strong>Important:</strong> This activation link will expire in 24 hours. If you don't activate your account within this time, you'll need to contact your system administrator.
            </div>

            <p>If the button above doesn't work, you can copy and paste this link into your browser:</p>
            <p style="word-break: break-all; background-color: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 12px;">
                {{ $activationUrl }}
            </p>

            <p>After activation, you will be able to:</p>
            <ul>
                <li>Set your password</li>
                <li>Access the admin dashboard</li>
                <li>Manage system settings</li>
                <li>Oversee user accounts</li>
            </ul>
        </div>

        <div class="footer">
            <p>If you did not request this account creation, please contact our support team immediately at <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
            <p>&copy; {{ date('Y') }} ABIC Accounting System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>

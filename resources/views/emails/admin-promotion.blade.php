<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Promotion Notification</title>
    <style>
        body {
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: white;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #7a0f1f;
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
            margin-bottom: 10px;
        }
        .highlight {
            background-color: #f0f9ff;
            border: 1px solid #7a0f1f;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
            color: #333;
        }
        .login-button {
            display: inline-block;
            background-color: #7a0f1f;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
            text-align: center;
        }
        .login-button:hover {
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">{{ $appName }}</div>
            <h1 class="title">Admin Role Promotion</h1>
        </div>

        <p>Hello <strong>{{ $userName }}</strong>,</p>

        <p>We are pleased to inform you that you have been promoted to the role of <strong>Admin</strong> in {{ $appName }}.</p>

        <div class="highlight">
            <strong>What this means for you:</strong><br>
            You now have administrative access to the system. You can log in with your existing credentials and access admin features and permissions.
        </div>

        <p style="text-align: center;">
            <a href="{{ $loginUrl }}" class="login-button">
                Log in to Your Admin Account
            </a>
        </p>

        <p>If the button above doesn't work, you can copy and paste this URL into your browser:</p>
        <p style="word-break: break-all; background-color: #f8f9fa; padding: 10px; border-radius: 4px;">
            {{ $loginUrl }}
        </p>

        <div class="footer">
            <p>This is an automated notification from {{ $appName }}. Please do not reply to this email.</p>
            <p>If you have any questions about your new role, please contact your system administrator.</p>
        </div>
    </div>
</body>
</html>

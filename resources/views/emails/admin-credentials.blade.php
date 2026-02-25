<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Account Credentials</title>
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
        .credentials {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .credential-item {
            margin-bottom: 15px;
        }
        .credential-label {
            font-weight: 600;
            color: #5f0c18;
            margin-bottom: 5px;
        }
        .credential-value {
            font-size: 16px;
            padding: 10px;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
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
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
            color: #856404;
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
            <h1 class="title">Admin Account Credentials</h1>
        </div>

        <p>Hello <strong>{{ $adminName }}</strong>,</p>

        <p>Your admin account has been created successfully. Below are your login credentials:</p>

        <div class="credentials">
            <div class="credential-item">
                <div class="credential-label">Email Address:</div>
                <div class="credential-value">{{ $adminEmail }}</div>
            </div>
            
            <div class="credential-item">
                <div class="credential-label">Temporary Password:</div>
                <div class="credential-value">{{ $password }}</div>
            </div>
        </div>

        <div class="warning">
            <strong>⚠️ Important Security Notice:</strong><br>
            <strong>Email Verification:</strong> Your email address will be automatically verified when you log in with these credentials for the first time. This login serves as your email verification.<br><br>
            <strong>Password Change Required:</strong> You will be required to change your password upon first login for security purposes. Please change it to a strong, unique password that you can remember.
        </div>

        <p style="text-align: center;">
            <a href="{{ $loginUrl }}" class="login-button">
                Login to Your Account
            </a>
        </p>

        <p>If the button above doesn't work, you can copy and paste this URL into your browser:</p>
        <p style="word-break: break-all; background-color: #f8f9fa; padding: 10px; border-radius: 4px;">
            {{ $loginUrl }}
        </p>

        <div class="footer">
            <p>This is an automated message from {{ $appName }}. Please do not reply to this email.</p>
            <p>If you did not request this account, please contact your system administrator immediately.</p>
        </div>
    </div>
</body>
</html>

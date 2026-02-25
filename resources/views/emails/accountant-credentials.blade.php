<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Account Credentials</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #800020 0%, #4B0000 50%, #2D0000 100%);
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
            margin: -30px -30px 30px -30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .logo {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .credentials-box {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .credential-item {
            margin: 10px 0;
            padding: 10px;
            background: white;
            border-radius: 5px;
            border-left: 4px solid #800020;
        }
        .credential-label {
            font-weight: bold;
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .credential-value {
            font-size: 16px;
            color: #333;
            word-break: break-all;
        }
        .password {
            font-family: 'Courier New', monospace;
            background: #fff3cd;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #ffeaa7;
            font-weight: bold;
        }
        .login-button {
            display: inline-block;
            background: linear-gradient(135deg, #800020, #4B0000);
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin: 20px 0;
            transition: all 0.3s ease;
        }
        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(128, 0, 32, 0.3);
        }
        .expiration-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
            color: #856404;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">{{ $companyName }}</div>
            <h1>Account Credentials</h1>
        </div>

        <p>Dear {{ $accountantName }},</p>

        <p>Welcome to {{ $companyName }}! Your accountant account has been created successfully. Below are your login credentials:</p>

        <div class="credentials-box">
            <h3>🔐 Your Login Information</h3>
            
            <div class="credential-item">
                <div class="credential-label">Email Address</div>
                <div class="credential-value">{{ $accountantEmail }}</div>
            </div>

            <div class="credential-item">
                <div class="credential-label">Temporary Password</div>
                <div class="credential-value password">{{ $password }}</div>
            </div>
        </div>

        <div style="text-align: center;">
            <a href="{{ $loginUrl }}" class="login-button">
                🚀 Login to Your Account
            </a>
        </div>

        <div class="expiration-warning">
            <h4>⏰ Important: Password Expiration</h4>
            <p><strong>Your temporary password will expire in 30 minutes.</strong> Please login immediately and set your own permanent password.</p>
            <p>If you don't login within 30 minutes, you'll need to contact your administrator for new credentials.</p>
        </div>

        <div class="expiration-warning">
            <h4>🔒 First Login Requirement</h4>
            <p>After your first login, you will be required to change your password to a permanent one of your choice.</p>
        </div>

        <p>If you have any questions or need assistance, please contact your system administrator.</p>

        <div class="footer">
            <p>
                {{ $companyName }}<br>
                This is an automated message. Please do not reply to this email.<br>
                © {{ date('Y') }} {{ $companyName }}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
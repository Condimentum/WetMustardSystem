<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In - {{ config('app.name', 'WetMustardSystem') }}</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #00453d 0%, #8ab826 100%);
            color: #1f2937;
        }

        .login-container {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.28);
            padding: 50px 40px;
            max-width: 430px;
            width: 100%;
        }

        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-logo img {
            max-width: 220px;
            width: 100%;
            height: auto;
        }

        h1 {
            margin: 0;
            text-align: center;
            color: #333;
            font-size: 1.8rem;
        }

        .login-subtitle {
            text-align: center;
            color: #4b5563;
            font-size: 0.95rem;
            margin: 10px 0 30px;
        }

        .btn-microsoft {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            padding: 15px;
            background: #2f2f2f;
            color: #fff;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            margin-top: 10px;
        }

        .btn-microsoft:hover {
            background: #111;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.22);
        }

        .btn-microsoft img {
            width: 22px;
            height: 22px;
        }

        .alert {
            margin-top: 16px;
            border-radius: 10px;
            padding: 10px 12px;
            border: 1px solid #fecaca;
            background: #fee2e2;
            color: #991b1b;
            font-size: 0.9rem;
        }

        .login-footer {
            text-align: center;
            color: #6b7280;
            font-size: 0.85rem;
            margin-top: 30px;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 34px 24px;
                border-radius: 16px;
            }

            h1 {
                font-size: 1.55rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <img src="{{ asset('assets/condimentum-logo.png') }}" alt="Condimentum Logo">
        </div>

        <h1>Welcome Back</h1>
        <p class="login-subtitle">{{ config('app.name', 'WetMustardSystem') }}</p>

        <a href="{{ route('auth.microsoft.redirect') }}" class="btn-microsoft">
            <img src="https://learn.microsoft.com/en-us/entra/identity-platform/media/howto-add-branding-in-apps/ms-symbollockup_mssymbol_19.png" alt="Microsoft">
            Sign in with Microsoft
        </a>

        @if (session('auth_error'))
            <div class="alert">{{ session('auth_error') }}</div>
        @endif

        <p class="login-footer">&copy; {{ now()->year }} Condimentum Ltd | All Rights Reserved</p>
    </div>
</body>
</html>

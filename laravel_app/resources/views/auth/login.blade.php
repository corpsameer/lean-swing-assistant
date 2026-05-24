<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f3f4f6; color: #111827; }
        main { max-width: 420px; margin: 60px auto; padding: 24px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
        h1 { margin: 0 0 8px; font-size: 28px; }
        p { margin: 0 0 16px; color: #4b5563; }
        label { display: block; margin-top: 12px; font-weight: 600; font-size: 14px; }
        input { width: 100%; padding: 10px; margin-top: 6px; border: 1px solid #d1d5db; border-radius: 6px; box-sizing: border-box; }
        button { margin-top: 16px; width: 100%; background: #2563eb; color: #fff; border: 0; padding: 10px; border-radius: 6px; cursor: pointer; }
        .error { margin-top: 12px; color: #b91c1c; font-size: 14px; }
    </style>
</head>
<body>
    <main>
        <h1>Login</h1>
        <p>Sign in to access admin pages.</p>

        <form method="POST" action="{{ url('/login') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

            <label for="password">Password</label>
            <input id="password" type="password" name="password" required>

            <button type="submit">Sign In</button>
        </form>

        @error('email')
            <div class="error">{{ $message }}</div>
        @enderror
    </main>
</body>
</html>

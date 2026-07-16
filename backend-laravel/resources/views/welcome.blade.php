<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'GH PIK2 API') }}</title>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background:#0f172a; color:#e2e8f0; display:flex; min-height:100vh; align-items:center; justify-content:center; margin:0; }
        .card { text-align:center; }
        h1 { font-weight:600; margin-bottom:.25rem; }
        code { background:#1e293b; padding:.2rem .45rem; border-radius:.35rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ config('app.name', 'GH PIK2 API') }}</h1>
        <p>Backend is running. API base path: <code>/api</code></p>
    </div>
</body>
</html>

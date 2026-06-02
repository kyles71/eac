<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $version->title }}</title>
    <style>
        body {
            color: #111827;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.6;
            margin: 0;
            background: #f9fafb;
        }

        main {
            max-width: 760px;
            margin: 0 auto;
            padding: 48px 24px;
            background: #ffffff;
            min-height: 100vh;
        }

        .document-meta {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 24px;
        }

        button {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #ffffff;
            padding: 8px 12px;
            font-weight: 600;
            cursor: pointer;
        }

        @media print {
            body,
            main {
                background: #ffffff;
            }

            main {
                padding: 0;
                max-width: none;
            }

            button {
                display: none;
            }
        }
    </style>
</head>
<body>
    <main>
        <button type="button" onclick="window.print()">Print</button>

        <h1>{{ $version->title }}</h1>

        <div class="document-meta">
            {{ $version->document?->name }} &middot; Version {{ $version->version }} &middot; Published {{ $version->published_at->format('M j, Y') }}
        </div>

        {!! $version->content !!}
    </main>
</body>
</html>

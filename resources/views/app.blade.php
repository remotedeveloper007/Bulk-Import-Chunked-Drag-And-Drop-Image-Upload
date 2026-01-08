<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        <!-- Styles / Scripts -->

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: #f4f6f9; }
            .card { border-radius: 12px; }
            .drop-zone {
                border: 2px dashed #0d6efd;
                padding: 40px;
                text-align: center;
                background: #fff;
                cursor: pointer;
            }
            .drop-zone.dragover {
                background: #e9f2ff;
            }
        </style>

    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">

            <div class="container py-5">
                <!-- Page Content -->
                <main class="pt-16">
                    @yield('content')
                </main>
            </div>
        </div>
        
        @stack('script')
    </body>
</html>

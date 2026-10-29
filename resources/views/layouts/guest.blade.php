<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' — '.config('app.name', 'WMS') : config('app.name', 'WMS') }}</title>

    <link rel="icon" href="{{ asset('favicon-32x32.png') }}" type="image/png" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">


    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-ink-50 font-sans text-ink-700 antialiased">
{{-- Halaman masuk bergaya ERPNext: logo, judul, dan satu kartu formulir di tengah. --}}
<div class="flex min-h-full flex-col items-center justify-center px-4 py-10">
    <div class="w-full max-w-[400px] animate-fade-in">
        <div class="mb-6 flex flex-col items-center text-center">
            <x-application-logo variant="full" class="h-28 w-auto" />
            @if ($heading)
                <h1 class="mt-5 text-xl font-semibold text-ink-950">{{ $heading }}</h1>
            @endif
            @if ($subheading)
                <p class="mt-1 text-sm text-ink-500">{{ $subheading }}</p>
            @endif
        </div>

        <div class="rounded-xl border border-ink-100 bg-white p-6 shadow-sm sm:p-8">
            {{ $slot }}
        </div>

        <p class="mt-6 text-center text-xs text-ink-400">
            {{ config('app.name', 'CheeryChic') }} &middot; Warehouse Management System &middot; &copy; {{ date('Y') }}
        </p>
    </div>
</div>
</body>
</html>

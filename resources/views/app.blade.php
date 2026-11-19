<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="dark">
        <meta name="theme-color" content="#0c0e14">
        <meta name="description" content="GameVault: a portfolio demo of a digital game-key marketplace with concurrency-safe checkout and reliable fulfillment. Fictional games, simulated payments.">

        <title inertia>{{ config('app.name', 'GameVault') }}</title>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600|sora:500,600,700,800|jetbrains-mono:500&display=swap" rel="stylesheet" />

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="min-h-screen bg-background font-sans antialiased">
        @inertia
    </body>
</html>

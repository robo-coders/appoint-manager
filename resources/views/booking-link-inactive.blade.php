<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Link no longer active</title>
        @include('partials.head')
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-paper p-6 font-sans text-14 text-ink antialiased">
        <main class="mx-auto max-w-md rounded border border-rule bg-white p-6">
            <h1 class="font-display text-17 font-medium">This booking link is no longer active</h1>
            <p class="mt-2 text-13 text-ink-2">
                Links expire once an appointment has been and gone, and a new one is sent with every
                confirmation. If you were expecting to change an appointment, the salon can do it for you.
            </p>
        </main>
    </body>
</html>

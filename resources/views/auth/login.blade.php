<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Anmeldung · Dokumentenprüfung</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="min-h-screen bg-slate-100 text-slate-900 grid place-items-center p-6"><main class="max-w-md w-full bg-white p-8 rounded-xl shadow-sm space-y-6">
<p class="text-sm font-semibold text-blue-700">KI-DOKUMENTENPRÜFUNG</p><h1 class="text-2xl font-bold">Willkommen</h1><p>Dokumente extrahieren, gemeinsam prüfen und freigeben.</p>
@if($errors->any())<p role="alert" class="text-red-700">{{ $errors->first() }}</p>@endif
<a href="{{ route('entra.redirect') }}" class="block rounded bg-blue-700 px-4 py-3 text-white text-center">Mit Microsoft anmelden</a>
@if($demoUsers->isNotEmpty())<div class="border-t pt-4"><p class="text-sm mb-3">Lokale Entwicklungsanmeldung</p>@foreach($demoUsers as $user)<form method="post" action="{{ route('development.login') }}" class="mb-2">@csrf<input type="hidden" name="user_id" value="{{ $user->id }}"><button class="w-full border rounded p-2">Als {{ $user->role->value }} anmelden</button></form>@endforeach</div>@endif
</main></body></html>

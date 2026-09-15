<?php

namespace App\Http\Controllers;

use App\Auth\ValidateEntraClaims;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Microsoft\Provider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class AuthController extends Controller
{
    private function provider(): Provider
    {
        abort_unless(Str::isUuid(config('services.microsoft.tenant')) && Str::isUuid(config('services.microsoft.client_id')) && config('services.microsoft.client_secret'), 503, 'Entra ist noch nicht eingerichtet.');
        $provider = Socialite::driver('microsoft');
        assert($provider instanceof Provider);

        return $provider->setRequest(request())->enablePKCE();
    }

    public function redirect(Request $request): SymfonyRedirectResponse
    {
        $nonce = Str::random(64);
        $request->session()->put('entra_nonce', $nonce);

        return $this->provider()->with(['nonce' => $nonce, 'response_mode' => 'query'])->redirect();
    }

    public function callback(Request $request, ValidateEntraClaims $validator): RedirectResponse
    {
        $nonce = $request->session()->pull('entra_nonce');
        try {
            abort_if($request->has('error'), 401);
            $provider = $this->provider();
            $external = $provider->user();
            $identity = $validator->handle($provider->getClaims(), $nonce);
            $user = User::where('entra_tenant_id', $identity['tenant'])->where('entra_object_id', $identity['object'])->first() ?? new User;
            $user->forceFill(['entra_tenant_id' => $identity['tenant'], 'entra_object_id' => $identity['object']]);
            $user->fill(['name' => $external->getName() ?: 'Entra-Benutzer', 'email' => $external->getEmail()]);
            $user->save();
            $user->refresh();
        } catch (\Throwable) {
            Log::notice('Entra callback rejected');

            return redirect()->route('login')->withErrors(['login' => 'Anmeldung fehlgeschlagen. Bitte erneut beginnen.']);
        }
        if (! $user->active) {
            return redirect()->route('login')->withErrors(['login' => 'Ihr Konto wartet auf Aktivierung durch einen Administrator.']);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/admin');
    }

    public function development(Request $request): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']) && config('development.login'), 404);
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $user = User::where('is_demo', true)->where('active', true)->findOrFail((int) $data['user_id']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/admin');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

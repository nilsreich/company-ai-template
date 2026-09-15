<?php

namespace App\Auth;

use Illuminate\Support\Str;
use RuntimeException;

final class ValidateEntraClaims
{
    /** @return array{tenant: string, object: string} */
    public function handle(?\stdClass $claims, mixed $nonce): array
    {
        $tenant = config()->string('services.microsoft.tenant');
        $client = config()->string('services.microsoft.client_id');
        if (! $claims || ! Str::isUuid($tenant) || ! Str::isUuid($client) || ! is_string($nonce) || $nonce === '' ||
            ($claims->tid ?? null) !== $tenant || ($claims->iss ?? null) !== 'https://login.microsoftonline.com/'.$tenant.'/v2.0' ||
            ($claims->aud ?? null) !== $client || ! is_string($claims->nonce ?? null) || ! hash_equals($nonce, $claims->nonce) ||
            ! is_int($claims->exp ?? null) || $claims->exp <= time() || ! is_int($claims->nbf ?? null) || $claims->nbf > time() + 60 ||
            ! is_string($claims->oid ?? null) || ! Str::isUuid($claims->oid)) {
            throw new RuntimeException('Ungültige Entra-Anmeldung.');
        }

        return ['tenant' => $tenant, 'object' => $claims->oid];
    }
}

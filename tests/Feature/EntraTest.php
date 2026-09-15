<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EntraTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const CLIENT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const OBJECT = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private function configureProvider(array $overrides = [], bool $badSignature = false): void
    {
        config(['services.microsoft' => ['tenant' => self::TENANT, 'client_id' => self::CLIENT, 'client_secret' => 'test-fixture-only', 'redirect' => 'http://localhost/auth/entra/callback', 'include_tenant_info' => false]]);
        Socialite::forgetDrivers();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);
        $claims = [...['tid' => self::TENANT, 'oid' => self::OBJECT, 'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0', 'aud' => self::CLIENT, 'nonce' => 'test-nonce', 'iat' => time(), 'nbf' => time() - 10, 'exp' => time() + 600, 'roles' => ['admin']], ...$overrides];
        if ($badSignature) {
            openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $pem);
        }
        $jwt = JWT::encode($claims, $pem, 'RS256', 'test-key');
        $responses = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'fixture-token', 'id_token' => $jwt])),
            new Response(200, [], json_encode(['id' => self::OBJECT, 'displayName' => 'Updated name', 'mail' => 'new@example.test', 'userPrincipalName' => 'new@example.test'])),
            new Response(200, [], json_encode(['issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0', 'jwks_uri' => 'https://login.microsoftonline.com/fixture/keys', 'id_token_signing_alg_values_supported' => ['RS256']])),
            new Response(200, [], json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256', 'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])]]])),
        ]);
        $provider = Socialite::driver('microsoft');
        $provider->setHttpClient(new Client(['handler' => HandlerStack::create($responses)]));
    }

    private function performCallback(string $state = 'state'): TestResponse
    {
        $this->withSession(['state' => 'state', 'entra_nonce' => 'test-nonce', 'code_verifier' => 'verifier']);
        // Socialite captures the Request when constructed; bind the incoming request at resolution.
        $provider = Socialite::driver('microsoft');
        $this->app->resolving('request', fn ($request) => $provider->setRequest($request));

        return $this->get('/auth/entra/callback?code=fixture-code&state='.$state);
    }

    public function test_first_login_creates_inactive_editor_with_stable_identity(): void
    {
        $this->configureProvider();
        $this->performCallback()->assertRedirect('/login');
        $this->assertGuest();
        $user = User::sole();
        $this->assertFalse($user->active);
        $this->assertSame(Role::Editor, $user->role);
        $this->assertSame(self::OBJECT, $user->entra_object_id);
    }

    public function test_profile_update_preserves_role_and_identity(): void
    {
        $user = User::factory()->create(['entra_tenant_id' => self::TENANT, 'entra_object_id' => self::OBJECT, 'role' => Role::Reviewer]);
        $this->configureProvider();
        $this->performCallback()->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(Role::Reviewer, $user->refresh()->role);
        $this->assertSame('new@example.test', $user->email);
        $this->assertSame(1, User::count());
    }

    public function test_authorization_redirect_uses_tenant_state_nonce_and_pkce(): void
    {
        $this->configureProvider();
        $response = $this->get('/auth/entra');
        $url = $response->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($query['state']);
        $this->assertSame(session('entra_nonce'), $query['nonce']);
    }

    public function test_first_administrator_requires_cli_and_is_audited(): void
    {
        config(['services.microsoft.tenant' => self::TENANT]);
        $this->artisan('app:bootstrap-admin', ['object-id' => self::OBJECT])->assertSuccessful();
        $user = User::sole();
        $this->assertTrue($user->active);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertDatabaseHas('audit_entries', ['action' => 'admin_bootstrapped_via_cli']);
        $this->artisan('app:bootstrap-admin', ['object-id' => self::OBJECT])->assertFailed();
    }

    public static function invalidClaims(): array
    {
        return [
            'tenant' => [['tid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd']],
            'audience substring' => [['aud' => 'prefix-'.self::CLIENT]],
            'issuer' => [['iss' => 'https://evil.example']], 'expired' => [['exp' => 1]],
            'future' => [['nbf' => PHP_INT_MAX]], 'nonce' => [['nonce' => 'wrong']], 'missing object' => [['oid' => null]],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_claims_reject_login_without_provisioning(array $claims): void
    {
        $this->configureProvider($claims);
        $this->performCallback()->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->configureProvider([], true);
        $this->performCallback()->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_state_mismatch_is_rejected(): void
    {
        $this->configureProvider();
        $this->performCallback('wrong')->assertRedirect('/login');
        $this->assertSame(0, User::count());
    }

    public function test_nonce_is_consumed_after_failed_callback(): void
    {
        $this->configureProvider();
        $this->performCallback('wrong');
        $this->assertNull(session('entra_nonce'));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use Tests\TestCase;

class TelescopeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Telescope::stopRecording();
        Telescope::flushEntries();
        parent::tearDown();
    }

    private function enableLocally(): void
    {
        $this->app->instance('env', 'local');
        config(['telescope.enabled' => true]);
        $this->app->register(AppServiceProvider::class, true);
        $this->artisan('migrate', ['--path' => 'database/migrations/local', '--force' => true])->assertSuccessful();
        Telescope::startRecording(false);
    }

    public function test_production_does_not_register_telescope_even_when_enabled_and_installed(): void
    {
        $this->app->instance('env', 'production');
        config(['telescope.enabled' => true]);
        $this->app->register(AppServiceProvider::class, true);
        $this->assertNull($this->app->getProvider(TelescopeServiceProvider::class));
        $this->get('/telescope')->assertNotFound();
        $this->post('/telescope/telescope-api/requests')->assertNotFound();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('telescope_entries'));
    }

    public function test_dashboard_and_internal_api_require_an_active_admin(): void
    {
        $this->enableLocally();
        $this->get('/telescope')->assertRedirect('/login');
        $user = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($user)->get('/telescope')->assertOk();
        $this->withSession(['_token' => 'fixture-token'])->post('/telescope/telescope-api/requests', ['_token' => 'fixture-token'])->assertOk();
        User::whereKey($user->id)->update(['role' => Role::Editor]);
        $this->get('/telescope')->assertForbidden();
        $this->post('/telescope/telescope-api/requests', ['_token' => 'fixture-token'])->assertForbidden();
        User::whereKey($user->id)->update(['role' => Role::Reviewer]);
        $this->get('/telescope')->assertForbidden();
        User::whereKey($user->id)->update(['role' => Role::Admin, 'active' => false]);
        $this->get('/telescope')->assertForbidden();
        $this->assertGuest();
    }

    public function test_stored_entries_contain_only_metadata_and_no_confidential_payloads(): void
    {
        $this->enableLocally();
        $secret = 'CONFIDENTIAL_TELESCOPE_FIXTURE';
        Route::get('/telemetry/{document}', fn () => response($secret));
        $user = User::factory()->create(['name' => $secret, 'email' => $secret.'@example.test']);
        $this->actingAs($user)->withHeader('Authorization', 'Bearer '.$secret)
            ->get('/telemetry/'.$secret.'?token='.$secret)->assertOk();
        Telescope::recordQuery(IncomingEntry::make([
            'sql' => "select '$secret'", 'bindings' => [$secret], 'time' => '1.25', 'connection' => 'pgsql', 'driver' => 'pgsql', 'slow' => false,
        ])->tags([$secret]));
        Telescope::recordClientRequest(IncomingEntry::make(['payload' => $secret]));
        Telescope::recordEvent(IncomingEntry::make(['payload' => $secret]));
        Telescope::store(app(EntriesRepository::class));
        Telescope::stopRecording();
        $entries = DB::table('telescope_entries')->get();
        $this->assertTrue($entries->contains('type', 'request'));
        $this->assertTrue($entries->contains('type', 'query'));
        $this->assertFalse($entries->contains('type', 'client_request'));
        $this->assertFalse($entries->contains('type', 'event'));
        $this->assertStringNotContainsString($secret, $entries->toJson());
        $this->assertStringContainsString('telemetry', $entries->toJson());
        $this->assertSame(0, DB::table('telescope_entries_tags')->count());
    }
}

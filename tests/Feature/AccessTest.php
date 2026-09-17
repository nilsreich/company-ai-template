<?php

namespace Tests\Feature;

use App\Actions\CorrectTask;
use App\Actions\UpdateUserAccess;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['editor', false, false], ['reviewer', true, false], ['admin', true, true]];
    }

    #[DataProvider('roles')]
    public function test_role_permissions(string $role, bool $review, bool $admin): void
    {
        $user = User::factory()->create(['role' => Role::from($role)]);
        $task = $this->upload($user);
        $gate = Gate::forUser($user);
        foreach (['view', 'download', 'update'] as $ability) {
            $this->assertTrue($gate->allows($ability, $task));
        }
        $this->assertFalse($gate->allows('export', $task));
        $task->update(['status' => TaskStatus::InReview]);
        $this->assertSame($review, $gate->allows('approve', $task));
        $this->assertSame($admin, $gate->allows('viewAny', User::class));
        $task->update(['status' => TaskStatus::Approved]);
        $this->assertSame($review, $gate->allows('export', $task));
        $this->assertFalse($gate->allows('update', $task));
    }

    public function test_private_endpoints_require_auth_and_export_requires_approval_and_role(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        foreach (['download', 'export', 'status'] as $route) {
            $this->get(route('tasks.'.$route, $task))->assertRedirect('/login');
        }
        $this->actingAs($user)->get(route('tasks.export', $task))->assertForbidden();
        $this->get('/storage/'.$task->path)->assertNotFound();
        $this->get('/admin/users')->assertForbidden();
        Livewire::test(ViewTask::class, ['record' => $task->id])->assertActionHidden('approve')->call('mountAction', 'approve')->call('callMountedAction');
        $this->assertSame(TaskStatus::Draft, $task->refresh()->status);
    }

    public function test_deactivated_existing_session_is_rejected(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        $this->actingAs($user)->get(route('tasks.status', $task))->assertOk();
        User::whereKey($user->id)->update(['active' => false]);
        $this->get(route('tasks.download', $task))->assertForbidden();
        $this->assertGuest();
    }

    public function test_role_change_is_effective_on_existing_livewire_session(): void
    {
        $user = User::factory()->create(['role' => Role::Reviewer]);
        $task = $this->upload($user);
        $task = app(CorrectTask::class)->handle($user, $task, 0, $this->taskPayload());
        $page = Livewire::actingAs($user)->test(ViewTask::class, ['record' => $task->id]);
        User::whereKey($user->id)->update(['role' => Role::Editor->value]);
        $page->call('mountAction', 'approve')->call('callMountedAction');
        $this->assertSame(TaskStatus::InReview, $task->refresh()->status);
    }

    public function test_development_login_is_unreachable_in_production_even_with_flag(): void
    {
        config(['development.login' => true]);
        $this->app->instance('env', 'production');
        $user = User::factory()->create(['is_demo' => true]);
        $this->withSession(['_token' => 'fixture-csrf'])->post('/auth/development', ['user_id' => $user->id, '_token' => 'fixture-csrf'])->assertNotFound();
        $this->assertGuest();
        $this->get('/login')->assertDontSee('Lokale Entwicklungsanmeldung');
    }

    public function test_local_login_requires_flag_and_demo_user(): void
    {
        $user = User::factory()->create();
        config(['development.login' => false]);
        $this->post('/auth/development', ['user_id' => $user->id])->assertNotFound();
        config(['development.login' => true]);
        $this->post('/auth/development', ['user_id' => $user->id])->assertNotFound();
        $user->forceFill(['is_demo' => true])->save();
        $this->post('/auth/development', ['user_id' => $user->id])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
    }

    public function test_last_administrator_cannot_be_disabled(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->expectException(ValidationException::class);
        app(UpdateUserAccess::class)->handle($admin, $admin, Role::Editor, false);
    }
}

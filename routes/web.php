<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\TaskController;
use App\Http\Middleware\EnsureActiveUser;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::get('/login', fn () => view('auth.login', ['demoUsers' => app()->environment(['local', 'testing']) && config('development.login') ? User::where('is_demo', true)->where('active', true)->get() : collect()]))->name('login');
Route::get('/auth/entra', [AuthController::class, 'redirect'])->middleware('throttle:20,1')->name('entra.redirect');
Route::get('/auth/entra/callback', [AuthController::class, 'callback'])->middleware('throttle:30,1')->name('entra.callback');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
if (app()->environment(['local', 'testing'])) {
    Route::post('/auth/development', [AuthController::class, 'development'])->middleware('throttle:20,1')->name('development.login');
}
Route::middleware(['auth', EnsureActiveUser::class])->group(function (): void {
    Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:5,1')->name('feedback.store');
    Route::get('/feedback/{feedback}/screenshot', [FeedbackController::class, 'screenshot'])->whereUuid('feedback')->name('feedback.screenshot');
    Route::get('/tasks/{task}/preview', [TaskController::class, 'preview'])->name('tasks.preview');
    Route::get('/tasks/{task}/download', [TaskController::class, 'download'])->name('tasks.download');
    Route::get('/tasks/{task}/export', [TaskController::class, 'export'])->name('tasks.export');
    Route::get('/tasks/{task}/status', [TaskController::class, 'status'])->name('tasks.status');
});

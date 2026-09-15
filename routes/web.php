<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
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
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/documents/{document}/export', [DocumentController::class, 'export'])->name('documents.export');
    Route::get('/documents/{document}/status', [DocumentController::class, 'status'])->name('documents.status');
});

<?php

use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\PortfolioController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/inquiries', [InquiryController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('api.inquiries.store');

Route::get('/v1/courses', [CourseController::class, 'index'])
    ->name('api.courses.index');

Route::get('/v1/courses/{course}', [CourseController::class, 'show'])
    ->name('api.courses.show');

// Website "Our Work" portfolio (published projects only).
Route::middleware('throttle:120,1')->prefix('v1/portfolio')->name('api.portfolio.')->group(function () {
    Route::get('categories', [PortfolioController::class, 'categories'])->name('categories');
    Route::get('projects', [PortfolioController::class, 'index'])->name('projects.index');
    Route::get('projects/{slug}', [PortfolioController::class, 'show'])->name('projects.show');
});

// Website blog (published posts only).
Route::middleware('throttle:120,1')->prefix('v1/blog')->name('api.blog.')->group(function () {
    Route::get('categories', [BlogController::class, 'categories'])->name('categories');
    Route::get('posts', [BlogController::class, 'index'])->name('posts.index');
    Route::get('posts/{slug}', [BlogController::class, 'show'])->name('posts.show');
});

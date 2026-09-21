<?php

use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\InquiryController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/inquiries', [InquiryController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('api.inquiries.store');

Route::get('/v1/courses', [CourseController::class, 'index'])
    ->name('api.courses.index');

Route::get('/v1/courses/{course}', [CourseController::class, 'show'])
    ->name('api.courses.show');

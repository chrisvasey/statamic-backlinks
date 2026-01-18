<?php

use Chrisvasey\StatamicBacklinks\Http\Controllers\BacklinksController;
use Illuminate\Support\Facades\Route;

Route::prefix('backlinks')->group(function () {
    Route::get('search', [BacklinksController::class, 'search'])->name('backlinks.search');
    Route::get('{entry}', [BacklinksController::class, 'show'])->name('backlinks.show');
    Route::post('create', [BacklinksController::class, 'create'])->name('backlinks.create');
});

<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\MumineenController;
use App\Http\Controllers\MumineenSabeelController;
use App\Http\Controllers\EstablishmentController;
use App\Http\Controllers\EstablishmentSabeelController;
use App\Http\Controllers\CounterController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\DepositsController;
use App\Http\Controllers\YearController;
use App\Http\Controllers\MumineenEstablishmentController;
use App\Http\Controllers\DashboardController;

Route::post('/register', [UserController::class, 'create']);

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum', 'role:admin,user')->group(function () {

    // dashboard route
    Route::prefix('dashboard')->group(function () {
        Route::get('/retrieve', [DashboardController::class, 'retrieve']);
        Route::post('/retrieve_sabeel_due', [DashboardController::class, 'retrieveSabeelDue']);
        Route::post('/export', [DashboardController::class, 'exportEstablishment']);
    });

    // users route
    Route::prefix('users')->group(function () {
        Route::post('/create', [UserController::class, 'create']);
        Route::post('/retrieve/{id?}', [UserController::class, 'fetch']);
        Route::post('/update/{id}', [UserController::class, 'edit']);
        Route::delete('/delete/{id}', [UserController::class, 'delete']);
        Route::post('/reset_password', [AuthController::class, 'updatePassword']);
        // Route::post('/export', [UserController::class, 'exportExcel']);
        Route::post('/change_password', [UserController::class, 'updatePassword']);
    });

    // mumineen route
    Route::prefix('family')->group(function () {
        Route::post('/create', [MumineenController::class, 'create']);
        Route::post('/retrieve/{id?}', [MumineenController::class, 'fetch']);
        Route::post('/update/{family_id}', [MumineenController::class, 'edit']);
        Route::delete('/delete/{id}', [MumineenController::class, 'delete']);
        Route::post('/export', [MumineenController::class, 'export']);
        Route::get('/sync_image', [MumineenController::class, 'syncAllPhotos']);
    });

    // mumineen-sabeel route
    Route::prefix('family_sabeel')->group(function () {
        Route::post('/create/{family_id}', [MumineenSabeelController::class, 'create']);
        Route::post('/retrieve/{family_id}/{id?}', [MumineenSabeelController::class, 'fetch']);
        Route::post('/update/{family_id}/{id}', [MumineenSabeelController::class, 'edit']);
        Route::delete('/delete/{family_id}/{id}', [MumineenSabeelController::class, 'delete']);
    });

        // get unique sectors
        Route::get('/sector', [MumineenController::class, 'index']);

        Route::get('/family_details/{family_id}/retrieve/{id?}', [MumineenController::class, 'fetch_family_details']);

    // establishment route
    Route::prefix('establishment')->group(function () {
        Route::post('/create', [EstablishmentController::class, 'create']);
        Route::post('/retrieve/{id?}', [EstablishmentController::class, 'fetch']);
        Route::post('/update/{establishment_id}', [EstablishmentController::class, 'edit']);
        Route::delete('/delete/{id}', [EstablishmentController::class, 'delete']);
    });

    // establishment-partner route
    Route::prefix('partners')->group(function () {
        Route::post('/create/{establishment_id}', [MumineenEstablishmentController::class, 'create']);
        Route::post('/retrieve/{establishment_id}/{id?}', [MumineenEstablishmentController::class, 'fetch']);
        Route::post('/update/{id}', [MumineenEstablishmentController::class, 'edit']);
        Route::delete('/delete/{id}', [MumineenEstablishmentController::class, 'delete']);
    });

        Route::get('/establishment_details/overview/{establishment_id}/retrieve/{id?}', [EstablishmentController::class, 'fetch_establishment_details']);

    // establishment-sabeel route
    Route::prefix('establishment_sabeel')->group(function () {
        Route::post('/create/{establishment_id}', [EstablishmentSabeelController::class, 'create']);
        Route::post('/retrieve/{establishment_id}/{id?}', [EstablishmentSabeelController::class, 'fetch']);
        Route::post('/update/{establishment_id}/{id}', [EstablishmentSabeelController::class, 'edit']);
        Route::delete('/delete/{establishment_id}/{id}', [EstablishmentSabeelController::class, 'delete']);
    });

    // counter route
    Route::prefix('counter')->group(function () {
        Route::post('/create', [CounterController::class, 'create']);
        Route::post('/retrieve/{id?}', [CounterController::class, 'fetch']);
        Route::post('/update/{id}', [CounterController::class, 'edit']);
        Route::delete('/delete/{id}', [CounterController::class, 'delete']);
    });

    // receipt route
    Route::prefix('receipt')->group(function () {
        Route::post('/create', [ReceiptController::class, 'create']);
        Route::post('/retrieve/{id?}', [ReceiptController::class, 'fetch']);
        Route::post('/update/{id}', [ReceiptController::class, 'edit']);
        Route::delete('/delete/{id}', [ReceiptController::class, 'delete']);
        Route::post('/export', [ReceiptController::class, 'export']);
    });

    // deposits route
    Route::prefix('deposits')->group(function () {
        Route::post('/create', [DepositsController::class, 'create']);
        Route::post('/retrieve/{id?}', [DepositsController::class, 'fetch']);
        Route::post('/update/{id}', [DepositsController::class, 'edit']);
        Route::delete('/delete/{id}', [DepositsController::class, 'delete']);
        Route::post('/export', [DepositsController::class, 'export']);
    });

    
    // year route
    Route::prefix('year')->group(function () {
        Route::post('/create', [YearController::class, 'create']);
        Route::post('/retrieve/{id?}', [YearController::class, 'fetch']);
        Route::post('/update/{id}', [YearController::class, 'edit']);
        Route::delete('/delete/{id}', [YearController::class, 'delete']);
    });

    Route::post('/logout', [AuthController::class, 'logout']);
});

<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DatabaseBackupController;




/**
 * Add these routes in your web.php file.
 */
Route::get('google/login',[DatabaseBackupController::class,'googleLogin'])->name('google.login');
Route::get('/take-database-backup', [DatabaseBackupController::class,'takeBackUp'])->name('take-database-backup');
?>
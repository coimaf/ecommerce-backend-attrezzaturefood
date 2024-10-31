<?php

use Illuminate\Support\Facades\Route;

Route::get( '/', function () { 
    return response()->json([
        'E-commerce' => "Attrezzature Food"
    ]); 
} );
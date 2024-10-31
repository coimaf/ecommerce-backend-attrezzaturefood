<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LogController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\BrandsController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\CategoriesController;

// Rotte prodotti
Route::get('/products', [ProductsController::class, 'getProducts']); // Carica i prodotti su prestahop
Route::get('/products-details', [ProductsController::class, 'getProductsDetails']); // Carica i prodotti senza immagini su prestahop
Route::get('/products-images', [ProductsController::class, 'getProductsImages']); // Carica le immagini dei prodotti su prestahop
Route::get('/products-stocks', [ProductsController::class, 'getProductsStocks']); // Carica le giacenze dei prodotti su prestahop

// Rotte Marche
Route::get('/brands', [BrandsController::class, 'getBrandsArca']); // Carica le marche su prestashop

// Rotte Categorie
Route::get('/categories', [CategoriesController::class, 'getCategories']); // Ottiene una lista di Categorie e sottocategorie in JSON
Route::get('/categories/upload', [CategoriesController::class, 'uploadCategoriesToPrestaShop']); // Carica le categorie e sottocategorie su Prestashop

// Rotte Clienti
Route::post('/customer/arca', [CustomersController::class, 'uploadArcaCustomers']); // Importa i clienti con ordini e indirizzi associati da Prestashop ad Arca

// Errori
Route::get('/logs/error', [LogController::class, 'getErrorLog']);

//! TEST
Route::get('/test', [TestController::class, 'test']);
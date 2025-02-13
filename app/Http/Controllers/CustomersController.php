<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ImportCustomersToArca;
use App\Services\GuzzleService;

class CustomersController extends Controller
{
    public function uploadArcaCustomers(GuzzleService $guzzleService)
    {
        ImportCustomersToArca::dispatch($guzzleService)->onQueue('attrezzaturefood');
        return response()->json([
            'message' => 'Job per importazione clienti inviato con successo!'
        ], 201);
    }

}
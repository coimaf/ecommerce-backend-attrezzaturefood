<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class LogController extends Controller
{
    public function getErrorLog()
    {
        $filePath = storage_path('logs/attrezzaturefood_stocks_errors.txt');

        // Controlla se il file esiste
        if (!File::exists($filePath)) {
            return response()->json([
                'status' => 'noError',
            ]);
        }

        // Leggi il contenuto del file
        $content = File::get($filePath);
        
        // Elimina il file dopo aver letto il contenuto
        File::delete($filePath);

        return response()->json([
            'status' => 'success',
            'data' => nl2br(e($content)) // Formattazione per i nuovi ritorni a capo
        ]);
    }   
}

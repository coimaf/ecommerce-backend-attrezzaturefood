<?php

namespace App\Services;

use GuzzleHttp\Client;

class GuzzleService
{
    public function getClient()
    {
        return new Client([
            'base_uri' => config('services.prestashop.api_url'),
            'verify' => false, //! Abilitare la verifica SSL in produzione
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(config('services.prestashop.api_key') . ':'),
                'Content-Type' => 'text/xml'
            ]
        ]);
    }
}

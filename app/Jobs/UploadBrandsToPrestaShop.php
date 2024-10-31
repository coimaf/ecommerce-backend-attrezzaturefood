<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\GuzzleService;

class UploadBrandsToPrestaShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $data;
    protected $guzzleService;

    public function __construct($data)
    {
        $this->data = $data;
        $this->guzzleService = app(GuzzleService::class);
    }

    public function handle(): void
    {
        $client = $this->guzzleService->getClient();
    
        $existingBrands = $this->getPrestashopBrands($client);
        $arcaBrandNames = array_column($this->data['brands']->toArray(), 'Descrizione');
    
        foreach ($this->data['brands'] as $brand) {
            $found = array_search($brand->Descrizione, array_column($existingBrands, 'name'));
    
            try {
                if ($found !== false) {
                    // La marca esiste, effettua un aggiornamento
                    $brandId = $existingBrands[$found]['id'];
                    $xmlPayload = $this->generateBrandsXML($brand, $brandId);
                    $response = $client->request('PUT', "manufacturers/$brandId", ['body' => $xmlPayload]);
                    Log::info("Marca aggiornata in Prestashop: {$brand->Descrizione}");
                } else {
                    // La marca non esiste, crea una nuova
                    $xmlPayload = $this->generateBrandsXML($brand);
                    if (!empty($brand->Descrizione)) {
                        $response = $client->request('POST', 'manufacturers', ['body' => $xmlPayload]);
                        Log::info("Marca aggiunta a Prestashop: {$brand->Descrizione}");
                    }
                }
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                Log::error("Errore nella gestione della marca: {$brand->Descrizione}", [
                    'error' => $e->getMessage(),
                    'response_body' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body'
                ]);
            }
        }    
    
        // Elimina le marche che non esistono più in Arca
        $prestashopBrandNames = array_column($existingBrands, 'name');
        foreach ($existingBrands as $existingBrand) {
            if (!in_array($existingBrand['name'], $arcaBrandNames)) {
                try {
                    $client->request('DELETE', "manufacturers/{$existingBrand['id']}");
                    Log::info("Marca eliminata da Prestashop: {$existingBrand['name']}");
                } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                    Log::error("Errore nell'eliminazione della marca: {$existingBrand['name']}", [
                        'error' => $e->getMessage(),
                        'brand_id' => $existingBrand['id']
                    ]);
                }
            }
        }
    }
    
    public function getPrestashopBrands($client)
    {
        try {
            $response = $client->request('GET', 'manufacturers', [
                'query' => [
                    'output_format' => 'JSON',
                    'display' => '[id,name]' // Chiedi esplicitamente per 'id' e 'name'
                ]
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true); // Decodifica JSON in array PHP

            return $data['manufacturers'] ?? [];
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            Log::error("Errore nel recupero delle marche da PrestaShop: " . $e->getMessage());
            return [];
        }
    }

    private function generateBrandsXML($brand, $brandId = null)
    {
        $xml = new \SimpleXMLElement('<prestashop/>');
        $xmlBrand = $xml->addChild('manufacturer');
        if ($brandId) {
            $xmlBrand->addChild('id', $brandId);
        }
        $xmlBrand->addChild('active', '1');
        $xmlBrand->addChild('name', htmlspecialchars($brand->Descrizione));
        
        return $xml->asXML();
    }
}

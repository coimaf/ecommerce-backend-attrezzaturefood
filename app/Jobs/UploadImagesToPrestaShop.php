<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\GuzzleService;

class UploadImagesToPrestaShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $data;
    protected $guzzleService;
    protected $errorLogFile;

    public function __construct($data, GuzzleService $guzzleService)
    {
        $this->data = $data;
        $this->guzzleService = $guzzleService;
        $this->errorLogFile = storage_path('logs/prestashop_errors.txt'); // Path to the error log file
    }
    
    public function handle()
    {
        $client = $this->guzzleService->getClient();

        try {
            $prestashopProducts = $this->getAllPrestaShopProducts($client);

            foreach ($this->data['products'] as $product) {
                $productId = $prestashopProducts[$product->Cd_AR];
                $newImages = $this->data['images'][$product->Cd_AR];
                $this->manageProductImages($productId, $newImages, $client);
            }
        } catch (\Exception $e) {
            $this->logError("Errore durante l'invio delle immagini a PrestaShop", $e);
            $this->fail($e);
        }
    }

    public function failed(\Throwable $exception)
    {
        $this->logError('Il job UploadImagesToPrestaShop è fallito.', $exception);
    }

    private function getAllPrestaShopProducts($client)
    {
        try {
            $response = $client->request('GET', 'products', [
                'query' => ['display' => '[id,reference]']
            ]);
            $products = new \SimpleXMLElement($response->getBody());
            $prestashopProducts = [];
            foreach ($products->products->product as $product) {
                $prestashopProducts[(string) $product->reference] = (string) $product->id;
            }

            return $prestashopProducts;
        } catch (GuzzleException $e) {
            $this->logError("Errore nel recupero dei prodotti PrestaShop", $e);
            return [];
        }
    }

    private function manageProductImages($productId, $newImages, $client)
    {
        $existingImages = [];

        // Tenta di recuperare le immagini esistenti
        try {
            $existingImagesResponse = $client->request('GET', "images/products/$productId");
            $existingImages = new \SimpleXMLElement($existingImagesResponse->getBody());
        } catch (GuzzleException $e) {
            if ($e->getCode() == 404) {
                Log::info("Non sono state trovate immagini per il prodotto: $productId. Procedo ad aggiungere altre immagini.");
            } else {
                $this->logError('Errore nel recupero delle immagini esistenti in Prestashop per eliminarle.', $e, $productId);
                return; // Interrompi il codice
            }
        }

        // Procedi a rimuovere le immagini esistenti, se presenti
        if (!empty($existingImages->image)) {
            // Elimina le vecchie immagini
            foreach ($existingImages->image->declination as $image) {
                $imageId = (string)$image['id'];
                try {
                    $client->request('DELETE', "images/products/$productId/{$imageId}");
                } catch (GuzzleException $e) {
                    $this->logError("Eliminazione immagine fallita.", $e, $productId, $imageId);
                }
            }
        }

        // Carica nuove immagini
        foreach ($newImages as $imageUrl) {
            try {
                $response = $client->request('POST', "images/products/$productId/", [
                    'multipart' => [
                        [
                            'name'     => 'image',
                            'contents' => fopen($imageUrl, 'r'),
                            'filename' => basename($imageUrl)
                        ]
                    ]
                ]);
                Log::info('Immagine aggiunta con successo.', [
                    'product_id' => $productId, 
                    'image_url' => $imageUrl,
                    'status_code' => $response->getStatusCode()
                ]);
            } catch (GuzzleException $e) {
                $this->logError('Caricamento immagine fallito.', $e, $productId, $imageUrl);
            }
        }
    }

    private function logError($message, $exception, $productId = null, $imageId = null)
    {
        $errorData = [
            'error' => $exception->getMessage(),
        ];
    
        if ($productId) {
            $errorData['product_id'] = $productId;
        }
    
        if ($imageId) {
            $errorData['image_id'] = $imageId;
        }
    
        Log::error($message, $errorData);
    
        // Aggiungi l'errore al file di log
        $errorLog = date('Y-m-d H:i:s') . " - $message - " . json_encode($errorData) . PHP_EOL;
        file_put_contents(storage_path('logs/prestashop_errors.txt'), $errorLog, FILE_APPEND);
    }
    
}

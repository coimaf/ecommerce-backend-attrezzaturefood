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
    protected $logFile;
    protected $errorLogFile;

    public function __construct($data, GuzzleService $guzzleService)
    {
        $this->data = $data;
        $this->guzzleService = $guzzleService;
        $this->logFile = $this->generateLogFileName(); // File giornaliero per i log
        $this->errorLogFile = storage_path('logs/prestashop_errors.txt'); // File per gli errori
    }
    
    public function handle()
    {
        $this->logMessage('Inizio sincronizzazione immagini su PrestaShop.');

        $client = $this->guzzleService->getClient();

        try {
            $prestashopProducts = $this->getAllPrestaShopProducts($client);
            $this->logMessage('Prodotti recuperati da PrestaShop.', ['count' => count($prestashopProducts)]);

            foreach ($this->data['products'] as $product) {
                $this->logMessage("Inizio gestione immagini per prodotto.", ['Cd_AR' => $product->Cd_AR]);
                
                $productId = $prestashopProducts[$product->Cd_AR] ?? null;

                if ($productId) {
                    $newImages = $this->data['images'][$product->Cd_AR] ?? [];
                    $this->manageProductImages($productId, $newImages, $client);
                } else {
                    $this->logMessage("Prodotto non trovato in PrestaShop.", ['Cd_AR' => $product->Cd_AR]);
                }
            }
        } catch (\Exception $e) {
            $this->logError("Errore durante l'invio delle immagini a PrestaShop", $e);
            $this->fail($e);
        }

        $this->logMessage('Fine sincronizzazione immagini su PrestaShop.');
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

        // Recupera le immagini esistenti
        try {
            $existingImagesResponse = $client->request('GET', "images/products/$productId");
            $existingImages = new \SimpleXMLElement($existingImagesResponse->getBody());
        } catch (GuzzleException $e) {
            if ($e->getCode() == 404) {
                $this->logMessage("Non sono state trovate immagini per il prodotto.", ['product_id' => $productId]);
            } else {
                $this->logError('Errore nel recupero delle immagini esistenti in Prestashop.', $e, $productId);
                return;
            }
        }

        // Elimina immagini esistenti
        if (!empty($existingImages->image)) {
            foreach ($existingImages->image->declination as $image) {
                $imageId = (string)$image['id'];
                try {
                    $client->request('DELETE', "images/products/$productId/{$imageId}");
                    $this->logMessage("Immagine eliminata.", ['product_id' => $productId, 'image_id' => $imageId]);
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
                $this->logMessage('Immagine aggiunta con successo.', [
                    'product_id' => $productId, 
                    'image_url' => $imageUrl,
                    'status_code' => $response->getStatusCode()
                ]);
            } catch (GuzzleException $e) {
                $this->logError('Caricamento immagine fallito.', $e, $productId, $imageUrl);
            }
        }
    }

    private function generateLogFileName()
    {
        $basePath = storage_path('logs');
        $date = now()->format('Y-m-d');
        $fileBaseName = "$basePath/prestashop_images_job_log_$date";

        $filePath = "$fileBaseName.txt";
        $counter = 1;

        while (file_exists($filePath)) {
            $filePath = "$fileBaseName($counter).txt";
            $counter++;
        }

        return $filePath;
    }

    private function logMessage($message, $data = null)
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message";
        if ($data) {
            $logEntry .= " | " . json_encode($data);
        }
        file_put_contents($this->logFile, $logEntry . PHP_EOL, FILE_APPEND);
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

        $errorLog = date('Y-m-d H:i:s') . " - $message - " . json_encode($errorData) . PHP_EOL;
        file_put_contents($this->errorLogFile, $errorLog, FILE_APPEND);
        $this->logMessage($message, $errorData); // Registra anche nel log giornaliero
    }
}

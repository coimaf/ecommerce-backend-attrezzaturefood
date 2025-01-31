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
use Exception;

class UploadProductsStocksToPrestaShop implements ShouldQueue
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
        $this->logFile = $this->generateLogFileName(); // Genera file log giornaliero
        $this->errorLogFile = storage_path('logs/prestashop_stocks_errors.txt'); // Log errori separato
    }

    public function handle()
    {
        $this->logMessage('Inizio sincronizzazione stock su PrestaShop.');

        $client = $this->guzzleService->getClient();

        try {
            $prestashopProducts = $this->getAllPrestaShopProducts($client);
            $this->logMessage('Prodotti recuperati da PrestaShop.', ['count' => count($prestashopProducts)]);
        } catch (Exception $e) {
            $this->logError("Errore nel recupero dei prodotti PrestaShop", $e);
            return;
        }

        foreach ($this->data['products'] as $product) {
            try {
                $this->logMessage("Inizio aggiornamento stock per prodotto.", ['Cd_AR' => $product->Cd_AR]);

                if (!isset($prestashopProducts[$product->Cd_AR])) {
                    throw new Exception("ID prodotto non trovato in PrestaShop per: {$product->Cd_AR}");
                }

                $productId = $prestashopProducts[$product->Cd_AR];
                $quantity = number_format($product->Giacenza);

                if ($quantity) {
                    $this->updateStock($productId, $quantity, $client);
                    $this->logMessage("Stock aggiornato con successo.", [
                        'Cd_AR' => $product->Cd_AR,
                        'quantity' => $quantity,
                    ]);
                } else {
                    $this->logMessage("Stock aggiornato con successo.", [
                        'Cd_AR' => $product->Cd_AR,
                        'quantity' => 0,
                    ]);
                }
            } catch (Exception $e) {
                $this->logError("Aggiornamento giacenza fallito per il prodotto: {$product->Cd_AR}", $e, $product->Cd_AR);
            }
        }

        $this->logMessage('Fine sincronizzazione stock su PrestaShop.');
    }

    private function getAllPrestaShopProducts($client)
    {
        $response = $client->request('GET', 'products', [
            'query' => ['display' => '[id,reference]']
        ]);

        $this->logMessage('Recupero prodotti PrestaShop completato.');

        $products = new \SimpleXMLElement($response->getBody());
        $prestashopProducts = [];
        foreach ($products->products->product as $product) {
            $prestashopProducts[(string) $product->reference] = (string) $product->id;
        }

        return $prestashopProducts;
    }

    private function updateStock($productId, $quantity, $client)
    {
        try {
            $response = $client->request('GET', 'stock_availables', [
                'query' => ['filter[id_product]' => $productId, 'display' => 'full']
            ]);
            $stockAvailables = new \SimpleXMLElement($response->getBody());
    
            if (!empty($stockAvailables->stock_availables->stock_available)) {
                $stockAvailable = $stockAvailables->stock_availables->stock_available;
                $stockAvailableId = (string)$stockAvailable->id;

                $xml = new \SimpleXMLElement('<prestashop/>');
                $stockAvailableNode = $xml->addChild('stock_available');
                $stockAvailableNode->addChild('id', $stockAvailableId);
                $stockAvailableNode->addChild('id_product', $productId);
                $stockAvailableNode->addChild('quantity', $quantity);
                $stockAvailableNode->addChild('id_product_attribute', '0');
                $stockAvailableNode->addChild('depends_on_stock', '0');
                $stockAvailableNode->addChild('out_of_stock', '2');
                $stockAvailableNode->addChild('id_shop', '1');
    
                $client->request('PUT', "stock_availables/$stockAvailableId", [
                    'body' => $xml->asXML()
                ]);
    
                $this->logMessage("Stock aggiornato su PrestaShop.", [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ]);
            } else {
                throw new Exception('No stock available found for the product.');
            }
        } catch (GuzzleException $e) {
            $this->logError('Errore nel recupero o aggiornamento dello stock', $e, $productId);
        }
    }

    private function generateLogFileName()
    {
        $basePath = storage_path('logs');
        $date = now()->format('Y-m-d');
        $fileBaseName = "$basePath/prestashop_stock_job_log_$date";

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

    private function logError($message, $exception, $productId = null)
    {
        $errorData = [
            'error' => $exception->getMessage(),
        ];

        if ($productId) {
            $errorData['product_id'] = $productId;
        }

        Log::error($message, $errorData);

        $errorLog = date('Y-m-d H:i:s') . " - $message - " . json_encode($errorData) . PHP_EOL;
        file_put_contents($this->errorLogFile, $errorLog, FILE_APPEND);
        $this->logMessage($message, $errorData); // Registra anche nel log generale
    }
}

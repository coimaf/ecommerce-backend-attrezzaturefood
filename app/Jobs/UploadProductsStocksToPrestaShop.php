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
    protected $errorLogFile;

    public function __construct($data, GuzzleService $guzzleService)
    {
        $this->data = $data;
        $this->guzzleService = $guzzleService;
        $this->errorLogFile = storage_path('logs/prestashop_stocks_errors.txt');
    }

    public function handle()
    {
        $client = $this->guzzleService->getClient();

        try {
            $prestashopProducts = $this->getAllPrestaShopProducts($client);
        } catch (Exception $e) {
            $this->logError("Errore nel recupero dei prodotti PrestaShop", $e);
            return;
        }

        foreach ($this->data['products'] as $product) {
            try {
                if (!isset($prestashopProducts[$product->Cd_AR])) {
                    throw new Exception("Product ID not found in PrestaShop for product: {$product->Cd_AR}");
                }
                $productId = $prestashopProducts[$product->Cd_AR];
                $quantity = number_format($product->Giacenza);

                if ($quantity) {
                    $this->updateStock($productId, $quantity, $client);
                }
            } catch (Exception $e) {
                $this->logError("Aggiornamento giacenza fallito per il prodotto: {$product->Cd_AR}", $e, $product->Cd_AR);
            }
        }
    }

    private function getAllPrestaShopProducts($client)
    {
        $response = $client->request('GET', 'products', [
            'query' => ['display' => '[id,reference]']
        ]);
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
    
                $response = $client->request('PUT', "stock_availables/$stockAvailableId", [
                    'body' => $xml->asXML()
                ]);
    
                Log::info('Stock updated successfully', ['response' => $response->getStatusCode()]);
            } else {
                throw new Exception('No stock available found for the product');
            }
        } catch (GuzzleException $e) {
            $this->logError('Failed to retrieve or update stock', $e, $productId);
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

        $errorLog = date('Y-m-d H:i:s') . " - $message - " . json_encode($errorData) . PHP_EOL;
        file_put_contents($this->errorLogFile, $errorLog, FILE_APPEND);
    }
}

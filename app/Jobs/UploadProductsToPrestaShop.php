<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use App\Services\GuzzleService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class UploadProductsToPrestaShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $data;
    protected $guzzleService;
    
    public function __construct($data, GuzzleService $guzzleService)
    {
        $this->data = $data;
        $this->guzzleService = $guzzleService;
    }
    
    public function handle()
    {
        $client = $this->guzzleService->getClient();

        // Ottieni tutti i prodotti esistenti su PrestaShop per confrontare e potenzialmente eliminare quelli non più in Arca
        $prestashopProducts = $this->getAllPrestaShopProducts($client);

        foreach ($this->data['products'] as $product) {
            $xmlProduct = $this->generateProductXML($product);
            Log::info("Generato XML per il prodotto: {$product->Cd_AR}", ['xml' => $xmlProduct]);

            try {
                // Verifica se il prodotto esiste già su PrestaShop
                if (isset($prestashopProducts[$product->Cd_AR])) {
                    $productId = $prestashopProducts[$product->Cd_AR];
                    $manufacturerId = $this->getManufacturerIdByName($client, $product->DescrizioneMarca);
                    $xmlProduct = $this->generateProductXML($product, $productId, $manufacturerId);
                    $response = $client->request('PUT', "products/$productId", ['body' => $xmlProduct]);
                } else {
                    $response = $client->request('POST', 'products', ['body' => $xmlProduct]);
                    $productId = $this->extractProductId($response);
                }

                $newImages = $this->data['images'][$product->Cd_AR];
                $this->manageProductImages($productId, $newImages, $client);
                $quantity = number_format($product->Giacenza);
                if ($quantity) {
                    $this->updateStock($productId, $quantity, $client);
                }

                // Rimuovi il prodotto dall'elenco degli esistenti per non eliminarlo
                unset($prestashopProducts[$product->Cd_AR]);
            } catch (GuzzleException $e) {
                // Verifica se l'eccezione è di tipo RequestException per accedere alla risposta
                $responseBody = $e instanceof RequestException && $e->hasResponse()
                    ? $e->getResponse()->getBody()->getContents()
                    : 'No response body';
                
                Log::error("Caricamento fallito per il prodotto: {$product->Cd_AR}", [
                    'error' => $e->getMessage(),
                    'response_body' => $responseBody
                ]);
            }
        }

        // Elimina i prodotti non più presenti in Arca
        $this->deleteAbsentProducts($client, $prestashopProducts);
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
            Log::error("Errore nel recupero dei prodotti PrestaShop", ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function deleteAbsentProducts($client, $products)
    {
        foreach ($products as $reference => $id) {
            try {
                $client->request('DELETE', "products/$id");
                Log::info("Prodotto eliminato da PrestaShop", ['Cd_AR' => $reference, 'ID' => $id]);
            } catch (GuzzleException $e) {
                Log::error("Errore nell'eliminazione del prodotto: $reference", [
                    'error' => $e->getMessage(),
                    'product_id' => $id
                ]);
            }
        }
    }


    private function getManufacturerIdByName($client, $manufacturerName)
    {
        $response = $client->request('GET', 'manufacturers', [
            'query' => [
                'output_format' => 'JSON',
                'filter[name]' => $manufacturerName,
                'display' => '[id]'
            ]
        ]);

        $data = json_decode($response->getBody(), true);
        return $data['manufacturers'][0]['id'] ?? null;
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
                // Gestisci altri errori qui
                Log::error('Errore nel recupero delle immagini esistenti in Prestashop per eliminarle.', [
                    'error' => $e->getMessage(),
                    'product_id' => $productId
                ]);
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
                    Log::error("Eliminazione immagine fallita.", [
                        'error' => $e->getMessage(),
                        'image_id' => $imageId,
                        'product_id' => $productId
                    ]);
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
                Log::error('Caricamento immagine fallito.', [
                    'error' => $e->getMessage(),
                    'product_id' => $productId,
                    'image_url' => $imageUrl
                ]);
            }
        }
    }


    private function generateProductXML($product, $productId = null, $manufacturerId = null)
    {
        $xml = new \SimpleXMLElement('<prestashop/>');
        $xmlProduct = $xml->addChild('product');
        
        // Aggiungi l'ID del prodotto come attributo se stai facendo un aggiornamento
        if ($productId) {
            $xmlProduct->addChild('id', $productId);
        }

        // Qui aggiungi l'ID della marca
        if ($manufacturerId) {
            $xmlProduct->addChild('id_manufacturer', $manufacturerId);
        }

        
        // Aggiungi ID prodotto come reference
        $xmlProduct->addChild('reference', htmlspecialchars($product->Cd_AR));
        
        // Aggiungi il nome del prodotto con supporto per la lingua
        $name = $xmlProduct->addChild('name');
        $langName = $name->addChild('language', htmlspecialchars($product->WebDescrizione));
        $langName->addAttribute('id', '1');  // Assumi che '1' sia l'ID della lingua desiderata

        // Aggiungi la descrizione del prodotto con supporto per la lingua
        $name = $xmlProduct->addChild('description');
        $langName = $name->addChild('language', htmlspecialchars($product->WebNote_AR));
        $langName->addAttribute('id', '1');  // Assumi che '1' sia l'ID della lingua desiderata

        $name = $xmlProduct->addChild('description_short');
        $langName = $name->addChild('language', htmlspecialchars($product->WebNote_AR));
        $langName->addAttribute('id', '1');  // Assumi che '1' sia l'ID della lingua desiderata
        
        // Aggiungi il prezzo
        $xmlProduct->addChild('price', htmlspecialchars($product->Prezzo_LSA0005));
        $xmlProduct->addChild('wholesale_price', htmlspecialchars($product->Prezzo_LSA0009)); // Prezzo all'ingrosso
        $xmlProduct->addChild('id_tax_rules_group', '1'); // iva al 22%
        // Prezzo con misura
        $xmlProduct->addChild('unit_price_ratio', '1');
        $xmlProduct->addChild('unit_price', htmlspecialchars($product->Prezzo_LSA0005));
        $xmlProduct->addChild('unity', htmlspecialchars($product->Cd_ARMisura));

        // Misure
        $xmlProduct->addChild('weight', htmlspecialchars($product->PesoLordo));
        $xmlProduct->addChild('depth', htmlspecialchars($product->Larghezza));
        $xmlProduct->addChild('height', htmlspecialchars($product->Altezza));
        $xmlProduct->addChild('width', htmlspecialchars($product->Lunghezza));
        
        // Stato attivo del prodotto
        $xmlProduct->addChild('active', '1');
        $xmlProduct->addChild('state', '1');
        
        // Altro
        $xmlProduct->addChild('product_type', 'standard');
        $xmlProduct->addChild('minimal_quantity', '1');
        $xmlProduct->addChild('available_for_order', '1');
        $xmlProduct->addChild('show_price', '1');
        $xmlProduct->addChild('indexed', '1');
        $xmlProduct->addChild('visibility', 'both');
        $xmlProduct->addChild('date_add', now());
        $xmlProduct->addChild('date_upd', now());
        
        // Aggiungere le categorie
        $xmlProduct->addChild('id_category_default', $product->matchedCategoryId ?: '2');
        $associations = $xmlProduct->addChild('associations');
        $categories = $associations->addChild('categories');
        $categories->addAttribute('nodeType', 'category');
        $categories->addAttribute('api', 'categories');
        
        $category = $categories->addChild('category');
        $category->addChild('id', $product->matchedCategoryId ?: '2');
        
        return $xml->asXML();
    }

    private function updateStock($productId, $quantity, $client)
    {
        try {
            $response = $client->request('GET', 'stock_availables', [
                'query' => ['filter[id_product]' => $productId, 'display' => 'full']
            ]);
            $stockAvailables = new \SimpleXMLElement($response->getBody());
    
            // Assicurati che ci siano elementi stock_available
            if (!empty($stockAvailables->stock_availables->stock_available)) {
                $stockAvailable = $stockAvailables->stock_availables->stock_available;
    
                // Accedere a ogni campo richiesto
                $stockAvailableId = (string)$stockAvailable->id;
                $currentQuantity = (string)$stockAvailable->quantity;

                // Qui puoi fare il tuo aggiornamento...
                Log::info("Stock ID: $stockAvailableId, Current Quantity: $currentQuantity");
    
                $xml = new \SimpleXMLElement('<prestashop/>');
                $stockAvailableNode = $xml->addChild('stock_available');
                $stockAvailableNode->addChild('id', $stockAvailableId);
                $stockAvailableNode->addChild('id_product', $productId);
                $stockAvailableNode->addChild('quantity', $quantity);
                $stockAvailableNode->addChild('id_product_attribute', $productId);
                $stockAvailableNode->addChild('depends_on_stock', '0');
                $stockAvailableNode->addChild('out_of_stock', '2');
                $stockAvailableNode->addChild('id_shop', '1');
    
                $response = $client->request('PUT', "stock_availables/$stockAvailableId", [
                    'body' => $xml->asXML()
                ]);
    
                Log::info('Stock updated successfully', ['response' => $response->getStatusCode()]);
            } else {
                Log::error('No stock available found for the product');
            }
        } catch (GuzzleException $e) {
            // Verifica se l'eccezione è di tipo RequestException per accedere alla risposta
            $responseBody = $e instanceof RequestException && $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : 'No response body';
            
            Log::error('Failed to retrieve or update stock', [
                'error' => $e->getMessage(),
                'response_body' => $responseBody
            ]);
        }
    }    
      



    private function extractProductId($response)
    {
        // Assumiamo che la risposta sia XML e contenga un elemento con l'ID del prodotto.
        $xml = new \SimpleXMLElement($response->getBody()->getContents());
        if (!empty($xml->product->id)) {
            return (string) $xml->product->id;
        }
        
        return null; // Ritorna null se non si trova l'ID.
    }
        
}

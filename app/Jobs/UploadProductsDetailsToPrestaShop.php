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

class UploadProductsDetailsToPrestaShop implements ShouldQueue
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
        // Genera il nome del file di log
        $logFile = $this->generateLogFileName();
        $this->logMessage($logFile, "Inizio job sincronizzazione prodotti \n");
    
        $client = $this->guzzleService->getClient();
    
        // Ottieni tutti i prodotti esistenti su PrestaShop
        $prestashopProducts = $this->getAllPrestaShopProducts($client);
        $this->logMessage($logFile, count($prestashopProducts) . " prodotti esistenti su PrestaShop ottenuti \n");
    
        foreach ($this->data['products'] as $product) {
            try {
                $this->logMessage($logFile, "Elaborazione prodotto: {$product->Cd_AR} \n");
    
                if (isset($prestashopProducts[$product->Cd_AR])) {
                    $productId = $prestashopProducts[$product->Cd_AR];
                    // $manufacturerId = $this->getManufacturerIdByName($client, $product->DescrizioneMarca);
                    $productData = $this->generateProductXML($product, $productId);
                    $xmlProduct = $productData['xml'];
                    $this->logMessage($logFile, "Prodotto esistente: aggiornamento in corso (ID Prestahop: $productId)");
                    $client->request('PUT', "products/$productId", ['body' => $xmlProduct]);
                } else {
                    $productData = $this->generateProductXML($product);
                    $xmlProduct = $productData['xml'];
                    $response = $client->request('POST', 'products', ['body' => $xmlProduct]);
                    $productId = $this->extractProductId($response);
                    $this->logMessage($logFile, "Nuovo prodotto creato: ID $productId");
                }
    
                // Aggiorna lo stock
                $quantity = $productData['quantity'];
                $outOfStock = $productData['out_of_stock'];
                $discountPercentage = $productData['discount_percentage'];
                $this->updateStock($productId, $quantity, $client, $outOfStock);
                $this->logMessage($logFile, "Stock aggiornato per prodotto ID Prestahop: $productId (quantità: $quantity)");
    
                // Gestisci specific_price
                if ($discountPercentage > 0) {
                    $this->updateSpecificPrice($productId, $discountPercentage, $client);
                    $this->logMessage($logFile, "Prezzo specifico aggiornato per prodotto ID Prestashop $productId (sconto: $discountPercentage%)\n");
                } else {
                    $this->deleteSpecificPrice($productId, $client);
                    $this->logMessage($logFile, "Prezzo specifico rimosso per prodotto ID Prestashop $productId\n");
                }
    
                // Rimuovi il prodotto dall'elenco degli esistenti
                unset($prestashopProducts[$product->Cd_AR]);
            } catch (GuzzleException $e) {
                $errorMessage = "Errore per il prodotto {$product->Cd_AR}: " . $e->getMessage();
                $this->logMessage($logFile, $errorMessage);
                Log::error($errorMessage, [
                    'response_body' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body'
                ]);
            }
        }
    
        // Elimina i prodotti non più presenti
        $this->deleteAbsentProducts($client, $prestashopProducts);
        $this->logMessage($logFile, "Prodotti non più presenti su arca eliminati");
    
        $this->logMessage($logFile, "Fine job sincronizzazione prodotti");
    }
    
    /**
     * Genera un nome per il file di log.
     */
    private function generateLogFileName()
    {
        $basePath = storage_path('logs');
        $date = now()->format('Y-m-d');
        $fileBaseName = "$basePath/attrezzaturefood_details_job_log_$date";
    
        $filePath = "$fileBaseName.txt";
        $counter = 1;
    
        while (file_exists($filePath)) {
            $filePath = "$fileBaseName($counter).txt";
            $counter++;
        }
    
        return $filePath;
    }
    
    /**
     * Scrive un messaggio nel file di log.
     */
    private function logMessage($filePath, $message)
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        file_put_contents($filePath, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
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

    // private function getManufacturerIdByName($client, $manufacturerName)
    // {
    //     $response = $client->request('GET', 'manufacturers', [
    //         'query' => [
    //             'output_format' => 'JSON',
    //             'filter[name]' => $manufacturerName,
    //             'display' => '[id]'
    //         ]
    //     ]);

    //     $data = json_decode($response->getBody(), true);
    //     return $data['manufacturers'][0]['id'] ?? null;
    // }

    private function generateProductXML($product, $productId = null, $manufacturerId = null, $existingCategories = [])
    {
        $xml = new \SimpleXMLElement('<prestashop/>');
        $xmlProduct = $xml->addChild('product');
        
        // Aggiungi l'ID del prodotto come attributo se stai facendo un aggiornamento
        if ($productId) {
            $xmlProduct->addChild('id', $productId);
        }
    
        // Aggiungi l'ID del produttore
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
        $description = $xmlProduct->addChild('description');
        $langDescription = $description->addChild('language', htmlspecialchars($product->WebNote_AR));
        $langDescription->addAttribute('id', '1');  // Assumi che '1' sia l'ID della lingua desiderata
    
        $shortDescription = $xmlProduct->addChild('description_short');
        $langShortDescription = $shortDescription->addChild('language', htmlspecialchars($product->WebNote_AR));
        $langShortDescription->addAttribute('id', '1');  // Assumi che '1' sia l'ID della lingua desiderata
    
        // Aggiungi il prezzo
        $xmlProduct->addChild('price', htmlspecialchars($product->Prezzo_LSA0005));
        $xmlProduct->addChild('wholesale_price', htmlspecialchars($product->Prezzo_LSA0009));
        $xmlProduct->addChild('id_tax_rules_group', '1'); // IVA al 22%
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
    
        // // Aggiungere le categorie
        // $xmlProduct->addChild('id_category_default', $product->matchedCategoryId ?: '2');
        // $associations = $xmlProduct->addChild('associations');
        // $categories = $associations->addChild('categories');
        // $categories->addAttribute('nodeType', 'category');
        // $categories->addAttribute('api', 'categories');
        // $category = $categories->addChild('category');
        // $category->addChild('id', $product->matchedCategoryId ?: '2');
    
        // Parsing degli attributi
        $attributi = $this->parseXml($product->Attributi);
        Log::info('Attributi del prodotto', ['Cd_AR' => $product->Cd_AR, 'attributi' => $attributi]);
    
        // Inizializzazione delle variabili
        $quantity = number_format($product->Giacenza, 0, '.', '');
        $outOfStock = '0'; // Per default, non consente ordini fuori stock
        $availableNowText = 'Disponibile';
        $availableLaterText = '';
        $availableDate = '0000-00-00';
        $discountPercentage = 0;
        $addToHomeCategory = false;

        //! Mappatura degli attributi di sconto
        $discountAttributes = [
            '1021' => 5,
            '1022' => 10,
            '1023' => 15,
            '1024' => 20,
            '1025' => 25,
            '1026' => 30,
            '1040' => 35,
            '1041' => 40,
            '1042' => 45,
            '1043' => 50,
        ];
    
        $discountPercentages = [];
    
        // Analisi degli attributi
        foreach ($attributi as $attributeId) {
            // Controllo degli attributi di sconto
            if (isset($discountAttributes[$attributeId])) {
                $discountPercentages[] = $discountAttributes[$attributeId];
            }
    
            // Controllo per l'attributo "in produzione" (1047)
            if ($attributeId == env('IN_PRODUZIONE')) {
                $outOfStock = '1';
                $availableNowText = '';
                $availableLaterText = env('LABEL_IN_PRODUZIONE');
                $quantity = '0';
                $availableDate = now()->addDays(15)->toDateString();
            }

            // Controllo per l'attributo "home" (1034)
            if ($attributeId == env('IN_HOME')) {
                $addToHomeCategory = true;
            }
        }
    
        if (!empty($discountPercentages)) {
            // Utilizza la percentuale di sconto più alta
            $discountPercentage = max($discountPercentages);
        }
    
        // Aggiungi la data di disponibilità
        $xmlProduct->addChild('available_date', $availableDate);
    
        // Aggiungi i messaggi di disponibilità
        $availableNow = $xmlProduct->addChild('available_now');
        $availableNowLang = $availableNow->addChild('language', htmlspecialchars($availableNowText));
        $availableNowLang->addAttribute('id', '1');
    
        $availableLater = $xmlProduct->addChild('available_later');
        $availableLaterLang = $availableLater->addChild('language', htmlspecialchars($availableLaterText));
        $availableLaterLang->addAttribute('id', '1');

        // Aggiungere le categorie
        // Sostituisci '2' con l'ID della categoria predefinita che desideri utilizzare per i prodotti senza categoria corrispondente
        $defaultCategoryId = $product->matchedCategoryId ?: '2';
        $xmlProduct->addChild('id_category_default', $defaultCategoryId);
        $associations = $xmlProduct->addChild('associations');
        $categories = $associations->addChild('categories');
        $categories->addAttribute('nodeType', 'category');
        $categories->addAttribute('api', 'categories');

        // Inizializza un array per tracciare le categorie aggiunte
        $addedCategories = [];

        // Aggiungi le categorie esistenti
        foreach ($existingCategories as $categoryId) {
            $category = $categories->addChild('category');
            $category->addChild('id', $categoryId);
            $addedCategories[] = $categoryId;
        }

        // Aggiungi la categoria predefinita se non già presente
        if (!in_array($defaultCategoryId, $addedCategories)) {
            $category = $categories->addChild('category');
            $category->addChild('id', $defaultCategoryId);
            $addedCategories[] = $defaultCategoryId;
        }

        // Se l'attributo "home" è presente, aggiungi anche la categoria "Home" (ID 2)
        if ($addToHomeCategory) {
            if (!in_array('2', $addedCategories)) {
                $homeCategory = $categories->addChild('category');
                $homeCategory->addChild('id', '2');
                $addedCategories[] = '2';
            }
        }


        // Log per verificare le categorie aggiunte
        Log::info('Categorie associate al prodotto', ['Cd_AR' => $product->Cd_AR, 'categories' => $addedCategories]);

        // Restituisci l'XML e le informazioni aggiuntive
        return [
            'xml' => $xml->asXML(),
            'quantity' => $quantity,
            'out_of_stock' => $outOfStock,
            'discount_percentage' => $discountPercentage
        ];
    }

    private function getProductCategories($client, $productId)
    {
        try {
            $response = $client->request('GET', "products/$productId", [
                'query' => ['display' => 'full']
            ]);
            $productXml = new \SimpleXMLElement($response->getBody());
            $categories = [];

            if (isset($productXml->product->associations->categories->category)) {
                foreach ($productXml->product->associations->categories->category as $category) {
                    $categories[] = (string) $category->id;
                }
            }

            return $categories;
        } catch (GuzzleException $e) {
            Log::error('Errore durante il recupero delle categorie del prodotto', [
                'error' => $e->getMessage(),
                'product_id' => $productId
            ]);
            return [];
        }
    }


    private function parseXml($xmlString)
    {
        if (empty($xmlString)) {
            return [];
        }
    
        $xml = @simplexml_load_string($xmlString);
    
        if ($xml === false) {
            return [];
        }
    
        $attributi = [];
    
        if ($xml->row != null) {
            foreach ($xml->row as $row) {
                $attributeId = (string) $row['attributo'];
                $attributi[] = $attributeId;
                Log::info('Attributo rilevato', ['Cd_AR' => $row['Cd_AR'], 'attributeId' => $attributeId]);
            }
        }
        
        return $attributi;
    }

    private function updateStock($productId, $quantity, $client, $outOfStock)
    {
        try {
            // Filtra per id_product_attribute = 0
            $response = $client->request('GET', 'stock_availables', [
                'query' => [
                    'filter[id_product]' => $productId,
                    'filter[id_product_attribute]' => '0',
                    'display' => 'full'
                ]
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
                $stockAvailableNode->addChild('out_of_stock', $outOfStock);
                $stockAvailableNode->addChild('id_shop', '1');
    
                // Log per debugging
                Log::info('Aggiornamento stock_available', [
                    'id' => $stockAvailableId,
                    'id_product' => $productId,
                    'quantity' => $quantity,
                    'out_of_stock' => $outOfStock
                ]);
    
                $response = $client->request('PUT', "stock_availables/$stockAvailableId", [
                    'body' => $xml->asXML()
                ]);
    
                Log::info('Stock aggiornato con successo', ['response' => $response->getStatusCode()]);
            } else {
                Log::error('Nessun stock_available trovato per il prodotto');
            }
        } catch (GuzzleException $e) {
            Log::error('Errore durante il recupero o l\'aggiornamento dello stock', [
                'error' => $e->getMessage(),
                'response_body' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body'
            ]);
        }
    }

    private function updateSpecificPrice($productId, $discountPercentage, $client)
    {
        try {
            // Controlla se esiste già uno specific_price per questo prodotto
            $response = $client->request('GET', 'specific_prices', [
                'query' => [
                    'filter[id_product]' => $productId,
                    'display' => 'full'
                ]
            ]);

            $specificPricesXml = new \SimpleXMLElement($response->getBody());
            $existingSpecificPriceId = null;

            if (!empty($specificPricesXml->specific_prices->specific_price)) {
                $existingSpecificPrice = $specificPricesXml->specific_prices->specific_price;
                $existingSpecificPriceId = (string)$existingSpecificPrice->id;
            }

            // Prepara l'XML per specific_price
            $xml = new \SimpleXMLElement('<prestashop/>');
            $specificPriceNode = $xml->addChild('specific_price');

            if ($existingSpecificPriceId) {
                $specificPriceNode->addChild('id', $existingSpecificPriceId);
            }

            $specificPriceNode->addChild('id_product', $productId);
            $specificPriceNode->addChild('id_shop', '1');
            $specificPriceNode->addChild('id_shop_group', '0');
            $specificPriceNode->addChild('id_currency', '0');
            $specificPriceNode->addChild('id_country', '0');
            $specificPriceNode->addChild('id_group', '0');
            $specificPriceNode->addChild('id_customer', '0');
            $specificPriceNode->addChild('id_product_attribute', '0');
            $specificPriceNode->addChild('id_cart', '0');
            $specificPriceNode->addChild('from_quantity', '1');
            $specificPriceNode->addChild('price', '-1');
            $specificPriceNode->addChild('reduction', $discountPercentage / 100);
            $specificPriceNode->addChild('reduction_tax', '1'); // 1 per sconto con tasse incluse
            $specificPriceNode->addChild('reduction_type', 'percentage');
            $specificPriceNode->addChild('from', '0000-00-00 00:00:00');
            $specificPriceNode->addChild('to', '0000-00-00 00:00:00');

            Log::info('XML specific_price inviato', ['xml' => $xml->asXML()]);

            if ($existingSpecificPriceId) {
                // Aggiorna specific_price esistente
                $response = $client->request('PUT', "specific_prices/$existingSpecificPriceId", [
                    'body' => $xml->asXML()
                ]);
                Log::info('Specific price aggiornato', ['product_id' => $productId, 'specific_price_id' => $existingSpecificPriceId]);
            } else {
                // Crea nuovo specific_price
                $response = $client->request('POST', 'specific_prices', [
                    'body' => $xml->asXML()
                ]);
                Log::info('Specific price creato', ['product_id' => $productId]);
            }
        } catch (GuzzleException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('Errore durante la gestione di specific_price', [
                'error' => $e->getMessage(),
                'response_body' => $responseBody,
                'product_id' => $productId,
                'discount_percentage' => $discountPercentage
            ]);
        }
    }

    private function deleteSpecificPrice($productId, $client)
    {
        try {
            // Controlla se esiste uno specific_price per questo prodotto
            $response = $client->request('GET', 'specific_prices', [
                'query' => [
                    'filter[id_product]' => $productId,
                    'display' => 'full'
                ]
            ]);

            $specificPricesXml = new \SimpleXMLElement($response->getBody());

            if (!empty($specificPricesXml->specific_prices->specific_price)) {
                $existingSpecificPrice = $specificPricesXml->specific_prices->specific_price;
                $specificPriceId = (string)$existingSpecificPrice->id;

                // Elimina lo specific_price
                $response = $client->request('DELETE', "specific_prices/$specificPriceId");
                Log::info('Specific price eliminato', ['product_id' => $productId, 'specific_price_id' => $specificPriceId]);
            }

        } catch (GuzzleException $e) {
            Log::error('Errore durante l\'eliminazione di specific_price', [
                'error' => $e->getMessage(),
                'product_id' => $productId,
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

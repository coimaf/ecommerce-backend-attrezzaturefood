<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use App\Services\GuzzleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\UploadImagesToPrestaShop;
use App\Jobs\UploadProductsToPrestaShop;
use GuzzleHttp\Exception\GuzzleException;
use App\Jobs\UploadProductsStocksToPrestaShop;
use App\Jobs\UploadProductsDetailsToPrestaShop;

class ProductsController extends Controller
{

    protected $guzzleService;

    public function __construct(GuzzleService $guzzleService)
    {
        $this->guzzleService = $guzzleService;
    }

    public function getArcaCategory()
    {
        $rootId = env('ROOT_ID'); //! ID BRICOCANALI
    
        // Funzione ricorsiva per recuperare categorie a più livelli
        $fetchCategories = function ($parentIds, &$allCategories) use (&$fetchCategories) {
            if (empty($parentIds)) {
                return; // Termina la ricorsione se non ci sono più ID genitori
            }
    
            // Recupera le sottocategorie per gli ID forniti
            $subCategories = DB::connection('arca')
                ->table('ARCategoria')
                ->select('Id_ARCategoria', 'Id_ARCategoria_P', 'Descrizione')
                ->whereIn('Id_ARCategoria_P', $parentIds)
                ->get();
    
            // Aggiungi le sottocategorie alla collezione generale
            $allCategories = $allCategories->merge($subCategories);
    
            // Colleziona i prossimi ID per la ricorsione
            $nextParentIds = $subCategories->pluck('Id_ARCategoria')->toArray();
    
            // Chiamata ricorsiva per esplorare i livelli successivi
            $fetchCategories($nextParentIds, $allCategories);
        };
    
        // Inizializza la collezione delle categorie principali e avvia la ricorsione
        $allCategories = collect();
        $fetchCategories([$rootId], $allCategories);
    
        // Filtra le categorie principali (quelle che hanno come genitore l'ID root)
        $mainCategories = $allCategories->filter(function ($category) use ($rootId) {
            return $category->Id_ARCategoria_P == $rootId;
        });
    
        // Restituisci le categorie principali, le sottocategorie e tutte le categorie
        return [
            'mainCategories' => $mainCategories,
            'allCategories' => $allCategories
        ];
    }    

    public function getPrestashopCategories()
    {
        $client = $this->guzzleService->getClient();

        try {
            $response = $client->request('GET', 'categories', [
                'query' => [
                    'output_format' => 'JSON',
                    'display' => '[id,name]' // Chiedi esplicitamente per 'id' e 'name'
                ]
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true); // Decodifica JSON in array PHP

            return $data['categories'] ?? [];
        } catch (GuzzleException $e) {
            Log::error("Errore nel recupero delle categorie: " . $e->getMessage());
            return [];
        }
    }

    private function fetchProducts()
    {
        // Ottieni l'ultima revisione per i listini 'LSA0005' e 'LSA0009'
        $id_LSRevisione5 = DB::connection('arca')
        ->table('LSRevisione')
        ->whereNotNull('DataPubblicazione')
        ->where('DataPubblicazione', '>', '1990-01-01')
        ->where('cd_ls', env('LISTINO_CLIENTE'))
        ->orderBy('DataPubblicazione', 'desc')
        ->value('Id_LSRevisione');

        $id_LSRevisione9 = DB::connection('arca')
            ->table('LSRevisione')
            ->whereNotNull('DataPubblicazione')
            ->where('DataPubblicazione', '>', '1990-01-01')
            ->where('cd_ls', env('LISTINO_COSTO_NETTO'))
            ->orderBy('DataPubblicazione', 'desc')
            ->value('Id_LSRevisione');

        // Ottieni la giacenza per tutti i prodotti
        $giacenze = DB::connection('arca')
            ->table(DB::raw("MGDisp('" . date('Y') . "') as MGGiacDisp"))
            ->join('AR', 'MGGiacDisp.Cd_AR', '=', 'AR.Cd_AR')
            ->join('ARARMisura', function ($join) {
                $join->on('AR.Cd_AR', '=', 'ARARMisura.Cd_AR')
                    ->where(function ($query) {
                        $query->where('ARARMisura.TipoARMisura', '=', 'V')
                            ->orWhere('ARARMisura.TipoARMisura', '=', 'E')
                            ->orWhere('ARARMisura.DefaultMisura', '=', 1);
                    });
            })
            ->select(
                'MGGiacDisp.Cd_AR',
                'ARARMisura.Cd_ARMisura',
                'MGGiacDisp.Quantita',
                'MGGiacDisp.ImpQ',
                'MGGiacDisp.OrdQ',
                DB::raw('SUM(MGGiacDisp.QuantitaDisp) as QuantitaDisp'),
                'ARARMisura.UMFatt'
            )
            ->groupBy('MGGiacDisp.Cd_AR', 'ARARMisura.Cd_ARMisura', 'MGGiacDisp.Quantita', 'MGGiacDisp.ImpQ', 'MGGiacDisp.OrdQ', 'ARARMisura.UMFatt')
            ->get()
            ->keyBy('Cd_AR');

        // Subquery per ottenere la misura corretta
        $subQuery = DB::connection('arca')
            ->table(DB::raw('(
                SELECT 
                    Cd_AR, 
                    Cd_ARMisura, 
                    UMFatt,
                    ROW_NUMBER() OVER (PARTITION BY Cd_AR ORDER BY 
                        CASE
                            WHEN TipoARMisura = \'V\' THEN 1
                            WHEN TipoARMisura = \'E\' THEN 2
                            ELSE 3
                        END, DefaultMisura DESC) AS rn
                FROM ARARMisura
                WHERE TipoARMisura IN (\'V\', \'E\') OR DefaultMisura = 1
            ) AS sub'))
            ->where('rn', 1);

            // Ottieni i prodotti con i prezzi dai listini
            $products = DB::connection('arca')
            ->table('AR')
            ->join('LSArticolo as Art5', function ($join) use ($id_LSRevisione5) {
                $join->on('AR.Cd_AR', '=', 'Art5.Cd_AR')
                    ->where('Art5.Id_LSRevisione', '=', $id_LSRevisione5);
            })
            ->join('LSArticolo as Art9', function ($join) use ($id_LSRevisione9) {
                $join->on('AR.Cd_AR', '=', 'Art9.Cd_AR')
                    ->where('Art9.Id_LSRevisione', '=', $id_LSRevisione9);
            })
            ->leftJoin('ARMarca', 'AR.Cd_ARMarca', '=', 'ARMarca.Cd_ARMarca') // Join con la tabella ARMarca
            ->joinSub($subQuery, 'ARMisura', function ($join) {
                $join->on('AR.Cd_AR', '=', 'ARMisura.Cd_AR');
            })
            ->where('AR.Obsoleto', 0)
            ->where('AR.WebB2CPubblica', 1)
            ->whereRaw("AR.Attributi.exist('/rows/row[@attributo=xs:unsignedLong(\"1028\")]') = 1")
            ->select(
                'AR.Cd_AR',
                'AR.WebDescrizione',
                'AR.WebNote_AR',
                'ARMisura.Cd_ARMisura',
                'ARMisura.UMFatt as Fattore',
                'AR.Altezza',
                'AR.Lunghezza',
                'AR.Larghezza',
                'AR.PesoLordo',
                'AR.Attributi',
                'AR.Id_ARCategoria',
                'ARMarca.Descrizione as DescrizioneMarca', // Seleziona la descrizione della marca
                'Art5.Prezzo as Prezzo_LSA0005',
                'Art5.Sconto as Sconto_LSA0005', // Aggiungi il campo sconto dal listino 4
                'Art9.Prezzo as Prezzo_LSA0009'
            )
            ->get()
            ->map(function ($item) use ($giacenze) {
                $fattore = $item->Fattore;
            
                // Calcola il prezzo scontato per il listino 4
                $item->Prezzo_LSA0005 = is_numeric($item->Prezzo_LSA0005) ? (float)$item->Prezzo_LSA0005 : 0;
                $item->Sconto_LSA0005 = is_numeric($item->Sconto_LSA0005) ? (float)$item->Sconto_LSA0005 : 0;
                $item->Prezzo_LSA0009 = is_numeric($item->Prezzo_LSA0009) ? (float)$item->Prezzo_LSA0009 : 0;
                $fattore = is_numeric($item->Fattore) ? (float)$item->Fattore : 1;
                
                // Calcola i prezzi con i valori verificati
                $item->Prezzo_LSA0005 = number_format(($item->Prezzo_LSA0005 * (1 - $item->Sconto_LSA0005 / 100)) * $fattore, 3, '.', '');
                $item->Prezzo_LSA0009 = number_format($item->Prezzo_LSA0009 * $fattore, 3, '.', '');
                
            
                // Dividi la giacenza per il fattore e verifica se è negativa
                $giacenza = isset($giacenze[$item->Cd_AR]) ? $giacenze[$item->Cd_AR]->QuantitaDisp / $fattore : 0;
                $item->Giacenza = number_format(max($giacenza, 0), 3, '.', ''); // Usa max() per evitare valori negativi
            
                // Estrai gli attributi e aggiungi la descrizione degli attributi
                $item->DescrizioneAttributo = $this->getAttributesByIds($this->parseXML($item->Attributi));
            
                return $item;
            });            

        return $products;    
    }

    private function parseXML($string)
    {
        preg_match_all('/attributo="(\d+)"/', $string, $matches);

        return $matches[1]; // Ritorna un array di ID degli attributi
    }

    private function getAttributesByIds(array $attributiIds)
    {
        // Ottieni i nomi degli attributi per i relativi ID
        $attributes = DB::connection('arca')
            ->table('Attributo')
            ->whereIn('Id_attributo', $attributiIds) // Usa whereIn per filtrare per ID
            ->select('Id_attributo', 'Descrizione')
            ->get();

        // Mappa l'array degli attributi con ID e Descrizione
        $attributesMap = [];
        foreach ($attributes as $attribute) {
            $attributesMap[] = [
                'Id_attributo' => $attribute->Id_attributo,
                'Descrizione' => $attribute->Descrizione
            ];
        }

        return $attributesMap; // Restituisce un array con ID e Nome degli attributi
    }
    
    // Prodotto completo
    public function getProducts()
    {
        $allImageUrls = [];
    
        foreach ($this->fetchProducts() as $product) {
            $img = DB::connection('arca')
                ->table('ARImg')
                ->where('Cd_AR', $product->Cd_AR)
                ->select('Riga', 'Cd_AR', 'Picture1Raw', 'Picture1OriginalFile')
                ->get();
    
            $imageUrls = []; // Array per memorizzare gli URL delle immagini
    
            foreach ($img as $item) {
                $im_src = imagecreatefromstring($item->Picture1Raw);
                $estensione = substr($item->Picture1OriginalFile, -4, 4);
    
                // Genera un nome univoco per il file
                $imagePath = $product->Cd_AR . $item->Riga . $estensione;
    
                if (strtolower($estensione) == '.png') {
                    imagepng($im_src, storage_path('app/public/' . $imagePath));
                } elseif (in_array(strtolower($estensione), ['.jpg', '.jpeg'])) {
                    imagejpeg($im_src, storage_path('app/public/' . $imagePath));
                }
    
                imagedestroy($im_src);
    
                // Genera l'URL per l'immagine e aggiungi all'array
                $imageUrls[] = asset('storage/' . $imagePath);
            }
            $allImageUrls[$product->Cd_AR] = $imageUrls;
        }
    
        $categories = $this->getArcaCategory();
        $categoryMap = $categories['allCategories']->pluck('Descrizione', 'Id_ARCategoria')->toArray();
    
        $prestashopCategories = $this->getPrestashopCategories();
        $prestashopCategoryMap = collect($prestashopCategories)->pluck('id', 'name')->toArray();
    
        $products = $this->fetchProducts();
        foreach ($products as $product) {
            $arcaCategoryDescription = $categoryMap[$product->Id_ARCategoria] ?? null;
            $matchedCategoryId = $prestashopCategoryMap[$arcaCategoryDescription] ?? null;
    
            $product->matchedCategoryId = $matchedCategoryId;
        }
    
        // Dispatch del job per il caricamento su PrestaShop
        UploadProductsToPrestaShop::dispatch([
            'products' => $products,
            'images' => $allImageUrls,
            'guzzleService' => $this->guzzleService, 
        ])->onQueue('attrezzaturefood');
    
        return response()->json([
            "message" => "Prodotti inviati per il caricamento a PrestaShop.",
            'data' => [
                'quantity' => count($products),
                'products' => $products,
                'imageUrls' => $allImageUrls,
                'status' => true
            ]
        ], 200);
    }
    
    // Senza immagini
    public function getProductsDetails()
    {
        $categories = $this->getArcaCategory();
        $categoryMap = $categories['allCategories']->pluck('Descrizione', 'Id_ARCategoria')->toArray();
    
        $prestashopCategories = $this->getPrestashopCategories();
        $prestashopCategoryMap = collect($prestashopCategories)->pluck('id', 'name')->toArray();
    
        $products = $this->fetchProducts();
        foreach ($products as $product) {
            $arcaCategoryDescription = $categoryMap[$product->Id_ARCategoria] ?? null;
            $matchedCategoryId = $prestashopCategoryMap[$arcaCategoryDescription] ?? null;
    
            $product->matchedCategoryId = $matchedCategoryId;
        }
    
        // Dispatch del job per il caricamento su PrestaShop
        UploadProductsDetailsToPrestaShop::dispatch([
            'products' => $products,
            'guzzleService' => $this->guzzleService, 
        ])->onQueue('attrezzaturefood');
    
        return response()->json([
            "message" => "Prodotti inviati per il caricamento a PrestaShop.",
            'data' => [
                'quantity' => count($products),
                'products' => $products,
                'status' => true
            ]
        ], 200);
    }

    // Immagini Prodotto
    public function getProductsImages()
    {
        $allImageUrls = [];
        $totalImages = 0; // Variabile per tenere traccia del numero totale di immagini
        
        try {
            foreach ($this->fetchProducts() as $product) {
                $img = DB::connection('arca')
                    ->table('ARImg')
                    ->where('Cd_AR', $product->Cd_AR)
                    ->select('Riga', 'Cd_AR', 'Picture1Raw', 'Picture1OriginalFile')
                    ->get();
    
                $imageUrls = []; // Array per memorizzare gli URL delle immagini
    
                foreach ($img as $item) {
                    $im_src = imagecreatefromstring($item->Picture1Raw);
                    $estensione = substr($item->Picture1OriginalFile, -4, 4);
    
                    // Genera un nome univoco per il file
                    $imagePath = $product->Cd_AR . $item->Riga . $estensione;
    
                    if (strtolower($estensione) == '.png') {
                        imagepng($im_src, storage_path('app/public/' . $imagePath));
                    } elseif (in_array(strtolower($estensione), ['.jpg', '.jpeg'])) {
                        imagejpeg($im_src, storage_path('app/public/' . $imagePath));
                    }
    
                    imagedestroy($im_src);
    
                    // Genera l'URL per l'immagine e aggiungi all'array
                    $imageUrls[] = asset('storage/' . $imagePath);
                    $totalImages++; // Incrementa il contatore delle immagini
                }
                $allImageUrls[$product->Cd_AR] = $imageUrls;
            }
    
            // Dispatch del job per il caricamento su PrestaShop
            UploadImagesToPrestaShop::dispatch([
                'products' => $this->fetchProducts(),
                'images' => $allImageUrls,
                'guzzleService' => $this->guzzleService, 
            ])->onQueue('attrezzaturefood');
    
            return response()->json([
                "message" => "Immagini inviate per il caricamento a PrestaShop.",
                'data' => [
                    'imageUrls' => $allImageUrls,
                    'quantity' => $totalImages, // Aggiungi il numero totale di immagini alla risposta
                    'status' => true
                ]
            ], 200);
    
        } catch (\Exception $e) {
            // Log dell'eccezione
            Log::error('Errore durante l\'invio delle immagini a PrestaShop.', [
                'error' => $e->getMessage()
            ]);
    
            return response()->json([
                "message" => "Errore durante l'invio delle immagini a PrestaShop.",
                'data' => [
                    'error' => $e->getMessage(),
                    'status' => false
                ]
            ], 500);
        }
    }    

    // Giacenze prodotti
    public function getProductsStocks()
    {
        $products = $this->fetchProducts();

        // Dispatch del job per il caricamento su PrestaShop
        UploadProductsStocksToPrestaShop::dispatch([
            'products' => $products,
            'guzzleService' => $this->guzzleService, 
        ])->onQueue('attrezzaturefood');

        return response()->json([
            "message" => "Giacenze inviate per il caricamento a PrestaShop.",
            'data' => [
                'quantity' => count($products),
                'products' => $products,
                'status' => true
            ]
        ], 200);
    }

}

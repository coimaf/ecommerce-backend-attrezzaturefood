<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use App\Services\GuzzleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\GuzzleException;

class CategoriesController extends Controller
{

    protected $guzzleService;

    public function __construct(GuzzleService $guzzleService)
    {
        $this->guzzleService = $guzzleService;
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

    public function getCategories()
    {
        $rootId = '8'; //! ID di Bricocanali - Da cambiare per altri ecommerce

        // Recupero Bricocanali e tutte le sottocategorie dirette
        $mainCategories = DB::connection('arca')
            ->table('ARCategoria')
            ->select('Id_ARCategoria', 'Id_ARCategoria_P', 'Descrizione')
            ->where('Id_ARCategoria_P', $rootId)
            ->get();

        // Colleziona tutti gli ID delle sottocategorie di primo livello
        $subcategoryIds = $mainCategories->pluck('Id_ARCategoria')->toArray();
        
        // Recupera sottocategorie di secondo livello
        $subSubCategories = DB::connection('arca')
            ->table('ARCategoria')
            ->select('Id_ARCategoria', 'Id_ARCategoria_P', 'Descrizione')
            ->whereIn('Id_ARCategoria_P', $subcategoryIds)
            ->get();
        
        // Colleziona tutti gli ID delle sottocategorie di secondo livello
        $subSubCategoryIds = $subSubCategories->pluck('Id_ARCategoria')->toArray();

        // Recupera sottocategorie di terzo livello
        $thirdLevelCategories = DB::connection('arca')
            ->table('ARCategoria')
            ->select('Id_ARCategoria', 'Id_ARCategoria_P', 'Descrizione')
            ->whereIn('Id_ARCategoria_P', $subSubCategoryIds)
            ->get();

        // Unisci tutte le categorie: principali, di primo e secondo livello, e di terzo livello
        $allCategories = $mainCategories
                        ->merge($subSubCategories)
                        ->merge($thirdLevelCategories);
        
        return [
            'quantity' => count($allCategories),
            'mainCategories' => $mainCategories,
            'subCategories' => $subSubCategories,
            'thirdLevelCategories' => $thirdLevelCategories,
            'allCategories' => $allCategories
        ];
    }

    public function uploadCategoriesToPrestaShop()
    {
        $categories = $this->getCategories()['allCategories'];
        $arcaCategoryNames = $categories->pluck('Descrizione')->all();
        // dd($arcaCategoryNames);
        $categoryMap = [];

        $client = $this->guzzleService->getClient();

        // Ottieni tutte le categorie esistenti da PrestaShop
        $existingCategories = $this->getPrestashopCategories($client);

        foreach ($existingCategories as $existingCategory) {
            if (!in_array($existingCategory['name'], $arcaCategoryNames) && !in_array($existingCategory['id'], ['1', '2'])) {
                try {
                    $client->request('DELETE', "categories/{$existingCategory['id']}");
                    Log::info("Categoria rimossa da PrestaShop: {$existingCategory['name']}, ID: {$existingCategory['id']}");
                } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                    Log::error("Errore nella rimozione della categoria: {$existingCategory['name']}, ID: {$existingCategory['id']}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        foreach ($categories as $category) {
            $parentId = $category->Id_ARCategoria_P == '2' ? '2' : ($categoryMap[$category->Id_ARCategoria_P] ?? '2');

            $categoryExists = array_search($category->Descrizione, array_column($existingCategories, 'name'));

            try {
                if ($categoryExists !== false) {
                    // Categoria esistente, esegui un aggiornamento
                    $categoryId = $existingCategories[$categoryExists]['id'];
                    $xmlCategory = $this->generateCategoryXML($category, $parentId, $categoryId);
                    $response = $client->request('PUT', "categories/$categoryId", ['body' => $xmlCategory]);
                } else {
                    // Nuova categoria, crea una nuova
                    $xmlCategory = $this->generateCategoryXML($category, $parentId);
                    $response = $client->request('POST', 'categories', ['body' => $xmlCategory]);
                }

                $responseXML = new \SimpleXMLElement($response->getBody());
                $newCategoryId = (string)$responseXML->category->id;
                $categoryMap[$category->Id_ARCategoria] = $newCategoryId;
                Log::info("Categoria processata: {$category->Descrizione}, ID Arca: {$category->Id_ARCategoria}, ID PrestaShop: $newCategoryId");
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                Log::error("Errore nel processamento della categoria: {$category->Descrizione}", [
                    'error' => $e->getMessage(),
                    'response_body' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body'
                ]);
            }
        }
    }


    private function generateCategoryXML($category, $parentId, $categoryId = null)
    {
        $xml = new \SimpleXMLElement('<prestashop/>');
        $xmlCategory = $xml->addChild('category');

        if ($categoryId) {
            $xmlCategory->addChild('id', $categoryId);
        }
    
        $xmlCategory->addChild('id_parent', htmlspecialchars($parentId));
        $xmlCategory->addChild('active', '1');

        $xmlCategory->addChild('id_shop_default', '1'); // Assumi ID negozio predefinito
        $xmlCategory->addChild('is_root_category', '0'); // Esempio: non è una categoria radice
    
        // Creazione dei campi multilingua
        $names = $xmlCategory->addChild('name');
        $linkRewrites = $xmlCategory->addChild('link_rewrite');
        $descriptions = $xmlCategory->addChild('description');
        $metaTitles = $xmlCategory->addChild('meta_title');
        $metaDescriptions = $xmlCategory->addChild('meta_description');
        $metaKeywords = $xmlCategory->addChild('meta_keywords');
    
        // Esempio per due lingue (assumi ID lingua 1 per italiano e 2 per inglese)
        foreach (['1' => 'Italiano', '2' => 'Inglese'] as $langId => $langValue) {
            $names->addChild('language', htmlspecialchars($category->Descrizione))->addAttribute('id', $langId);
            $linkRewrite = strtolower($category->Descrizione); // Converti tutto in minuscolo
            $linkRewrite = preg_replace('/[^\w\s-]/', '', $linkRewrite); // Rimuovi tutti i caratteri non parola, non spazio e non trattino
            $linkRewrite = str_replace(' ', '-', $linkRewrite); // Sostituisci spazi con trattini
            $linkRewrite = preg_replace('/-+/', '-', $linkRewrite); // Sostituisci multipli trattini con un singolo trattino

            $linkRewrites->addChild('language', htmlspecialchars($linkRewrite))->addAttribute('id', $langId);

            $descriptions->addChild('language', htmlspecialchars($category->Descrizione))->addAttribute('id', $langId);
            $metaTitles->addChild('language', htmlspecialchars($category->Descrizione))->addAttribute('id', $langId);
            $metaDescriptions->addChild('language', htmlspecialchars($category->Descrizione))->addAttribute('id', $langId);
            $metaKeywords->addChild('language', htmlspecialchars($category->Descrizione))->addAttribute('id', $langId);
        }
        
        return $xml->asXML();
    }

}

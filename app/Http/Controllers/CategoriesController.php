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

    protected $logFile;

    public function __construct(GuzzleService $guzzleService)
    {
        $this->guzzleService = $guzzleService;
        $this->logFile = $this->generateLogFileName(); // Genera il file di log giornaliero
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

            $this->logMessage('Categorie recuperate da PrestaShop.', ['count' => count($data['categories'] ?? [])]);

            return $data['categories'] ?? [];
        } catch (GuzzleException $e) {
            $this->logMessage('Errore nel recupero delle categorie.', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getCategories()
    {
        $rootId = env('ROOT_ID'); //! ID BRICOCANALI
        
        // Funzione ricorsiva per recuperare tutte le categorie figlie
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
    
            // Chiamata ricorsiva per continuare con i figli
            $fetchCategories($nextParentIds, $allCategories);
        };
    
        // Inizializza la collezione di tutte le categorie e avvia la ricorsione
        $allCategories = collect();
        $fetchCategories([$rootId], $allCategories);
    
        $this->logMessage('Categorie recuperate da Arca.', [
            'total_categories' => $allCategories->count()
        ]);
    
        return [
            'quantity' => $allCategories->count(),
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
                    $this->logMessage("Categoria rimossa da PrestaShop.", ['name' => $existingCategory['name'], 'id' => $existingCategory['id']]);
                } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                    $this->logMessage('Errore nella rimozione della categoria.', [
                        'name' => $existingCategory['name'],
                        'id' => $existingCategory['id'],
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
                    $this->logMessage('Categoria aggiornata su PrestaShop.', ['name' => $category->Descrizione, 'arca_id' => $category->Id_ARCategoria, 'prestashop_id' => $categoryId]);
                } else {
                    // Nuova categoria, crea una nuova
                    $xmlCategory = $this->generateCategoryXML($category, $parentId);
                    $response = $client->request('POST', 'categories', ['body' => $xmlCategory]);
                }

                $responseXML = new \SimpleXMLElement($response->getBody());
                $newCategoryId = (string)$responseXML->category->id;
                $categoryMap[$category->Id_ARCategoria] = $newCategoryId;
                $this->logMessage('Nuova categoria creata su PrestaShop.', ['name' => $category->Descrizione, 'arca_id' => $category->Id_ARCategoria, 'prestashop_id' => $newCategoryId]);
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                $this->logMessage('Errore nel processamento della categoria.', [
                    'name' => $category->Descrizione,
                    'arca_id' => $category->Id_ARCategoria,
                    'error' => $e->getMessage()
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

    private function generateLogFileName()
    {
        $basePath = storage_path('logs');
        $date = now()->format('Y-m-d');
        $fileBaseName = "$basePath/attrezzaturefood_categories_job_log_$date";

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

}

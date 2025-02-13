<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\UploadBrandsToPrestaShop;
use App\Services\GuzzleService;

class BrandsController extends Controller
{
    protected $guzzleService;

    public function __construct(GuzzleService $guzzleService)
    {
        $this->guzzleService = $guzzleService;
    }

    public function getBrandsArca()
    {
     // Ottengo l'ultima revisione per il listino 'LSA0005'
     $id_LSRevisione4 = DB::connection('arca')
        ->table('LSRevisione')
        ->whereNotNull('DataPubblicazione')
        ->where('DataPubblicazione', '>', '1990-01-01')
        ->where('cd_ls', env('LISTINO_CLIENTE'))
        ->orderBy('DataPubblicazione', 'desc')
        ->value('Id_LSRevisione');

    // Ottengo solo le marche dei prodotti che sono nel listino 4 e soddisfano le altre condizioni
    $brands = DB::connection('arca')
        ->table('ARMarca')
        ->join('AR', 'ARMarca.Cd_ARMarca', '=', 'AR.Cd_ARMarca')
        ->join('LSArticolo', function ($join) use ($id_LSRevisione4) {
            $join->on('AR.Cd_AR', '=', 'LSArticolo.Cd_AR')
                ->where('LSArticolo.Id_LSRevisione', '=', $id_LSRevisione4);
        })
        ->where('AR.Obsoleto', 0)
        ->where('AR.WebB2CPubblica', 1)
        ->distinct()
        ->select('ARMarca.Descrizione')
        ->get();
    

        UploadBrandsToPrestaShop::dispatch([
            'brands' => $brands,
        ])->onQueue('attrezzaturefood');
    
        return response()->json([
            'quantity' => count($brands),
            'brands' => $brands
        ]);
    }

}

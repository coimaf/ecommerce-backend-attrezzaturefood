<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use App\Services\GuzzleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrdersController extends Controller
{
    private static $currentDocumentNumber;

    public function __construct(GuzzleService $guzzleService)
    {
        $this->guzzleService = $guzzleService;
        if (is_null(self::$currentDocumentNumber)) {
            self::$currentDocumentNumber = $this->getInitialDocumentNumber();
        }
    }    

    public function createOrderDocuments($customerCode, $orders)
    {
        foreach ($orders as $order) {
            if (!$this->documentExists($order['reference'])) {
                $this->createDoTes($customerCode, $order);
            } else {
                Log::info("Documento già esistente per ordine: " . $order['reference']);
            }
        }
    }

    private function getInitialDocumentNumber()
    {
        $currentYear = date('Y');
        $numeroDoc = DB::connection('arca')
            ->table('DoTes')
            ->where('Cd_Do', 'OC')
            ->whereYear('EsAnno', $currentYear)
            ->max('NumeroDoc');
    
        return $numeroDoc ? $numeroDoc : 0;
    }       

    private function getNextDocumentNumber()
    {
        if (is_null(self::$currentDocumentNumber)) {
            self::$currentDocumentNumber = $this->getInitialDocumentNumber();
        }
        self::$currentDocumentNumber += 1;
        return self::$currentDocumentNumber;
    }    

    private function documentExists($reference)
    {
        return DB::connection('arca')
            ->table('DoTes')
            ->where('NumeroDocRif', $reference)
            ->exists();
    }

    private function createDoTes($customerCode, $order)
    {
        // Calcola il numero di prodotti nel carrello
        $numberOfProducts = count($order['associations']['order_rows']);

        // Determina il valore di Cd_PG in base al metodo di pagamento
        $cdPg = ($order['payment'] === 'Bonifico bancario') ? '0102' : '0057';
        
        // Incrementa il numero di documento solo una volta per il documento principale
        $numeroDoc = $this->getNextDocumentNumber();
        
        // Aggiungi la logica per creare il documento DoTes
        $doTesData = [
            'Cd_Do' => 'OC',
            'TipoDocumento' => 'O',
            'DoBitMask' => 2,
            'Cd_CF' => $customerCode,
            'CliFor' => 'C',
            'Cd_CN' => 'D01',
            'Contabile' => 0,
            'TipoFattura' => 0,
            'ImportiIvati' => 0,
            'IvaSospesa' => 0,
            'Esecutivo' => 1,
            'Prelevabile' => 1,
            'Modificabile' => 1,
            'ModificabilePdf' => 1,
            'NumeroDoc' => $numeroDoc,
            'DataDoc' => now()->format('Y-m-d'),
            'Cd_MGEsercizio' => date("Y"),
            'EsAnno' => date("Y"),
            'Cd_CGConto_Banca' => env('CONTO_BANCA'),
            'Cd_VL' => 'EUR',
            'Decimali' => 2,
            'DecimaliPrzUn' => 3,
            'Cambio' => 1,
            'MagPFlag' => 0,
            'MagAFlag' => 0,
            'Cd_LS_1' => env('LISTINO_CLIENTE'),
            'Cd_LS_2' => env('LISTINO_AVANZATO'),
            'Cd_Agente_1' => '007',
            'Cd_PG' => $cdPg,
            'Colli' => 0,
            'PesoLordo' => 0,
            'PesoNetto' => 0,
            'VolumeTotale' => 0,
            'AbbuonoV' => 0,
            'RigheMerce' => $numberOfProducts,
            'RigheSpesa' => isset($order['total_shipping_tax_excl']) && $order['total_shipping_tax_excl'] > 0 ? 1 : 0,
            'RigheMerceEvadibili' => $numberOfProducts,
            'AccontoPerc' => '100',
            'AccontoV' => $order['total_paid'],
            'CGCorrispondenzaIvaMerce' => 1,
            'IvaSplit' => 0,
            'NotePiede' => $order['messages'][0] ?? '',
            'Cd_DoTrasporto' => '01',
            'Cd_DoAspBene' => 'AV',
            'NumeroDocRif' => $order['reference'],
            'DataDocRif' => now()->format('Y-m-d'),
        ];
        
        $docId = DB::connection('arca')->table('DoTes')->insertGetId($doTesData);
        Log::info("Creato documento DoTes per ordine: " . $order['id']);
        
        $rowNumber = 1;
        $totaleImponibile = 0;
        $totaleImponibileLordo = 0;
        
        foreach ($order['associations']['order_rows'] as $orderRow) {
            $productDetails = $this->fetchProductDetails($orderRow['product_id']);
            $unitMeasure = $productDetails['product']['unity'];
            $this->createDoRig($docId, $customerCode, $orderRow, $rowNumber, $unitMeasure, $numeroDoc);
            $totaleImponibile += $orderRow['unit_price_tax_excl'] * $orderRow['product_quantity'];
            $totaleImponibileLordo += $orderRow['unit_price_tax_excl'] * $orderRow['product_quantity'];
            $rowNumber++;
        }
    
        // Aggiungi la riga di spedizione se presente
        if (isset($order['total_shipping_tax_excl']) && $order['total_shipping_tax_excl'] > 0) {
            $this->createDoRigSpesa($docId, $order['total_shipping_tax_excl']);
            $totaleImponibile += $order['total_shipping_tax_excl'];
            $totaleImponibileLordo += $order['total_shipping_tax_excl'];
        }
        
        $totaleImposta = $totaleImponibile * 0.22;
        $totaleDocumento = $totaleImposta + $totaleImponibile;
        
        // Chiama createDOTotali una sola volta
        $this->createDOTotali($totaleDocumento, $docId, $totaleImponibile, $totaleImposta, $totaleImponibileLordo, $order);
    }
    
     
    private function createDoRig($docId, $customerCode, $orderRow, $rowNumber, $unitMeasure, $numeroDoc)
    {
        $doRigData = [
            'ID_DOTes' => $docId,
            'Contabile' => 0,
            'NumeroDoc' => $numeroDoc,
            'DataDoc' => now()->format('Y-m-d'),
            'Cd_MGEsercizio' => date('Y'),
            'Cd_DO' => 'OC',
            'TipoDocumento' => 'O',
            'Cd_CF' => $customerCode,
            'Cd_VL' => 'EUR',
            'Cd_MG_P' => 'MP',
            'Cambio' => 1,
            'Decimali' => 2,
            'DecimaliPrzUn' => 3,
            'Riga' => $rowNumber,
            'Cd_MGCausale' => '090',
            'Cd_AR' => $orderRow['product_reference'],
            'Descrizione' => $orderRow['product_name'],
            'Cd_ARMisura' => $unitMeasure,
            'Cd_CGConto' => env('CONTO_RICAVO'),
            'Cd_Aliquota' => env('ALIQUOTA'),
            'Cd_Aliquota_R' => env('ALIQUOTA'),
            'Qta' => $orderRow['product_quantity'],
            'FattoreToUM1' => 1,
            'QtaEvadibile' => $orderRow['product_quantity'],
            'QtaEvasa' => 0,
            'PrezzoUnitarioV' => $orderRow['unit_price_tax_excl'],
            'PrezzoTotaleV' => $orderRow['unit_price_tax_excl'] * $orderRow['product_quantity'],
            'PrezzoTotaleMovE' => $orderRow['unit_price_tax_excl'] * $orderRow['product_quantity'],
            'Omaggio' => 1,
            'Evasa' => 0,
            'Evadibile' => 1,
            'Esecutivo' => 1,
            'FattoreScontoRiga' => 0,
            'FattoreScontoTotale' => 0,
            'Id_LSArticolo' => null,
        ];
    
        DB::connection('arca')->statement('DISABLE TRIGGER dbo.DORig_atrg_brd ON dbo.DORig');
        DB::connection('arca')->table('DoRig')->insert($doRigData);
        DB::connection('arca')->statement('ENABLE TRIGGER dbo.DORig_atrg_brd ON dbo.DORig');
    
        Log::info("Creato documento DoRig per prodotto: " . $orderRow['product_reference']);
    
        $row = DB::connection('arca')
            ->table('DoRig')
            ->select('Id_DORig')
            ->where('ID_DOTes', $docId)
            ->where('Riga', $rowNumber)
            ->first();
    
        $rowAR = DB::connection('arca')
            ->table('AR')
            ->select('Fittizio')
            ->where('Cd_AR', $orderRow['product_reference'])
            ->first();
    
        $AR_fittizio = $rowAR ? $rowAR->Fittizio : null;
        $Id_DORig = $row->Id_DORig;
    
        $this->createMov($orderRow, $Id_DORig, $AR_fittizio, $docId);
    }
    
    private function createMov($orderRow, $Id_DORig, $AR_fittizio, $docId)
    {
        $movData = [
            'DataMov' => now()->format('Y-m-d'),
            'Id_DoRig' => $Id_DORig,
            'Cd_MGEsercizio' => date('Y'),
            'Cd_AR' => $orderRow['product_reference'],
            'Cd_MG' => 'MP',
            'Id_MGMovDes' => 18,
            'PartenzaArrivo' => 'P',
            'PadreComponente' => 'P',
            'EsplosioneDB' => 0,
            'Quantita' => $orderRow['product_quantity'],
            'Valore' => $orderRow['unit_price_tax_excl'],
            'Cd_MGCausale' => 'DDT',
            'Ini' => 0,
            'Ret' => 0,
            'CarA' => 0,
            'CarP' => 0,
            'CarT' => 0,
            'ScaV' => 1,
            'ScaP' => 0,
            'ScaT' => 0,
        ];
    
        DB::connection('arca')->statement('DISABLE TRIGGER dbo.MGMov_atrg ON dbo.MGMov');
        if ($AR_fittizio == 0 && $Id_DORig != 0) {
            DB::connection('arca')->table('MGMov')->insert($movData);
        }
        DB::connection('arca')->statement('ENABLE TRIGGER dbo.MGMov_atrg ON dbo.MGMov');
    
        Log::info("Creato movimento MGMov per prodotto: " . $orderRow['product_reference']);
    }
    

    private function createDOTotali($totaleDocumento, $docId, $totaleImponibile, $totaleImposta, $totaleImponibileLordo, $order)
    {
        // Controlla se esiste già un record con lo stesso Id_DoTes
        $existingRecord = DB::connection('arca')->table('DOTotali')->where('Id_DoTes', $docId)->first();
    
        if ($existingRecord) {
            Log::warning("Esiste già un record in DOTotali per Id_DoTes: " . $docId);
            return; // Esce dal metodo per evitare duplicati
        }
    
        $tsqlDOTotaliINSERT = [
            'Id_DoTes' => $docId,
            'Cambio' => 1,
            'AbbuonoV' => 0,
            'AccontoV' => round($totaleDocumento, 2),
            'AccontoE' => round($totaleDocumento, 2),
            'TotImponibileV' => round($totaleImponibile, 2),
            'TotImponibileE' => round($totaleImponibile, 2),
            'TotImpostaV' => round($totaleImposta, 2),
            'TotImpostaE' => round($totaleImposta, 2),
            'TotDocumentoV' => round($totaleDocumento, 2),
            'TotDocumentoE' => round($totaleDocumento, 2),
            'TotMerceLordoV' => round($totaleImponibileLordo, 2),
            'TotMerceNettoV' => round($totaleImponibile, 2),
            'TotEsenteV' => 0,
            'TotSpese_TV' => 0,
            'TotSpese_NV' => 0,
            'TotSpese_MV' => 0,
            'TotSpese_BV' => 0,
            'TotSpese_AV' => 0,
            'TotSpese_VV' => $order['total_shipping_tax_excl'],
            'TotSpese_ZV' => 0,
            'Totspese_RV' => 0,
            'TotScontoV' => 0,
            'TotOmaggio_MV' => 0,
            'TotOmaggio_IV' => 0,
            'TotaPagareV' => 0,
            'TotaPagareE' => 0,
            'TotProvvigione_1V' => 0,
            'TotProvvigione_2V' => 0,
            'RA_ImportoV' => 0,
            'TotImpostaRCV' => 0,
            'TotImpostaSPV' => 0,
        ];
    
        DB::connection('arca')->table('DOTotali')->insert($tsqlDOTotaliINSERT);
    
        /***************** IVA DOCUMENTO **************/
    
        // Raggruppa gli articoli per conto
        $articoliPerConto = DB::connection('arca')
            ->table('DoRig')
            ->where('ID_DOTes', $docId)
            ->select('Cd_CGConto', DB::raw('SUM(PrezzoTotaleV) AS TotaleImponibile'))
            ->groupBy('Cd_CGConto')
            ->get();
    
        // Aggiungi le spese di spedizione al totale per conto
        $spesePerConto = DB::connection('arca')
            ->table('DoRigSpesa')
            ->where('Id_DoTes', $docId)
            ->select('Cd_CGConto', DB::raw('SUM(ImportoV) AS TotaleImponibile'))
            ->groupBy('Cd_CGConto')
            ->get();
    
        // Unisci i totali delle righe e delle spese
        $totaliPerConto = $articoliPerConto->concat($spesePerConto)->groupBy('Cd_CGConto')->map(function($items) {
            return $items->sum('TotaleImponibile');
        });
    
        // Per ogni gruppo di articoli e spese per conto, aggiungi il totale e l'IVA a DOIva
        foreach ($totaliPerConto as $conto => $totaleImponibile) {
            $ivaGruppo = $totaleImponibile * (22.0 / 100);
    
            DB::connection('arca')->table('DOIva')->insert([
                'Id_DOTes' => $docId,
                'Cd_Aliquota' => env('ALIQUOTA'),
                'Aliquota' => env('ALIQUOTA_DECIMALE'),
                'Cambio' => '1.000000',
                'ImponibileV' => round($totaleImponibile, 2),
                'ImpostaV' => round($ivaGruppo, 2),
                'Omaggio' => 1,
                'Cd_CGConto' => $conto,
            ]);
        }
    }
    
    
    private function createDoRigSpesa($docId, $shippingCost)
    {
        $doRigSpesaData = [
            'Id_DoTes' => $docId,
            'Contabile' => 0,
            'Esecutivo' => 1,
            'DataDoc' => now()->format('Y-m-d'),
            'Riga' => 1,
            'Descrizione' => 'Spese di spedizione',
            'TipoRigaSpesa' => 'T',
            'Cd_VL' => 'EUR',
            'Cd_Aliquota' => env('ALIQUOTA'),
            'Cd_Aliquota_E' => env('ALIQUOTA'),
            'Cd_Aliquota_R' => env('ALIQUOTA'),
            'Cd_CGConto' => env('CONTO_SPEDIZIONE'),
            'Decimali' => 2,
            'Cambio' => 1,
            'ImportoV' => $shippingCost,
            'ImportoEvadibileV' => $shippingCost
        ];

        DB::connection('arca')->statement('DISABLE TRIGGER dbo.DORigSpesa_atrg_brd ON dbo.DORigSpesa');
        DB::connection('arca')->table('DoRigSpesa')->insert($doRigSpesaData);
        DB::connection('arca')->statement('ENABLE TRIGGER dbo.DORigSpesa_atrg_brd ON dbo.DORigSpesa');

        Log::info("Creato riga spesa per spedizione: " . $shippingCost);
    }


    private function fetchProductDetails($productId)
    {
        $client = $this->guzzleService->getClient();

        $response = $client->request('GET', "products/{$productId}", [
            'query' => [
                'output_format' => 'JSON',
            ]
        ]);

        $productData = json_decode($response->getBody()->getContents(), true);

        return $productData;
    }
}

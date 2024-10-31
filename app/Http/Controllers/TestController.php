<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    public function test()
    {
        // Ottieni l'ultima revisione per i listini 'LSA0004' e 'LSA0009'
        $id_LSRevisione4 = DB::connection('arca')
        ->table('LSRevisione')
        ->whereNotNull('DataPubblicazione')
        ->where('DataPubblicazione', '>', '1990-01-01')
        ->where('cd_ls', 'LSA0004')
        ->orderBy('DataPubblicazione', 'desc')
        ->value('Id_LSRevisione');

        $id_LSRevisione9 = DB::connection('arca')
            ->table('LSRevisione')
            ->whereNotNull('DataPubblicazione')
            ->where('DataPubblicazione', '>', '1990-01-01')
            ->where('cd_ls', 'LSA0009')
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
            ->join('LSArticolo as Art4', function ($join) use ($id_LSRevisione4) {
                $join->on('AR.Cd_AR', '=', 'Art4.Cd_AR')
                    ->where('Art4.Id_LSRevisione', '=', $id_LSRevisione4);
            })
            ->join('LSArticolo as Art9', function ($join) use ($id_LSRevisione9) {
                $join->on('AR.Cd_AR', '=', 'Art9.Cd_AR')
                    ->where('Art9.Id_LSRevisione', '=', $id_LSRevisione9);
            })
            ->join('ARMarca', 'AR.Cd_ARMarca', '=', 'ARMarca.Cd_ARMarca') // Join con la tabella ARMarca
            ->joinSub($subQuery, 'ARMisura', function ($join) {
                $join->on('AR.Cd_AR', '=', 'ARMisura.Cd_AR');
            })
            ->where('AR.Obsoleto', 0)
            ->where('AR.WebB2CPubblica', 1)
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
                'Art4.Prezzo as Prezzo_LSA0004',
                'Art9.Prezzo as Prezzo_LSA0009'
            )
            ->take(20)
            ->get()
            ->map(function ($item) use ($giacenze) {
                $fattore = $item->Fattore;

                // Applica il fattore solo ai prezzi
                $item->Prezzo_LSA0004 = number_format($item->Prezzo_LSA0004 * $fattore, 3, '.', '');
                $item->Prezzo_LSA0009 = number_format($item->Prezzo_LSA0009 * $fattore, 3, '.', '');

                // Dividi la giacenza per il fattore
                $item->Giacenza = isset($giacenze[$item->Cd_AR]) ? number_format($giacenze[$item->Cd_AR]->QuantitaDisp / $fattore, 3, '.', '') : '0.000';

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
}
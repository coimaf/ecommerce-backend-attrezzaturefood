<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use App\Services\GuzzleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use App\Http\Controllers\OrdersController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;
use App\Notifications\CustomerDiscrepancyNotification;

class ImportCustomersToArca implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $guzzleService;
    protected $logFile;

    /**
     * Create a new job instance.
     */
    public function __construct(GuzzleService $guzzleService)
    {
        $this->guzzleService = $guzzleService;
        $this->logFile = $this->generateLogFileName(); // Genera il nome del file di log
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->logMessage("Inizio job sincronizzazione Clienti");
        $client = $this->guzzleService->getClient();
    
        $totalOrdersImported = 0;
        $totalOrdersCanceled = 0;
    
        try {
            $customers = $this->fetchCustomers($client);
            if (empty($customers)) {
                $this->logMessage('Nessun cliente trovato durante la sincronizzazione.');
                return; // Termina il job se non ci sono clienti
            }
            
            $this->logMessage('Clienti recuperati con successo.', ['count' => count($customers)]);
            $customersWithOrdersAndAddresses = [];
    
            foreach ($customers as $customer) {
                $this->logMessage('Gestione cliente iniziata.', ['customer_id' => $customer['id']]);
                $orders = $this->fetchCustomerOrders($client, $customer['id']);
    
                if (empty($orders)) {
                    $this->logMessage('Nessun ordine valido trovato per il cliente.', ['customer_id' => $customer['id']]);
                    continue; // Passa al prossimo cliente se non ci sono ordini
                }
    
                $this->logMessage('Ordini recuperati per il cliente.', ['customer_id' => $customer['id'], 'orders_count' => count($orders)]);
                $ordersWithAddressesAndMessages = [];
    
                foreach ($orders as $order) {
                    $this->logMessage('Recupero dettagli dello stato ordine.', ['order_id' => $order['id'], 'current_state' => $order['current_state']]);
    
                    // Verifica se lo stato è "Annullato" (current_state = 6)
                    if ((int)$order['current_state'] === 6) {
                        $this->logMessage('Ordine ignorato perché annullato.', ['order_id' => $order['id'], 'state' => $order['current_state']]);
                        $totalOrdersCanceled++;
                        continue; // Salta l'elaborazione di questo ordine
                    }
    
                    $this->logMessage('Gestione ordine iniziata.', ['order_id' => $order['id']]);
                    $orderDetails = $this->fetchOrderDetails($client, $order['id']);
                    $billingAddress = $this->fetchAddressDetails($client, $orderDetails['id_address_invoice'] ?? null);
                    $shippingAddress = $this->fetchAddressDetails($client, $orderDetails['id_address_delivery'] ?? null);
    
                    if ($billingAddress) {
                        $orderDetails['billing_address'] = $billingAddress;
                    }
    
                    if ($shippingAddress) {
                        $orderDetails['shipping_address'] = $shippingAddress;
                    }
    
                    $orderMessages = $this->fetchOrderMessages($client, $order['id']);
                    $orderDetails['messages'] = $orderMessages;
    
                    $ordersWithAddressesAndMessages[] = $orderDetails;
                    $this->logMessage('Ordine gestito con successo.', ['order_id' => $order['id']]);
                    $totalOrdersImported++;
                }
    
                if (!empty($ordersWithAddressesAndMessages)) {
                    $customersWithOrdersAndAddresses[] = [
                        'customer' => [
                            'id' => $customer['id'],
                            'firstname' => $customer['firstname'],
                            'lastname' => $customer['lastname'],
                            'email' => $customer['email']
                        ],
                        'orders' => $ordersWithAddressesAndMessages,
                    ];
                    $this->logMessage('Cliente con ordini validi aggiunto alla sincronizzazione.', ['customer_id' => $customer['id']]);
                } else {
                    $this->logMessage('Cliente ignorato perché non ha ordini validi.', ['customer_id' => $customer['id']]);
                }
            }
    
            // Processa i clienti e i loro ordini
            if (!empty($customersWithOrdersAndAddresses)) {
                $this->processCustomers($customersWithOrdersAndAddresses, $client);
            }
    
            // Log finale con i conteggi
            $this->logMessage('Importazione completata con successo.');
        } catch (\Exception $e) {
            $this->logMessage('Errore durante l\'importazione clienti: ' . $e->getMessage());
        }
    }
      

    private function fetchOrderStateDetails($client, $stateId)
    {
        try {
            $response = $client->request('GET', "order_states/{$stateId}", [
                'query' => [
                    'output_format' => 'JSON',
                ],
            ]);
    
            $body = $response->getBody();
            $orderStateData = json_decode($body, true);
    
            $this->logMessage('Dettagli stato ordine recuperati.', ['state_id' => $stateId]);
            return $orderStateData['order_state'] ?? [];
        } catch (\Exception $e) {
            $this->logMessage('Errore durante il recupero dello stato ordine.', ['state_id' => $stateId, 'error' => $e->getMessage()]);
            return [];
        }
    }
    
    private function fetchCustomers(Client $client)
    {
        try {
            $response = $client->request('GET', 'customers', [
                'query' => [
                    'output_format' => 'JSON',
                    'display' => '[id,firstname,lastname,email]'
                ]
            ]);

            $body = $response->getBody();
            $customersData = json_decode($body, true);
            $this->logMessage('Clienti recuperati da PrestaShop.');
            return $customersData['customers'] ?? [];
        } catch (\Exception $e) {
            $this->logMessage('Errore durante il recupero dei clienti: ' . $e->getMessage());
            return [];
        }
    }

    private function fetchCustomerOrders(Client $client, $customerId)
    {
        try {
            $response = $client->request('GET', 'orders', [
                'query' => [
                    'output_format' => 'JSON',
                    'filter[id_customer]' => $customerId,
                    'display' => '[id,current_state]'
                ]
            ]);

            $body = $response->getBody();
            $ordersData = json_decode($body, true);
            $this->logMessage('Ordini recuperati per cliente.', ['customer_id' => $customerId]);
            return $ordersData['orders'] ?? [];
        } catch (\Exception $e) {
            $this->logMessage("Errore durante il recupero degli ordini per cliente {$customerId}: " . $e->getMessage());
            return [];
        }
    }

    private function fetchOrderDetails(Client $client, $orderId)
    {
        try {
            $response = $client->request('GET', "orders/{$orderId}", [
                'query' => ['output_format' => 'JSON']
            ]);

            $body = $response->getBody();
            $orderDetails = json_decode($body, true);
            $this->logMessage('Dettagli ordine recuperati.', ['order_id' => $orderId]);
            return $orderDetails['order'] ?? [];
        } catch (\Exception $e) {
            $this->logMessage("Errore durante il recupero dei dettagli ordine {$orderId}: " . $e->getMessage());
            return [];
        }
    }

    private function fetchAddressDetails(Client $client, $addressId)
    {
        if (!$addressId) {
            return null;
        }

        try {
            $response = $client->request('GET', "addresses/{$addressId}", [
                'query' => ['output_format' => 'JSON']
            ]);

            $body = $response->getBody();
            $addressDetails = json_decode($body, true);
            $this->logMessage('Dettagli indirizzo recuperati.', ['address_id' => $addressId]);
            return $addressDetails['address'] ?? [];
        } catch (\Exception $e) {
            $this->logMessage("Errore durante il recupero dei dettagli indirizzo {$addressId}: " . $e->getMessage());
            return [];
        }
    }

    private function fetchOrderMessages(Client $client, $orderId)
    {
        try {
            $response = $client->request('GET', 'customer_threads', [
                'query' => [
                    'output_format' => 'JSON',
                    'filter[id_order]' => $orderId
                ]
            ]);

            $body = $response->getBody();
            $threadsData = json_decode($body, true);
            $threads = $threadsData['customer_threads'] ?? [];
            $messages = [];

            foreach ($threads as $thread) {
                $threadId = $thread['id'];
                $response = $client->request('GET', "customer_threads/{$threadId}", [
                    'query' => [
                        'output_format' => 'JSON',
                        'associations' => 'customer_messages'
                    ]
                ]);

                $body = $response->getBody();
                $threadDetails = json_decode($body, true);
                $threadDetails = $threadDetails['customer_thread'] ?? [];

                if (isset($threadDetails['associations']['customer_messages'])) {
                    foreach ($threadDetails['associations']['customer_messages'] as $messageAssoc) {
                        $messageId = $messageAssoc['id'];

                        $response = $client->request('GET', "customer_messages/{$messageId}", [
                            'query' => ['output_format' => 'JSON']
                        ]);

                        $body = $response->getBody();
                        $messageDetails = json_decode($body, true);
                        $messages[] = $messageDetails['customer_message']['message'] ?? '';
                    }
                }
            }
            $this->logMessage('Messaggi ordine recuperati.', ['order_id' => $orderId]);
            return $messages;
        } catch (\Exception $e) {
            $this->logMessage("Errore durante il recupero dei messaggi per ordine {$orderId}: " . $e->getMessage());
            return [];
        }
    }

    private function processCustomers($customersWithOrdersAndAddresses, $client)
    {
        $this->logMessage('Inizio elaborazione clienti.');
        foreach ($customersWithOrdersAndAddresses as $customerWithOrder) {
            $customer = $customerWithOrder['customer'];
            $orders = $customerWithOrder['orders'];
    
            // Ordina gli ordini per ID in modo decrescente per trovare il più recente
            usort($orders, function($a, $b) {
                return $b['id'] - $a['id'];
            });
    
            // Prendi l'ordine più recente
            $latestOrder = $orders[0];
            $this->logMessage('Elaborazione cliente.', ['customer_id' => $customer['id'], 'orders_count' => count($orders)]);
    
            $dni = $latestOrder['billing_address']['dni'] ?? null;
            if (!$dni) {
                $this->logMessage("cliente ID: {$customer['id']} non ha DNI, salta.");
                continue;
            }
    
            // Verifica se il cliente esiste già su Arca
            $existingCustomer = DB::connection('arca')->table('CF')
                ->where('CodiceFiscale', strtoupper($dni))
                ->first();
    
            if ($existingCustomer) {
                $this->logMessage("Existing customer found: " . json_encode($existingCustomer));
                // Controlla le discrepanze con il cliente esistente su Arca
                $billingDifferences = $this->getDifferences((array)$existingCustomer, $latestOrder['billing_address']);
    
                if (!empty($billingDifferences) && is_array($billingDifferences)) {
                    $this->logMessage("Discrepanza nell'indirizzo di fatturazione per l'ID cliente {$customer['id']}: Arca: " . json_encode($existingCustomer) . " vs PrestaShop: " . json_encode($latestOrder['billing_address']));
                    $discrepancies = [
                        [
                            'field' => 'Indirizzo di Fatturazione',
                            'differences' => $billingDifferences
                        ]
                    ];
    
                    // Invia notifica per le discrepanze
                    Notification::route('mail', config('services.contact.mail_root_alert'))
                        ->notify(new CustomerDiscrepancyNotification($customer, $discrepancies));
                }
                // Crea sempre i documenti per l'ordine, anche se ci sono discrepanze
                $customerCode = $existingCustomer->Cd_CF;
            } else {
                // Genera il nuovo codice cliente
                $nextCustomerCode = $this->getNextCustomerCode();
                $this->logMessage("Generato nuovo codice cliente: {$nextCustomerCode}");
    
                // Verifica e gestisci il valore di CodiceFPR
                $codiceFPR = $latestOrder['billing_address']['address2'] ?? '';
                
                if (!preg_match('/^[a-zA-Z0-9]{7}$/', $codiceFPR) && !filter_var($codiceFPR, FILTER_VALIDATE_EMAIL)) {
                    $this->logMessage("Codice FPR non valido: {$codiceFPR}, non rispetta i criteri (alfanumerico di 7 caratteri o email valida).");
                    $codiceFPR = null;
                }
                

                $stateId = $latestOrder['billing_address']['id_state'] ?? null;
                $provinceCode = null;
                
                if ($stateId) {
                    try {
                        // Richiesta API per recuperare il dettaglio della provincia
                        $response = $client->request('GET', 'states', [
                            'query' => [
                                'output_format' => 'JSON',
                                'filter[id]' => $stateId, // Filtro sull'ID dello stato
                                'display' => '[id,iso_code,name]'
                            ]
                        ]);
                
                        $body = $response->getBody();
                        $stateDetails = json_decode($body, true);

                        Log::info($stateDetails);
                
                        if (!empty($stateDetails['states'][0]['iso_code'])) {
                            $provinceCode = strtoupper($stateDetails['states'][0]['iso_code']);
                            Log::info($provinceCode);
                        } else {
                            $provinceCode = ''; // Valore predefinito in caso di errore o dato mancante
                        }
                    } catch (\Exception $e) {
                        $this->logMessage("Errore nel recupero della provincia con ID stato: {$stateId}, errore: {$e->getMessage()}");
                        $provinceCode = ''; // Fallback in caso di errore
                    }
                } else {
                    $this->logMessage("ID stato mancante per l'ordine.");
                    $provinceCode = '';
                }
                
                $countryId = $latestOrder['billing_address']['id_country'] ?? null;
                $countryCode = 'IT'; // Valore predefinito per l'Italia

                if ($countryId) {
                    try {
                        // Richiesta API per recuperare il dettaglio della nazione
                        $response = $client->request('GET', 'countries', [
                            'query' => [
                                'output_format' => 'JSON',
                                'filter[id]' => $countryId, // Filtro sull'ID del paese
                            ]
                        ]);

                        $body = $response->getBody();
                        $countryDetails = json_decode($body, true);

                        if (!empty($countryDetails['countries'][0]['iso_code'])) {
                            $countryCode = strtoupper($countryDetails['countries'][0]['iso_code']);
                        }
                    } catch (\Exception $e) {
                        $this->logMessage("Errore nel recupero della nazione con ID paese: {$countryId}, errore: {$e->getMessage()}");
                    }
                } else {
                    $this->logMessage("ID paese mancante per l'ordine.");
                }


                
    
                // Imposta TipoDitta basato sulla presenza della partita IVA
                $tipoDitta = empty($latestOrder['billing_address']['vat_number']) ? 'F' : 'G';
    
                // Imposta la Descrizione basata sulla presenza del valore company
                $ragioneSociale = !empty($latestOrder['billing_address']['company']) ? $latestOrder['billing_address']['company'] : (!empty($customer['company']) ? $customer['company'] : strtoupper($customer['firstname']) . ' ' . strtoupper($customer['lastname']));
    
                // Formattare i dati per Arca e salvare nel database
                $customerArca = [
                    'Cd_CF' => $nextCustomerCode,
                    'Descrizione' => strtoupper($ragioneSociale),
                    'Indirizzo' => strtoupper($latestOrder['billing_address']['address1']) ?? '',
                    'Localita' => strtoupper($latestOrder['billing_address']['city']) ?? '',
                    'Cd_Provincia' => $provinceCode, // Codice ISO della provincia
                    'Cd_Nazione' => $countryCode,    // Codice ISO della nazione
                    'Cap' => $latestOrder['billing_address']['postcode'] ?? '',
                    'PartitaIva' => $latestOrder['billing_address']['vat_number'] ?? '',
                    'CodiceFiscale' => strtoupper($dni),
                    'CodiceFPR' => $codiceFPR ? strtoupper($codiceFPR) : null,
                    'TipoDitta' => $tipoDitta, // Giuridica / Fisica p.iva o ci
                    'Cd_VL' => 'EUR',
                    'Cd_DOPorto' => 'EXW',
                    'Cd_DOSped' => '02',
                    'Id_Lingua' => '1040',
                    'Fido' => '1',
                    'Note_CF' => 'Importato da: ' . config('app.name'),
                    'Cd_Nazione_Destinazione' => $countryCode,
                    'Elenchi' => '1',
                    'Iban' => 'IT',
                    'Cd_NazioneIva' => $countryCode,
                    'Cd_CGConto_Mastro' => env('CONTO_CLIENTI_ITALIA')
                ];

                Log::info($customerArca);
    
                DB::connection('arca')->table('CF')->insert($customerArca);
    
                // Insert contact data into CFContatto table
                $this->insertContactData($nextCustomerCode, $customer, $latestOrder);

                $customerCode = $nextCustomerCode;
            }

            // Crea i documenti per l'ordine, sia per nuovi clienti che per clienti esistenti
            $orderController = new OrdersController($this->guzzleService);
            $orderController->createOrderDocuments($customerCode, $orders);
        }
        $this->logMessage('Elaborazione clienti completata.');
    }

    private function insertContactData($customerCode, $customer, $latestOrder)
    {
        $phone = $latestOrder['billing_address']['phone'] ?? '';
        $email = $customer['email'];
        $name = 'Aziendale';
    
        // Definisci i dati del contatto
        $contactData = [
            'Cd_CF' => $customerCode,
            'Id_CFContattoTipo' => 1, // Supponendo che 1 sia l'ID corretto per il tipo di contatto
            'Nome' => $name,
            'Sequenza' => 1,
            'Telefono' => $phone,
            'Email' => $email
        ];
    
        $this->logMessage("Tentativo di inserimento contatto: " . json_encode($contactData));
    
        // Verifica se il contatto esiste già
        $existingContact = DB::connection('arca')->table('CFContatto')
            ->where('Cd_CF', $contactData['Cd_CF'])
            ->where('Id_CFContattoTipo', $contactData['Id_CFContattoTipo'])
            ->where('Nome', $contactData['Nome'])
            ->where('Sequenza', $contactData['Sequenza'])
            ->where('Email', $contactData['Email'])
            ->exists();
    
        // Inserisci il contatto solo se non esiste già
        if (!$existingContact) {
            DB::connection('arca')->table('CFContatto')->insert($contactData);
            $this->logMessage("Contatto inserito con successo: " . json_encode($contactData));
        } else {
            $this->logMessage("Il contatto esiste già per Cd_CF: {$contactData['Cd_CF']}, Nome: {$contactData['Nome']}, Email: {$contactData['Email']}");
        }
    }

    private function getDifferences($old, $new)
    {
        $ignoredFields = ['id', 'date_add', 'date_upd'];
        $differences = [];
    
        // Mappa dei campi tra PrestaShop e Arca
        $fieldMap = [
            'indirizzo' => 'address1',
            'localita' => 'city',
            'cap' => 'postcode',
            'partitaiva' => 'vat_number',
            'codicefiscale' => 'dni',
            'firstname' => 'firstname', 
            'lastname' => 'lastname',   
            'descrizione' => 'company',
        ];
    
        // Converti tutte le chiavi in minuscolo per confronto uniforme
        $oldLower = array_change_key_case($old, CASE_LOWER);
        $newLower = array_change_key_case($new, CASE_LOWER);
    
        if (is_array($oldLower) && is_array($newLower)) {
            foreach ($fieldMap as $arcaField => $prestashopField) {
                if (!in_array($arcaField, $ignoredFields) && isset($oldLower[$arcaField]) && isset($newLower[$prestashopField])) {
                    $oldValue = strtolower($oldLower[$arcaField]);
                    $newValue = strtolower($newLower[$prestashopField]);
                    if ($oldValue != $newValue) {
                        $differences[$arcaField] = ['old' => $oldLower[$arcaField], 'new' => $newLower[$prestashopField]];
                    }
                }
            }
        }
        return $differences;
    }

    private function getNextCustomerCode()
    {
        // Recupera l'ultimo codice cliente da Arca
        $lastCustomerCode = DB::connection('arca')
            ->table('CF')
            ->select(DB::raw("MAX(CAST(SUBSTRING(Cd_CF, 3, LEN(Cd_CF) - 2) AS INT)) AS last_id"))
            ->where(DB::raw("SUBSTRING(Cd_CF, 1, 2)"), '=', 'C0')
            ->whereRaw("LEN(Cd_CF) = 7")
            ->value('last_id');

        // Estrai il numero dal codice cliente e incrementalo
        $nextNumber = $lastCustomerCode + 1;

        // Formatta il nuovo codice cliente
        return 'C0' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    private function generateLogFileName()
    {
        $basePath = storage_path('logs');
        $date = now()->format('Y-m-d');
        $fileBaseName = "$basePath/prestashop_customers_job_log_$date";

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
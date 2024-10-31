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

    /**
     * Create a new job instance.
     */
    public function __construct(GuzzleService $guzzleService)
    {
        $this->guzzleService = $guzzleService;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $client = $this->guzzleService->getClient();

        try {
            $customers = $this->fetchCustomers($client);

            $customersWithOrdersAndAddresses = [];
            foreach ($customers as $customer) {
                $orders = $this->fetchCustomerOrders($client, $customer['id']);

                if (!empty($orders)) {
                    $ordersWithAddressesAndMessages = [];
                    foreach ($orders as $order) {
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
                    }

                    $customersWithOrdersAndAddresses[] = [
                        'customer' => [
                            'id' => $customer['id'],
                            'firstname' => $customer['firstname'],
                            'lastname' => $customer['lastname'],
                            'email' => $customer['email']
                        ],
                        'orders' => $ordersWithAddressesAndMessages,
                    ];
                }
            }

            // Processa i clienti e i loro ordini
            $this->processCustomers($customersWithOrdersAndAddresses, $client);

        } catch (\Exception $e) {
            Log::error('Error retrieving customers, orders, addresses or messages: ' . $e->getMessage());
        }
    }

    private function fetchCustomers(Client $client)
    {
        $response = $client->request('GET', 'customers', [
            'query' => [
                'output_format' => 'JSON',
                'display' => '[id,firstname,lastname,email]'
            ]
        ]);

        $body = $response->getBody();
        $customersData = json_decode($body, true);
        return $customersData['customers'] ?? [];
    }

    private function fetchCustomerOrders(Client $client, $customerId)
    {
        $response = $client->request('GET', 'orders', [
            'query' => [
                'output_format' => 'JSON',
                'filter[id_customer]' => $customerId
            ]
        ]);

        $body = $response->getBody();
        $ordersData = json_decode($body, true);
        return $ordersData['orders'] ?? [];
    }

    private function fetchOrderDetails(Client $client, $orderId)
    {
        $response = $client->request('GET', "orders/{$orderId}", [
            'query' => [
                'output_format' => 'JSON'
            ]
        ]);

        $body = $response->getBody();
        $orderDetails = json_decode($body, true);
        return $orderDetails['order'] ?? [];
    }

    private function fetchAddressDetails(Client $client, $addressId)
    {
        if (!$addressId) {
            return null;
        }

        $response = $client->request('GET', "addresses/{$addressId}", [
            'query' => [
                'output_format' => 'JSON'
            ]
        ]);

        $body = $response->getBody();
        $addressDetails = json_decode($body, true);
        return $addressDetails['address'] ?? [];
    }

    private function fetchOrderMessages(Client $client, $orderId)
    {
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
                        'query' => [
                            'output_format' => 'JSON'
                        ]
                    ]);

                    $body = $response->getBody();
                    $messageDetails = json_decode($body, true);
                    $messageDetails = $messageDetails['customer_message'] ?? [];

                    $messages[] = $messageDetails['message'] ?? '';
                }
            }
        }
        return $messages;
    }

    private function processCustomers($customersWithOrdersAndAddresses, $client)
    {
        foreach ($customersWithOrdersAndAddresses as $customerWithOrder) {
            $customer = $customerWithOrder['customer'];
            $orders = $customerWithOrder['orders'];
    
            // Ordina gli ordini per ID in modo decrescente per trovare il più recente
            usort($orders, function($a, $b) {
                return $b['id'] - $a['id'];
            });
    
            // Prendi l'ordine più recente
            $latestOrder = $orders[0];
    
            Log::info("Processing customer ID: {$customer['id']}, Order ID: {$latestOrder['id']}");
    
            $dni = $latestOrder['billing_address']['dni'] ?? null;
            if (!$dni) {
                Log::warning("Customer ID: {$customer['id']} has no DNI, skipping.");
                continue;
            }
    
            // Verifica se il cliente esiste già su Arca
            $existingCustomer = DB::connection('arca')->table('CF')
                ->where('CodiceFiscale', strtoupper($dni))
                ->first();
    
            if ($existingCustomer) {
                Log::info("Existing customer found: " . json_encode($existingCustomer));
                // Controlla le discrepanze con il cliente esistente su Arca
                $billingDifferences = $this->getDifferences((array)$existingCustomer, $latestOrder['billing_address']);
    
                if (!empty($billingDifferences) && is_array($billingDifferences)) {
                    Log::info("Discrepancy in billing address for customer ID {$customer['id']}: Arca: " . json_encode($existingCustomer) . " vs PrestaShop: " . json_encode($latestOrder['billing_address']));
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
                Log::info("Generated new customer code: {$nextCustomerCode}");
    
                // Verifica e gestisci il valore di CodiceFPR
                $codiceFPR = $latestOrder['billing_address']['address2'] ?? '';
                if (!preg_match('/^[a-zA-Z0-9]{7}$/', $codiceFPR) && !filter_var($codiceFPR, FILTER_VALIDATE_EMAIL)) {
                    $codiceFPR = null;
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
                    'Cd_Nazione_Destinazione' => 'IT',
                    'Elenchi' => '1',
                    'Iban' => 'IT',
                    'Cd_NazioneIva' => 'IT',
                    'Cd_CGConto_Mastro' => '11010101001'
                ];
    
                DB::connection('arca')->table('CF')->insert($customerArca);
    
                // Insert contact data into CFContatto table
                $this->insertContactData($nextCustomerCode, $customer, $latestOrder);

                $customerCode = $nextCustomerCode;
            }

            // Crea i documenti per l'ordine, sia per nuovi clienti che per clienti esistenti
            $orderController = new OrdersController($this->guzzleService);
            $orderController->createOrderDocuments($customerCode, $orders);
        }
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
    
        Log::info("Tentativo di inserimento contatto: " . json_encode($contactData));
    
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
            Log::info("Contatto inserito con successo: " . json_encode($contactData));
        } else {
            Log::info("Il contatto esiste già per Cd_CF: {$contactData['Cd_CF']}, Nome: {$contactData['Nome']}, Email: {$contactData['Email']}");
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
}
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerDiscrepancyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $customer;
    protected $discrepancies;

    /**
     * Create a new notification instance.
     *
     * @param int $customer
     * @param array $discrepancies
     * @return void
     */
    public function __construct($customer, $discrepancies)
    {
        $this->customer = $customer;
        $this->discrepancies = $discrepancies;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $message = (new MailMessage)
            ->subject('Discrepanze nei dati del cliente')
            ->greeting('Avviso')
            ->line('Sono state rilevate discrepanze nei dati del cliente: ' . $this->customer['firstname'] . ' ' . $this->customer['lastname'])
            ->line('Di seguito sono riportate le differenze:');

        foreach ($this->discrepancies as $discrepancy) {
            if (is_array($discrepancy) && isset($discrepancy['field'], $discrepancy['differences']) && is_array($discrepancy['differences'])) {
                $message->line($discrepancy['field'] . ":");
                foreach ($discrepancy['differences'] as $field => $values) {
                    if (is_array($values) && isset($values['old'], $values['new'])) {
                        $message->line("Campo: " . $field)
                            ->line("Vecchio valore: " . ($values['old'] !== null ? $values['old'] : 'N/A'))
                            ->line("Nuovo valore: " . ($values['new'] !== null ? $values['new'] : 'N/A'))
                            ->line('');
                    }
                }
            }
        }

        $message->salutation("Saluti,\n" . config('app.name'));

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [];
    }
}

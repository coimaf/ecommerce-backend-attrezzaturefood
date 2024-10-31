# Ecommerce COIMAF

![alt text](https://github.com/NicolaMazzaferro/Coimaf_Dashboard_beta/blob/main/public/assets/coimaf_logo.png?raw=true)

Gestione ecommerce tramite Webservice di Prestahop. Questo servizio comunica attraverso le API fornite da Prestashop per la gestione di articoli, clienti, ordini ecc... importando dati dal gestionale Arca Evolution. Inoltre gli endpoint sono usati dal CRM Coimaf per gestire il caricamento dei dati in Prestashop. Le richieste a Prestashop sono gestite in modo asincrono attraverso i Jobs di Laravel.

## Installazione
```
composer install
cp .env.example .env
php artisan key:generate
```
## Configurazione
```
PRESTASHOP_API_URL=
PRESTASHOP_API_KEY=
```

```
MS_SQL_CONNECTION=arca
MS_SQL_HOST=
MS_SQL_PORT=
MS_SQL_DATABASE=
MS_SQL_USERNAME=
MS_SQL_PASSWORD=
```

## Lancia il progetto
```
php artisan serve
php artisan queue:work
```
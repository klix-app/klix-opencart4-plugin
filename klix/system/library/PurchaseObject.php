<?php

class PurchaseObject
{
    public array $client;
    public array $purchase;
    public string $brand_id = '';
    public int|string $reference = '';
    public string $success_redirect = '';
    public string $success_callback = '';
    public string $cancel_redirect = '';
    public string $failure_redirect = '';
    public string $platform = 'opencart';
    public string $creator_agent = '';

    public function __construct()
    {
        $this->client = [
            'email'          => '',
            'phone'          => '',
            'full_name'      => '',
            'street_address' => ''
        ];
        $this->purchase = [
            'products' => [],
            'language' => '',
            'notes'    => ''
        ];
    }
}
<?php

class PurchaseObject
{
    public $client;
    public $purchase;
    public $brand_id;
    public $reference;
    public $success_redirect;
    public $success_callback;
    public $cancel_redirect;
    public $failure_redirect;
    public $platform;
    public $creator_agent;

    public function __construct()
    {
        $this->client=[
            'email',
            'phone',
            'full_name',
            'street_address'
        ];
        $this->purchase=[
            'products',
            'language',
            'notes'
        ];
    }
}
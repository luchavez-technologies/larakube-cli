<?php

use App\Providers\AppServiceProvider;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Saloon\Laravel\SaloonServiceProvider;

return [
    AppServiceProvider::class,
    TranslationServiceProvider::class,
    ValidationServiceProvider::class,
    SaloonServiceProvider::class,
];

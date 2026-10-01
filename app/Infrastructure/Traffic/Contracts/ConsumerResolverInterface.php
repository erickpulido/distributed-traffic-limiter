<?php

declare(strict_types=1);

namespace App\Infrastructure\Traffic\Contracts;

use Illuminate\Http\Request;

interface ConsumerResolverInterface
{
    /**
     * Resuelve un identificador único jerárquico a partir de la petición HTTP.
     */
    public function resolve(Request $request): string;
}
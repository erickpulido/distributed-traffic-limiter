<?php

declare(strict_types=1);

namespace App\Infrastructure\Traffic;

enum FailMode: string
{
    case OPEN   = 'open';   // Permite el tráfico si Redis cae
    case CLOSED = 'closed'; // Bloquea el tráfico si Redis cae
}
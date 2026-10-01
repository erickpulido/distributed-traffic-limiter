# Distributed Traffic Limiter

Motor de control de tráfico y absorción de ráfagas (*traffic shaping*) distribuido, diseñado para entornos de alto rendimiento con persistencia en Redis y Laravel. Implementa el algoritmo Token Bucket de forma atómica mediante scripts en Lua, incorpora resolución jerárquica de consumidores, telemetría asíncrona y tolerancia a fallos basada en Circuit Breaker.

---

## 1. Problema de Ingeniería y Arquitectura

En arquitecturas empresariales que procesan volúmenes masivos de transacciones simultáneas, el control de concurrencia y la protección de bases de datos relacionales presentan dos retos críticos:

1. **Condiciones de Carrera (Race Conditions):** Realizar lecturas y escrituras secuenciales desde la capa de aplicación hacia la memoria compartida (ej. `GET` -> cálculo -> `SET`) genera colisiones cuando múltiples procesos atienden tráfico en paralelo, permitiendo ráfagas que superan el límite nominal.
2. **Impacto de la Infraestructura Auxiliar:** Si el almacén en memoria (Redis) sufre una degradación o caída de red, la capa perimetral no debe bloquear las transacciones del negocio ni degradar la latencia de respuesta de los clientes.

### Solución Implementada
* **Atomicidad en memoria:** Toda la matemática de recarga de tokens y evaluación de balance se delega al hilo de eventos de Redis mediante un script en Lua ejecutado vía `EVALSHA`.
* **Identificación Jerárquica:** El identificador del consumidor se resuelve desacoplando el origen de la petición en cascada: `API-Key` $\to$ `Tenant-ID` $\to$ `User-ID` $\to$ `Client IP`.
* **Resiliencia (Circuit Breaker):** Modos de contingencia configurables por ruta (`Fail-Open` para navegación y operaciones comerciales; `Fail-Closed` para rutas críticas de pago o autenticación).
* **Telemetría Desacoplada:** Las anomalías de tráfico y rechazos (`HTTP 429`) se emiten de forma asíncrona hacia workers de Laravel Horizon, manteniendo el tiempo de respuesta del middleware por debajo de 1 ms.

---

## 2. Registro de Decisiones de Arquitectura (ADR-001)

| Parámetro | Decisión Técnica | Justificación |
|---|---|---|
| **Algoritmo** | Token Bucket con costo dinámico | Permite absorber ráfagas controladas (*burst capacity*) y tarifar endpoints según su peso computacional (`cost`). |
| **Mecanismo de Ejecución** | Redis + `EVALSHA` | Elimina la transferencia redundante del código fuente de Lua sobre el socket TCP; utiliza re-carga automática ante errores `NOSCRIPT`. |
| **Contrato de Salida** | IETF RFC 6585 & RFC 7231 | Expone cabeceras estandarizadas: `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` y `Retry-After`. |
| **Hashing de Claves** | Algoritmo xxHash (`xxh128`) | Mayor rendimiento de CPU frente a SHA-256 / MD5 y menor tamaño en bytes para almacenamiento en Redis. |
| **Persistencia Volátil** | Estructura `HASH` con `EXPIRE` dinámico | Liberación automática de memoria RAM cuando el bucket alcanza su capacidad máxima sin actividad. |

---

## 3. Cinética del Algoritmo (Token Bucket)

La reposición de tokens no depende de tareas programadas (*cron jobs*), sino que se evalúa de manera perezosa (*lazy evaluation*) en cada petición:

$$\Delta t = \max(0, t_{\text{actual}} - t_{\text{última recarga}})$$

$$\text{Tokens Disponibles} = \min(\text{Capacidad}, \text{Tokens Previos} + (\Delta t \times \text{Tasa de Recarga}))$$

* **Si $\text{Tokens Disponibles} \ge \text{Costo}$:** Se descuenta el costo, se actualiza el timestamp y la petición continúa con código `200`.
* **Si $\text{Tokens Disponibles} < \text{Costo}$:** No se descuentan tokens, se conserva el estado y se calcula el tiempo de espera:

$$\text{Retry-After} = \max\left(1.0, \left\lceil \frac{\text{Costo} - \text{Tokens Disponibles}}{\text{Tasa de Recarga}} \right\rceil\right)$$

---

## 4. Estructura del Código

```text
app/
├── Http/
│   └── Middleware/
│       └── DistributedRateLimiterMiddleware.php  # Middleware perimetral e inyección de headers IETF
├── Infrastructure/
│   └── Traffic/
│       ├── Contracts/
│       │   └── ConsumerResolverInterface.php     # Contrato para resolución de identidad
│       ├── Resolvers/
│       │   └── HierarchicalConsumerResolver.php  # Cascada API-Key -> Tenant -> User -> IP
│       ├── FailMode.php                          # Enum: OPEN | CLOSED
│       ├── RateLimitResult.php                   # DTO inmutable de resultado
│       ├── TokenBucketLimiter.php                # Servicio ejecutor de EVALSHA y Circuit Breaker
│       └── token_bucket.lua                      # Script de ejecución atómica en Redis
└── Jobs/
    └── RecordTrafficAnomalyJob.php               # Telemetría asíncrona hacia Horizon
```

---

## 5. Pruebas y Validación Empírica

### Suite Automatizada
El repositorio cuenta con cobertura completa sobre agotamiento de ráfagas, recarga en el tiempo, jerarquía de consumidores y resiliencia ante fallos:

```bash
./vendor/bin/sail test
```

```text
PASS  Tests\Unit\HierarchicalConsumerResolverTest
✓ prioritizes api key over all other identifiers
✓ resolves tenant when no api key is present
✓ falls back to ip when no credentials are present

PASS  Tests\Unit\TokenBucketLimiterTest
✓ allows initial request and deducts tokens
✓ blocks request when tokens are exhausted
✓ refills tokens over time
✓ circuit breaker fails open on redis outage

PASS  Tests\Feature\AsyncTrafficTelemetryTest
✓ dispatches anomaly job when rate limit is exceeded

PASS  Tests\Feature\DistributedRateLimiterMiddlewareTest
✓ injects ratelimit headers on successful requests
✓ returns 429 with retry after when burst exhausted

Tests: 12 passed (36 assertions)
```

### Prueba de Carga (Benchmark de Ráfaga)
El script `benchmark.sh` valida la capacidad del sistema para absorber el límite configurado y rechazar el excedente:

```bash
./benchmark.sh
```

```text
================ RESULTADOS DEL BENCHMARK ================
Peticiones Aprobadas (HTTP 200): 32 (Tokens iniciales + recarga dinámica)
Peticiones Bloqueadas (HTTP 429): 3
Errores Inesperados: 0
==========================================================
PRUEBA SUPERADA: Bucket agotado en ráfaga y 429 activado.
```

---

## 6. Despliegue Local

### Requisitos
* Docker y Docker Compose
* PHP 8.3+ y Composer (solo si se ejecuta fuera de contenedores)

### Pasos de Instalación
```bash
# 1. Clonar repositorio
git clone https://github.com/erickpulido/distributed-traffic-limiter && cd distributed-traffic-limiter

# 2. Configurar variables de entorno
cp .env.example .env

# 3. Levantar entorno con Laravel Sail
./vendor/bin/sail up -d

# 4. Ejecutar suite de pruebas
./vendor/bin/sail test
```
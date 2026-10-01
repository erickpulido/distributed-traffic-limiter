-- =========================================================================
-- DISTRIBUTED TOKEN BUCKET ALGORITHM (REDIS LUA ENGINE)
-- =========================================================================
-- KEYS[1]: Llave del bucket (ej. "rate_limit:tenant_abc123:route_checkout")
-- ARGV[1]: Capacidad máxima de la cubeta (Burst capacity)
-- ARGV[2]: Tasa de recarga (Tokens por segundo)
-- ARGV[3]: Costo de la petición actual (Tokens consumidos, ej. 1 o 5)
-- ARGV[4]: Timestamp actual en segundos flotantes (microtime true en PHP)
-- =========================================================================

local key          = KEYS[1]
local capacity     = tonumber(ARGV[1])
local refill_rate  = tonumber(ARGV[2])
local cost         = tonumber(ARGV[3])
local now          = tonumber(ARGV[4])

-- 1. Obtener estado previo del bucket
local data = redis.call("HMGET", key, "tokens", "last_refill")
local current_tokens = tonumber(data[1])
local last_refill    = tonumber(data[2])

-- 2. Si el bucket no existe en memoria, inicializarlo lleno
if current_tokens == nil then
    current_tokens = capacity
    last_refill    = now
else
    -- 3. Calcular tokens acumulados por tiempo transcurrido
    local delta = math.max(0, now - last_refill)
    local tokens_to_add = delta * refill_rate
    current_tokens = math.min(capacity, current_tokens + tokens_to_add)
    last_refill = now
end

-- 4. Evaluar si hay suficientes tokens para la petición
local allowed = 0
local retry_after = 0

if current_tokens >= cost then
    -- APROBADO: Restar el costo y permitir el paso
    allowed = 1
    current_tokens = current_tokens - cost
else
    -- DENEGADO: Calcular segundos exactos de espera para el próximo intento
    allowed = 0
    local missing_tokens = cost - current_tokens
    retry_after = missing_tokens / refill_rate
end

-- 5. Persistir estado en Redis
redis.call("HMSET", key, "tokens", current_tokens, "last_refill", last_refill)

-- 6. Calcular TTL para liberar memoria RAM cuando no haya actividad
-- TTL = tiempo que tarda en llenarse por completo desde cero + 60s de margen
local ttl = math.ceil(capacity / refill_rate) + 60
redis.call("EXPIRE", key, ttl)

-- 7. Retornar array al cliente: [allowed (0|1), tokens_restantes, retry_after, capacidad]
return { allowed, current_tokens, retry_after, capacity }
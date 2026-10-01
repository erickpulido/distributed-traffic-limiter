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

if current_tokens == nil or last_refill == nil then
    current_tokens = capacity
    last_refill    = now
else
    local delta = math.max(0, now - last_refill)
    current_tokens = math.min(capacity, current_tokens + (delta * refill_rate))
    last_refill = now
end

-- 2. Evaluar y descontar
local allowed = 0
local retry_after = 0

if current_tokens >= cost then
    allowed = 1
    current_tokens = current_tokens - cost
else
    allowed = 0
    local missing = cost - current_tokens
    -- Redondeo hacia arriba con ceil para dar segundos enteros útiles,
    -- o cálculo con un piso mínimo garantizado de espera.
    retry_after = math.max(1.0, math.ceil(missing / refill_rate))
end

-- 3. Guardar estado
redis.call("HMSET", key, "tokens", current_tokens, "last_refill", last_refill)

local ttl = math.ceil(capacity / refill_rate) + 60
redis.call("EXPIRE", key, ttl)

return { allowed, math.floor(current_tokens), retry_after, math.floor(capacity) }
#!/usr/bin/env bash
# =========================================================================
# DISTRIBUTED TRAFFIC LIMITER - FAST BURST BENCHMARK
# =========================================================================

URL="http://127.0.0.1:8000/api/v1/bench-traffic"
TENANT="bench_$(date +%s)"
TOTAL_REQUESTS=35

echo "------------------------------------------------------------"
echo "Ejecutando rafaga de estres (Token Bucket: Cap 20, Recarga 5/s)..."
echo "Tenant ID: $TENANT"
echo "Total de peticiones: $TOTAL_REQUESTS"
echo "------------------------------------------------------------"

PASSED=0
BLOCKED=0
ERRORS=0

for i in $(seq 1 $TOTAL_REQUESTS); do
    CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "X-Tenant-ID: $TENANT" "$URL")
    
    if [ "$CODE" = "200" ]; then
        ((PASSED++))
    elif [ "$CODE" = "429" ]; then
        ((BLOCKED++))
    else
        ((ERRORS++))
    fi
done

echo ""
echo "================ RESULTADOS DEL BENCHMARK ================"
echo "Peticiones Aprobadas (HTTP 200): $PASSED (Esperado: 20 tokens)"
echo "Peticiones Bloqueadas (HTTP 429): $BLOCKED (Esperado: 15 bloqueadas)"
echo "Errores Inesperados: $ERRORS"
echo "=========================================================="

if [ "$ERRORS" -eq 0 ] && [ "$PASSED" -ge 20 ] && [ "$BLOCKED" -gt 0 ]; then
    echo " PRUEBA SUPERADA: Bucket agotado en ráfaga y 429 activado."
    exit 0
else
    echo " PRUEBA FALLIDA."
    exit 1
fi
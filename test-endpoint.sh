#!/bin/bash

# Reputation API Testing Script
# This script tests the /api/reputation/scan endpoint

API_URL="http://localhost:8000/api/reputation/scan"
HEADER_JSON="Content-Type: application/json"

echo "=========================================="
echo "Reputation API - Endpoint Testing"
echo "=========================================="
echo ""
echo "Make sure the Laravel development server is running:"
echo "  cd /home/cloud/Videos/reputation/be && php artisan serve"
echo ""

# Test 1: Website-based verification (simplest case)
echo "TEST 1: Website-based Verification"
echo "Request:"
echo '{
  "website": "apple.com"
}'
echo ""
echo "Response:"
curl -X POST "$API_URL" \
  -H "$HEADER_JSON" \
  -d '{
    "website": "apple.com"
  }' \
  -s | jq . || echo "Error: Could not parse response. Server might not be running."

echo ""
echo "-------------------------------------------"
echo ""

# Test 2: Website + Business Name
echo "TEST 2: Website + Business Name"
echo "Request:"
echo '{
  "business_name": "Apple",
  "website": "apple.com"
}'
echo ""
echo "Response:"
curl -X POST "$API_URL" \
  -H "$HEADER_JSON" \
  -d '{
    "business_name": "Apple",
    "website": "apple.com"
  }' \
  -s | jq . || echo "Error: Could not parse response."

echo ""
echo "-------------------------------------------"
echo ""

# Test 3: Phone + Location verification
echo "TEST 3: Phone + Location Verification"
echo "Request:"
echo '{
  "phone": "+1-415-123-4567",
  "location": "San Francisco, CA"
}'
echo ""
echo "Response:"
curl -X POST "$API_URL" \
  -H "$HEADER_JSON" \
  -d '{
    "phone": "+1-415-123-4567",
    "location": "San Francisco, CA"
  }' \
  -s | jq . || echo "Error: Could not parse response."

echo ""
echo "-------------------------------------------"
echo ""

# Test 4: Phone + Location + Business Name
echo "TEST 4: Phone + Location + Business Name"
echo "Request:"
echo '{
  "business_name": "Apple Restaurant",
  "phone": "+1-415-123-4567",
  "location": "San Francisco, CA"
}'
echo ""
echo "Response:"
curl -X POST "$API_URL" \
  -H "$HEADER_JSON" \
  -d '{
    "business_name": "Apple Restaurant",
    "phone": "+1-415-123-4567",
    "location": "San Francisco, CA"
  }' \
  -s | jq . || echo "Error: Could not parse response."

echo ""
echo "-------------------------------------------"
echo ""

# Test 5: Error test - Ambiguous business (no identifiers)
echo "TEST 5: Error Case - Ambiguous Business"
echo "Request (should fail):"
echo '{
  "business_name": "Pizza Restaurant"
}'
echo ""
echo "Expected: 422 Error - AMBIGUOUS_BUSINESS"
echo "Response:"
curl -X POST "$API_URL" \
  -H "$HEADER_JSON" \
  -d '{
    "business_name": "Pizza Restaurant"
  }' \
  -s | jq . || echo "Error: Could not parse response."

echo ""
echo "-------------------------------------------"
echo ""

# Test 6: Error test - Invalid domain
echo "TEST 6: Error Case - Invalid Domain"
echo "Request (should fail):"
echo '{
  "website": "invalid-domain-that-does-not-exist-12345.com"
}'
echo ""
echo "Expected: 400 Error - INVALID_DOMAIN"
echo "Response:"
curl -X POST "$API_URL" \
  -H "$HEADER_JSON" \
  -d '{
    "website": "invalid-domain-that-does-not-exist-12345.com"
  }' \
  -s | jq . || echo "Error: Could not parse response."

echo ""
echo "=========================================="
echo "Tests Complete"
echo "=========================================="

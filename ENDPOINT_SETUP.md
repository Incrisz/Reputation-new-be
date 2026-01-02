# Reputation API - Endpoint Setup Complete ✅

## Endpoint Created

### Main Endpoint
```
POST /api/reputation/scan
Content-Type: application/json
```

## Architecture

### 6 Backend Services Implemented

1. **BusinessVerificationService** - Verifies business via website OR phone+location
2. **SerperSearchService** - Searches web using 8 targeted queries, filters for high-signal results
3. **OpenAISentimentAnalyzer** - Analyzes sentiment and extracts themes using LLM
4. **ReputationScoringEngine** - Calculates 0-100 reputation score with source weighting
5. **RecommendationGenerator** - Generates 3-5 AI-powered improvement recommendations
6. **ReputationScanService** - Orchestrates all services in workflow

### Controller
**ReputationController** - Handles request validation, error handling, and response formatting

## Configuration

### Environment Variables Set
- `LLM_PROVIDER=openrouter` (uses Claude via OpenRouter - you can switch to `openai`)
- `OPENAI_API_KEY` - OpenAI API key configured
- `OPENROUTER_API_KEY` - OpenRouter API key (Claude 3.7 Sonnet)
- `SERPER_API_KEY` - Serper search API key

### Services Configuration
Added to `config/services.php`:
- LLM provider settings (OpenAI & OpenRouter)
- Serper API configuration

## Request Examples

### Option 1: Website-based Verification
```json
{
  "website": "apple.com"
}
```

### Option 2: Website + Business Name
```json
{
  "business_name": "Apple",
  "website": "apple.com",
  "industry": "Technology"
}
```

### Option 3: Phone + Location
```json
{
  "location": "San Francisco, CA",
  "phone": "+1-415-123-4567"
}
```

### Option 4: Phone + Location + Business Name
```json
{
  "business_name": "Apple Restaurant",
  "location": "San Francisco, CA",
  "phone": "+1-415-123-4567"
}
```

## Response Format (200 Success)
```json
{
  "status": "success",
  "business_name": "Apple",
  "verified_website": "apple.com",
  "verified_location": null,
  "verified_phone": null,
  "scan_date": "2025-12-31T10:00:00Z",
  "results": {
    "reputation_score": 85,
    "sentiment_breakdown": {
      "positive": 67,
      "negative": 20,
      "neutral": 13
    },
    "top_themes": [
      {
        "theme": "great food",
        "frequency": 8,
        "sentiment": "positive"
      }
    ],
    "top_mentions": [
      {
        "url": "https://yelp.com/...",
        "title": "Great experience!",
        "sentiment": "positive",
        "source": "reviews"
      }
    ],
    "recommendations": [
      "Focus on service speed",
      "Highlight food quality in marketing"
    ]
  }
}
```

## Error Responses

### 422 - Ambiguous Business
```json
{
  "status": "error",
  "code": "AMBIGUOUS_BUSINESS",
  "message": "Business name alone is too ambiguous",
  "details": "Provide website OR (phone + location)"
}
```

### 400 - Invalid Domain
```json
{
  "status": "error",
  "code": "INVALID_DOMAIN",
  "message": "Domain is not accessible",
  "details": "Please check the website URL"
}
```

### 404 - Business Not Found
```json
{
  "status": "error",
  "code": "BUSINESS_NOT_FOUND",
  "message": "No mentions found for this business",
  "details": "Try verifying with different business information"
}
```

### 403 - Domain Verification Failed
```json
{
  "status": "error",
  "code": "DOMAIN_VERIFICATION_FAILED",
  "message": "Could not verify business name on website",
  "details": "Please ensure the business name appears on your website"
}
```

### 429 - Rate Limited
- 10 requests per minute per IP (throttle:10,1)

### 500 - Analysis Error
```json
{
  "status": "error",
  "code": "ANALYSIS_ERROR",
  "message": "An error occurred during analysis"
}
```

## Running the API

### Start Development Server
```bash
cd /home/cloud/Videos/reputation/be
php artisan serve
```

Server runs at `http://localhost:8000`

### Test the Endpoint
```bash
curl -X POST http://localhost:8000/api/reputation/scan \
  -H "Content-Type: application/json" \
  -d '{"website": "example.com"}'
```

## Source Weighting System

The API uses source credibility weighting for accurate reputation scoring:

| Source Type | Weight | Impact |
|------------|--------|--------|
| Major news publishers | 1.0 | Highest |
| Review platforms (Yelp, Google) | 0.8 | High |
| Forums (Reddit) | 0.6 | Medium |
| Social posts | 0.5 | Medium-low |
| Blogs / personal sites | 0.4 | Low |

## Process Flow

1. **Verification** - Validate business with website OR phone+location
2. **Search** - Execute 8 targeted Serper queries
3. **Filtering** - Keep only high-signal mentions (20 max)
4. **Analysis** - Send extracted content to LLM for sentiment + themes
5. **Scoring** - Calculate reputation score (0-100) with source weighting
6. **Recommendations** - Generate actionable improvement suggestions

## Next Steps

### Phase 2: Test All Services
- Test both verification paths (website and phone+location)
- Test with real business data
- Verify all 6 error codes work correctly
- Load testing

### Phase 3: Frontend Integration
- Build Next.js form with smart input validation
- Create reputation dashboard
- Display top mentions with links
- Show sentiment breakdown charts

### Phase 4: Polish & Deploy
- Performance optimization
- Security review
- Production deployment

---

**Status**: Phase 1 Complete ✅  
**All 6 Services**: Implemented ✅  
**All Error Codes**: Configured ✅  
**Rate Limiting**: Enabled (10/min) ✅  
**LLM Integration**: Dual-provider (OpenAI & OpenRouter) ✅  
**Serper Integration**: High-signal filtering ✅

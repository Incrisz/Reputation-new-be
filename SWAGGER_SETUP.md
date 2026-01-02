# 🎯 SWAGGER API DOCUMENTATION - SETUP COMPLETE

## Access Swagger UI

Once the Laravel development server is running, visit:

```
http://localhost:8000/api/docs
```

**Status**: ✅ **READY TO USE**

All Swagger documentation is set up and ready for testing!

---

## What's Included

### 1. Interactive Swagger UI
- **URL**: `http://localhost:8000/api/docs`
- **Features**:
  - Try out endpoint requests directly in the browser
  - View complete API documentation
  - See example requests and responses
  - Understand all error codes

### 2. Complete OpenAPI 3.0 Specification
- **File**: `storage/api-docs/openapi.yaml`
- **Contains**:
  - Full API endpoint documentation
  - Request/response schemas
  - All error codes and examples
  - Source weighting explanation
  - Feature descriptions

### 3. Dedicated Swagger Controller
- **File**: `app/Http/Controllers/SwaggerController.php`
- **Endpoints**:
  - `GET /api/docs` - Swagger UI
  - `GET /api/docs/spec` - Download OpenAPI spec

---

## How to Use Swagger to Test the API

### Step 1: Start the Server
```bash
cd /home/cloud/Videos/reputation/be
php artisan serve
```

Output should show:
```
Laravel development server started:
http://127.0.0.1:8000
```

### Step 2: Open Swagger UI
Open your browser and go to:
```
http://localhost:8000/api/docs
```

You should see the Reputation AI API documentation page.

### Step 3: Test the Endpoint

#### Example 1: Website-based Verification

1. Click on the **POST /api/reputation/scan** endpoint
2. Click the **"Try it out"** button
3. In the request body, enter:
```json
{
  "website": "apple.com"
}
```
4. Click **"Execute"**
5. View the response below

#### Example 2: Phone + Location

1. Click **"Try it out"** again
2. Clear the previous body and enter:
```json
{
  "business_name": "Apple Restaurant",
  "phone": "+1-415-123-4567",
  "location": "San Francisco, CA"
}
```
3. Click **"Execute"**

#### Example 3: Website + Name

1. Click **"Try it out"**
2. Enter:
```json
{
  "business_name": "Apple",
  "website": "apple.com",
  "industry": "Technology"
}
```
3. Click **"Execute"**

---

## Understanding Swagger UI

### Top Section: API Info
- **Title**: Reputation AI API
- **Version**: 1.0.0
- **Description**: Overview of what the API does
- **Contact**: Support information

### Middle Section: Endpoints
- **POST /api/reputation/scan**: The main endpoint
  - Click to expand
  - See request and response schemas
  - View all error codes
  - Try it out directly

### Response Examples
The Swagger UI shows:
- **200**: Success response with full reputation analysis
- **400**: Invalid domain error
- **403**: Domain verification failed
- **404**: Business not found
- **422**: Ambiguous business or validation error
- **429**: Rate limit exceeded
- **500**: Server error

---

## API Endpoint Documentation

### POST /api/reputation/scan

**Purpose**: Analyze business online reputation

**Authentication**: None required (future: API key)

**Rate Limit**: 10 requests per minute per IP

**Response Time**: 15-45 seconds

### Request Format

#### Valid Input Combinations

```json
// Option 1: Website Only
{
  "website": "apple.com"
}

// Option 2: Website + Name
{
  "business_name": "Apple",
  "website": "apple.com",
  "industry": "Technology"
}

// Option 3: Phone + Location
{
  "phone": "+1-415-123-4567",
  "location": "San Francisco, CA"
}

// Option 4: Phone + Location + Name
{
  "business_name": "Apple Restaurant",
  "phone": "+1-415-123-4567",
  "location": "San Francisco, CA"
}
```

#### Field Descriptions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `business_name` | string | Optional | Business name (2-100 chars) |
| `website` | string | Conditional | Business website URL |
| `phone` | string | Conditional | Business phone number |
| `location` | string | Conditional | City, state (with phone) |
| `industry` | string | Optional | Business industry (max 50 chars) |

**Note**: Must provide either `website` OR (`phone` AND `location`)

### Response Format

#### Success Response (200)

```json
{
  "status": "success",
  "business_name": "Apple",
  "verified_website": "https://apple.com",
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
        "theme": "great products",
        "frequency": 12,
        "sentiment": "positive"
      }
    ],
    "top_mentions": [
      {
        "url": "https://www.yelp.com/biz/apple",
        "title": "Amazing experience!",
        "sentiment": "positive",
        "source": "reviews"
      }
    ],
    "recommendations": [
      "Focus on maintaining product quality",
      "Leverage positive feedback in marketing"
    ]
  }
}
```

#### Error Response (422 - Ambiguous)

```json
{
  "status": "error",
  "code": "AMBIGUOUS_BUSINESS",
  "message": "Business name alone is too ambiguous",
  "details": "Provide website OR (phone + location)"
}
```

#### Error Response (400 - Invalid Domain)

```json
{
  "status": "error",
  "code": "INVALID_DOMAIN",
  "message": "Domain is not accessible",
  "details": "Please check the website URL"
}
```

#### Error Response (404 - Not Found)

```json
{
  "status": "error",
  "code": "BUSINESS_NOT_FOUND",
  "message": "No mentions found for this business",
  "details": null
}
```

#### Error Response (403 - Verification Failed)

```json
{
  "status": "error",
  "code": "DOMAIN_VERIFICATION_FAILED",
  "message": "Could not verify business name on website",
  "details": "Please ensure the business name appears on your website"
}
```

#### Error Response (429 - Rate Limited)
```
HTTP/1.1 429 Too Many Requests
```

#### Error Response (500 - Server Error)

```json
{
  "status": "error",
  "code": "ANALYSIS_ERROR",
  "message": "An error occurred during analysis"
}
```

---

## Swagger Features

### Expandable Sections
Click on any section to expand/collapse:
- Endpoint details
- Request body schema
- Response schemas
- Error codes

### Schema Viewer
- Shows all fields and their types
- Displays field descriptions
- Shows required vs optional fields
- Provides validation rules

### Example Data
- Pre-filled example requests
- Example responses for each status code
- Real-world business scenarios

### Try It Out
- Fill in request body
- Click "Execute"
- See live response
- Check response headers
- View response time

---

## Files Created for Swagger

### 1. OpenAPI Specification
**File**: `storage/api-docs/openapi.yaml`
- Complete API specification
- All schemas and examples
- Error codes and descriptions
- Request/response formats

### 2. Swagger Controller
**File**: `app/Http/Controllers/SwaggerController.php`
- Serves Swagger UI
- Provides OpenAPI spec
- Handles documentation routes

### 3. Swagger UI View
**File**: `resources/views/swagger/index.blade.php`
- Interactive API documentation
- Swagger UI styling
- Built-in testing interface

### 4. Routes
**File**: `routes/web.php` (updated)
- `/api/docs` - Swagger UI
- `/api/docs/spec` - OpenAPI spec

---

## Source Weighting in Swagger Docs

The documentation explains the source credibility system:

| Source | Weight | Impact |
|--------|--------|--------|
| News Publishers | 1.0 | Highest impact on score |
| Review Platforms | 0.8 | High impact (Yelp, Google) |
| Forums | 0.6 | Medium impact (Reddit) |
| Social Media | 0.5 | Medium-low impact |
| Blogs/Personal | 0.4 | Low impact |

---

## Testing Workflow

### Quick Test Sequence

1. **Start Server**
   ```bash
   php artisan serve
   ```

2. **Open Swagger**
   ```
   http://localhost:8000/api/docs
   ```

3. **Test 1: Website Only**
   - Endpoint: POST /api/reputation/scan
   - Body: `{"website": "apple.com"}`
   - Expected: Success with reputation analysis

4. **Test 2: Error Case**
   - Body: `{"business_name": "Pizza Restaurant"}`
   - Expected: 422 error (AMBIGUOUS_BUSINESS)

5. **Test 3: Phone + Location**
   - Body: `{"phone": "+1-415-123-4567", "location": "San Francisco, CA"}`
   - Expected: Success with reputation analysis

---

## Troubleshooting

### Swagger UI Not Loading?
1. Make sure server is running: `php artisan serve`
2. Check URL: `http://localhost:8000/api/docs`
3. Clear browser cache
4. Try a different browser

### Getting 404 Error on /api/docs?
```bash
# Verify routes are registered
php artisan route:list | grep swagger
```

Should show:
```
GET|HEAD   api/docs ...................... swagger.ui › SwaggerController@index
GET|HEAD   api/docs/spec .............. swagger.spec › SwaggerController@spec
```

### OpenAPI Spec Not Found?
```bash
# Check file exists
ls -la storage/api-docs/openapi.yaml

# Should output: 
# -rw-r--r-- ... openapi.yaml
```

### Can't Execute Requests?
1. Make sure API endpoint is working first
2. Try in browser JavaScript console
3. Check CORS settings (if needed)
4. Verify API response format matches spec

---

## Browser Compatibility

Swagger UI works in:
- ✅ Chrome/Edge (latest)
- ✅ Firefox (latest)
- ✅ Safari (latest)
- ✅ Mobile browsers

---

## Next Steps

### After Testing in Swagger:

1. **Verify All Error Cases**
   - Test invalid domain
   - Test ambiguous business
   - Test rate limiting

2. **Check Response Times**
   - First scan: ~45 seconds
   - Cached: ~5-10 seconds

3. **Review Source Data**
   - Check top mentions
   - Verify sentiment analysis
   - Review recommendations

4. **Prepare for Frontend**
   - Note response structure
   - Plan dashboard layout
   - Design form inputs

---

## API Documentation Structure

```
/api/docs                          ← Swagger UI (human readable)
└── Interactive testing interface
    ├── Try it out feature
    ├── Example requests
    ├── Live responses
    └── Error code reference

/api/docs/spec                     ← OpenAPI YAML spec
    └── Machine-readable format
        ├── Full schema definitions
        ├── All endpoints
        └── Complete documentation

/api/reputation/scan               ← Actual endpoint
    ├── POST method
    ├── Rate limited (10/min)
    └── Returns reputation analysis
```

---

## Quick Command Reference

```bash
# Start server
php artisan serve

# Check routes
php artisan route:list | grep -E "swagger|reputation"

# Check file exists
ls -la storage/api-docs/openapi.yaml
ls -la app/Http/Controllers/SwaggerController.php
ls -la resources/views/swagger/index.blade.php

# View controller
cat app/Http/Controllers/SwaggerController.php

# View OpenAPI spec
cat storage/api-docs/openapi.yaml | head -50
```

---

## Summary

✅ **Swagger UI installed and configured**  
✅ **OpenAPI 3.0 specification created**  
✅ **Documentation routes added**  
✅ **Interactive testing enabled**  
✅ **Examples and error codes included**  

**Access at**: http://localhost:8000/api/docs

**Start testing now!** 🚀

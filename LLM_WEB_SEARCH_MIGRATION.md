# Migration Guide: Serper → LLM Web Search

## Overview
This guide explains how to migrate from the **Serper API** to **LLMWebSearchService** which uses your LLM provider's function calling capabilities with web search tools—saving costs while maintaining functionality.

## Key Benefits
- **Cost Savings**: No separate Serper API subscription needed
- **Unified LLM Usage**: Consolidates all API calls to one provider (OpenAI/OpenRouter)
- **Token Efficiency**: Leverages your existing LLM budget
- **Same Results**: Returns data in identical format to SerperSearchService

## Implementation

### Step 1: Configuration
Add a search provider switch in `.env`:

- `SEARCH_PROVIDER=llm` (use `LLMWebSearchService`)
- `SEARCH_PROVIDER=serper` (use `SerperSearchService`)
- `SEARCH_LLM_FALLBACK_TO_SERPER=true` (optional safety fallback when LLM returns no mentions)

The LLM service still uses existing settings:
- `LLM_PROVIDER` (openai or openrouter)
- `OPENAI_API_KEY` / `OPENROUTER_API_KEY`
- `OPENAI_MODEL` / `OPENROUTER_MODEL`

### Step 2: Update Service Container
In your service provider or wherever you register services, update the binding:

Bind `SearchServiceContract` and select implementation by `SEARCH_PROVIDER`:

```php
$this->app->bind(SearchServiceContract::class, function () {
    return match (config('services.search.provider', 'llm')) {
        'serper' => new SerperSearchService(),
        default => new LLMWebSearchService(),
    };
});
```

### Step 3: Update ReputationScanService

Instead of replacing directly, you can use dependency injection with the interface:

```php
// Option A: Use interface (recommended)
public function __construct(
    SearchServiceContract $searchService,
    // ... other services
) {
    $this->searchService = $searchService;
}

// Option B: Direct replacement
// Change: SerperSearchService $searchService
// To: LLMWebSearchService $searchService
```

### Step 4: Update OpenAISentimentAnalyzer

Inject `SearchServiceContract` and use it for content extraction:

```php
public function __construct(SearchServiceContract $searchService)
{
    $this->searchService = $searchService;
}
```

## Migration Steps

1. **Set provider in `.env`**:
```dotenv
SEARCH_PROVIDER=llm
# SEARCH_PROVIDER=serper
```

2. **Test the configured service** with a test endpoint first:
```php
// In a test route or controller
Route::get('/test-llm-search', function () {
    $search = app(SearchServiceContract::class);
    $results = $search->search('My Business Name', 'New York');
    return response()->json($results);
});
```

3. **Update ReputationScanService** to use `SearchServiceContract`

4. **Update OpenAISentimentAnalyzer** to use `SearchServiceContract`

5. **Test the reputation scan endpoint** with sample business data

6. **Monitor API usage** in your OpenAI/OpenRouter dashboard

7. **Optionally keep both services** and switch instantly via `SEARCH_PROVIDER`

## API Response Format (Identical)

Both services return the same structure:

```php
[
    'success' => true/false,
    'mentions' => [
        [
            'url' => 'https://example.com',
            'title' => 'Page Title',
            'snippet' => 'Preview text...',
            'source' => 'reviews|social|forum|news|blog',
            'source_weight' => 0.8,
            'content' => null // populated by extractContent()
        ],
        // ... more mentions
    ],
    'total_mentions' => 5
]
```

## Cost Comparison

| Metric | Serper API | LLM Web Search |
|--------|-----------|----------------|
| Per search call | $0.0025 | ~$0.001-0.005* |
| Setup cost | $10-20/month minimum | Included in LLM budget |
| Scaling | Linear add-on cost | Normalized with existing usage |

*Cost depends on your LLM provider's token pricing and model choice

## Limitations & Considerations

1. **LLM Rate Limits**: Subject to your LLM provider's rate limits
2. **Search Quality**: Depends on LLM's knowledge cutoff and parameters
3. **Real-time Data**: Less guaranteed than Serper (depends on LLM's training data)
4. **Fallback Strategy**: Keep Serper key configured as backup if needed

## Fallback Configuration

If you want a hybrid approach (use LLM first, fall back to Serper):

```php
public function search(string $businessName, ?string $location = null): array
{
    try {
        // Try LLM web search first
        $searchService = new LLMWebSearchService();
        $results = $searchService->search($businessName, $location);
        
        if ($results['success']) {
            return $results;
        }
    } catch (\Exception $e) {
        \Log::warning('LLM search failed, falling back to Serper');
    }
    
    // Fallback to Serper if LLM fails
    $searchService = new SerperSearchService();
    return $searchService->search($businessName, $location);
}
```

## Troubleshooting

**Issue**: Empty results from LLM search
- **Solution**: LLM may need more specific prompts. Check your model supports function calling with web_search

**Issue**: Different result quality than Serper
- **Solution**: Adjust the `generateSearchQueries()` method to fine-tune queries

**Issue**: API rate limiting
- **Solution**: Add exponential backoff in `executeWebSearch()` method

## Rollback Plan

To revert to Serper:
1. Change service binding back to `SerperSearchService`
2. Restart your application
3. No data migration needed

## Questions?

See: [ENDPOINT_SETUP.md](ENDPOINT_SETUP.md) and [SWAGGER_SETUP.md](SWAGGER_SETUP.md) for API integration details.

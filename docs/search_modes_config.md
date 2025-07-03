# Search API PostgreSQL - Search Modes

## Automatic NLP Processing in Views

When a user searches through your Views exposed filter, the module automatically:

### Hybrid Search (Default - Recommended)
```php
// User searches: "comfortable running shoes"
// Backend automatically:
// 1. Traditional search: matches "comfortable", "running", "shoes" 
// 2. AI semantic search: finds products about "athletic footwear", "jogging gear", "sports comfort"
// 3. Combines results with configurable weights

$query = $index->query();
$query->keys('comfortable running shoes');
// Hybrid mode active by default - no code needed!
```

### Configuration in Backend
```yaml
hybrid_search:
  text_weight: 0.6    # Traditional search influence
  vector_weight: 0.4  # AI semantic influence  
  similarity_threshold: 0.15
```

### Search Intelligence Examples

**User Query**: "eco-friendly vehicles"
- **Traditional match**: Content with exact words "eco-friendly" and "vehicles"  
- **AI semantic match**: Content about "sustainable transportation", "green cars", "electric mobility"
- **Result**: Intelligent results covering both exact matches and semantically related content

**User Query**: "budget laptop for students" 
- **Traditional**: Matches "budget", "laptop", "students"
- **AI Semantic**: Finds "affordable computers", "educational technology", "portable computers for academia"
- **Result**: Comprehensive results including synonyms and related concepts

## Graceful Degradation

If AI services are unavailable:
```php
// Module automatically falls back to traditional PostgreSQL full-text search
// User experience remains uninterrupted
// Admin gets notified of degraded functionality
```

## Performance Benefits

1. **Caching**: AI embeddings cached to avoid repeated API calls
2. **Batch Processing**: Multiple searches processed efficiently  
3. **Queue Support**: Heavy AI processing moved to background
4. **Smart Fallbacks**: Never fails completely - always provides results
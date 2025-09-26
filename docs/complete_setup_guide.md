# Complete NLP-Powered Search UI Setup

## Prerequisites Setup

### 1. Module Installation
```bash
composer require drupal/search_api_postgresql drupal/key drupal/search_api_autocomplete
drush en search_api_postgresql key search_api_autocomplete
```

### 2. Secure Credential Storage  
```
Admin → Configuration → System → Keys → Add Key
- Key name: "Azure OpenAI API Key"
- Key type: Authentication  
- Key provider: Configuration (or Environment for production)
- Key value: [Your Azure OpenAI API Key]
```

## Backend Configuration

### 3. Create PostgreSQL Server with AI
```
Admin → Configuration → Search → Search API → Add Server
- Server name: "AI-Powered Search"
- Backend: "PostgreSQL with Azure AI Vector Search"
- Database config: [Your PostgreSQL details]
- Password Key: [Select your database password key]

Azure AI Configuration:
- Enable Azure AI Vector Search: Yes
- Endpoint: https://your-resource.openai.azure.com/
- API Key: [Select your API key]
- Model: text-embedding-3-small
- Hybrid Search Weights:
  - Text Search: 0.6
  - Vector Search: 0.4
```

### 4. Create Smart Search Index
```
Admin → Configuration → Search → Search API → Add Index
- Index name: "Intelligent Content Index"
- Data sources: Content
- Server: [Your AI-powered server]

Fields to Index:
- Title (Fulltext) 
- Body (Fulltext)
- Tags (Fulltext)
- Created date
- Content type

Processors:
- HTML filter
- Ignore case
- Ignore characters
- Stopwords
- Content access (important!)
```

## Views Configuration

### 5. Create AI-Powered Search View
```
Admin → Structure → Views → Add View
- View name: "Smart Search"
- Show: "Intelligent Content Index" (your search index)
- Create a page: Yes
- Page title: "Search Results"
- Path: /search

Display Settings:
- Format: Unformatted list
- Show: Fields
- Items per page: 10
- Use pager: Yes

Fields to Display:
- Title (linked to content)
- Body (trimmed, 200 chars)
- Content type
- Created date
- Search excerpt (if available)
- Relevance score (for debugging)

Filter Criteria:
- "Search: Fulltext search" → Settings:
  - Expose filter: Yes
  - Label: "Search"
  - Required: No
  - Identifier: keys
  - Remember: No

Sort Criteria:
- "Search: Relevance" (descending)
- Created date (descending) as secondary

Advanced → Exposed Form:
- Exposed form in block: Yes
- Exposed form style: Input required
- Text on demand: "Enter search terms to find content"
- Submit button text: "Search"
- Reset button: Yes
```

### 6. Enable Autocomplete
```
Admin → Configuration → Search → Search API → [Your Index] → Autocomplete
- Enable autocomplete: Yes
- Suggester plugins:
  - Live results
  - Terms from indexed fields
  
Configuration:
- Display label: Yes
- Fields: Title, Body, Tags
- Characters: 2
- Results: 8
```

## Frontend Integration

### 7. Place Search Block
```
Admin → Structure → Block layout
- Add "Exposed form: smart-search-page" block
- Region: Header (or preferred location)
- Configure block:
  - Display title: No
  - Title: "Search Site"
```

### 8. Custom Search Page Template (Optional)
```twig
{# templates/views-view--smart-search.html.twig #}
<div class="intelligent-search-results">
  <div class="search-info">
    {% if view.result %}
      <p>{{ view.result|length }} results found</p>
    {% endif %}
  </div>
  
  {{ view.header }}
  
  <div class="search-results">
    {{ view.rows }}
  </div>
  
  {{ view.pager }}
  {{ view.footer }}
</div>
```

### 9. Enhanced Styling (Optional)
```css
/* Autocomplete enhancements */
.ui-autocomplete {
  border: 1px solid #ddd;
  border-radius: 4px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.ui-autocomplete .ui-menu-item {
  padding: 8px 12px;
  border-bottom: 1px solid #eee;
}

.ui-autocomplete .ui-menu-item:hover {
  background: #f8f9fa;
}

/* Search results styling */
.intelligent-search-results .views-row {
  margin-bottom: 2rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid #eee;
}

.search-excerpt {
  color: #666;
  margin-top: 0.5rem;
}

.search-relevance {
  font-size: 0.8rem;
  color: #999;
  margin-top: 0.25rem;
}
```

## Testing the AI Features

### 10. Test Search Intelligence
Try these searches to see AI in action:

**Semantic Understanding:**
- Search "automobile" → Should find "car", "vehicle" content
- Search "happy" → Should find "joy", "celebration", "positive" content  
- Search "budget-friendly" → Should find "affordable", "cheap", "cost-effective"

**Intent Recognition:**
- "How to cook pasta" → Finds cooking instructions
- "Best laptops 2024" → Finds computer recommendations
- "Eco-friendly products" → Finds sustainable/green content

**Typo Tolerance:**
- "reccomendations" → Still finds "recommendations"
- "enviromental" → Still finds "environmental" content

## Monitoring and Analytics

### 11. Monitor AI Performance
```
Admin → Configuration → Search → Search API PostgreSQL → Analytics
- View search analytics
- Monitor AI embedding coverage
- Track hybrid search performance
- Cost tracking for API usage
```

### 12. Health Monitoring
```bash
# Check AI service health
drush search-api-postgresql:test-ai your_server_id

# Monitor vector coverage  
drush search-api-postgresql:embedding-stats your_index_id

# Queue status (if using background processing)
drush search-api-postgresql:queue-status
```

## Advanced Features

### Faceted Search Integration
The AI backend works seamlessly with Search API facets:
```
composer require drupal/facets
# Configure facets normally - AI search results work with all facet filters
```

### Custom Search API Processors
The module's AI features work with all standard Search API processors:
- HTML filter
- Stemming
- Stopwords  
- Custom field processors
- Content access controls

### Multi-language Support
```yaml
# In backend configuration
fts_configuration: 'french'  # or german, spanish, etc.
# AI embeddings automatically work across languages
```

This setup gives you a fully AI-powered search experience that's completely transparent to users - they just get better, more intelligent search results automatically!
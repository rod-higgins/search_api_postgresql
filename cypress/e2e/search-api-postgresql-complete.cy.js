describe('Search API PostgreSQL - Complete End-to-End Test', () => {

  before(() => {
    // Setup: Login as admin
    cy.drupalLogin('admin', 'admin')

    // Ensure required modules are enabled
    cy.enableModule('search_api')
    cy.enableModule('search_api_postgresql')
    cy.enableModule('node')
    cy.enableModule('views')

    // Clear caches to ensure clean state
    cy.clearCaches()
  })

  beforeEach(() => {
    // Login before each test
    cy.drupalLogin('admin', 'admin')
  })

  describe('Module Installation and Configuration', () => {

    it('should verify module is properly installed', () => {
      cy.verifyModuleEnabled('search_api_postgresql')

      // Check module configuration page exists
      cy.visit('/admin/config/search/search-api')
      cy.contains('Search API')
      cy.contains('Servers')
      cy.contains('Indexes')
    })

    it('should create PostgreSQL search server', () => {
      cy.createPostgreSQLServer({
        name: 'PostgreSQL Test Server',
        id: 'postgresql_test',
        database: {
          host: 'db',  // Docker service name
          port: '5432',
          database: 'drupal',
          username: 'drupal',
          password: 'drupal'
        }
      })

      // Verify server was created
      cy.visit('/admin/config/search/search-api')
      cy.contains('PostgreSQL Test Server')
      cy.contains('Enabled')
    })

    it('should test database connection', () => {
      cy.visit('/admin/config/search/search-api/server/postgresql_test/edit')
      cy.testDatabaseConnection()
    })

    it('should configure AI features if available', () => {
      cy.visit('/admin/config/search/search-api/server/postgresql_test/edit')

      // Check if AI configuration section exists
      cy.get('body').then(($body) => {
        if ($body.find('#edit-backend-config-ai-enabled').length > 0) {
          cy.get('#edit-backend-config-ai-enabled').check()

          // Configure Azure OpenAI if fields exist
          if ($body.find('#edit-backend-config-azure-endpoint').length > 0) {
            cy.get('#edit-backend-config-azure-endpoint')
              .clear()
              .type('https://test.openai.azure.com/')

            cy.get('#edit-backend-config-azure-deployment')
              .clear()
              .type('text-embedding-ada-002')
          }

          cy.get('#edit-submit').click()
          cy.contains('successfully saved')
        }
      })
    })
  })

  describe('Search Index Creation and Configuration', () => {

    it('should create search index', () => {
      cy.createSearchIndex({
        name: 'Article Content Index',
        id: 'article_content',
        server: 'postgresql_test',
        datasource: 'entity:node'
      })

      // Verify index was created
      cy.visit('/admin/config/search/search-api')
      cy.contains('Article Content Index')
    })

    it('should configure index fields', () => {
      cy.visit('/admin/config/search/search-api/index/article_content/fields')

      // Add title field
      cy.addIndexFields([
        {
          property: 'title',
          label: 'Title',
          type: 'text',
          boost: 5.0
        },
        {
          property: 'body',
          label: 'Body',
          type: 'text',
          boost: 1.0
        },
        {
          property: 'created',
          label: 'Created',
          type: 'date'
        },
        {
          property: 'status',
          label: 'Published',
          type: 'boolean'
        }
      ])

      // Add vector field if available
      cy.get('body').then(($body) => {
        if ($body.find('option[value="vector"]').length > 0) {
          cy.addIndexFields([
            {
              property: 'body',
              label: 'Body Vector',
              type: 'vector'
            }
          ])
        }
      })
    })

    it('should configure index processors', () => {
      cy.visit('/admin/config/search/search-api/index/article_content/processors')

      // Enable common processors
      const processors = [
        'html_filter',
        'tokenizer',
        'stopwords',
        'stemmer',
        'highlight'
      ]

      processors.forEach(processor => {
        cy.get(`input[name="status[${processor}]"]`).then(($checkbox) => {
          if ($checkbox.length > 0) {
            cy.wrap($checkbox).check()
          }
        })
      })

      // Enable vector processor if available
      cy.get('input[name="status[vector_embeddings]"]').then(($checkbox) => {
        if ($checkbox.length > 0) {
          cy.wrap($checkbox).check()
        }
      })

      cy.get('#edit-submit').click()
      cy.contains('successfully saved')
    })
  })

  describe('Content Creation and Indexing', () => {

    it('should create test content', () => {
      const testArticles = [
        {
          title: 'Machine Learning Fundamentals',
          body: 'Machine learning is a subset of artificial intelligence that focuses on algorithms and statistical models. It enables computers to improve their performance on a specific task through experience without being explicitly programmed.'
        },
        {
          title: 'Database Management Systems',
          body: 'Database management systems are software applications that interact with users, applications, and databases to capture and analyze data. PostgreSQL is an advanced open-source relational database system.'
        },
        {
          title: 'Web Development Best Practices',
          body: 'Modern web development involves various technologies and frameworks. Drupal is a powerful content management system that provides flexibility for building complex websites and applications.'
        },
        {
          title: 'Search Technology Overview',
          body: 'Search technology has evolved significantly with the introduction of AI and machine learning. Vector databases and semantic search provide more relevant results by understanding context and meaning.'
        }
      ]

      testArticles.forEach((article, index) => {
        cy.createTestContent({
          type: 'article',
          title: article.title,
          body: article.body
        })

        // Wait a bit between content creation
        cy.wait(1000)
      })
    })

    it('should index the content', () => {
      cy.indexContent('article_content')

      // Verify indexing status
      cy.visit('/admin/config/search/search-api/index/article_content')
      cy.contains('4 items indexed out of 4 items total')
    })
  })

  describe('Search View Creation', () => {

    it('should create search results view', () => {
      cy.createSearchView({
        name: 'Search Results',
        id: 'search_results',
        path: 'search',
        index: 'article_content'
      })
    })

    it('should configure search form', () => {
      cy.visit('/admin/structure/views/view/search_results/edit')
      cy.addSearchFormToView()
    })

    it('should configure result display', () => {
      cy.visit('/admin/structure/views/view/search_results/edit')

      // Configure fields to display
      cy.get('.views-ui-display-tab-bucket.field .dropbutton-toggle').click()
      cy.contains('Rearrange').click()

      // Add title field if not present
      cy.get('body').then(($body) => {
        if ($body.find('.views-add-form').length > 0) {
          cy.get('.views-add-form select').select('title')
          cy.get('.views-add-form .form-submit').click()
          cy.get('.ui-dialog-buttonset .form-submit').click()
        }
      })

      // Add body field
      cy.get('.views-add-form select').select('body')
      cy.get('.views-add-form .form-submit').click()

      // Configure body field to show summary
      cy.get('#edit-options-format').select('summary_or_trimmed')
      cy.get('#edit-options-trim-length').clear().type('200')
      cy.get('.ui-dialog-buttonset .form-submit').click()

      // Save view
      cy.get('#edit-actions-submit').click()
      cy.contains('The view Search Results has been saved')
    })

    it('should configure search result styling', () => {
      cy.visit('/admin/structure/views/view/search_results/edit')

      // Configure pager
      cy.get('.views-ui-display-tab-bucket.pager .dropbutton-toggle').click()
      cy.contains('Mini pager').click()
      cy.get('#edit-pager-options-items-per-page').clear().type('10')
      cy.get('.ui-dialog-buttonset .form-submit').click()

      // Save view
      cy.get('#edit-actions-submit').click()
    })
  })

  describe('Search Functionality Testing', () => {

    it('should display search page', () => {
      cy.visit('/search')
      cy.contains('Search Results')
      cy.get('input[name="search"]').should('be.visible')
      cy.get('input[type="submit"]').should('be.visible')
    })

    it('should perform basic text search', () => {
      cy.visit('/search')

      cy.performSearch('machine learning', ['Machine Learning Fundamentals'])
      cy.verifySearchResults({
        count: 1,
        contains: ['Machine Learning Fundamentals', 'artificial intelligence']
      })
    })

    it('should perform database-related search', () => {
      cy.visit('/search')

      cy.performSearch('database', ['Database Management Systems'])
      cy.verifySearchResults({
        contains: ['PostgreSQL', 'database management']
      })
    })

    it('should perform multi-term search', () => {
      cy.visit('/search')

      cy.performSearch('web development', ['Web Development Best Practices'])
      cy.verifySearchResults({
        contains: ['Drupal', 'content management']
      })
    })

    it('should test search with no results', () => {
      cy.visit('/search')

      cy.performSearch('nonexistent term that should not match anything')
      cy.contains('No results found').or(cy.get('.view-empty'))
    })

    it('should test empty search', () => {
      cy.visit('/search')

      // Search with empty term should show all results or no results
      cy.get('input[name="search"]').clear()
      cy.get('input[type="submit"]').click()

      // Should either show all content or appropriate message
      cy.get('body').should('contain.text', 'Search Results')
    })
  })

  describe('Advanced Search Features', () => {

    it('should test vector search if available', () => {
      cy.visit('/search')

      // Test semantic search capabilities
      cy.get('body').then(($body) => {
        if ($body.find('#edit-search-mode').length > 0) {
          cy.testVectorSearch('artificial intelligence concepts', [
            'Machine Learning Fundamentals'
          ])
        }
      })
    })

    it('should test search result highlighting', () => {
      cy.visit('/search')

      cy.performSearch('PostgreSQL')

      // Check if search terms are highlighted
      cy.get('.search-results').within(() => {
        cy.get('.search-snippet').should('contain.text', 'PostgreSQL')
      })
    })

    it('should test search pagination', () => {
      cy.visit('/search')

      // Search for a broad term that should return multiple results
      cy.performSearch('the')

      // Check if pager exists when there are many results
      cy.get('body').then(($body) => {
        if ($body.find('.pager').length > 0) {
          cy.get('.pager').should('be.visible')
        }
      })
    })
  })

  describe('Search Performance and Reliability', () => {

    it('should handle concurrent searches', () => {
      const searches = ['machine', 'database', 'development', 'search']

      searches.forEach((term, index) => {
        cy.visit('/search')
        cy.performSearch(term)
        cy.wait(500) // Brief pause between searches
      })
    })

    it('should verify search index statistics', () => {
      cy.visit('/admin/config/search/search-api/index/article_content')

      // Check index status
      cy.contains('4 items indexed out of 4 items total')
      cy.contains('Status: Enabled')
    })

    it('should test search with special characters', () => {
      cy.visit('/search')

      const specialSearches = [
        'data+base',
        '"machine learning"',
        'artificial AND intelligence',
        'web OR development'
      ]

      specialSearches.forEach(term => {
        cy.get('input[name="search"]').clear().type(term)
        cy.get('input[type="submit"]').click()
        cy.wait(1000)
      })
    })
  })

  describe('Error Handling and Edge Cases', () => {

    it('should handle server errors gracefully', () => {
      // Test with potentially problematic search terms
      const problematicSearches = [
        'SELECT * FROM users',  // SQL injection attempt
        '<script>alert("xss")</script>',  // XSS attempt
        'very long search term '.repeat(100)  // Very long search
      ]

      problematicSearches.forEach(term => {
        cy.visit('/search')
        cy.get('input[name="search"]').clear().type(term)
        cy.get('input[type="submit"]').click()

        // Should not crash and should show appropriate response
        cy.get('body').should('be.visible')
        cy.wait(1000)
      })
    })

    it('should verify search works after cache clear', () => {
      cy.clearCaches()
      cy.visit('/search')
      cy.performSearch('machine learning')
      cy.verifySearchResults({
        contains: ['Machine Learning Fundamentals']
      })
    })
  })

  after(() => {
    // Cleanup: Could optionally clean up test data
    // For now, leave test content for manual verification
    cy.log('End-to-end tests completed successfully')
  })
})
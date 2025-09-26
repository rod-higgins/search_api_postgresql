describe('Search API PostgreSQL - Administrative Routes and Screenshots', () => {

  before(() => {
    // Setup: Login as admin
    cy.drupalLogin('admin', 'admin')

    // Ensure required modules are enabled
    cy.enableModule('search_api')
    cy.enableModule('search_api_postgresql')
    cy.clearCaches()
  })

  beforeEach(() => {
    cy.drupalLogin('admin', 'admin')
  })

  describe('Administrative Dashboard Routes', () => {

    it('should visit and screenshot main dashboard', () => {
      cy.visit('/admin/config/search/search-api-postgresql')
      cy.contains('Search API PostgreSQL Administration')

      // Wait for page to fully load
      cy.wait(2000)

      // Take full page screenshot
      cy.screenshot('admin-dashboard-main', {
        capture: 'fullPage',
        overwrite: true
      })

      // Check for main dashboard elements
      cy.get('body').should('contain', 'PostgreSQL')
      cy.get('.search-api-postgresql-dashboard').should('be.visible')
    })

    it('should visit and screenshot embedding management', () => {
      cy.visit('/admin/config/search/search-api-postgresql/embeddings')
      cy.contains('Embedding Management')

      cy.wait(2000)
      cy.screenshot('admin-embedding-management', {
        capture: 'fullPage',
        overwrite: true
      })

      // Verify embedding management form elements
      cy.get('form').should('be.visible')
    })

    it('should visit and screenshot analytics page', () => {
      cy.visit('/admin/config/search/search-api-postgresql/analytics')
      cy.contains('Embedding Analytics')

      cy.wait(2000)
      cy.screenshot('admin-analytics', {
        capture: 'fullPage',
        overwrite: true
      })
    })

    it('should visit and screenshot bulk regenerate form', () => {
      cy.visit('/admin/config/search/search-api-postgresql/bulk-regenerate')
      cy.contains('Bulk Regenerate Embeddings')

      cy.wait(2000)
      cy.screenshot('admin-bulk-regenerate', {
        capture: 'fullPage',
        overwrite: true
      })

      // Verify form elements exist
      cy.get('form').should('be.visible')
    })

    it('should visit and screenshot cache management', () => {
      cy.visit('/admin/config/search/search-api-postgresql/cache')
      cy.contains('Embedding Cache Management')

      cy.wait(2000)
      cy.screenshot('admin-cache-management', {
        capture: 'fullPage',
        overwrite: true
      })

      // Verify cache management form
      cy.get('form').should('be.visible')
    })

    it('should visit and screenshot queue management', () => {
      cy.visit('/admin/config/search/search-api-postgresql/queue')
      cy.contains('Queue Management')

      cy.wait(2000)
      cy.screenshot('admin-queue-management', {
        capture: 'fullPage',
        overwrite: true
      })

      // Verify queue management interface
      cy.get('form').should('be.visible')
    })

    it('should visit and screenshot configuration test', () => {
      cy.visit('/admin/config/search/search-api-postgresql/test-config')
      cy.contains('Test Configuration')

      cy.wait(2000)
      cy.screenshot('admin-config-test', {
        capture: 'fullPage',
        overwrite: true
      })
    })
  })

  describe('Server and Index Specific Routes', () => {

    before(() => {
      // Create a test server for route testing
      cy.drupalLogin('admin', 'admin')
      cy.createPostgreSQLServer({
        name: 'Screenshot Test Server',
        id: 'screenshot_test_server',
        database: {
          host: 'db',
          port: '5432',
          database: 'db',
          username: 'db',
          password: 'db'
        }
      })

      // Create a test index
      cy.createSearchIndex({
        name: 'Screenshot Test Index',
        id: 'screenshot_test_index',
        server: 'screenshot_test_server',
        datasource: 'entity:node'
      })
    })

    it('should visit and screenshot server status page', () => {
      cy.visit('/admin/config/search/search-api-postgresql/server/screenshot_test_server/status')

      cy.wait(2000)
      cy.screenshot('admin-server-status', {
        capture: 'fullPage',
        overwrite: true
      })

      // Verify server status information is displayed
      cy.get('body').should('contain', 'Server Status')
    })

    it('should visit and screenshot index embeddings page', () => {
      cy.visit('/admin/config/search/search-api-postgresql/index/screenshot_test_index/embeddings')

      cy.wait(2000)
      cy.screenshot('admin-index-embeddings', {
        capture: 'fullPage',
        overwrite: true
      })

      // Verify index embedding status is displayed
      cy.get('body').should('contain', 'Index Embedding Status')
    })
  })

  describe('Search API Integration Screenshots', () => {

    it('should screenshot Search API server list', () => {
      cy.visit('/admin/config/search/search-api')
      cy.contains('Search API')

      cy.wait(2000)
      cy.screenshot('search-api-main', {
        capture: 'fullPage',
        overwrite: true
      })

      // Verify our PostgreSQL servers are visible
      cy.get('body').should('contain', 'PostgreSQL')
    })

    it('should screenshot PostgreSQL server configuration', () => {
      cy.visit('/admin/config/search/search-api/add-server')
      cy.get('#edit-backend').select('postgresql')
      cy.wait(1000)

      cy.screenshot('server-config-postgresql', {
        capture: 'fullPage',
        overwrite: true
      })

      // Verify PostgreSQL backend options are visible
      cy.get('#edit-backend-config-database-host').should('be.visible')
    })

    it('should screenshot PostgreSQL index configuration', () => {
      cy.visit('/admin/config/search/search-api/add-index')

      cy.wait(2000)
      cy.screenshot('index-config-postgresql', {
        capture: 'fullPage',
        overwrite: true
      })
    })
  })

  describe('Error Page Screenshots', () => {

    it('should screenshot access denied page', () => {
      // Logout and try to access admin page
      cy.visit('/user/logout')
      cy.visit('/admin/config/search/search-api-postgresql')

      cy.wait(1000)
      cy.screenshot('access-denied-admin', {
        capture: 'fullPage',
        overwrite: true
      })

      // Should show access denied or login redirect
      cy.url().should('satisfy', (url) => {
        return url.includes('/user/login') || url.includes('access-denied')
      })
    })

    it('should screenshot invalid server route', () => {
      cy.drupalLogin('admin', 'admin')

      // Try to access non-existent server status
      cy.request({
        url: '/admin/config/search/search-api-postgresql/server/nonexistent/status',
        failOnStatusCode: false
      }).then((response) => {
        expect(response.status).to.be.oneOf([404, 403])
      })
    })

    it('should screenshot invalid index route', () => {
      // Try to access non-existent index embeddings
      cy.request({
        url: '/admin/config/search/search-api-postgresql/index/nonexistent/embeddings',
        failOnStatusCode: false
      }).then((response) => {
        expect(response.status).to.be.oneOf([404, 403])
      })
    })
  })

  describe('Mobile Responsive Screenshots', () => {

    beforeEach(() => {
      // Set mobile viewport
      cy.viewport(375, 812) // iPhone X dimensions
    })

    it('should screenshot mobile dashboard', () => {
      cy.drupalLogin('admin', 'admin')
      cy.visit('/admin/config/search/search-api-postgresql')

      cy.wait(2000)
      cy.screenshot('mobile-admin-dashboard', {
        capture: 'fullPage',
        overwrite: true
      })
    })

    it('should screenshot mobile embedding management', () => {
      cy.visit('/admin/config/search/search-api-postgresql/embeddings')

      cy.wait(2000)
      cy.screenshot('mobile-embedding-management', {
        capture: 'fullPage',
        overwrite: true
      })
    })

    afterEach(() => {
      // Reset to desktop viewport
      cy.viewport(1280, 720)
    })
  })

  describe('Interactive Elements Screenshots', () => {

    it('should screenshot form interactions', () => {
      cy.drupalLogin('admin', 'admin')
      cy.visit('/admin/config/search/search-api-postgresql/embeddings')

      // Take screenshot before interaction
      cy.screenshot('form-before-interaction', {
        capture: 'viewport',
        overwrite: true
      })

      // Interact with form elements and screenshot
      cy.get('form').within(() => {
        // Focus on first form element
        cy.get('input, select, textarea').first().focus()

        cy.wait(500)
        cy.screenshot('form-with-focus', {
          capture: 'viewport',
          overwrite: true
        })
      })
    })

    it('should screenshot dropdown menus and modals', () => {
      cy.visit('/admin/config/search/search-api-postgresql')

      // Look for any dropdown elements
      cy.get('body').then(($body) => {
        if ($body.find('.dropbutton').length > 0) {
          cy.get('.dropbutton').first().click()
          cy.wait(500)
          cy.screenshot('dropdown-expanded', {
            capture: 'viewport',
            overwrite: true
          })
        }
      })
    })
  })

  describe('Performance and Loading Screenshots', () => {

    it('should screenshot page loading states', () => {
      cy.drupalLogin('admin', 'admin')

      // Visit page and take screenshot immediately
      cy.visit('/admin/config/search/search-api-postgresql/analytics')
      cy.screenshot('page-loading-state', {
        capture: 'viewport',
        overwrite: true
      })

      // Wait for full load and screenshot again
      cy.wait(3000)
      cy.screenshot('page-fully-loaded', {
        capture: 'fullPage',
        overwrite: true
      })
    })

    it('should screenshot AJAX loading states', () => {
      cy.visit('/admin/config/search/search-api-postgresql')

      // Look for any AJAX-enabled elements
      cy.get('body').then(($body) => {
        if ($body.find('[data-ajax-url]').length > 0) {
          cy.get('[data-ajax-url]').first().click()

          // Screenshot during AJAX request
          cy.screenshot('ajax-loading', {
            capture: 'viewport',
            overwrite: true
          })

          cy.waitForAjax()

          // Screenshot after AJAX completion
          cy.screenshot('ajax-complete', {
            capture: 'viewport',
            overwrite: true
          })
        }
      })
    })
  })

  after(() => {
    cy.log('Administrative routes testing and screenshot capture completed')

    // Generate screenshot report
    cy.writeFile('cypress/screenshots/screenshot-report.json', {
      timestamp: new Date().toISOString(),
      screenshots: [
        'admin-dashboard-main.png',
        'admin-embedding-management.png',
        'admin-analytics.png',
        'admin-bulk-regenerate.png',
        'admin-cache-management.png',
        'admin-queue-management.png',
        'admin-config-test.png',
        'admin-server-status.png',
        'admin-index-embeddings.png',
        'search-api-main.png',
        'server-config-postgresql.png',
        'index-config-postgresql.png',
        'access-denied-admin.png',
        'mobile-admin-dashboard.png',
        'mobile-embedding-management.png',
        'form-before-interaction.png',
        'form-with-focus.png',
        'page-loading-state.png',
        'page-fully-loaded.png'
      ],
      routes_tested: [
        '/admin/config/search/search-api-postgresql',
        '/admin/config/search/search-api-postgresql/embeddings',
        '/admin/config/search/search-api-postgresql/analytics',
        '/admin/config/search/search-api-postgresql/bulk-regenerate',
        '/admin/config/search/search-api-postgresql/cache',
        '/admin/config/search/search-api-postgresql/queue',
        '/admin/config/search/search-api-postgresql/test-config',
        '/admin/config/search/search-api-postgresql/server/{server_id}/status',
        '/admin/config/search/search-api-postgresql/index/{index_id}/embeddings'
      ]
    })
  })
})
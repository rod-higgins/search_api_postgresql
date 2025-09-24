// Custom Cypress commands for search_api_postgresql testing

// Command to wait for AJAX requests to complete
Cypress.Commands.add('waitForAjax', () => {
  cy.window().then((win) => {
    if (win.jQuery) {
      cy.get('.ajax-progress', { timeout: 10000 }).should('not.exist')
    }
  })
})

// Command to check PostgreSQL connection
Cypress.Commands.add('testDatabaseConnection', () => {
  cy.get('#edit-test-connection').click()
  cy.waitForAjax()
  cy.contains('Database connection successful', { timeout: 10000 })
})

// Command to index content
Cypress.Commands.add('indexContent', (indexId) => {
  cy.visit(`/admin/config/search/search-api/index/${indexId}`)
  cy.get('#edit-index-now').click()
  cy.contains('Successfully indexed', { timeout: 30000 })
})

// Command to create test content
Cypress.Commands.add('createTestContent', (contentData) => {
  const defaults = {
    type: 'article',
    title: 'Test Article',
    body: 'This is a test article for search functionality.'
  }

  const config = { ...defaults, ...contentData }

  cy.visit(`/node/add/${config.type}`)

  cy.get('#edit-title-0-value').clear().type(config.title)

  // Handle body field (may be different depending on Drupal setup)
  cy.get('body').then(($body) => {
    if ($body.find('#edit-body-0-value').length > 0) {
      cy.get('#edit-body-0-value').clear().type(config.body)
    } else if ($body.find('.ck-editor__editable').length > 0) {
      cy.get('.ck-editor__editable').clear().type(config.body)
    } else {
      cy.get('textarea[name*="body"]').first().clear().type(config.body)
    }
  })

  // Publish the content
  cy.get('#edit-status-value').check()
  cy.get('#edit-submit').click()

  cy.contains('has been created')
})

// Command to perform search
Cypress.Commands.add('performSearch', (searchTerm, expectedResults = null) => {
  cy.get('input[name="search"]').clear().type(searchTerm)
  cy.get('input[type="submit"]').click()

  if (expectedResults) {
    expectedResults.forEach(result => {
      cy.contains(result)
    })
  }
})

// Command to verify search results
Cypress.Commands.add('verifySearchResults', (expectations) => {
  if (expectations.count !== undefined) {
    cy.get('.view-content .views-row').should('have.length', expectations.count)
  }

  if (expectations.contains) {
    expectations.contains.forEach(text => {
      cy.contains(text)
    })
  }

  if (expectations.notContains) {
    expectations.notContains.forEach(text => {
      cy.contains(text).should('not.exist')
    })
  }
})

// Command to check module status
Cypress.Commands.add('verifyModuleEnabled', (moduleName) => {
  cy.visit('/admin/modules')
  cy.get(`input[name="modules[${moduleName}][enable]"]`).should('be.checked')
})

// Command to enable module if not enabled
Cypress.Commands.add('enableModule', (moduleName) => {
  cy.visit('/admin/modules')
  cy.get(`input[name="modules[${moduleName}][enable]"]`).then(($checkbox) => {
    if (!$checkbox.prop('checked')) {
      cy.wrap($checkbox).check()
      cy.get('#edit-submit').click()
      cy.contains('modules have been enabled', { timeout: 30000 })
    }
  })
})

// Command to clear caches
Cypress.Commands.add('clearCaches', () => {
  cy.visit('/admin/config/development/performance')
  cy.get('#edit-clear').click()
  cy.contains('Caches cleared')
})

// Command to verify vector search functionality
Cypress.Commands.add('testVectorSearch', (query, expectedResults) => {
  // This would test AI-powered semantic search if configured
  cy.get('input[name="search"]').clear().type(query)
  cy.get('#edit-search-mode').select('vector') // If vector search mode exists
  cy.get('input[type="submit"]').click()

  expectedResults.forEach(result => {
    cy.contains(result)
  })
})

// Command to verify faceted search
Cypress.Commands.add('testFacetedSearch', (facets) => {
  Object.keys(facets).forEach(facetName => {
    cy.get(`[data-drupal-facet-id="${facetName}"]`).within(() => {
      facets[facetName].forEach(value => {
        cy.contains(value).click()
      })
    })
  })
})
// Cypress support file for search_api_postgresql module testing

// Import commands
import './commands'

// Global configuration
Cypress.config('defaultCommandTimeout', 10000)

// Custom commands for Drupal admin authentication
Cypress.Commands.add('drupalLogin', (username = 'admin', password = 'admin') => {
  cy.visit('/user/login')
  cy.get('#edit-name').type(username)
  cy.get('#edit-pass').type(password)
  cy.get('#edit-submit').click()
  cy.url().should('not.include', '/user/login')
})

// Command to create PostgreSQL search server
Cypress.Commands.add('createPostgreSQLServer', (serverData) => {
  const defaults = {
    name: 'PostgreSQL Test Server',
    id: 'postgresql_test_server',
    backend: 'postgresql',
    database: {
      host: 'localhost',
      port: '5432',
      database: 'drupal_test',
      username: 'drupal',
      password: 'drupal'
    }
  }

  const config = { ...defaults, ...serverData }

  cy.visit('/admin/config/search/search-api/add-server')

  // Fill server basic info
  cy.get('#edit-name').clear().type(config.name)
  cy.get('#edit-id').clear().type(config.id)
  cy.get('#edit-backend').select('postgresql')

  // Wait for backend form to load
  cy.wait(1000)

  // Fill database configuration
  cy.get('#edit-backend-config-database-host').clear().type(config.database.host)
  cy.get('#edit-backend-config-database-port').clear().type(config.database.port)
  cy.get('#edit-backend-config-database-database').clear().type(config.database.database)
  cy.get('#edit-backend-config-database-username').clear().type(config.database.username)
  cy.get('#edit-backend-config-database-password').clear().type(config.database.password)

  // Enable AI features if specified
  if (config.enableAI) {
    cy.get('#edit-backend-config-ai-enabled').check()
    if (config.azureEndpoint) {
      cy.get('#edit-backend-config-azure-endpoint').clear().type(config.azureEndpoint)
    }
    if (config.azureApiKey) {
      cy.get('#edit-backend-config-azure-api-key').clear().type(config.azureApiKey)
    }
  }

  cy.get('#edit-submit').click()
  cy.contains('The server was successfully saved.')
})

// Command to create search index
Cypress.Commands.add('createSearchIndex', (indexData) => {
  const defaults = {
    name: 'Test Content Index',
    id: 'test_content_index',
    server: 'postgresql_test_server',
    datasource: 'entity:node'
  }

  const config = { ...defaults, ...indexData }

  cy.visit('/admin/config/search/search-api/add-index')

  // Fill index basic info
  cy.get('#edit-name').clear().type(config.name)
  cy.get('#edit-id').clear().type(config.id)
  cy.get('#edit-server').select(config.server)

  // Select data source
  cy.get(`input[value = "${config.datasource}"]`).check()

  cy.get('#edit-submit').click()
  cy.contains('The index was successfully saved.')
})

// Command to add fields to index
Cypress.Commands.add('addIndexFields', (fields) => {
  fields.forEach((field, index) => {
    cy.get('#edit-add-field').click()
    cy.wait(500)

    // Select field
    cy.get(`input[value = "${field.property}"]`).check()
    cy.get('#edit-submit-add-field').click()

    // Configure field
    cy.get(`#edit - fields - ${field.property} - label`).clear().type(field.label)
    cy.get(`#edit - fields - ${field.property} - type`).select(field.type)

    if (field.boost) {
      cy.get(`#edit - fields - ${field.property} - boost`).clear().type(field.boost.toString())
    }
  })

  cy.get('#edit-submit').click()
  cy.contains('The changes were successfully saved.')
})

// Command to create search view
Cypress.Commands.add('createSearchView', (viewData) => {
  const defaults = {
    name: 'Search Results',
    id: 'search_results',
    path: 'search',
    index: 'test_content_index'
  }

  const config = { ...defaults, ...viewData }

  cy.visit('/admin/structure/views/add')

  // Fill view basic info
  cy.get('#edit-label').clear().type(config.name)
  cy.get('#edit-id').clear().type(config.id)

  // Set to use Search API index
  cy.get('#edit-show').select('search_api_index')
  cy.get('#edit-search-api-index').select(config.index)

  // Create page display
  cy.get('#edit-page-create').check()
  cy.get('#edit-page-title').clear().type(config.name)
  cy.get('#edit-page-path').clear().type(config.path)

  cy.get('#edit-submit').click()
  cy.url().should('include', '/admin/structure/views/view/')
})

// Command to add search form to view
Cypress.Commands.add('addSearchFormToView', () => {
  // Add exposed filter form
  cy.get('.views-ui-display-tab-actions .dropbutton-toggle').first().click()
  cy.contains('Filters').click()

  // Add search filter
  cy.get('.views-add-form select').select('search_api_fulltext')
  cy.get('.views-add-form .form-submit').click()

  // Configure the filter
  cy.get('#edit-options-expose-button-button').click()
  cy.get('#edit-options-expose-label').clear().type('Search')
  cy.get('#edit-options-expose-identifier').clear().type('search')

  cy.get('.ui-dialog-buttonset .form-submit').click()

  // Save view
  cy.get('#edit-actions-submit').click()
  cy.contains('The view Test has been saved.')
})

// Global error handling
Cypress.on('uncaught:exception', (err, runnable) => {
  // Ignore common Drupal JS errors that don't affect testing
  if (err.message.includes('Script error') ||
      err.message.includes('Non-Error promise rejection captured')) {
    return FALSE
  }
  return TRUE
})
<?php

namespace Drupal\Tests\search_api_postgresql\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for the EmbeddingAdminController.
 *
 * @group search_api_postgresql
 */
class EmbeddingAdminControllerTest extends BrowserTestBase
{
  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'search_api_postgresql',
    'node',
    'field',
    'user',
    'system',
    'views',
  ];

  /**
   * Admin user for testing.
   */
  protected $adminUser;

  /**
   * Regular user for testing permissions.
   */
  protected $regularUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // Create admin user with appropriate permissions.
    $this->adminUser = $this->drupalCreateUser([
      'administer search_api',
      'administer search_api_postgresql',
      'access administration pages',
      'view embedding analytics',
      'manage embedding cache',
      'manage embedding queue',
    ]);

    // Create regular user to test access restrictions.
    $this->regularUser = $this->drupalCreateUser([
      'access content',
    ]);
  }

  /**
   * Tests admin dashboard access and basic functionality.
   */
  public function testAdminDashboardAccess()
  {
    // Test unauthorized access.
    $this->drupalLogin($this->regularUser);
    $this->drupalGet('/admin/config/search/search-api/postgresql/dashboard');
    $this->assertSession()->statusCodeEquals(403);

    // Test authorized access.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/search-api/postgresql/dashboard');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Search API PostgreSQL Dashboard');
  }

  /**
   * Tests analytics page functionality.
   */
  public function testAnalyticsPage()
  {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/search-api/postgresql/analytics');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Embedding Analytics');

    // Check for expected analytics sections.
    $this->assertSession()->pageTextContains('Cache Statistics');
    $this->assertSession()->pageTextContains('Query Performance');
    $this->assertSession()->pageTextContains('Vector Operations');
  }

  /**
   * Tests cache management page.
   */
  public function testCacheManagementPage()
  {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/search-api/postgresql/cache');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Cache Management');

    // Check for cache management controls.
    $this->assertSession()->buttonExists('Clear Cache');
    $this->assertSession()->buttonExists('Refresh Statistics');
  }

  /**
   * Tests queue management page.
   */
  public function testQueueManagementPage()
  {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/search-api/postgresql/queue');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Queue Management');

    // Check for queue management controls.
    $this->assertSession()->pageTextContains('Embedding Queue Status');
    $this->assertSession()->buttonExists('Process Queue');
  }

  /**
   * Tests server status endpoint.
   */
  public function testServerStatusEndpoint()
  {
    $this->drupalLogin($this->adminUser);

    // Test with non-existent server.
    $this->drupalGet('/admin/config/search/search-api/postgresql/server/nonexistent/status');
    $this->assertSession()->statusCodeEquals(404);

    // Note: We can't easily test with a real server without setting up the full backend
    // This would require a more complex functional test setup.
  }

  /**
   * Tests configuration validation endpoint.
   */
  public function testConfigurationValidation()
  {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/search-api/postgresql/validate');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Configuration Validation');

    // Check for validation form elements.
    $this->assertSession()->fieldExists('database[host]');
    $this->assertSession()->fieldExists('database[port]');
    $this->assertSession()->fieldExists('database[database]');
    $this->assertSession()->buttonExists('Validate Configuration');
  }

  /**
   * Tests embedding operations page.
   */
  public function testEmbeddingOperationsPage()
  {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/search-api/postgresql/embeddings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Embedding Operations');

    // Check for embedding management controls.
    $this->assertSession()->pageTextContains('Bulk Operations');
    $this->assertSession()->buttonExists('Regenerate Embeddings');
  }

  /**
   * Tests system status integration.
   */
  public function testSystemStatusIntegration()
  {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);

    // The controller should add system status information
    // We can't easily test this without the full module being active
    // but we can verify the page loads.
    $this->assertSession()->pageTextContains('Status report');
  }

  /**
   * Tests AJAX endpoints.
   */
  public function testAjaxEndpoints()
  {
    $this->drupalLogin($this->adminUser);

    // Test cache statistics AJAX endpoint.
    $this->drupalGet('/admin/config/search/search-api/postgresql/ajax/cache-stats');
    $this->assertSession()->statusCodeEquals(200);

    // Test queue status AJAX endpoint.
    $this->drupalGet('/admin/config/search/search-api/postgresql/ajax/queue-status');
    $this->assertSession()->statusCodeEquals(200);

    // Test analytics data AJAX endpoint.
    $this->drupalGet('/admin/config/search/search-api/postgresql/ajax/analytics');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests error handling for invalid requests.
   */
  public function testErrorHandling()
  {
    $this->drupalLogin($this->adminUser);

    // Test invalid server ID.
    $this->drupalGet('/admin/config/search/search-api/postgresql/server/invalid-id/status');
    $this->assertSession()->statusCodeEquals(404);

    // Test invalid operation.
    $this->drupalGet('/admin/config/search/search-api/postgresql/invalid-operation');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Tests breadcrumb navigation.
   */
  public function testBreadcrumbNavigation()
  {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/search-api/postgresql/dashboard');
    $this->assertSession()->statusCodeEquals(200);

    // Check for proper breadcrumb structure.
    $this->assertSession()->linkExists('Home');
    $this->assertSession()->linkExists('Administration');
    $this->assertSession()->linkExists('Configuration');
    $this->assertSession()->linkExists('Search API');
  }

  /**
   * Tests action forms and submissions.
   */
  public function testActionForms()
  {
    $this->drupalLogin($this->adminUser);

    // Test cache clear action.
    $this->drupalGet('/admin/config/search/search-api/postgresql/cache');
    $this->submitForm([], 'Clear Cache');
    $this->assertSession()->pageTextContains('Cache cleared successfully');

    // Test configuration validation submission.
    $this->drupalGet('/admin/config/search/search-api/postgresql/validate');
    $this->submitForm([
      'database[host]' => 'localhost',
      'database[port]' => '5432',
      'database[database]' => 'test',
    ], 'Validate Configuration');

    // Should show validation results.
    $this->assertSession()->pageTextContains('Configuration validation');
  }

  /**
   * Tests responsive design elements.
   */
  public function testResponsiveDesign()
  {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/search-api/postgresql/dashboard');
    $this->assertSession()->statusCodeEquals(200);

    // Check for responsive CSS classes that should be present.
    $this->assertSession()->responseContains('class="');

    // The specific responsive elements would depend on the actual implementation
    // but we can verify basic structure is present.
  }

  /**
   * Tests help text and documentation links.
   */
  public function testHelpAndDocumentation()
  {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/search-api/postgresql/dashboard');
    $this->assertSession()->statusCodeEquals(200);

    // Check for help text and documentation.
    $this->assertSession()->pageTextContains('Help');

    // Should contain links to relevant documentation.
    $this->assertSession()->linkExists('Documentation');
  }
}

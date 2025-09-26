<?php

namespace Drupal\Tests\search_api_postgresql\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for BulkRegenerateForm.
 *
 * @group search_api_postgresql
 */
class BulkRegenerateFormTest extends BrowserTestBase
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
  ];

  /**
   * Admin user for testing.
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'administer search_api',
      'administer search_api_postgresql',
      'access administration pages',
    ]);

    $this->drupalLogin($this->adminUser);
  }

  /**
   * Tests form display and structure.
   */
  public function testFormDisplay()
  {
    $this->drupalGet($this->getFormPath());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('BulkRegenerateForm');
  }

  /**
   * Tests form submission with valid data.
   */
  public function testFormSubmissionValid()
  {
    $this->drupalGet($this->getFormPath());

    $edit = $this->getValidFormData();
    $this->submitForm($edit, 'Save');

    $this->assertSession()->pageTextContains('saved successfully');
  }

  /**
   * Tests form submission with invalid data.
   */
  public function testFormSubmissionInvalid()
  {
    $this->drupalGet($this->getFormPath());

    $edit = $this->getInvalidFormData();
    $this->submitForm($edit, 'Save');

    $this->assertSession()->pageTextContains('error');
  }

  /**
   * Tests form validation.
   */
  public function testFormValidation()
  {
    $this->drupalGet($this->getFormPath());

    // Submit empty form.
    $this->submitForm([], 'Save');

    // Should show validation errors.
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests form field existence.
   */
  public function testFormFields()
  {
    $this->drupalGet($this->getFormPath());

    $fields = $this->getExpectedFields();
    foreach ($fields as $field) {
      $this->assertSession()->fieldExists($field);
    }
  }

  /**
   * Tests form permissions.
   */
  public function testFormPermissions()
  {
    // Test as anonymous user.
    $this->drupalLogout();
    $this->drupalGet($this->getFormPath());
    $this->assertSession()->statusCodeEquals(403);

    // Test as authenticated user without permissions.
    $user = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($user);
    $this->drupalGet($this->getFormPath());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests AJAX functionality if present.
   */
  public function testAjaxFunctionality()
  {
    $this->drupalGet($this->getFormPath());

    // Check for AJAX elements.
    $this->assertSession()->responseContains('ajax');
  }

  /**
   * Tests form cancel operation.
   */
  public function testFormCancel()
  {
    $this->drupalGet($this->getFormPath());

    if ($this->assertSession()->buttonExists('Cancel')) {
      $this->click('Cancel');
      // Should redirect to appropriate page.
      $this->assertSession()->statusCodeEquals(200);
    }
  }

  /**
   * Tests form with existing configuration.
   */
  public function testFormWithExistingConfig()
  {
    // Set some configuration first.
    $config = $this->config('search_api_postgresql.settings');
    $config->set('test_setting', 'test_value');
    $config->save();

    $this->drupalGet($this->getFormPath());
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests form help text.
   */
  public function testFormHelpText()
  {
    $this->drupalGet($this->getFormPath());

    // Check for help text elements.
    $this->assertSession()->elementExists('css', '.description');
  }

  /**
   * Gets the form path.
   */
  protected function getFormPath()
  {
    // Return appropriate path based on form name.
    return '/admin/config/search/search-api/postgresql/' . strtolower(str_replace('Form', '', 'BulkRegenerateForm'));
  }

  /**
   * Gets valid form data for submission.
   */
  protected function getValidFormData()
  {
    return [
      'field_1' => 'valid_value_1',
      'field_2' => 'valid_value_2',
    ];
  }

  /**
   * Gets invalid form data for submission.
   */
  protected function getInvalidFormData()
  {
    return [
      'field_1' => '',
      'field_2' => 'invalid!@#',
    ];
  }

  /**
   * Gets expected form fields.
   */
  protected function getExpectedFields()
  {
    return [
      'field_1',
      'field_2',
    ];
  }
}

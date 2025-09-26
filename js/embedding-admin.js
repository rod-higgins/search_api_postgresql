/**
 * @file
 * JavaScript for Search API PostgreSQL administration interface.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  // Global variables
  var refreshIntervals = {};
  var updateTimers = {};

  /**
   * Initialize the admin interface.
   */
  Drupal.behaviors.searchApiPostgreSQLAdmin = {
    attach: function (context, settings) {
      // Initialize dashboard if present
      if ($('.search-api-postgresql-header', context).length) {
        initializeDashboard(context);
      }

      // Initialize embedding management form
      if ($('#search-api-postgresql-embedding-management', context).length) {
        initializeEmbeddingManagement(context);
      }

      // Initialize analytics page
      if ($('.analytics-filters', context).length) {
        initializeAnalytics(context);
      }

      // Initialize real-time updates
      initializeRealTimeUpdates(context);

      // Initialize tooltips and help text
      initializeTooltips(context);

      // Initialize form validation
      initializeFormValidation(context);
    }
  };

  /**
   * Initialize dashboard functionality.
   */
  function initializeDashboard(context) {
    // Auto-refresh stats every 30 seconds
    if (drupalSettings.searchApiPostgreSQL && drupalSettings.searchApiPostgreSQL.autoRefresh) {
      refreshIntervals.dashboard = setInterval(function () {
        refreshDashboardStats();
      }, 30000);
    }

    // Initialize stat card interactions
    $('.stat-card', context).each(function () {
      var $card = $(this);

      $card.on('click', function () {
        var link = $card.data('link');
        if (link) {
          window.location.href = link;
        }
      });

      // Add hover effects
      $card.hover(
        function () {
          $(this).addClass('stat-card-hover');
        },
        function () {
          $(this).removeClass('stat-card-hover');
        }
      );
    });

    // Initialize health check refresh
    $('.health-checks .refresh-button', context).on('click', function (e) {
      e.preventDefault();
      refreshHealthChecks();
    });
  }

  /**
   * Initialize embedding management form functionality.
   */
  function initializeEmbeddingManagement(context) {
    var $form = $('#search-api-postgresql-embedding-management', context);

    if (!$form.length) {
      return;
    }

    // Handle operation changes
    $form.find('input[name="operation"]').on('change', function () {
      var operation = $(this).val();
      updateOperationDescription(operation);
      updateCostEstimation();
      toggleAdvancedOptions(operation);
    });

    // Handle server/index selection changes
    $form.find('select[name="server_id"], select[name="index_id"]').on('change', function () {
      updateCostEstimation();
      updatePreview();
    });

    // Handle batch size changes
    $form.find('input[name="batch_size"]').on('input', debounce(function () {
      updateCostEstimation();
    }, 500));

    // Handle queue option changes
    $form.find('input[name="use_queue"]').on('change', function () {
      var useQueue = $(this).is(':checked');
      $form.find('.queue-options').toggle(useQueue);
      updateFormSubmitText(useQueue);
    });

    // Initialize preview button
    $form.find('input[value="Preview Changes"]').on('click', function (e) {
      e.preventDefault();
      showPreviewModal();
    });

    // Initialize cost estimation
    updateCostEstimation();
  }

  /**
   * Initialize analytics functionality.
   */
  function initializeAnalytics(context) {
    // Handle date range changes
    $('#analytics-date-range', context).on('change', function () {
      var range = $(this).val();
      updateAnalytics(range);
    });

    // Initialize chart interactions
    initializeCharts(context);

    // Auto-refresh analytics every 60 seconds
    if (drupalSettings.searchApiPostgreSQL && drupalSettings.searchApiPostgreSQL.analyticsAutoRefresh) {
      refreshIntervals.analytics = setInterval(function () {
        var currentRange = $('#analytics-date-range').val() || '7d';
        updateAnalytics(currentRange);
      }, 60000);
    }
  }

  /**
   * Initialize real-time updates for various components.
   */
  function initializeRealTimeUpdates(context) {
    // Update embedding progress bars
    $('.progress-bar', context).each(function () {
      var $bar = $(this);
      var indexId = $bar.data('index-id');

      if (indexId) {
        updateTimers[indexId] = setInterval(function () {
          updateEmbeddingProgress(indexId, $bar);
        }, 5000);
      }
    });

    // Update queue statistics
    if ($('.queue-stats', context).length) {
      refreshIntervals.queue = setInterval(function () {
        updateQueueStats();
      }, 10000);
    }

    // Update server status indicators
    $('.server-status-indicator', context).each(function () {
      var $indicator = $(this);
      var serverId = $indicator.data('server-id');

      if (serverId) {
        setInterval(function () {
          updateServerStatus(serverId, $indicator);
        }, 15000);
      }
    });
  }

  /**
   * Initialize tooltips and help text.
   */
  function initializeTooltips(context) {
    // Add tooltips to elements with data-tooltip attribute
    $('[data-tooltip]', context).each(function () {
      var $element = $(this);
      var tooltip = $element.data('tooltip');

      $element.on('mouseenter', function (e) {
        showTooltip(e, tooltip);
      }).on('mouseleave', function () {
        hideTooltip();
      });
    });

    // Initialize help toggles
    $('.help-toggle', context).on('click', function (e) {
      e.preventDefault();
      var $help = $(this).siblings('.help-text');
      $help.slideToggle(200);
    });
  }

  /**
   * Initialize form validation.
   */
  function initializeFormValidation(context) {
    // Real-time validation for embedding management form
    $('#search-api-postgresql-embedding-management', context).on('input change', 'input, select', function () {
      var $field = $(this);
      validateField($field);
    });

    // Confirmation dialogs for destructive operations
    $('input[value*="Clear"], input[value*="Delete"], input[value*="Remove"]', context).on('click', function (e) {
      var operation = $(this).val();
      if (!confirm('Are you sure you want to ' + operation.toLowerCase() + '? This action cannot be undone.')) {
        e.preventDefault();
        return FALSE;
      }
    });
  }

  /**
   * Refresh dashboard statistics.
   */
  function refreshDashboardStats() {
    $.ajax({
      url: drupalSettings.path.baseUrl + 'admin/config/search/search-api-postgresql/ajax/dashboard-stats',
      type: 'GET',
      dataType: 'json',
      success: function (data) {
        updateDashboardCards(data.stats);
        updateServerStatusTable(data.servers);
        updateIndexStatusTable(data.indexes);
      },
      error: function () {
        console.log('Failed to refresh dashboard stats');
      }
    });
  }

  /**
   * Update dashboard stat cards.
   */
  function updateDashboardCards(stats) {
    if (!stats) { return;
    }

    for (var key in stats) {
      var $card = $('.stat-card[data-stat="' + key + '"]');
      if ($card.length) {
        var $value = $card.find('.stat-card-value');
        var $subtitle = $card.find('.stat-card-subtitle');

        // Animate value change
        if (stats[key].value !== undefined) {
          animateValue($value, stats[key].value);
        }

        if (stats[key].subtitle !== undefined) {
          $subtitle.text(stats[key].subtitle);
        }

        // Update trend indicator
        if (stats[key].trend !== undefined) {
          updateTrendIndicator($card, stats[key].trend);
        }
      }
    }
  }

  /**
   * Update cost estimation.
   */
  function updateCostEstimation() {
    var $form = $('#search-api-postgresql-embedding-management');
    if (!$form.length) { return;
    }

    var formData = {
      operation: $form.find('input[name="operation"]:checked').val(),
      server_id: $form.find('select[name="server_id"]').val(),
      index_id: $form.find('select[name="index_id"]').val(),
      batch_size: $form.find('input[name="batch_size"]').val(),
      force_overwrite: $form.find('input[name="force_overwrite"]').is(':checked')
    };

    // Show loading state
    $('#cost-estimation-content').html('<div class="loading">Calculating...</div>');

    $.ajax({
      url: drupalSettings.path.baseUrl + 'admin/config/search/search-api-postgresql/ajax/cost-estimation',
      type: 'POST',
      data: formData,
      dataType: 'json',
      success: function (data) {
        displayCostEstimation(data);
      },
      error: function () {
        $('#cost-estimation-content').html('<div class="error">Failed to calculate cost estimation.</div>');
      }
    });
  }

  /**
   * Display cost estimation results.
   */
  function displayCostEstimation(data) {
    var html = '<div class="cost-estimation-results">';

    html += '<div class="cost-summary">';
    html += '<strong>Estimated Cost: $' + parseFloat(data.total_cost).toFixed(4) + '</strong>';
    html += '</div>';

    if (data.breakdown && data.breakdown.length > 0) {
      html += '<div class="cost-breakdown">';
      html += '<h4>Breakdown:</h4>';

      data.breakdown.forEach(function (item) {
        html += '<div class="cost-breakdown-item">';
        html += '<span>' + item.description + '</span>';
        html += '<span>$' + parseFloat(item.cost).toFixed(4) + '</span>';
        html += '</div>';
      });

      html += '</div>';
    }

    if (data.items_affected) {
      html += '<div class="items-affected">';
      html += '<p><strong>' + data.items_affected.toLocaleString() + '</strong> items will be affected</p>';
      html += '</div>';
    }

    if (data.estimated_time) {
      html += '<div class="estimated-time">';
      html += '<p>Estimated time: <strong>' + data.estimated_time + '</strong></p>';
      html += '</div>';
    }

    html += '</div>';

    $('#cost-estimation-content').html(html).addClass('fade-in');
  }

  /**
   * Update analytics data.
   */
  function updateAnalytics(range) {
    var $container = $('.analytics-container');
    $container.addClass('loading');

    $.ajax({
      url: drupalSettings.path.baseUrl + 'admin/config/search/search-api-postgresql/ajax/analytics',
      type: 'GET',
      data: { range: range },
      dataType: 'json',
      success: function (data) {
        updateCostCards(data.cost);
        updatePerformanceCharts(data.performance);
        updateUsageCharts(data.usage);
        $container.removeClass('loading');
      },
      error: function () {
        console.log('Failed to update analytics');
        $container.removeClass('loading');
      }
    });
  }

  /**
   * Update embedding progress for an index.
   */
  function updateEmbeddingProgress(indexId, $progressBar) {
    $.ajax({
      url: drupalSettings.path.baseUrl + 'admin/config/search/search-api-postgresql/ajax/embedding-progress/' + indexId,
      type: 'GET',
      dataType: 'json',
      success: function (data) {
        if (data.progress && data.progress.embedding_coverage !== undefined) {
          var percentage = Math.round(data.progress.embedding_coverage);

          $progressBar.find('.progress-bar-fill').css('width', percentage + '%');
          $progressBar.find('.progress-text').text(percentage + '%');

          // Update related statistics
          var $statsContainer = $progressBar.closest('.embedding-stats-container');
          if ($statsContainer.length) {
            updateEmbeddingStats($statsContainer, data.progress);
          }
        }
      },
      error: function () {
        // Silently handle errors for real-time updates
      }
    });
  }

  /**
   * Update embedding statistics display.
   */
  function updateEmbeddingStats($container, stats) {
    for (var key in stats) {
      var $stat = $container.find('[data-stat="' + key + '"]');
      if ($stat.length && stats[key] !== undefined) {
        if (typeof stats[key] === 'number') {
          animateValue($stat, stats[key]);
        } else {
          $stat.text(stats[key]);
        }
      }
    }
  }

  /**
   * Show preview modal.
   */
  function showPreviewModal() {
    var $form = $('#search-api-postgresql-embedding-management');
    var formData = serializeFormData($form);

    // Create modal overlay
    var $overlay = $('<div class="modal-overlay"></div>');
    var $modal = $('<div class="preview-modal"></div>');

    $modal.html('<div class="modal-header"><h3>Operation Preview</h3><button class="modal-close">×</button></div><div class="modal-body"><div class="loading">Generating preview...</div></div><div class="modal-footer"><button class="button button--primary confirm-operation">Confirm & Execute</button><button class="button cancel-operation">Cancel</button></div>');

    $overlay.append($modal);
    $('body').append($overlay);

    // Load preview data
    $.ajax({
      url: drupalSettings.path.baseUrl + 'admin/config/search/search-api-postgresql/ajax/operation-preview',
      type: 'POST',
      data: formData,
      dataType: 'json',
      success: function (data) {
        displayPreviewResults($modal, data);
      },
      error: function () {
        $modal.find('.modal-body').html('<div class="error">Failed to generate preview.</div>');
      }
    });

    // Handle modal interactions
    $overlay.on('click', '.modal-close, .cancel-operation', function () {
      $overlay.remove();
    });

    $overlay.on('click', '.confirm-operation', function () {
      $overlay.remove();
      $form.find('input[type="submit"]').click();
    });

    $overlay.on('click', function (e) {
      if (e.target === $overlay[0]) {
        $overlay.remove();
      }
    });
  }

  /**
   * Display preview results in modal.
   */
  function displayPreviewResults($modal, data) {
    var html = '<div class="preview-results">';

    html += '<div class="preview-summary">';
    html += '<h4>Operation Summary</h4>';
    html += '<p><strong>Operation:</strong> ' + data.operation + '</p>';
    html += '<p><strong>Affected Items:</strong> ' + data.affected_items.toLocaleString() + '</p>';
    html += '<p><strong>Estimated Cost:</strong> $' + parseFloat(data.estimated_cost).toFixed(4) + '</p>';
    html += '<p><strong>Estimated Time:</strong> ' + data.estimated_time + '</p>';
    html += '</div>';

    if (data.warnings && data.warnings.length > 0) {
      html += '<div class="preview-warnings">';
      html += '<h4>Warnings</h4>';
      html += '<ul>';
      data.warnings.forEach(function (warning) {
        html += '<li>' + warning + '</li>';
      });
      html += '</ul>';
      html += '</div>';
    }

    if (data.breakdown && data.breakdown.length > 0) {
      html += '<div class="preview-breakdown">';
      html += '<h4>Detailed Breakdown</h4>';
      html += '<table>';
      html += '<thead><tr><th>Server</th><th>Index</th><th>Items</th><th>Cost</th></tr></thead>';
      html += '<tbody>';

      data.breakdown.forEach(function (item) {
        html += '<tr>';
        html += '<td>' + item.server_name + '</td>';
        html += '<td>' + item.index_name + '</td>';
        html += '<td>' + item.item_count.toLocaleString() + '</td>';
        html += '<td>$' + parseFloat(item.cost).toFixed(4) + '</td>';
        html += '</tr>';
      });

      html += '</tbody></table>';
      html += '</div>';
    }

    html += '</div>';

    $modal.find('.modal-body').html(html);
  }

  /**
   * Initialize charts for analytics.
   */
  function initializeCharts(context) {
    // This would integrate with a charting library like Chart.js or D3.js
    // For now, we'll implement basic chart placeholders
    $('.metric-chart', context).each(function () {
      var $chart = $(this);
      var chartType = $chart.data('chart-type') || 'line';
      var chartData = $chart.data('chart-data') || [];

      // Initialize chart based on type
      initializeChart($chart, chartType, chartData);
    });
  }

  /**
   * Initialize individual chart.
   */
  function initializeChart($container, type, data) {
    // Basic chart implementation
    // In a real implementation, this would use Chart.js or similar
    var $canvas = $('<canvas width="400" height="200"></canvas>');
    $container.append($canvas);

    // Store chart reference for updates
    $container.data('chart-initialized', TRUE);
  }

  /**
   * Utility functions
   */

  /**
   * Debounce function calls.
   */
  function debounce(func, wait) {
    var timeout;
    return function executedFunction() {
      var context = this;
      var args = arguments;
      var later = function () {
        timeout = NULL;
        func.apply(context, args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  /**
   * Animate numeric values.
   */
  function animateValue($element, endValue) {
    var startValue = parseInt($element.text().replace(/[^0-9]/g, '')) || 0;
    var duration = 800;
    var startTime = Date.now();

    function updateValue() {
      var elapsed = Date.now() - startTime;
      var progress = Math.min(elapsed / duration, 1);

      // Easing function
      var easedProgress = 1 - Math.pow(1 - progress, 3);
      var currentValue = Math.round(startValue + (endValue - startValue) * easedProgress);

      $element.text(currentValue.toLocaleString());

      if (progress < 1) {
        requestAnimationFrame(updateValue);
      }
    }

    requestAnimationFrame(updateValue);
  }

  /**
   * Serialize form data to object.
   */
  function serializeFormData($form) {
    var data = {};
    $form.serializeArray().forEach(function (item) {
      data[item.name] = item.value;
    });
    return data;
  }

  /**
   * Show tooltip.
   */
  function showTooltip(event, text) {
    var $tooltip = $('<div class="admin-tooltip">' + text + '</div>');
    $('body').append($tooltip);

    $tooltip.css({
      position: 'absolute',
      top: event.pageY + 10,
      left: event.pageX + 10,
      zIndex: 9999
    });
  }

  /**
   * Hide tooltip.
   */
  function hideTooltip() {
    $('.admin-tooltip').remove();
  }

  /**
   * Validate form field.
   */
  function validateField($field) {
    var value = $field.val();
    var fieldName = $field.attr('name');
    var isValid = TRUE;
    var errorMessage = '';

    // Field-specific validation
    switch (fieldName) {
      case 'batch_size':
        if (value < 1 || value > 1000) {
          isValid = FALSE;
          errorMessage = 'Batch size must be between 1 and 1000.';
        }
        break;

      // Add more field validations as needed
    }

    // Update field styling
    $field.toggleClass('error', !isValid);

    // Show/hide error message
    var $errorElement = $field.siblings('.field-error');
    if (!isValid) {
      if (!$errorElement.length) {
        $errorElement = $('<div class="field-error"></div>');
        $field.after($errorElement);
      }
      $errorElement.text(errorMessage);
    } else {
      $errorElement.remove();
    }

    return isValid;
  }

  /**
   * Update operation description based on selection.
   */
  function updateOperationDescription(operation) {
    var descriptions = {
      'regenerate_all': 'This will regenerate embeddings for all items, including those that already have embeddings.',
      'regenerate_missing': 'This will only generate embeddings for items that don\'t currently have embeddings.',
      'validate_embeddings': 'This will check the integrity and validity of existing embeddings.',
      'clear_embeddings': 'This will permanently delete all embeddings. This action cannot be undone.',
      'update_dimensions': 'This will update the vector dimensions to match the current model configuration.'
    };

    var $description = $('.operation-description');
    if ($description.length && descriptions[operation]) {
      $description.html('<p>' + descriptions[operation] + '</p>').addClass('fade-in');
    }
  }

  /**
   * Toggle advanced options based on operation.
   */
  function toggleAdvancedOptions(operation) {
    var $forceOverwrite = $('.form-item-force-overwrite');
    var showForceOverwrite = ['regenerate_all', 'regenerate_missing'].includes(operation);

    $forceOverwrite.toggle(showForceOverwrite);
  }

  /**
   * Update form submit button text based on queue setting.
   */
  function updateFormSubmitText(useQueue) {
    var $submitButton = $('input[type="submit"][value*="Execute"]');
    if ($submitButton.length) {
      var text = useQueue ? 'Queue Operation' : 'Execute Operation';
      $submitButton.val(text);
    }
  }

  /**
   * Update trend indicator.
   */
  function updateTrendIndicator($card, trend) {
    var $indicator = $card.find('.trend-indicator');
    if (!$indicator.length) {
      $indicator = $('<span class="trend-indicator"></span>');
      $card.find('.stat-card-subtitle').append(' ', $indicator);
    }

    $indicator.removeClass('trend-up trend-down trend-neutral');

    if (trend > 0) {
      $indicator.addClass('trend-up').text('↗');
    } else if (trend < 0) {
      $indicator.addClass('trend-down').text('↘');
    } else {
      $indicator.addClass('trend-neutral').text('→');
    }
  }

  /**
   * Cleanup function for when leaving pages.
   */
  $(window).on('beforeunload', function () {
    // Clear all intervals
    for (var key in refreshIntervals) {
      clearInterval(refreshIntervals[key]);
    }

    for (var key in updateTimers) {
      clearInterval(updateTimers[key]);
    }
  });

  // Global function for analytics date range updates
  window.updateAnalytics = function (range) {
    updateAnalytics(range);
  };

})(jQuery, Drupal, drupalSettings);
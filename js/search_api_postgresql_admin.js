/**
 * @file
 * JavaScript enhancements for Search API PostgreSQL admin form.
 * Fixed for Drupal 10.5+ compatibility with once() library.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Simple UX enhancements for existing form structure.
   */
  Drupal.behaviors.searchApiPostgreSQLConfigConservative = {
    attach: function (context, settings) {

      // Auto-update dimensions when Azure model changes
      once('azure-model-dimension', 'select[name="ai_embeddings[azure][model]"]', context).forEach((element) => {
        $(element).on('change', function () {
          var selectedModel = $(this).val();
          var dimensionField = $('input[name="ai_embeddings[azure][dimension]"]');

          var modelDimensions = {
            'text-embedding-3-small': 1536,
            'text-embedding-3-large': 3072,
            'text-embedding-ada-002': 1536
          };

          if (modelDimensions[selectedModel] && dimensionField.length) {
            dimensionField.val(modelDimensions[selectedModel]);

            // Brief visual feedback
            dimensionField.css('background-color', '#fff2cc');
            setTimeout(function () {
              dimensionField.css('background-color', '');
            }, 1000);
          }
        });
      });

      // Normalize hybrid search weights when either changes
      var $textWeight = $('input[name="hybrid_search[text_weight]"]', context);
      var $vectorWeight = $('input[name="hybrid_search[vector_weight]"]', context);

      function normalizeWeights() {
        var textVal = parseFloat($textWeight.val()) || 0;
        var vectorVal = parseFloat($vectorWeight.val()) || 0;
        var total = textVal + vectorVal;

        // Only normalize if total is significantly different from 1.0
        if (total > 0 && Math.abs(total - 1.0) > 0.05) {
          var normalizedText = (textVal / total);
          var normalizedVector = (vectorVal / total);

          $textWeight.val(normalizedText.toFixed(2));
          $vectorWeight.val(normalizedVector.toFixed(2));

          // Brief visual feedback
          $textWeight.add($vectorWeight).css('background-color', '#fff2cc');
          setTimeout(function () {
            $textWeight.add($vectorWeight).css('background-color', '');
          }, 1000);
        }
      }

      once('weight-normalization', 'input[name="hybrid_search[text_weight]"], input[name="hybrid_search[vector_weight]"]', context).forEach((element) => {
        $(element).on('blur', normalizeWeights);
      });

      // Simple show/hide for vector method specific settings
      once('vector-method-toggle', 'select[name="vector_index[method]"]', context).forEach((element) => {
        $(element).on('change', function () {
          var method = $(this).val();
          var $listsField = $('.form-item-vector-index-lists');
          var $probesField = $('.form-item-vector-index-probes');

          // Simple visibility toggle based on method
          if (method === 'ivfflat') {
            $listsField.show();
            $probesField.show();
          } else {
            // For HNSW, hide IVFFlat specific settings
            $listsField.hide();
            $probesField.hide();
          }
        }).trigger('change');
      });

      // Auto-update probes max value based on lists value
      once('lists-probes-sync', 'input[name="vector_index[lists]"]', context).forEach((element) => {
        $(element).on('change', function () {
          var listsValue = parseInt($(this).val()) || 100;
          var $probesField = $('input[name="vector_index[probes]"]');

          if ($probesField.length) {
            $probesField.attr('max', listsValue);

            // If current probes value exceeds lists, adjust it
            var currentProbes = parseInt($probesField.val()) || 10;
            if (currentProbes > listsValue) {
              $probesField.val(listsValue);
            }
          }
        });
      });
    }
  };

})(jQuery, Drupal);
(function($) {
  'use strict';

  const debug = true;

  function log(...args) {
    if (debug) console.log('FRG Gallery:', ...args);
  }

  function logError(...args) {
    if (debug) {
      console.error('FRG Gallery:', ...args);
      console.log('Data:', ...args);
    }
  }

  function loadGallery($gallery) {
    log('Starting gallery load');

    // Find the refresh button for this gallery
    const $refreshButton = $gallery.closest('.flickr-random-gallery-container')
      .find('.gallery-refresh-button');
    const $buttonText = $refreshButton.find('.button-text');

    // Add loading state to button if it exists
    if ($refreshButton.length) {
      $refreshButton.addClass('loading')
        .prop('disabled', true);
    }

    // Get count from data attribute, fallback to default of 9
    const count = parseInt($gallery.data('count')) || 9;
    const target = $gallery.data('target') || '_blank';

    log('Loading gallery with count:', count);

    return $.ajax({
      url: frgAjax.ajaxurl,
      type: 'GET',
      dataType: 'json',
      data: {
        action: 'frg_load_photos',
        nonce: frgAjax.nonce,
        count: count
      }
    }).then(function(response) {
      log('Response received:', response);

      if (response?.success && Array.isArray(response.data)) {
        let html = '';
        response.data.forEach(function(photo) {
          log('Processing photo:', photo);

          const owner = photo.owner || photo.photoset?.owner || '';
          const photoPageUrl = `https://www.flickr.com/photos/${owner}/${photo.id}/in/album-${photo.album_id}`;
          const imgUrl = photo.url_l || `https://farm${photo.farm}.staticflickr.com/${photo.server}/${photo.id}_${photo.secret}_z.jpg`;

          log('Generated URL:', photoPageUrl);

          html += `
                        <div class="gallery-item">
                            <div class="image-wrapper">
                                <a href="${photoPageUrl}" target="${target}" title="${photo.title}">
                                    <img src="${imgUrl}"
                                         alt="${photo.title}"
                                         loading="lazy"
                                         style="transition: transform 0.3s ease-in-out;">
                                    <div class="overlay">
                                        <span class="view-on-flickr">View on Flickr</span>
                                    </div>
                                </a>
                            </div>
                        </div>
                    `;
        });

        $gallery.html(html);

        // After images are loaded, trigger layout adjustments
        $gallery.find('img').on('load', function() {
          $(this).closest('.image-wrapper').addClass('loaded');
        });
      } else {
        throw new Error(response?.data?.message || 'Invalid response format');
      }

      // After successful first load, update button text
      if ($buttonText.text() === 'Loading Photos') {
        $buttonText.text('Refresh Gallery');
      }
    }).catch(function(error) {
      logError('AJAX error:', error);
      $gallery.html('<p class="frg-error">Error loading gallery. Please try again later.</p>');

      // Update button text even on error
      if ($buttonText.text() === 'Loading Photos') {
        $buttonText.text('Refresh Gallery');
      }
    }).always(function() {
      // Reset refresh button state
      if ($refreshButton.length) {
        $refreshButton.removeClass('loading')
          .prop('disabled', false);
      }
    });
  }

  // Initialize galleries on page load
  $(function() {
    log('Initializing galleries');
    document.querySelectorAll('.flickr-random-gallery').forEach(function(element) {
      loadGallery($(element));
    });

    // Add click handler for refresh buttons
    $('.gallery-refresh-button').on('click', function(e) {
      e.preventDefault();
      const $gallery = $(this).closest('.flickr-random-gallery-container')
        .find('.flickr-random-gallery');
      loadGallery($gallery);
    });
  });

  // Add resize handler
  $(window).on('resize', function() {
    document.querySelectorAll('.flickr-random-gallery').forEach(function(element) {
      const $gallery = $(element);
      // Adjust layout if needed on resize
    });
  });

  // Add refresh method to window object
  window.frgRefreshGallery = function(galleryElement) {
    log('Manual gallery refresh triggered');
    return loadGallery($(galleryElement));
  };

})(jQuery);

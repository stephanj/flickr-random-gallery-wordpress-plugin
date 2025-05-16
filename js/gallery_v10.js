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

function loadGallery($gallery, forceRefresh = false) {
    log('Starting gallery load');
    log('Force refresh:', forceRefresh);

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
    const columns = parseInt($gallery.data('columns')) || 3;

    log('Loading gallery with count:', count);

    // Create placeholders first
    let placeholderHtml = '';
    for (let i = 0; i < count; i++) {
      placeholderHtml += `
        <div class="gallery-item">
            <div class="image-wrapper">
                <div class="placeholder-wrapper">
                    <div class="placeholder-shimmer"></div>
                </div>
            </div>
        </div>
      `;
    }
    $gallery.html(placeholderHtml);

    // Now make the AJAX request to load actual images
    return $.ajax({
      url: frgAjax.ajaxurl,
      type: 'GET',
      dataType: 'json',
      data: {
        action: 'frg_load_photos',
        nonce: frgAjax.nonce,
        count: count,
        force_refresh: forceRefresh // Add the force_refresh parameter
      }
    }).then(function(response) {
      log('Response received:', response);
      
      // Log cache status if available
      if (response?.data?.cache) {
        log('Cache status:', response.data.cache);
      } else {
        log('No cache data in response:', response);
      }

      if (response?.success && Array.isArray(response.data.photos)) {
        // Don't replace all HTML, instead update each placeholder with actual content
        response.data.photos.forEach(function(photo, index) {
          log('Processing photo:', photo);

          const owner = photo.owner || photo.photoset?.owner || '';
          const photoPageUrl = `https://www.flickr.com/photos/${owner}/${photo.id}/in/album-${photo.album_id}`;
          const imgUrl = photo.url_l || `https://farm${photo.farm}.staticflickr.com/${photo.server}/${photo.id}_${photo.secret}_z.jpg`;

          log('Generated URL:', photoPageUrl);
          
          // Get the placeholder at the current index
          const $galleryItem = $gallery.find('.gallery-item').eq(index);
          if ($galleryItem.length) {
            const $imageWrapper = $galleryItem.find('.image-wrapper');
            
            // Replace placeholder with actual image
            $imageWrapper.html(`
              <a href="${photoPageUrl}" target="${target}" title="${photo.title}">
                <img src="${imgUrl}"
                     alt="${photo.title}"
                     loading="lazy"
                     style="transition: transform 0.3s ease-in-out;">
                <div class="overlay">
                  <span class="view-on-flickr">View on Flickr</span>
                </div>
              </a>
            `);
            
            // Set up image load event
            $imageWrapper.find('img').on('load', function() {
              $(this).addClass('loaded');
            });
          }
        });

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
      
      // Add more detailed logging
      if (error.responseJSON) {
        logError('Server response:', error.responseJSON);
      }
      
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
      loadGallery($(element), false); // Default to not force refresh
    });

    // Add click handler for refresh buttons
    $('.gallery-refresh-button').on('click', function(e) {
      e.preventDefault();
      const $gallery = $(this).closest('.flickr-random-gallery-container')
        .find('.flickr-random-gallery');
      loadGallery($gallery, false); // Don't force refresh - just reshuffle from cache
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
  window.frgRefreshGallery = function(galleryElement, forceRefresh = false) {
    log('Manual gallery refresh triggered');
    return loadGallery($(galleryElement), forceRefresh);
  };

})(jQuery);

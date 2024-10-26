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

    const count = $gallery.data('count') || 9;
    const target = $gallery.data('target') || '_blank';

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

          // Use owner from photoset info if photo owner is undefined
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
                       loading="lazy">
                  <div class="overlay">
                    <span class="view-on-flickr">View on Flickr</span>
                  </div>
                </a>
              </div>
            </div>
          `;
        });

        // Add inline styles for the gallery grid
        const columns = $gallery.data('columns') || 3;
        $gallery.css({
          'display': 'grid',
          'grid-template-columns': `repeat(${columns}, 1fr)`,
          'gap': '20px',
          'grid-auto-rows': '1fr'
        });

        $gallery.html(html);

        // After images are loaded, trigger layout adjustments
        $gallery.find('img').on('load', function() {
          $(this).closest('.image-wrapper').addClass('loaded');
        });
      } else {
        throw new Error(response?.data?.message || 'Invalid response format');
      }
    }).catch(function(error) {
      logError('AJAX error:', error);
      $gallery.html('<p class="frg-error">Error loading gallery. Please try again later.</p>');
    });
  }

  // Initialize galleries on page load using jQuery ready handler
  $(function() {
    log('Initializing galleries');
    document.querySelectorAll('.flickr-random-gallery').forEach(function(element) {
      loadGallery($(element));
    });
  });

  // Add resize handler using modern event binding
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

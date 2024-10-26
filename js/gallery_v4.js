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
          const photoPageUrl = `https://www.flickr.com/photos/${photo.server}/${photo.id}`;
          const imgUrl = photo.url_l || `https://farm${photo.farm}.staticflickr.com/${photo.server}/${photo.id}_${photo.secret}_z.jpg`;

          html += `
            <div class="gallery-item">
              <a href="${photoPageUrl}" target="${target}" title="${photo.title}">
                <img src="${imgUrl}"
                     alt="${photo.title}"
                     loading="lazy">
                <div class="overlay">
                  <span class="view-on-flickr">View on Flickr</span>
                </div>
              </a>
            </div>
          `;
        });
        $gallery.html(html);
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
      // Add any resize-specific logic here
    });
  });

  // Add refresh method to window object
  window.frgRefreshGallery = function(galleryElement) {
    log('Manual gallery refresh triggered');
    return loadGallery($(galleryElement));
  };

})(jQuery);

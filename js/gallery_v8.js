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

    // Get count from data attribute, fallback to default of 9
    const count = parseInt($gallery.data('count')) || 9;
    const target = $gallery.data('target') || '_blank';

    // Show loading message
    $gallery.html(`
      <div class="frg-loading">
        <div class="frg-loading-spinner"></div>
        <p>Loading gallery...</p>
      </div>
    `);

    // Add loading styles if not already present
    if (!document.getElementById('frg-loading-styles')) {
      const style = document.createElement('style');
      style.id = 'frg-loading-styles';
      style.textContent = `
        .frg-loading {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          padding: 40px;
          text-align: center;
          grid-column: 1 / -1;
        }
        .frg-loading p {
          margin: 20px 0 0;
          color: #666;
          font-style: italic;
        }
        .frg-loading-spinner {
          width: 40px;
          height: 40px;
          border: 3px solid #f3f3f3;
          border-top: 3px solid #3498db;
          border-radius: 50%;
          animation: frg-spin 1s linear infinite;
        }
        @keyframes frg-spin {
          0% { transform: rotate(0deg); }
          100% { transform: rotate(360deg); }
        }
      `;
      document.head.appendChild(style);
    }

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

        // Add inline styles for the gallery grid
        const columns = $gallery.data('columns') || 3;
        $gallery.css({
          'display': 'grid',
          'grid-template-columns': `repeat(${columns}, 1fr)`,
          'gap': '20px',
          'grid-auto-rows': '1fr'
        });

        $gallery.html(html);

        // Add hover effect styles if not already present
        if (!document.getElementById('frg-gallery-styles')) {
          const style = document.createElement('style');
          style.id = 'frg-gallery-styles';
          style.textContent = `
            .gallery-item .image-wrapper {
              overflow: hidden;
              position: relative;
            }
            .gallery-item .image-wrapper img {
              width: 100%;
              height: 100%;
              object-fit: cover;
              display: block;
              transform: scale(1);
              transition: transform 0.3s ease-in-out;
            }
            .gallery-item .image-wrapper:hover img {
              transform: scale(1.1);
            }
            .gallery-item .overlay {
              position: absolute;
              top: 0;
              left: 0;
              right: 0;
              bottom: 0;
              background: rgba(0, 0, 0, 0.5);
              display: flex;
              align-items: center;
              justify-content: center;
              opacity: 0;
              transition: opacity 0.3s ease-in-out;
            }
            .gallery-item .image-wrapper:hover .overlay {
              opacity: 1;
            }
            .view-on-flickr {
              color: white;
              padding: 8px 16px;
              border: 2px solid white;
              border-radius: 4px;
              font-size: 14px;
            }
          `;
          document.head.appendChild(style);
        }

        // After images are loaded, trigger layout adjustments
        $gallery.find('img').on('load', function() {
          $(this).closest('.image-wrapper').addClass('loaded');
        });
      } else {
        throw new Error(response?.data?.message || 'Invalid response format');
      }
    }).catch(function(error) {
      logError('AJAX error:', error);
      $gallery.html(`
        <div class="frg-error" style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">
          <p>Error loading gallery. Please try again later.</p>
        </div>
      `);
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

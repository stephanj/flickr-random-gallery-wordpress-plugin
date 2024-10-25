Here is a sample README file for the Flickr Random Gallery WordPress plugin:

**Flickr Random Gallery WordPress Plugin**
=========================================

A WordPress plugin that displays random photos from selected Flickr albums using a shortcode with async loading.

**Description**
---------------

This plugin allows you to easily add a random photo gallery to your WordPress site. Simply select one or more Flickr albums, and the plugin will display a random photo from each album on your site. The gallery is loaded asynchronously, ensuring a seamless user experience.

**Features**

* Select multiple Flickr albums for a diverse gallery
* Random photos are displayed from selected albums
* Async loading for fast and smooth gallery performance
* Customizable via shortcode attributes (e.g., `count``)
* Compatible with WordPress 5.x and above

**Usage**
---------

1. Install the plugin through the WordPress Plugin Directory or by uploading the plugin files to your site.
2. Go to the WordPress admin dashboard and navigate to **Settings** > **Flickr Random Gallery**.
3. Provide your Flickr API Keys (click on related button to take you to Flickr)
4. Select one or more Flickr albums from the list of available albums.
5. Use the shortcode `[flickr_random_gallery]` in your page or post content to display the random photo gallery.

![AdminScreen](https://github.com/user-attachments/assets/35c9f787-f222-4a5b-a0eb-965ebb43434c)

**Example Usage**
-----------------

```php
[flickr_random_gallery count="4"]
```

This will display a 4-column, medium-sized gallery with random photos from selected Flickr albums.

![Gallery](https://github.com/user-attachments/assets/69851281-f31e-4fe7-9863-9f372674218e)


**License**
----------

The Flickr Random Gallery WordPress plugin is released under the [MIT License](https://opensource.org/licenses/MIT).

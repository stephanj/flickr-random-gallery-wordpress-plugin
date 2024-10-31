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

### Settings

Here you can add your Flickr key and secret.  The "Get Flickr API Keys" button will take you directly to the Flickr page.

![image](https://github.com/user-attachments/assets/c67f6085-ab67-44da-bf09-62e6b1e77b7f)

Once you're authenticated you can select the albums you want to show on your Wordpress page.

![image](https://github.com/user-attachments/assets/9f812320-16cc-4a42-8251-afaf74f28d6f)

The plugin caches the Flickr albums locally.

![image](https://github.com/user-attachments/assets/69008103-9d81-4b4e-9c42-8865b1646cba)

The documentation tab explains how to use the plugin.

![image](https://github.com/user-attachments/assets/3991f231-e19b-47a0-8fdc-0992e398d2d0)


**Example Usage**
-----------------

```php
[flickr_random_gallery count="4"]
```

This will display a 4-column, medium-sized gallery with random photos from selected Flickr albums.

![Gallery](https://github.com/user-attachments/assets/69851281-f31e-4fe7-9863-9f372674218e)

**Zip Installation**
---------------------

```bash
zip -r flickr-random-gallery.zip flickr-random-gallery --exclude "*/.git/*" "*/.idea/*" "*/.DS_Store"
```

**License**
----------

The Flickr Random Gallery WordPress plugin is released under the [MIT License](https://opensource.org/licenses/MIT).

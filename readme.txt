=== DuracellTomi's Google Tag Manager for WordPress ===
Contributors: duracelltomi
Donate link: https://duracelltomi.com/
Tags: google tag manager, tag manager, gtm, google, adwords, google adwords, adwords remarketing, remarketing, google analytics, analytics, facebook ads, facebook remarketing, facebook pixel
Requires at least: 3.4.0
Tested up to: 4.9.0
Stable tag: 1.7.2
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html

The first Google Tag Manager plugin for WordPress with business goals in mind.

== Description ==

Google Tag Manager (GTM) is Google's free tool for everyone to be able to manage your analyitcs, PPC and other code snipets
using an intuitive web UI. To learn more about this tool, visit the [official website](https://www.google.com/analytics/tag-manager/).

This plugin can place the necessary container code snippet into your website so that you do not need to add this manually.
Multiple containers are supported!

The plugin can support your GTM setups by adding lots of meta data about the visited page and about the user into the so called data layer.
GTM help center incluldes [more details about this data layer](https://developers.google.com/tag-manager/devguide#datalayer).

**Some parts of the plugin requires PHP 5.3 newer.
PHP 5.4 or newer is recommended.**

Please note that PHP 5.x is nearing its end of life cycle thus it is recommended to upgrade. If you are not sure which version you are using, please contact
your hosting provider for support.

= GTM container code placement =

GTM container code snippet is currently divided into two parts: the most important part will be added to the <head> tag of your website every time. The second part is
called the iframe tag and it is only being used when users do not have JavaScript enabled. Code placement option only controls this second code snippet. For best
performance this should be just after the opening body tag but it might be also safe to use the footer option in plugin settings as the worst case.

Yaniv Friedensohn showed me a solution that can add the GTM container code after the opening body tag
for almost every theme without modifying the theme files:

http://www.affectivia.com/blog/placing-the-google-tag-manager-in-wordpress-after-the-body-tag/

I added this solution to the plugin, currently as an experimental option.

Users of the Genisis Framework should use the "Custom" option but without altering the theme.
The Google Tag Manager container code will be added automatically.

= Basic data included =

* post/page titles
* post/page dates
* post/page category names
* post/page tag names
* post/page author ID and names
* post/page ID
* post types
* post count on the current page + in the current category/tag/taxonomy
* logged in status
* logged in user role
* logged in user ID (to track cross device behavior in Google Analytics)
* logged in user email address (to comply with [GTM terms of service](https://www.google.com/analytics/tag-manager/use-policy/) do not pass this towards Google tags)
* search data
* site name and id for WordPress multisite instances

= Browser / OS / Device data =

(beta)
 
* browser data (name, version, engine)
* OS data (name, version)
* device data (type, manufacturer, model)

Data is provided using the WhichBrowser library: http://whichbrowser.net/

= Weather data =

(beta)
 
Add the current weather conditions into the dataLayer so that you can use this information to generate special
remarketing lists and additional segmentation in your web analytics solution:

* weather category like clouds, rain, snow, etc.
* weather description: more detailed data
* temperature in Celsius or Fahrenheit
* air pressure
* wind speed and degrees

Weather data is queried from Open Weather Map. Depending on your websites traffic, additional fees may be applied:

http://openweathermap.org/price

It is also required to obtain a free API key from OpenWeatherMap on the page above.

To determine to current location of your visitor, this plugin uses geoplugin.net.
Depending on your websites traffic, additional fees may be applied:

http://www.geoplugin.com/premium

= Media player events =

(experimental)

The plugin can track user interaction with your embeded media:

* YouTube
* Vimeo
* Soundcloud

It fires dataLayer events when a media player was being loaded on the page, when the media is being played, paused or stopped.
It can fire dataLayer events when the user reaches 10, 20, 30, ..., 90, 100% of the media duration.

Tracking should work with embedded media using the oEmbed feauture of WordPress and it should also work with other plugins or even with copy/pasted codes.
Currently, players injected into the website after page load are not being tracked.

= Scroll tracking =

Fire tags based on how the visitor scrolls from the top to the bottom of a page.
You can track this as Analytics events and/or fire remarketing/conversion tags to if you want to track micro conversions.
Separate readers (who spend a specified amount of time on a page) from scrollers (who only scroll through within seconds)

Scroll tracking is based on the solution originally created by

* Nick Mihailovski
* Thomas Baekdal
* Avinash Kaushik
* Joost de Valk
* Eivind Savio
* Justin Cutroni

Original script:
http://cutroni.com/blog/2012/02/21/advanced-content-tracking-with-google-analytics-part-1/

= Google AdWords remarketing =

Google Tag Manager for WordPress can add every dataLayer variable as an AdWords remarketing custom parameter list.
Using this you can create more sophisticated remarketing lists.

= Blacklist & Whitelist Tag Manager tags and variables =

To increase security on your website, you can whitelist and blacklist tags and variables.
This means you can disable certain tags from being fired or prevent the use of certain variable types
from being used regardless of your current Tag Manager setup.

If your Google account is being hacked that is associated with your Google Tag Manager account,
an attacker could easily execute malware on your website without accessing its code on your hosting server.

By blacklisting custom HTML tags and/or custom JavaScript variables for example you can have a more secure Tag Manager container
if you do not use those kind of elements.

= Integration =

Google Tag Manager for WordPress can integrate with several popular plugins.

* Contact Form 7: fire an event after a successful form submission
* WooCommerce:
	* Classic e-commerce:
		* fire event when visitors ads a product to your cart
		* include transaction data to be sent to Google/Universal Analytics
		* include necessary remarketing tags for Google AdWords Dynamic Remarketing
	* Enhanced e-commerce (beta):
		*	implementation of [Enhanced E-commerce](https://developers.google.com/tag-manager/enhanced-ecommerce)
		* Does not include tracking of promotions since WooCommerce does not have such a feature (yet)
		* Does not currently support refunds

More integration to come!

== Installation ==

1. Upload `duracelltomi-google-tag-manager-for-wordpress` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to Settings / Google Tag Manager and enter your Google Tag Manager container ID and setup additional options

== Frequently Asked Questions ==

= How can I ... =

I created some step by step guides so that you can create the proper settings in Google Tag Manager:
http://duracelltomi.com/google-tag-manager-for-wordpress/how-to-articles/

= PayPal / 3rd party payment gateway transactions in WooCommerce are not being tracked in Google Analyics =

PayPal and some other 3rd party payment gateways does not redirect the user back to your website by default
after a successful transaction.
It offers the route back for your customer but it can happen that users simply close the browser
before they get back to your thankyou page (aka. order received page)

This means that neither Google Analyics tags or any other tags are being fired.

Enable auto return in your payment gateway settings. This will instruct them to show a quick
info page after payment and then redirect the user back to your site. This will
increase the number of tracked transactions.

= Why isn't there an option to blacklist tag/variable classes =

Although Google recommends to blacklist tags and variables using classes, I found it is complicated for people to understand
what tags and variables are being blacklisted/whitelisted automatically using classses. Therefore I decided to include
individual tags and variables on the blacklist tabs.

Please remember that tags are useless without variables so only blacklist variables if you are certain that you do not use them
with any tags in your container.

= How can I track scroll events in Google Tag Manager? =

To track scrolling of your visitor you need to setup some tag in Google Tag Manager.

What type of tags?
In most cases you will need Google/Universal Analytics event tags but you can use AdWords remarketing
or conversion tags as well to collect micro conversions or to focus only on visitors who spend more time
reading your contents.

There are five dataLayer events you can use in your rule definitions:

* gtm4wp.reading.articleLoaded: the content has been loaded
* gtm4wp.reading.startReading: the visitor started to scroll. You can use the dataLayer variable `timeToScroll` to see how many seconds have passed since the article has been loaded
* gtm4wp.reading.contentBottom: the visitor reached the end of the content (not the page!). `timeToScroll` dataLayer variable updated
* gtm4wp.reading.pagebottom: the visitor reached the end of the page. `timeToScroll` dataLayer variable updated
* gtm4wp.reading.readerType: at this point we are confident whether the visitor is a 'scanner' or 'reader' depending on how much time have passed since the content has been loaded. `readerType` dataLayer variable holds the type of the visitor

= Can I exclude certain user roles from being tracked? =

Google Tag Manager is not just about visitor tracking.
The ability to include a Google/Universal Analytics tag is only one feature you can manage.

Therefore there is no need to have an option to exclude the container code snippet on certain cases.

If you want to exclude logged in users or certain user roles, use the corresponding dataLayer variable (visitorType)
and an exclude filter in Google Tag Manager.

= How do I put the Google Tag Manager container code next to the opening body tag? =

Go to the admin section of the plugin and select "Custom" from the placement settings.
This way my plugin does not put the code snippet into the footer of the page.

In this case you have to edit your template files.
Go to `wp-content/plugins/themes/<your theme dir>` and edit `header.php`.
In most cases you will find the opening `<body>` tag here.

If you can not find it, contact the author of the theme and ask for instructions.

In case you found the opening `<body>` tag, open a new line just after it and insert this line of code:
`<?php if ( function_exists( 'gtm4wp_the_gtm_tag' ) ) { gtm4wp_the_gtm_tag(); } ?>`

Be careful not to include this line inside any `<div>`, `<p>`, `<header>`, `<article>` and so on.
It can break you theme.

There is also a solution named "Codeless" which tries to add the container code to the right place but
without additional theme tweaking. This is still experimental, use it wisely.

= Why can not this plugin insert the container snippet after the opening body tag automatically? =

Currently WordPress has two 'commands' or 'hooks' that a programmer can use: one for the `<head>` section and
one for the bottom of `<body>`. There is no way to inject any content after the opening body tag without manually editing your template files.

Fortunately some theme authors already resolved this so in some cases you do not need to edit your template.
I suggest first to select the Custom placement and use Google Tag Assistant Chrome browser extension to check
whether the container code is placed as expected.

If it shows an error, go and edit your theme manually.

= Facebook like/share/send button events do not fire for me, why? =

It is a limitation of Facebook. Click event tracking is only available for html5/xfbml buttons.
If you or your social plugin inserts the Facebook buttons using IFRAMEs (like Sociable), it is not possible to track likes.

== Screenshots ==

1. Admin panel
2. Basic settings
3. Events
4. Integration panel
5. Advanced settings
6. Scroll tracking

== Changelog ==

= 1.7.2 =

* Fixed: in some cases, the remove item from cart link in a WooCommerce cart was not altered properly with additional tracking codes
* Fixed: product categories were empty in the cart, on the checkout pages and on the order received page for product variations
* Fixed: checkout option data included checkout step #1 if cart page was setup to be the first
* Fixed: even more WooCommerce 3.x compatibility
* Added: registration date of the logged in user can be added to the data layer
* Updated: geoplugin.net has been replaced by freegeoip.net for weather tracking which has far better quota for free usage
* Updated: AdWords dynamic remarketing data layer items on a WooCommerce product page will be shown for the root product as well on variable product pages
* Updated: Selecting a product variation will include the price of the product in AdWords dynamic remarketing data layer items
* Updated: minor code cleanup

= 1.7.1 =

* Fixed: PHP 5.3 compatible syntax in frontend.php
* Fixed: PHP error using classic ecommerce with WooCommerce 2.6.x
* Updated: Added data-cfasync='false' to all <script> elements to prevent ClourFlare to load scripts async
* Added: Warning for users of PHP 5.4 or older to consier upgrade (FYI: PHP 5.5 and older versions do not get even security fixes)

= 1.7 =

* Updated: even better WooCommerce 3.0 compatibility (WooCommerce 2.6 still supported but this support ends with the next plugin version)
* Fixed: properly escaping product category name on variable product detail pages
* Fixed: proper data layer stucture in the gtm4wp.changeDetailViewEEC event
* Added: Google Optimize page hiding snippet under Integrations tab
* Added: add to cart data for WooCommerce enhanced ecommerce tracking if user undos a cart item removal (no need to update GTM tags)
* Added: you can now enter a product ID prefix so that IDs can match with IDs in some product feeds generated by other plugins
* Added: option to track cart page as step 1 in enhanced ecommerce checkout funnel

= 1.6.1 =

* Fixed: PHP warning message on WooCommerce cart page
* Fixed: Better compatibility with WooCommerce 2.6.x :-)

= 1.6 =

* Fixed: do not block product list item clicks if ad blocker is enabled
* Fixed: only track product clicks in product lists if link points to the product detail page URL
* Fixed: PHP warning in backlogs 'Undefined variable: gtm4wp_options'
* Added: product variation support in WooCommerce integration (enhanced ecommerce implementations should add the GTM event gtm4wp.changeDetailViewEEC to the ecommerce event trigger)
* Updated: better WooCommerce 3.0 compatibility

= 1.5.1 =

* Fixed: clicks on products in product list pages redirected to undefined URLs with some themes.

= 1.5 =

Lots of WooCommerce ecommerce codes has been changed and extended, please double check your measurement after upgrading to this version!

* Added: warning message if you are using PHP 5.3 or older. Browser/OS/Device tracking needs 5.4 or newer
* Added: Email address of the logged in user into the visitorEmail dataLayer variable. Remember: to comply with GTM TOS you are not allowed to pass this data towards any Google tag but you can use this in any other 3rd party tag.
* Added: gtm4wp_eec_product_array WordPress filter so that plugin and theme authors can add their own data for enhanced ecommere product arrays
* Fixed: JavaScript error in WooCommerce stores when enhanced ecommerce enabled and a product being clicked in a widget area
* Fixed: Order data not present in some cases on the order received page
* Changed: Extended "User SKUs instead of IDs for remarketing" option to be also applied to ecommerce product data arrays
* Changed: Use wc_clean instead of the deprecated function woocommerce_clean
* Changed: New, divided GTM container implemented - a fixed part in the <head> and an iframe part placed using the container placement option you've set earlier

= 1.4 =

* Fixed: WP CLI error message
* Fixed: wrong dynamic remarketing tagging on cart and checkout pages
* Updated: WhichBrowser library to 2.0.22
* Updated: slightly changed container code snippet to prevent W3 Total Cache to alter the code which breaks proper code execution
* Updated: replaced file_get_contents() usage in weather tracking to wp_remote_get() so that it is more compatible with several WP instances
* Updated: YouTube/Video/Soundcloud tracking now tracks videos not embedded using oEmbed (like videos in a widget area)
* Updated: new Vimeo Player API implemented which should solve several issues
* Changed: adapted W3C HTML5 media player event names which changes some events (needs updating your existing GTM setup):
  * Soundcloud: finish => ended, seek => seeked
  * YouTube: playing => play, paused => pause, playback-rate-change => ratechange
  * Vimeo: seek => seeked
* Added: new placement option - 'off'. This will only generate the data layer but you will need to add the proper GTM container code snippet by hand
* Added: new data layer variable: authorID
* Added: new data layer variable: siteID to be able to track based on blog ID in a multisite environment
* Added: new data layer variable: siteName to be able to track in a multisite environment

= 1.3.2 =

* Fixed: remove cart event not fired in WooCommerce 2.6
* Fixed: ecomm_prodid.push error message on product detail pages
* Fixed: proper tracking of cart actions on the cart page for WooCommerce 2.6
* Fixed: 'Illegal string offset' errors in some cases in the cart
* Fixed: OpenWeatherMap requires a (free) API key now, you can now enter this to use weather data in data layer

= 1.3.1 =

* Fixed: "json_encode() expects parameter 2 to be long, string given" on PHP 5.3 instances
* Fixed: Fatal PHP error in cart if you enabled taxes to be included in your cart

= 1.3 =

Major changes to the Enhanced Ecommerce implementation of the WooCommerce integration!

* Fixed: proper tracking of list positions
* Fixed: opening product detail page in a new window/tab when user pressed the CTRL key
* Fixed: ecomm_totalvalue included the total price of the cart without taxes
* Fixed: ecomm_totalvalue does not take into account the quantity of ordered products on the order received page
* Fixed: php error message on product lists when AdWords dynamic remarketing was enabled on WooCommerce 2.6
* Fixed: added data-cfasync="false" to the GTM container code for better compatibility with CloudFlare
* Added: introducing tracking of list names (general product list, recent products list, featured products list, etc.)
  * Some list names (like cross-sells) will be shown as 'General Product List'. A proposed change in WooCommerce 2.6 will solve that issue
* Added: tracking product lists in widgets
* Added: tracking checkout options (payment and shipment)
* Updated: better add-to-cart / remove-from-cart management in mini cart and while updating cart content
* Updated: added currency code to each enhanced ecommerce call so that currency reporting is OK for multi currency sites
* Updated: replaced usage of get_currentuser() to keep compatibility with WordPress 4.5

= 1.2 =

* Fixed: subtabs on admin page now showing in certain cases
* Fixed: error message when running the site using WP CLI (thanks Patrick Holberg Hesselberg)
* Fixed: some typos on admin page
* Fixed: dismissable notices did not disappear in some cases
* Fixed: tracking of Twitter event cased sometimes JS errors
* Fixed: site search tracking caused sometimes PHP errors when HTTP_REFERER was not set
* Updated: preparation for translate.wordpress.org
* Added: support for multiple container IDs
* Added: added form ID when sending a Contact Form 7 form. Variable name: gtm4wp.cf7formid

= 1.1.1 =

* Fixed: PHP errors in frontend.php and admin.php

= 1.1 =

* Added: track embedded YouTube/Vimeo/Soundcloud videos (experimental)
* Added: new checkbox - use product SKU for AdWords Dynamic Remarketing variables instead of product ID (experimental)
* Added: place your container code after the opening body tag without modifying your theme files (thx Yaniv Friedensohn)
* Added: automatic codeless container code injection for Genesis framework users
* Fixed: Possible PHP error with custom payment gateway (QuickPay) on the checkout page (thx Damiel for findig this)

= 1.0 =

The plugin itself is now declared as stable. This means that it should work with most WordPress instances.
From now on each version will include features labeled as:

* Beta: the feature has been proven to work for several users but it can still have some bugs
* Experimental: new feature that needs proper testing with more users
* Deprecated: this feature will be removed in a future version

If you see any issue with beta or experimental functions just disable the checkbox. Using this error messages should disappear.
Please report all bugs found in my plugin using the [contact form on my website](https://duracelltomi.com/contact).

* Fixed: wrong GTM container code when renaming default dataLayer variable name (thx Vassilis Papavassiliou)
* Fixed: Enhanced Ecommerce product click data was "undefined" in some cases (thx Sergio Alen)
* Fixed: wrong user role detection while adding visitorType to the dataLayer (thx Philippe Vachon-Rivard)
* Changed: only add visitorId to the dataLayer if there is a logged in user
* Added: feature labels so that you can see beta, experimental and deprecated features
* Deprecated: outbound click, email click and download click events. You should use GTM trigger events instead

= 0.9.1 =

* Fixed: PHP error message: missing get_shipping function using WooCommerce 2.3.x

= 0.9 =

* Added: visitorId dataLayer variable with the ID of the currently logged in user to track userID in Google Analytics
* Added: WordPress filter hook so that other templates and plugins can get access to the GTM container code before outputting it
* Fixed: 'variation incorrect' issue by Sharken03
* Fixed: error messages in WooCommerce integration when product has no categories
* Fixed: add_inline_js errors in newer versions of WooCommerce
* Fixed: error message when some device/browser/OS data could not be set
* Fixed: tracking Twitter events was broken

= 0.8.2 =

* Fixed: broken links when listing subcategories instead of products (thanks Jon)
* Fixed: wheather/weather typo (thanks John Hockaday)
* Fixed: wrong usage of get_the_permalink() instead of get_permalink() (thanks Szepe Viktor)

= 0.8.1 =

* Fixed: PHP error in enhanced ecommerce implementation when using layered nav widget

= 0.8 =

* Updated: Added subtabs to the admin UI to make room for new features :-)
* Updated: WhichBrowser library to the latest version
* Added: You can now dismiss plugin notices permanently for each user
* Added: weather data. See updated plugin description for details
* Added: Enhanced E-commerce for WooCommerce (experimental!)
* Fixed: PHP notice in frontend.php script. Credit to Daniel Sousa

= 0.7.1 =

* Fixed: WooCommerce 2.1.x compatibility

= 0.7 =

* Updated/Fixed: dataLayer variables are now populated at the end of the head section. Using this the container code can appear just after the opening body tag, thus Webmaster Tools verification using Tag Manager option will work
* Added: blacklist or whitelist tags and macros to increase security of your Tag Manager setup


= 0.6 =

* Updated: better add-to-cart events for WooCommerce, it includes now product name, SKU and ID
* Added: browser, OS and device data to dataLayer variables
* Added: postCountOnPage and postCountTotal dataLayer variables to track empty categories/tags/taxonomies

= 0.5.1 =

* Fixed: WooCommerce integration did not work on some environments

= 0.5 =
* Added: scroll tracking
* Fixed: social tracking option on the admin panel was being shown as an edit box instead of a checkbox
* Fixed: WooCommerce transaction data was not included in the dataLayer if you selected "Custom" code placement
* Fixed: do not do anything if you enabled WooCommerce integration but did not activate WooCommerce plugin itself
* Updated: do not re-declare dataLayer variable if it already exists (because another script already created it before my plugin was run)

= 0.4 =
* Added: you can now select container code placement. This way you can insert the code snippet after the opening body tag. Please read FAQ for details
* Added: initial support for social event tracking for Facebook and Twitter buttons. Please read FAQ for details
* Updated: event name on successful WooCommerce transaction: OrderCompleted -> gtm4wp.orderCompleted
* Fixed: frontend JS codes did not load on some WordPress installs

= 0.3 =
* Updated: admin page does not show an alert box if Tag Manager ID or dataLayer variable name is incorrect. Instead it shows a warning line below the input field.
* Updated: rewritten the code for WooCommerce dynamic remarketing. Added tag for homepage and order completed page.

= 0.2 =
* ! BACKWARD INCOMPATIBLE CHANGE ! - Names of Tag Manager click events has been changed to comply with naming conventions:
	* ContactFormSubmitted -> gtm4wp.contactForm7Submitted
	* DownloadClick -> gtm4wp.downloadClick
	* EmailClick -> gtm4wp.emailClick
	* OutboundClick -> gtm4wp.outboundClick
	* AddProductToCart -> gtm4wp.addProductToCart
* Updated: click events are now disabled by default to reflect recently released Tag Manager auto events. I do not plan to remove this functionality. You can decide which solution you would like to use :-)
* Updated: language template (pot) file and Hungarian translation
* Added: new form move events to track how visitors interact with your (comment, contact, etc.) forms
* Added: event names to admin options page so that you know what events to use in Google Tag Manager
* Added: Google Tag Manager icon to admin settings page
* Added: Settings link to admin plugins page
* Fixed: null value in visitorType dataLayer variable if no logged in user exists (now 'visitor-logged-out')

= 0.1 =
* First beta release

== Upgrade Notice ==

= 1.7.2 =

Bugfix release: many little fixes, event better WooCommerce 3.x compatibility

= 1.7.1 =

Bugfix release: better PHP 5.3 and WooCommerce 2.6.x compatibility

= 1.7 =

Better WooCommerce 3.x compatibility and new features

= 1.6.1 =

Bugfix release.

= 1.6 =

If you are using WooCommerce and enhanced ecommerce, please add gtm4wp.changeDetailViewEEC to the ecommerce helper trigger

= 1.5.1 =

Fixed: clicks on products in product list pages redirected to undefined URLs with some themes.

= 1.5 =

Lots of WooCommerce ecommerce codes has been changed and extended, please double check your measurement after upgrading to this version!

= 1.4 =

Several additions and fixes, breaking changes on media player tracking, please read changelog before upgrade

= 1.3.2 =

Quickfix release for 1.3.x: major changes and improvements in the enhanced ecommerce implementation for WooCommerce. If you are already using this beta feature, please read the changelog before upgrading!

= 1.3.1 =

Quickfix release for 1.3: major changes and improvements in the enhanced ecommerce implementation for WooCommerce. If you are already using this beta feature, please read the changelog before upgrading!

= 1.3 =

Major changes and improvements in the enhanced ecommerce implementation for WooCommerce. If you are already using this beta feature, please read the changelog before upgrading!

= 1.2 =

New release with lots of fixes from the past months and new features like multiple container support!

= 1.1.1 =

This is a bugfix release to address some issues with v1.1

= 1.1 =

New! Track popular media players embedded into your website!

= 1.0 =

First stable release, please read changelog for details!

= 0.9.1 =

Bugfix release for WooCommerce users with ecommerce tracking enabled

= 0.9 =

Many bug fixes, important fixes for WooCommerce users

= 0.8.2 =

Another bugfix release for WooCommerce users with Enhanced Ecommerce enabled

= 0.8.1 =

Bugfix release for WooCommerce users with Enhanced Ecommerce enabled

= 0.8 =

This version instroduces Enhanced E-commerce implementation for WooCommerce. Please note that this
feature of the plugin is still experimental and the feature of Google Analytics is still in beta.
Read the plugin FAQ for details.

= 0.7.1 =

If you are using WooCommerce and updated to 2.1.x you SHOULD update immediatelly.
This release includes a fix so that transaction data can be passed to GTM.

= 0.7 =

Improved code so that Webmaster Tools verification can work using your GTM container tag.
Blacklist or whitelist tags and macros to increase security of your Tag Manager setup.
Fixed: WhichBroswer library was missing from 0.6

= 0.6 =

Improved add-to-cart events for WooCommerce, added browser/OS/device infos and post count infos.

= 0.5.1 =

Bug fix release for WooCommerce users.

= 0.5 =
Besides of some fixes this version includes scroll tracking events for Google Tag Manager.

= 0.4 =
Important change: Tag Manager event name of a WooCommerce successful order has been changed. 
See changelog for details.

= 0.3 =
This is a minor release. If you are using WooCommerce you should update to include more accurate AdWords dynamic remarketing feature.

= 0.2 =
BACKWARD INCOMPATIBLE CHANGE: Names of Tag Manager click events has been changed to comply with naming conventions.
See changelog for details. Do not forget to update your Tag Manager container setup after upgrading this plugin!

= 0.1 =
This is the first public beta, no upgrade is needed.

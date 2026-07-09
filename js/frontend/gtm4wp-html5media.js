var gtm4wp_html5media_percentage_tracking = 10;
var gtm4wp_html5media_percentage_tracking_marks = {};

;jQuery(function() {
	jQuery( 'video:not([src*="vimeo.com|youtube.com|soundcloud.com"]),video:has(source),audio[src],audio:has(source)' ).each(function() {
		var html5media_filename = this.currentSrc.split("/").pop();

		window[ gtm4wp_datalayer_name ].push({
			'event': 'gtm4wp.mediaPlayerReady',
			'mediaType': 'html5media',
			'mediaData': {
				'id': html5media_filename,
				'author': '',
				'title': html5media_filename,
				'url': this.currentSrc,
				'duration': 0 // not available until video has be started to play
			},
			'mediaCurrentTime': 0
		});

		jQuery( this ).on( 'play pause seeked ended', function( e ) {
			var html5media_filename = this.currentSrc.split("/").pop();

			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.mediaPlayerStateChange',
				'mediaType': 'html5media',
				'mediaData': {
					'id': html5media_filename,
					'author': '',
					'title': html5media_filename,
					'url': this.currentSrc,
					'duration': isNaN( this.duration ) ? 0 : this.duration // not available until video has be started to play
				},
				'mediaPlayerState': e.type,
				'mediaCurrentTime': isNaN( this.currentTime ) ? 0 : this.currentTime  // not available until video has be started to play
			});
		});

		jQuery( this ).on( 'error', function( e ) {
			var html5media_filename = this.currentSrc.split("/").pop();

			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.mediaPlayerEvent',
				'mediaType': 'html5video',
				'mediaData': {
					'id': html5media_filename,
					'author': '',
					'title': html5media_filename,
					'url': this.currentSrc,
					'duration': isNaN( this.duration ) ? 0 : this.duration // not available until video has be started to play
				},
				'mediaCurrentTime': isNaN( this.currentTime ) ? 0 : this.currentTime,  // not available until video has be started to play
				'mediaPlayerEvent': 'error',
				'mediaPlayerEventParam': this.error.code // see https://developer.mozilla.org/en-US/docs/Web/API/MediaError/code
			});
		});

		jQuery( this ).on( 'ratechange', function( e ) {
			var html5media_filename = this.currentSrc.split("/").pop();

			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.mediaPlayerEvent',
				'mediaType': 'html5video',
				'mediaData': {
					'id': html5media_filename,
					'author': '',
					'title': html5media_filename,
					'url': this.currentSrc,
					'duration': isNaN( this.duration ) ? 0 : this.duration // not available until video has be started to play
				},
				'mediaCurrentTime': isNaN( this.currentTime ) ? 0 : this.currentTime,  // not available until video has be started to play
				'mediaPlayerEvent': 'ratechange',
				'mediaPlayerEventParam': this.playbackRate
			});
		});

		jQuery( this ).on( 'volumechange', function( e ) {
			var html5media_filename = this.currentSrc.split("/").pop();

			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.mediaPlayerEvent',
				'mediaType': 'html5video',
				'mediaData': {
					'id': html5media_filename,
					'author': '',
					'title': html5media_filename,
					'url': this.currentSrc,
					'duration': isNaN( this.duration ) ? 0 : this.duration // not available until video has be started to play
				},
				'mediaCurrentTime': isNaN( this.currentTime ) ? 0 : this.currentTime,  // not available until video has be started to play
				'mediaPlayerEvent': 'volumechange',
				'mediaPlayerEventParam': this.volume
			});
		});

		jQuery( this ).on( 'timeupdate', function( e ) {
			if ( isNaN( this.duration ) || isNaN( this.currentTime ) ) {
				return;
			}

			var videoDuration       = this.duration;
			var videoCurrentTime    = this.currentTime;
			var videoPercentage     = Math.floor( videoCurrentTime / videoDuration * 100 );
			var html5media_filename = this.currentSrc.split("/").pop();
			var videoid             = html5media_filename;

			if ( typeof gtm4wp_html5media_percentage_tracking_marks[ videoid ] == "undefined" ) {
				gtm4wp_html5media_percentage_tracking_marks[ videoid ] = [];
			}

			for( var i=0; i<100; i+=gtm4wp_html5media_percentage_tracking ) {
				if ( ( videoPercentage > i ) && ( gtm4wp_html5media_percentage_tracking_marks[ videoid ].indexOf( i ) == -1 ) ) {

					gtm4wp_html5media_percentage_tracking_marks[ videoid ].push( i );

					window[ gtm4wp_datalayer_name ].push({
						'event': 'gtm4wp.mediaPlaybackPercentage',
						'mediaType': 'html5media',
						'mediaData': {
							'id': html5media_filename,
							'author': '',
							'title': html5media_filename,
							'url': this.currentSrc,
							'duration': videoDuration
						},
						'mediaCurrentTime': videoCurrentTime,
						'mediaPercentage': i
					});

				}
			}
		});

	}); // end each video element
});
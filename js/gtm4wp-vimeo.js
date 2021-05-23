let gtm4wp_vimeo_percentage_tracking = 10;
let gtm4wp_vimeo_percentage_tracking_marks = {};

window.addEventListener('DOMContentLoaded', function() {
	const gtm4wp_vimeo_frames = document.querySelectorAll( 'iframe[src*="vimeo.com"]' );
	if ( !gtm4wp_vimeo_frames || gtm4wp_vimeo_frames.length == 0 ) {
		return;
	}

	gtm4wp_vimeo_frames.forEach(function( vimeo_frame ) {
		const vimeoapi = new Vimeo.Player( vimeo_frame );
		let videourl = vimeo_frame
			.getAttribute( "src" )
			.split( "?" )
			.shift();
		let videoid = videourl.split( "/" )
			.pop();

		vimeo_frame.setAttribute( "data-player_id", videoid );
		vimeo_frame.setAttribute( "data-player_url", videourl );

		vimeoapi.getVideoTitle().then( function( title ) {
			vimeo_frame.setAttribute( "data-player_title", title );

			vimeoapi.getDuration().then( function( duration ) {

				vimeo_frame.setAttribute( "data-player_duration", duration );

				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.mediaPlayerReady',
					'mediaType': 'vimeo',
					'mediaData': {
						'id': videoid,
						'author': '',
						'title': vimeo_frame.getAttribute( "data-player_title" ),
						'url': videourl,
						'duration': duration
					},
					'mediaCurrentTime': 0
				});

			}).catch( function( error ) {

				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.mediaPlayerEvent',
					'mediaType': 'vimeo',
					'mediaData': {
						'id': videoid,
						'author': '',
						'title': vimeo_frame.getAttribute( "data-player_title" ),
						'url': videourl,
						'duration': 0
					},
					'mediaCurrentTime': 0,
					'mediaPlayerEvent': 'error',
					'mediaPlayerEventParam': error
				});

			}); // end of api call getDuration

		}).catch( function( error ) {

			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.mediaPlayerEvent',
				'mediaType': 'vimeo',
				'mediaData': {
					'id': videoid,
					'author': '',
					'title': "Unknown title",
					'url': videourl,
					'duration': 0
				},
				'mediaCurrentTime': 0,
				'mediaPlayerEvent': 'error',
				'mediaPlayerEventParam': error
			});

		}); // end of api call getVideoTitle

		vimeoapi.on( 'play', function( data ) {
			gtm4wp_onVimeoPlayerStateChange( 'play', data );
		});

		vimeoapi.on( 'pause', function( data ) {
			gtm4wp_onVimeoPlayerStateChange( 'pause', data );
		});

		vimeoapi.on( 'ended', function( data ) {
			gtm4wp_onVimeoPlayerStateChange( 'ended', data );
		});

		vimeoapi.on( 'seeked', function( data ) {
			gtm4wp_onVimeoPlayerStateChange( 'seeked', data );
		});

		vimeoapi.on( 'texttrackchange', function( data ) {

			vimeoapi.getCurrentTime().then( function( seconds ) {

				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.mediaPlayerEvent',
					'mediaType': 'vimeo',
					'mediaData': {
						'id': videoid,
						'author': '',
						'title': vimeo_frame.getAttribute( "data-player_title" ),
						'url': vimeo_frame.getAttribute( "data-player_url" ),
						'duration': vimeo_frame.getAttribute( "data-player_duration" )
					},
					'mediaPlayerEvent': 'texttrackchange',
					'mediaPlayerEventParam': data,
					'mediaCurrentTime': seconds
				});

			}).catch( function( error ) {

				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.mediaPlayerEvent',
					'mediaType': 'vimeo',
					'mediaData': {
						'id': videoid,
						'author': '',
						'title': "Unknown title",
						'url': videourl,
						'duration': vimeo_frame.getAttribute( "data-player_duration" )
					},
					'mediaCurrentTime': 0,
					'mediaPlayerEvent': 'error',
					'mediaPlayerEventParam': error
				});

			}); // end call api getCurrentTime()

		});

		vimeoapi.on( 'volumechange', function( data ) {

			vimeoapi.getCurrentTime().then( function( seconds ) {

				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.mediaPlayerEvent',
					'mediaType': 'vimeo',
					'mediaData': {
						'id': videoid,
						'author': '',
						'title': vimeo_frame.getAttribute( "data-player_title" ),
						'url': vimeo_frame.getAttribute( "data-player_url" ),
						'duration': vimeo_frame.getAttribute( "data-player_duration" )
					},
					'mediaPlayerEvent': 'volumechange',
					'mediaPlayerEventParam': data.volume,
					'mediaCurrentTime': seconds

				});

			}).catch( function( error ) {

				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.mediaPlayerEvent',
					'mediaType': 'vimeo',
					'mediaData': {
						'id': videoid,
						'author': '',
						'title': "Unknown title",
						'url': videourl,
						'duration': vimeo_frame.getAttribute( "data-player_duration" )
					},
					'mediaCurrentTime': 0,
					'mediaPlayerEvent': 'error',
					'mediaPlayerEventParam': error
				});

			}); // end call api getCurrentTime()

		});

		vimeoapi.on( 'error', function( data ) {

			vimeoapi.getCurrentTime().then( function( seconds ) {

				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.mediaPlayerEvent',
					'mediaType': 'vimeo',
					'mediaData': {
						'id': videoid,
						'author': '',
						'title': vimeo_frame.getAttribute( "data-player_title" ),
						'url': vimeo_frame.getAttribute( "data-player_url" ),
						'duration': vimeo_frame.getAttribute( "data-player_duration" )
					},
					'mediaPlayerEvent': 'error',
					'mediaPlayerEventParam': data,
					'mediaCurrentTime': seconds

				});

			}).catch( function( error ) {

				window[ gtm4wp_datalayer_name ].push({
					'event': 'gtm4wp.mediaPlayerEvent',
					'mediaType': 'vimeo',
					'mediaData': {
						'id': videoid,
						'author': '',
						'title': "Unknown title",
						'url': videourl,
						'duration': vimeo_frame.getAttribute( "data-player_duration" )
					},
					'mediaCurrentTime': 0,
					'mediaPlayerEvent': 'error',
					'mediaPlayerEventParam': error
				});

			}); // end call api getCurrentTime()

		});

		vimeoapi.on( 'timeupdate', function( data ) {
			gtm4wp_onVimeoPercentageChange( data );
		});

		const gtm4wp_onVimeoPlayerStateChange = function( player_state, data ) {

			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.mediaPlayerStateChange',
				'mediaType': 'vimeo',
				'mediaData': {
					'id': videoid,
					'author': '',
					'title': vimeo_frame.getAttribute( "data-player_title" ),
					'url': vimeo_frame.getAttribute( "data-player_url" ),
					'duration': data.duration
				},
				'mediaPlayerState': player_state,
				'mediaCurrentTime': data.seconds
			});

		};

		const gtm4wp_onVimeoPercentageChange = function( data ) {

			let videoDuration   = data.duration;
			let videoPercentage = Math.floor( data.seconds / videoDuration * 100 );

			if ( typeof gtm4wp_vimeo_percentage_tracking_marks[ videoid ] == "undefined" ) {
				gtm4wp_vimeo_percentage_tracking_marks[ videoid ] = [];
			}

			for( let i=0; i<100; i+=gtm4wp_vimeo_percentage_tracking ) {
				if ( ( videoPercentage > i ) && ( gtm4wp_vimeo_percentage_tracking_marks[ videoid ].indexOf( i ) == -1 ) ) {

					gtm4wp_vimeo_percentage_tracking_marks[ videoid ].push( i );

					window[ gtm4wp_datalayer_name ].push({
						'event': 'gtm4wp.mediaPlaybackPercentage',
						'mediaType': 'vimeo',
						'mediaData': {
							'id': videoid,
							'author': '',
							'title': vimeo_frame.getAttribute( "data-player_title" ),
							'url': vimeo_frame.getAttribute( "data-player_url" ),
							'duration': videoDuration
						},
						'mediaCurrentTime': data.seconds,
						'mediaPercentage': i
					});

				}
			}
		};

	});
});

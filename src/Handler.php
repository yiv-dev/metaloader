<?php
namespace YIVDEV\METALOADER;

class Handler {
    private $url;

    function __construct(string $url) {
       $this->url = $url;
    }

    public function read_stream() {
        $meta_result    = false;
        $icy_metaint    = false;
        $result         = false;

        // Stream context default request headers
        $stream_context = stream_context_create(
            [
                'http' => [
                    'method'        => 'GET',
                    'header'        => 'Icy-MetaData: 1',
                    'user_agent'    => 'Mozilla/5.0 (AIO Radio Station Player) AppleWebKit/537.36 (KHTML, like Gecko)',
                    'timeout'       => 6,
                    'ignore_errors' => true
                ]
            ]
        );

        // Attempt to open stream, read it and close connection (all here)
        if ( $stream = @fopen( $this->url, 'r', false, $stream_context ) ) {

            if ( $stream && ( $meta_data = stream_get_meta_data( $stream ) ) && isset( $meta_data[ 'wrapper_data' ] ) ) {

                foreach ( $meta_data[ 'wrapper_data' ] as $header ) { // Loop headers searching something to indicate codec

                    if ( strpos( strtolower( $header ), 'icy-metaint' ) !== false ) { // Expected something like: string(17) "icy-metaint:16000" for MP3

                        $tmp         = explode( ":", $header );
                        $icy_metaint = trim( $tmp[ 1 ] ); // Should be interval value
                        break;

                    } else if ( $header == 'Content-Type: application/ogg' ) { // OGG Codec (start is 0)

                        $icy_metaint = 0;

                    }

                }

            }

            // Stream returned metadata refresh time, use it to get streamTitle info.
            if ( $icy_metaint !== false && is_numeric( $icy_metaint ) ) {

                $buffer = stream_get_contents( $stream, 600, $icy_metaint );

                // Attempt to find string "StreamTitle" in stream with length of 600 bytes and $icy_metaint is offset where to start
                if ( strpos( $buffer, 'StreamTitle=' ) !== false ) {

                    $title = explode( 'StreamTitle=', $buffer );
                    $title = trim( $title[ 1 ] );

                    // Use regex to match 'Song name - Title'; from StreamTitle='format';
                    if ( preg_match( "/'?([^'|^;]*)'?;/", $title, $m ) )
                        $meta_result = $m[ 1 ];

                    // Icecast method ( only works if stream title / artist are on beginning )
                } else if ( strpos( $buffer, 'TITLE=' ) !== false && strpos( $buffer, 'ARTIST=' ) !== false ) {

                    // This is not the best solution, it doesn't parse binary it just removes control characters after regex
                    preg_match( '/TITLE=(?P<title>.*)ARTIST=(?P<artist>.*)ENCODEDBY/s', $buffer, $m );                              // Match TITLE/ARTIST on the beginning of stream (OGG metadata)
                    $meta_result = preg_replace( '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $m[ 'artist' ] . ' - ' . $m[ 'title' ] );     // Remove control characters like '\u10'...

                }

                if (!!$meta_result) {
                    $meta = explode( ' - ', $meta_result );
                    $result = [
                        'artist' => $meta[0],
                        'title' => $meta[1],
                        'year'  => $meta[2]
                    ];
                    $cover_image = $this->from_api_itunes( $meta[0] );
                    if (!!$cover_image) {
                        $result['image'] = $cover_image;
                    }
                }

            }

            fclose( $stream );



        }

        // Handle information gathered so far
        return $result;
    }

    private function curl_custom( $url, $post = null, $auth = null, $progress = false, $timeout = 5, &$error = false, $options = [] ) {

        // Create CURL Object
        $CURL = curl_init();

        // By using array union we can pre-set/change options from function call
        $curl_opts = $options + array(
                CURLOPT_URL            => $url,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (AIO Radio Station Player) AppleWebKit/537.36 (KHTML, like Gecko)',
                CURLOPT_FOLLOWLOCATION => ( ( ini_get( 'open_basedir' ) == false ) ? true : false ),
                CURLOPT_CONNECTTIMEOUT => ( ( $timeout < 6 && $timeout != 0 ) ? 5 : $timeout ),
                //CURLOPT_REFERER        => 'http' . ( ( $_SERVER[ 'SERVER_PORT' ] == 443 ) ? 's://' : '://' ) . $_SERVER[ 'HTTP_HOST' ] . strtok( $_SERVER[ 'REQUEST_URI' ], '?' ),
                CURLOPT_CAINFO         => dirname( __FILE__ ) . '/bundle.crt'
            );


        // Post data to the URL (expects array)
        if ( isset( $post ) && is_array( $post ) ) {

            // Make every just simpler using custom array for options
            $curl_opts = $curl_opts + [
                    CURLOPT_POSTFIELDS    => http_build_query( $post, '', '&' ),
                    CURLOPT_POST          => true,
                    CURLOPT_FRESH_CONNECT => true,
                    CURLOPT_FORBID_REUSE  => true
            ];

        }

        // Use HTTP Authorization
        if ( isset( $auth ) && !empty( $auth ) ) {

            $curl_opts = $curl_opts + array( CURLOPT_USERPWD => $auth );

        }

        // Call anonymous $progress_function function
        if ( $progress !== false && is_callable( $progress ) ) {

            $curl_opts = $curl_opts + array(
                    CURLOPT_NOPROGRESS       => false,
                    CURLOPT_PROGRESSFUNCTION => $progress
                );

        }

        // Before executing CURL pass options array to the session
        curl_setopt_array( $CURL, $curl_opts );

        // Finally execute CURL
        $error = null;
        $data = curl_exec( $CURL );
        // Parse ERROR
         if ( curl_error( $CURL ) ) {

            // This must be referenced in-memory variable
            $error = curl_error( $CURL );

            
        }

        // Close connection and return data
        curl_close( $CURL );
        return ['data' => $data, 'errors' => $error];

    }

    private function from_api_itunes( $artist ) {

        // Attempt searching for image
        $data = $this->curl_custom( 'http://itunes.apple.com/search?term=' . urlencode( $artist ) . '&attribute=allArtistTerm&entity=musicTrack&limit=1' );

        // If there is an response
        if ( $data['data'] !== false ) {

            // Read JSON String
            $data = json_decode( $data['data'], true );

            // Reading JSON
            if ( $data[ 'resultCount' ] >= 1 ) { // Check if result is not empty

                // Find position of LAST slash (/)
                $last_slash = strripos( $data[ 'results' ][ 0 ][ 'artworkUrl100' ], '/' );

                // Return the modified string
                return $data[ 'results' ][ 0 ][ 'artworkUrl100' ];

            }

        }

        return false;

    }
}
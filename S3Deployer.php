<?php

class WP2Static_GitHub extends WP2Static_SitePublisher {

    public function __construct() {
        // calling outside WP chain, need to specify this
        // Add-on's option keys
        $deploy_keys = array(
          'github',
          array(
            'baseUrl-github',
            'cfDistributionId',
            'githubBucket',
            'githubKey',
            'githubRegion',
            'githubRemotePath',
            'githubSecret',
          ),
        );

        $this->loadSettings( 'github', $deploy_keys );

        $this->previous_hashes_path =
            $this->settings['wp_uploads_path'] .
                '/WP2STATIC-GitHub-PREVIOUS-HASHES.txt';

        if ( defined( 'WP_CLI' ) ) {
            return; }

        switch ( $_POST['ajax_action'] ) {
            case 'test_github':
                $this->test_github();
                break;
            case 'github_prepare_export':
                $this->bootstrap();
                $this->loadArchive();
                $this->prepareDeploy();
                break;
            case 'github_transfer_files':
                $this->bootstrap();
                $this->loadArchive();
                $this->upload_files();
                break;
            case 'cloudfront_invalidate_all_items':
                $this->cloudfront_invalidate_all_items();
                break;
        }
    }

    public function upload_files() {
        $this->files_remaining = $this->getRemainingItemsCount();

        if ( $this->files_remaining < 0 ) {
            echo 'ERROR';
            die(); }

        $batch_size = $this->settings['deployBatchSize'];

        if ( $batch_size > $this->files_remaining ) {
            $batch_size = $this->files_remaining;
        }

        $lines = $this->getItemsToDeploy( $batch_size );

        $this->openPreviousHashesFile();

        require_once dirname( __FILE__ ) .
            '/../static-html-output-plugin' .
            '/plugin/WP2Static/MimeTypes.php';

        foreach ( $lines as $line ) {
            list($local_file, $this->target_path) = explode( ',', $line );

            $local_file = $this->archive->path . $local_file;

            if ( ! is_file( $local_file ) ) {
                continue; }

            if ( isset( $this->settings['githubRemotePath'] ) ) {
                $this->target_path =
                    $this->settings['githubRemotePath'] . '/' . $this->target_path;
            }

            $this->logAction(
                "Uploading {$local_file} to {$this->target_path} in GitHub"
            );

            $this->local_file_contents = file_get_contents( $local_file );

            $this->hash_key = $this->target_path . basename( $local_file );

            if ( isset( $this->file_paths_and_hashes[ $this->hash_key ] ) ) {
                $prev = $this->file_paths_and_hashes[ $this->hash_key ];
                $current = crc32( $this->local_file_contents );

                if ( $prev != $current ) {
                    $this->logAction(
                        "{$this->hash_key} differs from previous deploy cache "
                    );

                    try {
                        $this->put_github_object(
                            $this->target_path .
                                    basename( $local_file ),
                            $this->local_file_contents,
                            GuessMimeType( $local_file )
                        );

                    } catch ( Exception $e ) {
                        $this->handleException( $e );
                    }
                } else {
                    $this->logAction(
                        "Skipping {$this->hash_key} as identical " .
                            'to deploy cache'
                    );
                }
            } else {
                $this->logAction(
                    "{$this->hash_key} not found in deploy cache "
                );

                try {
                    $this->put_github_object(
                        $this->target_path .
                                basename( $local_file ),
                        $this->local_file_contents,
                        GuessMimeType( $local_file )
                    );

                } catch ( Exception $e ) {
                    $this->handleException( $e );
                }
            }

            $this->recordFilePathAndHashInMemory(
                $this->hash_key,
                $this->local_file_contents
            );
        }

        $this->writeFilePathAndHashesToFile();

        $this->pauseBetweenAPICalls();

        if ( $this->uploadsCompleted() ) {
            $this->finalizeDeployment();
        }
    }

    public function test_github() {
        try {
            $this->put_github_object(
                '.tmp_wp2static.txt',
                'Test WP2Static connectivity',
                'text/plain'
            );

            if ( ! defined( 'WP_CLI' ) ) {
                echo 'SUCCESS';
            }
        } catch ( Exception $e ) {
            require_once dirname( __FILE__ ) .
                '/../static-html-output-plugin' .
                '/plugin/WP2Static/WsLog.php';

            WsLog::l( 'GitHub ERROR RETURNED: ' . $e );
            echo "There was an error testing GitHub.\n";
        }
    }

    public function put_github_object( $github_path, $content, $content_type ) {
        // NOTE: quick fix for #287
        $github_path = str_replace( '@', '%40', $github_path );

        $this->logAction( "PUT'ing file to {$github_path} in GitHub" );

        $host_name = $this->settings['githubBucket'] . '.github.' .
            $this->settings['githubRegion'] . '.amazonaws.com';

        $this->logAction( "Using GitHub Endpoint {$host_name}" );

        //$content_acl = 'public-read';
        $content_title = $github_path;
        $aws_service_name = 'github';
        $timestamp = gmdate( 'Ymd\THis\Z' );
        $date = gmdate( 'Ymd' );

        // HTTP request headers as key & value
        $request_headers = array();
        $request_headers['Content-Type'] = $content_type;
        $request_headers['Date'] = $timestamp;
        $request_headers['Host'] = $host_name;
        //$request_headers['x-amz-acl'] = $content_acl;
        $request_headers['x-amz-content-sha256'] = hash( 'sha256', $content );

        if ( ! empty( $this->settings[ 'githubCacheControl' ] ) ) {
            $max_age = $this->settings[ 'githubCacheControl' ];
            $request_headers['Cache-Control'] = 'max-age=' . $max_age;
        }

        // Sort it in ascending order
        ksort( $request_headers );

        $canonical_headers = array();

        foreach ( $request_headers as $key => $value ) {
            $canonical_headers[] = strtolower( $key ) . ':' . $value;
        }

        $canonical_headers = implode( "\n", $canonical_headers );

        $signed_headers = array();

        foreach ( $request_headers as $key => $value ) {
            $signed_headers[] = strtolower( $key );
        }

        $signed_headers = implode( ';', $signed_headers );

        $canonical_request = array();
        $canonical_request[] = 'PUT';
        $canonical_request[] = '/' . $content_title;
        $canonical_request[] = '';
        $canonical_request[] = $canonical_headers;
        $canonical_request[] = '';
        $canonical_request[] = $signed_headers;
        $canonical_request[] = hash( 'sha256', $content );
        $canonical_request = implode( "\n", $canonical_request );
        $hashed_canonical_request = hash( 'sha256', $canonical_request );

        $scope = array();
        $scope[] = $date;
        $scope[] = $this->settings['githubRegion'];
        $scope[] = $aws_service_name;
        $scope[] = 'aws4_request';

        $string_to_sign = array();
        $string_to_sign[] = 'AWS4-HMAC-SHA256';
        $string_to_sign[] = $timestamp;
        $string_to_sign[] = implode( '/', $scope );
        $string_to_sign[] = $hashed_canonical_request;
        $string_to_sign = implode( "\n", $string_to_sign );

        // Signing key
        $k_secret = 'AWS4' . $this->settings['githubSecret'];
        $k_date = hash_hmac( 'sha256', $date, $k_secret, true );
        $k_region =
            hash_hmac( 'sha256', $this->settings['githubRegion'], $k_date, true );
        $k_service = hash_hmac( 'sha256', $aws_service_name, $k_region, true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

        $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

        $authorization = [
            'Credential=' . $this->settings['githubKey'] . '/' .
                implode( '/', $scope ),
            'SignedHeaders=' . $signed_headers,
            'Signature=' . $signature,
        ];

        $authorization =
            'AWS4-HMAC-SHA256' . ' ' . implode( ',', $authorization );

        $curl_headers = [ 'Authorization: ' . $authorization ];

        foreach ( $request_headers as $key => $value ) {
            $curl_headers[] = $key . ': ' . $value;
        }

        $url = 'http://' . $host_name . '/' . $content_title;

        $this->logAction( "GitHub URL: {$url}" );

        $ch = curl_init( $url );

        curl_setopt( $ch, CURLOPT_HEADER, false );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
        // curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 0 );
        curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
        curl_setopt( $ch, CURLOPT_USERAGENT, 'WP2Static.com' );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 20 );
        curl_setopt( $ch, CURLOPT_VERBOSE, 1 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $content );

        $output = curl_exec( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

        if ( ! $output ) {
            $this->logAction( "No response from API request, printing cURL error" );
            $response = curl_error( $ch );
            $this->logAction( stripslashes( $response ) );

            throw new Exception(
                'No response from API request, check Debug Log'
            );
        }

        if ( ! $http_code ) {
            $this->logAction( "No response code from API, printing cURL info" );
            $this->logAction( print_r( curl_getinfo( $ch ), true ) );

            throw new Exception(
                'No response code from API, check Debug Log'
            );
        }

        $this->logAction( "API response code: {$http_code}" );
        $this->logAction( "API response body: {$output}" );

        // TODO: pass $ch to checkForValidResponses
        $this->checkForValidResponses(
            $http_code,
            array( '100', '200' )
        );

        curl_close( $ch );
    }

    public function cloudfront_invalidate_all_items() {
        $this->logAction( 'Invalidating all CloudFront items' );

        if ( ! isset( $this->settings['cfDistributionId'] ) ) {
            $this->logAction(
                'No CloudFront distribution ID set, skipping invalidation'
            );

            if ( ! defined( 'WP_CLI' ) ) {
                echo 'SUCCESS'; }

            return;
        }

        $distribution = $this->settings['cfDistributionId'];
        $access_key = $this->settings['githubKey'];
        $secret_key = $this->settings['githubSecret'];

        $epoch = date( 'U' );

        $xml = <<<EOD
<InvalidationBatch>
    <Path>/*</Path>
    <CallerReference>{$distribution}{$epoch}</CallerReference>
</InvalidationBatch>
EOD;

        $len = strlen( $xml );
        $date = gmdate( 'D, d M Y G:i:s T' );
        $sig = base64_encode(
            hash_hmac( 'sha1', $date, $secret_key, true )
        );
        $msg = 'POST /2010-11-01/distribution/';
        $msg .= "{$distribution}/invalidation HTTP/1.0\r\n";
        $msg .= "Host: cloudfront.amazonaws.com\r\n";
        $msg .= "Date: {$date}\r\n";
        $msg .= "Content-Type: text/xml; charset=UTF-8\r\n";
        $msg .= "Authorization: AWS {$access_key}:{$sig}\r\n";
        $msg .= "Content-Length: {$len}\r\n\r\n";
        $msg .= $xml;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $hostname = 'ssl://cloudfront.amazonaws.com:443';
        $fp = stream_socket_client(
            $hostname,
            $errno,
            $errstr,
            ini_get("default_socket_timeout"),
            STREAM_CLIENT_CONNECT,
            $context
        );


        //$fp = fsockopen(
        //    'ssl://cloudfront.amazonaws.com',
        //    443,
        //    $errno,
        //    $errstr,
        //    30
        //);

        if ( ! $fp ) {
            require_once dirname( __FILE__ ) .
                '/../static-html-output-plugin' .
                '/plugin/WP2Static/WsLog.php';

            WsLog::l( "CLOUDFRONT CONNECTION ERROR: {$errno} {$errstr}" );
            die( "Connection failed: {$errno} {$errstr}\n" );
        }

        fwrite( $fp, $msg );
        $resp = '';

        while ( ! feof( $fp ) ) {
            $resp .= fgets( $fp, 1024 );
        }

        $this->logAction( "CloudFront response body: {$resp}" );

        fclose( $fp );

        if ( ! defined( 'WP_CLI' ) ) {
            echo 'SUCCESS';
        }
    }
}

$github = new WP2Static_GitHub();

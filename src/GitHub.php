<?php

namespace WP2Static;

use Exception;

class GitHub extends SitePublisher {

    public function __construct() {
        $plugin = Controller::getInstance();

        $this->api_base = 'https://api.github.com/repos/';
        $this->batch_size =
            $plugin->options->getOption( 'deployBatchSize' );
        $this->gh_branch =
            $plugin->options->getOption( 'ghBranch' );
        $this->gh_commit_message =
            $plugin->options->getOption( 'ghCommitMessage' );
        $this->gh_path =
            $plugin->options->getOption( 'ghPath' );
        $this->gh_repo =
            $plugin->options->getOption( 'ghRepo' );
        $this->gh_token =
            $plugin->options->getOption( 'ghToken' );
        $this->previous_hashes_path =
            SiteInfo::getPath( 'uploads' ) .
            'wp2static-working-files/' .
            '/GITHUB-PREVIOUS-HASHES.txt';
    }

    public function upload_files() {
        $this->files_remaining = $this->getRemainingItemsCount();

        if ( $this->files_remaining < 0 ) {
            echo 'ERROR';
            die();
        }

        if ( $this->batch_size > $this->files_remaining ) {
            $this->batch_size = $this->files_remaining;
        }

        $this->client = new Request();

        $lines = $this->getItemsToDeploy( $this->batch_size );

        $this->openPreviousHashesFile();

        foreach ( $lines as $line ) {
            list($this->local_file, $this->target_path) = explode( ',', $line );

            $this->local_file = SiteInfo::getPath( 'uploads' ) .
                'wp2static-exported-site/' .
                $this->local_file;

            if ( ! is_file( $this->local_file ) ) {
                $err = 'COULDN\'T FIND LOCAL FILE TO DEPLOY: ' .
                    $this->local_file;
                WsLog::l( $err );
                throw new Exception( $err );
            }

            if ( isset( $this->gh_path ) ) {
                $this->target_path =
                    $this->gh_path . '/' .
                        $this->target_path;
            }

            $this->local_file_contents = file_get_contents( $this->local_file );

            $this->hash_key =
                $this->target_path . basename( $this->local_file );

            if ( isset( $this->file_paths_and_hashes[ $this->hash_key ] ) ) {
                $prev = $this->file_paths_and_hashes[ $this->hash_key ];
                $current = crc32( $this->local_file_contents );

                // current file different than previous deployed one
                if ( $prev != $current ) {
                    if ( $this->fileExistsInGitHub() ) {
                        $this->updateFileInGitHub();
                    } else {
                        $this->createFileInGitHub();
                    }

                    $this->recordFilePathAndHashInMemory(
                        $this->target_path,
                        $this->local_file_contents
                    );
                } else {
                }
            } else {
                if ( $this->fileExistsInGitHub() ) {
                    $this->updateFileInGitHub();
                } else {
                    $this->createFileInGitHub();
                }

                $this->recordFilePathAndHashInMemory(
                    $this->target_path,
                    $this->local_file_contents
                );
            }
        }

        $this->writeFilePathAndHashesToFile();

        $this->pauseBetweenAPICalls();

        if ( $this->uploadsCompleted() ) {
            $this->finalizeDeployment();
        }
    }

    public function test_upload() {
        try {
            $this->remote_path = $this->api_base . $this->gh_repo .
                '/contents/' . '.WP2Static/' . uniqid();

            $b64_file_contents = base64_encode( 'WP2Static test upload' );

            $ch = curl_init();

            curl_setopt( $ch, CURLOPT_URL, $this->remote_path );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
            curl_setopt( $ch, CURLOPT_HEADER, 0 );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
            curl_setopt( $ch, CURLOPT_POST, 1 );
            curl_setopt( $ch, CURLOPT_USERAGENT, 'WP2Static.com' );
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );

            $post_options = array(
                'message' => 'Test WP2Static connectivity',
                'content' => $b64_file_contents,
                'branch' => $this->gh_branch,
            );

            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode( $post_options )
            );

            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                array(
                    'Authorization: ' .
                        'token ' . $this->gh_token,
                )
            );

            $output = curl_exec( $ch );
            $status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

            curl_close( $ch );

            $good_response_codes = array(
                '100',
                '200',
                '201',
                '301',
                '302',
                '304'
            );

            if ( ! in_array( $status_code, $good_response_codes ) ) {
                WsLog::l(
                    'BAD RESPONSE STATUS (' . $status_code . '): '
                );
                throw new Exception( 'GitHub API bad response status' );
            }
        } catch ( Exception $e ) {
            WsLog::l( 'GITHUB EXPORT: error encountered' );
            WsLog::l( $e );
            throw new Exception( $e );
            return;
        }

        if ( ! defined( 'WP_CLI' ) ) {
            echo 'SUCCESS';
        }
    }

    public function fileExistsInGitHub() {
        $this->remote_path = $this->api_base . $this->gh_repo .
            '/contents/' . $this->target_path;
        // GraphQL query to get sha of existing file
        $this->query = <<<JSON
query{
  repository(owner: "{$this->user}", name: "{$this->repository}") {
    object(expression: "{$this->gh_branch}:{$this->target_path}") {
      ... on Blob {
        oid
        byteSize
      }
    }
  }
}
JSON;
        $this->client = new Request();

        $post_options = array(
            'query' => $this->query,
            'variables' => '',
        );

        $headers = array(
            'Authorization: ' .
                    'token ' . $this->gh_token,
        );

        $this->client->postWithJSONPayloadCustomHeaders(
            'https://api.github.com/graphql',
            $post_options,
            $headers,
            $curl_options = array(
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            )
        );

        $this->checkForValidResponses(
            $this->client->status_code,
            array( '100', '200', '201', '301', '302', '304' )
        );

        $gh_file_info = json_decode( $this->client->body, true );

        $this->existing_file_object =
            $gh_file_info['data']['repository']['object'];

        $action = '';
        $commit_message = '';

        if ( ! empty( $this->existing_file_object ) ) {

            return true;
        }
    }

    public function updateFileInGitHub() {

        $action = 'UPDATE';
        $existing_sha = $this->existing_file_object['oid'];
        $existing_bytesize = $this->existing_file_object['byteSize'];

        $b64_file_contents = base64_encode( $this->local_file_contents );

        if ( isset( $this->gh_commit_message ) ) {
            $commit_message = str_replace(
                array(
                    '%ACTION%',
                    '%FILENAME%',
                ),
                array(
                    $action,
                    $this->target_path,
                ),
                $this->gh_commit_message
            );
        } else {
            $commit_message = 'WP2Static ' .
                $action . ' ' .
                $this->target_path;
        }

        try {
            $post_options = array(
                'message' => $commit_message,
                'content' => $b64_file_contents,
                'branch' => $this->gh_branch,
                'sha' => $existing_sha,
            );

            $headers = array(
                'Authorization: ' .
                        'token ' . $this->gh_token,
            );

            $this->client->putWithJSONPayloadCustomHeaders(
                $this->remote_path,
                $post_options,
                $headers,
                $curl_options = array(
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                )
            );

            $this->checkForValidResponses(
                $this->client->status_code,
                array( '100', '200', '201', '301', '302', '304' )
            );
        } catch ( Exception $e ) {
            $this->handleException( $e );
        }
    }

    public function createFileInGitHub() {
        $action = 'CREATE';

        $b64_file_contents = base64_encode( $this->local_file_contents );

        if ( isset( $this->gh_commit_message ) ) {
            $commit_message = str_replace(
                array(
                    '%ACTION%',
                    '%FILENAME%',
                ),
                array(
                    $action,
                    $this->target_path,
                ),
                $this->gh_commit_message
            );
        } else {
            $commit_message = 'WP2Static ' .
                $action . ' ' .
                $this->target_path;
        }

        try {
            $post_options = array(
                'message' => $commit_message,
                'content' => $b64_file_contents,
                'branch' => $this->gh_branch,
            );

            $headers = array(
                'Authorization: ' .
                        'token ' . $this->gh_token,
            );

            $this->client->putWithJSONPayloadCustomHeaders(
                $this->remote_path,
                $post_options,
                $headers,
                $curl_options = array(
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                )
            );

            $this->checkForValidResponses(
                $this->client->status_code,
                array( '100', '200', '201', '301', '302', '304' )
            );

        } catch ( Exception $e ) {
            $this->handleException( $e );
        }
    }
}

$github = new GitHub();

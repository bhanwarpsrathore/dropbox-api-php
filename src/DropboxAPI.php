<?php

declare(strict_types=1);

namespace DropboxAPI;

use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\PumpStream;
use Psr\Http\Message\StreamInterface;

class DropboxAPI {

    public const MAX_CHUNK_SIZE = 1024 * 1024 * 150;
    public const UPLOAD_SESSION_START = 0;
    public const UPLOAD_SESSION_APPEND = 1;

    protected string $accessToken = '';
    protected array $lastResponse = [];
    protected array $options = [
        'auto_refresh' => false,
        'auto_retry' => false
    ];
    protected ?Session $session = null;
    protected ?Request $request = null;

    protected string $teamMemberId = '';
    protected string $namespaceId = '';

    protected int $maxChunkSize = self::MAX_CHUNK_SIZE;
    protected int $maxChunkRetries = 0;

    /**
     * Constructor
     * Set options and class instances to use.
     *
     * @param array|object $options Optional. Options to set.
     * @param Session $session Optional. The Session object to use.
     * @param Request $request Optional. The Request object to use.
     */
    public function __construct(array|object $options = [], ?Session $session = null, ?Request $request = null) {
        $this->setOptions($options);
        $this->setSession($session);

        $this->request = $request ?? new Request();
    }

    /**
     * Set the access token to use.
     *
     * @param string $accessToken The access token.
     *
     * @return self
     */
    public function setAccessToken(string $accessToken): self {
        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * Set options
     *
     * @param array|object $options Options to set.
     *
     * @return self
     */
    public function setOptions(array|object $options): self {
        $this->options = array_merge($this->options, (array) $options);

        return $this;
    }

    /**
     * Set the Session object to use.
     *
     * @param Session $session The Session object.
     *
     * @return self
     */
    public function setSession(?Session $session): self {
        $this->session = $session;

        return $this;
    }

    /**
     * Set team member id
     * 
     * @param string $team_member_id
     * 
     * @return self
     */
    public function setTeamMemberId(string $team_member_id): self {
        $this->teamMemberId = $team_member_id;

        return $this;
    }

    /**
     * Set Namespace id
     * 
     * @param string $namespace_id
     * 
     * @return self
     */
    public function setNamespaceId(string $namespace_id): self {
        $this->namespaceId = $namespace_id;

        return $this;
    }

    /**
     * Set max chunk size
     * 
     * @param int $maxChunkSize Max chunk size.
     * 
     * @return self
     */
    public function setMaxChunkSize(int $maxChunkSize): self {
        $this->maxChunkSize = ($maxChunkSize < self::MAX_CHUNK_SIZE ? max($maxChunkSize, 1) : self::MAX_CHUNK_SIZE);

        return $this;
    }

    /**
     * Set max upload chunk retries
     * 
     * @param int $maxChunkRetries Max upload chunk retries.
     * 
     * @return self
     */
    public function setMaxChunkRetries(int $maxChunkRetries): self {
        $this->maxChunkRetries = $maxChunkRetries;

        return $this;
    }

    /**
     * Add authorization headers.
     *
     * @param $headers array. Optional. Additional headers to merge with the authorization headers.
     *
     * @return array Authorization headers, optionally merged with the passed ones.
     */
    protected function authHeaders(array $headers = []): array {
        $accessToken = $this->session ? $this->session->getAccessToken() : $this->accessToken;

        if ($accessToken) {
            $headers = array_merge($headers, [
                'Authorization' => 'Bearer ' . $accessToken,
            ]);
        }

        return $headers;
    }

    /**
     * Add API headers
     * 
     * @param bool $team_member_id Optional. Set to false to not include the team member id header.
     * @param bool $namespace_id Optional. Set to false to not include the namespace id header.
     * @param string $member_type Optional. The member type. Default is 'admin'.
     * 
     * @return array API headers.
     */
    protected function apiHeaders(string $member_type = 'admin'): array {
        $headers = [];

        if ($this->teamMemberId) {
            $header_key = $member_type === 'admin' ? 'Dropbox-API-Select-Admin' : 'Dropbox-API-Select-User';
            $headers[$header_key] = $this->teamMemberId;
        }

        if ($this->namespaceId) {
            $headers['Dropbox-API-Path-Root'] = json_encode([
                '.tag' => 'namespace_id',
                'namespace_id' => $this->namespaceId,
            ]);
        }

        return $headers;
    }

    /**
     * Get the latest full response from the Dropbox API.
     *
     * @return array Response data.
     * - array body The response body.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    public function getLastResponse(): array {
        return $this->lastResponse;
    }

    /**
     * Send a request to the Dropbox API, automatically refreshing the access token as needed.
     *
     * @param string $method The HTTP method to use.
     * @param string $uri The URI to request.
     * @param string|array $parameters Optional. Query string parameters or HTTP body, depending on $method.
     *
     * @throws DropboxAPIException
     * @throws DropboxAPIAuthException
     *
     * @return array Response data.
     * - array body The response body.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    protected function rpcEndpointRequest(
        string $method,
        string $uri,
        array $parameters = [],
        array $headers = []
    ): array {

        try {
            $headers = $this->authHeaders($headers);

            $options = ['headers' => $headers];
            if ($parameters) {
                $options['json'] = $parameters;
            }

            return $this->request->rpcApi($method, $uri, $options);
        } catch (DropboxAPIException $e) {
            if ($this->options['auto_refresh'] && $e->hasExpiredToken()) {
                $result = $this->session->refreshAccessToken();

                if (!$result) {
                    throw new DropboxAPIException('Could not refresh access token.');
                }

                return $this->rpcEndpointRequest($method, $uri, $parameters, $headers);
            } elseif ($this->options['auto_retry'] && $e->isRateLimited()) {
                ['headers' => $lastHeaders] = $this->request->getLastResponse();

                sleep((int) $lastHeaders['retry-after']);

                return $this->rpcEndpointRequest($method, $uri, $parameters, $headers);
            }

            throw $e;
        }
    }

    /**
     * Send a request to the Dropbox API, automatically refreshing the access token as needed.
     *
     * @param string $method The HTTP method to use.
     * @param string $uri The URI to request.
     * @param string|array $arguments
     * @param string|resource|StreamInterface  $body
     * @param string|array $parameters Optional. Query string parameters or HTTP body, depending on $method.
     *
     * @throws DropboxAPIException
     * @throws DropboxAPIAuthException
     *
     * @return array Response data.
     * - array|StreamInterface body The response body.
     * - array headers Response headers.
     * - int status HTTP status code.
     * - string url The requested URL.
     */
    protected function contentEndpointRequest(
        string $method,
        string $uri,
        string|array $arguments = [],
        mixed $contents = '',
        array $parameters = [],
        array $headers = []
    ): array {
        $headers['Dropbox-API-Arg'] = json_encode($arguments);
        if ($contents !== '') {
            $headers['Content-Type'] = 'application/octet-stream';
        }

        try {
            $headers = $this->authHeaders($headers);
            $options = ['headers' => $headers];
            if ($contents !== '') {
                $options['body'] = $contents;
            }
            if ($parameters) {
                $options['json'] = $parameters;
            }

            return $this->request->contentApi($method, $uri, $options);
        } catch (DropboxAPIException $e) {
            if ($this->options['auto_refresh'] && $e->hasExpiredToken()) {
                $result = $this->session->refreshAccessToken();

                if (!$result) {
                    throw new DropboxAPIException('Could not refresh access token.');
                }

                return $this->contentEndpointRequest($method, $uri, $arguments, $contents, $parameters, $headers);
            } elseif ($this->options['auto_retry'] && $e->isRateLimited()) {
                ['headers' => $lastHeaders] = $this->request->getLastResponse();

                sleep((int) $lastHeaders['retry-after']);

                return $this->contentEndpointRequest($method, $uri, $arguments, $contents, $parameters, $headers);
            }

            throw $e;
        }
    }

    /**
     * The file should be uploaded in chunks if its size exceeds the 150 MB threshold
     * or if the resource size could not be determined (eg. a popen() stream).
     *
     * @param  string|resource  $contents
     */
    protected function shouldUploadChunked(mixed $contents): bool {
        $size = is_string($contents) ? strlen($contents) : fstat($contents)['size'];

        if ($this->isPipe($contents)) {
            return true;
        }

        return $size > $this->maxChunkSize;
    }

    /**
     * Check if the contents is a pipe stream (not seekable, no size defined).
     *
     * @param  string|resource  $contents
     */
    protected function isPipe(mixed $contents): bool {
        return is_resource($contents) && (fstat($contents)['mode'] & 010000) != 0;
    }

    /**
     * @param  string|resource  $contents
     */
    protected function getStream(mixed $contents): StreamInterface {
        if ($this->isPipe($contents)) {
            /* @var resource $contents */
            return new PumpStream(function ($length) use ($contents) {
                $data = fread($contents, $length);
                if (strlen($data) === 0) {
                    return false;
                }

                return $data;
            });
        }

        return Psr7\Utils::streamFor($contents);
    }

    /**
     * Return valid path for folder or file
     * 
     * @param string $path
     * @return string Correct path
     */
    protected function normalizePath(string $path): string {
        if (preg_match("/^id:.*|^rev:.*|^(ns:[0-9]+(\/.*)?)/", $path) === 1) {
            return $path;
        }

        $path = trim($path, '/');

        return ($path === '') ? '' : '/' . $path;
    }

    public function getUserAccount(string $account_id): array {
        $uri = '/users/get_account';

        $parameters = [
            'account_id' => $account_id
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters);

        return $this->lastResponse['body'];
    }

    /**
     * Get information about the current user's account.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#users-get_current_account
     * 
     * @return array
     */
    public function getCurrentAccount(): array {
        $uri = '/users/get_current_account';

        $headers = $this->apiHeaders();

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, [], $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Create a new file with the contents provided in the request.
     *
     * Do not use this to upload a file larger than 150 MB. Instead, create an upload session with upload_session/start.
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload
     *
     * @param  string|resource  $contents
     * @return array<mixed>
     */
    public function upload(string $path, mixed $contents, string $mode = 'add', bool $autorename = false) {
        if ($this->shouldUploadChunked($contents)) {
            return $this->uploadChunked($path, $contents, $mode);
        }

        $headers = $this->apiHeaders();

        $arguments = [
            'autorename' => $autorename,
            'mode' => $mode,
            'path' => $this->normalizePath($path)
        ];

        $uri = '/files/upload';
        $this->lastResponse = $this->contentEndpointRequest('POST', $uri, $arguments, $contents, [], $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Upload file split in chunks. This allows uploading large files, since
     * Dropbox API v2 limits the content size to 150MB.
     *
     * The chunk size will affect directly the memory usage, so be careful.
     * Large chunks tends to speed up the upload, while smaller optimizes memory usage.
     *
     * @param  string|resource  $contents
     * @return array<mixed>
     */
    public function uploadChunked(string $path, mixed $contents, string $mode = 'add', bool $autorename = false, ?int $chunkSize = null): array {
        if ($chunkSize === null || $chunkSize > $this->maxChunkSize) {
            $chunkSize = $this->maxChunkSize;
        }

        $stream = $this->getStream($contents);

        $cursor = $this->uploadChunk(self::UPLOAD_SESSION_START, $stream, $chunkSize, null);

        while (!$stream->eof()) {
            $cursor = $this->uploadChunk(self::UPLOAD_SESSION_APPEND, $stream, $chunkSize, $cursor);
        }

        return $this->uploadSessionFinish('', $cursor, $path, $mode, $autorename);
    }

    /**
     * @throws DropboxAPIException
     */
    protected function uploadChunk(int $type, StreamInterface &$stream, int $chunkSize, ?UploadSessionCursor $cursor = null): UploadSessionCursor {
        $maximumTries = $stream->isSeekable() ? $this->maxChunkRetries : 0;
        $pos = $stream->tell();

        $tries = 0;

        tryUpload:
        try {
            $tries++;

            $chunkStream = new Psr7\LimitStream($stream, $chunkSize, $stream->tell());

            if ($type === self::UPLOAD_SESSION_START) {
                return $this->uploadSessionStart($chunkStream);
            }

            if ($type === self::UPLOAD_SESSION_APPEND && $cursor !== null) {
                return $this->uploadSessionAppend($chunkStream, $cursor);
            }

            throw new DropboxAPIException('Invalid type');
        } catch (DropboxAPIException $exception) {
            if ($tries < $maximumTries) {
                // rewind
                $stream->seek($pos, SEEK_SET);
                goto tryUpload;
            }

            throw $exception;
        }
    }

    /**
     * Upload sessions allow you to upload a single file in one or more requests,
     * for example where the size of the file is greater than 150 MB.
     * This call starts a new upload session with the given data.
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload_session-start
     *
     * @param  string|resource|StreamInterface  $contents
     */
    public function uploadSessionStart(mixed $contents, bool $close = false): UploadSessionCursor {
        $headers = $this->apiHeaders();

        $arguments = compact('close');

        ['body' => $response] = $this->contentEndpointRequest('POST', '/files/upload_session/start', $arguments, $contents, [], $headers);

        return new UploadSessionCursor($response['session_id'], ($contents instanceof StreamInterface ? $contents->tell() : strlen($contents)));
    }

    /**
     * Append more data to an upload session.
     * When the parameter close is set, this call will close the session.
     * A single request should not upload more than 150 MB.
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload_session-append_v2
     */
    public function uploadSessionAppend(string|StreamInterface $contents, UploadSessionCursor $cursor, bool $close = false): UploadSessionCursor {
        $headers = $this->apiHeaders();

        $arguments = compact('cursor', 'close');

        $pos = $contents instanceof StreamInterface ? $contents->tell() : 0;
        $this->contentEndpointRequest('POST', '/files/upload_session/append_v2', $arguments, $contents, [], $headers);

        $cursor->offset += $contents instanceof StreamInterface ? ($contents->tell() - $pos) : strlen($contents);

        return $cursor;
    }

    /**
     * Finish an upload session and save the uploaded data to the given file path.
     * A single request should not upload more than 150 MB.
     *
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-upload_session-finish
     *
     * @param  string|resource|StreamInterface  $contents
     * @return array<mixed>
     */
    public function uploadSessionFinish(mixed $contents, UploadSessionCursor $cursor, string $path, string $mode = 'add', bool $autorename = false, bool $mute = false): array {
        $path = $this->normalizePath($path);

        $headers = $this->apiHeaders();

        $arguments = compact('cursor');
        $arguments['commit'] = compact('path', 'mode', 'autorename', 'mute');

        return $this->contentEndpointRequest(
            'POST',
            '/files/upload_session/finish',
            $arguments,
            ($contents == '') ? null : $contents,
            [],
            $headers
        );
    }

    /**
     * Create a folder at a given path.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-create_folder
     * 
     * @param string $path
     * @param bool $autorename Optional
     * @return array
     */
    public function createFolder(string $path, bool $autorename = false): array {
        $uri = '/files/create_folder_v2';

        $headers = $this->apiHeaders();

        $parameters = [
            'autorename' => $autorename,
            'path' => $this->normalizePath($path)
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters, $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Copy a file or folder to a different location in the user's Dropbox. If the source path is a folder all its contents will be copied.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-copy
     * 
     * @param string $from_path
     * @param string $to_path
     * @param bool $autorename Optional
     * @param bool $allow_ownership_transfer Optional
     * @return array
     */
    public function copy(string $from_path, string $to_path, bool $autorename = false, bool $allow_ownership_transfer = false): array {
        $uri = '/files/copy_v2';

        $headers = $this->apiHeaders();

        $parameters = [
            'allow_ownership_transfer' => $allow_ownership_transfer,
            'autorename' => $autorename,
            'from_path' => $this->normalizePath($from_path),
            'to_path' => $this->normalizePath($to_path)
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters, $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Move a file or folder to a different location in the user's Dropbox. If the source path is a folder all its contents will be moved. Note that we do not currently support case-only renaming.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-move
     * 
     * @param string $from_path
     * @param string $to_path
     * @param bool $autorename Optional
     * @param bool $allow_ownership_transfer Optional
     * @return array
     */
    public function move(string $from_path, string $to_path, bool $autorename = false, bool $allow_ownership_transfer = false): array {
        $uri = '/files/move_v2';

        $headers = $this->apiHeaders();

        $parameters = [
            'allow_ownership_transfer' => $allow_ownership_transfer,
            'autorename' => $autorename,
            'from_path' => $this->normalizePath($from_path),
            'to_path' => $this->normalizePath($to_path)
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters, $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Delete the file or folder at a given path. If the path is a folder, all its contents will be deleted too. A successful response indicates that the file or folder was deleted.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-delete
     * 
     * @param string $path
     * @return array
     */
    public function delete(string $path): array {
        $uri = '/files/delete_v2';

        $headers = $this->apiHeaders();

        $parameters = [
            'path' => $this->normalizePath($path)
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters, $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Download a file from a user's Dropbox.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-download
     * 
     * @param string $path
     * @return StreamInterface
     */
    public function download(string $path): StreamInterface {
        $uri = '/files/download';

        $headers = $this->apiHeaders();

        $arguments = [
            'path' => $this->normalizePath($path)
        ];

        $this->lastResponse = $this->contentEndpointRequest('POST', $uri, $arguments, '', [], $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Download a folder from the user's Dropbox, as a zip file. The folder must be less than 20 GB in size and any single file within must be less than 4 GB in size. The resulting zip must have fewer than 10,000 total file and folder entries, including the top level folder. The input cannot be a single file. Note: this endpoint does not support HTTP range requests.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-download_zip
     * 
     * @param string $path
     * @return StreamInterface
     */
    public function downloadZip(string $path): StreamInterface {
        $uri = '/files/download_zip';

        $headers = $this->apiHeaders('user');

        $arguments = [
            'path' => $this->normalizePath($path)
        ];

        $this->lastResponse = $this->contentEndpointRequest('POST', $uri, $arguments, '', [], $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Starts returning the contents of a folder.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-list_folder
     * 
     * @param string $path
     * @param bool $recursive Optional
     * @param bool $include_deleted Optional
     * @param bool $include_has_explicit_shared_members Optional
     * @param bool $include_mounted_folders Optional
     * @param int $limit Optional
     * @return array
     */
    public function folders(string $path, bool $recursive = false, bool $include_deleted = false, bool $include_has_explicit_shared_members = false, bool $include_mounted_folders = true, int $limit = 0): array {
        $uri = '/files/list_folder';

        $headers = $this->apiHeaders();

        $parameters = [
            'path' => $this->normalizePath($path),
            'recursive' => $recursive,
            'include_deleted' => $include_deleted,
            'include_has_explicit_shared_members' => $include_has_explicit_shared_members,
            'include_mounted_folders' => $include_mounted_folders
        ];

        if ($limit > 0) {
            $parameters['limit'] = $limit;
        }

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters, $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Returns the metadata for a file or folder. Note: Metadata for the root folder is unsupported.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#files-get_metadata
     * 
     * @param string $path
     * @param bool $include_media_info Optional
     * @param bool $include_deleted Optional
     * @param bool $include_has_explicit_shared_members Optional
     * @return array
     */
    public function metaData(string $path, bool $include_media_info = false, bool $include_deleted = false, bool $include_has_explicit_shared_members = false): array {
        $uri = '/files/get_metadata';

        $headers = $this->apiHeaders();

        $parameters = [
            'path' => $this->normalizePath($path),
            'include_media_info' => $include_media_info,
            'include_deleted' => $include_deleted,
            'include_has_explicit_shared_members' => $include_has_explicit_shared_members
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters, $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Create a shared link with custom settings. If no settings are given then the default visibility is RequestedVisibility.public 
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#sharing-create_shared_link_with_settings
     * 
     * @param string $path
     * @param array $settings Optional
     * @return array
     */
    public function createSharedLink(string $path, array $settings = []): array {
        $uri = '/sharing/create_shared_link_with_settings';

        $headers = $this->apiHeaders();

        $parameters = [
            'path' => $path
        ];

        $parameter_settings = [];
        $keys = ['require_password', 'expires',  'audience',  'access',   'allow_download'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $settings)) {
                $parameter_settings[$key] = $settings[$key];
                if ($key == 'require_password' && array_key_exists('link_password', $settings)) {
                    $parameter_settings['link_password'] = $settings['link_password'];
                }
            }
        }

        if (count($parameter_settings) > 0) {
            $parameters['settings'] = $parameter_settings;
        }

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters, $headers);

        return $this->lastResponse['body'];
    }

    /**
     * List shared links of this user. If no path is given, returns a list of all shared links for the current user. For members of business teams using team space and member folders, returns all shared links in the team member's home folder unless the team space ID is specified in the request header.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#sharing-list_shared_links
     * 
     * @param string $path Optional
     * @param string $cursor Optional
     * @param bool $direct_only Optional
     * @return array
     */
    public function listSharedLinks(string $path = null, string $cursor = null, bool $direct_only = true): array {
        $uri = '/sharing/list_shared_links';

        $headers = $this->apiHeaders('user');

        $parameters = [
            'direct_only' => $direct_only
        ];
        if ($path) {
            $parameters['path'] = $path;
        }
        if ($cursor) {
            $parameters['cursor'] = $cursor;
        }

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters, $headers);

        return $this->lastResponse['body'];
    }

    /**
     * Removes all manually added contacts. You'll still keep contacts who are on your team or who you imported. New contacts will be added when you share.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#contacts-delete_manual_contacts
     * 
     * @return bool
     */
    public function deleteManualContacts(): bool {
        $uri = '/contacts/delete_manual_contacts';

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri);

        return $this->lastResponse['status'] == 200;
    }

    /**
     * Removes manually added contacts from the given list.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/documentation#contacts-delete_manual_contacts_batch
     * 
     * @param array $email_addresses
     * @return bool
     */
    public function deleteManualContactsBatch(array $email_addresses): bool {
        $uri = '/contacts/delete_manual_contacts_batch';

        $parameters = [
            'email_addresses' => $email_addresses
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters);

        return $this->lastResponse['status'] == 200;
    }

    /**
     * Lists members of a team.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/teams#team-members-list
     * 
     * @param bool $include_removed Optional
     * @param int $limit Optional
     * @return array
     */
    public function teamMembers($include_removed = false, $limit = 1000): array {
        $uri = '/team/members/list_v2';

        $parameters = [
            'limit' => $limit,
            'include_removed' => $include_removed
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters);

        return $this->lastResponse['body'];
    }

    /**
     * Lists all team folders.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/teams#team-team_folder-list
     * 
     * @param int $limit Optional
     * @return array
     */
    public function teamFolders(int $limit = 1000): array {
        $uri = '/team/team_folder/list';

        $parameters = [
            'limit' => $limit
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters);

        return $this->lastResponse['body'];
    }

    /**
     * Creates a new, active, team folder with no members. This endpoint can only be used for teams that do not already have a shared team space.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/teams#team-team_folder-create
     * 
     * @param string $name
     * @param string $sync_setting Optional
     */
    public function createTeamFolder(string $name, string $sync_setting = 'not_synced'): array {
        $uri = '/team/team_folder/create';

        $parameters = [
            'sync_setting' => $sync_setting,
            'name' => $name
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters);

        return $this->lastResponse['body'];
    }

    /**
     * Changes an active team folder's name.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/teams#team-team_folder-rename
     * 
     * @param string $team_folder_id
     * @param string $name
     * @return array
     */
    public function renameTeamFolder(string $team_folder_id, string $name): array {
        $uri = '/team/team_folder/rename';

        $parameters = [
            'team_folder_id' => $team_folder_id,
            'name' => $name
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters);

        return $this->lastResponse['body'];
    }

    /**
     * Sets an active team folder's status to archived and removes all folder and file members. This endpoint cannot be used for teams that have a shared team space.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/teams#team-team_folder-archive
     * 
     * @param string $team_folder_id
     * @param bool $force_async_off Optional
     * @return array
     */
    public function archiveTeamFolder(string $team_folder_id, bool $force_async_off = false): array {
        $uri = '/team/team_folder/archive';

        $parameters = [
            'team_folder_id' => $team_folder_id,
            'force_async_off' => $force_async_off
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters);

        return $this->lastResponse['body'];
    }

    /**
     * Permanently deletes an archived team folder. This endpoint cannot be used for teams that have a shared team space.
     * 
     * @link https://www.dropbox.com/developers/documentation/http/teams#team-team_folder-permanently_delete
     * 
     * @param string $team_folder_id
     * @return bool
     */
    public function deleteTeamFolder(string $team_folder_id): bool {
        $uri = '/team/team_folder/permanently_delete';

        $parameters = [
            'team_folder_id' => $team_folder_id
        ];

        $this->lastResponse = $this->rpcEndpointRequest('POST', $uri, $parameters);

        return $this->lastResponse['status'] == 200;
    }
}

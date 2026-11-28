<?php

declare(strict_types=1);

namespace Phptg\TransportAmphp;

use Amp\ByteStream\Payload;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Http\Client\Form;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpContent;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\StreamedContent;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Transport\DownloadFileException;
use Phptg\BotApi\Transport\MimeTypeResolver\ApacheMimeTypeResolver;
use Phptg\BotApi\Transport\MimeTypeResolver\MimeTypeResolverInterface;
use Phptg\BotApi\Transport\TransportInterface;
use Phptg\BotApi\Type\InputFile;

use function is_string;
use function sprintf;
use function strlen;

/**
 * HTTP transport for the Telegram Bot API based on the Amp HTTP client.
 *
 * {@see AmpTransport::downloadFile()} buffers the whole file into a `php://temp` stream before returning.
 * To process a file chunk by chunk without buffering, use {@see AmpTransport::downloadFileStream()}, which is
 * specific to this transport.
 *
 * {@see AmpTransport::postWithFiles()} uploads a local file, i.e. an {@see InputFile} with a path or a resource
 * of a regular file, using the `amphp/file` package, which is not installed with this package and must be
 * installed separately to upload such files.
 *
 * @api
 */
final readonly class AmpTransport implements TransportInterface
{
    private HttpClient $client;

    /**
     * @param MimeTypeResolverInterface $mimeTypeResolver MIME type resolver for determining file types. Defaults
     * to {@see ApacheMimeTypeResolver}.
     * @param HttpClient|null $client HTTP client for making requests. `null` means a client built with
     * {@see HttpClientBuilder::buildDefault()} is used. A custom client may be used, for example, to tune
     * the connection pool or add interceptors. The default client retries GET requests, i.e. `get()`,
     * `longPoll()` and file downloads, up to twice on any `HttpException`, e.g. a timeout, so such a request
     * may take up to three times the corresponding timeout; a POST request is retried only if not sent yet.
     * @param float $tcpConnectTimeout Timeout in seconds for establishing the TCP connection for each request.
     * `0` means no timeout. Defaults to `10`.
     * @param float $tlsHandshakeTimeout Timeout in seconds for the TLS handshake for each request. `0` means
     * no timeout. Defaults to `10`.
     * @param float $apiTransferTimeout Timeout in seconds for the entire transfer of API requests without
     * file uploads, i.e. `get()` and `post()`, not counting the TCP connect and TLS handshake. Defaults to `60`:
     * the server responds within a couple of seconds, except for methods with a file passed by URL, e.g.
     * `sendPhoto`, where the server downloads the file from the third-party server first, which takes longer
     * on a slow host. Does not apply to `postWithFiles()`, see `$uploadTransferTimeout`, to long polling
     * requests, see `$longPollingTimeoutMargin`, or to file downloads, see `$downloadTransferTimeout`.
     * `0` means no timeout. API requests have no separate inactivity timeout: the server sends nothing until
     * it has processed the request, so such a limit would either fire together with the transfer timeout or
     * cut off a slow upload.
     * @param float $uploadTransferTimeout Timeout in seconds for the entire transfer of API requests with
     * file uploads, i.e. `postWithFiles()`. Defaults to `600`: files are up to 50 MB and the upload speed
     * depends on the connection, so the limit only needs to cover the upload time of the largest file on
     * a slow connection. `0` means no timeout.
     * @param float $longPollingTimeoutMargin Timeout in seconds added to the long polling timeout, i.e. the
     * maximum number of seconds the server may hold a `longPoll()` request, to get the transfer timeout for
     * the request. Once the server has responded, the response body is small, so the margin only needs to cover
     * its transfer; a stalled connection is detected once it has passed. `0` means the transfer timeout equals
     * the long polling timeout, leaving no time for the response to arrive. Defaults to `10`.
     * @param float $downloadTransferTimeout Timeout in seconds for the entire transfer of file downloads,
     * i.e. `downloadFile()` and `downloadFileStream()`. `0` means no timeout. Defaults to `300`: unlike API
     * requests, downloads get data continuously, so the limit only needs to cover the download time of a file,
     * up to 20 MB on the default Bot API server, on a slow connection. Files can only be larger on a local
     * Bot API server, see `$downloadBodySizeLimit`.
     * @param float $downloadInactivityTimeout Timeout in seconds since the last data was received before
     * a file download, i.e. `downloadFile()` or `downloadFileStream()`, fails due to inactivity. `0` means
     * no timeout. Defaults to `10`: downloads get data continuously, so a pause means a stalled connection.
     * Unlike `$downloadTransferTimeout`, this limit does not depend on the file size and connection speed.
     * @param int $downloadBodySizeLimit Maximum size in bytes of the body downloaded by `downloadFile()`
     * and `downloadFileStream()`. `0` means no limit. Defaults to 21 MiB: a margin over the 20 MB the
     * default Bot API server serves; raise it or set to `0` for a local Bot API server, which serves files
     * of any size. Otherwise keep it finite: `0` slows down HTTP/2 downloads.
     */
    public function __construct(
        private MimeTypeResolverInterface $mimeTypeResolver = new ApacheMimeTypeResolver(),
        ?HttpClient $client = null,
        private float $tcpConnectTimeout = 10.0,
        private float $tlsHandshakeTimeout = 10.0,
        private float $apiTransferTimeout = 60.0,
        private float $uploadTransferTimeout = 600.0,
        private float $longPollingTimeoutMargin = 10.0,
        private float $downloadTransferTimeout = 300.0,
        private float $downloadInactivityTimeout = 10.0,
        private int $downloadBodySizeLimit = 22020096, // 21 * 1024 * 1024
    ) {
        $this->client = $client ?? HttpClientBuilder::buildDefault();
    }

    public function get(string $url): ApiResponse
    {
        return $this->send($url, 'GET', transferTimeout: $this->apiTransferTimeout);
    }

    public function longPoll(string $url, int $timeout): ApiResponse
    {
        return $this->send($url, 'GET', transferTimeout: $timeout + $this->longPollingTimeoutMargin);
    }

    public function post(string $url, string $body, array $headers): ApiResponse
    {
        return $this->send(
            $url,
            'POST',
            transferTimeout: $this->apiTransferTimeout,
            body: $body,
            headers: $headers,
        );
    }

    public function postWithFiles(string $url, array $data, array $files): ApiResponse
    {
        $form = new Form();

        foreach ($data as $name => $value) {
            $form->addField($name, $value);
        }

        foreach ($files as $key => $file) {
            $form->addStream(
                $key,
                $this->createFileContent($file),
                $file->filename() ?? '',
            );
        }

        return $this->send(
            $url,
            'POST',
            transferTimeout: $this->uploadTransferTimeout,
            body: $form,
        );
    }

    public function downloadFile(string $url): mixed
    {
        try {
            $body = $this->downloadFileStream($url);
        } catch (HttpException $e) {
            throw new DownloadFileException($e->getMessage(), previous: $e);
        }

        /** @var resource $stream `php://temp` always opens successfully. */
        $stream = fopen('php://temp', 'r+b');

        try {
            while (($chunk = $body->read()) !== null) {
                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new DownloadFileException('Failed to write the downloaded file to the temporary stream.');
                }
            }
        } catch (StreamException $e) {
            throw new DownloadFileException($e->getMessage(), previous: $e);
        }
        rewind($stream);

        return $stream;
    }

    /**
     * Downloads a file by URL and returns its content as a stream. The content is not buffered in memory,
     * so it can be read chunk by chunk via `Payload::read()`.
     *
     * @param string $url The URL of the file to download.
     * @param Cancellation|null $cancellation The cancellation token used to abort the request.
     *
     * @return Payload The response body as a stream.
     *
     * @throws HttpException If an error occurred while performing the request, e.g. the connection failed,
     * a timeout was reached, or the server returned a non-200 response.
     * @throws CancelledException If the request has been cancelled via the cancellation token.
     */
    public function downloadFileStream(string $url, ?Cancellation $cancellation = null): Payload
    {
        $request = new Request($url, 'GET');
        $request->setBodySizeLimit($this->downloadBodySizeLimit);
        $request->setTlsHandshakeTimeout($this->tlsHandshakeTimeout);
        $request->setTcpConnectTimeout($this->tcpConnectTimeout);
        $request->setTransferTimeout($this->downloadTransferTimeout);
        $request->setInactivityTimeout($this->downloadInactivityTimeout);

        $response = $this->client->request($request, $cancellation);
        if ($response->getStatus() !== 200) {
            $response->getBody()->close();
            throw new HttpException(
                sprintf(
                    'Failed to download file. HTTP status code: %d.',
                    $response->getStatus(),
                ),
            );
        }

        return $response->getBody();
    }

    /**
     * Performs an HTTP request to the Telegram Bot API.
     *
     * @param string $url The URL to request.
     * @param "GET"|"POST" $method The HTTP method.
     * @param float $transferTimeout Timeout in seconds for the entire transfer of the request, not counting
     * the TCP connect and TLS handshake. `0` means no timeout.
     * @param string|HttpContent $body The request body: a string or an `HttpContent` instance, e.g. a form
     * with file uploads.
     * @param array<non-empty-string, string> $headers The request headers as an associative array
     * (header name => header value).
     *
     * @return ApiResponse The API response.
     *
     * @throws HttpException If an error occurred while performing the request, e.g. the connection failed,
     * a timeout was reached, or the server returned an invalid response.
     * @throws StreamException If an error occurred while reading the response body.
     */
    private function send(
        string $url,
        string $method,
        float $transferTimeout,
        string|HttpContent $body = '',
        array $headers = [],
    ): ApiResponse {
        $request = new Request($url, $method);
        $request->setTlsHandshakeTimeout($this->tlsHandshakeTimeout);
        $request->setTcpConnectTimeout($this->tcpConnectTimeout);
        $request->setTransferTimeout($transferTimeout);
        $request->setInactivityTimeout($transferTimeout);

        if ($body !== '') {
            $request->setBody($body);
        }

        if ($headers !== []) {
            $request->setHeaders($headers);
        }

        $response = $this->client->request($request);

        return new ApiResponse(
            $response->getStatus(),
            $response->getBody()->buffer(),
        );
    }

    /**
     * Creates the request body content for a file to upload. A path or a resource of a regular file is streamed
     * with {@see StreamedContent::fromFile()}, which requires the optional `amphp/file` package, see the class
     * description; any other resource is streamed with {@see ReadableResourceStream}.
     *
     * @param InputFile $file The file to upload.
     *
     * @return HttpContent The request body content for the file.
     *
     * @throws HttpException If the file could not be opened.
     */
    private function createFileContent(InputFile $file): HttpContent
    {
        $mimeType = $this->mimeTypeResolver->resolve($file);

        if (is_string($file->pathOrResource)) {
            return StreamedContent::fromFile($file->pathOrResource, $mimeType);
        }

        $metadata = stream_get_meta_data($file->pathOrResource);
        if ($metadata['wrapper_type'] === 'plainfile' && isset($metadata['uri'])) {
            return StreamedContent::fromFile($metadata['uri'], $mimeType);
        }

        if ($metadata['seekable']) {
            rewind($file->pathOrResource);
        }

        return StreamedContent::fromStream(
            content: new ReadableResourceStream($file->pathOrResource),
            contentType: $mimeType,
        );
    }
}

<?php

declare(strict_types=1);

namespace Phptg\TransportAmphp;

use Amp\ByteStream\BufferException;
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
use Amp\NullCancellation;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Transport\DownloadFileException;
use Phptg\BotApi\Transport\MimeTypeResolver\ApacheMimeTypeResolver;
use Phptg\BotApi\Transport\MimeTypeResolver\MimeTypeResolverInterface;
use Phptg\BotApi\Transport\TransportInterface;
use Phptg\BotApi\Type\InputFile;

use function is_string;
use function sprintf;

/**
 * HTTP transport for the Telegram Bot API based on the Amp HTTP client.
 *
 * {@see AmpTransport::downloadFile()} buffers the whole file into a `php://temp` stream before returning.
 * To process a file chunk by chunk without buffering, use {@see AmpTransport::downloadFileStream()}, which is
 * specific to this transport.
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
     * the connection pool or add interceptors.
     * @param float $tcpConnectTimeout Timeout in seconds for establishing the TCP connection for each request.
     * `0` means no timeout. Defaults to `10`.
     * @param float $tlsHandshakeTimeout Timeout in seconds for the TLS handshake for each request. `0` means
     * no timeout. Defaults to `10`.
     * @param float $transferTimeout Timeout in seconds for the entire transfer of API requests, i.e. `get()`,
     * `post()` and `postWithFiles()`, not counting the TCP connect and TLS handshake. File downloads use
     * `$downloadTransferTimeout` instead. `0` means no timeout. Defaults to `0`.
     * @param float $downloadTransferTimeout Timeout in seconds for the entire transfer of file downloads,
     * i.e. `downloadFile()` and `downloadFileStream()`. `0` means no timeout. Defaults to `300`: unlike API
     * requests, downloads get data continuously, so the limit only needs to cover the download time of a file,
     * up to 20 MB, on a slow connection.
     * @param float $inactivityTimeout Timeout in seconds since the last data was received before the request
     * fails due to inactivity. Applies to `get()`, `post()` and `postWithFiles()`. File downloads use
     * `$downloadInactivityTimeout` instead. `0` means no timeout. Defaults to `0`. If you use
     * neither long polling nor file uploads, a value of a few seconds helps to detect stalled connections.
     * @param float $downloadInactivityTimeout Timeout in seconds since the last data was received before
     * a file download, i.e. `downloadFile()` or `downloadFileStream()`, fails due to inactivity. `0` means
     * no timeout. Defaults to `10`: downloads get data continuously, so a pause means a stalled connection.
     * Unlike `$downloadTransferTimeout`, this limit does not depend on the file size and connection speed.
     */
    public function __construct(
        private MimeTypeResolverInterface $mimeTypeResolver = new ApacheMimeTypeResolver(),
        ?HttpClient $client = null,
        private float $tcpConnectTimeout = 10.0,
        private float $tlsHandshakeTimeout = 10.0,
        private float $transferTimeout = 0.0,
        private float $downloadTransferTimeout = 300.0,
        private float $inactivityTimeout = 0.0,
        private float $downloadInactivityTimeout = 10.0,
    ) {
        $this->client = $client ?? HttpClientBuilder::buildDefault();
    }

    public function get(string $url): ApiResponse
    {
        return $this->send($url, 'GET');
    }

    public function post(string $url, string $body, array $headers): ApiResponse
    {
        return $this->send($url, 'POST', $body, $headers);
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

        return $this->send($url, 'POST', $form);
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
                fwrite($stream, $chunk);
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
     * @param Cancellation $cancellation The cancellation token used to abort the request.
     *
     * @return Payload The response body as a stream.
     *
     * @throws HttpException If an error occurred while performing the request, e.g. the connection failed,
     * a timeout was reached, or the server returned a non-200 response.
     * @throws CancelledException If the request has been cancelled via the cancellation token.
     */
    public function downloadFileStream(string $url, Cancellation $cancellation = new NullCancellation()): Payload
    {
        $request = new Request($url, 'GET');
        $request->setBodySizeLimit(20971520); // 20 MB
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
     * @param string|HttpContent $body The request body: a string or an `HttpContent` instance, e.g. a form
     * with file uploads.
     * @param array<non-empty-string, string> $headers The request headers as an associative array
     * (header name => header value).
     *
     * @return ApiResponse The API response.
     *
     * @throws HttpException If an error occurred while performing the request, e.g. the connection failed,
     * a timeout was reached, or the server returned an invalid response.
     * @throws BufferException If the response body exceeds the buffer limit.
     * @throws StreamException If an error occurred while reading the response body.
     */
    private function send(
        string $url,
        string $method,
        string|HttpContent $body = '',
        array $headers = [],
    ): ApiResponse {
        $request = new Request($url, $method);
        $request->setTlsHandshakeTimeout($this->tlsHandshakeTimeout);
        $request->setTcpConnectTimeout($this->tcpConnectTimeout);
        $request->setTransferTimeout($this->transferTimeout);
        $request->setInactivityTimeout($this->inactivityTimeout);

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
     * Creates the request body content for a file to upload.
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
        if (isset($metadata['uri']) && !str_contains($metadata['uri'], '://')) {
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

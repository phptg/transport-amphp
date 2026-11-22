<?php

declare(strict_types=1);

namespace Phptg\TransportAmphp;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Http\Client\Form;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpContent;
use Amp\Http\Client\Request;
use Amp\Http\Client\StreamedContent;
use Phptg\BotApi\Transport\ApiResponse;
use Phptg\BotApi\Transport\MimeTypeResolver\ApacheMimeTypeResolver;
use Phptg\BotApi\Transport\MimeTypeResolver\MimeTypeResolverInterface;
use Phptg\BotApi\Transport\TransportInterface;
use RuntimeException;

use function is_string;

/**
 * @api
 */
final readonly class AmpTransport implements TransportInterface
{
    private HttpClient $client;

    public function __construct(
        private MimeTypeResolverInterface $mimeTypeResolver = new ApacheMimeTypeResolver(),
        ?HttpClient $client = null,
        private ?int $tcpConnectTimeout = null,
        private ?int $tlsHandshakeTimeout = null,
        private ?int $transferTimeout = null,
        private ?int $inactivityTimeout = null,
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
            $mimeType = $this->mimeTypeResolver->resolve($file);
            if (is_string($file->pathOrResource)) {
                $form->addFile($key, $file->pathOrResource, $mimeType);
            } else {
                $form->addStream(
                    $key,
                    StreamedContent::fromStream(
                        content: new ReadableResourceStream($file->pathOrResource),
                        contentType: $mimeType),
                    $file->filename(),
                );
            }
        }

        return $this->send($url, 'POST', $form);
    }

    public function downloadFile(string $url): mixed
    {
        throw new RuntimeException('Not implemented yet');
    }

    /**
     * @param "GET"|"POST" $method
     * @param array<non-empty-string, string> $headers
     */
    private function send(
        string $url,
        string $method,
        string|HttpContent $body = '',
        array $headers = [],
    ): ApiResponse {
        $request = new Request($url, $method);

        if ($body !== '') {
            $request->setBody($body);
        }

        if ($headers !== []) {
            $request->setHeaders($headers);
        }

        if ($this->tlsHandshakeTimeout !== null) {
            $request->setTlsHandshakeTimeout($this->tlsHandshakeTimeout);
        }
        if ($this->tcpConnectTimeout !== null) {
            $request->setTcpConnectTimeout($this->tcpConnectTimeout);
        }
        if ($this->transferTimeout !== null) {
            $request->setTransferTimeout($this->transferTimeout);
        }
        if ($this->inactivityTimeout !== null) {
            $request->setInactivityTimeout($this->inactivityTimeout);
        }

        $response = $this->client->request($request);

        return new ApiResponse(
            $response->getStatus(),
            $response->getBody()->buffer(),
        );
    }
}

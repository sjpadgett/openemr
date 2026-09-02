<?php

/**
 * Minimal Sinch Fax API v3 REST client shim.
 *
 * Self-contained client covering only the surface the faxsms module consumes:
 *
 *     $client->fax->v3->faxes->create([...])
 *     $client->fax->v3->faxes->read([...], $limit)
 *     $client->fax->v3->faxes->getContext($id)->fetch()
 *     $client->fax->v3->faxes->getContext($id)->downloadContent()
 *     $client->fax->v3->faxes->getContext($id)->deleteContent()
 *
 * Deliberately mirrors the shape of the bundled SignalWire shim so the two fax
 * vendors read the same way from the controller side, even though the wire
 * formats differ (Sinch is JSON + project-scoped paths; SignalWire is LaML +
 * form-encoded).
 *
 * All HTTP goes through Guzzle (bundled with OpenEMR), so the transport is
 * injectable/mockable in tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\RestClient\Sinch\Rest;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thrown when a Sinch REST request fails (transport error or non-2xx).
 */
class RestException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

/**
 * Internal HTTP transport. Owns credentials, base URL, auth, and JSON decoding.
 *
 * Authentication is HTTP Basic with the access key id as the username and the
 * key secret as the password — the scheme Sinch documents for the Fax API
 * alongside OAuth2. Credentials never appear in a URL, so they cannot leak
 * through redirect or referrer handling.
 *
 * @internal
 */
final readonly class Transport
{
    /** Sinch's Fax API is a single global endpoint; there are no regional hosts. */
    public const DEFAULT_HOSTNAME = 'https://fax.api.sinch.com';

    private string $hostname;
    private ClientInterface $http;

    public function __construct(
        private string $projectId,
        private string $keyId,
        private string $keySecret,
        string $hostname = self::DEFAULT_HOSTNAME,
        ?ClientInterface $http = null
    ) {
        $this->hostname = self::normalizeHostname($hostname);
        $this->http = $http ?? new GuzzleClient(['timeout' => 60]);
    }

    /**
     * Accept a bare host ("fax.api.sinch.com") or a full origin, with or
     * without a trailing slash, and normalize to a scheme-qualified origin.
     */
    private static function normalizeHostname(string $hostname): string
    {
        $hostname = trim($hostname);
        if ($hostname === '') {
            return self::DEFAULT_HOSTNAME;
        }
        if (!preg_match('#^https?://#i', $hostname)) {
            $hostname = 'https://' . $hostname;
        }
        return rtrim($hostname, '/');
    }

    /**
     * Project-scoped Faxes collection base:
     * https://fax.api.sinch.com/v3/projects/{projectId}/faxes
     */
    private function faxesBase(): string
    {
        return $this->hostname . '/v3/projects/' . rawurlencode($this->projectId) . '/faxes';
    }

    /**
     * Issue a request against the Faxes resource and decode the JSON body.
     *
     * @param array<string, mixed> $opts
     * @return array<mixed, mixed>
     * @throws RestException
     */
    public function request(string $method, string $path = '', array $opts = []): array
    {
        $response = $this->send($method, $this->faxesBase() . $path, $opts, 'application/json');
        $body = $response['body'];

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RestException(
                'Sinch returned a non-JSON body (HTTP ' . $response['status'] . '): ' . self::snippet($body),
                $response['status']
            );
        }

        return $decoded;
    }

    /**
     * Issue a request against another project-scoped resource (services and
     * their numbers), decoding the JSON body.
     *
     * @param array<string, mixed> $opts
     * @return array<mixed, mixed>
     * @throws RestException
     */
    public function requestProject(string $method, string $path, array $opts = []): array
    {
        $url = $this->hostname . '/v3/projects/' . rawurlencode($this->projectId) . $path;
        $response = $this->send($method, $url, $opts, 'application/json');
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RestException(
                'Sinch returned a non-JSON body (HTTP ' . $response['status'] . ')',
                $response['status']
            );
        }

        return $decoded;
    }

    /**
     * Issue a request whose body is opaque bytes (the fax PDF).
     *
     * @return array{status: int, body: string, contentType: string}
     * @throws RestException
     */
    public function requestBinary(string $method, string $path = ''): array
    {
        return $this->send($method, $this->faxesBase() . $path, [], 'application/pdf');
    }

    /**
     * Issue a request that returns no body (e.g. DELETE -> 204). True on 2xx.
     *
     * @throws RestException
     */
    public function requestNoContent(string $method, string $path = ''): bool
    {
        $this->send($method, $this->faxesBase() . $path, [], 'application/json');
        return true;
    }

    /**
     * @param array<string, mixed> $opts
     * @return array{status: int, body: string, contentType: string}
     * @throws RestException
     */
    private function send(string $method, string $url, array $opts, string $accept): array
    {
        $options = array_merge(
            [
                'auth' => [$this->keyId, $this->keySecret],
                'headers' => ['Accept' => $accept],
                'http_errors' => true,
            ],
            $opts
        );

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new RestException('Sinch request failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new RestException('Sinch returned HTTP ' . $status . ': ' . self::snippet($body), $status);
        }

        return [
            'status' => $status,
            'body' => $body,
            'contentType' => strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0])),
        ];
    }

    private static function snippet(string $body): string
    {
        $body = trim($body);
        return strlen($body) > 300 ? substr($body, 0, 300) . '…' : $body;
    }
}

/**
 * A single fax resource, mapped from the Sinch v3 JSON representation.
 *
 * Field names follow the API (already camelCase), so unlike the SignalWire
 * shim there is no snake_case translation layer — only type normalization.
 */
final class FaxInstance
{
    public ?string $id = null;
    public ?string $direction = null;
    public ?string $status = null;
    public ?string $from = null;
    public ?string $to = null;
    public ?int $numberOfPages = null;
    public ?int $pagesSentSuccessfully = null;
    public ?\DateTimeImmutable $createTime = null;
    public ?\DateTimeImmutable $completedTime = null;
    public ?string $errorType = null;
    public ?int $errorCode = null;
    public ?string $errorMessage = null;
    public ?string $serviceId = null;
    public ?int $retryCount = null;
    /** Whether Sinch still holds the document for this fax (storage-on accounts). */
    public ?bool $hasFile = null;
    public ?string $priceAmount = null;
    public ?string $priceCurrency = null;

    /**
     * @param array<mixed, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $fax = new self();
        $fax->id = self::str($raw['id'] ?? null);
        $fax->direction = self::str($raw['direction'] ?? null);
        $fax->status = self::str($raw['status'] ?? null);
        $fax->from = self::str($raw['from'] ?? null);
        $fax->to = self::str($raw['to'] ?? null);
        $fax->numberOfPages = self::intOrNull($raw['numberOfPages'] ?? null);
        $fax->pagesSentSuccessfully = self::intOrNull($raw['pagesSentSuccessfully'] ?? null);
        $fax->createTime = self::toDate($raw['createTime'] ?? null);
        $fax->completedTime = self::toDate($raw['completedTime'] ?? null);
        $fax->errorType = self::str($raw['errorType'] ?? null);
        $fax->errorCode = self::intOrNull($raw['errorCode'] ?? null);
        $fax->errorMessage = self::str($raw['errorMessage'] ?? null);
        $fax->serviceId = self::str($raw['serviceId'] ?? null);
        $fax->retryCount = self::intOrNull($raw['retryCount'] ?? null);
        $fax->hasFile = self::boolOrNull($raw['hasFile'] ?? null);

        $price = $raw['price'] ?? null;
        if (is_array($price)) {
            $fax->priceAmount = self::str($price['amount'] ?? null);
            $fax->priceCurrency = self::str($price['currencyCode'] ?? null);
        }

        return $fax;
    }

    /** True for a fax whose status can no longer change. */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['COMPLETED', 'FAILURE'], true);
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string)$value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * The API documents hasFile as a string, but real payloads carry either a
     * JSON boolean or the strings "true"/"false"; normalize both.
     */
    private static function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1'], true);
        }
        return null;
    }

    private static function toDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        // Sinch returns ISO-8601/RFC-3339 timestamps. date_create_immutable()
        // returns false on a bad string instead of throwing, which avoids
        // catching \Exception (and thus \ErrorException).
        $date = date_create_immutable($value);
        return $date instanceof \DateTimeImmutable ? $date : null;
    }
}

/**
 * Context for a single fax (selected by id): fetch, download content, delete
 * content.
 *
 * Note that Sinch has no "delete the fax record" operation — only
 * DELETE .../file, which frees the stored document while the fax metadata
 * remains listable. That is the provider's retention model, and the controller
 * layer is written around it.
 */
final readonly class FaxContext
{
    public function __construct(private Transport $transport, private string $id)
    {
    }

    /**
     * GET .../faxes/{id}
     *
     * @throws RestException
     */
    public function fetch(): FaxInstance
    {
        $data = $this->transport->request('GET', '/' . rawurlencode($this->id));
        return FaxInstance::fromArray($data);
    }

    /**
     * GET .../faxes/{id}/file — the rendered fax as PDF bytes.
     *
     * @return string|null Raw PDF bytes, or null when the provider no longer
     *                     holds the document (storage disabled, or already
     *                     deleted).
     * @throws RestException
     */
    public function downloadContent(): ?string
    {
        $response = $this->transport->requestBinary('GET', '/' . rawurlencode($this->id) . '/file');
        $body = $response['body'];

        return $body === '' ? null : $body;
    }

    /**
     * DELETE .../faxes/{id}/file — removes the stored document from Sinch. The
     * fax record itself (metadata) is retained by the provider.
     *
     * @return bool True on a 2xx response.
     * @throws RestException
     */
    public function deleteContent(): bool
    {
        return $this->transport->requestNoContent('DELETE', '/' . rawurlencode($this->id) . '/file');
    }
}

/**
 * The Faxes collection: create / read (list) / getContext.
 */
final readonly class FaxList
{
    /** Sinch caps page size at 1000; its own default is 100. */
    private const MAX_PAGE_SIZE = 1000;
    private const DEFAULT_PAGE_SIZE = 100;
    /** Hard stop on pagination so a malformed page counter cannot spin forever. */
    private const MAX_PAGES = 100;

    /** File types Sinch accepts for base64 payloads. */
    private const VALID_FILE_TYPES = ['PDF', 'DOCX', 'TIF', 'JPG', 'TXT', 'HTML', 'PNG'];

    public function __construct(private Transport $transport)
    {
    }

    /**
     * POST .../faxes
     *
     * Sends as application/json with base64-encoded file content, so the
     * document never has to be exposed at a URL the provider can reach. The
     * caller hands over raw bytes; base64 encoding happens here so there is
     * exactly one place that has to get the wire format right.
     *
     * @param array<string, mixed> $options Accepts:
     *        to (string|list<string>), from, files (list<array{content: string,
     *        fileType?: string}>), contentUrl (string|list<string>), headerText,
     *        headerPageNumbers, headerTimeZone, callbackUrl,
     *        callbackUrlContentType, imageConversionMethod, resolution,
     *        serviceId, maxRetries, retryDelaySeconds, labels.
     * @return list<FaxInstance> One entry per recipient.
     * @throws RestException
     */
    public function create(array $options): array
    {
        $payload = [];

        $to = $options['to'] ?? null;
        if (is_array($to)) {
            $recipients = [];
            foreach ($to as $recipient) {
                if (is_scalar($recipient)) {
                    $recipients[] = (string)$recipient;
                }
            }
            $payload['to'] = $recipients;
        } elseif (is_scalar($to)) {
            $payload['to'] = (string)$to;
        }

        $files = $options['files'] ?? null;
        if (is_array($files)) {
            $encoded = [];
            foreach ($files as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $content = $file['content'] ?? null;
                if (!is_string($content) || $content === '') {
                    continue;
                }
                $type = $file['fileType'] ?? 'PDF';
                $type = is_string($type) ? strtoupper($type) : 'PDF';
                if (!in_array($type, self::VALID_FILE_TYPES, true)) {
                    throw new RestException('Unsupported Sinch fax file type: ' . $type);
                }
                $encoded[] = ['file' => base64_encode($content), 'fileType' => $type];
            }
            if ($encoded !== []) {
                $payload['files'] = $encoded;
            }
        }

        $contentUrl = $options['contentUrl'] ?? null;
        if (is_array($contentUrl)) {
            $urls = [];
            foreach ($contentUrl as $url) {
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
            if ($urls !== []) {
                $payload['contentUrl'] = $urls;
            }
        } elseif (is_string($contentUrl) && $contentUrl !== '') {
            $payload['contentUrl'] = $contentUrl;
        }

        $passthrough = [
            'from', 'headerText', 'headerPageNumbers', 'headerTimeZone', 'callbackUrl',
            'callbackUrlContentType', 'imageConversionMethod', 'resolution', 'serviceId',
            'maxRetries', 'retryDelaySeconds',
        ];
        foreach ($passthrough as $key) {
            if (isset($options[$key]) && is_scalar($options[$key])) {
                $payload[$key] = $options[$key];
            }
        }

        if (isset($options['labels']) && is_array($options['labels'])) {
            $payload['labels'] = $options['labels'];
        }

        $data = $this->transport->request('POST', '', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string)json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        // A single recipient yields one fax object; an array of recipients
        // yields {"faxes": [...]} — except when the array held one entry, in
        // which case the server still answers with a bare fax object.
        $rows = $data['faxes'] ?? null;
        if (is_array($rows)) {
            $faxes = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $faxes[] = FaxInstance::fromArray($row);
                }
            }
            return $faxes;
        }

        return [FaxInstance::fromArray($data)];
    }

    /**
     * GET .../faxes (auto-paginates up to $limit).
     *
     * @param array<string, mixed> $filters Accepts: direction, status, to, from,
     *        serviceId, createTime (Y-m-d), createTimeFrom / createTimeTo
     *        (ISO-8601 instants, sent as the API's `createTime>` / `createTime<`).
     * @return list<FaxInstance>
     * @throws RestException
     */
    public function read(array $filters = [], ?int $limit = null): array
    {
        $query = [];
        foreach (['direction', 'status', 'to', 'from', 'serviceId'] as $key) {
            if (isset($filters[$key]) && is_scalar($filters[$key])) {
                $query[$key] = (string)$filters[$key];
            }
        }
        if (isset($filters['createTime']) && is_scalar($filters['createTime'])) {
            $query['createTime'] = (string)$filters['createTime'];
        }
        // The range bounds are literally named "createTime>" and "createTime<".
        if (isset($filters['createTimeFrom']) && is_scalar($filters['createTimeFrom'])) {
            $query['createTime>'] = (string)$filters['createTimeFrom'];
        }
        if (isset($filters['createTimeTo']) && is_scalar($filters['createTimeTo'])) {
            $query['createTime<'] = (string)$filters['createTimeTo'];
        }

        $pageSize = $limit !== null
            ? max(1, min($limit, self::MAX_PAGE_SIZE))
            : self::DEFAULT_PAGE_SIZE;
        $query['pageSize'] = $pageSize;

        $results = [];
        $page = 1;

        do {
            $query['page'] = $page;
            $data = $this->transport->request('GET', '', ['query' => $query]);

            $rows = $data['faxes'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $results[] = FaxInstance::fromArray($row);
                        if ($limit !== null && count($results) >= $limit) {
                            return $results;
                        }
                    }
                }
            }

            // Page-number pagination: keep going while the reported page is
            // behind the reported total.
            $currentPage = is_numeric($data['page'] ?? null) ? (int)$data['page'] : $page;
            $totalPages = is_numeric($data['totalPages'] ?? null) ? (int)$data['totalPages'] : 1;
            $page = $currentPage + 1;
        } while ($currentPage < $totalPages && $page <= self::MAX_PAGES);

        return $results;
    }

    /**
     * Select a single fax by id.
     */
    public function getContext(string $id): FaxContext
    {
        return new FaxContext($this->transport, $id);
    }
}

/**
 * A fax service: the container a Sinch project uses to group numbers, webhook
 * configuration and document-retention settings.
 *
 * The retention flags matter beyond configuration display: they are the
 * provider's own statement of whether documents will exist to download later,
 * which is otherwise a setting an administrator has to keep in sync by hand.
 */
final class ServiceInstance
{
    public ?string $id = null;
    public ?string $name = null;
    public ?string $defaultFrom = null;
    public ?bool $defaultForProject = null;
    public ?string $incomingWebhookUrl = null;
    public ?bool $saveInboundFaxDocuments = null;
    public ?bool $saveOutboundFaxDocuments = null;

    /**
     * @param array<mixed, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $service = new self();
        $service->id = self::str($raw['id'] ?? null);
        $service->name = self::str($raw['name'] ?? null);
        $service->defaultFrom = self::str($raw['defaultFrom'] ?? null);
        $service->defaultForProject = self::boolOrNull($raw['defaultForProject'] ?? null);
        $service->incomingWebhookUrl = self::str($raw['incomingWebhookUrl'] ?? null);
        $service->saveInboundFaxDocuments = self::boolOrNull($raw['saveInboundFaxDocuments'] ?? null);
        $service->saveOutboundFaxDocuments = self::boolOrNull($raw['saveOutboundFaxDocuments'] ?? null);

        return $service;
    }

    /**
     * Whether this service keeps documents for received faxes. Null when the
     * provider did not report it, which callers must treat as "unknown" rather
     * than as "off" - guessing "off" here would disable document fetching on a
     * service that actually retains them.
     */
    public function retainsInboundDocuments(): ?bool
    {
        return $this->saveInboundFaxDocuments;
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string)$value : null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1'], true);
        }

        return null;
    }
}

/**
 * Context for one fax service; currently just its phone numbers.
 */
final readonly class ServiceContext
{
    public function __construct(private Transport $transport, private string $serviceId)
    {
    }

    /**
     * GET .../services/{id}/numbers
     *
     * @return list<string> Phone numbers in E.164 form.
     * @throws RestException
     */
    public function numbers(int $pageSize = 100): array
    {
        $data = $this->transport->requestProject(
            'GET',
            '/services/' . rawurlencode($this->serviceId) . '/numbers',
            ['query' => ['pageSize' => $pageSize]]
        );

        $rows = $data['numbers'] ?? [];
        $numbers = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                // Entries are objects carrying phoneNumber, but tolerate a bare
                // string in case the API ever simplifies the shape.
                $value = is_array($row) ? ($row['phoneNumber'] ?? $row['number'] ?? null) : $row;
                if (is_string($value) && $value !== '') {
                    $numbers[] = $value;
                }
            }
        }

        return $numbers;
    }
}

/**
 * The Services collection.
 */
final readonly class ServiceList
{
    public function __construct(private Transport $transport)
    {
    }

    /**
     * GET .../services
     *
     * @return list<ServiceInstance>
     * @throws RestException
     */
    public function read(int $pageSize = 100): array
    {
        $data = $this->transport->requestProject('GET', '/services', ['query' => ['pageSize' => $pageSize]]);

        $rows = $data['services'] ?? [];
        $services = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $services[] = ServiceInstance::fromArray($row);
                }
            }
        }

        return $services;
    }

    public function getContext(string $serviceId): ServiceContext
    {
        return new ServiceContext($this->transport, $serviceId);
    }
}

/**
 * Version wrapper: exposes ->faxes and ->services under ->fax->v3->.
 */
final class V3Domain
{
    public FaxList $faxes;
    public ServiceList $services;

    public function __construct(Transport $transport)
    {
        $this->faxes = new FaxList($transport);
        $this->services = new ServiceList($transport);
    }
}

/**
 * Fax domain wrapper: exposes ->v3.
 */
final class FaxDomain
{
    public V3Domain $v3;

    public function __construct(Transport $transport)
    {
        $this->v3 = new V3Domain($transport);
    }
}

/**
 * Sinch Fax API v3 client (fax surface only).
 */
class Client
{
    /** Exposes the ->fax->v3->faxes chain used by the faxsms controller. */
    public FaxDomain $fax;

    private readonly Transport $transport;

    /**
     * @param string               $projectId Sinch project id (path-scoping, not auth).
     * @param string               $keyId     Access key id (Basic auth username).
     * @param string               $keySecret Access key secret (Basic auth password).
     * @param array<string, mixed> $options   Recognized keys: hostname (string,
     *                                        for self-hosted/proxied endpoints)
     *                                        and httpClient (GuzzleHttp\ClientInterface).
     */
    public function __construct(string $projectId, string $keyId, string $keySecret, array $options = [])
    {
        $rawHostname = $options['hostname'] ?? '';
        $hostname = is_string($rawHostname) && $rawHostname !== ''
            ? $rawHostname
            : Transport::DEFAULT_HOSTNAME;

        // Callers hand us an options array straight from request/config, so the
        // instanceof guard below is a real runtime check, not a tautology.
        $http = $options['httpClient'] ?? null;
        if ($http !== null && !$http instanceof ClientInterface) {
            throw new \InvalidArgumentException("'httpClient' must implement GuzzleHttp\\ClientInterface");
        }

        $this->transport = new Transport($projectId, $keyId, $keySecret, $hostname, $http);
        $this->fax = new FaxDomain($this->transport);
    }
}

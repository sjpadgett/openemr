<?php

/**
 * Isolated test for the bundled Sinch Fax API v3 REST client shim.
 *
 * The shim (OpenEMR\Modules\FaxSMS\RestClient\Sinch\Rest\Client) covers only
 * the surface SinchFaxClient consumes:
 *
 *     $client->fax->v3->faxes->create([...])
 *     $client->fax->v3->faxes->read([...], $limit)
 *     $client->fax->v3->faxes->getContext($id)->fetch()
 *     $client->fax->v3->faxes->getContext($id)->downloadContent()
 *     $client->fax->v3->faxes->getContext($id)->deleteContent()
 *
 * All HTTP goes through an injectable Guzzle client, so these tests drive the
 * shim entirely through a MockHandler — no network, no database. A recording
 * middleware captures the outgoing requests so we can assert the project-scoped
 * endpoint, Basic auth against the access key pair, the JSON body shape
 * (including base64 file encoding), and the page-number pagination.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\FaxSMS\RestClient;

use Composer\Autoload\ClassLoader;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\FaxSMS\RestClient\Sinch\Rest\Client;
use OpenEMR\Modules\FaxSMS\RestClient\Sinch\Rest\FaxInstance;
use OpenEMR\Modules\FaxSMS\RestClient\Sinch\Rest\RestException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SinchRestClientTest extends TestCase
{
    private const PROJECT = 'b7c1f0aa-1111-2222-3333-projectid0001';
    private const KEY_ID  = 'sinch_key_id_value';
    private const SECRET  = 'sinch_key_secret_value';
    private const HOST    = 'fax.api.sinch.com';

    private const EXPECTED_PATH = '/v3/projects/' . self::PROJECT . '/faxes';

    /**
     * The custom_modules/oe-module-faxsms module is not registered in the root
     * composer.json autoload map; at runtime the module manager registers its
     * PSR-4 prefix when the module is enabled. The isolated suite has no
     * database, so register the prefix here before touching any module class.
     *
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (!$loader instanceof ClassLoader) {
            self::fail('Composer ClassLoader not available to register module autoload prefix.');
        }
        $loader->addPsr4(
            'OpenEMR\\Modules\\FaxSMS\\',
            dirname(__DIR__, 6) . '/interface/modules/custom_modules/oe-module-faxsms/src/'
        );
    }

    public function testCreatePostsBase64JsonAndMapsResponse(): void
    {
        $history = [];
        $client = $this->makeClient(
            [
                new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'id'            => 'FAX01HZ0000000000000000',
                    'direction'     => 'OUTBOUND',
                    'status'        => 'QUEUED',
                    'from'          => '+15557654321',
                    'to'            => '+15551234567',
                    'numberOfPages' => 0,
                    'createTime'    => '2026-06-16T13:45:07Z',
                    'hasFile'       => true,
                    'price'         => ['amount' => '0.0700', 'currencyCode' => 'USD'],
                ])),
            ],
            $history
        );

        $faxes = $client->fax->v3->faxes->create([
            'to'    => '+15551234567',
            'from'  => '+15557654321',
            'files' => [['content' => '%PDF-1.4 body bytes', 'fileType' => 'pdf']],
        ]);

        self::assertCount(1, $faxes, 'A single recipient still yields a one-element list.');
        $fax = $faxes[0];
        self::assertSame('FAX01HZ0000000000000000', $fax->id);
        self::assertSame('QUEUED', $fax->status);
        self::assertSame('OUTBOUND', $fax->direction);
        self::assertSame(0, $fax->numberOfPages);
        self::assertTrue($fax->hasFile);
        self::assertSame('0.0700', $fax->priceAmount);
        self::assertSame('USD', $fax->priceCurrency);
        self::assertInstanceOf(\DateTimeImmutable::class, $fax->createTime);
        self::assertSame('2026-06-16 13:45:07', $fax->createTime->format('Y-m-d H:i:s'));
        self::assertFalse($fax->isTerminal(), 'QUEUED is not a terminal status.');

        $request = $this->lastRequest($history);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::HOST, $request->getUri()->getHost());
        self::assertSame(self::EXPECTED_PATH, $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame($this->expectedBasicAuthHeader(), $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('+15551234567', $body['to'] ?? null);
        self::assertSame('+15557654321', $body['from'] ?? null);
        $files = $body['files'] ?? null;
        self::assertIsArray($files);
        self::assertIsArray($files[0] ?? null);
        // The document must travel as base64 in the body — never as a URL the
        // provider has to reach back into this server for.
        self::assertSame(base64_encode('%PDF-1.4 body bytes'), $files[0]['file'] ?? null);
        self::assertSame('PDF', $files[0]['fileType'] ?? null, 'fileType is normalized to upper case.');
        self::assertArrayNotHasKey('contentUrl', $body);
    }

    public function testCreateWithMultipleRecipientsReturnsEveryFax(): void
    {
        $history = [];
        $client = $this->makeClient(
            [
                new Response(200, [], (string) json_encode([
                    'faxes' => [
                        ['id' => 'FAXA', 'to' => '+15551110000', 'status' => 'QUEUED'],
                        ['id' => 'FAXB', 'to' => '+15552220000', 'status' => 'QUEUED'],
                    ],
                ])),
            ],
            $history
        );

        $faxes = $client->fax->v3->faxes->create([
            'to'    => ['+15551110000', '+15552220000'],
            'from'  => '+15557654321',
            'files' => [['content' => 'bytes', 'fileType' => 'PDF']],
        ]);

        self::assertCount(2, $faxes);
        self::assertSame(['FAXA', 'FAXB'], array_map(static fn(FaxInstance $f): ?string => $f->id, $faxes));

        $body = json_decode((string) $this->lastRequest($history)->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(['+15551110000', '+15552220000'], $body['to'] ?? null);
    }

    public function testCreateRejectsUnsupportedFileType(): void
    {
        $history = [];
        $client = $this->makeClient([new Response(200, [], '{}')], $history);

        $this->expectException(RestException::class);
        $client->fax->v3->faxes->create([
            'to'    => '+15551234567',
            'files' => [['content' => 'bytes', 'fileType' => 'EXE']],
        ]);
    }

    public function testCreatePassesServiceIdAndOptionalScalarsThrough(): void
    {
        $history = [];
        $client = $this->makeClient([new Response(200, [], (string) json_encode(['id' => 'FAXC']))], $history);

        $client->fax->v3->faxes->create([
            'to'         => '+15551234567',
            'from'       => '+15557654321',
            'files'      => [['content' => 'bytes']],
            'serviceId'  => 'svc_123',
            'headerText' => 'Clinic',
            'maxRetries' => 2,
        ]);

        $body = json_decode((string) $this->lastRequest($history)->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('svc_123', $body['serviceId'] ?? null);
        self::assertSame('Clinic', $body['headerText'] ?? null);
        self::assertSame(2, $body['maxRetries'] ?? null);
        $files = $body['files'] ?? null;
        self::assertIsArray($files);
        self::assertIsArray($files[0] ?? null);
        self::assertSame('PDF', $files[0]['fileType'] ?? null, 'fileType defaults to PDF.');
    }

    public function testReadAppliesFiltersAndRangeParameterNames(): void
    {
        $history = [];
        $client = $this->makeClient(
            [
                new Response(200, [], (string) json_encode([
                    'faxes' => [
                        ['id' => 'FAXin1', 'direction' => 'INBOUND', 'status' => 'COMPLETED', 'numberOfPages' => 2],
                        ['id' => 'FAXin2', 'direction' => 'INBOUND', 'status' => 'COMPLETED', 'numberOfPages' => 1],
                    ],
                    'page'       => 1,
                    'totalPages' => 1,
                ])),
            ],
            $history
        );

        $faxes = $client->fax->v3->faxes->read(
            [
                'direction'      => 'INBOUND',
                'createTimeFrom' => '2026-06-01T00:00:01Z',
                'createTimeTo'   => '2026-06-16T23:59:59Z',
            ],
            100
        );

        self::assertCount(2, $faxes);
        self::assertSame(2, $faxes[0]->numberOfPages);

        $request = $this->lastRequest($history);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::EXPECTED_PATH, $request->getUri()->getPath());

        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame('INBOUND', $query['direction'] ?? null);
        self::assertSame('100', $query['pageSize'] ?? null);
        // PHP's parse_str mangles the literal '>' / '<' parameter names, so
        // assert on the raw query string for those two.
        $raw = rawurldecode($request->getUri()->getQuery());
        self::assertStringContainsString('createTime>=2026-06-01T00:00:01Z', $raw);
        self::assertStringContainsString('createTime<=2026-06-16T23:59:59Z', $raw);
    }

    public function testReadFollowsPageNumberPaginationToExhaustion(): void
    {
        $history = [];
        $client = $this->makeClient(
            [
                new Response(200, [], (string) json_encode([
                    'faxes'      => [['id' => 'FX1'], ['id' => 'FX2']],
                    'page'       => 1,
                    'totalPages' => 2,
                ])),
                new Response(200, [], (string) json_encode([
                    'faxes'      => [['id' => 'FX3']],
                    'page'       => 2,
                    'totalPages' => 2,
                ])),
            ],
            $history
        );

        $faxes = $client->fax->v3->faxes->read([], 100);

        self::assertSame(['FX1', 'FX2', 'FX3'], array_map(static fn(FaxInstance $f): ?string => $f->id, $faxes));
        self::assertCount(2, $history, 'Two pages means two round-trips.');

        parse_str($history[1]->getUri()->getQuery(), $secondQuery);
        self::assertSame('2', $secondQuery['page'] ?? null);
    }

    public function testReadStopsAtLimitWithoutOverFetching(): void
    {
        $history = [];
        $client = $this->makeClient(
            [
                new Response(200, [], (string) json_encode([
                    'faxes'      => [['id' => 'FX1'], ['id' => 'FX2'], ['id' => 'FX3']],
                    'page'       => 1,
                    'totalPages' => 5,
                ])),
            ],
            $history
        );

        $faxes = $client->fax->v3->faxes->read([], 2);

        self::assertCount(2, $faxes);
        self::assertCount(1, $history, 'The limit was reached on page one; no further page may be fetched.');
    }

    public function testGetContextFetchRetrievesSingleFax(): void
    {
        $history = [];
        $client = $this->makeClient(
            [
                new Response(200, [], (string) json_encode([
                    'id'                    => 'FAXfetchme',
                    'status'                => 'COMPLETED',
                    'direction'             => 'INBOUND',
                    'numberOfPages'         => 3,
                    'pagesSentSuccessfully' => 3,
                    'hasFile'               => 'true',
                ])),
            ],
            $history
        );

        $fax = $client->fax->v3->faxes->getContext('FAXfetchme')->fetch();

        self::assertSame('COMPLETED', $fax->status);
        self::assertSame(3, $fax->numberOfPages);
        self::assertSame(3, $fax->pagesSentSuccessfully);
        self::assertTrue($fax->hasFile, 'A string "true" must normalize to a real boolean.');
        self::assertTrue($fax->isTerminal());

        $request = $this->lastRequest($history);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::EXPECTED_PATH . '/FAXfetchme', $request->getUri()->getPath());
    }

    public function testDownloadContentRequestsPdfAndReturnsBytes(): void
    {
        $history = [];
        $client = $this->makeClient(
            [new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 fax bytes')],
            $history
        );

        $bytes = $client->fax->v3->faxes->getContext('FAXdl')->downloadContent();

        self::assertSame('%PDF-1.4 fax bytes', $bytes);

        $request = $this->lastRequest($history);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::EXPECTED_PATH . '/FAXdl/file', $request->getUri()->getPath());
        self::assertSame('application/pdf', $request->getHeaderLine('Accept'));
    }

    public function testDownloadContentReturnsNullOnEmptyBody(): void
    {
        $history = [];
        $client = $this->makeClient([new Response(200, ['Content-Type' => 'application/pdf'], '')], $history);

        self::assertNull($client->fax->v3->faxes->getContext('FAXgone')->downloadContent());
    }

    public function testDeleteContentTargetsTheFileSubresource(): void
    {
        $history = [];
        $client = $this->makeClient([new Response(204, [], '')], $history);

        self::assertTrue($client->fax->v3->faxes->getContext('FAXrelease')->deleteContent());

        $request = $this->lastRequest($history);
        self::assertSame('DELETE', $request->getMethod());
        // Sinch has no "delete the fax" operation; only its stored document is
        // released, which is what makes a handled fax drop out of the inbox.
        self::assertSame(self::EXPECTED_PATH . '/FAXrelease/file', $request->getUri()->getPath());
    }

    public function testMissingOptionalFieldsMapToNull(): void
    {
        $history = [];
        $client = $this->makeClient(
            [new Response(200, [], (string) json_encode(['id' => 'FAXsparse', 'status' => 'QUEUED']))],
            $history
        );

        $fax = $client->fax->v3->faxes->getContext('FAXsparse')->fetch();

        self::assertNull($fax->createTime);
        self::assertNull($fax->numberOfPages);
        self::assertNull($fax->hasFile);
        self::assertNull($fax->priceAmount);
        self::assertNull($fax->errorMessage);
    }

    public function testFailureStatusCarriesErrorDetail(): void
    {
        $history = [];
        $client = $this->makeClient(
            [
                new Response(200, [], (string) json_encode([
                    'id'           => 'FAXbad',
                    'status'       => 'FAILURE',
                    'errorType'    => 'LINE_ERROR',
                    'errorCode'    => 4111,
                    'errorMessage' => 'No answer',
                    'hasFile'      => false,
                ])),
            ],
            $history
        );

        $fax = $client->fax->v3->faxes->getContext('FAXbad')->fetch();

        self::assertSame('LINE_ERROR', $fax->errorType);
        self::assertSame(4111, $fax->errorCode);
        self::assertSame('No answer', $fax->errorMessage);
        self::assertFalse($fax->hasFile);
        self::assertTrue($fax->isTerminal());
    }

    public function testHttpErrorIsWrappedInRestException(): void
    {
        $history = [];
        $client = $this->makeClient(
            [new Response(400, [], (string) json_encode(['error' => ['message' => 'Invalid to number']]))],
            $history
        );

        try {
            $client->fax->v3->faxes->create(['to' => 'nope', 'files' => [['content' => 'x']]]);
            self::fail('Expected RestException for an HTTP 400 response.');
        } catch (RestException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertStringContainsString('request failed', $e->getMessage());
        }
    }

    public function testNonJsonBodyIsWrappedInRestException(): void
    {
        $history = [];
        $client = $this->makeClient([new Response(200, ['Content-Type' => 'text/html'], '<html>nope</html>')], $history);

        $this->expectException(RestException::class);
        $client->fax->v3->faxes->getContext('FAXanything')->fetch();
    }

    public function testBareHostnameOptionIsNormalizedToHttps(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], (string) json_encode(['id' => 'FAXz']))]);
        $stack = HandlerStack::create($mock);
        $this->recordRequestsInto($stack, $history);

        $client = new Client(self::PROJECT, self::KEY_ID, self::SECRET, [
            'hostname'   => self::HOST . '/',
            'httpClient' => new GuzzleClient(['handler' => $stack]),
        ]);

        $client->fax->v3->faxes->getContext('FAXz')->fetch();

        $uri = $this->lastRequest($history)->getUri();
        self::assertSame('https', $uri->getScheme());
        self::assertSame(self::HOST, $uri->getHost());
        self::assertSame(self::EXPECTED_PATH . '/FAXz', $uri->getPath());
    }

    public function testNonGuzzleHttpClientOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(self::PROJECT, self::KEY_ID, self::SECRET, ['httpClient' => new \stdClass()]);
    }

    public function testServiceDiscoveryListsServicesAndTheirRetentionFlags(): void
    {
        $history = [];
        $client = $this->makeClient(
            [
                new Response(200, [], (string) json_encode([
                    'services' => [
                        [
                            'id' => 'svc_main',
                            'name' => 'Main Clinic',
                            'defaultFrom' => '+15557654321',
                            'defaultForProject' => true,
                            'saveInboundFaxDocuments' => true,
                            'saveOutboundFaxDocuments' => true,
                        ],
                        [
                            'id' => 'svc_privacy',
                            'name' => 'Records',
                            // The API has been seen to render these as strings.
                            'saveInboundFaxDocuments' => 'false',
                        ],
                    ],
                ])),
            ],
            $history
        );

        $services = $client->fax->v3->services->read();

        self::assertCount(2, $services);
        self::assertSame('svc_main', $services[0]->id);
        self::assertSame('Main Clinic', $services[0]->name);
        self::assertSame('+15557654321', $services[0]->defaultFrom);
        self::assertTrue($services[0]->defaultForProject);
        self::assertTrue($services[0]->retainsInboundDocuments());
        self::assertFalse($services[1]->retainsInboundDocuments(), '"false" must normalize to a real boolean.');

        $request = $this->lastRequest($history);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v3/projects/' . self::PROJECT . '/services', $request->getUri()->getPath());
        self::assertSame($this->expectedBasicAuthHeader(), $request->getHeaderLine('Authorization'));
    }

    /**
     * An absent flag is "unknown", never "off" — guessing off would disable
     * document fetching on a service that actually retains them.
     */
    public function testUnreportedRetentionFlagIsNullNotFalse(): void
    {
        $history = [];
        $client = $this->makeClient(
            [new Response(200, [], (string) json_encode(['services' => [['id' => 'svc_quiet']]]))],
            $history
        );

        self::assertNull($client->fax->v3->services->read()[0]->retainsInboundDocuments());
    }

    public function testServiceNumbersAreExtractedFromTheirObjects(): void
    {
        $history = [];
        $client = $this->makeClient(
            [
                new Response(200, [], (string) json_encode([
                    'numbers' => [
                        ['phoneNumber' => '+15557654321', 'permissions' => 'both'],
                        ['phoneNumber' => '+15557650000', 'permissions' => 'receive'],
                        // Tolerated shapes: a bare string, and an unusable entry.
                        '+15557651111',
                        ['permissions' => 'send'],
                    ],
                ])),
            ],
            $history
        );

        $numbers = $client->fax->v3->services->getContext('svc_main')->numbers();

        self::assertSame(['+15557654321', '+15557650000', '+15557651111'], $numbers);
        self::assertSame(
            '/v3/projects/' . self::PROJECT . '/services/svc_main/numbers',
            $this->lastRequest($history)->getUri()->getPath()
        );
    }

    public function testEmptyServiceListIsNotAnError(): void
    {
        $history = [];
        $client = $this->makeClient([new Response(200, [], (string) json_encode(['services' => []]))], $history);

        self::assertSame([], $client->fax->v3->services->read());
    }

    /**
     * Build a Client whose transport is a MockHandler-backed Guzzle client,
     * recording outgoing requests into $history.
     *
     * @param list<Response>         $responses
     * @param list<RequestInterface> $history
     */
    private function makeClient(array $responses, array &$history): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->recordRequestsInto($stack, $history);

        return new Client(self::PROJECT, self::KEY_ID, self::SECRET, [
            'hostname'   => self::HOST,
            'httpClient' => new GuzzleClient(['handler' => $stack]),
        ]);
    }

    /**
     * Record each outgoing request into the given list. Replaces Guzzle's
     * history middleware, whose container is typed array|ArrayAccess and would
     * otherwise force every reader to re-narrow the captured entries.
     *
     * @param list<RequestInterface> $history
     */
    private function recordRequestsInto(HandlerStack $stack, array &$history): void
    {
        $stack->push(static function (callable $handler) use (&$history): callable {
            return static function (RequestInterface $request, array $options) use ($handler, &$history): mixed {
                $history[] = $request;
                return $handler($request, $options);
            };
        });
    }

    /**
     * @param list<RequestInterface> $history
     */
    private function lastRequest(array $history): RequestInterface
    {
        self::assertNotEmpty($history, 'Expected at least one HTTP request to have been made.');
        $last = end($history);
        self::assertInstanceOf(RequestInterface::class, $last);

        return $last;
    }

    private function expectedBasicAuthHeader(): string
    {
        return 'Basic ' . base64_encode(self::KEY_ID . ':' . self::SECRET);
    }
}

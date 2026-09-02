<?php

/**
 * Tests for the Sinch webhook payload parser and the vendor-neutral receiver.
 *
 * The parser has to cope with both shapes Sinch posts (JSON with a base64 file,
 * or multipart where `fax` arrives as a JSON *string* and the document as an
 * attached part), and the receiver has to enforce the order of operations that
 * keeps push ingest equivalent to pull ingest: authenticate first, never store
 * a fax twice, and fall back to the API when a callback carried metadata only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\FaxSMS\Webhook;

use Composer\Autoload\ClassLoader;
use OpenEMR\Modules\FaxSMS\Controller\FaxDocumentService;
use OpenEMR\Modules\FaxSMS\Webhook\InboundFaxContentFetcherInterface;
use OpenEMR\Modules\FaxSMS\Webhook\InboundFaxReceiver;
use OpenEMR\Modules\FaxSMS\Webhook\SharedSecretAuthenticator;
use OpenEMR\Modules\FaxSMS\Webhook\SinchWebhookPayloadParser;
use OpenEMR\Modules\FaxSMS\Webhook\WebhookRequestContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InboundFaxWebhookTest extends TestCase
{
    private const SECRET = 'a-good-long-webhook-secret-value-0123456789';
    private const PDF = '%PDF-1.4 received fax bytes';

    /** @var list<string> */
    private array $tempFiles = [];

    /**
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

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    // ---------------------------------------------------------------- parser

    public function testParsesJsonIncomingFaxWithInlineDocument(): void
    {
        $payload = (new SinchWebhookPayloadParser())->parse($this->jsonContext([
            'event' => 'INCOMING_FAX',
            'eventTime' => '2026-06-16T13:45:07Z',
            'fax' => [
                'id' => 'FAXin1',
                'direction' => 'INBOUND',
                'status' => 'COMPLETED',
                'from' => '+15550001111',
                'to' => '+15557654321',
                'numberOfPages' => 3,
                'createTime' => '2026-06-16T13:44:00Z',
            ],
            'file' => base64_encode(self::PDF),
            'fileType' => 'PDF',
        ]));

        self::assertNotNull($payload);
        self::assertSame('FAXin1', $payload->faxId);
        self::assertSame('+15550001111', $payload->from);
        self::assertSame('+15557654321', $payload->to);
        self::assertSame('inbound', $payload->direction);
        self::assertSame('completed', $payload->status);
        self::assertSame(3, $payload->pages);
        self::assertTrue($payload->isInbound());
        self::assertTrue($payload->hasContent());
        self::assertSame(self::PDF, $payload->content);
        self::assertSame('application/pdf', $payload->mimeType);
        self::assertSame('2026-06-16 13:44:00', $payload->receivedOn, 'Timestamps normalize to UTC for the queue.');
    }

    public function testParsesMultipartWhereFaxIsAJsonStringAndFileIsAnAttachment(): void
    {
        $tmp = $this->makeTempFile(self::PDF);

        $payload = (new SinchWebhookPayloadParser())->parse(new WebhookRequestContext(
            contentType: 'multipart/form-data; boundary=----abc',
            formFields: [
                'event' => 'INCOMING_FAX',
                'eventTime' => '2026-06-16T13:45:07Z',
                // Sinch does not decode this for us in multipart mode.
                'fax' => (string)json_encode([
                    'id' => 'FAXmulti',
                    'direction' => 'INBOUND',
                    'status' => 'COMPLETED',
                    'from' => '+15550002222',
                    'numberOfPages' => 1,
                    'createTime' => '2026-06-16T13:44:00Z',
                ]),
            ],
            files: ['file' => ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'name' => 'fax.pdf']],
        ));

        self::assertNotNull($payload);
        self::assertSame('FAXmulti', $payload->faxId);
        self::assertSame('+15550002222', $payload->from);
        self::assertSame(self::PDF, $payload->content);
    }

    public function testParsesOutboundCompletionEvent(): void
    {
        $payload = (new SinchWebhookPayloadParser())->parse($this->jsonContext([
            'event' => 'FAX_COMPLETED',
            'fax' => [
                'id' => 'FAXout1',
                'direction' => 'OUTBOUND',
                'status' => 'FAILURE',
                'completedTime' => '2026-06-16T14:00:00Z',
            ],
        ]));

        self::assertNotNull($payload);
        self::assertFalse($payload->isInbound());
        self::assertSame('failure', $payload->status);
        self::assertFalse($payload->hasContent());
    }

    public function testUnparseableOrIrrelevantBodiesYieldNull(): void
    {
        $parser = new SinchWebhookPayloadParser();

        self::assertNull($parser->parse($this->jsonContext(['event' => 'SOMETHING_ELSE', 'fax' => ['id' => 'x']])));
        self::assertNull($parser->parse($this->jsonContext(['event' => 'INCOMING_FAX'])), 'No fax object.');
        self::assertNull($parser->parse($this->jsonContext(['event' => 'INCOMING_FAX', 'fax' => ['status' => 'COMPLETED']])), 'No id.');
        self::assertNull($parser->parse(new WebhookRequestContext(contentType: 'application/json', rawBody: 'not json')));
        self::assertNull($parser->parse(new WebhookRequestContext(contentType: 'application/json', rawBody: '')));
    }

    public function testCorruptInlineFileLeavesPayloadWithoutContent(): void
    {
        $payload = (new SinchWebhookPayloadParser())->parse($this->jsonContext([
            'event' => 'INCOMING_FAX',
            'fax' => ['id' => 'FAXbad', 'direction' => 'INBOUND', 'status' => 'COMPLETED'],
            'file' => '!!!! not base64 !!!!',
        ]));

        self::assertNotNull($payload);
        self::assertFalse($payload->hasContent(), 'A corrupt attachment must not be mistaken for a document.');
    }

    // -------------------------------------------------------------- receiver

    public function testRejectsBeforeParsingOrStoringWhenUnauthenticated(): void
    {
        $documents = $this->createMock(FaxDocumentService::class);
        // The whole point: nothing is looked up or written for a bad caller.
        $documents->expects(self::never())->method('getFaxDocument');
        $documents->expects(self::never())->method('insertInboundFaxToQueue');

        $receiver = $this->makeReceiver($documents);

        $result = $receiver->handle($this->jsonContext([
            'event' => 'INCOMING_FAX',
            'fax' => ['id' => 'FAXin1', 'direction' => 'INBOUND', 'status' => 'COMPLETED'],
        ], 'wrong-secret'));

        self::assertSame(InboundFaxReceiver::RESULT_UNAUTHORIZED, $result);
    }

    public function testStoresAnIncomingFaxThroughTheSharedQueueIngest(): void
    {
        $documents = $this->createMock(FaxDocumentService::class);
        $documents->method('getFaxDocument')->willReturn(null);
        $documents->expects(self::once())
            ->method('insertInboundFaxToQueue')
            ->with(
                self::callback(static function (object $record): bool {
                    // Shaped for FaxDocumentService, which base64-decodes FaxImage.
                    $fields = get_object_vars($record);
                    $params = $fields['DocumentParams'] ?? null;

                    return ($fields['JobId'] ?? null) === 'FAXin1'
                        && ($fields['CallingNumber'] ?? null) === '+15550001111'
                        && is_string($fields['FaxImage'] ?? null)
                        && base64_decode($fields['FaxImage'], true) === self::PDF
                        && $params instanceof \stdClass
                        && ($params->Type ?? null) === 'application/pdf';
                }),
                'proj-123'
            );

        $receiver = $this->makeReceiver($documents);

        $result = $receiver->handle($this->jsonContext([
            'event' => 'INCOMING_FAX',
            'fax' => [
                'id' => 'FAXin1',
                'direction' => 'INBOUND',
                'status' => 'COMPLETED',
                'from' => '+15550001111',
            ],
            'file' => base64_encode(self::PDF),
        ]));

        self::assertSame(InboundFaxReceiver::RESULT_ACCEPTED, $result);
    }

    public function testReplayedFaxIsAcknowledgedWithoutStoringAgain(): void
    {
        $documents = $this->createMock(FaxDocumentService::class);
        $documents->method('getFaxDocument')->willReturn(['job_id' => 'FAXin1', 'direction' => 'inbound']);
        $documents->expects(self::never())->method('insertInboundFaxToQueue');

        $result = $this->makeReceiver($documents)->handle($this->jsonContext([
            'event' => 'INCOMING_FAX',
            'fax' => ['id' => 'FAXin1', 'direction' => 'INBOUND', 'status' => 'COMPLETED'],
            'file' => base64_encode(self::PDF),
        ]));

        // Acknowledged, so the vendor stops retrying — but not duplicated.
        self::assertSame(InboundFaxReceiver::RESULT_DUPLICATE, $result);
    }

    public function testMetadataOnlyCallbackFetchesTheDocumentFromTheVendor(): void
    {
        $documents = $this->createMock(FaxDocumentService::class);
        $documents->method('getFaxDocument')->willReturn(null);
        $documents->expects(self::once())
            ->method('insertInboundFaxToQueue')
            ->with(self::callback(
                static function (object $record): bool {
                    $image = get_object_vars($record)['FaxImage'] ?? null;

                    return is_string($image) && base64_decode($image, true) === self::PDF;
                }
            ));

        $fetcher = new class implements InboundFaxContentFetcherInterface {
            public int $calls = 0;

            public function fetchFaxContent(string $faxId): ?string
            {
                $this->calls++;

                return $faxId === 'FAXnofile' ? InboundFaxWebhookTest::pdfBytes() : null;
            }
        };

        $result = $this->makeReceiver($documents, $fetcher)->handle($this->jsonContext([
            'event' => 'INCOMING_FAX',
            'fax' => ['id' => 'FAXnofile', 'direction' => 'INBOUND', 'status' => 'COMPLETED'],
        ]));

        self::assertSame(InboundFaxReceiver::RESULT_ACCEPTED, $result);
        self::assertSame(1, $fetcher->calls);
    }

    public function testFaxStillQueuedWhenDocumentCannotBeFetched(): void
    {
        $documents = $this->createMock(FaxDocumentService::class);
        $documents->method('getFaxDocument')->willReturn(null);
        // Losing the document is not a reason to lose the fax: the row is still
        // written so the operator can see something arrived.
        $documents->expects(self::once())->method('insertInboundFaxToQueue');

        $fetcher = new class implements InboundFaxContentFetcherInterface {
            public function fetchFaxContent(string $faxId): ?string
            {
                return null;
            }
        };

        self::assertSame(
            InboundFaxReceiver::RESULT_ACCEPTED,
            $this->makeReceiver($documents, $fetcher)->handle($this->jsonContext([
                'event' => 'INCOMING_FAX',
                'fax' => ['id' => 'FAXnofile', 'direction' => 'INBOUND', 'status' => 'COMPLETED'],
            ]))
        );
    }

    public function testOutboundCompletionUpdatesStatusAndStoresNothing(): void
    {
        $documents = $this->createMock(FaxDocumentService::class);
        $documents->method('getFaxDocument')->willReturn(['job_id' => 'FAXout1', 'direction' => 'outbound']);
        $documents->expects(self::once())->method('updateFaxStatus')->with('FAXout1', 'completed');
        $documents->expects(self::never())->method('insertInboundFaxToQueue');

        $result = $this->makeReceiver($documents)->handle($this->jsonContext([
            'event' => 'FAX_COMPLETED',
            'fax' => ['id' => 'FAXout1', 'direction' => 'OUTBOUND', 'status' => 'COMPLETED'],
        ]));

        self::assertSame(InboundFaxReceiver::RESULT_IGNORED, $result);
    }

    public function testUnknownOutboundFaxIsNotRecorded(): void
    {
        $documents = $this->createMock(FaxDocumentService::class);
        $documents->method('getFaxDocument')->willReturn(null);
        $documents->expects(self::never())->method('updateFaxStatus');

        self::assertSame(
            InboundFaxReceiver::RESULT_IGNORED,
            $this->makeReceiver($documents)->handle($this->jsonContext([
                'event' => 'FAX_COMPLETED',
                'fax' => ['id' => 'FAXunknown', 'direction' => 'OUTBOUND', 'status' => 'COMPLETED'],
            ]))
        );
    }

    public function testUnactionableBodyIsReportedAsBadRequest(): void
    {
        $documents = $this->createMock(FaxDocumentService::class);
        $documents->expects(self::never())->method('insertInboundFaxToQueue');

        self::assertSame(
            InboundFaxReceiver::RESULT_BAD_REQUEST,
            $this->makeReceiver($documents)->handle($this->jsonContext(['event' => 'UNKNOWN_EVENT']))
        );
    }

    public function testStorageFailureIsReportedAsErrorNotSuccess(): void
    {
        $documents = $this->createMock(FaxDocumentService::class);
        $documents->method('getFaxDocument')->willReturn(null);
        $documents->method('insertInboundFaxToQueue')->willThrowException(new \RuntimeException('db down'));

        // An error status lets the vendor retry rather than silently dropping.
        self::assertSame(
            InboundFaxReceiver::RESULT_ERROR,
            $this->makeReceiver($documents)->handle($this->jsonContext([
                'event' => 'INCOMING_FAX',
                'fax' => ['id' => 'FAXboom', 'direction' => 'INBOUND', 'status' => 'COMPLETED'],
                'file' => base64_encode(self::PDF),
            ]))
        );
    }

    // --------------------------------------------------------------- helpers

    public static function pdfBytes(): string
    {
        return self::PDF;
    }

    private function makeReceiver(
        FaxDocumentService $documents,
        ?InboundFaxContentFetcherInterface $fetcher = null
    ): InboundFaxReceiver {
        return new InboundFaxReceiver(
            new SharedSecretAuthenticator(self::SECRET),
            new SinchWebhookPayloadParser(),
            $documents,
            new NullLogger(),
            'proj-123',
            $fetcher
        );
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function jsonContext(array $envelope, string $secret = self::SECRET): WebhookRequestContext
    {
        return new WebhookRequestContext(
            secret: $secret,
            contentType: 'application/json',
            rawBody: (string)json_encode($envelope),
        );
    }

    private function makeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'faxhook');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}

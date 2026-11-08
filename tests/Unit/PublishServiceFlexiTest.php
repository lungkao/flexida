<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Http\HttpResponse;
use Grafida\I18n\LanguageService;
use Grafida\Joomla\ApiClient;
use Grafida\Media\InlineImageExtractor;
use Grafida\Media\MediaRepository;
use Grafida\Media\MediaUploadTarget;
use Grafida\Publish\PublishService;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Storage\SettingsRepository;
use Grafida\Text\ContentNormalisationService;
use Grafida\Text\ContentNormaliser;
use Grafida\Tests\Unit\Support\FakeTransport;
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;

/**
 * A draft with a FLEXIcontent type is published through /v1/flexicontent/items, not /v1/content/articles.
 */
final class PublishServiceFlexiTest extends TestCase
{
    private const API = 'https://example.com/index.php/api';

    private DatabaseInterface $db;
    private DraftRepository $drafts;
    private FakeTransport $transport;
    private Site $site;

    protected function setUp(): void
    {
        $this->db   = TestDatabase::memory();
        $connection = TestDatabase::connection($this->db);
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, api_base, insecure_token, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', '" . self::API . "', 'tok', "
            . "'2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        $this->drafts    = new DraftRepository($this->db);
        $this->transport = new FakeTransport();
        $this->site      = new Site(id: 1, title: 'Site', baseUrl: 'https://example.com', apiBase: self::API, secretRef: null, hasInsecureToken: true);

        $refs = new ReferenceRepository($this->db);
        $refs->put(1, ReferenceService::KIND_FIELDS, []);
        $refs->put(1, ReferenceService::KIND_CATEGORIES, []);
        $refs->put(1, ReferenceService::KIND_FLEXI, [
            ['id' => 1, 'name' => 'Article', 'alias' => 'article', 'fields' => [
                ['name' => 'subtitle', 'label' => 'Subtitle', 'field_type' => 'text', 'required' => false],
                ['name' => 'photo', 'label' => 'Photo', 'field_type' => 'image', 'required' => false],
                ['name' => 'gallery', 'label' => 'Gallery', 'field_type' => 'image', 'required' => false, 'multiple' => true],
                ['name' => 'docs', 'label' => 'Docs', 'field_type' => 'file', 'required' => false, 'multiple' => true],
            ]],
            ['id' => 3, 'name' => 'Video', 'alias' => 'video', 'fields' => [
                ['name' => 'video_url', 'label' => 'Video', 'field_type' => 'text', 'required' => false],
            ]],
        ]);

        $response = static fn (int $status, string $id): HttpResponse => new HttpResponse(
            $status,
            json_encode(['data' => ['type' => 'flexicontent-items', 'id' => $id]]),
            ['Content-Type' => 'application/vnd.api+json'],
        );
        $this->transport->on(self::API . '/v1/flexicontent/images', new HttpResponse(
            201,
            json_encode(['data' => ['type' => 'flexicontent-images', 'id' => 'cat-x1.png', 'attributes' => ['file' => 'cat-x1.png']]]),
            ['Content-Type' => 'application/vnd.api+json'],
        ));
        $this->transport->on(self::API . '/v1/flexicontent/files', new HttpResponse(
            201,
            json_encode(['data' => ['type' => 'flexicontent-files', 'id' => '88', 'attributes' => ['id' => 88, 'file' => 'new.pdf', 'name' => 'new.pdf']]]),
            ['Content-Type' => 'application/vnd.api+json'],
        ));
        $this->transport->on(self::API . '/v1/flexicontent/items', $response(201, '77'));
        $this->transport->on(self::API . '/v1/flexicontent/items/77', $response(200, '77'));
    }

    private function publishService(): PublishService
    {
        $apiClient = new ApiClient($this->transport);
        $sites     = new SiteService(new SiteRepository($this->db), $apiClient, null);
        $refs      = new ReferenceService(new ReferenceRepository($this->db), $sites, $apiClient);
        $language  = new LanguageService(new SettingsRepository($this->db), \dirname(__DIR__, 2));
        $media     = new MediaRepository($this->db);

        return new PublishService(
            $sites,
            $apiClient,
            $refs,
            $this->drafts,
            $media,
            $language,
            new InlineImageExtractor($media),
            new MediaUploadTarget($apiClient),
            new ContentNormaliser(new ContentNormalisationService(new SettingsRepository($this->db))),
        );
    }

    private function draft(?int $remoteId = null): Draft
    {
        $draft = new Draft(
            id: null,
            siteId: 1,
            remoteId: $remoteId,
            title: 'ข่าว',
            alias: 'news',
            catid: 8,
            access: 1,
            language: '*',
            state: 1,
            html: '<p>Intro</p><hr id="system-readmore"><p>More</p>',
            fields: [
                '_flexi'    => ['typeId' => 1, 'cats' => [9]],
                'subtitle'  => 'Sub',
                'video_url' => 'https://x.test/v',
            ],
            tags: ['เลือกตั้ง'],
        );
        $draft->id = $this->drafts->insert($draft);

        return $draft;
    }

    /** @return array<string, mixed> */
    private function lastBody(): array
    {
        $request = end($this->transport->requests);
        self::assertIsArray($request);

        $body = json_decode((string) ($request['body'] ?? ''), true);
        self::assertIsArray($body);

        return $body;
    }

    public function testCreatePostsToFlexiEndpointWithTypeCategoriesTagsAndOnlyThatTypesFields(): void
    {
        $result = $this->publishService()->publish($this->draft(), $this->site);

        self::assertSame(['remoteId' => 77, 'created' => true], $result);

        $request = end($this->transport->requests);
        self::assertSame('POST', $request['method']);
        self::assertSame(self::API . '/v1/flexicontent/items', $request['url']);

        $body = $this->lastBody();
        self::assertSame(1, $body['type_id']);
        self::assertSame(8, $body['catid']);
        self::assertSame([9], $body['cats']);
        self::assertSame(['เลือกตั้ง'], $body['tags']);
        self::assertSame('<p>Intro</p>', $body['introtext']);
        self::assertSame('<p>More</p>', $body['fulltext']);
        // video_url belongs to the Video type only, so it must not be sent for an Article.
        self::assertSame(['subtitle' => 'Sub'], $body['fields']);
    }

    public function testUpdatePatchesFlexiItemWithoutChangingTheType(): void
    {
        $result = $this->publishService()->publish($this->draft(77), $this->site);

        self::assertSame(['remoteId' => 77, 'created' => false], $result);

        $request = end($this->transport->requests);
        self::assertSame('PATCH', $request['method']);
        self::assertSame(self::API . '/v1/flexicontent/items/77', $request['url']);
        self::assertArrayNotHasKey('type_id', $this->lastBody());
    }

    public function testLocalPictureInAnImageFieldIsUploadedAndSentByItsStoredName(): void
    {
        $mediaId = (new MediaRepository($this->db))->store(1, null, 'cat.png', 'image/png', 'raw-bytes', 640, 480);
        $draft   = $this->draft();
        $draft->fields['photo'] = json_encode(['imagefile' => 'grafida-media://' . $mediaId, 'alt_text' => 'A cat', 'alt_empty' => '']);
        $this->drafts->update($draft);

        $this->publishService()->publish($draft, $this->site);

        $uploads = array_values(array_filter($this->transport->requests, static fn (array $r): bool => str_ends_with($r['url'], '/v1/flexicontent/images')));
        self::assertCount(1, $uploads);
        $upload = json_decode((string) $uploads[0]['body'], true);
        self::assertSame(base64_encode('raw-bytes'), $upload['content']);
        self::assertStringEndsWith('.png', $upload['filename']);

        self::assertSame(['file' => 'cat-x1.png', 'alt' => 'A cat'], $this->lastBody()['fields']['photo']);
    }

    public function testImageFieldWithoutALocalPictureIsNotSent(): void
    {
        $draft = $this->draft();
        $draft->fields['photo'] = json_encode(['imagefile' => 'images/from-site.jpg', 'alt_text' => '', 'alt_empty' => '']);
        $this->drafts->update($draft);

        $this->publishService()->publish($draft, $this->site);

        self::assertArrayNotHasKey('photo', $this->lastBody()['fields']);
    }

    public function testGalleryFieldUploadsEveryLocalPictureAndSendsAList(): void
    {
        $repo = new MediaRepository($this->db);
        $one  = $repo->store(1, null, 'one.png', 'image/png', 'bytes-1', 10, 10);
        $two  = $repo->store(1, null, 'two.png', 'image/png', 'bytes-2', 10, 10);
        $draft = $this->draft();
        $draft->fields['gallery'] = [
            json_encode(['imagefile' => 'grafida-media://' . $one, 'alt_text' => 'First', 'alt_empty' => '']),
            json_encode(['imagefile' => 'grafida-media://' . $two, 'alt_text' => 'Second', 'alt_empty' => '']),
        ];
        $this->drafts->update($draft);

        $this->publishService()->publish($draft, $this->site);

        $uploads = array_values(array_filter($this->transport->requests, static fn (array $r): bool => str_ends_with($r['url'], '/v1/flexicontent/images')));
        self::assertCount(2, $uploads);

        $sent = $this->lastBody()['fields']['gallery'];
        self::assertCount(2, $sent);
        self::assertSame(['First', 'Second'], array_column($sent, 'alt'));
    }

    public function testFileFieldSendsTheWholeListOfFileIds(): void
    {
        $mediaId = (new MediaRepository($this->db))->store(1, null, 'new.pdf', 'application/pdf', 'PDF', null, null);
        $draft   = $this->draft();
        $draft->fields['docs'] = [
            json_encode(['id' => 5, 'name' => 'old.pdf']),
            json_encode(['media' => $mediaId, 'name' => 'new.pdf']),
        ];
        $this->drafts->update($draft);

        $this->publishService()->publish($draft, $this->site);

        $uploads = array_values(array_filter($this->transport->requests, static fn (array $r): bool => str_ends_with($r['url'], '/v1/flexicontent/files')));
        self::assertCount(1, $uploads);
        self::assertSame('new.pdf', json_decode((string) $uploads[0]['body'], true)['filename']);
        self::assertCount(2, $this->lastBody()['fields']['docs']);
        self::assertSame(['5', '88'], $this->lastBody()['fields']['docs']);
    }
}

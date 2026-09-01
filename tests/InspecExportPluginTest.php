<?php

/**
 * @file tests/InspecExportPluginTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the Inspec export plugin.
 */

namespace APP\plugins\generic\inspec\tests;

use APP\issue\Issue;
use APP\issue\Repository as IssueRepository;
use APP\journal\Journal;
use APP\plugins\generic\inspec\InspecExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\enums\VersionStage;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PKP\tests\PKPTestCase;
use ReflectionMethod;
use ZipArchive;

#[CoversClass(InspecExportPlugin::class)]
class InspecExportPluginTest extends PKPTestCase
{
    /**
     * A minimal JATS document. The xlink namespace is declared here because the
     * jatsTemplate plugin declares it on generated JATS.
     */
    private const JATS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <article xmlns:xlink="http://www.w3.org/1999/xlink" article-type="research-article">
            <front>
                <article-meta>
                    <title-group><article-title>Test article</article-title></title-group>
                    %s
                    <abstract><p>An abstract.</p></abstract>
                </article-meta>
            </front>
        </article>
        XML;

    protected function tearDown(): void
    {
        // Drop any container binding a test may have replaced so that the next
        // resolution builds a fresh instance.
        app()->forgetInstance(IssueRepository::class);
        parent::tearDown();
    }

    /**
     * Build the plugin with its settings stubbed out.
     *
     * @param array $settings Plugin setting name => value
     */
    private function createPlugin(array $settings = []): InspecExportPlugin
    {
        $plugin = $this->getMockBuilder(InspecExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetting'])
            ->getMock();

        $plugin->method('getSetting')
            ->willReturnCallback(fn ($contextId, $name) => $settings[$name] ?? null);

        return $plugin;
    }

    /**
     * Call a protected method on the plugin.
     */
    private function invoke(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }

    /**
     * Build a journal with the settings relevant to file naming.
     */
    private function createJournal(array $abbreviation = ['en' => 'JHS'], string $urlPath = 'healthsci'): Journal
    {
        $journal = new Journal();
        $journal->setId(1);
        $journal->setData('primaryLocale', 'en');
        $journal->setData('urlPath', $urlPath);
        if ($abbreviation !== []) {
            $journal->setData('abbreviation', $abbreviation);
        }
        return $journal;
    }

    /**
     * Build the JATS fixture, optionally injecting elements before the abstract.
     */
    private function jats(string $extraArticleMeta = ''): string
    {
        return sprintf(self::JATS, $extraArticleMeta);
    }

    /**
     * Run an XPath query against a returned XML string.
     */
    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'Result should be well-formed XML');
        return new DOMXPath($dom);
    }

    //
    // journalAbbreviation()
    //
    public function testJournalAbbreviationUsesTheJournalAbbreviation(): void
    {
        $plugin = $this->createPlugin();
        $journal = $this->createJournal();

        $this->assertSame('JHS', $plugin->journalAbbreviation($journal));
    }

    public function testJournalAbbreviationFallsBackToUrlPathWhenUnset(): void
    {
        $plugin = $this->createPlugin();
        $journal = $this->createJournal([]);

        $this->assertSame('healthsci', $plugin->journalAbbreviation($journal));
    }

    public function testJournalAbbreviationFallsBackToUrlPathWhenEmpty(): void
    {
        $plugin = $this->createPlugin();
        $journal = $this->createJournal(['en' => '']);

        $this->assertSame('healthsci', $plugin->journalAbbreviation($journal));
    }

    public function testJournalAbbreviationUsesThePrimaryLocale(): void
    {
        $plugin = $this->createPlugin();
        $journal = $this->createJournal(['en' => 'JHS', 'fr_CA' => 'JSS']);
        $journal->setData('primaryLocale', 'fr_CA');

        $this->assertSame('JSS', $plugin->journalAbbreviation($journal));
    }

    //
    // getExportActions()
    //
    public function testDepositActionOfferedWhenCredentialsAreComplete(): void
    {
        $plugin = $this->createPlugin([
            'host' => 'sftp.example.org',
            'username' => 'user',
            'password' => 'secret',
        ]);

        $this->assertSame([
            PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT,
            PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
            PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED,
        ], $plugin->getExportActions($this->createJournal()));
    }

    /**
     * @param array $settings The connection settings that *are* present
     */
    #[DataProvider('incompleteCredentialsProvider')]
    public function testDepositActionWithheldWhenCredentialsAreIncomplete(array $settings): void
    {
        $plugin = $this->createPlugin($settings);

        $this->assertSame([
            PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
            PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED,
        ], $plugin->getExportActions($this->createJournal()));
    }

    public static function incompleteCredentialsProvider(): array
    {
        return [
            'nothing set' => [[]],
            'host missing' => [['username' => 'user', 'password' => 'secret']],
            'username missing' => [['host' => 'sftp.example.org', 'password' => 'secret']],
            'password missing' => [['host' => 'sftp.example.org', 'username' => 'user']],
            'host empty' => [['host' => '', 'username' => 'user', 'password' => 'secret']],
        ];
    }

    //
    // isAccountComplete()
    //
    #[DataProvider('accountProvider')]
    public function testAccountCompletenessCheck(array $account, bool $complete): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame($complete, $plugin->isAccountComplete($account));
    }

    public static function accountProvider(): array
    {
        $complete = ['host' => 'sftp.example.org', 'username' => 'user', 'password' => 'secret'];

        return [
            'complete' => [$complete, true],
            'nothing set' => [[], false],
            'blank strings' => [['host' => '', 'username' => '', 'password' => ''], false],
            'host only' => [['host' => 'sftp.example.org'], false],
            'password missing' => [array_diff_key($complete, ['password' => null]), false],
        ];
    }

    //
    // buildFileName()
    //
    public function testBuildFileNameWithoutAnObjectIsJustTheAbbreviation(): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame(
            'jhs',
            $this->invoke($plugin, 'buildFileName', ['JHS', $this->createJournal()])
        );
    }

    public function testBuildFileNameAppendsTheExtension(): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame(
            'jhs.zip',
            $this->invoke($plugin, 'buildFileName', ['JHS', $this->createJournal(), null, false, 'zip'])
        );
    }

    public function testBuildFileNameUsesTheArticleNumberScheme(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e12345');

        $this->assertSame(
            'jhs-e12345.xml',
            $this->invoke($plugin, 'buildFileName', ['JHS', $this->createJournal(), $publication, false, 'xml'])
        );
    }

    public function testBuildFileNameUsesTheVolumeIssueScheme(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'volumeIssue']);

        $issue = new Issue();
        $issue->setData('volume', 12);
        $issue->setData('number', 3);
        $issueRepository = $this->createMock(IssueRepository::class);
        $issueRepository->method('get')->willReturn($issue);
        app()->instance(IssueRepository::class, $issueRepository);

        $publication = new Publication();
        $publication->setData('issueId', 7);
        $publication->setData('pages', '45-52');

        $this->assertSame(
            'jhs-12-3-45.xml',
            $this->invoke($plugin, 'buildFileName', ['JHS', $this->createJournal(), $publication, false, 'xml'])
        );
    }

    /**
     * A part that resolves to nothing is dropped rather than left as a stray separator.
     */
    public function testBuildFileNameDropsEmptyParts(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'volumeIssue']);

        $issue = new Issue();
        $issue->setData('volume', 12);
        $issueRepository = $this->createMock(IssueRepository::class);
        $issueRepository->method('get')->willReturn($issue);
        app()->instance(IssueRepository::class, $issueRepository);

        $publication = new Publication();
        $publication->setData('issueId', 7);

        $this->assertSame(
            'jhs-12',
            $this->invoke($plugin, 'buildFileName', ['JHS', $this->createJournal(), $publication])
        );
    }

    public function testBuildFileNameDefaultsToTheVolumeIssueSchemeWhenUnset(): void
    {
        $plugin = $this->createPlugin();

        $issue = new Issue();
        $issue->setData('volume', 12);
        $issue->setData('number', 3);
        $issueRepository = $this->createMock(IssueRepository::class);
        $issueRepository->method('get')->willReturn($issue);
        app()->instance(IssueRepository::class, $issueRepository);

        $publication = new Publication();
        $publication->setData('issueId', 7);
        $publication->setData('pages', '45-52');

        $this->assertSame(
            'jhs-12-3-45',
            $this->invoke($plugin, 'buildFileName', ['JHS', $this->createJournal(), $publication])
        );
    }

    /**
     * A submission is named after its current publication, so the name matches the
     * metadata the article displays.
     */
    public function testBuildFileNameResolvesTheCurrentPublicationOfASubmission(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);

        $earlier = $this->publishedPublication(1, ['articleNumber' => 'e999']);
        $current = $this->publishedPublication(2, ['articleNumber' => 'e111']);

        $submission = new Submission();
        $submission->setData('publications', collect([$earlier, $current]));
        $submission->setData('currentPublicationId', 2);

        $this->assertSame(
            'jhs-e111',
            $this->invoke($plugin, 'buildFileName', ['JHS', $this->createJournal(), $submission])
        );
    }

    //
    // depositPublication()
    //

    /**
     * A Publication selected directly is deposited as given; only a Submission is
     * resolved to a version.
     */
    public function testDepositPublicationPassesAPublicationThrough(): void
    {
        $publication = new Publication();
        $publication->setId(7);

        $this->assertSame(
            $publication,
            $this->invoke($this->createPlugin(), 'depositPublication', [$publication])
        );
    }

    public function testDepositPublicationIsTheCurrentVersion(): void
    {
        $earlier = $this->publishedPublication(1);
        $current = $this->publishedPublication(2);

        $submission = new Submission();
        $submission->setData('publications', collect([$earlier, $current]));
        $submission->setData('currentPublicationId', 2);

        $this->assertSame($current, $this->invoke($this->createPlugin(), 'depositPublication', [$submission]));
    }

    public function testDepositPublicationIsNullWhenThereIsNoCurrentVersion(): void
    {
        $submission = new Submission();
        $submission->setData('publications', collect([$this->publishedPublication(1)]));

        $this->assertNull($this->invoke($this->createPlugin(), 'depositPublication', [$submission]));
    }

    /**
     * Only the version of record reaches the export grid, so that is what a submission's
     * current publication is when it is offered for deposit.
     */
    public function testOnlyTheVersionOfRecordIsDepositable(): void
    {
        $this->assertSame(
            [VersionStage::VERSION_OF_RECORD],
            $this->createPlugin()->getExportableVersionStages()
        );
    }

    private function publishedPublication(int $id, array $data = []): Publication
    {
        $publication = new Publication();
        $publication->setId($id);
        $publication->setData('status', Submission::STATUS_PUBLISHED);
        $publication->setData('versionStage', VersionStage::VERSION_OF_RECORD->value);
        foreach ($data as $key => $value) {
            $publication->setData($key, $value);
        }
        return $publication;
    }

    public function testBuildFileNameStripsNonAlphanumericCharactersAndLowercases(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e 12/345');

        // Punctuation and whitespace are removed; digits and letters survive.
        $this->assertSame(
            'jpubknowledge1-e12345',
            $this->invoke(
                $plugin,
                'buildFileName',
                ['J. Pub/Knowledge: #1', $this->createJournal(), $publication]
            )
        );
    }

    public function testBuildFileNameAppendsATimestamp(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e12345');

        $filename = $this->invoke(
            $plugin,
            'buildFileName',
            ['JHS', $this->createJournal(), $publication, true, 'zip']
        );

        $this->assertMatchesRegularExpression('/^jhs-e12345-\d{14}\.zip$/', $filename);
    }

    //
    // createZipCollection()
    //

    /**
     * Build the plugin with createZip() stubbed to hand back ready-made packages,
     * so the collection logic can be exercised without a submission or its files.
     *
     * @param array $packages One createZip() return value per call, in order.
     */
    private function createPluginWithPackages(array $packages): InspecExportPlugin
    {
        $plugin = $this->getMockBuilder(InspecExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetting', 'createZip'])
            ->getMock();

        $plugin->method('getSetting')->willReturn(null);
        $plugin->method('createZip')->willReturnOnConsecutiveCalls(...$packages);

        return $plugin;
    }

    /**
     * Write a stand-in article package and return it in createZip()'s shape.
     */
    private function buildPackage(string $filename): array
    {
        $path = tempnam(sys_get_temp_dir(), 'InspecTest_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($filename . '/' . $filename . '.xml', '<article/>');
        $zip->close();

        return ['filename' => $filename, 'path' => $path];
    }

    /**
     * Read back the entry names of a zip file.
     */
    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        return $names;
    }

    public function testASingleObjectIsDownloadedAsItsOwnPackage(): void
    {
        $package = $this->buildPackage('jhs-1-1-1');
        $plugin = $this->createPluginWithPackages([$package]);

        $result = $this->invoke($plugin, 'createZipCollection', [[new Submission()], $this->createJournal()]);

        $this->assertSame($package['path'], $result['path'], 'The package itself is the download');

        // Guards the regression this replaced: a lone article wrapped in a collection zip
        $this->assertSame(['jhs-1-1-1/jhs-1-1-1.xml'], $this->zipEntries($result['path']));

        unlink($package['path']);
    }

    public function testSeveralObjectsAreGatheredIntoACollection(): void
    {
        $packages = [$this->buildPackage('jhs-1-1-1'), $this->buildPackage('jhs-1-1-9')];
        $plugin = $this->createPluginWithPackages($packages);

        $result = $this->invoke(
            $plugin,
            'createZipCollection',
            [[new Submission(), new Submission()], $this->createJournal()]
        );

        $this->assertSame(['jhs-1-1-1.zip', 'jhs-1-1-9.zip'], $this->zipEntries($result['path']));

        // The per-article packages are cleaned up once the collection is closed
        foreach ($packages as $package) {
            $this->assertFileDoesNotExist($package['path']);
        }

        unlink($result['path']);
    }

    public function testASingleObjectPassesItsErrorStraightThrough(): void
    {
        $plugin = $this->createPluginWithPackages([['error' => ['plugins.importexport.inspec.export.failure.loadJats']]]);

        $result = $this->invoke($plugin, 'createZipCollection', [[new Submission()], $this->createJournal()]);

        $this->assertSame(['error' => ['plugins.importexport.inspec.export.failure.loadJats']], $result);
    }

    //
    // discardZip() / deleteTempFile()
    //
    public function testDiscardZipRemovesTheArchiveAndReturnsTheError(): void
    {
        $plugin = $this->createPlugin();

        $zipPath = tempnam(sys_get_temp_dir(), 'InspecTest_');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('a/b.xml', '<x/>');

        $result = $this->invoke($plugin, 'discardZip', [$zip, $zipPath, ['some.error.key', 'detail']]);

        $this->assertSame(['error' => ['some.error.key', 'detail']], $result);
        $this->assertFileDoesNotExist($zipPath);
    }

    public function testDiscardZipAlsoRemovesCollectedPackages(): void
    {
        $plugin = $this->createPlugin();

        $zipPath = tempnam(sys_get_temp_dir(), 'InspecTest_');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('a/b.xml', '<x/>');

        $collected = [
            tempnam(sys_get_temp_dir(), 'InspecTest_'),
            tempnam(sys_get_temp_dir(), 'InspecTest_'),
        ];

        $this->invoke($plugin, 'discardZip', [$zip, $zipPath, ['some.error.key'], $collected]);

        $this->assertFileDoesNotExist($zipPath);
        foreach ($collected as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function testDeleteTempFileToleratesAMissingFile(): void
    {
        $plugin = $this->createPlugin();

        $path = tempnam(sys_get_temp_dir(), 'InspecTest_');
        unlink($path);

        $this->invoke($plugin, 'deleteTempFile', [$path]);

        $this->assertFileDoesNotExist($path);
    }

    /**
     * The empty file tempnam() creates would otherwise raise
     * "Using empty file as ZipArchive is deprecated" when opened.
     */
    public function testZipIsOpenedWithoutTriggeringTheEmptyFileDeprecation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'InspecTest_');
        $seen = [];
        set_error_handler(
            function (int $errno, string $message) use (&$seen): bool {
                $seen[] = $message;
                return true;
            },
            E_DEPRECATED
        );

        try {
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('a/b.xml', '<x/>');
            $zip->close();
        } finally {
            restore_error_handler();
            @unlink($path);
        }

        $this->assertSame([], $seen);
    }

    //
    // addPdfSelfUri()
    //
    public function testSelfUriIsInsertedBeforeTheAbstract(): void
    {
        $plugin = $this->createPlugin();

        $result = $this->invoke($plugin, 'addPdfSelfUri', [$this->jats(), 'jhs-12-3-45.pdf']);

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $selfUris = $xpath->query('//article-meta/self-uri');
        $this->assertSame(1, $selfUris->length);
        $this->assertSame('pdf', $selfUris->item(0)->getAttribute('content-type'));
        $this->assertSame('jhs-12-3-45.pdf', $selfUris->item(0)->getAttribute('xlink:href'));

        // The abstract must follow the new element.
        $this->assertSame(
            'abstract',
            $selfUris->item(0)->nextSibling->nodeName,
            'self-uri should be inserted immediately before the abstract'
        );
    }

    public function testSelfUriIsInsertedBeforeAnExistingSelfUri(): void
    {
        $plugin = $this->createPlugin();
        $jats = $this->jats('<self-uri content-type="html" xlink:href="article.html"/>');

        $result = $this->invoke($plugin, 'addPdfSelfUri', [$jats, 'jhs-12-3-45.pdf']);

        $xpath = $this->xpath($result);
        $selfUris = $xpath->query('//article-meta/self-uri');

        $this->assertSame(2, $selfUris->length);
        $this->assertSame('pdf', $selfUris->item(0)->getAttribute('content-type'));
        $this->assertSame('html', $selfUris->item(1)->getAttribute('content-type'));
    }

    public function testExistingPdfSelfUrisAreReplaced(): void
    {
        $plugin = $this->createPlugin();
        $jats = $this->jats(
            '<self-uri content-type="pdf" xlink:href="old.pdf"/>' .
            '<self-uri content-type="application/pdf" xlink:href="older.pdf"/>'
        );

        $result = $this->invoke($plugin, 'addPdfSelfUri', [$jats, 'new.pdf']);

        $xpath = $this->xpath($result);
        $selfUris = $xpath->query('//article-meta/self-uri');

        $this->assertSame(1, $selfUris->length);
        $this->assertSame('new.pdf', $selfUris->item(0)->getAttribute('xlink:href'));
    }

    /**
     * Existing PDF links are only removed when there is a replacement for them,
     * so an article packaged without a PDF galley keeps the reference it had
     * rather than being left with none.
     */
    public function testExistingPdfSelfUrisArePreservedWithoutAPackagedPdf(): void
    {
        $plugin = $this->createPlugin();
        $jats = $this->jats('<self-uri content-type="pdf" xlink:href="old.pdf"/>');

        $result = $this->invoke($plugin, 'addPdfSelfUri', [$jats, null]);

        $xpath = $this->xpath($result);
        $selfUris = $xpath->query('//article-meta/self-uri');

        $this->assertSame(1, $selfUris->length);
        $this->assertSame('old.pdf', $selfUris->item(0)->getAttribute('xlink:href'));
    }

    /**
     * Only PDF links are replaced; links to other representations are left alone,
     * with or without a packaged PDF.
     */
    public function testOtherSelfUrisArePreserved(): void
    {
        $plugin = $this->createPlugin();
        $jats = $this->jats('<self-uri content-type="text/html" xlink:href="article.html"/>');

        $withoutPdf = $this->xpath($this->invoke($plugin, 'addPdfSelfUri', [$jats, null]));
        $this->assertSame(1, $withoutPdf->query('//article-meta/self-uri')->length);
        $this->assertSame(
            'article.html',
            $withoutPdf->query('//article-meta/self-uri')->item(0)->getAttribute('xlink:href')
        );

        $withPdf = $this->xpath($this->invoke($plugin, 'addPdfSelfUri', [$jats, 'jhs.pdf']));
        $hrefs = [];
        foreach ($withPdf->query('//article-meta/self-uri') as $selfUri) {
            $hrefs[] = $selfUri->getAttribute('xlink:href');
        }
        $this->assertSame(['jhs.pdf', 'article.html'], $hrefs);
    }

    public function testAddPdfSelfUriIsIdempotent(): void
    {
        $plugin = $this->createPlugin();

        $once = $this->invoke($plugin, 'addPdfSelfUri', [$this->jats(), 'jhs.pdf']);
        $twice = $this->invoke($plugin, 'addPdfSelfUri', [$once, 'jhs.pdf']);

        $this->assertSame($once, $twice);
    }

    public function testMissingArticleMetaReturnsAnError(): void
    {
        $plugin = $this->createPlugin();
        $jats = '<?xml version="1.0"?><article><front></front></article>';

        $this->assertSame(
            ['plugins.importexport.inspec.export.failure.jatsNodeMissing', 'article-meta'],
            $this->invoke($plugin, 'addPdfSelfUri', [$jats, 'jhs.pdf'])
        );
    }

    public function testMissingAbstractReturnsAnError(): void
    {
        $plugin = $this->createPlugin();
        $jats = '<?xml version="1.0"?><article><front><article-meta>'
            . '<title-group><article-title>T</article-title></title-group>'
            . '</article-meta></front></article>';

        $this->assertSame(
            ['plugins.importexport.inspec.export.failure.jatsNodeMissing', 'abstract'],
            $this->invoke($plugin, 'addPdfSelfUri', [$jats, 'jhs.pdf'])
        );
    }

    public function testMalformedXmlReturnsAnError(): void
    {
        $plugin = $this->createPlugin();

        // addPdfSelfUri() relies on the caller having enabled internal error
        // handling; exportXML() does this before calling it.
        $previous = libxml_use_internal_errors(true);
        try {
            $result = $this->invoke($plugin, 'addPdfSelfUri', ['<article><front>', 'jhs.pdf']);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertSame(['plugins.importexport.inspec.export.failure.loadJats'], $result);
    }

    /**
     * Uploaded JATS is not guaranteed to declare the xlink namespace. The
     * attribute is written with setAttribute(), so the prefix is emitted
     * verbatim whether or not it has been declared.
     */
    public function testSelfUriHrefOnJatsWithoutTheXlinkNamespace(): void
    {
        $plugin = $this->createPlugin();
        $jats = '<?xml version="1.0"?><article><front><article-meta>'
            . '<abstract><p>An abstract.</p></abstract>'
            . '</article-meta></front></article>';

        $result = $this->invoke($plugin, 'addPdfSelfUri', [$jats, 'jhs.pdf']);

        $this->assertIsString($result);
        $this->assertStringContainsString('xlink:href="jhs.pdf"', $result);
    }

    //
    // validateJats()
    //
    public static function jatsEntityProvider(): array
    {
        return [
            'JATS 1.2 public identifier' => [
                '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN',
                'http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd',
                true,
            ],
            'JATS 1.2 system identifier over https' => [
                null,
                'https://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd',
                true,
            ],
            'another JATS version' => [
                null,
                'http://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1.dtd',
                false,
            ],
            'a module the DTD pulls in' => [
                null,
                '/somewhere/dtd/jats/1.2/JATS-common1.ent',
                false,
            ],
        ];
    }

    #[DataProvider('jatsEntityProvider')]
    public function testResolveJatsEntityPrefersTheBundledDtd(?string $publicId, string $systemId, bool $bundled): void
    {
        $resolved = $this->invoke($this->createPlugin(), 'resolveJatsEntity', [$publicId, $systemId, []]);

        if (!$bundled) {
            $this->assertSame($systemId, $resolved, 'Anything else should be left to libxml');
            return;
        }

        $this->assertStringEndsWith('/dtd/jats/1.2/JATS-journalpublishing1.dtd', $resolved);
        $this->assertFileExists($resolved);
    }

    /**
     * Build a DOMDocument from the fixture, optionally declaring a document type.
     */
    private function jatsDocument(?string $publicId = null, ?string $systemId = null): DOMDocument
    {
        $jats = $this->jats();
        if ($publicId !== null) {
            $doctype = sprintf('<!DOCTYPE article PUBLIC "%s" "%s">', $publicId, $systemId);
            $jats = str_replace('<article ', $doctype . PHP_EOL . '<article ', $jats);
        }
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($jats));
        return $dom;
    }

    public function testValidateJatsReportsDtdErrors(): void
    {
        // The fixture has no journal-meta, which the DTD requires
        $dom = $this->jatsDocument(
            '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN',
            'http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd'
        );

        $result = $this->invoke($this->createPlugin(), 'validateJats', [$dom]);

        $this->assertIsString($result, 'An invalid document should be reported, not accepted');
        $this->assertStringContainsString('DTD Error', $result);
        $this->assertStringContainsString('journal-meta', $result);
    }

    /**
     * Only JATS 1.2 can be validated, so any other version passes. The same fixture
     * reports DTD errors when it declares JATS 1.2.
     */
    public function testValidateJatsSkipsTheDtdForAnotherJatsVersion(): void
    {
        $dom = $this->jatsDocument(
            '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20151215//EN',
            'http://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1.dtd'
        );

        $result = $this->invoke($this->createPlugin(), 'validateJats', [$dom]);

        $this->assertTrue($result, 'A version we cannot validate should not fail the export');
    }

    public function testValidateJatsSkipsTheDtdWhenNoDoctypeIsDeclared(): void
    {
        $result = $this->invoke($this->createPlugin(), 'validateJats', [$this->jatsDocument()]);

        $this->assertTrue($result);
    }
}

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

    public function testBuildFileNameResolvesTheCurrentPublicationOfASubmission(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);

        $publication = new Publication();
        $publication->setData('articleNumber', 'e999');

        $submission = $this->getMockBuilder(Submission::class)
            ->onlyMethods(['getCurrentPublication'])
            ->getMock();
        $submission->method('getCurrentPublication')->willReturn($publication);

        $this->assertSame(
            'jhs-e999',
            $this->invoke($plugin, 'buildFileName', ['JHS', $this->createJournal(), $submission])
        );
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
}
